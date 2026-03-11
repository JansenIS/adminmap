(function(){
  const out = document.getElementById('out');
  const setOut = (data) => { out.textContent = typeof data === 'string' ? data : JSON.stringify(data, null, 2); };

  document.getElementById('runBtn').addEventListener('click', async () => {
    const payload = {
      dry_run: document.getElementById('dryRun').value === '1',
      downsample: Number(document.getElementById('downsample').value || 4),
      territorial_radius_cells: Number(document.getElementById('territorial').value || 18),
      target_neutral_area_cells: Number(document.getElementById('neutralArea').value || 1800)
    };
    setOut('Выполняется...');
    const res = await fetch('api/run.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    setOut(data);
  });

  document.getElementById('routeBtn').addEventListener('click', async () => {
    const payload = {
      from: document.getElementById('routeFrom').value.trim(),
      to: document.getElementById('routeTo').value.trim(),
      mode: document.getElementById('routeMode').value
    };
    setOut('Выполняется...');
    const res = await fetch('api/route.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    setOut(data);
  });
})();
