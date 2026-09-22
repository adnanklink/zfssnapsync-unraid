<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/attention-state.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$root='/tmp/attention-test-'.bin2hex(random_bytes(8));
check(zfsas_attention_read($root)===[]&&!is_dir($root),'Polling created storage');
$op=['id'=>'coordinator:test','createdAt'=>123,'state'=>'failed','recoveryRequired'=>true,'message'=>'Transfer stopped','actions'=>['retry'],'attentionVersion'=>'attempt1'];
$original=$op;$row=zfsas_attention_project([$op],[])[0];check($row['needsAttention'],'Failure missing');
zfsas_attention_save($row['attentionToken'],true,$root);$before=file_get_contents($root.'/dismissed.json');
zfsas_attention_save($row['attentionToken'],true,$root);check(file_get_contents($root.'/dismissed.json')===$before,'Repeated dismissal rewrote decision');
$dismissed=zfsas_attention_project([$op],zfsas_attention_read($root))[0];
check(!$dismissed['needsAttention']&&$dismissed['attentionDismissed']&&$dismissed['state']==='failed'&&$dismissed['recoveryRequired'],'Dismissal changed recovery state');
check(in_array('retry',$dismissed['actions'])&&in_array('restore_attention',$dismissed['actions']),'History actions lost');
check($op===$original,'Original operation mutated');
$op['attentionVersion']='attempt2';check(zfsas_attention_project([$op],zfsas_attention_read($root))[0]['needsAttention'],'New failure hidden by old dismissal');
zfsas_attention_save($row['attentionToken'],false,$root);check(zfsas_attention_project([$original],zfsas_attention_read($root))[0]['needsAttention'],'Restore failed');
check(zfsas_attention_project([$original],[])[0]['needsAttention'],'RAM loss preserved dismissal');
$records=[];for($i=0;$i<1000;$i++)$records[hash('sha256',(string)$i)]=time();file_put_contents($root.'/dismissed.json',json_encode($records));
zfsas_attention_save(hash('sha256','new'),true,$root);check(count(zfsas_attention_read($root))===1000,'Acknowledgements are unbounded');check(isset(zfsas_attention_read($root)[hash('sha256','new')]),'Full store discarded newest acknowledgement');
echo "PASS: attention display-only dismissal, restore, new failure identity, read-only polling and bounded RAM storage\n";
