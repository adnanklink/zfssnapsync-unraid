<?php
// OpenSSH KnownHostsCommand: existing known_hosts trust still applies. A
// changed-but-newly-trusted key must not execute an older captured operation.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$expected=$argv[1] ?? '';$reason=$argv[2] ?? '';$actual=$argv[3] ?? '';
if (!preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/D',$expected)) { exit(1); }
if ($reason==='ORDER') { exit(0); }
exit(in_array($reason,['HOSTNAME','ADDRESS'],true) && hash_equals($expected,$actual) ? 0 : 1);
