/* public.js (zoom + emblems) */

(function () {
  "use strict";

  const el = (id) => document.getElementById(id);

  const tooltip = el("tooltip");
  const title = el("provTitle");
  const pidEl = el("provPid");
  const ownerEl = el("provOwner");
  const suzerainEl = el("provSuzerain");
  const seniorEl = el("provSenior");
  const vassalsEl = el("provVassals");
  const terrainEl = el("provTerrain");
  const keyEl = el("provKey");

  const reloadBtn = el("reload");
  const urlInput = el("stateUrl");

  const DEFAULT_STATE_URL = "data/map_state.json";

  let state = null;
  let selectedKey = 0;

  function setTooltip(evt, text) {
    if (!text) { tooltip.style.display = "none"; return; }
    tooltip.textContent = text;
    tooltip.style.left = (evt.clientX + 12) + "px";
    tooltip.style.top  = (evt.clientY + 12) + "px";
    tooltip.style.display = "block";
  }

  function setSidebarEmpty() {
    title.textContent = "—";
    pidEl.textContent = "—";
    keyEl.textContent = "—";
    ownerEl.textContent = "—";
    suzerainEl.textContent = "—";
    seniorEl.textContent = "—";
    vassalsEl.textContent = "—";
    terrainEl.textContent = "—";
  }

  function renderProvince(key, meta) {
    selectedKey = key >>> 0;
    if (!state || !selectedKey) { setSidebarEmpty(); return; }

    const pd = state.provinces[String(selectedKey)];
    if (!pd) { setSidebarEmpty(); return; }

    const name = pd.name || (meta && meta.name) || "—";
    title.textContent = name;
    pidEl.textContent = String(pd.pid ?? (meta ? meta.pid : "—"));
    keyEl.textContent = String(selectedKey);

    ownerEl.textContent = pd.owner ? pd.owner : "—";
    suzerainEl.textContent = pd.suzerain ? pd.suzerain : "—";
    seniorEl.textContent = pd.senior ? pd.senior : "—";
    terrainEl.textContent = pd.terrain ? pd.terrain : "—";

    if (Array.isArray(pd.vassals) && pd.vassals.length) {
      vassalsEl.textContent = pd.vassals.join(", ");
    } else {
      vassalsEl.textContent = "—";
    }
  }

  async function loadState(url) {
    const res = await fetch(url, { cache: "no-store" });
    if (!res.ok) throw new Error("HTTP " + res.status + " for " + url);
    const obj = await res.json();
    if (!obj || typeof obj !== "object" || !obj.provinces) throw new Error("Invalid state JSON (missing provinces).");

    for (const pd of Object.values(obj.provinces)) {
      if (!pd) continue;
      if (typeof pd.emblem_svg !== "string") pd.emblem_svg = "";
      if (!Array.isArray(pd.emblem_box) || pd.emblem_box.length !== 2) pd.emblem_box = null;
    }

    return obj;
  }

  async function applyState(map) {
    map.clearAllFills();
    map.clearAllEmblems();

    for (const [k, pd] of Object.entries(state.provinces)) {
      const key = Number(k) >>> 0;

      if (pd && pd.fill_rgba && Array.isArray(pd.fill_rgba) && pd.fill_rgba.length === 4) {
        map.setFill(key, pd.fill_rgba);
      }

      if (pd && pd.emblem_svg) {
        const box = pd.emblem_box ? { w: +pd.emblem_box[0], h: +pd.emblem_box[1] } : { w: 2000, h: 2400 };
        map.setEmblem(key, pd.emblem_svg, box);
      }
    }

    await map.repaintAllEmblems();
  }

  function initZoomControls(map) {
    const mapArea = document.getElementById("mapArea");
    const mapWrap = document.getElementById("mapWrap");
    const baseMap = document.getElementById("baseMap");
    if (!mapArea || !mapWrap || !baseMap) return;

    let currentScale = 1;

    function setZoom(newScale) {
      newScale = Number(newScale);
      if (!isFinite(newScale) || newScale <= 0) newScale = 1;

      const centerX = (mapArea.scrollLeft + mapArea.clientWidth / 2) / currentScale;
      const centerY = (mapArea.scrollTop + mapArea.clientHeight / 2) / currentScale;

      currentScale = newScale;

      const W = baseMap.naturalWidth || map.W || 0;
      const H = baseMap.naturalHeight || map.H || 0;

      if (W && H) {
        mapWrap.style.width = Math.round(W * currentScale) + "px";
        mapWrap.style.height = Math.round(H * currentScale) + "px";
      }

      mapArea.scrollLeft = Math.max(0, centerX * currentScale - mapArea.clientWidth / 2);
      mapArea.scrollTop  = Math.max(0, centerY * currentScale - mapArea.clientHeight / 2);
    }

    const btns = document.querySelectorAll(".zoomBtn");
    for (const b of btns) {
      b.addEventListener("click", () => setZoom(b.getAttribute("data-zoom")));
    }

    setZoom(1);
    window.__setMapZoom = setZoom;
  }

  async function main() {
    urlInput.value = DEFAULT_STATE_URL;

    const map = new RasterProvinceMap({
      baseImgId: "baseMap",
      fillCanvasId: "fill",
      emblemCanvasId: "emblems",
      hoverCanvasId: "hover",
      provincesMetaUrl: "provinces.json",
      maskUrl: "provinces_id.png",
      onHover: ({ key, meta, evt }) => {
        if (!key || !state) { tooltip.style.display = "none"; return; }
        const pd = state.provinces[String(key)] || {};
        const label = pd.name || (meta && meta.name) || ("Провинция " + (meta ? meta.pid : ""));
        setTooltip(evt, label);
      },
      onClick: ({ key, meta }) => renderProvince(key, meta),
      onReady: () => {}
    });

    await map.init();
    initZoomControls(map);

    async function reload() {
      state = await loadState(urlInput.value.trim() || DEFAULT_STATE_URL);
      await applyState(map);
      renderProvince(selectedKey, map.getProvinceMeta(selectedKey));
    }

    reloadBtn.addEventListener("click", () => {
      reload().catch(e => alert("Не удалось загрузить JSON: " + e.message));
    });

    await reload();
    setSidebarEmpty();
  }

  main().catch(err => {
    console.error(err);
    alert("Ошибка запуска карты: " + err.message);
  });
})();
