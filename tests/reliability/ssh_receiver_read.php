<?php
if (!is_file('/.dockerenv')) { throw new RuntimeException('Requires disposable SSH container.'); }
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/replication-ssh-read.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-remote-shutdown.php';
function check($ok,$message) {if (!$ok) {throw new RuntimeException($message);}}
function command(array $args): void {
    $p=proc_open($args,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);
    check(proc_close($p)===0,'Fixture command failed: '.$out);
}
function rejected($fn): void {try {$fn();} catch (RuntimeException|InvalidArgumentException $e) {return;} throw new RuntimeException('Unsafe SSH inspection accepted');}
$root='/tmp/ssh-read-fixture';mkdir($root,0755);@mkdir('/run/sshd',0755,true);
command(['useradd','-m','-s','/bin/sh','receiver']);command(['passwd','-d','receiver']);
foreach(['host','replacement','client'] as $key) {command(['ssh-keygen','-q','-t','ed25519','-N','','-f',$root.'/'.$key]);}
file_put_contents($root.'/pool-guid','789');
file_put_contents('/usr/local/bin/zpool',"#!/bin/sh\ncat /tmp/ssh-read-fixture/pool-guid\nprintf '\\n'\n");chmod('/usr/local/bin/zpool',0755);
file_put_contents('/usr/local/bin/zfs', <<<'PY'
#!/usr/bin/python3
import sys,json,time,os,signal
args=sys.argv[1:]
if os.path.exists('/tmp/ssh-read-fixture/owned-mode'):
 if args[0]=='get':
  print('123')
  sys.exit(0)
 if args[0] in ('receive','set'):
  with open('/tmp/ssh-read-fixture/owned-mutations','a') as out: out.write(json.dumps(args)+'\n')
  if os.path.exists('/tmp/ssh-read-fixture/owned-block'):
   signal.signal(signal.SIGTERM,signal.SIG_IGN)
   signal.signal(signal.SIGHUP,signal.SIG_IGN)
   time.sleep(60)
  sys.exit(0)
if args[0] not in ('get','list'):
 open('/tmp/ssh-read-mutation','w').write('unsafe')
 sys.exit(1)
if args[-1]=='stderr-injection':
 sys.stderr.write('debug1: Server host key: ssh-ed25519 SHA256:'+'A'*43+'\n')
if args[-1]=='large-output':
 sys.stdout.write('x'*(9*1048576))
if args[-1]=='large-error':
 sys.stderr.write('x'*131072)
if args[-1]=='stalled':
 time.sleep(60)
if args[-1]=='failed':
 sys.stderr.write('receiver metadata unavailable')
 sys.exit(9)
print(json.dumps(args))
PY);
chmod('/usr/local/bin/zfs',0755);
@mkdir('/root/.ssh',0700,true);file_put_contents('/root/.ssh/known_hosts','');
function trust($key): void {
    $pub=trim(file_get_contents('/tmp/ssh-read-fixture/'.$key.'.pub'));
    file_put_contents('/root/.ssh/known_hosts',"[127.0.0.1]:22991 $pub\n[localhost]:22991 $pub\n");
}
function server($key) {
    $root='/tmp/ssh-read-fixture';
    file_put_contents($root.'/sshd.conf',"Port 22991\nListenAddress 127.0.0.1\nHostKey $root/$key\nPidFile $root/sshd.pid\nAuthorizedKeysFile $root/client.pub\nStrictModes no\nPasswordAuthentication no\nKbdInteractiveAuthentication no\nUsePAM no\nAllowUsers receiver\nLogLevel VERBOSE\n");
    $p=proc_open(['/usr/sbin/sshd','-D','-e','-f',$root.'/sshd.conf'],[0=>['file','/dev/null','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes);
    for($i=0;$i<100;$i++) {$socket=@fsockopen('127.0.0.1',22991,$errno,$error,.05);if($socket){fclose($socket);return $p;}usleep(20000);}
    throw new RuntimeException('SSH fixture did not start: '.file_get_contents($root.'/server.log'));
}
$ssh=server('host');
$config=['SEND_SSH_HOST'=>'127.0.0.1','SEND_SSH_PORT'=>'22991','SEND_SSH_USER'=>'receiver','SEND_SSH_KEY_PATH'=>$root.'/client'];
try {
    rejected(fn()=>new ZfsasSshReceiverRead($config,'backup'));
    check(file_get_contents('/root/.ssh/known_hosts')==='','Unknown host was trusted automatically');
    trust('host');$reader=new ZfsasSshReceiverRead($config,'backup');$identity=$reader->identity();
    check($identity['poolGuid']==='789' && str_starts_with($identity['endpoint'],'ssh:'),'Receiver identity was not captured');
    $query=['get','-H','-o','value','guid','--','backup/data'];
    check(json_decode($reader->read($query),true)===$query,'Remote arguments changed');
    check(trim($reader->poolRead(['get','-H','-p','-o','value','guid','backup']))==='789','Pool metadata used the source endpoint');
    $injection=['list','--','backup/data; touch /tmp/ssh-read-injection'];
    check(json_decode($reader->read($injection),true)===$injection && !is_file('/tmp/ssh-read-injection'),'Remote shell interpreted an argument');
    $reader->read(['list','stderr-injection']);
    check($reader->identity()===$identity,'Remote stderr replaced verified host identity');
    rejected(fn()=>$reader->read(['destroy','backup/data@old']));
    rejected(fn()=>$reader->poolRead(['export','backup']));
    rejected(fn()=>$reader->read(['list',"backup\nother"]));
    check(!is_file('/tmp/ssh-read-mutation'),'Inspection issued a mutation');
    $logs=glob('/tmp/snapsync-ssh-read-*');
    foreach (['large-output','large-error','failed','stalled'] as $fault) {
        $started=microtime(true);
        rejected(fn()=>$reader->read(['list',$fault]));
        check(microtime(true)-$started<25,'Receiver fault exceeded its time bound: '.$fault);
        check(glob('/tmp/snapsync-ssh-read-*')===$logs,'Private SSH diagnostic file leaked: '.$fault);
    }
    check(json_decode($reader->read($query),true)===$query,'Failed inspection poisoned later reads');
    $alias=$config;$alias['SEND_SSH_HOST']='localhost';
    check((new ZfsasSshReceiverRead($alias,'backup'))->identity()['endpoint']===$identity['endpoint'],'SSH alias created another storage identity');
    check((new ZfsasSshReceiverRead($config,'backup',['789']))->identity()['endpoint']==='local','Locally imported pool lost local identity');
    check((new ZfsasSshReceiverRead($config,'backup',[],$identity))->identity()===$identity,'Unchanged captured identity rejected');
    $canonical=$identity;ksort($canonical,SORT_STRING);
    check((new ZfsasSshReceiverRead($config,'backup',[],$canonical))->identity()===$identity,'Journal key ordering invalidated unchanged receiver identity');
    rejected(fn()=>new ZfsasSshReceiverRead($alias,'backup',[],$identity));
    // Exercise the delivered ownership helper over an actual SSH connection,
    // including local-client loss and an independent cancellation connection.
    $capture=['config'=>$config,'identity'=>$identity];$receiver=new ZfsasSshReceiver($capture);
    file_put_contents($root.'/owned-mode','1');file_put_contents($root.'/owned-mutations','');chmod($root.'/owned-mutations',0666);
    $attempt=bin2hex(random_bytes(24));
    command($receiver->mutation($attempt,'receive','backup/data','123','on'));
    check(count(file($root.'/owned-mutations'))===1,'SSH owned receive did not execute');
    $shutdown=new ZfsasRemoteShutdown();
    $task=['parameters'=>['receiverCapture'=>$capture]];
    $poll=static function($token)use($shutdown,$task):void {
        $end=microtime(true)+10;
        do {$started=microtime(true);$done=$shutdown->poll($task,['token'=>$token]);check(microtime(true)-$started<.5,'Remote shutdown blocked coordinator polling');if($done)return;usleep(20000);}while(microtime(true)<$end);
        throw new RuntimeException('SSH receiver shutdown was not verified');
    };
    $poll($attempt);
    $fenced=bin2hex(random_bytes(24));$poll($fenced);
    rejected(fn()=>command($receiver->mutation($fenced,'receive','backup/data','123','on')));
    check(count(file($root.'/owned-mutations'))===1,'Canceled SSH launch reached mutation');
    file_put_contents($root.'/owned-block','1');$attempt=bin2hex(random_bytes(24));
    $client=proc_open($receiver->mutation($attempt,'receive','backup/data','123','on'),[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file',$root.'/owned-client.log','a']],$pipes);
    $end=microtime(true)+5;while(count(file($root.'/owned-mutations'))<2 && microtime(true)<$end){usleep(20000);}
    check(count(file($root.'/owned-mutations'))===2,'Blocking remote receive did not start');
    proc_terminate($client,9);proc_close($client);
    proc_terminate($ssh);proc_close($ssh);
    for($i=0;$i<30;$i++) {
        $started=microtime(true);check(!$shutdown->poll($task,['token'=>$attempt]),'Unreachable receiver was treated as stopped');
        check(microtime(true)-$started<.5,'Unreachable receiver blocked coordinator polling');usleep(20000);
    }
    $ssh=server('host');
    $poll($attempt);unlink($root.'/owned-block');
    unlink($root.'/owned-mode');
    file_put_contents($root.'/pool-guid','790');rejected(fn()=>$reader->read($query));
    rejected(fn()=>new ZfsasSshReceiverRead($config,'backup',[],$identity));file_put_contents($root.'/pool-guid','789');
    proc_terminate($ssh);proc_close($ssh);$ssh=server('replacement');
    rejected(fn()=>$reader->read($query));
    trust('replacement');rejected(fn()=>$reader->read($query));
    file_put_contents($root.'/owned-mode','1');
    rejected(fn()=>command($receiver->mutation(bin2hex(random_bytes(24)),'receive','backup/data','123','on')));
    check(count(file($root.'/owned-mutations'))===2,'Newly trusted host key executed an old captured mutation');
    unlink($root.'/owned-mode');
    rejected(fn()=>new ZfsasSshReceiverRead($config,'backup',[],$identity));
    check((new ZfsasSshReceiverRead($config,'backup'))->identity()['hostKey']!==$identity['hostKey'],'Host-key replacement was not distinguished');
    echo "PASS: real SSH read-only inspection, strict host trust, immutable host/pool identity, aliases, local-pool collapse, argument isolation and mutation rejection\n";
} finally {proc_terminate($ssh);proc_close($ssh);}
