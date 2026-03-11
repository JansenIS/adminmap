(function(){
  const api = window.AdminmapDiplomacyApi;
  if (!api) return;

  const isPublicPage = /index(_ui_alt)?\.html$/i.test(location.pathname) || location.pathname === '/' || location.pathname === '/index.html';
  const playerToken = localStorage.getItem('player_admin_token') || new URLSearchParams(location.search).get('token') || '';
  const adminToken = localStorage.getItem('admin_token') || '';

  function hdrs(isAdmin=false){
    if (isAdmin && adminToken) return {'X-Admin-Token': adminToken};
    if (playerToken) return {'X-Player-Admin-Token': playerToken};
    return {};
  }

  function saveOrderContext(ctx){
    localStorage.setItem('adminmap_order_diplomacy_context', JSON.stringify(Object.assign({saved_at: Date.now()}, ctx||{})));
  }

  async function renderPublicDiploFeed(){
    const host = document.querySelector('.sidebar') || document.querySelector('.inspector') || document.body;
    if (!host) return;
    const card = document.createElement('section');
    card.className = 'card diplo-public-card';
    card.innerHTML = '<h3>Публичная дипломатия</h3><div id="diploPublicFeed">Загрузка…</div>';
    host.appendChild(card);
    const box = card.querySelector('#diploPublicFeed');
    try {
      const feed = await fetch('/api/diplomacy/public_feed/?limit=8').then(r=>r.json());
      const threads = feed.threads||[];
      const treaties = feed.treaties||[];
      const communiques = feed.communiques||[];
      const tHtml = threads.map(t => `<div class="diplo-feed-item"><b>${t.title||'Дипломатическая ветка'}</b><div class="diplo-feed-meta">${t.kind||'custom'} · ${t.status||'active'} · ${t.latest_message_at||''}</div></div>`).join('');
      const trHtml = treaties.map(t => `<div class="diplo-feed-item"><b>${t.title||'Договор'}</b><div class="diplo-feed-meta">${t.treaty_type||'custom'} · ${t.status||''}</div></div>`).join('');
      const cHtml = communiques.map(m => `<div class="diplo-feed-item"><b>${m.title||'Коммюнике'}</b><div>${m.short_preview||''}</div><div class="diplo-feed-meta">${m.created_at||''}</div></div>`).join('');
      box.innerHTML = `<div class="diplo-feed-group"><div class="diplo-feed-title">Публичные соглашения/переговоры</div>${tHtml||'<div class="muted">Нет публичных дипломатических веток.</div>'}</div>
      <div class="diplo-feed-group"><div class="diplo-feed-title">Публичные договоры</div>${trHtml||'<div class="muted">Нет публичных договоров.</div>'}</div>
      <div class="diplo-feed-group"><div class="diplo-feed-title">Коммюнике</div>${cHtml||'<div class="muted">Нет коммюнике.</div>'}</div>`;
    } catch(e){ box.textContent = 'Не удалось загрузить публичную дипломатию: ' + e.message; }
  }

  const modal = document.createElement('div');
  modal.className = 'diplo-modal';
  modal.innerHTML = `<div class="diplo-window">
    <div class="diplo-row"><b>Дипломатия v1</b><span id="diploUnread" class="diplo-pill">unread:0</span><button id="diploOpenConsole">Открыть консоль</button><button id="diploClose">Закрыть</button></div>
    <div class="diplo-row">
      <div style="flex:1;min-width:280px">
        <div class="diplo-row"><input id="diploTarget" placeholder="получатель (id, type:id, name)" style="flex:1"><input id="diploTitle" placeholder="Тема" style="flex:1"></div>
        <div class="diplo-row"><textarea id="diploBody" placeholder="Сообщение" style="width:100%;min-height:84px"></textarea></div>
        <div class="diplo-row"><button id="diploCreate">Отправить / создать тред</button></div>
      </div>
      <div style="flex:1;min-width:280px">
        <div class="diplo-row"><input id="diploProposalTitle" placeholder="Заголовок предложения" style="flex:1"><select id="diploProposalType"><option>alliance</option><option>trade</option><option>nap</option><option>peace</option><option>truce</option><option>ultimatum</option><option>custom</option></select></div>
        <div class="diplo-row"><textarea id="diploProposalSummary" placeholder="Краткое описание proposal" style="width:100%;min-height:84px"></textarea></div>
        <div class="diplo-row"><button id="diploPropose">Создать proposal в выбранном треде</button></div>
      </div>
    </div>
    <div class="diplo-row">
      <div class="diplo-col" style="flex:1"><div><b>Треды</b></div><div id="diploThreads"></div></div>
      <div class="diplo-col" style="flex:1"><div><b>Предложения</b></div><div id="diploProposals"></div></div>
      <div class="diplo-col" style="flex:1"><div><b>Договоры</b></div><div id="diploTreaties"></div></div>
    </div>
    <div class="diplo-row">
      <div class="diplo-col" style="flex:1">
        <div><b>Приказы / Вердикты / Арбитраж</b></div>
        <div class="diplo-row"><select id="diploOrderSelect"><option value="">— выбрать приказ —</option></select><input id="diploOrderId" placeholder="order_id"></div>
        <div class="diplo-row"><select id="diploVerdictSelect"><option value="">— выбрать вердикт —</option></select><input id="diploVerdictId" placeholder="verdict_id"></div>
        <div class="diplo-row">
          <button id="diploAttachOrder">Прикрепить к сообщению</button>
          <button id="diploAttachVerdict">Привязать вердикт</button>
          <button id="diploCreateOrder">Создать приказ на основе треда</button>
        </div>
        <div class="diplo-row"><select id="diploArbAction"><option value="approve">approve</option><option value="reject">reject</option><option value="partially_approve">partially_approve</option><option value="mark_violated">mark_violated</option><option value="terminate">terminate</option><option value="force_activate">force_activate</option><option value="close_thread">close_thread</option></select><button id="diploArbitrate">Админ-арбитраж</button></div>
      </div>
    </div>
  </div>`;
  document.body.appendChild(modal);

  const launch = document.createElement('button');
  launch.className = 'diplo-launcher';
  launch.textContent = 'Дипломатия';
  document.body.appendChild(launch);

  let selectedThreadId = '';
  let selectedProposalId = '';
  let selectedTreatyId = '';
  const byId = (id)=>modal.querySelector('#'+id);

  function consumeDiploFocus(){
    let raw='';
    try { raw=localStorage.getItem('adminmap_diplomacy_focus')||''; } catch(_e) { raw=''; }
    if(!raw) return null;
    localStorage.removeItem('adminmap_diplomacy_focus');
    try { return JSON.parse(raw); } catch(_e){ return null; }
  }

  async function loadOrderVerdictSelectors(){
    const orderSel = byId('diploOrderSelect');
    const verdictSel = byId('diploVerdictSelect');
    if (!orderSel || !verdictSel) return;
    orderSel.innerHTML = '<option value="">— выбрать приказ —</option>';
    verdictSel.innerHTML = '<option value="">— выбрать вердикт —</option>';
    try {
      let orders = [];
      if (adminToken) {
        const resp = await fetch('/api/orders/admin-queue/index.php', {headers: {'X-Admin-Token': adminToken}}).then(r=>r.json());
        orders = Array.isArray(resp.items) ? resp.items : [];
      } else if (playerToken) {
        const resp = await fetch('/api/orders/my/index.php', {headers: {'X-Player-Admin-Token': playerToken}}).then(r=>r.json());
        orders = Array.isArray(resp.items) ? resp.items : [];
      }
      for (const o of orders) {
        const oid = String(o.id||'').trim();
        if (oid) {
          const opt = document.createElement('option');
          opt.value = oid;
          opt.textContent = `${oid} · ${o.title||'Без названия'} · ${o.status||''}`;
          orderSel.appendChild(opt);
        }
      }
      try {
        const vc = await fetch('/api/diplomacy/verdict_catalog/?per_page=80', {headers: hdrs(!!adminToken)}).then(r=>r.json());
        (vc.items||[]).forEach(v=>{
          const vid=String(v.verdict_id||'').trim(); if(!vid) return;
          const opt=document.createElement('option');
          opt.value=vid;
          opt.textContent=`${vid} · ${v.order_title||v.order_id||''} · ${v.order_status||''}`;
          verdictSel.appendChild(opt);
        });
      } catch(_e) {
        // fallback from order snapshot only
        const verdictSet = new Map();
        for (const o of orders) {
          const vid = String((((o||{}).verdict||{}).id)||'').trim();
          if (vid && !verdictSet.has(vid)) verdictSet.set(vid, o);
        }
        for (const [vid, o] of verdictSet.entries()) {
          const opt = document.createElement('option');
          opt.value = vid;
          opt.textContent = `${vid} · ${o.title||'Приказ'}`;
          verdictSel.appendChild(opt);
        }
      }
    } catch(_e) {}
  }

  async function refresh(){
    try {
      const [threads, proposals, treaties, unread] = await Promise.all([
        api.threads({per_page:20}, hdrs()),
        api.proposals({per_page:20}, hdrs()),
        api.treaties({per_page:20}, hdrs()),
        api.unread(hdrs())
      ]);
      byId('diploUnread').textContent = `unread:${unread.unread_messages||0}`;
      byId('diploThreads').innerHTML = (threads.threads||[]).map(t=>`<div><button data-th="${t.id}">#${t.id}</button> ${t.title||'—'} <span class="diplo-pill">${t.status||'open'}</span></div>`).join('') || '<div>Нет тредов</div>';
      byId('diploProposals').innerHTML = (proposals.proposals||[]).map(p=>`<div><button data-pr="${p.id}">#${p.id}</button> ${p.title||''} <span class="diplo-pill">${p.status||''}</span> <button data-acc="${p.id}">✓</button> <button data-rej="${p.id}">✕</button></div>`).join('') || '<div>Нет предложений</div>';
      byId('diploTreaties').innerHTML = (treaties.treaties||[]).map(t=>`<div><button data-tr="${t.id}">#${t.id}</button> ${t.title||''} <span class="diplo-pill">${t.status||''}</span></div>`).join('') || '<div>Нет договоров</div>';
      await loadOrderVerdictSelectors();
    } catch(e) { byId('diploThreads').textContent = 'Ошибка: ' + e.message; }
  }

  launch.addEventListener('click', async ()=>{ modal.classList.add('open'); const focus=consumeDiploFocus(); if(focus && focus.thread_id) selectedThreadId=String(focus.thread_id); await refresh(); });
  byId('diploClose').addEventListener('click', ()=>modal.classList.remove('open'));
  byId('diploOpenConsole').addEventListener('click', ()=>window.open('/diplomacy_console.html', '_blank'));
  byId('diploOrderSelect').addEventListener('change', (e)=>{ byId('diploOrderId').value = e.target.value || ''; });
  byId('diploVerdictSelect').addEventListener('change', (e)=>{ byId('diploVerdictId').value = e.target.value || ''; });

  modal.addEventListener('click', async (e)=>{
    const th = e.target.getAttribute('data-th');
    const pr = e.target.getAttribute('data-pr');
    const tr = e.target.getAttribute('data-tr');
    const acc = e.target.getAttribute('data-acc');
    const rej = e.target.getAttribute('data-rej');
    if (th) { selectedThreadId = th; const r = await api.thread(th, hdrs()); byId('diploBody').value = ((r.messages||[]).slice(-1)[0]||{}).body || ''; }
    if (pr) selectedProposalId = pr;
    if (tr) selectedTreatyId = tr;
    if (acc) { await api.ratify({proposal_id:acc, action:'accept'}, hdrs()); refresh(); }
    if (rej) { await api.ratify({proposal_id:rej, action:'reject'}, hdrs()); refresh(); }
  });

  byId('diploCreate').addEventListener('click', async ()=>{
    const target = byId('diploTarget').value.trim();
    const title = byId('diploTitle').value.trim();
    const body = byId('diploBody').value.trim();
    if (!body) return;
    const payload = {thread_id:selectedThreadId, title, body};
    if (!selectedThreadId) payload.target_entity = target;
    const r = await api.send(payload, hdrs());
    selectedThreadId = r.thread.id;
    refresh();
  });

  byId('diploPropose').addEventListener('click', async ()=>{
    if (!selectedThreadId) return;
    const r = await api.propose({thread_id:selectedThreadId, proposal_type:byId('diploProposalType').value, title:byId('diploProposalTitle').value.trim(), summary:byId('diploProposalSummary').value.trim()}, hdrs());
    selectedProposalId = (r.proposal||{}).id || selectedProposalId;
    refresh();
  });

  byId('diploAttachOrder').addEventListener('click', async ()=>{
    if (!selectedThreadId) return alert('Сначала выберите тред.');
    const orderId = byId('diploOrderId').value.trim();
    if (!orderId) return alert('Укажите order_id.');
    await api.send({thread_id:selectedThreadId, body:`Связан приказ ${orderId}`, message_type:'system', linked_order_id:orderId, tags:['order_link']}, hdrs());
    refresh();
  });

  byId('diploAttachVerdict').addEventListener('click', async ()=>{
    if (!selectedThreadId) return alert('Сначала выберите тред.');
    const verdictId = byId('diploVerdictId').value.trim();
    if (!verdictId) return alert('Укажите verdict_id.');
    await api.send({thread_id:selectedThreadId, body:`Связан вердикт ${verdictId}`, message_type:'system', linked_verdict_id:verdictId, tags:['verdict_link']}, hdrs());
    refresh();
  });

  byId('diploCreateOrder').addEventListener('click', ()=>{
    if (!selectedThreadId) return alert('Сначала выберите тред.');
    saveOrderContext({thread_id:selectedThreadId, proposal_id:selectedProposalId, treaty_id:selectedTreatyId, title_hint:byId('diploTitle').value.trim(), body_hint:byId('diploBody').value.trim()});
    const token = playerToken ? ('?token=' + encodeURIComponent(playerToken)) : '';
    window.open('/player_orders_ui_alt.html' + token, '_blank');
  });

  byId('diploArbitrate').addEventListener('click', async ()=>{
    if (!adminToken) return alert('Нужен admin_token в localStorage.');
    const action = byId('diploArbAction').value;
    const payload = {action};
    if (selectedProposalId) payload.proposal_id = selectedProposalId;
    else if (selectedTreatyId) payload.treaty_id = selectedTreatyId;
    else if (selectedThreadId) payload.thread_id = selectedThreadId;
    else return alert('Выберите thread/proposal/treaty.');
    const verdictId = byId('diploVerdictId').value.trim();
    if (verdictId) payload.linked_verdict_id = verdictId;
    await api.arbitrate(payload, hdrs(true));
    refresh();
  });

  window.addEventListener('adminmap:diplomacy-open', async (e)=>{
    const detail=(e&&e.detail)||{};
    if(detail && detail.thread_id) selectedThreadId=String(detail.thread_id);
    modal.classList.add('open');
    await refresh();
  });

  if (isPublicPage) renderPublicDiploFeed();
})();
