<?php
require_once __DIR__.'/replication-ssh-receiver.php';

/** Independent bounded SSH probes, polled without blocking the socket loop. */
final class ZfsasRemoteShutdown
{
    private array $pending=[];
    private array $verified=[];

    public function poll(array $task,array $attempt): bool
    {
        $p=$task['parameters'];$checks=[];
        if(isset($p['receiverCapture'])) {$checks[]=['capture'=>$p['receiverCapture'],'dataset'=>null];}
        foreach($p['receiverLeases'] ?? [] as $lease) {$checks[]=['capture'=>$lease['receiverCapture'],'dataset'=>$lease['dataset']];}
        if(!$checks) {return false;}
        $all=true;
        foreach($checks as $index=>$check) {
            $key=$attempt['token'].':'.$index;
            if(!isset($this->verified[$key]) && $this->pollOne($key,$attempt['token'],$check)) {$this->verified[$key]=true;}
            if(!isset($this->verified[$key])) {$all=false;}
        }
        if($all) {foreach(array_keys($checks) as $index){unset($this->verified[$attempt['token'].':'.$index]);}}
        return $all;
    }

    private function pollOne(string $token,string $attempt,array $check): bool
    {
        $now=hrtime(true)/1e9;
        if (!isset($this->pending[$token])) { $this->pending[$token]=['retry'=>0]; }
        $entry=&$this->pending[$token];
        if (!isset($entry['process'])) {
            if ($now<$entry['retry']) { return false; }
            try { $command=(new ZfsasSshReceiver($check['capture']))->shutdown($attempt,$check['dataset']); }
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
        $expected=$check['dataset']===null?"stopped\nstopped\nstopped":'stopped';
        $verified=!$entry['overflow'] && $entry['exit']===0 && trim($entry['output'])===$expected;
        foreach($entry['pipes'] as $pipe) {fclose($pipe);}proc_close($entry['process']);
        if ($verified) {unset($this->pending[$token]);return true;}
        $entry=['retry'=>$now+2];return false;
    }
}
