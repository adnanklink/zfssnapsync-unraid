<?php
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires a disposable container.'); }
$plugin=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync');
require $plugin.'/php/snapshot-manager-helpers.php';
require $plugin.'/php/coordinator-executor.php';
require $plugin.'/php/coordinator-socket.php';
require $plugin.'/php/coordinator-batch.php';
require $plugin.'/php/coordinator-deletion.php';
$root='/tmp/zfs-snapsync-coordinator';$fixture='/tmp/shared-cleanup-adapter';
function check($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
function rpc($request) { $r=zfsas_coordinator_request($request);check($r['ok'],json_encode($r));return $r['result']; }
function until($fn) {
    $deadline=microtime(true)+20;
    do { if ($fn()) { return; } usleep(20000); } while(microtime(true)<$deadline);
    $details='';
    try {foreach(rpc(['action'=>'status'])['tasks'] as $task) {$details.=json_encode([$task['id'],$task['state'],$task['result']])."\n";}} catch(Throwable $e) {}
    foreach(glob('/tmp/zfs-snapsync-coordinator/attempts/*/output.log') ?: [] as $path) {$details.=substr((string)file_get_contents($path),-4096);}
    throw new RuntimeException('Shared cleanup timeout: '.@file_get_contents('/tmp/shared-cleanup-adapter/server.log').$details);
}
if (($argv[1] ?? '')==='server') {
    $j=new ZfsasCoordinatorState($root);$d=new ZfsasCoordinatorDeletion($j,$root);
    $e=new ZfsasCoordinatorExecutor($j,$root,$root.'/runtime',fn($task)=>$d->command($task),
        fn($task,$code)=>['outcome'=>'validation_failure','message'=>'No explicit adapter outcome.'],[],fn($id)=>$d->changed($id));
    $server=new ZfsasCoordinatorSocket('/var/run/zfs-snapsync-coordinator/control.sock',
        function($r) use($j,$e,$d) {
            return match($r['action']) {
                'submit'=>$j->submit($r['commandId'],$r['spec'],time()),
                'submit_pair'=>(function() use($j,$r) {
                    $runs=[];foreach($r['requests'] as $request) {$runs[]=$j->submit($request['commandId'],$request['spec'],time())['runId'];}return $runs;
                })(),
                'status'=>$j->state,
                'worker_report'=>$e->workerReport($r),
                'cancel'=>(function() use($e,$r) {$e->cancel($r['runId']);return [];})(),
                'manual_pair'=>(function() use($j,$d,$r) {
                    $runs=[];
                    foreach($r['batches'] as $batch) {
                        $items=$batch['items'];unset($batch['items']);
                        $run=$j->submit('batch-'.$batch['token'],['manual'=>true,'tasks'=>['items'=>[
                            'kind'=>'batch','dataset'=>$batch['dataset'],'items'=>$items,'parameters'=>['batch'=>$batch]]]],time())['runId'];
                        $d->dispatchBatch($j->state['tasks'][$run.':items']);$runs[]=$run;
                    }
                    return $runs;
                })(),
                default=>throw new InvalidArgumentException('Unknown action'),
            };
        },function($now) use($e,$d) {return min($e->tick($now),$d->tick($now));});
    $server->serve();exit;
}
mkdir($fixture.'/bin',0775,true);@mkdir('/boot/config/plugins/zfs.snapsync',0775,true);
file_put_contents('/boot/config/plugins/zfs.snapsync/zfs_snapsync.conf',"PREFIX=\"auto-\"\n");
file_put_contents('/boot/config/plugins/zfs.snapsync/zfs_send.conf',"SEND_SNAPSHOT_PREFIX=\"send-\"\n");
@mkdir('/var/local/emhttp',0775,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STARTED"');
file_put_contents($fixture.'/bin/zfs', <<<'PY'
#!/usr/bin/python3
import sys,os,time
a=sys.argv[1:];root='/tmp/shared-cleanup-adapter/'
if a[0]=='get':
 while os.path.exists(root+'block'):
  open(root+'entered','w').write(str(os.getpid()))
  time.sleep(.02)
 prop=a[a.index('value')+1]
 for p in prop.split(','):print({'guid':'123','userrefs':'0','clones':'-'}.get(p,'-'))
elif a[0]=='destroy':
 with open(root+'destroy','a') as out:out.write(a[-1]+'\n')
elif a[0]=='list':
 if '-t' in a and a[a.index('-t')+1]=='snapshot':print('tank/data@auto-manual\t1\t0\t0\t0\t123\t1\t-')
else:sys.exit(1)
PY);
chmod($fixture.'/bin/zfs',0755);putenv('PATH='.$fixture.'/bin:'.getenv('PATH'));
$hash=trim(shell_exec('bash -c '.escapeshellarg('source '.$plugin.'/scripts/ops-queue-lib.sh; send_config_hash')));
function submitOwner($name,$hash,$snapshot='tank/data@auto-old',$defer=false) {
    $job=['JOB_ID'=>$name,'REQUESTED_EPOCH'=>'1','QUEUE_SORT'=>'1','DATASET'=>'tank/data',
        'SNAPSHOT'=>$snapshot,'SNAPSHOT_NAME'=>explode('@',$snapshot)[1],'SNAPSHOT_EPOCH'=>'1','SNAPSHOT_GUID'=>'123',
        'SNAPSHOT_CREATETXG'=>'1','DELETE_POOL'=>'tank','ESTIMATED_RECLAIM_BYTES'=>'0','SEND_PROTECTED'=>'0',
        'DELETE_SCOPE'=>'snapshot','SEND_SCHEDULE_JOB_ID'=>'','SEND_CONFIG_HASH'=>$hash];
    $request=['action'=>'submit','commandId'=>$name,'spec'=>['tasks'=>['delete'=>['kind'=>'delete','dataset'=>'tank/data',
        'parameters'=>['nativeSchedule'=>true,'endpoint'=>'local','deleteJob'=>$job]]]]];
    return $defer ? $request : rpc($request)['runId'];
}
$proc=proc_open([PHP_BINARY,__FILE__,'server'],[0=>['file','/dev/null','r'],1=>['file',$fixture.'/server.log','a'],2=>['file',$fixture.'/server.log','a']],$pipes);
try {
    until(function() { try {rpc(['action'=>'status']);return true;} catch(Throwable $e) {return false;} });
    file_put_contents($fixture.'/block','1');
    $a=submitOwner('first-owner',$hash);
    until(fn()=>is_file($fixture.'/entered'));
    $state=rpc(['action'=>'status']);$physical=$state['tasks'][$a.':delete']['parameters']['cleanupTaskId'];
    $attempt=$state['attempts'][$state['tasks'][$physical]['attempt']];
    $b=submitOwner('late-owner',$hash);
    until(function() use($b,$physical) { $s=rpc(['action'=>'status']);return ($s['tasks'][$b.':delete']['parameters']['cleanupTaskId'] ?? '')===$physical; });
    rpc(['action'=>'cancel','runId'=>$a]);
    until(function() use($a) {return rpc(['action'=>'status'])['runs'][$a]['state']==='canceled';});
    check(ZfsasCoordinatorExecutor::members($attempt['pid'],$attempt['start'])===[],'Canceled approval still has live processes');
    check(!is_file($fixture.'/destroy'),'Canceled selected owner performed a mutation');
    unlink($fixture.'/block');
    until(function() use($b) {return rpc(['action'=>'status'])['runs'][$b]['state']==='complete';});
    check(file($fixture.'/destroy',FILE_IGNORE_NEW_LINES)===['tank/data@auto-old'],'Shared work was lost or duplicated');
    check(rpc(['action'=>'status'])['runs'][$a]['state']==='canceled','Completion resurrected canceled request');

    // Both queued owners receive the one physical result, with separate receipts.
    file_put_contents($fixture.'/block','1');unlink($fixture.'/entered');
    $c=submitOwner('result-owner-c',$hash,'tank/data@auto-next');
    until(fn()=>is_file($fixture.'/entered'));
    $d=submitOwner('result-owner-d',$hash,'tank/data@auto-next');
    until(function() use($c,$d) {$s=rpc(['action'=>'status']);return isset($s['tasks'][$d.':delete']['parameters']['cleanupTaskId'])
        && $s['tasks'][$c.':delete']['parameters']['cleanupTaskId']===$s['tasks'][$d.':delete']['parameters']['cleanupTaskId'];});
    unlink($fixture.'/block');
    until(function() use($c,$d) {$s=rpc(['action'=>'status']);return $s['runs'][$c]['state']==='complete' && $s['runs'][$d]['state']==='complete';});
    check(file($fixture.'/destroy',FILE_IGNORE_NEW_LINES)===['tank/data@auto-old','tank/data@auto-next'],'Result fanout repeated physical deletion');
    $batches=[];
    for($i=0;$i<2;$i++) {
        $batch=zfsas_sm_new_batch('tank/data','delete');$batch['approvedAt']=time();$batch['state']='queued';
        $batch['items']=[['identity'=>'tank/data@auto-manual#123','snapshot'=>'tank/data@auto-manual','guid'=>'123','candidate'=>true,'state'=>'queued']];
        zfsas_sm_batch_store($batch);$batches[]=$batch;
    }
    $manualRuns=rpc(['action'=>'manual_pair','batches'=>$batches]);
    until(function() use($manualRuns) {$s=rpc(['action'=>'status']);return $s['runs'][$manualRuns[0]]['state']==='complete' && $s['runs'][$manualRuns[1]]['state']==='complete';});
    foreach($batches as $batch) {
        $manifest=zfsas_sm_read_json_file(zfsas_sm_batch_path($batch['token']));
        check($manifest['items'][0]['state']==='completed','Shared manual approval was excluded by another registered owner');
    }
    check(file($fixture.'/destroy',FILE_IGNORE_NEW_LINES)===['tank/data@auto-old','tank/data@auto-next','tank/data@auto-manual'],'Overlapping manual reviews repeated or lost deletion');
    $policyRuns=rpc(['action'=>'submit_pair','requests'=>[
        submitOwner('stale-policy',str_repeat('f',64),'tank/data@auto-policy',true),
        submitOwner('current-policy',$hash,'tank/data@auto-policy',true)]]);
    until(function() use($policyRuns) {$s=rpc(['action'=>'status']);return $s['runs'][$policyRuns[0]]['state']==='complete' && $s['runs'][$policyRuns[1]]['state']==='complete';});
    $s=rpc(['action'=>'status']);
    check($s['tasks'][$policyRuns[0].':delete']['result']['itemState']==='skipped','Stale policy acquired another owner\'s authority');
    check($s['tasks'][$policyRuns[1].':delete']['result']['itemState']==='completed','Stale policy consumed valid independent approval');
    check(file($fixture.'/destroy',FILE_IGNORE_NEW_LINES)===['tank/data@auto-old','tank/data@auto-next','tank/data@auto-manual','tank/data@auto-policy'],'Policy handoff repeated or lost mutation');
    echo "PASS: actual shared deletion adapter, late independent owner, verified selected-owner shutdown, surviving approval, single physical mutation, independent result fanout, overlapping reviewed manual batches and stale-policy isolation\n";
} finally {
    @unlink($fixture.'/block');
    try {foreach(rpc(['action'=>'status'])['runs'] as $run) {rpc(['action'=>'cancel','runId'=>$run['id']]);}} catch(Throwable $e) {}
    proc_terminate($proc,9);proc_close($proc);
}
