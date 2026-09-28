<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auto-mutation.php';
require_once __DIR__.'/coordinator-worker-client.php';
$sequence=1;$mutationStarted=false;
try {
    $task=(string)getenv('ZFSAS_TASK_ID');$path=$argv[1] ?? '';
    if($path!=='/tmp/zfs-snapsync-coordinator/attempt-inputs/'.hash('sha256',$task).'.auto.json' || is_link($path))throw new InvalidArgumentException('Invalid Auto mutation capture.');
    $input=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if(($input['taskId'] ?? '')!==$task)throw new InvalidArgumentException('Auto capture belongs to another task.');
    $p=$input['parameters'];$proposal=$p['autoMutation']['proposal'];
    if($p['revision']!==zfsas_config_revision('/boot/config/plugins/zfs.snapsync'))throw new InvalidArgumentException('Configuration changed before Auto mutation.');
    $result=zfsas_auto_mutation_check($p);
    if(isset($result['ready'])) {
        zfsas_coordinator_worker_report('auto_authorize',$sequence++,['snapshot'=>$proposal['snapshot'],'datasetGuid'=>$proposal['datasetGuid']]);
        if($proposal['action']==='delete') {
            zfsas_coordinator_worker_report('delete_authorize',$sequence++,['jobId'=>$p['deleteJob']['JOB_ID'],'snapshot'=>$proposal['snapshot'],'guid'=>$proposal['guid']]);
        }
        if($p['revision']!==zfsas_config_revision('/boot/config/plugins/zfs.snapsync'))throw new InvalidArgumentException('Configuration changed at the Auto mutation boundary.');
        $mutationStarted=true;
        ZfsasReplicationInspection::autoMutation($proposal['action']==='delete'?'destroy':'snapshot',$proposal['snapshot']);
        $result=['outcome'=>'success','itemState'=>'completed','snapshot'=>$proposal['snapshot'],
            'message'=>$proposal['action']==='delete'?'Automatic snapshot deleted.':'Automatic snapshot created.'];
        if($proposal['action']==='snapshot') {
            $guid=trim(ZfsasReplicationInspection::command(['get','-H','-p','-o','value','guid','--',$proposal['snapshot']]));
            if(!preg_match('/^[0-9]{1,20}$/D',$guid))throw new RuntimeException('Created snapshot identity could not be verified.');
            $result['guid']=$guid;
        }
    }
} catch(Throwable $error) {
    $result=['outcome'=>'validation_failure','recoveryRequired'=>$mutationStarted,'mutationStarted'=>$mutationStarted,'message'=>$error->getMessage()];
}
zfsas_coordinator_worker_report('result',$sequence,$result);
