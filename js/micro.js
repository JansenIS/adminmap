(function () {
  "use strict";

  const BEIGE = "#efe6d2";
  const canvas = document.getElementById("mapCanvas");
  const ctx = canvas.getContext("2d", { alpha: false });
  const info = document.getElementById("info");
  const title = document.getElementById("microTitle");

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
  const pidResolved = new Map();
  const selectedProvincePids = new Set();
  const neighborProvincePids = new Set();
  const visibleProvincePids = new Set();
  const effectiveProvinceHexes = new Map();
  const pathByHexId = new Map();
  const verticesByHexId = new Map();
  const coordToHex = new Map();
  const hexSpatial = new Map();
  const spatialCell = HEX_SIZE * 2.4;

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

  function colorForPid(pid, state) {
    if (selectedProvincePids.has(pid)) return BEIGE;
    const pd = realmByProvince.get(pid);
    if (!pd) return "#778193";
    if (pd.free_city_id && state.free_cities && state.free_cities[pd.free_city_id]?.color) return state.free_cities[pd.free_city_id].color;
    if (pd.kingdom_id && state.kingdoms && state.kingdoms[pd.kingdom_id]?.color) return state.kingdoms[pd.kingdom_id].color;
    return "#778193";
  }

  function effectivePidFor(pid) {
    if (!(pid > 0)) return pid;
    if (pidResolved.has(pid)) return pidResolved.get(pid);

    const chain = [];
    const chainIndex = new Map();
    let cur = pid;

    while (pidRemap.has(cur)) {
      if (pidResolved.has(cur)) {
        const resolved = pidResolved.get(cur);
        for (const item of chain) pidResolved.set(item, resolved);
        return resolved;
      }

      const loopAt = chainIndex.get(cur);
      if (loopAt !== undefined) {
        for (let i = loopAt; i < chain.length; i++) {
          const node = chain[i];
          pidResolved.set(node, pidRemap.get(node) ?? node);
        }
        for (let i = loopAt - 1; i >= 0; i--) {
          const node = chain[i];
          pidResolved.set(node, pidResolved.get(chain[i + 1]));
        }
        return pidResolved.get(pid);
      }

      chainIndex.set(cur, chain.length);
      chain.push(cur);
      cur = pidRemap.get(cur);
    }

    for (const item of chain) pidResolved.set(item, cur);
    return cur;
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
    const pid = effectivePidFor(hex.p);
    const p = provinceById.get(pid);
    const pd = realmByProvince.get(pid);
    const ownerLabel = pd?.free_city_id ? `Территория: ${pd.free_city_id}` : `Королевство: ${pd?.kingdom_id || "-"}`;

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

  function collectNeighborProvincePids() {
    for (const pid of selectedProvincePids) {
      const list = effectiveProvinceHexes.get(pid) || [];
      for (const h of list) {
        const offsets = (h.q % 2 === 0) ? qParityOffsets.even : qParityOffsets.odd;
        for (const [dq, dr] of offsets) {
          const nh = coordToHex.get(`${h.q + dq},${h.r + dr}`);
          if (!nh) continue;
          const npid = effectivePidFor(nh.p);
          if (npid === pid) continue;
          if (!selectedProvincePids.has(npid)) neighborProvincePids.add(npid);
        }
      }
    }
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
        ctx.strokeStyle = "rgba(0,0,0,0.45)";
        ctx.lineWidth = 0.18;
        ctx.stroke(path);
      }
    }

    if (mode === "province" && hoverProvId && visibleProvincePids.has(hoverProvId)) {
      for (const h of (effectiveProvinceHexes.get(hoverProvId) || [])) {
        const path = pathByHexId.get(h.id);
        ctx.strokeStyle = "rgba(255,255,255,0.95)";
        ctx.lineWidth = 0.45;
        ctx.stroke(path);
      }
    } else if (hoverHex && visibleProvincePids.has(effectivePidFor(hoverHex.p))) {
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
      pidResolved.clear();
    }

    const resp = await fetch("data/map_state.json", { cache: "no-store" });
    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    const state = await resp.json();
    window.__MICRO_STATE__ = state;

    const provRecords = (Array.isArray(data.provOffsets) && typeof data.provOffsets[0] === "number")
      ? data.provinces.map((p, i) => ({ id: p.id, start: data.provOffsets[i], count: data.provOffsets[i + 1] - data.provOffsets[i] }))
      : data.provOffsets;

    for (const po of provRecords) {
      const list = [];
      for (let i = 0; i < po.count; i++) {
        const h = data.hexes[po.start + i];
        list.push(h);
      }
      const effectivePid = effectivePidFor(po.id);
      if (!effectiveProvinceHexes.has(effectivePid)) effectiveProvinceHexes.set(effectivePid, []);
      effectiveProvinceHexes.get(effectivePid).push(...list);
    }

    for (const [pidRaw, pd] of Object.entries(state.provinces || {})) {
      const sourcePid = Number(pidRaw);
      if (!(sourcePid > 0)) continue;
      const effectivePid = effectivePidFor(sourcePid);
      if (!realmByProvince.has(effectivePid) || effectivePid === sourcePid) {
        realmByProvince.set(effectivePid, pd || {});
      }
    }

    for (const pid of effectiveProvinceHexes.keys()) {
      const pd = realmByProvince.get(pid);
      if (!pd) continue;
      if (selectedKind === "free_city" && pd.free_city_id === selectedId) selectedProvincePids.add(pid);
      if (selectedKind !== "free_city" && pd.kingdom_id === selectedId) selectedProvincePids.add(pid);
    }

    if (!selectedProvincePids.size) {
      title.textContent = "Для выбранного королевства/территории не найдено провинций.";
      return;
    }

    collectNeighborProvincePids();
    for (const pid of selectedProvincePids) visibleProvincePids.add(pid);
    for (const pid of neighborProvincePids) visibleProvincePids.add(pid);

    const label = (selectedKind === "free_city")
      ? (state.free_cities?.[selectedId]?.name || selectedId)
      : (state.kingdoms?.[selectedId]?.name || selectedId);
    title.textContent = `${selectedKind === "free_city" ? "Территория" : "Королевство"}: ${label}. Показано провинций: ${visibleProvincePids.size}.`;

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
    if (hoverHex && !visibleProvincePids.has(effectivePidFor(hoverHex.p))) hoverHex = null;
    hoverProvId = hoverHex ? effectivePidFor(hoverHex.p) : null;
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
      hoverProvId = hoverHex ? effectivePidFor(hoverHex.p) : null;
      if (hoverHex) setInfo(hoverHex);
      render();
    });
  });

  main().catch((err) => {
    title.textContent = `Ошибка загрузки микрокарты: ${err.message}`;
  });
})();
