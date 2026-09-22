<?php
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once __DIR__.'/coordinator-worker-client.php';
require_once __DIR__.'/source-retention.php';
require_once __DIR__.'/replication-schedule-plan.php';
require_once __DIR__.'/send-helpers.php';
$sequence=2;$p=[];
try {
    $task=(string)getenv('ZFSAS_TASK_ID');$path=$argv[1] ?? '';
    if ($path!=='/tmp/zfs-snapsync-coordinator/attempt-inputs/'.hash('sha256',$task).'.source.json' || is_link($path)) { throw new InvalidArgumentException('Invalid source retention capture.'); }
    $input=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if (($input['taskId'] ?? '')!==$task) { throw new InvalidArgumentException('Source retention capture belongs to another task.'); }
    $p=$input['parameters'];$pair=zfsas_config_read_pair('/boot/config/plugins/zfs.snapsync');
    if ($p['revision']!==$pair['revision']) { throw new InvalidArgumentException('Configuration changed. Review or wait for a new successful replication.'); }
    if ($p['phase']==='source_retention_guard') { $result=['outcome'=>'success','message'=>'Required source checkpoints are protected.']; }
    elseif ($p['phase']==='source_retention_review') {
        $review=zfsas_source_review($p,zfsas_send_parse_jobs($pair['send']['SEND_JOBS'] ?? ''));
        zfsas_source_review_write($p['reviewToken'],$review);
        $result=['outcome'=>'success','message'=>'Source retention review ready; expires in five minutes.'];
    } else {
        if (zfsas_source_member_policy(zfsas_source_policy($pair['send'],$p['job']),$p['source'])!==$p['policy']) { throw new InvalidArgumentException('Source retention authorization changed.'); }
        if ($p['phase']==='source_retention_prepare') {
            try {
                $plan=zfsas_source_plan($p,zfsas_send_parse_jobs($pair['send']['SEND_JOBS'] ?? ''));
                if ($plan['tasks']) { zfsas_replication_publish_plan(['tasks'=>$plan['tasks']],$sequence); }
                $result=['outcome'=>'success','summary'=>$plan['summary'],'message'=>sprintf('Source retention: %d eligible; %d protected.',$plan['summary']['eligible'],$plan['summary']['protected'])];
            } catch (RuntimeException | InvalidArgumentException $error) { $result=['outcome'=>'success','skippedDataset'=>true,'message'=>'Source cleanup deferred: '.$error->getMessage()]; }
        } elseif ($p['phase']==='source_retention_delete') {
            $read=[ZfsasReplicationInspection::class,'command'];
            $result=zfsas_source_delete($p,$read,static fn($name)=>ZfsasReplicationInspection::command(['destroy','--',$name]),
                static function($name) use ($p,&$sequence) {
                    if ($p['revision']!==zfsas_config_revision('/boot/config/plugins/zfs.snapsync')) { throw new InvalidArgumentException('Configuration changed; remaining cleanup stopped.'); }
                    zfsas_coordinator_worker_report('progress',$sequence++,['phase'=>'source_retention_delete','message'=>'Deleting source checkpoint '.$name]);
                },static function($row,$state,$message) use (&$sequence) {
                    zfsas_coordinator_worker_report('source_item',$sequence++,['snapshot'=>$row['snapshot'],'guid'=>$row['guid'],'state'=>$state,'message'=>$message]);
                });
        } else { throw new InvalidArgumentException('Unknown source retention phase.'); }
    }
} catch (Throwable $error) {
    if (isset($p['reviewToken'])) { zfsas_source_review_write($p['reviewToken'],['state'=>'failed','expires'=>time()+300,'error'=>$error->getMessage()]); }
    $result=['outcome'=>$error instanceof InvalidArgumentException?'validation_failure':'transient_failure','message'=>$error->getMessage()];
}
zfsas_coordinator_worker_report('result',$sequence,$result);
