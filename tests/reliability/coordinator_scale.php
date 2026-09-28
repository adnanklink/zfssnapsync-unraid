<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-state.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/operation-diagnostics.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$root='/tmp/coordinator-scale-'.bin2hex(random_bytes(6));$started=microtime(true);
$j=new ZfsasCoordinatorState($root);$tasks=[];
for($i=0;$i<10000;$i++)$tasks['item-'.$i]=['kind'=>'prepare','dataset'=>'tank/data'.$i,'parameters'=>['phase'=>'replication_inspect']];
$receipt=$j->submit('scale-10000',['tasks'=>$tasks],100);
check(count($j->state['tasks'])===10000,'Task admission lost membership');
check($receipt===$j->submit('scale-10000',['tasks'=>$tasks],101),'Scale duplicate receipt changed');
$run=$receipt['runId'];$id=$run.':item-9999';$token=$j->claim($id,100,102);
check($j->result($id,$token,['outcome'=>'success'],101,103,true),'Last task result lost');
unset($j);$j=new ZfsasCoordinatorState($root);
check(count($j->state['tasks'])===10000&&$j->state['tasks'][$id]['state']==='complete','Restart lost scale state');
$d=zfsas_operation_stages(array_values($j->state['tasks']),'running','inspect',9950);
check($d['page']['total']===10000&&count($d['page']['rows'])===50&&$d['page']['nextOffset']===null,'Final stage page incorrect');
$j->cancel($run,104);
check($j->state['runs'][$run]['state']==='canceled','Scale cancellation not settled');
check($j->state['tasks'][$id]['state']==='complete','Cancellation overwrote completed work');
$elapsed=microtime(true)-$started;$memory=memory_get_peak_usage(true);
check($elapsed<30,'10,000-task fixture exceeded 30-second budget');
check($memory<512*1024*1024,'10,000-task fixture exceeded 512 MiB PHP budget');
echo json_encode(['status'=>'passed','tasks'=>10000,'seconds'=>round($elapsed,3),'peakPhpBytes'=>$memory,'scope'=>'deterministic journal admission, duplicate, result, reload, projection and cancellation; not real ZFS or soak'])."\n";
