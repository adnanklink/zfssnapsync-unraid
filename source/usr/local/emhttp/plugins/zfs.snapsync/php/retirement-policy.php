<?php
require_once __DIR__.'/send-helpers.php';
require_once __DIR__.'/replication-receiver-context.php';

function zfsas_retirement_dataset(string $dataset): void
{
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:+-]*(?:\/[A-Za-z0-9_.:+-]+)*$/D',$dataset)) throw new InvalidArgumentException('Choose a valid dataset.');
}
function zfsas_retirement_overlap(string $a,string $b): bool
{
    return $a===$b || str_starts_with($a,$b.'/') || str_starts_with($b,$a.'/');
}
/** No inventory or mutation: show precisely which saved settings would change. */
function zfsas_retirement_inspect(array $pair,string $dataset): array
{
    zfsas_retirement_dataset($dataset);
    $errors=[];$warnings=[];$configuredJobs=zfsas_send_parse_jobs($pair['send']['SEND_JOBS'] ?? '',$errors,$warnings);
    if($errors||$warnings)throw new InvalidArgumentException('Replication configuration needs review before retirement: '.implode(' ',array_merge($errors,$warnings)));
    $jobs=[];$conflicts=[];$targets=[['dataset'=>$dataset,'transport'=>'local','role'=>'source']];
    foreach(zfsas_send_parse_jobs($pair['send']['SEND_JOBS'] ?? '') as $job){
        if($job['source']===$dataset){
            if(($job['children'] ?? '0')==='1'){$conflicts[]='Job '.$job['id'].' includes children. Edit that job separately before retiring only this dataset.';continue;}
            $jobs[]=$job;$targets[]=['dataset'=>$job['destination'],'transport'=>$job['transport'] ?? 'local','role'=>'destination'];
        }elseif(($job['children'] ?? '0')==='1' && str_starts_with($dataset,$job['source'].'/')){
            $conflicts[]='Recursive job '.$job['id'].' also protects this dataset. Resolve its scope separately.';
        }
        if(($job['transport'] ?? 'local')==='local' && zfsas_retirement_overlap($job['destination'],$dataset)){
            $conflicts[]='Dataset is a receiver for job '.$job['id'].'. Retire that source relationship separately.';
        }
    }
    foreach($targets as $target)if($target['role']==='destination'&&$target['transport']==='local')foreach(explode(',',$pair['auto']['DATASETS'] ?? '') as $entry){
        $configured=preg_replace('/:[^:]*$/','',trim($entry));
        if($configured!==''&&($configured===$target['dataset']||str_starts_with($target['dataset'],$configured.'/')))$conflicts[]='Destination '.$target['dataset'].' remains managed by Automatic snapshots. Remove that selection separately.';
    }
    // A receiver shared with another job must not be treated as retired.
    $ids=array_column($jobs,'id');
    foreach($targets as $target)if($target['role']==='destination'){
        if($target['transport']==='local' && zfsas_retirement_overlap($dataset,$target['dataset']))$conflicts[]='Source and destination overlap; resolve the job configuration first.';
        foreach(zfsas_send_parse_jobs($pair['send']['SEND_JOBS'] ?? '') as $job){
            if(in_array($job['id'],$ids,true))continue;
            if(($job['transport'] ?? 'local')===$target['transport'] && zfsas_retirement_overlap($target['dataset'],$job['destination']))$conflicts[]='Destination '.$target['dataset'].' is also used by job '.$job['id'].'.';
            if($target['transport']==='local' && zfsas_retirement_overlap($target['dataset'],$job['source']))$conflicts[]='Destination '.$target['dataset'].' is a source for job '.$job['id'].'.';
        }
    }
    $auto=false;
    foreach(explode(',',$pair['auto']['DATASETS'] ?? '') as $entry)if(preg_replace('/:[^:]*$/','',trim($entry))===$dataset)$auto=true;
    foreach(explode(',',$pair['auto']['DATASETS'] ?? '') as $entry){
        $configured=preg_replace('/:[^:]*$/','',trim($entry));
        if($configured!=='' && str_starts_with($dataset,$configured.'/'))$conflicts[]='Auto Snapshot cleanup from ancestor '.$configured.' can still manage this dataset. Resolve that scope separately.';
    }
    return ['dataset'=>$dataset,'revision'=>$pair['revision'],'auto'=>$auto,'jobs'=>$jobs,'targets'=>$targets,'conflicts'=>array_values(array_unique($conflicts))];
}
function zfsas_retirement_replace(string $raw,string $key,string $value): string
{
    $line=$key.'='.zfsas_send_quote_config_string($value);
    $pattern='/^'.preg_quote($key,'/').'\s*=.*$/m';
    return preg_match($pattern,$raw)?preg_replace_callback($pattern,static fn()=>$line,$raw):rtrim($raw,"\n")."\n".$line."\n";
}
/** Hold the shared configuration lock across both writes; never change cadence. */
function zfsas_retirement_stop(string $dir,string $dataset,string $revision,string $sync): array
{
    $lock=zfsas_config_lock($dir);
    if(!$lock || !flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock configuration.');
    try{
        $pair=['auto'=>zfsas_send_parse_config_file($dir.'/zfs_snapsync.conf',zfsas_auto_defaults()),'send'=>zfsas_send_parse_config_file($dir.'/zfs_send.conf',zfsas_send_defaults()),'revision'=>zfsas_config_revision($dir)];
        if(!hash_equals($pair['revision'],$revision))throw new InvalidArgumentException('Settings changed. Inspect retirement again.');
        $plan=zfsas_retirement_inspect($pair,$dataset);
        if($plan['conflicts'])throw new InvalidArgumentException(implode(' ',$plan['conflicts']));
        $oldAuto=(string)file_get_contents($dir.'/zfs_snapsync.conf');$oldSend=(string)file_get_contents($dir.'/zfs_send.conf');
        $entries=array_filter(explode(',',$pair['auto']['DATASETS']),static fn($entry)=>preg_replace('/:[^:]*$/','',trim($entry))!==$dataset);
        $jobs=array_values(array_filter(zfsas_send_parse_jobs($pair['send']['SEND_JOBS']),static fn($job)=>!in_array($job['id'],array_column($plan['jobs'],'id'),true)));
        $auto=zfsas_retirement_replace($oldAuto,'DATASETS',implode(',',$entries));
        $send=zfsas_retirement_replace($oldSend,'SEND_JOBS',zfsas_send_render_jobs_string($jobs));
        if(!zfsas_send_write_config_atomically($dir.'/zfs_snapsync.conf',$auto))throw new RuntimeException('Could not save Auto Snapshot changes.');
        if(!zfsas_send_write_config_atomically($dir.'/zfs_send.conf',$send)){
            $restored=zfsas_send_write_config_atomically($dir.'/zfs_snapsync.conf',$oldAuto);
            throw new RuntimeException($restored?'Could not remove replication jobs; settings were restored.':'Could not remove replication jobs. Auto settings may already have changed; reload before continuing.');
        }
        $lines=[];$code=0;exec('ZFSAS_CONFIG_LOCK_HELD=1 '.escapeshellarg($sync).' 2>&1',$lines,$code);
        return $plan+['saved'=>true,'schedulerApplied'=>$code===0,'savedRevision'=>zfsas_config_revision($dir),'message'=>$code===0?'Automation stopped. Review snapshot cleanup next.':'Settings saved, but scheduler application failed. Cleanup is blocked until scheduler application succeeds.'];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
/** Prefixes classify the initial selection, never authorize a deletion. */
function zfsas_retirement_inventory(array $target,callable $read,array $prefixes): array
{
    $dataset=$target['dataset'];zfsas_retirement_dataset($dataset);
    $datasetGuid=trim($read(['get','-H','-p','-o','value','guid','--',$dataset]));
    if(!preg_match('/^[0-9]{1,20}$/D',$datasetGuid))throw new RuntimeException('Dataset identity is unavailable: '.$dataset);
    if(trim($read(['get','-H','-o','value','receive_resume_token','--',$dataset]))!=='-')throw new RuntimeException('Interrupted receive on '.$dataset.'. Resolve recovery before retirement cleanup.');
    $text=$read(['list','-H','-p','-t','snapshot','-o','name,guid,createtxg,creation,userrefs,clones','-d','1','--',$dataset]);
    if(strlen($text)>8*1048576)throw new RuntimeException('Snapshot inventory exceeds the review limit.');
    $rows=[];
    foreach(explode("\n",rtrim($text,"\n")) as $line){
        if($line==='')continue;$f=explode("\t",$line);
        if(count($f)!==6 || !str_starts_with($f[0],$dataset.'@') || !preg_match('/^[0-9]{1,20}$/D',$f[1]) || !ctype_digit($f[2]) || !ctype_digit($f[3]) || !ctype_digit($f[4]) || $f[5]==='')throw new RuntimeException('Incomplete snapshot metadata.');
        $name=substr($f[0],strlen($dataset)+1);if(!preg_match('/^[A-Za-z0-9_.:+-]+$/D',$name))throw new RuntimeException('Invalid snapshot identity.');
        $managed=false;foreach($prefixes as $prefix)if($prefix!==''&&str_starts_with($name,$prefix))$managed=true;
        $reason=$f[4]!=='0'?'Snapshot is held; release its hold separately.':($f[5]!=='-'?'Snapshot has clone dependencies.':'');
        $key=hash('sha256',($target['endpoint'] ?? 'local')."\0".$f[0]."\0".$f[1]);
        if(isset($rows[$key]))throw new RuntimeException('Duplicate snapshot identity.');
        $rows[$key]=['key'=>$key,'snapshot'=>$f[0],'guid'=>$f[1],'txg'=>$f[2],'created'=>$f[3],'datasetGuid'=>$datasetGuid,'managed'=>$managed,'reason'=>$reason,'selected'=>$managed&&$reason===''];
        if(count($rows)>50000)throw new RuntimeException('Too many snapshots for one retirement review.');
    }
    $held=array_values(array_filter($rows,static fn($row)=>str_starts_with($row['reason'],'Snapshot is held')));
    foreach(array_chunk($held,100) as $chunk){
        $tags=[];
        try{
            $output=$read(array_merge(['holds','-H'],array_column($chunk,'snapshot')));
            if(strlen($output)>1048576)throw new RuntimeException('Hold inventory too large.');
            foreach(explode("\n",trim($output)) as $line){$fields=explode("\t",$line);if(count($fields)>=2)$tags[$fields[0]][]=$fields[1];}
        }catch(Throwable $error){/* Keep the hold exclusion when tag inspection fails. */}
        foreach($chunk as $row){
            $names=$tags[$row['snapshot']] ?? [];$external=array_values(array_diff($names,['snapsync-manual']));
            if($external)$rows[$row['key']]['reason']='External ZFS hold: '.implode(', ',array_slice($external,0,5)).'. Release it with its owning tool on this host before reviewing again.';
            elseif(in_array('snapsync-manual',$names,true))$rows[$row['key']]['reason']='SnapSync hold. Use Protection → Release plugin hold on this host, then review again.';
            else $rows[$row['key']]['reason']='ZFS hold; tag unavailable. Inspect holds on this host before reviewing again.';
        }
    }
    return ['target'=>array_replace($target,['datasetGuid'=>$datasetGuid]),'rows'=>$rows,'inventoryHash'=>hash('sha256',rtrim($text,"\n"))];
}
