import { COMMODITIES, COM_INDEX, HARVEST_RAW_IDX, BUILDINGS } from "./resources.js";
import { XorShift32, dijkstra, clamp } from "./utils.js";

/**
 * @typedef {{pid:number,name:string,terrain:string,centroid:[number,number],neighbors:Array<{pid:number,shared_sides:number}>,hex_count:number,area_px:number,free_city_id:string}} ProvinceInfo
 */

/**
 * @typedef {{
 *   pid:number,
 *   idx:number,
 *   pop:number,
 *   infra:number,
 *   isCity:boolean,
 *   stock:Float32Array,
 *   price:Float32Array,
 *   target:Float32Array,
 *   reserve:Float32Array,
 *   buildings:Array<{type:string, count:number, efficiency:number}>,
 *   rawPotential:Float32Array,
 *   transportCap:number,
 *   transportUsed:number,
 *   worldTransportCap:number,
 *   worldTransportUsed:number,
 *   gdpTurnover:number,
 *   importsValue:number,
 *   exportsValue:number
 * }} ProvinceState
 */

const ESSENTIAL = new Set([
  "bread",
  "mutabryukva",
  "meat",
  "meat_cans",
  "distilled_water",
  "villadium_filter_personal",
]);

const COVER_BY_TIER = {
  raw: 10,
  component: 10,
  product: 7,
  animal: 6,
};

const EXPORT_BUFFER_MUL = 1.6; // сколько сверх target можно держать (и продавать)
const RESERVE_MUL = 0.55; // сколько от target держим как "непродаваемое" (минимум безопасности)

export class EconomyEngine {
  /**
   * @param {{
   *   provinces: ProvinceInfo[],
   *   seed?: number,
   *   transportUnitCost?: number,
   *   tradeFriction?: number,
   *   smoothSteps?: number,
 *   worldImportMarkup?: number,
 *   worldExportMarkdown?: number,
 *   worldPortFee?: number,
 *   hubCount?: number,
 *   dealTaxRate?: number,
 *   importTaxRate?: number,
 *   exportTaxRate?: number,
 *   transitFeePerBulkDist?: number,
 *   baseExpensePerPop?: number,
 *   infraExpenseMul?: number,
 *   buildingExpenseMul?: number,
 *   expenseVolatility?: number
 * }} cfg
   */
  constructor(cfg) {
    this.seed = cfg.seed ?? 1;
    this.rng = new XorShift32(this.seed);

    this.provinces = cfg.provinces;
    this.n = this.provinces.length;

    this.transportUnitCost = cfg.transportUnitCost ?? 0.35;
    this.tradeFriction = cfg.tradeFriction ?? 0.07;
    this.smoothSteps = cfg.smoothSteps ?? 8;

    // внешний рынок (импорт/экспорт) — страховка от "вечных нулей" и вечных переполнений
    this.world = {
      importMarkup: cfg.worldImportMarkup ?? 0.18,
      exportMarkdown: cfg.worldExportMarkdown ?? 0.12,
      portFee: cfg.worldPortFee ?? 0.45,
      hubCount: cfg.hubCount ?? 6,
    };

    this.fiscal = {
      dealTaxRate: clamp(cfg.dealTaxRate ?? 0.012, 0.0, 0.08),
      importTaxRate: clamp(cfg.importTaxRate ?? 0.016, 0.0, 0.12),
      exportTaxRate: clamp(cfg.exportTaxRate ?? 0.012, 0.0, 0.12),
      transitFeePerBulkDist: clamp(cfg.transitFeePerBulkDist ?? 0.022, 0.0, 0.5),
      baseExpensePerPop: clamp(cfg.baseExpensePerPop ?? 0.0013, 0.0, 0.2),
      infraExpenseMul: clamp(cfg.infraExpenseMul ?? 0.42, 0.0, 4.0),
      buildingExpenseMul: clamp(cfg.buildingExpenseMul ?? 0.0024, 0.0, 0.1),
      expenseVolatility: clamp(cfg.expenseVolatility ?? 0.18, 0.0, 1.0),
    };

    this.comCount = COMMODITIES.length;
    this.pidToIndex = new Map(this.provinces.map((p, i) => [p.pid, i]));

    this.edges = this._buildGraphEdges();
    this.distMatrix = null;
    this.routeCache = new Map();

    /** @type {number[]} */
    this.hubIndices = [];
    /** @type {Float32Array} */
    this.gateDist = new Float32Array(this.n);

    /** @type {Float32Array} средний потенциал по сырью — нужен для себестоимости */
    this.rawMean = new Float32Array(this.comCount);

    this.states = this._initProvinceStates();

    this.precomputeDistances();
    this._selectTradeHubs();
    this._computeGateDistances();
    this._ensureGlobalCoverage();
    this._updateTargets();
    this._marketMakerRestock();

    this.day = 0;

    this.yearWindow = 365;
    this.yearProduced = new Float64Array(this.comCount);
    this.yearSold = new Float64Array(this.comCount);
    this.dayProducedHistory = [];
    this.daySoldHistory = [];
    this._dayProduced = new Float64Array(this.comCount);
    this._daySold = new Float64Array(this.comCount);
    this.provYearHistory = {
      gdp: [],
      imports: [],
      exports: [],
      tradeTax: [],
      transitTax: [],
      expense: [],
      net: [],
    };
  }

  _recordProduced(cidx, qty) {
    if (!Number.isFinite(qty) || qty <= 0) return;
    this._dayProduced[cidx] += qty;
  }

  _recordSold(cidx, qty) {
    if (!Number.isFinite(qty) || qty <= 0) return;
    this._daySold[cidx] += qty;
  }

  _rollYearStats() {
    const prod = Float64Array.from(this._dayProduced);
    const sold = Float64Array.from(this._daySold);
    this.dayProducedHistory.push(prod);
    this.daySoldHistory.push(sold);

    for (let i = 0; i < this.comCount; i++) {
      this.yearProduced[i] += prod[i];
      this.yearSold[i] += sold[i];
    }

    if (this.dayProducedHistory.length > this.yearWindow) {
      const oldProd = this.dayProducedHistory.shift();
      const oldSold = this.daySoldHistory.shift();
      for (let i = 0; i < this.comCount; i++) {
        this.yearProduced[i] -= oldProd[i];
        this.yearSold[i] -= oldSold[i];
      }
    }
  }

  _rollProvinceYearStats() {
    const n = this.n;
    const day = {
      gdp: new Float64Array(n),
      imports: new Float64Array(n),
      exports: new Float64Array(n),
      tradeTax: new Float64Array(n),
      transitTax: new Float64Array(n),
      expense: new Float64Array(n),
      net: new Float64Array(n),
    };

    for (let i = 0; i < n; i++) {
      const st = this.states[i];
      day.gdp[i] = st.gdpTurnover;
      day.imports[i] = st.importsValue;
      day.exports[i] = st.exportsValue;
      day.tradeTax[i] = st.treasuryTradeTaxDaily;
      day.transitTax[i] = st.treasuryTransitDaily;
      day.expense[i] = st.treasuryExpenseDaily;
      day.net[i] = st.treasuryNetDaily;

      st.gdpYear += day.gdp[i];
      st.importsYear += day.imports[i];
      st.exportsYear += day.exports[i];
      st.treasuryTradeTaxYear += day.tradeTax[i];
      st.treasuryTransitYear += day.transitTax[i];
      st.treasuryExpenseYear += day.expense[i];
      st.treasuryNetYear += day.net[i];
    }

    for (const k of Object.keys(day)) this.provYearHistory[k].push(day[k]);

    if (this.provYearHistory.gdp.length > this.yearWindow) {
      const old = {};
      for (const k of Object.keys(this.provYearHistory)) old[k] = this.provYearHistory[k].shift();
      for (let i = 0; i < n; i++) {
        const st = this.states[i];
        st.gdpYear -= old.gdp[i];
        st.importsYear -= old.imports[i];
        st.exportsYear -= old.exports[i];
        st.treasuryTradeTaxYear -= old.tradeTax[i];
        st.treasuryTransitYear -= old.transitTax[i];
        st.treasuryExpenseYear -= old.expense[i];
        st.treasuryNetYear -= old.net[i];
      }
    }
  }

  /** @returns {Array<Array<{to:number,w:number}>>} */
  _buildGraphEdges() {
    const n = this.n;
    const edges = Array.from({ length: n }, () => []);

    const pidToIndex = this.pidToIndex;

    const existing = [];
    for (let i = 0; i < n; i++) {
      const p = this.provinces[i];
      for (const nb of (p.neighbors || [])) {
        const j = pidToIndex.get(nb.pid);
        if (j == null || j === i) continue;
        const [x1, y1] = p.centroid;
        const [x2, y2] = this.provinces[j].centroid;
        const d = Math.hypot(x2 - x1, y2 - y1);
        if (isFinite(d) && d > 0) existing.push(d);
      }
    }
    const avgEdge = existing.length ? existing.reduce((a, b) => a + b, 0) / existing.length : 100;

    // явные соседи
    for (let i = 0; i < n; i++) {
      const p = this.provinces[i];
      for (const nb of (p.neighbors || [])) {
        const j = pidToIndex.get(nb.pid);
        if (j == null || j === i) continue;

        const [x1, y1] = p.centroid;
        const [x2, y2] = this.provinces[j].centroid;
        const dist = Math.hypot(x2 - x1, y2 - y1) / avgEdge;

        const shared = Math.max(1, nb.shared_sides || 1);
        const borderEase = 1 / Math.sqrt(shared);
        const w = dist * (0.85 + 0.9 * borderEase);

        edges[i].push({ to: j, w });
      }
    }

    // эвристика для изолированных
    const kMin = 2;
    const kAdd = 3;
    const coords = this.provinces.map((p) => p.centroid);

    for (let i = 0; i < n; i++) {
      if (edges[i].length >= kMin) continue;
      const [x1, y1] = coords[i];
      const dists = [];
      for (let j = 0; j < n; j++) {
        if (i === j) continue;
        const [x2, y2] = coords[j];
        const d = Math.hypot(x2 - x1, y2 - y1);
        dists.push([d, j]);
      }
      dists.sort((a, b) => a[0] - b[0]);
      const want = Math.min(kAdd, dists.length);
      for (let t = 0; t < want; t++) {
        const j = dists[t][1];
        const w = (dists[t][0] / avgEdge) * 1.15;
        edges[i].push({ to: j, w });
      }
    }

    // симметризация
    const has = Array.from({ length: n }, () => new Set());
    for (let i = 0; i < n; i++) for (const e of edges[i]) has[i].add(e.to);
    for (let i = 0; i < n; i++) {
      for (const e of edges[i]) {
        if (!has[e.to].has(i)) {
          edges[e.to].push({ to: i, w: e.w });
          has[e.to].add(i);
        }
      }
    }

    return edges;
  }

  /** @returns {ProvinceState[]} */
  _initProvinceStates() {
    const n = this.n;
    const states = [];

    for (let i = 0; i < n; i++) {
      const p = this.provinces[i];

      const isCity = (p.terrain || "").toLowerCase().includes("город")
        || (!!p.free_city_id && p.free_city_id.trim().length > 0)
        || /вольный\s+город|город/i.test(p.name || "");

      const area = Math.max(1, (p.hex_count || 100));
      const baseDensity = isCity ? 12.0 : 3.2;

      const terrain = (p.terrain || "").toLowerCase();
      let terrainMul = 1.0;
      if (terrain.includes("пустош")) terrainMul = 0.6;
      if (terrain.includes("болот")) terrainMul = 0.7;
      if (terrain.includes("лес")) terrainMul = 0.85;
      if (terrain.includes("равнин")) terrainMul = 1.15;
      if (terrain.includes("холм")) terrainMul = 0.95;
      if (terrain.includes("остров")) terrainMul = 0.9;

      const noise = clamp(1 + this.rng.nextNorm() * 0.18, 0.6, 1.6);
      const pop = Math.round(area * baseDensity * terrainMul * noise * 100);

      const infraBase = isCity ? 0.78 : 0.44;
      const infra = clamp(infraBase + this.rng.nextNorm() * 0.12, 0.2, 1.0);

      const stock = new Float32Array(this.comCount);
      const price = new Float32Array(this.comCount);
      const target = new Float32Array(this.comCount);
      const reserve = new Float32Array(this.comCount);

      for (let c = 0; c < this.comCount; c++) {
        price[c] = COMMODITIES[c].basePrice * clamp(1 + this.rng.nextNorm() * 0.18, 0.55, 1.8);
        stock[c] = 0;
        target[c] = 0;
        reserve[c] = 0;
      }

      const rawPotential = new Float32Array(this.comCount);
      rawPotential.fill(0);

      const transportCap = pop * infra * 0.018; // пропускная способность в bulk*qty

      states.push({
        pid: p.pid,
        idx: i,
        pop,
        infra,
        isCity,
        stock,
        price,
        target,
        reserve,
        buildings: [],
        rawPotential,
        transportCap,
        transportUsed: 0,
        worldTransportCap: transportCap * 3 + (isCity ? 900 : 350),
        worldTransportUsed: 0,
        gdpTurnover: 0,
        importsValue: 0,
        exportsValue: 0,
        treasury: 0,
        treasuryDaily: 0,
        treasuryTradeTaxDaily: 0,
        treasuryTransitDaily: 0,
        treasuryExpenseDaily: 0,
        treasuryNetDaily: 0,
        gdpYear: 0,
        importsYear: 0,
        exportsYear: 0,
        treasuryTradeTaxYear: 0,
        treasuryTransitYear: 0,
        treasuryExpenseYear: 0,
        treasuryNetYear: 0,
      });
    }

    this._generateRawPotentials(states);
    this._seedStartingBuildings(states);
    this._seedStartingStocks(states);

    return states;
  }

  _generateRawPotentials(states) {
    const n = this.n;
    const rawIds = HARVEST_RAW_IDX.map((i) => COMMODITIES[i].id);

    const terrainBias = (terrain, commodityId) => {
      const t = (terrain || "").toLowerCase();
      if (commodityId === "wood_raw") {
        if (t.includes("лес")) return 0.35;
        if (t.includes("болот")) return 0.15;
        return 0.0;
      }
      if (commodityId === "fiber_raw") {
        if (t.includes("равнин")) return 0.20;
        if (t.includes("лес")) return 0.10;
        return 0.0;
      }
      if (commodityId === "iron_ore" || commodityId === "coke") {
        if (t.includes("холм")) return 0.25;
        if (t.includes("пустош")) return 0.10;
        return 0.0;
      }
      if (commodityId === "petrochem_raw" || commodityId === "rubber_raw") {
        if (t.includes("побереж")) return 0.12;
        if (t.includes("пустош")) return 0.05;
        return 0.0;
      }
      if (commodityId === "villadium" || commodityId === "gold" || commodityId === "silver") {
        if (t.includes("пустош")) return 0.25;
        if (t.includes("холм")) return 0.10;
        return 0.0;
      }
      return 0.0;
    };

    const nb = this.edges.map((list) => list.map((e) => e.to));

    for (const rid of rawIds) {
      const cidx = COM_INDEX[rid];

      const field = new Float32Array(n);
      for (let i = 0; i < n; i++) {
        const p = this.provinces[i];
        const b = terrainBias(p.terrain, rid);
        const v = clamp(0.55 + this.rng.nextNorm() * 0.22 + b, 0.05, 1.25);
        field[i] = v;
      }

      for (let step = 0; step < this.smoothSteps; step++) {
        const next = new Float32Array(n);
        for (let i = 0; i < n; i++) {
          const neigh = nb[i];
          let sum = field[i] * 2.2;
          let wsum = 2.2;
          for (const j of neigh) {
            sum += field[j];
            wsum += 1;
          }
          next[i] = sum / wsum;
        }
        field.set(next);
      }

      const rarity = COMMODITIES[cidx].rarity;
      const mean = clamp(40 * (1 - rarity), 0.02, 30);
      const min = mean * 0.12;

      let m = 0;
      for (let i = 0; i < n; i++) m += field[i];
      m /= n;
      const scale = m > 0 ? 1 / m : 1;

      let potSum = 0;
      for (let i = 0; i < n; i++) {
        const f = field[i] * scale;
        const out = min + (mean - min) * clamp(f, 0.0, 2.3);
        states[i].rawPotential[cidx] = out;
        potSum += out;
      }
      this.rawMean[cidx] = potSum / n;
    }
  }

  _seedStartingBuildings(states) {
    const idx = COM_INDEX;

    const add = (st, type, count, eff = 1.0) => {
      const c = Math.max(0, Math.floor(count));
      if (!c) return;
      st.buildings.push({ type, count: c, efficiency: eff });
    };

    // 1) сельское хозяйство: НЕ у всех. Определяем "плодородие" по terrain + шум.
    for (const st of states) {
      const p = this.provinces[st.idx];
      const t = (p.terrain || "").toLowerCase();
      const area = Math.max(10, Number(p.hex_count || 100));

      let fert = 0.55;
      if (t.includes("равнин")) fert = 1.0;
      else if (t.includes("лес")) fert = 0.72;
      else if (t.includes("холм")) fert = 0.55;
      else if (t.includes("болот")) fert = 0.45;
      else if (t.includes("пустош")) fert = 0.22;
      else if (t.includes("остров")) fert = 0.38;

      fert = clamp(fert + this.rng.nextNorm() * 0.12, 0.05, 1.2);

      const farmBase = (area / 140) * fert;
      const farmCount = st.isCity ? Math.round(farmBase * 0.6) : Math.round(farmBase);

      // фермы появляются только если условно "есть где"
      if (farmCount >= 1) {
        add(st, "farm_mutabryukva", clamp(farmCount, 1, st.isCity ? 2 : 6));
        add(st, "poultry_mutachicken", clamp(Math.round(farmCount * 0.55), 1, st.isCity ? 1 : 4));
      }

      // вода — почти везде, но разной мощности
      if (BUILDINGS.water_distillery) {
        const w = st.isCity ? 1 : (this.rng.next() < 0.45 ? 1 : 0);
        add(st, "water_distillery", w);
      }

      // лесопилка только там, где древесина реально есть
      if (st.rawPotential[idx.wood_raw] > this.rawMean[idx.wood_raw] * 1.1) {
        add(st, "sawmill", st.isCity ? 1 : (this.rng.next() < 0.25 ? 1 : 0));
      }

      // текстиль/кожа — где есть волокно/шкуры
      if (st.isCity && st.rawPotential[idx.fiber_raw] > this.rawMean[idx.fiber_raw] * 1.05) add(st, "textile", 1);
      if (st.isCity && st.rawPotential[idx.hides_raw] > this.rawMean[idx.hides_raw] * 1.05) add(st, "tannery", 1);

      // базовая одежда в городах (не везде)
      if (st.isCity && this.rng.next() < 0.55) add(st, "line_clothes_peasant", 1);
    }

    // 2) индустриализация городов: специализация. Не все линии в каждом городе.
    const cityIdx = states.filter((s) => s.isCity).map((s) => s.idx);

    // если городов мало, всё равно распределим минимальный набор отраслей
    const cityStates = cityIdx.map((i) => states[i]);
    cityStates.sort((a, b) => (b.pop * b.infra) - (a.pop * a.infra));

    const chooseSpecializations = (st) => {
      const pot = st.rawPotential;

      const score = {
        agro: 0.3 + (pot[idx.fiber_raw] || 0) * 0.02,
        metal: (pot[idx.iron_ore] + pot[idx.coke]) * 0.04,
        chem: (pot[idx.petrochem_raw] + pot[idx.rubber_raw]) * 0.05,
        elec: (pot[idx.copper] + pot[idx.tin] + pot[idx.lead]) * 0.002,
        rare: (pot[idx.villadium] + pot[idx.gold] + pot[idx.silver]) * 0.10,
      };

      const arr = Object.entries(score).sort((a, b) => b[1] - a[1]);
      const primary = arr[0][0];
      const pick2 = arr.slice(0, 3).map((x) => x[0]);
      const secondary = pick2[Math.floor(this.rng.next() * pick2.length)];
      return primary === secondary ? [primary] : [primary, secondary];
    };

    // гарантии: чтобы "вообще существовали" химия/металл/электроника
    const force = ["metal", "chem", "elec"]; // первые города

    for (let k = 0; k < cityStates.length; k++) {
      const st = cityStates[k];

      // базовое городское: пекарня + консервы
      add(st, "bakery", 2);
      if (st.pop > 50000 && this.rng.next() < 0.7) add(st, "canning", 1);

      // фильтры — далеко не везде
      if (this.rng.next() < 0.35) add(st, "filters", 1);

      // выбираем специализации
      let specs = chooseSpecializations(st);
      if (k < force.length) specs = [force[k], ...specs];
      specs = [...new Set(specs)].slice(0, 2);

      for (const sp of specs) {
        if (sp === "metal") {
          add(st, "smelter", 1);
          if (st.pop > 60000) add(st, "adv_smelter", 1);
          if (this.rng.next() < 0.55) add(st, "smithy", 1);
          if (this.rng.next() < 0.35) add(st, "stamping", 1);
          if (this.rng.next() < 0.25) add(st, "adv_smithy", 1);
        }

        if (sp === "chem") {
          if (this.rng.next() < 0.7) add(st, "plastics", 1);
          if (this.rng.next() < 0.6) add(st, "rubber_works", 1);
          if (this.rng.next() < 0.6) add(st, "fertilizer_plant", 1);
          if (this.rng.next() < 0.6) add(st, "electrolyte", 1);
          if (BUILDINGS.chem_filter_substrate && this.rng.next() < 0.55) add(st, "chem_filter_substrate", 1);
          if (BUILDINGS.chem_electrolyte_chems && this.rng.next() < 0.55) add(st, "chem_electrolyte_chems", 1);
        }

        if (sp === "elec") {
          if (this.rng.next() < 0.65) add(st, "electronics_basic", 1);
          if (this.rng.next() < 0.35) add(st, "electronics_adv", 1);
          if (BUILDINGS.microfab_mcu && this.rng.next() < 0.28) add(st, "microfab_mcu", 1);
          if (BUILDINGS.alloy_villadium && this.rng.next() < 0.22) add(st, "alloy_villadium", 1);
          if (BUILDINGS.life_core_fab && this.rng.next() < 0.16) add(st, "life_core_fab", 1);
        }

        if (sp === "agro") {
          if (this.rng.next() < 0.5) add(st, "textile", 1);
          if (this.rng.next() < 0.5) add(st, "tannery", 1);
          if (this.rng.next() < 0.45) add(st, "line_clothes_peasant", 1);
        }

        if (sp === "rare") {
          // редкий кластер (не линия, но повышенная вероятность фильтров/электроники)
          if (this.rng.next() < 0.65) add(st, "filters", 1);
          if (this.rng.next() < 0.55) add(st, "electronics_basic", 1);
        }
      }

      // сборочные линии — только в части городов
      if (this.rng.next() < 0.20) add(st, "line_gasmasks", 1);
      if (this.rng.next() < 0.18) add(st, "line_batteries", 1);
      if (this.rng.next() < 0.18) add(st, "line_motor_small", 1);
      if (this.rng.next() < 0.12) add(st, "wheel_works", 1);
      if (this.rng.next() < 0.08) add(st, "engine_assembly", 1);
      if (this.rng.next() < 0.06) add(st, "line_motorcycles", 1);
      if (this.rng.next() < 0.03) add(st, "line_jeeps", 1);

      // легкие военные линии — тоже не везде
      if (this.rng.next() < 0.12) add(st, "line_spears", 1);
      if (this.rng.next() < 0.10) add(st, "line_rifle_ep", 1);
    }
  }

  _seedStartingStocks(states) {
    const idx = COM_INDEX;

    for (const st of states) {
      const s = st.stock;
      const pop = st.pop;

      // еда на 6 дней (часть хлебом, часть мутабрюквой)
      s[idx.bread] += pop * 0.0006 * 6;
      s[idx.mutabryukva] += pop * 0.00045 * 6;
      s[idx.meat_cans] += pop * 0.00018 * 6;
      s[idx.meat] += pop * 0.00003 * 3;

      // вода
      s[idx.distilled_water] += pop * 0.0010 * 5;

      // фильтры
      s[idx.villadium_filter_personal] += Math.max(8, pop * 0.00006);

      // немного одежды/ткани
      s[idx.clothes_peasant] += Math.max(2, pop / 12000);
      s[idx.cloth_peasant] += Math.max(3, pop / 9000);

      // базовое сырьё (немного)
      s[idx.wood_raw] += 40;
      s[idx.iron_ore] += 10;
      s[idx.coke] += 8;

      // редкое
      s[idx.villadium] += Math.max(2, pop * 0.000006);
      s[idx.gold] += Math.max(0.5, pop * 0.0000007);
      s[idx.silver] += Math.max(0.2, pop * 0.0000005);

      // удобрения старт (не огромно)
      if (idx.fertilizer_mineral != null) s[idx.fertilizer_mineral] += st.isCity ? 2 : 0.5;
    }

    // в торговые хабы добавим "склад" небольших количеств для запуска цепочек и торговли
    const hubs = this.hubIndices.length ? this.hubIndices : states.filter((s) => s.isCity).slice(0, 6).map((s) => s.idx);
    for (const hi of hubs) {
      const st = states[hi];
      const s = st.stock;
      // химия/пластик/резина
      if (idx.petrochem_raw != null) s[idx.petrochem_raw] += 40;
      if (idx.rubber_raw != null) s[idx.rubber_raw] += 25;
      if (idx.poly_housings != null) s[idx.poly_housings] += 10;
      if (idx.rubber_industrial != null) s[idx.rubber_industrial] += 8;
      // металлы
      if (idx.iron != null) s[idx.iron] += 6;
      if (idx.steel != null) s[idx.steel] += 3;
      if (idx.rolled_steel != null) s[idx.rolled_steel] += 4;
      // электроника
      if (idx.e_parts != null) s[idx.e_parts] += 6;
      if (idx.basic_electronics != null) s[idx.basic_electronics] += 2;
      if (idx.filter_substrate != null) s[idx.filter_substrate] += 10;
      if (idx.electrolyte_chems != null) s[idx.electrolyte_chems] += 10;
    }
  }

  /** Один раз: матрица кратчайших расстояний */
  precomputeDistances() {
    if (this.distMatrix) return this.distMatrix;
    const n = this.n;
    const mat = new Float32Array(n * n);
    for (let i = 0; i < n; i++) {
      const dist = dijkstra(this.edges, i);
      for (let j = 0; j < n; j++) mat[i * n + j] = dist[j];
    }
    this.distMatrix = mat;
    return mat;
  }

  /** @param {number} i @param {number} j */
  dist(i, j) {
    if (!this.distMatrix) this.precomputeDistances();
    return this.distMatrix[i * this.n + j];
  }

  _selectTradeHubs() {
    // хабы: топ-N городов по pop*infra
    const cities = this.states
      .filter((s) => s.isCity)
      .map((s) => ({ idx: s.idx, score: s.pop * s.infra }));

    cities.sort((a, b) => b.score - a.score);
    this.hubIndices = cities.slice(0, Math.max(2, this.world.hubCount)).map((x) => x.idx);

    // если городов нет (на всякий), берём первые провинции
    if (!this.hubIndices.length) {
      this.hubIndices = Array.from({ length: Math.min(3, this.n) }, (_, i) => i);
    }
  }

  _computeGateDistances() {
    // расстояние до ближайшего хаба (как "порт/биржа")
    for (let i = 0; i < this.n; i++) {
      let best = Infinity;
      for (const h of this.hubIndices) {
        const d = this.dist(i, h);
        if (d < best) best = d;
      }
      this.gateDist[i] = isFinite(best) ? best : 9999;
    }
  }

  _marketFloorQty(st, cidx) {
    const com = COMMODITIES[cidx];
    const base = Math.max(0.0001, com.basePrice || 0.0001);

    // минимальная "витрина" в торговых хабах (в ценности), чтобы товары не были вечным 0 глобально
    let floorValue = 0;
    if (com.tier === "raw") floorValue = 420;
    else if (com.tier === "component") floorValue = 320;
    else if (com.tier === "product") floorValue = 520;
    else if (com.tier === "animal") floorValue = 380;

    // очень дорогие позиции — буквально витрина
    if (base >= 5000) floorValue = Math.min(floorValue, 260);

    let qty = floorValue / base;

    // bulky: держим меньше по штукам
    qty = qty / (0.55 + Math.max(0.1, com.bulk));

    const maxQ = com.tier === "raw" ? 160 : (com.tier === "component" ? 60 : (com.tier === "product" ? 10 : 6));
    qty = clamp(qty, 0.02, maxQ);

    // жизненно важные в хабах держим близко к target
    if (ESSENTIAL.has(com.id)) {
      const tgt = st.target ? st.target[cidx] : 0;
      if (tgt > 0) qty = Math.max(qty, tgt * 0.9);
    }

    return qty;
  }

  _marketMakerRestock() {
    // Поддерживаем ассортимент в торговых хабах через внешний рынок.
    // Это даёт ненулевую глобальную наличность даже при нулевом локальном спросе.
    const hubs = this.hubIndices || [];
    if (!hubs.length) return;

    for (const hi of hubs) {
      const st = this.states[hi];
      // бюджет на витрину, чтобы не сожрать всю внешнюю логистику
      let budgetValue = 12000 + (st.pop * st.infra) * 0.01;

      for (let c = 0; c < this.comCount; c++) {
        const com = COMMODITIES[c];
        if (!Number.isFinite(com.basePrice) || com.basePrice <= 0) continue;

        const floor = this._marketFloorQty(st, c);
        if (floor <= 0) continue;

        const cur = st.stock[c];
        if (cur >= floor * 0.98) continue;

        const need = floor - cur;
        const bulk = com.bulk || 1;

        // у внешнего импорта здесь множитель 0.35 ("внешняя логистика")
        const capLeft = st.worldTransportCap - st.worldTransportUsed;
        const maxByCap = capLeft > 0 ? (capLeft / (bulk * 0.35)) : 0;
        const ship = Math.min(need, Math.max(0, maxByCap));
        if (ship <= 0.00001) continue;

        const impPrice = this._worldImportPrice(st, c);
        let value = ship * impPrice;

        if (value > budgetValue) {
          const ship2 = budgetValue / Math.max(1e-9, impPrice);
          if (ship2 <= 0.00001) continue;
          st.stock[c] += ship2;
          st.worldTransportUsed += ship2 * bulk * 0.35;
          st.importsValue += ship2 * impPrice;
          st.gdpTurnover += ship2 * impPrice;
          this._collectTradeTax(st, ship2 * impPrice, "import");
          budgetValue = 0;
          break;
        }

        st.stock[c] += ship;
        st.worldTransportUsed += ship * bulk * 0.35;
        st.importsValue += value;
        st.gdpTurnover += value;
        this._collectTradeTax(st, value, "import");
        budgetValue -= value;

        if (budgetValue <= 0) break;
      }
    }
  }

  _collectTradeTax(st, dealValue, mode = "deal") {
    if (!Number.isFinite(dealValue) || dealValue <= 0) return;
    let rate = this.fiscal.dealTaxRate;
    if (mode === "import") rate = this.fiscal.importTaxRate;
    else if (mode === "export") rate = this.fiscal.exportTaxRate;
    const tax = dealValue * Math.max(0, rate);
    if (tax <= 0) return;
    st.treasury += tax;
    st.treasuryDaily += tax;
    st.treasuryTradeTaxDaily += tax;
  }

  _collectTransitTax(fromIdx, toIdx, movedQty, bulk) {
    if (!Number.isFinite(movedQty) || movedQty <= 0 || !Number.isFinite(bulk) || bulk <= 0) return;
    const route = this._shortestPathNodes(fromIdx, toIdx);
    if (!route || route.length <= 2) return;

    const caravanLoad = movedQty * bulk;
    const d = this.dist(fromIdx, toIdx);
    if (!Number.isFinite(d) || d <= 0) return;

    const totalFee = caravanLoad * d * this.fiscal.transitFeePerBulkDist;
    if (!Number.isFinite(totalFee) || totalFee <= 0) return;

    const mids = route.length - 2;
    const share = totalFee / mids;
    for (let r = 1; r < route.length - 1; r++) {
      const st = this.states[route[r]];
      st.treasury += share;
      st.treasuryDaily += share;
      st.treasuryTransitDaily += share;
    }
  }

  _applyTreasuryExpenses() {
    for (const st of this.states) {
      const popCost = st.pop * this.fiscal.baseExpensePerPop;
      const infraCost = st.pop * Math.max(0, st.infra) * this.fiscal.infraExpenseMul * 0.001;

      let buildingLoad = 0;
      for (const b of (st.buildings || [])) {
        const count = Math.max(0, Number(b.count) || 0);
        const eff = Math.max(0.25, Number(b.efficiency) || 1);
        buildingLoad += count * (0.7 + 0.3 * eff);
      }
      const buildingCost = buildingLoad * st.pop * this.fiscal.buildingExpenseMul * 0.001;

      const cycle = 1 + Math.sin((this.day + st.idx * 17) / 31) * (this.fiscal.expenseVolatility * 0.45);
      const noise = 1 + this.rng.nextNorm() * (this.fiscal.expenseVolatility * 0.18);
      const factor = clamp(cycle * noise, 0.72, 1.35);

      const expense = Math.max(0, (popCost + infraCost + buildingCost) * factor);
      st.treasury -= expense;
      st.treasuryDaily -= expense;
      st.treasuryExpenseDaily += expense;
      st.treasuryNetDaily = st.treasuryDaily;
    }
  }

  _shortestPathNodes(fromIdx, toIdx) {
    const key = `${fromIdx}:${toIdx}`;
    if (this.routeCache.has(key)) return this.routeCache.get(key);

    const n = this.n;
    const dist = new Float64Array(n);
    const used = new Uint8Array(n);
    const prev = new Int32Array(n);
    dist.fill(Number.POSITIVE_INFINITY);
    prev.fill(-1);
    dist[fromIdx] = 0;

    for (let step = 0; step < n; step++) {
      let u = -1;
      let best = Number.POSITIVE_INFINITY;
      for (let i = 0; i < n; i++) {
        if (!used[i] && dist[i] < best) {
          best = dist[i];
          u = i;
        }
      }
      if (u < 0 || u === toIdx) break;
      used[u] = 1;

      for (const e of this.edges[u]) {
        const v = e.to;
        const nd = dist[u] + e.w;
        if (nd < dist[v]) {
          dist[v] = nd;
          prev[v] = u;
        }
      }
    }

    if (!Number.isFinite(dist[toIdx])) {
      this.routeCache.set(key, null);
      return null;
    }

    const path = [];
    for (let cur = toIdx; cur >= 0; cur = prev[cur]) {
      path.push(cur);
      if (cur === fromIdx) break;
    }
    if (path[path.length - 1] !== fromIdx) {
      this.routeCache.set(key, null);
      return null;
    }

    path.reverse();
    this.routeCache.set(key, path);
    return path;
  }

  _ensureGlobalCoverage() {
    // Гарантируем, что:
    // 1) есть хотя бы один производитель по всем критическим типам зданий/цепочек;
    // 2) размещаем их в хабах, чтобы не было "все производят всё".
    const hubs = (this.hubIndices && this.hubIndices.length)
      ? this.hubIndices
      : this.states.filter((s) => s.isCity).slice(0, 6).map((s) => s.idx);

    if (!hubs.length) return;

    const haveBuilding = new Set();
    for (const st of this.states) {
      for (const b of st.buildings) haveBuilding.add(b.type);
    }

    const hubStates = hubs.map((i) => this.states[i]);
    let rr = 0;

    const add = (st, type, count = 1, eff = 1.0) => {
      const c = Math.max(0, Math.floor(count));
      if (!c) return;
      st.buildings.push({ type, count: c, efficiency: eff });
      haveBuilding.add(type);
    };

    // Критические узлы цепочек (особенно high-tier компоненты/продукты)
    const critical = [
      "smelter", "adv_smelter", "stamping", "smithy", "adv_smithy",
      "plastics", "rubber_works", "fertilizer_plant", "electrolyte", "chem_filter_substrate", "chem_electrolyte_chems",
      "electronics_basic", "electronics_adv", "microfab_mcu", "alloy_villadium", "life_core_fab", "life_support",
      "filters", "wheel_works", "engine_assembly", "line_batteries", "line_motor_small", "line_motor_medium", "line_motor_heavy",
      "line_gasmasks", "line_spears", "line_rifle_ep", "line_rifle_gauss", "line_pistols",
      "line_motorcycles", "line_jeeps",
      "line_agri_tools", "line_dishes", "line_motoblock", "line_tricycle_truck", "line_jeep_civil", "line_truck_civil", "line_boat_river", "line_barge_river", "line_air_purifier_home",
      "line_armor_militia", "line_armor_aux", "line_armor_preventor",
    ];

    for (const t of critical) {
      if (!BUILDINGS[t]) continue;
      if (haveBuilding.has(t)) continue;
      const st = hubStates[rr % hubStates.length];
      rr++;
      add(st, t, 1);
    }

    // Любые прочие типы зданий, если полностью отсутствуют
    for (const t of Object.keys(BUILDINGS)) {
      if (haveBuilding.has(t)) continue;
      if (t.startsWith("farm_") || t.startsWith("poultry_") || t === "bakery" || t === "water_distillery") continue;
      const st = hubStates[rr % hubStates.length];
      rr++;
      add(st, t, 1);
    }

    // Добавим хабам минимальные стартовые входы, чтобы цепочки стартовали быстрее
    const idx = COM_INDEX;
    for (const st of hubStates) {
      const s = st.stock;
      if (idx.petrochem_raw != null) s[idx.petrochem_raw] += 90;
      if (idx.rubber_raw != null) s[idx.rubber_raw] += 55;
      if (idx.iron_ore != null) s[idx.iron_ore] += 35;
      if (idx.coke != null) s[idx.coke] += 25;
      if (idx.copper != null) s[idx.copper] += 6;
      if (idx.tin != null) s[idx.tin] += 3;
      if (idx.lead != null) s[idx.lead] += 3;
      if (idx.villadium != null) s[idx.villadium] += 30;
      if (idx.gold != null) s[idx.gold] += 2;
      if (idx.silver != null) s[idx.silver] += 1;
      if (idx.distilled_water != null) s[idx.distilled_water] += 180;
      if (idx.fertilizer_mineral != null) s[idx.fertilizer_mineral] += 8;
    }
  }


  _updateTargets() {
    const idx = COM_INDEX;

    for (const st of this.states) {
      const dd = new Float32Array(this.comCount); // daily demand
      const prodCap = new Float32Array(this.comCount); // daily output capacity (approx)

      const pop = st.pop;

      // базовые потребности населения (в день)
      dd[idx.bread] += pop * 0.0006;
      dd[idx.mutabryukva] += pop * 0.00045;
      dd[idx.meat_cans] += pop * 0.00018;
      dd[idx.meat] += pop * 0.00003;
      dd[idx.distilled_water] += pop * 0.0010;
      dd[idx.villadium_filter_personal] += Math.max(0.15, (pop / 2000) * 0.85);
      dd[idx.clothes_peasant] += pop / 9000;

      // если одежды не хватает — будет "дожирать" ткань
      dd[idx.cloth_peasant] += pop / 25000;

      // ремонт/обслуживание (создаёт постоянный спрос и не даёт складам залипать на потолке)
      // простая модель: каждый объект слегка потребляет материалы
      const maintAdd = (cid, qty) => {
        const ci = idx[cid];
        if (ci == null) return;
        dd[ci] += qty;
      };

      // производственные потребности (входы) + экспортная ёмкость (выходы)
      for (const b of st.buildings) {
        const def = BUILDINGS[b.type];
        if (!def) continue;
        const count = Math.max(0, b.count | 0);
        if (!count) continue;

        const util = st.isCity ? 0.62 : 0.55;

        for (const [cid, req] of Object.entries(def.input || {})) {
          const ci = idx[cid];
          if (ci == null) continue;
          dd[ci] += req * count * util;
        }

        for (const [cid, out] of Object.entries(def.output || {})) {
          const ci = idx[cid];
          if (ci == null) continue;
          prodCap[ci] += out * count * util;
        }

        // универсальное обслуживание
        if (b.type.includes("smelt") || b.type.includes("stamping") || b.type.includes("engine") || b.type.includes("electronics") || b.type.includes("filters") || b.type.includes("electrolyte") || b.type.includes("life") || b.type.startsWith("line_")) {
          maintAdd("steel", 0.03 * count);
          maintAdd("forged_parts", 0.06 * count);
          if (b.type.includes("electronics") || b.type.includes("filters") || b.type.includes("life")) maintAdd("e_parts", 0.04 * count);
        } else {
          maintAdd("wood_processed", 0.05 * count);
        }
      }

      // формируем target/reserve
      for (let c = 0; c < this.comCount; c++) {
        const com = COMMODITIES[c];
        let cover = COVER_BY_TIER[com.tier] || 10;

        if (com.decayPerDay >= 0.02) cover = Math.min(cover, 8);
        if (com.decayPerDay >= 0.05) cover = Math.min(cover, 5);

        // базовый target по спросу
        let tgt = dd[c] * cover;

        // если провинция производит товар — держим буфер для экспорта
        if (prodCap[c] > 0) {
          tgt = Math.max(tgt, prodCap[c] * 5); // ~5 дней выпуска
        }

        // для сырья: если есть природный потенциал — небольшой торговый буфер
        if (com.tier === "raw" && st.rawPotential[c] > 0) {
          tgt = Math.max(tgt, st.rawPotential[c] * 1.6);
        }

        // минимум для жизненно важных
        if (ESSENTIAL.has(com.id)) {
          tgt = Math.max(tgt, 4);
        }

        // если спроса нет и не производим — target=0
        if (dd[c] <= 0 && prodCap[c] <= 0 && !(com.tier === "raw" && st.rawPotential[c] > 0)) {
          tgt = 0;
        }

        // небольшой буфер от дискретности
        if (tgt > 0) tgt += Math.max(0.5, tgt * 0.05);

        st.target[c] = tgt;
        st.reserve[c] = tgt * RESERVE_MUL;
      }

      // пропускная способность тоже зависит от инфраструктуры
      st.transportCap = Math.max(180, st.pop * st.infra * 0.022);

      // внешний поток больше внутреннего (караваны/торговцы/порты)
      st.worldTransportCap = st.transportCap * (st.isCity ? 4.0 : 2.6) + (st.isCity ? 900 : 420);
    }
  }

  /**
   * Себестоимость добычи сырья: зависит от редкости и "качества" месторождения.
   * @param {ProvinceState} st
   * @param {number} cidx
   */
  _rawUnitCost(st, cidx) {
    const base = COMMODITIES[cidx].basePrice;
    const rarity = COMMODITIES[cidx].rarity;
    const mean = this.rawMean[cidx] || 1;
    const adv = clamp(st.rawPotential[cidx] / (mean * 1.25), 0, 1);

    // low pot => cost > base; high pot => cost < base
    const costMul = (1.25 - 0.65 * adv) * (0.85 + 0.35 * rarity);
    return base * costMul;
  }

  /** Стоимость доставки в "внешний рынок" (через ближайший хаб). */
  _worldShipCost(st, cidx) {
    const bulk = COMMODITIES[cidx].bulk;
    const d = this.gateDist[st.idx] || 0;
    const infra = Math.max(0.15, st.infra);
    return (d * this.transportUnitCost * bulk) / infra + this.world.portFee * bulk;
  }

  _worldImportPrice(st, cidx) {
    const base = COMMODITIES[cidx].basePrice;
    const ship = this._worldShipCost(st, cidx);
    return base * (1 + this.world.importMarkup) + ship;
  }

  _worldExportPrice(st, cidx) {
    const base = COMMODITIES[cidx].basePrice;
    const ship = this._worldShipCost(st, cidx);
    // экспорт всегда чуть хуже базы и ещё минус доставка
    return Math.max(base * 0.08, base * (1 - this.world.exportMarkdown) - ship);
  }

  /** Один тик = 1 день */
  tick() {
    this.day++;
    this._dayProduced.fill(0);
    this._daySold.fill(0);

    for (const st of this.states) {
      st.transportUsed = 0;
      st.worldTransportUsed = 0;
      st.gdpTurnover = 0;
      st.importsValue = 0;
      st.exportsValue = 0;
      st.treasuryDaily = 0;
      st.treasuryTradeTaxDaily = 0;
      st.treasuryTransitDaily = 0;
      st.treasuryExpenseDaily = 0;
      st.treasuryNetDaily = 0;
    }

    // цели зависят от производства/населения (и меняют торговлю)
    this._updateTargets();

    // витрина ассортимента в хабах (внешний рынок), чтобы не было вечных нулей
    this._marketMakerRestock();

    // PRE-TRADE: сначала подтягиваем критические дефициты (импорт/переток), чтобы цепочки могли стартовать
    this._tradeAll({ phase: "pre" });

    this._harvestRaw();
    this._runProduction();
    this._consumePopulation();
    this._consumeMaintenance();
    this._decayStocks();

    // POST-TRADE: окончательное выравнивание + экспорт
    this._tradeAll({ phase: "post" });
    this._applyTreasuryExpenses();
    this._updatePrices();
    this._rollYearStats();
    this._rollProvinceYearStats();
  }

  _harvestRaw() {
    for (const st of this.states) {
      for (const cidx of HARVEST_RAW_IDX) {
        const pot = st.rawPotential[cidx];
        if (pot <= 0) continue;

        const cost = this._rawUnitCost(st, cidx);
        const p = st.price[cidx];
        const profit = p - cost;
        if (profit <= 0) continue;

        // реакция добычи на прибыль: 0..1
        const factor = clamp(profit / Math.max(1e-6, cost * 0.9), 0, 1);
        const qty = pot * (0.35 + 0.75 * factor);

        st.stock[cidx] += qty;
        this._recordProduced(cidx, qty);
      }
    }
  }

  _runProduction() {
    const idx = COM_INDEX;

    const isEssentialOutputDeficit = (st, def) => {
      for (const [cid, out] of Object.entries(def.output || {})) {
        if (out <= 0) continue;
        if (!ESSENTIAL.has(cid)) continue;
        const ci = idx[cid];
        const tgt = st.target[ci];
        if (tgt <= 0) continue;
        if (st.stock[ci] < tgt * 0.95) return true;
      }
      return false;
    };

    const demandFactorFor = (st, def) => {
      let want = 0;
      for (const [cid, out] of Object.entries(def.output || {})) {
        if (out <= 0) continue;
        const ci = idx[cid];
        const tgt = st.target[ci];
        if (tgt <= 0) continue;

        const cap = tgt * EXPORT_BUFFER_MUL;
        const ratio = cap > 0 ? st.stock[ci] / cap : 1;
        const need = clamp(1.0 - ratio, 0, 1);
        want = Math.max(want, need);
      }
      if (want <= 0.001) return 0;
      return clamp(want, 0.15, 1.0);
    };

    const buildingProfitOk = (st, def, count) => {
      // простая проверка: суммарная ценность выходов должна быть заметно выше входов
      // (иначе не грузим мощности, кроме жизненно важных дефицитов)
      let inCost = 0;
      for (const [cid, req] of Object.entries(def.input || {})) {
        const ci = idx[cid];
        inCost += req * count * st.price[ci];
      }
      let outValue = 0;
      for (const [cid, out] of Object.entries(def.output || {})) {
        const ci = idx[cid];
        outValue += out * count * st.price[ci];
      }

      const wage = st.price[idx.bread] * 0.15; // условная зарплата за 1 "ед" труда
      const laborCost = (def.labor || 0) * count * wage;

      // лёгкая "маржа" и труд
      return outValue >= (inCost + laborCost) * 1.03;
    };

    for (const st of this.states) {
      const workforce = st.pop * 0.42;
      let remainingLabor = workforce;

      const order = (btype) => {
        if (btype.startsWith("farm") || btype.startsWith("poultry") || btype === "bakery" || btype === "canning" || btype === "water_distillery") return 1;
        if (btype.includes("smelter") || btype.includes("sawmill") || btype.includes("textile") || btype.includes("tannery") || btype.includes("fertilizer") || btype.includes("chem_")) return 2;
        if (btype.includes("electronics") || btype.includes("filters") || btype.includes("electrolyte") || btype.includes("microfab") || btype.includes("alloy") || btype.includes("life")) return 3;
        if (btype.startsWith("line_") || btype.includes("assembly") || btype.includes("wheel")) return 4;
        return 5;
      };

      const blds = [...st.buildings].sort((a, b) => order(a.type) - order(b.type));

      for (const b of blds) {
        const def = BUILDINGS[b.type];
        if (!def) continue;

        const count = Math.max(0, b.count | 0);
        if (!count) continue;

        const needLabor = (def.labor || 0) * count;
        const laborFactor = clamp(needLabor > 0 ? remainingLabor / needLabor : 1, 0, 1);
        if (laborFactor <= 0.001) continue;

        const want = demandFactorFor(st, def);
        if (want <= 0.001) continue;

        // ограничение по входам
        let inputFactor = 1.0;
        for (const [cid, req] of Object.entries(def.input || {})) {
          const ci = idx[cid];
          const avail = st.stock[ci];
          const need = req * count;
          const f = need > 0 ? avail / need : 1;
          inputFactor = Math.min(inputFactor, f);
        }

        const essentialDeficit = isEssentialOutputDeficit(st, def);
        const profitOk = buildingProfitOk(st, def, count);
        if (!profitOk && !essentialDeficit) continue;

        const factor = clamp(Math.min(laborFactor, inputFactor, want) * (b.efficiency || 1) * (def.cap || 1), 0, 1);
        if (factor <= 0.0001) continue;

        for (const [cid, req] of Object.entries(def.input || {})) {
          const ci = idx[cid];
          st.stock[ci] -= req * count * factor;
          if (st.stock[ci] < 0) st.stock[ci] = 0;
        }
        for (const [cid, out] of Object.entries(def.output || {})) {
          const ci = idx[cid];
          const qty = out * count * factor;
          st.stock[ci] += qty;
          this._recordProduced(ci, qty);
        }

        remainingLabor -= needLabor * factor;
        if (remainingLabor <= workforce * 0.05) break;
      }
    }
  }

  _consumePopulation() {
    const idx = COM_INDEX;

    for (const st of this.states) {
      const pop = st.pop;

      const needBread = pop * 0.0006;
      const needRoot = pop * 0.00045;
      const needCans = pop * 0.00018;
      const needMeat = pop * 0.00003;
      const needWater = pop * 0.0010;

      const needFilter = Math.max(0.15, (pop / 2000) * 0.85);
      const needClothes = pop / 9000;

      const take = (cid, qty) => {
        const i = idx[cid];
        const got = Math.min(st.stock[i], qty);
        st.stock[i] -= got;
        this._recordSold(i, got);
        return got;
      };

      // углеводы: хлеб + мутабрюква как взаимозаменяемые (с перекрёстным добором)
      let b = take("bread", needBread);
      if (b < needBread) take("mutabryukva", (needBread - b) * 1.15);

      let r = take("mutabryukva", needRoot);
      if (r < needRoot) take("bread", (needRoot - r) * 0.9);

      // белок
      let c = take("meat_cans", needCans);
      if (c < needCans) take("meat", (needCans - c) * 1.1 + needMeat);
      else take("meat", needMeat * 0.35);

      take("distilled_water", needWater);
      take("villadium_filter_personal", needFilter);

      // одежда, если не хватает — частично тканью
      let cl = take("clothes_peasant", needClothes);
      if (cl < needClothes) take("cloth_peasant", (needClothes - cl) * 2.0);
    }
  }

  _consumeMaintenance() {
    const idx = COM_INDEX;

    // деградация, если нет обслуживания
    for (const st of this.states) {
      let fail = 0;

      const pull = (cid, qty) => {
        const ci = idx[cid];
        if (ci == null) return 0;
        const got = Math.min(st.stock[ci], qty);
        st.stock[ci] -= got;
        this._recordSold(ci, got);
        return got;
      };

      for (const b of st.buildings) {
        const count = Math.max(0, b.count | 0);
        if (!count) continue;

        let needSteel = 0.01 * count;
        let needParts = 0.02 * count;
        let needE = 0;
        let needWood = 0.006 * count;
        let needRubber = 0;
        let needPoly = 0;
        let needElectrolyteChem = 0;
        let needBasicElectronics = 0;
        let needFrames = 0;
        let needEngineKit = 0;
        let needBatt = 0;
        let needPower = 0;
        let needAdvCtrl = 0;
        let needArmo = 0;
        let needVillAlloy = 0;
        let needLife = 0;

        if (b.type.includes("smelt") || b.type.includes("electronics") || b.type.includes("filters") || b.type.includes("life") || b.type.startsWith("line_") || b.type.includes("engine") || b.type.includes("wheel") || b.type.includes("stamping") || b.type.includes("chem_")) {
          needSteel = 0.03 * count;
          needParts = 0.05 * count;
          if (b.type.includes("electronics") || b.type.includes("filters") || b.type.includes("life") || b.type.includes("microfab")) needE = 0.03 * count;

          needWood = 0.01 * count;
          needRubber = 0.008 * count;
          needPoly = 0.006 * count;
          needElectrolyteChem = 0.004 * count;
          needBasicElectronics = 0.005 * count;
          needFrames = 0.002 * count;
          needBatt = 0.003 * count;

          if (b.type.startsWith("line_") || b.type.includes("engine") || b.type.includes("wheel")) {
            needEngineKit = 0.0012 * count;
            needFrames = Math.max(needFrames, 0.004 * count);
          }

          if (b.type.includes("life") || b.type.includes("electronics") || b.type.includes("microfab") || b.type.includes("alloy") || b.type.includes("order_fortress")) {
            needPower = 0.0012 * count;
            needAdvCtrl = 0.0009 * count;
          }

          if (b.type.includes("armor") || b.type.includes("barracks") || b.type.includes("fortress")) {
            needArmo = 0.0025 * count;
            needVillAlloy = 0.0004 * count;
          }

          if (b.type.includes("life") || b.type.includes("bunker") || b.type.includes("fortress")) {
            needLife = 0.0005 * count;
          }
        }

        const gotSteel = pull("steel", needSteel);
        const gotParts = pull("forged_parts", needParts);
        const gotE = needE > 0 ? pull("e_parts", needE) : needE;
        const gotWood = pull("wood_processed", needWood);
        const gotRubber = needRubber > 0 ? pull("rubber_industrial", needRubber) : needRubber;
        const gotPoly = needPoly > 0 ? pull("poly_housings", needPoly) : needPoly;
        const gotElectrolyteChem = needElectrolyteChem > 0 ? pull("electrolyte_chems", needElectrolyteChem) : needElectrolyteChem;
        const gotBasicElectronics = needBasicElectronics > 0 ? pull("basic_electronics", needBasicElectronics) : needBasicElectronics;
        const gotFrames = needFrames > 0 ? pull("stamped_frames", needFrames) : needFrames;
        const gotEngineKit = needEngineKit > 0 ? pull("engine_kit", needEngineKit) : needEngineKit;
        const gotBatt = needBatt > 0 ? pull("batt_ind", needBatt) : needBatt;
        const gotPower = needPower > 0 ? pull("power_module", needPower) : needPower;
        const gotAdvCtrl = needAdvCtrl > 0 ? pull("adv_controller", needAdvCtrl) : needAdvCtrl;
        const gotArmo = needArmo > 0 ? pull("armotextile", needArmo) : needArmo;
        const gotVillAlloy = needVillAlloy > 0 ? pull("villadium_alloy", needVillAlloy) : needVillAlloy;
        const gotLife = needLife > 0 ? pull("life_module", needLife) : needLife;

        const gotTotal = gotSteel + gotParts + gotE + gotWood + gotRubber + gotPoly + gotElectrolyteChem + gotBasicElectronics + gotFrames + gotEngineKit + gotBatt + gotPower + gotAdvCtrl + gotArmo + gotVillAlloy + gotLife;
        const needTotal = needSteel + needParts + needE + needWood + needRubber + needPoly + needElectrolyteChem + needBasicElectronics + needFrames + needEngineKit + needBatt + needPower + needAdvCtrl + needArmo + needVillAlloy + needLife;
        if (gotTotal < needTotal * 0.6) fail += 1;
      }

      if (fail > 0) {
        const penalty = clamp(0.98 - fail * 0.0025, 0.90, 0.99);
        for (const b of st.buildings) b.efficiency = clamp((b.efficiency || 1) * penalty, 0.65, 1.15);
      } else {
        for (const b of st.buildings) b.efficiency = clamp((b.efficiency || 1) * 1.0008, 0.75, 1.15);
      }
    }
  }

  _decayStocks() {
    for (const st of this.states) {
      for (let i = 0; i < this.comCount; i++) {
        const d = COMMODITIES[i].decayPerDay;
        if (d > 0 && st.stock[i] > 0) {
          const before = st.stock[i];
          st.stock[i] *= 1 - d;
          this._recordSold(i, Math.max(0, before - st.stock[i]));
        }
      }
    }
  }

  _tradeAll(opts = {}) {
    if (!this.distMatrix) this.precomputeDistances();

    const n = this.n;
    const comCount = this.comCount;

    const canShip = (st, qty, bulkMul) => (st.transportUsed + qty * bulkMul) <= st.transportCap;
    const canWorldShip = (st, qty, bulkMul) => (st.worldTransportUsed + qty * bulkMul) <= st.worldTransportCap;

    for (let cidx = 0; cidx < comCount; cidx++) {
      const bulk = COMMODITIES[cidx].bulk;

      const sellers = [];
      const buyers = [];

      for (let i = 0; i < n; i++) {
        const st = this.states[i];
        const cur = st.stock[cidx];
        const tgt = st.target[cidx];
        const res = st.reserve[cidx];

        // если target=0, но есть товар — это чистый экспортный/перепродажный склад
        const keep = tgt > 0 ? res : 0;

        if (cur > keep * 1.05) {
          sellers.push({
            i,
            qty: cur - keep,
            ask: st.price[cidx] * (1 - 0.015),
          });
        }

        if (tgt > 0 && cur < tgt * 0.98) {
          buyers.push({
            i,
            qty: tgt - cur,
            bid: st.price[cidx] * (1 + 0.02),
          });
        }
      }

      // сначала — внутренняя торговля
      if (sellers.length && buyers.length) {
        sellers.sort((a, b) => a.ask - b.ask);

        const maxCandidates = Math.min(70, sellers.length);

        for (const b of buyers) {
          let need = b.qty;
          if (need <= 0) continue;

          // пытаемся закрыть потребность несколькими продавцами
          let safety = 0;
          while (need > 1e-6 && safety++ < 8) {
            let bestIdx = -1;
            let bestDelivered = Infinity;

            for (let s = 0; s < maxCandidates; s++) {
              const sel = sellers[s];
              if (sel.qty <= 1e-6) continue;

              const from = this.states[sel.i];
              const to = this.states[b.i];
              const d = this.dist(sel.i, b.i);
              if (!isFinite(d)) continue;

              const infra = (from.infra + to.infra) * 0.5;
              const ship = (d * this.transportUnitCost * bulk) / Math.max(0.15, infra);
              const delivered = sel.ask + ship * (1 + this.tradeFriction);

              if (delivered < bestDelivered) {
                bestDelivered = delivered;
                bestIdx = s;
              }
            }

            if (bestIdx < 0) break;

            const sel = sellers[bestIdx];
            const from = this.states[sel.i];
            const to = this.states[b.i];

            const qty = Math.min(need, sel.qty);
            if (qty <= 0) break;

            if (!canShip(from, qty, bulk) || !canShip(to, qty, bulk * 0.35)) break;

            // если цена доставки совсем не проходит — лучше импорт
            if (bestDelivered > b.bid * 1.25) break;

            const d = this.dist(sel.i, b.i);
            const loss = clamp(d * 0.01 * COMMODITIES[cidx].decayPerDay * 12, 0, 0.35);
            const arrived = qty * (1 - loss);

            from.stock[cidx] -= qty;
            to.stock[cidx] += arrived;
            this._recordSold(cidx, qty);

            from.transportUsed += qty * bulk;
            to.transportUsed += qty * bulk * 0.35;

            const money = qty * bestDelivered;
            from.gdpTurnover += money;
            to.gdpTurnover += money;
            this._collectTradeTax(from, money, "deal");
            this._collectTradeTax(to, money, "deal");
            this._collectTransitTax(sel.i, b.i, qty, bulk);

            sel.qty -= qty;
            need -= qty;
          }
        }
      }

      // затем — внешний рынок (импорт если дефицит, экспорт если избыток)
      const phase = (opts.phase || "post");
      for (let i = 0; i < n; i++) {
        const st = this.states[i];
        const cur = st.stock[cidx];
        const tgt = st.target[cidx];

        // импорт (если целевой >0)
        if (tgt > 0 && cur < tgt * (phase === "pre" ? 0.985 : 0.92)) {
          const fill = phase === "pre" ? 0.995 : 0.98;
          const need = (tgt * fill) - cur;
          if (need > 0.001) {
            if (canWorldShip(st, need, bulk * 0.35)) {
              const impPrice = this._worldImportPrice(st, cidx);
              st.stock[cidx] += need;
              st.worldTransportUsed += need * bulk * 0.35;
              const money = need * impPrice;
              st.gdpTurnover += money;
              st.importsValue += money;
              this._collectTradeTax(st, money, "import");
            }
          }
        }

        // экспорт (если запас выше экспортного буфера)
        if (phase === "pre") continue;
        const cap = tgt > 0 ? (tgt * EXPORT_BUFFER_MUL) : 0;
        const surplus = tgt > 0 ? (cur - cap) : (cur - 0);
        if (surplus > 0.001) {
          if (canWorldShip(st, surplus, bulk)) {
            const expPrice = this._worldExportPrice(st, cidx);
            st.stock[cidx] -= surplus;
            this._recordSold(cidx, surplus);
            st.worldTransportUsed += surplus * bulk;
            const money = surplus * expPrice;
            st.gdpTurnover += money;
            st.exportsValue += money;
            this._collectTradeTax(st, money, "export");
          }
        }
      }
    }
  }

  _updatePrices() {
    const comCount = this.comCount;

    for (const st of this.states) {
      for (let cidx = 0; cidx < comCount; cidx++) {
        const cur = st.stock[cidx];
        const tgt = st.target[cidx];
        const base = COMMODITIES[cidx].basePrice;

        // если target=0, цена прибивается к внешнему экспорту/импорту, но не дергается
        const ratio = tgt > 0 ? cur / tgt : 1;
        const scarcity = tgt > 0 ? clamp((tgt - cur) / Math.max(1e-6, tgt), -2, 2) : 0;

        let p = st.price[cidx];

        // реакция на дефицит/избыток
        p *= 1 + scarcity * 0.05;

        const imp = this._worldImportPrice(st, cidx);
        const exp = this._worldExportPrice(st, cidx);

        // мягкая привязка к внешним паритетам
        if (tgt > 0) {
          if (ratio < 0.9) {
            // тянем вверх к импорту
            p = p * 0.90 + imp * 0.10;
          } else if (ratio > EXPORT_BUFFER_MUL) {
            // тянем вниз к экспорту
            p = p * 0.90 + exp * 0.10;
          }
        } else {
          // без локального спроса цена близка к экспортной
          p = p * 0.92 + exp * 0.08;
        }

        // ограничения
        const lo = Math.max(base * 0.06, exp * 0.85);
        const hi = Math.max(base * 8.0, imp * 2.8);
        st.price[cidx] = clamp(p, lo, hi);
      }
    }
  }

  report() {
    let popTotal = 0;
    for (const st of this.states) popTotal += st.pop;

    const topGDP = [...this.states]
      .sort((a, b) => b.gdpYear - a.gdpYear)
      .slice(0, 10)
      .map((st) => ({
        pid: st.pid,
        name: this.provinces[st.idx].name,
        gdp: Math.round(st.gdpYear),
        pop: st.pop,
        infra: +st.infra.toFixed(2),
        imp: Math.round(st.importsYear),
        exp: Math.round(st.exportsYear),
        treasury: Math.round(st.treasury),
        treasuryTradeTaxYear: Math.round(st.treasuryTradeTaxYear),
        treasuryTransitYear: Math.round(st.treasuryTransitYear),
        treasuryExpenseYear: Math.round(st.treasuryExpenseYear),
        treasuryNetYear: Math.round(st.treasuryNetYear),
      }));

    const ratios = [];
    for (let c = 0; c < this.comCount; c++) {
      let sumRatio = 0;
      let cnt = 0;
      for (const st of this.states) {
        const tgt = st.target[c];
        const cur = st.stock[c];
        // если target=0 — не считаем как дефицит/избыток
        const r = tgt > 0 ? (cur / tgt) : 1;
        sumRatio += r;
        cnt++;
      }
      ratios.push({ c, ratio: sumRatio / cnt });
    }

    const scarce = [...ratios]
      .sort((a, b) => a.ratio - b.ratio)
      .slice(0, 10)
      .map((x) => ({
        commodity: COMMODITIES[x.c].name,
        ratio: +x.ratio.toFixed(3),
        basePrice: COMMODITIES[x.c].basePrice,
      }));

    const cheap = [...ratios]
      .sort((a, b) => b.ratio - a.ratio)
      .slice(0, 10)
      .map((x) => ({
        commodity: COMMODITIES[x.c].name,
        ratio: +x.ratio.toFixed(3),
        basePrice: COMMODITIES[x.c].basePrice,
      }));

    return { day: this.day, topGDP, scarce, cheap, popTotal };
  }

  globalTradeBalance() {
    const rows = COMMODITIES.map((c, i) => {
      let stock = 0;
      for (const st of this.states) stock += st.stock[i];
      const produced = this.yearProduced[i] || 0;
      const sold = this.yearSold[i] || 0;
      return {
        id: c.id,
        name: c.name,
        unit: c.unit,
        tier: c.tier,
        produced: +produced.toFixed(4),
        sold: +sold.toFixed(4),
        saldo: +(produced - sold).toFixed(4),
        stock: +stock.toFixed(4),
      };
    });

    return {
      day: this.day,
      periodDays: Math.min(this.day, this.yearWindow),
      rows,
    };
  }

  exportSnapshot() {
    return {
      day: this.day,
      hubs: this.hubIndices.map((i) => ({ pid: this.provinces[i].pid, name: this.provinces[i].name })),
      provinces: this.states.map((st) => ({
        pid: st.pid,
        pop: st.pop,
        infra: st.infra,
        isCity: st.isCity,
        stock: Array.from(st.stock),
        price: Array.from(st.price),
        target: Array.from(st.target),
        buildings: st.buildings,
        transportCap: st.transportCap,
        transportUsed: st.transportUsed,
        gdpTurnover: st.gdpYear,
        importsValue: st.importsYear,
        exportsValue: st.exportsYear,
        treasury: st.treasury,
        treasuryTradeTaxYear: st.treasuryTradeTaxYear,
        treasuryTransitYear: st.treasuryTransitYear,
        treasuryExpenseYear: st.treasuryExpenseYear,
        treasuryNetYear: st.treasuryNetYear,
      })),
      commodities: COMMODITIES,
    };
  }
}
