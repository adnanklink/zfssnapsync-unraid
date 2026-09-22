<?php
require_once __DIR__.'/source-retention-policy.php';
/** Called on transitions and once at recovery, never an idle inventory sweep. */
function zfsas_coordinator_source_followup(ZfsasCoordinatorState $journal,string $runId): void
{
    $run=$journal->state['runs'][$runId] ?? null;
    if (!$run || empty($run['sourceCleanupPending']) || $run['state']!=='complete') { return; }
    $origin=$journal->state['tasks'][$runId.':prepare']['parameters'];$tasks=[];
    foreach ($run['tasks'] as $id) {
        $task=$journal->state['tasks'][$id];
        if (($task['parameters']['phase'] ?? '')!=='replication_snapshot') { continue; }
        $p=$task['parameters'];$ref=$task['result']['reference'] ?? null;
        if ($task['state']!=='complete' || !$ref) { throw new RuntimeException('Successful replication lacks source checkpoint evidence.'); }
        $tasks['member-'.count($tasks)]=['kind'=>'prepare','dataset'=>$p['source'],'parameters'=>[
            'phase'=>'source_retention_prepare','allowDynamicPlan'=>true,'revision'=>$run['revision'],
            'parentRunId'=>$runId,'job'=>$origin['job'],'policy'=>zfsas_source_member_policy($origin['sourcePolicy'],$p['source']),
            'source'=>$p['source'],'sourceDatasetGuid'=>$p['sourceDatasetGuid'],'destination'=>$p['destination'],'verified'=>$ref]];
    }
    if (!$tasks) { return; }
    $command='source-cleanup-'.$runId;
    $receipt=$journal->state['commands'][$command] ?? $journal->submit($command,['revision'=>$run['revision'],'tasks'=>$tasks],time());
    $journal->state['runs'][$receipt['runId']]['sourceCleanupOf']=$runId;
    $journal->state['runs'][$runId]['sourceCleanupRunId']=$receipt['runId'];
    unset($journal->state['runs'][$runId]['sourceCleanupPending']);
    $journal->commit();
}
function zfsas_coordinator_source_command(array $task,ZfsasCoordinatorState $journal,string $root,string $revision): array
{
    $p=$task['parameters'];
    if ($p['revision']!==$revision) { return ['outcome'=>'validation_failure','message'=>'Configuration changed; source cleanup requires a new successful replication run.']; }
    if ($p['phase']==='source_retention_review') {
        $p['protectedGuids']=[];$p['protectedNames']=[];
        foreach ($journal->state['references'] as $ref) {
            $owner=$journal->state['runs'][$ref['runId']] ?? null;
            if ($owner && (!ZfsasCoordinatorState::terminal($owner['state']) || $journal->runRequiresReview($owner['id']))) {
                $p['protectedGuids'][$ref['guid']]=true;$p['protectedNames'][$ref['snapshot']]=true;
            }
        }
    }
    if ($p['phase']==='source_retention_delete') {
        $allowed=[];$skipped=[];
        foreach ($p['candidates'] as $row) {
            if (isset($task['sourceResults'][$row['guid']])) { continue; }
            if ($journal->deletionReferenceOwners($row['snapshot'],$row['guid'])) { $skipped[]=$row['snapshot']; }
            else { $allowed[]=$row; }
        }
        $p['candidates']=$allowed;$p['referenceSkipped']=count($skipped);
    }
    $path=$root.'/attempt-inputs/'.hash('sha256',$task['id']).'.source.json';
    if (!is_dir(dirname($path)) && !mkdir(dirname($path),0700,true)) { throw new RuntimeException('Cannot create source cleanup capture storage.'); }
    $text=json_encode(['taskId'=>$task['id'],'parameters'=>$p],JSON_THROW_ON_ERROR);
    if (@file_get_contents($path)!==$text && (file_put_contents($path.'.pending',$text)!==strlen($text) || !rename($path.'.pending',$path))) { throw new RuntimeException('Cannot publish source cleanup capture.'); }
    $gates=[$p['source'] ?? $p['job']['source']];
    foreach ($p['receivers'] ?? [] as $receiver) { $gates[]=$receiver['dataset']; }
    return array_merge(['/bin/bash',__DIR__.'/../scripts/coordinator-source-attempt.sh',$path,$p['phase']],array_unique($gates));
}
