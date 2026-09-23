<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/operation-diagnostics.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function task($phase,$state='complete',$dataset='tank/data',$attempts=1){return ['id'=>$phase.':'.$dataset,'kind'=>'prepare','dataset'=>$dataset,'state'=>$state,'attemptCount'=>$attempts,'parameters'=>['phase'=>$phase],'blocked'=>'','result'=>null];}
function stages($tasks,$state='complete',$stage='',$offset=0){return zfsas_operation_stages($tasks,$state,$stage,$offset);}
function state($data,$id){foreach($data['stages'] as $row)if($row['id']===$id)return $row['state'];}
check(!stages([])['available'],'Unknown history invented stages');
$d=stages([task('replication_inspect'),task('replication_space'),task('replication_transfer'),task('replication_verify')]);
check(state($d,'snapshots')==='not_required','Manual send creates snapshots');
check(state($d,'transfer')==='completed','Transfer not complete');
$d=stages([task('replication_schedule','failed'),task('replication_run_verify','canceled','tank/data',0)],'failed');
check(state($d,'snapshots')==='not_reached','Unplanned snapshots marked successful');
check(state($d,'cleanup')==='not_reached','Unplanned cleanup marked unnecessary');
check(state($d,'verify')==='not_reached','Dependent cancellation reported as executed');
foreach(['running'=>'running','waiting'=>'waiting','retry_wait'=>'retry_scheduled','failed'=>'failed','canceled'=>'canceled'] as $input=>$expected){$d=stages([task('replication_transfer',$input)],$input);check(state($d,'transfer')===$expected,$input.' mapped incorrectly');}
$d=stages([task('recovery_execute_start'),task('replication_verify')]);check(state($d,'snapshots')==='not_required','Recovery creates snapshots');
$tasks=[];for($i=0;$i<10000;$i++)$tasks[]=task('replication_transfer',$i===7654?'failed':'complete','tank/data'.$i);
$d=stages($tasks,'failed','transfer',7650);check(count($d['page']['rows'])===50,'Dataset page unbounded');check($d['page']['rows'][4]['state']==='failed','Failed dataset missing');check($d['page']['total']===10000&&$d['page']['nextOffset']===7700,'Pagination incomplete');check($d['stages'][5]['counts']['completed']===9999,'Dataset count wrong');
echo "PASS: recorded stages, failed/canceled/waiting/retrying, manual and recovery, unknown history, 10,000-dataset pagination\n";
