/* Progressive layout: use the existing forms, revisions and authorization paths. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id), root = document.querySelector('.zfsas-workspace');
  const escape = ZfsasUI.escape;
  function button(label, run, className='btn-quiet') { const b=document.createElement('button');b.type='button';b.className=className;b.textContent=label;b.addEventListener('click',run);return b; }
  const form=$('zfsas_settings_form');
  if(form) {
    const sections=[...form.querySelectorAll('[data-auto-section]')], footer=form.querySelector('.zfsas-actions'), save=$('zfsas_save_btn');
    const summary=document.createElement('section');summary.id='automation-summary';summary.className='ui-summary';form.before(summary);
    const discovery=$('dataset-discovery-status');const retry=discovery.nextElementSibling;summary.before(discovery);if(retry?.tagName==='BUTTON')discovery.after(retry);
    const steps=document.createElement('ol');steps.className='ui-stepper';steps.innerHTML='<li>Choose data</li><li>Schedule &amp; history</li><li>Review</li>';form.prepend(steps);
    const history=sections.find(section=>section.dataset.autoSection==='history');
    const customize=document.createElement('details');customize.innerHTML='<summary>Customize history</summary>';customize.append(history.querySelector('.zfsas-grid'));history.append(customize);
    const cron=$('resolved_cron_value').parentElement;$('automation-schedule-options').append(cron);
    const review=document.createElement('section');review.className='ui-flow-review';footer.before(review);
    const back=button('Back',()=>show(Math.max(0,step-1))), next=button('Continue',()=>{if(validate()){show(step+1);}},'btn-primary'), cancel=button('Cancel',()=>{
      if(form.dataset.dirty==='true'&&!confirm('Discard unsaved automation changes?'))return;
      restore();finish();
    });footer.prepend(back,next);footer.append(cancel);
    let baseline=[], step=0, mode=null, configured=false;
    function capture(){baseline=[...form.querySelectorAll('input[name],select[name]')].map(el=>[el,el.value,el.checked]);configured=!!form.querySelector('.zfsas-dataset-checkbox:checked');}
    function restore(){baseline.forEach(([el,value,checked])=>{el.value=value;el.checked=checked;});form.dispatchEvent(new Event('input',{bubbles:true}));form.dispatchEvent(new Event('change',{bubbles:true}));$('schedule_mode').dispatchEvent(new Event('change',{bubbles:true}));}
    function description(){
      const names=[...form.querySelectorAll('.zfsas-dataset-checkbox:checked')].map(el=>el.closest('tr').querySelector('input[type=hidden]').value);
      const schedule=$('schedule_preview').textContent||$('schedule_mode').selectedOptions[0].textContent;
      return [['data','Data',names.length?names.join(', '):'No datasets selected'],['schedule','Schedule',schedule],['history','History to keep',$('automation-retention-summary').textContent],['advanced','Advanced',$('dry_run').checked?'Dry Run enabled — no snapshots are created or deleted.':'Prefix: '+$('prefix').value]];
    }
    function renderSummary(target,editable){target.replaceChildren();for(const [key,title,text] of description()){const row=document.createElement('div');row.className='ui-summary-row';row.innerHTML='<div><h3>'+escape(title)+'</h3><p>'+escape(text)+'</p></div>';if(editable)row.append(button('Edit',()=>start(key)));target.append(row);}}
    function finish(){mode=null;form.hidden=true;summary.hidden=false;root.classList.remove('workflow-editing');renderSummary(summary,true);if(!configured){summary.innerHTML='<div class="ui-empty"><h2>Keep earlier versions automatically</h2><p>Choose data, decide when to snapshot it, and review how much history to keep.</p></div>';summary.firstChild.append(button('Set up automatic snapshots',()=>start('new'),'btn-primary'));}summary.querySelector('button')?.focus();}
    function start(section){mode=section;summary.hidden=true;form.hidden=false;root.classList.add('workflow-editing');show(0);}
    function show(index){step=index;const wizard=mode==='new';steps.hidden=!wizard;[...steps.children].forEach((li,i)=>{if(i===step)li.setAttribute('aria-current','step');else li.removeAttribute('aria-current');});sections.forEach(section=>{const key=section.dataset.autoSection;section.hidden=wizard?(step===0?key!=='data':step===1?!['schedule','history','advanced'].includes(key):true):key!==mode;});review.hidden=!wizard||step!==2;if(!review.hidden){renderSummary(review,false);const consequence=document.createElement('p');consequence.className='ui-notice';consequence.textContent='Automatic runs create snapshots and remove eligible older history according to these settings. Holds and required replication references remain protected.';review.append(consequence);}back.hidden=!wizard||step===0;next.hidden=!wizard||step===2;save.hidden=wizard&&step!==2;save.classList.add('btn-primary');if(mode==='advanced')$('automation-advanced').open=true;form.querySelector('[data-auto-section]:not([hidden]) h3, .ui-flow-review h3')?.setAttribute('tabindex','-1');form.querySelector('[data-auto-section]:not([hidden]) h3, .ui-flow-review h3')?.focus();}
    function validate(){if(step===0&&!form.querySelector('.zfsas-dataset-checkbox:checked')){ZfsasUI.notice('Select at least one dataset to continue.',true);return false;}for(const section of sections.filter(s=>!s.hidden))for(const field of section.querySelectorAll('input,select'))if(!field.checkValidity()){for(let node=field.parentElement;node&&node!==form;node=node.parentElement)if(node.tagName==='DETAILS')node.open=true;field.reportValidity();return false;}ZfsasUI.notice('');return true;}
    form.addEventListener('invalid',event=>{const section=event.target.closest('[data-auto-section]');if(section?.hidden){mode=section.dataset.autoSection;show(0);}for(let node=event.target.parentElement;node&&node!==form;node=node.parentElement)if(node.tagName==='DETAILS')node.open=true;},true);
    form.addEventListener('zfsas:saved',event=>{if(event.detail.saved){capture();finish();if(!event.detail.schedulerApplied)ZfsasUI.notice('Settings saved, but the scheduler could not apply them. '+(event.detail.notices||[]).join(' '),true);else ZfsasUI.notice('Automatic snapshot settings saved.');}});
    // Newly discovered rows are added to the rollback baseline without replacing drafts.
    document.addEventListener('zfsas:datasets-ready',()=>{const known=new Set(baseline.map(([el])=>el));for(const el of form.querySelectorAll('input[name],select[name]'))if(!known.has(el))baseline.push([el,el.value,el.checked]);});
    capture();finish();
  }
  // A single browsing header; full filtering and exact captured selections remain intact.
  if($('manager')) {
    const picker=root.querySelector('[aria-label="Choose dataset"]'), filters=$('filters'), manager=$('manager');
    const main=picker.querySelector('.toolbar'), advanced=filters.querySelector('details');
    const dataset=$('dataset').closest('label');main.prepend(dataset);
    const browse=document.createElement('div');browse.className='ui-browse-search';
    [...filters.children].filter(el=>el.tagName==='LABEL').forEach(el=>browse.append(el));main.append(browse);
    // Keep relocated fields associated with their original filter form.
    browse.querySelectorAll('[name]').forEach(el=>el.setAttribute('form','filters'));
    browse.addEventListener('input',()=>filters.dispatchEvent(new Event('input',{bubbles:true})));
    const discovery=document.createElement('details');discovery.className='ui-dataset-discovery';discovery.innerHTML='<summary>Find a dataset</summary><div class="toolbar"></div>';
    for(const id of ['dataset-search','pool'])discovery.lastChild.append($(id).closest('label'));
    discovery.lastChild.append($('reload-datasets'));picker.append(discovery);
    picker.append(filters);filters.prepend(discovery);advanced.classList.remove('ui-advanced');advanced.querySelector('summary').textContent='Filters';
    const pager=$('page-text').closest('.toolbar');pager.classList.add('ui-pager');manager.append(pager);
    const sizes=[...manager.children].find(el=>el.tagName==='DETAILS');if(sizes){sizes.classList.add('ui-size-help');pager.after(sizes);}
    const datasetActions=$('dataset-actions');const take=document.createElement('details');take.className='ui-action-menu';take.innerHTML='<summary>Take snapshot</summary>';while(datasetActions.firstChild)take.append(datasetActions.firstChild);datasetActions.append(take);const cleanup=$('cleanup');if(cleanup){datasetActions.append(cleanup);}
    // Bound browsing height while retaining every row and the original actions.
    manager.querySelector('.table-wrap').classList.add('ui-snapshot-table');
  }
  if($('migrate_dataset')) {
    const container=root.querySelector('.zfsas-dm-page'), cards=[...container.querySelectorAll('.zfsas-dm-card')];
    container.before($('migrate_feedback'));
    const steps=document.createElement('ol');steps.className='ui-stepper';steps.innerHTML='<li>Select data</li><li>Review</li><li>Run</li>';container.prepend(steps);
    const footer=document.createElement('div');footer.className='ui-form-footer';const back=button('Back to selection',()=>show(0));footer.append(back);container.append(footer);
    function show(step){cards.forEach((card,i)=>card.hidden=step===0?i!==0:step===1?![1,2,3].includes(i):i<4);steps.querySelectorAll('li').forEach((li,i)=>{if(i===step)li.setAttribute('aria-current','step');else li.removeAttribute('aria-current');});back.hidden=step!==1;}
    document.addEventListener('zfsas:migration-state',event=>{const data=event.detail;if(data.active)show(2);else if(data.reviewed)show(1);});
    show(0);
  }
  root.addEventListener('toggle',event=>{
    const menu=event.target;if(!menu.matches?.('.ui-action-menu'))return;
    if(menu.open){root.querySelectorAll('.ui-action-menu[open]').forEach(other=>{if(other!==menu)other.open=false;});}
  },true);
  root.addEventListener('keydown',event=>{if(event.key==='Escape'){const menu=event.target.closest('.ui-action-menu[open]');if(menu){menu.open=false;menu.querySelector('summary').focus();event.preventDefault();}}});
  root.addEventListener('click',event=>{const button=event.target.closest('button');const menu=button?.closest('.ui-action-menu');if(menu)menu.open=false;});
  document.addEventListener('click',event=>{root.querySelectorAll('.ui-action-menu[open]').forEach(menu=>{if(!menu.contains(event.target))menu.open=false;});});
})();
