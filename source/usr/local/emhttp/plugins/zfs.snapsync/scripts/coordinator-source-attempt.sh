#!/bin/bash
# The coordinator owns retries and process groups; this adapter owns only gates.
set -Eeuo pipefail
source /usr/local/emhttp/plugins/zfs.snapsync/scripts/ops-queue-lib.sh
trap 'release_dataset_gates || true' EXIT
trap 'exit 143' TERM
trap 'exit 130' INT
CLIENT=/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-worker-client.php
[[ $# -ge 3 ]] || exit 1
capture="$1"; phase="$2"; shift 2
printf '%s\n' '{"phase":"source_retention","message":"Checking source cleanup ownership and dataset gates."}' | php "$CLIENT" progress 1 >/dev/null
if ! unraid_array_actionable; then
  printf '%s\n' '{"outcome":"wait","reason":"array","message":"Array is unavailable."}' | php "$CLIENT" result 2 >/dev/null
  exit 0
fi
if [[ "$phase" == source_retention_delete ]]; then
  mkdir -p "$DELETE_WORKER_RUNTIME_DIR"
  exec 8>"$DELETE_WORKER_RUNTIME_DIR/owner.lock"
  if ! flock -n -x 8; then
    printf '%s\n' '{"outcome":"wait","reason":"resource","message":"Another deletion executor is active."}' | php "$CLIENT" result 2 >/dev/null
    exit 0
  fi
fi
if ! acquire_dataset_gates -x "$@"; then
  printf '%s\n' '{"outcome":"wait","reason":"resource","delay":1,"message":"Another operation owns a source or receiver dataset."}' | php "$CLIENT" result 2 >/dev/null
else
  php /usr/local/emhttp/plugins/zfs.snapsync/php/source-retention-worker.php "$capture"
fi
