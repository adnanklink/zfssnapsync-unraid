<?php
/** Parses OpenZFS send -vP stderr only; never reads or changes stream payload. */
final class ZfsasTransferProgress
{
    private ?int $size = null;
    private ?int $bytes = null;
    private ?float $time = null;
    public function sample(string $line, float $now): ?array
    {
        if (preg_match('/^(?:full|incremental)\t.*\t(\d{1,18})\s*$/D', $line, $match)) { $this->size = (int)$match[1]; return null; }
        if (preg_match('/^size\s+(\d{1,18})\s*$/D', $line, $match)) { $this->size = (int)$match[1]; return null; }
        if (!preg_match('/^\d{2}:\d{2}:\d{2}\s+(\d{1,18})\s+\S+/', $line, $match)) { return null; }
        $bytes = (int)$match[1];
        if ($this->time !== null && $now - $this->time < 2) { return null; }
        $rate = $this->time !== null && $bytes >= $this->bytes ? ($bytes - $this->bytes) / ($now - $this->time) : null;
        $this->time = $now; $this->bytes = $bytes;
        $result = ['phase'=>'transfer','message'=>sprintf('%.1f MiB sent', $bytes / 1048576)];
        if ($rate !== null) { $result['message'] .= sprintf(' · %.1f MiB/s', $rate / 1048576); }
        if ($this->size > 0) {
            $result['percent'] = min(99, (int)floor($bytes / $this->size * 100));
            $result['message'] .= sprintf(' · %d%% of estimated stream', $result['percent']);
        }
        return $result;
    }
}

/** Read-only projection; never invent aggregate percentages for parallel members. */
function zfsas_run_progress(array $run, int $now): array
{
    $empty = ['messages'=>[], 'percent'=>null, 'phase'=>''];
    if (in_array($run['state'], ['complete','failed','canceled','skipped','recorded'], true)) { return $empty; }
    $tasks = array_values(array_filter($run['taskStatus'] ?? [], static fn($task)=>in_array($task['state'], ['launching','running','stopping','waiting','retry_wait'], true)));
    $running = array_values(array_filter($tasks, static fn($task)=>$task['state']==='running'));
    $current = $running ?: $tasks;
    $phases = []; $messages = []; $percent = null;
    foreach ($current as $task) {
        if ($task['state'] !== 'running') { $phases[] = ($task['blocked'] ?? '') ?: $task['state']; continue; }
        $sample = $task['progress'] ?? [];
        $phases[] = $sample['phase'] ?? $task['phase'] ?? $task['kind'];
        if (isset($task['progressAt']) && $now - $task['progressAt'] > 10) {
            $messages[] = 'Waiting for a fresh worker progress sample.'; continue;
        }
        if (!empty($sample['message'])) { $messages[] = $sample['message']; }
        if (count($current)===1) { $percent = $sample['percent'] ?? null; }
    }
    return ['messages'=>array_values(array_unique($messages)), 'percent'=>$percent,
        'phase'=>implode(', ', array_unique($phases))];
}
