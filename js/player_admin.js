(() => {
  const params = new URLSearchParams(window.location.search || '');
  const token = String(params.get('token') || '').trim();
  if (!token) {
    alert('Требуется token в URL');
    return;
  }

  let scope = null;
  let scopeLoaded = false;
  let allowMigrationApplyRequest = false;
  let allowChangesApplyRequest = false;
  let baselineStateForTurnSave = null;

  function normalizeStateForBackendSave(rawState) {
    const stateForSave = JSON.parse(JSON.stringify(rawState || {}));
    if (Array.isArray(stateForSave.people)) {
      const out = [];
      const seen = new Set();
      for (const person of stateForSave.people) {
        const name = (typeof person === 'string') ? person.trim() : String((person && person.name) || '').trim();
        if (!name) continue;
        const key = name.toLowerCase();
        if (seen.has(key)) continue;
        seen.add(key);
        out.push(name);
      }
      stateForSave.people = out;
    }
    if (stateForSave.provinces && typeof stateForSave.provinces === 'object') {
      for (const pd of Object.values(stateForSave.provinces)) {
        if (!pd || typeof pd !== 'object') continue;
        if (typeof pd.province_card_image === 'string' && pd.province_card_image.startsWith('data:')) pd.province_card_image = '';
        if (typeof pd.province_card_base_image === 'string' && pd.province_card_base_image.startsWith('data:')) pd.province_card_base_image = '';
      }
    }
    return stateForSave;
  }

  function isEqualValue(a, b) {
    return JSON.stringify(a) === JSON.stringify(b);
  }

  function pickObjectDiff(baseObj, nextObj, allowedFields) {
    const diff = {};
    for (const field of allowedFields) {
      const oldVal = baseObj && Object.prototype.hasOwnProperty.call(baseObj, field) ? baseObj[field] : undefined;
      const newVal = nextObj && Object.prototype.hasOwnProperty.call(nextObj, field) ? nextObj[field] : undefined;
      if (typeof newVal === 'undefined') continue;
      if (!isEqualValue(oldVal, newVal)) diff[field] = newVal;
    }
    return diff;
  }

  function buildTurnChanges(baseState, nextState) {
    const changes = [];
    const owned = new Set(((scope && scope.owned_pids) || []).map((x) => Number(x)));

    const provinceFields = [
      'name', 'owner', 'suzerain', 'senior', 'terrain',
      'vassals', 'fill_rgba', 'emblem_svg', 'emblem_box', 'emblem_asset_id',
      'kingdom_id', 'great_house_id', 'minor_house_id', 'free_city_id', 'special_territory_id',
      'province_card_image', 'wiki_description',
    ];
    for (const pid of owned) {
      const key = String(pid);
      const before = baseState && baseState.provinces ? baseState.provinces[key] : null;
      const after = nextState && nextState.provinces ? nextState.provinces[key] : null;
      if (!after || typeof after !== 'object') continue;
      const diff = pickObjectDiff(before || {}, after, provinceFields);
      if (Object.keys(diff).length) changes.push({ kind: 'province', pid, changes: diff });
    }

    if (scope && scope.entity_type && scope.entity_id) {
      const type = String(scope.entity_type);
      const id = String(scope.entity_id);
      const before = baseState && baseState[type] && baseState[type][id] ? baseState[type][id] : null;
      const after = nextState && nextState[type] && nextState[type][id] ? nextState[type][id] : null;
      if (after && typeof after === 'object') {
        const realmFields = ['name', 'ruler', 'ruling_house_id', 'vassal_house_ids', 'color', 'capital_pid', 'emblem_scale', 'warlike_coeff', 'loyalty_coeff', 'emblem_svg', 'emblem_box', 'province_pids', 'wiki_description', 'diplomacy'];
        const diff = pickObjectDiff(before || {}, after, realmFields);
        if (Object.keys(diff).length) changes.push({ kind: 'realm', type, id, changes: diff });
      }
    }

    const beforeByUid = new Map();
    for (const row of (baseState && Array.isArray(baseState.army_registry) ? baseState.army_registry : [])) {
      if (!row || typeof row !== 'object') continue;
      const uid = String(row.army_uid || '').trim();
      if (uid) beforeByUid.set(uid, row);
    }
    for (const row of (nextState && Array.isArray(nextState.army_registry) ? nextState.army_registry : [])) {
      if (!row || typeof row !== 'object') continue;
      if (String(row.realm_type || '') !== String(scope && scope.entity_type || '')) continue;
      if (String(row.realm_id || '') !== String(scope && scope.entity_id || '')) continue;
      const uid = String(row.army_uid || '').trim();
      if (!uid) continue;
      const prev = beforeByUid.get(uid) || {};
      const diff = pickObjectDiff(prev, row, ['current_pid', 'moved_this_turn', 'moved_turn_year']);
      if (Object.keys(diff).length) changes.push({ kind: 'army', army_uid: uid, changes: diff });
    }
    return changes;
  }

  async function saveStateAsBackendVariantFromPlayer(serializedState) {
    const parsedState = normalizeStateForBackendSave(JSON.parse(serializedState));
    const baseState = normalizeStateForBackendSave(JSON.parse(JSON.stringify(baselineStateForTurnSave || {})));
    const changes = buildTurnChanges(baseState, parsedState);
    if (!changes.length) return { ok: true, applied: 0, noop: true };
    const versionRes = await fetch('/api/map/version/', { cache: 'no-store' });
    if (!versionRes.ok) throw new Error('Не удалось получить версию карты: HTTP ' + versionRes.status);
    const versionPayload = await versionRes.json();
    const ifMatch = String(versionPayload && versionPayload.map_version || '').trim();
    if (!ifMatch) throw new Error('Пустая версия карты (map_version)');

    let saveRes;
    allowChangesApplyRequest = true;
    try {
      saveRes = await fetch('/api/changes/apply/', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json;charset=utf-8',
          'If-Match': ifMatch,
        },
        body: JSON.stringify({ changes }),
      });
    } finally {
      allowChangesApplyRequest = false;
    }

    if (!saveRes.ok) {
      const errText = await saveRes.text();
      throw new Error('HTTP ' + saveRes.status + (errText ? (' — ' + errText.slice(0, 300)) : ''));
    }
    const result = await saveRes.json();
    baselineStateForTurnSave = parsedState;
    return result;
  }


  async function refreshBattleLinks() {
    const box = document.getElementById('warBattleLinks');
    if (!box) return;
    try {
      const res = await fetch('/api/war/battles/list/?token=' + encodeURIComponent(token), { cache: 'no-store' });
      if (res.status === 304) {
        if (!box.textContent.trim()) {
          box.textContent = 'Активных боёв с вашим участием нет.';
        }
        return;
      }
      const raw = await res.text();
      const json = raw ? JSON.parse(raw) : null;
      if (!res.ok || !json || !json.ok) throw new Error((json && json.error) || ('HTTP ' + res.status));
      const rows = Array.isArray(json.battles) ? json.battles.filter((b) => b && b.my_link) : [];
      if (!rows.length) {
        box.textContent = 'Активных боёв с вашим участием нет.';
        return;
      }
      box.innerHTML = rows.map((b) => {
        const pid = Number(b.province_pid || 0);
        const status = String(b.status || 'setup');
        const side = String(b.my_side || '?');
        const url = String(b.my_link || '#');
        return `<div style="margin-bottom:8px;"><b>${b.battle_id}</b> • PID ${pid} • сторона ${side} • ${status}<br><a href="${url}" target="_blank" rel="noopener">Открыть боевую сессию</a></div>`;
      }).join('');
    } catch (err) {
      box.textContent = 'Не удалось загрузить список боёв: ' + (err && err.message ? err.message : err);
    }
  }

  function setTurnActionStatus(message, isError) {
    const statusEl = document.getElementById('turnActionStatus');
    if (!statusEl) return;
    statusEl.textContent = String(message || '—');
    statusEl.style.color = isError ? '#ff9f9f' : '';
  }

  function setupSaveTurnButton() {
    const btn = document.getElementById('btnSaveTurnToBackend');
    const stateTA = document.getElementById('state');
    if (!btn || !stateTA) return;
    btn.addEventListener('click', async () => {
      const exportBtn = document.getElementById('export');
      const exportFn = window.AdminMapExportStateToTextarea;
      try {
        btn.disabled = true;
        setTurnActionStatus('Сохраняю ход в backend…', false);
        if (typeof exportFn === 'function') exportFn();
        else if (exportBtn && typeof exportBtn.click === 'function') exportBtn.click();
        const serialized = String(stateTA.value || '').trim();
        if (!serialized) throw new Error('Пустое состояние карты');
        const result = await saveStateAsBackendVariantFromPlayer(serialized);
        if (result && result.noop) {
          setTurnActionStatus('Изменений для сохранения нет.', false);
          return;
        }
        const stats = result && result.stats ? result.stats : null;
        const summary = stats ? (` assets: ${stats.assets || 0}, refs: ${stats.refs || 0}, provinces: ${stats.provinces || 0}`) : '';
        const applied = Number(result && result.applied || 0);
        setTurnActionStatus(`Ход сохранён (изменений применено: ${applied}).` + summary, false);
      } catch (err) {
        setTurnActionStatus('Не удалось сохранить ход: ' + (err && err.message ? err.message : err), true);
      } finally {
        btn.disabled = false;
      }
    });
  }

  function lockRealmToScope() {
    if (!scope) return;
    const typeEl = document.getElementById('realmType');
    const realmEl = document.getElementById('realmSelect');
    if (typeEl) {
      typeEl.value = String(scope.entity_type || '');
      typeEl.disabled = true;
    }
    if (realmEl) {
      const targetId = String(scope.entity_id || '');
      if (targetId) {
        const has = Array.from(realmEl.options || []).some((opt) => String(opt.value || '') === targetId);
        if (!has) {
          const opt = document.createElement('option');
          opt.value = targetId;
          opt.textContent = String(scope.entity_name || targetId);
          realmEl.appendChild(opt);
        }
        realmEl.value = targetId;
      }
      realmEl.disabled = true;
    }
  }

  function tuneTurnTreasuryUiForPlayer() {
    const provSpan = document.getElementById('turnTreasuryProvSum');
    const entitySpan = document.getElementById('turnTreasuryEntitySum');
    if (provSpan) {
      const row = provSpan.parentElement;
      const label = row ? row.querySelector('b') : null;
      if (label) label.textContent = 'Казна игрока:';
    }
    if (entitySpan && entitySpan.parentElement) entitySpan.parentElement.style.display = 'none';
  }

  function refreshTurnPanelForScope() {
    const run = window.AdminMapRefreshTurnPanel;
    if (typeof run !== 'function') return;
    run().catch(() => {});
  }

  async function loadScope() {
    const res = await fetch(`/api/player-admin/session/?token=${encodeURIComponent(token)}`);
    const json = await res.json();
    if (!res.ok || !json || !json.session) {
      throw new Error((json && json.error) || ('HTTP ' + res.status));
    }
    scope = json.session;
    scopeLoaded = true;
    window.PLAYER_ADMIN_SCOPE = scope;
    try {
      if (typeof window.AdminMapExportStateToTextarea === 'function') window.AdminMapExportStateToTextarea();
      const stateTA = document.getElementById('state');
      const serialized = String(stateTA && stateTA.value || '').trim();
      if (serialized) baselineStateForTurnSave = normalizeStateForBackendSave(JSON.parse(serialized));
    } catch (_e) {}

    const title = document.querySelector('h1');
    if (title) title.textContent = `Player Admin: ${scope.entity_name || scope.entity_id}`;

    const hideIds = [
      'btnMakeTurn','btnRefreshTurn','btnOpenTurnAdmin','saveServer','saveImportedBackend','btnImport','btnExport','btnBulkSetKingdom','btnBulkSetGreatHouse','btnBulkSetMinorHouse','btnBulkSetFreeCity','btnBulkSetSpecialTerritory'
    ];
    for (const id of hideIds) {
      const el = document.getElementById(id);
      if (el) el.style.display = 'none';
    }
    const tokenUiBtn = document.getElementById('playerAdminTokenCreateBtn');
    if (tokenUiBtn && tokenUiBtn.closest('.card')) tokenUiBtn.closest('.card').style.display = 'none';

    tuneTurnTreasuryUiForPlayer();
    lockRealmToScope();
    refreshTurnPanelForScope();
    refreshBattleLinks();
  }

  const nativeFetch = window.fetch.bind(window);
  window.fetch = async (input, init = {}) => {
    const req = new Request(input, init);
    const url = new URL(req.url, window.location.origin);
    const headers = new Headers(req.headers || {});
    headers.set('X-Player-Admin-Token', token);

    if (url.pathname === '/api/changes/apply/' && !allowChangesApplyRequest) {
      throw new Error('Операция недоступна в player_admin');
    }
    if (url.pathname === '/api/migration/apply/' && !allowMigrationApplyRequest) {
      throw new Error('Операция недоступна в player_admin');
    }

    if (url.pathname === '/api/realms/patch/' || url.pathname === '/api/provinces/patch/') {
      if (!scopeLoaded) {
        try { await loadScope(); } catch (_) {}
      }
      try {
        const bodyText = await req.clone().text();
        const payload = bodyText ? JSON.parse(bodyText) : {};
        if (scope) {
          if (url.pathname === '/api/realms/patch/') {
            if (String(payload.type || '') !== String(scope.entity_type || '') || String(payload.id || '') !== String(scope.entity_id || '')) {
              throw new Error('Можно редактировать только свою сущность');
            }
          }
          if (url.pathname === '/api/provinces/patch/') {
            const pid = Number(payload.pid || 0);
            const set = new Set((scope.owned_pids || []).map((x) => Number(x)));
            if (!set.has(pid)) throw new Error('Можно редактировать только свои провинции');
          }
        }
      } catch (err) {
        return Promise.reject(err);
      }
    }

    return nativeFetch(req, { ...init, headers });
  };

  loadScope().then(() => {
    setupSaveTurnButton();
    tuneTurnTreasuryUiForPlayer();
    lockRealmToScope();
    refreshTurnPanelForScope();
    refreshBattleLinks();
    setInterval(() => {
      lockRealmToScope();
      tuneTurnTreasuryUiForPlayer();
    }, 400);
    setInterval(() => { refreshBattleLinks(); }, 5000);
  }).catch((err) => {
    alert('Недействительный токен: ' + (err && err.message ? err.message : err));
  });
})();
