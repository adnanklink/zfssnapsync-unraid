<?php
require __DIR__ . '/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/transfer-progress.php';
function check($value) { if (!$value) { throw new RuntimeException('Progress assertion failed'); } }
$p = new ZfsasTransferProgress();
check($p->sample("size\t10485760\n", 0) === null);
$a=$p->sample("12:00:00\t1048576\ttank/data@snap\n", 1);
check($a['percent']===10 && !str_contains($a['message'],'MiB/s'));
check($p->sample("12:00:01\t2097152\ttank/data@snap\n",2)===null);
$a=$p->sample("12:00:02\t5242880\ttank/data@snap\n",3);
check($a['percent']===50 && str_contains($a['message'],'2.0 MiB/s'));
check($p->sample("12:00:03\t20971520\ttank/data@snap\n",5)['percent']===99);
check($p->sample("warning: receiver unavailable\n",7)===null);
$p=new ZfsasTransferProgress();
check(!isset($p->sample("12:00:00\t0\ttank/data@snap\n",1)['percent']));
echo "PASS: bounded transfer samples, measured rate, unknown estimate and no premature completion\n";

$task=['kind'=>'send','state'=>'running','progressAt'=>100,'progress'=>['phase'=>'transfer','percent'=>25,'message'=>'1 MiB/s']];
$run=['state'=>'running','taskStatus'=>[$task]];
check(zfsas_run_progress($run,105)['percent']===25);
check(zfsas_run_progress($run,111)['percent']===null);
check(!str_contains(implode(' ',zfsas_run_progress($run,111)['messages']),'MiB/s'));
$run['taskStatus'][]=$task;
check(zfsas_run_progress($run,105)['percent']===null);
$run['state']='complete';check(zfsas_run_progress($run,105)['phase']==='');
$run=['state'=>'running','taskStatus'=>[array_replace($task,['state'=>'waiting','blocked'=>'resource_contention'])]];
check(zfsas_run_progress($run,105)['phase']==='resource_contention');
check(zfsas_run_progress($run,105)['messages']===[]);
echo "PASS: active phase projection, parallel estimates, stale speed expiry and terminal suppression\n";

$waiting=['kind'=>'send','phase'=>'replication_transfer','state'=>'waiting','blocked'=>'resource',
    'result'=>['outcome'=>'wait','reason'=>'resource'],'progress'=>['phase'=>'resource_admission','message'=>'Checking dataset ownership.'],'progressAt'=>100];
$baseline=zfsas_run_progress(['state'=>'running','taskStatus'=>[$waiting]],105);
check($baseline['stateLabel']==='Waiting');
foreach(['launching','running','waiting'] as $state){$waiting['state']=$state;check(zfsas_run_progress(['state'=>'running','taskStatus'=>[$waiting]],105)===$baseline);}
$waiting['state']='running';$waiting['progress']=['phase'=>'transfer','percent'=>42,'message'=>'8.0 MiB/s'];
$actual=zfsas_run_progress(['state'=>'running','taskStatus'=>[$waiting]],105);
check($actual['percent']===42&&$actual['phase']==='transfer'&&$actual['stateLabel']===null);
echo "PASS: resource rechecks keep a stable waiting status until real transfer progress starts\n";
