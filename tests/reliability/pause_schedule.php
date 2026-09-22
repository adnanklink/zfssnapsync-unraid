<?php
if (!is_file('/.dockerenv')) { exit(77); }
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/send-queue-helpers.php';
function verify_pause($ok) { if (!$ok) { throw new RuntimeException('Pause assertion failed'); } }
$dir=zfsas_ops_plugin_config_dir();@mkdir($dir,0775,true);
file_put_contents($dir.'/zfs_send.conf', 'SEND_JOBS="abcdef123456|tank/source|backup/source|6h|100G|0|local"'."\n");
verify_pause(!zfsas_ops_pause_schedule('../invalid',$error));
verify_pause(!zfsas_ops_pause_schedule('000000000000',$error));
verify_pause(zfsas_ops_pause_schedule('abcdef123456',$error));
$path=zfsas_ops_control_path('paused','abcdef123456');$inode=fileinode($path);
verify_pause(zfsas_ops_pause_schedule('abcdef123456',$error));clearstatcache();verify_pause(fileinode($path)===$inode);
verify_pause(zfsas_ops_resume_schedule('abcdef123456',$error));verify_pause(!is_file($path));
echo "PASS: known schedule pause, invalid/missing IDs, idempotence and Resume\n";
