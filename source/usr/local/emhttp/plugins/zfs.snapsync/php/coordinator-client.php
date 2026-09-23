<?php
require_once __DIR__ . '/coordinator-socket.php';

function zfsas_coordinator_ensure(): void
{
    try {
        $reply=zfsas_coordinator_request(['action'=>'status'], '/var/run/zfs-snapsync-coordinator/control.sock', .5);
        if ($reply['ok']) return;
    } catch (RuntimeException $error) {}
    throw new RuntimeException('Coordinator is unavailable. The root watchdog will retry startup; inspect its log if this persists.');
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $action = $argv[1] ?? 'watchdog';
        if (!in_array($action, ['watchdog', 'reload', 'auto', 'replication_now', 'delete', 'status', 'cancel', 'resume'], true)) { throw new InvalidArgumentException('Unknown coordinator command.'); }
        if ($action === 'watchdog') { require_once __DIR__.'/coordinator-lifecycle.php'; zfsas_lifecycle('watchdog'); exit(0); }
        if ($action !== 'status') { zfsas_coordinator_ensure(); }
        $request = ['action' => $action];
        if ($action === 'auto') { $request['commandId'] = $argv[2] ?? 'manual-auto-' . bin2hex(random_bytes(16)); }
        if ($action === 'replication_now') { $request['commandId']=$argv[2] ?? 'manual-send-'.bin2hex(random_bytes(16)); }
        if ($action === 'cancel') { $request['runId'] = $argv[2] ?? ''; }
        $response = zfsas_coordinator_request($request);
        if ($action !== 'watchdog' || !$response['ok']) { echo json_encode($response, JSON_THROW_ON_ERROR) . "\n"; }
        exit($response['ok'] ? 0 : 1);
    } catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
