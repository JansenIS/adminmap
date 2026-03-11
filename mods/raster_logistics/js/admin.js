async function postJson(url, payload) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload || {})
  });
  return await res.json();
}

const runOut = document.getElementById('runOut');
const routeOut = document.getElementById('routeOut');

document.getElementById('runBtn').addEventListener('click', async () => {
  runOut.textContent = 'Запуск…';
  const payload = {
    auto_run_submodules: document.getElementById('autoRun').checked,
    merge_marine_land_port_into_province: document.getElementById('mergePorts').checked
  };
  try {
    const data = await postJson('api/run.php', payload);
    runOut.textContent = JSON.stringify(data, null, 2);
  } catch (e) {
    runOut.textContent = String(e);
  }
});

document.getElementById('routeBtn').addEventListener('click', async () => {
  routeOut.textContent = 'Расчёт…';
  const payload = {
    from: document.getElementById('from').value.trim(),
    to: document.getElementById('to').value.trim(),
    mode: document.getElementById('mode').value
  };
  try {
    const data = await postJson('api/route.php', payload);
    routeOut.textContent = JSON.stringify(data, null, 2);
  } catch (e) {
    routeOut.textContent = String(e);
  }
});
