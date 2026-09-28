<?php
require __DIR__.'/ssh_receiver_read.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/replication-ssh-delete.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/replication-receiver-lease.php';
$ssh=server('replacement');
$stateFile=$root.'/cleanup.json';
$initial=['exists'=>true,'holds'=>'0','clones'=>'-','guid'=>'190','txg'=>'7','destroyed'=>0];
file_put_contents($stateFile,json_encode($initial));chmod($stateFile,0666);
file_put_contents('/usr/local/bin/zfs', <<<'PY'
#!/usr/bin/python3
import sys,json
args=sys.argv[1:]
path='/tmp/ssh-read-fixture/cleanup.json'
with open(path) as inp: state=json.load(inp)
if args[0]=='get':
 prop=args[args.index('value')+1]
 if prop=='guid': print(state['guid'] if '@' in args[-1] else '123')
 elif prop=='receive_resume_token': print('-')
 elif prop=='guid,createtxg,userrefs,clones':
  if not state['exists']: sys.exit(1)
  print('\n'.join([state['guid'],state['txg'],state['holds'],state['clones']]))
 else: sys.exit(2)
elif args[0]=='list':
 if state['exists']: print('\t'.join(['backup/data@old',state['guid'],state['txg'],'1',state['holds'],state['clones']]))
elif args==['destroy','--','backup/data@old']:
 if not state['exists'] or state['holds']!='0' or state['clones']!='-': sys.exit(3)
 state['exists']=False
 state['destroyed']+=1
 with open(path,'w') as out: json.dump(state,out)
else: sys.exit(4)
PY);
chmod('/usr/local/bin/zfs',0755);
try {
    $reader=new ZfsasSshReceiverRead($config,'backup');$capture=['config'=>$config,'identity'=>$reader->identity()];
    $receiver=new ZfsasSshReceiver($capture);$shutdown=new ZfsasRemoteShutdown();
    $job=['DATASET'=>'backup/data','DATASET_GUID'=>'123','SNAPSHOT'=>'backup/data@old','SNAPSHOT_GUID'=>'190','SNAPSHOT_CREATETXG'=>'7'];
    $inventory=static fn()=>hash('sha256',rtrim($reader->read(['list','-H','-p','-t','snapshot','-o','name,guid,createtxg,creation,userrefs,clones','-d','1','--','backup/data']),"\n"));
    $stop=static function($token)use($shutdown,$capture):void{
        $end=microtime(true)+10;
        do{if($shutdown->poll(['parameters'=>['receiverCapture'=>$capture]],['token'=>$token]))return;usleep(20000);}while(microtime(true)<$end);
        throw new RuntimeException('Receiver deletion shutdown was not verified');
    };
    $token=bin2hex(random_bytes(24));$calls=0;
    $result=zfsas_ssh_delete_execute($receiver,$token,$job,$inventory(),static function()use(&$calls){$calls++;});
    $stop($token);
    check($calls===1 && $result['itemState']==='completed' && json_decode(file_get_contents($stateFile),true)['destroyed']===1,'Remote deletion lacked one live authorization or completion');
    foreach([
        ['outcome'=>'success','itemState'=>'skipped','message'=>'Space target met.'],
        ['outcome'=>'wait','reason'=>'space','delay'=>5,'message'=>'Receiver is freeing space.'],
    ] as $decision) {
        file_put_contents($stateFile,json_encode($initial));$token=bin2hex(random_bytes(24));
        $result=zfsas_ssh_delete_execute($receiver,$token,$job,$inventory(),static fn()=>$decision);
        $stop($token);
        check($result===$decision && json_decode(file_get_contents($stateFile),true)['destroyed']===0,'Final pressure decision still granted deletion');
    }
    foreach(['revoked','holds','clones','guid','txg'] as $fault) {
        file_put_contents($stateFile,json_encode($initial));$token=bin2hex(random_bytes(24));$calls=0;
        rejected(function()use($receiver,$token,$job,$inventory,$fault,$stateFile,&$calls){return zfsas_ssh_delete_execute($receiver,$token,$job,$inventory(),static function()use($fault,$stateFile,&$calls){
            $calls++;
            if($fault==='revoked'){throw new InvalidArgumentException('Approval revoked');}
            $state=json_decode(file_get_contents($stateFile),true);$state[$fault]=$fault==='clones'?'backup/clone':'999';file_put_contents($stateFile,json_encode($state));
        });});
        $stop($token);
        check($calls===1 && json_decode(file_get_contents($stateFile),true)['destroyed']===0,'Receiver deleted after final authority or metadata changed: '.$fault);
    }
    file_put_contents($stateFile,json_encode($initial));$token=bin2hex(random_bytes(24));$calls=0;
    rejected(function()use($receiver,$token,$job,&$calls){return zfsas_ssh_delete_execute($receiver,$token,$job,str_repeat('a',64),static function()use(&$calls){$calls++;});});
    $stop($token);check($calls===0,'Changed receiver inventory reached local destructive authorization');
    $token=bin2hex(random_bytes(24));$stop($token);
    rejected(fn()=>zfsas_ssh_delete_execute($receiver,$token,$job,$inventory(),static function(){}));
    check(json_decode(file_get_contents($stateFile),true)['destroyed']===0,'Delayed remote deletion ignored its cancellation fence');
    $proof=['dataset'=>'backup/data','datasetGuid'=>'123','base'=>['snapshot'=>'backup/data@old','guid'=>'190'],'receiverCapture'=>$capture];
    $guardStop=static function($token)use($shutdown,$proof):void {
        $end=microtime(true)+10;
        do{if($shutdown->poll(['parameters'=>['receiverLeases'=>[$proof]]],['token'=>$token]))return;usleep(20000);}while(microtime(true)<$end);
        throw new RuntimeException('Receiver checkpoint guard did not stop');
    };
    $first=bin2hex(random_bytes(24));$second=bin2hex(random_bytes(24));
    $lease=new ZfsasReceiverLease($proof,$first);$otherLease=new ZfsasReceiverLease($proof,$second);
    $lease->check();$otherLease->check();
    $blocked=bin2hex(random_bytes(24));$calls=0;
    rejected(function()use($receiver,$blocked,$job,$inventory,&$calls){return zfsas_ssh_delete_execute($receiver,$blocked,$job,$inventory(),static function()use(&$calls){$calls++;});});
    check($calls===0,'Receiver deletion bypassed a source-retention checkpoint guard');$stop($blocked);
    $state=$initial;$state['guid']='191';file_put_contents($stateFile,json_encode($state));
    rejected(fn()=>$lease->check());$lease->close();$otherLease->close();$guardStop($first);$guardStop($second);
    file_put_contents($stateFile,json_encode($initial));
    $orphan=bin2hex(random_bytes(24));
    $client=proc_open($receiver->guard($orphan,$proof),[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$root.'/guard-client.log','a']],$pipes);
    check(fgets($pipes[1])==="ready\n",'Disconnect fixture did not acquire receiver guard');
    proc_terminate($client,9);foreach($pipes as $pipe){fclose($pipe);}proc_close($client);
    $blocked=bin2hex(random_bytes(24));
    rejected(fn()=>command($receiver->mutation($blocked,'readonly','backup/data','123','on')));
    $stop($blocked);$guardStop($orphan);
    echo "PASS: real SSH deletion handshake, live grant, changed inventory/GUID/TXG/holds/clones, revoked approval and delayed-launch fencing\n";
} finally {proc_terminate($ssh);proc_close($ssh);}
