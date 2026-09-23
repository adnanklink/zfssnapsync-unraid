<?php
require_once __DIR__.'/response-helpers.php';
require_once __DIR__.'/coordinator-socket.php';
require_once __DIR__.'/coordinator-service.php';
if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET')zfsas_emit_marked_json(['ok'=>false,'error'=>'Use GET.'],405);
try{
    $id=$_GET['review_id'] ?? '';$offset=$_GET['offset'] ?? '0';
    if(!is_string($id)||!preg_match('/^run-[a-f0-9]{24}$/D',$id)||!is_string($offset)||!ctype_digit($offset)||strlen($offset)>8)throw new InvalidArgumentException('Invalid review request.');
    $r=zfsas_service_request(['action'=>'recovery_status','reviewId'=>$id,'offset'=>(int)$offset]);
    zfsas_emit_marked_json($r['ok']?['ok'=>true]+$r['result']:$r,$r['ok']?200:409);
}catch(Throwable $e){zfsas_emit_marked_json(['ok'=>false,'code'=>'request_failed','retryable'=>true,'error'=>$e->getMessage()],409);}
