<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/retirement-policy.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function reject($fn){try{$fn();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new RuntimeException('Unsafe retirement accepted');}
$job=['id'=>'aaaaaaaaaaaa','source'=>'tank/data','destination'=>'backup/data','frequency'=>'1d','threshold'=>'0G','children'=>'0','transport'=>'local'];
$pair=['auto'=>zfsas_auto_defaults()+[],'send'=>zfsas_send_defaults(),'revision'=>'fixture'];
$pair['auto']['DATASETS']='tank/data:10G,tank/other:20G';$pair['send']['SEND_JOBS']=zfsas_send_render_jobs_string([$job]);
$p=zfsas_retirement_inspect($pair,'tank/data');check($p['auto']&&count($p['jobs'])===1&&count($p['targets'])===2&&!$p['conflicts'],'Exact retirement scope wrong');
$pair['send']['SEND_JOBS']=zfsas_send_render_jobs_string([array_replace($job,['children'=>'1'])]);check(zfsas_retirement_inspect($pair,'tank/data')['conflicts'],'Recursive job silently retired children');
check(zfsas_retirement_inspect($pair,'tank/data/child')['conflicts'],'Ancestor job bypassed');
$pair['send']['SEND_JOBS']=zfsas_send_render_jobs_string([$job,array_replace($job,['id'=>'bbbbbbbbbbbb','source'=>'tank/other'])]);check(zfsas_retirement_inspect($pair,'tank/data')['conflicts'],'Shared receiver bypassed');
$pair['send']['SEND_JOBS']=zfsas_send_render_jobs_string([$job]);
$dir='/tmp/retirement-policy-'.bin2hex(random_bytes(6));mkdir($dir);
$rawAuto="# preserved\nDATASETS=\"tank/data:10G,tank/other:20G\"\nSCHEDULE_SPEC='unchanged'\nPREFIX=\"auto-\"\n";
$rawSend="SEND_JOBS=".zfsas_send_quote_config_string($pair['send']['SEND_JOBS'])."\nSEND_SSH_HOST=\"preserved\"\nSEND_SCHEDULE_SPECS=\"unchanged\"\n";
file_put_contents($dir.'/zfs_snapsync.conf',$rawAuto);file_put_contents($dir.'/zfs_send.conf',$rawSend);
$revision=zfsas_config_revision($dir);reject(fn()=>zfsas_retirement_stop($dir,'tank/data','stale','/bin/true'));
check(file_get_contents($dir.'/zfs_snapsync.conf')===$rawAuto,'Stale revision wrote configuration');
$result=zfsas_retirement_stop($dir,'tank/data',$revision,'/bin/false');check($result['saved']&&!$result['schedulerApplied'],'Saved/runtime distinction lost');
check(str_contains(file_get_contents($dir.'/zfs_snapsync.conf'),'tank/other:20G')&&!str_contains(file_get_contents($dir.'/zfs_snapsync.conf'),'tank/data:10G'),'Wrong Auto membership removed');
check(str_contains(file_get_contents($dir.'/zfs_send.conf'),'SEND_SCHEDULE_SPECS="unchanged"')&&str_contains(file_get_contents($dir.'/zfs_send.conf'),'SEND_JOBS=""'),'Unrelated settings changed');
$lines="tank/data@send-a\t10\t1\t100\t0\t-\ntank/data@manual\t11\t2\t101\t0\t-\ntank/data@send-held\t12\t3\t102\t1\t-\n";
$read=static function($args)use(&$lines){return $args[0]==='list'?$lines:(in_array('receive_resume_token',$args,true)?'-':'123');};
$r=zfsas_retirement_inventory(['dataset'=>'tank/data','endpoint'=>'local'],$read,['send-']);$rows=array_values($r['rows']);check($rows[0]['selected']&&!$rows[1]['selected']&&!$rows[2]['selected'],'Default selection bypassed scope or holds');
check($rows[2]['reason']!=='','Held exclusion hidden');$lines="tank/data/child@send-a\t10\t1\t100\t0\t-\n";reject(fn()=>zfsas_retirement_inventory(['dataset'=>'tank/data'],$read,['send-']));
echo "PASS: exact retirement scope, recursive/shared conflicts, stale revision, preserved settings, scheduler failure and bounded identity selection\n";

$lines='';for($i=0;$i<10000;$i++)$lines.="tank/data@send-$i\t".(100+$i)."\t".(1+$i)."\t100\t0\t-\n";
$r=zfsas_retirement_inventory(['dataset'=>'tank/data','datasetGuid'=>'999'],$read,['send-']);check(count($r['rows'])===10000,'Large inventory truncated');check($r['target']['datasetGuid']==='123','Captured GUID masked a changed live identity');
echo "PASS: 10,000 retirement snapshot identities and fresh dataset GUID replacement\n";
