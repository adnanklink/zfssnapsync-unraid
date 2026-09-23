<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-lifecycle.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function blocked($root){try{zfsas_lifecycle_idle($root);}catch(RuntimeException $e){return;}throw new RuntimeException('Unverified process group accepted');}
$root='/tmp/lifecycle-ownership-'.bin2hex(random_bytes(8));mkdir($root,0700,true);mkdir($root.'/attempts/test',0700,true);
file_put_contents($root.'/worker.sh',"#!/bin/bash\necho \$BASHPID > \"\$1/leader\"\nsleep 60 &\necho \$! > \"\$1/child\"\nwait\n");
$proc=proc_open(['setsid','bash',$root.'/worker.sh',$root],[0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']],$pipes);
$leader=null;$child=null;
try{
 for($i=0;$i<100&&!is_file($root.'/child');$i++)usleep(20000);
 $pid=(int)file_get_contents($root.'/leader');$leader=ZfsasCoordinatorExecutor::identity($pid);$child=(int)file_get_contents($root.'/child');
 check($leader['pid']===$leader['group'],'Fixture lacks own process group');file_put_contents($root.'/attempts/test/owner.json',json_encode($leader));
 blocked($root);check(posix_kill($pid,0)&&posix_kill($child,0),'Idle check killed active work');
 posix_kill($pid,9);proc_close($proc);$proc=null;usleep(50000);
 blocked($root);check(posix_kill($child,0),'Idle check killed orphan pipeline child');
 posix_kill($child,9);usleep(50000);zfsas_lifecycle_idle($root);
 $identity=ZfsasCoordinatorExecutor::identity(getmypid());$identity['start']='0';file_put_contents($root.'/attempts/test/owner.json',json_encode($identity));blocked($root);
 unlink($root.'/attempts/test/owner.json');
 $journal=new ZfsasCoordinatorState($root);$receipt=$journal->submit('queued',['tasks'=>['send'=>['kind'=>'send']]],time());unset($journal);
 $before=ZfsasCoordinatorState::readCommitted($root);zfsas_lifecycle_idle($root);check(ZfsasCoordinatorState::readCommitted($root)===$before,'Idle inspection changed queued work or receipts');
 echo "PASS: complete process groups, surviving pipeline children, reused identity rejection, no worker signals, queued journal preserved\n";
}finally{if($proc){proc_terminate($proc,9);proc_close($proc);}if($leader)foreach(ZfsasCoordinatorExecutor::members($leader['pid'],$leader['start'])??[] as $member)posix_kill($member['pid'],9);}
