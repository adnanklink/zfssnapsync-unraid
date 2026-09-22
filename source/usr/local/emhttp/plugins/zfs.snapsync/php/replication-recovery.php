<?php
require_once __DIR__.'/replication-membership.php';
require_once __DIR__.'/replication-plan.php';
require_once __DIR__.'/operation-diagnostics.php';

/** One bounded receiver inventory, also handles absent recursive receiver parents. */
function zfsas_recovery_receivers(array $job, ?callable $read=null): array
{
    $read ??= [ZfsasReplicationInspection::class,'command'];
    $pool=explode('/',$job['destination'])[0];$rows=[];
    $text=$read(['list','-H','-p','-o','name,guid','-t','filesystem,volume','-r','--',$pool]);
    if(strlen($text)>8*1048576)throw new RuntimeException('Receiver inventory exceeds the inspection limit.');
    foreach(explode("\n",trim($text)) as $line){
        $f=explode("\t",$line);
        if(count($f)!==2||!preg_match('/^[0-9]{1,20}$/D',$f[1])||isset($rows[$f[0]]))throw new RuntimeException('Receiver identity inventory is incomplete.');
        $rows[$f[0]]=$f[1];
    }
    if(!isset($rows[$pool]))throw new RuntimeException('Receiver pool identity is unavailable.');
    return $rows;
}
function zfsas_recovery_preflight(array $job,array $members,?callable $read=null): ?array
{
    $read ??= [ZfsasReplicationInspection::class,'command'];$receivers=zfsas_recovery_receivers($job,$read);$blocked=[];
    foreach($members as $m){
        if(!isset($receivers[$m['destination']]))continue;
        $token=trim($read(['get','-H','-o','value','receive_resume_token','--',$m['destination']]));
        if($token==='')throw new RuntimeException('Receiver interruption metadata is unavailable.');
        if($token!=='-')$blocked[]=['source'=>$m['source'],'destination'=>$m['destination']];
    }
    if(!$blocked)return null;
    $keys=array_map(static fn($m)=>$m['source'].'|'.$m['destination'],$blocked);sort($keys,SORT_STRING);
    return ['blockedDigest'=>hash('sha256',json_encode($keys,JSON_THROW_ON_ERROR)),'outcome'=>'validation_failure','failureCode'=>'interrupted_receive','recoveryRequired'=>true,
        'message'=>'An earlier transfer is unfinished at the destination. Review recovery before sending another snapshot.',
        'blockedReceivers'=>array_slice($blocked,0,20),'blockedCount'=>count($blocked)];
}
function zfsas_recovery_inspect_member(array $p,?callable $read=null): array
{
    $read ??= [ZfsasReplicationInspection::class,'command'];
    $base=['source'=>$p['source'],'destination'=>$p['destination'],'eligible'=>false];
    try{
        if(array_key_exists('receiverGuid',$p)){
            $receivers=[];
            if($p['receiverGuid']!==null)$receivers[$p['destination']]=$p['receiverGuid'];
            if($p['receiverParentGuid']!==null)$receivers[substr($p['destination'],0,strrpos($p['destination'],'/'))]=$p['receiverParentGuid'];
        }else $receivers=zfsas_recovery_receivers(['destination'=>$p['destination']],$read);
        $guid=trim($read(['get','-H','-p','-o','value','guid','--',$p['source']]));
        if($guid!==$p['sourceDatasetGuid'])throw new InvalidArgumentException('Source dataset identity changed. Review the configuration.');
        $token=isset($receivers[$p['destination']])?trim($read(['get','-H','-o','value','receive_resume_token','--',$p['destination']])):'-';
        if($token==='')throw new RuntimeException('Receiver interruption metadata is unavailable.');
        $snapshot=$p['snapshot']['snapshot'] ?? ''; $snapshotGuid=$p['snapshot']['guid'] ?? '';
        if($token!=='-'){
            $fields=[];
            foreach(explode("\n",$read(['send','-nvt',$token])) as $line){
                if(preg_match('/^\s*(toname|toguid|fromguid)\s*=\s*(\S+)\s*$/D',$line,$m)){
                    if(isset($fields[$m[1]]))throw new InvalidArgumentException('Conflicting interrupted snapshot identities.');
                    $fields[$m[1]]=$m[2];
                }
            }
            $snapshot=$fields['toname'] ?? '';
            if(!str_starts_with($snapshot,$p['source'].'@'))throw new InvalidArgumentException('The interrupted transfer belongs to a different source dataset.');
            $snapshotGuid=trim($read(['get','-H','-p','-o','value','guid','--',$snapshot]));
        }
        if($snapshot==='')return $base+['message'=>'No interrupted transfer or retained original snapshot is available. No new snapshot will be created.'];
        $request=['sourceSnapshot'=>$snapshot,'sourceGuid'=>$snapshotGuid,'destination'=>$p['destination'],'allowResume'=>true];
        if(isset($receivers[$p['destination']]))$request['destinationGuid']=$receivers[$p['destination']];
        else{
            $parent=substr($p['destination'],0,strrpos($p['destination'],'/'));
            if(!isset($receivers[$parent]))throw new InvalidArgumentException('Receiver parent is absent. Recover its parent first, then review again.');
            $request+=['createDestination'=>true,'destinationParentGuid'=>$receivers[$parent]];
        }
        $result=ZfsasReplicationInspection::inspect($request,$read);
        if($result['outcome']!=='success')throw new InvalidArgumentException($result['message']);
        if($result['inspection']['sourceDatasetGuid']!==$p['sourceDatasetGuid'])throw new InvalidArgumentException('Source identity changed during recovery review.');
        return array_replace($base,['eligible'=>true,'request'=>$request,'inspection'=>$result['inspection'],'snapshot'=>$snapshot,
            'message'=>$result['inspection']['mode']==='already_received'?'Already received; verify without retransmitting.':($token!=='-'?'Resume this original interrupted snapshot.':'Retry this retained original snapshot.')]);
    }catch(Throwable $error){return $base+['message'=>zfsas_diagnostic_text($error->getMessage()).(!empty($error->diagnostic)?' ZFS: '.zfsas_diagnostic_text($error->diagnostic):'')];}
}
