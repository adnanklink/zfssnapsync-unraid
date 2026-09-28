<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/coordinator-worker-client.php';
require_once __DIR__.'/replication-ssh-delete.php';
require_once __DIR__.'/send-queue-helpers.php';
$sequence=1;
try {
    zfsas_coordinator_worker_report('progress',$sequence++,['phase'=>'receiver_cleanup','message'=>'Checking captured receiver cleanup authority.']);
    $task=(string)getenv('ZFSAS_TASK_ID');$path=$argv[1] ?? '';
    if($path!=='/tmp/zfs-snapsync-coordinator/attempt-inputs/'.hash('sha256',$task).'.remote-delete.json' || is_link($path)) {throw new InvalidArgumentException('Invalid receiver deletion capture.');}
    $input=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(($input['taskId'] ?? '')!==$task) {throw new InvalidArgumentException('Deletion capture belongs to another task.');}
    $p=$input['parameters'];$job=$p['deleteJob'];
    $revision=$p['revision'] ?? $p['pressure']['revision'] ?? '';
    if(empty($p['remoteOwnership']) || $revision!==zfsas_config_revision('/boot/config/plugins/zfs.snapsync')) {throw new InvalidArgumentException('Receiver cleanup configuration or ownership changed.');}
    $capture=$p['receiverCapture'];$identity=$capture['identity'];
    $reader=new ZfsasSshReceiverRead($capture['config'],$identity['pool'],[],$identity);
    if($reader->identity()['endpoint']!==$p['endpoint']) {throw new InvalidArgumentException('Receiver cleanup endpoint changed.');}
    $locks=zfsas_ops_dataset_gates($job['DATASET'],$p['endpoint']);
    if($locks===false) {$result=['outcome'=>'wait','reason'=>'resource','delay'=>1,'message'=>'Another operation owns receiver cleanup.'];}
    else {
        try {
            $result=zfsas_ssh_delete_preflight($p,[$reader,'read'],[$reader,'poolRead']);
            if(isset($result['inventoryHash'])) {
                $result=zfsas_ssh_delete_execute(new ZfsasSshReceiver($capture),(string)getenv('ZFSAS_ATTEMPT_TOKEN'),$job,$result['inventoryHash'],
                    static function()use($job,$revision,$p,$reader,&$sequence):?array {
                        if($revision!==zfsas_config_revision('/boot/config/plugins/zfs.snapsync')) {throw new InvalidArgumentException('Configuration changed before receiver deletion.');}
                        $fresh=zfsas_ssh_delete_preflight($p,[$reader,'read'],[$reader,'poolRead']);
                        if(!isset($fresh['inventoryHash'])) {return $fresh;}
                        zfsas_coordinator_worker_report('delete_authorize',$sequence++,['jobId'=>$job['JOB_ID'],'snapshot'=>$job['SNAPSHOT'],'guid'=>$job['SNAPSHOT_GUID']]);
                        return null;
                    });
            }
        } finally {foreach($locks as $lock){fclose($lock);}}
    }
} catch(Throwable $error) {
    $result=zfsas_replication_error_result($error,'validation_failure');$result['recoveryRequired']=true;
}
zfsas_coordinator_worker_report('result',$sequence,$result);
