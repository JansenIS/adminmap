(function(){
  const typeEl = document.getElementById('playerTokenEntityType');
  const idEl = document.getElementById('playerTokenEntityId');
  const btn = document.getElementById('playerTokenCreateBtn');
  const out = document.getElementById('playerTokenLink');
  if(!typeEl || !idEl || !btn || !out) return;

  btn.addEventListener('click', async ()=>{
    const entity_type = (typeEl.value || '').trim();
    const entity_id = (idEl.value || '').trim();
    if(!entity_id){ out.value = 'Заполните ID сущности.'; return; }
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
})();
