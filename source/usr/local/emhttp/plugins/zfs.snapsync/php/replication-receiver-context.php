<?php
require_once __DIR__.'/replication-ssh-receiver.php';

/** Capture only connection fields; shared retention drafts never enter a plan. */
function zfsas_receiver_connection_capture(array $config): array
{
    $keys=['SEND_SSH_HOST','SEND_SSH_PORT','SEND_SSH_USER','SEND_SSH_KEY_PATH'];
    $capture=array_intersect_key($config,array_fill_keys($keys,true));
    ZfsasSshConnection::normalize($capture);return $capture;
}

/** Runs inside a granted worker, never inside the coordinator event loop. */
function zfsas_receiver_context(array $job,array $config,?array $expected=null): array
{
    if (($job['transport'] ?? 'local')==='local') {
        return ['read'=>[ZfsasReplicationInspection::class,'command'],'poolRead'=>[ZfsasReplicationInspection::class,'poolCommand'],'endpoint'=>'local','capture'=>null];
    }
    if (($job['transport'] ?? '')!=='ssh') {throw new InvalidArgumentException('Unsupported receiver transport.');}
    $connection=zfsas_receiver_connection_capture($config);
    $pool=explode('/',$job['destination'])[0];
    $local=trim(ZfsasReplicationInspection::poolCommand(['list','-H','-o','guid']));
    $guids=$local===''?[]:explode("\n",$local);
    foreach($guids as $guid) {if(!preg_match('/^[0-9]{1,20}$/D',$guid)){throw new RuntimeException('Local pool identities are unavailable.');}}
    $reader=new ZfsasSshReceiverRead($connection,$pool,$guids,$expected['identity'] ?? null);
    $identity=$reader->identity();
    if ($identity['endpoint']==='local') {
        // Loopback SSH shares local mutation gates and process ownership.
        // A changed pool import cannot silently switch an existing remote plan.
        return ['read'=>[ZfsasReplicationInspection::class,'command'],'poolRead'=>[ZfsasReplicationInspection::class,'poolCommand'],'endpoint'=>'local','capture'=>null];
    }
    return ['read'=>[$reader,'read'],'poolRead'=>[$reader,'poolRead'],'endpoint'=>$identity['endpoint'],
        'capture'=>['config'=>$connection,'identity'=>$identity]];
}
