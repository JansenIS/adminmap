const state = {
  mode: 'admin',
  allCharacters: [],
  allRelationships: [],
  characters: [],
  relationships: [],
  positions: new Map(),
  viewport: { minX: 0, minY: 0 },
  selectedId: null,
  selectedClan: 'all',
};

const svg = document.getElementById('tree');
const quickAdd = document.getElementById('quickAdd');
const statusEl = document.getElementById('status');
const panel = document.getElementById('adminPanel');
const clanFilter = document.getElementById('clanFilter');

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
  rels.forEach(r => {
    if (r.type === 'parent_child' && gen.has(r.target_id) && gen.has(r.source_id)) {
      const g = Math.max(gen.get(r.target_id) || 0, (gen.get(r.source_id) || 0) + 1);
      gen.set(r.target_id, g);
    }
  });

  let changed = true;
  let guard = 0;
  while (changed && guard < 20) {
    changed = false;
    guard++;
    rels.forEach(r => {
      if (r.type === 'parent_child' && gen.has(r.target_id) && gen.has(r.source_id)) {
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
    if (!gen.has(r.source_id) || !gen.has(r.target_id)) return;
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

function normalizeId(id) {
  const n = Number(id);
  return Number.isFinite(n) ? n : String(id);
}

function relKey(a, b) {
  const [x, y] = [normalizeId(a), normalizeId(b)].sort((left, right) => {
    if (typeof left === 'number' && typeof right === 'number') return left - right;
    return String(left).localeCompare(String(right));
  });
  return `${x}:${y}`;
}

function computeViewport() {
  const points = [...state.positions.values()];
  if (!points.length) {
    state.viewport = { minX: 0, minY: 0 };
    svg.setAttribute('viewBox', '0 0 1200 800');
    svg.setAttribute('width', '1200');
    svg.setAttribute('height', '800');
    return;
  }

  const minX = Math.min(...points.map(p => p.x - 120));
  const maxX = Math.max(...points.map(p => p.x + 120));
  const minY = Math.min(...points.map(p => p.y - 120));
  const maxY = Math.max(...points.map(p => p.y + 160));
  const width = Math.max(600, maxX - minX);
  const height = Math.max(500, maxY - minY);

  state.viewport = { minX, minY };
  svg.setAttribute('viewBox', `${minX} ${minY} ${width} ${height}`);
  svg.setAttribute('width', `${Math.ceil(width)}`);
  svg.setAttribute('height', `${Math.ceil(height)}`);
}

function applyClanFilter() {
  const clan = state.selectedClan;
  if (clan === 'all') {
    state.characters = [...state.allCharacters];
    state.relationships = [...state.allRelationships];
    return;
  }

  const clanMembers = state.allCharacters.filter(c => (c.clan || '').trim() === clan);
  const included = new Set(clanMembers.map(c => c.id));
  const externalSpouses = new Set();

  state.allRelationships.forEach(r => {
    if (r.type !== 'spouses') return;
    const aIn = included.has(r.source_id);
    const bIn = included.has(r.target_id);
    if (aIn && !bIn) {
      included.add(r.target_id);
      externalSpouses.add(r.target_id);
    }
    if (bIn && !aIn) {
      included.add(r.source_id);
      externalSpouses.add(r.source_id);
    }
  });

  state.allRelationships.forEach(r => {
    if (r.type === 'parent_child') {
      if (externalSpouses.has(r.source_id)) included.add(r.target_id);
      if (externalSpouses.has(r.target_id)) included.add(r.source_id);
    }
    if (r.type === 'siblings') {
      if (externalSpouses.has(r.source_id)) included.add(r.target_id);
      if (externalSpouses.has(r.target_id)) included.add(r.source_id);
    }
  });

  state.characters = state.allCharacters.filter(c => included.has(c.id));
  state.relationships = state.allRelationships.filter(r => included.has(r.source_id) && included.has(r.target_id));
  if (state.selectedId && !included.has(state.selectedId)) state.selectedId = null;
}

function syncClanFilter() {
  if (!clanFilter) return;
  const clans = [...new Set(state.allCharacters.map(c => (c.clan || '').trim()).filter(Boolean))]
    .sort((a, b) => a.localeCompare(b, 'ru'));
  const options = ['<option value="all">Все роды</option>', ...clans.map(clan => `<option value="${clan}">${clan}</option>`)];
  clanFilter.innerHTML = options.join('');
  if (state.selectedClan !== 'all' && clans.includes(state.selectedClan)) {
    clanFilter.value = state.selectedClan;
  } else {
    state.selectedClan = 'all';
    clanFilter.value = 'all';
  }
}

function render() {
  layout();
  computeViewport();
  svg.innerHTML = '';

  const spousePairs = new Set(
    state.relationships
      .filter(r => r.type === 'spouses')
      .map(r => relKey(r.source_id, r.target_id))
  );
  const groupedParentChild = new Set();
  const families = new Map();
  const parentsByChild = new Map();

  state.relationships
    .filter(r => r.type === 'parent_child')
    .forEach(r => {
      if (!parentsByChild.has(r.target_id)) parentsByChild.set(r.target_id, []);
      parentsByChild.get(r.target_id).push(r.source_id);
    });

  parentsByChild.forEach((parents, childId) => {
    if (parents.length < 2) return;
    for (let i = 0; i < parents.length; i++) {
      for (let j = i + 1; j < parents.length; j++) {
        const pairKey = relKey(parents[i], parents[j]);
        if (!spousePairs.has(pairKey)) continue;
        if (!families.has(pairKey)) {
          const [parentA, parentB] = [parents[i], parents[j]].sort((left, right) => {
            const a = normalizeId(left);
            const b = normalizeId(right);
            if (typeof a === 'number' && typeof b === 'number') return a - b;
            return String(a).localeCompare(String(b));
          });
          families.set(pairKey, {
            parentA,
            parentB,
            children: [],
          });
        }
        families.get(pairKey).children.push(childId);
        groupedParentChild.add(`${parents[i]}->${childId}`);
        groupedParentChild.add(`${parents[j]}->${childId}`);
        return;
      }
    }
  });

  state.relationships.forEach(r => {
    if (r.type === 'parent_child' && groupedParentChild.has(`${r.source_id}->${r.target_id}`)) {
      return;
    }

    const a = state.positions.get(r.source_id);
    const b = state.positions.get(r.target_id);
    if (!a || !b) return;
    const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    const isParentEdge = r.type === 'parent_child' && b.y > a.y;
    line.setAttribute('x1', a.x);
    line.setAttribute('y1', isParentEdge ? a.y + 48 : a.y);
    line.setAttribute('x2', b.x);
    line.setAttribute('y2', isParentEdge ? b.y - 48 : b.y);
    line.setAttribute('class', r.type === 'parent_child' ? 'edge-parent' : (r.type === 'siblings' ? 'edge-sibling' : 'edge-spouse'));
    svg.appendChild(line);
  });

  families.forEach((family) => {
    const p1 = state.positions.get(family.parentA);
    const p2 = state.positions.get(family.parentB);
    if (!p1 || !p2) return;

    const kids = [...new Set(family.children)]
      .map(id => ({ id, pos: state.positions.get(id) }))
      .filter(({ pos }) => !!pos)
      .sort((a, b) => a.pos.x - b.pos.x);

    if (!kids.length) return;

    const midX = (p1.x + p2.x) / 2;
    const midY = (p1.y + p2.y) / 2;
    const branchY = Math.max(midY + 40, Math.min(...kids.map(k => k.pos.y)) - 120);

    const trunk = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    trunk.setAttribute('x1', midX);
    trunk.setAttribute('y1', midY);
    trunk.setAttribute('x2', midX);
    trunk.setAttribute('y2', branchY);
    trunk.setAttribute('class', 'edge-parent');
    svg.appendChild(trunk);

    const railStartX = Math.min(midX, kids[0].pos.x);
    const railEndX = Math.max(midX, kids[kids.length - 1].pos.x);
    const rail = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    rail.setAttribute('x1', railStartX);
    rail.setAttribute('y1', branchY);
    rail.setAttribute('x2', railEndX);
    rail.setAttribute('y2', branchY);
    rail.setAttribute('class', 'edge-parent');
    svg.appendChild(rail);

    kids.forEach(({ pos }) => {
      const childBranch = document.createElementNS('http://www.w3.org/2000/svg', 'line');
      childBranch.setAttribute('x1', pos.x);
      childBranch.setAttribute('y1', branchY);
      childBranch.setAttribute('x2', pos.x);
      childBranch.setAttribute('y2', pos.y - 48);
      childBranch.setAttribute('class', 'edge-parent');
      svg.appendChild(childBranch);
    });
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
    img.setAttribute('preserveAspectRatio', 'xMidYMid slice');
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

    const clan = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    clan.setAttribute('x', p.x);
    clan.setAttribute('y', p.y + 104);
    clan.setAttribute('class', 'node-meta');
    clan.textContent = c.clan ? `Род: ${c.clan}` : 'Род: —';
    svg.appendChild(clan);

    const years = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    years.setAttribute('x', p.x);
    years.setAttribute('y', p.y + 120);
    years.setAttribute('class', 'node-meta');
    years.textContent = fmtYears(c);
    svg.appendChild(years);
  });

  if (state.mode === 'admin' && state.selectedId && state.positions.get(state.selectedId)) {
    const p = state.positions.get(state.selectedId);
    quickAdd.style.display = 'block';
    quickAdd.style.left = `${p.x - state.viewport.minX + 42}px`;
    quickAdd.style.top = `${p.y - state.viewport.minY - 22}px`;
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
        <p>Род: ${c.clan || '—'}</p>
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
  state.allCharacters = data.characters || [];
  state.allRelationships = data.relationships || [];
  applyClanFilter();
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
      syncClanFilter();
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
      syncClanFilter();
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
    sel.innerHTML = state.allCharacters.map(c => `<option value="${c.id}">${c.name} (${c.id})</option>`).join('');
    if (selected) sel.value = selected;
  });
}

function bindClanFilter() {
  if (!clanFilter) return;
  clanFilter.addEventListener('change', (e) => {
    state.selectedClan = e.target.value || 'all';
    applyClanFilter();
    render();
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
    bindClanFilter();
    syncClanFilter();
    syncCharacterSelects();
    render();
    setStatus('Данные загружены из backend API.');
  } catch (err) {
    setStatus(`Ошибка загрузки: ${err.message}`);
  }
}

init();
