<?php
require_once __DIR__.'/response-helpers.php';
require_once __DIR__.'/send-helpers.php';
require_once __DIR__.'/coordinator-client.php';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
        $error=null;if (!zfsas_validate_csrf_token($error)) { zfsas_emit_marked_json(['ok'=>false,'error'=>$error],403); }
        $errors=[];$jobs=zfsas_send_collect_submitted_jobs($_POST,$errors);
        if ($errors || count($jobs)!==1) { throw new InvalidArgumentException($errors?implode(' ',$errors):'Review exactly one replication job.'); }
        $keep=$_POST['keep'] ?? '';
        if (!is_string($keep) || !preg_match('/^[1-9][0-9]{0,3}$/D',$keep) || (int)$keep>1000) { throw new InvalidArgumentException('Choose 1–1,000 source snapshots before reviewing.'); }
        $token=bin2hex(random_bytes(24));
        zfsas_coordinator_ensure();
        $response=zfsas_coordinator_request(['action'=>'source_retention_review','job'=>$jobs[0],'keep'=>(int)$keep,
            'token'=>$token,'revision'=>(string)($_POST['config_revision'] ?? '')]);
        if (!$response['ok']) { throw new RuntimeException($response['error']); }
        zfsas_emit_marked_json(['ok'=>true,'token'=>$token,'runId'=>$response['result']['runId'],'state'=>'pending']);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET') { zfsas_emit_marked_json(['ok'=>false,'error'=>'Use GET or POST.'],405); }
    $path=zfsas_source_review_path((string)($_GET['token'] ?? ''));
    $review=is_link($path)?null:json_decode((string)@file_get_contents($path),true);
    if (!$review) {
        // Status reads never start the coordinator or recreate review authority.
        $response=zfsas_coordinator_request(['action'=>'status']);
        foreach ($response['result']['runs'] ?? [] as $run) {
            if ($run['id']===($_GET['run_id'] ?? '') && in_array($run['state'],['failed','canceled'],true)) { throw new RuntimeException('Source review stopped. Start a fresh review.'); }
        }
        zfsas_emit_marked_json(['ok'=>true,'state'=>'pending']);
    }
    if (($review['expires'] ?? 0)<time()) { throw new InvalidArgumentException('Source retention review expired. Review again.'); }
    if ($review['state']==='failed') { throw new RuntimeException($review['error']); }
    $offset=max(0,(int)($_GET['offset'] ?? 0));$rows=$review['rows'];
    zfsas_emit_marked_json(['ok'=>true,'state'=>'ready','expires'=>$review['expires'],'eligible'=>$review['eligible'],
        'protected'=>$review['protected'],'unmanaged'=>$review['unmanaged'],'total'=>count($rows),'offset'=>$offset,'rows'=>array_slice($rows,$offset,50)]);
} catch (Throwable $error) { zfsas_emit_marked_json(['ok'=>false,'error'=>$error->getMessage()],$error instanceof InvalidArgumentException?400:503); }
