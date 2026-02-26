const state = {
  rows: [],
  byPid: new Map(),
  byKey: new Map(),
  selectedPid: null,
  metric: "effectiveGDP",
  activeTab: "map",
  treasurySort: { key: "treasury", dir: "desc" },
  mapImg: null,
  maskImg: null,
  keyPixels: null,
  centroidByKey: new Map(),
  anchorByKey: new Map(),
  buildingCatalog: [],
  draftBuildings: [],
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
  buildingRows: document.getElementById("buildingRows"),
  buildingTypeSelect: document.getElementById("buildingTypeSelect"),
  btnAddBuilding: document.getElementById("btnAddBuilding"),
  btnSave: document.getElementById("btnSave"),
  saveStatus: document.getElementById("saveStatus"),
  tooltip: document.getElementById("tooltip"),
  tabBtnMap: document.getElementById("tabBtnMap"),
  tabBtnTreasury: document.getElementById("tabBtnTreasury"),
  tabMap: document.getElementById("tabMap"),
  tabTreasury: document.getElementById("tabTreasury"),
  treasuryTableBody: document.getElementById("treasuryTableBody"),
  sortBtns: Array.from(document.querySelectorAll(".sortBtn")),
};
const ctx = UI.canvas.getContext("2d", { willReadFrequently: true });

async function api(path, opts) {
  const res = await fetch(path, opts);
  if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
  return res.json();
}

function fmtMoney(v) {
  const n = Number(v || 0);
  if (!Number.isFinite(n)) return "0";
  return Math.round(n).toLocaleString("ru-RU");
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

function setActiveTab(tab) {
  state.activeTab = tab;
  const isMap = tab === "map";
  UI.tabBtnMap.classList.toggle("active", isMap);
  UI.tabBtnMap.setAttribute("aria-selected", isMap ? "true" : "false");
  UI.tabMap.classList.toggle("active", isMap);
  UI.tabMap.hidden = !isMap;

  UI.tabBtnTreasury.classList.toggle("active", !isMap);
  UI.tabBtnTreasury.setAttribute("aria-selected", isMap ? "false" : "true");
  UI.tabTreasury.classList.toggle("active", !isMap);
  UI.tabTreasury.hidden = isMap;
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
  state.draftBuildings = (row.buildings || []).map((b) => ({
    type: b.type,
    count: Math.max(1, Math.floor(Number(b.count) || 1)),
    efficiency: Number.isFinite(Number(b.efficiency)) ? Number(b.efficiency) : 1,
  }));
  renderBuildingsEditor();
  UI.editor.hidden = false;
  renderTreasuryTable();
  render();
}

function buildingNameByType(type) {
  return state.buildingCatalog.find((b) => b.type === type)?.name || type;
}

function renderBuildingCatalogSelect() {
  UI.buildingTypeSelect.innerHTML = "";
  const sorted = [...state.buildingCatalog].sort((a, b) => a.name.localeCompare(b.name, "ru"));
  for (const b of sorted) {
    const opt = document.createElement("option");
    opt.value = b.type;
    opt.textContent = b.name;
    UI.buildingTypeSelect.appendChild(opt);
  }
}

function renderBuildingsEditor() {
  UI.buildingRows.innerHTML = "";
  if (!state.draftBuildings.length) {
    const empty = document.createElement("div");
    empty.className = "muted";
    empty.textContent = "Нет заданных зданий. Добавь нужные производства ниже.";
    UI.buildingRows.appendChild(empty);
    return;
  }

  state.draftBuildings.forEach((b, index) => {
    const row = document.createElement("div");
    row.className = "buildingRow";

    const name = document.createElement("div");
    name.className = "buildingRowName";
    name.textContent = buildingNameByType(b.type);

    const countInput = document.createElement("input");
    countInput.type = "number";
    countInput.min = "1";
    countInput.step = "1";
    countInput.value = String(Math.max(1, Math.floor(Number(b.count) || 1)));
    countInput.addEventListener("input", () => {
      b.count = Math.max(1, Math.floor(Number(countInput.value) || 1));
    });

    const effInput = document.createElement("input");
    effInput.type = "number";
    effInput.min = "0.25";
    effInput.max = "2.5";
    effInput.step = "0.01";
    effInput.value = String((Number.isFinite(Number(b.efficiency)) ? Number(b.efficiency) : 1).toFixed(2));
    effInput.addEventListener("input", () => {
      b.efficiency = Math.max(0.25, Math.min(2.5, Number(effInput.value) || 1));
    });

    const btnRemove = document.createElement("button");
    btnRemove.type = "button";
    btnRemove.className = "btnRemoveBuilding";
    btnRemove.textContent = "×";
    btnRemove.title = "Удалить";
    btnRemove.addEventListener("click", () => {
      state.draftBuildings.splice(index, 1);
      renderBuildingsEditor();
    });

    row.append(name, countInput, effInput, btnRemove);
    UI.buildingRows.appendChild(row);
  });
}

function sortedTreasuryRows() {
  const { key, dir } = state.treasurySort;
  const mul = dir === "asc" ? 1 : -1;
  return [...state.rows].sort((a, b) => {
    if (key === "name") return a.name.localeCompare(b.name, "ru") * mul;
    const av = Number(a[key] ?? 0);
    const bv = Number(b[key] ?? 0);
    return (av - bv) * mul;
  });
}

function renderTreasuryTable() {
  if (!UI.treasuryTableBody) return;
  UI.treasuryTableBody.innerHTML = "";

  for (const row of sortedTreasuryRows()) {
    const tr = document.createElement("tr");
    if (row.pid === state.selectedPid) tr.style.background = "rgba(224, 174, 43, 0.18)";
    tr.innerHTML = `
      <td>${row.pid}</td>
      <td>${row.name}</td>
      <td>${fmtMoney(row.treasury)}</td>
      <td>${fmtMoney(row.treasuryNetYear)}</td>
      <td>${fmtMoney(row.treasuryTradeTaxYear)}</td>
      <td>${fmtMoney(row.treasuryTransitYear)}</td>
      <td>${fmtMoney(row.treasuryExpenseYear)}</td>
    `;
    tr.addEventListener("click", () => {
      setActiveTab("map");
      bindSelection(row.pid);
    });
    UI.treasuryTableBody.appendChild(tr);
  }

  for (const btn of UI.sortBtns) {
    const isActive = btn.dataset.sortKey === state.treasurySort.key;
    btn.classList.toggle("active", isActive);
    btn.classList.toggle("asc", isActive && state.treasurySort.dir === "asc");
    btn.classList.toggle("desc", isActive && state.treasurySort.dir === "desc");
  }
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
  UI.tooltip.innerHTML = `<b>${row.name}</b><br>PID: ${row.pid}<br>Pop: ${Math.round(row.population)}<br>GDP: ${Math.round(row.effectiveGDP)}<br>Казна: ${fmtMoney(row.treasury)}`;
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
  state.buildingCatalog = Array.isArray(payload.buildingCatalog) ? payload.buildingCatalog : [];
  renderBuildingCatalogSelect();
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

  renderTreasuryTable();
  render();
}

UI.tabBtnMap.addEventListener("click", () => setActiveTab("map"));
UI.tabBtnTreasury.addEventListener("click", () => {
  setActiveTab("treasury");
  renderTreasuryTable();
});

UI.sortBtns.forEach((btn) => {
  btn.addEventListener("click", () => {
    const key = btn.dataset.sortKey;
    if (!key) return;
    if (state.treasurySort.key === key) {
      state.treasurySort.dir = state.treasurySort.dir === "asc" ? "desc" : "asc";
    } else {
      state.treasurySort.key = key;
      state.treasurySort.dir = key === "name" ? "asc" : "desc";
    }
    renderTreasuryTable();
  });
});

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
    buildings: state.draftBuildings.map((b) => ({
      type: b.type,
      count: Math.max(1, Math.floor(Number(b.count) || 1)),
      efficiency: Math.max(0.25, Math.min(2.5, Number(b.efficiency) || 1)),
    })),
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

UI.btnAddBuilding.addEventListener("click", () => {
  const type = UI.buildingTypeSelect.value;
  if (!type) return;
  const existing = state.draftBuildings.find((b) => b.type === type);
  if (existing) {
    existing.count = Math.max(1, Math.floor(Number(existing.count) || 1) + 1);
  } else {
    state.draftBuildings.push({ type, count: 1, efficiency: 1 });
  }
  renderBuildingsEditor();
});

setActiveTab("map");
loadAll().catch((e) => {
  UI.saveStatus.textContent = `Ошибка: ${e.message}`;
});
