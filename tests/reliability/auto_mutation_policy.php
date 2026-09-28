<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/auto-mutation.php';
function check($ok,$why):void{if(!$ok)throw new RuntimeException($why);}
function reject($fn):void{try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Unsafe automatic mutation was accepted');}
$now=time();$auto=['PREFIX'=>'auto-','KEEP_ALL_FOR_DAYS'=>'14','KEEP_DAILY_UNTIL_DAYS'=>'30','KEEP_WEEKLY_UNTIL_DAYS'=>'183'];
$config=['auto'=>$auto,'send'=>['SEND_SNAPSHOT_PREFIX'=>'send-'],'prefixHistory'=>'old-send-'];
$rows=[];
foreach([['new',1,'200','10'],['old',200,'100','10']] as [$name,$age,$guid,$written]) {
    $rows['tank/data@auto-'.$name]=['guid'=>$guid,'txg'=>$guid,'created'=>(string)($now-$age*86400),'holds'=>'0','clones'=>'-','used'=>'10','written'=>$written];
}
$datasetGuid='10';$available='100';$freeing='0';$quota='0';$refquota='0';
$read=static function($a)use(&$rows,&$datasetGuid,&$available,&$quota,&$refquota):string {
    if($a[0]==='list' && in_array('snapshot',$a,true)) {
        return implode("\n",array_map(static fn($name,$r)=>implode("\t",[$name,$r['guid'],$r['txg'],$r['created'],$r['holds'],$r['clones'],$r['used'],$r['written']]),array_keys($rows),array_values($rows)))."\n";
    }
    if($a[0]==='list')return $available;
    return match($a[array_search('--',$a,true)-1]){'guid'=>$datasetGuid,'quota'=>$quota,'refquota'=>$refquota,'used','referenced'=>'0',default=>throw new RuntimeException('Unexpected metadata query')};
};
$poolRead=static function($a)use(&$freeing):string{return $freeing;};
$proposal=['action'=>'delete','snapshot'=>'tank/data@auto-old','policyDataset'=>'tank/data','datasetGuid'=>'10','guid'=>'100','txg'=>'100','reason'=>'age_window','inventoryHash'=>zfsas_auto_inventory('tank/data',$read)['hash']];
$p=['autoMutation'=>['proposal'=>$proposal]];
check(isset(zfsas_auto_mutation_check($p,$read,$poolRead,$config)['ready']),'Expired automatic snapshot was not eligible');
$datasetGuid='11';reject(fn()=>zfsas_auto_mutation_check($p,$read,$poolRead,$config));$datasetGuid='10';
$rows['tank/data@auto-old']['holds']='1';reject(fn()=>zfsas_auto_mutation_check($p,$read,$poolRead,$config));$rows['tank/data@auto-old']['holds']='0';
$changed=$config;$changed['auto']['KEEP_WEEKLY_UNTIL_DAYS']='300';reject(fn()=>zfsas_auto_mutation_check($p,$read,$poolRead,$changed));
$changed=$config;$changed['prefixHistory']='auto-';reject(fn()=>zfsas_auto_mutation_check($p,$read,$poolRead,$changed));
$pressure=$proposal;$pressure['reason']='space_pressure';$pressure['pressure']=['dataset'=>'tank/data','requiredBytes'=>200];
$p=['autoMutation'=>['proposal'=>$pressure]];
check(isset(zfsas_auto_mutation_check($p,$read,$poolRead,$config)['ready']),'Live reclaimable pressure snapshot rejected');
$available='200';check(zfsas_auto_mutation_check($p,$read,$poolRead,$config)['itemState']==='skipped','Achieved free-space target still granted deletion');
$available='100';$freeing='100';check(zfsas_auto_mutation_check($p,$read,$poolRead,$config)['itemState']==='skipped','Pending freeing changed legacy effective-space semantics');
$freeing='0';$refquota='10';check(zfsas_auto_mutation_check($p,$read,$poolRead,$config)['itemState']==='skipped','Refquota-only constraint authorized snapshot deletion');$refquota='0';
$rows['tank/data@auto-old']['used']='0';$p['autoMutation']['proposal']['inventoryHash']=zfsas_auto_inventory('tank/data',$read)['hash'];
reject(fn()=>zfsas_auto_mutation_check($p,$read,$poolRead,$config));
$rows['tank/data@auto-new']['written']='0';$rows['tank/data@auto-old']['written']='0';
$zero=$proposal;$zero['reason']='zero_change_housekeeping';
check(zfsas_auto_policy_candidate($rows,$zero,$auto),'Older zero-written duplicate was not selected');
$zero['snapshot']='tank/data@auto-new';check(!zfsas_auto_policy_candidate($rows,$zero,$auto),'Newest snapshot selected as a zero-change duplicate');
echo "PASS: Auto mutation identity, inventory, retention changes, checkpoint protection, pressure targets, freeing, refquota and zero-change anchors\n";
