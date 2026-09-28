#!/bin/bash
set -Eeuo pipefail
source /usr/local/emhttp/plugins/zfs.snapsync/scripts/ops-queue-lib.sh
CLIENT=/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-worker-client.php
[[ $# == 1 ]] || exit 1
if ! unraid_array_actionable; then
  printf '%s\n' '{"outcome":"wait","reason":"array","message":"Array is unavailable."}' | php "$CLIENT" result 1 >/dev/null
  exit 0
fi
ensure_runtime_layout
exec 9>"$DELETE_WORKER_RUNTIME_DIR/owner.lock"
if ! flock -n 9; then
  printf '%s\n' '{"outcome":"wait","reason":"resource","delay":1,"message":"Another deletion worker is active."}' | php "$CLIENT" result 1 >/dev/null
  exit 0
fi
php /usr/local/emhttp/plugins/zfs.snapsync/php/replication-ssh-delete-worker.php "$1"
