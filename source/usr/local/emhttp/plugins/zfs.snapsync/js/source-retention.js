(() => {
  'use strict';
  const form=document.getElementById('zfsas_send_form');
  if(!form)return;
  const endpoint='/plugins/zfs.snapsync/php/source-retention-preview.php';
  function attach(row) {
    if(row.querySelector('[name^="job_source_keep["]'))return;
    const index=row.querySelector('[name^="job_id["]')?.name.match(/\[(\d+)\]/)?.[1];if(index===undefined)return;
    const td=document.createElement('td');td.className='source-retention-control';
    td.innerHTML='<select aria-label="Source snapshots"><option value="all">Keep all</option><option value="count">Keep latest</option></select> <input type="number" min="1" max="1000" aria-label="Source checkpoint count" value="3">'+
      '<input type="hidden" name="job_source_keep['+index+']"><input type="hidden" name="job_source_review['+index+']" value="">'+
      '<p class="zfsas-send-help">Local jobs only. Cleanup follows fully verified replication. Required bases, recovery references, holds and clones remain protected beyond this count. Older failed checkpoints may be removed once superseded.</p>'+
      '<button type="button" class="source-review-button">Review source snapshots</button><div class="source-review-status" role="status" aria-live="polite"></div><div class="source-review-results"></div>';
    row.lastElementChild.before(td);
    const mode=td.querySelector('select'),count=td.querySelector('input[type=number]'),keep=td.querySelector('[name^=job_source_keep]'),token=td.querySelector('[name^=job_source_review]');
    const button=td.querySelector('button'),status=td.querySelector('.source-review-status'),results=td.querySelector('.source-review-results');
    const initial=Number(row.dataset.sourceKeep ?? 3);mode.value=initial?'count':'all';count.value=initial||3;
    let generation=0,poller=null;
    const value=name=>row.querySelector('[name^="job_'+name+'["]')?.value||'';
    const signature=()=>JSON.stringify(['id','source','destination','children','transport'].map(value).concat(keep.value,form.querySelector('[name=config_revision]')?.value));
    function sync() { const local=value('transport')==='local';mode.disabled=!local;count.hidden=mode.value!=='count'||!local;count.disabled=count.hidden;keep.value=local&&mode.value==='count'?count.value:'0';button.disabled=keep.value==='0'; }
    function invalidate() { ++generation;poller?.stop();poller=null;token.value='';results.replaceChildren();status.textContent='';sync(); }
    mode.addEventListener('change',invalidate);count.addEventListener('input',invalidate);
    row.addEventListener('change',event=>{if(event.target!==mode&&event.target!==count&&/job_(source|destination|children|transport)\[/.test(event.target.name||''))invalidate();});
    button.addEventListener('click',async()=>{
      if(!count.reportValidity())return;invalidate();const mine=generation,sig=signature();button.disabled=true;status.textContent='Inspecting source snapshots and receiver protections…';
      try {
        const body={keep:keep.value,config_revision:form.querySelector('[name=config_revision]')?.value||''};
        for(const key of ['id','source','destination','children','transport','frequency','threshold'])body['job_'+key+'[0]']=value(key);
        const started=await ZfsasRequests.request('source-review-start-'+index,endpoint,body,15000);
        if(mine!==generation||signature()!==sig)return;
        const url=endpoint+'?token='+encodeURIComponent(started.token)+'&run_id='+encodeURIComponent(started.runId);
        const deadline=Date.now()+120000;
        function render(data) {
          if(mine!==generation||signature()!==sig){poller?.stop();return false;}
          if(data.state!=='ready')return true;
          poller?.stop();button.disabled=false;
          status.textContent=data.eligible+' currently eligible; '+data.protected+' protected; '+data.unmanaged+' unmanaged snapshots left untouched. Review expires in five minutes. Saving authorizes ongoing cleanup after future successful runs; it does not delete now.';
          results.replaceChildren();const list=document.createElement('ul');list.className='source-review-list';
          for(const item of data.rows){const li=document.createElement('li');li.textContent=item.snapshot+' — '+item.reason;list.append(li);}results.append(list);
          const pager=document.createElement('p');pager.textContent=data.total?'Showing '+(data.offset+1)+'–'+Math.min(data.offset+50,data.total)+' of '+data.total+'. ':'No existing managed snapshots. ';
          for(const [label,offset] of [['Previous',data.offset-50],['Next',data.offset+50]]) {
            if(offset<0||offset>=data.total)continue;
            const next=document.createElement('button');next.type='button';next.textContent=label;next.addEventListener('click',async()=>{try{render(await ZfsasRequests.request('source-review-page-'+index,url+'&offset='+offset));}catch(error){status.textContent=error.message;}});pager.append(next);
          }results.append(pager);
          const accept=document.createElement('button');accept.type='button';accept.textContent='Use this retention policy';accept.addEventListener('click',()=>{
            if(mine!==generation||signature()!==sig||Date.now()>=data.expires*1000){invalidate();status.textContent='Review expired or settings changed. Review again.';return;}
            token.value=started.token;status.textContent='Reviewed. Save replication within five minutes to apply this policy.';results.replaceChildren();form.dispatchEvent(new Event('change',{bubbles:true}));
          });results.append(accept);return false;
        }
        poller=ZfsasRequests.poll('source-review-'+index,async()=>{
          if(Date.now()>deadline){poller?.stop();button.disabled=false;status.textContent='Source review timed out. Try reviewing again.';return {state:'stopped'};}
          try{return await ZfsasRequests.request('source-review-'+index,url);}
          catch(error){poller?.stop();button.disabled=false;status.textContent=error.message;throw error;}
        },data=>data.state==='stopped'?false:render(data));
      }catch(error){if(mine===generation){button.disabled=false;status.textContent=error.message;}}
    });
    form.addEventListener('change',event=>{if(event.target===form)sync();});
    form.addEventListener('zfsas:saved',event=>{if(event.detail.saved){token.value='';++generation;poller?.stop();status.textContent='';results.replaceChildren();}});
    sync();
  }
  const body=document.getElementById('zfsas_send_jobs_body');
  [...body.rows].forEach(attach);
  new MutationObserver(()=>[...body.rows].forEach(attach)).observe(body,{childList:true});
})();
