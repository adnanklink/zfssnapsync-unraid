<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/coordinator-socket.php';
require_once __DIR__.'/coordinator-service.php';
require_once __DIR__.'/coordinator-executor.php';
require_once __DIR__.'/send-queue-helpers.php';

/** Never signal a worker. Scan recorded groups, including leaders already gone. */
function zfsas_lifecycle_idle(string $root, bool $settled=false): void
{
    $state=ZfsasCoordinatorState::readCommitted($root);
    foreach (glob($root.'/attempts/*/owner.json') as $path) {
        $token=basename(dirname($path));
        if (($state['attempts'][$token]['state'] ?? '')==='stopped') continue;
        $owner=json_decode((string)file_get_contents($path),true);
        if (!is_array($owner) || !isset($owner['pid'],$owner['start'])) throw new RuntimeException('Incomplete worker ownership; refresh blocked.');
        $members=ZfsasCoordinatorExecutor::members((int)$owner['pid'],(string)$owner['start']);
        if ($members===null || $members) throw new RuntimeException('A worker process group is active or its ownership changed. Retry after work finishes.');
    }
    // Also check journal evidence when an attempt directory has disappeared.
    foreach ($state['attempts'] ?? [] as $attempt) {
        if (($attempt['state'] ?? '')==='stopped') continue;
        if ($settled) throw new RuntimeException('Waiting for the coordinator to commit attempt completion; retry after work finishes.');
        if (empty($attempt['pid'])) throw new RuntimeException('An attempt is starting; retry after work finishes.');
        $members=ZfsasCoordinatorExecutor::members((int)$attempt['pid'],(string)$attempt['start']);
        if ($members===null || $members) throw new RuntimeException('Recorded workers remain active; refresh pending.');
    }
    foreach(array_merge(glob('/tmp/zfs-snapsync-ops/jobs/*.job'),glob('/boot/config/plugins/zfs.snapsync/ops_queue/jobs/*.job')) as $path){
        $job=zfsas_ops_parse_job_file($path);
        if(!$job)throw new RuntimeException('Unreadable legacy ownership record; refresh blocked.');
        if(!in_array($job['STATE'] ?? '',['running','canceling'],true))continue;
        if(!ctype_digit($job['WORKER_PGID'] ?? '')||!ctype_digit($job['WORKER_START'] ?? ''))throw new RuntimeException('Legacy worker ownership is incomplete; refresh blocked.');
        $members=ZfsasCoordinatorExecutor::members((int)$job['WORKER_PGID'],$job['WORKER_START']);
        if($members===null||$members)throw new RuntimeException('A recorded legacy pipeline remains active; retry after work finishes.');
    }
    // Legacy launchers have no coordinator grants. Fail closed while any exist.
    foreach (glob('/proc/[0-9]*/cmdline') as $path) {
        $pid=(int)basename(dirname($path)); if($pid===getmypid()) continue;
        $args=explode("\0",(string)@file_get_contents($path));
        foreach (array_slice($args,0,3) as $arg) {
            if(preg_match('~^/usr/local/emhttp/plugins/zfs[.]snapsync/(?:php/snapshot-batch-worker[.]php|scripts/coordinator-[a-z-]*attempt[.]sh)$~D',$arg))throw new RuntimeException('A legacy worker is active; retry after work finishes.');
            if (preg_match('~^/usr/local/sbin/zfs_snapsync(?:_send|_send_worker|_delete_worker|_queue_handler|_queue_kicker|_snapshot_manager_worker|_migrate_datasets|_recovery_scan)?$~D',$arg)) throw new RuntimeException('Legacy work is active; retry installation after it finishes.');
        }
    }
}
function zfsas_lifecycle(string $mode): void
{
    $runtime='/var/run/zfs-snapsync-coordinator';$root='/tmp/zfs-snapsync-coordinator';$maintenance='/boot/config/plugins/zfs.snapsync/maintenance';
    if(!is_dir($runtime)) mkdir($runtime,0770,true);
    $lock=fopen($runtime.'/lifecycle.lock','c');
    if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Another coordinator lifecycle action is running.');
    $mark=static function(string $state,string $message) use($runtime): void {
        $path=$runtime.'/refresh.json';$temp=$path.'.tmp';
        if(file_put_contents($temp,json_encode(['state'=>$state,'message'=>$message],JSON_THROW_ON_ERROR))===false || !rename($temp,$path)) throw new RuntimeException('Cannot publish refresh barrier.');
    };
    $createdMaintenance=false;
    try {
        if($mode==='verify') {
            if(!is_file($runtime.'/installation-ready')) throw new RuntimeException('Package installed but installation hook failed; runtime activation is unverified.');
            $hello=zfsas_coordinator_request(['action'=>'handshake']);
            if(!$hello['ok'] || !zfsas_service_compatibility($hello['result'])['compatible']) throw new RuntimeException('Package installed but running coordinator does not match.');
            return;
        }
        if($mode==='prepare') {
            if(is_file($maintenance)) throw new RuntimeException('Installation maintenance already exists; resolve the previous installation first.');
            if(!is_dir(dirname($maintenance))) mkdir(dirname($maintenance),0755,true);
            if(file_put_contents($maintenance,'installation')===false) throw new RuntimeException('Cannot establish installation admission barrier.');
            $createdMaintenance=true;
            if(is_file($runtime.'/installation-ready'))unlink($runtime.'/installation-ready');
        } elseif(is_file($maintenance) && $mode!=='activate') { throw new RuntimeException('Explicit installation maintenance is active.'); }
        try { $hello=zfsas_coordinator_request(['action'=>'handshake'],$runtime.'/control.sock',1); }
        catch(RuntimeException $e) { $hello=['ok'=>false]; }
        $matching=!empty($hello['ok']) && zfsas_service_compatibility($hello['result'])['compatible'];
        if($matching && $mode==='watchdog' && !is_file($runtime.'/refresh.json')) return;
        $mark('pending','Coordinator refresh pending; waiting for existing attempts to finish.');
        $ownerLock=fopen($runtime.'/owner.lock','c');
        $unowned=flock($ownerLock,LOCK_EX|LOCK_NB);
        if(!$unowned) {
            // An older daemon only understands the explicit installation barrier.
            if((empty($hello['result']['build']) || ($hello['result']['protocol'] ?? null)!==1 || !is_array($hello['result']['actions'] ?? null)) && !is_file($maintenance)) throw new RuntimeException('Older coordinator cannot be drained automatically. Retry installation when work finishes.');
            // A socket round trip fences any admission already in progress when the barrier appeared.
            $status=zfsas_coordinator_request(['action'=>'status'],$runtime.'/control.sock',2);
            if(!$status['ok'])throw new RuntimeException('Cannot confirm coordinator admission barrier.');
            zfsas_lifecycle_idle($root,true);
            $owner=json_decode((string)@file_get_contents($runtime.'/owner.json'),true);
            if(!$owner) {
                // Old builds did not publish owner.json. Verify the permanent flock holder.
                $stat=fstat($ownerLock);$matches=[];
                foreach(file('/proc/locks',FILE_IGNORE_NEW_LINES) as $line) {
                    $fields=preg_split('/\s+/',trim($line));
                    if(($fields[1] ?? '')!=='FLOCK'||($fields[3] ?? '')!=='WRITE') continue;
                    $device=explode(':',$fields[5] ?? '');
                    if(count($device)!==3 || (int)$device[2]!==$stat['ino']) continue;
                    $pid=(int)$fields[4];$args=explode("\0",(string)@file_get_contents('/proc/'.$pid.'/cmdline'));
                    if(!in_array('/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-daemon.php',$args,true)) continue;
                    $matches[]=ZfsasCoordinatorExecutor::identity($pid);
                }
                if(count($matches)===1) $owner=$matches[0];
            }
            $current=$owner?ZfsasCoordinatorExecutor::identity((int)$owner['pid']):null;
            if(!$current || $current['start']!==$owner['start']) throw new RuntimeException('Daemon ownership cannot be verified; ownership retained.');
            // Barrier is established and complete worker groups are gone. Only stop the daemon.
            if(!posix_kill($current['pid'],15)) throw new RuntimeException('Cannot stop idle coordinator.');
            $deadline=microtime(true)+5;
            do { usleep(50000);$unowned=flock($ownerLock,LOCK_EX|LOCK_NB); } while(!$unowned && microtime(true)<$deadline);
            if(!$unowned) throw new RuntimeException('Coordinator still owns its lock; refresh blocked.');
        }
        zfsas_lifecycle_idle($root);
        if($mode==='prepare') return; // Maintenance remains until explicit activation.
        if($mode==='activate' && is_file($maintenance) && !unlink($maintenance)) throw new RuntimeException('Cannot release installation maintenance.');
        flock($ownerLock,LOCK_UN);fclose($ownerLock);
        if(is_file($runtime.'/refresh.json')) unlink($runtime.'/refresh.json');
        $detach=__DIR__.'/../scripts/detach-worker.sh';
        exec('nohup /bin/bash '.escapeshellarg($detach).' php '.escapeshellarg(__DIR__.'/coordinator-daemon.php').' >> /var/log/zfs_snapsync_coordinator.log 2>&1 < /dev/null &');
        $deadline=microtime(true)+5;
        do {
            usleep(50000);
            try { $hello=zfsas_coordinator_request(['action'=>'handshake'],$runtime.'/control.sock',.2); if($hello['ok'] && zfsas_service_compatibility($hello['result'])['compatible']) return; }
            catch(RuntimeException $e) {}
        } while(microtime(true)<$deadline);
        throw new RuntimeException('Package installed, but coordinator activation failed. Inspect the coordinator log; watchdog will retry.');
    } catch(Throwable $error) {
        $mark('blocked',$error->getMessage());
        if($createdMaintenance) unlink($maintenance); // Aborted before package replacement.
        throw $error;
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}
if(realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) {
    try { $mode=$argv[1] ?? 'watchdog';if(!in_array($mode,['prepare','activate','watchdog','verify'],true))throw new InvalidArgumentException('Invalid lifecycle mode.');zfsas_lifecycle($mode); }
    catch(Throwable $e) { fwrite(STDERR,$e->getMessage()."\n");exit(1); }
}
