import http from "node:http";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { EconomyEngine } from "./engine.js";
import { BUILDINGS } from "./resources.js";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.resolve(__dirname, "../..");
const adminMapStatePath = path.join(projectRoot, "data", "map_state.json");
const adminMapProvincesPath = path.join(projectRoot, "provinces.json");
const simAdminDataDir = path.join(__dirname, "data");
const simAdminOverridesPath = path.join(simAdminDataDir, "sim_admin_overrides.json");
const mapImagePath = path.join(projectRoot, "map.png");
const provincesMaskPath = path.join(projectRoot, "provinces_id.png");

function parseArgs(argv) {
  const out = { _: [] };
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (a.startsWith("--")) {
      const k = a.slice(2);
      const v = argv[i + 1] && !argv[i + 1].startsWith("--") ? argv[++i] : "true";
      out[k] = v;
    } else out._.push(a);
  }
  return out;
}

function loadProvinces(filePath) {
  const raw = fs.readFileSync(filePath, "utf-8");
  const data = JSON.parse(raw);

  const provincesObj = data.provinces || {};
  const provinces = Object.values(provincesObj).map((p) => ({
    pid: Number(p.pid),
    name: p.name || String(p.pid),
    terrain: p.terrain || "",
    centroid: Array.isArray(p.centroid) ? p.centroid : [0, 0],
    neighbors: Array.isArray(p.neighbors) ? p.neighbors : [],
    hex_count: Number(p.hex_count || 100),
    area_px: Number(p.area_px || 1000),
    free_city_id: p.free_city_id || "",
  }));

  for (const p of provinces) {
    p.neighbors = (p.neighbors || []).map((n) => ({
      pid: Number(n.pid),
      shared_sides: Number(n.shared_sides || 1),
    }));
  }

  return { provinces, rawData: data };
}

function loadJsonSafe(filePath, fallbackValue) {
  try {
    if (!fs.existsSync(filePath)) return fallbackValue;
    return JSON.parse(fs.readFileSync(filePath, "utf-8"));
  } catch {
    return fallbackValue;
  }
}

function saveJsonAtomic(filePath, data) {
  const dir = path.dirname(filePath);
  fs.mkdirSync(dir, { recursive: true });
  const tmp = `${filePath}.tmp`;
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2), "utf-8");
  fs.renameSync(tmp, filePath);
}

function normalizeProvinceOverride(raw) {
  const out = {};
  if (raw == null || typeof raw !== "object") return out;

  if (Number.isFinite(Number(raw.pop))) out.pop = Math.max(1, Math.round(Number(raw.pop)));
  if (Number.isFinite(Number(raw.infra))) out.infra = Math.max(0.1, Math.min(2.5, Number(raw.infra)));
  if (Number.isFinite(Number(raw.gdpWeight))) out.gdpWeight = Math.max(0.05, Math.min(8, Number(raw.gdpWeight)));
  if (Array.isArray(raw.buildings)) {
    out.buildings = raw.buildings
      .map((b) => {
        const type = typeof b?.type === "string" ? b.type.trim() : "";
        const count = Math.floor(Number(b?.count));
        const efficiency = Number(b?.efficiency);
        if (!type || !BUILDINGS[type] || !Number.isFinite(count) || count < 0) return null;
        const outEntry = { type, count };
        if (Number.isFinite(efficiency)) {
          outEntry.efficiency = Math.max(0.25, Math.min(2.5, efficiency));
        }
        return outEntry;
      })
      .filter((b) => b && b.count > 0);
  }
  return out;
}


function readPngSize(filePath) {
  try {
    const fd = fs.openSync(filePath, "r");
    const buf = Buffer.alloc(24);
    fs.readSync(fd, buf, 0, 24, 0);
    fs.closeSync(fd);
    const sig = buf.subarray(0, 8).toString("hex");
    if (sig !== "89504e470d0a1a0a") return null;
    const ihdr = buf.subarray(12, 16).toString("ascii");
    if (ihdr !== "IHDR") return null;
    const w = buf.readUInt32BE(16);
    const h = buf.readUInt32BE(20);
    return { width: w, height: h };
  } catch {
    return null;
  }
}

function contentTypeByExt(ext) {
  switch (ext) {
    case ".html":
      return "text/html; charset=utf-8";
    case ".js":
      return "text/javascript; charset=utf-8";
    case ".css":
      return "text/css; charset=utf-8";
    case ".json":
      return "application/json; charset=utf-8";
    case ".svg":
      return "image/svg+xml";
    case ".png":
      return "image/png";
    case ".jpg":
    case ".jpeg":
      return "image/jpeg";
    default:
      return "application/octet-stream";
  }
}

function sendJson(res, statusCode, obj) {
  const body = JSON.stringify(obj);
  res.writeHead(statusCode, {
    "Content-Type": "application/json; charset=utf-8",
    "Cache-Control": "no-store",
    "Access-Control-Allow-Origin": "*",
  });
  res.end(body);
}

function sendText(res, statusCode, text, contentType = "text/plain; charset=utf-8") {
  res.writeHead(statusCode, {
    "Content-Type": contentType,
    "Cache-Control": "no-store",
  });
  res.end(text);
}

const args = parseArgs(process.argv);
const dataFile = args._[0] || path.resolve(__dirname, "../province_routing_data.json");
const port = Number.parseInt(args.port || "8787", 10);

let baseConfig = {
  seed: Number.parseInt(args.seed || "1", 10),
  transportUnitCost: Number.parseFloat(args.transportUnitCost || args.transport || "0.35"),
  tradeFriction: Number.parseFloat(args.tradeFriction || args.friction || "0.05"),
  smoothSteps: Number.parseInt(args.smoothSteps || args.smooth || "8", 10),
};

let provinces = [];
try {
  provinces = loadProvinces(dataFile).provinces;
} catch (e) {
  console.error("[server] failed to load province data:", e?.message || e);
  console.error("[server] dataFile:", dataFile);
  process.exit(1);
}

const adminMapState = loadJsonSafe(adminMapStatePath, { provinces: {} });
const adminMapProvinceMeta = loadJsonSafe(adminMapProvincesPath, { provinces: [] });
const adminStateByPid = new Map(
  Object.values(adminMapState.provinces || {}).map((p) => [Number(p.pid), p])
);

for (const p of provinces) {
  const a = adminStateByPid.get(p.pid);
  if (!a) continue;
  if (typeof a.name === "string" && a.name.trim()) p.name = a.name.trim();
  if (typeof a.terrain === "string") p.terrain = a.terrain;
  if (typeof a.free_city_id === "string") p.free_city_id = a.free_city_id;
}

let simAdminOverrides = loadJsonSafe(simAdminOverridesPath, { schema_version: 1, provinces: {} });
if (!simAdminOverrides || typeof simAdminOverrides !== "object") {
  simAdminOverrides = { schema_version: 1, provinces: {} };
}
if (!simAdminOverrides.provinces || typeof simAdminOverrides.provinces !== "object") {
  simAdminOverrides.provinces = {};
}

let engine = null;

function makeEngine(cfg) {
  const e = new EconomyEngine({
    provinces,
    seed: cfg.seed,
    transportUnitCost: cfg.transportUnitCost,
    tradeFriction: cfg.tradeFriction,
    smoothSteps: cfg.smoothSteps,
  });
  e.precomputeDistances();
  return e;
}

function applySimAdminOverridesToEngine() {
  for (const [pidRaw, ov] of Object.entries(simAdminOverrides.provinces || {})) {
    const pid = Number(pidRaw);
    const idx = engine.pidToIndex.get(pid);
    if (typeof idx !== "number") continue;

    const st = engine.states[idx];
    const n = normalizeProvinceOverride(ov);
    if (typeof n.pop === "number") st.pop = n.pop;
    if (typeof n.infra === "number") st.infra = n.infra;
    if (Array.isArray(n.buildings)) {
      st.buildings = n.buildings.map((b) => ({
        type: b.type,
        count: b.count,
        efficiency: Number.isFinite(Number(b.efficiency)) ? Math.max(0.25, Math.min(2.5, Number(b.efficiency))) : 1,
      }));
    }
    const infraForCap = Math.max(0.1, st.infra);
    st.transportCap = st.pop * infraForCap * 0.018;
    st.worldTransportCap = st.transportCap * 3 + (st.isCity ? 900 : 350);
  }

  if (typeof engine._updateTargets === "function") {
    engine._updateTargets();
  }
}

function computeProvinceAdminStats(pid) {
  const idx = engine.pidToIndex.get(Number(pid));
  if (typeof idx !== "number") return null;
  const st = engine.states[idx];
  const ov = normalizeProvinceOverride((simAdminOverrides.provinces || {})[String(pid)] || {});
  const gdpWeight = typeof ov.gdpWeight === "number" ? ov.gdpWeight : 1;
  return {
    pop: st.pop,
    infra: st.infra,
    gdpWeight,
    effectiveGDP: st.gdpYear * gdpWeight,
    buildings: (st.buildings || []).map((b) => ({
      type: b.type,
      count: Number(b.count || 0),
      efficiency: Number.isFinite(Number(b.efficiency)) ? Number(b.efficiency) : 1,
      name: BUILDINGS[b.type]?.name || b.type,
    })),
  };
}

engine = makeEngine(baseConfig);
applySimAdminOverridesToEngine();


function getProvinceIndexByPid(pid) {
  const i = engine.pidToIndex.get(Number(pid));
  return typeof i === "number" ? i : -1;
}

function allowCors(req, res) {
  if (req.method === "OPTIONS") {
    res.writeHead(204, {
      "Access-Control-Allow-Origin": "*",
      "Access-Control-Allow-Methods": "GET,POST,OPTIONS",
      "Access-Control-Allow-Headers": "Content-Type",
      "Access-Control-Max-Age": "86400",
    });
    res.end();
    return true;
  }
  return false;
}

const server = http.createServer((req, res) => {
  try {
    if (allowCors(req, res)) return;

    const url = new URL(req.url || "/", `http://${req.headers.host || "localhost"}`);
    const pathname = url.pathname;

    // API
    if (pathname.startsWith("/api/")) {
      if (pathname === "/api/admin/map-sync") {
        const provincesMeta = Array.isArray(adminMapProvinceMeta.provinces)
          ? adminMapProvinceMeta.provinces
          : [];

        const rows = provincesMeta.map((meta) => {
          const pid = Number(meta.pid);
          const idx = engine.pidToIndex.get(pid);
          const p = typeof idx === "number" ? provinces[idx] : null;
          const st = typeof idx === "number" ? engine.states[idx] : null;
          const stats = computeProvinceAdminStats(pid);
          return {
            pid,
            key: Number(meta.key),
            name: p?.name || meta.name || `PID ${pid}`,
            terrain: p?.terrain || "",
            centroid: Array.isArray(meta.centroid) ? meta.centroid : [0, 0],
            bbox: Array.isArray(meta.bbox) ? meta.bbox : null,
            area_px: Number(meta.area_px || 0),
            population: stats?.pop ?? st?.pop ?? 0,
            infra: stats?.infra ?? st?.infra ?? 0,
            gdpTurnover: st?.gdpYear ?? 0,
            gdpWeight: stats?.gdpWeight ?? 1,
            effectiveGDP: stats?.effectiveGDP ?? (st?.gdpYear ?? 0),
          };
        });

        return sendJson(res, 200, {
          map: {
            image: "/admin-assets/map.png",
            mask: "/admin-assets/provinces_id.png",
            width: Number((readPngSize(mapImagePath)?.width) || adminMapProvinceMeta.image?.width || 0),
            height: Number((readPngSize(mapImagePath)?.height) || adminMapProvinceMeta.image?.height || 0),
          },
          provinces: rows,
          buildingCatalog: Object.entries(BUILDINGS).map(([type, def]) => ({
            type,
            name: def?.name || type,
          })),
        });
      }

      if (pathname === "/api/admin/province" && req.method === "POST") {
        let body = "";
        req.on("data", (chunk) => {
          body += chunk;
          if (body.length > 1_000_000) req.destroy();
        });
        req.on("end", () => {
          try {
            const payload = JSON.parse(body || "{}");
            const pid = Number(payload.pid);
            if (!Number.isFinite(pid) || !engine.pidToIndex.has(pid)) {
              return sendJson(res, 400, { error: "invalid_pid" });
            }

            const next = normalizeProvinceOverride(payload);
            simAdminOverrides.provinces[String(pid)] = {
              ...(simAdminOverrides.provinces[String(pid)] || {}),
              ...next,
            };
            simAdminOverrides.updated_utc = new Date().toISOString();

            saveJsonAtomic(simAdminOverridesPath, simAdminOverrides);
            applySimAdminOverridesToEngine();

            return sendJson(res, 200, {
              ok: true,
              pid,
              admin: computeProvinceAdminStats(pid),
            });
          } catch (e) {
            return sendJson(res, 400, { error: "invalid_json", message: String(e?.message || e) });
          }
        });
        return;
      }

      if (pathname === "/api/meta") {
        const snap = engine.exportSnapshot();
        const provMeta = provinces.map((p) => {
          const idx = engine.pidToIndex.get(p.pid);
          const st = engine.states[idx];
          return {
            pid: p.pid,
            name: p.name,
            terrain: p.terrain,
            centroid: p.centroid,
            hex_count: p.hex_count,
            isCity: st.isCity,
            pop: st.pop,
            infra: st.infra,
          };
        });

        return sendJson(res, 200, {
          day: engine.day,
          config: { ...baseConfig },
          provinces: provMeta,
          commodities: snap.commodities.map((c) => ({
            id: c.id,
            name: c.name,
            unit: c.unit,
            tier: c.tier,
            basePrice: c.basePrice,
            bulk: c.bulk,
            decayPerDay: c.decayPerDay,
            rarity: c.rarity,
          })),
        });
      }

      if (pathname === "/api/summary") {
        const r = engine.report();
        return sendJson(res, 200, { ...r, config: { ...baseConfig } });
      }

      if (pathname === "/api/province") {
        const pid = Number(url.searchParams.get("pid") || "0");
        const idx = getProvinceIndexByPid(pid);
        if (idx < 0) return sendJson(res, 404, { error: "province_not_found", pid });

        const st = engine.states[idx];
        const p = provinces[idx];
        const snap = engine.exportSnapshot();
        const commodities = snap.commodities;

        const tier = (url.searchParams.get("tier") || "all").toLowerCase();
        const sort = (url.searchParams.get("sort") || "value").toLowerCase();
        const limit = Math.max(10, Math.min(300, Number.parseInt(url.searchParams.get("limit") || "80", 10)));

        const active = (url.searchParams.get("active") || "0").toLowerCase();

        let rows = commodities.map((c, cidx) => {
          const stock = st.stock[cidx];
          const price = st.price[cidx];
          const tgt = st.target ? st.target[cidx] : 0;
          const ratio = tgt > 0 ? stock / tgt : 1;
          const value = stock * price;
          return {
            cidx,
            id: c.id,
            name: c.name,
            unit: c.unit,
            tier: c.tier,
            stock: +stock,
            price: +price,
            basePrice: c.basePrice,
            target: +tgt,
            ratio: +ratio,
            value: +value,
          };
        });

        if (active === "1") rows = rows.filter((r) => (r.stock > 1e-9) || (r.target > 0));

        if (tier !== "all") rows = rows.filter((r) => r.tier === tier);

        if (sort === "ratio") rows.sort((a, b) => a.ratio - b.ratio);
        else if (sort === "stock") rows.sort((a, b) => b.stock - a.stock);
        else if (sort === "price") rows.sort((a, b) => b.price - a.price);
        else rows.sort((a, b) => b.value - a.value);

        rows = rows.slice(0, limit);

        return sendJson(res, 200, {
          day: engine.day,
          pid: st.pid,
          name: p.name,
          terrain: p.terrain,
          centroid: p.centroid,
          isCity: st.isCity,
          pop: st.pop,
          infra: st.infra,
          transportCap: st.transportCap,
          transportUsed: st.transportUsed,
          gdpTurnover: st.gdpYear,
          treasury: st.treasury,
          treasuryTradeTaxYear: st.treasuryTradeTaxYear,
          treasuryTransitYear: st.treasuryTransitYear,
          treasuryExpenseYear: st.treasuryExpenseYear,
          treasuryNetYear: st.treasuryNetYear,
          buildings: st.buildings,
          commodities: rows,
        });
      }

      if (pathname === "/api/tick") {
        const yearsArg = url.searchParams.get("years");
        const years = yearsArg != null ? Number.parseInt(yearsArg || "1", 10) : null;
        const nRaw = years != null
          ? years * 365
          : Number.parseInt(url.searchParams.get("n") || "1", 10);
        const n = Math.max(1, Math.min(36500, nRaw));
        for (let i = 0; i < n; i++) engine.tick();
        const r = engine.report();
        return sendJson(res, 200, { ...r, ticked: n, day: engine.day, years: n / 365 });
      }

      if (pathname === "/api/reset") {
        const seed = url.searchParams.get("seed");
        const tuc = url.searchParams.get("transportUnitCost") || url.searchParams.get("transport");
        const fr = url.searchParams.get("tradeFriction") || url.searchParams.get("friction");
        const sm = url.searchParams.get("smoothSteps") || url.searchParams.get("smooth");

        baseConfig = {
          ...baseConfig,
          seed: seed != null ? Number.parseInt(seed, 10) : baseConfig.seed,
          transportUnitCost: tuc != null ? Number.parseFloat(tuc) : baseConfig.transportUnitCost,
          tradeFriction: fr != null ? Number.parseFloat(fr) : baseConfig.tradeFriction,
          smoothSteps: sm != null ? Number.parseInt(sm, 10) : baseConfig.smoothSteps,
        };

        engine = makeEngine(baseConfig);
        applySimAdminOverridesToEngine();
        const r = engine.report();
        return sendJson(res, 200, { ok: true, ...r, config: { ...baseConfig } });
      }

      if (pathname === "/api/snapshot") {
        return sendJson(res, 200, engine.exportSnapshot());
      }

      if (pathname === "/api/trade-balance") {
        return sendJson(res, 200, engine.globalTradeBalance());
      }

      return sendJson(res, 404, { error: "unknown_api", path: pathname });
    }

    // Static
    let filePath;
    if (pathname === "/" || pathname === "/index.html") {
      filePath = path.join(__dirname, "public", "index.html");
    } else if (pathname === "/sim-admin" || pathname === "/sim-admin.html") {
      filePath = path.join(__dirname, "public", "sim-admin.html");
    } else if (pathname === "/admin-assets/map.png") {
      filePath = mapImagePath;
    } else if (pathname === "/admin-assets/provinces_id.png") {
      filePath = provincesMaskPath;
    } else {
      const safe = pathname.replace(/\\/g, "/").replace(/\.+/g, ".");
      filePath = path.join(__dirname, "public", safe);
    }

    // запрет выхода из public
    const publicDir = path.join(__dirname, "public");
    const rootDir = path.resolve(projectRoot);
    const resolved = path.resolve(filePath);
    const inPublic = resolved.startsWith(path.resolve(publicDir));
    const inRootAssets = (pathname === "/admin-assets/map.png" || pathname === "/admin-assets/provinces_id.png") && resolved.startsWith(rootDir);
    if (!inPublic && !inRootAssets) {
      return sendText(res, 403, "Forbidden");
    }

    if (!fs.existsSync(resolved) || fs.statSync(resolved).isDirectory()) {
      return sendText(res, 404, "Not Found");
    }

    const ext = path.extname(resolved).toLowerCase();
    const ct = contentTypeByExt(ext);
    const buf = fs.readFileSync(resolved);
    res.writeHead(200, { "Content-Type": ct, "Cache-Control": "no-store" });
    res.end(buf);
  } catch (e) {
    console.error("[server] error:", e);
    sendJson(res, 500, { error: "server_error", message: String(e?.message || e) });
  }
});

server.listen(port, "0.0.0.0", () => {
  console.log(`[economy-ui] loaded provinces=${provinces.length} from: ${dataFile}`);
  console.log(`[economy-ui] config: seed=${baseConfig.seed} transportUnitCost=${baseConfig.transportUnitCost} tradeFriction=${baseConfig.tradeFriction} smoothSteps=${baseConfig.smoothSteps}`);
  console.log(`[economy-ui] open: http://localhost:${port}`);
});
