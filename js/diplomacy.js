(function(){
  const flags = window.ADMINMAP_FLAGS || {};
  if (!flags.DIPLOMACY_V1) return;

  const json = async (url, opts={}) => {
    const r = await fetch(url, Object.assign({headers:{'Content-Type':'application/json'}}, opts));
    const d = await r.json().catch(()=>({error:'invalid_json'}));
    if (!r.ok || d.error) throw new Error(d.error || ('http_'+r.status));
    return d;
  };

  const withHeaders = (headers={}) => ({'Content-Type':'application/json', ...headers});

  window.AdminmapDiplomacyApi = {
    threads(params={}, headers={}) { const q = new URLSearchParams(params).toString(); return json('/api/diplomacy/threads/' + (q ? ('?'+q) : ''), {headers: withHeaders(headers)}); },
    thread(thread_id, headers={}) { return json('/api/diplomacy/thread/?thread_id=' + encodeURIComponent(thread_id), {headers: withHeaders(headers)}); },
    send(payload, headers={}) { return json('/api/diplomacy/send/', {method:'POST', headers: withHeaders(headers), body:JSON.stringify(payload)}); },
    propose(payload, headers={}) { return json('/api/diplomacy/propose/', {method:'POST', headers: withHeaders(headers), body:JSON.stringify(payload)}); },
    ratify(payload, headers={}) { return json('/api/diplomacy/ratify/', {method:'POST', headers: withHeaders(headers), body:JSON.stringify(payload)}); },
    proposals(params={}, headers={}) { const q = new URLSearchParams(params).toString(); return json('/api/diplomacy/proposals/' + (q ? ('?'+q) : ''), {headers: withHeaders(headers)}); },
    treaties(params={}, headers={}) { const q = new URLSearchParams(params).toString(); return json('/api/diplomacy/treaties/' + (q ? ('?'+q) : ''), {headers: withHeaders(headers)}); },
    unread(headers={}) { return json('/api/diplomacy/unread/', {headers: withHeaders(headers)}); },
    arbitrate(payload, headers={}) { return json('/api/diplomacy/arbitrate/', {method:'POST', headers: withHeaders(headers), body:JSON.stringify(payload)}); },
  };
})();
