(() => {
  'use strict';
  const $=id=>document.getElementById(id), form=$('zfsas_send_form'), body=$('zfsas_send_jobs_body'), list=$('replication-job-list'), dialog=$('edit-job-dialog');
  const value=(row,name)=>row.querySelector('[name^="job_'+name+'["]')?.value || '';
  let step=0, quick=false, suspended=false, returnTrigger=null;
  let editing=null, original=null, editorBaseline='', summary=null, busy=false, fresh=false, nextIndex=body.querySelectorAll('[data-job]').length;
  const records=()=>[...body.querySelectorAll('[data-job]')];
  const action=(label,run)=>{const button=document.createElement('button');button.type='button';button.className='btn-quiet';button.textContent=label;button.addEventListener('click',run);return button;};
  function render() {
    if(list.contains(document.activeElement) || list.querySelector('details[open]'))return;
    list.replaceChildren();
    if(!records().length){list.innerHTML='<div class="ui-empty"><strong>No replication jobs yet</strong>Add a source and destination to keep another copy of your datasets.</div>';return;}
    const table=document.createElement('table');table.innerHTML='<thead><tr><th>Source → destination</th><th>Schedule</th><th>State</th><th>Actions</th></tr></thead><tbody></tbody>';
    for(const row of records()) {
      const id=value(row,'id'), schedule=summary?.schedules?.find(item=>item.id===id), item=document.createElement('tr');
      item.dataset.jobId=id;
      const path=document.createElement('td'), code=document.createElement('code');code.textContent=value(row,'source')+' → '+value(row,'destination');path.append(code);
      const retention=document.createElement('small');retention.className='ui-operation-kind';const keep=value(row,'source_keep');retention.textContent=keep&&keep!=='0'?'Source: latest '+keep+' checkpoints + protected snapshots':'Source: keep all snapshots';path.append(retention);
      const when=document.createElement('td');when.textContent=row.querySelector('[name^="job_frequency["]')?.selectedOptions[0]?.textContent || '';
      const next=document.createElement('small');next.className='ui-operation-kind';next.textContent=schedule?.paused?'Paused until Resume':schedule?.preview?.nextScheduledText || 'Next occurrence unavailable';when.append(next);
      const state=document.createElement('td');state.textContent=schedule?.paused?'Paused':'Configured';
      const actions=document.createElement('td'), edit=action('Edit',()=>open(row,edit));actions.append(edit);
      const menu=document.createElement('details');menu.className='ui-action-menu';menu.innerHTML='<summary>Actions</summary>';actions.append(menu);
      if(schedule)menu.append(action(schedule.paused?'Resume schedule':'Pause schedule',async event=>{
        const button=event.currentTarget;button.disabled=true;
        try {const response=await ZfsasRequests.request('replication-control','/plugins/zfs.snapsync/php/send-queue-action.php',{action:schedule.paused?'resume':'pause',job_id:id});schedule.paused=!schedule.paused;menu.open=false;button.blur();render();ZfsasUI.notice(response.message);status.refresh();}
        catch(error){errorAt(actions,error.message);}finally{button.disabled=false;}
      }));
      if(value(row,'transport')==='local')menu.append(action('Review recovery',event=>ZfsasRecovery.open('',id,event.currentTarget)));
      menu.append(action('Remove job',()=>{
        if(busy)return;
        const review=$('remove-job-dialog');review.dataset.id=id;$('remove-job-name').textContent=value(row,'source')+' → '+value(row,'destination');ZfsasUI.open(review,edit);
      }));
      item.append(path,when,state,actions);table.tBodies[0].append(item);
    }
    const wrap=document.createElement('div');wrap.className='table-wrap';wrap.append(table);list.append(wrap);
  }
  function errorAt(parent,message) {let node=parent.querySelector('[data-save-error]');if(!node){node=document.createElement('p');node.dataset.saveError='';node.setAttribute('role','alert');parent.append(node);}node.textContent=message;}
  function open(row,trigger,isNew=false) {
    if(busy||!dialog.hidden)return;
    fresh=isNew;original=row;editing=row.cloneNode(true);
    const originals=[...row.querySelectorAll('input,select')];editing.querySelectorAll('input,select').forEach((input,index)=>{input.value=originals[index].value;input.checked=originals[index].checked;});
    // Attach fresh behavior; clones contain values, but no listeners.
    editing.querySelector('.source-retention-control')?.remove();
    editing.querySelector('[name^="job_time["]')?.closest('.zfsas-send-help')?.remove();
    delete editing.dataset.scheduleReady;
    editing.dataset.sourceKeep=value(row,'source_keep') || row.dataset.sourceKeep || '3';
    editing.dataset.scheduleTime=value(row,'time') || '00:00';editing.dataset.scheduleDay=value(row,'day') || '0';
    ZfsasSourceRetention.attach(editing);ZfsasSendSchedule.attach(editing);
    const cells=[...editing.children], names=['Source dataset','Destination dataset','Schedule','Include children','Transport','Destination free-space target','Low-space retention','Source snapshots'];
    cells.forEach((cell,index)=>{
      if(index===cells.length-1){cell.hidden=true;return;}
      cell.dataset.editorCell=index;cell.querySelector(':scope > label[data-editor-label]')?.remove();cell.querySelector(':scope > small[data-connection-hint]')?.remove();cell.querySelector(':scope > [data-connection-setup]')?.remove();
      const label=document.createElement('label');label.dataset.editorLabel='';label.textContent=names[index];const field=cell.querySelector('select,input:not([type=hidden])');
      if(field){field.id='job-editor-field-'+index;label.htmlFor=field.id;}cell.prepend(label);if(index===4){const hint=document.createElement('small');hint.dataset.connectionHint='';hint.textContent='Uses the shared SSH connection.';const setup=action('Set up connection',()=>openShared('connection'));setup.dataset.connectionSetup='';cell.append(setup);cell.append(hint);const transport=cell.querySelector('select');if(transport){const show=()=>{hint.hidden=transport.value!=='ssh';setup.hidden=hint.hidden;};transport.addEventListener('change',show);show();}}
    });
    editing.replaceChildren();
    for(const [title,indices] of [['Source and destination',[0,4,1,3]],['Schedule and history',[2,7,5,6]]]) {
      const section=document.createElement('section'), heading=document.createElement('h3');heading.textContent=title;section.append(heading);const grid=document.createElement('div');grid.className='ui-editor-grid';indices.forEach(i=>{if(cells[i])grid.append(cells[i]);});section.append(grid);editing.append(section);
    }
    const space=document.createElement('details');space.className='ui-job-space';space.innerHTML='<summary>Destination space and low-space policy</summary><div class="ui-editor-grid"></div>';space.lastChild.append(cells[5],cells[6]);editing.children[1].append(space);
    const destinationHelp=document.createElement('small');destinationHelp.dataset.connectionHint='';destinationHelp.textContent='Use a destination below an existing parent dataset. Backups remain read-only.';cells[1].append(destinationHelp);
    const review=document.createElement('section');review.className='ui-job-review';editing.append(review);editing.append(cells[cells.length-1]);
    $('job-editor-body').replaceChildren(editing);$('edit-job-title').textContent=isNew?'Add replication job':'Edit replication job';$('finish-job-edit').textContent=isNew?'Create job':'Save job';$('job-editor-error').textContent='';
    editorBaseline=JSON.stringify([...editing.querySelectorAll('[name]')].map(input=>[input.name,input.value,input.checked]));
    returnTrigger=trigger;dialog.hidden=false;showEditor();showStep(isNew?0:-1);
  }
  function controlsValid(root) {for(const input of root.querySelectorAll('input,select'))if(!input.checkValidity()){const section=input.closest('#job-editor-body section');if(section)showStep([...editing.children].indexOf(section));for(let node=input.parentElement;node&&node!==form;node=node.parentElement)if(node.tagName==='DETAILS')node.open=true;input.reportValidity();return false;}return true;}
  async function save(scope,row,button,errorNode) {
    if(busy)return null;
    busy=true;const label=button.textContent;button.disabled=true;button.textContent='Saving…';errorNode.textContent='';
    dialog.querySelector('#cancel-job-edit').disabled=true;
    const payload={scope,ajax:'save',config_revision:form.elements.config_revision.value};
    if(row)for(const input of row.querySelectorAll('[name]')){if(input.disabled || (input.type==='checkbox'&&!input.checked))continue;payload[input.name.replace(/\[\d+\]/,'[0]')]=input.value;}
    else for(const input of $('replication-shared').querySelectorAll('[name]'))payload[input.name]=input.value;
    const locked=[...(row || $('replication-shared')).querySelectorAll('input,select,button')].map(input=>[input,input.disabled]);locked.forEach(([input])=>input.disabled=true);
    try {
      const data=await ZfsasRequests.request('replication-save',form.dataset.ajaxAction,payload,45000).catch(error=>{if(error.data?.saved)return error.data;throw error;});
      if(!data.saved)throw new Error(data.errors?.join(' ') || 'Configuration was not saved.');
      if(scope==='shared'&&data.settings)for(const input of $('replication-shared').querySelectorAll('[name]')){const canonical=data.settings[input.name.toUpperCase()];if(canonical!==undefined)input.value=canonical;}
      locked.forEach(([input,disabled])=>input.disabled=disabled);
      form.elements.config_revision.value=data.revision;
      form.dispatchEvent(new CustomEvent('zfsas:saved',{detail:{...data,scope}}));
      if(!data.schedulerApplied)ZfsasUI.notice('Configuration saved; scheduler application failed. '+(data.notices||[]).join(' '),true);
      status.refresh();return data;
    }catch(error){errorNode.textContent=error.message;if(row&&/review|retention/i.test(error.message))showStep(1);return null;}
    finally{locked.forEach(([input,disabled])=>input.disabled=disabled);busy=false;button.disabled=false;button.textContent=label;$('cancel-job-edit').disabled=false;}
  }
  $('open-new-job').addEventListener('click',event=>{
    const row=$('job-template').content.firstElementChild.cloneNode(true);row.querySelectorAll('[name]').forEach(input=>input.name=input.name.replace(/\[\d+\]/,'['+(nextIndex)+']'));nextIndex++;open(row,event.currentTarget,true);
  });
  $('cancel-job-edit').addEventListener('click',()=>{if(busy)return;if(editorBaseline!==fingerprint()&&!confirm('Discard unsaved job changes?'))return;closeEditor();});
  dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});

  $('finish-job-edit').addEventListener('click',async event=>{
    if(!editing)return;if(fresh&&step<2){if(controlsValid(editing.children[step]))showStep(step+1);return;}if(!controlsValid(editing))return;
    const data=await save(fresh?'job_create':'job_update',editing,event.currentTarget,$('job-editor-error'));if(!data)return;
    const id=value(editing,'id'), job=data.jobs.find(job=>id?job.id===id:job.source===value(editing,'source')&&job.destination===value(editing,'destination').trim());
    if(!job){$('job-editor-error').textContent='Saved, but the canonical job was missing. Reload before making further changes.';return;}
    for(const key of ['id','source','destination','frequency','threshold','children','transport'])if(job[key]!==undefined)editing.querySelector('[name^="job_'+key+'["]').value=job[key];
    editing.dataset.sourceKeep=value(editing,'source_keep');
    const flat=[...editing.querySelectorAll('[data-editor-cell]')].sort((a,b)=>Number(a.dataset.editorCell)-Number(b.dataset.editorCell));flat.push(editing.lastElementChild);editing.replaceChildren(...flat);editing.querySelectorAll('[id]').forEach(field=>field.removeAttribute('id'));
    if(fresh)body.append(editing);else original.replaceWith(editing);
    const savedId=job.id;closeEditor();document.activeElement?.blur();render();(list.querySelector('[data-job-id="'+savedId+'"] button') || $('open-new-job')).focus();
  });
  $('save_send_btn').addEventListener('click',async event=>{
    if(!controlsValid($('replication-shared')))return;
    const data=await save('shared',null,event.currentTarget,$('shared-save-status'));if(data){$('shared-save-status').textContent=data.schedulerApplied?'Shared settings saved.':'Saved; scheduler application failed.';if(suspended)returnFromShared();}
  });
  form.addEventListener('submit',event=>{event.preventDefault();(!dialog.hidden?$('finish-job-edit'):$('save_send_btn')).click();});
  const remove=document.createElement('dialog');remove.id='remove-job-dialog';remove.setAttribute('aria-labelledby','remove-job-title');remove.innerHTML='<div class="ui-dialog-header"><h2 id="remove-job-title">Remove replication job?</h2><button type="button" data-close-dialog>Cancel</button></div><p id="remove-job-name"></p><p>This removes the saved schedule. Existing snapshots remain; already accepted runs may finish.</p><p data-save-error role="alert"></p><div class="ui-form-footer"><button type="button" class="btn-danger">Remove job</button></div>';form.append(remove);
  remove.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
  remove.querySelector('.btn-danger').addEventListener('click',async event=>{const row=records().find(row=>value(row,'id')===remove.dataset.id);if(!row)return;remove.querySelector('[data-close-dialog]').disabled=true;const data=await save('job_remove',row,event.currentTarget,remove.querySelector('[data-save-error]'));remove.querySelector('[data-close-dialog]').disabled=false;if(data){row.remove();remove.close();render();$('open-new-job').focus();}});
  window.addEventListener('beforeunload',event=>{if(editing && editorBaseline!==JSON.stringify([...editing.querySelectorAll('[name]')].map(input=>[input.name,input.value,input.checked]))){event.preventDefault();event.returnValue='';}});
  const status=ZfsasRequests.poll('replication-status',()=>ZfsasRequests.request('replication-status','/plugins/zfs.snapsync/php/workspace-summary.php'),data=>{summary=data;if(dialog.hidden&&!remove.open)render();return false;});
  const transport=$('shared-transport');transport.value=records().some(row=>value(row,'transport')==='ssh')?'ssh':'local';
  const connectionFields=()=>{$('shared-ssh-settings').hidden=transport.value!=='ssh';};transport.addEventListener('change',connectionFields);connectionFields();
  $('replication-shared').addEventListener('invalid',event=>{if(event.target.name.startsWith('send_ssh_')){transport.value='ssh';connectionFields();}},true);

  const fingerprint=()=>JSON.stringify([...editing.querySelectorAll('[name]')].map(input=>[input.name,input.value,input.checked]));
  function showEditor(){ $('backup-list-panel').hidden=true;$('replication-shared').hidden=true;form.querySelector('.zfsas-send-actions').hidden=true;dialog.hidden=false;document.querySelector('.zfsas-workspace').classList.add('workflow-editing'); }
  function closeEditor(){dialog.hidden=true;editing=null;original=null;$('job-editor-body').replaceChildren();$('backup-list-panel').hidden=false;$('replication-shared').hidden=false;form.querySelector('.zfsas-send-actions').hidden=false;document.querySelector('.zfsas-workspace').classList.remove('workflow-editing');returnTrigger?.focus();}
  const steps=document.createElement('ol');steps.className='ui-stepper';steps.innerHTML='<li>Source &amp; destination</li><li>Schedule &amp; history</li><li>Review</li>';$('job-editor-body').before(steps);
  const back=action('Back',()=>{if(!busy)showStep(fresh?Math.max(0,step-1):-1);});dialog.querySelector('.ui-form-footer').prepend(back);
  function showStep(index){
    step=index;quick=!fresh;steps.hidden=quick;[...steps.children].forEach((li,i)=>{if(i===step)li.setAttribute('aria-current','step');else li.removeAttribute('aria-current');});
    const sections=[...editing.querySelectorAll(':scope > section')];sections.forEach((section,i)=>section.hidden=i!==index);
    if(index===2||index===-1){const review=sections[2];review.hidden=false;review.replaceChildren();for(const [title,text,edit] of [['Data',value(editing,'source')+' → '+value(editing,'destination'),0],['Schedule',editing.querySelector('[name^="job_frequency["]').selectedOptions[0].textContent+' '+(['1d','1w'].includes(value(editing,'frequency'))?value(editing,'time'):''),1],['History',value(editing,'source_keep')==='0'?'Keep all source snapshots':'Keep latest '+value(editing,'source_keep')+' source checkpoints, plus protected snapshots',1]]){const row=document.createElement('div');row.className='ui-summary-row';const description=document.createElement('div');description.innerHTML='<h3>'+ZfsasUI.escape(title)+'</h3><p>'+ZfsasUI.escape(text)+'</p>';row.append(description,action('Edit',()=>showStep(edit)));review.append(row);}const note=document.createElement('p');note.className='ui-notice';note.textContent='Backups are read-only. Source cleanup follows verified local replication and requires review when applicable. Destination history uses the shared backup settings.';review.append(note);review.append(action('Shared history settings',()=>openShared('history')));}
    $('finish-job-edit').hidden=index===-1;$('finish-job-edit').textContent=fresh?(index<2?'Continue':'Create job'):'Save job';back.hidden=index===-1||(fresh&&index===0);
    $('edit-job-title').textContent=fresh?'Create a backup job':'Backup job';
    const title=sections.find(section=>!section.hidden)?.querySelector('h3');if(title){title.tabIndex=-1;title.focus();}
  }
  const shared=$('replication-shared'), sharedGrid=shared.querySelector('.zfsas-send-inline-grid'), tabs=document.createElement('div');tabs.className='ui-tabs';tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label','Shared backup settings');
  const panels={history:document.createElement('section'),connection:document.createElement('section'),performance:document.createElement('section')};
  for(const [key,panel] of Object.entries(panels)){panel.id='shared-'+key;panel.setAttribute('role','tabpanel');panel.setAttribute('aria-labelledby','tab-'+key);shared.insertBefore(panel,shared.querySelector('.ui-form-footer'));const tab=action(key[0].toUpperCase()+key.slice(1),()=>selectShared(key));tab.id='tab-'+key;tab.setAttribute('role','tab');tab.setAttribute('aria-controls',panel.id);tabs.append(tab);}
  panels.performance.append(sharedGrid);
  panels.connection.append($('shared-transport').previousElementSibling,$('shared-transport'),$('shared-ssh-settings'));
  [...shared.children].filter(el=>el.matches('.zfsas-send-inline-grid,.zfsas-send-retention-grid,[data-config-tools]')).forEach(el=>panels.history.append(el));
  shared.querySelector('summary').after(tabs);const globalNote=document.createElement('p');globalNote.className='muted';globalNote.textContent='These settings apply to all backup jobs. Saving here does not save your job draft.';tabs.before(globalNote);
  const returnButton=action('Return to job',returnFromShared);returnButton.hidden=true;shared.querySelector('.ui-form-footer').append(returnButton);
  function selectShared(key){for(const [name,panel] of Object.entries(panels))panel.hidden=name!==key;[...tabs.children].forEach(tab=>{const selected=tab.id==='tab-'+key;tab.setAttribute('aria-selected',String(selected));tab.tabIndex=selected?0:-1;});}
  tabs.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;const all=[...tabs.children],i=all.indexOf(document.activeElement);const n=event.key==='Home'?0:event.key==='End'?all.length-1:(i+(event.key==='ArrowRight'?1:-1)+all.length)%all.length;all[n].click();all[n].focus();event.preventDefault();});
  function openShared(key){suspended=true;dialog.hidden=true;shared.hidden=false;shared.open=true;returnButton.hidden=false;selectShared(key);if(key==='connection'){$('shared-transport').value='ssh';$('shared-transport').dispatchEvent(new Event('change'));}$('tab-'+key).focus();}
  function returnFromShared(){suspended=false;returnButton.hidden=true;showEditor();showStep(step);}
  shared.addEventListener('invalid',event=>{const panel=event.target.closest('[role=tabpanel]');if(panel){shared.open=true;selectShared(panel.id.replace('shared-',''));}},true);
  selectShared('history');
  render();
})();
