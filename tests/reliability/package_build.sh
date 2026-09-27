#!/bin/bash
# Only temporary copies are changed; no plugin is installed.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
fixture="$(mktemp -d)"
trap 'rm -rf "$fixture"' EXIT
cp -a "$root/source" "$root/scripts" "$root/VERSION" "$root/zfs.snapsync.plg.in" "$fixture/"
export SOURCE_DATE_EPOCH=1700000000
bash "$fixture/scripts/build-release.sh" fixture https://example.invalid/dist > "$fixture/first.log"
cp "$fixture/dist/zfs-snapsync-fixture-noarch-1.txz" "$fixture/first.txz"
cp "$fixture/dist/zfs.snapsync.plg" "$fixture/first.plg"
# Checkout mtimes must not affect release bytes.
find "$fixture/source" -type f -exec touch -d @1800000000 {} +
find "$fixture/source" -type f -exec chmod g-w {} +
bash "$fixture/scripts/build-release.sh" fixture https://example.invalid/dist > "$fixture/second.log"
cmp "$fixture/first.txz" "$fixture/dist/zfs-snapsync-fixture-noarch-1.txz"
cmp "$fixture/first.plg" "$fixture/dist/zfs.snapsync.plg"
printf '\nchanged\n' >> "$fixture/source/usr/local/sbin/zfs_snapsync"
if bash "$fixture/scripts/verify-release.sh" "$fixture/first.txz" "$fixture" > "$fixture/rejected.log" 2>&1; then
  echo 'Verifier accepted stale package contents' >&2
  exit 1
fi
printf '%s\n' 'PASS: reproducible package/manifest bytes and stale source rejection'
