const $ = (id) => document.getElementById(id);
const els = {
  canvas: $('mapCanvas'), title: $('title'), pid: $('pid'), name: $('name'), isCity: $('isCity'),
  pop: $('pop'), infra: $('infra'), transportCap: $('transportCap'), transportUsed: $('transportUsed'), gdpTurnover: $('gdpTurnover'),
  buildingsTbl: $('buildingsTbl').querySelector('tbody'), addBuilding: $('addBuilding'), save: $('save'), status: $('status'),
  paintMode: $('paintMode'), showContours: $('showContours'), transparentMode: $('transparentMode')
};
const ctx = els.canvas.getContext('2d');

let provinces = [];
let provinceByPid = new Map();
let hexes = [];
let selected = null;

async function api(action, params = {}, method = 'GET', body = null) {
  const url = new URL('api.php', location.href);
  url.searchParams.set('action', action);
  Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, String(v)));
  const r = await fetch(url, { method, headers: {'Content-Type': 'application/json'}, body: body ? JSON.stringify(body) : null });
  const j = await r.json();
  if (!r.ok || !j.ok) throw new Error(j.message || j.error || `HTTP ${r.status}`);
  return j.data;
}

function hashColor(str, alpha = 1) {
  let h = 0;
  for (let i = 0; i < str.length; i++) h = ((h << 5) - h + str.charCodeAt(i)) | 0;
  const r = 80 + (Math.abs(h) % 140);
  const g = 80 + (Math.abs(h >> 8) % 140);
  const b = 80 + (Math.abs(h >> 16) % 140);
  return `rgba(${r},${g},${b},${alpha})`;
}

function provinceColor(p, alpha) {
  if (p.free_city_id) return `rgba(255,90,150,${Math.max(alpha,0.85)})`;
  const mode = els.paintMode.value;
  if (mode === 'kingdom') return hashColor(p.kingdom_id || 'no_kingdom', alpha);
  if (mode === 'great_house') return hashColor(p.great_house_id || 'no_great_house', alpha);
  return hashColor(p.minor_house_id || 'no_minor_house', alpha);
}

function drawMap() {
  const alpha = els.transparentMode.checked ? 0.25 : 0.9;
  const contours = els.showContours.checked;
  ctx.clearRect(0, 0, els.canvas.width, els.canvas.height);

  for (const h of hexes) {
    const p = provinceByPid.get(h.pid);
    if (!p) continue;
    ctx.fillStyle = provinceColor(p, alpha);
    ctx.fillRect(h.cx - 0.9, h.cy - 0.9, 1.8, 1.8);
    if (contours && h.border) {
      ctx.fillStyle = 'rgba(0,0,0,0.85)';
      ctx.fillRect(h.cx - 0.6, h.cy - 0.6, 1.2, 1.2);
    }
  }

  if (selected) {
    const p = provinceByPid.get(selected.pid);
    if (p) {
      ctx.strokeStyle = '#ffd166';
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.arc(p.centroid[0], p.centroid[1], 14, 0, Math.PI * 2);
      ctx.stroke();
    }
  }
}

function setBuildings(buildings = []) {
  els.buildingsTbl.innerHTML = '';
  for (const b of buildings) addBuildingRow(b.type, b.count, b.efficiency);
}

function addBuildingRow(type = '', count = 1, efficiency = 0.8) {
  const tr = document.createElement('tr');
  tr.innerHTML = `<td><input value="${type}"></td><td><input type="number" value="${count}"></td><td><input type="number" step="0.01" value="${efficiency}"></td><td><button type="button">x</button></td>`;
  tr.querySelector('button').onclick = () => tr.remove();
  els.buildingsTbl.appendChild(tr);
}

async function selectProvince(pid) {
  selected = await api('province', { pid, limit: 200, active: 0, tier: 'all', sort: 'value' });
  els.title.textContent = `Провинция ${selected.pid} — ${selected.name}`;
  els.pid.value = selected.pid;
  els.name.value = selected.name;
  els.isCity.checked = !!selected.isCity;
  els.pop.value = selected.pop;
  els.infra.value = selected.infra;
  els.transportCap.value = selected.transportCap;
  els.transportUsed.value = selected.transportUsed;
  els.gdpTurnover.value = selected.gdpTurnover;
  setBuildings(selected.buildings || []);
  drawMap();
}

function nearestProvincePid(x, y) {
  let best = null;
  let bestD = Infinity;
  for (const p of provinces) {
    const dx = p.centroid[0] - x;
    const dy = p.centroid[1] - y;
    const d = dx * dx + dy * dy;
    if (d < bestD) { bestD = d; best = p.pid; }
  }
  return best;
}

function collectBuildings() {
  return [...els.buildingsTbl.querySelectorAll('tr')].map((tr) => {
    const i = tr.querySelectorAll('input');
    return { type: i[0].value.trim(), count: Number(i[1].value || 0), efficiency: Number(i[2].value || 0) };
  }).filter((x) => x.type);
}

async function save() {
  if (!selected) return;
  const payload = {
    isCity: els.isCity.checked ? 1 : 0,
    pop: Number(els.pop.value || 0),
    infra: Number(els.infra.value || 0),
    transportCap: Number(els.transportCap.value || 0),
    transportUsed: Number(els.transportUsed.value || 0),
    gdpTurnover: Number(els.gdpTurnover.value || 0),
    buildings: collectBuildings(),
  };
  const data = await api('admin-province-save', { pid: selected.pid }, 'POST', payload);
  selected = data;
  els.status.textContent = `Сохранено: PID ${selected.pid} (${new Date().toLocaleTimeString()})`;
}

async function init() {
  const mapData = await api('admin-map');
  provinces = mapData.provinces;
  hexes = mapData.hexes;
  provinceByPid = new Map(provinces.map((p) => [p.pid, p]));

  drawMap();
  if (provinces[0]) selectProvince(provinces[0].pid);

  els.canvas.addEventListener('click', (e) => {
    const rect = els.canvas.getBoundingClientRect();
    const x = ((e.clientX - rect.left) / rect.width) * els.canvas.width;
    const y = ((e.clientY - rect.top) / rect.height) * els.canvas.height;
    const pid = nearestProvincePid(x, y);
    if (pid) selectProvince(pid);
  });
}

els.addBuilding.onclick = () => addBuildingRow();
els.save.onclick = () => save().catch((e) => els.status.textContent = e.message);
els.paintMode.onchange = drawMap;
els.showContours.onchange = drawMap;
els.transparentMode.onchange = drawMap;

init().catch((e) => { els.status.textContent = e.message; });
