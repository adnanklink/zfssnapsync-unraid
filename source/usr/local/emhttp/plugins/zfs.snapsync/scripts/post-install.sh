#!/bin/bash
set -euo pipefail
PLUGIN_DIR=/usr/local/emhttp/plugins/zfs.snapsync
# The manifest establishes the barrier before upgradepkg replaces any files.
# Direct package installation must also prove the service idle before activation.
if [[ ! -f /boot/config/plugins/zfs.snapsync/maintenance ]]; then
  php "${PLUGIN_DIR}/php/coordinator-lifecycle.php" prepare
fi
# Retire removed legacy recovery entry points only after the idle preflight.
rm -f /usr/local/emhttp/plugins/zfs.snapsync/php/recovery-tools.php
rm -f /usr/local/emhttp/plugins/zfs.snapsync/php/recovery-status.php
rm -f /usr/local/emhttp/plugins/zfs.snapsync/php/recovery-action.php
rm -f /usr/local/emhttp/plugins/zfs.snapsync/php/recovery-helpers.php
rm -f /usr/local/sbin/zfs_snapsync_recovery_scan
"${PLUGIN_DIR}/scripts/repair-permissions.sh"
"${PLUGIN_DIR}/scripts/migrate-runtime-state.sh"
"${PLUGIN_DIR}/scripts/sync-cron.sh"
if command -v update_cron >/dev/null 2>&1; then update_cron; fi
php "${PLUGIN_DIR}/php/coordinator-lifecycle.php" activate
if [[ -x /etc/rc.d/rc.php-fpm ]]; then /etc/rc.d/rc.php-fpm reload; fi
if [[ -x /etc/rc.d/rc.nginx ]]; then /etc/rc.d/rc.nginx reload; fi
: > /var/run/zfs-snapsync-coordinator/installation-ready
echo 'Package installed and coordinator activation verified.'
