<?php
if(!is_file('/.dockerenv'))exit(77);
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-socket.php';
$plugin=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php');
function check($ok,$why):void{if(!$ok)throw new RuntimeException($why);}
function rpc(array $request):array{$r=zfsas_coordinator_request($request);check($r['ok'],json_encode($r));return $r['result'];}
function until(callable $fn) {
    $end=microtime(true)+30;
    do{if($value=$fn())return $value;usleep(30000);}while(microtime(true)<$end);
    throw new RuntimeException('Auto daemon timeout: '.@file_get_contents('/tmp/auto-mutation-daemon.log').' '.@file_get_contents('/var/log/zfs_snapsync.log'));
}
$dir='/boot/config/plugins/zfs.snapsync';@mkdir($dir,0770,true);
$mode=empty($partialReplan)?'disabled':'hourly';
file_put_contents($dir.'/zfs_snapsync.conf',"DATASETS=\"tank/data:0G\"\nPREFIX=\"auto-\"\nSCHEDULE_MODE=\"$mode\"\n");
if(!empty($partialReplan))file_put_contents('/tmp/save-after-auto-snapshot','1');
if(!empty($changedCompletedIdentity))file_put_contents('/tmp/change-completed-guid','1');
file_put_contents($dir.'/zfs_send.conf',"SEND_SNAPSHOT_PREFIX=\"send-\"\n");
@mkdir('/var/local/emhttp',0770,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STARTED"');
$initial=['snapshots'=>[
    'tank/data@auto-new'=>['guid'=>'200','createtxg'=>'20','creation'=>(string)time(),'used'=>'10','written'=>'10','userrefs'=>'0','clones'=>'-'],
    'tank/data@auto-old'=>['guid'=>'100','createtxg'=>'10','creation'=>'1','used'=>'10','written'=>'10','userrefs'=>'0','clones'=>'-'],
],'mutations'=>[]];
file_put_contents('/tmp/auto-zfs.json',json_encode($initial));
file_put_contents('/usr/local/bin/zfs', <<<'PY'
#!/usr/bin/python3
import sys,json,time,os
a=sys.argv[1:];path='/tmp/auto-zfs.json'
with open(path) as f:s=json.load(f)
op=a[0];target=a[-1];fields=a[a.index('-o')+1] if '-o' in a else ''
if op in ['version','--version']:print('fixture');sys.exit(0)
if op=='list':
 if '-t' in a and a[a.index('-t')+1]=='snapshot':
  for name,row in s['snapshots'].items():
   if name.startswith(target+'@') or name.startswith(target+'/'):
    print('\t'.join(name if x=='name' else row[x] for x in fields.split(',')))
 elif fields in ['avail','available']:print('10000000000')
 elif fields=='name':print(target)
 else:sys.exit(2)
elif op=='get':
 props=a[a.index('--')-1 if '--' in a else -2].split(',')
 names=[target]
 if '-t' in a and a[a.index('-t')+1]=='snapshot':names=[n for n in s['snapshots'] if n.startswith(target+'@') or n.startswith(target+'/')]
 for name in names:
  row=s['snapshots'].get(name,{'guid':'10','available':'10000000000','quota':'0','refquota':'0','used':'0','referenced':'0'})
  for prop in props:
   data={'name':name,'property':prop,'value':row.get(prop,'-'),'source':'local'}
   if prop=='guid' and '@' in name and 'auto-replan-' in os.getenv('ZFSAS_TASK_ID','') and os.path.exists('/tmp/change-completed-guid'):data['value']='999'
   print('\t'.join(data[x] for x in fields.split(',')))
elif op in ['destroy','snapshot']:
 if os.path.exists('/tmp/block-auto-mutation'):
  open('/tmp/auto-mutation-entered','w').write(str(os.getpid()))
  while os.path.exists('/tmp/block-auto-mutation'):time.sleep(.02)
 if op=='destroy':
  assert s['snapshots'][target]['userrefs']=='0'
  del s['snapshots'][target]
 else:
  assert target not in s['snapshots']
  s['snapshots'][target]={'guid':'300','createtxg':'30','creation':str(int(time.time())),'used':'0','written':'0','userrefs':'0','clones':'-'}
 s['mutations'].append([op,target,os.getenv('ZFSAS_TASK_ID','')])
 with open(path+'.pending','w') as f:json.dump(s,f)
 os.replace(path+'.pending',path)
 if op=='snapshot' and os.path.exists('/tmp/save-after-auto-snapshot'):
  config='/boot/config/plugins/zfs.snapsync/zfs_snapsync.conf'
  with open(config) as f:text=f.read()
  with open(config,'w') as f:f.write(text.replace('tank/data:0G','tank/data:0G,tank/new:0G'))
  os.unlink('/tmp/save-after-auto-snapshot')
else:sys.exit(3)
PY);
file_put_contents('/usr/local/bin/zpool',"#!/bin/sh\nif [ \"\$1\" = get ]; then echo 0; elif [ \"\$1\" = list ]; then echo tank; else echo fixture; fi\n");
chmod('/usr/local/bin/zfs',0755);chmod('/usr/local/bin/zpool',0755);
$daemon=proc_open([PHP_BINARY,$plugin.'/coordinator-daemon.php'],[1=>['file','/tmp/auto-mutation-daemon.log','a'],2=>['file','/tmp/auto-mutation-daemon.log','a']],$pipes);
try {
    until(function(){try{return rpc(['action'=>'status']);}catch(Throwable $e){return false;}});
    if(!empty($partialReplan)) {
        $expected=empty($changedCompletedIdentity)?'complete':'failed';
        $run=until(function()use($expected){foreach(rpc(['action'=>'status'])['runs'] as $r)if($r['schedule']==='auto' && $r['state']===$expected && ($r['autoReplanCount'] ?? 0)===1)return $r;return false;});
        check(($run['autoReplanCount'] ?? 0)===1,'Actual automatic run did not replan once: '.json_encode($run));
        $state=json_decode(file_get_contents('/tmp/auto-zfs.json'),true);
        $created=array_values(array_filter($state['mutations'],static fn($row)=>$row[0]==='snapshot'));
        if(!empty($changedCompletedIdentity)) {
            check(count($state['mutations'])===2 && count($created)===1,'Changed completed snapshot did not stop continuation');
            echo "PASS: actual partial Auto continuation rejects changed completed checkpoint identity before further mutation\n";
            return;
        }
        check(count($state['mutations'])===3 && count($created)===2,'Partial continuation repeated completed mutations: '.json_encode($state));
        check(str_starts_with($created[0][1],'tank/data@') && str_starts_with($created[1][1],'tank/new@'),'Continuation failed to preserve completed dataset work');
        check(count(array_filter(rpc(['action'=>'status'])['runs'],static fn($r)=>$r['schedule']==='auto'))===1,'Continuation replaced the operation or accepted occurrence');
        echo "PASS: actual daemon continues after a settings save, verifies and preserves completed snapshot identity, creates only the new dataset checkpoint and retains the occurrence\n";
        return;
    }
    $receipt=rpc(['action'=>'auto','commandId'=>'individual-auto']);
    $run=until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $r)if($r['id']===$receipt['runId']&&in_array($r['state'],['complete','failed','canceled'],true))return $r;return false;});
    check($run['state']==='complete',json_encode($run).' '.@file_get_contents('/var/log/zfs_snapsync.log').' '.json_encode(rpc(['action'=>'operation_detail','runId'=>$run['id']])));
    $state=json_decode(file_get_contents('/tmp/auto-zfs.json'),true);
    check(count($state['mutations'])===2 && $state['mutations'][0][0]==='destroy' && $state['mutations'][1][0]==='snapshot','Auto retention/snapshot ordering changed: '.json_encode($state));
    check(str_contains($state['mutations'][0][2],':mutation') && str_contains($state['mutations'][1][2],':mutation'),'Policy driver executed a mutation directly');
    check(count($run['tasks'])===3,'Automatic mutations lack separate task records');
    check(isset($state['snapshots']['tank/data@auto-new']),'Newest prior snapshot was deleted');
    // Lifecycle draining must not deadlock the policy driver on its child.
    file_put_contents('/tmp/auto-zfs.json',json_encode($initial));file_put_contents('/tmp/block-auto-mutation','1');
    $receipt=rpc(['action'=>'auto','commandId'=>'drain-individual-auto']);
    until(fn()=>is_file('/tmp/auto-mutation-entered'));
    file_put_contents('/var/run/zfs-snapsync-coordinator/refresh.json',json_encode(['message'=>'Fixture drain']));
    unlink('/tmp/block-auto-mutation');
    $drained=until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $r)if($r['id']===$receipt['runId']&&in_array($r['state'],['complete','failed'],true))return $r;return false;});
    check($drained['state']==='complete','Lifecycle drain stranded automatic child tasks: '.json_encode($drained));
    unlink('/var/run/zfs-snapsync-coordinator/refresh.json');unlink('/tmp/auto-mutation-entered');
    // Dry Run must retain the policy preview without granting mutation tasks.
    file_put_contents('/tmp/auto-zfs.json',json_encode($initial));
    file_put_contents($dir.'/zfs_snapsync.conf',"\nDRY_RUN=\"1\"\n",FILE_APPEND);
    $receipt=rpc(['action'=>'auto','commandId'=>'dry-individual-auto']);
    $dry=until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $r)if($r['id']===$receipt['runId']&&in_array($r['state'],['complete','failed'],true))return $r;return false;});
    check($dry['state']==='complete' && count($dry['tasks'])===1 && json_decode(file_get_contents('/tmp/auto-zfs.json'),true)['mutations']===[],'Dry Run granted a mutation');
    file_put_contents($dir.'/zfs_snapsync.conf',"\nDRY_RUN=\"0\"\n",FILE_APPEND);
    file_put_contents('/tmp/auto-zfs.json',json_encode($initial));file_put_contents('/tmp/block-auto-mutation','1');
    $receipt=rpc(['action'=>'auto','commandId'=>'cancel-individual-auto']);
    until(fn()=>is_file('/tmp/auto-mutation-entered'));
    if(!empty($crashRecovery)) {
        proc_terminate($daemon,9);proc_close($daemon);$daemon=null;
        $restart=proc_open([PHP_BINARY,$plugin.'/coordinator-lifecycle.php','watchdog'],[1=>['file','/tmp/auto-restart.log','a'],2=>['file','/tmp/auto-restart.log','a']],$pipes);
        check(proc_close($restart)===0,'Watchdog could not recover recorded workers: '.@file_get_contents('/tmp/auto-restart.log'));
        until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $r)if($r['id']===$receipt['runId'])return $r['state']==='failed';return false;});
        check(json_decode(file_get_contents('/tmp/auto-zfs.json'),true)['mutations']===[],'Watchdog replayed or released an interrupted automatic mutation');
        echo "PASS: actual watchdog restarts after coordinator loss, retains operation history, revokes recorded workers and requires fresh review without replay\n";
        return;
    }
    rpc(['action'=>'cancel','runId'=>$receipt['runId']]);
    until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $r)if($r['id']===$receipt['runId'])return $r['state']==='canceled';return false;});
    check(json_decode(file_get_contents('/tmp/auto-zfs.json'),true)['mutations']===[],'Canceled Auto child mutated storage');
    rpc(['action'=>'resume']);unlink('/tmp/auto-mutation-entered');
    $receipt=rpc(['action'=>'auto','commandId'=>'lost-auto-policy']);
    until(fn()=>is_file('/tmp/auto-mutation-entered'));
    $detail=rpc(['action'=>'operation_detail','runId'=>$receipt['runId']]);
    // Inspect the committed launch record without creating a second journal owner.
    $checkpoint=json_decode(file_get_contents('/tmp/zfs-snapsync-coordinator/checkpoint.json'),true);
    $journal=json_decode($checkpoint['payload'],true);
    foreach(file('/tmp/zfs-snapsync-coordinator/journal.ndjson',FILE_IGNORE_NEW_LINES) as $line) {
        $envelope=json_decode($line,true);$event=json_decode($envelope['payload'],true);
        if($event['sequence']<=$journal['sequence'])continue;
        foreach($event['put'] as $collection=>$rows)foreach($rows as $id=>$row)$journal[$collection][$id]=$row;
        $journal['sequence']=$event['sequence'];
    }
    $parent=$journal['tasks'][$receipt['runId'].':snapshot'];$attempt=$journal['attempts'][$parent['attempt']];
    $kill=proc_open(['/bin/kill','-KILL','--','-'.$attempt['pid']],[1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);check(proc_close($kill)===0,'Could not inject driver loss');
    until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $r)if($r['id']===$receipt['runId'])return $r['state']==='failed';return false;});
    check(json_decode(file_get_contents('/tmp/auto-zfs.json'),true)['mutations']===[],'Orphaned policy approval allowed a mutation');
    echo "PASS: actual automatic policy and daemon, individual shared deletion and snapshot tasks, ordering, identities, lifecycle draining, Dry Run, child cancellation and revoked orphan authority\n";
} finally {
    if(is_resource($daemon)){proc_terminate($daemon);proc_close($daemon);}
    elseif(!empty($crashRecovery)) {
        $owner=json_decode((string)@file_get_contents('/var/run/zfs-snapsync-coordinator/owner.json'),true);
        if($owner)posix_kill($owner['pid'],15);
    }
}
