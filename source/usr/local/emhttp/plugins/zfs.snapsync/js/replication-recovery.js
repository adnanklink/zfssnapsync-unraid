(() => {
  'use strict';
  const base='/plugins/zfs.snapsync/php/';
  let dialog=null,poller=null,generation=0;
  function ensure(){
    if(dialog)return;
    dialog=document.createElement('dialog');dialog.id='replication-recovery';dialog.setAttribute('aria-labelledby','replication-recovery-title');
    dialog.innerHTML='<div class="ui-dialog-header"><h2 id="replication-recovery-title">Review interrupted replication</h2><button type="button" data-close-dialog>Close</button></div><p class="ui-notice">Recovery finishes reviewed original snapshots. It does not create fresh snapshots, authorize additional cleanup, or resume a paused schedule. Earlier history may be unavailable after reboot.</p><p class="recovery-status" role="status"></p><ul class="recovery-members source-review-list"></ul><div class="recovery-pages toolbar"></div><button type="button" class="recovery-approve" disabled>Retry reviewed work</button>';
    document.querySelector('.zfsas-workspace').append(dialog);
    dialog.addEventListener('close',()=>{++generation;poller?.stop();poller=null;});
  }
  async function open(runId='',scheduleId='',trigger=document.activeElement){
    ensure();++generation;const mine=generation;poller?.stop();
    const status=dialog.querySelector('.recovery-status'),rows=dialog.querySelector('.recovery-members'),pages=dialog.querySelector('.recovery-pages'),approve=dialog.querySelector('.recovery-approve');
    status.textContent='Inspecting receiver interruption state and original snapshots…';rows.replaceChildren();pages.replaceChildren();approve.disabled=true;approve.textContent='Retry reviewed work';approve.onclick=null;
    if(!dialog.open)ZfsasUI.open(dialog,trigger);
    try{
      const started=await ZfsasRequests.request('recovery-start',base+'coordinator-action.php',{action:'review_recovery',run_id:runId,schedule_id:scheduleId,command_id:'recovery-'+Array.from(crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join('')});
      if(mine!==generation)return;
      if(started.blocked)throw new Error('Another run currently owns this configuration. Wait for it to stop, then review again.');
      const url=base+'replication-recovery-status.php?review_id='+encodeURIComponent(started.runId);
      function render(data){
        if(mine!==generation||!dialog.open)return false;
        if(!['complete','failed','canceled'].includes(data.state)){status.textContent='Inspecting original snapshots… '+(data.eligible+data.blocked)+' datasets checked.';return true;}
        poller?.stop();
        if(data.state!=='complete'){status.textContent=data.problem?.summary || 'Review did not complete. Close this panel and review again.';return false;}
        status.textContent=data.eligible+' eligible; '+data.blocked+' unavailable. '+(data.expired?'Review expired; close and review again.':'Review expires in five minutes.');
        rows.replaceChildren();pages.replaceChildren();
        for(const item of data.rows){const li=document.createElement('li');li.textContent=item.source+' → '+item.destination+'\n'+(item.snapshot?item.snapshot+'\n':'')+item.message;rows.append(li);}
        if(data.nextOffset!==null){const next=document.createElement('button');next.type='button';next.textContent='Next datasets';next.onclick=async()=>{try{render(await ZfsasRequests.request('recovery-page',url+'&offset='+data.nextOffset));}catch(e){if(mine===generation)status.textContent=e.message;}};pages.append(next);}
        const first=document.createElement('button');first.type='button';first.textContent='First datasets';first.onclick=async()=>{try{render(await ZfsasRequests.request('recovery-page',url));}catch(e){if(mine===generation)status.textContent=e.message;}};pages.append(first);
        approve.disabled=data.expired||data.eligible===0;approve.textContent='Retry '+data.eligible+' reviewed datasets';
        approve.onclick=async()=>{
          if(Date.now()>=data.expiresAt*1000){approve.disabled=true;status.textContent='Review expired. Close this panel and review again.';return;}
          approve.disabled=true;
          try{
            const result=await ZfsasRequests.request('recovery-execute',base+'coordinator-action.php',{action:'retry_reviewed',review_id:started.runId});
            if(mine!==generation)return;
            if(result.blocked){status.textContent='Another run owns this configuration. Close and review again after it stops.';return;}
            status.textContent='Reviewed recovery queued. Recovery does not request a fresh run; the normal schedule is unchanged.';
            const link=document.createElement('a');link.href=ZfsasUI.workflowUrl('/Settings/ZFSSnapSync?section=activity');link.textContent='Follow recovery in Activity →';pages.replaceChildren(link);
            window.dispatchEvent(new Event('snapsync:recovery-started'));
          }catch(e){if(mine===generation){status.textContent=e.message;approve.disabled=false;}}
        };
        return false;
      }
      poller=ZfsasRequests.poll('recovery-status',()=>ZfsasRequests.request('recovery-status',url),render);
    }catch(e){if(mine===generation)status.textContent=e.message;}
  }
  window.ZfsasRecovery={open};
})();
