const {chromium}=require('/opt/zfsas-tests/node_modules/playwright-core');
const {execFileSync}=require('node:child_process'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const plugin=path.resolve(__dirname,'../../source/usr/local/emhttp/plugins/zfs.snapsync');
(async()=>{
 const html=execFileSync('php',['-r','$_GET["section"]="activity";require $argv[1];',plugin+'/php/workspace.php'],{encoding:'utf8'});
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
 try{
  const page=await browser.newPage({viewport:{width:390,height:850}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
  let mode='unsupported',logCalls=0,recoveryCalls=0,stagesCalls=0;
  const op={id:'coordinator:run-'+ 'a'.repeat(24),nativeId:'run-'+ 'a'.repeat(24),type:'replication',coordinator:true,title:'Replication',state:'running',createdAt:1,actions:['review_recovery'],url:'?section=replication'};
  const stages=['Check datasets','Create source snapshots','Inspect destinations','Cleanup','Check space','Transfer','Verify'].map((label,i)=>({id:['datasets','snapshots','inspect','cleanup','space','transfer','verify'][i],label,state:i===5?'failed':i===6?'not_reached':'completed',datasets:10000,counts:{completed:9999,failed:1}}));
  await page.addInitScript(()=>{const original=setTimeout;window.setTimeout=(fn,ms,...args)=>original(fn,[2000,10000].includes(ms)?150:ms,...args);});
  await page.route('http://compat.test/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
   if(/\.(js|css)$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.js')?'application/javascript':'text/css',body:fs.readFileSync(plugin+url.pathname.replace('/plugins/zfs.snapsync',''),'utf8')});
   let data={ok:true};
   if(url.pathname.endsWith('workspace-summary.php'))data={...data,generatedAt:1,timezone:'UTC',sources:{},operations:[op],schedules:[],pausedSchedules:[]};
   if(url.pathname.endsWith('operation-detail.php')){
    if(url.searchParams.get('offset')==='latest'){
     logCalls++;
     if(mode==='unsupported')data={ok:false,code:'unsupported_capability',retryable:false,error:'Older coordinator. Refresh when idle.'};
     else if(mode==='error')data={ok:false,code:'request_failed',retryable:true,error:'Temporary disconnect.'};
     else data={ok:true,state:mode==='complete'?'complete':'running',historyNotice:'RAM history.',entries:mode==='empty'?[]:[{at:1,phase:'replication_transfer',state:'running',message:'Retained job content'}]};
    }else{
     stagesCalls++;const offset=Number(url.searchParams.get('stage_offset')||0);
     data={ok:true,state:'running',checklist:{available:true,stages,page:{rows:Array.from({length:50},(_,i)=>({dataset:'tank/'+('long-path-'.repeat(12))+(offset+i),state:'failed',attempts:2,message:'Receiver unavailable'})),total:10000,previousOffset:offset?0:null,nextOffset:offset+50}}};
    }
   }
   if(url.pathname.endsWith('coordinator-action.php')){recoveryCalls++;data={ok:false,code:'unsupported_capability',retryable:false,error:'Recovery unavailable on older coordinator.'};}
   return route.fulfill({contentType:'text/plain',body:'ZFSAS_JSON_BEGIN'+JSON.stringify(data)+'ZFSAS_JSON_END'});
  });
  await page.goto('http://compat.test/');await page.getByRole('button',{name:'Details',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('[data-stage="transfer"] li'));
  assert.equal(await page.locator('[data-stage="transfer"]').evaluate(n=>n.open),true,'Failed stage not expanded');
  assert.equal(await page.locator('[data-stage="transfer"] li').count(),50);
  await page.locator('[data-stage="transfer"]').getByRole('button',{name:'Next datasets'}).click();
  await page.waitForFunction(()=>document.querySelector('[data-stage="transfer"] [data-panel-status]').textContent.startsWith('51–100'));
  assert(await page.locator('[data-stage="transfer"] li').evaluateAll(ns=>ns.every(n=>n.scrollWidth<=n.clientWidth+1)),'Narrow dataset overflow');
  await page.getByRole('button',{name:'Show job log',exact:true}).click();
  await page.waitForFunction(()=>document.querySelector('#operation-log-panel').dataset.state==='unavailable');
  assert.equal(await page.locator('#operation-detail-log').textContent(),'','Unsupported log left loading');
  assert.equal(await page.locator('#operation-log-panel [data-retry]').count(),0);
  const stopped=logCalls;await page.waitForTimeout(400);assert.equal(logCalls,stopped,'Unsupported logs kept polling');
  mode='error';await page.getByRole('button',{name:'Show job log',exact:true}).click();
  await page.locator('#operation-log-panel').getByRole('button',{name:'Retry loading'}).waitFor();
  mode='ready';await page.locator('#operation-log-panel').getByRole('button',{name:'Retry loading'}).click();
  await page.waitForFunction(()=>document.querySelector('#operation-detail-log').textContent.includes('Retained'));
  mode='error';await page.waitForFunction(()=>document.querySelector('.operation-log-notice').textContent.includes('stale'));
  assert.match(await page.locator('#operation-detail-log').textContent(),/Retained/);
  mode='complete';await page.locator('#operation-log-panel').getByRole('button',{name:'Retry loading'}).click();
  await page.waitForTimeout(80);const completed=logCalls;await page.waitForTimeout(400);assert.equal(logCalls,completed,'Completed logs kept polling');
  await page.getByRole('button',{name:'Review recovery',exact:true}).click();await page.waitForFunction(()=>document.querySelector('#replication-recovery').dataset.state==='unavailable');
  assert.match(await page.locator('.recovery-status').textContent(),/older coordinator/);assert.equal(recoveryCalls,1);
  await page.locator('#replication-recovery [data-close-dialog]').click();await page.locator('#operation-detail [data-close-dialog]').click();
  const closed=stagesCalls;await page.waitForTimeout(400);assert.equal(stagesCalls,closed,'Closed Details kept polling');
  assert.deepEqual(errors,[]);console.log('PASS: narrow stage pages, failed expansion, unsupported/error/stale panels, retry, terminal and closed-dialog polling');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
