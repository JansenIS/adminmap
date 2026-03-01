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
svg.setAttribute('preserveAspectRatio', 'xMinYMin meet');
const statusEl = document.getElementById('status');
const panel = document.getElementById('adminPanel');
const clanFilter = document.getElementById('clanFilter');
const canvasWrap = document.querySelector('.canvas-wrap');

async function api(path, options = {}) {
  const method = String(options.method || 'GET').toUpperCase();
  const fetchOptions = {
    cache: method === 'GET' ? 'no-store' : 'default',
    headers: { 'Content-Type': 'application/json' },
    ...options,
  };
  const res = await fetch(path, fetchOptions);
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
  const ids = chars.map(c => c.id);
  const gen = new Map(ids.map(id => [id, 0]));
  if (!ids.length) return gen;

  const peerEdges = rels.filter(r => (r.type === 'spouses' || r.type === 'siblings') && gen.has(r.source_id) && gen.has(r.target_id));

  // Union-Find: супруги/сиблинги должны делить одно поколение.
  const parent = new Map(ids.map(id => [id, id]));
  const rank = new Map(ids.map(id => [id, 0]));

  const find = (x) => {
    let p = parent.get(x);
    while (p !== parent.get(p)) p = parent.get(p);
    let cur = x;
    while (parent.get(cur) !== p) {
      const next = parent.get(cur);
      parent.set(cur, p);
      cur = next;
    }
    return p;
  };

  const unite = (a, b) => {
    let ra = find(a);
    let rb = find(b);
    if (ra === rb) return;
    const rka = rank.get(ra) || 0;
    const rkb = rank.get(rb) || 0;
    if (rka < rkb) [ra, rb] = [rb, ra];
    parent.set(rb, ra);
    if (rka === rkb) rank.set(ra, rka + 1);
  };

  peerEdges.forEach((r) => unite(r.source_id, r.target_id));

  const groupMembers = new Map();
  ids.forEach((id) => {
    const g = find(id);
    if (!groupMembers.has(g)) groupMembers.set(g, []);
    groupMembers.get(g).push(id);
  });

  const indegree = new Map([...groupMembers.keys()].map(g => [g, 0]));
  const out = new Map([...groupMembers.keys()].map(g => [g, new Set()]));

  rels.forEach((r) => {
    if (r.type !== 'parent_child' || !gen.has(r.source_id) || !gen.has(r.target_id)) return;
    const from = find(r.source_id);
    const to = find(r.target_id);
    if (from === to) return; // некорректная/циклическая связь внутри peer-группы
    if (out.get(from).has(to)) return;
    out.get(from).add(to);
    indegree.set(to, (indegree.get(to) || 0) + 1);
  });

  // Топологический проход по DAG групп; если остались циклы — игнорируем их ребра.
  const queue = [];
  indegree.forEach((deg, g) => { if (deg === 0) queue.push(g); });

  const gGen = new Map([...groupMembers.keys()].map(g => [g, 0]));
  const processed = new Set();

  while (queue.length) {
    const g = queue.shift();
    processed.add(g);
    const base = gGen.get(g) || 0;
    (out.get(g) || []).forEach((to) => {
      if ((gGen.get(to) || 0) < base + 1) gGen.set(to, base + 1);
      const nextDeg = (indegree.get(to) || 0) - 1;
      indegree.set(to, nextDeg);
      if (nextDeg === 0) queue.push(to);
    });
  }

  // Защита от циклов в parent_child: делаем ограниченный релакс только по непротиворечивым ребрам.
  rels.forEach((r) => {
    if (r.type !== 'parent_child' || !gen.has(r.source_id) || !gen.has(r.target_id)) return;
    const from = find(r.source_id);
    const to = find(r.target_id);
    if (from === to) return;
    if (processed.has(from) && processed.has(to)) return;
    const v = Math.max(gGen.get(to) || 0, (gGen.get(from) || 0) + 1);
    gGen.set(to, v);
  });

  groupMembers.forEach((members, g) => {
    const level = gGen.get(g) || 0;
    members.forEach((id) => gen.set(id, level));
  });

  return gen;
}

function layout() {
  const gen = computeGenerations(state.characters, state.relationships);
  const parentsByChild = new Map();
  const childrenByParent = new Map();
  const spousesById = new Map();
  const adjacency = new Map();

  const addLink = (map, key, value) => {
    if (!map.has(key)) map.set(key, new Set());
    map.get(key).add(value);
  };

  const addAdjacency = (a, b) => {
    addLink(adjacency, a, b);
    addLink(adjacency, b, a);
  };

  state.relationships.forEach((r) => {
    if (r.type === 'parent_child') {
      if ((gen.get(r.target_id) || 0) <= (gen.get(r.source_id) || 0)) return;
      addLink(parentsByChild, r.target_id, r.source_id);
      addLink(childrenByParent, r.source_id, r.target_id);
      addAdjacency(r.source_id, r.target_id);
      return;
    }

    if (r.type === 'spouses') {
      addLink(spousesById, r.source_id, r.target_id);
      addLink(spousesById, r.target_id, r.source_id);
      addAdjacency(r.source_id, r.target_id);
      return;
    }

    if (r.type === 'siblings') addAdjacency(r.source_id, r.target_id);
  });

  const spacing = 230;
  const componentGap = 280;
  const xById = new Map();

  const idSort = (left, right) => {
    const leftId = normalizeId(left);
    const rightId = normalizeId(right);
    if (typeof leftId === 'number' && typeof rightId === 'number') return leftId - rightId;
    return String(leftId).localeCompare(String(rightId));
  };

  const avg = (values) => {
    if (!values.length) return Number.POSITIVE_INFINITY;
    return values.reduce((sum, value) => sum + value, 0) / values.length;
  };

  const placeSequential = (items) => {
    if (!items.length) return;
    items[0].x = items[0].target;
    for (let i = 1; i < items.length; i++) {
      items[i].x = Math.max(items[i].target, items[i - 1].x + spacing);
    }
    for (let i = items.length - 2; i >= 0; i--) {
      items[i].x = Math.min(items[i].x, items[i + 1].x - spacing);
    }
  };

  const enforceGenerationParentBlocks = (rows, g) => {
    const ids = (rows.get(g) || []).slice();
    if (ids.length <= 1) return;

    const blocks = new Map();
    const blockMeta = new Map();

    ids.forEach((id) => {
      const parentIds = [...(parentsByChild.get(id) || [])]
        .filter(parentId => componentOf.get(parentId) === componentOf.get(id) && (normalizedGen.get(parentId) || 0) === g - 1)
        .sort(idSort);

      const key = parentIds.length ? `p:${parentIds.join('|')}` : `solo:${id}`;
      if (!blocks.has(key)) blocks.set(key, []);
      blocks.get(key).push(id);
      if (!blockMeta.has(key)) blockMeta.set(key, { parentIds });
    });

    if (blocks.size <= 1) return;

    const blockGap = spacing * 1.15;
    const orderedBlocks = [...blocks.entries()]
      .map(([key, members]) => {
        const membersSorted = members.slice().sort((a, b) => (xById.get(a) || 0) - (xById.get(b) || 0) || idSort(a, b));
        const parentIds = blockMeta.get(key)?.parentIds || [];
        const parentCenter = avg(parentIds.map(pid => xById.get(pid)).filter(x => typeof x === 'number'));
        const currentCenter = avg(membersSorted.map(mid => xById.get(mid)).filter(x => typeof x === 'number'));
        const targetCenter = Number.isFinite(parentCenter) ? parentCenter : currentCenter;
        return { key, members: membersSorted, targetCenter };
      })
      .sort((a, b) => a.targetCenter - b.targetCenter || a.key.localeCompare(b.key));

    let cursor = Number.NEGATIVE_INFINITY;
    orderedBlocks.forEach((block) => {
      const width = (block.members.length - 1) * spacing;
      const desiredLeft = block.targetCenter - width / 2;
      const left = Number.isFinite(cursor) ? Math.max(desiredLeft, cursor + blockGap) : desiredLeft;
      block.members.forEach((id, idx) => xById.set(id, left + idx * spacing));
      cursor = left + width;
    });
  };

  const enforceGenerationSpouseSubBlocks = (rows, g) => {
    const ids = (rows.get(g) || []).slice();
    if (ids.length <= 1) return;

    const groupByParents = new Map();
    ids.forEach((id) => {
      const parentIds = [...(parentsByChild.get(id) || [])]
        .filter(parentId => componentOf.get(parentId) === componentOf.get(id) && (normalizedGen.get(parentId) || 0) === g - 1)
        .sort(idSort);
      const key = parentIds.length ? `p:${parentIds.join('|')}` : `solo:${id}`;
      if (!groupByParents.has(key)) groupByParents.set(key, []);
      groupByParents.get(key).push(id);
    });

    const avgX = (members) => avg(members.map(mid => xById.get(mid)).filter(x => typeof x === 'number'));

    groupByParents.forEach((members) => {
      if (members.length <= 2) return;

      const memberSet = new Set(members);
      const visited = new Set();
      const componentsLocal = [];

      members.forEach((startId) => {
        if (visited.has(startId)) return;
        const stack = [startId];
        const component = [];
        visited.add(startId);

        while (stack.length) {
          const id = stack.pop();
          component.push(id);
          (spousesById.get(id) || new Set()).forEach((spouseId) => {
            if (!memberSet.has(spouseId) || visited.has(spouseId)) return;
            if ((normalizedGen.get(spouseId) || 0) !== g) return;
            visited.add(spouseId);
            stack.push(spouseId);
          });
        }

        componentsLocal.push(component);
      });

      if (componentsLocal.length <= 1) return;

      const componentGap = spacing * 1.1;
      const ordered = componentsLocal
        .map((component, index) => {
          const sortedMembers = component.slice().sort((a, b) => (xById.get(a) || 0) - (xById.get(b) || 0) || idSort(a, b));
          return {
            index,
            members: sortedMembers,
            center: avgX(sortedMembers),
          };
        })
        .sort((a, b) => a.center - b.center || a.index - b.index);

      const currentCenter = avgX(members);
      const totalWidth = ordered.reduce((sum, item) => sum + (item.members.length - 1) * spacing, 0) + (ordered.length - 1) * componentGap;
      let left = (Number.isFinite(currentCenter) ? currentCenter : 0) - totalWidth / 2;

      ordered.forEach((item) => {
        item.members.forEach((id, idx) => xById.set(id, left + idx * spacing));
        left += (item.members.length - 1) * spacing + componentGap;
      });
    });
  };

  const enforceStrictSpouseAdjacency = (rows, g) => {
    const ids = (rows.get(g) || []).slice();
    if (ids.length <= 1) return;

    const memberSet = new Set(ids);
    const visited = new Set();
    const spouseComponentById = new Map();
    const spouseComponents = [];

    ids.forEach((startId) => {
      if (visited.has(startId)) return;
      const stack = [startId];
      const component = [];
      visited.add(startId);

      while (stack.length) {
        const id = stack.pop();
        component.push(id);
        (spousesById.get(id) || new Set()).forEach((spouseId) => {
          if (!memberSet.has(spouseId) || visited.has(spouseId)) return;
          if ((normalizedGen.get(spouseId) || 0) !== g) return;
          visited.add(spouseId);
          stack.push(spouseId);
        });
      }

      if (component.length > 1) {
        const normalized = component.slice().sort((a, b) => (xById.get(a) || 0) - (xById.get(b) || 0) || idSort(a, b));
        spouseComponents.push(normalized);
        normalized.forEach((id) => spouseComponentById.set(id, normalized));
      }
    });

    if (!spouseComponents.length) return;

    const orderedIds = ids.slice().sort((a, b) => (xById.get(a) || 0) - (xById.get(b) || 0) || idSort(a, b));
    const blocks = [];
    const emittedIds = new Set();

    orderedIds.forEach((id) => {
      if (emittedIds.has(id)) return;
      const spouseComponent = spouseComponentById.get(id);
      if (!spouseComponent) {
        blocks.push([id]);
        emittedIds.add(id);
        return;
      }
      blocks.push(spouseComponent);
      spouseComponent.forEach(memberId => emittedIds.add(memberId));
    });

    if (blocks.length <= 1) return;

    const spouseSpacing = Math.max(130, spacing * 0.55);
    const blockGap = spacing;

    const blockWidths = blocks.map(block => {
      if (block.length <= 1) return 0;
      const localSpacing = block.length > 1 ? spouseSpacing : spacing;
      return (block.length - 1) * localSpacing;
    });
    const rowCenter = avg(ids.map(id => xById.get(id)).filter(x => typeof x === 'number'));
    const totalWidth = blockWidths.reduce((sum, width) => sum + width, 0) + (blocks.length - 1) * blockGap;
    let left = (Number.isFinite(rowCenter) ? rowCenter : 0) - totalWidth / 2;

    blocks.forEach((block, blockIdx) => {
      const localSpacing = block.length > 1 ? spouseSpacing : spacing;
      block.forEach((id, idx) => xById.set(id, left + idx * localSpacing));
      left += blockWidths[blockIdx] + blockGap;
    });
  };

  const enforceStrictSiblingBlocks = (rows, g) => {
    const ids = (rows.get(g) || []).slice();
    if (ids.length <= 1) return;

    const orderedIds = ids.slice().sort((a, b) => (xById.get(a) || 0) - (xById.get(b) || 0) || idSort(a, b));
    const blockById = new Map();

    const siblingsByParentKey = new Map();
    orderedIds.forEach((id) => {
      const parentIds = [...(parentsByChild.get(id) || [])]
        .filter(parentId => componentOf.get(parentId) === componentOf.get(id) && (normalizedGen.get(parentId) || 0) === g - 1)
        .sort(idSort);
      if (!parentIds.length) return;
      const key = `p:${parentIds.join('|')}`;
      if (!siblingsByParentKey.has(key)) siblingsByParentKey.set(key, []);
      siblingsByParentKey.get(key).push(id);
    });

    siblingsByParentKey.forEach((members) => {
      if (members.length <= 1) return;
      const normalized = members.slice().sort((a, b) => (xById.get(a) || 0) - (xById.get(b) || 0) || idSort(a, b));
      normalized.forEach((id) => blockById.set(id, normalized));
    });

    const blocks = [];
    const emitted = new Set();
    orderedIds.forEach((id) => {
      if (emitted.has(id)) return;
      const block = blockById.get(id) || [id];
      blocks.push(block);
      block.forEach((memberId) => emitted.add(memberId));
    });

    if (blocks.length <= 1) return;

    const maxChildrenByBlock = blocks.map((block) => {
      const adults = new Set(block);
      block.forEach((id) => {
        (spousesById.get(id) || new Set()).forEach((spouseId) => {
          if ((normalizedGen.get(spouseId) || 0) !== g) return;
          if (componentOf.get(spouseId) !== componentOf.get(id)) return;
          adults.add(spouseId);
        });
      });

      const children = new Set();
      adults.forEach((adultId) => {
        (childrenByParent.get(adultId) || new Set()).forEach((childId) => {
          if ((normalizedGen.get(childId) || 0) !== g + 1) return;
          if (componentOf.get(childId) !== componentOf.get(adultId)) return;
          children.add(childId);
        });
      });
      return children.size;
    });

    const rowMaxChildren = Math.max(0, ...maxChildrenByBlock);
    const descendantDrivenGap = Math.ceil((rowMaxChildren + 1) / 2) * spacing;
    const siblingGap = Math.max(spacing * 0.9, 190, descendantDrivenGap);
    const rowCenter = avg(orderedIds.map(id => xById.get(id)).filter(x => typeof x === 'number'));
    const widths = blocks.map(block => Math.max(0, (block.length - 1) * spacing));
    const totalWidth = widths.reduce((sum, width) => sum + width, 0) + (blocks.length - 1) * siblingGap;
    let left = (Number.isFinite(rowCenter) ? rowCenter : 0) - totalWidth / 2;

    blocks.forEach((block, idx) => {
      block.forEach((id, memberIdx) => xById.set(id, left + memberIdx * spacing));
      left += widths[idx] + siblingGap;
    });
  };

  const enforceFamilyBlocks = (rows, g) => {
    const adultIds = (rows.get(g) || []).slice();
    const childIds = (rows.get(g + 1) || []).slice();
    if (!adultIds.length) return;

    const adultSet = new Set(adultIds);
    const unitByAdult = new Map();
    const units = [];

    const spouseDfs = (startId) => {
      const stack = [startId];
      const seen = new Set([startId]);
      while (stack.length) {
        const id = stack.pop();
        (spousesById.get(id) || new Set()).forEach((spouseId) => {
          if (!adultSet.has(spouseId) || seen.has(spouseId)) return;
          if ((normalizedGen.get(spouseId) || 0) !== g) return;
          if (componentOf.get(spouseId) !== componentOf.get(id)) return;
          seen.add(spouseId);
          stack.push(spouseId);
        });
      }
      return [...seen];
    };

    const siblingKeyOf = (id) => {
      const parentIds = [...(parentsByChild.get(id) || [])]
        .filter(parentId => componentOf.get(parentId) === componentOf.get(id) && (normalizedGen.get(parentId) || 0) === g - 1)
        .sort(idSort);
      return parentIds.length ? `p:${parentIds.join('|')}` : `solo:${id}`;
    };

    const orderedAdults = adultIds.slice().sort((a, b) => (xById.get(a) || 0) - (xById.get(b) || 0) || idSort(a, b));
    orderedAdults.forEach((id) => {
      if (unitByAdult.has(id)) return;
      const members = spouseDfs(id).sort((a, b) => (xById.get(a) || 0) - (xById.get(b) || 0) || idSort(a, b));
      const siblingKey = siblingKeyOf(members[0]);
      const unit = {
        key: `u:${members.join('|')}`,
        siblingKey,
        members,
        center: avg(members.map(mid => xById.get(mid)).filter(x => typeof x === 'number')),
        children: [],
      };
      units.push(unit);
      members.forEach((memberId) => unitByAdult.set(memberId, unit));
    });

    if (!units.length) return;

    const groupsBySiblingKey = new Map();
    units.forEach((unit) => {
      if (!groupsBySiblingKey.has(unit.siblingKey)) groupsBySiblingKey.set(unit.siblingKey, []);
      groupsBySiblingKey.get(unit.siblingKey).push(unit);
    });

    const orderedGroups = [...groupsBySiblingKey.entries()]
      .map(([siblingKey, groupUnits]) => ({
        siblingKey,
        units: groupUnits.slice().sort((a, b) => a.center - b.center || a.key.localeCompare(b.key)),
        center: avg(groupUnits.map(unit => unit.center).filter(Number.isFinite)),
      }))
      .sort((a, b) => a.center - b.center || a.siblingKey.localeCompare(b.siblingKey));

    const spouseSpacing = Math.max(130, spacing * 0.55);
    const unitGap = Math.max(180, spacing * 0.9);
    const maxChildrenByGroup = orderedGroups.map((group) => {
      let groupMax = 0;
      group.units.forEach((unit) => {
        const childSet = new Set();
        unit.members.forEach((adultId) => {
          (childrenByParent.get(adultId) || new Set()).forEach((childId) => {
            if ((normalizedGen.get(childId) || 0) !== g + 1) return;
            if (componentOf.get(childId) !== componentOf.get(adultId)) return;
            childSet.add(childId);
          });
        });
        if (childSet.size > groupMax) groupMax = childSet.size;
      });
      return groupMax;
    });

    const siblingGap = Math.max(300, spacing * 1.6, Math.ceil((Math.max(0, ...maxChildrenByGroup) + 1) / 2) * spacing);

    const unitWidths = new Map();
    orderedGroups.forEach((group) => {
      group.units.forEach((unit) => {
        unitWidths.set(unit.key, Math.max(0, (unit.members.length - 1) * spouseSpacing));
      });
    });

    const rowCenter = avg(adultIds.map(id => xById.get(id)).filter(x => typeof x === 'number'));
    let totalWidth = 0;
    orderedGroups.forEach((group, groupIdx) => {
      group.units.forEach((unit, unitIdx) => {
        totalWidth += unitWidths.get(unit.key) || 0;
        if (unitIdx < group.units.length - 1) totalWidth += unitGap;
      });
      if (groupIdx < orderedGroups.length - 1) totalWidth += siblingGap;
    });

    let left = (Number.isFinite(rowCenter) ? rowCenter : 0) - totalWidth / 2;
    orderedGroups.forEach((group, groupIdx) => {
      group.units.forEach((unit, unitIdx) => {
        unit.members.forEach((id, idx) => xById.set(id, left + idx * spouseSpacing));
        const width = unitWidths.get(unit.key) || 0;
        const center = left + width / 2;
        unit.center = center;
        left += width;
        if (unitIdx < group.units.length - 1) left += unitGap;
      });
      if (groupIdx < orderedGroups.length - 1) left += siblingGap;
    });

    if (!childIds.length) return;

    const blocksByOwner = new Map();
    const childOrder = childIds.slice().sort((a, b) => (xById.get(a) || 0) - (xById.get(b) || 0) || idSort(a, b));
    childOrder.forEach((childId) => {
      const parentUnits = [...(parentsByChild.get(childId) || [])]
        .map(parentId => unitByAdult.get(parentId))
        .filter(Boolean);
      const owner = parentUnits.length ? parentUnits[0].key : `solo:${childId}`;
      if (!blocksByOwner.has(owner)) blocksByOwner.set(owner, []);
      blocksByOwner.get(owner).push(childId);
    });

    const childBlocks = [...blocksByOwner.entries()].map(([ownerKey, members]) => {
      const ownerUnit = units.find(unit => unit.key === ownerKey) || null;
      const siblingKey = ownerUnit ? ownerUnit.siblingKey : `solo:${ownerKey}`;
      const center = ownerUnit
        ? ownerUnit.center
        : avg(members.map(id => xById.get(id)).filter(x => typeof x === 'number'));
      return { ownerKey, siblingKey, members, center };
    });

    const childGroups = new Map();
    childBlocks.forEach((block) => {
      if (!childGroups.has(block.siblingKey)) childGroups.set(block.siblingKey, []);
      childGroups.get(block.siblingKey).push(block);
    });

    const orderedChildGroups = [...childGroups.entries()]
      .map(([siblingKey, blocks]) => ({
        siblingKey,
        blocks: blocks.slice().sort((a, b) => a.center - b.center || a.ownerKey.localeCompare(b.ownerKey)),
        center: avg(blocks.map(block => block.center).filter(Number.isFinite)),
      }))
      .sort((a, b) => a.center - b.center || a.siblingKey.localeCompare(b.siblingKey));

    const childGap = Math.max(170, spacing * 0.8);
    const childSiblingGap = Math.max(300, spacing * 1.5, Math.ceil((Math.max(0, ...maxChildrenByGroup) + 1) / 2) * spacing);
    const childWidths = new Map();

    orderedChildGroups.forEach((group) => {
      group.blocks.forEach((block) => {
        childWidths.set(block.ownerKey, Math.max(0, (block.members.length - 1) * spacing));
      });
    });

    // Дети должны оставаться под «своим» родительским юнитом.
    // Для этого сначала целимся в центр родителя, потом мягко разводим блоки,
    // чтобы они не пересекались внутри sibling-группы.
    orderedChildGroups.forEach((group) => {
      if (!group.blocks.length) return;

      const blocks = group.blocks
        .map((block, index) => {
          const width = childWidths.get(block.ownerKey) || 0;
          const targetCenter = Number.isFinite(block.center)
            ? block.center
            : avg(block.members.map(id => xById.get(id)).filter(x => typeof x === 'number'));
          return {
            ...block,
            index,
            width,
            targetCenter: Number.isFinite(targetCenter) ? targetCenter : 0,
            center: Number.isFinite(targetCenter) ? targetCenter : 0,
          };
        })
        .sort((a, b) => a.targetCenter - b.targetCenter || a.ownerKey.localeCompare(b.ownerKey));

      // forward pass: соблюдаем минимальные расстояния
      blocks.forEach((block, idx) => {
        if (idx === 0) {
          block.center = block.targetCenter;
          return;
        }
        const prev = blocks[idx - 1];
        const minCenter = prev.center + prev.width / 2 + childGap + block.width / 2;
        block.center = Math.max(block.targetCenter, minCenter);
      });

      // backward pass: возвращаем ближе к targetCenter без нарушения интервалов
      for (let idx = blocks.length - 2; idx >= 0; idx--) {
        const next = blocks[idx + 1];
        const block = blocks[idx];
        const maxCenter = next.center - next.width / 2 - childGap - block.width / 2;
        block.center = Math.min(block.center, maxCenter);
      }

      blocks.forEach((block) => {
        const left = block.center - block.width / 2;
        block.members.forEach((id, idx) => xById.set(id, left + idx * spacing));
      });
    });

    // Разводим sibling-группы между собой, сохраняя внутреннюю привязку детей к родителям.
    if (orderedChildGroups.length > 1) {
      const groupBounds = orderedChildGroups.map((group, index) => {
        const xs = group.blocks.flatMap(block => block.members.map(id => xById.get(id))).filter(x => typeof x === 'number');
        const minX = xs.length ? Math.min(...xs) : 0;
        const maxX = xs.length ? Math.max(...xs) : 0;
        return {
          index,
          minX,
          maxX,
          center: (minX + maxX) / 2,
          width: Math.max(0, maxX - minX),
        };
      });

      groupBounds.forEach((group, idx) => {
        if (idx === 0) return;
        const prev = groupBounds[idx - 1];
        const minLeft = prev.maxX + childSiblingGap;
        if (group.minX >= minLeft) return;
        const shift = minLeft - group.minX;
        orderedChildGroups[group.index].blocks.forEach((block) => {
          block.members.forEach((id) => {
            const x = xById.get(id);
            if (typeof x === 'number') xById.set(id, x + shift);
          });
        });
        group.minX += shift;
        group.maxX += shift;
      });
    }
  };

  const nodesById = new Set(state.characters.map(c => c.id));
  const componentOf = new Map();
  const components = [];

  nodesById.forEach((startId) => {
    if (componentOf.has(startId)) return;
    const stack = [startId];
    const ids = [];
    componentOf.set(startId, components.length);
    while (stack.length) {
      const id = stack.pop();
      ids.push(id);
      (adjacency.get(id) || []).forEach((neighborId) => {
        if (!nodesById.has(neighborId) || componentOf.has(neighborId)) return;
        componentOf.set(neighborId, components.length);
        stack.push(neighborId);
      });
    }
    components.push(ids);
  });

  const normalizedGen = new Map();
  components.forEach((ids, idx) => {
    const minGen = Math.min(...ids.map(id => gen.get(id) || 0));
    ids.forEach((id) => {
      normalizedGen.set(id, (gen.get(id) || 0) - minGen);
      componentOf.set(id, idx);
    });
  });

  const componentRows = components.map((ids) => {
    const rows = new Map();
    ids.forEach((id) => {
      const g = normalizedGen.get(id) || 0;
      if (!rows.has(g)) rows.set(g, []);
      rows.get(g).push(id);
    });
    return rows;
  });

  const componentMeta = components.map((ids, idx) => {
    const rows = componentRows[idx];
    const roots = ids.filter(id => (parentsByChild.get(id) || new Set()).size === 0).length;
    const rowCount = rows.size;
    const size = ids.length;
    return { idx, size, rowCount, roots };
  }).sort((a, b) => b.size - a.size || b.rowCount - a.rowCount || b.roots - a.roots || a.idx - b.idx);

  const relaxRow = (rows, g, iterations = 10) => {
    const ids = (rows.get(g) || []).slice();
    if (ids.length <= 1) return;

    for (let i = 0; i < iterations; i++) {
      const targets = ids.map((id) => {
        const parentTarget = avg([...(parentsByChild.get(id) || [])]
          .filter(parentId => componentOf.get(parentId) === componentOf.get(id))
          .map(parentId => xById.get(parentId))
          .filter(x => typeof x === 'number'));
        const childTarget = avg([...(childrenByParent.get(id) || [])]
          .filter(childId => componentOf.get(childId) === componentOf.get(id))
          .map(childId => xById.get(childId))
          .filter(x => typeof x === 'number'));
        const spouseTarget = avg([...(spousesById.get(id) || [])]
          .filter(spouseId => componentOf.get(spouseId) === componentOf.get(id) && (normalizedGen.get(spouseId) || 0) === g)
          .map(spouseId => xById.get(spouseId))
          .filter(x => typeof x === 'number'));

        const current = xById.get(id) || 0;
        const signals = [parentTarget, childTarget, spouseTarget].filter(Number.isFinite);
        if (!signals.length) return { id, target: current };
        const target = signals.reduce((sum, x) => sum + x, 0) / signals.length;
        return { id, target: current * 0.2 + target * 0.8 };
      }).sort((a, b) => a.target - b.target || idSort(a.id, b.id));

      if (!targets.length) continue;
      placeSequential(targets);

      targets.forEach(({ id, x }) => xById.set(id, x));
    }
  };

  const componentBounds = [];

  componentMeta.forEach(({ idx }) => {
    const rows = componentRows[idx];
    const rowIndex = [...rows.keys()].sort((a, b) => a - b);

    rowIndex.forEach((g) => {
      const ids = (rows.get(g) || []).slice().sort(idSort);
      if (!ids.length) return;
      const mid = (ids.length - 1) / 2;
      ids.forEach((id, i) => xById.set(id, (i - mid) * spacing));
    });

    for (let i = 0; i < 5; i++) {
      rowIndex.forEach(g => relaxRow(rows, g, 1));
      [...rowIndex].reverse().forEach(g => relaxRow(rows, g, 1));
    }

    rowIndex.forEach((g) => enforceGenerationParentBlocks(rows, g));
    rowIndex.forEach((g) => enforceGenerationSpouseSubBlocks(rows, g));
    rowIndex.forEach((g) => enforceFamilyBlocks(rows, g));
    rowIndex.forEach(g => relaxRow(rows, g, 1));
    rowIndex.forEach((g) => enforceGenerationParentBlocks(rows, g));
    rowIndex.forEach((g) => enforceGenerationSpouseSubBlocks(rows, g));
    rowIndex.forEach((g) => enforceFamilyBlocks(rows, g));
    rowIndex.forEach((g) => enforceStrictSpouseAdjacency(rows, g));
    rowIndex.forEach((g) => enforceStrictSiblingBlocks(rows, g));
    // Сиблинговые блоки могут разрывать пары, поэтому финально повторно
    // прижимаем супругов друг к другу (исключение «супруги всегда рядом»).
    rowIndex.forEach((g) => enforceStrictSpouseAdjacency(rows, g));

    const xs = components[idx].map(id => xById.get(id)).filter(x => typeof x === 'number');
    if (!xs.length) return;
    const minX = Math.min(...xs);
    const maxX = Math.max(...xs);
    componentBounds.push({ idx, minX, maxX, width: Math.max(spacing, maxX - minX) });
  });

  let cursorX = 0;
  componentBounds.forEach(({ idx, minX, width }) => {
    const shiftX = cursorX - minX;
    components[idx].forEach((id) => {
      const x = xById.get(id);
      if (typeof x === 'number') xById.set(id, x + shiftX);
    });
    cursorX += width + componentGap;
  });

  const usedWidth = Math.max(0, cursorX - componentGap);
  const globalShift = -usedWidth / 2;
  xById.forEach((x, id) => xById.set(id, x + globalShift));

  const allX = [...xById.values()];
  const minX = Math.min(...allX);
  const maxX = Math.max(...allX);
  const sceneWidth = Math.max(1400, maxX - minX + 420);
  const shiftX = (sceneWidth - (maxX - minX)) / 2 - minX;
  xById.forEach((x, id) => xById.set(id, x + shiftX));

  const pos = new Map();
  componentRows.forEach((rows) => {
    [...rows.keys()].forEach((g) => {
      (rows.get(g) || []).forEach((id) => {
        pos.set(id, { x: xById.get(id) || 0, y: 130 + g * 230 });
      });
    });
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

function computeViewport({ preserveAnchor = true } = {}) {
  const prevViewport = state.viewport;
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

  if (preserveAnchor && Number.isFinite(prevViewport?.minX) && Number.isFinite(prevViewport?.minY)) {
    state.camera.panX += (prevViewport.minX - minX);
    state.camera.panY += (prevViewport.minY - minY);
  }

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


function parseClanFilterValue(value) {
  const raw = String(value || 'all');
  if (raw === 'all') return { mode: 'all' };
  if (raw.startsWith('clan:')) return { mode: 'clan', clan: raw.slice(5) };
  if (raw.startsWith('branch:')) {
    const parts = raw.split(':');
    return { mode: 'branch', clan: parts[1] || '', founderId: parts[2] || '' };
  }
  return { mode: 'all' };
}

function getDirectChildren(parentId, clan = '') {
  const clanNorm = String(clan || '').trim();
  const children = new Set();
  state.allRelationships
    .filter((r) => r.type === 'parent_child' && r.source_id === parentId)
    .forEach((r) => {
      const child = nodeByIdFromAll(r.target_id);
      if (!child) return;
      if (clanNorm && String(child.clan || '').trim() !== clanNorm) return;
      children.add(child.id);
    });
  return children;
}

function getDescendantsWithinClan(founderId, clan = '') {
  const clanNorm = String(clan || '').trim();
  const members = new Set([founderId]);
  const queue = [founderId];
  while (queue.length) {
    const current = queue.shift();
    state.allRelationships
      .filter((r) => r.type === 'parent_child' && r.source_id === current)
      .forEach((r) => {
        const child = nodeByIdFromAll(r.target_id);
        if (!child) return;
        if (clanNorm && String(child.clan || '').trim() !== clanNorm) return;
        if (members.has(child.id)) return;
        members.add(child.id);
        queue.push(child.id);
      });
  }
  return members;
}

function getSpouses(id, clan = '', { includeExternal = false } = {}) {
  const clanNorm = String(clan || '').trim();
  const spouses = new Set();
  state.allRelationships
    .filter((r) => r.type === 'spouses' && (r.source_id === id || r.target_id === id))
    .forEach((r) => {
      const spouseId = r.source_id === id ? r.target_id : r.source_id;
      const spouse = nodeByIdFromAll(spouseId);
      if (!spouse) return;
      if (!includeExternal && clanNorm && String(spouse.clan || '').trim() !== clanNorm) return;
      spouses.add(spouseId);
    });
  return spouses;
}

function applyClanFilter() {
  const selected = parseClanFilterValue(state.selectedClan);
  if (selected.mode === 'all') {
    state.characters = [...state.allCharacters];
    state.relationships = [...state.allRelationships];
    return;
  }

  const clan = String(selected.clan || '').trim();
  if (!clan) {
    state.characters = [...state.allCharacters];
    state.relationships = [...state.allRelationships];
    return;
  }

  const clanMembers = state.allCharacters.filter((c) => String(c.clan || '').trim() === clan);
  if (!clanMembers.length) {
    state.characters = [];
    state.relationships = [];
    state.selectedId = null;
    return;
  }

  const included = new Set();

  if (selected.mode === 'branch') {
    const founderId = selected.founderId;
    const founder = nodeByIdFromAll(founderId);
    if (!founder || String(founder.clan || '').trim() !== clan) {
      state.characters = [];
      state.relationships = [];
      state.selectedId = null;
      return;
    }
    getDescendantsWithinClan(founderId, clan).forEach((id) => included.add(id));
    [...included].forEach((id) => {
      getSpouses(id, clan, { includeExternal: true }).forEach((spouseId) => included.add(spouseId));
    });
  } else {
    const sideFounders = clanMembers.filter((c) => c.clan_branch_type === 'side' && !!c.is_clan_founder);
    const sideBranchMembers = new Set();

    sideFounders.forEach((founder) => {
      getDescendantsWithinClan(founder.id, clan).forEach((id) => sideBranchMembers.add(id));
      included.add(founder.id);
      getSpouses(founder.id, clan, { includeExternal: true }).forEach((id) => included.add(id));
      getDirectChildren(founder.id, clan).forEach((id) => included.add(id));
    });

    clanMembers
      .filter((c) => !sideBranchMembers.has(c.id))
      .forEach((c) => included.add(c.id));

    [...included].forEach((id) => {
      getSpouses(id, clan, { includeExternal: true }).forEach((spouseId) => included.add(spouseId));
    });
  }

  state.allRelationships
    .filter((r) => r.type === 'parent_child' && included.has(r.target_id))
    .forEach((r) => {
      const parent = nodeByIdFromAll(r.source_id);
      const child = nodeByIdFromAll(r.target_id);
      if (!parent || !child) return;
      const parentClan = String(parent.clan || '').trim();
      const childClan = String(child.clan || '').trim();
      if (parentClan === clan || childClan === clan) included.add(r.source_id);
    });

  state.characters = state.allCharacters.filter((c) => included.has(c.id));
  state.relationships = state.allRelationships.filter((r) => included.has(r.source_id) && included.has(r.target_id));

  if (state.selectedId && !included.has(state.selectedId)) state.selectedId = null;
}

function nodeByIdFromAll(id) { return state.allCharacters.find(c => c.id === id); }

function syncClanFilter() {
  if (!clanFilter) return;
  const clans = [...new Set(state.allCharacters.map(c => (c.clan || '').trim()).filter(Boolean))]
    .sort((a, b) => a.localeCompare(b, 'ru'));

  const clanOptions = clans.map((clan) => `<option value="clan:${clan}">${clan}</option>`);
  const branchOptions = [];
  clans.forEach((clan) => {
    const founders = state.allCharacters
      .filter((c) => (c.clan || '').trim() === clan && c.clan_branch_type === 'side' && !!c.is_clan_founder)
      .sort((a, b) => String(a.name || '').localeCompare(String(b.name || ''), 'ru'));
    founders.forEach((founder) => {
      branchOptions.push(`<option value="branch:${clan}:${founder.id}">${clan} → Побочная ветвь: ${founder.name}</option>`);
    });
  });

  clanFilter.innerHTML = ['<option value="all">Все роды</option>', ...clanOptions, ...branchOptions].join('');
  const exists = [...clanFilter.options].some((opt) => opt.value === state.selectedClan);
  if (exists) {
    clanFilter.value = state.selectedClan;
  } else {
    state.selectedClan = 'all';
    clanFilter.value = 'all';
  }
}


function createQuickActionButton({ x, y, className = '', label, title, onClick }) {
  const btn = document.createElementNS('http://www.w3.org/2000/svg', 'g');
  btn.setAttribute('transform', `translate(${x}, ${y})`);
  btn.setAttribute('class', `quick-action ${className}`.trim());
  btn.style.cursor = 'pointer';
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    onClick();
  });

  const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
  circle.setAttribute('r', 13);
  circle.setAttribute('cx', 0);
  circle.setAttribute('cy', 0);
  circle.setAttribute('class', 'quick-action-circle');
  btn.appendChild(circle);

  const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
  text.setAttribute('x', 0);
  text.setAttribute('y', 4);
  text.setAttribute('class', 'quick-action-label');
  text.textContent = label;
  btn.appendChild(text);

  if (title) {
    const tip = document.createElementNS('http://www.w3.org/2000/svg', 'title');
    tip.textContent = title;
    btn.appendChild(tip);
  }

  return btn;
}

function defaultRelativePayload(name, clan = '', source = null) {
  const branchType = source?.clan_branch_type === 'side' ? 'side' : 'main';
  return {
    name,
    title: '',
    clan,
    clan_branch_type: branchType,
    is_clan_founder: false,
    birth_year: '',
    death_year: '',
    photo_url: '',
    notes: '',
  };
}

async function createRelativeFromNode(sourceId, relationType) {
  const source = nodeByIdFromAll(sourceId);
  if (!source) return;

  const relationLabelMap = {
    parent_child_parent: 'родителя',
    parent_child_child: 'ребёнка',
    spouses: 'супруга',
    siblings: 'брата/сестру',
  };

  const name = window.prompt(`Введите имя нового ${relationLabelMap[relationType] || 'родственника'}:`, '');
  if (!name || !name.trim()) return;

  const payload = defaultRelativePayload(name.trim(), source.clan || '', source);

  try {
    const created = await api('/api/genealogy/characters/', { method: 'POST', body: JSON.stringify(payload) });
    const targetId = created?.character?.id;
    if (!targetId) throw new Error('character_create_failed');

    let relationPayload = null;
    if (relationType === 'parent_child_parent') {
      relationPayload = { type: 'parent_child', source_id: targetId, target_id: sourceId };
    } else if (relationType === 'parent_child_child') {
      relationPayload = { type: 'parent_child', source_id: sourceId, target_id: targetId };
    } else if (relationType === 'spouses') {
      relationPayload = { type: 'spouses', source_id: sourceId, target_id: targetId };
    } else if (relationType === 'siblings') {
      relationPayload = { type: 'siblings', source_id: sourceId, target_id: targetId };
    }

    if (relationPayload) {
      await api('/api/genealogy/relationships/', { method: 'POST', body: JSON.stringify(relationPayload) });
    }

    state.selectedId = targetId;
    await loadData();
    syncClanFilter();
    syncCharacterSelects();
    syncMapCharacterSelect();
    render();
    setStatus(`Добавлен новый родственник: ${payload.name}.`);
  } catch (err) {
    setStatus(`Ошибка добавления родственника: ${err.message}`);
  }
}

async function createChildForRelationship(aId, bId, relationType) {
  const parentA = nodeByIdFromAll(aId);
  const parentB = nodeByIdFromAll(bId);
  const clan = parentA?.clan || parentB?.clan || '';
  const relText = relationType === 'siblings' ? 'общего брата/сестру' : 'общего ребёнка';
  const name = window.prompt(`Введите имя нового ${relText}:`, '');
  if (!name || !name.trim()) return;

  const payload = defaultRelativePayload(name.trim(), clan, parentA || parentB);

  try {
    const created = await api('/api/genealogy/characters/', { method: 'POST', body: JSON.stringify(payload) });
    const childId = created?.character?.id;
    if (!childId) throw new Error('character_create_failed');

    if (relationType === 'spouses') {
      await api('/api/genealogy/relationships/', { method: 'POST', body: JSON.stringify({ type: 'parent_child', source_id: aId, target_id: childId }) });
      await api('/api/genealogy/relationships/', { method: 'POST', body: JSON.stringify({ type: 'parent_child', source_id: bId, target_id: childId }) });
    } else {
      await api('/api/genealogy/relationships/', { method: 'POST', body: JSON.stringify({ type: 'siblings', source_id: aId, target_id: childId }) });
      await api('/api/genealogy/relationships/', { method: 'POST', body: JSON.stringify({ type: 'siblings', source_id: bId, target_id: childId }) });
    }

    state.selectedId = childId;
    await loadData();
    syncClanFilter();
    syncCharacterSelects();
    syncMapCharacterSelect();
    render();
    setStatus(`Добавлен родственник: ${payload.name}.`);
  } catch (err) {
    setStatus(`Ошибка добавления родственника: ${err.message}`);
  }
}

function render({ preserveViewportAnchor = true } = {}) {
  layout();
  computeViewport({ preserveAnchor: preserveViewportAnchor });
  svg.innerHTML = '';

  const NODE_RADIUS = 48;
  const SPOUSE_RAIL_OFFSET = 16;
  const SIBLING_RAIL_OFFSET = 16;
  const siblingPairs = state.relationships.filter(r => r.type === 'siblings').map(r => relKey(r.source_id, r.target_id));
  const siblingOrder = [...new Set(siblingPairs)].sort();
  const siblingLaneIndex = new Map(siblingOrder.map((key, idx) => [key, idx]));

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
      if (state.mode === 'admin') {
        const btn = createQuickActionButton({
          x: (minX + maxX) / 2,
          y: laneY,
          className: 'quick-child',
          label: 'C+',
          title: 'Добавить общего ребёнка в браке',
          onClick: () => createChildForRelationship(r.source_id, r.target_id, 'spouses'),
        });
        svg.appendChild(btn);
      }
      return;
    }

    if (r.type === 'siblings' && Math.abs(a.y - b.y) < 1) {
      const siblingPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      const minX = Math.min(a.x, b.x);
      const maxX = Math.max(a.x, b.x);
      const pairIndex = siblingLaneIndex.get(relKey(r.source_id, r.target_id)) || 0;
      const laneLift = SIBLING_RAIL_OFFSET + 12 + (pairIndex % 4) * 14;
      const laneY = Math.min(a.y, b.y) - NODE_RADIUS - laneLift;
      siblingPath.setAttribute('d', `M ${minX} ${a.y - NODE_RADIUS} L ${minX} ${laneY} L ${maxX} ${laneY} L ${maxX} ${b.y - NODE_RADIUS}`);
      siblingPath.setAttribute('fill', 'none');
      siblingPath.setAttribute('class', 'edge-sibling');
      svg.appendChild(siblingPath);
      if (state.mode === 'admin') {
        const btn = createQuickActionButton({
          x: (minX + maxX) / 2,
          y: laneY,
          className: 'quick-sibling',
          label: 'B+',
          title: 'Добавить общего брата/сестру',
          onClick: () => createChildForRelationship(r.source_id, r.target_id, 'siblings'),
        });
        svg.appendChild(btn);
      }
      return;
    }

    if (r.type === 'parent_child' && b.y > a.y) {
      const parentPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      const startY = a.y + NODE_RADIUS;
      const endY = b.y - NODE_RADIUS;
      const elbowY = startY + (endY - startY) * 0.45;
      parentPath.setAttribute('d', `M ${a.x} ${startY} L ${a.x} ${elbowY} L ${b.x} ${elbowY} L ${b.x} ${endY}`);
      parentPath.setAttribute('fill', 'none');
      parentPath.setAttribute('class', 'edge-parent');
      svg.appendChild(parentPath);
      return;
    }

    const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    line.setAttribute('x1', a.x);
    line.setAttribute('y1', a.y);
    line.setAttribute('x2', b.x);
    line.setAttribute('y2', b.y);
    line.setAttribute('class', r.type === 'siblings' ? 'edge-sibling' : 'edge-spouse');
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
    const minChildY = Math.min(...kids.map(k => k.pos.y));
    const minChildTopY = Math.min(...kids.map(k => k.pos.y - NODE_RADIUS));
    const branchY = Math.min(
      Math.max(spouseLaneY + 36, minChildY - 120),
      minChildTopY - 14
    );

    const trunk = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    trunk.setAttribute('x1', midX);
    trunk.setAttribute('y1', spouseLaneY);
    trunk.setAttribute('x2', midX);
    trunk.setAttribute('y2', branchY);
    trunk.setAttribute('class', 'edge-parent');
    svg.appendChild(trunk);

    const railStartX = Math.min(midX, ...kids.map(({ pos }) => pos.x));
    const railEndX = Math.max(midX, ...kids.map(({ pos }) => pos.x));
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
    const branchLabel = c.clan_branch_type === 'side' ? ' (побочная ветвь)' : '';
    const founderLabel = c.is_clan_founder ? ' • основатель' : '';
    clan.textContent = c.clan ? `Род: ${c.clan}${branchLabel}${founderLabel}` : 'Род: —';
    svg.appendChild(clan);

    const years = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    years.setAttribute('x', p.x);
    years.setAttribute('y', p.y + 120);
    years.setAttribute('class', 'node-meta');
    years.textContent = fmtYears(c);
    svg.appendChild(years);

    if (state.mode === 'admin' && state.selectedId === c.id) {
      const actions = [
        { dx: 62, dy: -26, className: 'quick-parent', label: 'P', title: 'Добавить родителя', action: () => createRelativeFromNode(c.id, 'parent_child_parent') },
        { dx: 62, dy: 0, className: 'quick-spouse', label: 'S', title: 'Добавить супруга', action: () => createRelativeFromNode(c.id, 'spouses') },
        { dx: 62, dy: 26, className: 'quick-child', label: 'C', title: 'Добавить ребёнка', action: () => createRelativeFromNode(c.id, 'parent_child_child') },
      ];
      actions.forEach((item) => {
        const btn = createQuickActionButton({
          x: p.x + item.dx,
          y: p.y + item.dy,
          className: item.className,
          label: item.label,
          title: item.title,
          onClick: item.action,
        });
        svg.appendChild(btn);
      });
    }
  });
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
  setValue('editCharacterClanBranchType', selected?.clan_branch_type || 'main');
  const founderEl = document.getElementById('editCharacterIsClanFounder');
  if (founderEl) founderEl.checked = !!selected?.is_clan_founder;
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
    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());
    payload.is_clan_founder = formData.get('is_clan_founder') === 'on';
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
    const formData = new FormData(e.target);
    const payload = Object.fromEntries(formData.entries());
    payload.is_clan_founder = formData.get('is_clan_founder') === 'on';
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
    const selected = parseClanFilterValue(state.selectedClan);
    const clan = selected.mode === 'clan' ? selected.clan : '';
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
    render({ preserveViewportAnchor: false });
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
