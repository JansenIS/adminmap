/* public.js (zoom + emblems + feudal layers) */

(function () {
  "use strict";
  const el = (id) => document.getElementById(id);
  const tooltip = el("tooltip");
  const title = el("provTitle"); const pidEl = el("provPid"); const ownerEl = el("provOwner"); const suzerainEl = el("provSuzerain"); const seniorEl = el("provSenior"); const vassalsEl = el("provVassals"); const terrainEl = el("provTerrain"); const keyEl = el("provKey");
  const reloadBtn = el("reload"); const urlInput = el("stateUrl"); const viewModeSelect = el("viewMode"); const toggleProvEmblemsBtn = el("toggleProvEmblems");
  const DEFAULT_STATE_URL = "data/map_state.json";
  const MODE_TO_FIELD = { provinces: null, kingdoms: "kingdom_id", great_houses: "great_house_id", minor_houses: "minor_house_id", free_cities: "free_city_id" };
  const REALM_OVERLAY_MODES = new Set(["kingdoms", "great_houses", "minor_houses"]);
  let state = null; let selectedKey = 0; let hideProvinceEmblems = false;

  function setTooltip(evt, text) { if (!text) { tooltip.style.display = "none"; return; } tooltip.textContent = text; tooltip.style.left = (evt.clientX + 12) + "px"; tooltip.style.top = (evt.clientY + 12) + "px"; tooltip.style.display = "block"; }
  function setSidebarEmpty() { title.textContent = "—"; pidEl.textContent = "—"; keyEl.textContent = "—"; ownerEl.textContent = "—"; suzerainEl.textContent = "—"; seniorEl.textContent = "—"; vassalsEl.textContent = "—"; terrainEl.textContent = "—"; }
  function getStateProvinceByPid(pid) { if (!state || !state.provinces) return null; return state.provinces[String(Number(pid) || 0)] || null; }
  function keyForPid(map, pid) {
    const p = Number(pid); if (!isFinite(p) || p <= 0) return 0;
    for (const [key, meta] of map.provincesByKey.entries()) if (meta && Number(meta.pid) === p) return key >>> 0;
    return 0;
  }
  function renderProvince(key, meta, map) { selectedKey = key >>> 0; if (!state || !selectedKey) return setSidebarEmpty(); const m = meta || (map ? map.getProvinceMeta(selectedKey) : null); const pid = m ? Number(m.pid) : 0; const pd = getStateProvinceByPid(pid); if (!pd) return setSidebarEmpty(); title.textContent = pd.name || (m && m.name) || "—"; pidEl.textContent = String(pd.pid ?? (m ? m.pid : "—")); keyEl.textContent = String(selectedKey); ownerEl.textContent = pd.owner || "—"; suzerainEl.textContent = pd.suzerain || "—"; seniorEl.textContent = pd.senior || "—"; terrainEl.textContent = pd.terrain || "—"; vassalsEl.textContent = (Array.isArray(pd.vassals) && pd.vassals.length) ? pd.vassals.join(", ") : "—"; }

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
    for (const pd of Object.values(obj.provinces)) { if (!pd) continue; if (typeof pd.emblem_svg !== "string") pd.emblem_svg = ""; if (!Array.isArray(pd.emblem_box) || pd.emblem_box.length !== 2) pd.emblem_box = null; }
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
      drawRealmLayer(map, mode, 150, 0.6);
      if (REALM_OVERLAY_MODES.has(mode)) drawRealmLayer(map, "free_cities", 230, 0.75);
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
    const map = new RasterProvinceMap({ baseImgId: "baseMap", fillCanvasId: "fill", emblemCanvasId: "emblems", hoverCanvasId: "hover", provincesMetaUrl: "provinces.json", maskUrl: "provinces_id.png", onHover: ({ key, meta, evt }) => { if (!key || !state) return tooltip.style.display = "none"; const pd = getStateProvinceByPid(meta && meta.pid); const label = (pd && pd.name) || (meta && meta.name) || ("Провинция " + (meta ? meta.pid : "")); setTooltip(evt, label); }, onClick: ({ key, meta }) => renderProvince(key, meta, map) });
    await map.init(); initZoomControls(map);
    async function reload() { state = await loadState(urlInput.value.trim() || DEFAULT_STATE_URL); await applyState(map); renderProvince(selectedKey, map.getProvinceMeta(selectedKey), map); }
    reloadBtn.addEventListener("click", () => reload().catch(e => alert("Не удалось загрузить JSON: " + e.message)));
    viewModeSelect.addEventListener("change", () => applyState(map).catch(e => alert(e.message)));
    if (toggleProvEmblemsBtn) {
      toggleProvEmblemsBtn.addEventListener("click", () => {
        hideProvinceEmblems = !hideProvinceEmblems;
        syncProvEmblemsToggleLabel();
        applyState(map).catch(e => alert(e.message));
      });
      syncProvEmblemsToggleLabel();
    }
    await reload(); setSidebarEmpty();
  }

  main().catch(err => { console.error(err); alert("Ошибка запуска карты: " + err.message); });
})();
