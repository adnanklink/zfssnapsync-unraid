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

    public function shutdown(string $attempt,?string $guardDataset=null): array
    {
        // Fence every possible sub-operation before accepting shutdown. Even if
        // a canceled launcher never connected, a delayed connection is rejected.
        $script='set -e; '.$this->script('revoke',$attempt,'readonly').'; '.$this->script('revoke',$attempt,'receive').'; '.$this->script('revoke',$attempt,'destroy');
        if($guardDataset!==null) {
            ZfsasEndpointIdentity::resource('local',$guardDataset);
            $script=$this->script('revoke',$attempt,'guard-'.hash('sha256',$guardDataset));
        }
        $command=array_merge(['/usr/bin/timeout','--foreground','--signal=TERM','--kill-after=2','20'],
            ZfsasSshConnection::arguments($this->connection,$this->identity['hostKey']));
        array_push($command,'--',ZfsasSshConnection::target($this->connection),$script);
        return $command;
    }

    public function guard(string $attempt,array $receiver): array
    {
        $dataset=$receiver['dataset'] ?? '';ZfsasEndpointIdentity::resource('local',$dataset);
        if(explode('/',$dataset)[0]!==$this->identity['pool'] || !str_contains($dataset,'/')) {throw new InvalidArgumentException('Receiver checkpoint belongs to another pool.');}
        foreach([$receiver['datasetGuid'] ?? '',$receiver['base']['guid'] ?? ''] as $guid) {
            if(!preg_match('/^[0-9]{1,20}$/D',$guid)) {throw new InvalidArgumentException('Captured receiver checkpoint identity required.');}
        }
        $snapshot=$receiver['base']['snapshot'] ?? '';
        if(!str_starts_with($snapshot,$dataset.'@') || !preg_match('/^[A-Za-z0-9_.:+-]+$/D',substr($snapshot,strlen($dataset)+1))) {throw new InvalidArgumentException('Invalid receiver checkpoint name.');}
        $command=ZfsasSshConnection::arguments($this->connection,$this->identity['hostKey']);
        array_push($command,'--',ZfsasSshConnection::target($this->connection),$this->script('run',$attempt,'guard-'.hash('sha256',$dataset),[
            'guard',$dataset,$receiver['datasetGuid'],'on',$snapshot,$receiver['base']['guid']]));
        return $command;
    }

    public function deletion(string $attempt,array $job,string $inventoryHash): array
    {
        $dataset=$job['DATASET'] ?? '';ZfsasEndpointIdentity::resource('local',$dataset);
        if (explode('/',$dataset)[0]!==$this->identity['pool'] || !str_contains($dataset,'/')
            || !preg_match('/^[a-f0-9]{64}$/D',$inventoryHash)) {throw new InvalidArgumentException('Invalid receiver deletion inventory binding.');}
        foreach(['DATASET_GUID','SNAPSHOT_GUID','SNAPSHOT_CREATETXG'] as $field) {
            if(!preg_match('/^[0-9]{1,20}$/D',$job[$field] ?? '')) {throw new InvalidArgumentException('Captured receiver deletion identity required.');}
        }
        if (!str_starts_with($job['SNAPSHOT'] ?? '',$dataset.'@') || !preg_match('/^[A-Za-z0-9_.:+-]+$/D',substr($job['SNAPSHOT'],strlen($dataset)+1))) {throw new InvalidArgumentException('Invalid receiver snapshot.');}
        $command=ZfsasSshConnection::arguments($this->connection,$this->identity['hostKey']);
        array_push($command,'--',ZfsasSshConnection::target($this->connection),$this->script('run',$attempt,'destroy',[
            'destroy',$dataset,$job['DATASET_GUID'],'on',$job['SNAPSHOT'],$job['SNAPSHOT_GUID'],$job['SNAPSHOT_CREATETXG'],$inventoryHash]));
        return $command;
    }
}
