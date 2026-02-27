/* admin.js (zoom + emblems + feudal layers) */

(function () {
  "use strict";

  const el = (id) => document.getElementById(id);

  const tooltip = el("tooltip");
  const flagsStatusEl = el("flagsStatus");
  const btnEnableBackendMode = el("enableBackendMode");

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

  const minorGreatHouseSelect = el("minorGreatHouseSelect");
  const minorVassalSelect = el("minorVassalSelect");
  const minorVassalName = el("minorVassalName");
  const minorSetCapitalBtn = el("minorSetCapital");
  const minorAddDomainBtn = el("minorAddDomain");
  const minorRemoveDomainBtn = el("minorRemoveDomain");
  const minorCreateVassalBtn = el("minorCreateVassal");
  const minorSetVassalCapitalBtn = el("minorSetVassalCapital");
  const minorAddVassalProvincesBtn = el("minorAddVassalProvinces");
  const minorRemoveVassalProvincesBtn = el("minorRemoveVassalProvinces");
  const minorPalette = el("minorPalette");

  const btnExport = el("export");
  const btnDownload = el("download");
  const btnExportMigrated = el("exportMigrated");
  const btnImport = el("import");
  const importFile = el("importFile");

  const btnSaveServer = el("saveServer");
  const stateTA = el("state");
  const btnExportProvincesPng = el("exportProvincesPng");
  const btnExportKingdomsPng = el("exportKingdomsPng");
  const btnExportGreatHousesPng = el("exportGreatHousesPng");
  const btnExportMinorHousesPng = el("exportMinorHousesPng");


  const uploadEmblemBtn = el("uploadEmblemBtn");
  const removeEmblemBtn = el("removeEmblemBtn");
  const emblemFile = el("emblemFile");
  const emblemPreviewImg = el("emblemPreviewImg");
  const emblemPreviewEmpty = el("emblemPreviewEmpty");
  const uploadProvinceImageBtn = el("uploadProvinceImageBtn");
  const buildProvinceImageBtn = el("buildProvinceImageBtn");
  const provinceImageFile = el("provinceImageFile");
  const provinceCardPreviewImg = el("provinceCardPreviewImg");
  const provinceCardPreviewEmpty = el("provinceCardPreviewEmpty");

  alphaInput.addEventListener("input", () => alphaVal.textContent = alphaInput.value);
  realmEmblemScaleInput.addEventListener("input", () => realmEmblemScaleVal.textContent = realmEmblemScaleInput.value);

  const STATE_URL_DEFAULT = "data/map_state.json";
  const SAVE_ENDPOINT = "save_state.php";
  const SAVE_TOKEN = "";
  const PROVINCE_PATCH_ENDPOINT = "/api/provinces/patch/";
  const REALM_PATCH_ENDPOINT = "/api/realms/patch/";
  const CHANGES_APPLY_ENDPOINT = "/api/changes/apply/";
  const APP_FLAGS = (window.AdminMapStateLoader && typeof window.AdminMapStateLoader.getFlags === "function") ? window.AdminMapStateLoader.getFlags() : (window.ADMINMAP_FLAGS || {});
  updateFlagsStatusText(APP_FLAGS);

  const TERRAIN_TYPES_FALLBACK = ["равнины", "холмы", "горы", "лес", "болота", "степь", "пустоши", "побережье", "остров", "город", "руины", "озёра/реки"];
  const MODE_TO_FIELD = { provinces: null, kingdoms: "kingdom_id", great_houses: "great_house_id", minor_houses: "minor_house_id", free_cities: "free_city_id" };
  const REALM_OVERLAY_MODES = new Set(["kingdoms", "great_houses", "minor_houses"]);
  const MINOR_ALPHA = { rest: 40, vassal: 100, vassal_capital: 170, domain: 160, capital: 200 };

  let state = null;
  let selectedKey = 0;
  let hideProvinceEmblems = false;
  const selectedKeys = new Set();
  const keyByPid = new Map();
  const pidByKey = new Map();
  const hexmapData = window.HEXMAP || null;
  const provinceCardBaseByPid = new Map();
  const CARD_TARGET_W = 1280;
  const CARD_TARGET_H = 720;
  const CARD_OUTPUT_QUALITY = 0.82;

  function loadImage(src) {
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error("Не удалось загрузить изображение"));
      img.src = src;
    });
  }

  function fileToDataUrl(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result || ""));
      reader.onerror = () => reject(new Error("Не удалось прочитать файл"));
      reader.readAsDataURL(file);
    });
  }

  function isPlainObject(value) {
    return !!value && typeof value === "object" && !Array.isArray(value);
  }

  function setTooltip(evt, text) { if (!text) { tooltip.style.display = "none"; return; } tooltip.textContent = text; tooltip.style.left = (evt.clientX + 12) + "px"; tooltip.style.top = (evt.clientY + 12) + "px"; tooltip.style.display = "block"; }

  function navigateToBackendMode() {
    const u = new URL(window.location.href);
    u.searchParams.set('use_chunked_api', '1');
    u.searchParams.set('use_emblem_assets', '1');
    u.searchParams.set('use_partial_save', '1');
    u.searchParams.set('use_server_render', '1');
    window.location.href = u.toString();
  }

  function updateFlagsStatusText(flags) {
    if (!flagsStatusEl) return;
    const active = [];
    if (flags && flags.USE_CHUNKED_API) active.push('USE_CHUNKED_API');
    if (flags && flags.USE_EMBLEM_ASSETS) active.push('USE_EMBLEM_ASSETS');
    if (flags && flags.USE_PARTIAL_SAVE) active.push('USE_PARTIAL_SAVE');
    if (flags && flags.USE_SERVER_RENDER) active.push('USE_SERVER_RENDER');
    flagsStatusEl.textContent = active.length ? ('Флаги: ' + active.join(', ')) : 'Флаги: legacy';
  }

  function normalizePeopleList(arr) { const out = []; const seen = new Set(); for (const raw of (arr || [])) { const s = String(raw || "").trim(); if (!s) continue; const key = s.toLowerCase(); if (seen.has(key)) continue; seen.add(key); out.push(s); } return out.sort((a, b) => a.localeCompare(b, "ru")); }
  function ensurePerson(name) { const s = String(name || "").trim(); if (!s) return ""; const key = s.toLowerCase(); const has = state.people.some(p => p.toLowerCase() === key); if (!has) { state.people.push(s); state.people = normalizePeopleList(state.people); rebuildPeopleControls(); } return s; }
  function rebuildPidKeyMaps(map) {
    keyByPid.clear(); pidByKey.clear();
    for (const [key, meta] of map.provincesByKey.entries()) {
      const pid = meta && meta.pid != null ? Number(meta.pid) : 0;
      if (pid > 0) { keyByPid.set(pid, key >>> 0); pidByKey.set(key >>> 0, pid); }
    }
  }
  function keyForPid(pid) { const p = Number(pid); return keyByPid.get(p) || 0; }
  function getProvData(key) { const pid = pidByKey.get(key >>> 0) || 0; return pid ? (state.provinces[String(pid)] || null) : null; }
  function currentMode() { return viewModeSelect.value || "provinces"; }
  function realmBucketByType(type) { if (!state[type] || typeof state[type] !== "object") state[type] = {}; return state[type]; }

  function ensureFeudalSchema(obj) {
    if (!isPlainObject(obj.kingdoms)) obj.kingdoms = {};
    if (!isPlainObject(obj.great_houses)) obj.great_houses = {};
    if (!isPlainObject(obj.minor_houses)) obj.minor_houses = {};
    if (!isPlainObject(obj.free_cities)) obj.free_cities = {};
    for (const realm of Object.values(obj.great_houses || {})) {
      if (!realm || typeof realm !== "object") continue;
      if (!isPlainObject(realm.minor_house_layer)) realm.minor_house_layer = {};
      const layer = realm.minor_house_layer;
      if (!Array.isArray(layer.domain_pids)) layer.domain_pids = [];
      layer.domain_pids = layer.domain_pids.map(v => Number(v) >>> 0).filter(Boolean);
      if (!(Number(layer.capital_pid) > 0)) layer.capital_pid = Number(realm.capital_pid || realm.capital_key || 0) >>> 0;
      if (!Array.isArray(layer.vassals)) layer.vassals = [];
      layer.vassals = layer.vassals.map((v, idx) => ({
        id: String(v && v.id || `vassal_${idx + 1}`).trim() || `vassal_${idx + 1}`,
        name: String(v && (v.name || v.id) || `Вассал ${idx + 1}`).trim() || `Вассал ${idx + 1}`,
        color: String(v && v.color || ""),
        capital_pid: Number(v && v.capital_pid || 0) >>> 0,
        province_pids: Array.isArray(v && v.province_pids) ? v.province_pids.map(x => Number(x) >>> 0).filter(Boolean) : []
      }));
    }
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

  function setEmblemPreview(pd) { const src = emblemSourceToDataUri(pd && pd.emblem_svg ? String(pd.emblem_svg) : ""); if (src) { emblemPreviewImg.src = src; emblemPreviewImg.style.display = "block"; emblemPreviewEmpty.style.display = "none"; } else { emblemPreviewImg.removeAttribute("src"); emblemPreviewImg.style.display = "none"; emblemPreviewEmpty.style.display = "block"; } }
  function setProvinceCardPreview(pd) { const pid = pd ? (Number(pd.pid) >>> 0) : 0; const baseSrc = pid ? (provinceCardBaseByPid.get(pid) || "") : ""; const src = String((pd && pd.province_card_image) || baseSrc || "").trim(); if (src) { provinceCardPreviewImg.src = src; provinceCardPreviewImg.style.display = "block"; provinceCardPreviewEmpty.style.display = "none"; } else { provinceCardPreviewImg.removeAttribute("src"); provinceCardPreviewImg.style.display = "none"; provinceCardPreviewEmpty.style.display = "block"; } }
  function getProvinceOwnerColor(pd) {
    if (pd && Array.isArray(pd.fill_rgba) && pd.fill_rgba.length >= 3) return [pd.fill_rgba[0] | 0, pd.fill_rgba[1] | 0, pd.fill_rgba[2] | 0];
    if (pd && pd.kingdom_id) {
      const realm = realmBucketByType("kingdoms")[pd.kingdom_id];
      if (realm && realm.color) return MapUtils.hexToRgb(realm.color);
    }
    return [90, 117, 146];
  }
  function getHexesForProvincePid(pid) {
    if (!hexmapData || !Array.isArray(hexmapData.hexes)) return [];
    const p = Number(pid) >>> 0;
    return hexmapData.hexes.filter(h => (Number(h.p) >>> 0) === p);
  }
  function drawImageCover(ctx, img, dw, dh) {
    const iw = Math.max(1, img.naturalWidth || img.width || 1);
    const ih = Math.max(1, img.naturalHeight || img.height || 1);
    const scale = Math.max(dw / iw, dh / ih);
    const sw = Math.max(1, Math.round(dw / scale));
    const sh = Math.max(1, Math.round(dh / scale));
    const sx = Math.max(0, Math.floor((iw - sw) * 0.5));
    const sy = Math.max(0, Math.floor((ih - sh) * 0.5));
    ctx.drawImage(img, sx, sy, sw, sh, 0, 0, dw, dh);
  }
  async function composeProvinceCardImage(pd, baseSrc) {
    const baseImageSrc = String(baseSrc || "").trim();
    if (!baseImageSrc) throw new Error("Сначала загрузи фон карточки провинции");
    const baseImg = await loadImage(baseImageSrc);
    const w = CARD_TARGET_W;
    const h = CARD_TARGET_H;
    const canvas = document.createElement("canvas");
    canvas.width = w; canvas.height = h;
    const ctx = canvas.getContext("2d");
    if (!ctx) throw new Error("Canvas не инициализирован");
    drawImageCover(ctx, baseImg, w, h);

    const kingdom = pd && pd.kingdom_id ? realmBucketByType("kingdoms")[pd.kingdom_id] : null;
    const kingdomSrc = emblemSourceToDataUri(kingdom && kingdom.emblem_svg);
    if (kingdomSrc) {
      try {
        const kimg = await loadImage(kingdomSrc);
        const size = Math.round(Math.min(w, h) * 0.22);
        const m = Math.round(Math.min(w, h) * 0.04);
        ctx.drawImage(kimg, m, m, size, size);
      } catch (_) {}
    }

    const boxSize = Math.round(Math.min(w, h) * 0.34);
    const margin = Math.round(Math.min(w, h) * 0.04);
    const boxX = w - boxSize - margin;
    const boxY = h - boxSize - margin;
    ctx.fillStyle = "rgba(8,12,17,0.8)";
    ctx.strokeStyle = "rgba(43,63,86,0.9)";
    ctx.lineWidth = Math.max(1, Math.round(boxSize * 0.01));
    ctx.fillRect(boxX, boxY, boxSize, boxSize);
    ctx.strokeRect(boxX, boxY, boxSize, boxSize);

    const hexes = getHexesForProvincePid(pd.pid);
    if (hexes.length) {
      let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
      for (const hex of hexes) {
        minX = Math.min(minX, Number(hex.cx) - 1.2 * Number(hexmapData.hexSize || 1));
        minY = Math.min(minY, Number(hex.cy) - 1.2 * Number(hexmapData.hexSize || 1));
        maxX = Math.max(maxX, Number(hex.cx) + 1.2 * Number(hexmapData.hexSize || 1));
        maxY = Math.max(maxY, Number(hex.cy) + 1.2 * Number(hexmapData.hexSize || 1));
      }
      const pw = Math.max(1, maxX - minX);
      const ph = Math.max(1, maxY - minY);
      const scale = Math.min((boxSize * 0.84) / pw, (boxSize * 0.84) / ph);
      const ox = boxX + (boxSize - pw * scale) * 0.5 - minX * scale;
      const oy = boxY + (boxSize - ph * scale) * 0.5 - minY * scale;
      const [fr, fg, fb] = getProvinceOwnerColor(pd);
      const r = Number(hexmapData.hexSize || 1) * scale;
      ctx.fillStyle = `rgb(${fr},${fg},${fb})`;
      ctx.strokeStyle = "rgba(0,0,0,0.45)";
      ctx.lineWidth = Math.max(1, r * 0.12);
      for (const hex of hexes) {
        const cx = Number(hex.cx) * scale + ox;
        const cy = Number(hex.cy) * scale + oy;
        ctx.beginPath();
        for (let i = 0; i < 6; i++) {
          const a = (Math.PI / 180) * (60 * i);
          const x = cx + r * Math.cos(a);
          const y = cy + r * Math.sin(a);
          if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        }
        ctx.closePath();
        ctx.fill();
        ctx.stroke();
      }
    }

    const provEmblemSrc = emblemSourceToDataUri(pd && pd.emblem_svg);
    if (provEmblemSrc) {
      try {
        const pimg = await loadImage(provEmblemSrc);
        const es = Math.round(boxSize * 0.34);
        ctx.drawImage(pimg, boxX + boxSize - es - Math.round(boxSize * 0.06), boxY + boxSize - es - Math.round(boxSize * 0.06), es, es);
      } catch (_) {}
    }

    return canvas.toDataURL("image/jpeg", CARD_OUTPUT_QUALITY);
  }

  function setSelection(key, meta) {
    selectedKey = key >>> 0;
    const pd = getProvData(selectedKey);
    multiSelCount.textContent = String(selectedKeys.size || (selectedKey ? 1 : 0));
    if (!selectedKey || !pd) { selName.textContent = "—"; selPid.textContent = "—"; selKey.textContent = "—"; provNameInput.value = ""; ownerInput.value = ""; suzerainSelect.value = ""; seniorSelect.value = ""; Array.from(vassalsSelect.options).forEach(o => o.selected = false); terrainSelect.value = ""; setEmblemPreview(null); setProvinceCardPreview(null); return; }
    selName.textContent = pd.name || (meta && meta.name) || "—"; selPid.textContent = String(pd.pid ?? (meta ? meta.pid : "—")); selKey.textContent = String(selectedKey);
    provNameInput.value = pd.name || ""; ownerInput.value = pd.owner || "";
    if (pd.owner) ensurePerson(pd.owner); if (pd.suzerain) ensurePerson(pd.suzerain); if (pd.senior) ensurePerson(pd.senior); if (Array.isArray(pd.vassals)) for (const v of pd.vassals) ensurePerson(v);
    suzerainSelect.value = pd.suzerain || ""; seniorSelect.value = pd.senior || ""; terrainSelect.value = pd.terrain || "";
    const vset = new Set(Array.isArray(pd.vassals) ? pd.vassals : []); for (const opt of vassalsSelect.options) opt.selected = vset.has(opt.value);
    if (pd.fill_rgba && Array.isArray(pd.fill_rgba) && pd.fill_rgba.length === 4) { const rgba = pd.fill_rgba; colorInput.value = MapUtils.rgbToHex(rgba[0], rgba[1], rgba[2]); alphaInput.value = String(rgba[3] | 0); alphaVal.textContent = String(rgba[3] | 0); }
    setEmblemPreview(pd);
    setProvinceCardPreview(pd);
  }

  function saveProvinceFieldsFromUI() { if (!selectedKey) return; const pd = getProvData(selectedKey); if (!pd) return; pd.name = String(provNameInput.value || "").trim(); pd.owner = ensurePerson(ownerInput.value); pd.suzerain = ensurePerson(suzerainSelect.value); pd.senior = ensurePerson(seniorSelect.value); pd.vassals = Array.from(vassalsSelect.selectedOptions || []).map(o => o.value).filter(Boolean); for (const v of pd.vassals) ensurePerson(v); pd.terrain = String(terrainSelect.value || "").trim(); if (typeof pd.province_card_image !== "string") pd.province_card_image = ""; selName.textContent = pd.name || selName.textContent; }
  function applyFillFromUI(map) { if (!selectedKey) return; const [r, g, b] = MapUtils.hexToRgb(colorInput.value); const a = Math.max(0, Math.min(255, parseInt(alphaInput.value, 10) | 0)); const rgba = [r, g, b, a]; const pd = getProvData(selectedKey); if (!pd) return; pd.fill_rgba = rgba; if (currentMode() === "provinces") map.setFill(selectedKey, rgba); }
  function exportStateToTextarea() { const out = JSON.parse(JSON.stringify(state)); for (const pd of Object.values(out.provinces || {})) { if (!pd || typeof pd !== "object") continue; if (typeof pd.province_card_base_image === "string" && pd.province_card_base_image.startsWith("data:")) pd.province_card_base_image = ""; } out.generated_utc = new Date().toISOString(); stateTA.value = JSON.stringify(out, null, 2); }
  function downloadJsonFile(filename, payload) { const blob = new Blob([JSON.stringify(payload, null, 2)], { type: "application/json;charset=utf-8" }); const a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = filename; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 1000); }
  function buildProvincePatchFromState(pd) { return { name: String(pd.name || ""), owner: String(pd.owner || ""), suzerain: String(pd.suzerain || ""), senior: String(pd.senior || ""), terrain: String(pd.terrain || ""), vassals: Array.isArray(pd.vassals) ? pd.vassals.map(v => String(v || "").trim()).filter(Boolean) : [], fill_rgba: (Array.isArray(pd.fill_rgba) && pd.fill_rgba.length === 4) ? pd.fill_rgba : null, emblem_svg: String(pd.emblem_svg || ""), emblem_box: (Array.isArray(pd.emblem_box) && pd.emblem_box.length === 2) ? pd.emblem_box : null, emblem_asset_id: String(pd.emblem_asset_id || ""), kingdom_id: String(pd.kingdom_id || ""), great_house_id: String(pd.great_house_id || ""), minor_house_id: String(pd.minor_house_id || ""), free_city_id: String(pd.free_city_id || ""), province_card_image: String(pd.province_card_image || "") }; }
  async function persistChangesBatch(changes) { const payload = { changes: Array.isArray(changes) ? changes : [] }; const res = await fetch(CHANGES_APPLY_ENDPOINT, { method: "POST", headers: { "Content-Type": "application/json;charset=utf-8" }, body: JSON.stringify(payload) }); if (!res.ok) throw new Error("HTTP " + res.status); }
  async function persistSelectedProvincePatch() { if (!selectedKey) return; const pd = getProvData(selectedKey); if (!pd) return; const payload = { pid: Number(pd.pid) >>> 0, changes: buildProvincePatchFromState(pd) }; if (APP_FLAGS && APP_FLAGS.USE_PARTIAL_SAVE) return persistChangesBatch([{ kind: "province", pid: payload.pid, changes: payload.changes }]); const res = await fetch(PROVINCE_PATCH_ENDPOINT, { method: "PATCH", headers: { "Content-Type": "application/json;charset=utf-8" }, body: JSON.stringify(payload) }); if (!res.ok) throw new Error("HTTP " + res.status); }
  function buildRealmPatchFromState(realm) { return { name: String(realm.name || ""), color: String(realm.color || "#ff3b30"), capital_pid: Number(realm.capital_pid || 0) >>> 0, emblem_scale: Math.max(0.2, Math.min(3, Number(realm.emblem_scale) || 1)), emblem_svg: String(realm.emblem_svg || ""), emblem_box: (Array.isArray(realm.emblem_box) && realm.emblem_box.length === 2) ? realm.emblem_box : null, province_pids: Array.isArray(realm.province_pids) ? realm.province_pids.map(v => Number(v) >>> 0).filter(Boolean) : [] }; }
  async function persistRealmPatch(type, id, realm) { const payload = { type: String(type || ""), id: String(id || ""), changes: buildRealmPatchFromState(realm) }; if (APP_FLAGS && APP_FLAGS.USE_PARTIAL_SAVE) return persistChangesBatch([{ kind: "realm", type: payload.type, id: payload.id, changes: payload.changes }]); const res = await fetch(REALM_PATCH_ENDPOINT, { method: "PATCH", headers: { "Content-Type": "application/json;charset=utf-8" }, body: JSON.stringify(payload) }); if (!res.ok) throw new Error("HTTP " + res.status); }


  function rgbToHsl(r, g, b) {
    const rn = r / 255, gn = g / 255, bn = b / 255;
    const max = Math.max(rn, gn, bn), min = Math.min(rn, gn, bn);
    const l = (max + min) / 2;
    if (max === min) return [0, 0, l];
    const d = max - min;
    const s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
    let h = 0;
    if (max === rn) h = ((gn - bn) / d + (gn < bn ? 6 : 0));
    else if (max === gn) h = ((bn - rn) / d + 2);
    else h = ((rn - gn) / d + 4);
    return [h * 60, s, l];
  }

  function hslToRgb(h, s, l) {
    const c = (1 - Math.abs(2 * l - 1)) * s;
    const hh = ((h % 360) + 360) % 360 / 60;
    const x = c * (1 - Math.abs(hh % 2 - 1));
    let r1 = 0, g1 = 0, b1 = 0;
    if (hh < 1) [r1, g1, b1] = [c, x, 0];
    else if (hh < 2) [r1, g1, b1] = [x, c, 0];
    else if (hh < 3) [r1, g1, b1] = [0, c, x];
    else if (hh < 4) [r1, g1, b1] = [0, x, c];
    else if (hh < 5) [r1, g1, b1] = [x, 0, c];
    else [r1, g1, b1] = [c, 0, x];
    const m = l - c / 2;
    return [Math.round((r1 + m) * 255), Math.round((g1 + m) * 255), Math.round((b1 + m) * 255)];
  }

  function buildVassalPalette(baseHex) {
    const [r, g, b] = MapUtils.hexToRgb(baseHex || "#ff3b30");
    const [h, s, l] = rgbToHsl(r, g, b);
    const shades = [];
    const seen = new Set([MapUtils.rgbToHex(r, g, b)]);
    const satShifts = [-0.30, -0.2, -0.1, 0.1, 0.2, 0.3];
    const lumShifts = [-0.25, -0.18, -0.1, 0.1, 0.18, 0.25];
    for (const ds of satShifts) {
      for (const dl of lumShifts) {
        const ns = Math.max(0.15, Math.min(0.95, s + ds));
        const nl = Math.max(0.2, Math.min(0.82, l + dl));
        const rgb = hslToRgb(h, ns, nl);
        const hex = MapUtils.rgbToHex(rgb[0], rgb[1], rgb[2]);
        if (seen.has(hex)) continue;
        seen.add(hex);
        shades.push(hex);
      }
    }
    return shades.slice(0, 10);
  }

  function getGreatHouseMinorLayer(greatHouseId) {
    const realm = realmBucketByType("great_houses")[greatHouseId];
    if (!realm) return null;
    if (!isPlainObject(realm.minor_house_layer)) realm.minor_house_layer = {};
    const layer = realm.minor_house_layer;
    if (!Array.isArray(layer.domain_pids)) layer.domain_pids = [];
    if (!Array.isArray(layer.vassals)) layer.vassals = [];
    if (!(Number(layer.capital_pid) > 0)) layer.capital_pid = Number(realm.capital_pid || realm.capital_key || 0) >>> 0;
    return layer;
  }

  function getMinorHouseHoverKeys(pid) {
    const pd = state.provinces[String(Number(pid) >>> 0)] || null;
    if (!pd || !pd.great_house_id) return [];

    const layer = getGreatHouseMinorLayer(pd.great_house_id);
    if (!layer) return [];

    const hoveredPid = Number(pd.pid) >>> 0;
    if (!hoveredPid) return [];

    const hoveredVassal = (layer.vassals || []).find(v => (v.province_pids || []).some(x => (Number(x) >>> 0) === hoveredPid));
    const pids = hoveredVassal ? (hoveredVassal.province_pids || []) : (layer.domain_pids || []);
    return pids.map(pidVal => keyForPid(pidVal)).filter(Boolean);
  }

  function drawMinorHousesLayer(map) {
    drawRealmLayer(map, "great_houses", MINOR_ALPHA.rest, 0);
    drawRealmLayer(map, "free_cities", 230, 0);
    for (const [id, realm] of Object.entries(realmBucketByType("great_houses"))) {
      const baseHex = realm && realm.color ? realm.color : "#ff3b30";
      const [r, g, b] = MapUtils.hexToRgb(baseHex);
      const allKeys = [];
      for (const pd of Object.values(state.provinces || {})) {
        if (!pd || pd.great_house_id !== id) continue;
        const key = keyForPid(pd.pid);
        if (key) allKeys.push(key);
      }
      if (!allKeys.length) continue;

      const layer = getGreatHouseMinorLayer(id);
      const capKey = keyForPid(layer && layer.capital_pid ? layer.capital_pid : 0);
      const domainKeys = new Set((layer && layer.domain_pids || []).map(pid => keyForPid(pid)).filter(Boolean));
      const vassalPalette = buildVassalPalette(baseHex);

      const vassalKeys = new Set();
      for (let i = 0; i < (layer && layer.vassals ? layer.vassals.length : 0); i++) {
        const v = layer.vassals[i];
        const vHex = v.color || vassalPalette[i % vassalPalette.length] || baseHex;
        v.color = vHex;
        const [vr, vg, vb] = MapUtils.hexToRgb(vHex);
        for (const pid of (v.province_pids || [])) {
          const key = keyForPid(pid);
          if (!key) continue;
          vassalKeys.add(key);
          const isVassalCapital = (Number(v.capital_pid) >>> 0) === (Number(pid) >>> 0);
          map.setFill(key, [vr, vg, vb, isVassalCapital ? MINOR_ALPHA.vassal_capital : MINOR_ALPHA.vassal]);
        }
      }

      for (const key of allKeys) {
        if (key === capKey) continue;
        if (domainKeys.has(key)) continue;
        if (vassalKeys.has(key)) continue;
        map.setFill(key, [r, g, b, MINOR_ALPHA.rest]);
      }
      for (const key of domainKeys) {
        if (key === capKey || vassalKeys.has(key)) continue;
        map.setFill(key, [r, g, b, MINOR_ALPHA.domain]);
      }
      if (capKey) {
        map.setFill(capKey, [r, g, b, MINOR_ALPHA.capital]);
        const emblemSrc = emblemSourceToDataUri(realm.emblem_svg);
        if (emblemSrc) {
          const box = realm.emblem_box ? { w: +realm.emblem_box[0], h: +realm.emblem_box[1] } : { w: 2000, h: 2400 };
          map.setEmblem(capKey, emblemSrc, box, { scale: realm.emblem_scale || 1 });
        }
      }
    }
  }

  function renderMinorPalette(greatHouseId) {
    if (!minorPalette) return;
    minorPalette.innerHTML = "";
    const realm = realmBucketByType("great_houses")[greatHouseId] || null;
    const shades = buildVassalPalette(realm && realm.color ? realm.color : "#ff3b30");
    for (const hex of shades) {
      const d = document.createElement("div");
      d.className = "swatch";
      d.title = hex;
      d.style.background = hex;
      minorPalette.appendChild(d);
    }
  }

  function rebuildMinorHouseControls() {
    if (!minorGreatHouseSelect) return;
    const curGh = minorGreatHouseSelect.value;
    minorGreatHouseSelect.innerHTML = "";
    const o0 = document.createElement("option"); o0.value = ""; o0.textContent = "—"; minorGreatHouseSelect.appendChild(o0);
    for (const [id, realm] of buildRealmEntries("great_houses")) {
      const o = document.createElement("option");
      o.value = id;
      o.textContent = realm.name || id;
      minorGreatHouseSelect.appendChild(o);
    }
    minorGreatHouseSelect.value = curGh && realmBucketByType("great_houses")[curGh] ? curGh : minorGreatHouseSelect.value;
    const gh = minorGreatHouseSelect.value;
    const layer = gh ? getGreatHouseMinorLayer(gh) : null;
    if (minorVassalSelect) {
      const curV = minorVassalSelect.value;
      minorVassalSelect.innerHTML = "";
      const z = document.createElement("option"); z.value = ""; z.textContent = "—"; minorVassalSelect.appendChild(z);
      for (const v of (layer && layer.vassals || [])) {
        const o = document.createElement("option");
        o.value = v.id;
        o.textContent = v.name || v.id;
        minorVassalSelect.appendChild(o);
      }
      minorVassalSelect.value = curV;
    }
    renderMinorPalette(gh);
  }

  function buildRealmEntries(type) {
    const bucket = realmBucketByType(type);
    return Object.entries(bucket).map(([id, r]) => [id, Object.assign({ id, name: id, color: "#ff3b30", capital_pid: 0, province_pids: [], emblem_svg: "", emblem_box: null, emblem_scale: 1 }, r)]);
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
    realmCapitalInput.value = realm && (realm.capital_pid || realm.capital_key) ? String(realm.capital_pid || realm.capital_key) : "";
    realmEmblemScaleInput.value = String(realm && realm.emblem_scale ? realm.emblem_scale : 1);
    realmEmblemScaleVal.textContent = realmEmblemScaleInput.value;
  }

  function ensureRealm(type, id) {
    const bucket = realmBucketByType(type);
    if (!bucket[id]) bucket[id] = { name: id, color: "#ff3b30", capital_pid: 0, province_pids: [], emblem_svg: "", emblem_box: null, emblem_scale: 1 };
    return bucket[id];
  }

  function drawRealmLayer(map, type, opacity, emblemOpacity) {
    const field = MODE_TO_FIELD[type];
    const bucket = realmBucketByType(type);
    for (const [id, realm] of Object.entries(bucket)) {
      const keys = [];
      for (const pd of Object.values(state.provinces)) {
        if (pd[field] !== id) continue;
        const key = keyForPid(pd.pid);
        if (key) keys.push(key);
      }
      if (!keys.length) continue;
      const [r, g, b] = MapUtils.hexToRgb(realm.color || "#ff3b30");
      const cap = keyForPid(realm.capital_pid || realm.capital_key || realm.capital);
      for (const key of keys) map.setFill(key, [r, g, b, key === cap ? Math.min(255, opacity + 50) : opacity]);
      const emblemSrc = emblemSourceToDataUri(realm.emblem_svg);
      if (emblemSrc) {
        const box = realm.emblem_box ? { w: +realm.emblem_box[0], h: +realm.emblem_box[1] } : { w: 2000, h: 2400 };
        map.setGroupEmblem(`${type}:${id}`, keys, emblemSrc, box, { scale: realm.emblem_scale || 1, opacity: emblemOpacity });
      }
    }
  }

  function applyLayerState(map) {
    const mode = currentMode();
    map.clearAllFills();
    map.clearAllEmblems();

    for (const pd of Object.values(state.provinces)) {
      const key = keyForPid(pd.pid);
      if (!key) continue;
      if (mode === "provinces") {
        if (pd.fill_rgba && Array.isArray(pd.fill_rgba) && pd.fill_rgba.length === 4) map.setFill(key, pd.fill_rgba);
      }
      const emblemSrc = emblemSourceToDataUri(pd.emblem_svg);
      if (!hideProvinceEmblems && emblemSrc) {
        const box = pd.emblem_box ? { w: +pd.emblem_box[0], h: +pd.emblem_box[1] } : { w: 2000, h: 2400 };
        map.setEmblem(key, emblemSrc, box);
      }
    }

    if (mode !== "provinces") {
      if (mode === "minor_houses") {
        drawMinorHousesLayer(map);
      } else {
        drawRealmLayer(map, mode, 150, 0.6);
        if (REALM_OVERLAY_MODES.has(mode)) drawRealmLayer(map, "free_cities", 230, 0.75);
      }
    }

    map.repaintAllEmblems().catch(() => {});
  }
  function collectProvinceKeysByRealmId(map, field, realmId) {
    const id = String(realmId || "").trim();
    if (!id) return [];
    const keys = [];
    for (const pd of Object.values(state.provinces || {})) {
      if (!pd || pd[field] !== id) continue;
      const key = keyForPid(pd.pid);
      if (key) keys.push(key);
    }
    return keys;
  }

  function buildLayerExportFillMap(mode) {
    const fills = new Map();
    if (mode === "provinces") {
      for (const pd of Object.values(state.provinces || {})) {
        if (!pd) continue;
        const key = keyForPid(pd.pid);
        if (!key || !Array.isArray(pd.fill_rgba) || pd.fill_rgba.length < 3) continue;
        fills.set(key, [pd.fill_rgba[0] | 0, pd.fill_rgba[1] | 0, pd.fill_rgba[2] | 0, 255]);
      }
      return fills;
    }

    if (mode === "minor_houses") {
      const byKey = new Map();
      for (const [id, realm] of Object.entries(realmBucketByType("great_houses"))) {
        const [r, g, b] = MapUtils.hexToRgb(realm && realm.color ? realm.color : "#ff3b30");
        const layer = getGreatHouseMinorLayer(id);
        const domain = new Set((layer.domain_pids || []).map(pid => keyForPid(pid)).filter(Boolean));
        const cap = keyForPid(layer.capital_pid || 0);
        const palette = buildVassalPalette(realm.color || "#ff3b30");
        const vset = new Set();
        for (let i = 0; i < layer.vassals.length; i++) {
          const v = layer.vassals[i];
          const vHex = v.color || palette[i % palette.length] || realm.color || "#ff3b30";
          const rgb = MapUtils.hexToRgb(vHex);
          for (const pid of (v.province_pids || [])) {
            const key = keyForPid(pid);
            if (!key) continue;
            byKey.set(key, rgb);
            vset.add(key);
          }
        }
        for (const pd of Object.values(state.provinces || {})) {
          if (!pd || pd.great_house_id !== id) continue;
          const key = keyForPid(pd.pid);
          if (!key || byKey.has(key) || domain.has(key) || key === cap) continue;
          byKey.set(key, [r, g, b]);
        }
        for (const key of domain) if (!byKey.has(key) && key !== cap) byKey.set(key, [r, g, b]);
        if (cap) byKey.set(cap, [r, g, b]);
      }
      for (const [key, rgb] of byKey.entries()) fills.set(key, [rgb[0] | 0, rgb[1] | 0, rgb[2] | 0, 255]);
      for (const [id, realm] of Object.entries(realmBucketByType("free_cities"))) {
        const [r, g, b] = MapUtils.hexToRgb(realm && realm.color ? realm.color : "#ff3b30");
        const keys = collectProvinceKeysByRealmId(null, "free_city_id", id);
        for (const key of keys) fills.set(key, [r, g, b, 255]);
      }
      return fills;
    }

    const field = MODE_TO_FIELD[mode];
    const bucket = realmBucketByType(mode);
    for (const [id, realm] of Object.entries(bucket)) {
      const [r, g, b] = MapUtils.hexToRgb(realm && realm.color ? realm.color : "#ff3b30");
      const rgba = [r, g, b, 255];
      const keys = collectProvinceKeysByRealmId(null, field, id);
      for (const key of keys) fills.set(key, rgba);
    }
    return fills;
  }

  function downloadCanvasAsPng(canvas, filename) {
    const url = canvas.toDataURL("image/png");
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
  }

  function exportLayerPng(map, mode, filename) {
    if (!map || !map.W || !map.H || !map.fillCtx || !map.keyPerPixel) {
      alert("Карта ещё не готова для выгрузки PNG.");
      return;
    }
    const fills = buildLayerExportFillMap(mode);
    const out = document.createElement("canvas");
    out.width = map.W;
    out.height = map.H;
    const ctx = out.getContext("2d", { willReadFrequently: true });
    const img = ctx.createImageData(map.W, map.H);
    const data = img.data;
    const keys = map.keyPerPixel;
    for (let i = 0, px = 0; i < keys.length; i++, px += 4) {
      const key = keys[i] >>> 0;
      const rgba = key ? fills.get(key) : null;
      if (!rgba) continue;
      data[px] = rgba[0] | 0;
      data[px + 1] = rgba[1] | 0;
      data[px + 2] = rgba[2] | 0;
      data[px + 3] = 255;
    }
    ctx.putImageData(img, 0, 0);
    downloadCanvasAsPng(out, filename);
  }




  function syncProvEmblemsToggleLabel() {
    if (!toggleProvEmblemsBtn) return;
    toggleProvEmblemsBtn.textContent = hideProvinceEmblems ? "Показать геральдику провинций" : "Скрыть геральдику провинций";
  }

  function sanitizeSvgText(svgText) { return String(svgText || "").replace(/<script[\s\S]*?<\/script\s*>/gi, ""); }
  function svgTextToDataUri(svgText) { return "data:image/svg+xml;base64," + MapUtils.toBase64Utf8(sanitizeSvgText(svgText)); }
  function extractSvgBox(svgText) { const box = MapUtils.parseSvgBox(svgText); return [box.w, box.h]; }
  function dataUriSvgToText(src) {
    const s = String(src || "").trim();
    if (!s.startsWith("data:image/svg+xml")) return "";
    const commaIdx = s.indexOf(",");
    if (commaIdx < 0) return "";
    const meta = s.slice(0, commaIdx).toLowerCase();
    const body = s.slice(commaIdx + 1);
    try {
      if (meta.includes(";base64")) {
        const bin = atob(body);
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return new TextDecoder("utf-8").decode(bytes);
      }
      return decodeURIComponent(body);
    } catch (_) {
      return "";
    }
  }
  function emblemSourceToDataUri(src) {
    const s = String(src || "").trim();
    if (!s) return "";
    if (s.startsWith("data:")) return s;
    if (/<svg[\s>]/i.test(s)) return svgTextToDataUri(s);
    return s;
  }
  function normalizeStoredEmblems(obj) {
    for (const pd of Object.values(obj.provinces || {})) {
      if (!pd || typeof pd !== "object") continue;
      const src = String(pd.emblem_svg || "");
      const decoded = dataUriSvgToText(src);
      if (decoded) pd.emblem_svg = sanitizeSvgText(decoded);
    }
    for (const type of ["kingdoms", "great_houses", "minor_houses", "free_cities"]) {
      for (const realm of Object.values(obj[type] || {})) {
        if (!realm || typeof realm !== "object") continue;
        const src = String(realm.emblem_svg || "");
        const decoded = dataUriSvgToText(src);
        if (decoded) realm.emblem_svg = sanitizeSvgText(decoded);
      }
    }
  }

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
    if (btnEnableBackendMode) btnEnableBackendMode.addEventListener("click", navigateToBackendMode);
    btnSaveProv.addEventListener("click", async () => { saveProvinceFieldsFromUI(); exportStateToTextarea(); if (APP_FLAGS && APP_FLAGS.USE_PARTIAL_SAVE) { try { await persistSelectedProvincePatch(); } catch (err) { alert("PATCH сохранение провинции не удалось: " + (err && err.message ? err.message : err)); } } });

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
    rebuildMinorHouseControls();
    if (minorGreatHouseSelect) minorGreatHouseSelect.addEventListener("change", rebuildMinorHouseControls);
    btnNewRealm.addEventListener("click", () => { const id = prompt("ID сущности (латиница/цифры):"); if (!id) return; ensureRealm(realmTypeSelect.value, id.trim()); rebuildRealmSelect(); rebuildMinorHouseControls(); realmSelect.value = id.trim(); loadRealmFields(); exportStateToTextarea(); });
    btnSaveRealm.addEventListener("click", async () => {
      const type = realmTypeSelect.value; const id = realmSelect.value; if (!id) return;
      const realm = ensureRealm(type, id);
      realm.name = String(realmNameInput.value || id).trim() || id;
      realm.color = String(realmColorInput.value || "#ff3b30");
      realm.capital_pid = Number(realmCapitalInput.value) >>> 0;
      realm.emblem_scale = Math.max(0.2, Math.min(3, Number(realmEmblemScaleInput.value) || 1));
      rebuildRealmSelect(); rebuildMinorHouseControls(); realmSelect.value = id; loadRealmFields(); applyLayerState(map); exportStateToTextarea();
      if (APP_FLAGS && APP_FLAGS.USE_PARTIAL_SAVE) {
        try { await persistRealmPatch(type, id, realm); }
        catch (err) { alert("PATCH сохранение сущности не удалось: " + (err && err.message ? err.message : err)); }
      }
    });
    btnAddSelectedToRealm.addEventListener("click", () => {
      const type = realmTypeSelect.value; const id = realmSelect.value; if (!id) return;
      const field = MODE_TO_FIELD[type]; const realm = ensureRealm(type, id);
      const keys = selectedKeys.size ? Array.from(selectedKeys) : (selectedKey ? [selectedKey] : []);
      for (const key of keys) { const pd = getProvData(key); if (pd) pd[field] = id; }
      realm.province_pids = keys.map(k => pidByKey.get(k) || 0).filter(Boolean);
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
      const safeSvg = sanitizeSvgText(text);
      const realm = ensureRealm(type, id); realm.emblem_svg = safeSvg; realm.emblem_box = extractSvgBox(safeSvg);
      applyLayerState(map); exportStateToTextarea();
    });
    realmRemoveEmblemBtn.addEventListener("click", () => { const type = realmTypeSelect.value; const id = realmSelect.value; if (!id) return; const realm = ensureRealm(type, id); realm.emblem_svg = ""; realm.emblem_box = null; applyLayerState(map); exportStateToTextarea(); });


    if (minorSetCapitalBtn) minorSetCapitalBtn.addEventListener("click", () => {
      const gh = minorGreatHouseSelect.value; if (!gh || !selectedKey) return;
      const layer = getGreatHouseMinorLayer(gh);
      layer.capital_pid = pidByKey.get(selectedKey) || 0;
      rebuildMinorHouseControls();
      applyLayerState(map); exportStateToTextarea();
    });
    if (minorAddDomainBtn) minorAddDomainBtn.addEventListener("click", () => {
      const gh = minorGreatHouseSelect.value; if (!gh) return;
      const layer = getGreatHouseMinorLayer(gh);
      const keys = selectedKeys.size ? Array.from(selectedKeys) : (selectedKey ? [selectedKey] : []);
      const set = new Set(layer.domain_pids || []);
      for (const key of keys) {
        const pid = pidByKey.get(key) || 0;
        if (pid) set.add(pid);
      }
      layer.domain_pids = Array.from(set);
      applyLayerState(map); exportStateToTextarea();
    });
    if (minorRemoveDomainBtn) minorRemoveDomainBtn.addEventListener("click", () => {
      const gh = minorGreatHouseSelect.value; if (!gh) return;
      const layer = getGreatHouseMinorLayer(gh);
      const keys = selectedKeys.size ? Array.from(selectedKeys) : (selectedKey ? [selectedKey] : []);
      const remove = new Set(keys.map(key => pidByKey.get(key) || 0).filter(Boolean));
      layer.domain_pids = (layer.domain_pids || []).filter(pid => !remove.has(pid));
      applyLayerState(map); exportStateToTextarea();
    });
    if (minorCreateVassalBtn) minorCreateVassalBtn.addEventListener("click", () => {
      const gh = minorGreatHouseSelect.value; if (!gh) return;
      const layer = getGreatHouseMinorLayer(gh);
      const name = String(minorVassalName.value || "").trim() || `Вассал ${layer.vassals.length + 1}`;
      const idBase = name.toLowerCase().replace(/\s+/g, "_").replace(/[^a-zа-я0-9_\-]/gi, "").slice(0, 32) || `vassal_${layer.vassals.length + 1}`;
      let id = idBase; let n = 2;
      while (layer.vassals.some(v => v.id === id)) { id = `${idBase}_${n++}`; }
      const palette = buildVassalPalette((realmBucketByType("great_houses")[gh] || {}).color || "#ff3b30");
      layer.vassals.push({ id, name, color: palette[layer.vassals.length % palette.length] || "", capital_pid: 0, province_pids: [] });
      minorVassalName.value = "";
      rebuildMinorHouseControls();
      applyLayerState(map); exportStateToTextarea();
    });
    if (minorSetVassalCapitalBtn) minorSetVassalCapitalBtn.addEventListener("click", () => {
      const gh = minorGreatHouseSelect.value; const vid = minorVassalSelect.value; if (!gh || !vid || !selectedKey) return;
      const layer = getGreatHouseMinorLayer(gh);
      const v = layer.vassals.find(x => x.id === vid); if (!v) return;
      v.capital_pid = pidByKey.get(selectedKey) || 0;
      applyLayerState(map); exportStateToTextarea();
    });
    if (minorAddVassalProvincesBtn) minorAddVassalProvincesBtn.addEventListener("click", () => {
      const gh = minorGreatHouseSelect.value; const vid = minorVassalSelect.value; if (!gh || !vid) return;
      const layer = getGreatHouseMinorLayer(gh);
      const v = layer.vassals.find(x => x.id === vid); if (!v) return;
      const set = new Set(v.province_pids || []);
      const keys = selectedKeys.size ? Array.from(selectedKeys) : (selectedKey ? [selectedKey] : []);
      for (const key of keys) {
        const pid = pidByKey.get(key) || 0;
        if (pid) set.add(pid);
      }
      v.province_pids = Array.from(set);
      applyLayerState(map); exportStateToTextarea();
    });
    if (minorRemoveVassalProvincesBtn) minorRemoveVassalProvincesBtn.addEventListener("click", () => {
      const gh = minorGreatHouseSelect.value; const vid = minorVassalSelect.value; if (!gh || !vid) return;
      const layer = getGreatHouseMinorLayer(gh);
      const v = layer.vassals.find(x => x.id === vid); if (!v) return;
      const keys = selectedKeys.size ? Array.from(selectedKeys) : (selectedKey ? [selectedKey] : []);
      const remove = new Set(keys.map(key => pidByKey.get(key) || 0).filter(Boolean));
      v.province_pids = (v.province_pids || []).filter(pid => !remove.has(pid));
      applyLayerState(map); exportStateToTextarea();
    });

    btnExport.addEventListener("click", exportStateToTextarea);
    btnDownload.addEventListener("click", () => { exportStateToTextarea(); const blob = new Blob([stateTA.value], { type: "application/json;charset=utf-8" }); const a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = "map_state.json"; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 1000); });
    if (btnExportMigrated) btnExportMigrated.addEventListener("click", async () => {
      try {
        exportStateToTextarea();
        const res = await fetch("/api/migration/export/", {
          method: "POST",
          headers: { "Content-Type": "application/json;charset=utf-8" },
          body: JSON.stringify({ state: JSON.parse(stateTA.value), include_legacy_svg: false })
        });
        if (!res.ok) throw new Error("HTTP " + res.status);
        const payload = await res.json();
        downloadJsonFile("map_state.migrated_bundle.json", payload);
      } catch (err) {
        alert("Не удалось выгрузить migrated bundle: " + (err && err.message ? err.message : err));
      }
    });
    if (btnExportProvincesPng) btnExportProvincesPng.addEventListener("click", () => exportLayerPng(map, "provinces", "layer_provinces.png"));
    if (btnExportKingdomsPng) btnExportKingdomsPng.addEventListener("click", () => exportLayerPng(map, "kingdoms", "layer_kingdoms.png"));
    if (btnExportGreatHousesPng) btnExportGreatHousesPng.addEventListener("click", () => exportLayerPng(map, "great_houses", "layer_great_houses.png"));
    if (btnExportMinorHousesPng) btnExportMinorHousesPng.addEventListener("click", () => exportLayerPng(map, "minor_houses", "layer_minor_houses.png"));
    btnImport.addEventListener("click", () => importFile.click());
    importFile.addEventListener("change", async () => { const file = importFile.files && importFile.files[0]; if (!file) return; const txt = await file.text(); const obj = JSON.parse(txt); if (!obj.provinces) return alert("Нет provinces"); ensureFeudalSchema(obj); state = Object.assign(state, obj); rebuildMinorHouseControls(); applyLayerState(map); exportStateToTextarea(); importFile.value = ""; });
    btnSaveServer.addEventListener("click", async () => { exportStateToTextarea(); const res = await fetch(SAVE_ENDPOINT, { method: "POST", headers: { "Content-Type": "application/json;charset=utf-8" }, body: JSON.stringify({ token: SAVE_TOKEN, state: JSON.parse(stateTA.value) }) }); if (!res.ok) alert("Ошибка сохранения"); else alert("Сохранено"); });

    uploadEmblemBtn.addEventListener("click", () => { if (!selectedKey) return alert("Сначала выбери провинцию."); emblemFile.click(); });
    emblemFile.addEventListener("change", async () => { const file = emblemFile.files && emblemFile.files[0]; emblemFile.value = ""; if (!file || !selectedKey) return; const text = String(await file.text() || "").replace(/^﻿/, ""); const safeSvg = sanitizeSvgText(text); const pd = getProvData(selectedKey); if (!pd) return; pd.emblem_svg = safeSvg; pd.emblem_box = extractSvgBox(safeSvg); setEmblemPreview(pd); applyLayerState(map); exportStateToTextarea(); });
    removeEmblemBtn.addEventListener("click", () => { if (!selectedKey) return; const pd = getProvData(selectedKey); if (!pd) return; pd.emblem_svg = ""; pd.emblem_box = null; setEmblemPreview(pd); applyLayerState(map); exportStateToTextarea(); });

    if (uploadProvinceImageBtn) uploadProvinceImageBtn.addEventListener("click", () => { if (!selectedKey) return alert("Сначала выбери провинцию."); provinceImageFile.click(); });
    if (provinceImageFile) provinceImageFile.addEventListener("change", async () => {
      const file = provinceImageFile.files && provinceImageFile.files[0];
      provinceImageFile.value = "";
      if (!file || !selectedKey) return;
      const pd = getProvData(selectedKey);
      if (!pd) return;
      const pid = Number(pd.pid) >>> 0;
      if (!pid) return;
      const baseDataUrl = await fileToDataUrl(file);
      provinceCardBaseByPid.set(pid, baseDataUrl);
      setProvinceCardPreview(pd);
    });
    if (buildProvinceImageBtn) buildProvinceImageBtn.addEventListener("click", async () => {
      if (!selectedKey) return alert("Сначала выбери провинцию.");
      const pd = getProvData(selectedKey);
      if (!pd) return;
      try {
        const pid = Number(pd.pid) >>> 0;
        if (!pid) throw new Error("Не определён PID провинции");
        const baseSrc = provinceCardBaseByPid.get(pid) || String(pd.province_card_base_image || "").trim();
        const cardDataUrl = await composeProvinceCardImage(pd, baseSrc);
        const fileName = `province_${String(pid).padStart(4, "0")}.jpg`;
        pd.province_card_image = `provinces/${fileName}`;
        pd.province_card_base_image = "";
        setProvinceCardPreview(pd);
        exportStateToTextarea();
        const res = await fetch(SAVE_ENDPOINT, {
          method: "POST",
          headers: { "Content-Type": "application/json;charset=utf-8" },
          body: JSON.stringify({ token: SAVE_TOKEN, state: JSON.parse(stateTA.value), province_cards: { [String(pd.pid)]: cardDataUrl } })
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        alert("Карточка провинции собрана и сохранена в папку provinces.");
      } catch (err) {
        alert("Не удалось собрать карточку: " + (err && err.message ? err.message : err));
      }
    });

    provNameInput.addEventListener("change", saveProvinceFieldsFromUI); ownerInput.addEventListener("change", () => { ensurePerson(ownerInput.value); saveProvinceFieldsFromUI(); });
    suzerainSelect.addEventListener("change", saveProvinceFieldsFromUI); seniorSelect.addEventListener("change", saveProvinceFieldsFromUI); vassalsSelect.addEventListener("change", saveProvinceFieldsFromUI); terrainSelect.addEventListener("change", saveProvinceFieldsFromUI);
  }


  function normalizeStateByPid(obj) {
    const src = obj && obj.provinces ? obj.provinces : {};
    const out = {};
    for (const [k, pd] of Object.entries(src)) {
      if (!pd || typeof pd !== "object") continue;
      const pid = Number(pd.pid != null ? pd.pid : k) | 0;
      if (pid <= 0) continue;
      if (!(String(pid) in out)) out[String(pid)] = pd;
      out[String(pid)].pid = pid;
    }
    obj.provinces = out;
    return obj;
  }

  async function loadInitialState(url) {
    const loader = window.AdminMapStateLoader;
    const loaded = loader ? await loader.loadState(url) : { state: await (await fetch(url, { cache: "no-store" })).json(), flags: {} };
    const obj = loaded.state; if (!obj || typeof obj !== "object" || !obj.provinces) throw new Error("Invalid state JSON");
    if (loaded.flags && loaded.flags.USE_CHUNKED_API) console.info("[admin] USE_CHUNKED_API enabled");
    if (loaded.flags && loaded.flags.USE_EMBLEM_ASSETS) console.info("[admin] USE_EMBLEM_ASSETS enabled");
    if (loaded.flags && loaded.flags.USE_PARTIAL_SAVE) console.info("[admin] USE_PARTIAL_SAVE enabled");
    obj.people = normalizePeopleList(obj.people || []); if (!Array.isArray(obj.terrain_types)) obj.terrain_types = TERRAIN_TYPES_FALLBACK.slice();
    for (const pd of Object.values(obj.provinces)) { if (!pd) continue; if (typeof pd.emblem_svg !== "string") pd.emblem_svg = ""; if (!Array.isArray(pd.emblem_box) || pd.emblem_box.length !== 2) pd.emblem_box = null; if (typeof pd.province_card_image !== "string") pd.province_card_image = ""; }
    ensureFeudalSchema(obj);
    normalizeStateByPid(obj);
    normalizeStoredEmblems(obj);
    return obj;
  }




  async function main() {
    state = await loadInitialState(STATE_URL_DEFAULT);
    rebuildPeopleControls(); rebuildTerrainSelect(); rebuildRealmSelect();

    const map = new RasterProvinceMap({
      baseImgId: "baseMap", fillCanvasId: "fill", emblemCanvasId: "emblems", hoverCanvasId: "hover", provincesMetaUrl: "provinces.json", maskUrl: "provinces_id.png",
      onHover: ({ key, meta, evt }) => { if (!key) { tooltip.style.display = "none"; return; } const pid = meta && meta.pid != null ? Number(meta.pid) : (pidByKey.get(key >>> 0) || 0); const pd = state.provinces[String(pid)] || {}; const label = (pd.name || (meta && meta.name) || ("Провинция " + (meta ? meta.pid : ""))); setTooltip(evt, label + " (ID " + (pd.pid || (meta && meta.pid) || "?") + ")"); if (currentMode() === "minor_houses") { const hoverKeys = getMinorHouseHoverKeys(pid); if (hoverKeys.length) map.setHoverHighlights(hoverKeys, [255, 255, 255, 60]); } },
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
    rebuildPidKeyMaps(map);
    initZoomControls(map);
    boot(map);
    setSelection(0, null);
    exportStateToTextarea();
  }

  main().catch(err => { console.error(err); alert("Ошибка запуска админки: " + err.message); });
})();
