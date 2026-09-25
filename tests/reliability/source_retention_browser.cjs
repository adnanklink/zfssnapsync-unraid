const {chromium}=require('/opt/zfsas-tests/node_modules/playwright-core');
const {execFileSync}=require('node:child_process');const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const plugin=path.resolve(__dirname,'../../source/usr/local/emhttp/plugins/zfs.snapsync');
(async()=>{
 const config='/boot/config/plugins/zfs.snapsync';fs.mkdirSync(config,{recursive:true});fs.writeFileSync(config+'/zfs_send.conf','SEND_JOBS="abcdef123456|tank/data|backup/data|1d|0G|0|local"\n');
 const html=execFileSync('php',[plugin+'/php/send-settings.php'],{encoding:'utf8'});
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
 try{
  const page=await browser.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));let starts=0,polls=0,slow=false,save=null;
  await page.route('http://source.test/**',async route=>{
   const request=route.request(),url=new URL(request.url());
   if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
   if(/\.(js|css)$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.js')?'application/javascript':'text/css',body:fs.readFileSync(plugin+'/'+(url.pathname.endsWith('.js')?'js/':'css/')+path.basename(url.pathname),'utf8')});
   let data={ok:true,sources:{},operations:[],schedules:[],pausedSchedules:[],jobs:[],pendingDeleteCount:0,spec:{kind:'interval',seconds:21600},datasets:[{dataset:'tank/data',pool:'tank',sendDestination:false}]};
   if(url.pathname.endsWith('source-retention-preview.php')){
    if(request.method()==='POST'){starts++;const body=new URLSearchParams(request.postData());assert.equal(body.get('keep'),'3');assert.equal(body.get('job_source[0]'),'tank/data');if(slow)await new Promise(r=>setTimeout(r,600));data={ok:true,state:'pending',token:'a'.repeat(48),runId:'review'};}
    else{polls++;data={ok:true,state:'ready',eligible:4,protected:3,unmanaged:2,total:70,offset:Number(url.searchParams.get('offset')||0),expires:Math.floor(Date.now()/1000)+300,rows:Array.from({length:50},(_,i)=>({snapshot:'tank/data@'+('long-name-'.repeat(30))+i,reason:'eligible after a newer fully verified replication'}))};}
   }
   if(url.pathname.endsWith('save-send-settings.php')){save=new URLSearchParams(request.postData());data={ok:true,saved:true,schedulerApplied:true,revision:'new-revision',jobs:[{id:'abcdef123456',source:'tank/data',destination:'backup/data'}],notices:[],errors:[]};}
   return route.fulfill({contentType:'text/plain',body:'ZFSAS_JSON_BEGIN'+JSON.stringify(data)+'ZFSAS_JSON_END'});
  });
  await page.goto('http://source.test/');await page.waitForFunction(()=>document.querySelector('[data-config-tools]')?.dataset.ready==='1');
  assert.equal(await page.locator('[name="job_source_keep[0]"]').inputValue(),'0','Existing job defaulted to destructive cleanup');
  await page.getByRole('button',{name:'Edit',exact:true}).click();
  await page.locator('#job-editor-body').getByLabel('Source snapshots',{exact:true}).selectOption('count');
  await page.getByRole('button',{name:'Review source snapshots',exact:true}).click();
  await page.getByRole('button',{name:'Use this retention policy',exact:true}).waitFor();
  assert.match(await page.locator('#job-editor-body .source-review-status').textContent(),/4 currently eligible; 3 protected; 2 unmanaged/);
  assert.equal(await page.locator('#job-editor-body .source-review-list li').count(),50);
  assert(await page.locator('#job-editor-body .source-review-list').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'Long review paths overflow');
  await page.getByRole('button',{name:'Next',exact:true}).click();await page.waitForFunction(()=>document.querySelector('#job-editor-body .source-review-results p')?.textContent.includes('51–70'));
  await page.getByRole('button',{name:'Use this retention policy',exact:true}).click();
  assert.equal(await page.locator('#job-editor-body [name="job_source_review[0]"]').inputValue(),'a'.repeat(48));
  await page.locator('#finish-job-edit').click();
  await page.waitForFunction(()=>!document.getElementById('edit-job-dialog').open);
  assert.equal(save.get('scope'),'job_update');assert.equal(save.get('job_source_keep[0]'),'3');assert.equal(save.get('job_source_review[0]'),'a'.repeat(48));
  await page.locator('#replication-shared summary').click();await page.locator('[data-restore-tuning]').click();assert.equal(await page.locator('[name="job_source_keep[0]"]').inputValue(),'3','Tuning defaults changed cleanup authorization');
  await page.locator('#save_send_btn').click();await page.waitForTimeout(250);assert.equal(save.get('scope'),'shared');assert.equal(save.get('job_source_keep[0]'),null);
  assert.equal(await page.locator('#zfsas_send_jobs_body [name="job_source_review[0]"]').inputValue(),'','Saved review token remained stale');
  await page.getByRole('button',{name:'Edit',exact:true}).click();slow=true;
  await page.getByRole('button',{name:'Review source snapshots',exact:true}).click();
  await page.locator('#job-editor-body').getByLabel('Source checkpoint count',{exact:true}).fill('4');await page.waitForTimeout(900);
  assert.equal(await page.getByRole('button',{name:'Use this retention policy',exact:true}).count(),0,'Stale review appeared after count changed');
  assert.equal(await page.locator('#job-editor-body [name="job_source_review[0]"]').inputValue(),'');
  await page.keyboard.press('Escape');
  await page.getByRole('button',{name:'Edit',exact:true}).click();assert.equal(await page.locator('#job-editor-body').getByLabel('Source checkpoint count',{exact:true}).inputValue(),'3','Cancel edit lost count');
  assert.deepEqual(errors,[]);assert(starts===2&&polls>=2);
  console.log('PASS: Chromium source retention defaults, bounded review pagination, path wrapping, explicit approval/save, tuning preservation and stale-response rejection');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
