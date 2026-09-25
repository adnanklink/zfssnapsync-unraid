<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/response-helpers.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/send-helpers.php';
$dir=sys_get_temp_dir().'/scoped-send-'.bin2hex(random_bytes(6));mkdir($dir);
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function save_scope($post,$sync='/bin/true') {global $dir;return zfsas_send_handle_save_request($post+['config_revision'=>zfsas_config_revision($dir)],$dir,$dir.'/zfs_send.conf',$sync,[], '/');}
function job_post($source,$destination) {return ['job_id'=>[''],'job_source'=>[$source],'job_destination'=>[$destination],'job_frequency'=>['1d'],'job_time'=>['23:17'],'job_threshold'=>['100G'],'job_transport'=>['local'],'job_children'=>['0'],'job_source_keep'=>['0']];}
try {
$a=save_scope(['scope'=>'job_create','send_max_parallel'=>'8']+job_post('tank/a','backup/a'));
check($a['saved'],'Create failed: '.json_encode($a));$id=$a['formJobs'][0]['id'];
check($a['config']['SEND_MAX_PARALLEL']===zfsas_send_defaults()['SEND_MAX_PARALLEL'],'Job persisted unrelated shared draft');
$revision=$a['revision'];$spec=json_decode($a['config']['SEND_SCHEDULE_SPECS'],true)[$id];
$b=save_scope(['scope'=>'job_create']+job_post('tank/b','backup/b'));check($b['saved'],'Second create failed');
$stale=save_scope(['scope'=>'job_update','config_revision'=>$revision,'job_id'=>[$id]]+job_post('tank/a','backup/changed'));check(!$stale['saved'],'Stale revision saved');
$duplicate=save_scope(['scope'=>'job_create']+job_post('tank/a','backup/a'));check(!$duplicate['saved'],'Duplicate create accepted');
$shared=save_scope(['scope'=>'shared','send_max_parallel'=>'4']+job_post('tank/evil','backup/evil'));
check($shared['saved']&&count($shared['formJobs'])===2,'Shared save changed jobs');check($shared['config']['SEND_MAX_PARALLEL']==='4','Shared setting not saved');
check(json_decode($shared['config']['SEND_SCHEDULE_SPECS'],true)[$id]===$spec,'Shared save changed schedule');
$update=save_scope(['scope'=>'job_update','job_id'=>[$id],'send_max_parallel'=>'7']+job_post('tank/a','backup/changed'));
check($update['saved']&&count($update['formJobs'])===2,'Update lost other job');check($update['formJobs'][0]['id']===$id,'Update reordered jobs');check($update['config']['SEND_MAX_PARALLEL']==='4','Update changed shared setting');
check(count(array_filter($update['formJobs'],fn($j)=>$j['id']===$id&&$j['destination']==='backup/changed'))===1,'Update changed identity');
$failed=save_scope(['scope'=>'job_update','job_id'=>[$id]]+job_post('bad@dataset','backup/changed'));check(!$failed['saved'],'Invalid job saved');
check(zfsas_config_revision($dir)===$update['revision'],'Failed save mutated config');
$runtime=save_scope(['scope'=>'shared','send_max_parallel'=>'5'],'/bin/false');check($runtime['saved']&&!$runtime['schedulerApplied'],'Runtime failure lost saved result');
$removed=save_scope(['scope'=>'job_remove','job_id'=>[$id]]);check($removed['saved']&&count($removed['formJobs'])===1,'Remove did not isolate job');
check(!save_scope(['scope'=>'job_remove','job_id'=>[$id]])['saved'],'Repeated removal accepted');
check(!save_scope(['scope'=>'unknown'])['saved'],'Unknown scope accepted');
echo "PASS: scoped create/update/remove/shared isolation, identities, duplicate submissions, revisions, validation and scheduler failure\n";
} finally {foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
