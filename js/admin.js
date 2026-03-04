/* admin.js (zoom + emblems + feudal layers) */

(function () {
  "use strict";

  const el = (id) => document.getElementById(id);

  const tooltip = el("tooltip");
  const flagsStatusEl = el("flagsStatus");
  
  const selName = el("selName");
  const selPid = el("selPid");
  const selKey = el("selKey");
  const multiSelCount = el("multiSelCount");
  const turnCurrentYear = el("turnCurrentYear");
  const turnCurrentStatus = el("turnCurrentStatus");
  const turnTreasuryProvSum = el("turnTreasuryProvSum");
  const turnTreasuryEntitySum = el("turnTreasuryEntitySum");
  const turnActionStatus = el("turnActionStatus");
  const btnMakeTurn = el("btnMakeTurn");
  const btnRefreshTurn = el("btnRefreshTurn");
  const btnOpenTurnAdmin = el("btnOpenTurnAdmin");

  const colorInput = el("color");
  const alphaInput = el("alpha");
  const alphaVal = el("alphaVal");

  const provNameInput = el("provName");
  const ownerInput = el("ownerInput");
  const peopleDatalist = el("peopleList");

  const suzerainText = el("suzerainText");
  const seniorText = el("seniorText");
  const terrainSelect = el("terrainSelect");

  const btnApplyFill = el("applyFill");
  const btnClearFill = el("clearFill");
  const btnSaveProv = el("saveProv");

  const viewModeSelect = el("viewMode");
  const toggleProvEmblemsBtn = el("toggleProvEmblems");
  const realmTypeSelect = el("realmType");
  const realmSelect = el("realmSelect");
  const realmNameInput = el("realmName");
  const realmRulerInput = el("realmRuler");
  const realmColorInput = el("realmColor");
  const realmCapitalInput = el("realmCapital");
  const realmEmblemScaleInput = el("realmEmblemScale");
  const realmEmblemScaleVal = el("realmEmblemScaleVal");
  const realmWarlikeCoeffInput = el("realmWarlikeCoeff");
  const btnSaveRealm = el("saveRealm");
  const btnAddSelectedToRealm = el("addSelectedToRealm");
  const btnRemoveSelectedFromRealm = el("removeSelectedFromRealm");
  const btnNewRealm = el("newRealm");
  const realmArrierbanBtn = el("realmArrierbanBtn");
  const realmDomainArrierbanBtn = el("realmDomainArrierbanBtn");
  const realmArrierbanDismissBtn = el("realmArrierbanDismissBtn");
  const realmArmyManageBtn = el("realmArmyManageBtn");
  const realmArrierbanOutput = el("realmArrierbanOutput");
  const arrierbanModal = el("arrierbanModal");
  const arrierbanTitle = el("arrierbanTitle");
  const arrierbanSubtitle = el("arrierbanSubtitle");
  const arrierbanPools = el("arrierbanPools");
  const arrierbanRemaining = el("arrierbanRemaining");
  const arrierbanRows = el("arrierbanRows");
  const arrierbanValidation = el("arrierbanValidation");
  const arrierbanClose = el("arrierbanClose");
  const arrierbanApply = el("arrierbanApply");
  const armyManageModal = el("armyManageModal");
  const armyManageTitle = el("armyManageTitle");
  const armyManageSubtitle = el("armyManageSubtitle");
  const armyManageList = el("armyManageList");
  const armyManageClose = el("armyManageClose");
  const armyManageValidation = el("armyManageValidation");
  const armyMergeBtn = el("armyMergeBtn");
  const armySplitBtn = el("armySplitBtn");
  const armySplitSize = el("armySplitSize");
  const armyManageSave = el("armyManageSave");
  const armyNormalizeBtn = el("armyNormalizeBtn");
  const armyMarkersLayer = el("armyMarkers");
  const realmUploadEmblemBtn = el("realmUploadEmblemBtn");
  const realmRemoveEmblemBtn = el("realmRemoveEmblemBtn");
  const realmEmblemFile = el("realmEmblemFile");

  const minorGreatHouseSelect = el("minorGreatHouseSelect");
  const minorVassalSelect = el("minorVassalSelect");
  const minorVassalName = el("minorVassalName");
  const minorVassalRuler = el("minorVassalRuler");
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
  const btnSaveImportedBackend = el("saveImportedBackend");
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
  const personModal = el("personModal");
  const personModalClose = el("personModalClose");
  const personModalPhoto = el("personModalPhoto");
  const personModalName = el("personModalName");
  const personModalSuzerain = el("personModalSuzerain");
  const personModalSeniors = el("personModalSeniors");
  const personModalVassals = el("personModalVassals");
  const personModalBio = el("personModalBio");
  const manualEditModal = el("manualEditModal");
  const manualEditTitle = el("manualEditTitle");
  const manualEditSubtitle = el("manualEditSubtitle");
  const manualEditClose = el("manualEditClose");
  const manualEditSave = el("manualEditSave");
  const manualName = el("manualName");
  const manualOwner = el("manualOwner");
  const manualKingdomId = el("manualKingdomId");
  const manualGreatHouseId = el("manualGreatHouseId");
  const manualMinorHouseId = el("manualMinorHouseId");
  const manualFreeCityId = el("manualFreeCityId");
  const manualTerrain = el("manualTerrain");
  const manualTreasury = el("manualTreasury");
  const manualPopulation = el("manualPopulation");
  const manualTax = el("manualTax");
  const manualBuildings = el("manualBuildings");
  const manualBackground = el("manualBackground");
  const manualCardImage = el("manualCardImage");
  const manualEmblemSvg = el("manualEmblemSvg");
  const manualDescription = el("manualDescription");
  const manualColor = el("manualColor");
  const manualCapitalPid = el("manualCapitalPid");
  const manualProvincePids = el("manualProvincePids");
  const manualExtraJson = el("manualExtraJson");

  alphaInput.addEventListener("input", () => alphaVal.textContent = alphaInput.value);
  realmEmblemScaleInput.addEventListener("input", () => realmEmblemScaleVal.textContent = realmEmblemScaleInput.value);
  if (realmWarlikeCoeffInput) {
    realmWarlikeCoeffInput.addEventListener("change", () => {
      realmWarlikeCoeffInput.value = String(clampWarlikeCoeff(realmWarlikeCoeffInput.value));
    });
  }

  const STATE_URL_DEFAULT = "/api/map/bootstrap/";
  const SAVE_TOKEN = "";
  const PROVINCE_PATCH_ENDPOINT = "/api/provinces/patch/";
  const PROVINCE_CARD_UPLOAD_ENDPOINT = "/api/provinces/card/upload/";
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
  let manualEditTarget = null;
  let hexmapData = window.HEXMAP || null;
  let hexmapDataLoadPromise = null;
  let battleSimData = window.DATA || null;
  let battleSimDataLoadPromise = null;
  let pendingArrierbanPlan = null;
  let mapInstanceRef = null;
  let pendingArmyManage = null;
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

  function parseHexmapDataScript(scriptText) {
    const src = String(scriptText || "").trim();
    const m = src.match(/^\s*window\.HEXMAP\s*=\s*(\{[\s\S]*\})\s*;?\s*$/);
    if (!m) throw new Error("Unexpected hexmap/data.js format");
    return JSON.parse(m[1]);
  }

  async function ensureHexmapDataLoaded() {
    if (hexmapData && Array.isArray(hexmapData.hexes)) return hexmapData;
    if (hexmapDataLoadPromise) return hexmapDataLoadPromise;
    hexmapDataLoadPromise = (async () => {
      const resp = await fetch("hexmap/data.js", { cache: "no-store" });
      if (!resp.ok) throw new Error(`HTTP ${resp.status} while loading hexmap/data.js`);
      const raw = await resp.text();
      const parsed = parseHexmapDataScript(raw);
      if (!parsed || !Array.isArray(parsed.hexes)) throw new Error("hexmap/data.js does not contain hexes");
      hexmapData = parsed;
      return hexmapData;
    })();
    try {
      return await hexmapDataLoadPromise;
    } finally {
      hexmapDataLoadPromise = null;
    }
  }

  async function ensureBattleSimDataLoaded() {
    if (battleSimData && battleSimData.UNIT_CATALOG) return battleSimData;
    if (battleSimDataLoadPromise) return battleSimDataLoadPromise;
    battleSimDataLoadPromise = (async () => {
      const resp = await fetch("battle_sim/js/data.js", { cache: "no-store" });
      if (!resp.ok) throw new Error(`HTTP ${resp.status} while loading battle_sim/js/data.js`);
      const raw = await resp.text();
      const sandboxWindow = {};
      const loader = new Function("window", `${raw}; return window.DATA || null;`);
      const parsed = loader(sandboxWindow);
      if (!parsed || !parsed.UNIT_CATALOG) throw new Error("battle_sim/js/data.js does not expose UNIT_CATALOG");
      battleSimData = parsed;
      return battleSimData;
    })();
    try {
      return await battleSimDataLoadPromise;
    } finally {
      battleSimDataLoadPromise = null;
    }
  }

  function isPlainObject(value) {
    return !!value && typeof value === "object" && !Array.isArray(value);
  }

  function setTooltip(evt, text) { if (!text) { tooltip.style.display = "none"; return; } tooltip.textContent = text; tooltip.style.left = (evt.clientX + 12) + "px"; tooltip.style.top = (evt.clientY + 12) + "px"; tooltip.style.display = "block"; }

  function updateFlagsStatusText(flags) {
    if (!flagsStatusEl) return;
    const active = [];
    if (flags && flags.USE_CHUNKED_API) active.push('USE_CHUNKED_API');
    if (flags && flags.USE_EMBLEM_ASSETS) active.push('USE_EMBLEM_ASSETS');
    if (flags && flags.USE_PARTIAL_SAVE) active.push('USE_PARTIAL_SAVE');
    if (flags && flags.USE_SERVER_RENDER) active.push('USE_SERVER_RENDER');
    flagsStatusEl.textContent = active.length ? ('Флаги: ' + active.join(', ')) : 'Флаги: backend';
  }

  function fmtMoneyCompact(value) {
    const n = Number(value || 0);
    if (!Number.isFinite(n)) return '0';
    return Math.round(n).toLocaleString('ru-RU');
  }

  function applyTurnTreasuryToProvinceState(rows) {
    if (!state || !state.provinces || !Array.isArray(rows)) return 0;
    let updated = 0;
    for (const row of rows) {
      const pid = Number(row && row.province_pid) >>> 0;
      if (!pid) continue;
      const pd = state.provinces[String(pid)];
      if (!pd || typeof pd !== 'object') continue;
      const closing = Number(row && row.closing_balance);
      if (!Number.isFinite(closing)) continue;
      if (Number(pd.treasury) === closing) continue;
      pd.treasury = closing;
      updated += 1;
    }
    return updated;
  }


  function applyTurnEconomyToProvinceState(economyRows, treasuryRows) {
    if (!state || !state.provinces) return 0;
    let updated = 0;
    const taxRateByPid = new Map();
    if (Array.isArray(treasuryRows)) {
      for (const row of treasuryRows) {
        const pid = Number(row && row.province_pid) >>> 0;
        if (!pid) continue;
        const taxRate = Number(row && row.tax_rate);
        if (Number.isFinite(taxRate)) taxRateByPid.set(pid, taxRate);
      }
    }
    for (const row of (Array.isArray(economyRows) ? economyRows : [])) {
      const pid = Number(row && row.province_pid) >>> 0;
      if (!pid) continue;
      const pd = state.provinces[String(pid)];
      if (!pd || typeof pd !== 'object') continue;
      const pop = Number(row && row.modifiers && row.modifiers.population);
      if (Number.isFinite(pop) && Number(pd.population) !== pop) {
        pd.population = pop;
        updated += 1;
      }
      if (taxRateByPid.has(pid)) {
        const taxRate = taxRateByPid.get(pid);
        if (Number(pd.tax_rate) !== taxRate) {
          pd.tax_rate = taxRate;
          updated += 1;
        }
      }
    }
    return updated;
  }

  async function turnApi(path, options) {
    const resp = await fetch(path, options);
    const body = await resp.json().catch(() => ({}));
    if (!resp.ok) {
      const err = body && (body.error || body.message) ? `${body.error || body.message}` : `HTTP ${resp.status}`;
      throw new Error(err);
    }
    return body;
  }

  async function refreshTurnPanel() {
    if (!turnCurrentYear) return;
    turnActionStatus.textContent = 'Обновляю данные по ходу…';
    try {
      const listBody = await turnApi('/api/turns/?published_only=1', { cache: 'no-store' });
      const items = Array.isArray(listBody && listBody.items) ? listBody.items : [];
      const published = items.length ? items[items.length - 1] : null;
      if (!published || !Number.isFinite(Number(published.year))) {
        turnCurrentYear.textContent = '—';
        turnCurrentStatus.textContent = 'published ходов нет';
        turnTreasuryProvSum.textContent = '—';
        turnTreasuryEntitySum.textContent = '—';
        turnActionStatus.textContent = 'Пока нет опубликованных ходов. Нажми «Сделать ход».';
        return;
      }

      const year = Number(published.year);
      turnCurrentYear.textContent = String(year);
      turnCurrentStatus.textContent = String(published.status || 'published');

      const details = await turnApi(`/api/turns/show/?year=${encodeURIComponent(year)}&include=snapshot_payload&full=1`, { cache: 'no-store' });
      const payload = details && details.snapshot_payload && typeof details.snapshot_payload === 'object' ? details.snapshot_payload : null;
      const provinceRows = Array.isArray(payload && payload.province_treasury) ? payload.province_treasury : [];
      const entityRows = Array.isArray(payload && payload.entity_treasury) ? payload.entity_treasury : [];
      const economyRows = Array.isArray(payload && payload.economy_state) ? payload.economy_state : [];
      const provSum = provinceRows.reduce((acc, row) => acc + Number(row && row.closing_balance || 0), 0);
      const entitySum = entityRows.reduce((acc, row) => acc + Number(row && row.closing_balance || 0), 0);
      applyTurnTreasuryToProvinceState(provinceRows);
      applyTurnEconomyToProvinceState(economyRows, provinceRows);
      if (selectedKey) setSelection(selectedKey);
      exportStateToTextarea();
      turnTreasuryProvSum.textContent = `${fmtMoneyCompact(provSum)} (${provinceRows.length} пров.)`;
      turnTreasuryEntitySum.textContent = `${fmtMoneyCompact(entitySum)} (${entityRows.length} сущ.)`;
      turnActionStatus.textContent = `Показан published ход ${year}.`;
    } catch (err) {
      turnActionStatus.textContent = `Не удалось загрузить данные хода: ${err && err.message ? err.message : err}`;
    }
  }

  async function makeNextTurn() {
    if (!btnMakeTurn) return;
    btnMakeTurn.disabled = true;
    turnActionStatus.textContent = 'Создаю следующий ход…';
    try {
      const listBody = await turnApi('/api/turns/', { cache: 'no-store' });
      const items = Array.isArray(listBody && listBody.items) ? listBody.items : [];
      const published = items.filter((it) => String(it && it.status || '') === 'published');
      const sourceYear = published.length ? Number(published[published.length - 1].year || 0) : 0;
      const targetYear = sourceYear + 1;

      turnActionStatus.textContent = 'Сохраняю изменения карты перед новым ходом…';
      exportStateToTextarea();
      await saveStateAsBackendVariant(stateTA.value);

      const created = await turnApi('/api/turns/create-from-previous/', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json;charset=utf-8' },
        body: JSON.stringify({ source_turn_year: sourceYear, target_turn_year: targetYear, ruleset_version: 'v1.0', prefer_map_state: true })
      });
      const createdVersion = String(created && created.turn && created.turn.version || '');
      turnActionStatus.textContent = `Ход ${targetYear} создан, считаю экономику…`;

      const processed = await turnApi('/api/turns/process-economy/', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json;charset=utf-8', 'If-Match': createdVersion },
        body: JSON.stringify({ turn_year: targetYear, if_match: createdVersion })
      });
      const processedVersion = String(processed && processed.turn && processed.turn.version || createdVersion);
      turnActionStatus.textContent = `Экономика для хода ${targetYear} посчитана, публикую…`;

      await turnApi('/api/turns/publish/', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json;charset=utf-8', 'If-Match': processedVersion },
        body: JSON.stringify({ turn_year: targetYear, if_match: processedVersion })
      });

      turnActionStatus.textContent = `Ход ${targetYear} опубликован.`;
      await refreshTurnPanel();
    } catch (err) {
      turnActionStatus.textContent = `Не удалось сделать ход: ${err && err.message ? err.message : err}`;
    } finally {
      btnMakeTurn.disabled = false;
    }
  }


  function makePlaceholderAvatar(name) {
    const seed = Array.from(String(name || "?")).reduce((a, c) => a + c.charCodeAt(0), 0);
    const hue = seed % 360;
    const initials = String(name || "?").split(/\s+/).filter(Boolean).slice(0, 2).map(x => x[0].toUpperCase()).join("") || "?";
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="hsl(${hue},65%,42%)"/><stop offset="100%" stop-color="hsl(${(hue+35)%360},58%,28%)"/></linearGradient></defs><rect width="512" height="512" fill="url(#g)"/><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" font-family="system-ui,Segoe UI,Arial" font-size="180" fill="#eaf2ff">${initials}</text></svg>`;
    return "data:image/svg+xml;base64," + MapUtils.toBase64Utf8(svg);
  }

  function profileByName(name) {
    const key = String(name || "").trim();
    if (!key) return null;
    const map = state && state.people_profiles && typeof state.people_profiles === "object" ? state.people_profiles : {};
    return map[key] && typeof map[key] === "object" ? map[key] : null;
  }

  function appendPersonLink(target, name) {
    const n = String(name || "").trim();
    if (!n) return false;
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "person-link";
    btn.textContent = n;
    btn.addEventListener("click", () => openPersonModal(n));
    target.appendChild(btn);
    return true;
  }

  function renderPersonNode(target, name) {
    if (!target) return;
    target.textContent = "";
    if (!appendPersonLink(target, name)) target.textContent = "—";
  }

  function renderRelationList(target, values) {
    if (!target) return;
    target.textContent = "";
    const arr = Array.from(values || []);
    if (!arr.length) { target.textContent = "—"; return; }
    arr.sort((a,b)=>a.localeCompare(b,'ru'));
    arr.forEach((name, idx) => {
      if (idx) target.appendChild(document.createTextNode(', '));
      appendPersonLink(target, name);
    });
  }

  function derivePersonRelations(name) {
    const target = String(name || "").trim();
    const suzerains = new Set();
    const seniors = new Set();
    const vassals = new Set();
    if (!target || !state || !state.provinces) return { suzerains, seniors, vassals };

    for (const pd of Object.values(state.provinces || {})) {
      if (!pd || String(pd.owner || "").trim() !== target) continue;
      const rel = getProvinceSuzerainSenior(pd);
      if (rel.suzerain && rel.suzerain !== target) suzerains.add(rel.suzerain);
      if (rel.senior && rel.senior !== target) seniors.add(rel.senior);
    }
    for (const pd of Object.values(state.provinces || {})) {
      if (!pd) continue;
      const owner = String(pd.owner || "").trim();
      if (!owner || owner === target) continue;
      const rel = getProvinceSuzerainSenior(pd);
      if (rel.suzerain === target || rel.senior === target) vassals.add(owner);
    }
    return { suzerains, seniors, vassals };
  }

  function openPersonModal(name) {
    if (!personModal) return;
    const n = String(name || "").trim();
    if (!n) return;
    const profile = profileByName(n) || {};
    personModalName.textContent = n;
    personModalPhoto.src = MapUtils.resolveCharacterPhotoUrl(profile.photo_url, n, makePlaceholderAvatar);
    const bio = String(profile.bio || "").trim();
    personModalBio.textContent = bio || "Биография будет добавлена позже.";
    const rel = derivePersonRelations(n);
    renderRelationList(personModalSuzerain, rel.suzerains);
    renderRelationList(personModalSeniors, rel.seniors);
    renderRelationList(personModalVassals, rel.vassals);
    personModal.classList.add('open');
    personModal.setAttribute('aria-hidden', 'false');
  }

  function closePersonModal() {
    if (!personModal) return;
    personModal.classList.remove('open');
    personModal.setAttribute('aria-hidden', 'true');
  }

  function normalizePeopleList(arr) { const out = []; const seen = new Set(); for (const raw of (arr || [])) { const s = String(raw || "").trim(); if (!s) continue; const key = s.toLowerCase(); if (seen.has(key)) continue; seen.add(key); out.push(s); } return out.sort((a, b) => a.localeCompare(b, "ru")); }
  function ensurePerson(name) { const s = String(name || "").trim(); if (!s) return ""; const key = s.toLowerCase(); const has = state.people.some(p => p.toLowerCase() === key); if (!has) { state.people.push(s); state.people = normalizePeopleList(state.people); rebuildPeopleControls(); } if (!isPlainObject(state.people_profiles)) state.people_profiles = {}; if (!isPlainObject(state.people_profiles[s])) state.people_profiles[s] = { photo_url: "", bio: "" }; return s; }
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
    if (!isPlainObject(obj.people_profiles)) obj.people_profiles = {};
    for (const type of ["kingdoms", "great_houses", "minor_houses", "free_cities"]) {
      for (const realm of Object.values(obj[type] || {})) {
        if (!realm || typeof realm !== "object") continue;
        realm.ruler = String(realm.ruler || "").trim();
      }
    }
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
        ruler: String(v && v.ruler || "").trim(),
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
  }

  function rebuildTerrainSelect() { const list = Array.isArray(state.terrain_types) && state.terrain_types.length ? state.terrain_types : TERRAIN_TYPES_FALLBACK; const cur = terrainSelect.value || ""; terrainSelect.innerHTML = ""; const o0 = document.createElement("option"); o0.value = ""; o0.textContent = "—"; terrainSelect.appendChild(o0); for (const t of list) { const o = document.createElement("option"); o.value = t; o.textContent = t; terrainSelect.appendChild(o); } terrainSelect.value = cur; }

  function syncPeopleFromRealmRulers() {
    for (const type of ["kingdoms", "great_houses", "minor_houses", "free_cities"]) {
      for (const realm of Object.values(state[type] || {})) {
        if (!realm || typeof realm !== "object") continue;
        if (realm.ruler) ensurePerson(realm.ruler);
      }
    }
    for (const realm of Object.values(state.great_houses || {})) {
      if (!realm || typeof realm !== "object") continue;
      const layer = realm.minor_house_layer;
      if (!layer || !Array.isArray(layer.vassals)) continue;
      for (const v of layer.vassals) if (v && v.ruler) ensurePerson(v.ruler);
    }
  }

  function setEmblemPreview(pd) { const src = emblemSourceToDataUri(pd && pd.emblem_svg ? String(pd.emblem_svg) : ""); if (src) { emblemPreviewImg.src = src; emblemPreviewImg.style.display = "block"; emblemPreviewEmpty.style.display = "none"; } else { emblemPreviewImg.removeAttribute("src"); emblemPreviewImg.style.display = "none"; emblemPreviewEmpty.style.display = "block"; } }
  function setProvinceCardPreview(pd) { const pid = pd ? (Number(pd.pid) >>> 0) : 0; const baseSrc = pid ? (provinceCardBaseByPid.get(pid) || "") : ""; const src = String((pd && pd.province_card_image) || baseSrc || "").trim(); if (src) { provinceCardPreviewImg.src = MapUtils.resolveStaticAssetUrl(src); provinceCardPreviewImg.style.display = "block"; provinceCardPreviewEmpty.style.display = "none"; } else { provinceCardPreviewImg.removeAttribute("src"); provinceCardPreviewImg.style.display = "none"; provinceCardPreviewEmpty.style.display = "block"; } }
  function getProvinceOwnerColor(pd) {
    if (pd && Array.isArray(pd.fill_rgba) && pd.fill_rgba.length >= 3) return [pd.fill_rgba[0] | 0, pd.fill_rgba[1] | 0, pd.fill_rgba[2] | 0];
    if (pd && pd.kingdom_id) {
      const realm = realmBucketByType("kingdoms")[pd.kingdom_id];
      if (realm && realm.color) return MapUtils.hexToRgb(realm.color);
    }
    return [90, 117, 146];
  }
  async function getHexesForProvincePid(pid) {
    const data = await ensureHexmapDataLoaded();
    if (!data || !Array.isArray(data.hexes)) return [];
    const p = Number(pid) >>> 0;
    return data.hexes.filter(h => (Number(h.p) >>> 0) === p);
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

    const localHexmapData = await ensureHexmapDataLoaded();
    const hexes = await getHexesForProvincePid(pd.pid);
    if (hexes.length) {
      let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
      for (const hex of hexes) {
        minX = Math.min(minX, Number(hex.cx) - 1.2 * Number(localHexmapData.hexSize || 1));
        minY = Math.min(minY, Number(hex.cy) - 1.2 * Number(localHexmapData.hexSize || 1));
        maxX = Math.max(maxX, Number(hex.cx) + 1.2 * Number(localHexmapData.hexSize || 1));
        maxY = Math.max(maxY, Number(hex.cy) + 1.2 * Number(localHexmapData.hexSize || 1));
      }
      const pw = Math.max(1, maxX - minX);
      const ph = Math.max(1, maxY - minY);
      const scale = Math.min((boxSize * 0.84) / pw, (boxSize * 0.84) / ph);
      const ox = boxX + (boxSize - pw * scale) * 0.5 - minX * scale;
      const oy = boxY + (boxSize - ph * scale) * 0.5 - minY * scale;
      const [fr, fg, fb] = getProvinceOwnerColor(pd);
      const r = Number(localHexmapData.hexSize || 1) * scale;
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


  function getProvinceSuzerainSenior(pd) {
    if (!pd || typeof pd !== "object") return { suzerain: "", senior: "" };
    const kingdom = pd.kingdom_id ? (state.kingdoms || {})[pd.kingdom_id] : null;
    const suzerain = String(kingdom && kingdom.ruler || "").trim();

    const greatHouse = pd.great_house_id ? (state.great_houses || {})[pd.great_house_id] : null;
    let senior = String(greatHouse && greatHouse.ruler || "").trim();

    const layer = greatHouse && greatHouse.minor_house_layer && typeof greatHouse.minor_house_layer === "object"
      ? greatHouse.minor_house_layer
      : null;
    if (layer && Array.isArray(layer.vassals)) {
      const pid = Number(pd.pid) >>> 0;
      const vassal = layer.vassals.find(v => (v && Array.isArray(v.province_pids) && v.province_pids.some(x => (Number(x) >>> 0) === pid)));
      if (vassal) {
        const vassalCapitalPid = Number(vassal.capital_pid) >>> 0;
        if (vassalCapitalPid > 0 && vassalCapitalPid !== pid) senior = String(vassal.ruler || "").trim() || senior;
      }
    }

    return { suzerain, senior };
  }
  function setSelection(key, meta) {
    selectedKey = key >>> 0;
    const pd = getProvData(selectedKey);
    multiSelCount.textContent = String(selectedKeys.size || (selectedKey ? 1 : 0));
    if (!selectedKey || !pd) { selName.textContent = "—"; selPid.textContent = "—"; selKey.textContent = "—"; provNameInput.value = ""; ownerInput.value = ""; suzerainText.textContent = "—"; seniorText.textContent = "—"; terrainSelect.value = ""; setEmblemPreview(null); setProvinceCardPreview(null); return; }
    selName.textContent = pd.name || (meta && meta.name) || "—"; selPid.textContent = String(pd.pid ?? (meta ? meta.pid : "—")); selKey.textContent = String(selectedKey);
    provNameInput.value = pd.name || ""; ownerInput.value = pd.owner || "";
    if (pd.owner) ensurePerson(pd.owner);
    const derived = getProvinceSuzerainSenior(pd);
    if (derived.suzerain) ensurePerson(derived.suzerain);
    if (derived.senior) ensurePerson(derived.senior);
    renderPersonNode(suzerainText, derived.suzerain || "");
    renderPersonNode(seniorText, derived.senior || "");
    terrainSelect.value = pd.terrain || "";
    if (pd.fill_rgba && Array.isArray(pd.fill_rgba) && pd.fill_rgba.length === 4) { const rgba = pd.fill_rgba; colorInput.value = MapUtils.rgbToHex(rgba[0], rgba[1], rgba[2]); alphaInput.value = String(rgba[3] | 0); alphaVal.textContent = String(rgba[3] | 0); }
    setEmblemPreview(pd);
    setProvinceCardPreview(pd);
  }

  function parseManualNumberish(raw) {
    const txt = String(raw || "").trim();
    if (!txt) return null;
    const n = Number(txt.replace(',', '.'));
    return Number.isFinite(n) ? n : null;
  }

  function parseManualList(raw, numeric) {
    const parts = String(raw || "").split(',').map((v) => String(v || "").trim()).filter(Boolean);
    if (!numeric) return parts;
    return parts.map((v) => Number(v) >>> 0).filter(Boolean);
  }

  function pickExtraFields(source, reservedKeys) {
    const out = {};
    for (const [k, v] of Object.entries(source || {})) {
      if (reservedKeys.has(k)) continue;
      out[k] = v;
    }
    return out;
  }

  function openManualEditModal(map, key, meta) {
    if (!manualEditModal) return;
    const mode = currentMode();
    const pd = getProvData(key);
    if (!pd) return;
    const entityField = MODE_TO_FIELD[mode] || "";
    const entityId = entityField ? String(pd[entityField] || "").trim() : "";
    const bucket = mode === 'provinces' ? null : realmBucketByType(mode);
    const entity = entityId && bucket ? (bucket[entityId] || null) : null;

    manualEditTarget = { mode, key: key >>> 0, pid: Number(pd.pid) >>> 0, entityField, entityId };
    manualEditTitle.textContent = mode === 'provinces' ? 'Редактор провинции' : `Редактор сущности (${mode})`;
    manualEditSubtitle.textContent = mode === 'provinces'
      ? `${pd.name || (meta && meta.name) || '—'} · PID ${Number(pd.pid) >>> 0}`
      : `${entity && entity.name ? entity.name : (entityId || 'не выбрана')} · PID ${Number(pd.pid) >>> 0}`;

    manualName.value = String((mode === 'provinces' ? pd.name : (entity && entity.name)) || '');
    manualOwner.value = String((mode === 'provinces' ? pd.owner : (entity && entity.ruler)) || '');
    manualKingdomId.value = String(pd.kingdom_id || '');
    manualGreatHouseId.value = String(pd.great_house_id || '');
    manualMinorHouseId.value = String(pd.minor_house_id || '');
    manualFreeCityId.value = String(pd.free_city_id || '');
    manualTerrain.value = String(pd.terrain || '');
    manualTreasury.value = String((mode === 'provinces' ? pd.treasury : (entity && entity.treasury)) ?? '');
    manualPopulation.value = String((mode === 'provinces' ? pd.population : (entity && entity.population)) ?? '');
    manualTax.value = String((mode === 'provinces' ? pd.tax_rate : (entity && entity.tax_rate)) ?? '');
    manualBuildings.value = Array.isArray(mode === 'provinces' ? pd.buildings : (entity && entity.buildings)) ? (mode === 'provinces' ? pd.buildings : entity.buildings).join(', ') : '';
    manualBackground.value = String((mode === 'provinces' ? (pd.background_image || pd.province_card_base_image) : (entity && entity.background_image)) || '');
    manualCardImage.value = String((mode === 'provinces' ? pd.province_card_image : (entity && entity.card_image)) || '');
    manualEmblemSvg.value = String((mode === 'provinces' ? pd.emblem_svg : (entity && entity.emblem_svg)) || '');
    manualDescription.value = String((mode === 'provinces' ? pd.wiki_description : (entity && entity.description)) || '');
    manualColor.value = String((entity && entity.color) || '');
    manualCapitalPid.value = String((entity && entity.capital_pid) || '');
    manualProvincePids.value = Array.isArray(entity && entity.province_pids) ? entity.province_pids.join(', ') : '';

    const reservedProvince = new Set(['pid', 'name', 'owner', 'kingdom_id', 'great_house_id', 'minor_house_id', 'free_city_id', 'terrain', 'treasury', 'population', 'tax_rate', 'buildings', 'background_image', 'province_card_base_image', 'province_card_image', 'emblem_svg', 'wiki_description', 'fill_rgba', 'emblem_box', 'emblem_asset_id']);
    const reservedEntity = new Set(['name', 'ruler', 'treasury', 'population', 'tax_rate', 'buildings', 'background_image', 'card_image', 'emblem_svg', 'description', 'color', 'capital_pid', 'province_pids', 'emblem_scale', 'emblem_box']);
    const extras = mode === 'provinces' ? pickExtraFields(pd, reservedProvince) : pickExtraFields(entity || {}, reservedEntity);
    manualExtraJson.value = Object.keys(extras).length ? JSON.stringify(extras, null, 2) : '';

    manualEditModal.classList.add('open');
    manualEditModal.setAttribute('aria-hidden', 'false');
  }

  function closeManualEditModal() {
    if (!manualEditModal) return;
    manualEditModal.classList.remove('open');
    manualEditModal.setAttribute('aria-hidden', 'true');
    manualEditTarget = null;
  }

  function saveManualEditFromModal(map) {
    if (!manualEditTarget) return;
    const pd = getProvData(manualEditTarget.key);
    if (!pd) return;
    const mode = manualEditTarget.mode;
    const extrasRaw = String(manualExtraJson.value || '').trim();
    let extra = {};
    if (extrasRaw) extra = JSON.parse(extrasRaw);

    if (mode === 'provinces') {
      pd.name = String(manualName.value || '').trim();
      pd.owner = ensurePerson(manualOwner.value);
      pd.kingdom_id = String(manualKingdomId.value || '').trim();
      pd.great_house_id = String(manualGreatHouseId.value || '').trim();
      pd.minor_house_id = String(manualMinorHouseId.value || '').trim();
      pd.free_city_id = String(manualFreeCityId.value || '').trim();
      pd.terrain = String(manualTerrain.value || '').trim();
      const treasury = parseManualNumberish(manualTreasury.value);
      const population = parseManualNumberish(manualPopulation.value);
      const tax = parseManualNumberish(manualTax.value);
      if (treasury == null) delete pd.treasury; else pd.treasury = treasury;
      if (population == null) delete pd.population; else pd.population = population;
      if (tax == null) delete pd.tax_rate; else pd.tax_rate = tax;
      const buildings = parseManualList(manualBuildings.value, false);
      if (buildings.length) pd.buildings = buildings; else delete pd.buildings;
      const bg = String(manualBackground.value || '').trim();
      if (bg) pd.background_image = bg; else delete pd.background_image;
      pd.province_card_image = String(manualCardImage.value || '').trim();
      pd.emblem_svg = String(manualEmblemSvg.value || '').trim();
      pd.emblem_box = pd.emblem_svg ? extractSvgBox(pd.emblem_svg) : null;
      pd.wiki_description = String(manualDescription.value || '').trim();
      Object.assign(pd, extra);
      setSelection(manualEditTarget.key, map.getProvinceMeta(manualEditTarget.key));
    } else {
      const entityField = MODE_TO_FIELD[mode] || '';
      let entityId = String(pd[entityField] || '').trim();
      if (!entityId) {
        entityId = `manual_${Date.now()}`;
        pd[entityField] = entityId;
      }
      const bucket = realmBucketByType(mode);
      const entity = ensureRealm(mode, entityId);
      entity.name = String(manualName.value || '').trim();
      entity.ruler = ensurePerson(manualOwner.value);
      const treasury = parseManualNumberish(manualTreasury.value);
      const population = parseManualNumberish(manualPopulation.value);
      const tax = parseManualNumberish(manualTax.value);
      if (treasury == null) delete entity.treasury; else entity.treasury = treasury;
      if (population == null) delete entity.population; else entity.population = population;
      if (tax == null) delete entity.tax_rate; else entity.tax_rate = tax;
      const buildings = parseManualList(manualBuildings.value, false);
      if (buildings.length) entity.buildings = buildings; else delete entity.buildings;
      const bg = String(manualBackground.value || '').trim();
      if (bg) entity.background_image = bg; else delete entity.background_image;
      entity.card_image = String(manualCardImage.value || '').trim();
      entity.emblem_svg = String(manualEmblemSvg.value || '').trim();
      entity.emblem_box = entity.emblem_svg ? extractSvgBox(entity.emblem_svg) : null;
      entity.description = String(manualDescription.value || '').trim();
      entity.color = String(manualColor.value || '').trim() || entity.color || '#ff3b30';
      const capitalPid = Number(manualCapitalPid.value) >>> 0;
      if (capitalPid) entity.capital_pid = capitalPid; else delete entity.capital_pid;
      entity.province_pids = parseManualList(manualProvincePids.value, true);
      pd.kingdom_id = String(manualKingdomId.value || pd.kingdom_id || '').trim();
      pd.great_house_id = String(manualGreatHouseId.value || pd.great_house_id || '').trim();
      pd.minor_house_id = String(manualMinorHouseId.value || pd.minor_house_id || '').trim();
      pd.free_city_id = String(manualFreeCityId.value || pd.free_city_id || '').trim();
      Object.assign(entity, extra);
      bucket[entityId] = entity;
    }

    applyLayerState(map);
    exportStateToTextarea();
    closeManualEditModal();
  }

  function saveProvinceFieldsFromUI() { if (!selectedKey) return; const pd = getProvData(selectedKey); if (!pd) return; pd.name = String(provNameInput.value || "").trim(); pd.owner = ensurePerson(ownerInput.value); pd.terrain = String(terrainSelect.value || "").trim(); if (typeof pd.province_card_image !== "string") pd.province_card_image = ""; selName.textContent = pd.name || selName.textContent; }
  function applyFillFromUI(map) { if (!selectedKey) return; const [r, g, b] = MapUtils.hexToRgb(colorInput.value); const a = Math.max(0, Math.min(255, parseInt(alphaInput.value, 10) | 0)); const rgba = [r, g, b, a]; const pd = getProvData(selectedKey); if (!pd) return; pd.fill_rgba = rgba; if (currentMode() === "provinces") map.setFill(selectedKey, rgba); }
  function exportStateToTextarea() { const out = JSON.parse(JSON.stringify(state)); for (const pd of Object.values(out.provinces || {})) { if (!pd || typeof pd !== "object") continue; if (typeof pd.province_card_base_image === "string" && pd.province_card_base_image.startsWith("data:")) pd.province_card_base_image = ""; } out.generated_utc = new Date().toISOString(); stateTA.value = JSON.stringify(out, null, 2); }
  function downloadJsonFile(filename, payload) { const blob = new Blob([JSON.stringify(payload, null, 2)], { type: "application/json;charset=utf-8" }); const a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = filename; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 1000); }
  function normalizeStateForBackendSave(rawState) {
    const stateForSave = JSON.parse(JSON.stringify(rawState || {}));
    if (Array.isArray(stateForSave.people)) {
      const out = [];
      const seen = new Set();
      for (const person of stateForSave.people) {
        const name = (typeof person === "string") ? person.trim() : String((person && person.name) || "").trim();
        if (!name) continue;
        const key = name.toLowerCase();
        if (seen.has(key)) continue;
        seen.add(key);
        out.push(name);
      }
      stateForSave.people = out;
    }
    if (stateForSave.provinces && typeof stateForSave.provinces === "object") {
      for (const pd of Object.values(stateForSave.provinces)) {
        if (!pd || typeof pd !== "object") continue;
        if (typeof pd.province_card_image === "string" && pd.province_card_image.startsWith("data:")) pd.province_card_image = "";
        if (typeof pd.province_card_base_image === "string" && pd.province_card_base_image.startsWith("data:")) pd.province_card_base_image = "";
      }
    }
    return stateForSave;
  }

  async function saveStateAsBackendVariant(serializedState) {
    const parsedState = normalizeStateForBackendSave(JSON.parse(serializedState));
    const versionRes = await fetch("/api/map/version/", { cache: "no-store" });
    if (!versionRes.ok) throw new Error("Не удалось получить версию карты: HTTP " + versionRes.status);
    const versionPayload = await versionRes.json();
    const ifMatch = String(versionPayload && versionPayload.map_version || "").trim();
    if (!ifMatch) throw new Error("Пустая версия карты (map_version)");

    const payload = {
      state: parsedState,
      include_legacy_svg: false,
      replace_map_state: true,
    };

    let saveRes;
    if (typeof CompressionStream === "function") {
      try {
        const json = JSON.stringify(payload);
        const compressedStream = new Blob([json]).stream().pipeThrough(new CompressionStream("gzip"));
        const compressedBuffer = await new Response(compressedStream).arrayBuffer();
        saveRes = await fetch("/api/migration/apply/", {
          method: "POST",
          headers: {
            "Content-Type": "application/json;charset=utf-8",
            "Content-Encoding": "gzip",
            "If-Match": ifMatch,
          },
          body: compressedBuffer,
        });
      } catch (_err) {
        saveRes = null;
      }
    }

    if (!saveRes) {
      saveRes = await fetch("/api/migration/apply/", {
        method: "POST",
        headers: {
          "Content-Type": "application/json;charset=utf-8",
          "If-Match": ifMatch,
        },
        body: JSON.stringify(payload),
      });
    }

    if (!saveRes.ok) {
      const errText = await saveRes.text();
      throw new Error("HTTP " + saveRes.status + (errText ? (" — " + errText.slice(0, 300)) : ""));
    }
    return saveRes.json();
  }
  function buildProvincePatchFromState(pd) { return { name: String(pd.name || ""), owner: String(pd.owner || ""), terrain: String(pd.terrain || ""), treasury: Number.isFinite(Number(pd.treasury)) ? Number(pd.treasury) : null, population: Number.isFinite(Number(pd.population)) ? Number(pd.population) : null, tax_rate: Number.isFinite(Number(pd.tax_rate)) ? Number(pd.tax_rate) : null, fill_rgba: (Array.isArray(pd.fill_rgba) && pd.fill_rgba.length === 4) ? pd.fill_rgba : null, emblem_svg: String(pd.emblem_svg || ""), emblem_box: (Array.isArray(pd.emblem_box) && pd.emblem_box.length === 2) ? pd.emblem_box : null, emblem_asset_id: String(pd.emblem_asset_id || ""), kingdom_id: String(pd.kingdom_id || ""), great_house_id: String(pd.great_house_id || ""), minor_house_id: String(pd.minor_house_id || ""), free_city_id: String(pd.free_city_id || ""), province_card_image: String(pd.province_card_image || "") }; }
  async function fetchIfMatchVersion() { const res = await fetch("/api/map/version/", { cache: "no-store" }); if (!res.ok) throw new Error("HTTP " + res.status); const payload = await res.json(); const v = String(payload && payload.map_version || "").trim(); if (!v) throw new Error("map_version_missing"); return v; }
  async function persistChangesBatch(changes) { const payload = { changes: Array.isArray(changes) ? changes : [] }; const ifMatch = await fetchIfMatchVersion(); const res = await fetch(CHANGES_APPLY_ENDPOINT, { method: "POST", headers: { "Content-Type": "application/json;charset=utf-8", "If-Match": ifMatch }, body: JSON.stringify(payload) }); if (!res.ok) throw new Error("HTTP " + res.status); }
  async function persistSelectedProvincePatch() { if (!selectedKey) return; const pd = getProvData(selectedKey); if (!pd) return; const payload = { pid: Number(pd.pid) >>> 0, changes: buildProvincePatchFromState(pd) }; if (APP_FLAGS && APP_FLAGS.USE_PARTIAL_SAVE) return persistChangesBatch([{ kind: "province", pid: payload.pid, changes: payload.changes }]); const res = await fetch(PROVINCE_PATCH_ENDPOINT, { method: "PATCH", headers: { "Content-Type": "application/json;charset=utf-8" }, body: JSON.stringify(payload) }); if (!res.ok) throw new Error("HTTP " + res.status); }
  function clampWarlikeCoeff(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return 30;
    return Math.max(1, Math.min(100, Math.round(n)));
  }
  function buildRealmPatchFromState(realm) { return { name: String(realm.name || ""), ruler: String(realm.ruler || ""), color: String(realm.color || "#ff3b30"), capital_pid: Number(realm.capital_pid || 0) >>> 0, emblem_scale: Math.max(0.2, Math.min(3, Number(realm.emblem_scale) || 1)), emblem_svg: String(realm.emblem_svg || ""), emblem_box: (Array.isArray(realm.emblem_box) && realm.emblem_box.length === 2) ? realm.emblem_box : null, province_pids: Array.isArray(realm.province_pids) ? realm.province_pids.map(v => Number(v) >>> 0).filter(Boolean) : [], warlike_coeff: clampWarlikeCoeff(realm.warlike_coeff) }; }
  async function persistRealmPatch(type, id, realm) { const payload = { type: String(type || ""), id: String(id || ""), changes: buildRealmPatchFromState(realm) }; if (APP_FLAGS && APP_FLAGS.USE_PARTIAL_SAVE) return persistChangesBatch([{ kind: "realm", type: payload.type, id: payload.id, changes: payload.changes }]); const res = await fetch(REALM_PATCH_ENDPOINT, { method: "PATCH", headers: { "Content-Type": "application/json;charset=utf-8" }, body: JSON.stringify(payload) }); if (!res.ok) throw new Error("HTTP " + res.status); }

  async function uploadProvinceCardImage(pid, imageDataUrl) {
    const payload = { pid: Number(pid) >>> 0, image_data_url: String(imageDataUrl || "") };
    const res = await fetch(PROVINCE_CARD_UPLOAD_ENDPOINT, {
      method: "POST",
      headers: { "Content-Type": "application/json;charset=utf-8" },
      body: JSON.stringify(payload)
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
  }


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
    loadMinorVassalFields();
  }


  function loadMinorVassalFields() {
    if (!minorVassalSelect || !minorVassalName || !minorVassalRuler) return;
    const gh = minorGreatHouseSelect ? minorGreatHouseSelect.value : "";
    const vid = minorVassalSelect.value;
    if (!gh || !vid) return;
    const layer = getGreatHouseMinorLayer(gh);
    const v = (layer.vassals || []).find(x => x.id === vid);
    if (!v) return;
    minorVassalName.value = v.name || "";
    minorVassalRuler.value = v.ruler || "";
    if (v.ruler) ensurePerson(v.ruler);
  }
  function buildRealmEntries(type) {
    if (type === "minor_houses") {
      const out = [];
      for (const [ghId, ghRealm] of Object.entries(state.great_houses || {})) {
        const layer = ghRealm && ghRealm.minor_house_layer && typeof ghRealm.minor_house_layer === "object" ? ghRealm.minor_house_layer : null;
        if (!layer || !Array.isArray(layer.vassals)) continue;
        for (const v of layer.vassals) {
          if (!v || typeof v !== "object") continue;
          const vid = String(v.id || "").trim();
          if (!vid) continue;
          const id = `vassal:${ghId}:${vid}`;
          out.push([id, {
            id,
            name: String(v.name || vid),
            ruler: String(v.ruler || ""),
            color: String(v.color || ghRealm.color || "#ff3b30"),
            capital_pid: Number(v.capital_pid) >>> 0,
            province_pids: Array.isArray(v.province_pids) ? v.province_pids.map((x) => Number(x) >>> 0).filter(Boolean) : [],
            emblem_svg: "",
            emblem_box: null,
            emblem_scale: 1,
            warlike_coeff: clampWarlikeCoeff((ghRealm && ghRealm.warlike_coeff) || 30),
            __vassal_ref: { great_house_id: ghId, vassal_id: vid }
          }]);
        }
      }
      return out;
    }
    const bucket = realmBucketByType(type);
    return Object.entries(bucket).map(([id, r]) => [id, Object.assign({ id, name: id, ruler: "", color: "#ff3b30", capital_pid: 0, province_pids: [], emblem_svg: "", emblem_box: null, emblem_scale: 1, warlike_coeff: 30 }, r)]);
  }

  function resolveVassalRealmRef(realmId) {
    const raw = String(realmId || "");
    if (!raw.startsWith("vassal:")) return null;
    const parts = raw.split(":");
    if (parts.length < 3) return null;
    const ghId = parts[1];
    const vassalId = parts.slice(2).join(":");
    if (!ghId || !vassalId) return null;
    const gh = (state.great_houses || {})[ghId];
    const layer = gh && gh.minor_house_layer && typeof gh.minor_house_layer === "object" ? gh.minor_house_layer : null;
    if (!layer || !Array.isArray(layer.vassals)) return null;
    const vassal = layer.vassals.find((v) => v && String(v.id || "") === vassalId) || null;
    if (!vassal) return null;
    return { ghId, vassalId, vassal, gh };
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
    let realm = id ? realmBucketByType(type)[id] : null;
    if (!realm && type === "minor_houses") {
      const ref = resolveVassalRealmRef(id);
      if (ref) {
        realm = {
          name: ref.vassal.name || ref.vassalId,
          ruler: String(ref.vassal.ruler || ""),
          color: String(ref.vassal.color || (ref.gh && ref.gh.color) || "#ff3b30"),
          capital_pid: Number(ref.vassal.capital_pid) >>> 0,
          emblem_scale: 1,
          warlike_coeff: clampWarlikeCoeff((ref.gh && ref.gh.warlike_coeff) || 30)
        };
      }
    }
    realmNameInput.value = realm ? (realm.name || id) : "";
    realmRulerInput.value = realm ? String(realm.ruler || "") : "";
    realmColorInput.value = realm && realm.color ? realm.color : "#ff3b30";
    realmCapitalInput.value = realm && (realm.capital_pid || realm.capital_key) ? String(realm.capital_pid || realm.capital_key) : "";
    realmEmblemScaleInput.value = String(realm && realm.emblem_scale ? realm.emblem_scale : 1);
    if (realmWarlikeCoeffInput) realmWarlikeCoeffInput.value = String(clampWarlikeCoeff(realm && realm.warlike_coeff));
    if (realm && realm.ruler) ensurePerson(realm.ruler);
    realmEmblemScaleVal.textContent = realmEmblemScaleInput.value;
    if (realmArrierbanOutput) realmArrierbanOutput.textContent = "";
  }

  function ensureRealm(type, id) {
    const bucket = realmBucketByType(type);
    if (!bucket[id]) bucket[id] = { name: id, ruler: "", color: "#ff3b30", capital_pid: 0, province_pids: [], emblem_svg: "", emblem_box: null, emblem_scale: 1, warlike_coeff: 30 };
    return bucket[id];
  }

  function collectProvinceIdsForArrierban(realm, mode) {
    const pids = new Set();
    if (Array.isArray(realm && realm.province_pids)) {
      for (const raw of realm.province_pids) {
        const pid = Number(raw) >>> 0;
        if (pid > 0) pids.add(pid);
      }
    }
    if (mode === "great_houses") {
      const layer = realm && realm.minor_house_layer && typeof realm.minor_house_layer === "object" ? realm.minor_house_layer : null;
      if (layer) {
        for (const raw of (layer.domain_pids || [])) {
          const pid = Number(raw) >>> 0;
          if (pid > 0) pids.add(pid);
        }
      }
      for (const pd of Object.values(state.provinces || {})) {
        if (!pd || typeof pd !== "object") continue;
        const pid = Number(pd.pid) >>> 0;
        if (!pid) continue;
        if (String(pd.great_house_id || "").trim() === String(realm.id || "").trim()) pids.add(pid);
      }
    }
    return Array.from(pids);
  }

  function calculateArrierbanForRealm(mode, id) {
    const realm = id ? realmBucketByType(mode)[id] : null;
    if (!realm) return null;
    const warlikeCoeff = clampWarlikeCoeff(realm.warlike_coeff);
    const loyaltyCoeff = Math.max(0, Math.min(100, Number(realm.loyalty_coeff) || 0));
    const domainPids = collectProvinceIdsForArrierban(Object.assign({ id }, realm), mode);
    let domainHexes = 0;
    let domainPopulation = 0;
    for (const pid of domainPids) {
      const pd = state.provinces && state.provinces[String(pid)];
      if (!pd || typeof pd !== "object") continue;
      const pop = Math.max(0, Number(pd.population) || 0);
      domainPopulation += pop;
      const hexes = Number(pd.hex_count);
      if (Number.isFinite(hexes) && hexes > 0) domainHexes += hexes;
      else domainHexes += Array.isArray(hexmapData && hexmapData.hexes) ? hexmapData.hexes.filter((h) => (Number(h && h.p) >>> 0) === pid).length : 0;
    }

    const sergeantsPool = Math.floor((((domainPopulation / 3) * (warlikeCoeff / 100)) / 50) * 5);
    const pools = {
      knights: Math.floor((domainHexes / 10) * (warlikeCoeff / 100)),
      nehts: Math.floor((Math.floor(domainHexes / 3) * (warlikeCoeff / 100) * 10) / 10),
      sergeants: sergeantsPool,
      militia: Math.floor((((Math.max(0, domainPopulation - sergeantsPool) * (warlikeCoeff / 100)) / 50) * 5)),
    };

    const supportingSources = [];
    const triggerChance = Math.max(0, Math.min(100, loyaltyCoeff + warlikeCoeff));
    const layer = mode === "great_houses" && realm.minor_house_layer && typeof realm.minor_house_layer === "object" ? realm.minor_house_layer : null;
    if (layer && Array.isArray(layer.vassals)) {
      for (const v of layer.vassals) {
        if (!v || typeof v !== "object") continue;
        if ((Math.random() * 100) >= triggerChance) continue;
        const vid = String(v.id || "").trim() || `vassal_${supportingSources.length + 1}`;
        supportingSources.push({ id: `vassal:${vid}`, name: String(v.name || vid), kind: "vassal" });
      }
      const assigned = new Set((layer.domain_pids || []).map((x) => Number(x) >>> 0).filter(Boolean));
      for (const v of layer.vassals) for (const raw of (v && v.province_pids || [])) { const pid = Number(raw) >>> 0; if (pid) assigned.add(pid); }
      for (const pd of Object.values(state.provinces || {})) {
        if (!pd || typeof pd !== "object") continue;
        if (String(pd.great_house_id || "").trim() !== String(id || "").trim()) continue;
        const pid = Number(pd.pid) >>> 0;
        if (!pid || assigned.has(pid)) continue;
        if ((Math.random() * 100) >= triggerChance) continue;
        supportingSources.push({ id: `unassigned:${pid}`, name: `Неназначенная провинция ${pid}`, kind: "unassigned" });
      }
    }

    return { realm, warlikeCoeff, loyaltyCoeff, domainPids, domainHexes, domainPopulation, pools, supportingArmies: supportingSources.length, supportingSources, triggerChance };
  }

  function distributeLevyByPopulation(domainPids, totalLevy) {
    const rows = [];
    let totalPopulation = 0;
    for (const pid of domainPids) {
      const pd = state.provinces && state.provinces[String(pid)];
      if (!pd || typeof pd !== "object") continue;
      const population = Math.max(0, Number(pd.population) || 0);
      rows.push({ pid, pd, population, levy: 0 });
      totalPopulation += population;
    }
    if (!rows.length || totalLevy <= 0 || totalPopulation <= 0) return rows;
    let assigned = 0;
    for (const row of rows) {
      const raw = (totalLevy * row.population) / totalPopulation;
      row.levy = Math.min(row.population, Math.floor(raw));
      assigned += row.levy;
    }
    let remaining = Math.max(0, totalLevy - assigned);
    if (remaining > 0) {
      const sortable = rows.map((row) => ({ row, frac: row.population > 0 ? ((totalLevy * row.population) / totalPopulation) - row.levy : 0 })).sort((a, b) => b.frac - a.frac);
      for (const item of sortable) {
        if (remaining <= 0) break;
        const cap = Math.max(0, item.row.population - item.row.levy);
        if (cap <= 0) continue;
        const add = Math.min(cap, remaining);
        item.row.levy += add;
        remaining -= add;
      }
    }
    return rows;
  }

  function arrierbanDomainUnitDefs(catalog) {
    const mk = (source, id) => ({ source, id, name: String((catalog[id] || {}).name || id), baseSize: Number((catalog[id] || {}).baseSize) || 1 });
    return [
      mk("militia", "militia"), mk("militia", "militia_tr"),
      mk("sergeants", "shot"), mk("sergeants", "pikes"), mk("sergeants", "assault150"),
      mk("nehts", "bikes"),
      mk("knights", "palatines"), mk("knights", "preventors100"),
    ];
  }

  function unitCategoryLabel(source) {
    const map = { militia: "Ополчение", sergeants: "Сержанты", nehts: "Нехты", knights: "Рыцари" };
    return map[String(source || "")] || "Прочее";
  }

  function resolveUnitCategory(unit) {
    const source = String(unit && unit.source || "");
    if (["militia", "sergeants", "nehts", "knights"].includes(source)) return source;
    const byId = { militia: "militia", militia_tr: "militia", shot: "sergeants", pikes: "sergeants", assault150: "sergeants", bikes: "nehts", palatines: "knights", preventors100: "knights" };
    return byId[String(unit && unit.unit_id || "")] || "other";
  }

  function formatRemainingPools(calc, allocations) {
    const keys = ["militia", "sergeants", "nehts", "knights"];
    return keys.map((key) => {
      const cap = Math.max(0, Math.floor(Number(calc && calc.pools && calc.pools[key]) || 0));
      const used = Math.max(0, Math.floor(Number(allocations && allocations[key]) || 0));
      return `${unitCategoryLabel(key)}: ${Math.max(0, cap - used)} из ${cap}`;
    }).join(", ");
  }

  function arrierbanRandomVassalArmies(calc, catalog) {
    const ids = ["militia", "militia_tr", "shot", "pikes", "assault150", "bikes", "palatines", "preventors100"];
    const armies = [];
    const sources = Array.isArray(calc && calc.supportingSources) ? calc.supportingSources : [];
    for (const src of sources) {
      const units = [];
      const unitCount = 1 + Math.floor(Math.random() * 2);
      for (let i = 0; i < unitCount; i++) {
        const id = ids[Math.floor(Math.random() * ids.length)];
        const unit = catalog[id] || null;
        if (!unit) continue;
        const base = Math.max(1, Number(unit.baseSize) || 1);
        const size = Math.max(Math.ceil(base * 0.1), Math.round(base * (0.8 + (Math.random() * 0.6))));
        units.push({ source: "vassal_random", unit_id: id, unit_name: String(unit.name || id), size, base_size: base });
      }
      armies.push({ army_id: String(src.id || `feudal_${armies.length + 1}`), army_name: String(src.name || `Феодальная армия ${armies.length + 1}`), army_kind: String(src.kind || "vassal"), units });
    }
    return armies;
  }

  function createArrierbanUnitInput(def, value) {
    const wrapper = document.createElement("div");
    wrapper.className = "arrierban-row-alloc__entry";
    wrapper.innerHTML = `<input type="number" min="0" step="1" value="${Math.max(0, Math.floor(Number(value) || 0))}" data-unit-id="${def.id}" data-source="${def.source}" data-base-size="${def.baseSize}" /><button type="button" class="arrierban-row-alloc__remove" data-action="remove-arrierban-row" title="Удалить отряд">−</button>`;
    return wrapper;
  }

  function buildArrierbanDomainRow(def) {
    const row = document.createElement("div");
    row.className = "arrierban-grid";
    const minSize = Math.max(1, Math.ceil(def.baseSize * 0.1));

    const allocWrap = document.createElement("div");
    allocWrap.className = "arrierban-row-alloc";
    allocWrap.dataset.unitId = def.id;
    allocWrap.dataset.source = def.source;
    allocWrap.dataset.baseSize = String(def.baseSize);
    allocWrap.appendChild(createArrierbanUnitInput(def, 0));

    const addBtn = document.createElement("button");
    addBtn.type = "button";
    addBtn.className = "arrierban-row-alloc__add";
    addBtn.dataset.action = "add-arrierban-row";
    addBtn.textContent = "+ Добавить отряд";
    allocWrap.appendChild(addBtn);

    row.appendChild(Object.assign(document.createElement("div"), { textContent: def.name }));
    row.appendChild(Object.assign(document.createElement("div"), { textContent: unitCategoryLabel(def.source) }));
    row.appendChild(Object.assign(document.createElement("div"), { textContent: String(def.baseSize) }));
    row.appendChild(Object.assign(document.createElement("div"), { textContent: String(minSize) }));
    row.appendChild(allocWrap);
    return row;
  }

  function openArrierbanModal(plan) {
    if (!arrierbanModal || !arrierbanRows) return;
    pendingArrierbanPlan = plan;
    const ruler = String(plan.calc.realm.ruler || "").trim() || "Без правителя";
    if (arrierbanTitle) arrierbanTitle.textContent = `Арьербан — ${ruler}`;
    if (arrierbanSubtitle) arrierbanSubtitle.textContent = plan.domainOnly ? `Доменных провинций: ${plan.calc.domainPids.length}, без созыва вассалов` : `Доменных провинций: ${plan.calc.domainPids.length}, доп. вассальных армий: ${plan.calc.supportingArmies}`;
    if (arrierbanPools) arrierbanPools.textContent = `Пулы доменного призыва: рыцари ${plan.calc.pools.knights}, нехты ${plan.calc.pools.nehts}, сержанты ${plan.calc.pools.sergeants}, ополчение ${plan.calc.pools.militia}.`;
    if (arrierbanRemaining) arrierbanRemaining.textContent = `Нераспределено: ${formatRemainingPools(plan.calc, null)}.`;
    if (arrierbanValidation) arrierbanValidation.textContent = "";

    arrierbanRows.innerHTML = "";
    const categories = ["militia", "sergeants", "nehts", "knights"];
    for (const category of categories) {
      const defs = plan.domainDefs.filter((def) => def.source === category);
      if (!defs.length) continue;
      const header = document.createElement("div");
      header.className = "arrierban-category";
      header.innerHTML = `<div class="arrierban-category__title">${unitCategoryLabel(category)}</div>`;
      arrierbanRows.appendChild(header);
      for (const def of defs) arrierbanRows.appendChild(buildArrierbanDomainRow(def));
    }

    arrierbanModal.classList.add("open");
    arrierbanModal.setAttribute("aria-hidden", "false");
  }

  function closeArrierbanModal() {
    if (!arrierbanModal) return;
    arrierbanModal.classList.remove("open");
    arrierbanModal.setAttribute("aria-hidden", "true");
    pendingArrierbanPlan = null;
  }

  function updateArrierbanRemainingCounter() {
    if (!pendingArrierbanPlan || !arrierbanRows || !arrierbanRemaining) return;
    const allocations = { militia: 0, sergeants: 0, nehts: 0, knights: 0 };
    const inputs = Array.from(arrierbanRows.querySelectorAll('input[type="number"]'));
    for (const input of inputs) {
      const source = String(input.dataset.source || "");
      if (!(source in allocations)) continue;
      allocations[source] += Math.max(0, Math.floor(Number(input.value) || 0));
    }
    arrierbanRemaining.textContent = `Нераспределено: ${formatRemainingPools(pendingArrierbanPlan.calc, allocations)}.`;
  }

  function collectDomainUnitsFromModal(plan) {
    const allocations = { militia: 0, sergeants: 0, nehts: 0, knights: 0 };
    const units = [];
    const inputs = arrierbanRows ? Array.from(arrierbanRows.querySelectorAll('input[type="number"]')) : [];
    for (const input of inputs) {
      const size = Math.max(0, Math.floor(Number(input.value) || 0));
      if (size <= 0) continue;
      const baseSize = Math.max(1, Number(input.dataset.baseSize) || 1);
      const minSize = Math.max(1, Math.ceil(baseSize * 0.1));
      if (size < minSize) return { ok: false, error: `Размер отряда ${input.dataset.unitId} меньше минимального (${minSize}).` };
      const source = String(input.dataset.source || "");
      allocations[source] = (allocations[source] || 0) + size;
      const unitCfg = plan.catalog[input.dataset.unitId] || null;
      units.push({ source, unit_id: input.dataset.unitId, unit_name: String(unitCfg && unitCfg.name || input.dataset.unitId), size, base_size: baseSize });
    }
    for (const key of Object.keys(allocations)) {
      const cap = Number(plan.calc.pools[key] || 0);
      if (allocations[key] > cap) return { ok: false, error: `Превышен лимит для ${unitCategoryLabel(key)}: ${allocations[key]} из ${cap}.` };
    }
    if (units.length === 0) {
      const fallbackOrder = ["militia", "sergeants", "nehts", "knights"];
      let fallbackSource = "";
      for (const key of fallbackOrder) {
        if ((Number(plan.calc.pools[key]) || 0) > 0) { fallbackSource = key; break; }
      }
      const allDefs = arrierbanDomainUnitDefs(plan.catalog || {});
      if (!fallbackSource && allDefs.length) fallbackSource = String(allDefs[0].source || "militia");
      const defs = allDefs.find((x) => String(x.source) === fallbackSource) || allDefs[0] || null;
      if (!defs) return { ok: false, error: "Невозможно собрать армию: отсутствует базовый шаблон отряда." };
      const cap = Math.max(1, Math.floor(Number(plan.calc.pools[fallbackSource]) || 0));
      const baseSize = Math.max(1, Number(defs.baseSize) || 1);
      const size = Math.max(1, Math.min(cap, baseSize));
      units.push({ source: fallbackSource, unit_id: defs.id, unit_name: String(defs.name || defs.id), size, base_size: baseSize });
      allocations[fallbackSource] += size;
    }
    if (arrierbanRemaining) arrierbanRemaining.textContent = `Нераспределено: ${formatRemainingPools(plan.calc, allocations)}.`;
    return { ok: true, units, allocations };
  }

  function applyArrierbanToProvinces(calc, domainUnits, vassalUnits) {
    const totalLevy = Math.max(0, Math.floor(domainUnits.reduce((s, u) => s + (Number(u.size) || 0), 0) + vassalUnits.reduce((s, u) => s + (Number(u.size) || 0), 0)));
    const rows = distributeLevyByPopulation(calc.domainPids, totalLevy);
    for (const row of rows) {
      if (row.population <= 0 || row.levy <= 0) continue;
      row.pd.population = Math.max(0, Math.floor(row.population - row.levy));
      row.pd.arrierban_income_penalty = Math.max(0, Math.min(1, row.levy / row.population));
      row.pd.arrierban_levy = (Number(row.pd.arrierban_levy) || 0) + row.levy;
      row.pd.arrierban_raised = true;
    }
    return { totalLevy, rows };
  }

  function formatArrierbanText(calc, domainUnits, vassalArmies, appliedTotal) {
    if (!calc) return "Сущность не найдена.";
    const ruler = String(calc.realm.ruler || "").trim() || "Без правителя";
    const feudalArmies = Array.isArray(vassalArmies) ? vassalArmies : [];
    const feudalUnitsTotal = feudalArmies.reduce((sum, a) => sum + ((a && Array.isArray(a.units)) ? a.units.length : 0), 0);
    return [
      `${ruler}:`,
      "Призыв войск:",
      `Рыцари ${calc.pools.knights}, нехты ${calc.pools.nehts}, сержанты ${calc.pools.sergeants}, ополчение ${calc.pools.militia}.`,
      `Сформировано доменных отрядов: ${domainUnits.length}.`,
      `Сформировано феодальных армий: ${feudalArmies.length} (отрядов: ${feudalUnitsTotal}).`,
      `Всего призвано в поле: ${appliedTotal}.`,
      "На время созыва население провинций уменьшено, доходы снижены пропорционально мобилизации.",
    ].join("\n");
  }



  function getRealmArrierbanProvinceRows(type, id) {
    const field = MODE_TO_FIELD[type] || "";
    if (!field) return [];
    const rows = [];
    for (const pd of Object.values(state.provinces || {})) {
      if (!pd || typeof pd !== "object") continue;
      if (String(pd[field] || "").trim() !== String(id || "").trim()) continue;
      const levy = Math.max(0, Math.floor(Number(pd.arrierban_levy) || 0));
      if (levy <= 0) continue;
      rows.push({ pd, levy, pid: Number(pd.pid) || 0 });
    }
    rows.sort((a, b) => a.pid - b.pid);
    return rows;
  }

  function distributeEvenLoss(totalLoss, capacities) {
    const losses = capacities.map(() => 0);
    let remaining = Math.max(0, Math.floor(Number(totalLoss) || 0));
    let active = capacities.map((_, idx) => idx).filter((idx) => capacities[idx] > 0);
    while (remaining > 0 && active.length) {
      const share = Math.max(1, Math.floor(remaining / active.length));
      const next = [];
      for (const idx of active) {
        const cap = Math.max(0, capacities[idx] - losses[idx]);
        if (cap <= 0) continue;
        const add = Math.min(cap, share, remaining);
        if (add <= 0) continue;
        losses[idx] += add;
        remaining -= add;
        if (losses[idx] < capacities[idx]) next.push(idx);
        if (remaining <= 0) break;
      }
      active = next;
    }
    return losses;
  }

  function dismissArrierbanWithLosses(type, id, requestedLosses) {
    const realm = ensureRealm(type, id);
    const rows = getRealmArrierbanProvinceRows(type, id);
    const mobilizedTotal = rows.reduce((sum, row) => sum + row.levy, 0);
    const fieldTotal = Math.max(0, Math.floor((Array.isArray(realm.arrierban_units) ? realm.arrierban_units : []).reduce((sum, row) => sum + (Number(row && row.size) || 0), 0) + (Array.isArray(realm.arrierban_vassal_units) ? realm.arrierban_vassal_units : []).reduce((sum, row) => sum + (Number(row && row.size) || 0), 0)));
    const impliedLosses = Math.max(0, mobilizedTotal - fieldTotal);
    const losses = Math.min(mobilizedTotal, Math.max(impliedLosses, Math.floor(Number(requestedLosses) || 0)));
    const lossByProvince = distributeEvenLoss(losses, rows.map((row) => row.levy));

    let returnedTotal = 0;
    for (let i = 0; i < rows.length; i++) {
      const row = rows[i];
      const returned = Math.max(0, row.levy - (lossByProvince[i] || 0));
      row.pd.population = Math.max(0, Math.floor((Number(row.pd.population) || 0) + returned));
      row.pd.arrierban_levy = 0;
      row.pd.arrierban_income_penalty = 0;
      row.pd.arrierban_raised = false;
      returnedTotal += returned;
    }

    realm.arrierban_units = [];
    realm.arrierban_vassal_armies = [];
    realm.arrierban_vassal_units = [];
    realm.arrierban_active = false;
    realm.arrierban_domain_only = false;

    return {
      mobilizedTotal,
      fieldTotal,
      impliedLosses,
      losses,
      returnedTotal,
      provinces: rows.length,
    };
  }

  function normalizeArmyUnits(units) {
    const out = [];
    const map = new Map();
    for (const unit of (Array.isArray(units) ? units : [])) {
      if (!unit || typeof unit !== "object") continue;
      const unitId = String(unit.unit_id || "").trim();
      const size = Math.max(0, Math.floor(Number(unit.size) || 0));
      if (!unitId || size <= 0) continue;
      const key = unitId;
      if (!map.has(key)) {
        map.set(key, { source: String(unit.source || ""), unit_id: unitId, unit_name: String(unit.unit_name || unitId), size: 0, base_size: Math.max(1, Number(unit.base_size) || 1) });
      }
      map.get(key).size += size;
    }
    for (const v of map.values()) out.push(v);
    return out;
  }

  function sanitizeArmyUnits(units) {
    const out = [];
    for (const unit of (Array.isArray(units) ? units : [])) {
      if (!unit || typeof unit !== "object") continue;
      const unitId = String(unit.unit_id || "").trim();
      const size = Math.max(0, Math.floor(Number(unit.size) || 0));
      if (!unitId || size <= 0) continue;
      out.push({
        source: String(unit.source || ""),
        unit_id: unitId,
        unit_name: String(unit.unit_name || unitId),
        size,
        base_size: Math.max(1, Number(unit.base_size) || 1),
      });
    }
    return out;
  }

  function openArmyManageModal(type, id) {
    if (!armyManageModal || !armyManageList) return;
    const realm = ensureRealm(type, id);
    const armies = [];
    armies.push({ army_id: "domain", army_name: "Доменная армия", army_kind: "domain", units: Array.isArray(realm.arrierban_units) ? realm.arrierban_units.map((u) => Object.assign({}, u)) : [] });

    if (Array.isArray(realm.arrierban_vassal_armies) && realm.arrierban_vassal_armies.length) {
      for (const a of realm.arrierban_vassal_armies) {
        if (!a || typeof a !== "object") continue;
        armies.push({ army_id: String(a.army_id || `feudal_${armies.length}`), army_name: String(a.army_name || "Феодальная армия"), army_kind: String(a.army_kind || "vassal"), units: Array.isArray(a.units) ? a.units.map((u) => Object.assign({}, u)) : [] });
      }
    } else if (Array.isArray(realm.arrierban_vassal_units) && realm.arrierban_vassal_units.length) {
      armies.push({ army_id: "feudal_legacy", army_name: "Феодальная армия", army_kind: "vassal", units: realm.arrierban_vassal_units.map((u) => Object.assign({}, u)) });
    }

    pendingArmyManage = { type, id, armies };
    if (armyManageTitle) armyManageTitle.textContent = "Менеджмент армий";
    if (armyManageSubtitle) armyManageSubtitle.textContent = `Сущность: ${String(realm.name || id)}`;
    if (armyManageValidation) armyManageValidation.textContent = "";
    renderArmyManageRows();
    armyManageModal.classList.add("open");
    armyManageModal.setAttribute("aria-hidden", "false");
  }

  function closeArmyManageModal() {
    if (!armyManageModal) return;
    armyManageModal.classList.remove("open");
    armyManageModal.setAttribute("aria-hidden", "true");
    pendingArmyManage = null;
  }

  function renderArmyManageRows() {
    if (!armyManageList) return;
    const armies = pendingArmyManage && Array.isArray(pendingArmyManage.armies) ? pendingArmyManage.armies : [];
    pendingArmyManage.flatRows = [];
    armyManageList.innerHTML = "";
    const categories = ["militia", "sergeants", "nehts", "knights", "other"];
    for (const category of categories) {
      const section = document.createElement("div");
      section.className = "army-manager-category";
      section.innerHTML = `<div class="army-manager-category__title">${unitCategoryLabel(category)}</div>`;
      let hasRows = false;
      armies.forEach((army, armyIdx) => {
        const units = Array.isArray(army.units) ? army.units : [];
        for (let unitIdx = 0; unitIdx < units.length; unitIdx++) {
          const row = units[unitIdx];
          if (resolveUnitCategory(row) !== category) continue;
          hasRows = true;
          const flatIdx = pendingArmyManage.flatRows.length;
          pendingArmyManage.flatRows.push({ armyIdx, unitIdx });
          const div = document.createElement("div");
          div.className = "army-manager-row";
          const armyLabel = `${army.army_kind === "domain" ? "Домен" : "Феод"}: ${army.army_name}`;
          div.innerHTML = `<input type="checkbox" data-idx="${flatIdx}" /><div>${armyLabel}</div><div>${row.unit_name || row.unit_id || "unit"}</div><div>${Number(row.size) || 0}</div>`;
          section.appendChild(div);
        }
      });
      if (!hasRows) {
        const empty = document.createElement("div");
        empty.className = "small";
        empty.textContent = "Нет отрядов";
        section.appendChild(empty);
      }
      armyManageList.appendChild(section);
    }
  }

  function armyManageSelectedEntries() {
    if (!armyManageList || !pendingArmyManage) return [];
    const flat = Array.isArray(pendingArmyManage.flatRows) ? pendingArmyManage.flatRows : [];
    return Array.from(armyManageList.querySelectorAll('input[type="checkbox"]:checked'))
      .map((cb) => flat[Number(cb.dataset.idx) || 0] || null)
      .filter(Boolean)
      .sort((a,b) => (a.armyIdx - b.armyIdx) || (a.unitIdx - b.unitIdx));
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
    mapInstanceRef = map || mapInstanceRef;
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
    renderArmyMarkers();
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
  function clearArmyMarkers() {
    if (!armyMarkersLayer) return;
    armyMarkersLayer.innerHTML = "";
  }

  function getRealmArmyMarkerInfo(type, id, realm) {
    const field = MODE_TO_FIELD[type] || "";
    let capitalPid = Number(realm && realm.capital_pid) >>> 0;
    if (!capitalPid && Array.isArray(realm && realm.province_pids) && realm.province_pids.length > 0) {
      capitalPid = Number(realm.province_pids[0]) >>> 0;
    }
    if (!capitalPid && type === "great_houses") {
      const layer = realm && realm.minor_house_layer && typeof realm.minor_house_layer === "object" ? realm.minor_house_layer : null;
      if (layer && Array.isArray(layer.domain_pids) && layer.domain_pids.length > 0) capitalPid = Number(layer.domain_pids[0]) >>> 0;
    }
    let targetId = String(id || "").trim();
    if (type === "minor_houses") {
      const ref = resolveVassalRealmRef(id);
      if (ref) {
        targetId = String(ref.vassalId || "").trim();
        if (!capitalPid && Array.isArray(ref.vassal && ref.vassal.province_pids) && ref.vassal.province_pids.length > 0) {
          capitalPid = Number(ref.vassal.province_pids[0]) >>> 0;
        }
      }
    }
    if (!capitalPid && field) {
      for (const pd of Object.values(state.provinces || {})) {
        if (!pd || typeof pd !== "object") continue;
        if (String(pd[field] || "").trim() !== targetId) continue;
        capitalPid = Number(pd.pid) >>> 0;
        if (capitalPid) break;
      }
    }
    const key = capitalPid ? keyForPid(capitalPid) : 0;
    const meta = key ? mapInstanceRef && mapInstanceRef.getProvinceMeta(key) : null;
    if (!meta) return null;
    const colorHex = String(realm && realm.color || "#3f6aa2");
    const emblemSrc = emblemSourceToDataUri(realm && realm.emblem_svg || "");
    return { x: Number(meta.cx || 0), y: Number(meta.cy || 0), colorHex, emblemSrc, key };
  }

  function createArmyMarker(xPct, yPct, colorHex, emblemSrc, label, isFeudal) {
    if (!armyMarkersLayer) return;
    const marker = document.createElement("div");
    marker.className = isFeudal ? "army-marker army-marker--feudal" : "army-marker";
    marker.style.left = `${xPct}%`;
    marker.style.top = `${yPct}%`;
    marker.style.background = colorHex;
    marker.title = label;
    if (emblemSrc) {
      const img = document.createElement("img");
      img.className = "army-marker__emblem";
      img.src = emblemSrc;
      img.alt = label;
      marker.appendChild(img);
    } else {
      marker.textContent = isFeudal ? "Ф" : "Д";
    }
    armyMarkersLayer.appendChild(marker);
  }

  function renderArmyMarkers() {
    clearArmyMarkers();
    if (!armyMarkersLayer || !state || !mapInstanceRef) return;
    const allTypes = ["kingdoms", "great_houses", "minor_houses", "free_cities"];
    for (const type of allTypes) {
      const bucket = realmBucketByType(type);
      for (const [id, realm] of Object.entries(bucket || {})) {
        if (!realm || !realm.arrierban_active) continue;
        const info = getRealmArmyMarkerInfo(type, id, realm);
        if (!info) continue;
        const xPct = (info.x / Math.max(1, mapInstanceRef.W)) * 100;
        const yPct = (info.y / Math.max(1, mapInstanceRef.H)) * 100;
        const hasDomain = Array.isArray(realm.arrierban_units) && realm.arrierban_units.length > 0;
        const hasFeudal = (Array.isArray(realm.arrierban_vassal_armies) && realm.arrierban_vassal_armies.some((a) => a && Array.isArray(a.units) && a.units.length > 0)) || (Array.isArray(realm.arrierban_vassal_units) && realm.arrierban_vassal_units.length > 0);
        if (hasDomain) createArmyMarker(hasFeudal ? xPct - 0.7 : xPct, yPct, info.colorHex, info.emblemSrc, "Доменная армия", false);
        if (hasFeudal) createArmyMarker(hasDomain ? xPct + 0.7 : xPct, yPct, info.colorHex, info.emblemSrc, "Феодальная армия", true);
      }
    }
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
    mapInstanceRef = map;
    if (btnRefreshTurn) btnRefreshTurn.addEventListener("click", () => { refreshTurnPanel().catch(() => {}); });
    if (btnMakeTurn) btnMakeTurn.addEventListener("click", () => { makeNextTurn().catch(() => {}); });
    if (btnOpenTurnAdmin) btnOpenTurnAdmin.addEventListener("click", () => {
      window.open('turn_admin.html', '_blank', 'noopener');
    });

    btnApplyFill.addEventListener("click", () => applyFillFromUI(map));
    btnClearFill.addEventListener("click", () => { if (!selectedKey) return; const pd = getProvData(selectedKey); if (pd) pd.fill_rgba = null; if (currentMode() === "provinces") map.clearFill(selectedKey); });
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
    if (personModalClose) personModalClose.addEventListener("click", closePersonModal);
    if (personModal) personModal.addEventListener("click", (evt) => { if (evt.target === personModal) closePersonModal(); });
    window.addEventListener("keydown", (evt) => { if (evt.key === "Escape") { closePersonModal(); closeArrierbanModal(); closeArmyManageModal(); } });
    realmTypeSelect.addEventListener("change", rebuildRealmSelect);
    realmSelect.addEventListener("change", loadRealmFields);
    if (minorVassalSelect) minorVassalSelect.addEventListener("change", loadMinorVassalFields);
    rebuildMinorHouseControls();
    if (minorGreatHouseSelect) minorGreatHouseSelect.addEventListener("change", rebuildMinorHouseControls);
    btnNewRealm.addEventListener("click", () => {
      if (realmTypeSelect.value === "minor_houses") {
        alert("Для «Малых Домов» используйте блок «Слой Малые Дома» ниже: там создаются вассалы.");
        return;
      }
      const id = prompt("ID сущности (латиница/цифры):");
      if (!id) return;
      ensureRealm(realmTypeSelect.value, id.trim());
      rebuildRealmSelect(); rebuildMinorHouseControls(); realmSelect.value = id.trim(); loadRealmFields(); exportStateToTextarea();
    });
    btnSaveRealm.addEventListener("click", async () => {
      const type = realmTypeSelect.value; const id = realmSelect.value; if (!id) return;
      if (type === "minor_houses") {
        const ref = resolveVassalRealmRef(id);
        if (ref) {
          ref.vassal.name = String(realmNameInput.value || ref.vassalId).trim() || ref.vassalId;
          ref.vassal.ruler = ensurePerson(realmRulerInput.value);
          ref.vassal.color = String(realmColorInput.value || "#ff3b30");
          ref.vassal.capital_pid = Number(realmCapitalInput.value) >>> 0;
          rebuildRealmSelect(); rebuildMinorHouseControls(); realmSelect.value = id; loadRealmFields(); applyLayerState(map); setSelection(selectedKey, map.getProvinceMeta(selectedKey)); exportStateToTextarea();
          return;
        }
      }
      const realm = ensureRealm(type, id);
      realm.name = String(realmNameInput.value || id).trim() || id;
      realm.ruler = ensurePerson(realmRulerInput.value);
      realm.color = String(realmColorInput.value || "#ff3b30");
      realm.capital_pid = Number(realmCapitalInput.value) >>> 0;
      realm.emblem_scale = Math.max(0.2, Math.min(3, Number(realmEmblemScaleInput.value) || 1));
      realm.warlike_coeff = clampWarlikeCoeff(realmWarlikeCoeffInput ? realmWarlikeCoeffInput.value : realm.warlike_coeff);
      rebuildRealmSelect(); rebuildMinorHouseControls(); realmSelect.value = id; loadRealmFields(); applyLayerState(map); setSelection(selectedKey, map.getProvinceMeta(selectedKey)); exportStateToTextarea();
      if (APP_FLAGS && APP_FLAGS.USE_PARTIAL_SAVE) {
        try { await persistRealmPatch(type, id, realm); }
        catch (err) { alert("PATCH сохранение сущности не удалось: " + (err && err.message ? err.message : err)); }
      }
    });
    btnAddSelectedToRealm.addEventListener("click", () => {
      const type = realmTypeSelect.value; const id = realmSelect.value; if (!id) return;
      if (type === "minor_houses") {
        const ref = resolveVassalRealmRef(id);
        if (!ref) return;
        const keys = selectedKeys.size ? Array.from(selectedKeys) : (selectedKey ? [selectedKey] : []);
        const pids = [];
        for (const key of keys) {
          const pd = getProvData(key);
          if (!pd) continue;
          const pid = Number(pd.pid) >>> 0;
          if (!pid) continue;
          pd.minor_house_id = ref.vassalId;
          pd.great_house_id = ref.ghId;
          pids.push(pid);
        }
        const set = new Set(Array.isArray(ref.vassal.province_pids) ? ref.vassal.province_pids.map((v) => Number(v) >>> 0).filter(Boolean) : []);
        for (const pid of pids) set.add(pid);
        ref.vassal.province_pids = Array.from(set);
        applyLayerState(map); exportStateToTextarea();
        return;
      }
      const field = MODE_TO_FIELD[type]; const realm = ensureRealm(type, id);
      const keys = selectedKeys.size ? Array.from(selectedKeys) : (selectedKey ? [selectedKey] : []);
      for (const key of keys) { const pd = getProvData(key); if (pd) pd[field] = id; }
      realm.province_pids = keys.map(k => pidByKey.get(k) || 0).filter(Boolean);
      applyLayerState(map); exportStateToTextarea();
    });
    btnRemoveSelectedFromRealm.addEventListener("click", () => {
      const type = realmTypeSelect.value; const id = realmSelect.value; if (!id) return;
      if (type === "minor_houses") {
        const ref = resolveVassalRealmRef(id);
        if (!ref) return;
        const keys = selectedKeys.size ? Array.from(selectedKeys) : (selectedKey ? [selectedKey] : []);
        const remove = new Set();
        for (const key of keys) {
          const pd = getProvData(key);
          if (!pd) continue;
          const pid = Number(pd.pid) >>> 0;
          if (!pid) continue;
          if (String(pd.minor_house_id || "") === ref.vassalId) pd.minor_house_id = "";
          remove.add(pid);
        }
        ref.vassal.province_pids = (Array.isArray(ref.vassal.province_pids) ? ref.vassal.province_pids : [])
          .map((v) => Number(v) >>> 0)
          .filter((pid) => pid && !remove.has(pid));
        applyLayerState(map); exportStateToTextarea();
        return;
      }
      const field = MODE_TO_FIELD[type];
      const keys = selectedKeys.size ? Array.from(selectedKeys) : (selectedKey ? [selectedKey] : []);
      for (const key of keys) { const pd = getProvData(key); if (pd && pd[field] === id) pd[field] = ""; }
      applyLayerState(map); exportStateToTextarea();
    });

    realmUploadEmblemBtn.addEventListener("click", () => realmEmblemFile.click());
    realmEmblemFile.addEventListener("change", async () => {
      const file = realmEmblemFile.files && realmEmblemFile.files[0]; realmEmblemFile.value = ""; if (!file) return;
      const type = realmTypeSelect.value; const id = realmSelect.value; if (!id) return;
      if (type === "minor_houses" && resolveVassalRealmRef(id)) {
        alert("У вассалов герб задаётся через столичную провинцию (герб провинции), а не через сущность.");
        return;
      }
      const text = String(await file.text() || "").replace(/^﻿/, "");
      const safeSvg = sanitizeSvgText(text);
      const realm = ensureRealm(type, id); realm.emblem_svg = safeSvg; realm.emblem_box = extractSvgBox(safeSvg);
      applyLayerState(map); exportStateToTextarea();
    });
    realmRemoveEmblemBtn.addEventListener("click", () => {
      const type = realmTypeSelect.value; const id = realmSelect.value; if (!id) return;
      if (type === "minor_houses" && resolveVassalRealmRef(id)) {
        alert("У вассалов герб задаётся через столичную провинцию.");
        return;
      }
      const realm = ensureRealm(type, id); realm.emblem_svg = ""; realm.emblem_box = null; applyLayerState(map); exportStateToTextarea();
    });


    if (arrierbanClose) arrierbanClose.addEventListener("click", closeArrierbanModal);
    if (arrierbanModal) arrierbanModal.addEventListener("click", (evt) => { if (evt.target === arrierbanModal) closeArrierbanModal(); });
    if (arrierbanRows) arrierbanRows.addEventListener("click", (evt) => {
      const btn = evt.target && evt.target.closest ? evt.target.closest('button[data-action]') : null;
      if (!btn) return;
      const action = String(btn.dataset.action || "");
      const allocWrap = btn.closest('.arrierban-row-alloc');
      if (!allocWrap) return;
      const unitId = String(allocWrap.dataset.unitId || "");
      const source = String(allocWrap.dataset.source || "");
      const baseSize = Math.max(1, Number(allocWrap.dataset.baseSize) || 1);
      const def = { id: unitId, source, baseSize };

      if (action === "add-arrierban-row") {
        const addBtn = allocWrap.querySelector('button[data-action="add-arrierban-row"]');
        const entry = createArrierbanUnitInput(def, 0);
        allocWrap.insertBefore(entry, addBtn || null);
        const input = entry.querySelector('input[type="number"]');
        if (input) input.focus();
        updateArrierbanRemainingCounter();
      } else if (action === "remove-arrierban-row") {
        const entry = btn.closest('.arrierban-row-alloc__entry');
        if (!entry) return;
        const entries = allocWrap.querySelectorAll('.arrierban-row-alloc__entry');
        if (entries.length <= 1) {
          const input = entry.querySelector('input[type="number"]');
          if (input) input.value = '0';
          updateArrierbanRemainingCounter();
          return;
        }
        entry.remove();
        updateArrierbanRemainingCounter();
      }
    });


    if (arrierbanRows) arrierbanRows.addEventListener("input", (evt) => {
      const input = evt.target && evt.target.matches && evt.target.matches('input[type="number"]') ? evt.target : null;
      if (!input) return;
      updateArrierbanRemainingCounter();
    });
    async function startArrierban(domainOnly) {
      const type = realmTypeSelect.value;
      const id = realmSelect.value;
      if (!id) return alert("Сначала выберите сущность.");
      if (type === "minor_houses" && resolveVassalRealmRef(id)) return alert("Арьербан запускается для Королевств, Больших Домов и Вольных Городов. Для вассалов используйте арьербан сюзерена.");
      if (!realmArrierbanOutput) return;
      try {
        await ensureHexmapDataLoaded();
        const data = await ensureBattleSimDataLoaded();
        const realm = ensureRealm(type, id);
        if (realm.arrierban_active) {
          realmArrierbanOutput.textContent = "Арьербан уже активен для этой сущности. Сначала распустите войско.";
          return;
        }
        const calc = calculateArrierbanForRealm(type, id);
        const domainDefs = arrierbanDomainUnitDefs((data && data.UNIT_CATALOG) || {});
        openArrierbanModal({ type, id, calc, catalog: (data && data.UNIT_CATALOG) || {}, domainDefs, domainOnly: !!domainOnly });
      } catch (err) {
        realmArrierbanOutput.textContent = "Не удалось подготовить арьербан: " + (err && err.message ? err.message : err);
      }
    }

    if (realmArrierbanBtn) realmArrierbanBtn.addEventListener("click", () => { startArrierban(false).catch(() => {}); });
    if (realmDomainArrierbanBtn) realmDomainArrierbanBtn.addEventListener("click", () => { startArrierban(true).catch(() => {}); });
    if (realmArrierbanDismissBtn) realmArrierbanDismissBtn.addEventListener("click", () => {
      const type = realmTypeSelect.value;
      const id = realmSelect.value;
      if (!id) return alert("Сначала выберите сущность.");
      if (type === "minor_houses" && resolveVassalRealmRef(id)) return alert("Для вассалов роспуск армий выполняется через арьербан сюзерена.");
      const realm = ensureRealm(type, id);
      if (!realm.arrierban_active) return alert("У сущности нет активных армий для роспуска.");
      const rows = getRealmArrierbanProvinceRows(type, id);
      const mobilizedTotal = rows.reduce((sum, row) => sum + row.levy, 0);
      const fieldTotal = Math.max(0, Math.floor((Array.isArray(realm.arrierban_units) ? realm.arrierban_units : []).reduce((sum, row) => sum + (Number(row && row.size) || 0), 0) + (Array.isArray(realm.arrierban_vassal_units) ? realm.arrierban_vassal_units : []).reduce((sum, row) => sum + (Number(row && row.size) || 0), 0)));
      const impliedLosses = Math.max(0, mobilizedTotal - fieldTotal);
      const input = prompt(`Потери при возвращении войск (0..${mobilizedTotal}). Будут распределены равномерно по провинциям.`, String(impliedLosses));
      if (input == null) return;
      const requestedLosses = Math.max(0, Math.floor(Number(input) || 0));
      const summary = dismissArrierbanWithLosses(type, id, requestedLosses);
      applyLayerState(map);
      exportStateToTextarea();
      if (realmArrierbanOutput) realmArrierbanOutput.textContent = [
        `Армии распущены и вернулись в провинции: ${summary.provinces}.`,
        `Всего призвано: ${summary.mobilizedTotal}.`,
        `Возвращено в население: ${summary.returnedTotal}.`,
        `Потери учтены: ${summary.losses} (минимум по текущей численности в поле: ${summary.impliedLosses}).`,
      ].join("\n");
    });

    if (arrierbanApply) arrierbanApply.addEventListener("click", () => {
      if (!pendingArrierbanPlan) return;
      const plan = pendingArrierbanPlan;
      const realm = ensureRealm(plan.type, plan.id);
      const collected = collectDomainUnitsFromModal(plan);
      if (!collected.ok) {
        if (arrierbanValidation) arrierbanValidation.textContent = collected.error || "Проверьте распределение.";
        return;
      }
      const vassalArmies = plan.domainOnly ? [] : arrierbanRandomVassalArmies(plan.calc, plan.catalog);
      const vassalUnits = vassalArmies.flatMap((a) => Array.isArray(a.units) ? a.units : []);
      realm.arrierban_units = collected.units;
      realm.arrierban_vassal_armies = vassalArmies;
      realm.arrierban_vassal_units = vassalUnits;
      realm.arrierban_active = true;
      realm.arrierban_domain_only = !!plan.domainOnly;
      const applied = applyArrierbanToProvinces(plan.calc, collected.units, vassalUnits);
      applyLayerState(map);
      exportStateToTextarea();
      realmArrierbanOutput.textContent = formatArrierbanText(plan.calc, collected.units, vassalArmies, applied.totalLevy);
      closeArrierbanModal();
    });

    if (realmArmyManageBtn) realmArmyManageBtn.addEventListener("click", () => {
      const type = realmTypeSelect.value;
      const id = realmSelect.value;
      if (!id) return alert("Сначала выберите сущность.");
      if (type === "minor_houses" && resolveVassalRealmRef(id)) return alert("Менеджмент армий для вассалов выполняется через сюзерена.");
      openArmyManageModal(type, id);
    });
    if (armyManageClose) armyManageClose.addEventListener("click", closeArmyManageModal);
    if (armyManageModal) armyManageModal.addEventListener("click", (evt) => { if (evt.target === armyManageModal) closeArmyManageModal(); });

    if (armyMergeBtn) armyMergeBtn.addEventListener("click", () => {
      if (!pendingArmyManage) return;
      const entries = armyManageSelectedEntries();
      if (entries.length < 2) { if (armyManageValidation) armyManageValidation.textContent = "Выберите минимум 2 отряда для слияния."; return; }
      const armies = pendingArmyManage.armies;
      const target = entries[0];
      const targetArmy = armies[target.armyIdx];
      const targetUnit = targetArmy && targetArmy.units && targetArmy.units[target.unitIdx];
      if (!targetArmy || !targetUnit) return;

      const uniqueArmyIdx = Array.from(new Set(entries.map((e) => e.armyIdx)));
      const sameArmyKind = uniqueArmyIdx.every((idx) => armies[idx] && armies[idx].army_kind === targetArmy.army_kind);
      if (!sameArmyKind) { if (armyManageValidation) armyManageValidation.textContent = "Сливать армии можно только одного типа (домен/феод)."; return; }

      if (uniqueArmyIdx.length > 1) {
        for (const idx of uniqueArmyIdx) {
          if (idx === target.armyIdx) continue;
          const src = armies[idx];
          if (!src || !Array.isArray(src.units)) continue;
          targetArmy.units.push(...src.units.map((u) => Object.assign({}, u)));
          src.units = [];
        }
        for (let i = armies.length - 1; i >= 0; i--) {
          const a = armies[i];
          if (!a || !Array.isArray(a.units)) continue;
          if (a.army_kind === "domain") continue;
          if (a.units.length === 0) armies.splice(i, 1);
        }
        targetArmy.units = normalizeArmyUnits(targetArmy.units);
        if (armyManageValidation) armyManageValidation.textContent = "";
        renderArmyManageRows();
        return;
      }

      let sum = Number(targetUnit.size) || 0;
      const toRemove = [];
      for (let i = 1; i < entries.length; i++) {
        const e = entries[i];
        const u = targetArmy && targetArmy.units && targetArmy.units[e.unitIdx];
        if (!u) continue;
        if (String(u.unit_id || "") !== String(targetUnit.unit_id || "")) {
          if (armyManageValidation) armyManageValidation.textContent = "Внутри армии можно слить только одинаковые отряды.";
          return;
        }
        sum += Number(u.size) || 0;
        toRemove.push(e.unitIdx);
      }
      targetUnit.size = sum;
      toRemove.sort((a,b)=>b-a);
      for (const unitIdx of toRemove) targetArmy.units.splice(unitIdx, 1);
      if (armyManageValidation) armyManageValidation.textContent = "";
      renderArmyManageRows();
    });

    if (armySplitBtn) armySplitBtn.addEventListener("click", () => {
      if (!pendingArmyManage) return;
      const entries = armyManageSelectedEntries();
      if (entries.length !== 1) { if (armyManageValidation) armyManageValidation.textContent = "Выберите ровно один отряд для разделения."; return; }
      const split = Math.max(1, Math.floor(Number(armySplitSize && armySplitSize.value) || 0));
      const e = entries[0];
      const army = pendingArmyManage.armies[e.armyIdx];
      const row = army && Array.isArray(army.units) ? army.units[e.unitIdx] : null;
      const size = Math.floor(Number(row && row.size) || 0);
      if (!row || split >= size) { if (armyManageValidation) armyManageValidation.textContent = "Размер отделения должен быть меньше исходного отряда."; return; }
      row.size = size - split;
      army.units.splice(e.unitIdx + 1, 0, Object.assign({}, row, { size: split }));
      if (armyManageValidation) armyManageValidation.textContent = "";
      renderArmyManageRows();
    });

    if (armyNormalizeBtn) armyNormalizeBtn.addEventListener("click", () => {
      if (!pendingArmyManage || !Array.isArray(pendingArmyManage.armies)) return;
      for (const army of pendingArmyManage.armies) {
        if (!army || !Array.isArray(army.units)) continue;
        army.units = normalizeArmyUnits(army.units);
      }
      if (armyManageValidation) armyManageValidation.textContent = "";
      renderArmyManageRows();
    });

    if (armyManageSave) armyManageSave.addEventListener("click", () => {
      if (!pendingArmyManage || !Array.isArray(pendingArmyManage.armies)) return;
      const realm = ensureRealm(pendingArmyManage.type, pendingArmyManage.id);
      const domainArmy = pendingArmyManage.armies.find((a) => a && a.army_kind === "domain") || { units: [] };
      const feudalArmies = pendingArmyManage.armies.filter((a) => a && a.army_kind !== "domain");
      realm.arrierban_units = sanitizeArmyUnits(domainArmy.units || []);
      realm.arrierban_vassal_armies = feudalArmies.map((a, idx) => ({
        army_id: String(a.army_id || `feudal_${idx + 1}`),
        army_name: String(a.army_name || `Феодальная армия ${idx + 1}`),
        army_kind: String(a.army_kind || "vassal"),
        units: sanitizeArmyUnits(a.units || []),
      })).filter((a) => a.units.length > 0);
      realm.arrierban_vassal_units = realm.arrierban_vassal_armies.flatMap((a) => a.units || []);
      realm.arrierban_active = (realm.arrierban_units.length + realm.arrierban_vassal_units.length) > 0;
      applyLayerState(map);
      exportStateToTextarea();
      closeArmyManageModal();
      if (realmArrierbanOutput) realmArrierbanOutput.textContent = `Армии обновлены: доменных ${realm.arrierban_units.length}, феодальных армий ${realm.arrierban_vassal_armies.length}.`;
    });

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
      const ruler = ensurePerson(minorVassalRuler.value);
      const idBase = name.toLowerCase().replace(/\s+/g, "_").replace(/[^a-zа-я0-9_\-]/gi, "").slice(0, 32) || `vassal_${layer.vassals.length + 1}`;
      let id = idBase; let n = 2;
      while (layer.vassals.some(v => v.id === id)) { id = `${idBase}_${n++}`; }
      const palette = buildVassalPalette((realmBucketByType("great_houses")[gh] || {}).color || "#ff3b30");
      layer.vassals.push({ id, name, ruler, color: palette[layer.vassals.length % palette.length] || "", capital_pid: 0, province_pids: [] });
      minorVassalName.value = "";
      minorVassalRuler.value = "";
      rebuildMinorHouseControls();
      applyLayerState(map); exportStateToTextarea();
    });
    if (minorVassalName) minorVassalName.addEventListener("change", () => {
      const gh = minorGreatHouseSelect.value; const vid = minorVassalSelect.value; if (!gh || !vid) return;
      const layer = getGreatHouseMinorLayer(gh);
      const v = layer.vassals.find(x => x.id === vid); if (!v) return;
      v.name = String(minorVassalName.value || "").trim() || v.name;
      rebuildMinorHouseControls();
      minorVassalSelect.value = vid;
      applyLayerState(map); exportStateToTextarea();
    });
    if (minorVassalRuler) minorVassalRuler.addEventListener("change", () => {
      const gh = minorGreatHouseSelect.value; const vid = minorVassalSelect.value; if (!gh || !vid) return;
      const layer = getGreatHouseMinorLayer(gh);
      const v = layer.vassals.find(x => x.id === vid); if (!v) return;
      v.ruler = ensurePerson(minorVassalRuler.value);
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
    btnDownload.addEventListener("click", () => { exportStateToTextarea(); const blob = new Blob([stateTA.value], { type: "application/json;charset=utf-8" }); const a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = "backend_state_snapshot.json"; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 1000); });
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
    importFile.addEventListener("change", async () => { const file = importFile.files && importFile.files[0]; if (!file) return; const txt = await file.text(); const obj = JSON.parse(txt); if (!obj.provinces) return alert("Нет provinces"); ensureFeudalSchema(obj); state = Object.assign(state, obj); syncPeopleFromRealmRulers(); rebuildMinorHouseControls(); applyLayerState(map); exportStateToTextarea(); importFile.value = ""; });
    btnSaveServer.addEventListener("click", async () => {
      alert("Legacy full-save отключен. Используйте PATCH/changes apply и кнопку 'Сохранить imported JSON как backend-вариант'.");
    });
    if (btnSaveImportedBackend) btnSaveImportedBackend.addEventListener("click", async () => {
      try {
        exportStateToTextarea();
        const result = await saveStateAsBackendVariant(stateTA.value);
        const stats = result && result.stats ? result.stats : null;
        const summary = stats ? ("\nassets: " + (stats.assets || 0) + ", refs: " + (stats.refs || 0) + ", provinces: " + (stats.provinces || 0)) : "";
        alert("Сохранено в backend-варианте (без legacy emblem_svg)." + summary);
      } catch (err) {
        alert("Не удалось сохранить backend-вариант: " + (err && err.message ? err.message : err));
      }
    });

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
        await uploadProvinceCardImage(pid, cardDataUrl);
        if (APP_FLAGS && APP_FLAGS.USE_PARTIAL_SAVE) {
          await persistSelectedProvincePatch();
        } else {
          const res = await fetch(SAVE_ENDPOINT, {
            method: "POST",
            headers: { "Content-Type": "application/json;charset=utf-8" },
            body: JSON.stringify({ token: SAVE_TOKEN, state: JSON.parse(stateTA.value) })
          });
          if (!res.ok) throw new Error(`HTTP ${res.status}`);
        }
        alert("Карточка провинции собрана и сохранена в папку provinces.");
      } catch (err) {
        alert("Не удалось собрать карточку: " + (err && err.message ? err.message : err));
      }
    });

    provNameInput.addEventListener("change", saveProvinceFieldsFromUI); ownerInput.addEventListener("change", () => { ensurePerson(ownerInput.value); saveProvinceFieldsFromUI(); });
    terrainSelect.addEventListener("change", saveProvinceFieldsFromUI);
    if (manualEditClose) manualEditClose.addEventListener('click', closeManualEditModal);
    if (manualEditSave) manualEditSave.addEventListener('click', () => {
      try {
        saveManualEditFromModal(map);
      } catch (err) {
        alert('Не удалось сохранить изменения: ' + (err && err.message ? err.message : err));
      }
    });
    if (manualEditModal) manualEditModal.addEventListener('click', (evt) => { if (evt.target === manualEditModal) closeManualEditModal(); });
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
    if (!loader || typeof loader.loadStateBackendOnly !== "function") throw new Error("AdminMapStateLoader.loadStateBackendOnly is required");
    const loaded = await loader.loadStateBackendOnly();
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





  async function loadGenealogyCharacters() {
    try {
      const res = await fetch('/api/genealogy/', { cache: 'no-store' });
      if (!res.ok) return [];
      const body = await res.json().catch(() => ({}));
      return Array.isArray(body && body.characters) ? body.characters : [];
    } catch (_) {
      return [];
    }
  }

  function mergeGenealogyPeopleIntoState(characters) {
    if (!Array.isArray(characters) || !characters.length) return;
    if (!isPlainObject(state.people_profiles)) state.people_profiles = {};

    for (const row of characters) {
      if (!row || typeof row !== 'object') continue;
      const name = String(row.name || '').trim();
      if (!name) continue;
      ensurePerson(name);

      if (!isPlainObject(state.people_profiles[name])) {
        state.people_profiles[name] = { photo_url: '', bio: '' };
      }

      const profile = state.people_profiles[name];
      const photo = String(row.photo_url || '').trim();
      const title = String(row.title || '').trim();
      const notes = String(row.notes || '').trim();
      const bioParts = [title, notes].filter(Boolean);
      const bio = bioParts.join('\n\n').trim();

      if (photo && String(profile.photo_url || '').trim() !== photo) profile.photo_url = photo;
      if (bio && String(profile.bio || '').trim() !== bio) profile.bio = bio;
    }
  }

  async function main() {
    state = await loadInitialState(STATE_URL_DEFAULT);
    const genealogyCharacters = await loadGenealogyCharacters();
    mergeGenealogyPeopleIntoState(genealogyCharacters);
    rebuildPeopleControls(); syncPeopleFromRealmRulers(); rebuildTerrainSelect(); rebuildRealmSelect();

    const map = new RasterProvinceMap({
      baseImgId: "baseMap", fillCanvasId: "fill", emblemCanvasId: "emblems", hoverCanvasId: "hover", provincesMetaUrl: "provinces.json", maskUrl: "provinces_id.png",
      onHover: ({ key, meta, evt }) => { if (!key) { tooltip.style.display = "none"; return; } const pid = meta && meta.pid != null ? Number(meta.pid) : (pidByKey.get(key >>> 0) || 0); const pd = state.provinces[String(pid)] || {}; const label = (pd.name || (meta && meta.name) || ("Провинция " + (meta ? meta.pid : ""))); setTooltip(evt, label + " (ID " + (pd.pid || (meta && meta.pid) || "?") + ")"); if (currentMode() === "minor_houses") { const hoverKeys = getMinorHouseHoverKeys(pid); if (hoverKeys.length) map.setHoverHighlights(hoverKeys, [255, 255, 255, 60]); } },
      onClick: ({ key, meta, evt }) => {
        if (evt.ctrlKey || evt.metaKey || evt.shiftKey) {
          if (selectedKeys.has(key)) selectedKeys.delete(key); else selectedKeys.add(key);
          setSelection(key, meta);
          return;
        }
        selectedKeys.clear(); selectedKeys.add(key);
        setSelection(key, meta);
        openManualEditModal(map, key, meta);
      },
      onReady: () => applyLayerState(map)
    });

    await map.init();
    rebuildPidKeyMaps(map);
    initZoomControls(map);
    boot(map);
    setSelection(0, null);
    exportStateToTextarea();
    await refreshTurnPanel();
  }

  main().catch(err => { console.error(err); alert("Ошибка запуска админки: " + err.message); });
})();
