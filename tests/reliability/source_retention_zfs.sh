#!/bin/bash
# Only explicitly enabled disposable file-vdev pools; never use existing pools.
set -Eeuo pipefail
[[ -f /.dockerenv && "${ZFSAS_DISPOSABLE_POOL_TEST:-}" == 1 ]] || exit 77
fixture="$(mktemp -d "${ZFSAS_POOL_FIXTURE_ROOT:?}/source-retention.XXXXXXXX")"
nonce="$(basename "$fixture" | tr -cd 'a-zA-Z0-9')"
source_pool="zfsas_test_${nonce}_src"; target_pool="zfsas_test_${nonce}_dst"
source_guid=''; target_guid=''
cleanup() {
  local rc=$? pool expected
  trap - EXIT INT TERM
  for pool in "$source_pool" "$target_pool"; do
    expected="$source_guid"; [[ "$pool" != "$target_pool" ]] || expected="$target_guid"
    if [[ -n "$expected" && "$(zpool get -H -o value guid "$pool" 2>/dev/null || true)" == "$expected" ]]; then zpool destroy "$pool" || rc=1; fi
  done
  if (( rc == 0 )); then rm -rf "$fixture"; else echo "Preserved fixture: $fixture" >&2; fi
  exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
truncate -s 512M "$fixture/source.vdev" "$fixture/destination.vdev"
zpool create -o cachefile=none -O mountpoint=none "$source_pool" "$fixture/source.vdev"
source_guid="$(zpool get -H -o value guid "$source_pool")"
zpool create -o cachefile=none -O mountpoint=none "$target_pool" "$fixture/destination.vdev"
target_guid="$(zpool get -H -o value guid "$target_pool")"
php tests/reliability/source_retention_zfs.php "$source_pool" "$target_pool" "$fixture"
