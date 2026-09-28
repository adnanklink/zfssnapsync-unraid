<?php
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-state.php';
require __DIR__.'/../../source/usr/local/emhttp/plugins/zfs.snapsync/php/coordinator-retirement.php';
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function reject($fn){try{$fn();}catch(InvalidArgumentException|RuntimeException $e){return;}throw new RuntimeException('Unsafe retirement approval accepted');}
$root='/tmp/retirement-authority-'.bin2hex(random_bytes(6));$j=new ZfsasCoordinatorState($root);
$pair=['revision'=>'revision'];$token=bin2hex(random_bytes(16));
$ref=['role'=>'base','endpoint'=>'local','dataset'=>'tank/data','datasetGuid'=>'10','snapshot'=>'tank/data@send-base','guid'=>'100'];
$old=$j->submit('old',['tasks'=>['prepare'=>['kind'=>'prepare','dataset'=>'tank/data','references'=>[$ref],'parameters'=>['job'=>['id'=>'aaaaaaaaaaaa']]]]],100)['runId'];
$t=$j->claim($old.':prepare',100,100);$j->result($old.':prepare',$t,['outcome'=>'validation_failure','recoveryRequired'=>true],101,101,true);
$foreign=$j->submit('foreign',['tasks'=>['prepare'=>['kind'=>'prepare','dataset'=>'tank/data','references'=>[$ref],'parameters'=>['job'=>['id'=>'bbbbbbbbbbbb']]]]],100)['runId'];
$t=$j->claim($foreign.':prepare',100,100);$j->result($foreign.':prepare',$t,['outcome'=>'validation_failure','recoveryRequired'=>true],101,101,true);
$review=$j->submit('review',['tasks'=>['target'=>['kind'=>'prepare','dataset'=>'tank/data','parameters'=>['phase'=>'retirement_review']]]],100)['runId'];$t=$j->claim($review.':target',100,100);$j->result($review.':target',$t,['outcome'=>'success'],101,101,true);
$path='/tmp/zfs-snapsync-coordinator/attempt-inputs/'.hash('sha256',$review.':target').'.retirement-review.json';@mkdir(dirname($path),0700,true);file_put_contents($path,json_encode(['target'=>['dataset'=>'tank/data','datasetGuid'=>'10','endpoint'=>'local'],'rows'=>[]]));
$session=['token'=>$token,'dataset'=>'tank/data','saved'=>true,'schedulerApplied'=>true,'savedRevision'=>'revision','jobs'=>[['id'=>'aaaaaaaaaaaa']],'targets'=>[['dataset'=>'tank/data','transport'=>'local','role'=>'source']],'reviewRun'=>$review,'expires'=>time()+300];zfsas_retirement_store($session);
reject(fn()=>zfsas_retirement_request($j,['action'=>'retirement_abandon','token'=>$token],$pair));
$r=zfsas_retirement_request($j,['action'=>'retirement_abandon','token'=>$token,'confirm'=>true],$pair);check($r['resolved']===[$old],'Abandoned unrelated recovery');check($j->deletionReferenceOwners($ref['snapshot'],$ref['guid'],'local')===[$foreign],'Foreign recovery lost protection');check($j->state['tasks'][$old.':prepare']['state']==='failed','Historical outcome overwritten');check($j->state['version']===9,'Old executor not fenced');
// A job removed before retirement still requires an exact source/destination match.
$removedJob=['id'=>'cccccccccccc','source'=>'tank/data','destination'=>'backup/data','transport'=>'local','children'=>'0'];
$removed=$j->submit('removed',['tasks'=>['prepare'=>['kind'=>'prepare','dataset'=>'tank/data','references'=>[$ref],'parameters'=>['job'=>$removedJob]]]],100)['runId'];
$t=$j->claim($removed.':prepare',100,100);$j->result($removed.':prepare',$t,['outcome'=>'validation_failure','recoveryRequired'=>true],101,101,true);
$destReview=$j->submit('review-removed',['tasks'=>['source'=>['kind'=>'prepare','dataset'=>'tank/data'],'destination'=>['kind'=>'prepare','dataset'=>'backup/data']]],100)['runId'];
foreach(['source'=>'tank/data','destination'=>'backup/data'] as $role=>$dataset){$id=$destReview.':'.$role;$t=$j->claim($id,100,100);$j->result($id,$t,['outcome'=>'success'],101,101,true);file_put_contents(dirname($path).'/'.hash('sha256',$id).'.retirement-review.json',json_encode(['target'=>['role'=>$role,'transport'=>'local','dataset'=>$dataset,'datasetGuid'=>$role==='source'?'10':'20','endpoint'=>'local'],'rows'=>[]]));}
$session['jobs']=[];$session['reviewRun']=$destReview;zfsas_retirement_store($session);
$r=zfsas_retirement_request($j,['action'=>'retirement_abandon','token'=>$token,'confirm'=>true],$pair);check($r['resolved']===[$removed],'Explicit destination did not recover an already-removed job');check($j->deletionReferenceOwners($ref['snapshot'],$ref['guid'],'local')===[$foreign],'Removed-job abandonment released foreign recovery');
// Mixed Auto scope must be rejected before touching saved configuration.
$mixed=$j->submit('mixed',['tasks'=>['auto'=>['kind'=>'auto','dataset'=>'tank/data','parameters'=>['individualMutations'=>true,'mutationDatasets'=>['tank/data','tank/other']]]]],100)['runId'];
$inspect=$session;$inspect['saved']=false;$inspect['createdAt']=time();$inspect['revision']='revision';zfsas_retirement_store($inspect);
check(in_array($mixed,zfsas_retirement_busy($j,$session['targets']),true),'Mixed Auto scope not detected');
reject(fn()=>zfsas_retirement_request($j,['action'=>'retirement_stop','token'=>$token],$pair));
$session['expires']=time()-1;zfsas_retirement_store($session);reject(fn()=>zfsas_retirement_request($j,['action'=>'retirement_submit','token'=>$token,'confirm'=>true],$pair));
$session['expires']=time()+300;zfsas_retirement_store($session);reject(fn()=>zfsas_retirement_request($j,['action'=>'retirement_submit','token'=>$token,'confirm'=>true],['revision'=>'changed']));
echo "PASS: explicit retirement recovery abandonment, foreign references preserved, historical outcome preserved, journal fence, expiry and stale revision\n";
