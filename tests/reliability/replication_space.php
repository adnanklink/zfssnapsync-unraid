<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/replication-plan.php';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$p=['replication'=>['sourceSnapshot'=>'tank/data@next','destination'=>'backup/data'],'inspection'=>['mode'=>'incremental','base'=>['snapshot'=>'tank/data@base']],'freeSpaceFloor'=>'1G'];
$available='1200000000';$calls=[];
$read=static function($args)use(&$available,&$calls){$calls[]=$args;return $args[0]==='send'?"size\t100000000\n":$available;};
$result=zfsas_replication_space($p,$read,fn()=>'0');
check($result['outcome']==='success'&&$result['requiredBytes']===1173741824,'Configured free-space floor was ignored');
$available='1100000000';$result=zfsas_replication_space($p,$read,fn()=>'0');
check($result['outcome']==='validation_failure'&&$result['reason']==='space','Insufficient post-transfer headroom was accepted');
$waiting=zfsas_replication_space($p,$read,fn()=>'1048576');check($waiting['outcome']==='wait'&&$waiting['reason']==='space','Measured freeing work consumed a failure');
$available='-';try{zfsas_replication_space($p,$read,fn()=>'0');throw new Exception('Missing space metadata accepted');}catch(RuntimeException $e){}
foreach(['-1G','1.5G','999999999999999T','1Q'] as $bad){try{zfsas_replication_space_floor($bad);throw new Exception('Unsafe space floor accepted');}catch(InvalidArgumentException $e){}}
check(!array_filter($calls,fn($a)=>!in_array($a[0],['send','get'],true)),'Space approval mutated ZFS');
echo "PASS: measured space plus captured headroom, insufficient-space reason, incomplete metadata and overflow rejection\n";

// Reproduce the OpenZFS 2.4.3 estimate from an interrupted local receive.
// The real pipeline must discard binary data and reject even a partial count on error.
$fixture = sys_get_temp_dir().'/snapsync-resume-'.bin2hex(random_bytes(8));
mkdir($fixture, 0700);
$oldPath = getenv('PATH');
file_put_contents($fixture.'/zfs', <<<'SH'
#!/bin/bash
[[ "$#" == 3 && "$1" == send && "$2" == -t && "$3" == fixture-token ]] || exit 10
case "$MEASURE_FIXTURE" in
  complete) head -c 9435936 /dev/zero ;;
  partial) printf abc; echo "source read failed for $3" >&2; exit 7 ;;
  empty) exit 0 ;;
  *) exit 9 ;;
esac
SH
);
chmod($fixture.'/zfs', 0700);
putenv('PATH='.$fixture.':'.$oldPath);
try {
    $p['inspection'] = ['mode'=>'resume', 'resumeHash'=>hash('sha256', 'fixture-token')];
    $estimate = '18446744073595038120';
    $estimateCalls = 0;
    $source = static function ($args) use (&$estimate, &$estimateCalls) {
        check($args === ['send','-nP','-t','fixture-token'], 'Wrong source inspection');
        $estimateCalls++;
        return "size\t$estimate\n";
    };
    $tokens = [];
    $free = '7901524066304';
    $receiver = static function ($args) use (&$tokens, &$free) {
        if ($args[4] === 'receive_resume_token') { return array_shift($tokens) ?? 'fixture-token'; }
        check($args[5] === 'available', 'Unexpected receiver action');
        return $free;
    };
    putenv('MEASURE_FIXTURE=complete');
    $measurements = 0;
    $result = zfsas_replication_space($p, $source, fn()=>'0', $receiver, static function () use (&$measurements) { $measurements++; });
    check($result['outcome'] === 'success' && $result['measuredResumeBytes'] === 9435936 && $measurements === 1, 'Failed to count resumed stream');
    check($result['requiredBytes'] === 9435936 + 1073741824 && str_contains($result['message'], '9,435,936'), 'Lost margin or measurement in job log');
    $free = '1000000000';
    $cached = $p + ['measuredResumeBytes'=>$result['measuredResumeBytes']];
    check(zfsas_replication_space($cached, $source, fn()=>'0', $receiver)['reason'] === 'space' && $estimateCalls === 1, 'Cached count bypassed live space check');
    $tokens = ['changed'];
    try { zfsas_replication_space($cached, $source, null, $receiver); throw new Exception('Stale measurement accepted'); } catch (InvalidArgumentException $expected) {}
    $tokens = ['fixture-token', 'changed'];
    try { zfsas_replication_space($p, $source, null, $receiver); throw new Exception('Token change during measurement accepted'); } catch (InvalidArgumentException $expected) {}
    foreach (['partial','empty'] as $mode) {
        putenv('MEASURE_FIXTURE='.$mode);
        $result = zfsas_replication_space($p, $source, null, $receiver);
        check($result['outcome'] === 'validation_failure' && $result['failureCode'] === 'resume_measurement', 'Incomplete read approved');
        check(!str_contains(json_encode($result), 'fixture-token'), 'Resume token leaked in diagnostics');
        if ($mode === 'partial') { check($result['exitCode'] === 7 && str_contains($result['diagnostic'], 'source read failed'), 'Producer failure was lost'); }
    }
    $estimate = '9435936';
    $free = '7901524066304';
    check(zfsas_replication_space($p, $source, null, $receiver)['outcome'] === 'success', 'Valid estimate unnecessarily read stream');
} finally {
    putenv('PATH='.$oldPath);
    putenv('MEASURE_FIXTURE');
    unlink($fixture.'/zfs');
    rmdir($fixture);
}
echo "PASS: wrapped resume estimate, complete stream count, token changes, capacity recheck and partial-read failure\n";
