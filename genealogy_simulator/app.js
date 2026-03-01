const state = {
  mode: 'admin',
  allCharacters: [],
  allRelationships: [],
  characters: [],
  relationships: [],
  positions: new Map(),
  viewport: { minX: 0, minY: 0, width: 1200, height: 800 },
  selectedId: null,
  selectedClan: 'all',
  mapPeople: [],
  camera: { zoom: 1, panX: 0, panY: 0 },
  dragging: null,
};

const svg = document.getElementById('tree');
const quickAdd = document.getElementById('quickAdd');
const statusEl = document.getElementById('status');
const panel = document.getElementById('adminPanel');
const clanFilter = document.getElementById('clanFilter');
const canvasWrap = document.querySelector('.canvas-wrap');

async function api(path, options = {}) {
  const res = await fetch(path, { headers: { 'Content-Type': 'application/json' }, ...options });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(body.error || `HTTP ${res.status}`);
  return body;
}

function resolvePhotoUrl(rawUrl, name = '') {
  const url = String(rawUrl || '').trim();
  if (!url) return `https://placehold.co/160x160/1f2937/ffffff?text=${encodeURIComponent((name || '?').slice(0, 1) || '?')}`;
  if (/^data:image\//i.test(url) || /^blob:/i.test(url) || /^\/api\/genealogy\/photo\//.test(url)) return url;
  if (/^https?:\/\//i.test(url)) {
    return `/api/genealogy/photo/?url=${encodeURIComponent(url)}&name=${encodeURIComponent(name || '')}`;
  }
  return url;
}

async function uploadPhotoSquare(file, name = '') {
  const fd = new FormData();
  fd.append('photo', file);
  if (name) fd.append('name', String(name));
  const res = await fetch('/api/genealogy/photo/upload/', { method: 'POST', body: fd });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(body.error || `HTTP ${res.status}`);
  const photoUrl = String(body.photo_url || '').trim();
  if (!photoUrl) throw new Error('empty_photo_url');
  return photoUrl;
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
  const parentsByChild = new Map();

  state.relationships
    .filter(r => r.type === 'parent_child')
    .forEach((r) => {
      if (!parentsByChild.has(r.target_id)) parentsByChild.set(r.target_id, []);
      parentsByChild.get(r.target_id).push(r.source_id);
    });

  state.characters.forEach(c => {
    const g = gen.get(c.id) || 0;
    if (!rows.has(g)) rows.set(g, []);
    rows.get(g).push(c.id);
  });

  const pos = new Map();
  const spacing = 230;
  const rowWidth = 1900;

  [...rows.entries()].sort((a, b) => a[0] - b[0]).forEach(([g, ids]) => {
    const targetXById = new Map();
    ids.forEach((id) => {
      const parentIds = parentsByChild.get(id) || [];
      const knownParents = parentIds
        .map(pid => pos.get(pid)?.x)
        .filter(x => typeof x === 'number');
      const targetX = knownParents.length
        ? knownParents.reduce((sum, x) => sum + x, 0) / knownParents.length
        : Number.POSITIVE_INFINITY;
      targetXById.set(id, targetX);
    });

    const byTarget = (left, right) => {
      const leftTargetX = targetXById.get(left) ?? Number.POSITIVE_INFINITY;
      const rightTargetX = targetXById.get(right) ?? Number.POSITIVE_INFINITY;
      if (leftTargetX !== rightTargetX) return leftTargetX - rightTargetX;

      const a = normalizeId(left);
      const b = normalizeId(right);
      if (typeof a === 'number' && typeof b === 'number') return a - b;
      return String(a).localeCompare(String(b));
    };

    const spouseAdj = new Map();
    state.relationships
      .filter(r => r.type === 'spouses')
      .forEach((r) => {
        if (!ids.includes(r.source_id) || !ids.includes(r.target_id)) return;
        if (!spouseAdj.has(r.source_id)) spouseAdj.set(r.source_id, new Set());
        if (!spouseAdj.has(r.target_id)) spouseAdj.set(r.target_id, new Set());
        spouseAdj.get(r.source_id).add(r.target_id);
        spouseAdj.get(r.target_id).add(r.source_id);
      });

    const visited = new Set();
    const blocks = [];

    ids.forEach((id) => {
      if (visited.has(id)) return;
      if (!spouseAdj.has(id)) {
        visited.add(id);
        blocks.push([id]);
        return;
      }

      const stack = [id];
      const component = [];
      while (stack.length) {
        const current = stack.pop();
        if (visited.has(current)) continue;
        visited.add(current);
        component.push(current);
        (spouseAdj.get(current) || []).forEach((next) => {
          if (!visited.has(next)) stack.push(next);
        });
      }

      const componentSorted = component.sort(byTarget);
      blocks.push(componentSorted);
    });

    blocks.sort((leftBlock, rightBlock) => {
      const leftAnchor = Math.min(...leftBlock.map(id => targetXById.get(id) ?? Number.POSITIVE_INFINITY));
      const rightAnchor = Math.min(...rightBlock.map(id => targetXById.get(id) ?? Number.POSITIVE_INFINITY));
      if (leftAnchor !== rightAnchor) return leftAnchor - rightAnchor;
      return byTarget(leftBlock[0], rightBlock[0]);
    });

    const ordered = blocks.flatMap(block => block);

    const offset = (rowWidth - (ordered.length - 1) * spacing) / 2;
    ordered.forEach((id, i) => pos.set(id, { x: offset + i * spacing, y: 130 + g * 230 }));
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
    state.viewport = { minX: 0, minY: 0, width: 1200, height: 800 };
    svg.setAttribute('width', '1200');
    svg.setAttribute('height', '800');
    applyViewBox();
    return;
  }

  const minX = Math.min(...points.map(p => p.x - 120));
  const maxX = Math.max(...points.map(p => p.x + 120));
  const minY = Math.min(...points.map(p => p.y - 120));
  const maxY = Math.max(...points.map(p => p.y + 160));
  const width = Math.max(600, maxX - minX);
  const height = Math.max(500, maxY - minY);

  state.viewport = { minX, minY, width, height };
  applyViewBox();
  svg.setAttribute('width', `${Math.ceil(width)}`);
  svg.setAttribute('height', `${Math.ceil(height)}`);
}

function applyViewBox() {
  const { minX, minY, width, height } = state.viewport;
  const zoom = state.camera.zoom || 1;
  const viewW = width / zoom;
  const viewH = height / zoom;
  const x = minX + state.camera.panX;
  const y = minY + state.camera.panY;
  svg.setAttribute('viewBox', `${x} ${y} ${viewW} ${viewH}`);
}

function clampZoom(v) { return Math.min(3, Math.max(0.4, v)); }

function worldToScreen(point) {
  const vb = svg.viewBox.baseVal;
  const rect = svg.getBoundingClientRect();
  if (!rect.width || !rect.height) return { x: 0, y: 0 };
  return {
    x: ((point.x - vb.x) / vb.width) * rect.width,
    y: ((point.y - vb.y) / vb.height) * rect.height,
  };
}


function applyClanFilter() {
  const clan = state.selectedClan;
  if (clan === 'all') {
    state.characters = [...state.allCharacters];
    state.relationships = [...state.allRelationships];
    return;
  }

  const included = new Set(
    state.allCharacters
      .filter(c => (c.clan || '').trim() === clan)
      .map(c => c.id)
  );

  if (!included.size) {
    state.characters = [];
    state.relationships = [];
    state.selectedId = null;
    return;
  }

  state.allRelationships.forEach((r) => {
    if (r.type !== 'spouses' && r.type !== 'siblings') return;
    const sourceIn = included.has(r.source_id);
    const targetIn = included.has(r.target_id);
    if (sourceIn && !targetIn) included.add(r.target_id);
    if (!sourceIn && targetIn) included.add(r.source_id);
  });

  state.characters = state.allCharacters.filter(c => included.has(c.id));
  state.relationships = state.allRelationships.filter((r) => {
    if (!included.has(r.source_id) || !included.has(r.target_id)) return false;
    const sourceInClan = (nodeByIdFromAll(r.source_id)?.clan || '').trim() === clan;
    const targetInClan = (nodeByIdFromAll(r.target_id)?.clan || '').trim() === clan;

    if (sourceInClan && targetInClan) return true;
    if (r.type === 'parent_child') return sourceInClan || targetInClan;
    return r.type === 'spouses' || r.type === 'siblings';
  });

  if (state.selectedId && !included.has(state.selectedId)) state.selectedId = null;
}

function nodeByIdFromAll(id) { return state.allCharacters.find(c => c.id === id); }

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

  const NODE_RADIUS = 48;
  const SPOUSE_RAIL_OFFSET = 16;
  const SIBLING_RAIL_OFFSET = 16;

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
    if (r.type === 'spouses' && Math.abs(a.y - b.y) < 1) {
      const spousePath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      const minX = Math.min(a.x, b.x);
      const maxX = Math.max(a.x, b.x);
      const laneY = Math.max(a.y, b.y) + NODE_RADIUS + SPOUSE_RAIL_OFFSET;
      spousePath.setAttribute('d', `M ${minX} ${a.y + NODE_RADIUS} L ${minX} ${laneY} L ${maxX} ${laneY} L ${maxX} ${b.y + NODE_RADIUS}`);
      spousePath.setAttribute('fill', 'none');
      spousePath.setAttribute('class', 'edge-spouse');
      svg.appendChild(spousePath);
      return;
    }

    if (r.type === 'siblings' && Math.abs(a.y - b.y) < 1) {
      const siblingPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      const minX = Math.min(a.x, b.x);
      const maxX = Math.max(a.x, b.x);
      const laneY = Math.min(a.y, b.y) - NODE_RADIUS - SIBLING_RAIL_OFFSET;
      siblingPath.setAttribute('d', `M ${minX} ${a.y - NODE_RADIUS} L ${minX} ${laneY} L ${maxX} ${laneY} L ${maxX} ${b.y - NODE_RADIUS}`);
      siblingPath.setAttribute('fill', 'none');
      siblingPath.setAttribute('class', 'edge-sibling');
      svg.appendChild(siblingPath);
      return;
    }

    const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    const isParentEdge = r.type === 'parent_child' && b.y > a.y;
    line.setAttribute('x1', a.x);
    line.setAttribute('y1', isParentEdge ? a.y + NODE_RADIUS : a.y);
    line.setAttribute('x2', b.x);
    line.setAttribute('y2', isParentEdge ? b.y - NODE_RADIUS : b.y);
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
    const spouseLaneY = Math.max(p1.y, p2.y) + NODE_RADIUS + SPOUSE_RAIL_OFFSET;
    const branchY = Math.max(spouseLaneY + 36, Math.min(...kids.map(k => k.pos.y)) - 120);

    const trunk = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    trunk.setAttribute('x1', midX);
    trunk.setAttribute('y1', spouseLaneY);
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
      childBranch.setAttribute('y2', pos.y - NODE_RADIUS);
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
    img.setAttribute('href', resolvePhotoUrl(c.photo_url, c.name));
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
    const screen = worldToScreen(p);
    quickAdd.style.left = `${screen.x + 42}px`;
    quickAdd.style.top = `${screen.y - 22}px`;
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
      <img src="${resolvePhotoUrl(c.photo_url, c.name)}" alt="${c.name}">
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
  if (state.mode === 'admin') {
    const assignSel = document.getElementById('assignClanCharacter');
    const assignClan = document.getElementById('assignClanName');
    const selected = nodeByIdFromAll(id);
    if (assignSel) assignSel.value = id;
    if (assignClan && selected) assignClan.value = selected.clan || '';
    syncEditCharacterForm(id);
  }
  if (state.mode === 'public') {
    openProfile(id);
  }
  render();
}


function syncEditCharacterForm(id = null) {
  const selectedId = id || state.selectedId;
  const selected = selectedId ? nodeByIdFromAll(selectedId) : null;
  const setValue = (elId, value) => {
    const el = document.getElementById(elId);
    if (!el) return;
    el.value = value ?? '';
  };

  setValue('editCharacterSelect', selected?.id || '');
  setValue('editCharacterName', selected?.name || '');
  setValue('editCharacterTitle', selected?.title || '');
  setValue('editCharacterClan', selected?.clan || '');
  setValue('editCharacterBirthYear', selected?.birth_year ?? '');
  setValue('editCharacterDeathYear', selected?.death_year ?? '');
  setValue('editCharacterPhotoUrl', selected?.photo_url || '');
  setValue('editCharacterNotes', selected?.notes || '');
}

async function loadData() {
  const data = await api('/api/genealogy/');
  state.allCharacters = data.characters || [];
  state.allRelationships = data.relationships || [];
  if (state.selectedId && !state.allCharacters.some((c) => c.id === state.selectedId)) {
    state.selectedId = null;
  }
  if (!state.selectedId && state.allCharacters.length) {
    state.selectedId = state.allCharacters[0].id;
  }
  applyClanFilter();
}

async function loadMapPeople() {
  if (state.mode !== 'admin') return;
  try {
    const data = await api('/api/genealogy/map-people/');
    state.mapPeople = Array.isArray(data.people) ? data.people : [];
  } catch (_) {
    state.mapPeople = [];
  }
}

function bindAdmin() {
  if (!panel) return;

  const createPhotoBtn = document.getElementById('createPhotoUploadBtn');
  const createPhotoFile = document.getElementById('createPhotoFile');
  const createForm = document.getElementById('createCharacterForm');
  const createPhotoInput = createForm ? createForm.querySelector('input[name="photo_url"]') : null;
  if (createPhotoBtn && createPhotoFile && createPhotoInput) {
    createPhotoBtn.addEventListener('click', () => createPhotoFile.click());
    createPhotoFile.addEventListener('change', async () => {
      const file = createPhotoFile.files && createPhotoFile.files[0];
      createPhotoFile.value = '';
      if (!file) return;
      const nameInput = createForm.querySelector('input[name="name"]');
      try {
        createPhotoBtn.disabled = true;
        createPhotoInput.value = await uploadPhotoSquare(file, nameInput ? nameInput.value : '');
        setStatus('Фото загружено на сервер с обрезкой под квадрат.');
      } catch (err) {
        setStatus(`Ошибка загрузки фото: ${err.message}`);
      } finally {
        createPhotoBtn.disabled = false;
      }
    });
  }

  const editPhotoBtn = document.getElementById('editPhotoUploadBtn');
  const editPhotoFile = document.getElementById('editPhotoFile');
  const editPhotoInput = document.getElementById('editCharacterPhotoUrl');
  if (editPhotoBtn && editPhotoFile && editPhotoInput) {
    editPhotoBtn.addEventListener('click', () => editPhotoFile.click());
    editPhotoFile.addEventListener('change', async () => {
      const file = editPhotoFile.files && editPhotoFile.files[0];
      editPhotoFile.value = '';
      if (!file) return;
      const editNameInput = document.getElementById('editCharacterName');
      try {
        editPhotoBtn.disabled = true;
        editPhotoInput.value = await uploadPhotoSquare(file, editNameInput ? editNameInput.value : '');
        setStatus('Фото загружено на сервер с обрезкой под квадрат.');
      } catch (err) {
        setStatus(`Ошибка загрузки фото: ${err.message}`);
      } finally {
        editPhotoBtn.disabled = false;
      }
    });
  }

  document.getElementById('createCharacterForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const payload = Object.fromEntries(new FormData(form).entries());
    ['birth_year', 'death_year'].forEach(k => { if (payload[k] === '') delete payload[k]; });
    try {
      await api('/api/genealogy/characters/', { method: 'POST', body: JSON.stringify(payload) });
      form.reset();
      await loadData();
      await loadMapPeople();
      syncClanFilter();
      render();
      setStatus('Персонаж добавлен.');
      syncCharacterSelects();
      syncMapCharacterSelect();
    } catch (err) { setStatus(`Ошибка: ${err.message}`); }
  });

  document.getElementById('mapAssignClanForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = Object.fromEntries(new FormData(e.target).entries());
    const mapName = (payload.name || '').trim();
    const clan = (payload.clan || '').trim();
    if (!mapName) {
      setStatus('Выберите персонажа карты.');
      return;
    }
    if (!clan) {
      setStatus('Укажите род для добавления.');
      return;
    }

    try {
      const existing = state.allCharacters.find((c) => (c.name || '').trim() === mapName);
      if (existing) {
        await api('/api/genealogy/characters/update-clan/', { method: 'PATCH', body: JSON.stringify({ id: existing.id, clan }) });
      } else {
        await api('/api/genealogy/characters/', { method: 'POST', body: JSON.stringify({ name: mapName, clan }) });
      }
      await loadData();
      await loadMapPeople();
      syncClanFilter();
      syncCharacterSelects();
      syncMapCharacterSelect();
      render();
      setStatus('Персонаж карты добавлен в род.');
    } catch (err) { setStatus(`Ошибка: ${err.message}`); }
  });

  document.getElementById('assignClanForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = Object.fromEntries(new FormData(e.target).entries());
    try {
      await api('/api/genealogy/characters/update-clan/', { method: 'PATCH', body: JSON.stringify(payload) });
      await loadData();
      syncClanFilter();
      syncCharacterSelects();
      syncMapCharacterSelect();
      render();
      setStatus('Род персонажа обновлён.');
    } catch (err) { setStatus(`Ошибка: ${err.message}`); }
  });

  document.getElementById('editCharacterSelect')?.addEventListener('change', (e) => {
    const id = e.target.value;
    if (!id) return;
    state.selectedId = id;
    syncEditCharacterForm(id);
    render();
  });

  document.getElementById('editCharacterForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const payload = Object.fromEntries(new FormData(e.target).entries());
    ['birth_year', 'death_year'].forEach((k) => {
      if (payload[k] === '') payload[k] = '';
    });
    try {
      await api('/api/genealogy/characters/update-clan/', { method: 'PATCH', body: JSON.stringify(payload) });
      state.selectedId = payload.id || state.selectedId;
      await loadData();
      await loadMapPeople();
      syncClanFilter();
      syncCharacterSelects();
      syncMapCharacterSelect();
      syncEditCharacterForm(state.selectedId);
      render();
      setStatus('Данные персонажа обновлены.');
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
  document.getElementById('deleteCharacterBtn')?.addEventListener('click', async () => {
    if (!state.selectedId) {
      setStatus('Выберите персонажа для удаления.');
      return;
    }
    const character = nodeById(state.selectedId);
    if (!character) return;
    if (!window.confirm(`Удалить персонажа «${character.name}» и все его связи?`)) return;
    try {
      await api('/api/genealogy/characters/delete/', { method: 'DELETE', body: JSON.stringify({ id: state.selectedId }) });
      state.selectedId = null;
      await loadData();
      syncClanFilter();
      syncCharacterSelects();
      syncMapCharacterSelect();
      render();
      setStatus('Персонаж удалён.');
    } catch (err) { setStatus(`Ошибка: ${err.message}`); }
  });

  document.getElementById('deleteClanBtn')?.addEventListener('click', async () => {
    const clan = state.selectedClan === 'all' ? '' : state.selectedClan;
    if (!clan) {
      setStatus('Выберите род в фильтре для удаления.');
      return;
    }
    if (!window.confirm(`Удалить весь род «${clan}» вместе с персонажами и связями?`)) return;
    try {
      const res = await api('/api/genealogy/clans/delete/', { method: 'DELETE', body: JSON.stringify({ clan }) });
      state.selectedClan = 'all';
      state.selectedId = null;
      await loadData();
      syncClanFilter();
      syncCharacterSelects();
      syncMapCharacterSelect();
      render();
      setStatus(`Род удалён. Персонажей удалено: ${res.deleted_characters ?? 0}.`);
    } catch (err) { setStatus(`Ошибка: ${err.message}`); }
  });

  document.getElementById('zoomInBtn')?.addEventListener('click', () => {
    state.camera.zoom = clampZoom(state.camera.zoom * 1.2);
    applyViewBox();
    render();
  });
  document.getElementById('zoomOutBtn')?.addEventListener('click', () => {
    state.camera.zoom = clampZoom(state.camera.zoom / 1.2);
    applyViewBox();
    render();
  });
  document.getElementById('resetViewBtn')?.addEventListener('click', () => {
    state.camera = { zoom: 1, panX: 0, panY: 0 };
    applyViewBox();
    render();
  });

}

function syncCharacterSelects() {
  const selects = [
    document.getElementById('linkSource'),
    document.getElementById('linkTarget'),
    document.getElementById('assignClanCharacter'),
    document.getElementById('editCharacterSelect'),
  ].filter(Boolean);
  selects.forEach(sel => {
    const selected = sel.value;
    sel.innerHTML = state.allCharacters.map(c => `<option value="${c.id}">${c.name} (${c.id})</option>`).join('');
    if (selected) sel.value = selected;
  });
  syncEditCharacterForm();
}

function syncMapCharacterSelect() {
  const select = document.getElementById('mapCharacterSelect');
  if (!select) return;
  const selected = select.value;
  const options = state.mapPeople.map(name => `<option value="${name}">${name}</option>`);
  select.innerHTML = options.join('');
  if (selected && state.mapPeople.includes(selected)) select.value = selected;
}

function bindClanFilter() {
  if (!clanFilter) return;
  clanFilter.addEventListener('change', (e) => {
    state.selectedClan = e.target.value || 'all';
    applyClanFilter();
    render();
  });
}


function bindViewportControls() {
  if (!canvasWrap) return;

  svg.addEventListener('wheel', (e) => {
    e.preventDefault();
    const factor = e.deltaY < 0 ? 1.1 : 0.9;
    state.camera.zoom = clampZoom(state.camera.zoom * factor);
    applyViewBox();
    render();
  }, { passive: false });

  svg.addEventListener('mousedown', (e) => {
    const tag = (e.target?.tagName || '').toLowerCase();
    if (tag && tag !== 'svg') return;
    state.dragging = { x: e.clientX, y: e.clientY, panX: state.camera.panX, panY: state.camera.panY };
    canvasWrap.classList.add('panning');
  });

  window.addEventListener('mousemove', (e) => {
    if (!state.dragging) return;
    const rect = svg.getBoundingClientRect();
    if (!rect.width || !rect.height) return;
    const vb = svg.viewBox.baseVal;
    const dx = ((e.clientX - state.dragging.x) / rect.width) * vb.width;
    const dy = ((e.clientY - state.dragging.y) / rect.height) * vb.height;
    state.camera.panX = state.dragging.panX - dx;
    state.camera.panY = state.dragging.panY - dy;
    applyViewBox();
    render();
  });

  window.addEventListener('mouseup', () => {
    state.dragging = null;
    canvasWrap.classList.remove('panning');
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
    await loadMapPeople();
    bindAdmin();
    bindClanFilter();
    bindViewportControls();
    syncClanFilter();
    syncCharacterSelects();
    syncMapCharacterSelect();
    render();
    setStatus('Данные загружены из backend API.');
  } catch (err) {
    setStatus(`Ошибка загрузки: ${err.message}`);
  }
}

init();
