(() => {
  const params = new URLSearchParams(window.location.search || '');
  const token = String(params.get('token') || '').trim();
  const status = document.getElementById('status');
  const btn = document.getElementById('confirmBtn');

  async function init() {
    if (!token) {
      status.textContent = 'Ошибка: отсутствует token в ссылке.';
      return;
    }
    try {
      const res = await fetch('/api/vk/admin_mode/confirm/?token=' + encodeURIComponent(token), { cache: 'no-store' });
      const data = await res.json();
      if (!res.ok || !data || !data.ok) {
        status.textContent = 'Токен недействителен или истёк. Запросите новую ссылку в боте.';
        return;
      }
      status.textContent = 'Нажмите кнопку ниже для подтверждения прав админа в боте.';
      btn.disabled = false;
    } catch (_e) {
      status.textContent = 'Не удалось проверить токен. Попробуйте позже.';
    }
  }

  btn?.addEventListener('click', async () => {
    btn.disabled = true;
    status.textContent = 'Подтверждаем…';
    try {
      const res = await fetch('/api/vk/admin_mode/confirm/', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json;charset=utf-8' },
        body: JSON.stringify({ token }),
      });
      const data = await res.json();
      if (!res.ok || !data || !data.ok) throw new Error('confirm_failed');
      status.textContent = 'Готово! Админ-режим подтверждён. Вернитесь в бот и выберите «Заполнение провинций». ';
    } catch (_e) {
      btn.disabled = false;
      status.textContent = 'Не удалось подтвердить. Запросите новую ссылку в боте.';
    }
  });

  init();
})();
