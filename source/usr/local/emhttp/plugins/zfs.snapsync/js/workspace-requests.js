(() => {
  'use strict';
  const inflight=new Map();
  async function request(key, url, body=null, timeout=8000) {
    inflight.get(key)?.abort(); const controller=new AbortController(); inflight.set(key,controller);
    let timedOut=false;const timer=setTimeout(()=>{timedOut=true;controller.abort();},timeout);
    try {
      const csrf=document.querySelector('.zfsas-workspace')?.dataset.csrf || window.csrf_token || '';
      const response=await fetch(url,{cache:'no-store',signal:controller.signal,...(body?{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-Token':csrf},body:new URLSearchParams({...body,csrf_token:csrf})}:{})});
      const text=await response.text(), match=text.match(/ZFSAS_JSON_BEGIN\s*([\s\S]*?)\s*ZFSAS_JSON_END/);
      const data=JSON.parse(match?match[1]:text);
      if(!response.ok || !data.ok) throw Object.assign(new Error(data.error || data.errors?.join(' ') || 'Request failed.'),{data,code:data.code,retryable:data.retryable!==false});
      if(inflight.get(key)!==controller) throw new DOMException('Superseded','AbortError');
      return data;
    } catch(error) { if(timedOut)throw Object.assign(new Error('Request timed out. Retry loading.'),{retryable:true});throw error; } finally { clearTimeout(timer); if(inflight.get(key)===controller) inflight.delete(key); }
  }
  function poll(key, load, render, options={}) {
    let timer=null, generation=0, stopped=false;
    async function tick() {
      clearTimeout(timer); if(document.hidden || stopped) return;
      const mine=++generation;let active=false;
      try { const data=await load(); if(mine!==generation || stopped) return; active=!!render(data);if(options.stopWhenIdle&&!active)stop(); }
      catch(error) { if(mine===generation && error.name!=='AbortError') {if(options.onError){options.onError(error);stop();}else window.ZfsasUI?.notice(error.message,true);} }
      finally { if(mine===generation && !document.hidden && !stopped) timer=setTimeout(tick,active?2000:10000); }
    }
    const visibility=()=>{ ++generation;clearTimeout(timer);inflight.get(key)?.abort();if(!document.hidden) tick(); };
    document.addEventListener('visibilitychange',visibility);tick();
    function stop(){stopped=true;++generation;clearTimeout(timer);inflight.get(key)?.abort();document.removeEventListener('visibilitychange',visibility);}
    return {refresh:tick,stop};
  }
  window.ZfsasRequests={request,poll};
})();
