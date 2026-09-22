<?php
if (!is_file('/.dockerenv')) { exit(77); }
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/snapshot-manager-helpers.php';
$bin=sys_get_temp_dir().'/inventory-fixture-'.bin2hex(random_bytes(5));mkdir($bin);
file_put_contents($bin.'/zfs', '#!/bin/bash'."\n".'echo call >> "'.$bin.'/calls"'."\n".'for ((i=0;i<500;i++)); do printf "tank/cache@snap%s\\t1700000000\\t100\\t50\\t0\\t%s\\t%s\\t-\\n" "$i" "$((i+1))" "$((i+1))"; done'."\n");chmod($bin.'/zfs',0755);putenv('PATH='.$bin.':'.getenv('PATH'));
try {
 zfsas_sm_invalidate_inventory('tank/cache');
 $rows=zfsas_sm_dataset_snapshots('tank/cache',$error);
 if($error || count($rows)!==500)throw new RuntimeException('Cold inventory failed: '.$error);
 $cache=json_decode(file_get_contents(zfsas_sm_inventory_path('tank/cache')),true);
 if($cache['expires']-microtime(true)<50)throw new RuntimeException('Browse cache too short');
 zfsas_sm_dataset_snapshots('tank/cache',$error);
 if(count(file($bin.'/calls'))!==1)throw new RuntimeException('Cached browse rescanned ZFS');
 zfsas_sm_dataset_snapshots('tank/cache',$error,true);
 if(count(file($bin.'/calls'))!==2)throw new RuntimeException('Fresh validation used cached metadata');
 echo "PASS: 500-snapshot cold load, RAM cache reuse, fresh validation bypass\n";
} finally {zfsas_sm_invalidate_inventory('tank/cache');unlink($bin.'/zfs');@unlink($bin.'/calls');rmdir($bin);}
