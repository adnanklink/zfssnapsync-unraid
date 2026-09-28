<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-state.php';
function check($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
function reject($fn) { try {$fn();} catch (InvalidArgumentException $e) {return;} throw new RuntimeException('Revoked shared cleanup grant accepted'); }
$root='/tmp/shared-cleanup-'.bin2hex(random_bytes(6));$j=new ZfsasCoordinatorState($root);
$generation=str_repeat('a',48);
function owner($j,$name,$guid='123',$endpoint='local') {
    return $j->submit($name,['tasks'=>['delete'=>['kind'=>'delete','dataset'=>'tank/data','parameters'=>[
        'nativeSchedule'=>true,'endpoint'=>$endpoint,'deleteJob'=>['JOB_ID'=>$name,'DATASET'=>'tank/data',
            'SNAPSHOT'=>'tank/data@old','SNAPSHOT_GUID'=>$guid,'SEND_CONFIG_HASH'=>hash('sha256',$name)]]]]],100)['runId'].':delete';
}
function start($j,$id,$generation,$clock=1) {
    $task=$j->cleanupExecutionTask($j->state['tasks'][$id],true);
    check($task!==null,'No independent owner selected');
    $token=$j->claim($id,$clock,100+$clock,$generation);$j->started($id,$token,123,'100');return $token;
}
function grant($j,$id,$token,$generation,$clock=101) {
    $job=$j->state['tasks'][$id]['parameters']['deleteJob'];
    return $j->workerReport(['taskId'=>$id,'token'=>$token,'generation'=>$generation,'sequence'=>1,
        'type'=>'delete_authorize','payload'=>['jobId'=>$job['JOB_ID'],'snapshot'=>$job['SNAPSHOT'],'guid'=>$job['SNAPSHOT_GUID']]],$generation,$clock);
}
$a=owner($j,'owner-a');$b=owner($j,'owner-b');
$physical=$j->delegateCleanup($a,100);
check($j->state['version']===4,'Shared authority did not upgrade the RAM journal');
$checkpoint=json_decode(file_get_contents($root.'/checkpoint.json'),true);
check(json_decode($checkpoint['payload'],true)['version']===4,'Shared authority preceded the old-reader rejection boundary');
check($physical===$j->delegateCleanup($b,100),'Equal storage identities did not share');
check($physical===$j->delegateCleanup($a,100),'Duplicate delegation created work');
check(count($j->state['tasks'][$physical]['parameters']['cleanupOwners'])===2,'Independent captures lost');
$token=start($j,$physical,$generation);
check(grant($j,$physical,$token,$generation)['authorized'],'Selected owner not authorized');
$aRun=$j->state['tasks'][$a]['runId'];$bRun=$j->state['tasks'][$b]['runId'];
$tokens=$j->cancel($aRun,102);
check($tokens===[$token] && $j->state['runs'][$aRun]['state']==='canceling','Selected owner cancellation did not wait for shutdown');
check($j->state['tasks'][$physical]['state']==='stopping','Selected approval was transferred to a live worker');
reject(fn()=>grant($j,$physical,$token,$generation,102));
$j->stopped($token,103,3);
check($j->state['runs'][$aRun]['state']==='canceled','Verified shutdown did not finish cancellation');
check($j->state['tasks'][$physical]['state']==='queued','Independent owner lost work');
$token=start($j,$physical,$generation,4);
check($j->state['tasks'][$physical]['parameters']['cleanupSelected']===$b,'Canceled owner reused');
check(grant($j,$physical,$token,$generation,104)['authorized'],'Surviving capture rejected');
$j->result($physical,$token,['outcome'=>'success','itemState'=>'completed','message'=>'Deleted exact snapshot.'],5,105,true);
check($j->state['runs'][$bRun]['state']==='complete','Surviving owner did not receive verified result');
check($j->state['runs'][$aRun]['state']==='canceled','Completion resurrected canceled owner');

// Canceling an unselected owner must not interrupt the selected worker.
$c=owner($j,'owner-c');$d=owner($j,'owner-d');$p=$j->delegateCleanup($c,110);$j->delegateCleanup($d,110);
$token=start($j,$p,$generation,11);$dRun=$j->state['tasks'][$d]['runId'];
check($j->cancel($dRun,112)===[],'Unselected cancellation interrupted another approval');
check($j->state['tasks'][$p]['state']==='running' && $j->state['runs'][$dRun]['state']==='canceled','Unselected owner failed to detach');
$j->cancel($j->state['tasks'][$c]['runId'],113);$j->stopped($token,114,14);
check($j->state['runs'][$j->state['tasks'][$p]['runId']]['state']==='canceled','Last owner retained physical authority');

$allA=owner($j,'all-cancel-a');$allB=owner($j,'all-cancel-b');$p=$j->delegateCleanup($allA,115);$j->delegateCleanup($allB,115);
$token=start($j,$p,$generation,16);
$j->cancel($j->state['tasks'][$allA]['runId'],117);$j->cancel($j->state['tasks'][$allB]['runId'],117);
check($j->state['runs'][$j->state['tasks'][$allB]['runId']]['state']==='canceling','Last owner detached before the selected worker stopped');
$j->stopped($token,118,18);
check($j->state['tasks'][$p]['state']==='canceled','All-owner cancellation requeued physical work');

// A policy rejection belongs only to the selected approval, not to other owners.
$e=owner($j,'owner-e');$f=owner($j,'owner-f');$p=$j->delegateCleanup($e,120);$j->delegateCleanup($f,120);
$token=start($j,$p,$generation,21);
$j->result($p,$token,['outcome'=>'success','itemState'=>'skipped','message'=>'Selected policy no longer permits deletion.'],22,122,true);
check($j->state['tasks'][$e]['result']['itemState']==='skipped','Rejected policy result lost');
check($j->state['tasks'][$f]['state']==='waiting' && $j->state['tasks'][$p]['state']==='queued','Rejected policy consumed another approval');
$j->checkpoint();unset($j);$j=new ZfsasCoordinatorState($root);
$token=start($j,$p,$generation,23);
check($j->state['tasks'][$p]['parameters']['cleanupSelected']===$f,'Restart lost independent owner selection');
$j->result($p,$token,['outcome'=>'success','itemState'=>'completed'],24,124,true);
check($j->state['tasks'][$f]['state']==='complete','Restart lost shared result projection');

// A changed captured owner is failed independently; it cannot donate its approval.
$changed=owner($j,'changed-owner');$valid=owner($j,'valid-owner');
$p=$j->delegateCleanup($changed,125);$j->delegateCleanup($valid,125);
$j->state['tasks'][$changed]['parameters']['deleteJob']['SEND_CONFIG_HASH']=str_repeat('f',64);
$j->reconcileSharedCleanup(126);
check($j->state['tasks'][$changed]['state']==='failed','Changed approval remained eligible');
$token=start($j,$p,$generation,27);
check($j->state['tasks'][$p]['parameters']['cleanupSelected']===$valid,'Changed approval was selected');
$j->prune(40*86400);
check(isset($j->state['tasks'][$changed]),'Pruning discarded an owner before physical shutdown');
$j->result($p,$token,['outcome'=>'success','itemState'=>'completed'],28,128,true);
check($j->state['tasks'][$changed]['state']==='failed','Shared completion renewed a changed approval');

$activeOwner=owner($j,'active-expired');$survivor=owner($j,'active-survivor');
$p=$j->delegateCleanup($activeOwner,130);$j->delegateCleanup($survivor,130);
$token=start($j,$p,$generation,31);$activeRun=$j->state['tasks'][$activeOwner]['runId'];
$j->state['tasks'][$activeOwner]['parameters']['deleteJob']['SEND_CONFIG_HASH']=str_repeat('e',64);
check($j->reconcileSharedCleanup(132)===[$token],'Active invalidation did not stop its captured worker');
check(!ZfsasCoordinatorState::terminal($j->state['runs'][$activeRun]['state']),'Invalidated owner finished before worker shutdown');
$j->stopped($token,133,33);
check($j->state['runs'][$activeRun]['state']==='failed','Invalidated owner did not finish after shutdown');
$token=start($j,$p,$generation,34);
$j->result($p,$token,['outcome'=>'success','itemState'=>'completed'],35,135,true);

$groupA=owner($j,'group-cancel-a');$groupB=owner($j,'group-cancel-b');
$p=$j->delegateCleanup($groupA,136);$j->delegateCleanup($groupB,136);$token=start($j,$p,$generation,37);
$j->cancel($j->state['tasks'][$p]['runId'],138);
check($j->state['tasks'][$groupA]['state']==='waiting','Direct physical cancellation completed owners before shutdown');
$j->stopped($token,139,39);
check($j->state['tasks'][$groupA]['state']==='failed' && $j->state['tasks'][$groupB]['state']==='failed','Direct physical cancellation stranded active owners');

$manualOwners=[];$manualParents=[];
for($n=0;$n<2;$n++) {
    $batchToken=str_repeat((string)($n+1),32);
    $item=['identity'=>'tank/data@manual#321','snapshot'=>'tank/data@manual','guid'=>'321','candidate'=>true,'state'=>'queued'];
    $parent=$j->submit('review-'.$n,['manual'=>true,'tasks'=>['items'=>['kind'=>'batch','items'=>[$item],
        'parameters'=>['batch'=>['token'=>$batchToken,'action'=>'delete','approvedAt'=>140,'configRevision'=>'reviewed']]]]],140)['runId'];
    $itemId=$j->state['tasks'][$parent.':items']['items'][0];
    $manual=$j->submit('manual-'.$n,['tasks'=>['delete'=>['kind'=>'delete','parameters'=>[
        'ownerRunId'=>$parent,'ownerItemId'=>$itemId,'deleteJob'=>['JOB_ID'=>'sm-'.$batchToken.'-item',
            'DATASET'=>'tank/data','SNAPSHOT'=>'tank/data@manual','SNAPSHOT_GUID'=>'321']]]]],140)['runId'].':delete';
    $j->state['items'][$itemId]['state']='deleting';$j->state['items'][$itemId]['deletionTaskId']=$manual;
    $j->refreshDeletionBatch($parent.':items',140);
    $manualOwners[]=$manual;$manualParents[]=$parent;$manualPhysical=$j->delegateCleanup($manual,140);
}
check(count($j->state['tasks'][$manualPhysical]['parameters']['cleanupOwners'])===2,'Manual reviews failed to share');
$j->state['tasks'][$manualParents[0].':items']['parameters']['batch']['configRevision']='changed';
$j->reconcileSharedCleanup(141);
check($j->state['tasks'][$manualOwners[0]]['state']==='failed','Changed manual review retained authority');
$token=start($j,$manualPhysical,$generation,42);
check($j->state['tasks'][$manualPhysical]['parameters']['cleanupSelected']===$manualOwners[1],'Changed review borrowed another batch approval');
check(grant($j,$manualPhysical,$token,$generation,142)['authorized'],'Independent unchanged manual review rejected');
$j->result($manualPhysical,$token,['outcome'=>'success','itemState'=>'completed'],43,143,true);

// Unbound legacy inbox records are never upgraded to independent owner authority.
$legacy=$j->submit('legacy',['tasks'=>['delete'=>['kind'=>'delete','parameters'=>[
    'deleteJob'=>['JOB_ID'=>'legacy','DATASET'=>'tank/data','SNAPSHOT'=>'tank/data@old','SNAPSHOT_GUID'=>'123']]]]],129)['runId'].':delete';
check($j->delegateCleanup($legacy,129)===null,'Legacy work gained shared authority');

$g=owner($j,'owner-g');$h=owner($j,'owner-h','124');$i=owner($j,'owner-i','123','ssh:'.str_repeat('b',64));
check(count(array_unique([$j->delegateCleanup($g,130),$j->delegateCleanup($h,130),$j->delegateCleanup($i,130)]))===3,'Different GUIDs or endpoints shared authority');
$j->prune(40*86400);
check(isset($j->state['tasks'][$g]),'Pruning discarded active cleanup');
$j->checkpoint();$sequence=$j->state['sequence'];unset($j);
$payload=json_encode(['version'=>3,'sequence'=>$sequence+1,'put'=>[],'remove'=>[]]);
file_put_contents($root.'/journal.ndjson',json_encode(['payload'=>$payload,'sha256'=>hash('sha256',$payload)])."\n");
$rejected=false;
try {$j=new ZfsasCoordinatorState($root);} catch(RuntimeException $error) {$rejected=true;}
check($rejected,'Shared journal accepted a downgraded authority record');
echo "PASS: independent shared cleanup captures, cancellation handoff, last-owner shutdown, policy rejection isolation, restart, immutable approvals, pruning, legacy exclusion, endpoint/GUID isolation and result fanout\n";
