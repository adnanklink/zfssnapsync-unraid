<?php
if(!is_file('/.dockerenv'))exit(77);
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-socket.php';
if(!function_exists('check')){function check($ok,$why){if(!$ok)throw new RuntimeException($why);}}
function rpc($r){$reply=zfsas_coordinator_request($r);check($reply['ok'],json_encode($reply));return $reply['result'];}
function waitRun($id){$end=microtime(true)+30;do{foreach(rpc(['action'=>'status'])['runs'] as $run)if($run['id']===$id&&in_array($run['state'],['complete','failed','canceled'],true))return $run;usleep(50000);}while(microtime(true)<$end);throw new RuntimeException('Retirement timed out: '.file_get_contents('/tmp/retirement-daemon.log'));}
$plugin='/usr/local/emhttp/plugins/zfs.snapsync';$dir='/boot/config/plugins/zfs.snapsync';@mkdir($dir,0770,true);@mkdir('/var/local/emhttp',0770,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STARTED"');
file_put_contents($dir.'/zfs_snapsync.conf',"DATASETS=\"tank/data:0G\"\nPREFIX=\"auto-\"\nSCHEDULE_MODE=\"disabled\"\n");
$transport=empty($retirementSsh)?'local':'ssh';
file_put_contents($dir.'/zfs_send.conf',"SEND_SNAPSHOT_PREFIX=\"send-\"\nSEND_JOBS=\"aaaaaaaaaaaa|tank/data|backup/data|1d|0G|0|$transport\"\n");
if(!empty($retirementSsh)){foreach($config as $key=>$value)file_put_contents($dir.'/zfs_send.conf',$key.'="'.$value.'"'."\n",FILE_APPEND);command(['usermod','-a','-G','users','receiver']);file_put_contents('/usr/local/bin/zpool',"#!/bin/sh\nif [ \"\$1\" = list ]; then echo 111; else echo 789; fi\n");chmod('/usr/local/bin/zpool',0755);}
file_put_contents($plugin.'/scripts/sync-cron.sh',"#!/bin/sh\nexit 0\n");chmod($plugin.'/scripts/sync-cron.sh',0755);
$initial=['rows'=>['tank/data@auto-old'=>['1','1','100','0','-'],'tank/data@send-base'=>['2','2','101','0','-'],'tank/data@manual'=>['3','3','102','0','-'],'backup/data@send-base'=>['2','2','101','0','-'],'backup/data@send-held'=>['4','4','103','1','-']],'actions'=>[]];file_put_contents('/tmp/retirement-zfs.json',json_encode($initial));chmod('/tmp/retirement-zfs.json',0666);
file_put_contents('/usr/local/bin/zfs',<<<'PY'
#!/usr/bin/python3
import sys,json,os,time
a=sys.argv[1:];p='/tmp/retirement-zfs.json';s=json.load(open(p));name=a[-1]
if a[0]=='get':
 prop=a[a.index('--')-1]
 if prop=='guid,createtxg,userrefs,clones':
  r=s['rows'][name];print('\n'.join([r[0],r[1],r[3],r[4]]))
 else:print('-' if prop=='receive_resume_token' else ('10' if name.startswith('tank/') else '20'))
elif a[0]=='list':
 for n,r in s['rows'].items():
  if n.startswith(name+'@'):print('\t'.join([n]+r))
elif a[0]=='destroy':
 if os.path.exists('/tmp/retirement-block'):
  open('/tmp/retirement-entered','w').write('entered')
  while os.path.exists('/tmp/retirement-block'):time.sleep(.02)
 if os.path.exists('/tmp/retirement-fail') and name.startswith('backup/'):sys.exit(1)
 assert name in s['rows'] and s['rows'][name][3]=='0'
 del s['rows'][name];s['actions'].append(name);json.dump(s,open(p,'w'))
else:sys.exit(2)
PY);chmod('/usr/local/bin/zfs',0755);
@mkdir($dir.'/send-control/paused',0770,true);file_put_contents($dir.'/send-control/paused/aaaaaaaaaaaa','fixture');
$daemon=proc_open([PHP_BINARY,$plugin.'/php/coordinator-daemon.php'],[1=>['file','/tmp/retirement-daemon.log','a'],2=>['file','/tmp/retirement-daemon.log','a']],$pipes);
try{
 for($i=0;$i<100;$i++){try{rpc(['action'=>'status']);break;}catch(Throwable $e){usleep(30000);}}
 $session=rpc(['action'=>'retirement_inspect','dataset'=>'tank/data']);check(count($session['jobs'])===1,'Missing job');$token=$session['token'];
 $s=rpc(['action'=>'retirement_stop','token'=>$token]);check($s['saved']&&$s['schedulerApplied'],'Stop failed');check(rpc(['action'=>'retirement_stop','token'=>$token])===$s,'Stop not idempotent');
 $s=rpc(['action'=>'retirement_review','token'=>$token]);check(waitRun($s['reviewRun'])['state']==='complete','Review failed');
 $s=rpc(['action'=>'retirement_status','token'=>$token]);check($s['total']===5&&$s['selected']===3&&$s['eligible']===4,'Review counts: '.json_encode($s));
 $s=rpc(['action'=>'retirement_submit','token'=>$token,'confirm'=>true]);check(rpc(['action'=>'retirement_submit','token'=>$token,'confirm'=>true])['deleteRun']===$s['deleteRun'],'Duplicate mutation receipt changed');
 $run=waitRun($s['deleteRun']);check($run['state']==='complete','Deletion failed: '.json_encode($run));
 $state=json_decode(file_get_contents('/tmp/retirement-zfs.json'),true);check(count($state['actions'])===3&&$state['actions'][0]==='backup/data@send-base','Destination-first deletion wrong');check(isset($state['rows']['tank/data@manual'],$state['rows']['backup/data@send-held']),'Unselected/held snapshot deleted');
 // Already removed job: explicitly reselect its receiver; destination failure preserves source.
 file_put_contents('/tmp/retirement-zfs.json',json_encode($initial));file_put_contents('/tmp/retirement-fail','1');
 $s=rpc(['action'=>'retirement_inspect','dataset'=>'tank/data','destinations'=>[['dataset'=>'backup/data','transport'=>$transport]]]);$token=$s['token'];rpc(['action'=>'retirement_stop','token'=>$token]);$s=rpc(['action'=>'retirement_review','token'=>$token]);check(waitRun($s['reviewRun'])['state']==='complete','Second review failed');$s=rpc(['action'=>'retirement_submit','token'=>$token,'confirm'=>true]);check(waitRun($s['deleteRun'])['state']==='failed','Receiver failure hidden');check(json_decode(file_get_contents('/tmp/retirement-zfs.json'),true)['actions']===[],'Source deleted after receiver failure');
 if(empty($retirementSsh)){
  unlink('/tmp/retirement-fail');
  $begin=static function()use($initial){file_put_contents('/tmp/retirement-zfs.json',json_encode($initial));$s=rpc(['action'=>'retirement_inspect','dataset'=>'tank/data','destinations'=>[['dataset'=>'backup/data','transport'=>'local']]]);rpc(['action'=>'retirement_stop','token'=>$s['token']]);$s=rpc(['action'=>'retirement_review','token'=>$s['token']]);check(waitRun($s['reviewRun'])['state']==='complete','Fault review failed');return $s;};
  $s=$begin();$changed=$initial;$changed['rows']['backup/data@send-base'][0]='999';file_put_contents('/tmp/retirement-zfs.json',json_encode($changed));$s=rpc(['action'=>'retirement_submit','token'=>$s['token'],'confirm'=>true]);check(waitRun($s['deleteRun'])['state']==='failed','Changed snapshot GUID accepted');check(json_decode(file_get_contents('/tmp/retirement-zfs.json'),true)['actions']===[],'Changed GUID caused a mutation');
  $s=$begin();file_put_contents('/tmp/retirement-block','1');$s=rpc(['action'=>'retirement_submit','token'=>$s['token'],'confirm'=>true]);$end=microtime(true)+10;while(!is_file('/tmp/retirement-entered')&&microtime(true)<$end)usleep(20000);check(is_file('/tmp/retirement-entered'),'Deletion did not reach cancellation gate');rpc(['action'=>'cancel','runId'=>$s['deleteRun']]);check(waitRun($s['deleteRun'])['state']==='canceled','Retirement cancellation failed');check(json_decode(file_get_contents('/tmp/retirement-zfs.json'),true)['actions']===[],'Canceled mutation executed');unlink('/tmp/retirement-block');
  $s=$begin();$path='/tmp/zfs-snapsync-coordinator/retirements/'.$s['token'].'.json';$stored=json_decode(file_get_contents($path),true);$stored['expires']=time()-1;file_put_contents($path,json_encode($stored));$rejected=zfsas_coordinator_request(['action'=>'retirement_submit','token'=>$s['token'],'confirm'=>true]);check(!$rejected['ok'],'Expired retirement review accepted');
  $s=$begin();$new=$initial;$new['rows']['tank/data@auto-added']=['500','500','500','0','-'];file_put_contents('/tmp/retirement-zfs.json',json_encode($new));$s=rpc(['action'=>'retirement_submit','token'=>$s['token'],'confirm'=>true]);check(waitRun($s['deleteRun'])['state']==='complete','Captured selection failed');check(isset(json_decode(file_get_contents('/tmp/retirement-zfs.json'),true)['rows']['tank/data@auto-added']),'New snapshot joined captured selection');
  echo "PASS: actual retirement GUID replacement, cancellation, expired review and newly created snapshot exclusion\n";
 }
 echo "PASS: actual retirement daemon stop/review/submit, exact defaults, held/manual exclusions, duplicate requests, removed-job destination and destination-first failure boundary\n";
}finally{proc_terminate($daemon);proc_close($daemon);}
