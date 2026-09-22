<?php
$plugin=__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php';
require $plugin.'/coordinator-state.php';require $plugin.'/coordinator-source-retention.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function reject($fn){try{$fn();}catch(InvalidArgumentException $error){return;}throw new RuntimeException('Unsafe publication accepted');}
$root='/tmp/source-coordinator-'.bin2hex(random_bytes(8));$journal=new ZfsasCoordinatorState($root);
$job=['id'=>'abcdef123456','source'=>'tank/data','destination'=>'backup/data','children'=>'0','transport'=>'local'];
$policy=['keep'=>3,'binding'=>zfsas_source_binding($job),'datasets'=>['tank/data'=>'10']];
$ref=['role'=>'source','endpoint'=>'local','dataset'=>'tank/data','datasetGuid'=>'10','snapshot'=>'tank/data@s7','guid'=>'107'];
$spec=['revision'=>'rev','tasks'=>[
 'prepare'=>['kind'=>'prepare','parameters'=>['phase'=>'replication_schedule','job'=>$job,'sourcePolicy'=>$policy]],
 'snapshot'=>['kind'=>'auto','parameters'=>['phase'=>'replication_snapshot','source'=>'tank/data','sourceDatasetGuid'=>'10','destination'=>'backup/data','snapshotName'=>'s7']],
 'verify'=>['kind'=>'finalize']]];
function complete($journal,$task,$result=['outcome'=>'success']):void{
 $token=$journal->claim($task,1,time(),'generation');$journal->started($task,$token,123,'456');
 $journal->workerReport(['type'=>'result','sequence'=>1,'payload'=>$result,'taskId'=>$task,'token'=>$token,'generation'=>'generation'],'generation',time());
 $journal->result($task,$token,$result,1,time(),true);
}
$run=$journal->submit('replication',$spec,time())['runId'];
complete($journal,$run.':prepare');complete($journal,$run.':snapshot',['outcome'=>'success','reference'=>$ref]);
check(empty($journal->state['runs'][$run]['sourceCleanupPending']),'Cleanup started before full verification');
complete($journal,$run.':verify');check($journal->state['runs'][$run]['sourceCleanupPending'],'Completion handoff not journaled');
unset($journal);$journal=new ZfsasCoordinatorState($root);
zfsas_coordinator_source_followup($journal,$run);$child=$journal->state['runs'][$run]['sourceCleanupRunId'];
check($journal->state['runs'][$child]['sourceCleanupOf']===$run,'Follow-up lacks parent link');
zfsas_coordinator_source_followup($journal,$run);check(count($journal->state['runs'])===2,'Repeated completion duplicated cleanup');
// Simulate the publication boundary between accepting the child and linking it.
$journal->state['runs'][$run]['sourceCleanupPending']=true;$journal->commit();unset($journal);$journal=new ZfsasCoordinatorState($root);
zfsas_coordinator_source_followup($journal,$run);check(count($journal->state['runs'])===2,'Interrupted handoff duplicated cleanup');
$journal->state['runs'][$run]['finishedAt']=1;$journal->commit();$journal->prune(time());check(isset($journal->state['runs'][$run]),'Pruned evidence needed by active cleanup');
$task=$child.':member-0';$token=$journal->claim($task,1,time(),'generation');$journal->started($task,$token,123,'456');
$plan=['tasks'=>['delete'=>['kind'=>'delete','dataset'=>'tank/data','parameters'=>['phase'=>'source_retention_delete','source'=>'tank/data','revision'=>'rev','candidates'=>[['snapshot'=>'tank/data@s1','guid'=>'101']]]],
 'finish'=>['kind'=>'finalize','dataset'=>'tank/data','parameters'=>['phase'=>'source_retention_guard','revision'=>'rev','source'=>'tank/data']]]];
$journal->workerReport(['type'=>'plan','sequence'=>1,'payload'=>$plan,'taskId'=>$task,'token'=>$token,'generation'=>'generation'],'generation',time());
$journal->result($task,$token,['outcome'=>'success'],1,time(),true);
$delete=$task.':delete';$token=$journal->claim($delete,1,time(),'generation');$journal->started($delete,$token,123,'456');
$foreign=$ref;$foreign['guid']='101';$foreign['snapshot']='other/data@selected';$foreign['dataset']='other/data';
reject(fn()=>$journal->submit('racing-send',['tasks'=>['send'=>['kind'=>'send','references'=>[$foreign]]]],time()));
$item=['snapshot'=>'tank/data@s1','guid'=>'101','state'=>'completed','message'=>'Deleted'];
$report=['type'=>'source_item','sequence'=>1,'payload'=>$item,'taskId'=>$delete,'token'=>$token,'generation'=>'generation'];
$journal->workerReport($report,'generation',time());$journal->workerReport($report,'generation',time());
check(count($journal->state['tasks'][$delete]['sourceResults'])===1,'Duplicate item result changed accounting');
$stale=$report;$stale['token']=str_repeat('0',48);reject(fn()=>$journal->workerReport($stale,'generation',time()));
$bad=$report;$bad['sequence']=2;$bad['payload']['guid']='999';reject(fn()=>$journal->workerReport($bad,'generation',time()));
$journal->result($delete,$token,['outcome'=>'transient_failure'],1,time(),true);
$command=zfsas_coordinator_source_command($journal->state['tasks'][$delete],$journal,$root,'rev');
$capture=json_decode(file_get_contents($command[2]),true);check($capture['parameters']['candidates']===[],'Retry repeated a committed successful item');
$journal->cancel($child,time());check($journal->state['runs'][$run]['state']==='complete','Cleanup cancellation changed replication success');
// Failed or canceled replication never schedules source cleanup.
foreach(['failed','canceled'] as $state){$r=$journal->submit($state,$spec,time())['runId'];if($state==='canceled')$journal->cancel($r,time());else{$t=$journal->claim($r.':prepare',1,time());$journal->result($r.':prepare',$t,['outcome'=>'validation_failure'],1,time(),true);}check(empty($journal->state['runs'][$r]['sourceCleanupPending']),'Failed replication authorized cleanup');}
unset($journal);$empty=new ZfsasCoordinatorState($root.'-reboot');check(!$empty->state['runs'],'RAM loss reconstructed cleanup authority');
echo "PASS: atomic source cleanup handoff, restart/idempotency, source deletion reference fence, per-item results, stale workers, parent success and RAM-loss boundary\n";
