<?php
if(!is_file('/.dockerenv')||getenv('ZFSAS_DISPOSABLE_POOL_TEST')!=='1'||count($argv)!==4)exit(77);
$plugin=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php');
require $plugin.'/coordinator-socket.php';require $plugin.'/send-helpers.php';require $plugin.'/replication-snapshot.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function z($args){return ZfsasReplicationInspection::command($args);}
function rpc($request){$response=zfsas_coordinator_request($request);check($response['ok'],json_encode($response));return $response['result'];}
function until($fn){$end=microtime(true)+90;do{if($value=$fn())return $value;usleep(50000);}while(microtime(true)<$end);throw new RuntimeException('Source cleanup timeout: '.@file_get_contents('/tmp/source-zfs-daemon.log'));}
function send($snapshot,$target,$base=null){$command='set -o pipefail; zfs send '.($base?'-i '.escapeshellarg($base).' ':'').escapeshellarg($snapshot).' | zfs receive -u '.escapeshellarg($target);$proc=proc_open(['/bin/bash','-c',$command],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$err=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);check(proc_close($proc)===0,$err);}
$source=$argv[1].'/data';$destination=$argv[2].'/data';$lagging=$argv[2].'/lagging';$id='123456abcdef';$prefix='snapsync-send-'.$id.'-';
z(['create','-o','mountpoint='.$argv[3].'/data',$source]);$sourceGuid=trim(z(['get','-H','-p','-o','value','guid',$source]));
file_put_contents($argv[3].'/data/payload','preserve this dataset content');
z(['snapshot',$source.'@foreign']);
for($i=1;$i<=5;$i++){
 zfsas_replication_snapshot(['source'=>$source,'sourceDatasetGuid'=>$sourceGuid,'snapshotName'=>$prefix.$i,'scheduleId'=>$id,'occurrence'=>(string)$i]);
 send($source.'@'.$prefix.$i,$destination,$i===1?null:$source.'@'.$prefix.($i-1));
 if($i===1){send($source.'@'.$prefix.$i,$lagging);zfsas_replication_snapshot(['source'=>$source,'sourceDatasetGuid'=>$sourceGuid,'snapshotName'=>$prefix.'failed','scheduleId'=>$id,'occurrence'=>'0']);}
}
z(['hold','fixture',$source.'@'.$prefix.'2']);z(['clone','-o','mountpoint=none',$source.'@'.$prefix.'3',$argv[1].'/clone']);
exec('/bin/mount -t tmpfs -o size=8m tmpfs /boot',$output,$code);check($code===0,'Cannot isolate boot fixture');
$config='/boot/config/plugins/zfs.snapsync';mkdir($config,0770,true);
$job=['id'=>$id,'source'=>$source,'destination'=>$destination,'frequency'=>'1d','threshold'=>'0G','children'=>'0','transport'=>'local'];
$slow=$job;$slow['id']='abcdef123456';$slow['destination']=$lagging;
$policy=['keep'=>3,'binding'=>zfsas_source_binding($job),'datasets'=>[$source=>$sourceGuid]];
$send=zfsas_send_defaults();$send['SEND_JOBS']=zfsas_send_render_jobs_string([$job,$slow]);$send['SEND_SOURCE_RETENTION']=json_encode(['version'=>1,'jobs'=>[$id=>$policy]]);
file_put_contents($config.'/zfs_snapsync.conf',"DATASETS=\"\"\nPREFIX=\"snapsync-auto-\"\n");
$specs=[];foreach([$id,$slow['id']] as $schedule)$specs[$schedule]=['version'=>1,'kind'=>'interval','seconds'=>21600,'anchor'=>time()];
file_put_contents($config.'/zfs_send.conf',zfsas_send_render_config($send)."\nSEND_SCHEDULE_SPECS='".json_encode($specs)."'\n");
@mkdir('/var/local/emhttp',0770,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STARTED"');
exec('/bin/mount -o remount,ro /boot',$output,$code);check($code===0,'Cannot protect flash fixture');
$command=[PHP_BINARY,$plugin.'/coordinator-daemon.php'];
if(getenv('ZFSAS_TRACE')==='1')$command=array_merge(['/usr/bin/strace','-D','-f','-yy','-s','512','-e','trace=%file','-o','/trace-output/source-files.log'],$command);
$daemon=proc_open($command,[1=>['file','/tmp/source-zfs-daemon.log','a'],2=>['file','/tmp/source-zfs-daemon.log','a']],$pipes);
try{
 until(function(){try{return rpc(['action'=>'status']);}catch(Throwable $e){return false;}});
 foreach([6,7,8] as $occurrence){
  $request=['action'=>'scheduled_replication','scheduleId'=>$id,'commandId'=>'source-zfs-'.$occurrence,'occurrence'=>$occurrence];$receipt=rpc($request);check(rpc($request)===$receipt,'Duplicate submission changed receipt');
  $run=until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $r)if($r['id']===$receipt['runId']&&ZfsasCoordinatorState::terminal($r['state'])&&!empty($r['sourceCleanupRunId']))return $r;return false;});
  check($run['state']==='complete',json_encode($run));
  $cleanup=until(function()use($run){foreach(rpc(['action'=>'status'])['runs'] as $r)if($r['id']===$run['sourceCleanupRunId']&&ZfsasCoordinatorState::terminal($r['state']))return $r;return false;});
  check($cleanup['state']==='complete',json_encode($cleanup));check($cleanup['sourceCleanup']['deferred']===0,'Unexpected cleanup deferral: '.json_encode($cleanup));
  $snapshots=z(['list','-H','-o','name','-t','snapshot','-d','1',$source]);
  check(str_contains($snapshots,'@foreign'),'Foreign snapshot deleted');check(!str_contains($snapshots,'@'.$prefix.'failed'),'Superseded failed source checkpoint retained');
  if($occurrence===6){
   foreach([1,2,3,4,5,6] as $i)check(str_contains($snapshots,'@'.$prefix.$i."\n"),'Protected snapshot lost: '.$i);
   send($source.'@'.$prefix.'6',$lagging,$source.'@'.$prefix.'1');z(['release','fixture',$source.'@'.$prefix.'2']);z(['destroy',$argv[1].'/clone']);
  }else{
   $owned=array_filter(explode("\n",trim($snapshots)),fn($name)=>str_contains($name,'@'.$prefix));check(count($owned)===3,'Expected latest three owned snapshots: '.$snapshots);
  }
  check(file_get_contents($argv[3].'/data/payload')==='preserve this dataset content','Source dataset data changed');
  check(trim(z(['get','-H','-p','-o','value','guid',$source.'@'.$prefix.$occurrence]))===trim(z(['get','-H','-p','-o','value','guid',$destination.'@'.$prefix.$occurrence])),'Incremental receive no longer matches source');
 }
 echo "PASS: real ZFS native success handoff, read-only flash, latest-three cleanup, lagging receiver, holds/clones, superseded failed checkpoint, foreign preservation and subsequent incremental transfer\n";
}finally{proc_terminate($daemon,15);proc_close($daemon);exec('/bin/umount /boot',$output,$code);check($code===0,'Cannot release flash fixture');}
