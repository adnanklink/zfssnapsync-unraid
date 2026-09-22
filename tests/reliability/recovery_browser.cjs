const {chromium}=require('/opt/zfsas-tests/node_modules/playwright-core');
const {execFileSync}=require('node:child_process'),fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const plugin=path.resolve(__dirname,'../../source/usr/local/emhttp/plugins/zfs.snapsync');
(async()=>{
 const html=execFileSync('php',['-r','$_GET["section"]="activity";require $argv[1];',plugin+'/php/workspace.php'],{encoding:'utf8'});
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
 try{
  const page=await browser.newPage({viewport:{width:760,height:900}}),errors=[];page.on('pageerror',e=>{errors.push(e.message);console.error('PAGE ERROR',e.message);});
  let reviews=0,retries=0,slow=false;
  const op={id:'coordinator:run-'+ 'a'.repeat(24),nativeId:'run-'+ 'a'.repeat(24),type:'replication',coordinator:true,title:'Replication',state:'failed',createdAt:1700000000,actions:['review_recovery'],logType:'replication',url:'?section=replication',source:'tank/'+ 'long-source-'.repeat(50),problem:{summary:'An earlier transfer is unfinished at the destination.',nextAction:'Review the interrupted transfer before sending another snapshot.',source:'tank/data',destination:'backup/data',diagnosticAvailable:false}};
  await page.route('http://recovery.test/**',async route=>{
   const req=route.request(),url=new URL(req.url());
   if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
   if(/\.(js|css)$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.js')?'application/javascript':'text/css',body:fs.readFileSync(plugin+url.pathname.replace('/plugins/zfs.snapsync',''),'utf8')});
   let data={ok:true};
   if(url.pathname.endsWith('workspace-summary.php'))data={...data,generatedAt:1700000000,timezone:'UTC',sources:{},operations:[op],schedules:[],pausedSchedules:[]};
   if(url.pathname.endsWith('operation-detail.php')){assert.equal(url.searchParams.get('operation_id'),op.id);data={...data,scope:'job',historyNotice:'Runtime history is lost after reboot.',previousOffset:null,nextOffset:null,entries:Array.from({length:100},(_,i)=>({at:1700000000+i,phase:'transfer',state:'transient_failure',source:'tank/data',destination:'backup/data',message:'Original attempt '+i,diagnostic:'cannot receive: quota exceeded',exitCode:1}))};}
   if(url.pathname.endsWith('workspace-log.php'))data={...data,content:'OTHER JOB SHARED LOG'};
   if(url.pathname.endsWith('coordinator-action.php')){
    const body=new URLSearchParams(req.postData());
    if(body.get('action')==='review_recovery'){reviews++;assert.equal(body.get('run_id'),op.nativeId);data={...data,runId:'run-'+ 'b'.repeat(24)};if(slow)await new Promise(r=>setTimeout(r,500));}
    if(body.get('action')==='retry_reviewed'){retries++;assert.equal(body.get('review_id'),'run-'+ 'b'.repeat(24));data={...data,runId:'run-'+ 'c'.repeat(24)};}
   }
   if(url.pathname.endsWith('replication-recovery-status.php'))data={...data,reviewId:'run-'+ 'b'.repeat(24),state:'complete',expiresAt:Math.floor(Date.now()/1000)+300,eligible:1,blocked:1,expired:false,nextOffset:null,rows:[{source:op.source,destination:'backup/data',snapshot:'tank/data@ORIGINAL_A',message:'Resume this original interrupted snapshot.'},{source:'tank/child',destination:'backup/child',message:'No retained original snapshot; no new snapshot will be created.'}]};
   return route.fulfill({contentType:'text/plain',body:'ZFSAS_JSON_BEGIN'+JSON.stringify(data)+'ZFSAS_JSON_END'});
  });
  await page.goto('http://recovery.test/');await page.getByRole('button',{name:'Details',exact:true}).click();
  assert.match(await page.locator('#operation-metadata').textContent(),/What happened.*earlier transfer.*Next action/s);
  await page.getByRole('button',{name:'Show job log',exact:true}).click();await page.waitForFunction(()=>document.getElementById('operation-detail-log')?.textContent.includes('quota exceeded'));
  assert(! (await page.locator('#operation-detail-log').textContent()).includes('OTHER JOB'));
  assert(await page.locator('#operation-detail-log').evaluate(el=>el.scrollTop>0));
  await page.locator('#operation-detail-log').evaluate(el=>el.scrollTop=100);await page.locator('#activity-refresh').evaluate(el=>el.click());await page.waitForTimeout(150);
  assert.equal(await page.locator('#operation-detail-log').evaluate(el=>el.scrollTop),100);
  await page.getByRole('button',{name:'Shared replication log — includes other jobs',exact:true}).click();await page.waitForFunction(()=>document.getElementById('operation-detail-log')?.textContent.includes('OTHER JOB'));
  await page.getByRole('button',{name:'Review recovery',exact:true}).click();await page.getByRole('button',{name:'Retry 1 reviewed datasets',exact:true}).waitFor({timeout:5000}).catch(async e=>{throw new Error(e.message+' STATUS: '+await page.locator('.recovery-status').textContent());});
  assert.match(await page.locator('.recovery-status').textContent(),/1 eligible; 1 unavailable/);
  assert(await page.locator('.recovery-members li').evaluateAll(nodes=>nodes.every(n=>n.scrollWidth<=n.clientWidth+1)),'Recovery paths overflow');
  assert.match(await page.locator('.recovery-members').textContent(),/ORIGINAL_A/);
  await page.getByRole('button',{name:'Retry 1 reviewed datasets',exact:true}).click();await page.waitForFunction(()=>document.querySelector('.recovery-status').textContent.includes('queued'));
  assert.equal(retries,1);assert.equal(reviews,1);
  await page.locator('#replication-recovery [data-close-dialog]').click();slow=true;
  await page.getByRole('button',{name:'Review recovery',exact:true}).click();await page.locator('#replication-recovery [data-close-dialog]').click();await page.waitForTimeout(700);
  assert.equal(await page.locator('#replication-recovery').evaluate(el=>el.open),false,'Stale request reopened review');
  assert.deepEqual(errors,[]);console.log('PASS: job log isolation, actionable failure, scroll preservation, review approval, partial blockers, path wrapping and stale response rejection');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
