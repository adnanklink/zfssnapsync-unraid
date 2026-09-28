<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/coordinator-worker-client.php';
require_once __DIR__.'/coordinator-retirement.php';
require_once __DIR__.'/replication-ssh-delete.php';
require_once __DIR__.'/send-queue-helpers.php';
$sequence=1;
try{
    $task=(string)getenv('ZFSAS_TASK_ID');$path=$argv[1] ?? '';
    if($path!=='/tmp/zfs-snapsync-coordinator/attempt-inputs/'.hash('sha256',$task).'.retirement.json'||is_link($path))throw new InvalidArgumentException('Invalid retirement capture.');
    $input=json_decode(file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
    if($input['taskId']!==$task)throw new InvalidArgumentException('Retirement task mismatch.');
    $p=$input['parameters'];$revision=$p['revision'];
    if($revision!==zfsas_config_revision('/boot/config/plugins/zfs.snapsync'))throw new InvalidArgumentException('Settings changed. Review again.');
    if($p['phase']==='retirement_review'){
        $target=$p['target'];$context=zfsas_receiver_context(['transport'=>$target['transport'],'destination'=>$target['dataset']],$p['connection']);
        $target['endpoint']=$context['endpoint'];if($context['capture'])$target['receiverCapture']=$context['capture'];
        $capture=zfsas_retirement_inventory($target,$context['read'],$p['prefixes']);
        $output=dirname($path).'/'.hash('sha256',$task).'.retirement-review.json';$text=json_encode($capture,JSON_THROW_ON_ERROR);
        if(file_put_contents($output.'.pending',$text)!==strlen($text)||!rename($output.'.pending',$output))throw new RuntimeException('Cannot publish retirement inventory.');
        $result=['outcome'=>'success','message'=>count($capture['rows']).' snapshots inspected.'];
    }else{
        $target=$p['retirement']['target'];$row=$p['retirement']['row'];$job=$p['deleteJob'];$read=[ZfsasReplicationInspection::class,'command'];
        if(isset($p['receiverCapture'])){$capture=$p['receiverCapture'];$reader=new ZfsasSshReceiverRead($capture['config'],$capture['identity']['pool'],[],$capture['identity']);$read=[$reader,'read'];}
        $locks=$target['endpoint']==='local'?[]:zfsas_ops_dataset_gates($target['dataset'],$target['endpoint']);
        // Local gates are held by the shell wrapper; avoid taking them twice.
        if($target['endpoint']==='local'){$locks=[];}
        if($locks===false)$result=['outcome'=>'wait','reason'=>'resource','message'=>'Another operation owns this receiver.'];
        else try{
            $check=static function()use($target,$row,$read,$revision):array{
                if($revision!==zfsas_config_revision('/boot/config/plugins/zfs.snapsync'))throw new InvalidArgumentException('Settings changed before deletion.');
                $fresh=zfsas_retirement_inventory($target,$read,[]);
                if($fresh['target']['datasetGuid']!==$target['datasetGuid'])throw new InvalidArgumentException('Dataset identity changed.');
                $current=$fresh['rows'][$row['key']] ?? null;
                if(!$current||$current['guid']!==$row['guid']||$current['txg']!==$row['txg']||$current['created']!==$row['created']||$current['reason']!=='')throw new InvalidArgumentException('Snapshot identity or protection changed. Review remaining snapshots again.');
                return $fresh;
            };
            $fresh=$check();
            $authorize=static function()use($check,$job,&$sequence):void{$check();zfsas_coordinator_worker_report('delete_authorize',$sequence++,['jobId'=>$job['JOB_ID'],'snapshot'=>$job['SNAPSHOT'],'guid'=>$job['SNAPSHOT_GUID']]);};
            if(isset($p['receiverCapture']))$result=zfsas_ssh_delete_execute(new ZfsasSshReceiver($p['receiverCapture']),(string)getenv('ZFSAS_ATTEMPT_TOKEN'),$job,$fresh['inventoryHash'],$authorize);
            else{$authorize();ZfsasReplicationInspection::autoMutation('destroy',$row['snapshot']);$result=['outcome'=>'success','itemState'=>'completed','message'=>'Retired snapshot deleted: '.$row['snapshot']];}
        }finally{foreach($locks as $lock)fclose($lock);}
    }
}catch(Throwable $error){$result=zfsas_replication_error_result($error,'validation_failure');$result['recoveryRequired']=true;}
zfsas_coordinator_worker_report('result',$sequence,$result);
