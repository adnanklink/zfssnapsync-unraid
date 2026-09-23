#!/bin/bash
# Exercise manifest ordering and failures in disposable production-path isolation.
set -euo pipefail
[[ -f /.dockerenv ]] || exit 77
plugin=/usr/local/emhttp/plugins/zfs.snapsync
config=/boot/config/plugins/zfs.snapsync
mkdir -p "$plugin" "$config" /var/log/packages /tmp/install-bin /etc/rc.d
# Service reloads would terminate the real installer's reporting connection.
for service in rc.php-fpm rc.nginx; do
  printf '#!/bin/bash\necho invoked > /tmp/webgui-restarted\nexit 91\n' > "/etc/rc.d/$service"
  chmod +x "/etc/rc.d/$service"
done
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
  if bash /tmp/install-manifest.sh >/tmp/install-output 2>/tmp/install-hidden-errors; then cat /tmp/install-output; echo 'Unexpected installation success' >&2; exit 1; fi
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
grep -q 'Legacy work is active' /tmp/install-output
grep -q 'checking coordinator and worker ownership before replacement' /tmp/install-output
[[ ! -s /tmp/install-hidden-errors ]]
[[ "$before" == "$(sha256sum "$plugin/php/coordinator-daemon.php")" ]]
kill -- -"$worker"
wait "$worker" || true
trap - EXIT
# A package manager that suppresses a failing doinst hook cannot report activation.
printf '#!/bin/bash\nexit 17\n' > "$plugin/scripts/repair-permissions.sh"
: > /tmp/run-install-hook
expect_failure
[[ -f /tmp/package-replaced && ! -f /var/run/zfs-snapsync-coordinator/installation-ready ]]
grep -q 'applying permissions and configuration (exit 17)' /tmp/install-output
[[ ! -s /tmp/install-hidden-errors ]]
[[ "$(cat "$config/maintenance")" == installation ]]
grep -q 'applying permissions and configuration (exit 17)' /var/run/zfs-snapsync-coordinator/refresh.json
cp /var/run/zfs-snapsync-coordinator/refresh.json /tmp/hook-blocker-before
# Watchdog checks preserve the installation blocker rather than overwrite it.
php "$plugin/php/coordinator-lifecycle.php" watchdog >/tmp/watchdog-output 2>&1 && exit 1
cmp /var/run/zfs-snapsync-coordinator/refresh.json /tmp/hook-blocker-before
[[ "$(cat "$config/maintenance")" == installation ]]
# With a skipped package hook, explicit activation must still propagate failures.
rm -f /tmp/run-install-hook
expect_failure
grep -q 'applying permissions and configuration (exit 17)' /tmp/install-output
[[ "$(cat "$config/maintenance")" == installation ]]
for helper in repair-permissions sync-cron migrate-runtime-state; do printf '#!/bin/bash\nexit 0\n' > "$plugin/scripts/$helper.sh"; done
# Retrying with surviving work must keep the barrier and leave files intact.
rm -f /tmp/package-replaced
setsid /usr/local/sbin/zfs_snapsync_send_worker &
worker=$!
trap 'kill -- -"$worker" 2>/dev/null || true' EXIT
sleep .1
expect_failure
[[ ! -f /tmp/package-replaced && "$(cat "$config/maintenance")" == installation ]]
kill -- -"$worker"
wait "$worker" || true
trap - EXIT
# Skipped package hook: manifest must activate and verify the service itself.
rm -f /tmp/run-install-hook
bash /tmp/install-manifest.sh >/tmp/install-output 2>/tmp/install-hidden-errors || { cat /tmp/install-output; exit 1; }
[[ -f /var/run/zfs-snapsync-coordinator/installation-ready && ! -f "$config/maintenance" ]]
# When the package hook runs, it must defer to the manifest (one activation only).
: > /tmp/run-install-hook
bash /tmp/install-manifest.sh >/tmp/install-output 2>/tmp/install-hidden-errors || { cat /tmp/install-output; exit 1; }
[[ "$(grep -c 'Package installed and coordinator activation verified.' /tmp/install-output)" == 1 ]]
# Exercise real configuration, migration and cron helpers, not only hook stubs.
for helper in repair-permissions sync-cron migrate-runtime-state; do
  cp "source/usr/local/emhttp/plugins/zfs.snapsync/scripts/$helper.sh" "$plugin/scripts/$helper.sh"
done
mkdir -p /etc/cron.d
cat > /tmp/install-bin/update_cron <<'WORKER'
#!/bin/bash
if [[ -f /tmp/fail-cron-activation ]]; then echo 'fixture: cron activation failed' >&2; exit 23; fi
exit 0
WORKER
chmod +x /tmp/install-bin/update_cron
: > /tmp/fail-cron-activation
expect_failure
[[ "$(cat "$config/maintenance")" == installation ]]
grep -q 'applying schedules (exit 23)' /tmp/install-output
grep -q 'applying schedules (exit 23)' /var/run/zfs-snapsync-coordinator/refresh.json
rm /tmp/fail-cron-activation
bash /tmp/install-manifest.sh >/tmp/install-output 2>/tmp/install-hidden-errors || { cat /tmp/install-output; exit 1; }
[[ -f /var/run/zfs-snapsync-coordinator/installation-ready && ! -f "$config/maintenance" && ! -s /tmp/install-hidden-errors ]]
grep -q 'zfs_snapsync_coordinator watchdog' /etc/cron.d/zfs_snapsync
[[ ! -e /tmp/webgui-restarted ]]
echo 'PASS: WebGUI stays running, stdout-only errors, busy preflight preserves release, failed/skipped hook barriers survive, watchdog preserves blockers, idle retry succeeds without manual flag removal'
