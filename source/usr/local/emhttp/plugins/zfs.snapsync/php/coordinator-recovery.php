<?php
require_once __DIR__.'/replication-recovery.php';

function zfsas_recovery_begin(ZfsasCoordinatorState $j,array $request,array $config): array
{
    $command=$request['commandId'] ?? '';
    if(!is_string($command)||!preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D',$command))throw new InvalidArgumentException('Stable review command ID required.');
    $scope=['recoveryRun'=>(string)($request['runId'] ?? ''),'recoverySchedule'=>(string)($request['scheduleId'] ?? '')];
    if(isset($j->state['commands'][$command])){
        $receipt=$j->state['commands'][$command];
        if(($receipt['context'] ?? null)!==$scope)throw new InvalidArgumentException('Command ID belongs to a different recovery review.');
        return $receipt;
    }
    $origin=$j->state['runs'][$request['runId'] ?? ''] ?? null;
    if($origin && !ZfsasCoordinatorState::terminal($origin['state']))throw new InvalidArgumentException('Wait for the current run and its workers to stop before review.');
    if(!$origin && !empty($request['scheduleId'])){
        foreach(array_reverse($j->state['runs']) as $candidate){
            $parameters=$j->state['tasks'][$candidate['id'].':prepare']['parameters'] ?? [];
            if(($parameters['job']['id'] ?? '')===$request['scheduleId'] && ($parameters['phase'] ?? '')!=='recovery_scan' && ZfsasCoordinatorState::terminal($candidate['state']) && $j->runRequiresReview($candidate['id'])){$origin=$candidate;break;}
        }
    }
    $old=$origin?$j->state['tasks'][$origin['id'].':prepare']['parameters'] ?? []:[];
    $schedule=$old['job']['id'] ?? $request['scheduleId'] ?? '';
    $job=null;foreach(zfsas_send_parse_jobs($config['send']['SEND_JOBS'] ?? '') as $row)if($row['id']===$schedule)$job=$row;
    if(!$job||$job['transport']!=='local')throw new InvalidArgumentException('Choose an existing local replication configuration for recovery.');
    if(isset($old['job']) && array_intersect_key($old['job'],array_flip(['source','destination','children']))!==array_intersect_key($job,array_flip(['source','destination','children']))){
        if(!empty($request['runId']))throw new InvalidArgumentException('Replication membership changed. Review recovery from the current configuration instead.');
        $origin=null; // A fresh configuration review must not adopt old membership.
    }
    $members=[];
    if($origin)foreach($origin['tasks'] as $id){
        $task=$j->state['tasks'][$id];$p=$task['parameters'];
        if(isset($p['replication'],$p['inspection'])){
            $source=explode('@',$p['replication']['sourceSnapshot'])[0];
            $members[$source]=['source'=>$source,'sourceDatasetGuid'=>$p['inspection']['sourceDatasetGuid'],'destination'=>$p['replication']['destination'],
                'snapshot'=>['snapshot'=>$p['replication']['sourceSnapshot'],'guid'=>$p['replication']['sourceGuid']]];
        }
        if(($p['phase'] ?? '')==='replication_snapshot')$members[$p['source']]=['source'=>$p['source'],'sourceDatasetGuid'=>$p['sourceDatasetGuid'],'destination'=>$p['destination'],'snapshot'=>$task['result']['reference'] ?? null];
    }
    return $j->submit($command,['receiptData'=>$scope,'manual'=>true,'coordinationKey'=>$job['id'],'revision'=>$config['revision'],'tasks'=>['prepare'=>[
        'kind'=>'prepare','dataset'=>$job['source'],'parameters'=>['phase'=>'recovery_scan','nativePlan'=>true,'allowDynamicPlan'=>true,
            'job'=>$job,'members'=>array_values($members),'originRunId'=>$origin['id'] ?? '', 'revision'=>$config['revision']]]]],time());
}
function zfsas_recovery_status(ZfsasCoordinatorState $j,string $id,int $offset=0): array
{
    $run=$j->state['runs'][$id] ?? null;$p=$j->state['tasks'][$id.':prepare']['parameters'] ?? [];
    if(!$run||($p['phase'] ?? '')!=='recovery_scan')throw new InvalidArgumentException('Recovery review is unavailable. Start a fresh review.');
    $rows=[];$eligible=0;$blocked=0;
    foreach($run['tasks'] as $taskId){
        $r=$j->state['tasks'][$taskId]['result']['review'] ?? null;
        if(!$r)continue;
        if($r['eligible'])$eligible++;else $blocked++;
        unset($r['request'],$r['inspection']);$rows[]=$r;
    }
    $expires=$run['finishedAt']===null?null:$run['finishedAt']+300;
    return ['reviewId'=>$id,'state'=>$run['state'],'expiresAt'=>$expires,'expired'=>$expires!==null && time()>=$expires,
        'eligible'=>$eligible,'blocked'=>$blocked,'rows'=>array_slice($rows,max(0,$offset),50),'nextOffset'=>$offset+50<count($rows)?$offset+50:null,
        'problem'=>zfsas_operation_problem(array_map(static fn($tid)=>$j->state['tasks'][$tid],$run['tasks'])),
        'message'=>'Only reviewed original snapshots will be sent. No fresh snapshots or additional cleanup are authorized.'];
}
function zfsas_recovery_execute(ZfsasCoordinatorState $j,string $id,array $config): array
{
    $command='reviewed-retry-'.$id;
    if(isset($j->state['commands'][$command]))return $j->state['commands'][$command];
    $review=zfsas_recovery_status($j,$id);$run=$j->state['runs'][$id];$p=$j->state['tasks'][$id.':prepare']['parameters'];
    if($review['state']!=='complete'||$review['expired']||!$review['eligible'])throw new InvalidArgumentException('Review is unfinished, expired, or contains no eligible work. Review again.');
    if($run['revision']!==$config['revision'])throw new InvalidArgumentException('Configuration changed. Start a fresh recovery review.');
    $tasks=['prepare'=>['kind'=>'prepare','dataset'=>$p['job']['source'],'parameters'=>[
        'phase'=>'recovery_execute_start','job'=>$p['job'],'reviewRunId'=>$id,'originRunId'=>$p['originRunId'],'revision'=>$config['revision']]]];
    $finalizers=[];$index=0;
    foreach($run['tasks'] as $taskId){
        $r=$j->state['tasks'][$taskId]['result']['review'] ?? null;
        if(empty($r['eligible']))continue;
        $prefix='member-'.($index++).'-';
        $plan=zfsas_replication_plan($r['request'],$r['inspection'],$config['revision'],$config['send']['SEND_RATE_LIMIT'] ?? '0');
        foreach($plan['tasks'] as $name=>$task){
            $task['parameters']['reviewRunId']=$id;
            $task['parameters']['freeSpaceFloor']=$p['job']['threshold'];
            $task['dependencies']=array_merge(['prepare'],array_map(static fn($dep)=>$prefix.$dep,$task['dependencies']));
            $tasks[$prefix.$name]=$task;
            if($task['kind']==='finalize')$finalizers[]=$prefix.$name;
        }
    }
    $tasks['verify-recovery']=['kind'=>'finalize','dataset'=>$p['job']['source'],'parameters'=>['phase'=>'recovery_finish','revision'=>$config['revision']], 'dependencies'=>$finalizers];
    return $j->submit($command,['manual'=>true,'coordinationKey'=>$p['job']['id'],'revision'=>$config['revision'],'tasks'=>$tasks],time());
}
function zfsas_recovery_command(array $task,ZfsasCoordinatorState $j,string $root,string $revision): array
{
    $p=$task['parameters'];
    if($p['revision']!==$revision)return ['outcome'=>'validation_failure','reason'=>'configuration','message'=>'Configuration changed. Start a fresh recovery review.'];
    $path=$root.'/attempt-inputs/'.hash('sha256',$task['id']).'.recovery.json';
    if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);
    $text=json_encode(['taskId'=>$task['id'],'parameters'=>$p],JSON_THROW_ON_ERROR);
    if(file_put_contents($path.'.pending',$text)!==strlen($text)||!rename($path.'.pending',$path))throw new RuntimeException('Cannot publish recovery worker input.');
    return ['/bin/bash',__DIR__.'/../scripts/coordinator-recovery-attempt.sh',$path,$p['source'] ?? $p['job']['source'] ?? $task['dataset'],$p['destination'] ?? $p['job']['destination'] ?? $task['dataset']];
}
