<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/replication-ssh-delete.php';
function check($ok,$why):void{if(!$ok)throw new RuntimeException($why);}
function reject($fn):void{try{$fn();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new RuntimeException('Unsafe remote cleanup accepted');}
$endpoint=ZfsasEndpointIdentity::receiver('SHA256:'.str_repeat('a',43),'789');
$prefix='backup/data@snapsync-send-abcdef123456-';$now=time();
$rows=[];
foreach([['newest','300',1,0,'-'],['base','200',10,0,'-'],['daily','190',20,0,'-'],['expired','180',200,0,'-'],['held','170',220,1,'-'],['clone','160',230,0,'backup/clone']] as $i=>[$name,$guid,$age,$hold,$clone]) {
    $rows[$name]=[$prefix.$name,$guid,(string)(100-$i),(string)($now-$age*86400),'0',(string)$hold,$clone];
}
$available='0';$freeing='0';$resume='-';$changed=false;$hashReads=0;
$read=static function($a)use(&$rows,&$available,&$resume,&$changed,&$hashReads){
    if($a[0]==='list') {
        $hash=in_array('name,guid,createtxg,creation,userrefs,clones',$a,true);
        if($hash && ++$hashReads>1 && $changed) {return "changed\n";}
        return implode("\n",array_map(static fn($r)=>implode("\t",$hash?[$r[0],$r[1],$r[2],$r[3],$r[5],$r[6]]:$r),$rows))."\n";
    }
    if(in_array('receive_resume_token',$a,true))return $resume;
    if(in_array('available',$a,true))return $available;
    if(in_array('refquota,referenced',$a,true))return "0\n0";
    if(in_array('quota',$a,true))return '0';
    return end($a)==='backup/data'?'20':'200';
};
$readPool=static function($a)use(&$freeing){return $freeing;};
$source=static function($a){check(end($a)==='tank/source@next','Receiver reference routed to source');return '400';};
$request=['transport'=>'ssh','sourceSnapshot'=>'tank/source@next','sourceGuid'=>'400','destination'=>'backup/data'];
$inspection=['receiverEndpoint'=>$endpoint,'mode'=>'incremental','destinationDatasetGuid'=>'20','references'=>[
    ['endpoint'=>'local','snapshot'=>'tank/source@next','guid'=>'400'],['endpoint'=>$endpoint,'snapshot'=>$prefix.'base','guid'=>'200']]];
$policy=['mode'=>'older_anchors','scheduleId'=>'abcdef123456','prefix'=>'snapsync-send-','sendConfigHash'=>str_repeat('a',64),'keepAll'=>14,'keepDaily'=>30,'keepWeekly'=>183];
$retention=zfsas_replication_cleanup($request,$inspection,$policy,$read,$now);check(count($retention)===1,'Remote retention changed eligibility');
$p=reset($retention)['parameters'];
check($p['endpoint']===$endpoint && $p['replication']===$request,'Remote cleanup lost endpoint authority');
$hashReads=0;check(isset(zfsas_ssh_delete_preflight($p,$read,$readPool,$source)['inventoryHash']),'Eligible remote retention was not bound to receiver inventory');
$rows['expired'][5]='1';$hashReads=0;check(zfsas_ssh_delete_preflight($p,$read,$readPool,$source)['itemState']==='skipped','New hold did not revoke remote cleanup');$rows['expired'][5]='0';
$changed=true;$hashReads=0;reject(fn()=>zfsas_ssh_delete_preflight($p,$read,$readPool,$source));$changed=false;
$resume='resume-token';reject(fn()=>zfsas_ssh_delete_preflight($p,$read,$readPool,$source));$resume='-';
$anchors=zfsas_replication_anchor_candidates($request,$inspection,$policy,$read,$now);check(count($anchors)===1,'Remote anchor selection changed');
$job=reset($anchors)['parameters']['deleteJob'];
$pressure=['endpoint'=>$endpoint,'deleteJob'=>$job,'pressure'=>['requiredBytes'=>1000,'replication'=>$request,'inspection'=>$inspection,'policy'=>$policy]];
$hashReads=0;check(isset(zfsas_ssh_delete_preflight($pressure,$read,$readPool,$source)['inventoryHash']),'Eligible remote anchor was rejected');
$available='1000';$hashReads=0;check(zfsas_ssh_delete_preflight($pressure,$read,$readPool,$source)['itemState']==='skipped','Remote anchor deleted after target was met');$available='0';
$freeing='5';$hashReads=0;check(zfsas_ssh_delete_preflight($pressure,$read,$readPool,$source)['reason']==='space','Remote freeing was ignored');
echo "PASS: remote retention and pressure ownership, holds, recovery, inventory races, achieved targets and receiver freeing\n";
