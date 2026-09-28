<?php
// Reuse the real SSH setup and first exercise trust/ownership faults. This suite
// then drives the actual native phase adapter with a deterministic ZFS fixture.
require __DIR__.'/ssh_receiver_read.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/replication-ssh-phase.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/replication-recovery.php';
$ssh=server('replacement');
$stateFile=$root.'/phase.json';
file_put_contents($stateFile,json_encode(['new'=>false,'received'=>false,'readonly'=>'off']));chmod($stateFile,0666);
file_put_contents($root.'/phase-commands','');chmod($root.'/phase-commands',0666);
file_put_contents('/usr/local/bin/zfs', <<<'PY'
#!/usr/bin/python3
import os,sys,json
args=sys.argv[1:]
remote=os.geteuid()!=0
path='/tmp/ssh-read-fixture/phase.json'
with open(path) as inp: state=json.load(inp)
target=args[-1]
if args[0]=='get':
 prop=args[args.index('value')+1]
 if prop=='guid':
  print('100' if target.endswith('@base') else '200' if target.endswith('@next') else '222' if target=='backup' else '333' if remote else '111')
 elif prop=='available': print('10000000000')
 elif prop=='receive_resume_token': print(state.get('resume','-'))
 elif prop=='readonly': print(state['readonly'])
 else: sys.exit(3)
elif args[0]=='list':
 if 'snapshot' in args:
  if not state['new'] or not remote: print(target+'@base\t100\t1')
  if not remote or state['received']: print(target+'@next\t200\t2')
 else:
  print('backup')
  if not state['new'] or state['received']: print('backup/data')
elif args[0]=='send' and not remote:
 if '-nvt' in args: print('toname = tank/source@next\ntoguid = 200\nfromguid = 100')
 elif '-nP' in args: print('size\t1000')
 else:
  with open('/tmp/ssh-read-fixture/phase-commands','a') as out: out.write(json.dumps(['source']+args)+'\n')
  sys.stdout.write('fixture-stream-200')
elif args[0]=='receive' and remote:
 data=sys.stdin.read()
 if data!='fixture-stream-200' or '-F' in args: sys.exit(4)
 state['received']=True
 state['resume']='-'
 state['readonly']=args[args.index('-o')+1].split('=')[1]
 with open(path,'w') as out: json.dump(state,out)
 with open('/tmp/ssh-read-fixture/phase-commands','a') as out: out.write(json.dumps(['receiver']+args)+'\n')
elif args[0]=='set' and remote:
 state['readonly']=args[1].split('=')[1]
 with open(path,'w') as out: json.dump(state,out)
else: sys.exit(5)
PY);
chmod('/usr/local/bin/zfs',0755);
$attempts=[];
try {
    $reader=new ZfsasSshReceiverRead($config,'backup');$identity=$reader->identity();
    $capture=['config'=>$config,'identity'=>$identity];
    $request=['transport'=>'ssh','sourceSnapshot'=>'tank/source@next','sourceGuid'=>'200','destination'=>'backup/data','destinationGuid'=>'333'];
    $inspection=ZfsasReplicationInspection::inspect($request,null,[$reader,'read'],$identity['endpoint']);
    check($inspection['inspection']['mode']==='incremental','Remote common base was not selected');
    $plan=zfsas_replication_plan($request,$inspection['inspection'],str_repeat('a',64),'0',$capture);
    check(empty($plan['tasks']['space']['parameters']['remoteOwnership']) && $plan['tasks']['transfer']['parameters']['remoteOwnership'] && $plan['tasks']['verify']['parameters']['remoteOwnership'],'Plan failed to bind mutating phases to remote shutdown');
    $reports=[];$sequence=1;$report=static function($type,$seq,$payload)use(&$reports){$reports[]=$payload;};
    $shutdown=new ZfsasRemoteShutdown();
    $phase=static function($p)use(&$attempts,&$sequence,$report,$shutdown):array {
        $token=$attempts[]=bin2hex(random_bytes(24));putenv('ZFSAS_ATTEMPT_TOKEN='.$token);
        $result=zfsas_replication_ssh_phase($p,$sequence,$report);
        if (!empty($p['remoteOwnership'])) {
            $end=microtime(true)+10;
            do {$done=$shutdown->poll(['parameters'=>$p],['token'=>$token]);if($done)break;usleep(20000);}while(microtime(true)<$end);
            check($done,'Native remote phase did not verify receiver shutdown');
        }
        return $result;
    };
    check($phase($plan['tasks']['space']['parameters'])['outcome']==='success','Remote space approval failed');
    $result=$phase($plan['tasks']['transfer']['parameters']);
    check($result['inspection']['mode']==='already_received','Native SSH transfer did not verify the resulting checkpoint');
    check($phase($plan['tasks']['verify']['parameters'])['outcome']==='success','Native SSH finalization failed');
    $commands=array_map(fn($line)=>json_decode($line,true),file($root.'/phase-commands'));
    check(count($commands)===2 && in_array('-i',$commands[0],true) && in_array('tank/source@base',$commands[0],true) && !in_array('-F',$commands[1],true),'SSH incremental stream changed execution semantics');
    check(json_decode(file_get_contents($stateFile),true)['readonly']==='on','Backup receiver was left writable');
    // New-target restore streams fully, remains unmounted, and becomes writable.
    file_put_contents($stateFile,json_encode(['new'=>true,'received'=>false,'readonly'=>'off']));
    $request=['transport'=>'ssh','sourceSnapshot'=>'tank/source@next','sourceGuid'=>'200','destination'=>'backup/data','createDestination'=>true,'destinationParentGuid'=>'222','purpose'=>'restore'];
    $inspection=ZfsasReplicationInspection::inspect($request,null,[$reader,'read'],$identity['endpoint']);
    check($inspection['inspection']['mode']==='full','New receiver did not require a full stream');
    $plan=zfsas_replication_plan($request,$inspection['inspection'],str_repeat('a',64),'0',$capture);
    check($phase($plan['tasks']['transfer']['parameters'])['inspection']['mode']==='already_received','New-target SSH transfer failed');
    check($phase($plan['tasks']['verify']['parameters'])['outcome']==='success','Writable restore verification failed');
    $commands=array_map(fn($line)=>json_decode($line,true),file($root.'/phase-commands'));
    check(!in_array('-i',$commands[2],true) && in_array('-u',$commands[3],true) && json_decode(file_get_contents($stateFile),true)['readonly']==='off','Full restore or mount policy changed');
    file_put_contents($stateFile,json_encode(['new'=>false,'received'=>false,'readonly'=>'on','resume'=>'opaque-resume']));
    $review=zfsas_recovery_inspect_member(['source'=>'tank/source','sourceDatasetGuid'=>'111','destination'=>'backup/data','transport'=>'ssh','receiverGuid'=>'333','receiverParentGuid'=>'222'],null,[$reader,'read'],$identity['endpoint']);
    check($review['eligible'] && $review['inspection']['mode']==='resume' && $review['snapshot']==='tank/source@next','SSH recovery did not retain the original interrupted snapshot');
    $plan=zfsas_replication_plan($review['request'],$review['inspection'],str_repeat('a',64),'0',$capture);
    check($phase($plan['tasks']['transfer']['parameters'])['inspection']['mode']==='already_received','Reviewed SSH resume did not verify completion');
    check($phase($plan['tasks']['verify']['parameters'])['outcome']==='success','SSH resume finalization failed');
    $commands=array_map(fn($line)=>json_decode($line,true),file($root.'/phase-commands'));
    check(in_array('-t',$commands[4],true) && in_array('opaque-resume',$commands[4],true),'SSH recovery substituted a fresh stream');
    echo "PASS: native SSH incremental/full phases, receiver space, exact checkpoint verification, protection and unmounted restore semantics\n";
} finally {proc_terminate($ssh);proc_close($ssh);}
