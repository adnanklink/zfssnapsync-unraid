<?php
require_once __DIR__.'/replication-membership.php';
require_once __DIR__.'/replication-plan.php';

function zfsas_replication_schedule_plan(array $parameters, ?callable $read=null, ?array $members=null): array
{
    $members??=zfsas_replication_membership($parameters['job'],$read);
    $tasks=[];$parents=[];$index=0;
    foreach($members as $member){
        $key=sprintf('%05d',$index++);$snapshot='snapshot-'.$key;$prepare='member-'.$key;
        $common=$member+['revision'=>$parameters['revision'],'rateLimit'=>$parameters['rateLimit'],
            'snapshotName'=>$parameters['snapshotName'],'scheduleId'=>$parameters['job']['id'],
            'occurrence'=>(string)$parameters['occurrence'],'cleanupPolicy'=>$parameters['cleanupPolicy'] ?? null,'nativeSchedule'=>true];
        $tasks[$snapshot]=['kind'=>'auto','dataset'=>$member['source'],'parameters'=>$common+['phase'=>'replication_snapshot']];
        $dependencies=[$snapshot];
        if($member['source']!==$parameters['job']['source']){
            $parent=substr($member['source'],0,strrpos($member['source'],'/'));
            $dependencies[]=$parents[$parent];
        }
        $tasks[$prepare]=['kind'=>'prepare','dataset'=>$member['source'],'parameters'=>$common+[
            'phase'=>'replication_member','snapshotTask'=>$snapshot,'nativePlan'=>true,'allowDynamicPlan'=>true], 'dependencies'=>$dependencies];
        $parents[$member['source']]=$prepare;
    }
    $tasks['verify-run']=['kind'=>'finalize','dataset'=>$parameters['job']['source'],'parameters'=>[
        'source'=>$parameters['job']['source'],'destination'=>$parameters['job']['destination'],
        'phase'=>'replication_run_verify','nativeSchedule'=>true,'revision'=>$parameters['revision']], 'dependencies'=>array_values($parents)];
    return ['tasks'=>$tasks];
}

function zfsas_replication_publish_plan(array $plan, int &$sequence): void
{
    if(count($plan['tasks'])<=50 && strlen(json_encode($plan,JSON_THROW_ON_ERROR))<=700000){zfsas_coordinator_worker_report('plan',$sequence++,$plan);return;}
    $canonical=static function(array $value)use(&$canonical):array{
        if(!array_is_list($value)){ksort($value,SORT_STRING);}
        foreach($value as &$item){if(is_array($item))$item=$canonical($item);}return $value;
    };
    $digest=hash('sha256',json_encode($canonical($plan),JSON_THROW_ON_ERROR));$offset=0;
    $chunk=[];$bytes=0;
    $flush=static function()use(&$chunk,&$bytes,&$offset,&$sequence,$digest):void{
        zfsas_coordinator_worker_report('plan_chunk',$sequence++,['digest'=>$digest,'offset'=>$offset,'tasks'=>$chunk]);$offset+=count($chunk);$chunk=[];$bytes=0;
    };
    foreach($plan['tasks'] as $name=>$task){
        $size=strlen(json_encode([$name=>$task],JSON_THROW_ON_ERROR));
        if($size>700000){throw new InvalidArgumentException('A preparation task exceeds the bounded publication limit.');}
        if($chunk && (count($chunk)>=50 || $bytes+$size>700000)){$flush();}
        $chunk[$name]=$task;$bytes+=$size;
    }
    if($chunk){$flush();}
    zfsas_coordinator_worker_report('plan_seal',$sequence++,['digest'=>$digest,'count'=>$offset]);
}
