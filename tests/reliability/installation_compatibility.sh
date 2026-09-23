#!/bin/bash
# Exercise manifest ordering and failures in disposable production-path isolation.
set -euo pipefail
[[ -f /.dockerenv ]] || exit 77
plugin=/usr/local/emhttp/plugins/zfs.snapsync
config=/boot/config/plugins/zfs.snapsync
mkdir -p "$plugin" "$config" /var/log/packages /tmp/install-bin
cp -a source/usr/local/emhttp/plugins/zfs.snapsync/. "$plugin/"
printf 'DATASETS=""\nPREFIX="auto-"\n' > "$config/zfs_snapsync.conf"
printf 'SEND_SNAPSHOT_PREFIX="send-"\n' > "$config/zfs_send.conf"
# Standalone helper payload as downloaded by the manifest.
tar -cJf "$config/zfs-snapsync-fixture-noarch-1.txz" -C source .
export PATH="/tmp/install-bin:$PATH"
cat > /tmp/install-bin/upgradepkg <<'WORKER'
#!/bin/bash
set -e
: > /tmp/package-replaced
: > /var/log/packages/zfs-snapsync-fixture
if [[ -f /tmp/run-install-hook ]]; then /bin/sh /work/source/install/doinst.sh || true; fi
WORKER
chmod +x /tmp/install-bin/upgradepkg
python3 - <<'PY'
from pathlib import Path
import hashlib,xml.etree.ElementTree as ET
package=Path('/boot/config/plugins/zfs.snapsync/zfs-snapsync-fixture-noarch-1.txz')
text=Path('zfs.snapsync.plg.in').read_text().replace('__VERSION__','fixture').replace('__BASE_URL__','https://invalid.test').replace('__PKG_MD5__',hashlib.md5(package.read_bytes()).hexdigest())
root=ET.fromstring(text)
script=next(node.find('INLINE').text for node in root.findall('FILE') if node.get('Run')=='/bin/bash' and node.get('Method') is None)
Path('/tmp/install-manifest.sh').write_text(script)
PY
expect_failure() {
  if bash /tmp/install-manifest.sh >/tmp/install-output 2>&1; then cat /tmp/install-output; echo 'Unexpected installation success' >&2; exit 1; fi
}
# Active legacy work must block before the package-manager call.
cat > /usr/local/sbin/zfs_snapsync_send_worker <<'WORKER'
#!/bin/bash
sleep 60
WORKER
chmod +x /usr/local/sbin/zfs_snapsync_send_worker
setsid /usr/local/sbin/zfs_snapsync_send_worker &
worker=$!
trap 'kill -- -"$worker" 2>/dev/null || true' EXIT
sleep .1
before="$(sha256sum "$plugin/php/coordinator-daemon.php")"
expect_failure
[[ ! -f /tmp/package-replaced && ! -f "$config/maintenance" ]]
[[ "$before" == "$(sha256sum "$plugin/php/coordinator-daemon.php")" ]]
kill -- -"$worker"
wait "$worker" || true
trap - EXIT
# A package manager that suppresses a failing doinst hook cannot report activation.
printf '#!/bin/bash\nexit 17\n' > "$plugin/scripts/repair-permissions.sh"
: > /tmp/run-install-hook
expect_failure
[[ -f /tmp/package-replaced && ! -f /var/run/zfs-snapsync-coordinator/installation-ready ]]
rm -f "$config/maintenance"
# A skipped hook also fails the explicit verification step.
rm -f /tmp/run-install-hook
for helper in repair-permissions sync-cron migrate-runtime-state; do printf '#!/bin/bash\nexit 0\n' > "$plugin/scripts/$helper.sh"; done
expect_failure
grep -q 'installation hook failed' /tmp/install-output
rm -f "$config/maintenance"
# Successful hook, matching handshake and registration are all required.
: > /tmp/run-install-hook
bash /tmp/install-manifest.sh >/tmp/install-output 2>&1 || { cat /tmp/install-output; exit 1; }
[[ -f /var/run/zfs-snapsync-coordinator/installation-ready && ! -f "$config/maintenance" ]]
echo 'PASS: busy preflight preserves release, critical hook failure propagates, ignored/skipped hook rejected, matching activation verified'
