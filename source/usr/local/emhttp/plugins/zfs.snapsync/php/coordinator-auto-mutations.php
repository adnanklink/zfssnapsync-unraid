<?php
/** The Auto policy driver proposes one mutation and waits for its owned result. */
trait ZfsasCoordinatorAutoMutations
{
    /** Preserve evidence while replacing only safely stopped automatic work. */
    public function replanPartialAuto(string $runId,array $parameters,int $now): bool
    {
        $run=$this->state['runs'][$runId] ?? null;
        if(!$run || $run['manual'] || $run['schedule']!=='auto' || $run['state']!=='failed'
            || $run['revision']===$parameters['revision'] || !empty($run['upgradeReviewRequired']))return false;
        if(($this->state['schedules']['auto']['runId'] ?? '')!==$runId)return false;
        foreach($this->state['runs'] as $other) {
            if($other['id']===$runId || self::terminal($other['state']))continue;
            foreach($other['tasks'] as $id)if(!empty($this->state['tasks'][$id]['parameters']['individualMutations']))return false;
        }
        $driver=null;$completed=[];$superseded=[];
        foreach($run['tasks'] as $id) {
            $task=$this->state['tasks'][$id];$p=$task['parameters'];
            if(!empty($task['supersededBy']))continue;
            if(in_array($task['state'],['running','launching','stopping'],true) || $task['attempt']!==null)return false;
            if(!empty($p['individualMutations'])) {
                $driver=$task;
                if(($task['result']['reason'] ?? '')!=='configuration')return false;
                $superseded[]=$id;continue;
            }
            $mutation=$p['autoMutation'] ?? null;
            if(!$mutation)return false;
            $physical=$this->state['tasks'][$p['cleanupTaskId'] ?? ''] ?? $task;
            if($physical['attempt']!==null || in_array($physical['state'],['running','launching','stopping'],true))return false;
            if($task['state']==='complete' && ($task['result']['itemState'] ?? '')==='completed') {
                if($mutation['proposal']['action']==='snapshot') {
                    $guid=$task['result']['guid'] ?? null;
                    if(!is_string($guid) || !preg_match('/^[0-9]{1,20}$/D',$guid))return false;
                    $proposal=$mutation['proposal'];$dataset=explode('@',$proposal['snapshot'])[0];
                    $completed[$dataset]=['role'=>'checkpoint','endpoint'=>'local','dataset'=>$dataset,
                        'datasetGuid'=>$proposal['datasetGuid'],'snapshot'=>$proposal['snapshot'],'guid'=>$guid];
                }
                continue;
            }
            foreach($this->state['attempts'] as $attempt) {
                if($attempt['taskId']!==$physical['id'])continue;
                // An older attempt without explicit pre-mutation evidence is
                // ambiguous; it cannot be retried using a settings save.
                if(!empty($attempt['autoMutationAuthorized']) || !isset($attempt['result'])
                    || ($attempt['result']['mutationStarted'] ?? true)!==false)return false;
            }
            $superseded[]=$id;
        }
        if(!$driver || ($driver['parameters']['scheduleSpec'] ?? null)!==$parameters['scheduleSpec']
            || $parameters['scheduleSpec']['kind']==='disabled' || !$parameters['mutationDatasets'])return false;
        foreach($driver['parameters']['completedAutoSnapshots'] ?? [] as $dataset=>$proof)$completed[$dataset] ??= $proof;
        $number=(int)($run['autoReplanCount'] ?? 0)+1;
        $id=$runId.':auto-replan-'.$number;
        $parameters['completedAutoSnapshots']=$completed;
        $this->checkReferenceAdmission(array_values($completed));
        $this->state['tasks'][$id]=['id'=>$id,'runId'=>$runId,'kind'=>'auto','dataset'=>'','parameters'=>$parameters,
            'dependencies'=>[],'references'=>array_values($completed),'state'=>'queued','attemptCount'=>0,'attempt'=>null,
            'retryAt'=>null,'retryMonotonic'=>null,'blocked'=>'','result'=>null];
        $this->registerReferences($id,array_values($completed));
        foreach($superseded as $old)$this->state['tasks'][$old]['supersededBy']=$id;
        $this->state['runs'][$runId]['tasks'][]=$id;
        $this->state['runs'][$runId]['autoReplanCount']=$number;
        $this->state['runs'][$runId]['replannedFrom'] ??= $run['revision'];
        $this->state['runs'][$runId]['replannedAt']=$now;
        $this->state['runs'][$runId]['revision']=$parameters['revision'];
        $this->state['runs'][$runId]['state']='queued';$this->state['runs'][$runId]['finishedAt']=null;
        $this->state['version']=max(8,$this->state['version']);
        $this->commit();return true;
    }

    private function proposeAutoMutation(string $parentId,string $token,array $proposal,int $now): array
    {
        $parent=$this->state['tasks'][$parentId];$p=$parent['parameters'];
        $action=$proposal['action'] ?? '';$snapshot=$proposal['snapshot'] ?? '';
        if($parent['kind']!=='auto' || empty($p['individualMutations'])
            || !in_array($action,['delete','snapshot'],true)
            || !is_string($snapshot) || !preg_match('/^([A-Za-z0-9][A-Za-z0-9._\/-]*)@([A-Za-z0-9_.:+-]+)$/D',$snapshot,$match)
            || !is_string($proposal['datasetGuid'] ?? null) || !preg_match('/^[0-9]{1,20}$/D',$proposal['datasetGuid'])
            || !is_string($proposal['inventoryHash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$proposal['inventoryHash'])
            || !is_string($proposal['policyDataset'] ?? null)
            || array_diff(array_keys($proposal),['action','snapshot','datasetGuid','inventoryHash','guid','txg','reason','policyDataset','pressure'])) {
            throw new InvalidArgumentException('Invalid individual Auto mutation proposal.');
        }
        $dataset=$match[1];
        $scope=$proposal['policyDataset'];
        if(!in_array($scope,$p['mutationDatasets'],true) || ($dataset!==$scope && !str_starts_with($dataset,$scope.'/'))
            || ($action==='snapshot' && $dataset!==$scope) || !str_starts_with($match[2],$p['mutationPrefix'])) {
            throw new InvalidArgumentException('Auto mutation is outside the captured dataset and prefix scope.');
        }
        if($action==='delete' && (!is_string($proposal['guid'] ?? null) || !preg_match('/^[0-9]{1,20}$/D',$proposal['guid'])
            || !is_string($proposal['txg'] ?? null) || !preg_match('/^[0-9]{1,20}$/D',$proposal['txg'])
            || !in_array($proposal['reason'] ?? '',['age_window','daily_consolidation','weekly_consolidation','space_pressure','zero_change_housekeeping'],true))) {
            throw new InvalidArgumentException('Auto deletion lacks exact snapshot identity and policy.');
        }
        if($action==='snapshot' && array_intersect(array_keys($proposal),['guid','txg','reason'])) {
            throw new InvalidArgumentException('Snapshot creation cannot supply deletion authority.');
        }
        if(($proposal['reason'] ?? '')==='space_pressure') {
            $pressure=$proposal['pressure'] ?? [];
            if(!in_array($pressure['dataset'] ?? null,$p['mutationDatasets'],true)
                || !is_int($pressure['requiredBytes'] ?? null) || $pressure['requiredBytes']<1
                || array_diff(array_keys($pressure),['dataset','requiredBytes'])) {
                throw new InvalidArgumentException('Auto pressure cleanup lacks its captured capacity target.');
            }
        } elseif(isset($proposal['pressure'])) {throw new InvalidArgumentException('Unexpected pressure authority.');}
        $identity=hash('sha256',json_encode([$action,$snapshot,$proposal['datasetGuid'],$proposal['guid'] ?? ''],JSON_THROW_ON_ERROR));
        $id=$parentId.':mutation-'.$identity;
        if(isset($this->state['tasks'][$id])) {
            $existing=$this->state['tasks'][$id];
            if(($existing['parameters']['autoMutation']['parentAttempt'] ?? '')!==$token) {
                throw new InvalidArgumentException('Previous Auto mutation requires reconciliation before another attempt.');
            }
            if($existing['parameters']['autoMutation']['proposal']!==$proposal) {
                throw new InvalidArgumentException('Auto mutation capture changed after admission.');
            }
            return ['mutationId'=>$id];
        }
        $previous=$this->state['tasks'][$parent['activeAutoMutation'] ?? ''] ?? null;
        if($previous && !self::terminal($previous['state'])) {
            throw new InvalidArgumentException('Finish the previous Auto mutation before proposing another.');
        }
        $parameters=['revision'=>$p['revision'],'autoMutation'=>[
            'parentTask'=>$parentId,'parentAttempt'=>$token,'proposal'=>$proposal]];
        if($action==='delete') {
            $parameters+=['nativeSchedule'=>true,'endpoint'=>'local','deleteJob'=>[
                'JOB_ID'=>'auto-'.substr($identity,0,40),'DATASET'=>$dataset,'DATASET_GUID'=>$proposal['datasetGuid'],
                'REQUESTED_EPOCH'=>(string)$now,'QUEUE_SORT'=>(string)$now,'SNAPSHOT_EPOCH'=>'0',
                'ESTIMATED_RECLAIM_BYTES'=>'0','SEND_PROTECTED'=>'0','SEND_SCHEDULE_JOB_ID'=>'','SEND_CONFIG_HASH'=>hash('sha256',$p['sendConfig'] ?? ''),
                'SNAPSHOT'=>$snapshot,'SNAPSHOT_NAME'=>$match[2],'SNAPSHOT_GUID'=>$proposal['guid'],
                'SNAPSHOT_CREATETXG'=>$proposal['txg'],'DELETE_SCOPE'=>'snapshot','DELETE_POOL'=>explode('/',$dataset)[0]]];
        }
        $this->state['tasks'][$id]=['id'=>$id,'runId'=>$parent['runId'],'kind'=>$action==='delete'?'delete':'finalize',
            'dataset'=>$dataset,'parameters'=>$parameters,'dependencies'=>[],'references'=>[],
            'state'=>'queued','attemptCount'=>0,'attempt'=>null,'retryAt'=>null,'retryMonotonic'=>null,'blocked'=>'','result'=>null];
        $this->state['runs'][$parent['runId']]['tasks'][]=$id;
        $this->state['tasks'][$parentId]['activeAutoMutation']=$id;
        return ['mutationId'=>$id];
    }

    /** A policy driver cannot lend a mutation grant after it loses ownership. */
    public function autoMutationParentLive(array $task): bool
    {
        $p=$task['parameters']['autoMutation'] ?? null;
        if(!$p) {return false;}
        $parent=$this->state['tasks'][$p['parentTask']] ?? null;
        return $parent && $parent['state']==='running' && !empty($parent['parameters']['individualMutations'])
            && $this->owned($parent['id'],$p['parentAttempt'])
            && !in_array($this->state['runs'][$parent['runId']]['state'],['canceling','canceled','failed','complete'],true);
    }

    private function authorizeAutoMutation(string $taskId,array $payload): array
    {
        $task=$this->cleanupExecutionTask($this->state['tasks'][$taskId]);
        $proposal=$task['parameters']['autoMutation']['proposal'] ?? null;
        if(!$task || !$proposal || !$this->autoMutationParentLive($task)
            || $payload!==['snapshot'=>$proposal['snapshot'],'datasetGuid'=>$proposal['datasetGuid']]) {
            throw new InvalidArgumentException('Auto policy attempt no longer authorizes this mutation.');
        }
        return ['authorized'=>true];
    }

    /** Read-only polling does not append journal events or renew a worker grant. */
    public function autoMutationStatus(array $request,string $generation): array
    {
        [$parentId,$token]=$this->workerOwner($request,$generation);
        $id=$request['mutationId'] ?? null;
        $task=is_string($id)?($this->state['tasks'][$id] ?? null):null;
        $p=$task['parameters']['autoMutation'] ?? [];
        if(($p['parentTask'] ?? '')!==$parentId || ($p['parentAttempt'] ?? '')!==$token) {
            throw new InvalidArgumentException('Auto mutation belongs to another policy attempt.');
        }
        return ['state'=>$task['state'],'result'=>$task['result']];
    }
}
