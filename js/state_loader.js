(function () {
  "use strict";

  const DEFAULT_FLAGS = {
    USE_CHUNKED_API: true,
    USE_EMBLEM_ASSETS: true,
    USE_PARTIAL_SAVE: true,
    USE_SERVER_RENDER: true,
  };

  const REALM_TYPES = ["kingdoms", "great_houses", "minor_houses", "free_cities"];

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

    const partialSave = parseBool(params.get("use_partial_save"));
    if (partialSave !== null) base.USE_PARTIAL_SAVE = partialSave;

    const serverRender = parseBool(params.get("use_server_render"));
    if (serverRender !== null) base.USE_SERVER_RENDER = serverRender;

    return base;
  }

  async function fetchJson(url) {
    const res = await fetch(url, { cache: "no-store" });
    if (!res.ok) throw new Error(`HTTP ${res.status} for ${url}`);
    const raw = await res.text();
    try {
      return JSON.parse(raw);
    } catch (err) {
      const preview = String(raw || "").slice(0, 180).replace(/\s+/g, " ");
      throw new Error(`Invalid JSON from ${url}: ${err && err.message ? err.message : err}. preview=${preview}`);
    }
  }

  async function loadChunkedProvinces() {
    const chunkSize = 150;
    let offset = 0;
    let expectedTotal = null;
    const byPid = {};

    while (true) {
      const page = await fetchJson(`/api/provinces/?offset=${offset}&limit=${chunkSize}&profile=full`);
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

  async function loadRealms() {
    const out = {};
    for (const type of REALM_TYPES) {
      const data = await fetchJson(`/api/realms/?type=${encodeURIComponent(type)}`);
      const bucket = {};
      for (const item of (data.items || [])) {
        if (!item || typeof item !== "object") continue;
        const id = String(item.id || "").trim();
        if (!id) continue;
        const copy = Object.assign({}, item);
        delete copy.id;
        bucket[id] = copy;
      }
      out[type] = bucket;
    }
    return out;
  }

  async function loadEmblemAssetsById(assetIds) {
    const map = new Map();
    for (const id of assetIds) {
      if (!id || map.has(id)) continue;
      const data = await fetchJson(`/api/assets/emblems/show/?id=${encodeURIComponent(id)}`);
      const item = data && data.item ? data.item : null;
      if (!item || !item.id) continue;
      map.set(String(item.id), String(item.svg || ""));
    }
    return map;
  }

  async function loadStateLegacy(stateUrl) {
    return fetchJson(stateUrl);
  }

  async function loadStateChunked() {
    const boot = await fetchJson("/api/map/bootstrap/");
    const realms = await loadRealms();
    const provinces = await loadChunkedProvinces();
    return Object.assign({ provinces }, realms, {
      schema_version: boot.schema_version,
      generated_utc: boot.generated_utc,
      people: Array.isArray(boot.people) ? boot.people : [],
      terrain_types: Array.isArray(boot.terrain_types) ? boot.terrain_types : [],
    });
  }

  async function loadState(stateUrl) {
    const flags = getFlags();
    let state;
    if (flags.USE_CHUNKED_API) {
      state = await loadStateChunked();
    } else {
      try {
        state = await loadStateLegacy(stateUrl);
      } catch (legacyErr) {
        console.warn("[state-loader] legacy map_state failed, fallback to chunked api", legacyErr);
        state = await loadStateChunked();
        flags.USE_CHUNKED_API = true;
      }
    }

    if (flags.USE_EMBLEM_ASSETS) {
      try {
        const ids = new Set();
        for (const pd of Object.values(state.provinces || {})) {
          if (!pd || typeof pd !== "object") continue;
          if (pd.emblem_svg) continue;
          const aid = String(pd.emblem_asset_id || "").trim();
          if (aid) ids.add(aid);
        }
        for (const type of REALM_TYPES) {
          for (const realm of Object.values(state[type] || {})) {
            if (!realm || typeof realm !== "object") continue;
            if (realm.emblem_svg) continue;
            const aid = String(realm.emblem_asset_id || "").trim();
            if (aid) ids.add(aid);
          }
        }
        const assets = await loadEmblemAssetsById(Array.from(ids));
        for (const pd of Object.values(state.provinces || {})) {
          if (!pd || typeof pd !== "object") continue;
          if (pd.emblem_svg) continue;
          const aid = String(pd.emblem_asset_id || "").trim();
          if (aid && assets.has(aid)) pd.emblem_svg = assets.get(aid);
        }
        for (const type of REALM_TYPES) {
          for (const realm of Object.values(state[type] || {})) {
            if (!realm || typeof realm !== "object") continue;
            if (realm.emblem_svg) continue;
            const aid = String(realm.emblem_asset_id || "").trim();
            if (aid && assets.has(aid)) realm.emblem_svg = assets.get(aid);
          }
        }
      } catch (err) {
        console.warn("[state-loader] emblem assets failed, fallback to legacy emblem_svg", err);
      }
    }

    return { state, flags };
  }

  window.AdminMapStateLoader = {
    getFlags,
    loadState,
  };
})();
