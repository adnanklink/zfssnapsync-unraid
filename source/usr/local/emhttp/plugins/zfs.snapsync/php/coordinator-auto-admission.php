<?php
/** Configuration admission only: no ZFS inspection or mutation. */
function zfsas_coordinator_auto_command(ZfsasCoordinatorState $journal, array $task, array $config, string $root): array
{
    $parameters = $task['parameters'];
    if ($parameters['revision'] !== $config['revision']) {
        $replacement = ['revision' => $config['revision'], 'autoConfig' => $config['rawAuto'],
            'sendConfig' => $config['rawSend'], 'prefixHistory' => $config['prefixHistory'],
            'scheduleSpec' => $config['schedule']];
        // A converted/disabled schedule must obey its new first-run time. Do not
        // turn an old accepted occurrence into an immediate run of the new one.
        $sameSchedule = isset($parameters['scheduleSpec'])
            && $parameters['scheduleSpec'] == $config['schedule']
            && $config['schedule']['kind'] !== 'disabled';
        if (!$sameSchedule || trim($config['auto']['DATASETS'] ?? '') === ''
            || !$journal->replanAuto($task['id'], $config['revision'], $replacement, time())) {
            $manual = $journal->state['runs'][$task['runId']]['manual'];
            return ['outcome' => 'validation_failure', 'reason' => 'configuration',
                'message' => $manual ? 'Configuration changed; review and submit a new run.'
                    : 'Configuration changed; this run cannot be safely replanned. The next scheduled occurrence may run.'];
        }
        $parameters = $replacement;
    }
    $capture = $root . '/config/' . $parameters['revision'];
    if (!is_dir($capture) && !mkdir($capture, 0700, true)) { throw new RuntimeException('Cannot create captured configuration directory.'); }
    foreach (['zfs_snapsync.conf' => 'autoConfig', 'zfs_send.conf' => 'sendConfig', 'send-prefix-history' => 'prefixHistory'] as $file => $key) {
        $path = $capture . '/' . $file;
        // A service crash during capture must not leave a partial file that the
        // next attempt mistakes for its committed configuration.
        if (@file_get_contents($path) === $parameters[$key]) { continue; }
        if (file_put_contents($path . '.pending', $parameters[$key]) !== strlen($parameters[$key])
            || !rename($path . '.pending', $path)) { throw new RuntimeException('Cannot publish captured configuration.'); }
    }
    if(empty($parameters['individualMutations'])) {
        $parameters['individualMutations']=true;
        $parameters['mutationDatasets']=array_values(array_unique(array_map(static fn($entry)=>trim(substr($entry,0,strrpos($entry,':'))),explode(',',$config['auto']['DATASETS']))));
        $parameters['mutationPrefix']=$config['auto']['PREFIX'];
        $journal->state['tasks'][$task['id']]['parameters']=$parameters;
        $journal->commit();
    }
    $completed=$root.'/config/'.hash('sha256',$task['id']).'.auto-completed.json';
    $text=json_encode(['taskId'=>$task['id'],'revision'=>$parameters['revision'],'snapshots'=>$parameters['completedAutoSnapshots'] ?? []],JSON_THROW_ON_ERROR);
    if(file_put_contents($completed.'.pending',$text)!==strlen($text) || !rename($completed.'.pending',$completed))throw new RuntimeException('Cannot capture completed Auto snapshots.');
    return ['/usr/bin/env', 'ZFSAS_COORDINATED=1', 'ZFSAS_INDIVIDUAL_AUTO=1', 'ZFSAS_AUTO_COMPLETED_FILE='.$completed, 'CONFIG_FILE=' . $capture . '/zfs_snapsync.conf',
        'ZFSAS_CONFIG_REVISION=' . $parameters['revision'], '/bin/bash', __DIR__.'/../scripts/coordinator-auto-attempt.sh'];
}

function zfsas_coordinator_replan_partial_auto(ZfsasCoordinatorState $journal,string $runId,string $configDir): void
{
    $run=$journal->state['runs'][$runId] ?? null;
    if(!$run || $run['state']!=='failed' || $run['manual'] || $run['schedule']!=='auto'
        || is_file(zfsas_ops_control_path('paused','auto')) || is_file($configDir.'/maintenance')
        || is_file('/var/run/zfs-snapsync-coordinator/refresh.json'))return;
    $pair=zfsas_config_read_pair($configDir,true);
    if(!$pair || $pair['revision']===$run['revision'] || trim($pair['auto']['DATASETS'])==='')return;
    $parameters=['revision'=>$pair['revision'],'autoConfig'=>$pair['rawAuto'],'sendConfig'=>$pair['rawSend'],
        'prefixHistory'=>$pair['prefixHistory'],'scheduleSpec'=>ZfsasSchedule::autoConfig($pair['auto']),
        'individualMutations'=>true,'mutationPrefix'=>$pair['auto']['PREFIX'],
        'mutationDatasets'=>array_values(array_unique(array_map(static fn($entry)=>trim(substr($entry,0,strrpos($entry,':'))),explode(',',$pair['auto']['DATASETS']))))];
    $journal->replanPartialAuto($runId,$parameters,time());
}

function zfsas_coordinator_auto_mutation_command(array $task,ZfsasCoordinatorState $journal,string $root,string $revision): array
{
    if(!$journal->autoMutationParentLive($task) || $task['parameters']['revision']!==$revision) {
        return ['outcome'=>'validation_failure','recoveryRequired'=>true,'message'=>'Auto policy ownership or configuration changed before mutation.'];
    }
    $path=$root.'/attempt-inputs/'.hash('sha256',$task['id']).'.auto.json';
    if(!is_dir(dirname($path)) && !mkdir(dirname($path),0700,true))throw new RuntimeException('Cannot create Auto capture directory.');
    $text=json_encode(['taskId'=>$task['id'],'parameters'=>$task['parameters']],JSON_THROW_ON_ERROR);
    if(file_put_contents($path.'.pending',$text)!==strlen($text) || !rename($path.'.pending',$path))throw new RuntimeException('Cannot publish Auto mutation capture.');
    $proposal=$task['parameters']['autoMutation']['proposal'];
    return ['/bin/bash',__DIR__.'/../scripts/coordinator-auto-mutation-attempt.sh',$path,$proposal['action'],explode('@',$proposal['snapshot'])[0]];
}
