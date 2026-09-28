const {chromium}=require('/opt/zfsas-tests/node_modules/playwright-core');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const plugin=path.resolve(__dirname,'../../source/usr/local/emhttp/plugins/zfs.snapsync');
(async()=>{const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});try{
 for(const width of [1440,390]){
  const page=await browser.newPage({viewport:{width,height:900}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const html=execFileSync('php',['-r','$_GET["section"]="activity";require $argv[1];',plugin+'/php/workspace.php'],{encoding:'utf8'});
  await page.route('http://auto.test/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
   if(/\.(js|css)$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.js')?'application/javascript':'text/css',body:fs.readFileSync(plugin+'/'+(url.pathname.endsWith('.js')?'js/':'css/')+path.basename(url.pathname),'utf8')});
   const detail=url.pathname.endsWith('operation-detail.php');
   const data=detail?{ok:true,state:'complete',checklist:{available:true,stages:[{id:'cleanup',label:'Delete eligible snapshots',state:'completed',datasets:1,counts:{completed:1}}],page:{rows:[{dataset:'tank/data',state:'completed',attempts:2,message:'2 recorded steps.'}],total:1,previousOffset:null,nextOffset:null}}}:{ok:true,generatedAt:123,timezone:'UTC',sources:{configuration:{available:true}},schedules:[],pausedSchedules:[],operations:[{id:'coordinator:auto',nativeId:'auto',type:'auto',coordinator:true,createdAt:123,title:'Automatic snapshots',state:'complete',actions:[],url:'?section=automation',logType:'auto'}]};
   return route.fulfill({contentType:'text/plain',body:'ZFSAS_JSON_BEGIN'+JSON.stringify(data)+'ZFSAS_JSON_END'});
  });
  await page.goto('http://auto.test/');const trigger=page.getByRole('button',{name:'Details',exact:true});await trigger.focus();await page.keyboard.press('Enter');
  const summary=page.locator('#operation-checklist summary');await summary.waitFor();await summary.focus();await page.keyboard.press('Enter');
  await page.waitForFunction(()=>document.querySelector('#operation-checklist li')?.textContent.includes('tank/data'));
  assert.match(await page.locator('#operation-checklist').textContent(),/2 recorded steps/);
  await page.keyboard.press('Escape');assert(await trigger.evaluate(el=>el===document.activeElement),'Details did not restore keyboard focus');
  assert.deepEqual(errors,[]);await page.close();
 }
 console.log('PASS: Auto recorded checklist at desktop/mobile, keyboard expansion and focus restoration');
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exit(1);});
