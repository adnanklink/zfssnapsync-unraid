<?php
require_once __DIR__.'/replication-ssh-receiver.php';
require_once __DIR__.'/replication-plan.php';
require_once __DIR__.'/send-queue-helpers.php';
require_once __DIR__.'/transfer-progress.php';

function zfsas_replication_ssh_phase(array $p,int &$sequence,callable $report): array
{
    $capture=$p['receiverCapture'] ?? [];$identity=$capture['identity'] ?? [];
    $reader=new ZfsasSshReceiverRead($capture['config'] ?? [],$identity['pool'] ?? '',[],$identity);
    $receiver=new ZfsasSshReceiver($capture);$endpoint=$reader->identity()['endpoint'];
    if ($endpoint==='local' || $endpoint!==($p['endpoint'] ?? '')) {throw new InvalidArgumentException('Receiver endpoint changed after planning.');}
    $phase=$p['phase'];$request=$p['replication'];
    if (!in_array($phase,['replication_space','replication_transfer','replication_verify'],true)) {throw new InvalidArgumentException('Invalid SSH replication phase.');}
    if ($phase!=='replication_space' && empty($p['remoteOwnership'])) {throw new InvalidArgumentException('Remote mutation lacks coordinator shutdown ownership.');}
    $locks=zfsas_ops_dataset_gates($request['destination'],$endpoint);
    if ($locks===false) {return ['outcome'=>'wait','reason'=>'resource','delay'=>1,'message'=>'Another operation owns this receiver dataset.'];}
    try {
        $result=zfsas_replication_revalidate($p,null,[$reader,'read'],$endpoint);
        if ($result['outcome']!=='success') {return $result;}
        $complete=$result['inspection']['mode']==='already_received';
        if ($phase==='replication_verify' && !$complete) {throw new InvalidArgumentException('Expected receiver checkpoint is absent.');}
        $readonly=($request['purpose'] ?? 'backup')==='restore'?'off':'on';
        $attempt=(string)getenv('ZFSAS_ATTEMPT_TOKEN');
        if ($phase!=='replication_space' && $result['inspection']['destinationDatasetGuid']!==null) {
            $report('progress',$sequence++,['phase'=>'receiver_policy','message'=>'Applying the captured receiver protection policy.']);
            zfsas_replication_ssh_command(array_merge(['/usr/bin/timeout','--foreground','--kill-after=2','20'],
                $receiver->mutation($attempt,'readonly',$request['destination'],$result['inspection']['destinationDatasetGuid'],$readonly)),$sequence,$report);
        }
        if ($phase==='replication_verify') {
            if (trim($reader->read(['get','-H','-o','value','readonly','--',$request['destination']]))!==$readonly) {throw new RuntimeException('Receiver readonly policy could not be verified.');}
            $result['message']='Verified receiver snapshot, dataset identity and protection policy.';return $result;
        }
        if ($complete) {return $result;}
        $space=zfsas_replication_space($p,null,[$reader,'poolRead'],[$reader,'read']);
        if ($space['outcome']!=='success' || $phase==='replication_space') {return $space;}
        $rate=$p['rateLimit'] ?? '0';
        if (zfsas_send_normalize_rate_limit($rate)===null || ($rate!=='0' && !trim((string)shell_exec('command -v mbuffer 2>/dev/null')))) {throw new InvalidArgumentException('Configured transfer rate requires a valid rate and installed mbuffer.');}
        $resume=$p['inspection']['mode']==='resume'?zfsas_replication_resume_token($p,[$reader,'read']):'';
        $expected=$result['inspection']['destinationDatasetGuid'] ?? 'absent:'.$request['destinationParentGuid'];
        $ssh=$receiver->mutation($attempt,'receive',$request['destination'],$expected,$readonly);
        $script='if [[ -n "$4" ]]; then zfs send -vP -t "$4"; elif [[ -n "$1" ]]; then zfs send -vP -i "$1" "$2"; else zfs send -vP "$2"; fi | { if [[ "$3" == 0 ]]; then cat; else mbuffer -q -R "$3"; fi; } | '.implode(' ',array_map('escapeshellarg',$ssh));
        $report('progress',$sequence++,['phase'=>'transfer','message'=>'Transferring the captured snapshot to the verified SSH receiver.']);
        zfsas_replication_ssh_command(['/bin/bash','-o','pipefail','-c',$script,'snapsync-ssh-transfer',
            $p['inspection']['base']['snapshot'] ?? '',$request['sourceSnapshot'],$rate,$resume],$sequence,$report);
        $report('progress',$sequence++,['phase'=>'verification','message'=>'Verifying the receiver checkpoint after transfer.']);
        $result=zfsas_replication_revalidate($p,null,[$reader,'read'],$endpoint);
        if ($result['outcome']==='success' && $result['inspection']['mode']!=='already_received') {throw new InvalidArgumentException('SSH pipeline ended without the expected receiver checkpoint.');}
        return $result;
    } finally {foreach($locks as $lock){fclose($lock);}}
}

function zfsas_replication_ssh_command(array $command,int &$sequence,callable $report): void
{
    $process=proc_open($command,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) {throw new RuntimeException('Cannot start owned receiver operation.');}
    $diagnostic='';$meter=new ZfsasTransferProgress();
    try {
        while (!feof($pipes[2])) {
            $line=fgets($pipes[2],8192);if ($line===false){break;}
            $diagnostic=substr($diagnostic.$line,-4096);
            $progress=$meter->sample($line,hrtime(true)/1e9);
            if ($progress!==null) {$report('progress',$sequence++,$progress);}
        }
    } finally {fclose($pipes[2]);$code=proc_close($process);}
    if ($code!==0) {throw new ZfsasReplicationCommandError('SSH operation failed; receiver shutdown and recovery must be verified.',$diagnostic,$code);}
}
