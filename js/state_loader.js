(function () {
  "use strict";

  const DEFAULT_FLAGS = {
    USE_CHUNKED_API: false,
    USE_EMBLEM_ASSETS: false,
  };

  function parseBool(v) {
    if (v === true || v === "1" || v === "true") return true;
    if (v === false || v === "0" || v === "false") return false;
    return null;
  }

  function getFlags() {
    const base = Object.assign({}, DEFAULT_FLAGS, window.ADMINMAP_FLAGS || {});
    const params = new URLSearchParams(window.location.search || "");

    const chunked = parseBool(params.get("use_chunked_api"));
    if (chunked !== null) base.USE_CHUNKED_API = chunked;

    const emblems = parseBool(params.get("use_emblem_assets"));
    if (emblems !== null) base.USE_EMBLEM_ASSETS = emblems;

    return base;
  }

  async function fetchJson(url) {
    const res = await fetch(url, { cache: "no-store" });
    if (!res.ok) throw new Error(`HTTP ${res.status} for ${url}`);
    return res.json();
  }

  async function loadChunkedProvinces() {
    const version = await fetchJson("api/map/version.php");
    const total = Math.max(0, Number(version && version.state_size_bytes ? version.state_size_bytes : 0));
    void total; // version endpoint smoke usage for migration phase.

    const chunkSize = 150;
    let offset = 0;
    let expectedTotal = null;
    const byPid = {};

    while (true) {
      const page = await fetchJson(`api/provinces/index.php?offset=${offset}&limit=${chunkSize}`);
      if (expectedTotal == null) expectedTotal = Number(page.total || 0);
      const items = Array.isArray(page.items) ? page.items : [];
      for (const pd of items) {
        if (!pd || typeof pd !== "object") continue;
        const pid = Number(pd.pid) | 0;
        if (pid <= 0) continue;
        byPid[String(pid)] = pd;
      }
      offset += items.length;
      if (!items.length || offset >= expectedTotal) break;
    }

    return byPid;
  }

  async function loadEmblemAssets() {
    const data = await fetchJson("api/assets/emblems.php?offset=0&limit=2000");
    const map = new Map();
    for (const item of (data.items || [])) {
      if (!item || !item.id) continue;
      map.set(String(item.id), String(item.svg || ""));
    }
    return map;
  }

  async function loadState(stateUrl) {
    const flags = getFlags();
    const legacy = await fetchJson(stateUrl);

    if (flags.USE_CHUNKED_API) {
      try {
        legacy.provinces = await loadChunkedProvinces();
      } catch (err) {
        console.warn("[state-loader] chunked api failed, fallback to legacy state", err);
      }
    }

    if (flags.USE_EMBLEM_ASSETS) {
      try {
        const assets = await loadEmblemAssets();
        for (const pd of Object.values(legacy.provinces || {})) {
          if (!pd || typeof pd !== "object") continue;
          if (pd.emblem_svg) continue;
          const aid = String(pd.emblem_asset_id || "").trim();
          if (aid && assets.has(aid)) pd.emblem_svg = assets.get(aid);
        }
      } catch (err) {
        console.warn("[state-loader] emblem assets failed, fallback to legacy emblem_svg", err);
      }
    }

    return { state: legacy, flags };
  }

  window.AdminMapStateLoader = {
    getFlags,
    loadState,
  };
})();
