<?php
require_once __DIR__.'/snapshot-manager-helpers.php';
require_once __DIR__.'/coordinator-service.php';
require_once __DIR__.'/coordinator-socket.php';
try{
    $method=$_SERVER['REQUEST_METHOD'] ?? 'GET';$input=$method==='POST'?$_POST:$_GET;
    $action=$input['action'] ?? 'status';
    if(!in_array($action,['inspect','stop','review','submit','status','abandon'],true))throw new InvalidArgumentException('Invalid retirement action.');
    if($action!=='status'){
        if($method!=='POST')throw new InvalidArgumentException('Use POST for retirement actions.');
        if(!zfsas_validate_csrf_token($error))zfsas_emit_marked_json(['ok'=>false,'error'=>$error],403);
    }
    $request=['action'=>'retirement_'.$action];
    foreach(['dataset','token'] as $key)if(isset($input[$key])){if(!is_string($input[$key]))throw new InvalidArgumentException('Invalid request.');$request[$key]=$input[$key];}
    foreach(['destinations','included','excluded'] as $key)if(isset($input[$key]))$request[$key]=json_decode($input[$key],true,16,JSON_THROW_ON_ERROR);
    $request['confirm']=($input['confirm'] ?? '')==='1';$request['offset']=max(0,(int)($input['offset'] ?? 0));
    $reply=zfsas_service_request($request);
    zfsas_emit_marked_json($reply['ok']?['ok'=>true]+$reply['result']:$reply,$reply['ok']?200:409);
}catch(Throwable $error){zfsas_emit_marked_json(['ok'=>false,'error'=>$error->getMessage()],409);}
