#!/bin/bash
set -Eeuo pipefail
source /usr/local/emhttp/plugins/zfs.snapsync/scripts/ops-queue-lib.sh
CLIENT=/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-worker-client.php
[[ $# == 4 ]] || exit 1
trap 'release_dataset_gates || true' EXIT
if ! unraid_array_actionable; then
 printf '%s\n' '{"outcome":"wait","reason":"array","message":"Array is unavailable."}' | php "$CLIENT" result 1 >/dev/null
 exit 0
fi
ensure_runtime_layout
if [[ "$2" == retirement_delete ]]; then
 exec 9>"$DELETE_WORKER_RUNTIME_DIR/owner.lock"
 if ! flock -n -x 9 || { [[ "$4" == local ]] && ! acquire_dataset_gates -x "$3"; }; then
  printf '%s\n' '{"outcome":"wait","reason":"resource","message":"Another operation owns cleanup or the dataset."}' | php "$CLIENT" result 1 >/dev/null
  exit 0
 fi
fi
php /usr/local/emhttp/plugins/zfs.snapsync/php/retirement-worker.php "$1"
