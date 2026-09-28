<?php
require_once __DIR__.'/replication-ssh-connection.php';
require_once __DIR__.'/replication-inspection.php';

/** Read-only SSH inspection. No receiver plugin, installation, or mutation. */
final class ZfsasSshReceiverRead
{
    private array $connection;
    private string $pool;
    private array $identity;

    public function __construct(array $config, string $pool, array $localPoolGuids=[], ?array $expected=null)
    {
        $this->connection=ZfsasSshConnection::normalize($config);
        ZfsasEndpointIdentity::resource('local',$pool);
        if (str_contains($pool,'/')) { throw new InvalidArgumentException('Receiver pool name required.'); }
        $this->pool=$pool;
        $binding=hash('sha256',json_encode($this->connection,JSON_THROW_ON_ERROR));
        if ($expected!==null && (($expected['connectionDigest'] ?? '')!==$binding || ($expected['pool'] ?? '')!==$pool)) {
            throw new InvalidArgumentException('SSH connection changed after receiver inspection.');
        }
        $probe=$this->execute(null,[]);
        $this->identity=['endpoint'=>ZfsasEndpointIdentity::receiver($probe['hostKey'],$probe['poolGuid'],$localPoolGuids),
            'hostKey'=>$probe['hostKey'],'poolGuid'=>$probe['poolGuid'],'pool'=>$pool,'connectionDigest'=>$binding,'bootId'=>$probe['bootId']];
        if ($expected!==null && $this->identity!==$expected) {
            throw new InvalidArgumentException('Verified SSH receiver identity changed; inspect it again.');
        }
    }

    public function identity(): array { return $this->identity; }

    public function read(array $arguments): string { return $this->query('zfs',$arguments); }
    public function poolRead(array $arguments): string { return $this->query('zpool',$arguments); }

    private function query(string $program, array $arguments): string
    {
        if (!array_is_list($arguments) || !in_array($arguments[0] ?? '',['get','list'],true)
            || count($arguments)>256) { throw new InvalidArgumentException('Only bounded receiver metadata reads are allowed.'); }
        foreach ($arguments as $argument) {
            if (!is_string($argument) || $argument==='' || strlen($argument)>4096 || strpbrk($argument,"\0\r\n")!==false) {
                throw new InvalidArgumentException('Invalid receiver inspection argument.');
            }
        }
        if (strlen(json_encode($arguments,JSON_THROW_ON_ERROR))>32768) { throw new InvalidArgumentException('Receiver inspection is too large.'); }
        $result=$this->execute($program,$arguments);
        if ($result['hostKey']!==$this->identity['hostKey'] || $result['poolGuid']!==$this->identity['poolGuid'] || $result['bootId']!==$this->identity['bootId']) {
            throw new InvalidArgumentException('Verified SSH receiver identity changed during inspection.');
        }
        return $result['output'];
    }

    private function execute(?string $program, array $arguments): array
    {
        // One verified connection returns the pool identity and the requested
        // metadata. Never trust a separate unauthenticated ssh-keyscan result.
        $script='set -eu; zpool get -H -p -o value guid '.escapeshellarg($this->pool).'; cat /proc/sys/kernel/random/boot_id;';
        if ($program!==null) { $script.=' exec '.implode(' ',array_map('escapeshellarg',array_merge([$program],$arguments))); }
        $sessionLog=tempnam('/tmp','snapsync-ssh-read-');
        if ($sessionLog===false) { throw new RuntimeException('Cannot capture verified SSH connection identity.'); }
        chmod($sessionLog,0600);
        $ssh=array_merge(['/usr/bin/timeout','--foreground','--signal=TERM','--kill-after=2','20'],ZfsasSshConnection::arguments($this->connection));
        array_push($ssh,'-v','-E',$sessionLog,'--',ZfsasSshConnection::target($this->connection),'LC_ALL=C sh -c '.escapeshellarg($script));
        $process=proc_open($ssh,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) { unlink($sessionLog);throw new RuntimeException('Unable to start receiver inspection.'); }
        foreach ($pipes as $pipe) { stream_set_blocking($pipe,false); }
        $output='';$diagnostic='';$exit=null;
        try {
            do {
                $output.=stream_get_contents($pipes[1],65536);$diagnostic.=stream_get_contents($pipes[2],65536);
                clearstatcache(true,$sessionLog);
                if (strlen($output)>8*1048576 || strlen($diagnostic)>65536 || filesize($sessionLog)>65536) {
                    proc_terminate($process,15);throw new RuntimeException('Receiver inspection exceeded its output limit.');
                }
                $status=proc_get_status($process);
                if (!$status['running'] && $exit===null) { $exit=$status['exitcode']; }
                if (!$status['running'] && feof($pipes[1]) && feof($pipes[2])) { break; }
                $read=[$pipes[1],$pipes[2]];$write=$except=null;
                @stream_select($read,$write,$except,0,100000);
            } while (true);
            $connectionLog=(string)file_get_contents($sessionLog,false,null,0,65537);
            if (strlen($output)>8*1048576 || strlen($diagnostic)>65536 || strlen($connectionLog)>65536) { throw new RuntimeException('Receiver inspection exceeded its output limit.'); }
            if ($exit!==0) { throw new ZfsasReplicationCommandError('SSH receiver inspection failed; verify connectivity and the trusted host key.',substr($connectionLog."\n".$diagnostic,-4096),(int)$exit); }
            // The outer client's private log excludes remote stderr and a jump
            // host's diagnostics. Each read verifies a fresh target connection.
            if (!preg_match('/^debug1: Server host key: \S+ (SHA256:[A-Za-z0-9+\/]{43})\r?$/m',$connectionLog,$match)) {
                throw new RuntimeException('SSH did not report a verified receiver host-key identity.');
            }
            $parts=explode("\n",$output,3);$guid=trim($parts[0]);$boot=trim($parts[1] ?? '');
            if (!preg_match('/^[0-9]{1,20}$/D',$guid)) { throw new RuntimeException('Incomplete receiver pool identity.'); }
            if (!preg_match('/^[a-f0-9-]{36}$/D',$boot)) { throw new RuntimeException('Incomplete receiver boot identity.'); }
            return ['hostKey'=>$match[1],'poolGuid'=>$guid,'bootId'=>$boot,'output'=>$parts[2] ?? ''];
        } finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_close($process);unlink($sessionLog); }
    }
}
