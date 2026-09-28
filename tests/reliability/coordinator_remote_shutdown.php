<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-executor.php';
function check($ok,$message): void {if(!$ok)throw new RuntimeException($message);}
function drive($executor,$predicate): void {
    $end=microtime(true)+5;
    do {$executor->tick(hrtime(true)/1e9);if($predicate())return;usleep(10000);}while(microtime(true)<$end);
    throw new RuntimeException('Remote shutdown fixture timed out');
}
$root='/tmp/remote-shutdown-'.bin2hex(random_bytes(8));mkdir($root);
$journal=new ZfsasCoordinatorState($root);
$verified=false;$polled=[];
$command=fn($task)=>['/bin/sleep',$task['parameters']['sleep'] ?? '.01'];
$outcome=fn()=>['outcome'=>'success'];
$probe=function($task,$attempt)use(&$verified,&$polled){$polled[$attempt['token']]=$task['id'];return $verified;};
$executor=new ZfsasCoordinatorExecutor($journal,$root,$root.'/runtime',$command,$outcome,[],null,$probe);
$receipt=$journal->submit('remote-success',['tasks'=>['send'=>['kind'=>'send','parameters'=>['remoteOwnership'=>true]]]],time());
$id=$receipt['runId'].':send';
drive($executor,fn()=>($journal->state['tasks'][$id]['blocked'] ?? '')==='receiver_shutdown');
$token=$journal->state['tasks'][$id]['attempt'];
check($journal->state['version']===5,'Remote execution did not upgrade the journal authority boundary');
$checkpoint=json_decode(file_get_contents($root.'/checkpoint.json'),true);
check(json_decode($checkpoint['payload'],true)['version']===5,'Remote grant preceded old-reader rejection');
check($journal->state['runs'][$receipt['runId']]['state']==='running' && isset($polled[$token]),'Local exit released remote execution');
$verified=true;drive($executor,fn()=>$journal->state['runs'][$receipt['runId']]['state']==='complete');
$verified=false;
$receipt=$journal->submit('remote-cancel',['tasks'=>['send'=>['kind'=>'send','parameters'=>['remoteOwnership'=>true,'sleep'=>'60']]]],time());
$id=$receipt['runId'].':send';drive($executor,fn()=>$journal->state['tasks'][$id]['state']==='running');
$token=$journal->state['tasks'][$id]['attempt'];$executor->cancel($receipt['runId']);
drive($executor,fn()=>($journal->state['tasks'][$id]['blocked'] ?? '')==='receiver_shutdown');
check($journal->state['runs'][$receipt['runId']]['state']==='canceling' && $journal->state['attempts'][$token]['state']!=='stopped','Cancellation released an unverified receiver');
// A coordinator restart must continue the same remote fence and withhold grants.
unset($executor);
$executor=new ZfsasCoordinatorExecutor($journal,$root,$root.'/runtime',$command,$outcome,[],null,$probe);
$other=$journal->submit('unrelated',['tasks'=>['work'=>['kind'=>'auto']]],time());
for($i=0;$i<5;$i++){$executor->tick(hrtime(true)/1e9);}
check($journal->state['tasks'][$other['runId'].':work']['attempt']===null,'Recovery issued a grant before receiver shutdown');
$verified=true;drive($executor,fn()=>$journal->state['runs'][$receipt['runId']]['state']==='canceled');
drive($executor,fn()=>$journal->state['runs'][$other['runId']]['state']==='complete');
// A recovered remote attempt requires a fresh review even after proven stop.
$receipt=$journal->submit('remote-interrupted',['tasks'=>['send'=>['kind'=>'send','parameters'=>['remoteOwnership'=>true,'sleep'=>'60']]]],time());
$id=$receipt['runId'].':send';drive($executor,fn()=>$journal->state['tasks'][$id]['state']==='running');
unset($executor);$executor=new ZfsasCoordinatorExecutor($journal,$root,$root.'/runtime',$command,$outcome,[],null,$probe);
drive($executor,fn()=>$journal->state['runs'][$receipt['runId']]['state']==='failed');
check($journal->state['tasks'][$id]['result']['recoveryRequired']===true && $journal->state['tasks'][$id]['attempt']===null,'Remote interruption was automatically retried after shutdown');
// An absent remote adapter is not interpreted as a successful shutdown.
unset($executor);$executor=new ZfsasCoordinatorExecutor($journal,$root,$root.'/runtime',$command,$outcome);
$receipt=$journal->submit('missing-adapter',['tasks'=>['send'=>['kind'=>'send','parameters'=>['remoteOwnership'=>true]]]],time());
$id=$receipt['runId'].':send';drive($executor,fn()=>($journal->state['tasks'][$id]['blocked'] ?? '')==='receiver_shutdown');
check($journal->state['runs'][$receipt['runId']]['state']==='running','Missing remote adapter released ownership');
$executor->cancel($receipt['runId']);unset($executor);
$executor=new ZfsasCoordinatorExecutor($journal,$root,$root.'/runtime',$command,$outcome,[],null,$probe);
drive($executor,fn()=>$journal->state['runs'][$receipt['runId']]['state']==='canceled');
$receipt=$journal->submit('remote-delete',['tasks'=>['delete'=>['kind'=>'delete','parameters'=>['remoteOwnership'=>true,'receiverLeases'=>[]]]]],time());
check($journal->state['version']===6,'Remote cleanup retained an executor format that cannot fence guards or destruction');
$checkpoint=json_decode(file_get_contents($root.'/checkpoint.json'),true);
check(json_decode($checkpoint['payload'],true)['version']===6,'Remote cleanup admission preceded old-reader rejection');
echo "PASS: remote shutdown holds completion, cancellation, restart grants and missing-adapter ownership; cleanup upgrades its authority format\n";
