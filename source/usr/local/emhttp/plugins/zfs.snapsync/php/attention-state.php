<?php
/** Display acknowledgements only. Never alter worker, recovery or reference state. */
function zfsas_attention_token(array $operation): string
{
    $identity=[];
    foreach (['id','createdAt','finishedAt','state','recoveryRequired','source','destination','message','attentionVersion'] as $key) {
        $identity[$key]=$operation[$key] ?? null;
    }
    return hash('sha256',json_encode($identity,JSON_THROW_ON_ERROR));
}
function zfsas_attention_read(?string $root=null): array
{
    $path=($root ?? '/tmp/zfs-snapsync-attention').'/dismissed.json';
    if (!is_file($path) || is_link($path)) { return []; }
    $text=file_get_contents($path);
    if ($text===false || strlen($text)>131072) { throw new RuntimeException('Attention acknowledgements are unavailable.'); }
    $records=json_decode($text,true,8,JSON_THROW_ON_ERROR);
    if (!is_array($records) || count($records)>1000) { throw new RuntimeException('Invalid attention acknowledgements.'); }
    foreach ($records as $token=>$time) {
        if (!preg_match('/^[a-f0-9]{64}$/D',(string)$token) || !is_int($time)) { throw new RuntimeException('Invalid attention acknowledgement.'); }
    }
    return $records;
}
function zfsas_attention_project(array $operations,array $records): array
{
    foreach ($operations as &$operation) {
        $attention=$operation['state']==='failed' || !empty($operation['recoveryRequired']);
        $operation['attentionToken']=$attention?zfsas_attention_token($operation):null;
        $operation['attentionDismissed']=$attention && isset($records[$operation['attentionToken']]);
        $operation['needsAttention']=$attention && !$operation['attentionDismissed'];
        if ($attention) { $operation['actions'][]=$operation['attentionDismissed']?'restore_attention':'dismiss_attention'; }
    }
    unset($operation);
    return $operations;
}
function zfsas_attention_save(string $token,bool $dismiss,?string $root=null): void
{
    if (!preg_match('/^[a-f0-9]{64}$/D',$token)) { throw new InvalidArgumentException('Invalid attention identity.'); }
    $root ??= '/tmp/zfs-snapsync-attention';
    if (!str_starts_with($root,'/tmp/') || is_link($root)) { throw new RuntimeException('Attention state must remain in RAM.'); }
    if (!is_dir($root) && !mkdir($root,0770,true)) { throw new RuntimeException('Cannot create attention storage.'); }
    @chmod($root,0770);@chown($root,'nobody');@chgrp($root,'users');
    $lock=fopen($root.'/owner.lock','c');
    if (!$lock) { throw new RuntimeException('Cannot lock attention storage.'); }
    @chmod($root.'/owner.lock',0660);@chown($root.'/owner.lock','nobody');@chgrp($root.'/owner.lock','users');
    try {
        if (!flock($lock,LOCK_EX)) { throw new RuntimeException('Cannot lock attention storage.'); }
        $records=zfsas_attention_read($root);
        if ($dismiss===isset($records[$token])) { return; }
        if ($dismiss) { $records=[$token=>time()]+$records; } else { unset($records[$token]); }
        $records=array_filter($records,static fn($time)=>$time>=time()-30*86400);
        arsort($records);$records=array_slice($records,0,1000,true);
        $tmp=tempnam($root,'.attention-');
        if (!$tmp) { throw new RuntimeException('Cannot stage attention acknowledgement.'); }
        try {
            @chmod($tmp,0660);@chown($tmp,'nobody');@chgrp($tmp,'users');
            $text=json_encode((object)$records,JSON_THROW_ON_ERROR);
            if (file_put_contents($tmp,$text)!==strlen($text) || !rename($tmp,$root.'/dismissed.json')) { throw new RuntimeException('Cannot save attention acknowledgement.'); }
        } finally { if (is_file($tmp)) { unlink($tmp); } }
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}
