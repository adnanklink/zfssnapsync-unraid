<?php
if(!is_file('/.dockerenv'))exit(77);
require __DIR__.'/source_retention_fixture.php';
$plugin=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php');
require $plugin.'/coordinator-state.php';require $plugin.'/coordinator-socket.php';require $plugin.'/send-helpers.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function rpc($request){$reply=zfsas_coordinator_request($request);check($reply['ok'],json_encode($reply));return $reply['result'];}
function until($fn){$end=microtime(true)+90;do{if($value=$fn())return $value;usleep(30000);}while(microtime(true)<$end);throw new RuntimeException('Daemon timeout: '.@file_get_contents('/tmp/source-daemon.log'));}
source_fixture_zfs(105);
exec('/bin/mount -t tmpfs -o size=8m tmpfs /boot',$output,$code);check($code===0,'Cannot isolate boot');
$dir='/boot/config/plugins/zfs.snapsync';mkdir($dir,0770,true);
$job=['id'=>'abcdef123456','source'=>'tank/data','destination'=>'backup/data','children'=>'0','transport'=>'local','frequency'=>'1d','threshold'=>'0G'];
$policy=['keep'=>3,'binding'=>zfsas_source_binding($job),'datasets'=>['tank/data'=>'10']];
$send=zfsas_send_defaults();$send['SEND_JOBS']=zfsas_send_render_jobs_string([$job]);$send['SEND_SOURCE_RETENTION']=json_encode(['version'=>1,'jobs'=>[$job['id']=>$policy]]);
file_put_contents($dir.'/zfs_snapsync.conf',"DATASETS=\"\"\nPREFIX=\"snapsync-auto-\"\n");
file_put_contents($dir.'/zfs_send.conf',zfsas_send_render_config($send)."\nSEND_SCHEDULE_SPECS='".json_encode([$job['id']=>['version'=>1,'kind'=>'interval','seconds'=>21600,'anchor'=>time()]])."'\n");
$revision=zfsas_config_revision($dir);$journal=new ZfsasCoordinatorState('/tmp/zfs-snapsync-coordinator');
$ref=['role'=>'source','endpoint'=>'local','dataset'=>'tank/data','datasetGuid'=>'10','snapshot'=>'tank/data@s105','guid'=>'205'];
$r=$journal->submit('verified-replication',['revision'=>$revision,'tasks'=>[
 'prepare'=>['kind'=>'prepare','parameters'=>['phase'=>'replication_schedule','nativeSchedule'=>true,'sourcePolicy'=>$policy,'job'=>$job]],
 'snapshot'=>['kind'=>'auto','parameters'=>['phase'=>'replication_snapshot','nativeSchedule'=>true,'source'=>'tank/data','sourceDatasetGuid'=>'10','destination'=>'backup/data','snapshotName'=>'s105']],
 'verify'=>['kind'=>'finalize']]],time())['runId'];
foreach(['prepare','snapshot','verify'] as $name){$task=$r.':'.$name;$token=$journal->claim($task,1,time(),'fixture');$journal->started($task,$token,123,'456');$result=['outcome'=>'success']+($name==='snapshot'?['reference'=>$ref]:[]);$journal->workerReport(['taskId'=>$task,'token'=>$token,'generation'=>'fixture','sequence'=>1,'type'=>'result','payload'=>$result],'fixture',time());$journal->result($task,$token,$result,1,time(),true);}
// A failed transfer retains recovery protection even though its worker stopped.
$ref['snapshot']='tank/data@s1';$ref['guid']='101';$owner=$journal->submit('recovery-reference',['manual'=>true,'tasks'=>['send'=>['kind'=>'send','references'=>[$ref]]]],time())['runId'];
$token=$journal->claim($owner.':send',1,time());$journal->result($owner.':send',$token,['outcome'=>'validation_failure','recoveryRequired'=>true],1,time(),true);
unset($journal);
@mkdir('/var/local/emhttp',0770,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STARTED"');
exec('/bin/mount -o remount,ro /boot',$output,$code);check($code===0,'Cannot make flash read-only');
$command=[PHP_BINARY,$plugin.'/coordinator-daemon.php'];
if(getenv('ZFSAS_TRACE')==='1')$command=array_merge(['/trace-tools/ld-linux-x86-64.so.2','--library-path','/trace-tools','/trace-tools/strace','-D','-f','-yy','-s','512','-e','trace=%file,write,pwrite64,ftruncate,fchmod,fchown,fsync,fdatasync','-o','/trace-output/source-files.log'],$command);
$daemon=proc_open($command,[1=>['file','/tmp/source-daemon.log','a'],2=>['file','/tmp/source-daemon.log','a']],$pipes);
try{
 until(function(){try{return rpc(['action'=>'status']);}catch(Throwable $e){return false;}});
 $parent=until(function()use($r){foreach(rpc(['action'=>'status'])['runs'] as $run)if($run['id']===$r&&!empty($run['sourceCleanupRunId']))return $run;return false;});
 $cleanup=until(function()use($parent){foreach(rpc(['action'=>'status'])['runs'] as $run)if($run['id']===$parent['sourceCleanupRunId']&&ZfsasCoordinatorState::terminal($run['state']))return $run;return false;});
 check($cleanup['state']==='complete',json_encode($cleanup));
 $state=json_decode(file_get_contents('/tmp/source-zfs.json'),true);check(count($state['deleted'])===101,'Wrong delete count: '.json_encode($cleanup));
 check(isset($state['datasets']['tank/data']['snapshots']['s1'],$state['datasets']['tank/data']['snapshots']['s103'],$state['datasets']['tank/data']['snapshots']['s104'],$state['datasets']['tank/data']['snapshots']['s105'],$state['datasets']['tank/data']['snapshots']['foreign']),'Protected snapshots were lost');
 check($cleanup['sourceCleanup']['deleted']===101&&$cleanup['sourceCleanup']['skipped']===1,'Live cleanup accounting lost results');
 for($i=0;$i<20;$i++)rpc(['action'=>'status']);
 check(count(json_decode(file_get_contents('/tmp/source-zfs.json'),true)['deleted'])===101,'Idle polling repeated deletions');
 echo "PASS: actual daemon, native source workers, 50-item chunks, protected recovery reference, per-item accounting, read-only flash and idle polling\n";
}finally{proc_terminate($daemon,15);proc_close($daemon);exec('/bin/umount /boot',$output,$code);check($code===0,'Cannot release flash mount');}
