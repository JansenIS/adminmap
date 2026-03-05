(() => {
  const typeEl = document.getElementById('playerAdminTokenEntityType');
  const idEl = document.getElementById('playerAdminTokenEntityId');
  const btn = document.getElementById('playerAdminTokenCreateBtn');
  const out = document.getElementById('playerAdminTokenLink');
  if (!typeEl || !idEl || !btn || !out) return;

  const maps = {
    kingdoms: 'kingdomsMap',
    great_houses: 'greatHousesMap',
    minor_houses: 'minorHousesMap',
    free_cities: 'freeCitiesMap',
    special_territories: 'specialTerritoriesMap',
  };

  function entitiesByType(type) {
    const state = window.state || {};
    const key = maps[type];
    const map = state && state[key];
    if (!map || typeof map !== 'object') return [];
    return Object.entries(map).map(([id, v]) => ({ id, name: String((v && v.name) || id) }));
  }

  function refill() {
    const type = String(typeEl.value || '');
    const list = entitiesByType(type).sort((a, b) => a.name.localeCompare(b.name, 'ru'));
    idEl.innerHTML = '';
    for (const item of list) {
      const opt = document.createElement('option');
      opt.value = item.id;
      opt.textContent = item.name;
      idEl.appendChild(opt);
    }
    if (!idEl.value && idEl.options.length) idEl.selectedIndex = 0;
  }

  typeEl.addEventListener('change', refill);

  const initInterval = setInterval(() => {
    if (window.state && Object.keys(window.state).length) {
      clearInterval(initInterval);
      refill();
    }
  }, 150);

  btn.addEventListener('click', async () => {
    const entity_type = String(typeEl.value || '');
    const entity_id = String(idEl.value || '');
    if (!entity_type || !entity_id) return;
    btn.disabled = true;
    try {
      const res = await fetch('/api/player-admin/tokens/create/', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ entity_type, entity_id }),
      });
      const json = await res.json();
      if (!res.ok || !json || !json.path) throw new Error((json && json.error) || ('HTTP ' + res.status));
      const full = new URL(json.path, window.location.origin).toString();
      out.value = full;
      out.focus();
      out.select();
      try { await navigator.clipboard.writeText(full); } catch (_) {}
    } catch (err) {
      alert('Ошибка генерации ссылки: ' + (err && err.message ? err.message : err));
    } finally {
      btn.disabled = false;
    }
  });
})();
