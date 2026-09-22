<?php
if(!is_file('/.dockerenv'))exit(77);
require __DIR__.'/source_retention_fixture.php';
$base=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php');
require $base.'/send-helpers.php';require $base.'/coordinator-client.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
source_fixture_zfs();$dir='/boot/config/plugins/zfs.snapsync';@mkdir($dir,0770,true);
$job=['id'=>'abcdef123456','source'=>'tank/data','destination'=>'backup/data','frequency'=>'1d','children'=>'0','transport'=>'local','threshold'=>'0G'];
$send=zfsas_send_defaults();$send['SEND_JOBS']=zfsas_send_render_jobs_string([$job]);
file_put_contents($dir.'/zfs_snapsync.conf',"DATASETS=\"\"\nPREFIX=\"snapsync-auto-\"\n");
file_put_contents($dir.'/zfs_send.conf',zfsas_send_render_config($send)."\nSEND_SCHEDULE_SPECS='".json_encode([$job['id']=>['version'=>1,'kind'=>'interval','seconds'=>21600,'anchor'=>time()]])."'\n");
@mkdir('/var/local/emhttp',0770,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STARTED"');
$runner=tempnam('/tmp','source-endpoint-');file_put_contents($runner,'<?php $GLOBALS["csrf_token"]="fixture";$_SERVER["REQUEST_METHOD"]=$argv[2];$_POST=json_decode(base64_decode($argv[3]),true);$_GET=json_decode(base64_decode($argv[4]),true);require $argv[1];');
function endpoint($name,$method,$post=[],$get=[]){global $runner,$base;$proc=proc_open([PHP_BINARY,$runner,$base.'/'.$name,$method,base64_encode(json_encode($post)),base64_encode(json_encode($get))],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);proc_close($proc);check((bool)preg_match('/ZFSAS_JSON_BEGIN\s*(.*?)\s*ZFSAS_JSON_END/s',$out,$m),$out.$err);return json_decode($m[1],true);}
$post=['csrf_token'=>'fixture','ajax'=>'save','config_revision'=>zfsas_config_revision($dir)];foreach($job as $key=>$value)$post['job_'.$key]=[$value];
$daemon=proc_open([PHP_BINARY,$base.'/coordinator-daemon.php'],[1=>['file','/tmp/source-endpoints.log','a'],2=>['file','/tmp/source-endpoints.log','a']],$pipes);
try{
 zfsas_coordinator_ensure();
 check(!endpoint('source-retention-preview.php','POST',[])['ok'],'Review accepted missing CSRF');
 $bad=endpoint('save-send-settings.php','POST',$post+['job_source_keep'=>['3']]);check(empty($bad['saved']),'Existing source cleanup enabled without review');
 $before=file_get_contents($dir.'/zfs_send.conf');
 $start=endpoint('source-retention-preview.php','POST',$post+['keep'=>'3']);check($start['ok'],json_encode($start));
 $query=['token'=>$start['token'],'run_id'=>$start['runId']];$ready=null;
 for($i=0;$i<200;$i++){$response=endpoint('source-retention-preview.php','GET',[],$query);check($response['ok'],json_encode($response));if($response['state']==='ready'){$ready=$response;break;}usleep(30000);}
 check($ready&&$ready['eligible']===4&&$ready['protected']===3&&$ready['unmanaged']===1,'Incorrect review: '.json_encode($ready));
 $reviewPath=zfsas_source_review_path($start['token']);check((fileperms(dirname($reviewPath))&0777)===0770 && (fileperms($reviewPath)&0777)===0660,'Web process cannot read RAM review');
 check(file_get_contents($dir.'/zfs_send.conf')===$before,'Review or polling changed flash configuration');
 check(!json_decode(file_get_contents('/tmp/source-zfs.json'),true)['deleted'],'Review executed cleanup');
 $saved=endpoint('save-send-settings.php','POST',$post+['job_source_keep'=>['3'],'job_source_review'=>[$start['token']]]);check(!empty($saved['saved']),json_encode($saved));
 $actual=zfsas_send_parse_config_file($dir.'/zfs_send.conf',zfsas_send_defaults());check(zfsas_source_policy($actual,$job)['keep']===3,'Actual save lost source policy');
 $post['config_revision']=$saved['revision'];
 $bad=endpoint('save-send-settings.php','POST',$post+['job_source_keep'=>['2']]);check(empty($bad['saved']),'Lower count bypassed review');
 $unchanged=endpoint('save-send-settings.php','POST',$post);check(!empty($unchanged['saved']),json_encode($unchanged));
 $actual=zfsas_send_parse_config_file($dir.'/zfs_send.conf',zfsas_send_defaults());check(zfsas_source_policy($actual,$job)['keep']===3,'Older form discarded source policy');
 $post['config_revision']=$unchanged['revision'];$disabled=endpoint('save-send-settings.php','POST',$post+['job_source_keep'=>['0']]);check(!empty($disabled['saved']),json_encode($disabled));
 check(zfsas_source_policy(zfsas_send_parse_config_file($dir.'/zfs_send.conf',zfsas_send_defaults()),$job)['keep']===0,'Disabling source retention failed');
 // A new job can authorize future checkpoints without an existing-backlog review.
 $post['job_id']=['123456abcdef'];$post['config_revision']=$disabled['revision'];
 $new=endpoint('save-send-settings.php','POST',$post+['job_source_keep'=>['3']]);check(!empty($new['saved']),json_encode($new));
 $newJob=$job;$newJob['id']='123456abcdef';
 check(zfsas_source_policy(zfsas_send_parse_config_file($dir.'/zfs_send.conf',zfsas_send_defaults()),$newJob)['keep']===3,'New local job lost default source retention');
 echo "PASS: actual asynchronous review/status/save endpoints, CSRF, no pre-save deletion, explicit policy authorization, reduced-count rejection, omitted fields and disabling\n";
}finally{proc_terminate($daemon,15);proc_close($daemon);unlink($runner);}
