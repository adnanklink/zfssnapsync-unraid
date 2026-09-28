<?php
/** Independent requests delegate one exact mutation to a boot-local worker run. */
trait ZfsasCoordinatorSharedCleanup
{
    private array $sharedCleanupTasks = [];
    private array $sharedCleanupKeys = [];
    private array $sharedCleanupChanges = [];

    public function takeSharedCleanupChanges(): array
    {
        $ids=array_keys($this->sharedCleanupChanges);$this->sharedCleanupChanges=[];return $ids;
    }

    private function indexSharedCleanup(string $id): void
    {
        $task=$this->state['tasks'][$id] ?? null;
        $old=$this->sharedCleanupTasks[$id] ?? null;
        if ($old !== null && ($this->sharedCleanupKeys[$old] ?? '')===$id) { unset($this->sharedCleanupKeys[$old]); }
        unset($this->sharedCleanupTasks[$id]);
        if (!$task || empty($task['parameters']['sharedCleanup']) || self::terminal($task['state'])) { return; }
        $key=$task['parameters']['cleanupKey'];
        $this->sharedCleanupTasks[$id]=$key;$this->sharedCleanupKeys[$key]=$id;
    }

    public function cleanupOwnerLive(string $physicalId, string $ownerId): bool
    {
        $physical=$this->state['tasks'][$physicalId] ?? null;
        $capture=$physical['parameters']['cleanupOwners'][$ownerId] ?? null;
        $task=$this->state['tasks'][$ownerId] ?? null;
        if (!$capture || !$task || self::terminal($task['state'])
            || ($task['parameters']['cleanupTaskId'] ?? '')!==$physicalId) { return false; }
        $parameters=$task['parameters'];unset($parameters['cleanupTaskId']);
        if ($parameters!==$capture['parameters']) { return false; }
        foreach (array_unique([$task['runId'],$parameters['ownerRunId'] ?? $task['runId']]) as $runId) {
            $run=$this->state['runs'][$runId] ?? null;
            if (!$run || self::terminal($run['state']) || $run['state']==='canceling' || !empty($run['upgradeReviewRequired'])) { return false; }
        }
        if (str_starts_with($parameters['deleteJob']['JOB_ID'] ?? '', 'sm-')) {
            $item=$this->state['items'][$parameters['ownerItemId'] ?? ''] ?? null;
            $parent=$item ? ($this->state['tasks'][$item['taskId']] ?? null) : null;
            if (!$parent || $parent['runId']!==($parameters['ownerRunId'] ?? '')
                || ($parent['parameters']['batch']['action'] ?? '')!=='delete'
                || empty($parent['parameters']['batch']['approvedAt'])
                || ($item['deletionTaskId'] ?? '')!==$ownerId || $item['state']!=='deleting'
                || empty($item['spec']['candidate'])
                || $item['spec']['snapshot']!==$parameters['deleteJob']['SNAPSHOT']
                || $item['spec']['guid']!==$parameters['deleteJob']['SNAPSHOT_GUID']) { return false; }
            if (($capture['manualFingerprint'] ?? '')!==hash('sha256',json_encode(
                self::canonical([$parent['parameters']['batch'],$item['spec']]),JSON_THROW_ON_ERROR))) { return false; }
        }
        return true;
    }

    /** Only admitted native tasks or journal-owned manual items may share. */
    public function delegateCleanup(string $id, int $now): ?string
    {
        $task=$this->state['tasks'][$id];$p=$task['parameters'];
        if (!empty($p['sharedCleanup'])) { return null; }
        if (isset($p['cleanupTaskId'])) { return $p['cleanupTaskId']; }
        $endpoint=ZfsasEndpointIdentity::deletionEndpoint($p);
        if ($task['kind']!=='delete' || $task['attemptCount']!==0 || $task['attempt']!==null
            || !isset($p['deleteJob']) || !ZfsasEndpointIdentity::known($endpoint)
            || (empty($p['nativeSchedule']) && empty($p['ownerItemId']))) { return null; }
        $job=$p['deleteJob'];
        if ($this->state['version'] < 4) {
            // Publish the incompatibility boundary before any shared authority.
            // Older readers reject v4 instead of treating a physical worker as
            // an ownerless legacy deletion. Persistent config is untouched.
            $this->state['version']=4;$this->checkpoint();
        }
        $key=hash('sha256',json_encode([$endpoint,$job['DATASET'],$job['SNAPSHOT'],$job['SNAPSHOT_GUID']],JSON_THROW_ON_ERROR));
        $physical=$this->sharedCleanupKeys[$key] ?? null;
        if ($physical && ($this->state['runs'][$this->state['tasks'][$physical]['runId']]['state'] ?? '')==='canceling') { $physical=null; }
        if (!$physical) {
            // Empty owner membership has no execution authority if a crash occurs
            // between this receipt and the atomic delegation publication below.
            $parameters=$p;unset($parameters['ownerRunId'],$parameters['ownerItemId']);
            $parameters+=['sharedCleanup'=>true,'cleanupKey'=>$key,'cleanupOwners'=>[],'cleanupSelected'=>''];
            $receipt=$this->submit('cleanup-'.bin2hex(random_bytes(16)),['tasks'=>['mutation'=>[
                'kind'=>'delete','dataset'=>$task['dataset'],'parameters'=>$parameters]]],$now);
            $physical=$receipt['runId'].':mutation';
        }
        $capture=['parameters'=>$p];
        if (!empty($p['ownerItemId'])) {
            $item=$this->state['items'][$p['ownerItemId']];
            $capture['manualFingerprint']=hash('sha256',json_encode(self::canonical([
                $this->state['tasks'][$item['taskId']]['parameters']['batch'],$item['spec']]),JSON_THROW_ON_ERROR));
        }
        $this->state['tasks'][$physical]['parameters']['cleanupOwners'][$id]=$capture;
        $proxy=&$this->state['tasks'][$id];
        $proxy['parameters']['cleanupTaskId']=$physical;
        $proxy['state']='waiting';$proxy['blocked']='dependency';
        $proxy['dependencies'][]=$physical;$proxy['retryAt']=null;$proxy['retryMonotonic']=null;
        $this->state['runs'][$proxy['runId']]['state']='running';
        $this->commit();return $physical;
    }

    /** Select an intact approval; the worker must still recheck its policy on ZFS. */
    public function cleanupExecutionTask(array $task, bool $select=false): ?array
    {
        if (empty($task['parameters']['sharedCleanup'])) { return $task; }
        $control=$task['parameters'];$selected=$control['cleanupSelected'];
        if (!$this->cleanupOwnerLive($task['id'],$selected)) {
            if (!$select || $task['attempt']!==null) { return null; }
            $selected='';
            foreach ($control['cleanupOwners'] as $id=>$_) {
                if ($this->cleanupOwnerLive($task['id'],$id)) { $selected=$id;break; }
            }
            if ($selected==='') { return null; }
        }
        $parameters=$control['cleanupOwners'][$selected]['parameters'];
        $ownerRunId=$parameters['ownerRunId'] ?? $this->state['tasks'][$selected]['runId'];
        unset($parameters['ownerRunId'],$parameters['ownerItemId']);
        $parameters+=array_intersect_key($control,array_flip(['sharedCleanup','cleanupKey','cleanupOwners']));
        $parameters['cleanupSelected']=$selected;
        if ($select && $parameters!==$task['parameters']) {
            $this->state['tasks'][$task['id']]['parameters']=$parameters;
            $this->state['tasks'][$task['id']]['attemptCount']=0;
            unset($this->state['tasks'][$task['id']]['spaceProgress'],$this->state['tasks'][$task['id']]['lastDiagnostic']);
            $this->commit();
        }
        $task['parameters']=$parameters;
        $task['parameters']['ownerRunId']=$ownerRunId;
        $task['parameters']['ownerItemId']=$control['cleanupOwners'][$selected]['parameters']['ownerItemId'] ?? '';
        $task['parameters']['cleanupOriginTaskId']=$selected;
        return $task;
    }

    /** Fan out a proven mutation only after the physical worker has stopped. */
    private function finishSharedCleanup(string $id, int $now): void
    {
        $task=$this->state['tasks'][$id];
        if (empty($task['parameters']['sharedCleanup']) || !self::terminal($task['state']) || $task['attempt']!==null) { return; }
        $completed=$task['state']==='complete' && ($task['result']['itemState'] ?? '')==='completed';
        $canceled=$task['state']==='canceled';
        $selected=$task['parameters']['cleanupSelected'];
        foreach ($task['parameters']['cleanupOwners'] as $ownerId=>$_) {
            if (!$this->cleanupOwnerLive($id,$ownerId) || (!$completed && !$canceled && $ownerId!==$selected)) { continue; }
            $owner=&$this->state['tasks'][$ownerId];
            $owner['state']=$canceled ? 'failed' : $task['state'];
            $owner['result']=$canceled ? ['outcome'=>'validation_failure','message'=>'Shared deletion was canceled; review the unfinished request again.'] : $task['result'];
            $owner['result']['sharedCleanupTaskId']=$id;
            $owner['result']['authorizedByTaskId']=$selected;
            $owner['attemptCount']=$task['attemptCount'];$owner['blocked']='';
            $this->settle($owner['runId'],$now);unset($owner);
        }
        if (!$completed && !$canceled) {
            foreach ($task['parameters']['cleanupOwners'] as $ownerId=>$_) {
                if (!$this->cleanupOwnerLive($id,$ownerId)) { continue; }
                $physical=&$this->state['tasks'][$id];
                $physical['state']='queued';$physical['result']=null;$physical['blocked']='';
                $physical['retryAt']=null;$physical['retryMonotonic']=null;
                $physical['parameters']['cleanupSelected']='';$physical['attemptCount']=0;
                $this->state['runs'][$physical['runId']]['state']='running';
                $this->state['runs'][$physical['runId']]['finishedAt']=null;
                break;
            }
        }
    }

    /** Revocation never transfers a live worker's captured approval to another owner. */
    private function revokeCleanupOwner(string $id, int $now): array
    {
        $owner=$this->state['tasks'][$id];
        $physicalId=$owner['parameters']['cleanupTaskId'] ?? '';
        $task=$this->state['tasks'][$physicalId] ?? null;
        if (!$task || self::terminal($task['state'])) { return []; }
        $live=false;
        foreach ($task['parameters']['cleanupOwners'] as $other=>$_) {
            $live=$live || $this->cleanupOwnerLive($physicalId,$other);
        }
        $selected=($task['parameters']['cleanupSelected'] ?? '')===$id;
        if ($task['attempt']!==null && ($selected || !$live)) {
            $this->state['runs'][$owner['runId']]['cleanupShutdown'][$task['attempt']]=true;
        }
        if (!$live) { return $this->cancel($task['runId'],$now); }
        if ($selected && $task['attempt']!==null) {
            $this->state['tasks'][$physicalId]['state']='stopping';return [$task['attempt']];
        }
        return [];
    }

    /** Catch policy/run invalidation as well as explicit cancellation. */
    public function reconcileSharedCleanup(int $now): array
    {
        $tokens=[];$changed=false;
        foreach (array_keys($this->sharedCleanupTasks) as $id) {
            $task=$this->state['tasks'][$id];$live=[];
            foreach ($task['parameters']['cleanupOwners'] as $ownerId=>$_) {
                if ($this->cleanupOwnerLive($id,$ownerId)) { $live[]=$ownerId;continue; }
                $owner=$this->state['tasks'][$ownerId] ?? null;
                if ($owner && !self::terminal($owner['state'])) {
                    $this->state['tasks'][$ownerId]['state']='failed';
                    $this->state['tasks'][$ownerId]['result']=['outcome'=>'validation_failure','message'=>'Independent cleanup approval changed or expired; review this request again.'];
                    $this->settle($owner['runId'],$now);$this->sharedCleanupChanges[$ownerId]=true;$changed=true;
                }
            }
            if (!$live) {
                $tokens=array_merge($tokens,$this->cancel($task['runId'],$now));
                $this->sharedCleanupChanges[$id]=true;continue;
            }
            $selected=$task['parameters']['cleanupSelected'];
            if ($task['attempt']!==null && !in_array($selected,$live,true)) {
                $this->state['tasks'][$id]['state']='stopping';$tokens[]=$task['attempt'];$changed=true;
                $ownerRun=$this->state['tasks'][$selected]['runId'] ?? null;
                if ($ownerRun) { $this->state['runs'][$ownerRun]['cleanupShutdown'][$task['attempt']]=true; }
            }
        }
        if ($changed) { $this->commit(); }
        return array_values(array_unique($tokens));
    }
}
