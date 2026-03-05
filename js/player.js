(function(){
  const q = new URLSearchParams(location.search);
  const token = q.get('token') || '';
  const $ = (id)=>document.getElementById(id);
  const statusEl = $('status');
  const MODE_TO_FIELD = { provinces:null, kingdoms:'kingdom_id', great_houses:'great_house_id', minor_houses:'minor_house_id', free_cities:'free_city_id', special_territories:'special_territory_id' };
  const MINOR_ALPHA = { rest: 40, vassal: 100, vassal_capital: 170, domain: 160, capital: 200 };
  let session = null;
  let provinces = [];
  let realms = {};
  let map = null;
  let selectedPid = 0;
  let minorHouseByPid = new Map();
  let pendingArmies = [];
  let pendingArrierbanPlan = null;

  const armyMarkersLayer = $('armyMarkers');
  const arrierbanModal = $('arrierbanModal');
  const arrierbanTitle = $('arrierbanTitle');
  const arrierbanSubtitle = $('arrierbanSubtitle');
  const arrierbanPools = $('arrierbanPools');
  const arrierbanRemaining = $('arrierbanRemaining');
  const arrierbanRows = $('arrierbanRows');
  const arrierbanValidation = $('arrierbanValidation');
  const arrierbanClose = $('arrierbanClose');
  const arrierbanApply = $('arrierbanApply');

  function dataUriSvgToText(src){
    const s = String(src || '').trim();
    if (!s.startsWith('data:image/svg+xml')) return '';
    const commaIdx = s.indexOf(',');
    if (commaIdx < 0) return '';
    const meta = s.slice(0, commaIdx).toLowerCase();
    const body = s.slice(commaIdx + 1);
    try {
      if (meta.includes(';base64')) {
        const bin = atob(body);
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return new TextDecoder('utf-8').decode(bytes);
      }
      return decodeURIComponent(body);
    } catch (_) {
      return '';
    }
  }

  function svgToDataUri(svg){
    const v = String(svg || '').trim();
    if (!v) return '';
    if (v.startsWith('data:')) return v;
    if (/<svg[\s>]/i.test(v)) return `data:image/svg+xml;base64,${MapUtils.toBase64Utf8(v)}`;
    return v;
  }

  function normalizeSessionEmblems(){
    const decode = (value) => {
      const decoded = dataUriSvgToText(value);
      return decoded ? decoded.trim() : String(value || '').trim();
    };
    if (session && session.entity) session.entity.emblem_svg = decode(session.entity.emblem_svg);
    for (const p of (Array.isArray(provinces) ? provinces : [])) {
      if (!p) continue;
      p.emblem_svg = decode(p.emblem_svg);
    }
    for (const mode of Object.keys(realms || {})) {
      const bucket = realms[mode];
      if (!bucket || typeof bucket !== 'object') continue;
      for (const realm of Object.values(bucket)) {
        if (!realm || typeof realm !== 'object') continue;
        realm.emblem_svg = decode(realm.emblem_svg);
      }
    }
  }


  function setPreview(imgId, svg){
    const img = $(imgId); if(!img) return;
    const src = svgToDataUri(svg);
    if (!src){ img.removeAttribute('src'); img.style.display = 'none'; return; }
    img.src = src;
    img.style.display = 'block';
  }

  function setStatus(msg, bad){ statusEl.textContent = msg || ''; statusEl.style.color = bad ? '#ff9f9f' : '#9bd39f'; }
  async function jfetch(url, opts){
    const r = await fetch(url, opts);
    const d = await r.json();
    if(!r.ok || d.error){
      const err = new Error(d.error || ('HTTP '+r.status));
      if (d && typeof d === 'object') Object.assign(err, d);
      throw err;
    }
    return d;
  }
  function toRgbaFromHex(hex, a){
    const m = /^#?([0-9a-f]{6})$/i.exec(String(hex||''));
    if(!m) return [120,120,120,a];
    const n = parseInt(m[1],16);
    return [(n>>16)&255,(n>>8)&255,n&255,a];
  }

  function boostColor(rgba, boost){
    const b = Number(boost || 0);
    return [
      Math.min(255, (rgba[0] || 0) + b),
      Math.min(255, (rgba[1] || 0) + b),
      Math.min(255, (rgba[2] || 0) + b),
      rgba[3] == null ? 140 : rgba[3]
    ];
  }

  function keyForPid(pid){
    const target = Number(pid) || 0;
    if (!map || !target) return 0;
    for (const [key, meta] of map.provincesByKey.entries()) {
      if (Number(meta && meta.pid) === target) return key;
    }
    return 0;
  }

  function buildVassalPalette(baseHex) {
    const [r, g, b] = MapUtils.hexToRgb(baseHex || '#ff3b30');
    const shades = [];
    for (let i = 0; i < 10; i++) {
      const f = 0.55 + (i / 18);
      shades.push(MapUtils.rgbToHex(Math.min(255, Math.round(r * f)), Math.min(255, Math.round(g * f)), Math.min(255, Math.round(b * f))));
    }
    return shades;
  }

  function getMinorParentLayer(parentType, parentId) {
    const type = parentType === 'special_territories' ? 'special_territories' : 'great_houses';
    const realm = (realms[type] || {})[parentId];
    if (!realm) return null;
    if (!realm.minor_house_layer || typeof realm.minor_house_layer !== 'object') realm.minor_house_layer = {};
    const layer = realm.minor_house_layer;
    if (!Array.isArray(layer.domain_pids)) layer.domain_pids = [];
    if (!Array.isArray(layer.vassals)) layer.vassals = [];
    if (type === 'great_houses') {
      if (!(Number(layer.capital_pid) > 0)) layer.capital_pid = Number(realm.capital_pid || 0) || 0;
    }
    return layer;
  }

  function showProvinceInfo(p){
    $('infoName').textContent = p ? (p.name || '—') : '—';
    $('infoPid').textContent = p ? String(p.pid || '—') : '—';
    $('infoOwner').textContent = p ? (p.owner || '—') : '—';
    $('infoSuzerain').textContent = p ? (p.suzerain || '—') : '—';
    $('infoSenior').textContent = p ? (p.senior || '—') : '—';
    $('infoTerrain').textContent = p ? (p.terrain || '—') : '—';
  }

  function unitCategoryLabel(category) {
    const key = String(category || '').trim().toLowerCase();
    if (key === 'knights') return 'Рыцари';
    if (key === 'nehts') return 'Нейты';
    if (key === 'sergeants') return 'Сержанты';
    if (key === 'militia') return 'Милиция';
    return 'Прочее';
  }

  function resolveUnitCategory(unit) {
    if (!unit) return 'other';
    const source = String(unit.source || '').trim().toLowerCase();
    if (source === 'knights' || source === 'nehts' || source === 'sergeants' || source === 'militia') return source;
    const id = String(unit.unit_id || '').trim().toLowerCase();
    if (id.includes('knight')) return 'knights';
    if (id.includes('neht')) return 'nehts';
    if (id.includes('sergeant')) return 'sergeants';
    if (id.includes('militia')) return 'militia';
    return 'other';
  }

  function formatRemainingPools(pools, allocations){
    const base = pools || {};
    const used = { knights:0, nehts:0, sergeants:0, militia:0 };
    for (const row of (allocations || [])) {
      const source = String(row && row.source || '');
      if (!(source in used)) continue;
      used[source] += Math.max(0, Math.floor(Number(row && row.size) || 0));
    }
    const parts = [];
    ['knights','nehts','sergeants','militia'].forEach((k)=>{
      const left = Math.max(0, (Math.floor(Number(base[k]) || 0) - used[k]));
      parts.push(`${unitCategoryLabel(k).toLowerCase()} ${left}`);
    });
    return parts.join(', ');
  }

  function buildArrierbanDomainRow(def){
    const row = document.createElement('div');
    row.className = 'arrierban-grid';
    row.innerHTML = `<div>${def.name || def.id || 'unit'}</div><div class="arrierban-row-alloc"></div>`;
    const wrap = row.querySelector('.arrierban-row-alloc');
    const entry = document.createElement('div');
    entry.className = 'arrierban-row-alloc__entry';
    entry.innerHTML = `<input type="number" min="0" step="1" value="0" data-unit-id="${def.id}" data-source="${def.source}" data-base-size="${Math.max(1, Number(def.base_size)||1)}" /><button type="button" class="arrierban-row-alloc__remove" data-action="remove-arrierban-row">−</button>`;
    wrap.appendChild(entry);
    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'arrierban-row-alloc__add';
    addBtn.dataset.action = 'add-arrierban-row';
    addBtn.textContent = '+ отряд';
    wrap.appendChild(addBtn);
    return row;
  }

  function updateArrierbanRemaining(){
    if (!pendingArrierbanPlan || !arrierbanRows || !arrierbanRemaining) return;
    const inputs = Array.from(arrierbanRows.querySelectorAll('input[type="number"]'));
    const allocations = inputs.map((input)=>({ source: String(input.dataset.source || ''), size: Math.max(0, Math.floor(Number(input.value) || 0)) }));
    arrierbanRemaining.textContent = `Нераспределено: ${formatRemainingPools(pendingArrierbanPlan.pools, allocations)}.`;
  }

  function collectArrierbanAllocations(){
    if (!pendingArrierbanPlan || !arrierbanRows) return { units: [], error: 'Нет данных призыва.' };
    const pools = pendingArrierbanPlan.pools || {};
    const used = { knights:0, nehts:0, sergeants:0, militia:0 };
    const units = [];
    for (const input of Array.from(arrierbanRows.querySelectorAll('input[type="number"]'))) {
      const source = String(input.dataset.source || '');
      const size = Math.max(0, Math.floor(Number(input.value) || 0));
      if (!size) continue;
      if (!(source in used)) continue;
      used[source] += size;
      units.push({ source, unit_id: String(input.dataset.unitId || ''), size, base_size: Math.max(1, Math.floor(Number(input.dataset.baseSize) || 1)) });
    }
    for (const k of Object.keys(used)) {
      const cap = Math.max(0, Math.floor(Number(pools[k]) || 0));
      if (used[k] > cap) return { units: [], error: `Превышен пул ${unitCategoryLabel(k).toLowerCase()}: ${used[k]} > ${cap}.` };
    }
    return { units };
  }

  function openArrierbanModal(plan, mode){
    if (!arrierbanModal || !arrierbanRows) return;
    pendingArrierbanPlan = Object.assign({}, plan || {}, { mode: String(mode || (plan && plan.mode) || 'domain') });
    if (arrierbanTitle) arrierbanTitle.textContent = 'Арьербан';
    if (arrierbanSubtitle) arrierbanSubtitle.textContent = mode === 'royal' ? 'Королевский призыв' : (mode === 'vassal' ? 'Вассальный призыв' : 'Доменный призыв');
    if (arrierbanPools) arrierbanPools.textContent = `Пулы: рыцари ${plan.pools.knights||0}, нейты ${plan.pools.nehts||0}, сержанты ${plan.pools.sergeants||0}, милиция ${plan.pools.militia||0}.`;
    if (arrierbanValidation) arrierbanValidation.textContent = '';
    arrierbanRows.innerHTML = '';
    const categories = ['militia','sergeants','nehts','knights'];
    categories.forEach((cat)=>{
      const header = document.createElement('div');
      header.className = 'mutedSmall';
      header.style.margin = '10px 0 6px';
      header.textContent = unitCategoryLabel(cat);
      arrierbanRows.appendChild(header);
      (plan.domain_unit_defs || []).filter((d)=>String(d.source||'')===cat).forEach((def)=>{
        const row = buildArrierbanDomainRow(def);
        const input = row.querySelector('input[type="number"]');
        const found = (plan.default_units || []).find((u)=>String(u.unit_id||'')===String(def.id||''));
        if (input && found) input.value = String(Math.max(0, Math.floor(Number(found.size)||0)));
        arrierbanRows.appendChild(row);
      });
    });
    updateArrierbanRemaining();
    arrierbanModal.classList.add('open');
    arrierbanModal.setAttribute('aria-hidden','false');
  }

  function closeArrierbanModal(){
    if (!arrierbanModal) return;
    arrierbanModal.classList.remove('open');
    arrierbanModal.setAttribute('aria-hidden','true');
    pendingArrierbanPlan = null;
  }

  function clearArmyMarkers(){ if (armyMarkersLayer) armyMarkersLayer.innerHTML = ''; }
  function getArmyMarkerPointByPid(pid) {
    const key = keyForPid(pid);
    const meta = key ? map && map.getProvinceMeta(key) : null;
    if (!meta) return null;
    const centroid = Array.isArray(meta.centroid) ? meta.centroid : null;
    const x = centroid && centroid[0] != null ? Number(centroid[0]) : Number(meta.cx || 0);
    const y = centroid && centroid[1] != null ? Number(centroid[1]) : Number(meta.cy || 0);
    if (!Number.isFinite(x) || !Number.isFinite(y)) return null;
    return { x, y };
  }
  function createArmyMarker(x, y, colorHex, emblemSrc, label, isFeudal){
    if (!armyMarkersLayer) return;
    const marker = document.createElement('div');
    marker.className = `army-marker${isFeudal ? ' army-marker--feudal' : ''}`;
    marker.style.left = `${Math.round(x)}px`;
    marker.style.top = `${Math.round(y)}px`;
    marker.style.background = colorHex || '#3f6aa2';
    marker.title = label || '';
    if (emblemSrc) {
      const img = document.createElement('img');
      img.className = 'army-marker__emblem';
      img.src = emblemSrc;
      img.alt = label || 'army';
      marker.appendChild(img);
    } else {
      marker.textContent = isFeudal ? 'Ф' : 'Д';
    }
    armyMarkersLayer.appendChild(marker);
  }
  function renderArmyMarkers(){
    clearArmyMarkers();
    if (!map || !session || !session.entity) return;
    const colorHex = String(session.entity.color || '#3f6aa2');
    const emblemSrc = svgToDataUri(session.entity.emblem_svg || '');
    pendingArmies.forEach((army, idx)=>{
      const pid = Number(army && army.location_pid) || Number(army && army.muster_pid) || 0;
      const p = getArmyMarkerPointByPid(pid);
      if (!p) return;
      const isFeudal = String(army && army.army_kind || '') !== 'domain';
      const dx = isFeudal ? ((idx % 3) - 1) * 12 : 0;
      const dy = isFeudal ? Math.floor(idx / 3) * 10 : 0;
      createArmyMarker(p.x + dx, p.y + dy, colorHex, emblemSrc, `${army.army_name || army.army_id || 'Армия'}`, isFeudal);
    });
  }

  function renderArmies(){
    const sel = $('armySelect');
    const prev = sel.value;
    sel.innerHTML='';
    pendingArmies = JSON.parse(JSON.stringify(session.entity.player_armies || []));
    for(const a of pendingArmies){ const o=document.createElement('option'); o.value=a.army_id; o.textContent=`${a.army_name} (${a.size}) @PID ${a.location_pid}`; sel.appendChild(o); }
    if (prev && Array.from(sel.options).some((o)=>o.value === prev)) sel.value = prev;
    sel.onchange = renderArmyUnits;
    renderArmyUnits();
    const current = pendingArmies.reduce((sum,a)=>sum+(Number(a && a.size)||0),0);
    $('musterCap').textContent = `В поле войск: ${current}`;
  }

  function renderArmyUnits(){
    const box = $('armyUnits');
    if (!box) return;
    const armyId = $('armySelect').value;
    const army = pendingArmies.find(a => String(a.army_id) === String(armyId));
    box.innerHTML = '';
    if (!army || !Array.isArray(army.units) || !army.units.length) { box.textContent = 'Нет отрядов'; return; }
    army.units.forEach((u, idx)=>{
      const row = document.createElement('div');
      row.className = 'armyUnitRow';
      row.innerHTML = `<input type="checkbox" data-unit-idx="${idx}"><div>${u.unit_name || u.unit_id || 'unit'}</div><div>${Number(u.size)||0}</div>`;
      box.appendChild(row);
    });
  }

  function selectedArmyUnitIndexes(){
    return Array.from(document.querySelectorAll('#armyUnits input[type="checkbox"]:checked')).map((cb)=>Number(cb.dataset.unitIdx)||0).sort((a,b)=>a-b);
  }

  function normalizeArmyUnits(units){
    const map = new Map();
    for (const u of (Array.isArray(units) ? units : [])) {
      if (!u) continue;
      const id = String(u.unit_id || '').trim();
      const size = Math.max(0, Math.floor(Number(u.size) || 0));
      if (!id || size <= 0) continue;
      if (!map.has(id)) map.set(id, { source: String(u.source || ''), unit_id: id, unit_name: String(u.unit_name || id), size: 0, base_size: Math.max(1, Number(u.base_size) || 1) });
      map.get(id).size += size;
    }
    return Array.from(map.values());
  }

  function renderProvinceSelect(){
    const sel = $('provinceSelect'); sel.innerHTML='';
    for(const p of provinces.filter(x=>x.is_owned)){
      const o=document.createElement('option'); o.value=p.pid; o.textContent=`${p.pid}: ${p.name}`; sel.appendChild(o);
    }
    sel.onchange = ()=>{
      const p = provinces.find(x=>String(x.pid)===sel.value); if(!p) return;
      $('provinceDesc').value = p.wiki_description || '';
      $('provinceImage').value = p.province_card_image || '';
      $('provinceEmblem').value = p.emblem_svg || '';
      setPreview('provinceEmblemPreview', p.emblem_svg || '');
      selectedPid = Number(p.pid||0);
      showProvinceInfo(p);
    };
    if(sel.options.length) sel.dispatchEvent(new Event('change'));
  }

  function paintMap(){
    if(!map) return;
    const mode = $('viewMode').value || 'provinces';
    map.clearAllFills();
    map.clearAllEmblems();

    const drawRealmLayer = (type, opacity, emblemOpacity) => {
      const field = MODE_TO_FIELD[type];
      const bucket = realms[type] || {};
      for (const [id, realm] of Object.entries(bucket)) {
        const keys = [];
        for (const p of provinces) {
          if (!p || String(p[field] || '') !== String(id)) continue;
          const k = keyForPid(p.pid);
          if (k) keys.push(k);
        }
        if (!keys.length) continue;
        const [r, g, b] = MapUtils.hexToRgb(realm.color || '#778193');
        const cap = keyForPid(realm.capital_pid || 0);
        keys.forEach((key) => map.setFill(key, [r, g, b, key === cap ? Math.min(255, opacity + 50) : opacity]));
        const emblemSrc = svgToDataUri(realm.emblem_svg);
        if (emblemSrc) {
          const box = Array.isArray(realm.emblem_box) && realm.emblem_box.length === 2 ? { w: +realm.emblem_box[0], h: +realm.emblem_box[1] } : { w: 2000, h: 2400 };
          map.setGroupEmblem(`${type}:${id}`, keys, emblemSrc, box, { margin: 0.02, scale: Number(realm.emblem_scale) || 1, opacity: emblemOpacity });
        }
      }
    };

    if (mode === 'provinces') {
      for (const [key, meta] of map.provincesByKey.entries()) {
        const p = provinces.find(x=>Number(x.pid)===Number(meta.pid));
        if(!p) continue;
        let rgba = p.is_owned ? [95,160,255,130] : [80,90,105,95];
        if (selectedPid && Number(p.pid) === selectedPid) rgba = boostColor(rgba, 45);
        map.setFill(key, rgba);
        if (p.emblem_svg) {
          const box = Array.isArray(p.emblem_box) && p.emblem_box.length === 2 ? { w: +p.emblem_box[0], h: +p.emblem_box[1] } : null;
          map.setEmblem(key, svgToDataUri(p.emblem_svg), box, { margin: 0.12 });
        }
      }
    } else if (mode === 'minor_houses') {
      drawRealmLayer('great_houses', MINOR_ALPHA.rest, 0);
      drawRealmLayer('free_cities', 230, 0);
      for (const parentType of ['great_houses', 'special_territories']) {
        for (const [id, realm] of Object.entries(realms[parentType] || {})) {
          const baseHex = realm && realm.color ? realm.color : '#ff3b30';
          const [r, g, b] = MapUtils.hexToRgb(baseHex);
          const layer = getMinorParentLayer(parentType, id);
          if (!layer) continue;
          const capKey = parentType === 'great_houses' ? keyForPid(layer.capital_pid || 0) : 0;
          const domainKeys = new Set((layer.domain_pids || []).map((pid)=>keyForPid(pid)).filter(Boolean));
          const vassalPalette = buildVassalPalette(baseHex);
          const vassalKeys = new Set();
          for (let i = 0; i < (layer.vassals || []).length; i++) {
            const v = layer.vassals[i] || {};
            const [vr, vg, vb] = MapUtils.hexToRgb(v.color || vassalPalette[i % vassalPalette.length] || baseHex);
            for (const pid of (v.province_pids || [])) {
              const key = keyForPid(pid);
              if (!key) continue;
              vassalKeys.add(key);
              const isVC = (Number(v.capital_pid) || 0) === (Number(pid) || 0);
              map.setFill(key, [vr, vg, vb, isVC ? MINOR_ALPHA.vassal_capital : MINOR_ALPHA.vassal]);
            }
          }
          for (const p of provinces) {
            const parentId = parentType === 'great_houses' ? p.great_house_id : p.special_territory_id;
            if (String(parentId || '') !== String(id)) continue;
            const key = keyForPid(p.pid);
            if (!key || key === capKey || vassalKeys.has(key)) continue;
            if (parentType === 'great_houses' && domainKeys.has(key)) continue;
            map.setFill(key, [r, g, b, MINOR_ALPHA.rest]);
          }
          if (parentType === 'great_houses') {
            for (const key of domainKeys) {
              if (key && key !== capKey && !vassalKeys.has(key)) map.setFill(key, [r, g, b, MINOR_ALPHA.domain]);
            }
          }
          if (capKey) {
            map.setFill(capKey, [r, g, b, MINOR_ALPHA.capital]);
            const emblemSrc = svgToDataUri(realm.emblem_svg);
            if (emblemSrc) {
              const box = Array.isArray(realm.emblem_box) && realm.emblem_box.length === 2 ? { w: +realm.emblem_box[0], h: +realm.emblem_box[1] } : { w: 2000, h: 2400 };
              map.setEmblem(capKey, emblemSrc, box, { scale: Number(realm.emblem_scale) || 1 });
            }
          }
        }
      }
    } else {
      drawRealmLayer(mode, 150, 0.6);
      if (['kingdoms', 'great_houses', 'minor_houses'].includes(mode)) {
        drawRealmLayer('free_cities', 230, 0.75);
        drawRealmLayer('special_territories', 230, 0.75);
      }
    }
    renderArmyMarkers();
  }

  function rebuildMinorHouseMap(){
    minorHouseByPid = new Map();
    for (const [ghId, gh] of Object.entries(realms.great_houses || {})) {
      const layer = gh && gh.minor_house_layer;
      if (!layer || !Array.isArray(layer.vassals)) continue;
      for (const v of layer.vassals) {
        if (!v) continue;
        const vid = String(v.id || '').trim();
        if (!vid) continue;
        const pids = Array.isArray(v.province_pids) ? v.province_pids : [];
        for (const pid of pids) {
          const n = Number(pid) || 0;
          if (n > 0) minorHouseByPid.set(n, vid);
        }
      }
    }
  }

  function openForeignProvinceModal(p){
    $('modalProvinceName').textContent = p.name || 'Провинция';
    $('modalPid').textContent = String(p.pid || '—');
    $('modalOwner').textContent = p.owner || '—';
    $('modalSuzerain').textContent = p.suzerain || '—';
    $('modalSenior').textContent = p.senior || '—';
    $('modalTerrain').textContent = p.terrain || '—';
    $('modalDesc').textContent = p.wiki_description || 'Нет описания.';
    setPreview('modalEmblem', p.emblem_svg || '');
    $('foreignProvinceModal').classList.add('open');
    $('foreignProvinceModal').setAttribute('aria-hidden', 'false');
  }

  function initZoomControls(){
    const mapArea = $('mapArea');
    const mapWrap = $('mapWrap');
    const baseMap = $('baseMap');
    if (!mapArea || !mapWrap || !baseMap || !map) return;

    const MIN_ZOOM = 0.1;
    const MAX_ZOOM = 12;
    const WHEEL_FACTOR = 1.12;
    let currentScale = 1;

    function getBaseSize(){
      return [baseMap.naturalWidth || map.W || 0, baseMap.naturalHeight || map.H || 0];
    }
    function getFitScale(){
      const [W, H] = getBaseSize();
      if (!W || !H) return 1;
      const sx = mapArea.clientWidth / W;
      const sy = mapArea.clientHeight / H;
      return Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, Math.min(sx, sy)));
    }
    function setZoom(newScale, anchorClientX, anchorClientY){
      newScale = Number(newScale);
      if (!isFinite(newScale) || newScale <= 0) newScale = 1;
      newScale = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, newScale));
      const [W, H] = getBaseSize();
      if (!W || !H) return;

      const anchorX = (anchorClientX == null ? mapArea.clientWidth / 2 : anchorClientX);
      const anchorY = (anchorClientY == null ? mapArea.clientHeight / 2 : anchorClientY);
      const worldX = (mapArea.scrollLeft + anchorX) / currentScale;
      const worldY = (mapArea.scrollTop + anchorY) / currentScale;

      currentScale = newScale;
      mapWrap.style.width = Math.round(W * currentScale) + 'px';
      mapWrap.style.height = Math.round(H * currentScale) + 'px';
      mapArea.scrollLeft = Math.max(0, worldX * currentScale - anchorX);
      mapArea.scrollTop = Math.max(0, worldY * currentScale - anchorY);
    }

    document.querySelectorAll('.zoomBtn').forEach((btn)=>{
      btn.addEventListener('click', ()=>{
        const target = btn.getAttribute('data-zoom');
        if (target === 'fit') return setZoom(getFitScale());
        setZoom(target);
      });
    });

    mapArea.addEventListener('wheel', (evt)=>{
      if (!evt.deltaY) return;
      evt.preventDefault();
      const nextScale = evt.deltaY < 0 ? currentScale * WHEEL_FACTOR : currentScale / WHEEL_FACTOR;
      setZoom(nextScale, evt.clientX - mapArea.getBoundingClientRect().left, evt.clientY - mapArea.getBoundingClientRect().top);
    }, { passive:false });

    window.addEventListener('resize', ()=>{
      if (Math.abs(currentScale - getFitScale()) < 0.001) setZoom(getFitScale());
    });

    setZoom(1);
  }


  function updateMusterButtons(){
    const type = String((session && session.entity && session.entity.type) || '');
    const entityId = String((session && session.entity && session.entity.id) || '');
    const royalBtn = $('musterRoyalBtn');
    const vassalBtn = $('musterVassalBtn');
    if (vassalBtn) {
      const allowed = (type === 'great_houses' || type === 'kingdoms');
      vassalBtn.style.display = allowed ? 'block' : 'none';
    }
    let canRoyal = false;
    if (type === 'great_houses') {
      for (const kingdom of Object.values((realms && realms.kingdoms) || {})) {
        if (!kingdom) continue;
        if (String(kingdom.ruling_house_id || '').trim() === entityId) { canRoyal = true; break; }
      }
    }
    if (royalBtn) royalBtn.style.display = canRoyal ? 'block' : 'none';
  }

  async function load(){
    if(!token){ setStatus('Токен не найден в ссылке.', true); return; }
    const data = await jfetch(`/api/player/session/?token=${encodeURIComponent(token)}`);
    session = data.session;
    provinces = data.provinces || [];
    realms = data.realms || {};
    normalizeSessionEmblems();
    rebuildMinorHouseMap();
    $('entityName').textContent = `${session.entity.name} (${session.entity.type})`;
    $('treasury').textContent = session.entity.treasury_total;
    $('population').textContent = session.entity.population_total;
    $('entityDesc').value = session.entity.wiki_description || '';
    $('entityImage').value = session.entity.image_url || '';
    $('entityEmblem').value = session.entity.emblem_svg || '';
    setPreview('entityEmblemPreview', session.entity.emblem_svg || '');
    renderProvinceSelect(); renderArmies();
    updateMusterButtons();
    await initMap();
  }

  async function initMap(){
    if (!map) {
      map = new RasterProvinceMap({
        baseImgId:'baseMap', fillCanvasId:'fill', emblemCanvasId:'emblems', hoverCanvasId:'hover',
        provincesMetaUrl:'provinces.json', maskUrl:'provinces_id.png',
        onHover: ({key, evt}) => {
          const meta = map.getProvinceMeta(key || 0);
          const p = meta ? provinces.find(x=>Number(x.pid)===Number(meta.pid)) : null;
          if (p) {
            showProvinceInfo(p);
            map.setHoverHighlight(key, [255,255,255,55]);
          } else {
            map.clearHover();
          }
        },
        onClick: ({key}) => {
          const meta = map.getProvinceMeta(key || 0);
          const p = meta ? provinces.find(x=>Number(x.pid)===Number(meta.pid)) : null;
          if (!p) return;
          selectedPid = Number(p.pid || 0);
          showProvinceInfo(p);
          if (p.is_owned) {
            const sel = $('provinceSelect');
            if (sel) {
              sel.value = String(selectedPid);
              sel.dispatchEvent(new Event('change'));
            }
          } else {
            openForeignProvinceModal(p);
          }
          paintMap();
        }
      });
      await map.init();
      initZoomControls();
    }
    paintMap();
  }

  $('viewMode').addEventListener('change', paintMap);
  $('closeForeignModal').addEventListener('click', ()=>{
    $('foreignProvinceModal').classList.remove('open');
    $('foreignProvinceModal').setAttribute('aria-hidden', 'true');
  });
  $('foreignProvinceModal').addEventListener('click', (evt)=>{
    if (evt.target === $('foreignProvinceModal')) $('closeForeignModal').click();
  });

  $('entityEmblemFile').addEventListener('change', async ()=>{
    const file = $('entityEmblemFile').files && $('entityEmblemFile').files[0];
    $('entityEmblemFile').value = '';
    if (!file) return;
    const text = await file.text();
    $('entityEmblem').value = String(text || '').replace(/^\uFEFF/, '').trim();
    setPreview('entityEmblemPreview', $('entityEmblem').value);
  });

  $('provinceEmblemFile').addEventListener('change', async ()=>{
    const file = $('provinceEmblemFile').files && $('provinceEmblemFile').files[0];
    $('provinceEmblemFile').value = '';
    if (!file) return;
    const text = await file.text();
    $('provinceEmblem').value = String(text || '').replace(/^\uFEFF/, '').trim();
    setPreview('provinceEmblemPreview', $('provinceEmblem').value);
  });

  $('saveEntity').onclick = async ()=>{
    await jfetch('/api/player/session/save/', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token,entity:{wiki_description:$('entityDesc').value,image_url:$('entityImage').value,emblem_svg:$('entityEmblem').value}})});
    setStatus('Сущность сохранена.');
  };

  $('saveProvince').onclick = async ()=>{
    const pid = Number($('provinceSelect').value||0);
    await jfetch('/api/player/session/save/', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token,provinces:[{pid,wiki_description:$('provinceDesc').value,province_card_image:$('provinceImage').value,emblem_svg:$('provinceEmblem').value}]})});
    setStatus('Провинция сохранена.');
    await load();
  };

  async function doMuster(mode){
    try {
      const plan = await jfetch('/api/player/army/action/', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token,action:'muster_plan',muster_mode:mode})});
      openArrierbanModal(plan, mode);
    } catch (e) {
      setStatus(e && e.message ? e.message : 'Ошибка созыва', true);
    }
  }

  async function applyMuster(mode){
    try {
      const collected = collectArrierbanAllocations();
      if (collected.error) { if (arrierbanValidation) arrierbanValidation.textContent = collected.error; return; }
      await jfetch('/api/player/army/action/', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token,action:'muster',muster_mode:mode,army_name:$('musterName').value,muster_units:collected.units})});
      closeArrierbanModal();
      const labels = { domain:'Созвано доменное войско.', vassal:'Созваны вассалы (арьербан).', royal:'Объявлен королевский призыв.' };
      setStatus(labels[mode] || 'Созыв завершён.');
      await load();
    } catch (e) {
      setStatus(e && e.message ? e.message : 'Ошибка созыва', true);
    }
  }

  $('musterDomainBtn').onclick = async ()=>{ await doMuster('domain'); };
  $('musterVassalBtn').onclick = async ()=>{ await doMuster('vassal'); };
  $('musterRoyalBtn').onclick = async ()=>{ await doMuster('royal'); };
  if (arrierbanClose) arrierbanClose.addEventListener('click', closeArrierbanModal);
  if (arrierbanModal) arrierbanModal.addEventListener('click', (evt)=>{ if (evt.target === arrierbanModal) closeArrierbanModal(); });
  if (arrierbanRows) arrierbanRows.addEventListener('click', (evt)=>{
    const btn = evt.target && evt.target.closest ? evt.target.closest('button[data-action]') : null;
    if (!btn) return;
    const action = btn.dataset.action;
    const allocWrap = btn.closest('.arrierban-row-alloc');
    if (!allocWrap) return;
    if (action === 'add-arrierban-row') {
      const sample = allocWrap.querySelector('input[type="number"]');
      if (!sample) return;
      const entry = document.createElement('div');
      entry.className = 'arrierban-row-alloc__entry';
      entry.innerHTML = `<input type="number" min="0" step="1" value="0" data-unit-id="${sample.dataset.unitId || ''}" data-source="${sample.dataset.source || ''}" data-base-size="${sample.dataset.baseSize || '1'}" /><button type="button" class="arrierban-row-alloc__remove" data-action="remove-arrierban-row">−</button>`;
      allocWrap.insertBefore(entry, btn);
      updateArrierbanRemaining();
    }
    if (action === 'remove-arrierban-row') {
      const entry = btn.closest('.arrierban-row-alloc__entry');
      if (!entry) return;
      const entries = allocWrap.querySelectorAll('.arrierban-row-alloc__entry');
      if (entries.length <= 1) {
        const input = entry.querySelector('input[type="number"]');
        if (input) input.value = '0';
      } else {
        entry.remove();
      }
      updateArrierbanRemaining();
    }
  });
  if (arrierbanRows) arrierbanRows.addEventListener('input', (evt)=>{ if (evt.target && evt.target.matches && evt.target.matches('input[type="number"]')) updateArrierbanRemaining(); });
  if (arrierbanApply) arrierbanApply.addEventListener('click', async ()=>{
    const mode = pendingArrierbanPlan && pendingArrierbanPlan.mode ? String(pendingArrierbanPlan.mode) : 'domain';
    await applyMuster(mode);
  });
  $('moveBtn').onclick = async ()=>{
    try {
      await jfetch('/api/player/army/action/', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token,action:'move',army_id:$('armySelect').value,to_pid:Number($('movePid').value||0)})});
      setStatus('Армия перемещена.'); await load();
    } catch (e) {
      if (e && e.message === 'pid_not_owned') {
        setStatus('Нельзя перемещать армию в не принадлежащую вам провинцию.', true);
        return;
      }
      if (e && e.message === 'army_already_moved_this_turn') {
        setStatus('Эта армия уже двигалась в текущем ходу.', true);
        return;
      }
      setStatus(e && e.message ? e.message : 'Ошибка перемещения армии.', true);
      return;
    }
  };
  $('disbandBtn').onclick = async ()=>{
    await jfetch('/api/player/army/action/', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token,action:'disband',army_id:$('armySelect').value})});
    setStatus('Армия распущена.'); await load();
  };

  $('mergeBtn').onclick = ()=>{
    const army = pendingArmies.find(a => String(a.army_id) === String($('armySelect').value));
    if (!army) return;
    const indexes = selectedArmyUnitIndexes();
    if (indexes.length < 2) return setStatus('Выбери минимум два отряда для слияния.', true);
    const sourceId = String((army.units[indexes[0]] && army.units[indexes[0]].unit_id) || '');
    if (!sourceId || indexes.some((idx)=>String((army.units[idx] && army.units[idx].unit_id) || '') !== sourceId)) return setStatus('Сливать можно только одинаковые типы отрядов.', true);
    let sum = 0;
    indexes.forEach((idx)=>{ sum += Number(army.units[idx] && army.units[idx].size) || 0; });
    army.units[indexes[0]].size = sum;
    for (let i = indexes.length - 1; i >= 1; i--) army.units.splice(indexes[i], 1);
    army.units = normalizeArmyUnits(army.units);
    army.size = army.units.reduce((s,u)=>s+(Number(u.size)||0),0);
    renderArmyUnits();
    setStatus('Отряды слиты локально. Нажми «Сохранить структуру армий».');
  };

  $('splitBtn').onclick = ()=>{
    const army = pendingArmies.find(a => String(a.army_id) === String($('armySelect').value));
    if (!army) return;
    const indexes = selectedArmyUnitIndexes();
    if (indexes.length !== 1) return setStatus('Для разделения выбери ровно один отряд.', true);
    const idx = indexes[0];
    const split = Math.max(1, Math.floor(Number($('splitSize').value) || 0));
    const row = army.units[idx];
    const size = Number(row && row.size) || 0;
    if (!row || split >= size) return setStatus('Размер отделения должен быть меньше исходного отряда.', true);
    row.size = size - split;
    army.units.splice(idx + 1, 0, Object.assign({}, row, { size: split }));
    army.size = army.units.reduce((s,u)=>s+(Number(u.size)||0),0);
    renderArmyUnits();
    setStatus('Отряд разделён локально. Нажми «Сохранить структуру армий».');
  };

  $('normalizeBtn').onclick = ()=>{
    const army = pendingArmies.find(a => String(a.army_id) === String($('armySelect').value));
    if (!army) return;
    army.units = normalizeArmyUnits(army.units);
    army.size = army.units.reduce((s,u)=>s+(Number(u.size)||0),0);
    renderArmyUnits();
    setStatus('Армия нормализована локально. Нажми «Сохранить структуру армий».');
  };


  const addArmyBtn = $('addFeudalArmyBtn');
  if (addArmyBtn) addArmyBtn.onclick = ()=>{
    const next = pendingArmies.filter(a => String(a.army_kind || 'vassal') !== 'domain').length + 1;
    pendingArmies.push({ army_id: `feudal_${Date.now()}`, army_name: `Феодальная армия ${next}`, army_kind: 'vassal', muster_pid: Number(session && session.entity && session.entity.capital_pid) || 0, location_pid: Number(session && session.entity && session.entity.capital_pid) || 0, units: [], size: 0 });
    session.entity.player_armies = pendingArmies;
    renderArmies();
    $('armySelect').value = pendingArmies[pendingArmies.length - 1].army_id;
    renderArmyUnits();
    setStatus('Добавлена новая феодальная армия локально. Нажми «Сохранить структуру армий».');
  };

  const removeArmyBtn = $('removeArmyBtn');
  if (removeArmyBtn) removeArmyBtn.onclick = ()=>{
    const armyId = $('armySelect').value;
    const idx = pendingArmies.findIndex(a => String(a.army_id) === String(armyId));
    if (idx < 0) return;
    if (String(pendingArmies[idx].army_kind || '') === 'domain') return setStatus('Доменную армию удалить нельзя.', true);
    pendingArmies.splice(idx, 1);
    session.entity.player_armies = pendingArmies;
    renderArmies();
    setStatus('Армия удалена локально. Нажми «Сохранить структуру армий».');
  };

  $('saveArmyManage').onclick = async ()=>{
    await jfetch('/api/player/army/action/', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token,action:'save_armies',armies:pendingArmies})});
    setStatus('Структура армий сохранена.');
    await load();
  };

  load().catch(e=>setStatus(e.message,true));
})();
