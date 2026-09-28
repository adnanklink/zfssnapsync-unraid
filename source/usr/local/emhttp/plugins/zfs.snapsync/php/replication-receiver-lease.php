<?php
require_once __DIR__.'/replication-ssh-receiver.php';

/** Keeps an exact receiver checkpoint under its compatible dataset gates. */
final class ZfsasReceiverLease
{
    private $process;
    private array $pipes=[];
    private string $output='';
    private string $diagnostic='';

    public function __construct(array $receiver,string $attempt)
    {
        $command=(new ZfsasSshReceiver($receiver['receiverCapture']))->guard($attempt,$receiver);
        $this->process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$this->pipes);
        if(!is_resource($this->process)) {throw new RuntimeException('Cannot acquire receiver checkpoint guard.');}
        foreach($this->pipes as $pipe){stream_set_blocking($pipe,false);}
        try {$this->ready();} catch(Throwable $error) {$this->close();throw $error;}
    }

    private function ready(): void
    {
        $end=hrtime(true)/1e9+20;
        do {
            $this->output.=stream_get_contents($this->pipes[1],8192);
            $this->diagnostic.=stream_get_contents($this->pipes[2],8192);
            if(strlen($this->output)>8192 || strlen($this->diagnostic)>65536) {throw new RuntimeException('Receiver checkpoint guard exceeded output limits.');}
            if(str_contains($this->output,"\n")) {
                if($this->output!=="ready\n") {throw new RuntimeException('Invalid receiver checkpoint acknowledgement.');}
                $this->output='';return;
            }
            if(!proc_get_status($this->process)['running']) {throw new RuntimeException('Receiver checkpoint guard stopped: '.substr($this->diagnostic,-4096));}
            $read=[$this->pipes[1],$this->pipes[2]];$write=$except=null;@stream_select($read,$write,$except,0,100000);
        }while(hrtime(true)/1e9<$end);
        throw new RuntimeException('Receiver checkpoint guard timed out.');
    }

    public function check(): void
    {
        if(fwrite($this->pipes[0],"check\n")!==6 || !fflush($this->pipes[0])) {throw new RuntimeException('Receiver checkpoint connection was lost.');}
        $this->ready();
    }

    public function close(): void
    {
        if(!is_resource($this->process)){return;}
        if(isset($this->pipes[0]) && is_resource($this->pipes[0])) {@fwrite($this->pipes[0],"release\n");@fflush($this->pipes[0]);}
        foreach($this->pipes as $pipe){if(is_resource($pipe)){fclose($pipe);}}
        proc_terminate($this->process);proc_close($this->process);$this->process=null;
    }
    public function __destruct(){ $this->close(); }
}
