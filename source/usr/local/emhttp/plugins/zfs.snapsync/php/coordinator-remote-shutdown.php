<?php
require_once __DIR__.'/replication-ssh-receiver.php';

/** Independent bounded SSH probes, polled without blocking the socket loop. */
final class ZfsasRemoteShutdown
{
    private array $pending=[];

    public function poll(array $task,array $attempt): bool
    {
        $token=$attempt['token'];$now=hrtime(true)/1e9;
        if (!isset($this->pending[$token])) { $this->pending[$token]=['retry'=>0]; }
        $entry=&$this->pending[$token];
        if (!isset($entry['process'])) {
            if ($now<$entry['retry']) { return false; }
            try { $command=(new ZfsasSshReceiver($task['parameters']['receiverCapture'] ?? []))->shutdown($token); }
            catch (Throwable $error) { $entry['retry']=$now+5;return false; }
            $process=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if (!is_resource($process)) { $entry['retry']=$now+5;return false; }
            foreach($pipes as $pipe) {stream_set_blocking($pipe,false);}
            $entry=['process'=>$process,'pipes'=>$pipes,'output'=>'','error'=>'','overflow'=>false];
            return false;
        }
        $entry['output'].=stream_get_contents($entry['pipes'][1],8192);
        $entry['error'].=stream_get_contents($entry['pipes'][2],8192);
        if (strlen($entry['output'])>8192 || strlen($entry['error'])>8192) {
            $entry['overflow']=true;proc_terminate($entry['process']);
            $entry['output']=substr($entry['output'],-8192);$entry['error']=substr($entry['error'],-8192);
        }
        $status=proc_get_status($entry['process']);
        if (!$status['running'] && !isset($entry['exit'])) {$entry['exit']=$status['exitcode'];}
        if ($status['running'] || !feof($entry['pipes'][1]) || !feof($entry['pipes'][2])) { return false; }
        $verified=!$entry['overflow'] && $entry['exit']===0 && trim($entry['output'])==="stopped\nstopped";
        foreach($entry['pipes'] as $pipe) {fclose($pipe);}proc_close($entry['process']);
        if ($verified) {unset($this->pending[$token]);return true;}
        $entry=['retry'=>$now+2];return false;
    }
}
