(function(){
  const typeEl = document.getElementById('playerTokenEntityType');
  const idEl = document.getElementById('playerTokenEntityId');
  const btn = document.getElementById('playerTokenCreateBtn');
  const out = document.getElementById('playerTokenLink');
  if(!typeEl || !idEl || !btn || !out) return;

  function renderEntityOptions(items){
    const prev = (idEl.value || '').trim();
    idEl.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Выберите сущность…';
    idEl.appendChild(placeholder);

    for (const it of (Array.isArray(items) ? items : [])) {
      const id = String(it && it.id || '').trim();
      if (!id) continue;
      const name = String(it && it.name || id).trim();
      const option = document.createElement('option');
      option.value = id;
      option.textContent = `${name} (${id})`;
      idEl.appendChild(option);
    }

    if (prev) {
      for (const opt of idEl.options) {
        if (String(opt.value) === prev) { idEl.value = prev; break; }
      }
    }
  }

  async function loadEntitiesForType(){
    const entityType = (typeEl.value || '').trim();
    if (!entityType) {
      renderEntityOptions([]);
      return;
    }

    idEl.innerHTML = '<option value="">Загрузка…</option>';
    try {
      const res = await fetch(`/api/realms/?type=${encodeURIComponent(entityType)}&profile=compact`);
      const data = await res.json();
      if (!res.ok || data.error) throw new Error(data.error || ('HTTP ' + res.status));
      renderEntityOptions(data.items || []);
      if (!idEl.options.length || !idEl.value) {
        out.value = 'Выберите сущность для генерации ссылки.';
      }
    } catch (e) {
      idEl.innerHTML = '<option value="">Не удалось загрузить список</option>';
      out.value = 'Ошибка загрузки сущностей: ' + (e && e.message ? e.message : e);
    }
  }

  typeEl.addEventListener('change', ()=>{ loadEntitiesForType(); });

  btn.addEventListener('click', async ()=>{
    const entity_type = (typeEl.value || '').trim();
    const entity_id = (idEl.value || '').trim();
    if(!entity_id){ out.value = 'Выберите сущность из списка.'; return; }
    out.value = 'Генерирую…';
    try {
      const res = await fetch('/api/player/tokens/create/', {
        method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({entity_type, entity_id})
      });
      const data = await res.json();
      if(!res.ok || data.error) throw new Error(data.error || ('HTTP '+res.status));
      out.value = data.url || '';
      out.select();
      try { await navigator.clipboard.writeText(out.value); } catch (_) {}
    } catch (e) {
      out.value = 'Ошибка: ' + (e && e.message ? e.message : e);
    }
  });

  loadEntitiesForType();
})();
