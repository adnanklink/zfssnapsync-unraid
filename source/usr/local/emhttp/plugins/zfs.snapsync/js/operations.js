(() => {
  'use strict';
  const $=id=>document.getElementById(id), escape=ZfsasUI.escape, base='/plugins/zfs.snapsync/php/';
  const overview=document.querySelector('.zfsas-workspace').dataset.section==='overview';
  const terminal=new Set(['complete','completed','failed','canceled','skipped','recorded']);
  let snapshot=null, selected=null, busy=false, logPoll=null,detailLogPoll=null,stagePoll=null;
  const phaseLabel=value=>({replication_schedule:'Check datasets',replication_snapshot:'Create source snapshot',replication_member:'Inspect destination',replication_inspect:'Inspect destination',replication_space:'Check destination space',replication_transfer:'Transfer snapshot',replication_verify:'Verify received snapshot',replication_run_verify:'Verify all datasets',recovery_scan:'Inspect recovery membership',recovery_member_review:'Review interrupted snapshot',recovery_review_finish:'Complete recovery review',recovery_execute_start:'Start reviewed recovery',recovery_finish:'Verify recovery'}[value] || (value||'—').replaceAll('_',' '));
  const needsAttention=op=>op.needsAttention ?? ((op.state==='failed'||op.recoveryRequired)&&!op.attentionDismissed);
  const active=operation=>!terminal.has(operation.state);
  const date=epoch=>epoch ? new Date(epoch*1000).toLocaleString(undefined,{timeZone:snapshot?.timezone || 'UTC',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}) : 'Not recorded';
  const label=operation=>operation.state==='canceling'?'Stopping · cancellation saved':operation.stateLabel || ({retry_wait:'Waiting to retry',complete:'Completed',running:'Running',queued:'Queued',failed:'Failed',canceled:'Canceled',waiting:'Waiting'}[operation.state] || operation.state);
  function render(data) {
    snapshot=data;
    const available=Object.values(data.sources).every(source=>source.available);
    $('summary-availability').replaceChildren();
    for(const source of Object.values(data.sources)) if(!source.available) {
      const notice=document.createElement('p');notice.className='ui-notice';notice.textContent=source.message;$('summary-availability').append(notice);
    }
    if(data.service&&(!data.service.compatible||data.service.refreshPending)){const notice=document.createElement('p');notice.className='ui-notice';notice.textContent=data.service.message;$('summary-availability').append(notice);}
    document.querySelectorAll('[data-host-zone]').forEach(node=>node.textContent=data.timezone);
    $('activity-updated').textContent='Updated '+date(data.generatedAt);
    if(overview) {
      $('summary-active').textContent=data.operations.filter(active).length+(available?'':' + ?');
      $('summary-attention').textContent=data.operations.filter(needsAttention).length+(available?'':' + ?');
      $('summary-paused').textContent=data.sources.configuration?.available?data.pausedSchedules.length:'Unavailable';
      const upcoming=$('upcoming-schedules');
      if (!upcoming.contains(document.activeElement)) { upcoming.replaceChildren();
      for(const schedule of [...data.schedules].sort((a,b)=>(a.preview.nextScheduledTime||Infinity)-(b.preview.nextScheduledTime||Infinity)).slice(0,6)) {
        const item=document.createElement('div');item.className='ui-schedule-item';
        const name=document.createElement('strong');name.textContent=schedule.label;
        const when=document.createElement('p');when.className='muted';when.textContent=schedule.paused?'Paused until Resume':schedule.preview.nextScheduledText || 'Not scheduled';
        item.append(name,when);
        if(schedule.paused) {const resume=document.createElement('button');resume.type='button';resume.textContent='Resume schedule';resume.addEventListener('click',()=>resumeSchedule(schedule,resume));item.append(resume);}
        upcoming.append(item);
      }
      if(!data.schedules.length) upcoming.textContent=data.sources.configuration?.available?'No schedules configured.':'Schedule information unavailable.';
      }
      const attention=$('attention-list');
      if (!attention.contains(document.activeElement)) { attention.replaceChildren();
      const failures=data.operations.filter(needsAttention).slice(0,4);
      for(const op of failures) { const button=document.createElement('button');button.type='button';button.className='ui-attention-item';button.textContent=op.title+' · '+(op.source||label(op));button.addEventListener('click',()=>open(op.id,button));attention.append(button); }
      if(!failures.length) attention.innerHTML='<div class="ui-empty"><strong>'+ (available?'No reported failures':'Status is incomplete')+'</strong>'+ (available?'Nothing needs attention in the available recent records.':'Some sources are unavailable. See the notices above.')+'</div>';
    }
    }
    rows();
    if(selected && $('operation-detail').open && !busy) { const op=data.operations.find(op=>op.id===selected);if(op) detail(op); }
    return data.operations.some(active);
  }
  function rows() {
    let operations=snapshot.operations;
    const type=$('activity-type')?.value,state=$('activity-state')?.value;
    if(type) operations=operations.filter(op=>op.type===type);
    if(state) operations=operations.filter(op=>state==='active'?active(op):state==='failed'?needsAttention(op):state==='complete'?['complete','completed'].includes(op.state):op.state===state);
    if(overview) operations=operations.slice(0,8);
    const body=$('operation-rows'), existing=new Map([...body.querySelectorAll('[data-operation]')].map(row=>[row.dataset.operation,row]));
    body.querySelector('[data-empty]')?.remove();
    if(!existing.size) body.replaceChildren();
    for(const op of operations) {
      let row=existing.get(op.id);
      if(!row) {row=document.createElement('tr');row.dataset.operation=op.id;row.innerHTML='<td><strong></strong><small class="ui-operation-kind"></small></td><td><code></code></td><td><span class="ui-badge"></span></td><td></td><td><button type="button">Details</button></td>';row.querySelector('button').addEventListener('click',event=>open(op.id,event.currentTarget));}
      existing.delete(op.id);
      row.cells[0].querySelector('strong').textContent=op.title;
      row.cells[0].querySelector('small').textContent=op.parentId?'Run '+op.parentId:op.type;
      row.cells[1].querySelector('code').textContent=[op.source,op.destination].filter(Boolean).join(' → ') || 'Configured datasets';
      const badge=row.cells[2].firstChild;badge.dataset.state=['Waiting','Waiting to retry','Queued'].includes(op.stateLabel)?'waiting':op.state;badge.textContent=label(op);
      let activity=row.cells[2].querySelector('.ui-transfer-status');
      if(!activity){
        activity=document.createElement('div');activity.className='ui-transfer-status';
        activity.innerHTML='<small class="ui-transfer-phase"></small><progress max="100" aria-label="Estimated transfer progress"></progress><small class="ui-transfer-message"></small>';
        row.cells[2].append(activity);
      }
      const phase=activity.querySelector('.ui-transfer-phase'),bar=activity.querySelector('progress'),message=activity.querySelector('.ui-transfer-message');
      const phaseText=active(op)?(op.phase || (op.blocked||[]).join(', ') || '').replaceAll('_',' '):'';
      const messageText=active(op)&&op.type==='replication'?op.message || '':'';
      if(phase.textContent!==phaseText)phase.textContent=phaseText;
      if(message.textContent!==messageText)message.textContent=messageText;
      phase.title=phaseText;message.title=messageText;
      activity.classList.toggle('is-replication',op.type==='replication');
      // Reserve the same bar/text space while queued, checking resources and
      // transferring. Updating samples must not move the rows below this one.
      const showBar=active(op)&&op.type==='replication'&&(Number.isFinite(op.progress)||op.phase?.includes('transfer'));
      bar.style.visibility=showBar?'visible':'hidden';bar.setAttribute('aria-hidden',showBar?'false':'true');
      if(Number.isFinite(op.progress))bar.value=Math.max(0,Math.min(100,op.progress));else bar.removeAttribute('value');
      row.cells[3].textContent=date(op.createdAt);
      // Avoid detaching focused rows on routine refresh.
      if(row.parentElement!==body) body.append(row);
    }
    for(const row of existing.values()) row.remove();
    if(!operations.length) { const row=document.createElement('tr');row.dataset.empty='1';row.innerHTML='<td colspan="5" class="ui-empty"><strong>No operations to show</strong>New work will appear here. Earlier history may be unavailable after reboot.</td>';body.append(row); }
  }
  function detail(op) {
    $('operation-title').textContent=op.title;
    const entries=[['Status',label(op)],['Phase',phaseLabel(op.problem?.phase || op.phase)],['Source',op.source||'Configured datasets'],['Destination',op.destination||'—'],['Requested',date(op.createdAt)],['Next retry',op.retryAt?date(op.retryAt):'—'],['Waiting for',(op.blocked||[]).join(', ')||'—'],['Run',op.parentId||op.nativeId],...(op.sourceCleanupOf?[['Replication run',op.sourceCleanupOf]]:[]),...(op.sourceCleanupRunId?[['Source cleanup run',op.sourceCleanupRunId]]:[])];
    if(op.attentionDismissed)entries.push(['Needs attention','Dismissed for this alert; history and recovery protections remain intact.']);
    if(op.sourceCleanupOf)for(const [label,key] of [['Protected checkpoints','protectedReasons'],['Skipped checkpoints','skippedReasons']])entries.push([label,Object.entries(op.sourceCleanup?.[key]||{}).map(([reason,count])=>reason+' ('+count+')').join('; ')||'None recorded']);
    const content=$('operation-body');
    let metadata=$('operation-metadata');
    if(!metadata){metadata=document.createElement('div');metadata.id='operation-metadata';content.prepend(metadata);}
    const drawer=$('operation-detail'), scroll=drawer.scrollTop;
    if (content.contains(document.activeElement)) return;
    const problem=op.problem?'<section class="ui-notice"><h3>What happened</h3><p>'+escape(op.problem.summary)+'</p><p>'+escape([op.problem.source,op.problem.destination].filter(Boolean).join(' → '))+'</p><h3>Next action</h3><p>'+escape(op.problem.nextAction)+'</p>'+(op.problem.diagnosticAvailable?'<details><summary>ZFS error recorded for this step or an earlier attempt</summary><pre>'+escape(op.problem.diagnostic)+'</pre></details>':'<small>The original ZFS error is unavailable in this run’s recorded history.</small>')+'</section>':'';
    const markup=problem+'<p class="ui-notice">'+escape(label(op))+'. '+escape(op.problem?.nextAction || (active(op)?'Work continues when prerequisites and resources are ready.':op.state==='complete'?'Work recorded as complete.':'Review recorded steps and recovery before retrying.'))+'</p><details data-technical><summary>Technical details</summary><dl>'+entries.map(([key,value])=>'<dt>'+escape(key)+'</dt><dd>'+escape(value)+'</dd>').join('')+'</dl></details>'+(Number.isFinite(op.progress)?'<label>Reported progress<progress max="100" value="'+Math.max(0,Math.min(100,op.progress))+'"></progress>'+escape(op.progress)+'%</label>':'')+(op.recoveryRequired?'<p class="error">Recovery requires review before another transfer. Use the recovery action below when available.</p>':'')+'<a href="'+escape(ZfsasUI.workflowUrl(op.url))+'">Open workflow →</a>';
    if(metadata.dataset.operation!==op.id || metadata.dataset.markup!==markup){
      const expanded=[...metadata.querySelectorAll('details')].map(node=>node.open);
      metadata.innerHTML=markup;metadata.querySelectorAll('details').forEach((node,i)=>node.open=expanded[i]||false);metadata.dataset.markup=markup;metadata.dataset.operation=op.id;
      const related=op.sourceCleanupRunId||op.sourceCleanupOf;
      if(related){const button=document.createElement('button');button.type='button';button.textContent=op.sourceCleanupRunId?'View source cleanup':'View completed replication';button.addEventListener('click',()=>{if(snapshot.operations.some(item=>item.id==='coordinator:'+related))open('coordinator:'+related,button);else ZfsasUI.notice('Related run details are outside the current history window.');});metadata.append(button);}
    }
    drawer.scrollTop=scroll;
    const actions=$('operation-actions');
    const fingerprint=JSON.stringify([op.id,op.actions,op.attentionToken,snapshot.service]);
    if(actions.dataset.fingerprint!==fingerprint) {
      actions.replaceChildren();actions.dataset.fingerprint=fingerprint;
      for(const action of op.actions) {const button=document.createElement('button');button.type='button';button.textContent={review_recovery:'Review recovery',cancel:'Cancel run',retry:'Retry',clear_failed:'Clear failed record',dismiss_attention:'Dismiss from Needs attention',restore_attention:'Restore to Needs attention'}[action];if(action==='cancel')button.className='ui-danger';button.addEventListener('click',()=>perform(op,action));actions.append(button);}
      if(op.coordinator || op.id.startsWith('coordinator:')){if(snapshot.service&&(!snapshot.service.compatible||snapshot.service.refreshPending)){const reason=document.createElement('p');reason.textContent=snapshot.service.message;actions.append(reason);}const log=document.createElement('button');log.type='button';log.textContent='Show job log';log.addEventListener('click',()=>showDetailLog(op));actions.append(log);}
      const shared=document.createElement('button');shared.type='button';shared.textContent='Shared '+(op.logType||op.type)+' log — includes other jobs';shared.addEventListener('click',()=>showDetailLog(op,true));actions.append(shared);
      if(op.logDownloadUrl){const link=document.createElement('a');link.className='btn';link.textContent='Download failure log';link.href=op.logDownloadUrl;actions.append(link);}
    }
  }
  function open(id,trigger) {const op=snapshot.operations.find(item=>item.id===id);if(!op)return;if(selected!==id){detailLogPoll?.stop();stagePoll?.stop();$('operation-checklist')?.remove();$('operation-log-panel')?.remove();}selected=id;$('operation-action-message').textContent='';detail(op);ZfsasUI.open($('operation-detail'),trigger);if(op.coordinator&&op.type==='replication'&&!op.sourceCleanupOf)showChecklist(op);}
  async function perform(op,action) {
    if(busy)return;
    if(action==='review_recovery'){ZfsasRecovery.open(op.nativeId,op.scheduleId);return;}
    if(action==='cancel' && !window.confirm(op.sourceCleanupOf?'Cancel the remaining source cleanup? Replication has completed; snapshots already deleted cannot be restored.':op.manual && op.type==='replication'?'Cancel this manual replication run? Completed receiver snapshots will be preserved.':op.type==='batch'?'Cancel this batch and its pending operations? Completed results will remain available.':'Cancel this whole run? Its schedule will remain paused until Resume.'))return;
    if(action==='retry' && op.coordinator===true && !window.confirm('Retry this captured snapshot and destination? Any interrupted receive will be validated before resuming.'))return;
    if(action==='clear_failed' && op.recoveryRequired && !window.confirm('Clear this recovery record after reviewing the preserved snapshots? Clearing releases its cleanup protection; it does not verify or remove those snapshots.'))return;
    busy=true; $('operation-actions').querySelectorAll('button').forEach(button=>button.disabled=true);
    try {
      if(action==='dismiss_attention'||action==='restore_attention'){
        const data=await ZfsasRequests.request('operation-action',base+'attention-action.php',{action,operation_id:op.id,attention_token:op.attentionToken});
        $('operation-action-message').textContent=data.message;
        return;
      }
      const coordinator=op.coordinator===true||op.type==='auto'||op.type==='batch';
      const data=await ZfsasRequests.request('operation-action',base+(coordinator?'coordinator-action.php':'send-queue-action.php'),coordinator?{action,run_id:op.nativeId}:{action,job_id:op.nativeId});
      $('operation-action-message').textContent=action==='cancel'?'Cancellation saved. Waiting for verified worker shutdown.':data.message||'Request accepted.';
    }catch(error){$('operation-action-message').textContent=error.message;}
    finally{busy=false;$('operation-actions').dataset.fingerprint='';poll.refresh();}
  }
  async function resumeSchedule(schedule,button) {
    button.disabled=true;
    try {await ZfsasRequests.request('resume',base+(schedule.type==='auto'?'coordinator-action.php':'send-queue-action.php'),{action:'resume',job_id:schedule.id});poll.refresh();}
    catch(error){ZfsasUI.notice(error.message,true);button.disabled=false;}
  }
  async function showDetailLog(op,shared=false,offset='latest') {
    detailLogPoll?.stop();detailLogPoll=null;
    let panel=$('operation-log-panel');
    if(!panel){panel=document.createElement('section');panel.id='operation-log-panel';panel.innerHTML='<h3 class="operation-log-scope"></h3><p class="operation-log-notice"></p><pre id="operation-detail-log"></pre><div class="toolbar operation-log-pages"></div>';$('operation-body').append(panel);}
    const output=$('operation-detail-log'),scope=shared?'shared':'job';
    if(panel.dataset.scope!==scope){output.dataset.loaded='';panel.dataset.scope=scope;}
    panel.querySelector('h3').textContent=shared?'Shared '+(op.logType||op.type)+' log — includes other jobs':'Job log — only this run';
    panel.dataset.state='loading';panel.querySelector('.operation-log-notice').textContent='Loading…';
    if(!output.dataset.loaded)output.textContent='Loading log…';
    const url=shared?base+'workspace-log.php?type='+encodeURIComponent(op.logType||op.type):base+'operation-detail.php?operation_id='+encodeURIComponent(op.id)+'&offset='+offset;
    const render=data=>{
      if(selected!==op.id || !$('operation-detail').open || !panel.isConnected)return false;
      const first=!output.dataset.loaded,bottom=output.scrollHeight-output.scrollTop-output.clientHeight<20,scroll=output.scrollTop,drawer=$('operation-detail'),drawerScroll=drawer.scrollTop;
      const text=shared?data.content:(data.entries||[]).map(e=>date(e.at)+' · '+phaseLabel(e.phase)+' · '+e.state+'\n'+[e.source,e.destination].filter(Boolean).join(' → ')+'\n'+e.message+(e.exitCode!==null&&e.exitCode!==undefined?' (exit '+e.exitCode+')':'')+(e.diagnostic?'\nZFS diagnostics:\n'+e.diagnostic:'')+(e.output?'\nWorker output:\n'+e.output:'')).join('\n\n');
      if(output.textContent!==text)output.textContent=text||'No log is available for this boot.';
      panel.dataset.state=text?'ready':'empty';output.dataset.loaded='1';output.scrollTop=first||bottom?output.scrollHeight:scroll;drawer.scrollTop=drawerScroll;
      panel.querySelector('.operation-log-notice').textContent=shared?'Latest shared category entries; these may not describe the selected job.':data.historyNotice;
      const pages=panel.querySelector('.operation-log-pages'),paging=JSON.stringify([shared,data.previousOffset,data.nextOffset]);
      if(pages.dataset.paging!==paging){pages.dataset.paging=paging;pages.replaceChildren();
      for(const [label,next] of shared?[]:[['Older entries',data.previousOffset],['Newer entries',data.nextOffset]]){
        if(next===null||next===undefined)continue;
        const button=document.createElement('button');button.type='button';button.textContent=label;button.onclick=()=>showDetailLog(op,false,next);pages.append(button);
      }
      }
      return shared?snapshot?.operations.some(item=>item.id===op.id&&active(item)):!terminal.has(data.state||snapshot?.operations.find(item=>item.id===op.id)?.state);
    };
    detailLogPoll=ZfsasRequests.poll('detail-log',()=>ZfsasRequests.request('detail-log',url),render,{stopWhenIdle:true,onError:error=>panelError(panel,error,()=>showDetailLog(op,shared,offset),!!output.dataset.loaded)});
  }
  function panelError(panel,error,retry,loaded=false) {
    panel.dataset.state=error.retryable===false?'unavailable':'error';
    const notice=panel.querySelector('.operation-log-notice, [data-panel-status]');
    notice.textContent=(loaded?'Loaded content is stale. ':'')+error.message;
    if(!loaded)panel.querySelector('pre')?.replaceChildren();
    panel.querySelector('[data-retry]')?.remove();
    if(error.retryable!==false){const button=document.createElement('button');button.type='button';button.dataset.retry='1';button.textContent='Retry loading';button.onclick=()=>{button.remove();retry();};notice.after(button);}
  }
  function showChecklist(op) {
    stagePoll?.stop();
    let panel=$('operation-checklist');
    if(!panel){panel=document.createElement('section');panel.id='operation-checklist';panel.innerHTML='<h3>Replication stages</h3><p data-panel-status role="status">Loading stages…</p><div data-stages></div>';$('operation-metadata').after(panel);}
    const url=base+'operation-detail.php?operation_id='+encodeURIComponent(op.id)+'&offset=0';
    const stateLabel=state=>({completed:'Completed',running:'Running',waiting:'Waiting',retry_scheduled:'Retry scheduled',failed:'Failed',canceled:'Canceled',not_reached:'Not reached',not_required:'Not required'}[state]||state);
    const icon=state=>({completed:'✓',failed:'!',running:'▶',canceled:'×',not_required:'—'}[state]||'○');
    async function datasets(stage,offset=0){
      if(document.hidden)return;stage.dataset.offset=String(offset);
      const mine=String(Number(stage.dataset.generation||0)+1);stage.dataset.generation=mine;
      const status=stage.querySelector('[data-panel-status]');status.textContent='Loading datasets…';
      try{
        const data=await ZfsasRequests.request('stage-'+stage.dataset.stage,url+'&stage='+stage.dataset.stage+'&stage_offset='+offset);
        if(document.hidden||!stage.open||selected!==op.id||!panel.isConnected||!$('operation-detail').open||stage.dataset.generation!==mine)return;
        const page=data.checklist?.page;if(!page)return;
        const rows=stage.querySelector('ul');rows.replaceChildren();
        for(const item of page.rows){const li=document.createElement('li');li.textContent=item.dataset+' · '+stateLabel(item.state)+' · '+item.attempts+' attempts'+(item.message?' — '+item.message:'');rows.append(li);}
        status.textContent=page.total?(offset+1)+'–'+(offset+page.rows.length)+' of '+page.total+' datasets':'No dataset work was recorded for this stage.';
        const pages=stage.querySelector('[data-pages]');const paging=JSON.stringify([page.previousOffset,page.nextOffset]);if(pages.dataset.paging===paging)return;pages.dataset.paging=paging;pages.replaceChildren();
        for(const [label,next] of [['Previous datasets',page.previousOffset],['Next datasets',page.nextOffset]])if(next!==null){const button=document.createElement('button');button.type='button';button.textContent=label;button.onclick=()=>datasets(stage,next);pages.append(button);}
      }catch(error){if(selected===op.id&&panel.isConnected&&stage.dataset.generation===mine)panelError(stage,error,()=>datasets(stage,offset),!!stage.querySelector('li'));}
    }
    stagePoll=ZfsasRequests.poll('operation-stages',()=>ZfsasRequests.request('operation-stages',url),data=>{
      if(selected!==op.id||!panel.isConnected||!$('operation-detail').open)return false;
      const checklist=data.checklist;panel.querySelector('[data-panel-status]').textContent=checklist?.available?'':checklist?.message||'Checklist unavailable for this older record. Recorded information remains in technical details and logs.';
      for(const item of checklist?.stages||[]){
        let stage=panel.querySelector('[data-stage="'+item.id+'"]');
        if(!stage){stage=document.createElement('details');stage.dataset.stage=item.id;stage.innerHTML='<summary></summary><p data-panel-status role="status"></p><ul class="source-review-list"></ul><div data-pages class="toolbar"></div>';panel.querySelector('[data-stages]').append(stage);stage.addEventListener('toggle',()=>{if(stage.open)datasets(stage);});stage.open=item.state==='failed';}
        const fingerprint=JSON.stringify(item);if(stage.open&&stage.dataset.fingerprint&&stage.dataset.fingerprint!==fingerprint)datasets(stage,Number(stage.dataset.offset||0));stage.dataset.fingerprint=fingerprint;
        stage.querySelector('summary').textContent=icon(item.state)+' '+item.label+' · '+stateLabel(item.state)+' · '+item.datasets+' datasets'+(Object.keys(item.counts).length>1?' ('+Object.entries(item.counts).map(([state,count])=>count+' '+stateLabel(state).toLowerCase()).join(', ')+')':'');
      }
      panel.dataset.loaded='1';return !terminal.has(data.state);
    },{stopWhenIdle:true,onError:error=>panelError(panel,error,()=>showChecklist(op),!!panel.dataset.loaded)});
  }
  $('operation-detail').addEventListener('close',()=>{stagePoll?.stop();stagePoll=null;detailLogPoll?.stop();detailLogPoll=null;});
  window.addEventListener('snapsync:recovery-started',()=>poll.refresh());
  const poll=ZfsasRequests.poll('workspace-summary',()=>ZfsasRequests.request('workspace-summary',base+'workspace-summary.php'),render);
  ['activity-type','activity-state'].forEach(id=>$(id)?.addEventListener('change',()=>{rows();const url=new URL(location.href);url.searchParams.set(id==='activity-type'?'type':'state',$(id).value);history.replaceState(null,'',url);}));
  if($('activity-type')) {const params=new URLSearchParams(location.search);$('activity-type').value=params.get('type')||'';$('activity-state').value=params.get('state')||'';}
  $('activity-refresh')?.addEventListener('click',()=>poll.refresh());
  const logs=$('activity-logs');
  function startLogs(){logPoll?.stop();logPoll=null;if(!logs?.open)return;logPoll=ZfsasRequests.poll('activity-log',()=>ZfsasRequests.request('activity-log',base+'workspace-log.php?type='+encodeURIComponent($('activity-log-type').value)),data=>{const node=$('activity-log-output'), bottom=node.scrollHeight-node.scrollTop-node.clientHeight<20;node.textContent=data.content||'No log is available for this boot.';if(bottom)node.scrollTop=node.scrollHeight;return snapshot?.operations.some(active);});}
  logs?.addEventListener('toggle',startLogs);$('activity-log-type')?.addEventListener('change',startLogs);$('activity-log-refresh')?.addEventListener('click',()=>logPoll?.refresh());
})();
