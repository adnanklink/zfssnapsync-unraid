<?php
require_once __DIR__.'/response-helpers.php';
require_once __DIR__.'/coordinator-socket.php';
require_once __DIR__.'/coordinator-service.php';
if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET')zfsas_emit_marked_json(['ok'=>false,'error'=>'Use GET.'],405);
try{
    $id=$_GET['operation_id'] ?? '';$offset=$_GET['offset'] ?? 'latest';
    if(!is_string($id)||!preg_match('/^coordinator:(run-[a-f0-9]{24})$/D',$id,$m)||!is_string($offset)||($offset!=='latest'&&!ctype_digit($offset))||strlen($offset)>8)throw new InvalidArgumentException('Invalid job history request.');
    $stage=$_GET['stage'] ?? '';$stageOffset=$_GET['stage_offset'] ?? '0';
    if(!is_string($stage)||!in_array($stage,['','datasets','snapshots','inspect','cleanup','space','transfer','verify'],true)||!is_string($stageOffset)||!ctype_digit($stageOffset)||strlen($stageOffset)>8)throw new InvalidArgumentException('Invalid stage page.');
    $response=zfsas_service_request(['action'=>'operation_detail','runId'=>$m[1],'offset'=>$offset==='latest'?-1:(int)$offset,'stage'=>$stage,'stageOffset'=>(int)$stageOffset]);
    zfsas_emit_marked_json($response['ok']?['ok'=>true]+$response['result']:$response,$response['ok']?200:404);
}catch(Throwable $e){zfsas_emit_marked_json(['ok'=>false,'code'=>$e instanceof InvalidArgumentException?'invalid_request':'request_failed','retryable'=>!($e instanceof InvalidArgumentException),'error'=>$e->getMessage()],409);}
