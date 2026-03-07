(function () {
  "use strict";

  const ENTITY_TYPES = [
    { value: "great_houses", label: "Большие Дома" },
    { value: "minor_houses", label: "Малые Дома" },
    { value: "free_cities", label: "Вольные Города" },
    { value: "special_territories", label: "Особые Территории" },
  ];

  const $ = (id) => document.getElementById(id);
  const fmt = (n) => Number.isFinite(Number(n)) ? new Intl.NumberFormat("ru-RU").format(Number(n)) : "—";
  const esc = (s) => String(s || "").replace(/[&<>"']/g, (c) => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[c]));

  const refs = {
    entityType: $("entityType"),
    entityId: $("entityId"),
    reloadBtn: $("reloadBtn"),
    entityName: $("entityName"),
    entitySubtitle: $("entitySubtitle"),
    entityEmblem: $("entityEmblem"),
    kpis: $("kpis"),
    wikiLink: $("wikiLink"),
    genealogyLink: $("genealogyLink"),
    rulerPhoto: $("rulerPhoto"),
    rulerName: $("rulerName"),
    rulerYears: $("rulerYears"),
    hierarchy: $("hierarchy"),
    treasuryBars: $("treasuryBars"),
    treasuryStatus: $("treasuryStatus"),
    armyTable: $("armyTable"),
    provinceStats: $("provinceStats"),
    openPlayerAdmin: $("openPlayerAdmin"),
  };

  const state = { world: null, types: {}, entities: {}, byName: new Map() };

  function svgToDataUri(svg) {
    const raw = String(svg || "").trim();
    if (!raw) return "";
    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(raw)}`;
  }

  async function fetchJson(url) {
    const res = await fetch(url, { cache: "no-store", headers: { Accept: "application/json" } });
    if (!res.ok) throw new Error(`HTTP ${res.status}: ${url}`);
    return res.json();
  }

  function populateSelectors() {
    refs.entityType.innerHTML = ENTITY_TYPES.map((t) => `<option value="${t.value}">${t.label}</option>`).join("");
    const params = new URLSearchParams(location.search);
    const qType = params.get("type") || "great_houses";
    if (ENTITY_TYPES.some((t) => t.value === qType)) refs.entityType.value = qType;
    rebuildEntitySelect(params.get("id") || "");
  }

  function rebuildEntitySelect(wantedId) {
    const type = refs.entityType.value;
    const bucket = state.world?.[type] || {};
    const entries = Object.entries(bucket)
      .map(([id, row]) => ({ id, name: String(row?.name || id) }))
      .sort((a, b) => a.name.localeCompare(b.name, "ru"));
    refs.entityId.innerHTML = entries.map((r) => `<option value="${esc(r.id)}">${esc(r.name)}</option>`).join("");
    if (wantedId && entries.some((r) => r.id === wantedId)) refs.entityId.value = wantedId;
    if (!refs.entityId.value && entries[0]) refs.entityId.value = entries[0].id;
  }

  function calcProvinceStats(type, id) {
    const all = Object.values(state.world?.provinces || {});
    const field = ({ great_houses: "great_house_id", minor_houses: "minor_house_id", free_cities: "free_city_id", special_territories: "special_territory_id" })[type];
    const owned = all.filter((p) => String(p?.[field] || "") === id);
    const pop = owned.reduce((s, p) => s + Number(p.population || 0), 0);
    const tre = owned.reduce((s, p) => s + Number(p.treasury || 0), 0);
    const taxAvg = owned.length ? owned.reduce((s, p) => s + Number(p.tax_rate || 0), 0) / owned.length : 0;
    const terrains = {};
    for (const p of owned) terrains[String(p.terrain || "неизв.")] = (terrains[String(p.terrain || "неизв.")] || 0) + 1;
    const topTerrain = Object.entries(terrains).sort((a, b) => b[1] - a[1])[0] || ["—", 0];

    refs.provinceStats.innerHTML = `
      <tr><th>Провинций</th><td>${fmt(owned.length)}</td></tr>
      <tr><th>Население</th><td>${fmt(pop)}</td></tr>
      <tr><th>Локальная казна</th><td>${fmt(tre)}</td></tr>
      <tr><th>Средний налог</th><td>${taxAvg.toFixed(2)}</td></tr>
      <tr><th>Основной ландшафт</th><td>${esc(topTerrain[0])} (${fmt(topTerrain[1])})</td></tr>
    `;

    return { ownedCount: owned.length, pop, tre };
  }

  async function renderHierarchy(type, id) {
    refs.hierarchy.innerHTML = "<div class='muted'>Загрузка иерархии…</div>";
    try {
      const data = await fetchJson(`/api/wiki/show/?kind=entity&entity_type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`);
      const item = data?.item || {};
      const parent = item?.entity?.parent_entity;
      const kids = Array.isArray(item?.entity?.children?.entities) ? item.entity.children.entities : [];
      const provinces = Array.isArray(item?.entity?.children?.provinces) ? item.entity.children.provinces : [];
      const rows = [];
      if (parent) rows.push(`<div class='hier__row'><span class='muted'>Выше вас:</span><span class='chip'>${esc(parent.name)}</span></div>`);
      rows.push(`<div class='hier__row'><span class='muted'>Ниже вас:</span>${kids.length ? kids.slice(0, 5).map((k) => `<span class='chip'>${esc(k.name)}</span>`).join("") : "<span class='chip'>нет прямых вассалов</span>"}</div>`);
      rows.push(`<div class='hier__row'><span class='muted'>Провинции:</span><span class='chip'>${fmt(provinces.length)}</span></div>`);
      refs.hierarchy.innerHTML = rows.join("");
      refs.wikiLink.href = `/wiki/?kind=entity&entity_type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`;
    } catch (err) {
      refs.hierarchy.innerHTML = `<div class='muted'>Иерархия недоступна: ${esc(err.message)}</div>`;
    }
  }

  async function renderTreasury(type, id) {
    refs.treasuryBars.innerHTML = "";
    refs.treasuryStatus.textContent = "Загрузка истории treasury…";
    try {
      const list = await fetchJson("/api/turns/?published_only=1");
      const years = (list.items || []).map((x) => Number(x.year)).filter((n) => Number.isFinite(n)).slice(-8);
      const rows = [];
      for (const y of years) {
        const turn = await fetchJson(`/api/turns/show/?year=${y}&include=treasury`);
        const treRows = turn?.treasury?.entity_treasury?.rows || turn?.entity_treasury?.rows || [];
        const one = treRows.find((r) => String(r.entity_type || "") === type && String(r.entity_id || "") === id);
        if (!one) continue;
        rows.push({ year: y, closing: Number(one.closing_balance || 0), income: Number(one.income_total || 0) });
      }
      if (!rows.length) throw new Error("нет опубликованных данных treasury для сущности");
      const maxIncome = Math.max(...rows.map((r) => r.income), 1);
      refs.treasuryBars.innerHTML = rows.map((r) => `<div class='bar'><b>${r.year}</b><i><span style='width:${Math.max(3, Math.round(r.income / maxIncome * 100))}%'></span></i><span>+${fmt(r.income)} / ${fmt(r.closing)}</span></div>`).join("");
      refs.treasuryStatus.textContent = "Показаны доход и итог казны по опубликованным ходам.";
    } catch (err) {
      refs.treasuryStatus.textContent = `История treasury недоступна (${err.message}). Показаны текущие значения по провинциям.`;
      const p = calcProvinceStats(refs.entityType.value, refs.entityId.value);
      refs.treasuryBars.innerHTML = `<div class='bar'><b>текущ.</b><i><span style='width:100%'></span></i><span>${fmt(p.tre)}</span></div>`;
    }
  }

  function renderArmies(type, id) {
    const armies = (state.world?.army_registry || []).filter((a) => String(a?.realm_type || "") === type && String(a?.realm_id || "") === id);
    if (!armies.length) {
      refs.armyTable.innerHTML = "<tr><td colspan='4' class='muted'>Армии не найдены. Можно созвать через кнопку «Созвать/распустить».</td></tr>";
      return;
    }
    refs.armyTable.innerHTML = armies.map((a) => {
      const units = Array.isArray(a.units) ? a.units : [];
      const men = units.reduce((s, u) => s + Number(u.count || u.size || 0), 0);
      return `<tr><td>${esc(a.army_name || a.army_uid || "Армия")}</td><td>${fmt(a.pid || 0)}</td><td>${fmt(units.length)}</td><td>${fmt(men)}</td></tr>`;
    }).join("");
  }

  async function resolveRulerProfile(rulerName) {
    if (!rulerName) return null;
    try {
      const g = await fetchJson("/api/genealogy/");
      const chars = Array.isArray(g.characters) ? g.characters : [];
      const pick = chars.find((c) => String(c.full_name || c.name || "").trim().toLowerCase() === rulerName.trim().toLowerCase())
        || chars.find((c) => String(c.full_name || c.name || "").toLowerCase().includes(rulerName.trim().toLowerCase()));
      return pick || null;
    } catch (_) {
      return null;
    }
  }

  async function render() {
    const type = refs.entityType.value;
    const id = refs.entityId.value;
    const realm = state.world?.[type]?.[id] || {};
    const name = String(realm.name || id);
    const ruler = String(realm.ruler || "Не указан");

    const url = new URL(location.href);
    url.searchParams.set("type", type);
    url.searchParams.set("id", id);
    history.replaceState(null, "", url);

    refs.entityName.textContent = name;
    refs.entitySubtitle.textContent = `${ENTITY_TYPES.find((t) => t.value === type)?.label || type} · ID: ${id}`;
    refs.entityEmblem.src = svgToDataUri(realm.emblem_svg);
    refs.rulerName.textContent = ruler;
    refs.rulerYears.textContent = "Годы жизни: —";
    refs.openPlayerAdmin.href = `/player_admin.html?entity_type=${encodeURIComponent(type)}&entity_id=${encodeURIComponent(id)}`;

    const p = calcProvinceStats(type, id);
    refs.kpis.innerHTML = `<span class='pill'>Провинций: ${fmt(p.ownedCount)}</span><span class='pill'>Население: ${fmt(p.pop)}</span><span class='pill'>Казна провинций: ${fmt(p.tre)}</span>`;

    const profile = await resolveRulerProfile(ruler);
    if (profile) {
      refs.rulerName.textContent = String(profile.full_name || profile.name || ruler);
      const born = profile.birth_year ?? profile.born_year ?? profile.born;
      const died = profile.death_year ?? profile.died_year ?? profile.died;
      refs.rulerYears.textContent = `Годы жизни: ${born ?? "?"} — ${died ?? "…"}`;
      const photo = String(profile.photo || profile.avatar || "");
      if (photo) refs.rulerPhoto.src = /^https?:|^\/api\//.test(photo) ? photo : `/api/genealogy/photo/?url=${encodeURIComponent(photo)}`;
      if (profile.id) refs.genealogyLink.href = `/genealogy_8gen_example.html?focus=${encodeURIComponent(profile.id)}`;
    }

    await Promise.all([renderHierarchy(type, id), renderTreasury(type, id)]);
    renderArmies(type, id);
  }

  async function bootstrap() {
    refs.reloadBtn.disabled = true;
    try {
      const loaded = await window.AdminMapStateLoader.loadStateBackendOnly();
      state.world = loaded.state || {};
      populateSelectors();
      await render();
      refs.entityType.addEventListener("change", () => { rebuildEntitySelect(""); render(); });
      refs.entityId.addEventListener("change", render);
      refs.reloadBtn.addEventListener("click", render);
    } catch (err) {
      refs.entityName.textContent = "Ошибка загрузки";
      refs.entitySubtitle.textContent = String(err && err.message ? err.message : err);
    } finally {
      refs.reloadBtn.disabled = false;
    }
  }

  bootstrap();
})();
