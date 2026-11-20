<?php
require_once __DIR__.'/transfer-progress.php';

/** Project the same Activity row for recent history and paged failure review. */
function zfsas_workspace_operation(array $run): ?array
{
    $terminal = ['complete', 'completed', 'failed', 'canceled', 'skipped', 'recorded'];
    if (!empty($run['sourceReview']) || !empty($run['recoveryReview'])) { return null; }
    $sourceCleanup=!empty($run['sourceCleanupOf']);
    $kinds = $run['kinds'] ?? [];
    $replication = in_array('send',$kinds,true) || in_array('prepare',$kinds,true) || in_array('finalize',$kinds,true);
    if (!in_array('auto', $kinds, true) && !in_array('batch', $kinds, true) && !$replication) { return null; }
    $auto = in_array('auto', $kinds, true) && empty($run['nativeReplication']);
    $display = zfsas_run_progress($run, time());
    $details = $display['messages']; $datasets = []; $resultMessages = [];
    $progress = $display['percent']; $phase = $display['phase'];
    foreach ($run['taskStatus'] ?? [] as $task) {
        if (!empty($task['dataset'])) { $datasets[] = $task['dataset']; }
        if (!empty($task['result']['message'])) { $resultMessages[] = $task['result']['message']; }
    }
    if (!$details) { $details = $run['state']==='failed' ? [$run['problem']['summary'] ?? 'A required step failed. Open job details for the cause.'] : $resultMessages; }
    if ($sourceCleanup) { $c=$run['sourceCleanup'];array_unshift($details,sprintf('Source cleanup: %d deleted, %d skipped, %d protected, %d datasets deferred.',$c['deleted'],$c['skipped'],$c['protected'],$c['deferred'])); }
    return ['id' => 'coordinator:' . $run['id'], 'nativeId' => $run['id'], 'coordinator'=>true,'manual'=>$run['manual'] ?? false,'type' => $auto ? 'auto' : ($replication ? 'replication' : 'batch'),
        'title' => !empty($run['retirement']) ? 'Dataset retirement' : ($sourceCleanup ? 'Source cleanup' : ($auto ? 'Automatic snapshots' : ($replication ? 'Replication' : 'Snapshot batch'))), 'source' => implode(', ', array_unique($datasets)),
        'failureResolved'=>!empty($run['failureResolved']), 'problem'=>$run['problem'] ?? null, 'destination' => $run['problem']['destination'] ?? '', 'state' => $run['state'], 'stateLabel'=>$display['stateLabel'] ?? null, 'message' => implode(' ', array_unique($details)),
        'sourceCleanup'=>$run['sourceCleanup'] ?? null,'sourceCleanupRunId'=>$run['sourceCleanupRunId'] ?? null,'sourceCleanupOf'=>$run['sourceCleanupOf'] ?? null,
        'cleanup'=>$run['cleanup'] ?? null, 'createdAt' => $run['createdAt'], 'finishedAt' => $run['finishedAt'], 'progress' => in_array($run['state'], $terminal, true) ? null : $progress,
        'phase' => in_array($run['state'], $terminal, true) ? '' : ($phase ?: (implode(', ', $run['blockedReasons'] ?? []) ?: 'Queued')),
        'blocked' => $run['blockedReasons'] ?? [], 'retryAt' => $run['nextRetry'] ?? null,
        'recoveryRequired' => $run['recoveryRequired'] ?? false,
        'attentionVersion'=>hash('sha256',json_encode(array_map(static fn($task)=>[$task['id'],$task['attemptCount'] ?? 0,$task['state'],$task['result'] ?? null],$run['taskStatus'] ?? []),JSON_THROW_ON_ERROR)),
        'scheduleId'=>$run['scheduleId'] ?? null, 'actions' => !in_array($run['state'], array_merge($terminal, ['canceling']), true) ? ['cancel'] : (!empty($run['canReviewRecovery']) ? ['review_recovery'] : (!empty($run['canRetry']) ? ['retry'] : [])),
        'url' => ($replication ? '/Settings/ZFSSnapSync?section=replication' : '/Settings/ZFSSnapSync?section=snapshots') . ($auto ? '&tab=automation' : ''),
        'logType' => $auto ? 'auto' : ($replication ? 'replication' : 'batch')];
}
