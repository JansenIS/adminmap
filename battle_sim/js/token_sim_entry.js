(function(){
  'use strict';
  const stateEl = document.getElementById('state');
  const qs = new URLSearchParams(window.location.search || '');
  const token = String(qs.get('token') || qs.get('battle_token') || '').trim();

  function setMsg(html){ stateEl.innerHTML = html; }

  if(!token){
    setMsg('<b>Ошибка:</b> token не передан. Используйте ссылку вида <code>/battle_sim/token_sim.html?token=...</code>.');
    return;
  }

  try {
    localStorage.setItem('battle_sim_token', token);
    sessionStorage.setItem('battle_sim_token', token);
  } catch(_e) {}

  (async function(){
    try {
      const res = await fetch('/api/war/battle/session/?token=' + encodeURIComponent(token), { cache:'no-store' });
      const json = await res.json();
      if(!res.ok || !json || !json.ok) throw new Error((json && json.error) || ('HTTP ' + res.status));
      setMsg('Токен валиден. Переход в симулятор…');
      const q = encodeURIComponent(token);
      const target = '/battle_sim/index.html?battle_token=' + q + '&token=' + q + '&token_required=1#token=' + q;
      window.location.replace(target);
    } catch (err) {
      setMsg('<b>Ошибка токен-сессии:</b> ' + (err && err.message ? err.message : err));
    }
  })();
})();
