const state = {
  rows: [],
  byPid: new Map(),
  byKey: new Map(),
  selectedPid: null,
  metric: "effectiveGDP",
  mapImg: null,
  maskImg: null,
  keyPixels: null,
  centroidByKey: new Map(),
  anchorByKey: new Map(),
  width: 0,
  height: 0,
};

const UI = {
  canvas: document.getElementById("mapCanvas"),
  metricSelect: document.getElementById("metricSelect"),
  selPid: document.getElementById("selPid"),
  selName: document.getElementById("selName"),
  selTerrain: document.getElementById("selTerrain"),
  editor: document.getElementById("editor"),
  inputPop: document.getElementById("inputPop"),
  inputInfra: document.getElementById("inputInfra"),
  inputGdpWeight: document.getElementById("inputGdpWeight"),
  btnSave: document.getElementById("btnSave"),
  saveStatus: document.getElementById("saveStatus"),
  tooltip: document.getElementById("tooltip"),
};
const ctx = UI.canvas.getContext("2d", { willReadFrequently: true });

async function api(path, opts) {
  const res = await fetch(path, opts);
  if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
  return res.json();
}

function metricValue(row) {
  const v = Number(row[state.metric] ?? 0);
  return Number.isFinite(v) ? v : 0;
}

function buildScale(values) {
  const sorted = values.filter((v) => Number.isFinite(v) && v >= 0).sort((a, b) => a - b);
  const max = sorted[sorted.length - 1] || 1;
  const min = sorted[0] || 0;
  return { min, max, span: Math.max(1e-9, max - min) };
}

function radiusFor(row, scale) {
  const t = (metricValue(row) - scale.min) / scale.span;
  return 2 + Math.pow(Math.max(0, Math.min(1, t)), 0.45) * 12;
}

function pickPidFromCanvasEvent(evt) {
  if (!state.keyPixels) return null;
  const rect = UI.canvas.getBoundingClientRect();
  const x = Math.floor((evt.clientX - rect.left) * (state.width / rect.width));
  const y = Math.floor((evt.clientY - rect.top) * (state.height / rect.height));
  if (x < 0 || y < 0 || x >= state.width || y >= state.height) return null;
  const idx = y * state.width + x;
  const key = state.keyPixels[idx] >>> 0;
  return state.byKey.get(key)?.pid ?? null;
}


function computeKeyCentroids() {
  const sums = new Map();
  const w = state.width;
  const h = state.height;
  const px = state.keyPixels;

  for (let y = 0; y < h; y++) {
    const rowOff = y * w;
    for (let x = 0; x < w; x++) {
      const key = px[rowOff + x] >>> 0;
      if (!key) continue;
      const prev = sums.get(key);
      if (prev) {
        prev.sx += x;
        prev.sy += y;
        prev.n += 1;
      } else {
        sums.set(key, { sx: x, sy: y, n: 1 });
      }
    }
  }

  state.centroidByKey = new Map();
  for (const [key, v] of sums.entries()) {
    if (v.n > 0) state.centroidByKey.set(key, [v.sx / v.n, v.sy / v.n]);
  }

  // Representative in-mask anchor per province:
  // choose an actual province pixel closest to the centroid,
  // so the dot is guaranteed to be inside province boundaries.
  const best = new Map();
  for (const [key, c] of state.centroidByKey.entries()) {
    best.set(key, { x: c[0], y: c[1], d2: Number.POSITIVE_INFINITY });
  }

  for (let y = 0; y < h; y++) {
    const rowOff = y * w;
    for (let x = 0; x < w; x++) {
      const key = px[rowOff + x] >>> 0;
      if (!key) continue;
      const c = state.centroidByKey.get(key);
      const b = best.get(key);
      if (!c || !b) continue;
      const dx = x - c[0];
      const dy = y - c[1];
      const d2 = dx * dx + dy * dy;
      if (d2 < b.d2) {
        b.x = x;
        b.y = y;
        b.d2 = d2;
      }
    }
  }

  state.anchorByKey = new Map();
  for (const [key, b] of best.entries()) {
    state.anchorByKey.set(key, [b.x, b.y]);
  }
}


function resolveRowKey(rowKey) {
  const k = rowKey >>> 0;
  if (state.centroidByKey.has(k) || state.anchorByKey.has(k)) return k;
  const swapped = (((k & 0x0000ff) << 16) | (k & 0x00ff00) | ((k & 0xff0000) >>> 16)) >>> 0;
  if (state.centroidByKey.has(swapped) || state.anchorByKey.has(swapped)) return swapped;
  return k;
}

function render() {
  if (!state.mapImg) return;
  ctx.clearRect(0, 0, state.width, state.height);
  ctx.drawImage(state.mapImg, 0, 0, state.width, state.height);

  ctx.save();
  ctx.shadowColor = "rgba(78,206,255,0.95)";
  ctx.shadowBlur = 8;
  ctx.strokeStyle = "rgba(90,220,255,0.95)";
  ctx.lineWidth = 1.2;

  if (state.maskImg) {
    const off = document.createElement("canvas");
    off.width = state.width;
    off.height = state.height;
    const ox = off.getContext("2d", { willReadFrequently: true });
    ox.drawImage(state.maskImg, 0, 0, state.width, state.height);
    const d = ox.getImageData(0, 0, state.width, state.height);
    const src = d.data;
    const dst = new Uint8ClampedArray(src.length);
    const stride = state.width;

    for (let y = 1; y < state.height - 1; y++) {
      for (let x = 1; x < state.width - 1; x++) {
        const i = (y * stride + x) * 4;
        const k = ((src[i] << 16) | (src[i + 1] << 8) | src[i + 2]) >>> 0;
        if (!k) continue;
        let border = false;
        for (const [dx, dy] of [[-1,0],[1,0],[0,-1],[0,1]]) {
          const j = ((y + dy) * stride + (x + dx)) * 4;
          const kk = ((src[j] << 16) | (src[j + 1] << 8) | src[j + 2]) >>> 0;
          if (kk !== k) { border = true; break; }
        }
        if (border) {
          dst[i] = 120; dst[i + 1] = 240; dst[i + 2] = 255; dst[i + 3] = 220;
        }
      }
    }
    const outline = new ImageData(dst, state.width, state.height);
    ctx.putImageData(outline, 0, 0);
  }
  ctx.restore();

  const scale = buildScale(state.rows.map(metricValue));
  for (const row of state.rows) {
    const key = resolveRowKey(row.key);
    const [cx, cy] = state.anchorByKey.get(key) || state.centroidByKey.get(key) || row.centroid || [0, 0];
    const r = radiusFor(row, scale);
    ctx.beginPath();
    ctx.fillStyle = row.pid === state.selectedPid ? "rgba(255,196,60,.95)" : "rgba(45,194,255,.9)";
    ctx.shadowColor = row.pid === state.selectedPid ? "rgba(255,198,90,.9)" : "rgba(80,210,255,.95)";
    ctx.shadowBlur = 12;
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.fill();
  }
}

function bindSelection(pid) {
  state.selectedPid = pid;
  const row = state.byPid.get(pid);
  if (!row) return;
  UI.selPid.textContent = String(row.pid);
  UI.selName.textContent = row.name;
  UI.selTerrain.textContent = row.terrain || "—";
  UI.inputPop.value = String(Math.round(row.population || 1));
  UI.inputInfra.value = String(Number(row.infra || 0.5).toFixed(2));
  UI.inputGdpWeight.value = String(Number(row.gdpWeight || 1).toFixed(2));
  UI.editor.hidden = false;
  render();
}

function setTooltip(evt, pid) {
  if (!pid) {
    UI.tooltip.style.display = "none";
    return;
  }
  const row = state.byPid.get(pid);
  if (!row) return;
  UI.tooltip.style.display = "block";
  UI.tooltip.style.left = `${evt.clientX + 16}px`;
  UI.tooltip.style.top = `${evt.clientY + 16}px`;
  UI.tooltip.innerHTML = `<b>${row.name}</b><br>PID: ${row.pid}<br>Pop: ${Math.round(row.population)}<br>GDP: ${Math.round(row.effectiveGDP)}`;
}

async function loadImages(imageUrl, maskUrl) {
  const load = (src) => new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = reject;
    img.src = src;
  });
  const [mapImg, maskImg] = await Promise.all([load(imageUrl), load(maskUrl)]);
  state.mapImg = mapImg;
  state.maskImg = maskImg;
  state.width = mapImg.naturalWidth;
  state.height = mapImg.naturalHeight;
  UI.canvas.width = state.width;
  UI.canvas.height = state.height;

  const off = document.createElement("canvas");
  off.width = state.width;
  off.height = state.height;
  const ox = off.getContext("2d", { willReadFrequently: true });
  ox.drawImage(maskImg, 0, 0, state.width, state.height);
  const data = ox.getImageData(0, 0, state.width, state.height).data;
  const out = new Uint32Array(state.width * state.height);
  for (let i = 0, p = 0; i < out.length; i++, p += 4) {
    out[i] = ((data[p] << 16) | (data[p + 1] << 8) | data[p + 2]) >>> 0;
  }
  state.keyPixels = out;
  computeKeyCentroids();
}

async function loadAll() {
  const payload = await api("/api/admin/map-sync");
  state.rows = payload.provinces || [];
  state.byPid = new Map(state.rows.map((r) => [r.pid, r]));
  state.byKey = new Map(state.rows.map((r) => [r.key >>> 0, r]));
  await loadImages(payload.map.image, payload.map.mask);

  state.rows = state.rows.map((row) => ({
    ...row,
    centroid: state.centroidByKey.get(resolveRowKey(row.key)) || row.centroid || [0, 0],
  }));
  state.byPid = new Map(state.rows.map((r) => [r.pid, r]));
  state.byKey = new Map(state.rows.map((r) => [r.key >>> 0, r]));

  render();
}

UI.metricSelect.addEventListener("change", () => {
  state.metric = UI.metricSelect.value;
  render();
});

UI.canvas.addEventListener("click", (evt) => {
  const pid = pickPidFromCanvasEvent(evt);
  if (pid) bindSelection(pid);
});

UI.canvas.addEventListener("mousemove", (evt) => {
  const pid = pickPidFromCanvasEvent(evt);
  setTooltip(evt, pid);
});
UI.canvas.addEventListener("mouseleave", () => setTooltip(null, null));

UI.btnSave.addEventListener("click", async () => {
  if (!state.selectedPid) return;
  const payload = {
    pid: state.selectedPid,
    pop: Number(UI.inputPop.value),
    infra: Number(UI.inputInfra.value),
    gdpWeight: Number(UI.inputGdpWeight.value),
  };
  UI.saveStatus.textContent = "Сохраняю...";
  await api("/api/admin/province", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  await loadAll();
  bindSelection(state.selectedPid);
  UI.saveStatus.textContent = "Сохранено";
});

loadAll().catch((e) => {
  UI.saveStatus.textContent = `Ошибка: ${e.message}`;
});
