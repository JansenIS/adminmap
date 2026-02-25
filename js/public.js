/* public.js (zoom + emblems + feudal layers) */

(function () {
  "use strict";
  const el = (id) => document.getElementById(id);
  const tooltip = el("tooltip");
  const title = el("provTitle"); const pidEl = el("provPid"); const ownerEl = el("provOwner"); const suzerainEl = el("provSuzerain"); const seniorEl = el("provSenior"); const vassalsEl = el("provVassals"); const terrainEl = el("provTerrain"); const keyEl = el("provKey");
  const reloadBtn = el("reload"); const urlInput = el("stateUrl"); const viewModeSelect = el("viewMode"); const toggleProvEmblemsBtn = el("toggleProvEmblems"); const openMicroMapBtn = el("openMicroMap");
  const provinceModal = el("provinceModal"); const provinceModalClose = el("provinceModalClose");
  const modalProvinceMapImage = el("modalProvinceMapImage"); const modalKingdomHerald = el("modalKingdomHerald"); const modalKingdomName = el("modalKingdomName");
  const modalGreatHouseHerald = el("modalGreatHouseHerald"); const modalGreatHouseName = el("modalGreatHouseName"); const modalMinorHouseHerald = el("modalMinorHouseHerald");
  const modalMinorHouseName = el("modalMinorHouseName"); const modalProvinceHerald = el("modalProvinceHerald"); const modalProvinceTitle = el("modalProvinceTitle");
  const DEFAULT_STATE_URL = "data/map_state.json";
  const MODE_TO_FIELD = { provinces: null, kingdoms: "kingdom_id", great_houses: "great_house_id", minor_houses: "minor_house_id", free_cities: "free_city_id" };
  const REALM_OVERLAY_MODES = new Set(["kingdoms", "great_houses", "minor_houses"]);
  const MINOR_ALPHA = { rest: 40, vassal: 100, vassal_capital: 170, domain: 160, capital: 200 };
  let state = null; let selectedKey = 0; let hideProvinceEmblems = false;
  let selectedMicroTarget = null;

  function setTooltip(evt, text) { if (!text) { tooltip.style.display = "none"; return; } tooltip.textContent = text; tooltip.style.left = (evt.clientX + 12) + "px"; tooltip.style.top = (evt.clientY + 12) + "px"; tooltip.style.display = "block"; }
  function setSidebarEmpty() { title.textContent = "—"; pidEl.textContent = "—"; keyEl.textContent = "—"; ownerEl.textContent = "—"; suzerainEl.textContent = "—"; seniorEl.textContent = "—"; vassalsEl.textContent = "—"; terrainEl.textContent = "—"; }
  function updateMicroMapButton() {
    if (!openMicroMapBtn) return;
    const mode = viewModeSelect.value || "provinces";
    const visible = (mode === "kingdoms" || mode === "great_houses") && !!selectedMicroTarget;
    openMicroMapBtn.classList.toggle("hidden", !visible);
    if (!visible) return;
    const kindLabel = selectedMicroTarget.kind === "free_city"
      ? "территории"
      : (selectedMicroTarget.kind === "great_house" ? "Большого Дома" : "королевства");
    openMicroMapBtn.textContent = `Перейти на микрокарту ${kindLabel}: ${selectedMicroTarget.name}`;
  }
  function getMicroTargetByPid(pid) {
    if (!state) return null;
    const pd = getStateProvinceByPid(pid);
    if (!pd) return null;
    const mode = viewModeSelect.value || "provinces";
    if (mode === "great_houses" && pd.great_house_id && (state.great_houses || {})[pd.great_house_id]) {
      return { kind: "great_house", id: pd.great_house_id, name: state.great_houses[pd.great_house_id].name || pd.great_house_id };
    }
    if (pd.free_city_id && (state.free_cities || {})[pd.free_city_id]) return { kind: "free_city", id: pd.free_city_id, name: state.free_cities[pd.free_city_id].name || pd.free_city_id };
    if (pd.kingdom_id && (state.kingdoms || {})[pd.kingdom_id]) return { kind: "kingdom", id: pd.kingdom_id, name: state.kingdoms[pd.kingdom_id].name || pd.kingdom_id };
    if (mode === "great_houses" && pd.great_house_id) return { kind: "great_house", id: pd.great_house_id, name: pd.great_house_id };
    return null;
  }
  function getStateProvinceByPid(pid) { if (!state || !state.provinces) return null; return state.provinces[String(Number(pid) || 0)] || null; }
  function keyForPid(map, pid) {
    const p = Number(pid); if (!isFinite(p) || p <= 0) return 0;
    for (const [key, meta] of map.provincesByKey.entries()) if (meta && Number(meta.pid) === p) return key >>> 0;
    return 0;
  }
  function renderProvince(key, meta, map) { selectedKey = key >>> 0; if (!state || !selectedKey) { selectedMicroTarget = null; updateMicroMapButton(); return setSidebarEmpty(); } const m = meta || (map ? map.getProvinceMeta(selectedKey) : null); const pid = m ? Number(m.pid) : 0; const pd = getStateProvinceByPid(pid); if (!pd) { selectedMicroTarget = null; updateMicroMapButton(); return setSidebarEmpty(); } title.textContent = pd.name || (m && m.name) || "—"; pidEl.textContent = String(pd.pid ?? (m ? m.pid : "—")); keyEl.textContent = String(selectedKey); ownerEl.textContent = pd.owner || "—"; suzerainEl.textContent = pd.suzerain || "—"; seniorEl.textContent = pd.senior || "—"; terrainEl.textContent = pd.terrain || "—"; vassalsEl.textContent = (Array.isArray(pd.vassals) && pd.vassals.length) ? pd.vassals.join(", ") : "—"; selectedMicroTarget = getMicroTargetByPid(pid); updateMicroMapButton(); }

  function emblemSourceToDataUri(src) {
    const s = String(src || "").trim();
    if (!s) return "";
    if (s.startsWith("data:")) return s;
    if (/<svg[\s>]/i.test(s)) return "data:image/svg+xml;base64," + MapUtils.toBase64Utf8(String(s).replace(/<script[\s\S]*?<\/script\s*>/gi, ""));
    return s;
  }

  function ensureFeudalSchema(obj) {
    if (!obj.kingdoms || typeof obj.kingdoms !== "object") obj.kingdoms = {};
    if (!obj.great_houses || typeof obj.great_houses !== "object") obj.great_houses = {};
    if (!obj.minor_houses || typeof obj.minor_houses !== "object") obj.minor_houses = {};
    if (!obj.free_cities || typeof obj.free_cities !== "object") obj.free_cities = {};
    for (const realm of Object.values(obj.great_houses || {})) {
      if (!realm || typeof realm !== "object") continue;
      if (!realm.minor_house_layer || typeof realm.minor_house_layer !== "object") realm.minor_house_layer = {};
      const layer = realm.minor_house_layer;
      if (!Array.isArray(layer.domain_pids)) layer.domain_pids = [];
      if (!Array.isArray(layer.vassals)) layer.vassals = [];
      if (!(Number(layer.capital_pid) > 0)) layer.capital_pid = Number(realm.capital_pid || realm.capital_key || 0) >>> 0;
      layer.domain_pids = layer.domain_pids.map(v => Number(v) >>> 0).filter(Boolean);
      layer.vassals = layer.vassals.map((v, idx) => ({
        id: String(v && v.id || `vassal_${idx + 1}`).trim() || `vassal_${idx + 1}`,
        name: String(v && (v.name || v.id) || `Вассал ${idx + 1}`).trim() || `Вассал ${idx + 1}`,
        color: String(v && v.color || ""),
        capital_pid: Number(v && v.capital_pid || 0) >>> 0,
        province_pids: Array.isArray(v && v.province_pids) ? v.province_pids.map(x => Number(x) >>> 0).filter(Boolean) : []
      }));
    }
    for (const pd of Object.values(obj.provinces || {})) {
      if (typeof pd.kingdom_id !== "string") pd.kingdom_id = "";
      if (typeof pd.great_house_id !== "string") pd.great_house_id = "";
      if (typeof pd.minor_house_id !== "string") pd.minor_house_id = "";
      if (typeof pd.free_city_id !== "string") pd.free_city_id = "";
    }
  }

  function drawRealmLayer(map, type, opacity, emblemOpacity) {
    const field = MODE_TO_FIELD[type];
    const bucket = state[type] || {};
    for (const [id, realm] of Object.entries(bucket)) {
      const keys = [];
      for (const pd of Object.values(state.provinces)) { if (pd[field] !== id) continue; const k = keyForPid(map, pd.pid); if (k) keys.push(k); }
      if (!keys.length) continue;
      const [r, g, b] = MapUtils.hexToRgb(realm.color || "#ff3b30");
      const cap = keyForPid(map, realm.capital_pid || realm.capital_key || realm.capital);
      keys.forEach(key => map.setFill(key, [r, g, b, key === cap ? Math.min(255, opacity + 50) : opacity]));
      const emblemSrc = emblemSourceToDataUri(realm.emblem_svg);
      if (emblemSrc) {
        const box = realm.emblem_box ? { w: +realm.emblem_box[0], h: +realm.emblem_box[1] } : { w: 2000, h: 2400 };
        map.setGroupEmblem(`${type}:${id}`, keys, emblemSrc, box, { scale: realm.emblem_scale || 1, opacity: emblemOpacity });
      }
    }
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
    const realm = (state.great_houses || {})[greatHouseId];
    if (!realm) return null;
    if (!realm.minor_house_layer || typeof realm.minor_house_layer !== "object") realm.minor_house_layer = {};
    const layer = realm.minor_house_layer;
    if (!Array.isArray(layer.domain_pids)) layer.domain_pids = [];
    if (!Array.isArray(layer.vassals)) layer.vassals = [];
    if (!(Number(layer.capital_pid) > 0)) layer.capital_pid = Number(realm.capital_pid || realm.capital_key || 0) >>> 0;
    return layer;
  }

  function getMinorHouseHoverKeys(map, pid) {
    const pd = getStateProvinceByPid(pid);
    if (!pd || !pd.great_house_id) return [];
    const layer = getGreatHouseMinorLayer(pd.great_house_id);
    if (!layer) return [];

    const hoveredPid = Number(pd.pid) >>> 0;
    if (!hoveredPid) return [];

    const hoveredVassal = (layer.vassals || []).find(v => (v.province_pids || []).some(x => (Number(x) >>> 0) === hoveredPid));
    const pids = hoveredVassal ? (hoveredVassal.province_pids || []) : (layer.domain_pids || []);
    return pids.map(v => keyForPid(map, v)).filter(Boolean);
  }

  function drawMinorHousesLayer(map) {
    drawRealmLayer(map, "great_houses", MINOR_ALPHA.rest, 0);
    drawRealmLayer(map, "free_cities", 230, 0);
    for (const [id, realm] of Object.entries(state.great_houses || {})) {
      const baseHex = realm && realm.color ? realm.color : "#ff3b30";
      const [r, g, b] = MapUtils.hexToRgb(baseHex);
      const allKeys = [];
      for (const pd of Object.values(state.provinces || {})) {
        if (!pd || pd.great_house_id !== id) continue;
        const key = keyForPid(map, pd.pid);
        if (key) allKeys.push(key);
      }
      if (!allKeys.length) continue;
      const layer = getGreatHouseMinorLayer(id);
      const capKey = keyForPid(map, layer && layer.capital_pid ? layer.capital_pid : 0);
      const domainKeys = new Set((layer && layer.domain_pids || []).map(pid => keyForPid(map, pid)).filter(Boolean));
      const vassalPalette = buildVassalPalette(baseHex);
      const vassalKeys = new Set();
      for (let i = 0; i < (layer && layer.vassals ? layer.vassals.length : 0); i++) {
        const v = layer.vassals[i];
        const vHex = v.color || vassalPalette[i % vassalPalette.length] || baseHex;
        v.color = vHex;
        const [vr, vg, vb] = MapUtils.hexToRgb(vHex);
        for (const pid of (v.province_pids || [])) {
          const key = keyForPid(map, pid);
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

  function setModalHerald(imgEl, src, alt) {
    if (!imgEl) return;
    imgEl.alt = alt || "Герб";
    if (src) {
      imgEl.src = src;
      imgEl.style.visibility = "visible";
    } else {
      imgEl.removeAttribute("src");
      imgEl.style.visibility = "hidden";
    }
  }

  function getMinorHouseInfo(pd) {
    if (!state || !pd || !pd.great_house_id) return { name: "—", emblemSvg: "" };
    const realm = (state.great_houses || {})[pd.great_house_id];
    const layer = realm && realm.minor_house_layer;
    const pid = Number(pd.pid) >>> 0;
    if (!layer || !Array.isArray(layer.vassals)) return { name: "—", emblemSvg: "" };
    for (const v of layer.vassals) {
      const pids = Array.isArray(v.province_pids) ? v.province_pids : [];
      if (!pids.some(x => (Number(x) >>> 0) === pid)) continue;
      const capPid = Number(v.capital_pid) >>> 0;
      const cap = capPid ? getStateProvinceByPid(capPid) : null;
      return { name: v.name || "Безымянный вассал", emblemSvg: (cap && cap.emblem_svg) || "" };
    }
    if (Array.isArray(layer.domain_pids) && layer.domain_pids.some(x => (Number(x) >>> 0) === pid)) {
      const capPid = Number(layer.capital_pid) >>> 0;
      const cap = capPid ? getStateProvinceByPid(capPid) : null;
      return { name: "Домен Большого Дома", emblemSvg: (cap && cap.emblem_svg) || "" };
    }
    return { name: "—", emblemSvg: "" };
  }

  async function buildProvinceMaskedImage(map, key) {
    const k = key >>> 0;
    if (!k) return "";
    const mask = map.clipMaskByKey.get(k);
    if (!mask) return "";
    const meta = map.getProvinceMeta(k);
    if (!meta || !Array.isArray(meta.bbox)) return "";
    const [x0, y0, x1, y1] = meta.bbox;
    const bw = Math.max(1, x1 - x0);
    const bh = Math.max(1, y1 - y0);
    const canvas = document.createElement("canvas");
    canvas.width = bw;
    canvas.height = bh;
    const ctx = canvas.getContext("2d");
    if (!ctx) return "";
    ctx.drawImage(map.baseImg, x0, y0, bw, bh, 0, 0, bw, bh);
    ctx.globalCompositeOperation = "destination-in";
    ctx.drawImage(mask, x0, y0, bw, bh, 0, 0, bw, bh);
    ctx.globalCompositeOperation = "source-over";
    return canvas.toDataURL("image/png");
  }

  async function openProvinceModal(map, key, meta) {
    if (!provinceModal || !state || !key) return;
    const m = meta || map.getProvinceMeta(key);
    const pd = m ? getStateProvinceByPid(m.pid) : null;
    if (!pd) return;

    modalProvinceTitle.textContent = (pd.name || m.name || "Провинция").toUpperCase();

    const kingdom = pd.kingdom_id ? (state.kingdoms || {})[pd.kingdom_id] : null;
    const greatHouse = pd.great_house_id ? (state.great_houses || {})[pd.great_house_id] : null;
    const minorHouse = getMinorHouseInfo(pd);

    modalKingdomName.textContent = kingdom && kingdom.name ? kingdom.name : "—";
    modalGreatHouseName.textContent = greatHouse && greatHouse.name ? greatHouse.name : "—";
    modalMinorHouseName.textContent = minorHouse.name || "—";

    setModalHerald(modalProvinceHerald, emblemSourceToDataUri(pd.emblem_svg), "Герб провинции");
    setModalHerald(modalKingdomHerald, emblemSourceToDataUri(kingdom && kingdom.emblem_svg), "Герб королевства");
    setModalHerald(modalGreatHouseHerald, emblemSourceToDataUri(greatHouse && greatHouse.emblem_svg), "Герб большого дома");
    setModalHerald(modalMinorHouseHerald, emblemSourceToDataUri(minorHouse.emblemSvg), "Герб малого дома");

    const savedCardImage = String(pd.province_card_image || "").trim();
    if (savedCardImage) {
      modalProvinceMapImage.src = savedCardImage;
      modalProvinceMapImage.style.visibility = "visible";
    } else {
      const maskedDataUri = await buildProvinceMaskedImage(map, key);
      if (maskedDataUri) {
        modalProvinceMapImage.src = maskedDataUri;
        modalProvinceMapImage.style.visibility = "visible";
      } else {
        modalProvinceMapImage.removeAttribute("src");
        modalProvinceMapImage.style.visibility = "hidden";
      }
    }

    provinceModal.classList.add("open");
    provinceModal.setAttribute("aria-hidden", "false");
  }

  function closeProvinceModal() {
    if (!provinceModal) return;
    provinceModal.classList.remove("open");
    provinceModal.setAttribute("aria-hidden", "true");
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

  async function loadState(url) {
    const res = await fetch(url, { cache: "no-store" }); if (!res.ok) throw new Error("HTTP " + res.status + " for " + url);
    const obj = await res.json(); if (!obj || typeof obj !== "object" || !obj.provinces) throw new Error("Invalid state JSON (missing provinces).");
    for (const pd of Object.values(obj.provinces)) { if (!pd) continue; if (typeof pd.emblem_svg !== "string") pd.emblem_svg = ""; if (!Array.isArray(pd.emblem_box) || pd.emblem_box.length !== 2) pd.emblem_box = null; if (typeof pd.province_card_image !== "string") pd.province_card_image = ""; if (typeof pd.province_card_base_image !== "string") pd.province_card_base_image = ""; }
    ensureFeudalSchema(obj);
    normalizeStateByPid(obj);
    return obj;
  }




  async function applyState(map) {
    const mode = viewModeSelect.value || "provinces";
    map.clearAllFills(); map.clearAllEmblems();
    for (const pd of Object.values(state.provinces)) {
      const key = keyForPid(map, pd.pid);
      if (!key) continue;
      if (mode === "provinces" && pd.fill_rgba && Array.isArray(pd.fill_rgba) && pd.fill_rgba.length === 4) map.setFill(key, pd.fill_rgba);
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
    await map.repaintAllEmblems();
  }


  function syncProvEmblemsToggleLabel() {
    if (!toggleProvEmblemsBtn) return;
    toggleProvEmblemsBtn.textContent = hideProvinceEmblems ? "Показать геральдику провинций" : "Скрыть геральдику провинций";
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

  async function main() {
    urlInput.value = DEFAULT_STATE_URL;
    const map = new RasterProvinceMap({ baseImgId: "baseMap", fillCanvasId: "fill", emblemCanvasId: "emblems", hoverCanvasId: "hover", provincesMetaUrl: "provinces.json", maskUrl: "provinces_id.png", onHover: ({ key, meta, evt }) => { if (!key || !state) return tooltip.style.display = "none"; const pd = getStateProvinceByPid(meta && meta.pid); const label = (pd && pd.name) || (meta && meta.name) || ("Провинция " + (meta ? meta.pid : "")); setTooltip(evt, label); if ((viewModeSelect.value || "provinces") === "minor_houses") { const hoverKeys = getMinorHouseHoverKeys(map, meta && meta.pid); if (hoverKeys.length) map.setHoverHighlights(hoverKeys, [255, 255, 255, 60]); } }, onClick: ({ key, meta }) => { renderProvince(key, meta, map); openProvinceModal(map, key, meta).catch(e => console.warn(e)); } });
    await map.init(); initZoomControls(map);
    async function reload() { state = await loadState(urlInput.value.trim() || DEFAULT_STATE_URL); await applyState(map); renderProvince(selectedKey, map.getProvinceMeta(selectedKey), map); }
    reloadBtn.addEventListener("click", () => reload().catch(e => alert("Не удалось загрузить JSON: " + e.message)));
    viewModeSelect.addEventListener("change", () => {
      updateMicroMapButton();
      applyState(map).catch(e => alert(e.message));
    });
    if (openMicroMapBtn) {
      openMicroMapBtn.addEventListener("click", () => {
        if (!selectedMicroTarget) return;
        const url = `micro.html?kind=${encodeURIComponent(selectedMicroTarget.kind)}&id=${encodeURIComponent(selectedMicroTarget.id)}`;
        window.open(url, "_blank", "noopener");
      });
      updateMicroMapButton();
    }
    if (toggleProvEmblemsBtn) {
      toggleProvEmblemsBtn.addEventListener("click", () => {
        hideProvinceEmblems = !hideProvinceEmblems;
        syncProvEmblemsToggleLabel();
        applyState(map).catch(e => alert(e.message));
      });
      syncProvEmblemsToggleLabel();
    }
    if (provinceModalClose) provinceModalClose.addEventListener("click", closeProvinceModal);
    if (provinceModal) {
      provinceModal.addEventListener("click", (evt) => {
        if (evt.target === provinceModal) closeProvinceModal();
      });
    }
    document.addEventListener("keydown", (evt) => {
      if (evt.key === "Escape") closeProvinceModal();
    });
    await reload(); setSidebarEmpty();
  }

  main().catch(err => { console.error(err); alert("Ошибка запуска карты: " + err.message); });
})();
