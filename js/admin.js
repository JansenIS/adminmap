/* admin.js (zoom + emblems + feudal layers) */

(function () {
  "use strict";

  const el = (id) => document.getElementById(id);

  const tooltip = el("tooltip");

  const selName = el("selName");
  const selPid = el("selPid");
  const selKey = el("selKey");
  const multiSelCount = el("multiSelCount");

  const colorInput = el("color");
  const alphaInput = el("alpha");
  const alphaVal = el("alphaVal");

  const provNameInput = el("provName");
  const ownerInput = el("ownerInput");
  const peopleDatalist = el("peopleList");

  const suzerainSelect = el("suzerainSelect");
  const seniorSelect = el("seniorSelect");
  const vassalsSelect = el("vassalsSelect");
  const terrainSelect = el("terrainSelect");

  const btnApplyFill = el("applyFill");
  const btnClearFill = el("clearFill");
  const btnSaveProv = el("saveProv");

  const viewModeSelect = el("viewMode");
  const toggleProvEmblemsBtn = el("toggleProvEmblems");
  const realmTypeSelect = el("realmType");
  const realmSelect = el("realmSelect");
  const realmNameInput = el("realmName");
  const realmColorInput = el("realmColor");
  const realmCapitalInput = el("realmCapital");
  const realmEmblemScaleInput = el("realmEmblemScale");
  const realmEmblemScaleVal = el("realmEmblemScaleVal");
  const btnSaveRealm = el("saveRealm");
  const btnAddSelectedToRealm = el("addSelectedToRealm");
  const btnRemoveSelectedFromRealm = el("removeSelectedFromRealm");
  const btnNewRealm = el("newRealm");
  const realmUploadEmblemBtn = el("realmUploadEmblemBtn");
  const realmRemoveEmblemBtn = el("realmRemoveEmblemBtn");
  const realmEmblemFile = el("realmEmblemFile");

  const btnExport = el("export");
  const btnDownload = el("download");
  const btnImport = el("import");
  const importFile = el("importFile");

  const btnSaveServer = el("saveServer");
  const stateTA = el("state");

  const uploadEmblemBtn = el("uploadEmblemBtn");
  const removeEmblemBtn = el("removeEmblemBtn");
  const emblemFile = el("emblemFile");
  const emblemPreviewImg = el("emblemPreviewImg");
  const emblemPreviewEmpty = el("emblemPreviewEmpty");

  alphaInput.addEventListener("input", () => alphaVal.textContent = alphaInput.value);
  realmEmblemScaleInput.addEventListener("input", () => realmEmblemScaleVal.textContent = realmEmblemScaleInput.value);

  const STATE_URL_DEFAULT = "data/map_state.json";
  const SAVE_ENDPOINT = "save_state.php";
  const SAVE_TOKEN = "";

  const TERRAIN_TYPES_FALLBACK = ["равнины", "холмы", "горы", "лес", "болота", "степь", "пустоши", "побережье", "остров", "город", "руины", "озёра/реки"];
  const MODE_TO_FIELD = { provinces: null, kingdoms: "kingdom_id", great_houses: "great_house_id", minor_houses: "minor_house_id", free_cities: "free_city_id" };
  const REALM_OVERLAY_MODES = new Set(["kingdoms", "great_houses", "minor_houses"]);

  let state = null;
  let selectedKey = 0;
  let hideProvinceEmblems = false;
  const selectedKeys = new Set();

  function isPlainObject(value) {
    return !!value && typeof value === "object" && !Array.isArray(value);
  }

  function setTooltip(evt, text) { if (!text) { tooltip.style.display = "none"; return; } tooltip.textContent = text; tooltip.style.left = (evt.clientX + 12) + "px"; tooltip.style.top = (evt.clientY + 12) + "px"; tooltip.style.display = "block"; }
  function normalizePeopleList(arr) { const out = []; const seen = new Set(); for (const raw of (arr || [])) { const s = String(raw || "").trim(); if (!s) continue; const key = s.toLowerCase(); if (seen.has(key)) continue; seen.add(key); out.push(s); } return out.sort((a, b) => a.localeCompare(b, "ru")); }
  function ensurePerson(name) { const s = String(name || "").trim(); if (!s) return ""; const key = s.toLowerCase(); const has = state.people.some(p => p.toLowerCase() === key); if (!has) { state.people.push(s); state.people = normalizePeopleList(state.people); rebuildPeopleControls(); } return s; }
  function getProvData(key) { return state.provinces[String(key >>> 0)] || null; }
  function currentMode() { return viewModeSelect.value || "provinces"; }
  function realmBucketByType(type) { if (!state[type] || typeof state[type] !== "object") state[type] = {}; return state[type]; }

  function ensureFeudalSchema(obj) {
    if (!isPlainObject(obj.kingdoms)) obj.kingdoms = {};
    if (!isPlainObject(obj.great_houses)) obj.great_houses = {};
    if (!isPlainObject(obj.minor_houses)) obj.minor_houses = {};
    if (!isPlainObject(obj.free_cities)) obj.free_cities = {};
    for (const pd of Object.values(obj.provinces || {})) {
      if (!pd || typeof pd !== "object") continue;
      if (typeof pd.kingdom_id !== "string") pd.kingdom_id = "";
      if (typeof pd.great_house_id !== "string") pd.great_house_id = "";
      if (typeof pd.minor_house_id !== "string") pd.minor_house_id = "";
      if (typeof pd.free_city_id !== "string") pd.free_city_id = "";
    }
  }

  function rebuildPeopleControls() { /* unchanged */
    peopleDatalist.innerHTML = "";
    for (const p of state.people) { const opt = document.createElement("option"); opt.value = p; peopleDatalist.appendChild(opt); }
    const buildSelect = (sel, allowEmpty) => { const cur = sel.value || ""; sel.innerHTML = ""; if (allowEmpty) { const o0 = document.createElement("option"); o0.value = ""; o0.textContent = "—"; sel.appendChild(o0); } for (const p of state.people) { const o = document.createElement("option"); o.value = p; o.textContent = p; sel.appendChild(o); } sel.value = cur; };
    buildSelect(suzerainSelect, true); buildSelect(seniorSelect, true);
    const curSel = new Set(Array.from(vassalsSelect.selectedOptions || []).map(o => o.value));
    vassalsSelect.innerHTML = ""; for (const p of state.people) { const o = document.createElement("option"); o.value = p; o.textContent = p; if (curSel.has(p)) o.selected = true; vassalsSelect.appendChild(o); }
  }

  function rebuildTerrainSelect() { const list = Array.isArray(state.terrain_types) && state.terrain_types.length ? state.terrain_types : TERRAIN_TYPES_FALLBACK; const cur = terrainSelect.value || ""; terrainSelect.innerHTML = ""; const o0 = document.createElement("option"); o0.value = ""; o0.textContent = "—"; terrainSelect.appendChild(o0); for (const t of list) { const o = document.createElement("option"); o.value = t; o.textContent = t; terrainSelect.appendChild(o); } terrainSelect.value = cur; }

  function setEmblemPreview(pd) { const src = pd && pd.emblem_svg ? String(pd.emblem_svg) : ""; if (src) { emblemPreviewImg.src = src; emblemPreviewImg.style.display = "block"; emblemPreviewEmpty.style.display = "none"; } else { emblemPreviewImg.removeAttribute("src"); emblemPreviewImg.style.display = "none"; emblemPreviewEmpty.style.display = "block"; } }

  function setSelection(key, meta) {
    selectedKey = key >>> 0;
    const pd = getProvData(selectedKey);
    multiSelCount.textContent = String(selectedKeys.size || (selectedKey ? 1 : 0));
    if (!selectedKey || !pd) { selName.textContent = "—"; selPid.textContent = "—"; selKey.textContent = "—"; provNameInput.value = ""; ownerInput.value = ""; suzerainSelect.value = ""; seniorSelect.value = ""; Array.from(vassalsSelect.options).forEach(o => o.selected = false); terrainSelect.value = ""; setEmblemPreview(null); return; }
    selName.textContent = pd.name || (meta && meta.name) || "—"; selPid.textContent = String(pd.pid ?? (meta ? meta.pid : "—")); selKey.textContent = String(selectedKey);
    provNameInput.value = pd.name || ""; ownerInput.value = pd.owner || "";
    if (pd.owner) ensurePerson(pd.owner); if (pd.suzerain) ensurePerson(pd.suzerain); if (pd.senior) ensurePerson(pd.senior); if (Array.isArray(pd.vassals)) for (const v of pd.vassals) ensurePerson(v);
    suzerainSelect.value = pd.suzerain || ""; seniorSelect.value = pd.senior || ""; terrainSelect.value = pd.terrain || "";
    const vset = new Set(Array.isArray(pd.vassals) ? pd.vassals : []); for (const opt of vassalsSelect.options) opt.selected = vset.has(opt.value);
    if (pd.fill_rgba && Array.isArray(pd.fill_rgba) && pd.fill_rgba.length === 4) { const rgba = pd.fill_rgba; colorInput.value = MapUtils.rgbToHex(rgba[0], rgba[1], rgba[2]); alphaInput.value = String(rgba[3] | 0); alphaVal.textContent = String(rgba[3] | 0); }
    setEmblemPreview(pd);
  }

  function saveProvinceFieldsFromUI() { if (!selectedKey) return; const pd = getProvData(selectedKey); if (!pd) return; pd.name = String(provNameInput.value || "").trim(); pd.owner = ensurePerson(ownerInput.value); pd.suzerain = ensurePerson(suzerainSelect.value); pd.senior = ensurePerson(seniorSelect.value); pd.vassals = Array.from(vassalsSelect.selectedOptions || []).map(o => o.value).filter(Boolean); for (const v of pd.vassals) ensurePerson(v); pd.terrain = String(terrainSelect.value || "").trim(); selName.textContent = pd.name || selName.textContent; }
  function applyFillFromUI(map) { if (!selectedKey) return; const [r, g, b] = MapUtils.hexToRgb(colorInput.value); const a = Math.max(0, Math.min(255, parseInt(alphaInput.value, 10) | 0)); const rgba = [r, g, b, a]; const pd = getProvData(selectedKey); if (!pd) return; pd.fill_rgba = rgba; if (currentMode() === "provinces") map.setFill(selectedKey, rgba); }
  function exportStateToTextarea() { const out = JSON.parse(JSON.stringify(state)); out.generated_utc = new Date().toISOString(); stateTA.value = JSON.stringify(out, null, 2); }

  function buildRealmEntries(type) {
    const bucket = realmBucketByType(type);
    return Object.entries(bucket).map(([id, r]) => [id, Object.assign({ id, name: id, color: "#ff3b30", capital_key: 0, province_keys: [], emblem_svg: "", emblem_box: null, emblem_scale: 1 }, r)]);
  }

  function rebuildRealmSelect() {
    const type = realmTypeSelect.value;
    const cur = realmSelect.value;
    realmSelect.innerHTML = "";
    const o0 = document.createElement("option"); o0.value = ""; o0.textContent = "—"; realmSelect.appendChild(o0);
    for (const [id, realm] of buildRealmEntries(type)) { const o = document.createElement("option"); o.value = id; o.textContent = realm.name || id; realmSelect.appendChild(o); }
    realmSelect.value = cur;
    loadRealmFields();
  }

  function loadRealmFields() {
    const type = realmTypeSelect.value;
    const id = realmSelect.value;
    const realm = id ? realmBucketByType(type)[id] : null;
    realmNameInput.value = realm ? (realm.name || id) : "";
    realmColorInput.value = realm && realm.color ? realm.color : "#ff3b30";
    realmCapitalInput.value = realm && realm.capital_key ? String(realm.capital_key) : "";
    realmEmblemScaleInput.value = String(realm && realm.emblem_scale ? realm.emblem_scale : 1);
    realmEmblemScaleVal.textContent = realmEmblemScaleInput.value;
  }

  function ensureRealm(type, id) {
    const bucket = realmBucketByType(type);
    if (!bucket[id]) bucket[id] = { name: id, color: "#ff3b30", capital_key: 0, province_keys: [], emblem_svg: "", emblem_box: null, emblem_scale: 1 };
    return bucket[id];
  }

  function drawRealmLayer(map, type, opacity, emblemOpacity) {
    const field = MODE_TO_FIELD[type];
    const bucket = realmBucketByType(type);
    for (const [id, realm] of Object.entries(bucket)) {
      const keys = [];
      for (const [k, pd] of Object.entries(state.provinces)) {
        const key = Number(k) >>> 0;
        if (pd[field] === id) keys.push(key);
      }
      if (!keys.length) continue;
      const [r, g, b] = MapUtils.hexToRgb(realm.color || "#ff3b30");
      const cap = Number(realm.capital_key) >>> 0;
      for (const key of keys) map.setFill(key, [r, g, b, key === cap ? Math.min(255, opacity + 50) : opacity]);
      if (realm.emblem_svg) {
        const box = realm.emblem_box ? { w: +realm.emblem_box[0], h: +realm.emblem_box[1] } : { w: 2000, h: 2400 };
        map.setGroupEmblem(`${type}:${id}`, keys, realm.emblem_svg, box, { scale: realm.emblem_scale || 1, opacity: emblemOpacity });
      }
    }
  }

  function applyLayerState(map) {
    const mode = currentMode();
    map.clearAllFills();
    map.clearAllEmblems();

    for (const [k, pd] of Object.entries(state.provinces)) {
      const key = Number(k) >>> 0;
      if (mode === "provinces") {
        if (pd.fill_rgba && Array.isArray(pd.fill_rgba) && pd.fill_rgba.length === 4) map.setFill(key, pd.fill_rgba);
      }
      if (!hideProvinceEmblems && pd.emblem_svg) {
        const box = pd.emblem_box ? { w: +pd.emblem_box[0], h: +pd.emblem_box[1] } : { w: 2000, h: 2400 };
        map.setEmblem(key, pd.emblem_svg, box);
      }
    }

    if (mode !== "provinces") {
      drawRealmLayer(map, mode, 150, 0.6);
      if (REALM_OVERLAY_MODES.has(mode)) drawRealmLayer(map, "free_cities", 230, 0.75);
    }

    map.repaintAllEmblems().catch(() => {});
  }


  function syncProvEmblemsToggleLabel() {
    if (!toggleProvEmblemsBtn) return;
    toggleProvEmblemsBtn.textContent = hideProvinceEmblems ? "Показать геральдику провинций" : "Скрыть геральдику провинций";
  }

  function sanitizeSvgText(svgText) { return String(svgText || "").replace(/<script[\s\S]*?<\/script\s*>/gi, ""); }
  function svgTextToDataUri(svgText) { return "data:image/svg+xml;base64," + MapUtils.toBase64Utf8(sanitizeSvgText(svgText)); }
  function extractSvgBox(svgText) { const box = MapUtils.parseSvgBox(svgText); return [box.w, box.h]; }

  function initZoomControls(map) {
    const mapArea = document.getElementById("mapArea");
    const mapWrap = document.getElementById("mapWrap");
    const baseMap = document.getElementById("baseMap");
    if (!mapArea || !mapWrap || !baseMap) return;

    const MIN_ZOOM = 0.1;
    const MAX_ZOOM = 12;
    const WHEEL_FACTOR = 1.12;
    let currentScale = 1;

    function getBaseSize() {
      return [baseMap.naturalWidth || map.W || 0, baseMap.naturalHeight || map.H || 0];
    }

    function getFitScale() {
      const [W, H] = getBaseSize();
      if (!W || !H) return 1;
      const sx = mapArea.clientWidth / W;
      const sy = mapArea.clientHeight / H;
      return Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, Math.min(sx, sy)));
    }

    function setZoom(newScale, anchorClientX, anchorClientY) {
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
      mapWrap.style.width = Math.round(W * currentScale) + "px";
      mapWrap.style.height = Math.round(H * currentScale) + "px";

      mapArea.scrollLeft = Math.max(0, worldX * currentScale - anchorX);
      mapArea.scrollTop = Math.max(0, worldY * currentScale - anchorY);
    }

    document.querySelectorAll(".zoomBtn").forEach((btn) => {
      btn.addEventListener("click", () => {
        const target = btn.getAttribute("data-zoom");
        if (target === "fit") return setZoom(getFitScale());
        setZoom(target);
      });
    });

    mapArea.addEventListener("wheel", (evt) => {
      if (!evt.deltaY) return;
      evt.preventDefault();
      const nextScale = evt.deltaY < 0 ? currentScale * WHEEL_FACTOR : currentScale / WHEEL_FACTOR;
      setZoom(nextScale, evt.clientX - mapArea.getBoundingClientRect().left, evt.clientY - mapArea.getBoundingClientRect().top);
    }, { passive: false });

    window.addEventListener("resize", () => {
      if (Math.abs(currentScale - getFitScale()) < 0.001) setZoom(getFitScale());
    });

    setZoom(1);
  }


  function boot(map) {
    btnApplyFill.addEventListener("click", () => applyFillFromUI(map));
    btnClearFill.addEventListener("click", () => { if (!selectedKey) return; const pd = getProvData(selectedKey); if (pd) pd.fill_rgba = null; if (currentMode() === "provinces") map.clearFill(selectedKey); });
    btnSaveProv.addEventListener("click", () => { saveProvinceFieldsFromUI(); exportStateToTextarea(); });

    viewModeSelect.addEventListener("change", () => applyLayerState(map));
    if (toggleProvEmblemsBtn) {
      toggleProvEmblemsBtn.addEventListener("click", () => {
        hideProvinceEmblems = !hideProvinceEmblems;
        syncProvEmblemsToggleLabel();
        applyLayerState(map);
      });
      syncProvEmblemsToggleLabel();
    }
    realmTypeSelect.addEventListener("change", rebuildRealmSelect);
    realmSelect.addEventListener("change", loadRealmFields);
    btnNewRealm.addEventListener("click", () => { const id = prompt("ID сущности (латиница/цифры):"); if (!id) return; ensureRealm(realmTypeSelect.value, id.trim()); rebuildRealmSelect(); realmSelect.value = id.trim(); loadRealmFields(); exportStateToTextarea(); });
    btnSaveRealm.addEventListener("click", () => {
      const type = realmTypeSelect.value; const id = realmSelect.value; if (!id) return;
      const realm = ensureRealm(type, id);
      realm.name = String(realmNameInput.value || id).trim() || id;
      realm.color = String(realmColorInput.value || "#ff3b30");
      realm.capital_key = Number(realmCapitalInput.value) >>> 0;
      realm.emblem_scale = Math.max(0.2, Math.min(3, Number(realmEmblemScaleInput.value) || 1));
      rebuildRealmSelect(); realmSelect.value = id; loadRealmFields(); applyLayerState(map); exportStateToTextarea();
    });
    btnAddSelectedToRealm.addEventListener("click", () => {
      const type = realmTypeSelect.value; const id = realmSelect.value; if (!id) return;
      const field = MODE_TO_FIELD[type]; const realm = ensureRealm(type, id);
      const keys = selectedKeys.size ? Array.from(selectedKeys) : (selectedKey ? [selectedKey] : []);
      for (const key of keys) { const pd = getProvData(key); if (pd) pd[field] = id; }
      realm.province_keys = keys;
      applyLayerState(map); exportStateToTextarea();
    });
    btnRemoveSelectedFromRealm.addEventListener("click", () => {
      const type = realmTypeSelect.value; const id = realmSelect.value; if (!id) return;
      const field = MODE_TO_FIELD[type];
      const keys = selectedKeys.size ? Array.from(selectedKeys) : (selectedKey ? [selectedKey] : []);
      for (const key of keys) { const pd = getProvData(key); if (pd && pd[field] === id) pd[field] = ""; }
      applyLayerState(map); exportStateToTextarea();
    });

    realmUploadEmblemBtn.addEventListener("click", () => realmEmblemFile.click());
    realmEmblemFile.addEventListener("change", async () => {
      const file = realmEmblemFile.files && realmEmblemFile.files[0]; realmEmblemFile.value = ""; if (!file) return;
      const type = realmTypeSelect.value; const id = realmSelect.value; if (!id) return;
      const text = String(await file.text() || "").replace(/^﻿/, "");
      const realm = ensureRealm(type, id); realm.emblem_svg = svgTextToDataUri(text); realm.emblem_box = extractSvgBox(text);
      applyLayerState(map); exportStateToTextarea();
    });
    realmRemoveEmblemBtn.addEventListener("click", () => { const type = realmTypeSelect.value; const id = realmSelect.value; if (!id) return; const realm = ensureRealm(type, id); realm.emblem_svg = ""; realm.emblem_box = null; applyLayerState(map); exportStateToTextarea(); });

    btnExport.addEventListener("click", exportStateToTextarea);
    btnDownload.addEventListener("click", () => { exportStateToTextarea(); const blob = new Blob([stateTA.value], { type: "application/json;charset=utf-8" }); const a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = "map_state.json"; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 1000); });
    btnImport.addEventListener("click", () => importFile.click());
    importFile.addEventListener("change", async () => { const file = importFile.files && importFile.files[0]; if (!file) return; const txt = await file.text(); const obj = JSON.parse(txt); if (!obj.provinces) return alert("Нет provinces"); ensureFeudalSchema(obj); state = Object.assign(state, obj); applyLayerState(map); exportStateToTextarea(); importFile.value = ""; });
    btnSaveServer.addEventListener("click", async () => { exportStateToTextarea(); const res = await fetch(SAVE_ENDPOINT, { method: "POST", headers: { "Content-Type": "application/json;charset=utf-8" }, body: JSON.stringify({ token: SAVE_TOKEN, state: JSON.parse(stateTA.value) }) }); if (!res.ok) alert("Ошибка сохранения"); else alert("Сохранено"); });

    uploadEmblemBtn.addEventListener("click", () => { if (!selectedKey) return alert("Сначала выбери провинцию."); emblemFile.click(); });
    emblemFile.addEventListener("change", async () => { const file = emblemFile.files && emblemFile.files[0]; emblemFile.value = ""; if (!file || !selectedKey) return; const text = String(await file.text() || "").replace(/^﻿/, ""); const pd = getProvData(selectedKey); if (!pd) return; pd.emblem_svg = svgTextToDataUri(text); pd.emblem_box = extractSvgBox(text); setEmblemPreview(pd); applyLayerState(map); exportStateToTextarea(); });
    removeEmblemBtn.addEventListener("click", () => { if (!selectedKey) return; const pd = getProvData(selectedKey); if (!pd) return; pd.emblem_svg = ""; pd.emblem_box = null; setEmblemPreview(pd); applyLayerState(map); exportStateToTextarea(); });

    provNameInput.addEventListener("change", saveProvinceFieldsFromUI); ownerInput.addEventListener("change", () => { ensurePerson(ownerInput.value); saveProvinceFieldsFromUI(); });
    suzerainSelect.addEventListener("change", saveProvinceFieldsFromUI); seniorSelect.addEventListener("change", saveProvinceFieldsFromUI); vassalsSelect.addEventListener("change", saveProvinceFieldsFromUI); terrainSelect.addEventListener("change", saveProvinceFieldsFromUI);
  }

  async function loadInitialState(url) {
    const res = await fetch(url, { cache: "no-store" }); if (!res.ok) throw new Error("HTTP " + res.status + " for " + url);
    const obj = await res.json(); if (!obj || typeof obj !== "object" || !obj.provinces) throw new Error("Invalid state JSON");
    obj.people = normalizePeopleList(obj.people || []); if (!Array.isArray(obj.terrain_types)) obj.terrain_types = TERRAIN_TYPES_FALLBACK.slice();
    for (const pd of Object.values(obj.provinces)) { if (!pd) continue; if (typeof pd.emblem_svg !== "string") pd.emblem_svg = ""; if (!Array.isArray(pd.emblem_box) || pd.emblem_box.length !== 2) pd.emblem_box = null; }
    ensureFeudalSchema(obj);
    return obj;
  }

  async function main() {
    state = await loadInitialState(STATE_URL_DEFAULT);
    rebuildPeopleControls(); rebuildTerrainSelect(); rebuildRealmSelect();

    const map = new RasterProvinceMap({
      baseImgId: "baseMap", fillCanvasId: "fill", emblemCanvasId: "emblems", hoverCanvasId: "hover", provincesMetaUrl: "provinces.json", maskUrl: "provinces_id.png",
      onHover: ({ key, meta, evt }) => { if (!key) { tooltip.style.display = "none"; return; } const pd = state.provinces[String(key)] || {}; const label = (pd.name || (meta && meta.name) || ("Провинция " + (meta ? meta.pid : ""))); setTooltip(evt, label + " (ID " + (pd.pid || (meta && meta.pid) || "?") + ")"); },
      onClick: ({ key, meta, evt }) => {
        if (evt.ctrlKey || evt.metaKey || evt.shiftKey) {
          if (selectedKeys.has(key)) selectedKeys.delete(key); else selectedKeys.add(key);
        } else {
          selectedKeys.clear(); selectedKeys.add(key);
        }
        setSelection(key, meta);
      },
      onReady: () => applyLayerState(map)
    });

    await map.init();
    initZoomControls(map);
    boot(map);
    setSelection(0, null);
    exportStateToTextarea();
  }

  main().catch(err => { console.error(err); alert("Ошибка запуска админки: " + err.message); });
})();
