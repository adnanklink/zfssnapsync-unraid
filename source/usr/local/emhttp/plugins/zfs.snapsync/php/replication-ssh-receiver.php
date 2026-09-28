<?php
require_once __DIR__.'/replication-ssh-read.php';

/** Commands are limited to the owned receiver protocol, never arbitrary shell. */
final class ZfsasSshReceiver
{
    private array $connection;
    private array $identity;

    public function __construct(array $capture)
    {
        $this->connection=ZfsasSshConnection::normalize($capture['config'] ?? []);
        $this->identity=$capture['identity'] ?? [];
        if (!hash_equals(hash('sha256',json_encode($this->connection,JSON_THROW_ON_ERROR)),$this->identity['connectionDigest'] ?? '')
            || !preg_match('/^[a-f0-9-]{36}$/D',$this->identity['bootId'] ?? '')
            || !preg_match('/^[0-9]{1,20}$/D',$this->identity['poolGuid'] ?? '')
            || !preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/D',$this->identity['hostKey'] ?? '')) {
            throw new InvalidArgumentException('Immutable receiver connection and boot identity required.');
        }
        ZfsasEndpointIdentity::resource('local',$this->identity['pool'] ?? '');
        if (str_contains($this->identity['pool'],'/')) { throw new InvalidArgumentException('Receiver pool name required.'); }
        if (($this->identity['endpoint'] ?? '')!==ZfsasEndpointIdentity::receiver($this->identity['hostKey'],$this->identity['poolGuid'])) {
            throw new InvalidArgumentException('Receiver execution endpoint differs from its verified identity.');
        }
    }

    private static function token(string $attempt,string $action): string
    {
        if (!preg_match('/^[a-f0-9]{48}$/D',$attempt)) { throw new InvalidArgumentException('Coordinator attempt token required.'); }
        return substr(hash('sha256','snapsync-receiver-v1:'.$attempt.':'.$action),0,48);
    }

    private function script(string $mode,string $attempt,string $action,array $arguments=[]): string
    {
        $script=file_get_contents(__DIR__.'/../scripts/replication-receiver.sh');
        if ($script===false) { throw new RuntimeException('Receiver ownership helper is unavailable.'); }
        $args=array_merge([$mode,$this->identity['bootId'],self::token($attempt,$action),$this->identity['poolGuid']],$arguments);
        return ($mode==='run' ? 'setsid ' : '').'bash -c '.escapeshellarg($script).' -- '.implode(' ',array_map('escapeshellarg',$args));
    }

    public function mutation(string $attempt,string $action,string $dataset,string $expected,string $readonly): array
    {
        if (!in_array($action,['receive','readonly'],true) || !in_array($readonly,['on','off'],true)
            || !preg_match('/^(?:absent:)?[0-9]{1,20}$/D',$expected)) {
            throw new InvalidArgumentException('Invalid captured receiver mutation.');
        }
        ZfsasEndpointIdentity::resource('local',$dataset);
        if (!str_contains($dataset,'/') || explode('/',$dataset)[0]!==$this->identity['pool']) { throw new InvalidArgumentException('Mutation belongs to another receiver pool.'); }
        $command=ZfsasSshConnection::arguments($this->connection,$this->identity['hostKey']);
        array_push($command,'--',ZfsasSshConnection::target($this->connection),$this->script('run',$attempt,$action,[$action,$dataset,$expected,$readonly]));
        return $command;
    }

    public function shutdown(string $attempt): array
    {
        // Fence every possible sub-operation before accepting shutdown. Even if
        // a canceled launcher never connected, a delayed connection is rejected.
        $script='set -e; '.$this->script('revoke',$attempt,'readonly').'; '.$this->script('revoke',$attempt,'receive');
        $command=array_merge(['/usr/bin/timeout','--foreground','--signal=TERM','--kill-after=2','20'],
            ZfsasSshConnection::arguments($this->connection,$this->identity['hostKey']));
        array_push($command,'--',ZfsasSshConnection::target($this->connection),$script);
        return $command;
    }
}
