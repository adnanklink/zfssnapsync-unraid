<?php
require_once __DIR__.'/retirement-policy.php';

function zfsas_retirement_path(string $token): string
{
    if(!preg_match('/^[a-f0-9]{32}$/D',$token))throw new InvalidArgumentException('Invalid retirement session.');
    return '/tmp/zfs-snapsync-coordinator/retirements/'.$token.'.json';
}
function zfsas_retirement_store(array $session): void
{
    $path=zfsas_retirement_path($session['token']);
    if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);
    $text=json_encode($session,JSON_THROW_ON_ERROR);
    if(file_put_contents($path.'.pending',$text)!==strlen($text)||!rename($path.'.pending',$path))throw new RuntimeException('Cannot save retirement session.');
}
function zfsas_retirement_load(string $token): array
{
    $path=zfsas_retirement_path($token);
    if(is_link($path)||!is_file($path))throw new InvalidArgumentException('Retirement session is unavailable after reboot. Inspect the dataset and select its destinations again.');
    return json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
}
/** Conservative active-work scan: mixed-scope work is never canceled implicitly. */
function zfsas_retirement_busy(ZfsasCoordinatorState $journal,array $targets): array
{
    $busy=[];
    foreach($journal->state['runs'] as $run){
        if(ZfsasCoordinatorState::terminal($run['state']))continue;
        foreach($run['tasks'] as $id){
            $task=$journal->state['tasks'][$id];$p=$task['parameters'];
            if(str_starts_with($p['phase'] ?? '','retirement_'))continue;
            $datasets=array_filter([$task['dataset'] ?? '',$p['source'] ?? '',$p['destination'] ?? '',$p['job']['source'] ?? '',$p['job']['destination'] ?? '']);
            foreach($p['mutationDatasets'] ?? [] as $dataset)$datasets[]=$dataset;
            if(!empty($p['autoConfig']) && preg_match('/^DATASETS=[\"\']?(.*?)[\"\']?$/m',$p['autoConfig'],$m))foreach(explode(',',$m[1]) as $entry)if($entry!=='')$datasets[]=preg_replace('/:[^:]*$/','',trim($entry));
            foreach($datasets as $dataset)foreach($targets as $target)if(zfsas_retirement_overlap($dataset,$target['dataset'])){$busy[$run['id']]=$run['id'];break 3;}
        }
    }
    return array_values($busy);
}
function zfsas_retirement_request(ZfsasCoordinatorState $journal,array $request,array $pair,?ZfsasCoordinatorExecutor $executor=null): array
{
    $action=$request['action'];$now=time();
    if($action==='retirement_inspect'){
        $session=zfsas_retirement_inspect($pair,(string)($request['dataset'] ?? ''));
        // Explicit destinations support jobs removed before this session.
        $extra=$request['destinations'] ?? [];
        if(!is_array($extra)||count($extra)>20)throw new InvalidArgumentException('At most 20 explicit destinations may be reviewed.');
        foreach($extra as $target){
            if(!is_array($target)||array_diff(array_keys($target),['dataset','transport']))throw new InvalidArgumentException('Invalid destination.');
            zfsas_retirement_dataset($target['dataset'] ?? '');
            if(!in_array($target['transport'] ?? '',['local','ssh'],true))throw new InvalidArgumentException('Select local or SSH transport.');
            if($target['transport']==='local'&&zfsas_retirement_overlap($target['dataset'],$session['dataset']))throw new InvalidArgumentException('Source and destination overlap.');
            foreach(zfsas_send_parse_jobs($pair['send']['SEND_JOBS']) as $job){
                if(in_array($job['id'],array_column($session['jobs'],'id'),true))continue;
                if(($target['transport']===$job['transport']&&zfsas_retirement_overlap($target['dataset'],$job['destination']))||($target['transport']==='local'&&zfsas_retirement_overlap($target['dataset'],$job['source'])))throw new InvalidArgumentException('Explicit destination is still used by job '.$job['id'].'.');
            }
            if($target['transport']==='local')foreach(explode(',',$pair['auto']['DATASETS'] ?? '') as $entry){$configured=preg_replace('/:[^:]*$/','',trim($entry));if($configured!==''&&($configured===$target['dataset']||str_starts_with($target['dataset'],$configured.'/')))throw new InvalidArgumentException('Explicit destination remains managed by Automatic snapshots.');}
            $session['targets'][]=$target+['role'=>'destination'];
        }
        $unique=[];foreach($session['targets'] as $target)$unique[$target['transport'].'|'.$target['dataset']]=$target;$session['targets']=array_values($unique);
        $session+=['token'=>bin2hex(random_bytes(16)),'createdAt'=>$now,'state'=>'inspected'];zfsas_retirement_store($session);
        return $session+['busy'=>zfsas_retirement_busy($journal,$session['targets'])];
    }
    $session=zfsas_retirement_load((string)($request['token'] ?? ''));
    if($action==='retirement_stop'){
        if(!empty($session['saved'])){
            foreach($session['stoppingRuns'] ?? [] as $runId)if($executor&&isset($journal->state['runs'][$runId])&&!ZfsasCoordinatorState::terminal($journal->state['runs'][$runId]['state']))$executor->cancel($runId);
            if(empty($session['schedulerApplied'])){
                if($pair['revision']!==$session['savedRevision'])throw new InvalidArgumentException('Settings changed. Inspect retirement again.');
                $lines=[];$code=1;exec(escapeshellarg(__DIR__.'/../scripts/sync-cron.sh').' 2>&1',$lines,$code);
                $session['schedulerApplied']=$code===0;$session['message']=$code===0?'Automation stopped; scheduler applied.':'Settings remain saved; scheduler application failed. Retry after resolving the scheduler error.';zfsas_retirement_store($session);
            }
            return $session;
        }
        if($session['createdAt']+300<$now)throw new InvalidArgumentException('Retirement inspection expired. Inspect again.');
        $busy=zfsas_retirement_busy($journal,$session['targets']);
        $cancel=[];$mixed=[];
        foreach($busy as $runId){
            $run=$journal->state['runs'][$runId];$job=$journal->state['tasks'][$runId.':prepare']['parameters']['job'] ?? null;
            $owned=$job && ($job['children'] ?? '0')==='0' && in_array($job['id'],array_column($session['jobs'],'id'),true);
            if(!$owned){
                $owned=true;$hasAuto=false;
                foreach($run['tasks'] as $id){$t=$journal->state['tasks'][$id];$p=$t['parameters'];
                    if(!empty($p['individualMutations'])){$hasAuto=true;if(($p['mutationDatasets'] ?? [])!==[$session['dataset']])$owned=false;}
                    elseif(!isset($p['autoMutation']))$owned=false;
                    if(!empty($t['dataset'])&&$t['dataset']!==$session['dataset'])$owned=false;
                }
                $owned=$owned&&$hasAuto;
            }
            if($owned&&$executor)$cancel[]=$runId;else $mixed[]=$runId;
        }
        if($mixed)throw new InvalidArgumentException('Mixed-scope work must finish or be canceled separately in Activity: '.implode(', ',$mixed));
        $result=zfsas_retirement_stop('/boot/config/plugins/zfs.snapsync',$session['dataset'],$session['revision'],__DIR__.'/../scripts/sync-cron.sh');
        $session=array_replace($session,array_intersect_key($result,array_flip(['saved','schedulerApplied','savedRevision','message'])));$session['state']='stopped';$session['stoppingRuns']=$cancel;
        zfsas_retirement_store($session);foreach($cancel as $runId)$executor->cancel($runId);return $session;
    }
    if($action==='retirement_review'){
        if(empty($session['saved'])||empty($session['schedulerApplied']))throw new InvalidArgumentException('Stop automation and apply scheduler settings before cleanup.');
        if($pair['revision']!==$session['savedRevision'])throw new InvalidArgumentException('Settings changed. Inspect retirement again.');
        if(zfsas_retirement_busy($journal,$session['targets']))throw new InvalidArgumentException('Overlapping work must finish before cleanup review.');
        if(isset($session['deleteRun']))throw new InvalidArgumentException('This session already submitted deletion. Inspect again for remaining snapshots.');
        if(isset($session['reviewRun']) && isset($journal->state['runs'][$session['reviewRun']]) && !ZfsasCoordinatorState::terminal($journal->state['runs'][$session['reviewRun']]['state']))return $session;
        $tasks=[];
        foreach($session['targets'] as $index=>$target)$tasks['target-'.$index]=['kind'=>'prepare','dataset'=>$target['dataset'],'parameters'=>[
            'phase'=>'retirement_review','revision'=>$pair['revision'],'target'=>$target,'connection'=>$target['transport']==='ssh'?zfsas_receiver_connection_capture($pair['send']):[],
            'prefixes'=>array_merge([$pair['auto']['PREFIX']],zfsas_known_send_prefixes('/boot/config/plugins/zfs.snapsync'))]];
        $receipt=$journal->submit('retirement-review-'.$session['token'].'-'.bin2hex(random_bytes(4)),['manual'=>true,'revision'=>$pair['revision'],'tasks'=>$tasks],$now);
        $session['reviewRun']=$receipt['runId'];$session['expires']=$now+300;$session['state']='reviewing';zfsas_retirement_store($session);return $session;
    }
    if($action==='retirement_abandon'){
        if(empty($request['confirm'])||($session['expires'] ?? 0)<$now||$pair['revision']!==($session['savedRevision'] ?? ''))throw new InvalidArgumentException('Review expired or settings changed.');
        $review=$journal->state['runs'][$session['reviewRun'] ?? ''] ?? null;
        if(!$review||$review['state']!=='complete')throw new InvalidArgumentException('Every receiver must be inspected without an unresolved receive before abandoning recovery.');
        $targets=[];foreach($review['tasks'] as $id)$targets[]=zfsas_retirement_review_capture($id)['target'];
        $resolved=[];
        foreach($journal->state['runs'] as $run){
            if(!ZfsasCoordinatorState::terminal($run['state'])||!$journal->runRequiresReview($run['id']))continue;
            $job=$journal->state['tasks'][$run['id'].':prepare']['parameters']['job'] ?? [];
            $retired=in_array($job['id'] ?? '',array_column($session['jobs'],'id'),true);
            if(!$retired && ($job['source'] ?? '')===$session['dataset'] && ($job['children'] ?? '1')==='0'
                && !in_array($job['id'] ?? '',array_column(zfsas_send_parse_jobs($pair['send']['SEND_JOBS'] ?? ''),'id'),true)){
                foreach($targets as $target)if($target['role']==='destination'&&$target['dataset']===($job['destination'] ?? '')&&$target['transport']===($job['transport'] ?? 'local'))$retired=true;
            }
            if(!$retired)continue;
            $references=array_filter($journal->state['references'],static fn($ref)=>$ref['runId']===$run['id']);
            if(!$references)continue;$safe=true;
            foreach($references as $ref){$matched=false;foreach($targets as $target)if(($ref['endpoint'] ?? '')===$target['endpoint']&&$ref['dataset']===$target['dataset']&&$ref['datasetGuid']===$target['datasetGuid'])$matched=true;if(!$matched)$safe=false;}
            foreach($journal->state['attempts'] as $attempt)if(in_array($attempt['taskId'],$run['tasks'],true)&&$attempt['state']!=='stopped')$safe=false;
            if($safe)$resolved[]=$run['id'];
        }
        if(!$resolved)throw new InvalidArgumentException('No recovery references belong solely to these retired jobs. Resolve other operations separately.');
        $journal->state['version']=max(9,$journal->state['version']);
        foreach($resolved as $id){$journal->state['runs'][$id]['recoveryResolvedBy']='retirement:'.$session['token'];$journal->state['runs'][$id]['retirementAbandonedAt']=$now;}
        $journal->commit();return ['resolved'=>$resolved,'message'=>'Recovery for the reviewed retired jobs was explicitly abandoned. Historical outcomes remain recorded.'];
    }
    if($action==='retirement_status'){
        $result=$session;$run=$journal->state['runs'][$session['deleteRun'] ?? $session['reviewRun'] ?? ''] ?? null;
        $result['run']=$run?array_intersect_key($run,array_flip(['id','state','createdAt','finishedAt'])):null;
        $result['tasks']=[];$rows=[];
        foreach($run['tasks'] ?? [] as $id){
            $task=$journal->state['tasks'][$id];
            $result['tasks'][]=['dataset'=>$task['dataset'],'state'=>$task['state'],'message'=>$task['result']['message'] ?? ''];
            if(!isset($session['deleteRun'])&&$task['state']==='complete'){
                $capture=zfsas_retirement_review_capture($id);
                foreach($capture['rows'] as $row){$owners=$journal->deletionReferenceOwners($row['snapshot'],$row['guid'],$capture['target']['endpoint']);if($owners){$row['reason']='Protected by operation '.implode(', ',$owners).'. Resolve recovery before cleanup.';$row['selected']=false;}$rows[]=$row+['dataset'=>$task['dataset'],'role'=>$capture['target']['role'],'transport'=>$capture['target']['transport']];}
            }
        }
        $offset=max(0,(int)($request['offset'] ?? 0));$result['total']=count($rows);$result['eligible']=count(array_filter($rows,static fn($row)=>$row['reason']===''));$result['selected']=count(array_filter($rows,static fn($row)=>$row['selected']));$result['rows']=array_slice($rows,$offset,50);$result['offset']=$offset;$result['taskCounts']=array_count_values(array_column($result['tasks'],'state'));$result['taskTotal']=count($result['tasks']);$result['tasks']=array_slice($result['tasks'],$offset,50);
        return $result;
    }
    if($action==='retirement_submit'){
        if(isset($session['deleteRun']))return $session;
        if(empty($request['confirm'])||($session['expires'] ?? 0)<$now||$pair['revision']!==($session['savedRevision'] ?? ''))throw new InvalidArgumentException('Cleanup review expired or settings changed. Review again.');
        $review=$journal->state['runs'][$session['reviewRun'] ?? ''] ?? null;
        if(!$review||$review['state']!=='complete')throw new InvalidArgumentException('All source and destination inspections must complete.');
        if(zfsas_retirement_busy($journal,$session['targets']))throw new InvalidArgumentException('Overlapping work started. Review after it finishes.');
        $excluded=$request['excluded'] ?? [];$included=$request['included'] ?? [];
        foreach([$excluded,$included] as $keys){if(!is_array($keys)||count($keys)>10000)throw new InvalidArgumentException('Selection exceeds request limit.');foreach($keys as $key)if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new InvalidArgumentException('Invalid snapshot selection.');}
        $rows=[];$seen=[];$targetIdentities=[];
        foreach($review['tasks'] as $id){$capture=zfsas_retirement_review_capture($id);$targetKey=$capture['target']['endpoint'].'|'.$capture['target']['datasetGuid'];if(isset($targetIdentities[$targetKey]))throw new InvalidArgumentException('Source or destination aliases resolve to the same dataset. Inspect distinct targets again.');$targetIdentities[$targetKey]=true;foreach($capture['rows'] as $row){$seen[$row['key']]=true;if(($row['selected']&&!in_array($row['key'],$excluded,true))||in_array($row['key'],$included,true)){
            if($row['reason']!==''||$journal->deletionReferenceOwners($row['snapshot'],$row['guid'],$capture['target']['endpoint']))throw new InvalidArgumentException('Selected snapshot is still protected: '.$row['snapshot']);
            $rows[]=[$capture['target'],$row];
        }}}
        if(array_diff(array_merge($excluded,$included),array_keys($seen)))throw new InvalidArgumentException('Selection does not belong to this review.');
        if(!$rows)throw new InvalidArgumentException('Select at least one eligible snapshot.');
        usort($rows,static fn($a,$b)=>($a[0]['role']==='source')<=>($b[0]['role']==='source'));
        $tasks=[];$prior=null;
        foreach($rows as $index=>[$target,$row]){
            $name='delete-'.$index;$job=['JOB_ID'=>'retire-'.substr($row['key'],0,40),'DATASET'=>$target['dataset'],'DATASET_GUID'=>$target['datasetGuid'],'SNAPSHOT'=>$row['snapshot'],'SNAPSHOT_GUID'=>$row['guid'],'SNAPSHOT_CREATETXG'=>$row['txg'],'SNAPSHOT_EPOCH'=>$row['created'],'SNAPSHOT_NAME'=>explode('@',$row['snapshot'],2)[1],'DELETE_POOL'=>explode('/',$target['dataset'])[0],'DELETE_SCOPE'=>'retirement'];
            $p=['phase'=>'retirement_delete','revision'=>$pair['revision'],'retirement'=>['token'=>$session['token'],'row'=>$row,'target'=>$target],'endpoint'=>$target['endpoint'],'deleteJob'=>$job];
            if(isset($target['receiverCapture']))$p+=['receiverCapture'=>$target['receiverCapture'],'remoteOwnership'=>true];
            $tasks[$name]=['kind'=>'delete','dataset'=>$target['dataset'],'dependencies'=>$prior?[$prior]:[],'parameters'=>$p];$prior=$name;
        }
        $journal->state['version']=max(9,$journal->state['version']);$journal->commit();
        $receipt=$journal->submit('retirement-delete-'.$session['token'],['manual'=>true,'revision'=>$pair['revision'],'tasks'=>$tasks],$now);
        $session['deleteRun']=$receipt['runId'];$session['state']='deleting';zfsas_retirement_store($session);return $session;
    }
    throw new InvalidArgumentException('Unknown retirement action.');
}
function zfsas_retirement_review_capture(string $task): array
{
    $path='/tmp/zfs-snapsync-coordinator/attempt-inputs/'.hash('sha256',$task).'.retirement-review.json';
    if(is_link($path)||!is_file($path))throw new RuntimeException('Review inventory unavailable. Inspect again.');
    return json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
}
function zfsas_retirement_command(array $task,string $root,string $revision): array
{
    if(($task['parameters']['revision'] ?? '')!==$revision)return ['outcome'=>'validation_failure','message'=>'Settings changed; review retirement again.'];
    $path=$root.'/attempt-inputs/'.hash('sha256',$task['id']).'.retirement.json';
    if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);
    $text=json_encode(['taskId'=>$task['id'],'parameters'=>$task['parameters']],JSON_THROW_ON_ERROR);
    if(file_put_contents($path.'.pending',$text)!==strlen($text)||!rename($path.'.pending',$path))throw new RuntimeException('Cannot capture retirement work.');
    return ['/bin/bash',__DIR__.'/../scripts/coordinator-retirement-attempt.sh',$path,$task['parameters']['phase'],$task['dataset'],$task['parameters']['endpoint'] ?? 'local'];
}
