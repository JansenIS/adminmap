(function () {
  'use strict';

  const el = (id) => document.getElementById(id);
  const UI = {
    currentYear: el('currentYear'),
    currentStatus: el('currentStatus'),
    status: el('status'),
    turnRows: el('turnRows'),
    sourceYear: el('sourceYear'),
    targetYear: el('targetYear'),
    rulesetVersion: el('rulesetVersion'),
    actionYear: el('actionYear'),
    setCurrentYear: el('setCurrentYear'),
    btnRefresh: el('btnRefresh'),
    btnCreate: el('btnCreate'),
    btnMakeNext: el('btnMakeNext'),
    btnProcess: el('btnProcess'),
    btnPublish: el('btnPublish'),
    btnRollback: el('btnRollback'),
    btnSetCurrent: el('btnSetCurrent'),
    btnResetTurns: el('btnResetTurns'),
    btnGenerateBaseline: el('btnGenerateBaseline'),
  };

  function setStatus(text) {
    UI.status.textContent = String(text || '');
  }

  async function api(path, options) {
    const resp = await fetch(path, options);
    const body = await resp.json().catch(() => ({}));
    if (!resp.ok) {
      const msg = body && (body.error || body.message) ? `${body.error || body.message}` : `HTTP ${resp.status}`;
      const err = new Error(msg);
      err.body = body;
      throw err;
    }
    return body;
  }

  function renderTurns(items) {
    UI.turnRows.innerHTML = '';
    for (const row of items) {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${row.year}</td>
        <td>${row.status || ''}</td>
        <td style="font-family:monospace;">${row.version || ''}</td>
        <td>${row.entity_treasury_records || 0}</td>
        <td>${row.province_treasury_records || 0}</td>
        <td>${row.treasury_ledger_records || 0}</td>
      `;
      tr.addEventListener('click', () => {
        UI.actionYear.value = String(row.year || 1);
        UI.setCurrentYear.value = String(row.year || 1);
      });
      UI.turnRows.appendChild(tr);
    }
  }

  async function loadTurns() {
    const body = await api('/api/turns/', { cache: 'no-store' });
    const items = Array.isArray(body.items) ? body.items : [];
    renderTurns(items);
    const published = items.filter((x) => String(x.status || '') === 'published');
    const current = published.length ? published[published.length - 1] : (items.length ? items[items.length - 1] : null);
    UI.currentYear.value = current ? String(current.year || '—') : '—';
    UI.currentStatus.value = current ? String(current.status || '—') : '—';
    return { items, current };
  }

  async function getTurnVersion(year) {
    const body = await api(`/api/turns/show/?year=${encodeURIComponent(year)}&published_only=0`, { cache: 'no-store' });
    return String(body?.turn?.version || '');
  }

  async function createTurn(sourceYear, targetYear, rulesetVersion) {
    return api('/api/turns/create-from-previous/', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json;charset=utf-8' },
      body: JSON.stringify({ source_turn_year: sourceYear, target_turn_year: targetYear, ruleset_version: rulesetVersion }),
    });
  }

  async function processTurn(year) {
    const version = await getTurnVersion(year);
    return api('/api/turns/process-economy/', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json;charset=utf-8', 'If-Match': version },
      body: JSON.stringify({ turn_year: year, if_match: version }),
    });
  }

  async function publishTurn(year) {
    const version = await getTurnVersion(year);
    return api('/api/turns/publish/', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json;charset=utf-8', 'If-Match': version },
      body: JSON.stringify({ turn_year: year, if_match: version }),
    });
  }

  async function rollbackTurn(year) {
    const version = await getTurnVersion(year);
    return api('/api/turns/rollback/', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json;charset=utf-8', 'If-Match': version },
      body: JSON.stringify({ turn_year: year, reason: 'manual rollback from turn-admin', if_match: version }),
    });
  }

  async function restoreStateToYear(year) {
    try {
      return await api('/api/turns/restore-state/', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json;charset=utf-8' },
        body: JSON.stringify({ turn_year: year }),
      });
    } catch (err) {
      const expected = String(err?.body?.expected_version || '');
      if (!expected) throw err;
      return api('/api/turns/restore-state/', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json;charset=utf-8', 'If-Match': expected },
        body: JSON.stringify({ turn_year: year, if_match: expected }),
      });
    }
  }

  async function resetTurns() {
    return api('/api/turns/reset/', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json;charset=utf-8' },
      body: JSON.stringify({}),
    });
  }

  async function generateProvinceBaseline() {
    return api('/api/turns/generate-province-baseline/', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json;charset=utf-8' },
      body: JSON.stringify({}),
    });
  }

  UI.btnRefresh.addEventListener('click', async () => {
    try {
      setStatus('Обновляю список ходов…');
      await loadTurns();
      setStatus('Список обновлён.');
    } catch (err) {
      setStatus(`Ошибка обновления: ${err.message}`);
    }
  });

  UI.btnCreate.addEventListener('click', async () => {
    const sourceYear = Number(UI.sourceYear.value || 0);
    const targetYear = Number(UI.targetYear.value || 1);
    const rulesetVersion = String(UI.rulesetVersion.value || 'v1.0').trim() || 'v1.0';
    try {
      setStatus(`Создаю ход ${targetYear} из ${sourceYear}…`);
      await createTurn(sourceYear, targetYear, rulesetVersion);
      await loadTurns();
      setStatus(`Ход ${targetYear} создан.`);
    } catch (err) {
      setStatus(`Ошибка создания хода: ${err.message}`);
    }
  });

  UI.btnMakeNext.addEventListener('click', async () => {
    try {
      setStatus('Определяю следующий год…');
      const { items } = await loadTurns();
      const published = items.filter((x) => String(x.status || '') === 'published');
      const sourceYear = published.length ? Number(published[published.length - 1].year || 0) : 0;
      const targetYear = sourceYear + 1;
      const rulesetVersion = String(UI.rulesetVersion.value || 'v1.0').trim() || 'v1.0';
      setStatus(`Создаю/обрабатываю/публикую ход ${targetYear}…`);
      await createTurn(sourceYear, targetYear, rulesetVersion);
      await processTurn(targetYear);
      await publishTurn(targetYear);
      UI.targetYear.value = String(targetYear + 1);
      UI.actionYear.value = String(targetYear);
      UI.setCurrentYear.value = String(targetYear);
      await loadTurns();
      setStatus(`Готово: ход ${targetYear} опубликован.`);
    } catch (err) {
      setStatus(`Ошибка авто-сценария: ${err.message}`);
    }
  });

  UI.btnProcess.addEventListener('click', async () => {
    const year = Number(UI.actionYear.value || 1);
    try {
      setStatus(`Process economy для ${year}…`);
      await processTurn(year);
      await loadTurns();
      setStatus(`Экономика для ${year} обработана.`);
    } catch (err) {
      setStatus(`Ошибка process economy: ${err.message}`);
    }
  });

  UI.btnPublish.addEventListener('click', async () => {
    const year = Number(UI.actionYear.value || 1);
    try {
      setStatus(`Publish ${year}…`);
      await publishTurn(year);
      await loadTurns();
      setStatus(`Ход ${year} опубликован.`);
    } catch (err) {
      setStatus(`Ошибка publish: ${err.message}`);
    }
  });

  UI.btnRollback.addEventListener('click', async () => {
    const year = Number(UI.actionYear.value || 1);
    try {
      setStatus(`Rollback ${year}…`);
      await rollbackTurn(year);
      await loadTurns();
      setStatus(`Ход ${year} откатан.`);
    } catch (err) {
      setStatus(`Ошибка rollback: ${err.message}`);
    }
  });

  UI.btnSetCurrent.addEventListener('click', async () => {
    const year = Number(UI.setCurrentYear.value || 1);
    try {
      setStatus(`Восстанавливаю map_state из published хода ${year}…`);
      await restoreStateToYear(year);
      setStatus(`Текущий state переключён на год ${year}.`);
    } catch (err) {
      setStatus(`Ошибка выставления текущего года: ${err.message}`);
    }
  });

  UI.btnResetTurns.addEventListener('click', async () => {
    try {
      setStatus('Сбрасываю все ходы…');
      const result = await resetTurns();
      UI.sourceYear.value = '0';
      UI.targetYear.value = '1';
      UI.actionYear.value = '1';
      UI.setCurrentYear.value = '1';
      await loadTurns();
      setStatus(`Ходы сброшены. Удалено: turns=${result?.removed?.turn_files || 0}, snapshots=${result?.removed?.snapshots || 0}, overlays=${result?.removed?.overlays || 0}. Текущий ход: 0.`);
    } catch (err) {
      setStatus(`Ошибка сброса ходов: ${err.message}`);
    }
  });

  UI.btnGenerateBaseline.addEventListener('click', async () => {
    try {
      setStatus('Генерирую стартовое население и казну по провинциям…');
      const result = await generateProvinceBaseline();
      const updated = result?.updated || {};
      setStatus(`Базовые значения сгенерированы: провинций=${updated.updated || 0}, население=${updated.population_total || 0}, казна=${updated.treasury_total || 0}.`);
    } catch (err) {
      setStatus(`Ошибка генерации базовых значений: ${err.message}`);
    }
  });

  loadTurns().then(() => setStatus('Готово.')).catch((err) => setStatus(`Ошибка запуска: ${err.message}`));
})();
