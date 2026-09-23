<?php
/** Read-only compatibility information. The daemon captures this at startup. */
function zfsas_service_build(): string
{
    $files = array_merge(glob(__DIR__.'/*.php'), glob(__DIR__.'/../scripts/*.sh'), glob(__DIR__.'/../../../../sbin/zfs_snapsync*'));
    sort($files); $hash = hash_init('sha256');
    foreach ($files as $file) { hash_update($hash, basename($file)); hash_update_file($hash, $file); }
    return hash_final($hash);
}
function zfsas_service_handshake(): array
{
    return ['build'=>zfsas_service_build(), 'protocol'=>1, 'actions'=>[
        'status','watchdog','handshake','operation_detail','recovery_status','review_recovery','retry_reviewed',
        'worker_report','reload','auto','retry','replication_now','scheduled_replication','replication_receipt',
        'replication','delete','batch','cancel','resume','source_retention_review']];
}
function zfsas_service_compatibility(?array $running): array
{
    $installed=zfsas_service_build();
    $known=is_array($running) && is_string($running['build'] ?? null) && ($running['protocol'] ?? null)===1 && is_array($running['actions'] ?? null);
    $matching=$known && hash_equals($installed,$running['build']);
    $refresh=json_decode((string)@file_get_contents('/var/run/zfs-snapsync-coordinator/refresh.json'),true);
    return ['compatible'=>$matching,'installedBuild'=>$installed,'runningBuild'=>$running['build'] ?? null,
        'protocol'=>$running['protocol'] ?? null,'actions'=>$known?$running['actions']:[],
        'refresh'=>$refresh, 'refreshPending'=>is_array($refresh), 'message'=>is_array($refresh)?$refresh['message']:($matching?'Coordinator is current.':
        ($refresh['message'] ?? ($known?'Coordinator refresh pending; existing attempts may finish before new work starts.':'Older coordinator: job logs and recovery are unavailable. Retry installation when work finishes.')))];
}
function zfsas_service_request(array $request): array
{
    $hello=zfsas_coordinator_request(['action'=>'handshake']);
    $service=zfsas_service_compatibility($hello['ok']?($hello['result'] ?? null):null);
    if(!in_array($request['action'],$service['actions'],true)) return ['ok'=>false,'code'=>'unsupported_capability','retryable'=>false,'error'=>'This coordinator does not support '.$request['action'].'. '.$service['message'],'service'=>$service];
    if((!$service['compatible'] || $service['refreshPending']) && !in_array($request['action'],['operation_detail','recovery_status'],true)) return ['ok'=>false,'code'=>'refresh_pending','retryable'=>false,'error'=>$service['message'],'service'=>$service];
    $result=zfsas_coordinator_request($request);
    if(!$result['ok']) $result+=['code'=>'history_unavailable','retryable'=>false];
    return $result;
}
