<?php
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires disposable receiver container.'); }
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-executor.php';
function check($ok,$message): void { if (!$ok) { throw new RuntimeException($message); } }
$helper='/usr/local/emhttp/plugins/zfs.snapsync/scripts/replication-receiver.sh';
$boot=trim(file_get_contents('/proc/sys/kernel/random/boot_id'));
$fixture='/tmp/receiver-ownership-fixture';mkdir($fixture);
file_put_contents('/usr/local/bin/zpool',"#!/bin/sh\nprintf '789\\n'\n");chmod('/usr/local/bin/zpool',0755);
file_put_contents('/usr/local/bin/zfs', <<<'SH'
#!/bin/bash
set -eu
case "$1" in
get) printf '123\n';;
list) printf 'backup\nbackup/data\n';;
receive|set)
  printf '%s\n' "$$" >> /tmp/receiver-ownership-fixture/mutations
  if [[ -e /tmp/receiver-ownership-fixture/block ]]; then
    trap '' TERM
    sleep 60 &
    echo "$!" > /tmp/receiver-ownership-fixture/child
    wait
  fi;;
*) exit 1;;
esac
SH);
chmod('/usr/local/bin/zfs',0755);
function invoke(array $args, bool $async=false) {
    global $helper,$fixture;
    $p=proc_open(array_merge(['/usr/bin/setsid','/bin/bash',$helper],$args),[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file',$fixture.'/errors','a']],$pipes);
    check(is_resource($p),'Could not launch receiver fixture');
    if ($async) { fclose($pipes[1]); return $p; }
    $out=trim(stream_get_contents($pipes[1]));fclose($pipes[1]);return [proc_close($p),$out];
}
function runArgs(string $token,string $expected='123'): array {global $boot;return ['run',$boot,$token,'789','receive','backup/data',$expected,'on'];}
function revoke(string $token): array {global $boot;return invoke(['revoke',$boot,$token,'789']);}
function waitFor(callable $test): void { $end=microtime(true)+8;do{if($test())return;usleep(20000);}while(microtime(true)<$end);throw new RuntimeException('Receiver fixture timed out'); }
function stopped(string $token): void {waitFor(fn()=>revoke($token)===[0,'stopped']);}
function mutations(): int {return is_file('/tmp/receiver-ownership-fixture/mutations')?count(file('/tmp/receiver-ownership-fixture/mutations')):0;}
$processes=[];$tokens=[];
try {
    $token=$tokens[]=bin2hex(random_bytes(24));
    check(revoke($token)===[0,'stopped'],'Unstarted attempt could not be fenced');
    check(invoke(runArgs($token))[0]!==0 && mutations()===0,'Delayed launch passed cancellation fence');
    $token=$tokens[]=bin2hex(random_bytes(24));
    check(invoke(runArgs($token,'999'))[0]!==0 && mutations()===0,'Changed dataset identity reached mutation');
    stopped($token);
    $token=$tokens[]=bin2hex(random_bytes(24));
    check(invoke(runArgs($token,'absent:123'))[0]!==0 && mutations()===0,'Existing dataset accepted as a new receiver');
    stopped($token);
    $token=$tokens[]=bin2hex(random_bytes(24));
    $args=runArgs($token);$args[1]='00000000-0000-0000-0000-000000000000';
    check(invoke($args)[0]!==0 && mutations()===0,'Receiver reboot guard was bypassed');
    $token=$tokens[]=bin2hex(random_bytes(24));
    check(invoke(runArgs($token))[0]===0 && mutations()===1,'Valid receive was not executed');
    check(invoke(runArgs($token))[0]!==0 && mutations()===1,'Attempt token executed twice');
    stopped($token);
    // The receiver uses the exact ancestor lock namespace of local mutations.
    $gate=fopen('/tmp/zfs-snapsync-ops/dataset-locks/'.hash('sha256','backup').'.lock','c');flock($gate,LOCK_EX);
    $token=$tokens[]=bin2hex(random_bytes(24));
    check(invoke(runArgs($token))[0]!==0 && mutations()===1,'Receiver bypassed an ancestor gate');
    stopped($token);flock($gate,LOCK_UN);fclose($gate);
    file_put_contents($fixture.'/block','1');
    $token=$tokens[]=bin2hex(random_bytes(24));$p=$processes[]=invoke(runArgs($token),true);
    waitFor(fn()=>is_file($fixture.'/child'));
    $ownerPath='/dev/shm/zfs-snapsync-receiver-0/'.$token.'/owner';
    $owner=explode(' ',trim(file_get_contents($ownerPath)));$pid=(int)$owner[1];$start=$owner[2];
    check(ZfsasCoordinatorExecutor::members($pid,$start)!==[],'Fixture did not create owned children');
    // A forged/reused process start must never authorize signaling.
    file_put_contents($ownerPath,"$boot $pid 0 789\n");
    check(revoke($token)===[0,'ambiguous'],'Changed process identity was signaled');
    file_put_contents($ownerPath,implode(' ',$owner)."\n");
    check(revoke($token)===[0,'running'],'Cancel reported stopped while children were live');
    stopped($token);
    check(ZfsasCoordinatorExecutor::members($pid,$start)===[],'Receiver cancellation left pipeline descendants');
    proc_close($p);array_pop($processes);unlink($fixture.'/child');
    // Losing the wrapper is not proof that its receive child stopped.
    $token=$tokens[]=bin2hex(random_bytes(24));$p=$processes[]=invoke(runArgs($token),true);
    waitFor(fn()=>is_file($fixture.'/child'));
    $owner=explode(' ',trim(file_get_contents('/dev/shm/zfs-snapsync-receiver-0/'.$token.'/owner')));
    posix_kill((int)$owner[1],9);proc_close($p);array_pop($processes);
    check(revoke($token)===[0,'running'],'Orphaned receive was treated as stopped');
    stopped($token);
    check(ZfsasCoordinatorExecutor::members((int)$owner[1],$owner[2])===[],'Orphaned receive survived revocation');
    echo "PASS: receiver launch fence, duplicate prevention, dataset/reboot guards, compatible locks, PID identity and verified descendant shutdown\n";
} finally {
    foreach($tokens as $token) {revoke($token);}
    foreach($processes as $p) {proc_close($p);}
}
