<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/auto-mutation.php';
require_once __DIR__.'/coordinator-worker-client.php';
try {
    $token=(string)getenv('ZFSAS_ATTEMPT_TOKEN');
    if(!preg_match('/^[a-f0-9]{48}$/D',$token))throw new InvalidArgumentException('Missing Auto policy grant.');
    $path='/tmp/zfs-snapsync-coordinator/attempts/'.$token.'/auto-proposal.json';
    if(is_link(dirname($path)) || is_link($path))throw new InvalidArgumentException('Unsafe Auto proposal path.');
    if(($argv[1] ?? '')==='capture') {
        [$action,$snapshot,$scope,$reason,$lowDataset,$required]=array_pad(array_slice($argv,2),6,'');
        if(!in_array($action,['snapshot','delete'],true) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*@[A-Za-z0-9_.:+-]+$/D',$snapshot)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D',$scope))throw new InvalidArgumentException('Invalid Auto policy proposal.');
        $dataset=explode('@',$snapshot)[0];$inventory=zfsas_auto_inventory($scope);
        $proposal=['action'=>$action,'snapshot'=>$snapshot,'policyDataset'=>$scope,
            'datasetGuid'=>trim(ZfsasReplicationInspection::command(['get','-H','-p','-o','value','guid','--',$dataset])),
            'inventoryHash'=>$inventory['hash']];
        if($action==='delete') {
            $row=$inventory['rows'][$snapshot] ?? null;
            if(!$row)throw new InvalidArgumentException('Auto snapshot disappeared before capture.');
            $proposal+=['guid'=>$row['guid'],'txg'=>$row['txg'],'reason'=>$reason];
            if($reason==='space_pressure') {
                if(!ctype_digit($required)||strlen($required)>18)throw new InvalidArgumentException('Invalid Auto capacity target.');
                $proposal['pressure']=['dataset'=>$lowDataset,'requiredBytes'=>(int)$required];
            }
        }
        $text=json_encode($proposal,JSON_THROW_ON_ERROR);
        if(file_put_contents($path.'.pending',$text)!==strlen($text) || !rename($path.'.pending',$path))throw new RuntimeException('Cannot capture Auto mutation.');
        exit;
    }
    if(($argv[1] ?? '')!=='submit' || !ctype_digit($argv[2] ?? ''))throw new InvalidArgumentException('Invalid Auto mutation request.');
    $proposal=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
    $receipt=zfsas_coordinator_worker_report('auto_mutation',(int)$argv[2],$proposal);
    $request=['action'=>'auto_mutation_status','taskId'=>(string)getenv('ZFSAS_TASK_ID'),'token'=>$token,
        'generation'=>(string)getenv('ZFSAS_COORDINATOR_GENERATION'),'mutationId'=>$receipt['mutationId']];
    while(true) {
        $reply=zfsas_coordinator_request($request);
        if(!$reply['ok'])throw new RuntimeException($reply['error'] ?? 'Auto mutation ownership expired.');
        $result=$reply['result'];
        if($result['state']==='complete') {
            if(($result['result']['itemState'] ?? '')!=='completed')throw new RuntimeException($result['result']['message'] ?? 'Auto policy changed; remaining work stopped.');
            break;
        }
        if(in_array($result['state'],['failed','canceled'],true))throw new RuntimeException($result['result']['message'] ?? 'Auto mutation stopped.');
        usleep(100000);
    }
} catch(Throwable $error) {fwrite(STDERR,$error->getMessage()."\n");exit(1);}
