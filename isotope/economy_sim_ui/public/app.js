const el = (id) => document.getElementById(id);

const UI = {
  seed: el("seed"),
  stepYears: el("stepYears"),
  transport: el("transport"),
  friction: el("friction"),
  btnReset: el("btnReset"),
  btnTick: el("btnTick"),
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
let ACTIVE_TAB = "market";

function fmtInt(x) { return Number(x || 0).toLocaleString("ru-RU"); }
function fmtNum(x, digits = 2) {
  const v = Number(x || 0);
  if (!Number.isFinite(v)) return "0";
  return v.toLocaleString("ru-RU", { maximumFractionDigits: digits, minimumFractionDigits: 0 });
}

async function api(action, params = {}) {
  const url = new URL("api.php", window.location.href);
  url.searchParams.set("action", action);
  Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, String(v)));
  const r = await fetch(url, { cache: "no-store" });
  const data = await r.json();
  if (!r.ok || !data.ok) throw new Error(data.message || data.error || `HTTP ${r.status}`);
  return data.data;
}

function renderSummary(sum) {
  UI.day.textContent = String(sum.day ?? 0);
  UI.popTotal.textContent = fmtInt(sum.popTotal ?? 0);
  UI.topGDP.textContent = (sum.topGDP || []).map((x, i) => `#${i + 1}  ${x.pid}  ${x.name}  | gdp=${fmtInt(x.gdp)}  pop=${fmtInt(x.pop)}  infra=${fmtNum(x.infra, 2)}`).join("\n") || "—";
  UI.scarce.textContent = (sum.scarce || []).map((x) => `↓ ${x.commodity}  ratio=${fmtNum(x.ratio, 3)}  base=${fmtNum(x.basePrice, 0)}`).join("\n") || "—";
  UI.cheap.textContent = (sum.cheap || []).map((x) => `↑ ${x.commodity}  ratio=${fmtNum(x.ratio, 3)}  base=${fmtNum(x.basePrice, 0)}`).join("\n") || "—";
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
  if (q) arr = arr.filter((p) => (p.name || "").toLowerCase().includes(q) || (p.terrain || "").toLowerCase().includes(q) || String(p.pid).includes(q));
  arr.sort((a, b) => {
    const ka = provinceSortKey(a, mode); const kb = provinceSortKey(b, mode);
    if (typeof ka === "string") return ka.localeCompare(kb);
    return ka - kb;
  });

  UI.provList.innerHTML = "";
  const frag = document.createDocumentFragment();
  for (const p of arr) {
    const div = document.createElement("div");
    div.className = "item" + (p.pid === SELECTED_PID ? " active" : "");
    const top = document.createElement("div"); top.className = "itemTop";
    const name = document.createElement("div"); name.className = "itemName"; name.textContent = `${p.pid} — ${p.name}`;
    const badge = document.createElement("div"); badge.className = "badge" + (p.isCity ? " city" : ""); badge.textContent = p.isCity ? "CITY" : (p.terrain || "").slice(0, 16);
    const meta = document.createElement("div"); meta.className = "itemMeta"; meta.textContent = `pop ${fmtInt(p.pop)} · infra ${fmtNum(p.infra, 2)} · hex ${fmtInt(p.hex_count)}`;
    top.appendChild(name); top.appendChild(badge); div.appendChild(top); div.appendChild(meta);
    div.addEventListener("click", () => selectProvince(p.pid));
    frag.appendChild(div);
  }
  UI.provList.appendChild(frag);
}

function ratioClass(ratio) { if (!Number.isFinite(ratio)) return ""; if (ratio < 0.8) return "ratioBad"; if (ratio > 1.3) return "ratioGood"; return ""; }

function renderTradeBalance(tb) {
  UI.tbPeriod.textContent = String(tb.periodDays ?? 0);
  UI.tradeBalance.innerHTML = "";
  const rows = [...(tb.rows || [])].sort((a, b) => Math.abs(b.saldo) - Math.abs(a.saldo));
  const frag = document.createDocumentFragment();
  for (const r of rows) {
    const tr = document.createElement("tr");
    tr.innerHTML = `<td>${r.name}</td><td class="r mono">${fmtNum(r.produced,2)}</td><td class="r mono">${fmtNum(r.sold,2)}</td><td class="r mono ${r.saldo>=0?"ratioGood":"ratioBad"}">${fmtNum(r.saldo,2)}</td><td class="r mono">${fmtNum(r.stock,2)}</td><td>${r.tier}</td>`;
    frag.appendChild(tr);
  }
  UI.tradeBalance.appendChild(frag);
}

function setActiveTab(tab) {
  ACTIVE_TAB = tab;
  UI.tabMarket.classList.toggle("active", tab === "market");
  UI.tabTradeBalance.classList.toggle("active", tab === "trade");
  document.querySelectorAll(".tabPanel").forEach((panel) => panel.classList.toggle("hidden", panel.getAttribute("data-tab") !== tab));
}

function renderProvinceDetail(p) {
  UI.provHeader.textContent = `${p.pid} — ${p.name}`;
  UI.p_pid.textContent = String(p.pid);
  UI.p_terrain.textContent = p.terrain || "";
  UI.p_city.textContent = p.isCity ? "yes" : "no";
  UI.p_pop.textContent = fmtInt(p.pop);
  UI.p_infra.textContent = fmtNum(p.infra, 2);
  UI.p_transport.textContent = `${fmtNum(p.transportUsed, 1)} / ${fmtNum(p.transportCap, 1)}`;
  UI.p_gdp.textContent = fmtInt(p.gdpTurnover);

  UI.goods.innerHTML = "";
  const frag = document.createDocumentFragment();
  for (const g of p.commodities || []) {
    const tr = document.createElement("tr");
    tr.innerHTML = `<td>${g.name}</td><td class="r mono">${fmtNum(g.stock,2)}</td><td class="r mono">${fmtNum(g.target,2)}</td><td class="r mono ${ratioClass(g.ratio)}">${fmtNum(g.ratio,3)}</td><td class="r mono">${fmtNum(g.price,2)}</td><td class="r mono">${fmtNum(g.value,0)}</td><td>${g.tier}</td>`;
    frag.appendChild(tr);
  }
  UI.goods.appendChild(frag);
  UI.buildings.textContent = (p.buildings || []).map((x) => `${x.type}  x${x.count}  eff=${fmtNum(x.efficiency, 2)}`).join("\n") || "—";
}

async function loadMeta() {
  META = await api("meta");
  UI.seed.value = String(META.config?.seed ?? 1);
  UI.transport.value = String(META.config?.transportUnitCost ?? 0.35);
  UI.friction.value = String(META.config?.tradeFriction ?? 0.05);
  renderProvinceList();
}
async function refreshSummary() { renderSummary(await api("summary")); }
async function refreshTradeBalance() { renderTradeBalance(await api("trade-balance")); }

async function selectProvince(pid) {
  SELECTED_PID = pid; renderProvinceList();
  const p = await api("province", {
    pid,
    tier: UI.tier.value,
    sort: UI.sortGoods.value,
    limit: UI.limit.value,
    active: UI.onlyActive.checked ? 1 : 0,
  });
  renderProvinceDetail(p);
}

async function tickOnce() {
  renderSummary(await api("tick", { years: Number.parseInt(UI.stepYears.value || "1", 10) }));
  await refreshTradeBalance();
  if (SELECTED_PID != null) await selectProvince(SELECTED_PID);
}

async function reset() {
  renderSummary(await api("reset", {
    seed: Number.parseInt(UI.seed.value || "1", 10),
    transportUnitCost: Number.parseFloat(UI.transport.value || "0.35"),
    tradeFriction: Number.parseFloat(UI.friction.value || "0.05"),
  }));
  await refreshTradeBalance();
  await loadMeta();
  if (SELECTED_PID != null) await selectProvince(SELECTED_PID);
}

UI.search.addEventListener("input", renderProvinceList);
UI.sort.addEventListener("change", renderProvinceList);
UI.tier.addEventListener("change", () => SELECTED_PID != null && selectProvince(SELECTED_PID));
UI.sortGoods.addEventListener("change", () => SELECTED_PID != null && selectProvince(SELECTED_PID));
UI.limit.addEventListener("change", () => SELECTED_PID != null && selectProvince(SELECTED_PID));
UI.onlyActive.addEventListener("change", () => SELECTED_PID != null && selectProvince(SELECTED_PID));
UI.btnTick.addEventListener("click", () => tickOnce().catch((e) => alert(e.message)));
UI.btnReset.addEventListener("click", () => reset().catch((e) => alert(e.message)));
UI.tabMarket.addEventListener("click", () => setActiveTab("market"));
UI.tabTradeBalance.addEventListener("click", () => { setActiveTab("trade"); refreshTradeBalance().catch((e) => alert(e.message)); });

(async function init() {
  try {
    await loadMeta();
    await refreshSummary();
    await refreshTradeBalance();
    setActiveTab(ACTIVE_TAB);
    const firstCity = META.provinces.find((p) => p.isCity);
    const first = firstCity || META.provinces[0];
    if (first) await selectProvince(first.pid);
  } catch (e) {
    console.error(e);
    alert(e.message || String(e));
  }
})();
