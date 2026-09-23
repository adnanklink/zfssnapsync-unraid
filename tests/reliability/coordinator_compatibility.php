<?php
if(!is_file('/.dockerenv'))throw new RuntimeException('Disposable container required.');
$source=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync');
$plugin='/usr/local/emhttp/plugins/zfs.snapsync';
exec('mkdir -p '.escapeshellarg($plugin));exec('cp -a '.escapeshellarg($source).'/. '.escapeshellarg($plugin));
require $plugin.'/php/coordinator-lifecycle.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function until($fn){for($i=0;$i<300;$i++){if($fn())return;usleep(20000);}throw new RuntimeException('Timed out: '.@file_get_contents('/var/log/zfs_snapsync_coordinator.log'));}
function rpc($action,$extra=[]){$r=zfsas_coordinator_request(['action'=>$action]+$extra);check($r['ok'],$r['error']??'RPC failed');return $r['result'];}
function endpoint($name,$method='GET',$args=[]){
    global $plugin;
    $code='$GLOBALS["csrf_token"]="fixture";$_SERVER["REQUEST_METHOD"]=$argv[2];$_GET=json_decode($argv[3],true);$_POST=$_GET;require $argv[1];';
    $proc=proc_open([PHP_BINARY,'-r',$code,$plugin.'/php/'.$name,$method,json_encode($args)],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);proc_close($proc);
    check($err===''&&preg_match('/ZFSAS_JSON_BEGIN\s*(.*?)\s*ZFSAS_JSON_END/s',$out,$match),'Endpoint failed: '.$err.$out);
    return json_decode($match[1],true);
}
function blocked($fn){try{$fn();}catch(RuntimeException $e){return $e->getMessage();}throw new RuntimeException('Unsafe lifecycle action succeeded.');}
$dir='/boot/config/plugins/zfs.snapsync';mkdir($dir,0775,true);
file_put_contents($dir.'/zfs_snapsync.conf',"DATASETS=\"tank/test:1G\"\nPREFIX=\"auto-\"\nSCHEDULE_MODE=\"disabled\"\n");
file_put_contents($dir.'/zfs_send.conf',"SEND_SNAPSHOT_PREFIX=\"send-\"\n");
file_put_contents('/usr/local/sbin/zfs_snapsync',"#!/bin/bash\necho started >> /tmp/compat-worker\nwhile [[ ! -f /tmp/compat-release ]]; do sleep .05; done\necho finished >> /tmp/compat-worker\n");chmod('/usr/local/sbin/zfs_snapsync',0755);
check(!zfsas_service_compatibility([])['compatible'],'Missing handshake accepted');
foreach(['build','protocol','actions'] as $key){$hello=zfsas_service_handshake();unset($hello[$key]);check(!zfsas_service_compatibility($hello)['compatible'],'Missing '.$key.' accepted');}
zfsas_lifecycle('watchdog');$first=rpc('auto',['commandId'=>'compat-first']);
until(fn()=>is_file('/tmp/compat-worker'));
// Maintenance pauses admission, not the existing attempt. Capture another queued task directly.
file_put_contents($dir.'/maintenance','fixture');
$before=rpc('status');$receipt=rpc('auto',['commandId'=>'compat-queued']); // Existing auto work may deduplicate.
unlink($dir.'/maintenance');
file_put_contents($plugin.'/php/operation-stages.php',"\n// changed installed build\n",FILE_APPEND);
$message=blocked(fn()=>zfsas_lifecycle('watchdog'));
check(is_file('/var/run/zfs-snapsync-coordinator/refresh.json'),'No RAM refresh barrier');
check(!is_file($dir.'/maintenance'),'Watchdog wrote persistent maintenance');
check(file_get_contents('/tmp/compat-worker')==="started\n",'Transfer was stopped or restarted');
$message=blocked(fn()=>zfsas_lifecycle('prepare'));check(!is_file($dir.'/maintenance'),'Rejected preflight left persistent maintenance');
file_put_contents('/tmp/compat-release','1');
until(function()use($first){foreach(rpc('status')['runs'] as $run)if($run['id']===$first['runId'])return $run['state']==='complete';return false;});
$lockInode=fileinode('/var/run/zfs-snapsync-coordinator/owner.lock');
zfsas_lifecycle('watchdog');
check(zfsas_service_compatibility(rpc('handshake'))['compatible'],'Idle refresh did not activate installed code');
check(rpc('auto',['commandId'=>'compat-first'])===$first,'RAM receipt lost');
check(file_get_contents('/tmp/compat-worker')==="started\nfinished\n",'Finished transfer was retransmitted');
check(fileinode('/var/run/zfs-snapsync-coordinator/owner.lock')===$lockInode,'Permanent owner lock replaced');
$held=fopen('/var/run/zfs-snapsync-coordinator/lifecycle.lock','c');flock($held,LOCK_EX);check(str_contains(blocked(fn()=>zfsas_lifecycle('watchdog')),'Another'),'Concurrent lifecycle not serialized');flock($held,LOCK_UN);fclose($held);
// Stop cleanly, then reproduce a pre-handshake daemon using current executor fixtures.
zfsas_lifecycle('prepare');
$daemon=$plugin.'/php/coordinator-daemon.php';$current=file_get_contents($daemon);
$old=str_replace("    if (\$action === 'handshake') { return \$service; }",'', $current);
$old=str_replace("'service'=>\$service, ",'',$old);
file_put_contents($daemon,$old);unlink($dir.'/maintenance');unlink('/var/run/zfs-snapsync-coordinator/refresh.json');
$process=proc_open([PHP_BINARY,$daemon],[0=>['file','/dev/null','r'],1=>['file','/tmp/old-coordinator.log','a'],2=>['file','/tmp/old-coordinator.log','a']],$pipes);
until(function(){try{return zfsas_coordinator_request(['action'=>'handshake'])['ok']===false;}catch(Throwable $e){return false;}});
unlink('/var/run/zfs-snapsync-coordinator/owner.json');file_put_contents($daemon,$current);
$sequence=rpc('status')['sequence'];
$r=endpoint('operation-detail.php','GET',['operation_id'=>'coordinator:'.$first['runId']]);check(!$r['ok']&&$r['code']==='unsupported_capability'&&!$r['retryable'],'Old logs are not actionable');
$r=endpoint('coordinator-action.php','POST',['csrf_token'=>'fixture','action'=>'review_recovery','run_id'=>$first['runId']]);check($r['code']==='unsupported_capability','Old recovery is not gated');
check(rpc('status')['sequence']===$sequence,'Capability reads changed journal');
check(endpoint('coordinator-status.php')['service']['compatible']===false,'Status hid older service');
check(str_contains(blocked(fn()=>zfsas_lifecycle('watchdog')),'Older coordinator'),'Old watchdog did not fail closed');
zfsas_lifecycle('prepare');proc_close($process);zfsas_lifecycle('activate');
$r=zfsas_service_request(['action'=>'operation_detail','runId'=>$first['runId']]);check($r['ok'],'Logs unavailable after old-service replacement');
check(in_array('review_recovery',rpc('handshake')['actions'],true),'Recovery still unavailable');
check(str_contains(blocked(fn()=>zfsas_lifecycle('verify')),'hook failed'),'Suppressed install-hook failure accepted');
// Deliberately prevent startup after a verified stop; activation must fail visibly.
zfsas_lifecycle('prepare');file_put_contents($daemon,"<?php exit(1);\n");
check(str_contains(blocked(fn()=>zfsas_lifecycle('activate')),'activation failed'),'Failed startup reported success');
check(is_file('/tmp/zfs-snapsync-coordinator/checkpoint.json'),'History removed on failed startup');
echo "PASS: missing capabilities, active attempt drain, idle refresh without retransmission, RAM receipts and lock preservation, concurrent watchdogs, pre-handshake upgrade, install hook and restart failure\n";
