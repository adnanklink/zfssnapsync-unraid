<?php
/** Bounded, read-only projections of coordinator evidence. */
function zfsas_diagnostic_text(string $text): string
{
    $text=preg_replace('/(Authorization:\s*Bearer\s+)\S+/i','$1[redacted]',$text);
    $text=preg_replace('/((?:receive_resume_token|token|password|secret|credential|passphrase)\s*[:=]\s*)(?:"[^"\n]*"|\S+)/i','$1[redacted]',$text);
    $text=preg_replace('/(zfs\s+send\s+(?:-\S+\s+)*-t\s+)\S+/i','$1[redacted]',$text);
    $text=preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/','',$text);
    return json_decode(json_encode(substr($text,-4096),JSON_INVALID_UTF8_SUBSTITUTE),true);
}
function zfsas_operation_problem(array $tasks): ?array
{
    $failures=array_values(array_filter($tasks,static fn($t)=>$t['state']==='failed'));
    if (!$failures) return null;
    // A dependency that never ran is not the cause. Prefer the actual failed result.
    $task=$failures[0];$r=$task['result'] ?? [];$p=$task['parameters'] ?? [];
    $code=$r['failureCode'] ?? (($r['inspection']['resumeRequired'] ?? false)?'interrupted_receive':($r['reason'] ?? 'unknown'));
    $message=$r['message'] ?? 'This step stopped without recording an error.';
    if(str_contains($message,'Receiver has an interrupted transfer'))$code='interrupted_receive';
    if($code==='unknown'){
        if(preg_match('/(snapshot.*(?:absent|missing|not exist)|cannot open.*snapshot)/i',$message))$code='missing_snapshot';
        elseif(preg_match('/(base.*(?:absent|differ|changed)|no.*common|no.*base)/i',$message))$code='incompatible_base';
        elseif(preg_match('/(?:identity|identities|GUID).*(?:changed|differ)/i',$message))$code='identity_changed';
        elseif(str_contains($message,'Configuration changed'))$code='configuration';
        elseif(preg_match('/timeout|timed out/i',$message))$code='timeout';
    }
    if($code==='transfer_failed' && preg_match('/out of space|quota exceeded|no space left/i',$r['diagnostic'] ?? ''))$code='space';
    if($code==='space'){
        $message=isset($r['requiredBytes'],$r['availableBytes'])?sprintf('Not enough destination space: %.1f GiB required, %.1f GiB available.',$r['requiredBytes']/1073741824,$r['availableBytes']/1073741824):'The destination ran out of space or reached its quota during transfer.';
    }
    $diagnostic=zfsas_diagnostic_text(($r['diagnostic'] ?? '') ?: ($task['lastDiagnostic']['text'] ?? ''));
    $next=match($code){
        'interrupted_receive'=>'Review the interrupted transfer before sending another snapshot.',
        'space'=>'Free destination space or review the configured cleanup policy, then review Retry.',
        'missing_snapshot'=>'The original source snapshot is unavailable. Review the source; a fresh run cannot resume a missing snapshot.',
        'incompatible_base'=>'The source and receiver do not have the required matching base. Review both snapshot histories; no forced rollback will be attempted.',
        'identity_changed'=>'A source or receiver identity changed. Review the selected datasets before starting a new transfer.',
        'timeout'=>'Check that the source and destination pools are available, then start a fresh recovery review.',
        'configuration'=>'Review the current configuration before submitting new work.',
        default=>'Read this job’s diagnostic details and review recovery before retrying an interrupted transfer.'};
    return ['code'=>$code,'summary'=>$code==='interrupted_receive'?'An earlier transfer is unfinished at the destination.':zfsas_diagnostic_text($message),
        'nextAction'=>$next,'source'=>$r['blockedReceivers'][0]['source'] ?? $p['source'] ?? explode('@',$p['replication']['sourceSnapshot'] ?? $task['dataset'] ?? '')[0],
        'destination'=>$r['blockedReceivers'][0]['destination'] ?? $p['destination'] ?? $p['replication']['destination'] ?? $p['job']['destination'] ?? '',
        'phase'=>$p['phase'] ?? $task['phase'] ?? $task['kind'],'failedCount'=>count($failures),
        'diagnostic'=>$diagnostic, 'diagnosticAvailable'=>$diagnostic!==''];
}
function zfsas_operation_detail(ZfsasCoordinatorState $journal,string $id,int $offset=0): array
{
    $run=$journal->state['runs'][$id] ?? null;
    if(!$run)throw new InvalidArgumentException('Job history is unavailable for this boot.');
    $tasks=[];foreach($run['tasks'] as $taskId)$tasks[]=$journal->state['tasks'][$taskId];
    $events=[];$memberIds=array_fill_keys($run['tasks'],true);
    foreach($journal->state['attempts'] as $token=>$attempt){
        if(!isset($memberIds[$attempt['taskId']]))continue;
        $task=$journal->state['tasks'][$attempt['taskId']];$p=$task['parameters'];$r=$attempt['result'] ?? $attempt['reportedResult'] ?? [];
        $events[]=['taskId'=>$task['id'],'attemptId'=>$token,'at'=>$attempt['finishedAt'] ?? $attempt['createdAt'],
            'phase'=>$p['phase'] ?? $task['kind'],'source'=>$p['source'] ?? $p['replication']['sourceSnapshot'] ?? $task['dataset'],
            'destination'=>$p['destination'] ?? $p['replication']['destination'] ?? $p['job']['destination'] ?? '',
            'state'=>$r['outcome'] ?? $attempt['state'],'message'=>zfsas_diagnostic_text($r['message'] ?? 'No result recorded for this attempt.'),
            'diagnostic'=>zfsas_diagnostic_text($r['diagnostic'] ?? ''),'exitCode'=>$r['exitCode'] ?? null];
    }
    $attempted=array_fill_keys(array_column($events,'taskId'),true);
    foreach($tasks as $task){
        if(isset($attempted[$task['id']]))continue;
        $events[]=['taskId'=>$task['id'],'at'=>$run['finishedAt'] ?? $run['createdAt'],'phase'=>$task['parameters']['phase'] ?? $task['kind'],
            'source'=>$task['dataset'],'destination'=>'','state'=>$task['state'],
            'message'=>zfsas_diagnostic_text($task['result']['message'] ?? ($task['state']==='canceled'?'Did not run: prerequisite work failed or the run was canceled.':'Waiting for prerequisites.')),'diagnostic'=>''];
    }
    usort($events,static fn($a,$b)=>$a['at']<=>$b['at'] ?: strcmp($a['taskId'],$b['taskId']));
    if($offset<0){
        $offset=count($events);$tailBytes=0;$tailCount=0;
        while($offset>0 && $tailCount<200){$size=strlen(json_encode($events[$offset-1],JSON_THROW_ON_ERROR));if($tailBytes+$size>120000)break;$offset--;$tailCount++;$tailBytes+=$size;}
    }else $offset=max(0,$offset);$page=[];$bytes=0;
    foreach(array_slice($events,$offset,200) as $event){$size=strlen(json_encode($event,JSON_THROW_ON_ERROR));if($bytes+$size>120000)break;$page[]=$event;$bytes+=$size;}
    $next=$offset+count($page);
    return ['runId'=>$id,'state'=>$run['state'],'problem'=>zfsas_operation_problem($tasks),'entries'=>$page,
        'previousOffset'=>$offset>0?max(0,$offset-200):null,'nextOffset'=>$next<count($events)?$next:null,'total'=>count($events),'scope'=>'job','historyNotice'=>'Runtime history is lost after reboot. Older attempts may not have recorded ZFS diagnostics.'];
}

function zfsas_replication_error_result(Throwable $error,string $outcome='validation_failure'): array
{
    return ['outcome'=>$outcome,'message'=>zfsas_diagnostic_text($error->getMessage()),
        'diagnostic'=>zfsas_diagnostic_text($error->diagnostic ?? ''),'exitCode'=>$error->getCode() ?: null];
}
