<?php
require __DIR__.'/ssh_receiver_read.php';
require __DIR__.'/source_retention_fixture.php';
$plugin=realpath(__DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php');
require_once $plugin.'/coordinator-state.php';
require_once $plugin.'/coordinator-socket.php';
require_once $plugin.'/source-retention-policy.php';
function rpc(array $request):array {
    $reply=zfsas_coordinator_request($request);check($reply['ok'],json_encode($reply));return $reply['result'];
}
function until(callable $fn) {
    $end=microtime(true)+90;
    do {if($value=$fn())return $value;usleep(30000);}while(microtime(true)<$end);
    throw new RuntimeException('SSH source cleanup timeout: '.@file_get_contents('/tmp/ssh-source-daemon.log'));
}
source_fixture_zfs(7);
// This fixture hosts both endpoints in one container, so use the plugin's
// shared gate group for the otherwise separate receiver account.
command(['usermod','-a','-G','users','receiver']);
file_put_contents('/usr/local/bin/zpool',"#!/bin/sh\nif [ \"\$1\" = list ]; then echo 111; else echo 789; fi\n");
chmod('/usr/local/bin/zpool',0755);
$ssh=server('replacement');$daemon=null;
try {
    $dir='/boot/config/plugins/zfs.snapsync';@mkdir($dir,0770,true);
    $job=['id'=>'abcdef123456','source'=>'tank/data','destination'=>'backup/data','children'=>'0','transport'=>'ssh','frequency'=>'1d','threshold'=>'0G'];
    $policy=['keep'=>3,'binding'=>zfsas_source_binding($job,$config),'datasets'=>['tank/data'=>'10']];
    $send=array_replace(zfsas_send_defaults(),$config);
    $send['SEND_JOBS']=zfsas_send_render_jobs_string([$job]);
    $send['SEND_SOURCE_RETENTION']=json_encode(['version'=>1,'jobs'=>[$job['id']=>$policy]]);
    $send['SEND_SCHEDULE_SPECS']=json_encode([$job['id']=>['version'=>1,'kind'=>'interval','seconds'=>21600,'anchor'=>time()]]);
    file_put_contents($dir.'/zfs_snapsync.conf',"DATASETS=\"\"\nPREFIX=\"snapsync-auto-\"\n");
    file_put_contents($dir.'/zfs_send.conf',zfsas_send_render_config($send)."\nSEND_SCHEDULE_SPECS='".$send['SEND_SCHEDULE_SPECS']."'\n");
    $revision=zfsas_config_revision($dir);$journal=new ZfsasCoordinatorState('/tmp/zfs-snapsync-coordinator');
    $ref=['role'=>'source','endpoint'=>'local','dataset'=>'tank/data','datasetGuid'=>'10','snapshot'=>'tank/data@s7','guid'=>'107'];
    $r=$journal->submit('verified-ssh-replication',['revision'=>$revision,'tasks'=>[
        'prepare'=>['kind'=>'prepare','parameters'=>['phase'=>'replication_schedule','nativeSchedule'=>true,'sourcePolicy'=>$policy,'job'=>$job]],
        'snapshot'=>['kind'=>'auto','parameters'=>['phase'=>'replication_snapshot','nativeSchedule'=>true,'source'=>'tank/data','sourceDatasetGuid'=>'10','destination'=>'backup/data','snapshotName'=>'s7']],
        'verify'=>['kind'=>'finalize']]],time())['runId'];
    foreach(['prepare','snapshot','verify'] as $name) {
        $task=$r.':'.$name;$token=$journal->claim($task,1,time(),'fixture');$journal->started($task,$token,123,'456');
        $result=['outcome'=>'success']+($name==='snapshot'?['reference'=>$ref]:[]);
        $journal->workerReport(['taskId'=>$task,'token'=>$token,'generation'=>'fixture','sequence'=>1,'type'=>'result','payload'=>$result],'fixture',time());
        $journal->result($task,$token,$result,1,time(),true);
    }
    unset($journal);
    @mkdir('/var/local/emhttp',0770,true);file_put_contents('/var/local/emhttp/var.ini','mdState="STARTED"');
    $daemon=proc_open([PHP_BINARY,$plugin.'/coordinator-daemon.php'],[1=>['file','/tmp/ssh-source-daemon.log','a'],2=>['file','/tmp/ssh-source-daemon.log','a']],$pipes);
    until(function(){try{return rpc(['action'=>'status']);}catch(Throwable $e){return false;}});
    $parent=until(function()use($r){foreach(rpc(['action'=>'status'])['runs'] as $run)if($run['id']===$r&&!empty($run['sourceCleanupRunId']))return $run;return false;});
    $cleanup=until(function()use($parent){foreach(rpc(['action'=>'status'])['runs'] as $run)if($run['id']===$parent['sourceCleanupRunId']&&ZfsasCoordinatorState::terminal($run['state']))return $run;return false;});
    check($cleanup['state']==='complete',json_encode($cleanup));
    $state=json_decode(file_get_contents('/tmp/source-zfs.json'),true);
    check(count($state['deleted'])===4,'SSH follow-up deleted the wrong checkpoints: '.json_encode($cleanup));
    check(isset($state['datasets']['tank/data']['snapshots']['s5'],$state['datasets']['tank/data']['snapshots']['s6'],$state['datasets']['tank/data']['snapshots']['s7'],$state['datasets']['tank/data']['snapshots']['foreign']),'SSH follow-up lost protected checkpoints');
    check($cleanup['sourceCleanup']['deleted']===4,'SSH source cleanup accounting lost results');
    echo "PASS: actual coordinator SSH source follow-up, receiver checkpoint leases, verified remote shutdown and source retention accounting\n";
} finally {
    if(is_resource($daemon)){proc_terminate($daemon);proc_close($daemon);}
    proc_terminate($ssh);proc_close($ssh);
}
