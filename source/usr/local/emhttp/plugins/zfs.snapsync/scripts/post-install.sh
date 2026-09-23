#!/bin/bash
exec 2>&1
set -Eeuo pipefail
INSTALL_STAGE='checking the installation admission barrier'
activation_failed() {
  local status="$?"
  trap - ERR
  local message="Package activation failed while ${INSTALL_STAGE} (exit ${status}). Review the installation output above."
  echo "ERROR: $message"
  # Keep the hook failure visible to status readers and subsequent watchdogs.
  php -r '
    $path="/var/run/zfs-snapsync-coordinator/refresh.json";
    $prior=json_decode((string)@file_get_contents($path),true);
    if (($prior["state"] ?? "") === "blocked") exit;
    $text=json_encode(["state"=>"blocked","message"=>$argv[1]], JSON_THROW_ON_ERROR);
    if (@file_put_contents($path.".install-failure",$text)!==false) @rename($path.".install-failure",$path);
  ' "$message" || true
  exit "$status"
}
trap activation_failed ERR
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
INSTALL_STAGE='applying permissions and configuration'
"${PLUGIN_DIR}/scripts/repair-permissions.sh"
INSTALL_STAGE='migrating legacy runtime records'
"${PLUGIN_DIR}/scripts/migrate-runtime-state.sh"
INSTALL_STAGE='applying schedules'
"${PLUGIN_DIR}/scripts/sync-cron.sh"
if command -v update_cron >/dev/null 2>&1; then update_cron; fi
INSTALL_STAGE='starting and verifying the coordinator'
php "${PLUGIN_DIR}/php/coordinator-lifecycle.php" activate
# Unraid's installer reports through the WebGUI/nchan services. Restarting
# those services here interrupts the caller before plugin registration finishes.
: > /var/run/zfs-snapsync-coordinator/installation-ready
echo 'Package installed and coordinator activation verified.'
