#!/bin/bash
set -Eeuo pipefail
source /usr/local/emhttp/plugins/zfs.snapsync/scripts/ops-queue-lib.sh
CLIENT=/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-worker-client.php
[[ $# == 3 ]] || exit 1
if ! unraid_array_actionable; then
  printf '%s\n' '{"outcome":"wait","reason":"array","message":"Array is unavailable."}' | php "$CLIENT" result 1 >/dev/null
  exit 0
fi
ensure_runtime_layout
if [[ "$2" == delete ]]; then
  exec 9>"$DELETE_WORKER_RUNTIME_DIR/owner.lock"
  exec 8>"$OPS_ROOT/auto-cleanup.lock"
  if ! flock -n -x 9 || ! flock -n -x 8; then
    printf '%s\n' '{"outcome":"wait","reason":"resource","delay":1,"message":"Another operation owns automatic cleanup."}' | php "$CLIENT" result 1 >/dev/null
    exit 0
  fi
else
  trap 'release_dataset_gates || true' EXIT
  if ! acquire_dataset_gates -x "$3"; then
    printf '%s\n' '{"outcome":"wait","reason":"resource","delay":1,"message":"Another operation owns the snapshot dataset."}' | php "$CLIENT" result 1 >/dev/null
    exit 0
  fi
fi
php /usr/local/emhttp/plugins/zfs.snapsync/php/auto-mutation-worker.php "$1"
