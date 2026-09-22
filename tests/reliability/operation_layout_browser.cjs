const {chromium}=require('/opt/zfsas-tests/node_modules/playwright-core');
const {execFileSync}=require('node:child_process');const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const plugin=path.resolve(__dirname,'../../source/usr/local/emhttp/plugins/zfs.snapsync');
(async()=>{const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});try{
 for(const section of ['overview','activity'])for(const width of [1440,760]){
  const page=await browser.newPage({viewport:{width,height:950}});let step=0;const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const html=execFileSync('php',['-r','$_GET["section"]=$argv[1];require $argv[2];',section,plugin+'/php/workspace.php'],{encoding:'utf8'});
  await page.route('http://layout.test/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
   if(/\.(js|css)$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.js')?'application/javascript':'text/css',body:fs.readFileSync(plugin+'/'+(url.pathname.endsWith('.js')?'js/':'css/')+path.basename(url.pathname),'utf8')});
   const common={type:'replication',coordinator:true,createdAt:123,source:'tank/data',destination:'backup/data',actions:[],url:'?section=replication',logType:'replication'};
   const states=[{state:'waiting',stateLabel:'Waiting',phase:'resource',message:'Waiting for another operation.'},{state:'running',stateLabel:'Waiting',phase:'resource',message:'Waiting for another operation to release this dataset. '.repeat(8)},{state:'running',phase:'transfer',progress:10,message:'8.0 MiB/s'},{state:'running',phase:'transfer',progress:99,message:'12345 MiB sent · 16 MiB/s · 99% of estimated stream'},{state:'complete',message:'Completed.'}];
   const data={ok:true,generatedAt:123,timezone:'UTC',sources:{configuration:{available:true}},schedules:[],pausedSchedules:[],operations:[{...common,id:'coordinator:active',nativeId:'active',title:'Active transfer',state:'running',phase:'transfer',progress:42,message:step%2?'42 MiB sent · 8.0 MiB/s · estimated stream':'8 MiB/s'},{...common,id:'coordinator:waiting',nativeId:'waiting',title:'Waiting transfer',...states[step]},{...common,id:'coordinator:below',nativeId:'below',title:'Next row',state:'complete'}]};
   return route.fulfill({contentType:'text/plain',body:'ZFSAS_JSON_BEGIN'+JSON.stringify(data)+'ZFSAS_JSON_END'});
  });
  await page.goto('http://layout.test/');await page.locator('tr[data-operation="coordinator:below"]').waitFor();
  const below=page.locator('tr[data-operation="coordinator:below"]'),waiting=page.locator('tr[data-operation="coordinator:waiting"]');
  const initial=(await below.boundingBox()).y,bar=await waiting.locator('progress').elementHandle();
  for(step=1;step<5;step++){
   await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'));Object.defineProperty(document,'hidden',{configurable:true,value:false});document.dispatchEvent(new Event('visibilitychange'));});
   await page.waitForTimeout(180);
   assert(Math.abs((await below.boundingBox()).y-initial)<1,`Rows shifted for ${section} at ${width}, step ${step}`);
   assert(await bar.evaluate(el=>el.isConnected),'Progress node was recreated');
   assert.equal(await page.locator('tr[data-operation="coordinator:active"] progress').getAttribute('value'),'42','Active transfer progress lost');
  }
  await waiting.getByRole('button',{name:'Details'}).click();assert.match(await page.locator('#operation-body').textContent(),/Completed/);
  assert.deepEqual(errors,[]);await page.close();
 }
 console.log('PASS: stable operation rows through resource rechecks, long messages, transfer and completion at desktop/narrow widths; progress nodes retained');
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exit(1);});
