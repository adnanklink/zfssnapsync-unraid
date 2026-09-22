<?php
if(!is_file('/.dockerenv')||getenv('ZFSAS_DISPOSABLE_POOL_TEST')!=='1'||count($argv)!==4)exit(77);
$plugin=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php');
require $plugin.'/coordinator-socket.php';require $plugin.'/send-helpers.php';require $plugin.'/replication-inspection.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function z($a){return ZfsasReplicationInspection::command($a);}
function rpc($r){$v=zfsas_coordinator_request($r);check($v['ok'],json_encode($v));return $v['result'];}
function until($f,$seconds=100){$end=microtime(true)+$seconds;do{if($v=$f())return $v;usleep(50000);}while(microtime(true)<$end);throw new RuntimeException('Recovery timeout: '.@file_get_contents('/tmp/recovery-daemon.log'));}
function pipeline($source,$dest,$base=null,$interrupt=false){
 $cmd='set -o pipefail; zfs send '.($base?'-i '.escapeshellarg($base).' ':'').escapeshellarg($source).' | '.($interrupt?'head -c 1048576 | ':'').'zfs receive -s -u '.escapeshellarg($dest);
 $p=proc_open(['/bin/bash','-c',$cmd],[1=>['file','/dev/null','w'],2=>['pipe','w']],$pipes);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($p);check($interrupt?$code!==0:$code===0,$err);
}
$source=$argv[1].'/data';$dest=$argv[2].'/data';$fixture=$argv[3];$schedule='abcdef123456';
z(['create','-o','mountpoint='.$fixture.'/data',$source]);z(['create',$source.'/child']);
file_put_contents($fixture.'/data/base','base');file_put_contents($fixture.'/data/child/base','child');z(['snapshot','-r',$source.'@base']);
pipeline($source.'@base',$dest);pipeline($source.'/child@base',$dest.'/child');
file_put_contents($fixture.'/data/payload',random_bytes(32*1048576));file_put_contents($fixture.'/data/child/next','child already complete');
z(['snapshot','-r',$source.'@A']);pipeline($source.'/child@A',$dest.'/child',$source.'/child@base');pipeline($source.'@A',$dest,$source.'@base',true);
z(['snapshot',$source.'@B']);z(['snapshot',$argv[2].'@unrelated']);
check(trim(z(['get','-H','-o','value','receive_resume_token',$dest]))!=='-','Interrupted fixture lacks resume token');
exec('/bin/mount -t tmpfs -o size=8m tmpfs /boot',$output,$code);check($code===0,'Cannot isolate flash');
$config='/boot/config/plugins/zfs.snapsync';mkdir($config,0770,true);
$job=['id'=>$schedule,'source'=>$source,'destination'=>$dest,'frequency'=>'1d','threshold'=>'0G','children'=>'1','transport'=>'local'];
$send=zfsas_send_defaults();$send['SEND_JOBS']=zfsas_send_render_jobs_string([$job]);$send['SEND_RATE_LIMIT']='0';
file_put_contents($config.'/zfs_snapsync.conf',"DATASETS=''\nPREFIX=snapsync-auto-\n");
file_put_contents($config.'/zfs_send.conf',zfsas_send_render_config($send)."\nSEND_SCHEDULE_SPECS='".json_encode([$schedule=>['version'=>1,'kind'=>'interval','seconds'=>21600,'anchor'=>time()]])."'\n");
@mkdir('/var/local/emhttp',0770,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STARTED"');
exec('/bin/mount -o remount,ro /boot',$output,$code);check($code===0,'Cannot protect flash');
$runner=tempnam('/tmp','recovery-endpoint-');file_put_contents($runner,'<?php $GLOBALS["csrf_token"]="fixture";$_SERVER["REQUEST_METHOD"]=$argv[2];$_POST=json_decode(base64_decode($argv[3]),true);$_GET=$_POST;require $argv[1];');
function endpoint($name,$method,$body){global $runner,$plugin;$proc=proc_open([PHP_BINARY,$runner,$plugin.'/'.$name,$method,base64_encode(json_encode($body))],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);proc_close($proc);check($err===''&&(bool)preg_match('/ZFSAS_JSON_BEGIN\s*(.*?)\s*ZFSAS_JSON_END/s',$out,$m),$out.$err);return json_decode($m[1],true);}
$command=[PHP_BINARY,$plugin.'/coordinator-daemon.php'];
if(getenv('ZFSAS_TRACE')==='1')$command=array_merge(['/trace-tools/ld-linux-x86-64.so.2','--library-path','/trace-tools','/trace-tools/strace','-D','-f','-yy','-s','512','-e','trace=%file,write,pwrite64,ftruncate,fchmod,fchown,fsync,fdatasync','-o','/trace-output/recovery-files.log'],$command);
$start=function()use($command){$p=proc_open($command,[1=>['file','/tmp/recovery-daemon.log','a'],2=>['file','/tmp/recovery-daemon.log','a']],$pipes);until(function(){try{return rpc(['action'=>'status']);}catch(Throwable $e){return false;}},10);return $p;};
$daemon=$start();
try{
 $before=z(['list','-H','-o','name','-t','snapshot','-r',$source]);
 $receipt=rpc(['action'=>'replication_now','commandId'=>'manual-send-A']);$failedId=$receipt['runs'][$schedule]['runId'];
 $failed=until(function()use($failedId){foreach(rpc(['action'=>'status'])['runs'] as $r)if($r['id']===$failedId&&$r['state']==='failed')return $r;return false;});
 check($failed['canReviewRecovery']&&$failed['problem']['code']==='interrupted_receive',json_encode($failed));
 check(z(['list','-H','-o','name','-t','snapshot','-r',$source])===$before,'Run Now created snapshots behind interrupted receiver');
 $again=endpoint('run-send-now.php','POST',['csrf_token'=>'fixture','command_id'=>'manual-send-B']);check($again['ok']&&$again['recoveryRequired'][0]['runId']===$failedId,'Repeated Run Now did not route to recovery');
 $post=['action'=>'review_recovery','run_id'=>$failedId,'command_id'=>'review-real'];
 check(!endpoint('coordinator-action.php','POST',$post)['ok'],'Missing CSRF accepted');check(!endpoint('coordinator-action.php','GET',$post)['ok'],'GET created review');$post['csrf_token']='fixture';
 $review=endpoint('coordinator-action.php','POST',$post);check($review['ok'],json_encode($review));$reviewId=$review['runId'];
 $ready=until(function()use($reviewId){$r=endpoint('replication-recovery-status.php','GET',['review_id'=>$reviewId]);return ($r['state'] ?? '')==='complete'?$r:false;});
 check($ready['eligible']===1&&$ready['blocked']===1,json_encode($ready));check($ready['rows'][0]['snapshot']===$source.'@A','Review selected newer B instead of original A');
 check(endpoint('coordinator-action.php','POST',$post)['runId']===$reviewId,'Duplicate review submission changed run');
 proc_terminate($daemon,15);proc_close($daemon);$daemon=$start();
 $execute=['csrf_token'=>'fixture','action'=>'retry_reviewed','review_id'=>$reviewId];$recovery=endpoint('coordinator-action.php','POST',$execute);check($recovery['ok'],json_encode($recovery));
 check(endpoint('coordinator-action.php','POST',$execute)['runId']===$recovery['runId'],'Duplicate approval sent another run');
 $done=until(function()use($recovery){foreach(rpc(['action'=>'status'])['runs'] as $r)if($r['id']===$recovery['runId']&&ZfsasCoordinatorState::terminal($r['state']))return $r;return false;});check($done['state']==='complete',json_encode($done));
 check(trim(z(['get','-H','-o','value','receive_resume_token',$dest]))==='-','Resume did not finish');
 check(trim(z(['get','-H','-p','-o','value','guid',$source.'@A']))===trim(z(['get','-H','-p','-o','value','guid',$dest.'@A'])),'Recovered snapshot identity differs');
 check(z(['list','-H','-o','name','-t','snapshot','-r',$source])===$before,'Recovery created new snapshots');
 check(trim(z(['get','-H','-p','-o','value','guid',$source.'/child@A']))===trim(z(['get','-H','-p','-o','value','guid',$dest.'/child@A'])),'Completed child was changed');
 $seq=rpc(['action'=>'status'])['sequence'];
 for($i=0;$i<10;$i++){check(endpoint('operation-detail.php','GET',['operation_id'=>'coordinator:'.$recovery['runId']])['ok'],'Job log endpoint failed');endpoint('replication-recovery-status.php','GET',['review_id'=>$reviewId]);}
 check(rpc(['action'=>'status'])['sequence']===$seq,'Read-only diagnostics changed coordinator state');
 check(!endpoint('operation-detail.php','GET',['operation_id'=>'../../boot/config/secrets'])['ok'],'Path traversal accepted');
 echo "PASS: real incremental interruption, Run Now preflight, original A recovery, partial recursive review, restart, idempotent endpoint approval, preserved child/unrelated snapshots and read-only diagnostics\n";
 // Full interrupted receive with no retained run history: explicit configuration review only.
 $fullSource=$argv[1].'/full';$fullDest=$argv[2].'/full';
 z(['create','-o','mountpoint='.$fixture.'/full',$fullSource]);file_put_contents($fixture.'/full/data',random_bytes(16*1048576));z(['snapshot',$fullSource.'@original']);pipeline($fullSource.'@original',$fullDest,null,true);
 $fullJob=$job;$fullJob['id']='123456abcdef';$fullJob['source']=$fullSource;$fullJob['destination']=$fullDest;$fullJob['children']='0';
 exec('/bin/mount -o remount,rw /boot',$output,$code);check($code===0,'Cannot update fixture configuration');
 $send['SEND_JOBS']=zfsas_send_render_jobs_string([$job,$fullJob]);
 $specs=[];foreach([$schedule,$fullJob['id']] as $sid)$specs[$sid]=['version'=>1,'kind'=>'interval','seconds'=>21600,'anchor'=>time()];
 file_put_contents($config.'/zfs_send.conf',zfsas_send_render_config($send)."\nSEND_SCHEDULE_SPECS='".json_encode($specs)."'\n");
 exec('/bin/mount -o remount,ro /boot',$output,$code);check($code===0,'Cannot protect updated fixture');
 $fullReview=endpoint('coordinator-action.php','POST',['csrf_token'=>'fixture','action'=>'review_recovery','schedule_id'=>$fullJob['id'],'command_id'=>'full-review']);check($fullReview['ok'],json_encode($fullReview));
 $fullReady=until(function()use($fullReview){$r=endpoint('replication-recovery-status.php','GET',['review_id'=>$fullReview['runId']]);return ($r['state'] ?? '')==='complete'?$r:false;});check($fullReady['eligible']===1,json_encode($fullReady));
 $fullExecute=endpoint('coordinator-action.php','POST',['csrf_token'=>'fixture','action'=>'retry_reviewed','review_id'=>$fullReview['runId']]);check($fullExecute['ok'],json_encode($fullExecute));
 $fullDone=until(function()use($fullExecute){foreach(rpc(['action'=>'status'])['runs'] as $r)if($r['id']===$fullExecute['runId']&&ZfsasCoordinatorState::terminal($r['state']))return $r;return false;});check($fullDone['state']==='complete',json_encode($fullDone));
 check(trim(z(['get','-H','-p','-o','value','guid',$fullSource.'@original']))===trim(z(['get','-H','-p','-o','value','guid',$fullDest.'@original'])),'Full recovery identity differs');
 echo "PASS: full interrupted receive recovered only after fresh explicit review without historical execution authority\n";
 // An actual quota failure must retain ZFS stderr, even when a later retry reports only an interrupted receive.
 // Delay only the real send's launch to inject a quota change after measured space approval.
 $realZfs=trim((string)shell_exec('command -v zfs'));
 file_put_contents('/usr/local/bin/zfs', "#!/bin/bash\nif [[ \"\$1\" == send && \"\$2\" == -vP ]]; then sleep 2; fi\nexec ".escapeshellarg($realZfs)." \"\$@\"\n");chmod('/usr/local/bin/zfs',0755);
 file_put_contents($fixture.'/data/more',random_bytes(32*1048576));
 $next=rpc(['action'=>'replication_now','commandId'=>'manual-send-diagnostics']);$failureId=$next['runs'][$schedule]['runId'];
 until(function()use($failureId){foreach(rpc(['action'=>'status'])['runs'] as $r)if($r['id']===$failureId)foreach($r['taskStatus'] as $t)if(($t['progress']['phase'] ?? '')==='transfer')return true;return false;});
 $used=(int)trim(z(['get','-H','-p','-o','value','used',$dest]));z(['set','quota='.($used+4*1048576),$dest]);
 $diagnostics=until(function()use($failureId){$d=endpoint('operation-detail.php','GET',['operation_id'=>'coordinator:'.$failureId]);foreach($d['entries'] ?? [] as $e)if(!empty($e['diagnostic']))return $d;return false;});
 check(preg_match('/space|quota/i',json_encode($diagnostics)),'ZFS quota cause was discarded');
 $failed=until(function()use($failureId){foreach(rpc(['action'=>'status'])['runs'] as $r)if($r['id']===$failureId&&$r['state']==='failed')return $r;return false;},100);
 $diagnostics=endpoint('operation-detail.php','GET',['operation_id'=>'coordinator:'.$failureId]);check(preg_match('/space|quota/i',json_encode($diagnostics)),'Later interruption overwrote original quota error');
 check(trim(z(['get','-H','-p','-o','value','guid',$argv[2].'@unrelated']))!=='','Unrelated snapshot removed');
 echo "PASS: actual receive quota error retained across later interrupted-receive failure, bounded job log, and read-only boot flash\n";
}finally{proc_terminate($daemon,15);proc_close($daemon);unlink($runner);exec('/bin/umount /boot',$output,$code);check($code===0,'Cannot release flash fixture');}
