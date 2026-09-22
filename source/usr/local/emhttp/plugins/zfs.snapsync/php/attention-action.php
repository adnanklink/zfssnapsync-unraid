<?php
require_once __DIR__.'/response-helpers.php';
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { zfsas_emit_marked_json(['ok'=>false,'error'=>'Use POST.'],405); }
$error=null;
if (!zfsas_validate_csrf_token($error)) { zfsas_emit_marked_json(['ok'=>false,'error'=>$error],403); }
require_once __DIR__.'/workspace-summary.php';
try {
    $action=$_POST['action'] ?? '';
    if (!in_array($action,['dismiss_attention','restore_attention'],true)) { throw new InvalidArgumentException('Invalid attention action.'); }
    $id=$_POST['operation_id'] ?? '';$token=$_POST['attention_token'] ?? '';
    if (!is_string($id) || !is_string($token)) { throw new InvalidArgumentException('Invalid attention request.'); }
    $operations=zfsas_workspace_summary()['operations'];$match=null;
    foreach ($operations as $operation) { if ($operation['id']===$id) { $match=$operation;break; } }
    if (!$match || !is_string($match['attentionToken']) || !hash_equals($match['attentionToken'],$token)) {
        throw new InvalidArgumentException('The operation changed or is no longer available. Refresh and review its current status.');
    }
    zfsas_attention_save($token,$action==='dismiss_attention');
    zfsas_emit_marked_json(['ok'=>true,'attentionDismissed'=>$action==='dismiss_attention',
        'message'=>$action==='dismiss_attention'?'Dismissed from Needs attention. History and recovery protections are preserved.':'Restored to Needs attention.']);
} catch (Throwable $error) { zfsas_emit_marked_json(['ok'=>false,'error'=>$error->getMessage()],$error instanceof InvalidArgumentException?409:503); }
