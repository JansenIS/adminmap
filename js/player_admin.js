(() => {
  const params = new URLSearchParams(window.location.search || '');
  const token = String(params.get('token') || '').trim();
  if (!token) {
    alert('Требуется token в URL');
    return;
  }

  let scope = null;
  let scopeLoaded = false;

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
  }

  const nativeFetch = window.fetch.bind(window);
  window.fetch = async (input, init = {}) => {
    const req = new Request(input, init);
    const url = new URL(req.url, window.location.origin);
    const headers = new Headers(req.headers || {});
    headers.set('X-Player-Admin-Token', token);

    if (url.pathname === '/api/migration/apply/' || url.pathname === '/api/changes/apply/') {
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

  loadScope().catch((err) => {
    alert('Недействительный токен: ' + (err && err.message ? err.message : err));
  });
})();
