(function(){
  const el = (id)=>document.getElementById(id);
  const modulesEl = el('modules');
  const logEl = el('log');
  let modules = [];
  let pipelines = {};
  let activeTag = '';

  function log(msg, data){
    const line = `[${new Date().toLocaleTimeString()}] ${msg}`;
    logEl.textContent = `${line}\n${logEl.textContent}`;
    if (data) logEl.textContent = `${JSON.stringify(data, null, 2)}\n${logEl.textContent}`;
  }

  async function api(url, opts){
    const r = await fetch(url, opts);
    const ct = r.headers.get('content-type') || '';
    if (!ct.includes('application/json')) return {ok:false,error:'non-json response'};
    return r.json();
  }

  function renderTags(){
    const tags = new Set();
    modules.forEach(m => (m.tags || []).forEach(t => tags.add(t)));
    const box = el('tagFilters');
    box.innerHTML = '';
    [...tags].sort().forEach(tag => {
      const b = document.createElement('button');
      b.type = 'button'; b.textContent = tag;
      b.className = activeTag === tag ? 'active' : '';
      b.onclick = ()=>{ activeTag = activeTag===tag ? '' : tag; renderTags(); renderModules(); };
      box.appendChild(b);
    });
  }

  function badge(label, cls=''){ return `<span class="badge ${cls}">${label}</span>`; }

  function supportsAction(m, key){ return !!(m.supports && m.supports[key]); }

  function renderModules(){
    const q = (el('search').value || '').toLowerCase().trim();
    const list = modules.filter(m => {
      if (activeTag && !(m.tags || []).includes(activeTag)) return false;
      if (!q) return true;
      const hay = `${m.id} ${m.name} ${(m.tags||[]).join(' ')}`.toLowerCase();
      return hay.includes(q);
    });
    modulesEl.innerHTML = '';
    list.forEach(m => {
      const card = document.createElement('article');
      card.className = 'module-card';
      const outputs = (m.outputs || []).map(o => `<a class="btn" href="/api/sidecar/download/?module_id=${encodeURIComponent(m.id)}&file=${encodeURIComponent(o)}">${o}</a>`).join(' ');
      card.innerHTML = `
        <div class="row"><strong>${m.name}</strong> ${badge(m.id)} ${badge(m.version || '0.0.0')}</div>
        <div class="row">
          ${m.invalid ? badge('invalid', 'err') : badge('installed', 'ok')}
          ${(m.status && m.status.runtime_present) ? badge('runtime present', 'ok') : badge('runtime missing', 'err')}
          ${badge(`${((m.status && m.status.runtime_size) || 0)} bytes`)}
        </div>
        <div class="row">${(m.tags || []).map(t => badge(t)).join(' ')}</div>
        <div class="row actions">
          <a class="btn" href="${m.entry_ui_alt || 'sidecar_admin.html'}">Открыть UI entry</a>
          <a class="btn" href="${m.entry_admin || '#'}" target="_blank" rel="noopener">Открыть admin</a>
          <button data-action="dry" data-id="${m.id}" ${supportsAction(m,'dry_run')?'':'disabled'}>Dry-run</button>
          <button data-action="run" data-id="${m.id}">Run</button>
          <button data-action="attach" data-id="${m.id}" ${supportsAction(m,'attach_to_turn')?'':'disabled'}>Run + attach</button>
          <button data-action="cleanup" data-id="${m.id}" ${supportsAction(m,'cleanup')?'':'disabled'}>Очистить runtime</button>
          <button data-action="refresh" data-id="${m.id}">Обновить</button>
        </div>
        <div class="row">${outputs || '<span class="muted">Нет outputs</span>'}</div>
      `;
      modulesEl.appendChild(card);
    });
  }

  async function loadModules(){
    try {
      const body = await api('/api/sidecar/modules/');
      modules = Array.isArray(body.modules) ? body.modules : [];
      renderTags(); renderModules();
      log('Модули загружены', {count: modules.length});
    } catch (e) {
      log('Ошибка загрузки модулей', {error: String(e)});
    }
  }

  async function loadPipelines(){
    const body = await api('/api/sidecar/pipelines/');
    pipelines = body.pipelines || {};
    const box = el('pipelines'); box.innerHTML = '';
    Object.entries(pipelines).forEach(([id,p]) => {
      const node = document.createElement('div');
      node.className = 'pipeline';
      node.innerHTML = `<div><b>${p.title || id}</b> <span class="muted">${id}</span></div><div class="muted">${p.description || ''}</div><div class="muted">Шаги: ${(p.steps || []).join(' → ')}</div><button data-pipeline="${id}" type="button">Запустить pipeline</button>`;
      box.appendChild(node);
    });
  }

  async function runModule(moduleId, opts={}){
    const body = await api('/api/sidecar/run/', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({module_id:moduleId,...opts})});
    log(`run ${moduleId}`, body);
    await loadModules();
  }

  async function cleanupModule(moduleId){
    const body = await api('/api/sidecar/cleanup/', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({module_id:moduleId})});
    log(`cleanup ${moduleId}`, body);
    await loadModules();
  }

  async function runPipeline(pipelineId, dryRun){
    const body = await api('/api/sidecar/pipelines/', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pipeline_id:pipelineId,dry_run:!!dryRun})});
    log(`pipeline ${pipelineId}`, body);
    await loadModules();
  }

  document.addEventListener('click', async (e)=>{
    const t = e.target;
    const action = t && t.getAttribute && t.getAttribute('data-action');
    const id = t && t.getAttribute && t.getAttribute('data-id');
    const pipelineId = t && t.getAttribute && t.getAttribute('data-pipeline');
    if (pipelineId) return runPipeline(pipelineId, false);
    if (!action || !id) return;
    if (action === 'dry') return runModule(id, {dry_run:true});
    if (action === 'run') return runModule(id, {dry_run:false});
    if (action === 'attach') {
      const turn = Number(prompt('К какому году прикрепить overlay?', '1') || '0');
      return runModule(id, {dry_run:false, attach_to_turn:true, turn_year:turn});
    }
    if (action === 'cleanup') return cleanupModule(id);
    if (action === 'refresh') return loadModules();
  });

  el('search').addEventListener('input', renderModules);
  el('refreshAll').addEventListener('click', loadModules);
  el('runLogistics').addEventListener('click', async ()=>{
    const targets = modules.filter(m => (m.tags||[]).includes('logistics')).map(m=>m.id);
    for (const id of targets) await runModule(id, {dry_run:false});
  });
  el('runUnified').addEventListener('click', ()=>runPipeline('logistics_full', false));
  el('runEconomy').addEventListener('click', ()=>runPipeline('economy_full', false));

  loadModules();
  loadPipelines().catch((e)=>log('Ошибка загрузки pipeline', {error:String(e)}));
})();
