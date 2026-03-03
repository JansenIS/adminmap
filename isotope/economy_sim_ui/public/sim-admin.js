const state = {
  rows: [],
  byPid: new Map(),
  byKey: new Map(),
  selectedPid: null,
  selectedPids: new Set(),
  metric: "effectiveGDP",
  mapMode: "circles",
  realmColorsByMode: { kingdoms: new Map(), great_houses: new Map(), minor_houses: new Map() },
  activeTab: "map",
  treasurySort: { key: "treasury", dir: "desc" },
  entityEconomy: null,
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
  mapModeSelect: document.getElementById("mapModeSelect"),
  metricSelect: document.getElementById("metricSelect"),
  selPid: document.getElementById("selPid"),
  selName: document.getElementById("selName"),
  selTerrain: document.getElementById("selTerrain"),
  selCount: document.getElementById("selCount"),
  editor: document.getElementById("editor"),
  inputPop: document.getElementById("inputPop"),
  inputInfra: document.getElementById("inputInfra"),
  inputGdpWeight: document.getElementById("inputGdpWeight"),
  buildingRows: document.getElementById("buildingRows"),
  buildingTypeSelect: document.getElementById("buildingTypeSelect"),
  btnAddBuilding: document.getElementById("btnAddBuilding"),
  btnSave: document.getElementById("btnSave"),
  applyToSelection: document.getElementById("applyToSelection"),
  btnSelectAll: document.getElementById("btnSelectAll"),
  btnClearSelection: document.getElementById("btnClearSelection"),
  btnWriteStartState: document.getElementById("btnWriteStartState"),
  saveStatus: document.getElementById("saveStatus"),
  tooltip: document.getElementById("tooltip"),
  tabBtnMap: document.getElementById("tabBtnMap"),
  tabBtnTreasury: document.getElementById("tabBtnTreasury"),
  tabBtnEntities: document.getElementById("tabBtnEntities"),
  tabMap: document.getElementById("tabMap"),
  tabTreasury: document.getElementById("tabTreasury"),
  tabEntities: document.getElementById("tabEntities"),
  treasuryTableBody: document.getElementById("treasuryTableBody"),
  entityEconomyDump: document.getElementById("entityEconomyDump"),
  sortBtns: Array.from(document.querySelectorAll(".sortBtn")),
  flagOffMarket: document.getElementById("flagOffMarket"),
  flagBlackMarket: document.getElementById("flagBlackMarket"),
  flagExchange: document.getElementById("flagExchange"),
};
const ctx = UI.canvas.getContext("2d", { willReadFrequently: true });

async function api(path, opts) {
  const requestPath = resolveApiPath(path);
  const res = await fetch(requestPath, opts);
  if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
  return res.json();
}

function resolveApiPath(path) {
  if (typeof path !== "string" || !path.startsWith("/api/")) return path;
  const economicsBase = detectEconomicsBasePath();
  if (!economicsBase) return path;
  return `${economicsBase}${path}`;
}

function detectEconomicsBasePath() {
  const pathname = String(window.location.pathname || "");
  const marker = "/economics";
  const idx = pathname.indexOf(marker);
  if (idx < 0) return "";
  const after = pathname.slice(idx + marker.length);
  if (after && !after.startsWith("/")) return "";
  return pathname.slice(0, idx + marker.length);
}


function detectAdminRootPrefix() {
  const economicsBase = detectEconomicsBasePath();
  if (!economicsBase) return "";
  return economicsBase.endsWith("/economics")
    ? economicsBase.slice(0, -"/economics".length)
    : "";
}

async function tryLoadAdminProvinceIndex() {
  const prefix = detectAdminRootPrefix();
  const path = `${prefix}/api/provinces/?limit=500&profile=compact`;
  try {
    const payload = await fetch(path, { cache: "no-store" }).then((r) => (r.ok ? r.json() : null));
    const items = Array.isArray(payload?.items) ? payload.items : [];
    return new Map(items.map((row) => [Number(row?.pid), row]).filter(([pid]) => Number.isFinite(pid) && pid > 0));
  } catch {
    return new Map();
  }
}

function enrichRowsWithAdminProvinces(rows, adminByPid) {
  if (!Array.isArray(rows) || !(adminByPid instanceof Map) || adminByPid.size === 0) return rows;
  return rows.map((row) => {
    const pid = Number(row?.pid);
    const admin = adminByPid.get(pid);
    if (!admin) return row;
    return {
      ...row,
      name: (typeof admin.name === "string" && admin.name.trim()) ? admin.name.trim() : row.name,
      terrain: (typeof admin.terrain === "string" && admin.terrain.trim()) ? admin.terrain.trim() : row.terrain,
      kingdom_id: (typeof admin.kingdom_id === "string" && admin.kingdom_id.trim()) ? admin.kingdom_id.trim() : row.kingdom_id,
      great_house_id: (typeof admin.great_house_id === "string" && admin.great_house_id.trim()) ? admin.great_house_id.trim() : row.great_house_id,
      minor_house_id: (typeof admin.minor_house_id === "string" && admin.minor_house_id.trim()) ? admin.minor_house_id.trim() : row.minor_house_id,
      free_city_id: (typeof admin.free_city_id === "string" && admin.free_city_id.trim()) ? admin.free_city_id.trim() : row.free_city_id,
    };
  });
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
  const resolvedKey = resolveRowKey(key);
  return state.byKey.get(key)?.pid ?? state.byKey.get(resolvedKey)?.pid ?? null;
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

function swapRgbKey(k) {
  const v = k >>> 0;
  return (((v & 0x0000ff) << 16) | (v & 0x00ff00) | ((v & 0xff0000) >>> 16)) >>> 0;
}

function resolveRowKey(rowKey) {
  const k = rowKey >>> 0;
  const swapped = swapRgbKey(k);

  if (state.byKey?.has(k)) return k;
  if (state.byKey?.has(swapped)) return swapped;
  if (state.centroidByKey.has(k) || state.anchorByKey.has(k)) return k;
  if (state.centroidByKey.has(swapped) || state.anchorByKey.has(swapped)) return swapped;
  return k;
}


function normalizeHexColor(color, fallback = "#58697a") {
  if (typeof color !== "string") return fallback;
  const c = color.trim();
  if (/^#[0-9a-fA-F]{6}$/.test(c)) return c;
  if (/^#[0-9a-fA-F]{3}$/.test(c)) {
    return `#${c[1]}${c[1]}${c[2]}${c[2]}${c[3]}${c[3]}`;
  }
  return fallback;
}

function rgbaFromHex(hex, alpha = 0.55) {
  const n = normalizeHexColor(hex, "#58697a");
  const r = Number.parseInt(n.slice(1, 3), 16);
  const g = Number.parseInt(n.slice(3, 5), 16);
  const b = Number.parseInt(n.slice(5, 7), 16);
  return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function buildRealmColorMaps(realms) {
  const byMode = { kingdoms: new Map(), great_houses: new Map(), minor_houses: new Map() };
  for (const mode of Object.keys(byMode)) {
    const items = Array.isArray(realms?.[mode]) ? realms[mode] : [];
    for (const item of items) {
      const id = String(item?.id || "").trim();
      if (!id) continue;
      byMode[mode].set(id, normalizeHexColor(item?.color));
    }
  }
  return byMode;
}

function realmColorForRow(row) {
  const mode = state.mapMode;
  if (mode === "circles") return null;

  const isSpecial = row.isOffMarket || row.isBlackMarket || row.isExchange || row.isSpecialTerritory;
  if (row.free_city_id) {
    const freeCityColor = state.realmColorsByMode?.free_cities?.get(String(row.free_city_id || ""));
    if (freeCityColor) return freeCityColor;
  }
  if (isSpecial) return "#ffd166";

  const realmIdByMode = {
    kingdoms: row.kingdom_id,
    great_houses: row.great_house_id,
    minor_houses: row.minor_house_id,
  };
  const realmId = realmIdByMode[mode];
  if (!realmId) return null;
  return state.realmColorsByMode?.[mode]?.get(String(realmId)) || null;
}

function paintTerritoryOverlay() {
  if (!state.maskImg || state.mapMode === "circles") return;

  const off = document.createElement("canvas");
  off.width = state.width;
  off.height = state.height;
  const ox = off.getContext("2d", { willReadFrequently: true });

  const img = ox.createImageData(state.width, state.height);
  const out = img.data;

  for (let i = 0, p = 0; i < state.keyPixels.length; i++, p += 4) {
    const key = state.keyPixels[i] >>> 0;
    if (!key) continue;
    const resolvedKey = resolveRowKey(key);
    const swappedKey = swapRgbKey(key);
    const row = state.byKey.get(key) || state.byKey.get(resolvedKey) || state.byKey.get(swappedKey);
    if (!row) continue;
    const color = realmColorForRow(row);
    if (!color) continue;
    const hex = normalizeHexColor(color);
    out[p] = Number.parseInt(hex.slice(1, 3), 16);
    out[p + 1] = Number.parseInt(hex.slice(3, 5), 16);
    out[p + 2] = Number.parseInt(hex.slice(5, 7), 16);
    out[p + 3] = 210;
  }

  ox.putImageData(img, 0, 0);
  ctx.drawImage(off, 0, 0, state.width, state.height);
}

function render() {
  if (!state.mapImg) return;
  ctx.clearRect(0, 0, state.width, state.height);

  const circlesMode = state.mapMode === "circles";
  if (circlesMode) {
    ctx.fillStyle = "rgba(8,18,30,0.92)";
    ctx.fillRect(0, 0, state.width, state.height);
  } else {
    ctx.drawImage(state.mapImg, 0, 0, state.width, state.height);
    paintTerritoryOverlay();
  }

  ctx.save();
  ctx.shadowColor = circlesMode ? "rgba(0,0,0,0)" : "rgba(78,206,255,0.95)";
  ctx.shadowBlur = circlesMode ? 0 : 8;
  ctx.strokeStyle = circlesMode ? "rgba(120,240,255,0.75)" : "rgba(90,220,255,0.95)";
  ctx.lineWidth = circlesMode ? 1.35 : 1.2;

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
          dst[i] = 120; dst[i + 1] = 240; dst[i + 2] = 255; dst[i + 3] = circlesMode ? 205 : 220;
        }
      }
    }
    const outline = new ImageData(dst, state.width, state.height);
    ox.clearRect(0, 0, state.width, state.height);
    ox.putImageData(outline, 0, 0);
    ctx.drawImage(off, 0, 0, state.width, state.height);
  }
  ctx.restore();

  if (!circlesMode) return;

  const scale = buildScale(state.rows.map(metricValue));

  for (const row of state.rows) {
    const key = resolveRowKey(row.key);
    const [cx, cy] = state.anchorByKey.get(key) || state.centroidByKey.get(key) || row.centroid || [0, 0];
    const r = radiusFor(row, scale);
    const selected = state.selectedPids.has(row.pid);
    ctx.beginPath();
    ctx.fillStyle = selected ? "rgba(255,196,60,.96)" : "rgba(45,194,255,.74)";
    ctx.shadowColor = selected ? "rgba(255,214,130,.95)" : "rgba(105,235,255,.95)";
    ctx.shadowBlur = selected ? 20 : 14;
    ctx.arc(cx, cy, r, 0, Math.PI * 2);
    ctx.fill();
  }
  ctx.shadowColor = "rgba(0,0,0,0)";
  ctx.shadowBlur = 0;
}

function setActiveTab(tab) {
  state.activeTab = tab;
  const isMap = tab === "map";
  const isTreasury = tab === "treasury";
  const isEntities = tab === "entities";

  UI.tabBtnMap.classList.toggle("active", isMap);
  UI.tabBtnMap.setAttribute("aria-selected", isMap ? "true" : "false");
  UI.tabMap.classList.toggle("active", isMap);
  UI.tabMap.hidden = !isMap;

  UI.tabBtnTreasury.classList.toggle("active", isTreasury);
  UI.tabBtnTreasury.setAttribute("aria-selected", isTreasury ? "true" : "false");
  UI.tabTreasury.classList.toggle("active", isTreasury);
  UI.tabTreasury.hidden = !isTreasury;

  UI.tabBtnEntities.classList.toggle("active", isEntities);
  UI.tabBtnEntities.setAttribute("aria-selected", isEntities ? "true" : "false");
  UI.tabEntities.classList.toggle("active", isEntities);
  UI.tabEntities.hidden = !isEntities;
}

function renderEntityEconomy() {
  if (!UI.entityEconomyDump) return;
  const payload = state.entityEconomy;
  if (!payload) {
    UI.entityEconomyDump.textContent = "Нет данных";
    return;
  }
  UI.entityEconomyDump.textContent = JSON.stringify(payload, null, 2);
}

function updateSelectionUi() {
  if (UI.selCount) UI.selCount.textContent = String(state.selectedPids.size);
}

function addPidToSelection(pid) {
  if (!pid) return;
  state.selectedPids.add(pid);
  updateSelectionUi();
}

function clearSelection() {
  state.selectedPids.clear();
  state.selectedPid = null;
  UI.selPid.textContent = "—";
  UI.selName.textContent = "—";
  UI.selTerrain.textContent = "—";
  UI.editor.hidden = true;
  updateSelectionUi();
  renderTreasuryTable();
  render();
}

function bindSelection(pid, options = {}) {
  const { additive = false } = options;
  if (!additive) state.selectedPids.clear();
  state.selectedPid = pid;
  addPidToSelection(pid);
  const row = state.byPid.get(pid);
  if (!row) return;
  UI.selPid.textContent = String(row.pid);
  UI.selName.textContent = row.name;
  UI.selTerrain.textContent = row.terrain || "—";
  UI.inputPop.value = String(Math.round(row.population || 1));
  UI.inputInfra.value = String(Number(row.infra || 0.5).toFixed(2));
  UI.inputGdpWeight.value = String(Number(row.gdpWeight || 1).toFixed(2));
  UI.flagOffMarket.checked = row.marketMode === "off_market";
  UI.flagBlackMarket.checked = row.marketMode === "black_market";
  UI.flagExchange.checked = row.marketMode === "exchange";
  state.draftBuildings = (row.buildings || []).map((b) => ({
    type: b.type,
    count: Math.max(1, Math.floor(Number(b.count) || 1)),
    efficiency: Number.isFinite(Number(b.efficiency)) ? Number(b.efficiency) : 1,
  }));
  renderBuildingsEditor();
  UI.editor.hidden = false;
  renderTreasuryTable();
  updateSelectionUi();
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
    if (state.selectedPids.has(row.pid)) tr.style.background = "rgba(224, 174, 43, 0.18)";
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
  const adminByPid = await tryLoadAdminProvinceIndex();
  state.rows = enrichRowsWithAdminProvinces(payload.provinces || [], adminByPid);
  state.realmColorsByMode = buildRealmColorMaps(payload.realms || {});
  if (Array.isArray(payload.realms?.free_cities)) {
    state.realmColorsByMode.free_cities = new Map(payload.realms.free_cities
      .map((item) => [String(item?.id || "").trim(), normalizeHexColor(item?.color, "#ffd166")])
      .filter(([id]) => id));
  } else {
    state.realmColorsByMode.free_cities = new Map();
  }
  state.byPid = new Map(state.rows.map((r) => [r.pid, r]));
  state.byKey = new Map(state.rows.map((r) => [r.key >>> 0, r]));
  state.entityEconomy = payload.entityEconomy || null;
  await loadImages(payload.map.image, payload.map.mask);

  state.rows = state.rows.map((row) => ({
    ...row,
    centroid: state.centroidByKey.get(resolveRowKey(row.key)) || row.centroid || [0, 0],
  }));
  state.byPid = new Map(state.rows.map((r) => [r.pid, r]));
  state.byKey = new Map(state.rows.map((r) => [r.key >>> 0, r]));

  renderTreasuryTable();
  renderEntityEconomy();
  updateSelectionUi();
  render();
}

UI.tabBtnMap.addEventListener("click", () => setActiveTab("map"));
UI.tabBtnTreasury.addEventListener("click", () => {
  setActiveTab("treasury");
  renderTreasuryTable();
});
UI.tabBtnEntities.addEventListener("click", () => {
  setActiveTab("entities");
  renderEntityEconomy();
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

UI.mapModeSelect?.addEventListener("change", () => {
  state.mapMode = UI.mapModeSelect.value || "circles";
  render();
});

UI.metricSelect.addEventListener("change", () => {
  state.metric = UI.metricSelect.value;
  render();
});

UI.canvas.addEventListener("click", (evt) => {
  const pid = pickPidFromCanvasEvent(evt);
  if (!pid) return;
  const additive = evt.ctrlKey || evt.metaKey;
  if (additive && state.selectedPids.has(pid)) {
    state.selectedPids.delete(pid);
    if (state.selectedPid === pid) state.selectedPid = [...state.selectedPids][0] || null;
    updateSelectionUi();
    renderTreasuryTable();
    render();
    return;
  }
  bindSelection(pid, { additive });
});

UI.canvas.addEventListener("mousemove", (evt) => {
  const pid = pickPidFromCanvasEvent(evt);
  setTooltip(evt, pid);
});
UI.canvas.addEventListener("mouseleave", () => setTooltip(null, null));


function bindMarketModeChecks() {
  const sync = (active) => {
    if (active !== "off") UI.flagOffMarket.checked = false;
    if (active !== "black") UI.flagBlackMarket.checked = false;
    if (active !== "exchange") UI.flagExchange.checked = false;
  };
  UI.flagOffMarket?.addEventListener("change", () => { if (UI.flagOffMarket.checked) sync("off"); });
  UI.flagBlackMarket?.addEventListener("change", () => { if (UI.flagBlackMarket.checked) sync("black"); });
  UI.flagExchange?.addEventListener("change", () => { if (UI.flagExchange.checked) sync("exchange"); });
}

UI.btnSave.addEventListener("click", async () => {
  if (!state.selectedPid && !state.selectedPids.size) return;
  let marketMode = "normal";
  if (UI.flagOffMarket.checked) marketMode = "off_market";
  else if (UI.flagExchange.checked) marketMode = "exchange";
  else if (UI.flagBlackMarket.checked) marketMode = "black_market";

  const payloadBase = {
    pop: Number(UI.inputPop.value),
    infra: Number(UI.inputInfra.value),
    gdpWeight: Number(UI.inputGdpWeight.value),
    marketMode,
    buildings: state.draftBuildings.map((b) => ({
      type: b.type,
      count: Math.max(1, Math.floor(Number(b.count) || 1)),
      efficiency: Math.max(0.25, Math.min(2.5, Number(b.efficiency) || 1)),
    })),
  };
  const targets = UI.applyToSelection?.checked
    ? [...state.selectedPids]
    : (state.selectedPid ? [state.selectedPid] : []);
  if (!targets.length) return;

  UI.saveStatus.textContent = targets.length > 1
    ? `Сохраняю (${targets.length} провинций)...`
    : "Сохраняю...";

  for (const pid of targets) {
    await api("/api/admin/province", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ...payloadBase, pid }),
    });
  }

  await loadAll();
  const keep = state.selectedPid && targets.includes(state.selectedPid) ? state.selectedPid : targets[0];
  if (keep) bindSelection(keep);
  for (const pid of targets) state.selectedPids.add(pid);
  updateSelectionUi();
  renderTreasuryTable();
  render();
  UI.saveStatus.textContent = targets.length > 1 ? `Сохранено (${targets.length})` : "Сохранено";
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

bindMarketModeChecks();
updateSelectionUi();

UI.btnWriteStartState?.addEventListener("click", async () => {
  UI.saveStatus.textContent = "Записываю стартовое состояние...";
  await api("/api/admin/write-start-state", { method: "POST" });
  UI.saveStatus.textContent = "Стартовое состояние записано";
});

UI.btnSelectAll?.addEventListener("click", () => {
  state.selectedPids = new Set(state.rows.map((r) => r.pid));
  state.selectedPid = state.rows[0]?.pid || null;
  if (state.selectedPid) bindSelection(state.selectedPid, { additive: true });
  updateSelectionUi();
  renderTreasuryTable();
  render();
});

UI.btnClearSelection?.addEventListener("click", () => {
  clearSelection();
});

const initTab = (() => {
  const raw = new URLSearchParams(window.location.search).get("tab") || "map";
  const tab = String(raw).trim().toLowerCase();
  return (tab === "treasury" || tab === "entities" || tab === "map") ? tab : "map";
})();

setActiveTab(initTab);
loadAll().catch((e) => {
  const msg = e && e.message ? e.message : String(e || "unknown_error");
  UI.saveStatus.textContent = `Ошибка: ${msg}`;
});
