<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-state.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/operation-diagnostics.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$j=new ZfsasCoordinatorState('/tmp/diagnostics-'.bin2hex(random_bytes(8)));
$r=$j->submit('errors',['tasks'=>['success'=>['kind'=>'prepare'],'bad'=>['kind'=>'send','dataset'=>'tank/data','parameters'=>['replication'=>['sourceSnapshot'=>'tank/data@s','destination'=>'backup/data']]],'later'=>['kind'=>'finalize','dependencies'=>['bad']]]],time())['runId'];
$id=$r.':success';$token=$j->claim($id,1,time());$j->result($id,$token,['outcome'=>'success','message'=>'Verified expected receiver snapshot.'],1,time(),true);
$id=$r.':bad';$token=$j->claim($id,1,time());$j->result($id,$token,['outcome'=>'transient_failure','message'=>'Pipeline failed','exitCode'=>1,'diagnostic'=>'cannot receive: out of space'],1,time(),true);
$token=$j->claim($id,62,time());$j->result($id,$token,['outcome'=>'validation_failure','failureCode'=>'interrupted_receive','message'=>'An earlier transfer is unfinished.','recoveryRequired'=>true],62,time(),true);
$d=zfsas_operation_detail($j,$r);
check($d['problem']['code']==='interrupted_receive','Failure was obscured by a successful task');
check($d['problem']['destination']==='backup/data','Failure has no destination');
check(count($d['entries'])===4,'Earlier attempt or blocked task was lost');
check(str_contains(json_encode($d),'out of space'),'Original ZFS error was overwritten');
check(!str_contains($d['problem']['summary'],'Verified'),'Success leaked into the failure headline');
check(str_contains(json_encode($d),'prerequisite'),'Blocked child lacks explanation');
$other=$j->submit('other',['tasks'=>['send'=>['kind'=>'send']]],time())['runId'];
check(!str_contains(json_encode(zfsas_operation_detail($j,$other)),'out of space'),'Different jobs share diagnostics');
$secret=zfsas_diagnostic_text("password=hunter2 receive_resume_token=raw-secret\nRun zfs send -t raw-secret\nAuthorization: Bearer private\n");
check(!preg_match('/hunter2|raw-secret|private/',$secret),'Diagnostic leaked secrets');
check(strlen(zfsas_diagnostic_text(str_repeat('x',10000)))===4096,'Unbounded diagnostic');
for($i=0;$i<350;$i++)$j->state['attempts']['fixture'.$i]=['taskId'=>$r.':bad','state'=>'stopped','createdAt'=>$i,'reportedResult'=>['outcome'=>'validation_failure','message'=>str_repeat('a',4000)]];
$d=zfsas_operation_detail($j,$r,-1);check(count($d['entries'])<=200&&strlen(json_encode($d))<131072,'History exceeds response bound');
check($d['previousOffset']!==null,'Large history lost pagination');
echo "PASS: job-only diagnostics, original attempt retention, failure priority, dependency explanations, redaction and bounded history\n";
