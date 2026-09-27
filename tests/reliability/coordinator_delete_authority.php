<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-state.php';
function check($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
function rejects($fn) { try { $fn(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException('Expired deletion authority accepted'); }
$root = '/tmp/delete-authority-'.bin2hex(random_bytes(6));
$j = new ZfsasCoordinatorState($root);
$generation = str_repeat('a',48);
$job = ['JOB_ID'=>'fixture', 'SNAPSHOT'=>'tank/data@old', 'SNAPSHOT_GUID'=>'123'];
$owner = $j->submit('owner', ['tasks'=>['wait'=>['kind'=>'batch']]], 100)['runId'];
$run = $j->submit('delete', ['tasks'=>['delete'=>['kind'=>'delete',
    'parameters'=>['endpoint'=>'local','ownerRunId'=>$owner,'deleteJob'=>$job]]]], 100)['runId'];
$id = $run.':delete'; $token = $j->claim($id,0,100,$generation); $j->started($id,$token,123,'1');
$request = ['taskId'=>$id,'token'=>$token,'generation'=>$generation,'sequence'=>1,'type'=>'delete_authorize',
    'payload'=>['jobId'=>'fixture','snapshot'=>$job['SNAPSHOT'],'guid'=>'123']];
$bad = $request; $bad['payload']['guid']='999';
rejects(fn()=>$j->workerReport($bad,$generation,100));
foreach ([[], ['jobId'=>'fixture','snapshot'=>$job['SNAPSHOT'],'guid'=>123], $request['payload']+['ownerRunId'=>$owner]] as $payload) {
    $bad=$request;$bad['payload']=$payload;
    rejects(fn()=>$j->workerReport($bad,$generation,100));
}
check($j->workerReport($request,$generation,100)['authorized'], 'Current authority rejected');
$sequence = $j->state['sequence'];
$j->workerReport($request,$generation,100);
check($j->state['sequence']===$sequence, 'Duplicate grant changed journal');
// A replay must recheck its independent parent, even if its own attempt survives.
$j->state['runs'][$owner]['upgradeReviewRequired']=true;
rejects(fn()=>$j->workerReport($request,$generation,101));
unset($j->state['runs'][$owner]['upgradeReviewRequired']);
$j->cancel($owner,102);
rejects(fn()=>$j->workerReport($request,$generation,102));
$j->stopped($token,103,3);
check($j->state['runs'][$owner]['state']==='canceled', 'Cancellation released its worker early');

// A running manual deletion cannot borrow authority from an unrelated batch.
$manual = $job; $manual['JOB_ID']='sm-'.str_repeat('b',32).'-item';
$run = $j->submit('unapproved', ['tasks'=>['delete'=>['kind'=>'delete','parameters'=>['deleteJob'=>$manual]]]], 104)['runId'];
$id=$run.':delete'; $token=$j->claim($id,4,104,$generation); $j->started($id,$token,124,'2');
$request['taskId']=$id; $request['token']=$token; $request['payload']['jobId']=$manual['JOB_ID'];
rejects(fn()=>$j->workerReport($request,$generation,104));
$j->cancel($run,105); $j->stopped($token,106,6);

$batchToken = str_repeat('c',32);
$spec = ['identity'=>'tank/data@old:123','snapshot'=>$job['SNAPSHOT'],'guid'=>'123','candidate'=>true,'state'=>'queued'];
$parent = $j->submit('batch-'.$batchToken, ['manual'=>true,'tasks'=>['items'=>['kind'=>'batch',
    'parameters'=>['batch'=>['token'=>$batchToken,'action'=>'delete','approvedAt'=>107]],'items'=>[$spec]]]],107)['runId'];
$itemId=$j->state['tasks'][$parent.':items']['items'][0];
$manual['JOB_ID']='sm-'.$batchToken.'-item';
$run=$j->submit('approved', ['tasks'=>['delete'=>['kind'=>'delete','parameters'=>[
    'deleteJob'=>$manual,'ownerRunId'=>$parent,'ownerItemId'=>$itemId]]]],107)['runId'];
$id=$run.':delete';
$j->state['items'][$itemId]['state']='deleting';
$j->state['items'][$itemId]['deletionTaskId']=$id;
$j->refreshDeletionBatch($parent.':items',107);
$token=$j->claim($id,7,107,$generation);$j->started($id,$token,125,'3');
$request['taskId']=$id;$request['token']=$token;$request['payload']['jobId']=$manual['JOB_ID'];
check($j->workerReport($request,$generation,107)['authorized'],'Journal-owned manual approval rejected');
$j->state['items'][$itemId]['state']='failed';
rejects(fn()=>$j->workerReport($request,$generation,108));
$j->state['items'][$itemId]['state']='deleting';
$j->state['items'][$itemId]['spec']['guid']='999';
rejects(fn()=>$j->workerReport($request,$generation,108));
$j->cancel($parent,109);$j->stopped($token,110,10);
$reference=['role'=>'source','endpoint'=>'local','dataset'=>'tank/data','datasetGuid'=>'42',
    'snapshot'=>$job['SNAPSHOT'],'guid'=>'123'];
$protector=$j->submit('protector',['tasks'=>['send'=>['kind'=>'send','references'=>[$reference]]]],111)['runId'];
$run=$j->submit('protected-delete',['tasks'=>['delete'=>['kind'=>'delete',
    'parameters'=>['endpoint'=>'local','deleteJob'=>$job]]]],111)['runId'];
$id=$run.':delete';$token=$j->claim($id,11,111,$generation);$j->started($id,$token,126,'4');
$request['taskId']=$id;$request['token']=$token;$request['payload']['jobId']=$job['JOB_ID'];
rejects(fn()=>$j->workerReport($request,$generation,111));
$j->cancel($protector,112);
check($j->workerReport($request,$generation,112)['authorized'],'Released reference retained authority');
$j->cancel($run,113);$j->stopped($token,114,14);
echo "PASS: final deletion identity, live owner authority, replay revalidation, cancellation, protected references, and exact manual item approval\n";
