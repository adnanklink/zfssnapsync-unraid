const {chromium}=require('/opt/zfsas-tests/node_modules/playwright-core');
const {execFileSync}=require('node:child_process');const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const plugin=path.resolve(__dirname,'../../source/usr/local/emhttp/plugins/zfs.snapsync');
(async()=>{
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
 try{
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  let dismissed=false,mutations=0;
  const op={id:'coordinator:failed',nativeId:'failed',coordinator:true,type:'replication',title:'Failed replication',state:'failed',recoveryRequired:true,createdAt:123,source:'tank/data',attentionToken:'a'.repeat(64),url:'?section=replication',logType:'replication'};
  const html=execFileSync('php',['-r','$_GET["section"]="overview";require $argv[1];',plugin+'/php/workspace.php'],{encoding:'utf8'});
  await page.route('http://attention.test/**',async route=>{
   const request=route.request(),url=new URL(request.url());
   if(url.pathname==='/')return route.fulfill({contentType:'text/html',body:html});
   if(/\.(js|css)$/.test(url.pathname))return route.fulfill({contentType:url.pathname.endsWith('.js')?'application/javascript':'text/css',body:fs.readFileSync(plugin+'/'+(url.pathname.endsWith('.js')?'js/':'css/')+path.basename(url.pathname),'utf8')});
   let data={ok:true};
   if(url.pathname.endsWith('workspace-summary.php'))data={ok:true,generatedAt:123,timezone:'UTC',sources:{configuration:{available:true}},schedules:[],pausedSchedules:[],operations:[{...op,attentionDismissed:dismissed,needsAttention:!dismissed,actions:[dismissed?'restore_attention':'dismiss_attention']}]};
   if(request.method()==='POST'){
    assert(url.pathname.endsWith('attention-action.php'),'Dismissal reached worker mutation endpoint');mutations++;
    const body=new URLSearchParams(request.postData());assert.equal(body.get('operation_id'),op.id);assert.equal(body.get('attention_token'),op.attentionToken);dismissed=body.get('action')==='dismiss_attention';data={ok:true,message:'History and recovery protections are preserved.'};
   }
   return route.fulfill({contentType:'text/plain',body:'ZFSAS_JSON_BEGIN'+JSON.stringify(data)+'ZFSAS_JSON_END'});
  });
  await page.goto('http://attention.test/');await page.waitForFunction(()=>document.getElementById('summary-attention').textContent==='1');
  await page.locator('.ui-attention-item').click();await page.getByRole('button',{name:'Dismiss from Needs attention',exact:true}).click();
  await page.waitForFunction(()=>document.getElementById('summary-attention').textContent==='0');
  assert.equal(await page.locator('.ui-attention-item').count(),0);assert.equal(await page.locator('tr[data-operation="coordinator:failed"]').count(),1);
  assert.match(await page.locator('#operation-body').textContent(),/Recovery requires review/);
  await page.getByRole('button',{name:'Restore to Needs attention',exact:true}).click();await page.waitForFunction(()=>document.getElementById('summary-attention').textContent==='1');
  await page.getByRole('button',{name:'Dismiss from Needs attention',exact:true}).click();await page.waitForFunction(()=>document.getElementById('summary-attention').textContent==='0');
  await page.reload();await page.waitForFunction(()=>document.getElementById('summary-attention').textContent==='0');
  assert.equal(await page.locator('tr[data-operation="coordinator:failed"]').count(),1);assert.equal(mutations,3);assert.deepEqual(errors,[]);
  console.log('PASS: Chromium dismiss/restore, attention counts, retained history/recovery and dismissal across reload');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
