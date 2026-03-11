(function(){
  const api = window.AdminmapDiplomacyApi;
  if (!api) return;
  const qs = new URLSearchParams(location.search);
  const playerToken = localStorage.getItem('player_admin_token') || qs.get('token') || '';
  const adminToken = localStorage.getItem('admin_token') || '';
  const H = adminToken ? {'X-Admin-Token': adminToken} : (playerToken ? {'X-Player-Admin-Token': playerToken} : {});
  const q=s=>document.querySelector(s);
  let currentThread='';
  let currentProposal='';
  let currentTreaty='';

  async function loadSelectors(){
    const orderSel=q('#orderSelect'); const verdictSel=q('#verdictSelect');
    orderSel.innerHTML='<option value="">— приказ —</option>'; verdictSel.innerHTML='<option value="">— вердикт —</option>';
    let orders=[];
    try {
      if (adminToken) { const d=await fetch('/api/orders/admin-queue/index.php',{headers:{'X-Admin-Token':adminToken}}).then(r=>r.json()); orders=d.items||[]; }
      else if (playerToken) { const d=await fetch('/api/orders/my/index.php',{headers:{'X-Player-Admin-Token':playerToken}}).then(r=>r.json()); orders=d.items||[]; }
    } catch(_e) {}
    for (const o of orders){
      const oid=String(o.id||'');
      if(oid){ const op=document.createElement('option'); op.value=oid; op.textContent=`${oid} · ${o.title||''}`; orderSel.appendChild(op); }
    }
    try {
      const vc=await fetch('/api/diplomacy/verdict_catalog/?per_page=120',{headers:H}).then(r=>r.json());
      (vc.items||[]).forEach(v=>{
        const vid=String(v.verdict_id||'');
        if(!vid) return;
        const vp=document.createElement('option');
        vp.value=vid;
        vp.textContent=`${vid} · ${v.order_title||v.order_id||''} · ${v.order_status||''}`;
        verdictSel.appendChild(vp);
      });
    } catch(_e) {
      const seenVerd=new Set();
      for (const o of orders){
        const vid=String((((o||{}).verdict||{}).id)||'');
        if(vid && !seenVerd.has(vid)){ seenVerd.add(vid); const vp=document.createElement('option'); vp.value=vid; vp.textContent=`${vid} · ${o.title||''}`; verdictSel.appendChild(vp); }
      }
    }
  }

  async function loadThreads(){
    const participant=q('#fParticipant').value.trim();
    const status=q('#fStatus').value;
    const d=await api.threads({per_page:100, participant, status}, H);
    const box=q('#threads'); box.innerHTML='';
    (d.threads||[]).forEach(t=>{
      const div=document.createElement('div'); div.className='item';
      div.innerHTML=`<b>#${t.id}</b> ${t.title||''}<div class='muted'>${t.kind||''} · ${t.status||''}</div>`;
      div.onclick=()=>openThread(t.id);
      box.appendChild(div);
    });
  }

  async function openThread(id){
    currentThread=id;
    const d=await api.thread(id,H);
    q('#threadTitle').textContent=`Thread #${id}`;
    q('#threadMeta').textContent=`${(d.thread||{}).status||''} · ${(d.thread||{}).kind||''}`;
    const m=q('#messages'); m.innerHTML='';
    (d.messages||[]).forEach(x=>{ const div=document.createElement('div'); div.className='item'; div.innerHTML=`<div>${x.body||''}</div><div class='muted'>${x.message_type||''} · ${x.created_at||''}</div>`; m.appendChild(div); });
    const p=q('#proposals'); p.innerHTML='';
    (d.proposals||[]).forEach(x=>{ const div=document.createElement('div'); div.className='item'; div.innerHTML=`<b>#${x.id}</b> ${x.title||''}<div class='muted'>${x.status||''}</div>`; div.onclick=()=>{currentProposal=x.id;}; p.appendChild(div); });
  }

  q('#refreshThreads').onclick=loadThreads;
  q('#sendMsg').onclick=async()=>{
    if(!currentThread) return;
    await api.send({thread_id:currentThread, body:q('#msgBody').value.trim(), linked_order_id:q('#msgOrder').value.trim(), linked_verdict_id:q('#msgVerdict').value.trim()}, H);
    await openThread(currentThread);
  };
  q('#createProposal').onclick=async()=>{
    if(!currentThread) return;
    const d=await api.propose({thread_id:currentThread, proposal_type:q('#prType').value, title:q('#prTitle').value.trim(), summary:q('#prSummary').value.trim(), linked_order_id:q('#orderId').value.trim(), linked_verdict_id:q('#verdictId').value.trim()}, H);
    currentProposal=(d.proposal||{}).id||'';
    await openThread(currentThread);
  };
  q('#orderSelect').onchange=e=>{ q('#orderId').value=e.target.value||''; };
  q('#verdictSelect').onchange=e=>{ q('#verdictId').value=e.target.value||''; };
  q('#linkOrder').onclick=async()=>{ if(!currentThread) return; await api.send({thread_id:currentThread, body:`Связан приказ ${q('#orderId').value.trim()}`, linked_order_id:q('#orderId').value.trim(), message_type:'system'}, H); await openThread(currentThread); };
  q('#linkVerdict').onclick=async()=>{ if(!currentThread) return; await api.send({thread_id:currentThread, body:`Связан вердикт ${q('#verdictId').value.trim()}`, linked_verdict_id:q('#verdictId').value.trim(), message_type:'system'}, H); await openThread(currentThread); };
  q('#arbDo').onclick=async()=>{
    if(!adminToken) return alert('Нужен admin_token');
    const payload={action:q('#arbAction').value};
    if(currentProposal) payload.proposal_id=currentProposal;
    else if(currentTreaty) payload.treaty_id=currentTreaty;
    else if(currentThread) payload.thread_id=currentThread;
    if(q('#verdictId').value.trim()) payload.linked_verdict_id=q('#verdictId').value.trim();
    const d=await api.arbitrate(payload, {'X-Admin-Token':adminToken});
    q('#status').textContent='Арбитраж применён: '+JSON.stringify(d);
    if(currentThread) await openThread(currentThread);
  };

  loadSelectors();
  loadThreads();
})();
