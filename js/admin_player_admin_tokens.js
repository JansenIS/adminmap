(() => {
  const typeEl = document.getElementById('playerAdminTokenEntityType');
  const idEl = document.getElementById('playerAdminTokenEntityId');
  const btn = document.getElementById('playerAdminTokenCreateBtn');
  const out = document.getElementById('playerAdminTokenLink');
  if (!typeEl || !idEl || !btn || !out) return;

  const maps = {
    great_houses: 'great_houses',
    minor_houses: 'minor_houses',
    free_cities: 'free_cities',
  };

  async function fetchEntitiesByType(type) {
    if (type === 'minor_houses') {
      const [great, special] = await Promise.all([
        fetch('/api/realms/?type=great_houses&profile=compact', { cache: 'no-store' }),
        fetch('/api/realms/?type=special_territories&profile=compact', { cache: 'no-store' }),
      ]);
      if (!great.ok) throw new Error(`HTTP ${great.status}`);
      if (!special.ok) throw new Error(`HTTP ${special.status}`);
      const greatPayload = await great.json();
      const specialPayload = await special.json();
      const out = [];
      const collect = (parentType, payload) => {
        const realms = Array.isArray(payload && payload.items) ? payload.items : [];
        for (const realm of realms) {
          if (!realm || typeof realm !== 'object') continue;
          const parentId = String(realm.id || '').trim();
          if (!parentId) continue;
          const layer = realm.minor_house_layer;
          if (!layer || typeof layer !== 'object' || !Array.isArray(layer.vassals)) continue;
          for (const v of layer.vassals) {
            if (!v || typeof v !== 'object') continue;
            const vassalId = String(v.id || '').trim();
            if (!vassalId) continue;
            out.push({
              id: `vassal:${parentType}:${parentId}:${vassalId}`,
              name: String(v.name || vassalId),
            });
          }
        }
      };
      collect('great_houses', greatPayload);
      collect('special_territories', specialPayload);
      return out;
    }

    const res = await fetch(`/api/realms/?type=${encodeURIComponent(type)}&profile=compact`, { cache: 'no-store' });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const payload = await res.json();
    const items = Array.isArray(payload && payload.items) ? payload.items : [];
    return items
      .map((item) => {
        const id = String(item && item.id || '').trim();
        if (!id) return null;
        return { id, name: String((item && item.name) || id) };
      })
      .filter(Boolean);
  }

  function entitiesByType(type) {
    const state = window.state || {};
    const key = maps[type];
    const map = state && state[key];
    if (map && typeof map === 'object' && Object.keys(map).length) {
      return Object.entries(map).map(([id, v]) => ({ id, name: String((v && v.name) || id) }));
    }
    if (type !== 'minor_houses') return [];

    const out = new Map();
    const collect = (parentType, bucket) => {
      if (!bucket || typeof bucket !== 'object') return;
      for (const [parentId, realm] of Object.entries(bucket)) {
        if (!realm || typeof realm !== 'object') continue;
        const layer = realm.minor_house_layer;
        if (!layer || typeof layer !== 'object' || !Array.isArray(layer.vassals)) continue;
        for (const v of layer.vassals) {
          if (!v || typeof v !== 'object') continue;
          const id = String(v.id || '').trim();
          if (!id) continue;
          const key = `vassal:${parentType}:${String(parentId).trim()}:${id}`;
          if (out.has(key)) continue;
          const name = String(v.name || id).trim() || id;
          out.set(key, { id: key, name });
        }
      }
    };
    collect('great_houses', state.great_houses);
    collect('special_territories', state.special_territories);
    return Array.from(out.values());
  }

  async function refill() {
    const type = String(typeEl.value || '');
    idEl.innerHTML = '<option value="">Загрузка…</option>';
    let list = [];
    try {
      list = await fetchEntitiesByType(type);
      if (!list.length && type === 'minor_houses') {
        list = entitiesByType(type);
      }
    } catch (err) {
      list = entitiesByType(type);
    }
    list.sort((a, b) => a.name.localeCompare(b.name, 'ru'));
    idEl.innerHTML = '';
    for (const item of list) {
      const opt = document.createElement('option');
      opt.value = item.id;
      opt.textContent = item.name;
      idEl.appendChild(opt);
    }
    if (!list.length) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'Нет доступных сущностей';
      idEl.appendChild(opt);
    }
    if (!idEl.value && idEl.options.length) idEl.selectedIndex = 0;
  }

  typeEl.addEventListener('change', refill);

  refill();

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
