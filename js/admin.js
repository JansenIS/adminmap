/* admin.js (zoom + emblems) */

(function () {
  "use strict";

  const el = (id) => document.getElementById(id);

  const tooltip = el("tooltip");

  const selName = el("selName");
  const selPid  = el("selPid");
  const selKey  = el("selKey");

  const colorInput = el("color");
  const alphaInput = el("alpha");
  const alphaVal   = el("alphaVal");

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

  const STATE_URL_DEFAULT = "data/map_state.json";
  const SAVE_ENDPOINT = "save_state.php";
  const SAVE_TOKEN = "";

  const TERRAIN_TYPES_FALLBACK = [
    "равнины", "холмы", "горы", "лес", "болота", "степь", "пустоши",
    "побережье", "остров", "город", "руины", "озёра/реки"
  ];

  let state = null;
  let selectedKey = 0;

  function setTooltip(evt, text) {
    if (!text) { tooltip.style.display = "none"; return; }
    tooltip.textContent = text;
    tooltip.style.left = (evt.clientX + 12) + "px";
    tooltip.style.top  = (evt.clientY + 12) + "px";
    tooltip.style.display = "block";
  }

  function normalizePeopleList(arr) {
    const out = [];
    const seen = new Set();
    for (const raw of (arr || [])) {
      const s = String(raw || "").trim();
      if (!s) continue;
      const key = s.toLowerCase();
      if (seen.has(key)) continue;
      seen.add(key);
      out.push(s);
    }
    return out.sort((a, b) => a.localeCompare(b, "ru"));
  }

  function ensurePerson(name) {
    const s = String(name || "").trim();
    if (!s) return "";
    const key = s.toLowerCase();
    const has = state.people.some(p => p.toLowerCase() === key);
    if (!has) {
      state.people.push(s);
      state.people = normalizePeopleList(state.people);
      rebuildPeopleControls();
    }
    return s;
  }

  function rebuildPeopleControls() {
    peopleDatalist.innerHTML = "";
    for (const p of state.people) {
      const opt = document.createElement("option");
      opt.value = p;
      peopleDatalist.appendChild(opt);
    }

    const buildSelect = (sel, allowEmpty) => {
      const cur = sel.value || "";
      sel.innerHTML = "";
      if (allowEmpty) {
        const o0 = document.createElement("option");
        o0.value = "";
        o0.textContent = "—";
        sel.appendChild(o0);
      }
      for (const p of state.people) {
        const o = document.createElement("option");
        o.value = p;
        o.textContent = p;
        sel.appendChild(o);
      }
      sel.value = cur;
    };

    buildSelect(suzerainSelect, true);
    buildSelect(seniorSelect, true);

    const curSel = new Set(Array.from(vassalsSelect.selectedOptions || []).map(o => o.value));
    vassalsSelect.innerHTML = "";
    for (const p of state.people) {
      const o = document.createElement("option");
      o.value = p;
      o.textContent = p;
      if (curSel.has(p)) o.selected = true;
      vassalsSelect.appendChild(o);
    }
  }

  function rebuildTerrainSelect() {
    const list = Array.isArray(state.terrain_types) && state.terrain_types.length ? state.terrain_types : TERRAIN_TYPES_FALLBACK;
    const cur = terrainSelect.value || "";
    terrainSelect.innerHTML = "";
    const o0 = document.createElement("option");
    o0.value = "";
    o0.textContent = "—";
    terrainSelect.appendChild(o0);
    for (const t of list) {
      const o = document.createElement("option");
      o.value = t;
      o.textContent = t;
      terrainSelect.appendChild(o);
    }
    terrainSelect.value = cur;
  }

  function getProvData(key) {
    return state.provinces[String(key >>> 0)] || null;
  }

  function setEmblemPreview(pd) {
    const src = pd && pd.emblem_svg ? String(pd.emblem_svg) : "";
    if (src) {
      emblemPreviewImg.src = src;
      emblemPreviewImg.style.display = "block";
      emblemPreviewEmpty.style.display = "none";
    } else {
      emblemPreviewImg.removeAttribute("src");
      emblemPreviewImg.style.display = "none";
      emblemPreviewEmpty.style.display = "block";
    }
  }

  function setSelection(key, meta) {
    selectedKey = key >>> 0;
    const pd = getProvData(selectedKey);

    if (!selectedKey || !pd) {
      selName.textContent = "—";
      selPid.textContent = "—";
      selKey.textContent = "—";
      provNameInput.value = "";
      ownerInput.value = "";
      suzerainSelect.value = "";
      seniorSelect.value = "";
      Array.from(vassalsSelect.options).forEach(o => o.selected = false);
      terrainSelect.value = "";
      setEmblemPreview(null);
      return;
    }

    selName.textContent = pd.name || (meta && meta.name) || "—";
    selPid.textContent = String(pd.pid ?? (meta ? meta.pid : "—"));
    selKey.textContent = String(selectedKey);

    provNameInput.value = pd.name || "";
    ownerInput.value = pd.owner || "";

    if (pd.owner) ensurePerson(pd.owner);
    if (pd.suzerain) ensurePerson(pd.suzerain);
    if (pd.senior) ensurePerson(pd.senior);
    if (Array.isArray(pd.vassals)) for (const v of pd.vassals) ensurePerson(v);

    suzerainSelect.value = pd.suzerain || "";
    seniorSelect.value = pd.senior || "";
    terrainSelect.value = pd.terrain || "";

    const vset = new Set(Array.isArray(pd.vassals) ? pd.vassals : []);
    for (const opt of vassalsSelect.options) opt.selected = vset.has(opt.value);

    if (pd.fill_rgba && Array.isArray(pd.fill_rgba) && pd.fill_rgba.length === 4) {
      const rgba = pd.fill_rgba;
      colorInput.value = MapUtils.rgbToHex(rgba[0], rgba[1], rgba[2]);
      alphaInput.value = String(rgba[3] | 0);
      alphaVal.textContent = String(rgba[3] | 0);
    }

    setEmblemPreview(pd);
  }

  function saveProvinceFieldsFromUI() {
    if (!selectedKey) return;
    const pd = getProvData(selectedKey);
    if (!pd) return;

    const name = String(provNameInput.value || "").trim();
    const owner = ensurePerson(ownerInput.value);
    const suzerain = ensurePerson(suzerainSelect.value);
    const senior = ensurePerson(seniorSelect.value);

    const vassals = Array.from(vassalsSelect.selectedOptions || []).map(o => o.value).filter(Boolean);
    for (const v of vassals) ensurePerson(v);

    const terrain = String(terrainSelect.value || "").trim();

    pd.name = name;
    pd.owner = owner;
    pd.suzerain = suzerain;
    pd.senior = senior;
    pd.vassals = vassals;
    pd.terrain = terrain;

    selName.textContent = name || selName.textContent;
  }

  function applyFillFromUI(map) {
    if (!selectedKey) return;
    const [r, g, b] = MapUtils.hexToRgb(colorInput.value);
    const a = Math.max(0, Math.min(255, parseInt(alphaInput.value, 10) | 0));
    const rgba = [r, g, b, a];
    const pd = getProvData(selectedKey);
    if (!pd) return;
    pd.fill_rgba = rgba;
    map.setFill(selectedKey, rgba);
  }

  function exportStateToTextarea() {
    const out = JSON.parse(JSON.stringify(state));
    out.generated_utc = new Date().toISOString();
    stateTA.value = JSON.stringify(out, null, 2);
  }

  function downloadState() {
    exportStateToTextarea();
    const blob = new Blob([stateTA.value], { type: "application/json;charset=utf-8" });
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "map_state.json";
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  }

  function importStateFromText(txt, map) {
    let obj;
    try { obj = JSON.parse(txt); } catch (e) { alert("Некорректный JSON"); return; }
    if (!obj || typeof obj !== "object" || !obj.provinces) { alert("JSON не похож на map_state.json (нет поля provinces)."); return; }

    obj.people = normalizePeopleList(obj.people || []);
    if (!Array.isArray(obj.terrain_types)) obj.terrain_types = TERRAIN_TYPES_FALLBACK.slice();

    for (const [k, pd] of Object.entries(state.provinces)) {
      if (!obj.provinces[k]) continue;
      const src = obj.provinces[k] || {};
      pd.name = String(src.name || pd.name || "").trim();
      pd.owner = String(src.owner || "").trim();
      pd.suzerain = String(src.suzerain || "").trim();
      pd.senior = String(src.senior || "").trim();
      pd.vassals = Array.isArray(src.vassals) ? src.vassals.map(v => String(v||"").trim()).filter(Boolean) : [];
      pd.terrain = String(src.terrain || "").trim();
      pd.fill_rgba = (Array.isArray(src.fill_rgba) && src.fill_rgba.length === 4) ? src.fill_rgba : null;

      pd.emblem_svg = (typeof src.emblem_svg === "string" && src.emblem_svg.startsWith("data:image/svg+xml")) ? src.emblem_svg : "";
      pd.emblem_box = (Array.isArray(src.emblem_box) && src.emblem_box.length === 2) ? src.emblem_box : null;
    }

    state.people = normalizePeopleList([...state.people, ...obj.people]);
    state.terrain_types = obj.terrain_types;

    rebuildPeopleControls();
    rebuildTerrainSelect();

    map.clearAllFills();
    map.clearAllEmblems();

    for (const [k, pd] of Object.entries(state.provinces)) {
      const key = Number(k) >>> 0;
      if (pd.fill_rgba && Array.isArray(pd.fill_rgba) && pd.fill_rgba.length === 4) map.setFill(key, pd.fill_rgba);
      if (pd.emblem_svg) {
        const box = pd.emblem_box ? { w: +pd.emblem_box[0], h: +pd.emblem_box[1] } : { w: 2000, h: 2400 };
        map.setEmblem(key, pd.emblem_svg, box);
      }
    }

    map.repaintAllEmblems().catch(() => {});
    setSelection(selectedKey, map.getProvinceMeta(selectedKey));
  }

  async function saveStateToServer() {
    exportStateToTextarea();
    try {
      const res = await fetch(SAVE_ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json;charset=utf-8" },
        body: JSON.stringify({ token: SAVE_TOKEN, state: JSON.parse(stateTA.value) })
      });
      const txt = await res.text();
      if (!res.ok) throw new Error(txt || ("HTTP " + res.status));
      alert("Сохранено на сервер: " + txt);
    } catch (e) {
      alert("Не удалось сохранить на сервер: " + e.message);
    }
  }

  async function loadInitialState(url) {
    const res = await fetch(url, { cache: "no-store" });
    if (!res.ok) throw new Error("HTTP " + res.status + " for " + url);
    const obj = await res.json();
    if (!obj || typeof obj !== "object" || !obj.provinces) throw new Error("Invalid state JSON: missing provinces.");

    obj.people = normalizePeopleList(obj.people || []);
    if (!Array.isArray(obj.terrain_types)) obj.terrain_types = TERRAIN_TYPES_FALLBACK.slice();

    for (const pd of Object.values(obj.provinces)) {
      if (!pd) continue;
      if (typeof pd.emblem_svg !== "string") pd.emblem_svg = "";
      if (!Array.isArray(pd.emblem_box) || pd.emblem_box.length !== 2) pd.emblem_box = null;
    }
    return obj;
  }

  function sanitizeSvgText(svgText) {
    let s = String(svgText || "");
    s = s.replace(/<script[\s\S]*?<\/script\s*>/gi, "");
    return s;
  }

  function svgTextToDataUri(svgText) {
    const clean = sanitizeSvgText(svgText);
    const b64 = MapUtils.toBase64Utf8(clean);
    return "data:image/svg+xml;base64," + b64;
  }

  function extractSvgBox(svgText) {
    const box = MapUtils.parseSvgBox(svgText);
    return [box.w, box.h];
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

  function boot(map) {
    btnApplyFill.addEventListener("click", () => applyFillFromUI(map));
    btnClearFill.addEventListener("click", () => {
      if (!selectedKey) return;
      const pd = getProvData(selectedKey);
      if (pd) pd.fill_rgba = null;
      map.clearFill(selectedKey);
    });

    btnSaveProv.addEventListener("click", () => {
      saveProvinceFieldsFromUI();
      exportStateToTextarea();
    });

    btnExport.addEventListener("click", exportStateToTextarea);
    btnDownload.addEventListener("click", downloadState);

    btnImport.addEventListener("click", () => importFile.click());
    importFile.addEventListener("change", async () => {
      const file = importFile.files && importFile.files[0];
      if (!file) return;
      const txt = await file.text();
      importStateFromText(txt, map);
      importFile.value = "";
    });

    btnSaveServer.addEventListener("click", () => saveStateToServer());

    uploadEmblemBtn.addEventListener("click", () => {
      if (!selectedKey) { alert("Сначала выбери провинцию."); return; }
      emblemFile.click();
    });

    emblemFile.addEventListener("change", async () => {
      const file = emblemFile.files && emblemFile.files[0];
      if (!file) return;
      if (!selectedKey) { emblemFile.value = ""; return; }

      try {
        const textRaw = await file.text();
        const text = String(textRaw || "").replace(/^\uFEFF/, "");
        if (!/<svg[\s>]/i.test(text)) {
          alert("Это не похоже на SVG (нет тега <svg>).");
          emblemFile.value = "";
          return;
        }

        const pd = getProvData(selectedKey);
        if (!pd) { emblemFile.value = ""; return; }

        const dataUri = svgTextToDataUri(text);
        const box = extractSvgBox(text);

        pd.emblem_svg = dataUri;
        pd.emblem_box = box;

        setEmblemPreview(pd);

        map.setEmblem(selectedKey, dataUri, { w: box[0], h: box[1] });
        map.repaintAllEmblems().catch(() => {});

        exportStateToTextarea();
      } catch (e) {
        alert("Не удалось загрузить SVG: " + e.message);
      } finally {
        emblemFile.value = "";
      }
    });

    removeEmblemBtn.addEventListener("click", () => {
      if (!selectedKey) return;
      const pd = getProvData(selectedKey);
      if (!pd) return;

      pd.emblem_svg = "";
      pd.emblem_box = null;

      map.clearEmblem(selectedKey);
      setEmblemPreview(pd);
      exportStateToTextarea();
    });

    provNameInput.addEventListener("change", () => saveProvinceFieldsFromUI());
    ownerInput.addEventListener("change", () => { ensurePerson(ownerInput.value); saveProvinceFieldsFromUI(); });
    suzerainSelect.addEventListener("change", () => saveProvinceFieldsFromUI());
    seniorSelect.addEventListener("change", () => saveProvinceFieldsFromUI());
    vassalsSelect.addEventListener("change", () => saveProvinceFieldsFromUI());
    terrainSelect.addEventListener("change", () => saveProvinceFieldsFromUI());
  }

  async function main() {
    state = await loadInitialState(STATE_URL_DEFAULT);

    rebuildPeopleControls();
    rebuildTerrainSelect();

    const map = new RasterProvinceMap({
      baseImgId: "baseMap",
      fillCanvasId: "fill",
      emblemCanvasId: "emblems",
      hoverCanvasId: "hover",
      provincesMetaUrl: "provinces.json",
      maskUrl: "provinces_id.png",
      onHover: ({ key, meta, evt }) => {
        if (!key) { tooltip.style.display = "none"; return; }
        const pd = state.provinces[String(key)] || {};
        const label = (pd.name || (meta && meta.name) || ("Провинция " + (meta ? meta.pid : "")));
        setTooltip(evt, label + " (ID " + (pd.pid || (meta && meta.pid) || "?") + ")");
      },
      onClick: ({ key, meta }) => setSelection(key, meta),
      onReady: () => {
        for (const [k, pd] of Object.entries(state.provinces)) {
          const key = Number(k) >>> 0;

          if (pd.fill_rgba && Array.isArray(pd.fill_rgba) && pd.fill_rgba.length === 4) {
            map.setFill(key, pd.fill_rgba);
          }

          if (pd.emblem_svg) {
            const box = pd.emblem_box ? { w: +pd.emblem_box[0], h: +pd.emblem_box[1] } : { w: 2000, h: 2400 };
            map.setEmblem(key, pd.emblem_svg, box);
          }
        }
        map.repaintAllEmblems().catch(() => {});
      }
    });

    await map.init();
    initZoomControls(map);
    boot(map);

    setSelection(0, null);
    exportStateToTextarea();
  }

  main().catch(err => {
    console.error(err);
    alert("Ошибка запуска админки: " + err.message);
  });
})();
