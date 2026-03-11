const out = document.getElementById('output');
const summaryGrid = document.getElementById('summaryGrid');

function show(data) {
  out.textContent = JSON.stringify(data, null, 2);
  const world = data?.summary?.world || data?.overlay?.world || null;
  if (!world) {
    summaryGrid.innerHTML = '<div class="stat">Нет world summary.</div>';
    return;
  }
  const stats = [
    ['Базовое население', world.base_population],
    ['Эффективное население', world.effective_population],
    ['Плановые рабочие места', world.jobs_planned],
    ['Активные рабочие места', world.jobs_active],
    ['Изъято арьербаном', world.active_labor_removed],
    ['Безвозвратные потери', world.permanent_losses],
    ['Recovery pool', world.recovery_pool],
    ['Оценка товарооборота', world.trade_turnover_est],
    ['Алерты', world.alerts],
  ];
  summaryGrid.innerHTML = stats.map(([k, v]) => `<div class="stat"><div class="muted">${k}</div><div>${v ?? '—'}</div></div>`).join('');
}

async function request(payload) {
  const res = await fetch('./api/run.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  const data = await res.json();
  show(data);
}

document.getElementById('runBtn').addEventListener('click', () => {
  request({
    action: 'run',
    dry_run: document.getElementById('dryRun').checked,
    attach_to_turn: document.getElementById('attachTurn').checked,
    turn_year: Number(document.getElementById('turnYear').value || 0),
    run_label: document.getElementById('runLabel').value || 'admin-ui'
  });
});

document.getElementById('latestBtn').addEventListener('click', () => request({ action: 'latest' }));
document.getElementById('cleanupBtn').addEventListener('click', () => request({ action: 'cleanup' }));
document.getElementById('downloadStateBtn').addEventListener('click', () => window.location.href = './api/download.php?scope=state');
document.getElementById('downloadTraceBtn').addEventListener('click', () => window.location.href = './api/download.php?scope=trace');
