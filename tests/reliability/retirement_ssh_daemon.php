<?php
require __DIR__.'/ssh_receiver_read.php';
$ssh=server('replacement');$retirementSsh=true;
try {require __DIR__.'/retirement_daemon.php';}
finally {proc_terminate($ssh);proc_close($ssh);}
