<?php
require_once __DIR__.'/replication-ssh-receiver.php';
require_once __DIR__.'/replication-cleanup.php';
require_once __DIR__.'/replication-pressure.php';

/** Inspect against the selected owner's policy; never borrow another owner's. */
function zfsas_ssh_delete_preflight(array $p,callable $read,callable $readPool,?callable $readSource=null): array
{
    $job=$p['deleteJob'];$request=$p['replication'] ?? $p['pressure']['replication'] ?? [];
    $inspection=$p['inspection'] ?? $p['pressure']['inspection'] ?? [];
    $policy=$p['cleanupPolicy'] ?? $p['pressure']['policy'] ?? [];
    $readSource ??= [ZfsasReplicationInspection::class,'command'];
    if (($request['transport'] ?? '')!=='ssh' || ($job['DELETE_SCOPE'] ?? '')!=='destination_checkpoint'
        || ($job['DATASET'] ?? '')!==($request['destination'] ?? '') || ($job['DATASET_GUID'] ?? '')!==($inspection['destinationDatasetGuid'] ?? null)
        || ($job['SEND_CONFIG_HASH'] ?? '')!==($policy['sendConfigHash'] ?? null)) {throw new InvalidArgumentException('Remote cleanup lacks captured replication authority.');}
    if (trim($read(['get','-H','-o','value','receive_resume_token','--',$job['DATASET']]))!=='-') {throw new InvalidArgumentException('Receiver recovery protects its checkpoints.');}
    foreach($inspection['references'] as $reference) {
        if (ZfsasEndpointIdentity::mayOverlap($reference['endpoint'] ?? null,$p['endpoint'] ?? null)
            && ($reference['snapshot']===$job['SNAPSHOT'] || $reference['guid']===$job['SNAPSHOT_GUID'])) {throw new InvalidArgumentException('Snapshot is a protected replication checkpoint.');}
        $reader=($reference['endpoint'] ?? 'local')==='local'?$readSource:$read;
        if(trim($reader(['get','-H','-p','-o','value','guid','--',$reference['snapshot']]))!==$reference['guid']) {throw new InvalidArgumentException('A protected replication reference changed.');}
    }
    $hashQuery=['list','-H','-p','-t','snapshot','-o','name,guid,createtxg,creation,userrefs,clones','-d','1','--',$job['DATASET']];
    $before=$read($hashQuery);
    $pressure=isset($p['pressure']);
    $candidates=$pressure?zfsas_replication_anchor_candidates($request,$inspection,$policy,$read):zfsas_replication_cleanup($request,$inspection,$policy,$read);
    $eligible=false;
    foreach($candidates as $candidate) {
        $candidate=$candidate['parameters']['deleteJob'];
        if($candidate['SNAPSHOT']===$job['SNAPSHOT'] && $candidate['SNAPSHOT_GUID']===$job['SNAPSHOT_GUID']
            && $candidate['SNAPSHOT_CREATETXG']===$job['SNAPSHOT_CREATETXG'] && $candidate['SNAPSHOT_EPOCH']===$job['SNAPSHOT_EPOCH']) {$eligible=true;break;}
    }
    if(!$eligible) {return ['outcome'=>'success','itemState'=>'skipped','message'=>'Snapshot is no longer eligible under this cleanup approval.'];}
    if($pressure) {
        $required=$p['pressure']['requiredBytes'];
        $available=trim($read(['get','-H','-p','-o','value','available','--',$job['DATASET']]));
        if(!ctype_digit($available)||strlen($available)>18) {throw new InvalidArgumentException('Incomplete receiver available-space measurement.');}
        if((int)$available>=$required) {return ['outcome'=>'success','itemState'=>'skipped','message'=>'Space target is already met; retained anchor preserved.'];}
        zfsas_replication_pressure_capacity($job['DATASET'],$required,$read);
        $freeing=trim($readPool(['get','-H','-p','-o','value','freeing',$job['DELETE_POOL']]));
        if(!ctype_digit($freeing)) {throw new InvalidArgumentException('Incomplete receiver freeing measurement.');}
        if(trim($freeing,'0')!=='') {return ['outcome'=>'wait','reason'=>'space','delay'=>5,'availableBytes'=>(int)$available,'requiredBytes'=>$required,'message'=>'Waiting for receiver ZFS freeing before another deletion.'];}
    }
    if($read($hashQuery)!==$before) {throw new InvalidArgumentException('Receiver cleanup inventory changed during inspection.');}
    return ['inventoryHash'=>hash('sha256',rtrim($before,"\n"))];
}

/** Receiver holds its gates before a fresh local authorization is sent on stdin. */
function zfsas_ssh_delete_execute(ZfsasSshReceiver $receiver,string $attempt,array $job,string $inventoryHash,callable $authorize): array
{
    $command=array_merge(['/usr/bin/timeout','--foreground','--signal=TERM','--kill-after=2','120'],$receiver->deletion($attempt,$job,$inventoryHash));
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process)) {throw new RuntimeException('Cannot start owned receiver deletion.');}
    foreach($pipes as $pipe){stream_set_blocking($pipe,false);}
    $output='';$diagnostic='';$authorized=false;$exit=null;
    try {
        do {
            $output.=stream_get_contents($pipes[1],8192);$diagnostic.=stream_get_contents($pipes[2],8192);
            if(strlen($output)>8192||strlen($diagnostic)>65536) {throw new RuntimeException('Receiver deletion exceeded diagnostic limits.');}
            if(!$authorized && str_contains($output,"\n")) {
                if($output!=="ready\n") {throw new RuntimeException('Invalid receiver deletion handshake.');}
                $decision=$authorize();
                // A fresh policy check may preserve the snapshot after the
                // receiver has acquired its gates. Never send a grant then.
                if(is_array($decision)) {return $decision;}
                $grant='destroy:'.$job['SNAPSHOT_GUID']."\n";
                if(fwrite($pipes[0],$grant)!==strlen($grant)||!fflush($pipes[0])) {throw new RuntimeException('Receiver deletion authorization was not delivered.');}
                fclose($pipes[0]);unset($pipes[0]);$authorized=true;
            }
            $status=proc_get_status($process);
            if(!$status['running'] && $exit===null){$exit=$status['exitcode'];}
            if(!$status['running'] && feof($pipes[1]) && feof($pipes[2])){break;}
            $read=[$pipes[1],$pipes[2]];$write=$except=null;@stream_select($read,$write,$except,0,100000);
        }while(true);
        if($exit!==0 || !$authorized || $output!=="ready\ndeleted\n") {throw new ZfsasReplicationCommandError('Receiver deletion did not return verified completion.',$diagnostic,(int)$exit);}
        return ['outcome'=>'success','itemState'=>'completed','message'=>'Receiver snapshot deleted under its captured cleanup approval.'];
    } finally {
        foreach($pipes as $pipe){fclose($pipe);}proc_terminate($process);proc_close($process);
    }
}
