// Configuration-tool behavior not duplicated by the guided job/save journeys.
const {chromium} = require('/opt/zfsas-tests/node_modules/playwright-core');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const plugin = path.resolve(__dirname, '../../source/usr/local/emhttp/plugins/zfs.snapsync');
(async () => {
 const browser = await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
 try {
  for (const kind of ['auto','send']) {
   const page = await browser.newPage();
   const errors=[];
   page.on('pageerror',error=>errors.push(error.message));
   await page.route('http://config.test/**', async route=>{
    const url=new URL(route.request().url());
    if (/\.(css|js|png)$/.test(url.pathname)) return route.fulfill({body:fs.readFileSync(plugin+url.pathname.replace('/plugins/zfs.snapsync','')),contentType:url.pathname.endsWith('.js')?'application/javascript':url.pathname.endsWith('.css')?'text/css':'image/png'});
    if(url.pathname==='/') return route.fulfill({contentType:'text/html',body:execFileSync('php',['-r','parse_str($argv[1],$_GET);require $argv[2];','section='+(kind==='auto'?'automation':'replication'),plugin+'/php/workspace.php'],{encoding:'utf8'})});
    const data={ok:true,probe:true,available:true,runs:[],spec:{kind:'interval',seconds:21600},sources:{},operations:[],schedules:[],jobs:[],pausedSchedules:[],pendingDeleteCount:0,content:'',datasets:[{dataset:'tank/data',pool:'tank',sendDestination:false},{dataset:'tank/dest',pool:'tank',sendDestination:true},{dataset:'backup/archive',pool:'backup',sendDestination:false}]};
    return route.fulfill({contentType:'text/plain',body:'ZFSAS_JSON_BEGIN'+JSON.stringify(data)+'ZFSAS_JSON_END'});
   });
   await page.goto('http://config.test/');
   await page.waitForFunction(()=>document.querySelector('[data-config-tools]')?.dataset.ready==='1');
   const form=page.locator(kind==='auto'?'#zfsas_settings_form':'#zfsas_send_form');
   assert.equal(await form.getAttribute('data-dirty'),'false','Discovery dirtied the form');
   if(kind==='auto') {
    await page.getByRole('button',{name:'Set up automatic snapshots'}).click();
    await page.waitForFunction(()=>document.querySelectorAll('.zfsas-dataset-row').length===3);
    await page.selectOption('#dataset_pool_filter','backup');
    assert.equal(await page.locator('.zfsas-dataset-row:visible').count(),1);
    await page.locator('#dataset-page-checkbox').check();
    await page.selectOption('#dataset_pool_filter','tank');
    assert.match(await page.locator('#dataset_count').textContent(),/1 selected overall; 0 selected among shown/);
    await page.locator('#dataset_name_filter').fill(' DATA ');
    assert.equal(await page.locator('.zfsas-dataset-row:visible').count(),1);
    await page.locator('#dataset-page-checkbox').check();
    assert.equal(await page.locator('.zfsas-dataset-checkbox:checked').count(),2);
    await page.locator('#dataset_name_filter').fill('no-match');
    assert.equal(await page.locator('.zfsas-dataset-row:visible').count(),0);
    assert(await page.locator('#dataset-page-checkbox').isDisabled());
    assert.equal(await page.locator('.zfsas-dataset-checkbox:checked').count(),2);
    await page.locator('#dataset_name_filter').fill('');
    await page.selectOption('#dataset_pool_filter','__all');
    await page.getByText('Selection',{exact:true}).click();
    await page.locator('#dataset_clear_all').click();
    assert.equal(await page.locator('.zfsas-dataset-checkbox:checked').count(),0);
    await page.getByText('Selection',{exact:true}).click();
    await page.locator('#dataset_select_all').click();
    assert.equal(await page.locator('.zfsas-dataset-checkbox:checked').count(),2,'Reserved receiver became selected');
    await page.getByRole('button',{name:'Continue',exact:true}).click();
    await page.selectOption('#schedule_mode','daily');
    await page.getByText('Customize history',{exact:true}).click();
    await page.locator('#automation-advanced > summary').click();
    await page.locator('#dry_run').check();
   } else {
    await page.locator('#replication-shared > summary').click();
    await page.getByRole('tab',{name:'Performance'}).click();
    await page.locator('[name="send_max_parallel"]').fill('4');
    await page.locator('[name="send_rate_limit"]').fill('20M');
    await page.getByRole('tab',{name:'History'}).click();
   }
   const prefix=kind==='auto'?'prefix':'send_snapshot_prefix';
   const retention=kind==='auto'?'keep_all_for_days':'send_keep_all_for_days';
   if(kind==='send') await page.getByRole('tab',{name:'Performance'}).click();
   await page.locator('[name="'+prefix+'"]').fill('unique-'+kind+'-');
   if(kind==='send') await page.getByRole('tab',{name:'History'}).click();
   await page.locator('[name="'+retention+'"]').fill('99');
   assert.equal(await form.getAttribute('data-dirty'),'true');
   await page.locator('[data-restore-tuning]').click();
   assert.equal(await page.locator('[name="'+retention+'"]').inputValue(),'14');
   assert.equal(await page.locator('[name="'+prefix+'"]').inputValue(),'unique-'+kind+'-');
   assert.match(await page.locator('span[data-dirty]').textContent(),/Unsaved/);
   if(kind==='auto') {
    assert.equal(await page.locator('.zfsas-dataset-checkbox:checked').count(),2);
    assert(await page.locator('#dry_run').isChecked());
    assert.equal(await page.locator('#schedule_mode').inputValue(),'disabled');
   } else {
    assert.equal(await page.locator('[name="send_max_parallel"]').inputValue(),'1');
    assert.equal(await page.locator('[name="send_rate_limit"]').inputValue(),'0');
   }
   const options=JSON.parse(await page.locator('[data-config-tools]').getAttribute('data-config-tools'));
   if(kind==='send') await page.getByRole('tab',{name:'Performance'}).click();
   await page.locator('[name="'+prefix+'"]').fill(options.otherPrefix+'overlap-');
   assert(await page.locator('[name="'+prefix+'"]').evaluate(input=>!input.checkValidity()));
   assert.match(await page.locator('[data-prefix-feedback]').textContent(),/Conflict/);
   assert.deepEqual(errors,[]);
   await page.close();
  }
  console.log('PASS: current configuration forms, filtered header selection, disabled destinations, reset preserves selections/prefix/Dry Run, shared defaults, dirty state and prefix conflicts');
 } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exit(1);});
