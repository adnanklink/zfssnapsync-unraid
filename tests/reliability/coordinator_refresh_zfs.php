<?php
if(!is_file('/.dockerenv')||getenv('ZFSAS_DISPOSABLE_POOL_TEST')!=='1'||count($argv)!==4)exit(77);
$sourcePlugin=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync');$plugin='/usr/local/emhttp/plugins/zfs.snapsync';
exec('mkdir -p '.escapeshellarg($plugin));exec('cp -a '.escapeshellarg($sourcePlugin).'/. '.escapeshellarg($plugin));
require_once $plugin.'/php/coordinator-lifecycle.php';require_once $plugin.'/php/send-helpers.php';require_once $plugin.'/php/snapshot-manager-helpers.php';require_once $plugin.'/php/replication-inspection.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function z($args){return ZfsasReplicationInspection::command($args);}
function rpc($request){$r=zfsas_coordinator_request($request);check($r['ok'],json_encode($r));return $r['result'];}
function until($fn){$end=microtime(true)+60;do{if($v=$fn())return $v;usleep(50000);}while(microtime(true)<$end);throw new RuntimeException('Refresh timeout: '.@file_get_contents('/var/log/zfs_snapsync_coordinator.log').' '.json_encode(rpc(['action'=>'status'])));}
$source=$argv[1].'/data';$destination=$argv[2].'/data';$fixture=$argv[3];
z(['create','-o','mountpoint='.$fixture.'/data',$source]);file_put_contents($fixture.'/data/payload',random_bytes(16*1048576));z(['snapshot',$source.'@original']);
$realZfs=trim(shell_exec('command -v zfs'));
file_put_contents('/tmp/refresh-throttle.py',"import sys,time\nwhile True:\n b=sys.stdin.buffer.read(65536)\n if not b: break\n sys.stdout.buffer.write(b);sys.stdout.buffer.flush();time.sleep(.03)\n");
file_put_contents('/usr/local/bin/zfs',"#!/bin/bash\nset -o pipefail\nif [[ \"\$1\" == send && \"\$2\" == -vP ]]; then\n echo transfer >> /tmp/refresh-zfs-sends\n ".escapeshellarg($realZfs)." \"\$@\" | python3 /tmp/refresh-throttle.py\nelse exec ".escapeshellarg($realZfs)." \"\$@\"; fi\n");chmod('/usr/local/bin/zfs',0755);
exec('mount -t tmpfs -o size=8m tmpfs /boot',$output,$code);check($code===0,'Cannot isolate flash');
$config='/boot/config/plugins/zfs.snapsync';mkdir($config,0770,true);
file_put_contents($config.'/zfs_snapsync.conf',"DATASETS=''\nPREFIX=snapsync-auto-\n");file_put_contents($config.'/zfs_send.conf',zfsas_send_render_config(zfsas_send_defaults()));
@mkdir('/var/local/emhttp',0770,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STARTED"');
exec('mount -o remount,ro /boot',$output,$code);check($code===0,'Cannot protect flash');file_put_contents('/tmp/refresh-flash-readonly','1');
try{
 zfsas_lifecycle('watchdog');
 $request=['action'=>'replication','commandId'=>'refresh-real-first','revision'=>zfsas_config_revision($config),'sourceDatasetGuid'=>trim(z(['get','-H','-p','-o','value','guid',$source])),
  'replication'=>['sourceSnapshot'=>$source.'@original','sourceGuid'=>trim(z(['get','-H','-p','-o','value','guid',$source.'@original'])),'destination'=>$destination,'destinationParentGuid'=>trim(z(['get','-H','-p','-o','value','guid',$argv[2]])),'createDestination'=>true]];
 $receipt=rpc($request);until(fn()=>is_file('/tmp/refresh-zfs-sends'));
 file_put_contents($plugin.'/php/operation-stages.php',"\n// fixture updated release\n",FILE_APPEND);
 try{zfsas_lifecycle('watchdog');throw new LogicException('Refresh replaced an active transfer.');}catch(RuntimeException $e){}
 $queued=$request;$queued['commandId']='refresh-real-queued';$queued['replication']['destination']=$argv[2].'/queued';$second=rpc($queued);
 until(function()use($receipt){foreach(rpc(['action'=>'status'])['runs'] as $run)if($run['id']===$receipt['runId'])foreach($run['taskStatus'] as $task)if($task['phase']==='replication_transfer')return $task['state']==='complete';return false;});
 $state=ZfsasCoordinatorState::readCommitted('/tmp/zfs-snapsync-coordinator');
 check($state['tasks'][$second['runId'].':prepare']['attemptCount']===0,'Queued work launched through refresh barrier');
 $beforeAttempts=0;foreach($state['runs'][$receipt['runId']]['tasks'] as $id)if($state['tasks'][$id]['kind']==='send')$beforeAttempts+=$state['tasks'][$id]['attemptCount'];
 check(count(file('/tmp/refresh-zfs-sends'))===1,'Active transfer retransmitted');
 zfsas_lifecycle('watchdog');
 until(function()use($receipt,$second){$done=[];foreach(rpc(['action'=>'status'])['runs'] as $r)$done[$r['id']]=$r['state'];return ($done[$receipt['runId']]??'')==='complete'&&($done[$second['runId']]??'')==='complete';});
 check(rpc($request)===$receipt,'Refresh lost stable receipt');
 $state=ZfsasCoordinatorState::readCommitted('/tmp/zfs-snapsync-coordinator');$sendAttempts=0;
 foreach($state['runs'][$receipt['runId']]['tasks'] as $id)if($state['tasks'][$id]['kind']==='send')$sendAttempts+=$state['tasks'][$id]['attemptCount'];
 check($sendAttempts===$beforeAttempts&&count(file('/tmp/refresh-zfs-sends'))===2,'Transfer repeated after refresh');
 check(trim(z(['get','-H','-p','-o','value','guid',$destination.'@original']))===$request['replication']['sourceGuid'],'Receiver identity differs');
 check(trim(z(['get','-H','-o','value','receive_resume_token',$destination]))==='-','Refresh interrupted receiver');
 check(zfsas_service_compatibility(rpc(['action'=>'handshake']))['compatible'],'Service build not refreshed');
 echo "PASS: real ZFS transfer finishes during pending refresh, queued work waits and revalidates, no retransmission, exact receiver GUID, receipts retained, read-only flash\n";
}finally{
 $owner=json_decode((string)@file_get_contents('/var/run/zfs-snapsync-coordinator/owner.json'),true);if($owner)posix_kill($owner['pid'],15);
 exec('umount /boot',$output,$code);check($code===0,'Cannot unmount flash fixture');
}
