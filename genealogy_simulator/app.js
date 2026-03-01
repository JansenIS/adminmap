const state = {
  mode: 'admin',
  characters: [],
  relationships: [],
  positions: new Map(),
  selectedId: null,
};

const svg = document.getElementById('tree');
const quickAdd = document.getElementById('quickAdd');
const statusEl = document.getElementById('status');
const panel = document.getElementById('adminPanel');

async function api(path, options = {}) {
  const res = await fetch(path, { headers: { 'Content-Type': 'application/json' }, ...options });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(body.error || `HTTP ${res.status}`);
  return body;
}

function fmtYears(c) {
  const b = c.birth_year ?? '?';
  const d = c.death_year ?? '...';
  return `${b}—${d}`;
}

function computeGenerations(chars, rels) {
  const gen = new Map(chars.map(c => [c.id, 0]));
  const parents = new Map(chars.map(c => [c.id, []]));
  rels.filter(r => r.type === 'parent_child').forEach(r => {
    parents.get(r.target_id)?.push(r.source_id);
  });
  let changed = true;
  let guard = 0;
  while (changed && guard < 20) {
    changed = false;
    guard++;
    rels.forEach(r => {
      if (r.type === 'parent_child') {
        const parentGen = gen.get(r.source_id) || 0;
        const childGen = gen.get(r.target_id) || 0;
        if (childGen <= parentGen) {
          gen.set(r.target_id, parentGen + 1);
          changed = true;
        }
      }
    });
  }
  rels.filter(r => r.type === 'spouses' || r.type === 'siblings').forEach(r => {
    const g = Math.max(gen.get(r.source_id) || 0, gen.get(r.target_id) || 0);
    gen.set(r.source_id, g);
    gen.set(r.target_id, g);
  });
  return gen;
}

function layout() {
  const gen = computeGenerations(state.characters, state.relationships);
  const rows = new Map();
  state.characters.forEach(c => {
    const g = gen.get(c.id) || 0;
    if (!rows.has(g)) rows.set(g, []);
    rows.get(g).push(c.id);
  });
  [...rows.values()].forEach(arr => arr.sort());

  const pos = new Map();
  [...rows.entries()].sort((a, b) => a[0] - b[0]).forEach(([g, ids]) => {
    const spacing = 230;
    const offset = (1900 - (ids.length - 1) * spacing) / 2;
    ids.forEach((id, i) => pos.set(id, { x: offset + i * spacing, y: 130 + g * 230 }));
  });
  state.positions = pos;
}

function nodeById(id) { return state.characters.find(c => c.id === id); }

function render() {
  layout();
  svg.innerHTML = '';

  state.relationships.forEach(r => {
    const a = state.positions.get(r.source_id);
    const b = state.positions.get(r.target_id);
    if (!a || !b) return;
    const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    line.setAttribute('x1', a.x);
    line.setAttribute('y1', a.y);
    line.setAttribute('x2', b.x);
    line.setAttribute('y2', b.y);
    line.setAttribute('class', r.type === 'parent_child' ? 'edge-parent' : (r.type === 'siblings' ? 'edge-sibling' : 'edge-spouse'));
    svg.appendChild(line);
  });

  state.characters.forEach((c) => {
    const p = state.positions.get(c.id);
    if (!p) return;

    const clipId = `clip_${c.id}`;
    const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
    const clip = document.createElementNS('http://www.w3.org/2000/svg', 'clipPath');
    clip.setAttribute('id', clipId);
    const clipCircle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    clipCircle.setAttribute('cx', p.x);
    clipCircle.setAttribute('cy', p.y);
    clipCircle.setAttribute('r', 46);
    clip.appendChild(clipCircle);
    defs.appendChild(clip);
    svg.appendChild(defs);

    const img = document.createElementNS('http://www.w3.org/2000/svg', 'image');
    img.setAttribute('href', c.photo_url || 'https://placehold.co/160x160/1f2937/ffffff?text=?');
    img.setAttribute('x', p.x - 50);
    img.setAttribute('y', p.y - 50);
    img.setAttribute('width', 100);
    img.setAttribute('height', 100);
    img.setAttribute('clip-path', `url(#${clipId})`);
    img.style.cursor = 'pointer';
    img.addEventListener('click', () => onNodeClick(c.id));
    svg.appendChild(img);

    const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    circle.setAttribute('cx', p.x);
    circle.setAttribute('cy', p.y);
    circle.setAttribute('r', 48);
    circle.setAttribute('fill', 'transparent');
    circle.setAttribute('class', `node-circle ${state.selectedId === c.id ? 'selected' : ''}`);
    circle.addEventListener('click', () => onNodeClick(c.id));
    svg.appendChild(circle);

    const name = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    name.setAttribute('x', p.x);
    name.setAttribute('y', p.y + 70);
    name.setAttribute('class', 'node-label');
    name.textContent = c.name;
    svg.appendChild(name);

    const title = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    title.setAttribute('x', p.x);
    title.setAttribute('y', p.y + 88);
    title.setAttribute('class', 'node-meta');
    title.textContent = c.title || '—';
    svg.appendChild(title);

    const years = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    years.setAttribute('x', p.x);
    years.setAttribute('y', p.y + 106);
    years.setAttribute('class', 'node-meta');
    years.textContent = fmtYears(c);
    svg.appendChild(years);
  });

  if (state.mode === 'admin' && state.selectedId && state.positions.get(state.selectedId)) {
    const p = state.positions.get(state.selectedId);
    quickAdd.style.display = 'block';
    quickAdd.style.left = `${p.x + 42}px`;
    quickAdd.style.top = `${p.y - 22}px`;
  } else {
    quickAdd.style.display = 'none';
  }
}

function setStatus(msg) { if (statusEl) statusEl.textContent = msg; }

function openProfile(id) {
  const c = nodeById(id);
  if (!c) return;
  const modal = document.getElementById('profileModal');
  document.getElementById('profileBody').innerHTML = `
    <div style="display:flex;gap:16px;align-items:center;">
      <img src="${c.photo_url || 'https://placehold.co/120x120/1f2937/ffffff?text=?'}" alt="${c.name}">
      <div>
        <h3>${c.name}</h3>
        <p>${c.title || 'Без титула'}</p>
        <p>${fmtYears(c)}</p>
      </div>
    </div>
    <p style="margin-top:12px; color:#cbd5e1;">${c.notes || ''}</p>
  `;
  modal.style.display = 'flex';
}

function onNodeClick(id) {
  state.selectedId = id;
  if (state.mode === 'public') {
    openProfile(id);
  }
  render();
}

async function loadData() {
  const data = await api('/api/genealogy/');
  state.characters = data.characters || [];
  state.relationships = data.relationships || [];
}

function bindAdmin() {
  if (!panel) return;

  document.getElementById('createCharacterForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const payload = Object.fromEntries(new FormData(form).entries());
    ['birth_year', 'death_year'].forEach(k => { if (payload[k] === '') delete payload[k]; });
    try {
      await api('/api/genealogy/characters/', { method: 'POST', body: JSON.stringify(payload) });
      form.reset();
      await loadData();
      render();
      setStatus('Персонаж добавлен.');
      syncCharacterSelects();
    } catch (err) { setStatus(`Ошибка: ${err.message}`); }
  });

  document.getElementById('linkForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = Object.fromEntries(new FormData(e.target).entries());
    try {
      await api('/api/genealogy/relationships/', { method: 'POST', body: JSON.stringify(payload) });
      await loadData();
      render();
      setStatus('Связь создана.');
    } catch (err) { setStatus(`Ошибка: ${err.message}`); }
  });

  quickAdd.addEventListener('click', () => {
    if (!state.selectedId) return;
    const source = state.selectedId;
    document.getElementById('linkSource').value = source;
    document.getElementById('linkTarget').focus();
  });
}

function syncCharacterSelects() {
  const selects = [document.getElementById('linkSource'), document.getElementById('linkTarget')].filter(Boolean);
  selects.forEach(sel => {
    const selected = sel.value;
    sel.innerHTML = state.characters.map(c => `<option value="${c.id}">${c.name} (${c.id})</option>`).join('');
    if (selected) sel.value = selected;
  });
}

async function init() {
  state.mode = document.body.dataset.mode || 'admin';
  document.getElementById('closeProfile')?.addEventListener('click', () => {
    document.getElementById('profileModal').style.display = 'none';
  });
  document.getElementById('profileModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'profileModal') e.currentTarget.style.display = 'none';
  });

  try {
    await loadData();
    bindAdmin();
    syncCharacterSelects();
    render();
    setStatus('Данные загружены из backend API.');
  } catch (err) {
    setStatus(`Ошибка загрузки: ${err.message}`);
  }
}

init();
