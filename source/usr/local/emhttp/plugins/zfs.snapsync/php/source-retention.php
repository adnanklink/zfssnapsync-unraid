<?php
require_once __DIR__.'/source-retention-policy.php';
require_once __DIR__.'/replication-membership.php';

function zfsas_source_decimal(string $a,string $b): int { return strlen($a)<=>strlen($b) ?: strcmp($a,$b); }
/** One bounded inventory per dataset, including explicit property provenance. */
function zfsas_source_inventory(string $dataset,callable $read): array
{
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:+-]*(?:\/[A-Za-z0-9_.:+-]+)*$/D',$dataset)) { throw new InvalidArgumentException('Invalid source dataset.'); }
    $text=$read(['list','-H','-p','-o','name,guid,createtxg,userrefs,clones','-t','snapshot','-d','1','--',$dataset]);
    if (strlen($text)>8*1048576) { throw new RuntimeException('Source snapshot inventory exceeds the bounded limit.'); }
    $rows=[];
    foreach (explode("\n",rtrim($text,"\n")) as $line) {
        if ($line==='') { continue; }
        $f=explode("\t",$line);
        if (count($f)!==5 || !str_starts_with($f[0],$dataset.'@') || isset($rows[$f[0]])
            || !preg_match('/^[0-9]{1,20}$/D',$f[1]) || !ctype_digit($f[2]) || !ctype_digit($f[3])) { throw new RuntimeException('Incomplete source snapshot metadata.'); }
        if (!preg_match('/^[A-Za-z0-9_.:+-]+$/D',substr($f[0],strlen($dataset)+1))) { throw new RuntimeException('Invalid snapshot name.'); }
        $rows[$f[0]]=['snapshot'=>$f[0],'guid'=>$f[1],'txg'=>$f[2],'holds'=>$f[3],'clones'=>$f[4],'properties'=>[]];
        if (count($rows)>50000) { throw new RuntimeException('Source snapshot inventory exceeds 50,000 entries.'); }
    }
    if (!$rows) { return []; }
    $text=$read(['get','-H','-o','name,property,value,source','-r','-d','1','org.zfs.snapsync:schedule,org.zfs.snapsync:occurrence,org.zfs.snapsync:source','--',$dataset]);
    if (strlen($text)>32*1048576) { throw new RuntimeException('Source ownership inventory exceeds the bounded limit.'); }
    foreach (explode("\n",rtrim($text,"\n")) as $line) {
        $f=explode("\t",$line);
        if (count($f)!==4) { throw new RuntimeException('Incomplete source ownership metadata.'); }
        if (isset($rows[$f[0]]) && $f[3]==='local') { $rows[$f[0]]['properties'][$f[1]]=$f[2]; }
    }
    uasort($rows,static fn($a,$b)=>-zfsas_source_decimal($a['txg'],$b['txg']) ?: strcmp($a['snapshot'],$b['snapshot']));
    return $rows;
}
function zfsas_source_owned(array $row,string $job,string $datasetGuid): bool
{
    return ($row['properties']['org.zfs.snapsync:schedule'] ?? '')===$job
        && ($row['properties']['org.zfs.snapsync:source'] ?? '')===$datasetGuid
        && preg_match('/^[0-9]{1,12}$/D',$row['properties']['org.zfs.snapsync:occurrence'] ?? '')===1;
}
function zfsas_source_guid(string $name,callable $read): string
{
    $guid=trim($read(['get','-H','-p','-o','value','guid','--',$name]));
    if (!preg_match('/^[0-9]{1,20}$/D',$guid)) { throw new RuntimeException('Dataset or snapshot identity is unavailable.'); }
    return $guid;
}
/** Every configured consumer participates, including paused and foreign-prefix jobs. */
function zfsas_source_receivers(string $source,array $jobs,array $inventory,callable $read): array
{
    $receivers=[];
    foreach ($jobs as $job) {
        if ($job['source']!==$source && !(($job['children'] ?? '0')==='1' && str_starts_with($source,$job['source'].'/'))) { continue; }
        if (($job['transport'] ?? 'local')!=='local') { throw new RuntimeException('A configured remote receiver needs this source; source cleanup requires verified local receivers.'); }
        $destination=$job['destination'].substr($source,strlen($job['source']));
        if (isset($receivers[$destination])) { continue; }
        $guid=zfsas_source_guid($destination,$read);
        $token=trim($read(['get','-H','-o','value','receive_resume_token','--',$destination]));
        if ($token!=='-') { throw new RuntimeException('Receiver '.$destination.' has an unresolved resume token; source checkpoints remain protected.'); }
        $text=$read(['list','-H','-p','-o','name,guid','-t','snapshot','-d','1','--',$destination]);
        if (strlen($text)>8*1048576) { throw new RuntimeException('Receiver inventory exceeds the bounded limit.'); }
        $names=[];
        foreach (explode("\n",rtrim($text,"\n")) as $line) {
            if ($line==='') { continue; }
            $f=explode("\t",$line);
            if (count($f)!==2 || !str_starts_with($f[0],$destination.'@') || !ctype_digit($f[1]) || isset($names[$f[0]])) { throw new RuntimeException('Incomplete receiver checkpoint metadata.'); }
            $names[$f[0]]=$f[1];
        }
        $common=null;
        foreach ($inventory as $row) {
            $target=$destination.substr($row['snapshot'],strlen($source));
            if (($names[$target] ?? '')===$row['guid']) { $common=['source'=>$row['snapshot'],'snapshot'=>$target,'guid'=>$row['guid']];break; }
        }
        if (!$common) { throw new RuntimeException('Receiver '.$destination.' has no verified common checkpoint; source cleanup is deferred.'); }
        $receivers[$destination]=['dataset'=>$destination,'datasetGuid'=>$guid,'base'=>$common];
    }
    if (!$receivers) { throw new RuntimeException('No configured receiver proves this source is replicated.'); }
    return array_values($receivers);
}
/** Pure count selection: older failed checkpoints are eligible only behind verified success. */
function zfsas_source_select(array $inventory,array $p,array $receivers): array
{
    $verified=$inventory[$p['verified']['snapshot']] ?? null;
    if (!$verified || $verified['guid']!==$p['verified']['guid'] || !zfsas_source_owned($verified,$p['job']['id'],$p['sourceDatasetGuid'])) { throw new RuntimeException('The verified source checkpoint changed or lost its ownership metadata.'); }
    $owned=array_filter($inventory,fn($row)=>zfsas_source_owned($row,$p['job']['id'],$p['sourceDatasetGuid']));
    $keep=array_fill_keys(array_slice(array_keys($owned),0,$p['policy']['keep']), 'retention count');
    $keep[$verified['snapshot']]='verified checkpoint';
    foreach ($receivers as $receiver) { $keep[$receiver['base']['source']]='receiver incremental base'; }
    $candidates=[];$protected=[];$anchors=[];
    foreach ($inventory as $name=>$row) {
        if (isset($keep[$name])) { $anchors[$name]=$row['guid']; }
        if (!isset($owned[$name])) { continue; }
        $reason=$keep[$name] ?? ($row['holds']!=='0'?'ZFS hold':($row['clones']!=='-'?'clone or incomplete clone metadata':(zfsas_source_decimal($row['txg'],$verified['txg'])>=0?'newer than verified boundary':'')));
        if ($reason!=='') { $protected[]=['snapshot'=>$name,'reason'=>$reason]; }
        else { $candidates[]=$row; }
    }
    return ['candidates'=>$candidates,'protected'=>$protected,'anchors'=>$anchors,'owned'=>count($owned)];
}
function zfsas_source_plan(array $p,array $jobs,?callable $read=null): array
{
    $read ??= [ZfsasReplicationInspection::class,'command'];
    if (($p['policy']['datasets'][$p['source']] ?? '')!==$p['sourceDatasetGuid'] || $p['policy']['keep']<1) { throw new InvalidArgumentException('Source membership is not authorized for cleanup. Review source retention again.'); }
    if (zfsas_source_guid($p['source'],$read)!==$p['sourceDatasetGuid']) { throw new InvalidArgumentException('Source dataset identity changed.'); }
    $inventory=zfsas_source_inventory($p['source'],$read);
    $receivers=zfsas_source_receivers($p['source'],$jobs,$inventory,$read);
    $verifiedReceiver=false;
    foreach ($receivers as $receiver) {
        if ($receiver['dataset']===$p['destination']) {
            $target=$p['destination'].substr($p['verified']['snapshot'],strlen($p['source']));
            $verifiedReceiver=zfsas_source_guid($target,$read)===$p['verified']['guid'];
        }
    }
    if (!$verifiedReceiver) { throw new RuntimeException('The successful receiver checkpoint is no longer verified.'); }
    $selection=zfsas_source_select($inventory,$p,$receivers);$tasks=[];$guards=[];$refs=[];
    foreach ($selection['anchors'] as $name=>$guid) { $refs[]=['role'=>'base','endpoint'=>'local','dataset'=>$p['source'],'datasetGuid'=>$p['sourceDatasetGuid'],'snapshot'=>$name,'guid'=>$guid]; }
    foreach (array_chunk($refs,1000) as $i=>$chunk) {
        $id='guard-'.$i;$guards[]=$id;
        $tasks[$id]=['kind'=>'prepare','dataset'=>$p['source'],'references'=>$chunk,'parameters'=>['phase'=>'source_retention_guard','revision'=>$p['revision'],'source'=>$p['source']]];
    }
    foreach (array_chunk($selection['candidates'],50) as $i=>$chunk) {
        $tasks['delete-'.$i]=['kind'=>'delete','dataset'=>$p['source'],'dependencies'=>$guards,'parameters'=>[
            'phase'=>'source_retention_delete','revision'=>$p['revision'],'job'=>$p['job'],'policy'=>$p['policy'],
            'source'=>$p['source'],'sourceDatasetGuid'=>$p['sourceDatasetGuid'],'destination'=>$p['destination'],
            'candidates'=>$chunk,'anchors'=>$selection['anchors'],'receivers'=>$receivers]];
    }
    $tasks['finish']=['kind'=>'finalize','dataset'=>$p['source'],'parameters'=>['phase'=>'source_retention_guard','revision'=>$p['revision'],'source'=>$p['source']],'dependencies'=>array_keys($tasks)];
    return ['tasks'=>$tasks,'summary'=>['eligible'=>count($selection['candidates']),'protected'=>count($selection['protected']),
        'reasons'=>array_count_values(array_column($selection['protected'],'reason'))]];
}
/** Called under source and receiver gates and the single global deletion lock. */
function zfsas_source_delete(array $p,callable $read,callable $destroy,callable $authorize,?callable $record=null): array
{
    $record ??= static function(array $row,string $state,string $message): void {};
    $deleted=0;$skipped=(int)($p['referenceSkipped'] ?? 0);$reasons=[];
    if (count($p['candidates'])>50) { throw new InvalidArgumentException('Source cleanup chunk exceeds 50 snapshots.'); }
    if (zfsas_source_guid($p['source'],$read)!==$p['sourceDatasetGuid']) { throw new InvalidArgumentException('Source dataset identity changed.'); }
    // These anchors are registered references until the entire cleanup run ends.
    // Recheck their existence, so an external rollback cannot silently move a base.
    if ($p['anchors']) {
        $text=$read(array_merge(['get','-H','-p','-o','name,value','guid','--'],array_keys($p['anchors'])));$actual=[];
        foreach (explode("\n",rtrim($text,"\n")) as $line) {
            $f=explode("\t",$line);if (count($f)!==2 || isset($actual[$f[0]])) { throw new RuntimeException('Incomplete retained source identities.'); }$actual[$f[0]]=$f[1];
        }
        ksort($actual);$expected=$p['anchors'];ksort($expected);
        if ($actual!==$expected) { throw new RuntimeException('Retained source checkpoints changed. Cleanup stopped.'); }
    }
    foreach ($p['receivers'] as $receiver) {
        if (zfsas_source_guid($receiver['dataset'],$read)!==$receiver['datasetGuid']
            || trim($read(['get','-H','-o','value','receive_resume_token','--',$receiver['dataset']]))!=='-'
            || zfsas_source_guid($receiver['base']['snapshot'],$read)!==$receiver['base']['guid']) {
            throw new RuntimeException('Receiver identity, recovery state or incremental base changed. Cleanup stopped.');
        }
    }
    foreach ($p['candidates'] as $candidate) {
        $name=$candidate['snapshot'];
        try {
            $text=$read(['get','-H','-p','-o','property,value,source','guid,createtxg,userrefs,clones,org.zfs.snapsync:schedule,org.zfs.snapsync:occurrence,org.zfs.snapsync:source','--',$name]);
        } catch (RuntimeException $error) { $record($candidate,'skipped','Snapshot unavailable or already removed.');$skipped++;$reasons['snapshot unavailable or already removed']=($reasons['snapshot unavailable or already removed'] ?? 0)+1;continue; }
        $values=[];$local=[];
        foreach (explode("\n",rtrim($text,"\n")) as $line) {
            $f=explode("\t",$line);if (count($f)!==3 || isset($values[$f[0]])) { throw new RuntimeException('Incomplete source deletion preflight.'); }
            $values[$f[0]]=$f[1];if ($f[2]==='local') { $local[$f[0]]=$f[1]; }
        }
        if (($values['guid'] ?? '')!==$candidate['guid'] || ($values['createtxg'] ?? '')!==$candidate['txg']
            || ($values['userrefs'] ?? '')!=='0' || ($values['clones'] ?? '')!=='-'
            || !zfsas_source_owned(['properties'=>$local],$p['job']['id'],$p['sourceDatasetGuid'])
            || ($local['org.zfs.snapsync:occurrence'] ?? '')!==($candidate['properties']['org.zfs.snapsync:occurrence'] ?? '')) {
            $record($candidate,'skipped','Identity, ownership, hold or clone changed.');$skipped++;$reasons['identity, ownership, hold or clone changed']=($reasons['identity, ownership, hold or clone changed'] ?? 0)+1;continue;
        }
        // A live token acknowledgement plus current configuration precedes every
        // mutation. Registered references cannot be admitted through this grant.
        if (zfsas_source_guid($p['source'],$read)!==$p['sourceDatasetGuid']) { throw new InvalidArgumentException('Source dataset changed before deletion.'); }
        $authorize($name);
        $destroy($name);$record($candidate,'completed','Source checkpoint deleted.');$deleted++;
    }
    return ['outcome'=>'success','deleted'=>$deleted,'skipped'=>$skipped,'referenceSkipped'=>(int)($p['referenceSkipped'] ?? 0),'reasons'=>$reasons,
        'message'=>"Source cleanup: $deleted deleted; $skipped skipped. Protected checkpoints may exceed the retention count."];
}
function zfsas_source_review(array $p,array $jobs,?callable $read=null): array
{
    $read ??= [ZfsasReplicationInspection::class,'command'];
    $members=zfsas_replication_membership($p['job'],$read);$datasets=[];$rows=[];$unmanaged=0;$eligible=0;$protected=0;
    $jobs=array_values(array_filter($jobs,fn($job)=>$job['id']!==$p['job']['id']));$jobs[]=$p['job'];
    foreach ($members as $member) {
        $source=$member['source'];$guid=$member['sourceDatasetGuid'];$datasets[$source]=$guid;
        $inventory=zfsas_source_inventory($source,$read);
        $owned=array_filter($inventory,fn($row)=>zfsas_source_owned($row,$p['job']['id'],$guid));
        $unmanaged+=count($inventory)-count($owned);
        if (!$owned) { continue; }
        try {
            $receivers=zfsas_source_receivers($source,$jobs,$inventory,$read);$newest=reset($owned);
            $selection=zfsas_source_select($inventory,['job'=>$p['job'],'sourceDatasetGuid'=>$guid,'policy'=>['keep'=>$p['keep']],
                'verified'=>['snapshot'=>$newest['snapshot'],'guid'=>$newest['guid']]],$receivers);
            foreach ($selection['protected'] as $row) { $rows[]=$row+['eligible'=>false];$protected++; }
            foreach ($selection['candidates'] as $row) {
                $active=isset($p['protectedGuids'][$row['guid']]) || isset($p['protectedNames'][$row['snapshot']]);
                $rows[]=['snapshot'=>$row['snapshot'],'eligible'=>!$active,'reason'=>$active?'active or unresolved recovery reference':'eligible after a newer fully verified replication'];
                if ($active) { $protected++; } else { $eligible++; }
            }
        } catch (RuntimeException $error) {
            foreach ($owned as $row) { $rows[]=['snapshot'=>$row['snapshot'],'eligible'=>false,'reason'=>$error->getMessage()];$protected++; }
        }
        if (count($rows)>50000 || strlen(json_encode($rows,JSON_THROW_ON_ERROR))>16*1048576) { throw new RuntimeException('Source review exceeds the bounded limit. Review a smaller scope.'); }
    }
    if (count($rows)>50000 || strlen(json_encode($rows,JSON_THROW_ON_ERROR))>16*1048576) { throw new RuntimeException('Source retention review exceeds the bounded limit. Review a smaller recursive scope.'); }
    return ['state'=>'ready','expires'=>time()+300,'revision'=>$p['revision'],'binding'=>zfsas_source_binding($p['job']),
        'keep'=>$p['keep'],'datasets'=>$datasets,'rows'=>$rows,'eligible'=>$eligible,'protected'=>$protected,'unmanaged'=>$unmanaged];
}
