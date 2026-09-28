<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-state.php';
function check($ok,$why):void{if(!$ok)throw new RuntimeException($why);}
function reject($fn):void{try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Invalid Auto mutation was accepted');}
$root='/tmp/auto-mutations-'.bin2hex(random_bytes(6));$j=new ZfsasCoordinatorState($root);$generation='fixture';
$run=$j->submit('auto-driver',['tasks'=>['driver'=>['kind'=>'auto','parameters'=>[
    'individualMutations'=>true,'revision'=>str_repeat('a',64),'mutationDatasets'=>['tank/data'],'mutationPrefix'=>'auto-']]]],100)['runId'];
$parent=$run.':driver';$token=$j->claim($parent,1,101,$generation);$j->started($parent,$token,123,'1');
$grant=['taskId'=>$parent,'token'=>$token,'generation'=>$generation];
$proposal=['action'=>'delete','snapshot'=>'tank/data@auto-old','policyDataset'=>'tank/data','datasetGuid'=>'10','guid'=>'100','txg'=>'1','inventoryHash'=>str_repeat('b',64),'reason'=>'age_window'];
$report=static fn($sequence,$payload)=>$j->workerReport($grant+['sequence'=>$sequence,'type'=>'auto_mutation','payload'=>$payload],$generation,102);
$first=$report(1,$proposal);$child=$first['mutationId'];
check($j->state['version']===7,'Individual mutation authority did not reject older executors');
check($first===$report(1,$proposal) && count($j->state['runs'][$run]['tasks'])===2,'Duplicate proposal created another mutation');
check($j->state['tasks'][$child]['kind']==='delete' && $j->autoMutationParentLive($j->state['tasks'][$child]),'Mutation lost its live policy owner');
reject(fn()=>$report(2,array_replace($proposal,['snapshot'=>'tank/other@auto-old'])));
reject(fn()=>$report(2,array_replace($proposal,['snapshot'=>'tank/data@foreign'])));
reject(fn()=>$report(2,array_replace($proposal,['snapshot'=>'tank/data@auto-next','guid'=>'101'])));
reject(fn()=>$j->autoMutationStatus($grant+['mutationId'=>'another-task'],$generation));
$sequence=$j->state['sequence'];
check($j->autoMutationStatus($grant+['mutationId'=>$child],$generation)['state']==='queued','Mutation status lost its pending result');
check($sequence===$j->state['sequence'],'Read-only mutation polling appended journal events');
$attempt=$j->claim($child,2,102,$generation);$j->started($child,$attempt,124,'2');
$j->result($child,$attempt,['outcome'=>'success','itemState'=>'completed'],3,103,true);
$next=['action'=>'snapshot','snapshot'=>'tank/data@auto-next','policyDataset'=>'tank/data','datasetGuid'=>'10','inventoryHash'=>str_repeat('c',64)];
$creation=$report(2,$next)['mutationId'];
check($j->state['tasks'][$creation]['kind']==='finalize','Snapshot creation competes with its own policy-driver slot');
check($j->autoMutationStatus($grant+['mutationId'=>$child],$generation)['result']['itemState']==='completed','Completed deletion result was lost');
$createToken=$j->claim($creation,4,104,$generation);$j->started($creation,$createToken,125,'3');
$authorize=['taskId'=>$creation,'token'=>$createToken,'generation'=>$generation,'sequence'=>1,'type'=>'auto_authorize','payload'=>['snapshot'=>$next['snapshot'],'datasetGuid'=>'10']];
check($j->workerReport($authorize,$generation,104)['authorized'],'Live automatic snapshot grant rejected');
$j->cancel($run,104);
check(!$j->autoMutationParentLive($j->state['tasks'][$creation]),'Canceled driver still lends mutation authority');
reject(fn()=>$j->autoMutationStatus($grant+['mutationId'=>$creation],$generation));
reject(fn()=>$j->workerReport($authorize,$generation,104));
echo "PASS: individual Auto mutation identity, duplicate submission, scope rejection, ordered ownership, journal compatibility and read-only status\n";
