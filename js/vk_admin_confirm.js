(() => {
  const status = document.getElementById('status');
  const btn = document.getElementById('confirmBtn');
  const codeInput = document.getElementById('confirmCode');

  btn?.addEventListener('click', async () => {
    const code = String(codeInput?.value || '').trim();
    if (!/^\d{6}$/.test(code)) {
      status.textContent = 'Введите корректный 6-значный код из бота.';
      return;
    }
    btn.disabled = true;
    status.textContent = 'Проверяем код…';
    try {
      const checkRes = await fetch('/api/vk/admin_mode/confirm/?code=' + encodeURIComponent(code), { cache: 'no-store' });
      const check = await checkRes.json();
      if (!checkRes.ok || !check || !check.ok) throw new Error('invalid_code');

      status.textContent = 'Подтверждаем…';
      const res = await fetch('/api/vk/admin_mode/confirm/', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json;charset=utf-8' },
        body: JSON.stringify({ code }),
      });
      const data = await res.json();
      if (!res.ok || !data || !data.ok) throw new Error('confirm_failed');
      status.textContent = 'Готово! Админ-режим подтверждён. Вернитесь в бот и выберите «Заполнение провинций». ';
    } catch (_e) {
      btn.disabled = false;
      status.textContent = 'Не удалось подтвердить. Проверьте код или запросите новый в боте.';
    }
  });
})();
