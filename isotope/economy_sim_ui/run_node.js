import fs from "node:fs";
import path from "node:path";
import { EconomyEngine } from "./engine.js";

/**
 * CLI:
 *   node run_node.js <path_to_province_routing_data.json> --days 60 --seed 42
 *   node run_node.js <path_to_province_routing_data.json> --years 1 --seed 42
 */

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

const args = parseArgs(process.argv);
const file = args._[0] || "../province_routing_data.json";
const years = args.years != null ? parseInt(args.years || "1", 10) : null;
const days = years != null ? years * 365 : parseInt(args.days || "60", 10);
const seed = parseInt(args.seed || "1", 10);

const raw = fs.readFileSync(file, "utf-8");
const data = JSON.parse(raw);

const provincesObj = data.provinces || {};
const provinces = Object.values(provincesObj).map(p => ({
  pid: p.pid,
  name: p.name,
  terrain: p.terrain || "",
  centroid: p.centroid || [0,0],
  neighbors: p.neighbors || [],
  hex_count: p.hex_count || 100,
  area_px: p.area_px || 1000,
  free_city_id: p.free_city_id || "",
}));

// Важный момент: в JSON ключи строковые, но pid — число; соседей приводим к числам.
for (const p of provinces) {
  p.neighbors = (p.neighbors || []).map(n => ({ pid: Number(n.pid), shared_sides: Number(n.shared_sides || 1) }));
}

console.log(`[economy] provinces=${provinces.length}, days=${days}, years=${(days / 365).toFixed(3)}, seed=${seed}`);

const engine = new EconomyEngine({
  provinces,
  seed,
  transportUnitCost: 0.35,
  tradeFriction: 0.05,
  smoothSteps: 8,
});

engine.precomputeDistances();

for (let d = 0; d < days; d++) {
  engine.tick();

  if ((d + 1) % 10 === 0) {
    const r = engine.report();
    console.log(`\nDay ${r.day} | popTotal=${r.popTotal.toLocaleString("ru-RU")}`);
    console.log("Top GDP turnover:");
    for (const x of r.topGDP) {
      console.log(`  #${x.pid} ${x.name} | gdp=${x.gdp} | pop=${x.pop} | infra=${x.infra}`);
    }
    console.log("Scarce (low stock/target):");
    for (const x of r.scarce) console.log(`  ${x.commodity}: ratio=${x.ratio}`);
    console.log("Overstocked (high stock/target):");
    for (const x of r.cheap) console.log(`  ${x.commodity}: ratio=${x.ratio}`);
  }
}

// пример экспорта снапшота
if (args.snapshot === "true") {
  const outPath = path.join(process.cwd(), `snapshot_day${engine.day}.json`);
  fs.writeFileSync(outPath, JSON.stringify(engine.exportSnapshot(), null, 2), "utf-8");
  console.log(`\nSaved snapshot: ${outPath}`);
}
