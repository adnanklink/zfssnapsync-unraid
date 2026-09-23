<?php
require_once __DIR__ . '/response-helpers.php';
require_once __DIR__ . '/coordinator-socket.php';
require_once __DIR__.'/coordinator-service.php';
try {
    $response = zfsas_coordinator_request(['action' => 'status'], '/var/run/zfs-snapsync-coordinator/control.sock', 2);
    if ($response['ok']) $response['result']['service']=zfsas_service_compatibility($response['result']['service'] ?? null);
    zfsas_emit_marked_json($response['ok'] ? ['ok' => true, 'available' => true] + $response['result'] : $response);
} catch (Throwable $error) {
    // Status never starts a service or initializes runtime/persistent storage.
    zfsas_emit_marked_json(['ok' => true, 'available' => false, 'runs' => [], 'message' => 'Coordinator is unavailable; the watchdog will retry.']);
}
