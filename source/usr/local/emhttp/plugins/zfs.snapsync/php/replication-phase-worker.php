<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/coordinator-worker-client.php';
require_once __DIR__ . '/replication-plan.php';
require_once __DIR__ . '/send-helpers.php';
require_once __DIR__ . '/replication-pressure.php';
require_once __DIR__ . '/transfer-progress.php';
require_once __DIR__ . '/operation-diagnostics.php';
try {
    zfsas_coordinator_worker_report('progress',2,['phase'=>'replication_validation','message'=>'Revalidating captured replication identities.']);
    $path = $argv[1] ?? ''; $task = getenv('ZFSAS_TASK_ID');
    if ($path !== '/tmp/zfs-snapsync-coordinator/attempt-inputs/'.hash('sha256',(string)$task).'.replication.json' || is_link($path)) {
        throw new InvalidArgumentException('Invalid replication capture path.');
    }
    $input = json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if (($input['taskId'] ?? '') !== $task) { throw new InvalidArgumentException('Replication input belongs to another task.'); }
    $parameters = $input['parameters']; $phase = $parameters['phase'];
    try {
        if ($parameters['revision'] !== zfsas_config_revision('/boot/config/plugins/zfs.snapsync')) {
            throw new InvalidArgumentException('Configuration changed; review replication again.');
        }
        $result = zfsas_replication_revalidate($parameters);
        if ($result['outcome'] === 'success') {
            $complete = $result['inspection']['mode'] === 'already_received';
            $readonly = ($parameters['replication']['purpose'] ?? 'backup') === 'restore' ? 'off' : 'on';
            // Apply only under the worker's dataset gates after identity validation.
            // Protect existing backups before transfer, including already-received retries.
            if (($phase === 'replication_transfer' || $phase === 'replication_verify')
                && $result['inspection']['destinationDatasetGuid'] !== null) {
                ZfsasReplicationInspection::command(['set','readonly='.$readonly,$parameters['replication']['destination']]);
            }
            if ($phase === 'replication_verify') {
                if (!$complete) { throw new InvalidArgumentException('Expected receiver checkpoint is absent; replication is not complete.'); }
                $actual = trim(ZfsasReplicationInspection::command(['get','-H','-o','value','readonly','--',$parameters['replication']['destination']]));
                if ($actual !== $readonly) { throw new RuntimeException('Receiver readonly policy could not be verified.'); }
                $result['message'] = 'Verified expected receiver snapshot and dataset GUIDs. '.($readonly === 'on' ? 'Backup destination is read-only.' : 'Restored destination is writable; mount it when ready.');
            } elseif (in_array($phase,['replication_space','replication_transfer'],true)) {
                if (!$complete) { $result = zfsas_replication_space($parameters); }
                if (!$complete && $phase === 'replication_space' && ($result['outcome'] ?? '')==='validation_failure' && ($result['reason'] ?? '')==='space') {
                    $sequence=3;
                    $result=zfsas_replication_pressure_proposal($parameters,$result,$sequence);
                }
                if (!$complete && $phase === 'replication_transfer' && $result['outcome'] === 'success') {
                    $rate = $parameters['rateLimit'] ?? '0';
                    if (zfsas_send_normalize_rate_limit($rate) === null || ($rate !== '0' && !trim((string)shell_exec('command -v mbuffer 2>/dev/null')))) {
                        throw new InvalidArgumentException('Configured transfer rate requires a valid rate and installed mbuffer.');
                    }
                    // Ownership is rechecked immediately before the mutation. The
                    // pipeline uses one incremental target, no intermediates or -F.
                    zfsas_coordinator_worker_report('progress',3,['phase'=>'transfer','message'=>'Transferring the verified incremental snapshot.']);
                    $sequence = 4;
                    $request = $parameters['replication'];
                    $resumeToken = $parameters['inspection']['mode'] === 'resume' ? zfsas_replication_resume_token($parameters) : '';
                    $process = proc_open(['/bin/bash','-o','pipefail','-c',
                        'if [[ -n "$5" ]]; then zfs send -vP -t "$5"; elif [[ -n "$1" ]]; then zfs send -vP -i "$1" "$2"; else zfs send -vP "$2"; fi | { if [[ "$4" == 0 ]]; then cat; else mbuffer -q -R "$4"; fi; } | zfs receive -s -u -o "readonly=$6" -- "$3"','snapsync-transfer',
                        $parameters['inspection']['base']['snapshot'] ?? '',$request['sourceSnapshot'],$request['destination'],$rate,$resumeToken,$readonly],
                        [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$pipes);
                    if (!is_resource($process)) { throw new RuntimeException('Cannot launch replication pipeline.'); }
                    // Drain diagnostics without retaining unbounded output or any
                    // stream payload. The coordinator remains responsive separately.
                    $diagnostic = '';
                    $meter = new ZfsasTransferProgress();
                    while (!feof($pipes[2])) {
                        $line = fgets($pipes[2],8192);
                        if ($line === false) { break; }
                        $diagnostic = substr($diagnostic . $line,-4096);
                        $progress = $meter->sample($line, hrtime(true) / 1e9);
                        if ($progress !== null) { zfsas_coordinator_worker_report('progress',$sequence++,$progress); }
                    }
                    zfsas_coordinator_worker_report('progress',$sequence++,['phase'=>'verification','message'=>'Transfer pipeline ended; verifying receiver checkpoint.']);
                    fclose($pipes[2]); $code = proc_close($process);
                    if ($code !== 0) {
                        $result = ['outcome'=>'transient_failure','recoveryRequired'=>true,'message'=>'Replication pipeline failed; receiver recovery must be validated before another mutation.','exitCode'=>$code,'failureCode'=>'transfer_failed','diagnostic'=>zfsas_diagnostic_text($diagnostic)];
                    } else {
                        $result = zfsas_replication_revalidate($parameters);
                        if ($result['outcome'] === 'success' && $result['inspection']['mode'] !== 'already_received') {
                            throw new InvalidArgumentException('Pipeline exited successfully without the expected receiver checkpoint.');
                        }
                    }
                }
            } else { throw new InvalidArgumentException('Unknown native replication phase.'); }
        }
    } catch (InvalidArgumentException $error) { $result=zfsas_replication_error_result($error,'validation_failure'); }
      catch (RuntimeException $error) { $result=zfsas_replication_error_result($error,'transient_failure'); }
    zfsas_coordinator_worker_report('result',$sequence ?? 3,$result);
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n"); exit(1); }
