#!/bin/bash
# Keep output within the owned process group and propagate worker/collector failures.
set -euo pipefail
/usr/local/sbin/zfs_snapsync 2>&1 | php /usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-attempt-log.php
