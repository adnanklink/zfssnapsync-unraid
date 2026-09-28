<?php
/** Recheck journal authority immediately before an adapter mutates ZFS. */
trait ZfsasCoordinatorDeleteAuthority
{
    private function authorizeDeletion(string $taskId, array $payload): array
    {
        $task = $this->cleanupExecutionTask($this->state['tasks'][$taskId]);
        if ($task===null) { throw new InvalidArgumentException('Selected cleanup owner no longer authorizes this attempt.'); }
        $parameters = $task['parameters'];
        if(isset($parameters['autoMutation']) && !$this->autoMutationParentLive($task)) {
            throw new InvalidArgumentException('Auto policy attempt no longer authorizes deletion.');
        }
        $job = $parameters['deleteJob'] ?? null;
        if ($task['kind'] !== 'delete' || !$job
            || !is_string($payload['jobId'] ?? null) || $payload['jobId'] === ''
            || !is_string($payload['snapshot'] ?? null) || $payload['snapshot'] === ''
            || !is_string($payload['guid'] ?? null) || !preg_match('/^[0-9]{1,20}$/D', $payload['guid'])
            || array_diff(array_keys($payload), ['jobId', 'snapshot', 'guid'])
            || ($payload['jobId'] ?? null) !== ($job['JOB_ID'] ?? null)
            || ($payload['snapshot'] ?? null) !== ($job['SNAPSHOT'] ?? null)
            || ($payload['guid'] ?? null) !== ($job['SNAPSHOT_GUID'] ?? null)) {
            throw new InvalidArgumentException('Deletion identity is outside the captured grant.');
        }
        $run = $this->state['runs'][$task['runId']];
        $ownerId = $parameters['ownerRunId'] ?? '';
        $owner = $ownerId === '' ? $run : ($this->state['runs'][$ownerId] ?? null);
        if (!$owner || self::terminal($owner['state']) || $owner['state'] === 'canceling'
            || !empty($owner['upgradeReviewRequired']) || !empty($run['upgradeReviewRequired'])) {
            throw new InvalidArgumentException('Deletion owner no longer authorizes execution.');
        }
        if (str_starts_with($job['JOB_ID'], 'sm-')) {
            $item = $this->state['items'][$parameters['ownerItemId'] ?? ''] ?? null;
            $parent = $item ? ($this->state['tasks'][$item['taskId']] ?? null) : null;
            if (!$parent || $parent['runId'] !== $ownerId
                || ($parent['parameters']['batch']['action'] ?? '') !== 'delete'
                || empty($parent['parameters']['batch']['approvedAt'])
                || ($item['deletionTaskId'] ?? '') !== ($parameters['cleanupOriginTaskId'] ?? $taskId) || $item['state'] !== 'deleting'
                || empty($item['spec']['candidate']) || $item['spec']['snapshot'] !== $job['SNAPSHOT']
                || $item['spec']['guid'] !== $job['SNAPSHOT_GUID']) {
                throw new InvalidArgumentException('Deletion item approval is no longer current.');
            }
        }
        if ($this->deletionReferenceOwners($job['SNAPSHOT'], $job['SNAPSHOT_GUID'],
            ZfsasEndpointIdentity::deletionEndpoint($parameters))) {
            throw new InvalidArgumentException('Snapshot has a protected replication reference.');
        }
        return ['authorized'=>true];
    }
}
