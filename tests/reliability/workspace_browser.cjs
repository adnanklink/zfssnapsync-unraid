const {chromium}=require('/opt/zfsas-tests/node_modules/playwright-core');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const plugin=path.resolve(__dirname,'../../source/usr/local/emhttp/plugins/zfs.snapsync');
const summary={ok:true,generatedAt:1700000000,timezone:'UTC',sources:{configuration:{available:true},coordinator:{available:true}},operations:[{id:'coordinator:batch',nativeId:'batch-run',type:'batch',title:'Snapshot batch',state:'running',createdAt:1699999999,actions:['cancel'],url:'?section=snapshots',logType:'batch'},{id:'coordinator:example',nativeId:'example',type:'auto',title:'Automatic snapshots',state:'running',createdAt:1700000000,actions:['cancel'],url:'?section=snapshots&tab=automation',logType:'auto'},{id:'replication:recovery',nativeId:'recovery',type:'replication',title:'Interrupted snapshot creation',state:'failed',createdAt:1700000000,recoveryRequired:true,actions:['clear_failed'],url:'?section=replication',logType:'replication'}],schedules:[{id:'abcdef123456',type:'replication',paused:false,preview:{nextScheduledText:'Tomorrow'}}],pausedSchedules:[]};
summary.operations[2].source=process.env.ZFSAS_DOC_CAPTURE?'tank/photos':'tank/'+ 'long-dataset-name-'.repeat(30);
summary.schedules[0].label='Photos backup';
summary.operations.push({id:'coordinator:transfer',nativeId:'transfer',type:'replication',title:'Active transfer',state:'running',phase:'transfer',progress:42,message:'42 MiB sent · 8.0 MiB/s',actions:[],url:'?section=activity'});
summary.operations.push({id:'coordinator:native',nativeId:'native-run',type:'replication',coordinator:true,manual:true,title:'Native replication',state:'failed',createdAt:1700000000,recoveryRequired:true,actions:['retry'],url:'?section=activity'});
(async()=>{const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});try{
 for(const query of ['section=overview','section=snapshots','section=snapshots&tab=automation','section=replication','section=activity','section=tools','section=tools&tab=migrator','section=help']){
 const html=execFileSync('php',['-r','parse_str($argv[1],$_GET); require $argv[2];',query,plugin+'/php/workspace.php'],{encoding:'utf8'});
 const page=await browser.newPage({viewport:{width:1440,height:1000}}),errors=[];let summaryRequests=0,mutationRequests=0,discoveryRequests=0,saveMode='ok',savePosts=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://workspace.test/**',async route=>{const url=new URL(route.request().url());
 if(url.pathname.endsWith('.png'))return route.fulfill({contentType:'image/png',body:fs.readFileSync(plugin+url.pathname.replace('/plugins/zfs.snapsync',''))});
 if(/\.(js|css)$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.js')?'application/javascript':'text/css',body:fs.readFileSync(plugin+url.pathname.replace('/plugins/zfs.snapsync',''),'utf8')});
 if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
 let data={ok:true,probe:true,spec:{kind:'interval',seconds:21600},status:{},datasets:[{dataset:'tank/data',mountpoint:'/mnt/tank/data',pool:'tank',sendDestination:false}],snapshots:[],jobs:[],pausedSchedules:[],pendingDeleteCount:0,content:Array.from({length:200},(_,i)=>'Test log '+i).join('\n'),logTail:[],docker:{runningContainers:[]}};
 if(url.pathname.endsWith('dataset-inventory.php')){
   discoveryRequests++;
   if(discoveryRequests===1){await new Promise(resolve=>setTimeout(resolve,300));data={ok:false,error:'Test discovery failure'};}
   if(discoveryRequests===3) await new Promise(resolve=>setTimeout(resolve,1200));
 }
 if(url.pathname.endsWith('save-send-settings.php')){savePosts.push(new URLSearchParams(route.request().postData()));await new Promise(resolve=>setTimeout(resolve,150));data={ok:true,saved:true,schedulerApplied:true,revision:'saved',errors:[],jobs:[{id:'abcdef123456',source:'tank/anchor-test',destination:'backup/anchor-test'}]};if(saveMode==='failure')data={ok:false,saved:false,errors:['Settings changed. Your draft is preserved.']};if(saveMode==='runtime'){data.schedulerApplied=false;data.ok=false;data.errors=['Scheduler application failed'];}}
 if(url.pathname.endsWith('save-interface-settings.php')) data={ok:true,enabled:true,revision:'saved-revision'};
 if(url.pathname.endsWith('send-queue-action.php')){mutationRequests++;const params=new URLSearchParams(route.request().postData()||'');if(['pause','resume'].includes(params.get('action')))summary.schedules[0].paused=params.get('action')==='pause';}
 if(url.pathname.endsWith('coordinator-status.php'))data={ok:true,available:true,autoPaused:false,runs:[{id:'test-run',kinds:['auto'],state:'running'}]};
 if(url.pathname.endsWith('workspace-summary.php')){summaryRequests++;data=summary;}
 if(url.pathname.endsWith('migrate-datasets-status.php') && url.searchParams.get('dataset'))data.preview={folders:[]};
 return route.fulfill({contentType:'text/plain',body:'ZFSAS_JSON_BEGIN'+JSON.stringify(data)+'ZFSAS_JSON_END'});
 });
 await page.addInitScript(()=>{const original=window.setTimeout;window.setTimeout=(fn,ms,...args)=>original(fn,ms===20000?800:ms,...args);});
 await page.goto('http://workspace.test/?'+query);
 async function captureState(state){fs.mkdirSync('/tmp/zfsas-ui-screenshots',{recursive:true});for(const theme of ['light','dark'])for(const width of [1440,390]){await page.setViewportSize({width,height:1000});await page.evaluate(theme=>document.body.style.backgroundColor=theme==='dark'?'rgb(25,25,25)':'rgb(255,255,255)',theme);await page.screenshot({path:'/tmp/zfsas-ui-screenshots/'+state+'-'+theme+'-'+width+'.png',fullPage:false});}await page.setViewportSize({width:1440,height:1000});await page.evaluate(()=>document.body.style.backgroundColor='rgb(255,255,255)');}

 if(query==='section=snapshots' || query.endsWith('tab=automation') || query==='section=replication'){
   const browse=query==='section=snapshots',status=page.locator(browse?'#notice':'#dataset-discovery-status');
   assert.match(await status.textContent(),/Discovering/);
   await page.waitForTimeout(400);assert.match(await status.textContent(),/discovery failed/i);
   if(browse)await page.getByText('Find a dataset',{exact:true}).click();
   const retry=page.getByRole('button',{name:browse?'Refresh datasets':'Retry dataset discovery',exact:true});
   assert(await retry.isVisible());await retry.click();
   await page.waitForFunction(()=>document.querySelector('#notice')?.textContent.includes('Choose a dataset') || document.querySelector('#dataset-discovery-status')?.textContent.includes('datasets discovered'));
   if(browse){
     assert.equal(await page.locator('#dataset option').count(),2);

     await retry.click();await page.waitForTimeout(900);
     assert.match(await status.textContent(),/timed out/);assert(await retry.isEnabled());
     await retry.click();await page.waitForTimeout(100);assert.match(await status.textContent(),/Choose a dataset/);
   }else{
     assert.equal(await page.locator('[data-config-tools] button').count(),1);

   }
 }
 await page.waitForTimeout(500);
 assert(await page.locator('.ui-brand-mark').evaluate(img=>img.complete && img.naturalWidth>0),'Brand icon did not load');
 assert.equal(await page.locator('.zfsas-workspace').count(),1,query);assert.equal(await page.locator('h1').count(),1,query);assert.equal(await page.locator('iframe').count(),0);
 if(query.endsWith('tab=automation'))await page.getByRole('button',{name:'Set up automatic snapshots',exact:true}).click();
 if(query==='section=activity' || query.endsWith('tab=automation')){
 const groups=query==='section=activity' ? [['#activity-state','#activity-refresh']] : [['#dataset_pool_filter','#dataset_name_filter']];
 for(const selectors of groups){const boxes=await Promise.all(selectors.map(selector=>page.locator(selector).boundingBox()));for(const box of boxes.slice(1)){assert(Math.abs((boxes[0].y+boxes[0].height)-(box.y+box.height))<3,'Misaligned controls: '+selectors.join(', '));}}
 }
 if(query==='section=overview' || query==='section=activity'){
 await page.addStyleTag({content:'table td{white-space:nowrap;height:24px;} button{white-space:nowrap;} code{white-space:pre;}'});
 assert(await page.locator('#operation-rows td:nth-child(2) code').evaluateAll(nodes=>nodes.every(el=>el.scrollWidth<=el.clientWidth+1)),'Dataset text overflows cell');
 assert(await page.locator('#operation-rows td:nth-child(2) code').evaluateAll(nodes=>nodes.every(el=>el.getBoundingClientRect().right<=el.closest('td').getBoundingClientRect().right+1)),'Dataset extends into adjacent column');
 if(query==='section=overview')assert(await page.locator('.ui-attention-item').evaluateAll(nodes=>nodes.every(el=>el.scrollWidth<=el.clientWidth+1)),'Attention text overflows');
 }
 if(query==='section=overview'){const button=page.getByRole('button',{name:'Details',exact:true}).first();await button.click();await page.getByText('More log options',{exact:true}).click();await page.getByRole('button',{name:'Shared batch log — includes other jobs'}).click();await page.waitForTimeout(2300);assert.match(await page.locator('#operation-detail-log').textContent(),/Test log/);
 const log=page.locator('#operation-detail-log');assert(await log.evaluate(el=>el.scrollTop>0),'Log did not open at newest entries');
 await log.evaluate(el=>el.scrollTop=125);
 await page.locator('#operation-detail').evaluate(el=>el.scrollTop=180);
 const drawerScroll=await page.locator('#operation-detail').evaluate(el=>el.scrollTop);
 await page.waitForTimeout(4500);
 assert.equal(await page.locator('#operation-detail').evaluate(el=>el.scrollTop),drawerScroll,'Status refresh reset drawer scroll');
 assert.equal(await log.evaluate(el=>el.scrollTop),125,'Status refresh reset log scroll');
await page.keyboard.press('Escape');await page.waitForFunction(()=>!document.getElementById('operation-detail').open);assert(await button.evaluate(el=>el===document.activeElement));
 await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'));});const previous=summaryRequests;await page.waitForTimeout(2200);assert.equal(summaryRequests,previous,'Hidden Overview polled');await page.evaluate(()=>{delete document.hidden;document.dispatchEvent(new Event('visibilitychange'));});}
 if(query==='section=activity'){
 assert.equal(await page.evaluate(()=>{history.replaceState(null,'','/ZFSSnapSyncTab?section=activity');const result=ZfsasUI.workflowUrl('/Settings/ZFSSnapSync?section=replication');history.replaceState(null,'','/?section=activity');return result;}),'/ZFSSnapSyncTab?section=replication');
 assert.equal(await page.locator('[data-operation="coordinator:transfer"] progress').getAttribute('value'),'42');
 assert.match(await page.locator('[data-operation="coordinator:transfer"]').textContent(),/8.0 MiB\/s/);
 await page.locator('[data-operation="coordinator:native"] button').click();
 page.once('dialog',async dialog=>{assert.match(dialog.message(),/validated before resuming/);await dialog.accept();});
 const [nativeRequest]=await Promise.all([page.waitForRequest(request=>request.url().endsWith('coordinator-action.php')),page.getByRole('button',{name:'Retry',exact:true}).click()]);
 assert.match(nativeRequest.postData(),/action=retry/);assert.match(nativeRequest.postData(),/run_id=native-run/);
 await page.keyboard.press('Escape');
 await page.locator('[data-operation="replication:recovery"] button').click();
 assert.match(await page.locator('#operation-body').textContent(),/Recovery requires review/);
 assert.equal(await page.getByRole('button',{name:'Retry',exact:true}).count(),0);
 page.once('dialog',async dialog=>{assert.match(dialog.message(),/releases its cleanup protection/);await dialog.dismiss();});
 await page.getByRole('button',{name:'Clear failed record',exact:true}).click();
 assert.equal(mutationRequests,0,'Dismissed review warning submitted a mutation');
 page.once('dialog',dialog=>dialog.accept());
 const [request]=await Promise.all([page.waitForRequest(request=>request.url().endsWith('send-queue-action.php')),page.getByRole('button',{name:'Clear failed record',exact:true}).click()]);
 assert.match(request.postData(),/action=clear_failed/);assert.match(request.postData(),/job_id=recovery/);
 await page.keyboard.press('Escape');
 await page.locator('[data-operation="coordinator:batch"] button').click();
 page.once('dialog',async dialog=>{assert.match(dialog.message(),/Cancel this batch/);assert.doesNotMatch(dialog.message(),/paused/);await dialog.accept();});
 const [batchRequest]=await Promise.all([page.waitForRequest(request=>request.url().endsWith('coordinator-action.php')),page.getByRole('button',{name:'Cancel run',exact:true}).click()]);
 assert.match(batchRequest.postData(),/action=cancel/);assert.match(batchRequest.postData(),/run_id=batch-run/);
 await page.keyboard.press('Escape');
 }
 if(query.endsWith('tab=automation')){await page.locator('#dataset-page-checkbox').check();await page.locator('#dataset_name_filter').fill('no-match');assert(await page.locator('.zfsas-dataset-checkbox').isChecked());await page.locator('#dataset_name_filter').fill('');assert(await page.locator('#dataset-page-checkbox').isChecked());await page.getByRole('button',{name:'Continue',exact:true}).click();await page.locator('#automation-advanced summary').click();await page.locator('#dry_run').check();assert.match(await page.locator('#automation-dry-run-summary').textContent(),/Dry Run enabled/);await captureState('automation-running-draft');}
 if(query==='section=replication'){
 await captureState('replication-empty');await page.locator('#replication-shared summary').click();await page.getByRole('tab',{name:'Performance',exact:true}).click();await page.locator('#send_max_parallel').fill('7');await page.locator('#replication-shared summary').click();
 await page.locator('#open-new-job').click();assert(await page.locator('#edit-job-dialog').evaluate(el=>!el.hidden));
 const editor=page.locator('#job-editor-body');
 await editor.locator('[name^="job_source["]').evaluate(el=>el.add(new Option('tank/anchor-test','tank/anchor-test')));
 await editor.locator('[name^="job_source["]').selectOption('tank/anchor-test');await editor.locator('[name^="job_destination["]').fill('backup/anchor-test');
 assert.equal(await editor.locator('[name^="job_source_keep["]').inputValue(),'3');
 for(const width of [1440,900,390]){await page.setViewportSize({width,height:1000});assert(await page.locator('#edit-job-dialog').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Editor overflow at '+width);}
 await page.setViewportSize({width:1440,height:1000});
 await captureState('replication-editing');await page.locator('#finish-job-edit').click();await page.locator('#finish-job-edit').click();saveMode='failure';await page.locator('#finish-job-edit').click();await page.waitForFunction(()=>document.getElementById('job-editor-error').textContent.includes('Settings changed'));
 assert.equal(await editor.locator('[name^="job_destination["]').inputValue(),'backup/anchor-test');await captureState('replication-error');
 const before=savePosts.length;saveMode='runtime';await page.locator('#finish-job-edit').evaluate(button=>{button.click();button.click();});await page.waitForFunction(()=>document.getElementById('edit-job-dialog').hidden);assert.equal(savePosts.length,before+1);assert.equal(savePosts.at(-1).get('scope'),'job_create');assert.equal(savePosts.at(-1).get('send_max_parallel'),null);
 assert.equal(await page.locator('#send_max_parallel').inputValue(),'7');assert.equal(await page.locator('#zfsas_send_form').getAttribute('data-dirty'),'true');assert.match(await page.locator('#workspace-notice').textContent(),/scheduler application failed/);
 saveMode='ok';await page.locator('#replication-shared summary').click();await page.locator('#save_send_btn').click();await page.waitForFunction(()=>document.getElementById('zfsas_send_form').dataset.dirty==='false');assert.equal(savePosts.at(-1).get('scope'),'shared');assert.equal(savePosts.at(-1).get('job_source[0]'),null);await page.locator('#replication-shared summary').click();
 await captureState('replication-configured');
 await page.locator('#replication-job-list').getByRole('button',{name:'Edit',exact:true}).last().click();await page.locator('.ui-job-review').getByRole('button',{name:'Edit',exact:true}).last().click();
 await page.locator('.ui-job-space summary').click();const policy=editor.locator('[name^="job_cleanup_policy["]');await policy.selectOption('older_anchors');page.once('dialog',dialog=>dialog.accept());await page.locator('#cancel-job-edit').click();
 await page.locator('#replication-job-list').getByRole('button',{name:'Edit',exact:true}).last().click();await page.locator('.ui-job-review').getByRole('button',{name:'Edit',exact:true}).last().click();assert.equal(await policy.inputValue(),'retention_only');await page.locator('.ui-job-space summary').click();
 await policy.selectOption('older_anchors');await page.locator('#finish-job-edit').click();await page.waitForFunction(()=>document.getElementById('edit-job-dialog').hidden);
 assert.equal(await page.locator('#zfsas_send_jobs_body [name^="job_cleanup_policy["]').last().inputValue(),'older_anchors');
 assert.equal(await page.locator('#zfsas_send_form').getAttribute('data-dirty'),'false');
 await page.locator('#replication-job-list summary').last().click();await page.getByRole('button',{name:'Pause schedule',exact:true}).click();
 await page.locator('#replication-job-list summary').last().click();await page.getByRole('button',{name:'Resume schedule',exact:true}).click();
 }
 if(query==='section=tools'){
 assert(!(await page.locator('[name=show_tab]').isChecked()));
 await page.locator('[name=show_tab]').check();
 const [request]=await Promise.all([page.waitForRequest(r=>r.url().endsWith('save-interface-settings.php')),page.getByRole('button',{name:'Save interface preference'}).click()]);
 assert.match(request.postData(),/show_tab=1/);
 await page.waitForFunction(()=>document.getElementById('interface-status').textContent.includes('Saved.'));
 assert(await page.locator('#interface-reload').isVisible());
 }
 if(query.endsWith('tab=migrator')){await page.selectOption('#migrate_dataset','tank/data');assert(await page.locator('#migrate_start').isDisabled());await page.locator('#migrate_preview').click();await page.waitForTimeout(100);await page.locator('#migrate-review-confirm').check();assert(await page.locator('#migrate_start').isEnabled());}
 fs.mkdirSync('/tmp/zfsas-ui-screenshots',{recursive:true});const name=query.replaceAll(/[=&]/g,'-');await page.screenshot({path:'/tmp/zfsas-ui-screenshots/'+name+'-light.png',fullPage:query!=='section=snapshots&tab=automation'});
 await page.evaluate(()=>document.body.style.backgroundColor='rgb(25,25,25)');await page.waitForTimeout(50);await page.screenshot({path:'/tmp/zfsas-ui-screenshots/'+name+'-dark.png',fullPage:true});
 await page.setViewportSize({width:900,height:1000});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Tablet overflow: '+query);
 await page.setViewportSize({width:390,height:844});await page.locator('.ui-menu-toggle').click();assert.equal(await page.locator('.ui-menu-toggle').getAttribute('aria-expanded'),'true');await page.locator('.ui-menu-toggle').click();await page.screenshot({path:'/tmp/zfsas-ui-screenshots/'+name+'-mobile.png',fullPage:true});
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Horizontal overflow: '+query);
 assert.deepEqual(errors,[],query);await page.close();console.log('PASS '+query);
 }
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exit(1)});
