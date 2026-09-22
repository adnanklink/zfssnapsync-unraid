<?php
/** Configuration is authorization; inventories and reviews remain in RAM. */
function zfsas_source_policies(array $config): array
{
    $raw=$config['SEND_SOURCE_RETENTION'] ?? '{"version":1,"jobs":{}}';
    if (!is_string($raw) || strlen($raw)>1048576) { throw new InvalidArgumentException('Invalid source retention policy.'); }
    try { $object=json_decode($raw,false,16,JSON_THROW_ON_ERROR); } catch (JsonException $error) { throw new InvalidArgumentException('Invalid source retention policy.',0,$error); }
    if (!$object instanceof stdClass || !($object->jobs ?? null) instanceof stdClass || array_diff(array_keys(get_object_vars($object)),['version','jobs'])) { throw new InvalidArgumentException('Invalid source retention document.'); }
    $doc=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if (($doc['version'] ?? null)!==1 || !is_array($doc['jobs'] ?? null)) { throw new InvalidArgumentException('Unsupported source retention policy.'); }
    foreach ($doc['jobs'] as $id=>$policy) {
        if (!preg_match('/^[a-f0-9]{12}$/D',(string)$id) || !is_array($policy)
            || !is_int($policy['keep'] ?? null) || $policy['keep']<0 || $policy['keep']>1000
            || !is_string($policy['binding'] ?? null) || !is_array($policy['datasets'] ?? null)
            || array_diff(array_keys($policy),['keep','binding','datasets'])
            || ($policy['keep']>0 && !preg_match('/^[a-f0-9]{64}$/D',$policy['binding']))) {
            throw new InvalidArgumentException('Invalid source retention authorization.');
        }
        foreach ($policy['datasets'] as $dataset=>$guid) {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:+-]*(?:\/[A-Za-z0-9_.:+-]+)*$/D',(string)$dataset) || !is_string($guid) || !preg_match('/^[0-9]{1,20}$/D',$guid)) { throw new InvalidArgumentException('Invalid authorized source identity.'); }
        }
    }
    return $doc['jobs'];
}
function zfsas_source_binding(array $job): string
{
    $scope=[];foreach (['id','source','destination','children','transport'] as $key) { $scope[$key]=$job[$key] ?? ''; }
    return hash('sha256',json_encode($scope,JSON_THROW_ON_ERROR));
}
function zfsas_source_policy(array $config,array $job): array
{
    $policy=zfsas_source_policies($config)[$job['id']] ?? ['keep'=>0,'binding'=>'','datasets'=>[]];
    if (($job['transport'] ?? 'local')!=='local' || $policy['binding']!==zfsas_source_binding($job)) { $policy['keep']=0; }
    return $policy;
}
function zfsas_source_member_policy(array $policy,string $source): array
{
    $policy['datasets']=array_intersect_key($policy['datasets'],[$source=>true]);
    return $policy;
}
function zfsas_source_review_path(string $token): string
{
    if (!preg_match('/^[a-f0-9]{48}$/D',$token)) { throw new InvalidArgumentException('Invalid source retention review.'); }
    return '/tmp/zfs-snapsync-source-reviews/'.$token.'.json';
}
function zfsas_source_review_write(string $token,array $review): void
{
    $path=zfsas_source_review_path($token);
    if (!is_dir(dirname($path)) && !mkdir(dirname($path),0770,true)) { throw new RuntimeException('Cannot create RAM review storage.'); }
    @chmod(dirname($path),0770);@chown(dirname($path),'nobody');@chgrp(dirname($path),'users');
    $tmp=tempnam(dirname($path),'.review-');
    if ($tmp===false) { throw new RuntimeException('Cannot stage source retention review.'); }
    @chmod($tmp,0660);@chown($tmp,'nobody');@chgrp($tmp,'users');
    try {
        if (file_put_contents($tmp,json_encode($review,JSON_THROW_ON_ERROR))===false || !rename($tmp,$path)) { throw new RuntimeException('Cannot publish source review.'); }
    } finally { if (is_file($tmp)) { unlink($tmp); } }
}
function zfsas_source_save(array $previous,array $jobs,array $choices,array $tokens,string $revision,?callable $membership=null,?callable $inventory=null): string
{
    require_once __DIR__.'/replication-membership.php';
    $membership ??= 'zfsas_replication_membership';
    $inventory ??= static function($source):array { require_once __DIR__.'/source-retention.php';return zfsas_source_inventory($source,[ZfsasReplicationInspection::class,'command']); };
    $oldJobs=function_exists('zfsas_send_parse_jobs')?array_column(zfsas_send_parse_jobs($previous['SEND_JOBS'] ?? ''),null,'id'):[];
    $old=zfsas_source_policies($previous);$result=[];
    foreach ($jobs as $job) {
        $id=$job['id'];$prior=$old[$id] ?? ['keep'=>0,'binding'=>'','datasets'=>[]];
        // Omitted fields from older forms never introduce destructive authority.
        if (!array_key_exists($id,$choices)) { $result[$id]=$prior; continue; }
        $choice=(string)$choices[$id];
        if (!preg_match('/^(?:0|[1-9][0-9]{0,3})$/D',$choice) || (int)$choice>1000) { throw new InvalidArgumentException('Keep 1–1,000 source snapshots, or choose Keep all.'); }
        $keep=(int)$choice;$binding=zfsas_source_binding($job);
        if ($keep===0) { $result[$id]=['keep'=>0,'binding'=>$binding,'datasets'=>[]];continue; }
        if (($job['transport'] ?? 'local')!=='local') { throw new InvalidArgumentException('Source retention is available for local jobs only.'); }
        $token=$tokens[$id] ?? '';
        if ($token==='') {
            if ($prior['keep']>0 && $keep>=$prior['keep'] && $prior['binding']===$binding) { $result[$id]=$prior;$result[$id]['keep']=$keep;continue; }
            // A genuinely new job can authorize its future snapshots on Save.
            // Reused IDs with a tagged backlog still require explicit review.
            if (!isset($old[$id]) && !isset($oldJobs[$id])) {
                $datasets=[];$backlog=false;
                foreach ($membership($job) as $member) {
                    $datasets[$member['source']]=$member['sourceDatasetGuid'];
                    foreach ($inventory($member['source']) as $row) {
                        if (($row['properties']['org.zfs.snapsync:schedule'] ?? '')===$id) { $backlog=true;break; }
                    }
                }
                if (!$backlog) { $result[$id]=['keep'=>$keep,'binding'=>$binding,'datasets'=>$datasets];continue; }
            }
            throw new InvalidArgumentException('Review source snapshots for '.$job['source'].' before enabling or reducing retention.');
        }
        $path=zfsas_source_review_path($token);
        $review=is_link($path)?null:json_decode((string)@file_get_contents($path),true);
        if (!$review || ($review['state'] ?? '')!=='ready' || ($review['expires'] ?? 0)<time()
            || ($review['revision'] ?? '')!==$revision || ($review['binding'] ?? '')!==$binding || ($review['keep'] ?? null)!==$keep) {
            throw new InvalidArgumentException('Source retention review expired or settings changed. Review again.');
        }
        $datasets=[];foreach ($membership($job) as $member) { $datasets[$member['source']]=$member['sourceDatasetGuid']; }
        if ($datasets!==$review['datasets']) { throw new InvalidArgumentException('Source identities or membership changed. Review again.'); }
        $result[$id]=['keep'=>$keep,'binding'=>$binding,'datasets'=>$datasets];
    }
    ksort($result);
    $encoded=json_encode(['version'=>1,'jobs'=>(object)$result],JSON_THROW_ON_ERROR);
    if (strlen($encoded)>1048576) { throw new InvalidArgumentException('Source retention authorization exceeds the configuration limit.'); }
    return $encoded;
}
