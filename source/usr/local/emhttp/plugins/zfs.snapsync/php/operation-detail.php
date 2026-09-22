<?php
require_once __DIR__.'/response-helpers.php';
require_once __DIR__.'/coordinator-socket.php';
if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET')zfsas_emit_marked_json(['ok'=>false,'error'=>'Use GET.'],405);
try{
    $id=$_GET['operation_id'] ?? '';$offset=$_GET['offset'] ?? 'latest';
    if(!is_string($id)||!preg_match('/^coordinator:(run-[a-f0-9]{24})$/D',$id,$m)||!is_string($offset)||($offset!=='latest'&&!ctype_digit($offset))||strlen($offset)>8)throw new InvalidArgumentException('Invalid job history request.');
    $response=zfsas_coordinator_request(['action'=>'operation_detail','runId'=>$m[1],'offset'=>$offset==='latest'?-1:(int)$offset]);
    zfsas_emit_marked_json($response['ok']?['ok'=>true]+$response['result']:$response,$response['ok']?200:404);
}catch(Throwable $e){zfsas_emit_marked_json(['ok'=>false,'error'=>$e->getMessage()],409);}
