<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-state.php';
function check($ok,$why):void{if(!$ok)throw new RuntimeException($why);}
$generation='fixture';$schedule=['version'=>1,'kind'=>'interval','seconds'=>3600,'anchor'=>100];
$parameters=['individualMutations'=>true,'revision'=>str_repeat('a',64),'mutationDatasets'=>['tank/data'],
    'mutationPrefix'=>'auto-','scheduleSpec'=>$schedule,'sendConfig'=>'','autoConfig'=>'','prefixHistory'=>''];
$next=array_replace($parameters,['revision'=>str_repeat('b',64),'mutationDatasets'=>['tank/data','tank/new']]);
function fixture(array $parameters,bool $manual=false,bool $uncertain=false):array {
    $j=new ZfsasCoordinatorState('/tmp/partial-auto-'.bin2hex(random_bytes(6)));$generation='fixture';
    $receipt=$j->submit('occurrence',['manual'=>$manual,'schedule'=>'auto','occurrence'=>100,'revision'=>$parameters['revision'],
        'tasks'=>['snapshot'=>['kind'=>'auto','parameters'=>$parameters]]],100);
    $parent=$receipt['runId'].':snapshot';$token=$j->claim($parent,1,101,$generation);$j->started($parent,$token,123,'1');
    $proposal=['action'=>'snapshot','snapshot'=>'tank/data@auto-made','policyDataset'=>'tank/data','datasetGuid'=>'10','inventoryHash'=>str_repeat('c',64)];
    $child=$j->workerReport(['taskId'=>$parent,'token'=>$token,'generation'=>$generation,'sequence'=>1,'type'=>'auto_mutation','payload'=>$proposal],$generation,102)['mutationId'];
    $ct=$j->claim($child,2,102,$generation);$j->started($child,$ct,124,'2');
    $j->workerReport(['taskId'=>$child,'token'=>$ct,'generation'=>$generation,'sequence'=>1,'type'=>'auto_authorize','payload'=>['snapshot'=>$proposal['snapshot'],'datasetGuid'=>'10']],$generation,102);
    $j->result($child,$ct,$uncertain?['outcome'=>'validation_failure','recoveryRequired'=>true]:['outcome'=>'success','itemState'=>'completed','guid'=>'200'],3,103,true);
    $j->result($parent,$token,['outcome'=>'validation_failure','reason'=>'configuration'],4,104,true);
    return [$j,$receipt,$parent,$child];
}
[$j,$receipt,$parent,$child]=fixture($parameters);
check(!$j->replanPartialAuto($receipt['runId'],array_replace($next,['scheduleSpec'=>['kind'=>'disabled']]),105),'Disabled schedule resumed old work');
check($j->replanPartialAuto($receipt['runId'],$next,105),'Verified partial automatic work did not replan');
$id=$j->state['tasks'][$parent]['supersededBy'];$task=$j->state['tasks'][$id];
check($j->state['tasks'][$parent]['state']==='failed' && $j->state['tasks'][$child]['state']==='complete','Replanning rewrote prior outcomes');
check($task['parameters']['completedAutoSnapshots']['tank/data']['guid']==='200' && count($task['references'])===1,'Completed snapshot identity or protection was lost');
check($j->state['schedules']['auto']['accepted']===100 && $j->state['commands']['occurrence']===$receipt,'Replanning changed occurrence or command identity');
check($j->state['version']===8 && !$j->replanPartialAuto($receipt['runId'],$next,106),'Replanning duplicated continuation or missed downgrade boundary');
$token=$j->claim($id,5,105,$generation);$j->started($id,$token,125,'3');$j->result($id,$token,['outcome'=>'success'],6,106,true);
check($j->state['runs'][$receipt['runId']]['state']==='complete' && !$j->runRequiresReview($receipt['runId']),'Superseded evidence prevented verified continuation completion');
[$manual,$receipt]=fixture($parameters,true);check(!$manual->replanPartialAuto($receipt['runId'],$next,105),'Manual approval adopted changed settings');
[$uncertain,$receipt]=fixture($parameters,false,true);check(!$uncertain->replanPartialAuto($receipt['runId'],$next,105),'Ambiguous authorized mutation was replayed');
[$stale,$receipt]=fixture($parameters);$stale->state['schedules']['auto']['runId']='newer-run';check(!$stale->replanPartialAuto($receipt['runId'],$next,105),'Old occurrence resumed after newer admission');
echo "PASS: partial Auto replanning preserves verified outcomes, checkpoint references, operation and occurrence IDs; rejects manual, changed schedules and ambiguous mutations\n";
