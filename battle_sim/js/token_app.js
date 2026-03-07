(function(){
  'use strict';
  const params = new URLSearchParams(window.location.search || '');
  const token = String(params.get('token') || '').trim();
  const statusEl = document.getElementById('status');
  const infoEl = document.getElementById('info');
  const armiesEl = document.getElementById('armies');
  const simLink = document.getElementById('simLink');

  if (!token) {
    statusEl.textContent = 'Отсутствует token.';
    return;
  }
  simLink.href = '/battle_sim/index.html?battle_token=' + encodeURIComponent(token);

  async function load(){
    const res = await fetch('/api/war/battle/session/?token=' + encodeURIComponent(token), { cache: 'no-store' });
    const json = await res.json();
    if (!res.ok || !json.ok) throw new Error((json && json.error) || ('HTTP ' + res.status));
    const b = json.battle || {};
    statusEl.textContent = 'Сессия активна. Сторона: ' + String(json.side || '—') + '. Статус: ' + String(b.status || 'setup');
    const deadline = Number(b.auto_resolve_at || 0) > 0 ? new Date(Number(b.auto_resolve_at) * 1000).toLocaleString() : '—';
    const auto = b && b.auto_resolve_result ? b.auto_resolve_result : null;
    const autoLine = auto ? ('<div><b>Авторасчёт:</b> winner=' + String(auto.winner || 'draw') + ', rounds=' + Number(auto.rounds_total || 0) + '</div>') : '';
    infoEl.style.display = '';
    infoEl.innerHTML = '<div><b>Битва:</b> ' + (b.battle_id || '—') + '</div>' +
      '<div><b>Провинция:</b> ' + Number(b.province_pid || 0) + '</div>' +
      '<div><b>Дедлайн авторасчёта:</b> ' + deadline + '</div>' + autoLine;

    const my = Array.isArray(json.my_armies) ? json.my_armies : [];
    const enemy = Array.isArray(json.enemy_armies) ? json.enemy_armies : [];
    function renderArmy(row){
      const units = Array.isArray(row.units) ? row.units.map((u) => `${u.unit_id}: ${u.size}`).join(', ') : '—';
      return `<li>${row.army_name || row.army_uid} (PID ${Number(row.current_pid || 0)}, сила ${Number(row.strength_total || 0)})<br><span class="small">${units}</span></li>`;
    }
    armiesEl.style.display = '';
    armiesEl.innerHTML = '<div><b>Мои армии</b></div><ul>' + my.map(renderArmy).join('') + '</ul>' +
      '<div><b>Армии противника</b></div><ul>' + enemy.map(renderArmy).join('') + '</ul>';
  }

  async function setReady(ready){
    const res = await fetch('/api/war/battle/ready/', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ token, ready })
    });
    const json = await res.json();
    if (!res.ok || !json.ok) throw new Error((json && json.error) || ('HTTP ' + res.status));
    await load();
  }


  async function restartBattle(){
    const res = await fetch('/api/war/battle/restart/', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ token })
    });
    const json = await res.json();
    if (!res.ok || !json.ok) throw new Error((json && json.error) || ('HTTP ' + res.status));
    await load();
  }

  document.getElementById('btnReady').addEventListener('click', () => setReady(true).catch((e)=>alert(e.message || e)));
  document.getElementById('btnUnready').addEventListener('click', () => setReady(false).catch((e)=>alert(e.message || e)));
  document.getElementById('btnRestartBattle').addEventListener('click', () => restartBattle().catch((e)=>alert(e.message || e)));

  load().catch((err) => { statusEl.textContent = 'Ошибка: ' + (err.message || err); });
})();
