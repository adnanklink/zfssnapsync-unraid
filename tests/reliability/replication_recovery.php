<?php
$plugin=__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php';
require $plugin.'/coordinator-state.php';require $plugin.'/coordinator-recovery.php';require $plugin.'/send-helpers.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function reject($fn){try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe recovery accepted');}
$token='private-token';$target='tank/source@A';$sourceGuid='10';$receiverGuid='20';$calls=[];
$read=function($a)use(&$token,&$target,&$sourceGuid,&$receiverGuid,&$calls){
 $calls[]=$a;$name=end($a);
 if($a[0]==='send')return "toname = $target\ntoguid = 400\nfromguid = 200\n";
 if($a[0]==='list'){
  if(in_array('name,guid',$a,true))return "backup\t5\nbackup/target\t$receiverGuid\n";
  return $name==='tank/source'?"tank/source@base\t200\t1\ntank/source@A\t400\t2\ntank/source@B\t500\t3\n":"backup/target@base\t200\t1\n";
 }
 if(in_array('receive_resume_token',$a,true))return $token;
 return match($name){'tank/source'=>$sourceGuid,'backup/target'=>$receiverGuid,'tank/source@A'=>'400','tank/source@B'=>'500','tank/source@base','backup/target@base'=>'200',default=>throw new RuntimeException('Unexpected read '.json_encode($a))};
};
$p=['source'=>'tank/source','sourceDatasetGuid'=>'10','destination'=>'backup/target','snapshot'=>['snapshot'=>'tank/source@B','guid'=>'500']];
$review=zfsas_recovery_inspect_member($p,$read);
check($review['eligible']&&$review['snapshot']==='tank/source@A','Run Now B replaced interrupted snapshot A');
check($review['inspection']['mode']==='resume'&&count($review['inspection']['references'])===3,'Resume bases were not protected');
check(!str_contains(json_encode($review),'private-token'),'Review exposed token');
check(!array_filter($calls,fn($a)=>!in_array($a[0],['get','list','send'],true)||($a[0]==='send'&&!in_array('-nvt',$a,true))),'Review mutated ZFS');
$target='elsewhere@A';check(!zfsas_recovery_inspect_member($p,$read)['eligible'],'Foreign source was adopted');$target='tank/source@A';
$sourceGuid='11';check(!zfsas_recovery_inspect_member($p,$read)['eligible'],'Changed source GUID was accepted');$sourceGuid='10';
$blocked=zfsas_recovery_preflight(['destination'=>'backup/target'],[$p],$read);check($blocked['failureCode']==='interrupted_receive','Run Now preflight ignored interrupted receive');
$token='-';$no=$p;unset($no['snapshot']);check(!zfsas_recovery_inspect_member($no,$read)['eligible'],'Lost RAM reconstructed snapshot authority');$token='private-token';
$j=new ZfsasCoordinatorState('/tmp/recovery-'.bin2hex(random_bytes(8)));$revision=str_repeat('a',64);
$job=['id'=>'abcdef123456','source'=>'tank/source','destination'=>'backup/target','frequency'=>'1d','threshold'=>'0G','children'=>'0','transport'=>'local'];
$config=['revision'=>$revision,'send'=>['SEND_JOBS'=>zfsas_send_render_jobs_string([$job]),'SEND_RATE_LIMIT'=>'0']];
$original=$j->submit('failed',['tasks'=>['prepare'=>['kind'=>'prepare','parameters'=>['phase'=>'replication_schedule','job'=>$job]]]],time())['runId'];
$j->rejectAdmission($original.':prepare',$blocked,1,time());
$start=zfsas_recovery_begin($j,['commandId'=>'review','runId'=>$original],$config);$id=$start['runId'];
$plan=['tasks'=>['member'=>['kind'=>'prepare','dataset'=>$p['source'],'parameters'=>$p+['phase'=>'recovery_member_review','revision'=>$revision]],'finish'=>['kind'=>'finalize','dataset'=>$p['source'],'parameters'=>['phase'=>'recovery_review_finish','revision'=>$revision],'dependencies'=>['member']]]];
function startTask($j,$id){$token=$j->claim($id,1,time(),'fixture');$j->started($id,$token,123,'456');return $token;}
$t=$id.':prepare';$owner=startTask($j,$t);$j->workerReport(['taskId'=>$t,'token'=>$owner,'generation'=>'fixture','sequence'=>1,'type'=>'plan','payload'=>$plan],'fixture',time());$j->result($t,$owner,['outcome'=>'success'],1,time(),true);
$t=$id.':prepare:member';$owner=startTask($j,$t);$payload=['outcome'=>'success','review'=>$review];
$j->workerReport(['taskId'=>$t,'token'=>$owner,'generation'=>'fixture','sequence'=>1,'type'=>'result','payload'=>$payload],'fixture',time());$j->result($t,$owner,$payload,1,time(),true);
$t=$id.':prepare:finish';$owner=startTask($j,$t);$j->result($t,$owner,['outcome'=>'success'],1,time(),true);
check(in_array($id,$j->deletionReferenceOwners('tank/source@A','400'),true),'Completed review lost reference lease');
$status=zfsas_recovery_status($j,$id);check($status['eligible']===1&&!str_contains(json_encode($status),'resumeHash'),'Public review leaked internal approval evidence');
reject(fn()=>zfsas_recovery_execute($j,$id,array_replace($config,['revision'=>str_repeat('b',64)])));
$j->state['runs'][$id]['finishedAt']=time()-301;reject(fn()=>zfsas_recovery_execute($j,$id,$config));$j->state['runs'][$id]['finishedAt']=time();
$execution=zfsas_recovery_execute($j,$id,$config);check(zfsas_recovery_execute($j,$id,$config)===$execution,'Duplicate approval created another run');
$run=$j->state['runs'][$execution['runId']];
check(!array_filter($run['tasks'],fn($tid)=>in_array($j->state['tasks'][$tid]['kind'],['auto','delete'],true)),'Recovery authorized snapshot creation or cleanup');
foreach($run['tasks'] as $tid){$o=startTask($j,$tid);$j->result($tid,$o,['outcome'=>'success'],1,time(),true);}
$j->resolveReplicationRecovery($run['id']);
check(!$j->runRequiresReview($original),'Verified interrupted transfer did not resolve its blocker');
check($j->state['runs'][$original]['state']==='failed','Recovery rewrote original failure history');
check(!isset($j->state['runs'][$run['id']]['sourceCleanupPending']),'Recovery granted source cleanup');
$changed=$job;$changed['destination']='backup/other';$changedConfig=$config;$changedConfig['send']['SEND_JOBS']=zfsas_send_render_jobs_string([$changed]);
reject(fn()=>zfsas_recovery_begin($j,['commandId'=>'changed-old','runId'=>$original],$changedConfig));
$fresh=zfsas_recovery_begin($j,['commandId'=>'changed-new','scheduleId'=>$job['id']],$changedConfig);
check($j->state['tasks'][$fresh['runId'].':prepare']['parameters']['members']===[],'Changed configuration adopted stale membership');
echo "PASS: original snapshot recovery, identity validation, foreign/missing evidence rejection, reference leases, expiration, duplicate approval and verified partial history resolution\n";
