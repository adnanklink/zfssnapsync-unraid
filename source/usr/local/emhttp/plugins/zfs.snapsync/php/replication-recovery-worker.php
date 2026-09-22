<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/coordinator-worker-client.php';
require_once __DIR__.'/replication-recovery.php';
require_once __DIR__.'/replication-schedule-plan.php';
try{
    zfsas_coordinator_worker_report('progress',2,['phase'=>'recovery_review','message'=>'Inspecting original snapshot and receiver identities.']);$sequence=3;
    $task=getenv('ZFSAS_TASK_ID');$path=$argv[1] ?? '';
    if($path!=='/tmp/zfs-snapsync-coordinator/attempt-inputs/'.hash('sha256',(string)$task).'.recovery.json'||is_link($path))throw new InvalidArgumentException('Invalid recovery input.');
    $input=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    if($input['taskId']!==$task)throw new InvalidArgumentException('Recovery input belongs to another task.');
    $p=$input['parameters'];
    try{
        if($p['phase']==='recovery_scan'){
            $members=$p['members'] ?: zfsas_replication_membership($p['job']);$tasks=[];
            $receivers=zfsas_recovery_receivers($p['job']);
            foreach($members as $i=>$member){
                $parent=substr($member['destination'],0,strrpos($member['destination'],'/'));
                $member['receiverGuid']=$receivers[$member['destination']] ?? null;
                $member['receiverParentGuid']=$receivers[$parent] ?? null;
                $tasks['member-'.$i]=['kind'=>'prepare','dataset'=>$member['source'],'parameters'=>$member+['phase'=>'recovery_member_review','revision'=>$p['revision']]];
            }
            $tasks['finish']=['kind'=>'finalize','dataset'=>$p['job']['source'],'parameters'=>['phase'=>'recovery_review_finish','revision'=>$p['revision']], 'dependencies'=>array_keys($tasks)];
            zfsas_replication_publish_plan(['tasks'=>$tasks],$sequence);
            $result=['outcome'=>'success','message'=>'Captured fixed recovery review membership.'];
        }elseif($p['phase']==='recovery_member_review'){
            $result=['outcome'=>'success','review'=>zfsas_recovery_inspect_member($p)];$result['message']=$result['review']['message'];
        }elseif(in_array($p['phase'],['recovery_review_finish','recovery_execute_start','recovery_finish'],true)){
            $result=['outcome'=>'success','message'=>$p['phase']==='recovery_finish'?'Every approved recovery member completed verification.':'Recovery step completed.'];
        }else throw new InvalidArgumentException('Unknown recovery phase.');
    }catch(Throwable $error){$result=['outcome'=>'validation_failure','message'=>zfsas_diagnostic_text($error->getMessage())];}
    zfsas_coordinator_worker_report('result',$sequence,$result);
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
