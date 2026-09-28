<?php
require_once __DIR__.'/replication-inspection.php';
require_once __DIR__.'/send-helpers.php';
require_once __DIR__.'/snapshot-cleanup.php';

/** Re-evaluate the established retention ordering against the gated inventory. */
function zfsas_auto_policy_candidate(array $rows,array $proposal,array $auto): bool
{
    $prefix=$auto['PREFIX'];
    $rows=array_filter($rows,static fn($row,$name)=>str_starts_with(explode('@',$name)[1],$prefix),ARRAY_FILTER_USE_BOTH);
    uksort($rows,static fn($a,$b)=>(int)$rows[$b]['created']<=>(int)$rows[$a]['created'] ?: strcmp($a,$b));
    $newest=array_key_first($rows);
    if($newest===$proposal['snapshot'] || $newest===null)return false;
    if($proposal['reason']==='space_pressure') {
        $ascending=$rows;uksort($ascending,static fn($a,$b)=>(int)$rows[$a]['created']<=>(int)$rows[$b]['created'] ?: strcmp($a,$b));
        $start=null;
        foreach($ascending as $name=>$row) {
            if($name===$newest || $row['holds']!=='0' || $row['clones']!=='-') {$start=null;continue;}
            $start ??= $name;
            if(ctype_digit($row['used']) && (int)$row['used']>0)return $start===$proposal['snapshot'];
        }
        return false;
    }
    $zero=false;$days=[];$weeks=[];$now=time();
    foreach($rows as $name=>$row) {
        $written=$row['written'];$reason='';
        if($name===$newest){$zero=$written==='0';continue;}
        if($written==='0' && $zero) {$reason='zero_change_housekeeping';}
        else {
            $zero=$written==='0';$age=$now-(int)$row['created'];
            if($age>(int)$auto['KEEP_WEEKLY_UNTIL_DAYS']*86400)$reason='age_window';
            elseif($age>(int)$auto['KEEP_DAILY_UNTIL_DAYS']*86400) {
                $key=zfsas_sm_retention_week((int)$row['created']);
                if(isset($weeks[$key]))$reason='weekly_consolidation';$weeks[$key]=true;
            } elseif($age>(int)$auto['KEEP_ALL_FOR_DAYS']*86400) {
                $key=date('Y-m-d',(int)$row['created']);
                if(isset($days[$key]))$reason='daily_consolidation';$days[$key]=true;
            }
        }
        if($name===$proposal['snapshot'])return $reason===$proposal['reason'];
    }
    return false;
}

function zfsas_auto_inventory(string $dataset,?callable $read=null): array
{
    $read ??= [ZfsasReplicationInspection::class,'command'];
    $text=$read(['list','-H','-p','-t','snapshot','-o','name,guid,createtxg,creation,userrefs,clones,used,written','-r','--',$dataset]);
    $rows=[];
    foreach(explode("\n",trim($text)) as $line) {
        if($line==='')continue;
        $fields=explode("\t",$line);
        if(count($fields)!==8) {throw new InvalidArgumentException('Incomplete Auto mutation inventory.');}
        [$name,$guid,$txg,$created,$holds,$clones,$used,$written]=$fields;
        $source=explode('@',$name)[0];
        if(!str_contains($name,'@') || ($source!==$dataset && !str_starts_with($source,$dataset.'/'))
            || !preg_match('/^[0-9]{1,20}$/D',$guid) || !ctype_digit($txg) || !ctype_digit($created)
            || !ctype_digit($holds) || isset($rows[$name]) || count($rows)>=50000) {
            throw new InvalidArgumentException('Invalid Auto mutation inventory identity.');
        }
        $rows[$name]=['guid'=>$guid,'txg'=>$txg,'created'=>$created,'holds'=>$holds,'clones'=>$clones,'used'=>$used,'written'=>$written];
    }
    ksort($rows,SORT_STRING);
    return ['hash'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR)),'rows'=>$rows];
}

/** Preserve the legacy minimum of pool, ancestor quota and dataset refquota. */
function zfsas_auto_pressure_needed(array $pressure,string $candidate,?callable $read=null,?callable $poolRead=null): bool
{
    $read ??= [ZfsasReplicationInspection::class,'command'];
    $poolRead ??= [ZfsasReplicationInspection::class,'poolCommand'];
    $dataset=$pressure['dataset'];$pool=explode('/',$dataset)[0];
    $number=static function(string $text):int {
        $text=trim($text);
        if(!ctype_digit($text) || strlen($text)>18)throw new InvalidArgumentException('Incomplete Auto capacity measurement.');
        return (int)$text;
    };
    $available=$number($read(['list','-H','-p','-o','avail','--',$pool]));
    $freeing=trim($poolRead(['get','-H','-p','-o','value','freeing',$pool]));
    $constraints=['pool:'.$pool=>$available+($freeing==='-'?0:$number($freeing))];
    for($scope=$dataset;;$scope=substr($scope,0,strrpos($scope,'/'))) {
        $quota=$number($read(['get','-H','-p','-o','value','quota','--',$scope]));
        if($quota>0) {$constraints['quota:'.$scope]=max(0,$quota-$number($read(['get','-H','-p','-o','value','used','--',$scope])));}
        if($scope===$pool)break;
    }
    $refquota=$number($read(['get','-H','-p','-o','value','refquota','--',$dataset]));
    if($refquota>0) {$constraints['refquota:'.$dataset]=max(0,$refquota-$number($read(['get','-H','-p','-o','value','referenced','--',$dataset])));}
    $minimum=min($constraints);
    if($minimum>=$pressure['requiredBytes'])return false;
    foreach($constraints as $key=>$bytes) {
        if($bytes!==$minimum)continue;
        if(str_starts_with($key,'pool:') && explode('/',$candidate)[0]===substr($key,5))return true;
        if(str_starts_with($key,'quota:') && ($candidate===substr($key,6)||str_starts_with($candidate,substr($key,6).'/')))return true;
    }
    return false;
}

function zfsas_auto_mutation_check(array $p,?callable $read=null,?callable $poolRead=null,?array $config=null): array
{
    $read ??= [ZfsasReplicationInspection::class,'command'];
    $proposal=$p['autoMutation']['proposal'];$snapshot=$proposal['snapshot'];$dataset=explode('@',$snapshot)[0];
    if(trim($read(['get','-H','-p','-o','value','guid','--',$dataset]))!==$proposal['datasetGuid']) {
        throw new InvalidArgumentException('Auto dataset identity changed before mutation.');
    }
    $inventory=zfsas_auto_inventory($proposal['policyDataset'],$read);
    if(!hash_equals($proposal['inventoryHash'],$inventory['hash'])) {
        throw new InvalidArgumentException('Auto inventory changed during mutation handoff; remaining work requires a fresh plan.');
    }
    if($proposal['action']==='snapshot') {
        if(isset($inventory['rows'][$snapshot]))throw new InvalidArgumentException('Auto snapshot target is already occupied.');
        return ['ready'=>true];
    }
    $row=$inventory['rows'][$snapshot] ?? null;
    if(!$row || $row['guid']!==$proposal['guid'] || $row['txg']!==$proposal['txg'] || $row['holds']!=='0' || $row['clones']!=='-') {
        throw new InvalidArgumentException('Auto snapshot identity or protection changed.');
    }
    $config ??= zfsas_config_read_pair('/boot/config/plugins/zfs.snapsync');
    $name=explode('@',$snapshot)[1];
    $prefixes=array_merge([$config['send']['SEND_SNAPSHOT_PREFIX']],explode("\n",$config['prefixHistory'] ?? ''));
    foreach($prefixes as $prefix)if($prefix!=='' && str_starts_with($name,$prefix))throw new InvalidArgumentException('Auto cleanup cannot delete a replication checkpoint.');
    if(!zfsas_auto_policy_candidate($inventory['rows'],$proposal,$config['auto']))throw new InvalidArgumentException('Automatic cleanup policy no longer selects this snapshot.');
    foreach(@file('/boot/config/plugins/zfs.snapsync/snapshot_leases.tsv',FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $lease=array_map('trim',explode("\t",$line));
        if(($lease[0] ?? '')===$snapshot && ctype_digit($lease[1] ?? '') && (int)$lease[1]>time()
            && in_array($lease[2] ?? '',['','active'],true))throw new InvalidArgumentException('Auto snapshot has an active lease.');
    }
    if(isset($proposal['pressure']) && !zfsas_auto_pressure_needed($proposal['pressure'],$dataset,$read,$poolRead)) {
        return ['outcome'=>'success','itemState'=>'skipped','message'=>'The capacity target is met or this snapshot cannot improve the active constraint.'];
    }
    return ['ready'=>true];
}
