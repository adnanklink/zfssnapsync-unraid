<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/source-retention.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function reject($fn){try{$fn();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new RuntimeException('Unsafe action accepted');}
$id='abcdef123456';$source='tank/data';$dest='backup/data';
$job=['id'=>$id,'source'=>$source,'destination'=>$dest,'children'=>'0','transport'=>'local'];
$policy=['keep'=>3,'binding'=>zfsas_source_binding($job),'datasets'=>[$source=>'10']];
$p=['job'=>$job,'policy'=>$policy,'source'=>$source,'sourceDatasetGuid'=>'10','destination'=>$dest,'revision'=>str_repeat('a',64),'verified'=>['snapshot'=>$source.'@s7','guid'=>'107']];
$rows=[];
for($i=1;$i<=7;$i++)$rows[$source.'@s'.$i]=['snapshot'=>$source.'@s'.$i,'guid'=>(string)(100+$i),'txg'=>(string)$i,'holds'=>'0','clones'=>'-',
 'properties'=>['org.zfs.snapsync:schedule'=>$id,'org.zfs.snapsync:source'=>'10','org.zfs.snapsync:occurrence'=>(string)$i]];
$rows[$source.'@foreign']=['snapshot'=>$source.'@foreign','guid'=>'999','txg'=>'99','holds'=>'0','clones'=>'-','properties'=>[]];
$destinations=[$dest=>['guid'=>'20','token'=>'-','snapshots'=>['s2'=>'102','s7'=>'107']]];
$calls=[];
$read=function($args)use(&$rows,&$destinations,&$calls,$source){
 $calls[]=$args;$name=end($args);$fields=$args[array_search('-o',$args)+1] ?? '';
 if($args[0]==='list'){
  if($fields==='name,guid'&&$name===$source)return "$source\t10\n";
  if($fields==='name,guid'&&isset($destinations[$name])){ $out='';foreach($destinations[$name]['snapshots'] as $snap=>$guid)$out.="$name@$snap\t$guid\n";return $out; }
  if($fields==='name,guid,createtxg,userrefs,clones') { $out='';foreach($rows as $row)$out.=implode("\t",[$row['snapshot'],$row['guid'],$row['txg'],$row['holds'],$row['clones']])."\n";return $out; }
 }
 if($args[0]==='get'){
  $property=$args[array_search('--',$args)-1];
  if($fields==='name,property,value,source') { $out='';foreach($rows as $row)foreach(['schedule','occurrence','source'] as $key){$prop='org.zfs.snapsync:'.$key;$value=$row['properties'][$prop] ?? '-';$out.=$row['snapshot']."\t$prop\t$value\t".($value==='-'?'-':'local')."\n";}return $out; }
  if($fields==='name,value') { $out='';foreach(array_slice($args,array_search('--',$args)+1) as $key){if(!isset($rows[$key]))throw new RuntimeException('missing anchor');$out.=$key."\t".$rows[$key]['guid']."\n";}return $out; }
  if($fields==='property,value,source'){
   if(!isset($rows[$name]))throw new RuntimeException('missing snapshot');$r=$rows[$name];$out='';
   foreach(['guid'=>$r['guid'],'createtxg'=>$r['txg'],'userrefs'=>$r['holds'],'clones'=>$r['clones']] as $key=>$v)$out.="$key\t$v\t-\n";
   foreach($r['properties'] as $key=>$v)$out.="$key\t$v\tlocal\n";return $out;
  }
  if($property==='receive_resume_token'&&isset($destinations[$name]))return $destinations[$name]['token'];
  if($property==='guid'){
   if($name===$source)return '10';if(isset($rows[$name]))return $rows[$name]['guid'];if(isset($destinations[$name]))return $destinations[$name]['guid'];
   [$dataset,$snap]=array_pad(explode('@',$name,2),2,'');if(isset($destinations[$dataset]['snapshots'][$snap]))return $destinations[$dataset]['snapshots'][$snap];
  }
 }
 throw new RuntimeException('Unavailable metadata: '.json_encode($args));
};
// Names and replicated/inherited property values alone cannot grant ownership.
$copied=$rows[$source.'@s1'];$copied['properties']['org.zfs.snapsync:source']='20';
check(!zfsas_source_owned($copied,$id,'10'),'Received property copy granted source ownership');
$copied['properties']['org.zfs.snapsync:source']='10';unset($copied['properties']['org.zfs.snapsync:occurrence']);
check(!zfsas_source_owned($copied,$id,'10'),'Incomplete ownership granted cleanup');
$boundary=$p;$boundary['verified']=['snapshot'=>$source.'@s5','guid'=>'105'];
$ordered=$rows;uasort($ordered,fn($a,$b)=>-zfsas_source_decimal($a['txg'],$b['txg']));
$selection=zfsas_source_select($ordered,$boundary,[]);
check(!in_array($source.'@s6',array_column($selection['candidates'],'snapshot'),true),'Deleted beyond successful checkpoint boundary');
$plan=zfsas_source_plan($p,[$job],$read);
$deletes=array_values(array_filter($plan['tasks'],fn($task)=>$task['kind']==='delete'));
check(count($deletes)===1 && array_column($deletes[0]['parameters']['candidates'],'snapshot')===['tank/data@s4','tank/data@s3','tank/data@s2','tank/data@s1'],'Wrong latest-three or foreign ownership boundary');
$second=$job;$second['id']='fedcba654321';$second['destination']='backup/lagging';$destinations['backup/lagging']=['guid'=>'21','token'=>'-','snapshots'=>['s2'=>'102']];
$rows[$source.'@s3']['holds']='1';$rows[$source.'@s4']['clones']='tank/clone';
$plan=zfsas_source_plan($p,[$job,$second],$read);$delete=array_values(array_filter($plan['tasks'],fn($t)=>$t['kind']==='delete'))[0]['parameters'];
check(array_column($delete['candidates'],'snapshot')===['tank/data@s1'],'Lagging receiver, hold or clone lost protection');
check($plan['summary']['protected']===6,'Protected snapshots exceeding count omitted');
$mutations=[];$records=[];$authorizations=[];
$result=zfsas_source_delete($delete,$read,function($name)use(&$mutations,&$rows){$mutations[]=$name;unset($rows[$name]);},function($name)use(&$authorizations){$authorizations[]=$name;},function($row,$state)use(&$records){$records[$row['snapshot']]=$state;});
check($mutations===['tank/data@s1']&&$result['deleted']===1&&$records['tank/data@s1']==='completed','Superseded unreceived checkpoint was not deleted with explicit result');
$result=zfsas_source_delete($delete,$read,fn()=>throw new RuntimeException('Repeated deletion'),fn()=>null);
check($result['skipped']===1,'Retry repeated a successful deletion');
$destinations['backup/lagging']['token']='resume';reject(fn()=>zfsas_source_plan($p,[$job,$second],$read));
$destinations['backup/lagging']['token']='-';$remote=$second;$remote['transport']='ssh';reject(fn()=>zfsas_source_plan($p,[$job,$remote],$read));
$destinations['backup/lagging']['snapshots']=[];reject(fn()=>zfsas_source_delete($delete,$read,fn()=>throw new RuntimeException('Destroyed after base removal'),fn()=>null));
$replaced=$p;$replaced['sourceDatasetGuid']='11';reject(fn()=>zfsas_source_plan($replaced,[$job],$read));
$review=zfsas_source_review(['job'=>$job,'keep'=>3,'revision'=>$p['revision']],[$job],$read);
check($review['unmanaged']===1 && $review['datasets']===[$source=>'10'],'Review adopted foreign snapshots');
$token=bin2hex(random_bytes(24));zfsas_source_review_write($token,$review);
$membership=fn()=>[['source'=>$source,'sourceDatasetGuid'=>'10']];
try{
 check(zfsas_source_policy([],$job)['keep']===0,'Existing job gained deletion authority');
 reject(fn()=>zfsas_source_save([],[$job],[$id=>'3'],[],$p['revision'],$membership,fn()=>$rows));
 $new=zfsas_source_save([],[$job],[$id=>'3'],[],$p['revision'],$membership,fn()=>[]);check(zfsas_source_policy(['SEND_SOURCE_RETENTION'=>$new],$job)['keep']===3,'New job failed to authorize future checkpoints');
 $saved=zfsas_source_save([],[$job],[$id=>'3'],[$id=>$token],$p['revision'],$membership);
 $config=['SEND_SOURCE_RETENTION'=>$saved];check(zfsas_source_policy($config,$job)['keep']===3,'Explicit review lost');
 check(zfsas_source_save($config,[$job],[],[],$p['revision'],$membership)===$saved,'Unrelated save changed policy');
 reject(fn()=>zfsas_source_save($config,[$job],[$id=>'2'],[],$p['revision'],$membership));
 reject(fn()=>zfsas_source_save([],[$job],[$id=>'3'],[$id=>$token],'different',$membership));
 reject(fn()=>zfsas_source_save([],[$job],[$id=>'3'],[$id=>$token],$p['revision'],fn()=>[['source'=>$source,'sourceDatasetGuid'=>'999']]));
 $changed=$job;$changed['destination']='backup/other';check(zfsas_source_policy($config,$changed)['keep']===0,'Changed scope retained authority');
 $review['expires']=time()-1;zfsas_source_review_write($token,$review);reject(fn()=>zfsas_source_save([],[$job],[$id=>'3'],[$id=>$token],$p['revision'],$membership));
 foreach(['{}','[]','{"version":2,"jobs":{}}','{"version":1,"jobs":[]}','{'] as $raw)reject(fn()=>zfsas_source_policies(['SEND_SOURCE_RETENTION'=>$raw]));
}finally{@unlink(zfsas_source_review_path($token));}
// A single inventory pass scales independently of candidate count.
$rows=[];for($i=1;$i<=10000;$i++)$rows[$source.'@s'.$i]=['snapshot'=>$source.'@s'.$i,'guid'=>(string)(100+$i),'txg'=>(string)$i,'holds'=>'0','clones'=>'-',
 'properties'=>['org.zfs.snapsync:schedule'=>$id,'org.zfs.snapsync:source'=>'10','org.zfs.snapsync:occurrence'=>(string)$i]];
$p['verified']=['snapshot'=>$source.'@s10000','guid'=>'10100'];$destinations[$dest]['snapshots']=['s10000'=>'10100'];$calls=[];
$large=zfsas_source_plan($p,[$job],$read);check($large['summary']['eligible']===9997,'10,000-snapshot selection wrong');
check(count($calls)<12,'Planner rescanned per candidate');
foreach($large['tasks'] as $task)if($task['kind']==='delete')check(count($task['parameters']['candidates'])<=50,'Oversized deletion chunk');
echo "PASS: source retention ownership, newest-three, lagging receiver, holds/clones, superseded failures, review binding/expiry, exact preflight and 10,000-snapshot bounded planning\n";
