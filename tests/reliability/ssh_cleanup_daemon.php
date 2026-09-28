<?php
require __DIR__.'/ssh_receiver_read.php';
$plugin=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php');
require_once $plugin.'/coordinator-state.php';
require_once $plugin.'/coordinator-socket.php';
require_once $plugin.'/replication-cleanup.php';
require_once $plugin.'/send-helpers.php';
command(['usermod','-a','-G','users','receiver']);
$stateFile=$root.'/cleanup-daemon.json';$prefix='snapsync-send-abcdef123456-';
file_put_contents($stateFile,json_encode(['deleted'=>[], 'snapshots'=>[
    $prefix.'new'=>['guid'=>'200','txg'=>'20','created'=>time()],
    $prefix.'old'=>['guid'=>'100','txg'=>'10','created'=>1],
]]));chmod($stateFile,0666);
file_put_contents('/usr/local/bin/zfs', <<<'PY'
#!/usr/bin/python3
import sys,json
a=sys.argv[1:];path='/tmp/ssh-read-fixture/cleanup-daemon.json'
with open(path) as f:s=json.load(f)
name=a[-1];fields=a[a.index('-o')+1] if '-o' in a else ''
if a[0]=='list':
 for snap,row in s['snapshots'].items():
  values={'name':'backup/data@'+snap,'guid':row['guid'],'createtxg':row['txg'],'creation':str(row['created']),'used':'10','userrefs':'0','clones':'-'}
  print('\t'.join(values[x] for x in fields.split(',')))
elif a[0]=='get':
 for prop in a[a.index('--')-1].split(','):
  if prop=='receive_resume_token': print('-')
  elif '@' not in name: print('20')
  else:
   row=s['snapshots'][name.split('@')[1]]
   print({'guid':row['guid'],'createtxg':row['txg'],'userrefs':'0','clones':'-'}[prop])
elif a[0]=='destroy':
 del s['snapshots'][name.split('@')[1]]
 s['deleted'].append(name)
 with open(path,'w') as f:json.dump(s,f)
else:sys.exit(2)
PY);
chmod('/usr/local/bin/zfs',0755);
$ssh=server('replacement');$daemon=null;
try {
    $dir='/boot/config/plugins/zfs.snapsync';@mkdir($dir,0770,true);
    file_put_contents($dir.'/zfs_snapsync.conf',"DATASETS=\"\"\n");
    file_put_contents($dir.'/zfs_send.conf',zfsas_send_render_config(array_replace(zfsas_send_defaults(),$config)));
    $revision=zfsas_config_revision($dir);$reader=new ZfsasSshReceiverRead($config,'backup');
    $capture=['config'=>$config,'identity'=>$reader->identity()];$endpoint=$reader->identity()['endpoint'];
    $request=['transport'=>'ssh','sourceSnapshot'=>'tank/data@new','sourceGuid'=>'200','destination'=>'backup/data'];
    $inspection=['receiverEndpoint'=>$endpoint,'destinationDatasetGuid'=>'20','references'=>[]];
    $policy=['scheduleId'=>'abcdef123456','prefix'=>'snapsync-send-','sendConfigHash'=>hash('sha256',file_get_contents($dir.'/zfs_send.conf')),'keepAll'=>14,'keepDaily'=>30,'keepWeekly'=>183];
    $tasks=zfsas_replication_cleanup($request,$inspection,$policy,[$reader,'read']);
    check(count($tasks)===1,'Remote daemon fixture did not plan one deletion');
    foreach($tasks as &$task){$task['parameters']+=['receiverCapture'=>$capture,'remoteOwnership'=>true,'revision'=>$revision];}unset($task);
    $journal=new ZfsasCoordinatorState('/tmp/zfs-snapsync-coordinator');$runs=[];
    foreach(['owner-one','owner-two'] as $command){$runs[]=$journal->submit($command,['revision'=>$revision,'tasks'=>$tasks],time())['runId'];}
    unset($journal);
    @mkdir('/var/local/emhttp',0770,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STARTED"');
    $daemon=proc_open([PHP_BINARY,$plugin.'/coordinator-daemon.php'],[1=>['file',$root.'/cleanup-daemon.log','a'],2=>['file',$root.'/cleanup-daemon.log','a']],$pipes);
    $end=microtime(true)+45;$complete=false;$status=[];
    do {
        try {$reply=zfsas_coordinator_request(['action'=>'status']);$status=$reply['result']['runs'] ?? [];}catch(Throwable $e){}
        $finished=array_filter($status,static fn($r)=>in_array($r['id'],$runs,true)&&ZfsasCoordinatorState::terminal($r['state']));
        if(count($finished)===2){$complete=true;break;}usleep(30000);
    }while(microtime(true)<$end);
    check($complete,'Remote daemon cleanup timed out: '.json_encode($status));
    foreach($finished as $run){check($run['state']==='complete','Remote cleanup owner failed: '.json_encode($run));}
    $state=json_decode(file_get_contents($stateFile),true);
    check($state['deleted']===['backup/data@'.$prefix.'old'],'Shared SSH cleanup did not perform exactly one deletion');
    check(isset($state['snapshots'][$prefix.'new']),'Remote cleanup lost the newest checkpoint');
    echo "PASS: actual coordinator shared SSH deletion, immutable owner selection, live worker authorization, result fanout and verified receiver shutdown\n";
} finally {
    if(is_resource($daemon)){proc_terminate($daemon);proc_close($daemon);}
    proc_terminate($ssh);proc_close($ssh);
}
