(function () {
  "use strict";

  const BEIGE = "#efe6d2";
  const canvas = document.getElementById("mapCanvas");
  const ctx = canvas.getContext("2d", { alpha: false });
  const info = document.getElementById("info");
  const title = document.getElementById("microTitle");
  const flagsStatusEl = document.getElementById("flagsStatus");
  const toggleLegacyModeBtn = document.getElementById("toggleLegacyMode");

  const data = window.HEXMAP;
  const [vbX, vbY, vbW, vbH] = data.viewBox;
  const vb0 = { x: vbX, y: vbY, w: vbW, h: vbH };
  let vb = { ...vb0 };
  const HEX_SIZE = data.hexSize;

  const qParityOffsets = {
    even: [[+1, 0], [+1, -1], [0, -1], [-1, -1], [-1, 0], [0, +1]],
    odd: [[+1, +1], [+1, 0], [0, -1], [-1, 0], [-1, +1], [0, +1]]
  };

  const query = new URLSearchParams(window.location.search);
  const selectedKind = query.get("kind") || "kingdom";
  const selectedId = query.get("id") || "";
  const isGreatHouseMode = selectedKind === "great_house";

  const LABELS = {
    great_house: "Большой Дом",
    free_city: "Территория",
    kingdom: "Королевство"
  };

  function isLegacyMode(flags) {
    return !(flags && flags.USE_CHUNKED_API && flags.USE_EMBLEM_ASSETS && flags.USE_PARTIAL_SAVE && flags.USE_SERVER_RENDER);
  }

  function navigateWithLegacyMode(enabled) {
    const u = new URL(window.location.href);
    const v = enabled ? "0" : "1";
    u.searchParams.set("use_chunked_api", v);
    u.searchParams.set("use_emblem_assets", v);
    u.searchParams.set("use_partial_save", v);
    u.searchParams.set("use_server_render", v);
    window.location.href = u.toString();
  }

  function updateFlagsStatus(flags) {
    const active = [];
    if (flags && flags.USE_CHUNKED_API) active.push('USE_CHUNKED_API');
    if (flags && flags.USE_EMBLEM_ASSETS) active.push('USE_EMBLEM_ASSETS');
    if (flags && flags.USE_PARTIAL_SAVE) active.push('USE_PARTIAL_SAVE');
    if (flags && flags.USE_SERVER_RENDER) active.push('USE_SERVER_RENDER');
    if (flagsStatusEl) flagsStatusEl.textContent = active.length ? ('Флаги: ' + active.join(', ')) : 'Флаги: legacy';
    if (toggleLegacyModeBtn) toggleLegacyModeBtn.textContent = isLegacyMode(flags) ? 'Вернуться в backend-режим' : 'Включить legacy-режим';
  }


  let dpr = window.devicePixelRatio || 1;
  let viewport = { scale: 1, ox: 0, oy: 0 };
  let mode = "hex";
  let isPanning = false;
  let panStart = null;
  let hoverHex = null;
  let hoverProvId = null;

  const provinceById = new Map(data.provinces.map(p => [p.id, p]));
  const realmByProvince = new Map();
  const pidRemap = new Map();
  const selectedProvincePids = new Set();
  const neighborProvincePids = new Set();
  const visibleProvincePids = new Set();
  const originalProvinceHexes = new Map();
  const effectiveProvinceHexes = new Map();
  const pathByHexId = new Map();
  const verticesByHexId = new Map();
  const coordToHex = new Map();
  const hexSpatial = new Map();
  const spatialCell = HEX_SIZE * 2.4;
  const emblemImageCache = new Map();
  const emblemImagePending = new Set();

  function spatialKey(cx, cy) {
    return `${Math.floor(cx / spatialCell)},${Math.floor(cy / spatialCell)}`;
  }

  function hexVertices(cx, cy) {
    const pts = [];
    for (let k = 0; k < 6; k++) {
      const a = (Math.PI / 180) * (60 * k);
      pts.push([cx + HEX_SIZE * Math.cos(a), cy + HEX_SIZE * Math.sin(a)]);
    }
    return pts;
  }

  for (const h of data.hexes) {
    coordToHex.set(`${h.q},${h.r}`, h);
    const verts = hexVertices(h.cx, h.cy);
    verticesByHexId.set(h.id, verts);
    const p = new Path2D();
    p.moveTo(verts[0][0], verts[0][1]);
    for (let i = 1; i < 6; i++) p.lineTo(verts[i][0], verts[i][1]);
    p.closePath();
    pathByHexId.set(h.id, p);

    const bucketKey = spatialKey(h.cx, h.cy);
    if (!hexSpatial.has(bucketKey)) hexSpatial.set(bucketKey, []);
    hexSpatial.get(bucketKey).push(h);
  }

  function getOwnerInfo(pid, state) {
    const pd = realmByProvince.get(pid) || {};
    if (isGreatHouseMode && pd.great_house_id && state.great_houses && state.great_houses[pd.great_house_id]) {
      const owner = state.great_houses[pd.great_house_id];
      return { kind: "great_house", id: pd.great_house_id, name: owner.name || pd.great_house_id, color: owner.color || "#778193", emblemSvg: owner.emblem_svg || "", emblemBox: owner.emblem_box || null, emblemScale: Number(owner.emblem_scale) || 1 };
    }
    if (pd.free_city_id && state.free_cities && state.free_cities[pd.free_city_id]) {
      const owner = state.free_cities[pd.free_city_id];
      return { kind: "free_city", id: pd.free_city_id, name: owner.name || pd.free_city_id, color: owner.color || "#778193", emblemSvg: owner.emblem_svg || "", emblemBox: owner.emblem_box || null, emblemScale: Number(owner.emblem_scale) || 1 };
    }
    if (pd.kingdom_id && state.kingdoms && state.kingdoms[pd.kingdom_id]) {
      const owner = state.kingdoms[pd.kingdom_id];
      return { kind: "kingdom", id: pd.kingdom_id, name: owner.name || pd.kingdom_id, color: owner.color || "#778193", emblemSvg: owner.emblem_svg || "", emblemBox: owner.emblem_box || null, emblemScale: Number(owner.emblem_scale) || 1 };
    }
    return { kind: "none", id: "", name: "", color: "#778193", emblemSvg: "", emblemBox: null, emblemScale: 1 };
  }

  function mutedColor(hexColor) {
    if (typeof hexColor !== "string") return "#435063";
    const m = hexColor.trim().match(/^#?([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i);
    if (!m) return hexColor;
    const bg = [16, 24, 35];
    const rgb = [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)];
    const mix = rgb.map((v, i) => Math.round(v * 0.3 + bg[i] * 0.7));
    return `rgb(${mix[0]}, ${mix[1]}, ${mix[2]})`;
  }

  function colorForPid(pid, state) {
    if (selectedProvincePids.has(pid)) return BEIGE;
    const owner = getOwnerInfo(pid, state);
    return mutedColor(owner.color);
  }


  function normalizeEmblemSource(src) {
    if (!src || typeof src !== "string") return "";
    const v = src.trim();
    if (!v) return "";
    if (/^data:image\//i.test(v) || /^blob:/i.test(v) || /^https?:/i.test(v)) return v;
    if (v.startsWith("<?xml") || v.startsWith("<svg")) return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(v)}`;
    const compact = v.replace(/\s+/g, "");
    if (/^[A-Za-z0-9+/=]+$/.test(compact) && compact.length > 64) return `data:image/svg+xml;base64,${compact}`;
    return "";
  }

  function requestEmblemImage(key, emblemSvg) {
    const source = normalizeEmblemSource(emblemSvg);
    if (!source || emblemImageCache.has(key) || emblemImagePending.has(key)) return;
    emblemImagePending.add(key);
    const img = new Image();
    img.onload = () => {
      emblemImagePending.delete(key);
      emblemImageCache.set(key, img);
      render();
    };
    img.onerror = () => {
      emblemImagePending.delete(key);
      emblemImageCache.set(key, null);
    };
    img.src = source;
  }


  function ownerLabelForPid(pid, state) {
    const pd = realmByProvince.get(pid) || {};
    if (isGreatHouseMode && pd.great_house_id) {
      const name = state?.great_houses?.[pd.great_house_id]?.name || pd.great_house_id;
      return `Большой Дом: ${name}`;
    }
    if (pd.free_city_id) {
      const name = state?.free_cities?.[pd.free_city_id]?.name || pd.free_city_id;
      return `Территория: ${name}`;
    }
    if (pd.kingdom_id) {
      const name = state?.kingdoms?.[pd.kingdom_id]?.name || pd.kingdom_id;
      return `Королевство: ${name}`;
    }
    return isGreatHouseMode ? "Большой Дом: -" : "Королевство: -";
  }

  function effectivePidFor(pid) {
    return pidRemap.get(pid) ?? pid;
  }

  function effectivePidForHex(hex) {
    return hex?.effectivePid ?? effectivePidFor(hex?.p);
  }

  function rebuildEffectiveProvinceHexes() {
    effectiveProvinceHexes.clear();
    for (const [sourcePid, list] of originalProvinceHexes.entries()) {
      const dstPid = effectivePidFor(sourcePid);
      if (!effectiveProvinceHexes.has(dstPid)) effectiveProvinceHexes.set(dstPid, []);
      const bucket = effectiveProvinceHexes.get(dstPid);
      for (const h of list) {
        h.effectivePid = dstPid;
        bucket.push(h);
      }
    }
  }


  function pointInPoly(pt, poly) {
    let c = false;
    for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
      const xi = poly[i][0], yi = poly[i][1], xj = poly[j][0], yj = poly[j][1];
      if (((yi > pt.y) !== (yj > pt.y)) && (pt.x < (xj - xi) * (pt.y - yi) / (yj - yi + 1e-9) + xi)) c = !c;
    }
    return c;
  }

  function findHexAt(world) {
    const gx = Math.floor(world.x / spatialCell);
    const gy = Math.floor(world.y / spatialCell);
    let best = null;
    let bestD = Infinity;

    for (let dx = -1; dx <= 1; dx++) {
      for (let dy = -1; dy <= 1; dy++) {
        const bucket = hexSpatial.get(`${gx + dx},${gy + dy}`);
        if (!bucket) continue;
        for (const h of bucket) {
          const d = (h.cx - world.x) ** 2 + (h.cy - world.y) ** 2;
          if (d < bestD) {
            bestD = d;
            best = h;
          }
        }
      }
    }

    if (!best) return null;
    const verts = verticesByHexId.get(best.id);
    if (verts && pointInPoly(world, verts)) return best;
    return bestD <= (HEX_SIZE * 1.35) ** 2 ? best : null;
  }

  function setInfo(hex) {
    if (!hex) {
      info.textContent = "";
      return;
    }
    const pid = effectivePidForHex(hex);
    const p = provinceById.get(pid);
    const ownerLabel = ownerLabelForPid(pid, window.__MICRO_STATE__);

    if (mode === "hex") {
      info.textContent = `Провинция: P${pid}\nГекс: #${hex.n}\nAxial: q=${hex.q}, r=${hex.r}\nGlobal ID: ${hex.id}\nSource PID: P${hex.p}\n${ownerLabel}`;
      return;
    }

    const count = effectiveProvinceHexes.get(pid)?.length || p?.hexCount || 0;
    const role = selectedProvincePids.has(pid) ? "Выбранная область" : (neighborProvincePids.has(pid) ? "Соседняя провинция" : "-");
    info.textContent = `Провинция: P${pid}\nГексов: ${count}\n${ownerLabel}\nСтатус: ${role}`;
  }

  function updateViewportTransform() {
    const w = canvas.clientWidth;
    const h = canvas.clientHeight;
    const scale = Math.min(w / vb.w, h / vb.h);
    viewport.scale = scale;
    viewport.ox = (w - vb.w * scale) * 0.5;
    viewport.oy = (h - vb.h * scale) * 0.5;
  }

  function resize() {
    const rect = canvas.getBoundingClientRect();
    dpr = window.devicePixelRatio || 1;
    canvas.width = Math.max(1, Math.floor(rect.width * dpr));
    canvas.height = Math.max(1, Math.floor(rect.height * dpr));
    updateViewportTransform();
    render();
  }

  function clientToWorld(clientX, clientY) {
    const rect = canvas.getBoundingClientRect();
    const sx = clientX - rect.left;
    const sy = clientY - rect.top;
    return {
      x: (sx - viewport.ox) / viewport.scale + vb.x,
      y: (sy - viewport.oy) / viewport.scale + vb.y
    };
  }

  function fitViewToVisibleHexes() {
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    for (const pid of visibleProvincePids) {
      const list = effectiveProvinceHexes.get(pid) || [];
      for (const h of list) {
        minX = Math.min(minX, h.cx - HEX_SIZE * 1.2);
        minY = Math.min(minY, h.cy - HEX_SIZE * 1.2);
        maxX = Math.max(maxX, h.cx + HEX_SIZE * 1.2);
        maxY = Math.max(maxY, h.cy + HEX_SIZE * 1.2);
      }
    }
    if (!isFinite(minX)) {
      vb = { ...vb0 };
      return;
    }
    const pad = 3;
    vb = {
      x: Math.max(vb0.x, minX - pad),
      y: Math.max(vb0.y, minY - pad),
      w: Math.min(vb0.w, (maxX - minX) + pad * 2),
      h: Math.min(vb0.h, (maxY - minY) + pad * 2)
    };
  }


  function drawMutedOwnerEmblems(state) {
    const groups = new Map();

    for (const pid of visibleProvincePids) {
      if (selectedProvincePids.has(pid)) continue;
      const owner = getOwnerInfo(pid, state);
      if (owner.kind === "none" || !owner.emblemSvg) continue;
      const key = `${owner.kind}:${owner.id}`;
      if (!groups.has(key)) groups.set(key, { owner, hexes: [] });
      groups.get(key).hexes.push(...(effectiveProvinceHexes.get(pid) || []));
    }

    for (const [key, group] of groups.entries()) {
      const { owner, hexes } = group;
      if (!hexes.length) continue;

      requestEmblemImage(key, owner.emblemSvg);
      const img = emblemImageCache.get(key);

      let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
      ctx.save();
      ctx.beginPath();
      let pathStarted = false;
      for (const h of hexes) {
        const verts = verticesByHexId.get(h.id);
        if (!verts || !verts.length) continue;
        ctx.moveTo(verts[0][0], verts[0][1]);
        for (let i = 1; i < verts.length; i++) ctx.lineTo(verts[i][0], verts[i][1]);
        ctx.closePath();
        pathStarted = true;
        minX = Math.min(minX, h.cx - HEX_SIZE * 1.05);
        minY = Math.min(minY, h.cy - HEX_SIZE * 1.05);
        maxX = Math.max(maxX, h.cx + HEX_SIZE * 1.05);
        maxY = Math.max(maxY, h.cy + HEX_SIZE * 1.05);
      }
      if (!pathStarted || !isFinite(minX) || maxX <= minX || maxY <= minY) {
        ctx.restore();
        continue;
      }

      const bw = maxX - minX;
      const bh = maxY - minY;
      const boxW = Number(owner.emblemBox?.[0]) || Number(owner.emblemBox?.w) || Math.max(1, img?.width || 1);
      const boxH = Number(owner.emblemBox?.[1]) || Number(owner.emblemBox?.h) || Math.max(1, img?.height || 1);
      const fitScale = Math.max(bw / boxW, bh / boxH) * Math.max(0.2, Math.min(3, owner.emblemScale || 1));
      const dw = boxW * fitScale;
      const dh = boxH * fitScale;
      const dx = minX + (bw - dw) * 0.5;
      const dy = minY + (bh - dh) * 0.5;

      if (!img) {
        ctx.restore();
        continue;
      }
      ctx.clip();
      ctx.globalAlpha = 0.72;
      ctx.drawImage(img, dx, dy, dw, dh);
      ctx.restore();
    }
  }


  function collectNeighborProvincePids() {
    for (const pid of selectedProvincePids) {
      const list = effectiveProvinceHexes.get(pid) || [];
      for (const h of list) {
        const offsets = (h.q % 2 === 0) ? qParityOffsets.even : qParityOffsets.odd;
        for (const [dq, dr] of offsets) {
          const nh = coordToHex.get(`${h.q + dq},${h.r + dr}`);
          if (!nh) continue;
          const npid = effectivePidForHex(nh);
          if (npid === pid) continue;
          if (!selectedProvincePids.has(npid)) neighborProvincePids.add(npid);
        }
      }
    }
  }

  function edgeKey(ax, ay, bx, by) {
    // Lower quantization precision prevents false "different edge" splits
    // from tiny floating-point drifts in vertex coordinates.
    const scale = 10;
    const a = `${Math.round(ax * scale)},${Math.round(ay * scale)}`;
    const b = `${Math.round(bx * scale)},${Math.round(by * scale)}`;
    return a < b ? `${a}|${b}` : `${b}|${a}`;
  }

  function collectProvinceEdges(hexes) {
    const edgeStats = new Map();
    for (const h of hexes) {
      const verts = verticesByHexId.get(h.id);
      if (!verts || verts.length < 6) continue;
      for (let i = 0; i < 6; i++) {
        const a = verts[i];
        const b = verts[(i + 1) % 6];
        const key = edgeKey(a[0], a[1], b[0], b[1]);
        const rec = edgeStats.get(key);
        if (rec) {
          rec.count += 1;
        } else {
          edgeStats.set(key, { count: 1, a, b });
        }
      }
    }

    const internalEdges = [];
    const externalEdges = [];
    for (const rec of edgeStats.values()) {
      if (rec.count > 1) internalEdges.push(rec);
      else externalEdges.push(rec);
    }
    return { internalEdges, externalEdges };
  }

  function collectProvinceBoundaryEdges(pid) {
    const list = effectiveProvinceHexes.get(pid) || [];
    if (!list.length) return [];

    const edgeStats = new Map();
    for (const h of list) {
      const verts = verticesByHexId.get(h.id);
      if (!verts || verts.length < 6) continue;
      for (let i = 0; i < 6; i++) {
        const a = verts[i];
        const b = verts[(i + 1) % 6];
        const key = edgeKey(a[0], a[1], b[0], b[1]);
        const rec = edgeStats.get(key);
        if (rec) rec.count += 1;
        else edgeStats.set(key, { count: 1, a, b });
      }
    }

    const boundary = [];
    for (const rec of edgeStats.values()) {
      if (rec.count === 1) boundary.push(rec);
    }
    return boundary;
  }

  function render() {
    const w = canvas.clientWidth;
    const h = canvas.clientHeight;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);
    ctx.fillStyle = "#101823";
    ctx.fillRect(0, 0, w, h);

    updateViewportTransform();

    ctx.save();
    ctx.beginPath();
    ctx.rect(viewport.ox, viewport.oy, vb.w * viewport.scale, vb.h * viewport.scale);
    ctx.clip();
    ctx.translate(viewport.ox - vb.x * viewport.scale, viewport.oy - vb.y * viewport.scale);
    ctx.scale(viewport.scale, viewport.scale);

    for (const pid of visibleProvincePids) {
      const fill = colorForPid(pid, window.__MICRO_STATE__);
      const list = effectiveProvinceHexes.get(pid) || [];
      for (const h of list) {
        const path = pathByHexId.get(h.id);
        ctx.fillStyle = fill;
        ctx.fill(path);
      }
    }

    for (const pid of visibleProvincePids) {
      const list = effectiveProvinceHexes.get(pid) || [];
      const { internalEdges } = collectProvinceEdges(list);
      const externalEdges = collectProvinceBoundaryEdges(pid);

      ctx.strokeStyle = "rgba(0,0,0,0.38)";
      ctx.lineWidth = 0.12;
      ctx.beginPath();
      for (const edge of internalEdges) {
        ctx.moveTo(edge.a[0], edge.a[1]);
        ctx.lineTo(edge.b[0], edge.b[1]);
      }
      ctx.stroke();

      ctx.strokeStyle = "rgba(0,0,0,0.72)";
      ctx.lineWidth = 0.38;
      ctx.beginPath();
      for (const edge of externalEdges) {
        ctx.moveTo(edge.a[0], edge.a[1]);
        ctx.lineTo(edge.b[0], edge.b[1]);
      }
      ctx.stroke();
    }

    drawMutedOwnerEmblems(window.__MICRO_STATE__);

    if (mode === "province" && hoverProvId && visibleProvincePids.has(hoverProvId)) {
      const boundaryEdges = collectProvinceBoundaryEdges(hoverProvId);
      if (boundaryEdges.length) {
        ctx.strokeStyle = "rgba(255,255,255,0.95)";
        ctx.lineWidth = 0.45;
        ctx.beginPath();
        for (const edge of boundaryEdges) {
          ctx.moveTo(edge.a[0], edge.a[1]);
          ctx.lineTo(edge.b[0], edge.b[1]);
        }
        ctx.stroke();
      }
    } else if (hoverHex && visibleProvincePids.has(effectivePidForHex(hoverHex))) {
      const path = pathByHexId.get(hoverHex.id);
      ctx.strokeStyle = "rgba(255,255,255,0.95)";
      ctx.lineWidth = 0.6;
      ctx.stroke(path);
    }

    ctx.restore();
  }

  async function main() {
    const pidRemapResp = await fetch("data/hexmap_pid_remap.json", { cache: "no-store" });
    if (pidRemapResp.ok) {
      const remapPayload = await pidRemapResp.json();
      const remapObj = remapPayload?.pid_remap || remapPayload;
      for (const [srcRaw, dstRaw] of Object.entries(remapObj || {})) {
        const src = Number(srcRaw);
        const dst = Number(dstRaw);
        if (src > 0 && dst > 0 && src !== dst) pidRemap.set(src, dst);
      }
    }

    const loader = window.AdminMapStateLoader;
    const loaded = loader
      ? await loader.loadState("data/map_state.json")
      : { state: await (await fetch("data/map_state.json", { cache: "no-store" })).json(), flags: {} };
    const state = loaded.state || {};
    window.__MICRO_STATE__ = state;
    updateFlagsStatus(loaded.flags || {});
    if (toggleLegacyModeBtn) {
      toggleLegacyModeBtn.onclick = () => navigateWithLegacyMode(!isLegacyMode(loaded.flags || {}));
    }

    const provRecords = (Array.isArray(data.provOffsets) && typeof data.provOffsets[0] === "number")
      ? data.provinces.map((p, i) => ({ id: p.id, start: data.provOffsets[i], count: data.provOffsets[i + 1] - data.provOffsets[i] }))
      : data.provOffsets;

    originalProvinceHexes.clear();
    for (const po of provRecords) {
      const list = [];
      for (let i = 0; i < po.count; i++) {
        const sourceHex = data.hexes[po.start + i];
        list.push({ ...sourceHex, p: po.id, effectivePid: po.id });
      }
      originalProvinceHexes.set(po.id, list);
    }
    rebuildEffectiveProvinceHexes();

    realmByProvince.clear();
    for (const [pidRaw, pd] of Object.entries(state.provinces || {})) {
      const pid = Number(pidRaw);
      if (pid > 0) realmByProvince.set(pid, pd || {});
    }
    for (const [pidRaw, pd] of Object.entries(state.provinces || {})) {
      const sourcePid = Number(pidRaw);
      if (!(sourcePid > 0)) continue;
      const effectivePid = effectivePidFor(sourcePid);
      if (!realmByProvince.has(effectivePid)) realmByProvince.set(effectivePid, pd || {});
    }

    for (const pid of effectiveProvinceHexes.keys()) {
      const pd = realmByProvince.get(pid);
      if (!pd) continue;
      if (selectedKind === "great_house" && pd.great_house_id === selectedId) selectedProvincePids.add(pid);
      if (selectedKind === "free_city" && pd.free_city_id === selectedId) selectedProvincePids.add(pid);
      if (selectedKind === "kingdom" && pd.kingdom_id === selectedId) selectedProvincePids.add(pid);
    }

    if (!selectedProvincePids.size) {
      const selectedTypeLabel = LABELS[selectedKind] || "Область";
      title.textContent = `Для выбранной сущности (${selectedTypeLabel}) не найдено провинций.`;
      return;
    }

    collectNeighborProvincePids();
    for (const pid of selectedProvincePids) visibleProvincePids.add(pid);
    for (const pid of neighborProvincePids) visibleProvincePids.add(pid);

    const label = (selectedKind === "great_house")
      ? (state.great_houses?.[selectedId]?.name || selectedId)
      : ((selectedKind === "free_city")
        ? (state.free_cities?.[selectedId]?.name || selectedId)
        : (state.kingdoms?.[selectedId]?.name || selectedId));
    const selectedTypeLabel = LABELS[selectedKind] || "Область";
    title.textContent = `${selectedTypeLabel}: ${label}. Показано провинций: ${visibleProvincePids.size}.`;

    fitViewToVisibleHexes();
    resize();
  }

  canvas.addEventListener("mousemove", (evt) => {
    const world = clientToWorld(evt.clientX, evt.clientY);
    if (!Number.isFinite(world.x) || !Number.isFinite(world.y)) return;

    if (isPanning) {
      const dx = world.x - panStart.p.x;
      const dy = world.y - panStart.p.y;
      vb.x = panStart.vb.x - dx;
      vb.y = panStart.vb.y - dy;
      render();
      return;
    }

    hoverHex = findHexAt(world);
    if (hoverHex && !visibleProvincePids.has(effectivePidForHex(hoverHex))) hoverHex = null;
    hoverProvId = hoverHex ? effectivePidForHex(hoverHex) : null;
    setInfo(hoverHex);
    render();
  });

  canvas.addEventListener("mouseleave", () => {
    hoverHex = null;
    hoverProvId = null;
    setInfo(null);
    render();
  });

  canvas.addEventListener("wheel", (evt) => {
    evt.preventDefault();
    const zoomFactor = Math.sign(evt.deltaY) > 0 ? 1.12 : 1 / 1.12;
    const p = clientToWorld(evt.clientX, evt.clientY);
    if (!Number.isFinite(p.x) || !Number.isFinite(p.y)) return;

    const newW = vb.w * zoomFactor;
    const newH = vb.h * zoomFactor;
    const rx = (p.x - vb.x) / vb.w;
    const ry = (p.y - vb.y) / vb.h;
    vb.x = p.x - rx * newW;
    vb.y = p.y - ry * newH;
    vb.w = newW;
    vb.h = newH;
    render();
  }, { passive: false });

  canvas.addEventListener("mousedown", (evt) => {
    isPanning = true;
    panStart = { p: clientToWorld(evt.clientX, evt.clientY), vb: { ...vb } };
  });

  window.addEventListener("mouseup", () => { isPanning = false; });
  canvas.addEventListener("dblclick", () => { fitViewToVisibleHexes(); render(); });
  window.addEventListener("resize", resize);

  document.querySelectorAll('input[name="mode"]').forEach(r => {
    r.addEventListener("change", () => {
      mode = document.querySelector('input[name="mode"]:checked').value;
      hoverProvId = hoverHex ? effectivePidForHex(hoverHex) : null;
      if (hoverHex) setInfo(hoverHex);
      render();
    });
  });

  main().catch((err) => {
    title.textContent = `Ошибка загрузки микрокарты: ${err.message}`;
  });
})();
