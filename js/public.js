/* public.js (zoom + emblems + feudal layers) */

(function () {
  "use strict";
  const el = (id) => document.getElementById(id);
  const tooltip = el("tooltip");
  const title = el("provTitle"); const pidEl = el("provPid"); const ownerEl = el("provOwner"); const suzerainEl = el("provSuzerain"); const seniorEl = el("provSenior"); const vassalsEl = el("provVassals"); const terrainEl = el("provTerrain"); const keyEl = el("provKey");
  const reloadBtn = el("reload"); const urlInput = el("stateUrl"); const viewModeSelect = el("viewMode");
  const DEFAULT_STATE_URL = "data/map_state.json";
  const MODE_TO_FIELD = { provinces: null, kingdoms: "kingdom_id", great_houses: "great_house_id", minor_houses: "minor_house_id" };
  let state = null; let selectedKey = 0;

  function setTooltip(evt, text) { if (!text) { tooltip.style.display = "none"; return; } tooltip.textContent = text; tooltip.style.left = (evt.clientX + 12) + "px"; tooltip.style.top = (evt.clientY + 12) + "px"; tooltip.style.display = "block"; }
  function setSidebarEmpty() { title.textContent = "—"; pidEl.textContent = "—"; keyEl.textContent = "—"; ownerEl.textContent = "—"; suzerainEl.textContent = "—"; seniorEl.textContent = "—"; vassalsEl.textContent = "—"; terrainEl.textContent = "—"; }
  function renderProvince(key, meta) { selectedKey = key >>> 0; if (!state || !selectedKey) return setSidebarEmpty(); const pd = state.provinces[String(selectedKey)]; if (!pd) return setSidebarEmpty(); title.textContent = pd.name || (meta && meta.name) || "—"; pidEl.textContent = String(pd.pid ?? (meta ? meta.pid : "—")); keyEl.textContent = String(selectedKey); ownerEl.textContent = pd.owner || "—"; suzerainEl.textContent = pd.suzerain || "—"; seniorEl.textContent = pd.senior || "—"; terrainEl.textContent = pd.terrain || "—"; vassalsEl.textContent = (Array.isArray(pd.vassals) && pd.vassals.length) ? pd.vassals.join(", ") : "—"; }

  function ensureFeudalSchema(obj) {
    if (!obj.kingdoms || typeof obj.kingdoms !== "object") obj.kingdoms = {};
    if (!obj.great_houses || typeof obj.great_houses !== "object") obj.great_houses = {};
    if (!obj.minor_houses || typeof obj.minor_houses !== "object") obj.minor_houses = {};
    for (const pd of Object.values(obj.provinces || {})) {
      if (typeof pd.kingdom_id !== "string") pd.kingdom_id = "";
      if (typeof pd.great_house_id !== "string") pd.great_house_id = "";
      if (typeof pd.minor_house_id !== "string") pd.minor_house_id = "";
    }
  }

  async function loadState(url) {
    const res = await fetch(url, { cache: "no-store" }); if (!res.ok) throw new Error("HTTP " + res.status + " for " + url);
    const obj = await res.json(); if (!obj || typeof obj !== "object" || !obj.provinces) throw new Error("Invalid state JSON (missing provinces).");
    for (const pd of Object.values(obj.provinces)) { if (!pd) continue; if (typeof pd.emblem_svg !== "string") pd.emblem_svg = ""; if (!Array.isArray(pd.emblem_box) || pd.emblem_box.length !== 2) pd.emblem_box = null; }
    ensureFeudalSchema(obj);
    return obj;
  }

  async function applyState(map) {
    const mode = viewModeSelect.value || "provinces";
    map.clearAllFills(); map.clearAllEmblems();
    for (const [k, pd] of Object.entries(state.provinces)) {
      const key = Number(k) >>> 0;
      if (mode === "provinces" && pd.fill_rgba && Array.isArray(pd.fill_rgba) && pd.fill_rgba.length === 4) map.setFill(key, pd.fill_rgba);
      if (pd.emblem_svg) {
        const box = pd.emblem_box ? { w: +pd.emblem_box[0], h: +pd.emblem_box[1] } : { w: 2000, h: 2400 };
        map.setEmblem(key, pd.emblem_svg, box);
      }
    }

    if (mode !== "provinces") {
      const field = MODE_TO_FIELD[mode];
      const bucket = state[mode] || {};
      for (const [id, realm] of Object.entries(bucket)) {
        const keys = [];
        for (const [k, pd] of Object.entries(state.provinces)) if (pd[field] === id) keys.push(Number(k) >>> 0);
        if (!keys.length) continue;
        const [r, g, b] = MapUtils.hexToRgb(realm.color || "#ff3b30");
        const cap = Number(realm.capital_key) >>> 0;
        keys.forEach(key => map.setFill(key, [r, g, b, key === cap ? 200 : 150]));
        if (realm.emblem_svg) {
          const box = realm.emblem_box ? { w: +realm.emblem_box[0], h: +realm.emblem_box[1] } : { w: 2000, h: 2400 };
          map.setGroupEmblem(`${mode}:${id}`, keys, realm.emblem_svg, box, { scale: realm.emblem_scale || 1, opacity: 0.6 });
        }
      }
    }
    await map.repaintAllEmblems();
  }

  function initZoomControls(map) { const mapArea = document.getElementById("mapArea"); const mapWrap = document.getElementById("mapWrap"); const baseMap = document.getElementById("baseMap"); if (!mapArea || !mapWrap || !baseMap) return; let currentScale = 1; function setZoom(newScale) { newScale = Number(newScale); if (!isFinite(newScale) || newScale <= 0) newScale = 1; const centerX = (mapArea.scrollLeft + mapArea.clientWidth / 2) / currentScale; const centerY = (mapArea.scrollTop + mapArea.clientHeight / 2) / currentScale; currentScale = newScale; const W = baseMap.naturalWidth || map.W || 0; const H = baseMap.naturalHeight || map.H || 0; if (W && H) { mapWrap.style.width = Math.round(W * currentScale) + "px"; mapWrap.style.height = Math.round(H * currentScale) + "px"; } mapArea.scrollLeft = Math.max(0, centerX * currentScale - mapArea.clientWidth / 2); mapArea.scrollTop = Math.max(0, centerY * currentScale - mapArea.clientHeight / 2); } document.querySelectorAll(".zoomBtn").forEach(b => b.addEventListener("click", () => setZoom(b.getAttribute("data-zoom")))); setZoom(1); }

  async function main() {
    urlInput.value = DEFAULT_STATE_URL;
    const map = new RasterProvinceMap({ baseImgId: "baseMap", fillCanvasId: "fill", emblemCanvasId: "emblems", hoverCanvasId: "hover", provincesMetaUrl: "provinces.json", maskUrl: "provinces_id.png", onHover: ({ key, meta, evt }) => { if (!key || !state) return tooltip.style.display = "none"; const pd = state.provinces[String(key)] || {}; const label = pd.name || (meta && meta.name) || ("Провинция " + (meta ? meta.pid : "")); setTooltip(evt, label); }, onClick: ({ key, meta }) => renderProvince(key, meta) });
    await map.init(); initZoomControls(map);
    async function reload() { state = await loadState(urlInput.value.trim() || DEFAULT_STATE_URL); await applyState(map); renderProvince(selectedKey, map.getProvinceMeta(selectedKey)); }
    reloadBtn.addEventListener("click", () => reload().catch(e => alert("Не удалось загрузить JSON: " + e.message)));
    viewModeSelect.addEventListener("change", () => applyState(map).catch(e => alert(e.message)));
    await reload(); setSidebarEmpty();
  }

  main().catch(err => { console.error(err); alert("Ошибка запуска карты: " + err.message); });
})();
