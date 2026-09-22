<?php
if(!is_file('/.dockerenv'))exit(77);
$base=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php');
require $base.'/workspace-summary.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$runner=tempnam('/tmp','attention-endpoint-');file_put_contents($runner,'<?php $GLOBALS["csrf_token"]="fixture";$_SERVER["REQUEST_METHOD"]=$argv[2];$_POST=json_decode(base64_decode($argv[3]),true);require $argv[1];');
function endpoint($method,$post){global $base,$runner;$proc=proc_open([PHP_BINARY,$runner,$base.'/attention-action.php',$method,base64_encode(json_encode($post))],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);proc_close($proc);check((bool)preg_match('/ZFSAS_JSON_BEGIN\s*(.*?)\s*ZFSAS_JSON_END/s',$out,$m),$out.$err);return json_decode($m[1],true);}
zfsas_ops_ensure_storage_dirs();
$path=zfsas_ops_jobs_dir().'/attention-fixture.job';$job=['JOB_ID'=>'attention-fixture','JOB_TYPE'=>'send','STATE'=>'failed','SOURCE_ROOT'=>'tank/data','DESTINATION_ROOT'=>'backup/data','LAST_ERROR'=>'Review recovery','RECOVERY_REQUIRED'=>'1','REQUESTED_AT'=>gmdate('c')];
zfsas_ops_write_job_file_unlocked($path,$job);$before=file_get_contents($path);
$find=static function(){foreach(zfsas_workspace_summary()['operations'] as $op)if($op['id']==='replication:attention-fixture')return $op;throw new RuntimeException('Missing fixture');};
$row=$find();$post=['csrf_token'=>'fixture','action'=>'dismiss_attention','operation_id'=>$row['id'],'attention_token'=>$row['attentionToken']];
check(!endpoint('GET',$post)['ok'],'GET mutated state');$bad=$post;unset($bad['csrf_token']);check(!endpoint('POST',$bad)['ok'],'Missing CSRF accepted');
$bad=$post;$bad['attention_token']=str_repeat('0',64);check(!endpoint('POST',$bad)['ok'],'Stale identity accepted');
check(endpoint('POST',$post)['ok'],'Dismiss endpoint failed');check(endpoint('POST',$post)['ok'],'Duplicate dismissal failed');
$row=$find();check($row['attentionDismissed']&&!$row['needsAttention']&&$row['recoveryRequired'],'Dismissed record lost recovery or remained visible');
check(file_get_contents($path)===$before,'Dismissal changed authoritative job');
check(!is_dir('/var/run/zfs-snapsync-coordinator'),'Dismissal started coordinator');
$post['action']='restore_attention';check(endpoint('POST',$post)['ok']&&$find()['needsAttention'],'Restore endpoint failed');
$post['action']='dismiss_attention';check(endpoint('POST',$post)['ok'],'Second dismissal failed');
$job['LAST_ERROR']='New receiver failure';zfsas_ops_write_job_file_unlocked($path,$job);check($find()['needsAttention'],'New failure remained hidden');
check(!endpoint('POST',$post)['ok'],'Old client dismissed new failure');
unlink($runner);echo "PASS: attention POST/CSRF/identity checks, duplicate dismissal, restore, unchanged recovery job and read-only coordinator status\n";
