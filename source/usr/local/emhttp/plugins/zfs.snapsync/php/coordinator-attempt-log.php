<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// The launch wrapper supplies this path, never a browser or worker request.
$path=getenv('ZFSAS_ATTEMPT_LOG');
$token=getenv('ZFSAS_ATTEMPT_TOKEN');
if (!is_string($path) || !preg_match('/^[a-f0-9]{48}$/D',(string)$token)
    || !str_starts_with($path,'/tmp/') || basename(dirname($path))!==$token
    || basename($path)!=='output.log' || is_link(dirname($path)) || is_link($path)) { exit(1); }
$tail='';
while (!feof(STDIN)) {
    $line=fgets(STDIN,8192);
    if ($line===false) { break; }
    $tail=substr($tail.$line,-65536);
    $pending=$path.'.pending';
    if (file_put_contents($pending,$tail)!==strlen($tail) || !rename($pending,$path)) { exit(1); }
}
