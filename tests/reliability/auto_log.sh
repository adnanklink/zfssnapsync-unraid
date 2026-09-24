#!/bin/bash
set -euo pipefail
[[ -f /.dockerenv ]] || exit 77
fixture=$(mktemp -d /tmp/auto-log.XXXXXXXX)
trap 'rm -rf "$fixture"' EXIT
export ZFSAS_ATTEMPT_TOKEN
ZFSAS_ATTEMPT_TOKEN=$(printf '%048d' 1)
mkdir "$fixture/$ZFSAS_ATTEMPT_TOKEN"
export ZFSAS_ATTEMPT_LOG="$fixture/$ZFSAS_ATTEMPT_TOKEN/output.log"
mkdir -p /usr/local/sbin
cat > /usr/local/sbin/zfs_snapsync <<'WORKER'
#!/bin/bash
head -c 200000 /dev/zero | tr '\0' x
printf '\nstdout-marker\n'
printf 'stderr-marker\n' >&2
exit 7
WORKER
chmod +x /usr/local/sbin/zfs_snapsync
rc=0
bash source/usr/local/emhttp/plugins/zfs.snapsync/scripts/coordinator-auto-attempt.sh || rc=$?
[[ "$rc" == 7 ]]
[[ $(stat -c %s "$ZFSAS_ATTEMPT_LOG") == 65536 ]]
grep -q stdout-marker "$ZFSAS_ATTEMPT_LOG"
grep -q stderr-marker "$ZFSAS_ATTEMPT_LOG"
echo 'PASS: bounded auto stdout/stderr capture and worker failure exit propagation'
