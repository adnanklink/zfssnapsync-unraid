<?php
/** Projection of recorded tasks only; absent tasks are never counted as successes. */
function zfsas_operation_stages(array $tasks, string $runState, string $selected='', int $offset=0): array
{
    $labels=['datasets'=>'Check datasets','snapshots'=>'Create source snapshots','inspect'=>'Inspect destinations','cleanup'=>'Cleanup','space'=>'Check space','transfer'=>'Transfer','verify'=>'Verify'];
    $phases=['replication_schedule'=>'datasets','recovery_execute_start'=>'datasets','replication_snapshot'=>'snapshots',
        'replication_member'=>'inspect','replication_inspect'=>'inspect','replication_space'=>'space',
        'replication_transfer'=>'transfer','replication_verify'=>'verify','replication_run_verify'=>'verify','recovery_finish'=>'verify'];
    $groups=array_fill_keys(array_keys($labels),[]);$known=false;$scheduled=false;$destinations=[];$membership=[];$wholeChecks=[];$wholeVerify=[];
    $byId=array_column($tasks,null,'id');
    foreach($tasks as $task){$p=$task['parameters'] ?? [];if(isset($p['source'],$p['destination']))$destinations[$p['destination']]=$p['source'];}
    foreach($tasks as $task){
        $p=$task['parameters'] ?? [];$phase=$p['phase'] ?? '';
        $stage=$phases[$phase] ?? (($p['deleteJob']['DELETE_SCOPE'] ?? '')==='destination_checkpoint'?'cleanup':null);
        if($stage===null)continue;$known=true;$scheduled=$scheduled||$phase==='replication_schedule';
        $dataset=$p['source'] ?? (isset($p['replication']['sourceSnapshot'])?explode('@',$p['replication']['sourceSnapshot'])[0]:($destinations[$task['dataset'] ?? ''] ?? $task['dataset'] ?? 'Recorded membership'));
        $state=$task['state'];$attempts=(int)($task['attemptCount'] ?? 0);
        $state=match($state){'complete'=>'completed','retry_wait'=>'retry_scheduled','running','starting','stopping','canceling'=>'running','canceled'=>($attempts||$runState==='canceled')?'canceled':'not_reached',default=>$state};
        if(in_array($state,['queued','waiting'],true))$state=in_array($runState,['failed','canceled','complete'],true)?'not_reached':'waiting';
        if($stage==='cleanup' && $state==='completed' && ($task['result']['itemState'] ?? '')==='skipped')$state='not_required';
        $explanation=$task['result']['message'] ?? (($task['blocked'] ?? '') ?: '');
        if($explanation==='' && in_array($state,['waiting','not_reached'],true)){
            $waiting=[];
            foreach($task['dependencies'] ?? [] as $dependency){
                $prior=$byId[$dependency] ?? null;
                if(!$prior || ($prior['state'] ?? '')!=='complete'){
                    $priorPhase=$prior['parameters']['phase'] ?? '';
                    $waiting[]=$labels[$phases[$priorPhase] ?? ''] ?? 'a prerequisite';
                }
            }
            if($waiting)$explanation='Waiting for '.implode(', ',array_slice(array_unique($waiting),0,3)).'.';
            elseif($state==='not_reached')$explanation='This step was not executed.';
        }
        $row=['dataset'=>$dataset,'state'=>$state,'attempts'=>$attempts,'message'=>zfsas_diagnostic_text($explanation),'taskId'=>$task['id'],'retryAt'=>$task['retryAt'] ?? null];
        if($phase==='replication_schedule' || $phase==='recovery_execute_start'){$wholeChecks[]=$row;continue;}
        if($phase==='replication_run_verify' || $phase==='recovery_finish'){$wholeVerify[]=$row;continue;}
        $membership[$dataset]=true;
        $groups[$stage][$dataset][]=$row;
        // Manual inspection validates both source and receiver identities.
        if($phase==='replication_inspect')$groups['datasets'][$dataset][]=$row;
    }
    if(!$known)return ['available'=>false,'message'=>'This record has no supported replication stage evidence. Recorded information is available in technical details and logs.','stages'=>[]];
    $priority=['failed','running','retry_scheduled','waiting','canceled','not_reached','completed','not_required'];
    $aggregate=static function(array $states)use($priority):string {foreach($priority as $state)if(in_array($state,$states,true))return $state;return 'not_reached';};
    $planned=$groups;
    if(!$membership)foreach($wholeChecks as $row)$membership[$row['dataset']]=true;
    foreach(array_keys($membership) as $dataset){
        foreach($wholeChecks as $row){$row['dataset']=$dataset;$groups['datasets'][$dataset][]=$row;}
        foreach(array_keys($labels) as $key){
            if(isset($groups[$key][$dataset]))continue;
            $state='not_reached';$message='No execution was recorded for this dataset and stage.';
            if($key==='snapshots'&&!$scheduled){$state='not_required';$message='Uses the captured original snapshot.';}
            $verifiedPlan=isset($planned['space'][$dataset])||isset($planned['transfer'][$dataset])||isset($planned['verify'][$dataset]);
            if($key==='cleanup'&&$verifiedPlan){$state='not_required';$message='No destination deletion was planned.';}
            if(in_array($key,['space','transfer'],true)&&isset($planned['verify'][$dataset])&&!isset($planned['transfer'][$dataset])&&!isset($planned['space'][$dataset])){
                $state='not_required';$message='The recorded plan requires verification only.';
            }
            $groups[$key][$dataset][]=['dataset'=>$dataset,'state'=>$state,'attempts'=>0,'message'=>$message];
        }
    }
    $summaries=[];$page=[];$offset=max(0,$offset);
    foreach($labels as $key=>$label){
        $rows=[];
        foreach($groups[$key] as $dataset=>$items){
            $state=$aggregate(array_column($items,'state'));
            $rows[]=['dataset'=>$dataset,'state'=>$state,'attempts'=>array_sum(array_column($items,'attempts')),
                'message'=>json_decode(json_encode(substr(implode(' ',array_slice(array_unique(array_filter(array_column($items,'message'))),0,2)),0,512),JSON_INVALID_UTF8_SUBSTITUTE),true)];
        }
        $counts=array_count_values(array_column($rows,'state'));$state=$aggregate(array_keys($counts));
        if($key==='verify'&&$wholeVerify)$state=$aggregate(array_merge([$state],array_column($wholeVerify,'state')));
        if(!$rows && $key==='snapshots' && !$scheduled)$state='not_required';
        // A published downstream task proves inspection planned no work for this stage.
        if(!$rows && $key==='cleanup' && ($groups['space']||$groups['transfer']))$state='not_required';
        if(!$rows && in_array($key,['space','transfer'],true) && $groups['verify'] && !$scheduled)$state='not_required';
        $summaries[]=['id'=>$key,'label'=>$label,'state'=>$state,'datasets'=>count($rows),'counts'=>$counts];
        if($key===$selected){$slice=array_slice($rows,$offset,50);$page=['stage'=>$key,'rows'=>$slice,'total'=>count($rows),'offset'=>$offset,'previousOffset'=>$offset?max(0,$offset-50):null,'nextOffset'=>$offset+count($slice)<count($rows)?$offset+count($slice):null];}
    }
    return ['available'=>true,'stages'=>$summaries,'page'=>$page];
}
