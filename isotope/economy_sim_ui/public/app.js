const el = (id) => document.getElementById(id);

const UI = {
  seed: el("seed"),
  stepYears: el("stepYears"),
  transport: el("transport"),
  friction: el("friction"),
  btnReset: el("btnReset"),
  btnTick: el("btnTick"),
  btnRun: el("btnRun"),
  tabMarket: el("tabMarket"),
  tabTradeBalance: el("tabTradeBalance"),

  day: el("day"),
  popTotal: el("popTotal"),

  search: el("search"),
  sort: el("sort"),
  provList: el("provList"),

  topGDP: el("topGDP"),
  scarce: el("scarce"),
  cheap: el("cheap"),

  provHeader: el("provHeader"),
  p_pid: el("p_pid"),
  p_terrain: el("p_terrain"),
  p_city: el("p_city"),
  p_pop: el("p_pop"),
  p_infra: el("p_infra"),
  p_transport: el("p_transport"),
  p_gdp: el("p_gdp"),
  p_treasury: el("p_treasury"),
  p_treasury_trade_tax: el("p_treasury_trade_tax"),
  p_treasury_transit: el("p_treasury_transit"),
  p_treasury_expense: el("p_treasury_expense"),
  p_treasury_net: el("p_treasury_net"),
  goods: el("goods"),
  buildings: el("buildings"),
  tbPeriod: el("tbPeriod"),
  tradeBalance: el("tradeBalance"),

  tier: el("tier"),
  sortGoods: el("sortGoods"),
  limit: el("limit"),
  onlyActive: el("onlyActive"),
};

let META = null;
let SELECTED_PID = null;
let RUN_TIMER = null;
let ACTIVE_TAB = "market";

function fmtInt(x) {
  return Number(x || 0).toLocaleString("ru-RU");
}

function fmtNum(x, digits = 2) {
  const v = Number(x || 0);
  if (!Number.isFinite(v)) return "0";
  return v.toLocaleString("ru-RU", { maximumFractionDigits: digits, minimumFractionDigits: 0 });
}

async function api(path) {
  const r = await fetch(path, { cache: "no-store" });
  if (!r.ok) {
    const t = await r.text();
    throw new Error(`${r.status} ${r.statusText}: ${t}`);
  }
  return r.json();
}

function setRunning(isRunning) {
  if (isRunning) {
    UI.btnRun.textContent = "Стоп";
    UI.btnRun.classList.add("primary");
  } else {
    UI.btnRun.textContent = "Пуск";
    UI.btnRun.classList.add("primary");
  }
}

function renderSummary(sum) {
  UI.day.textContent = String(sum.day ?? 0);
  UI.popTotal.textContent = fmtInt(sum.popTotal ?? 0);

  const top = (sum.topGDP || [])
    .map((x, i) => `#${i + 1}  ${x.pid}  ${x.name}  | gdp=${fmtInt(x.gdp)}  pop=${fmtInt(x.pop)}  infra=${fmtNum(x.infra, 2)}`)
    .join("\n");
  UI.topGDP.textContent = top || "—";

  const scarce = (sum.scarce || [])
    .map((x) => `↓ ${x.commodity}  ratio=${fmtNum(x.ratio, 3)}  base=${fmtNum(x.basePrice, 0)}`)
    .join("\n");
  UI.scarce.textContent = scarce || "—";

  const cheap = (sum.cheap || [])
    .map((x) => `↑ ${x.commodity}  ratio=${fmtNum(x.ratio, 3)}  base=${fmtNum(x.basePrice, 0)}`)
    .join("\n");
  UI.cheap.textContent = cheap || "—";
}

function provinceSortKey(p, mode) {
  if (mode === "pop") return -p.pop;
  if (mode === "infra") return -p.infra;
  if (mode === "city") return p.isCity ? -1 : 0;
  return (p.name || "").toLowerCase();
}

function renderProvinceList() {
  if (!META) return;
  const q = (UI.search.value || "").trim().toLowerCase();
  const mode = UI.sort.value;

  let arr = META.provinces.slice();
  if (q) {
    arr = arr.filter((p) => {
      const n = (p.name || "").toLowerCase();
      const t = (p.terrain || "").toLowerCase();
      return n.includes(q) || t.includes(q) || String(p.pid).includes(q);
    });
  }

  arr.sort((a, b) => {
    const ka = provinceSortKey(a, mode);
    const kb = provinceSortKey(b, mode);
    if (typeof ka === "string") return ka.localeCompare(kb);
    return ka - kb;
  });

  UI.provList.innerHTML = "";

  const frag = document.createDocumentFragment();
  for (const p of arr) {
    const div = document.createElement("div");
    div.className = "item" + (p.pid === SELECTED_PID ? " active" : "");
    div.dataset.pid = String(p.pid);

    const top = document.createElement("div");
    top.className = "itemTop";

    const name = document.createElement("div");
    name.className = "itemName";
    name.textContent = `${p.pid} — ${p.name}`;

    const badge = document.createElement("div");
    badge.className = "badge" + (p.isCity ? " city" : "");
    badge.textContent = p.isCity ? "CITY" : (p.terrain || "").slice(0, 16);

    top.appendChild(name);
    top.appendChild(badge);

    const meta = document.createElement("div");
    meta.className = "itemMeta";
    meta.textContent = `pop ${fmtInt(p.pop)} · infra ${fmtNum(p.infra, 2)} · hex ${fmtInt(p.hex_count)}`;

    div.appendChild(top);
    div.appendChild(meta);

    div.addEventListener("click", () => selectProvince(p.pid));
    frag.appendChild(div);
  }
  UI.provList.appendChild(frag);
}

function ratioClass(ratio) {
  if (!Number.isFinite(ratio)) return "";
  if (ratio < 0.8) return "ratioBad";
  if (ratio > 1.3) return "ratioGood";
  return "";
}

function renderTradeBalance(tb) {
  UI.tbPeriod.textContent = String(tb.periodDays ?? 0);
  UI.tradeBalance.innerHTML = "";

  const rows = [...(tb.rows || [])].sort((a, b) => Math.abs(b.saldo) - Math.abs(a.saldo));
  const frag = document.createDocumentFragment();

  for (const r of rows) {
    const tr = document.createElement("tr");

    const tdName = document.createElement("td");
    tdName.textContent = r.name;

    const tdProduced = document.createElement("td");
    tdProduced.className = "r mono";
    tdProduced.textContent = fmtNum(r.produced, 2);

    const tdSold = document.createElement("td");
    tdSold.className = "r mono";
    tdSold.textContent = fmtNum(r.sold, 2);

    const tdSaldo = document.createElement("td");
    tdSaldo.className = `r mono ${r.saldo >= 0 ? "ratioGood" : "ratioBad"}`;
    tdSaldo.textContent = fmtNum(r.saldo, 2);

    const tdStock = document.createElement("td");
    tdStock.className = "r mono";
    tdStock.textContent = fmtNum(r.stock, 2);

    const tdTier = document.createElement("td");
    tdTier.textContent = r.tier;

    tr.appendChild(tdName);
    tr.appendChild(tdProduced);
    tr.appendChild(tdSold);
    tr.appendChild(tdSaldo);
    tr.appendChild(tdStock);
    tr.appendChild(tdTier);
    frag.appendChild(tr);
  }

  UI.tradeBalance.appendChild(frag);
}

function setActiveTab(tab) {
  ACTIVE_TAB = tab;
  UI.tabMarket.classList.toggle("active", tab === "market");
  UI.tabTradeBalance.classList.toggle("active", tab === "trade");

  document.querySelectorAll(".tabPanel").forEach((panel) => {
    const own = panel.getAttribute("data-tab") || "market";
    const show = (tab === "market" && own === "market") || (tab === "trade" && own === "trade");
    panel.classList.toggle("hidden", !show);
  });
}

async function refreshTradeBalance() {
  const tb = await api("/api/trade-balance");
  renderTradeBalance(tb);
}

function renderProvinceDetail(p) {
  if (!p) return;

  UI.provHeader.textContent = `${p.pid} — ${p.name}`;
  UI.p_pid.textContent = String(p.pid);
  UI.p_terrain.textContent = p.terrain || "";
  UI.p_city.textContent = p.isCity ? "yes" : "no";
  UI.p_pop.textContent = fmtInt(p.pop);
  UI.p_infra.textContent = fmtNum(p.infra, 2);
  UI.p_transport.textContent = `${fmtNum(p.transportUsed, 1)} / ${fmtNum(p.transportCap, 1)}`;
  UI.p_gdp.textContent = fmtInt(p.gdpTurnover);
  UI.p_treasury.textContent = fmtInt(p.treasury);
  UI.p_treasury_trade_tax.textContent = fmtInt(p.treasuryTradeTaxYear);
  UI.p_treasury_transit.textContent = fmtInt(p.treasuryTransitYear);
  UI.p_treasury_expense.textContent = fmtInt(p.treasuryExpenseYear);
  UI.p_treasury_net.textContent = fmtInt(p.treasuryNetYear);

  UI.goods.innerHTML = "";
  const frag = document.createDocumentFragment();

  for (const g of p.commodities || []) {
    const tr = document.createElement("tr");

    const tdName = document.createElement("td");
    tdName.textContent = g.name;

    const tdStock = document.createElement("td");
    tdStock.className = "r mono";
    tdStock.textContent = fmtNum(g.stock, 2);

    const tdTarget = document.createElement("td");
    tdTarget.className = "r mono";
    tdTarget.textContent = fmtNum(g.target, 2);

    const tdRatio = document.createElement("td");
    tdRatio.className = `r mono ${ratioClass(g.ratio)}`;
    tdRatio.textContent = fmtNum(g.ratio, 3);

    const tdPrice = document.createElement("td");
    tdPrice.className = "r mono";
    tdPrice.textContent = fmtNum(g.price, 2);

    const tdValue = document.createElement("td");
    tdValue.className = "r mono";
    tdValue.textContent = fmtNum(g.value, 0);

    const tdTier = document.createElement("td");
    tdTier.textContent = g.tier;

    tr.appendChild(tdName);
    tr.appendChild(tdStock);
    tr.appendChild(tdTarget);
    tr.appendChild(tdRatio);
    tr.appendChild(tdPrice);
    tr.appendChild(tdValue);
    tr.appendChild(tdTier);

    frag.appendChild(tr);
  }

  UI.goods.appendChild(frag);

  const b = (p.buildings || [])
    .map((x) => `${x.type}  x${x.count}  eff=${fmtNum(x.efficiency, 2)}`)
    .join("\n");
  UI.buildings.textContent = b || "—";
}

async function loadMeta() {
  META = await api("/api/meta");

  UI.seed.value = String(META.config?.seed ?? 1);
  UI.transport.value = String(META.config?.transportUnitCost ?? 0.35);
  UI.friction.value = String(META.config?.tradeFriction ?? 0.05);

  renderProvinceList();
}

async function refreshSummary() {
  const sum = await api("/api/summary");
  renderSummary(sum);
}

async function selectProvince(pid) {
  SELECTED_PID = pid;
  renderProvinceList();

  const tier = UI.tier.value;
  const sort = UI.sortGoods.value;
  const limit = UI.limit.value;
  const active = UI.onlyActive && UI.onlyActive.checked ? '1' : '0';

  const p = await api(`/api/province?pid=${encodeURIComponent(pid)}&tier=${encodeURIComponent(tier)}&sort=${encodeURIComponent(sort)}&limit=${encodeURIComponent(limit)}&active=${encodeURIComponent(active)}`);
  renderProvinceDetail(p);
}

async function tickOnce() {
  const years = Number.parseInt(UI.stepYears.value || "1", 10);
  const sum = await api(`/api/tick?years=${encodeURIComponent(years)}`);
  renderSummary(sum);
  await refreshTradeBalance();
  if (SELECTED_PID != null) await selectProvince(SELECTED_PID);
}

async function reset() {
  const seed = Number.parseInt(UI.seed.value || "1", 10);
  const transportUnitCost = Number.parseFloat(UI.transport.value || "0.35");
  const tradeFriction = Number.parseFloat(UI.friction.value || "0.05");

  const sum = await api(`/api/reset?seed=${encodeURIComponent(seed)}&transportUnitCost=${encodeURIComponent(transportUnitCost)}&tradeFriction=${encodeURIComponent(tradeFriction)}`);
  renderSummary(sum);
  await refreshTradeBalance();
  await loadMeta();
  if (SELECTED_PID != null) await selectProvince(SELECTED_PID);
}

function toggleRun() {
  if (RUN_TIMER) {
    clearInterval(RUN_TIMER);
    RUN_TIMER = null;
    UI.btnRun.textContent = "Пуск";
    return;
  }

  UI.btnRun.textContent = "Стоп";
  RUN_TIMER = setInterval(() => {
    tickOnce().catch((e) => console.error(e));
  }, 700);
}

UI.search.addEventListener("input", () => renderProvinceList());
UI.sort.addEventListener("change", () => renderProvinceList());

UI.tier.addEventListener("change", () => {
  if (SELECTED_PID != null) selectProvince(SELECTED_PID);
});
UI.sortGoods.addEventListener("change", () => {
  if (SELECTED_PID != null) selectProvince(SELECTED_PID);
});
UI.limit.addEventListener("change", () => {
  if (SELECTED_PID != null) selectProvince(SELECTED_PID);
});

UI.onlyActive.addEventListener("change", () => {
  if (SELECTED_PID != null) selectProvince(SELECTED_PID);
});

UI.btnTick.addEventListener("click", () => tickOnce().catch((e) => alert(e.message)));
UI.btnReset.addEventListener("click", () => reset().catch((e) => alert(e.message)));
UI.btnRun.addEventListener("click", () => toggleRun());
UI.tabMarket.addEventListener("click", () => setActiveTab("market"));
UI.tabTradeBalance.addEventListener("click", () => {
  setActiveTab("trade");
  refreshTradeBalance().catch((e) => alert(e.message));
});

(async function init() {
  try {
    await loadMeta();
    await refreshSummary();
    await refreshTradeBalance();
    setActiveTab(ACTIVE_TAB);

    // автоселект: первая CITY, иначе первая провинция
    const firstCity = META.provinces.find((p) => p.isCity);
    const first = firstCity || META.provinces[0];
    if (first) await selectProvince(first.pid);
  } catch (e) {
    console.error(e);
    alert(e.message || String(e));
  }
})();
