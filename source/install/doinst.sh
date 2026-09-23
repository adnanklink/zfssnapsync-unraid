#!/bin/sh
set -e
# The plugin manifest activates after upgradepkg finishes replacing/removing files.
# Direct package installations still run the hook here.
if [ "${ZFSAS_DEFER_ACTIVATION:-0}" != 1 ]; then
  /bin/bash /usr/local/emhttp/plugins/zfs.snapsync/scripts/post-install.sh
fi
