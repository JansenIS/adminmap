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

  async function saveStateAsBackendVariantFromPlayer(serializedState) {
    const parsedState = normalizeStateForBackendSave(JSON.parse(serializedState));
    const versionRes = await fetch('/api/map/version/', { cache: 'no-store' });
    if (!versionRes.ok) throw new Error('Не удалось получить версию карты: HTTP ' + versionRes.status);
    const versionPayload = await versionRes.json();
    const ifMatch = String(versionPayload && versionPayload.map_version || '').trim();
    if (!ifMatch) throw new Error('Пустая версия карты (map_version)');

    const payload = {
      state: parsedState,
      include_legacy_svg: false,
      replace_map_state: true,
    };

    let saveRes;
    allowMigrationApplyRequest = true;
    try {
      if (typeof CompressionStream === 'function') {
        try {
          const json = JSON.stringify(payload);
          const compressedStream = new Blob([json]).stream().pipeThrough(new CompressionStream('gzip'));
          const compressedBuffer = await new Response(compressedStream).arrayBuffer();
          saveRes = await fetch('/api/migration/apply/', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json;charset=utf-8',
              'Content-Encoding': 'gzip',
              'If-Match': ifMatch,
            },
            body: compressedBuffer,
          });
        } catch (_err) {
          saveRes = null;
        }
      }

      if (!saveRes) {
        saveRes = await fetch('/api/migration/apply/', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json;charset=utf-8',
            'If-Match': ifMatch,
          },
          body: JSON.stringify(payload),
        });
      }
    } finally {
      allowMigrationApplyRequest = false;
    }

    if (!saveRes.ok) {
      const errText = await saveRes.text();
      throw new Error('HTTP ' + saveRes.status + (errText ? (' — ' + errText.slice(0, 300)) : ''));
    }
    return saveRes.json();
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
      const btnExport = document.getElementById('btnExport');
      try {
        btn.disabled = true;
        setTurnActionStatus('Сохраняю ход в backend…', false);
        if (btnExport && typeof btnExport.click === 'function') btnExport.click();
        const serialized = String(stateTA.value || '').trim();
        if (!serialized) throw new Error('Пустое состояние карты');
        const result = await saveStateAsBackendVariantFromPlayer(serialized);
        const stats = result && result.stats ? result.stats : null;
        const summary = stats ? (` assets: ${stats.assets || 0}, refs: ${stats.refs || 0}, provinces: ${stats.provinces || 0}`) : '';
        setTurnActionStatus('Ход сохранён в backend-варианте.' + summary, false);
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

  async function loadScope() {
    const res = await fetch(`/api/player-admin/session/?token=${encodeURIComponent(token)}`);
    const json = await res.json();
    if (!res.ok || !json || !json.session) {
      throw new Error((json && json.error) || ('HTTP ' + res.status));
    }
    scope = json.session;
    scopeLoaded = true;
    window.PLAYER_ADMIN_SCOPE = scope;

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
  }

  const nativeFetch = window.fetch.bind(window);
  window.fetch = async (input, init = {}) => {
    const req = new Request(input, init);
    const url = new URL(req.url, window.location.origin);
    const headers = new Headers(req.headers || {});
    headers.set('X-Player-Admin-Token', token);

    if (url.pathname === '/api/changes/apply/') {
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
    setInterval(() => {
      lockRealmToScope();
      tuneTurnTreasuryUiForPlayer();
    }, 400);
  }).catch((err) => {
    alert('Недействительный токен: ' + (err && err.message ? err.message : err));
  });
})();
