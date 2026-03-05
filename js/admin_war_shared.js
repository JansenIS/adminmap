/* shared arrierban modal mechanics extracted from admin war panel */
(function () {
  "use strict";

  function unitCategoryLabel(source) {
    const map = { militia: "Ополчение", sergeants: "Сержанты", nehts: "Нехты", knights: "Рыцари" };
    return map[String(source || "")] || "Прочее";
  }

  function resolveUnitCategory(unit) {
    const source = String(unit && unit.source || "");
    if (["militia", "sergeants", "nehts", "knights"].includes(source)) return source;
    const byId = { militia: "militia", militia_tr: "militia", shot: "sergeants", pikes: "sergeants", assault150: "sergeants", bikes: "nehts", dragoons: "nehts", ulans: "nehts", foot_nehts: "nehts", palatines: "knights", preventors100: "knights", foot_knights: "knights", moto_knights: "knights" };
    return byId[String(unit && unit.unit_id || "")] || "other";
  }

  function createArrierbanModalController(opts) {
    const o = opts || {};
    const categories = ["militia", "sergeants", "nehts", "knights"];

    function getPlan() { return typeof o.getPlan === "function" ? o.getPlan() : null; }
    function setPlan(v) { if (typeof o.setPlan === "function") o.setPlan(v); }

    function toPlanCalc(plan) {
      if (!plan || typeof plan !== "object") return { pools: {} };
      if (plan.calc && typeof plan.calc === "object") return plan.calc;
      return {
        pools: Object.assign({}, plan.pools || {}),
        realm: { ruler: String(plan.ruler || "") },
        domainPids: Array.isArray(plan.domain_pids) ? plan.domain_pids : [],
        supportingArmies: Number(plan.supporting_armies) || 0,
      };
    }

    function toDomainDefs(plan) {
      if (Array.isArray(plan && plan.domainDefs)) return plan.domainDefs;
      if (Array.isArray(plan && plan.domain_defs)) return plan.domain_defs.map((def) => ({
        id: def.id,
        name: def.name || def.id,
        source: def.source,
        baseSize: Math.max(1, Number(def.baseSize || def.base_size) || 1),
      }));
      if (Array.isArray(plan && plan.domain_unit_defs)) return plan.domain_unit_defs.map((def) => ({
        id: def.id,
        name: def.name || def.id,
        source: def.source,
        baseSize: Math.max(1, Number(def.baseSize || def.base_size) || 1),
      }));
      return [];
    }

    function toCatalog(plan) { return (plan && (plan.catalog || plan.unit_catalog)) || {}; }

    function formatRemainingPools(calc, allocations) {
      return categories.map((key) => {
        const cap = Math.max(0, Math.floor(Number(calc && calc.pools && calc.pools[key]) || 0));
        const used = Math.max(0, Math.floor(Number(allocations && allocations[key]) || 0));
        return `${unitCategoryLabel(key)}: ${Math.max(0, cap - used)} из ${cap}`;
      }).join(", ");
    }

    function createArrierbanUnitInput(def, value) {
      const wrapper = document.createElement("div");
      wrapper.className = "arrierban-row-alloc__entry";
      wrapper.innerHTML = `<input type="number" min="0" step="1" value="${Math.max(0, Math.floor(Number(value) || 0))}" data-unit-id="${def.id}" data-source="${def.source}" data-base-size="${def.baseSize}" /><button type="button" class="arrierban-row-alloc__remove" data-action="remove-arrierban-row" title="Удалить отряд">−</button>`;
      return wrapper;
    }

    function buildArrierbanDomainRow(def) {
      const row = document.createElement("div");
      row.className = "arrierban-grid";
      const minSize = Math.max(1, Math.ceil(def.baseSize * 0.1));

      const allocWrap = document.createElement("div");
      allocWrap.className = "arrierban-row-alloc";
      allocWrap.dataset.unitId = def.id;
      allocWrap.dataset.source = def.source;
      allocWrap.dataset.baseSize = String(def.baseSize);
      allocWrap.appendChild(createArrierbanUnitInput(def, 0));

      const addBtn = document.createElement("button");
      addBtn.type = "button";
      addBtn.className = "arrierban-row-alloc__add";
      addBtn.dataset.action = "add-arrierban-row";
      addBtn.textContent = "+ Добавить отряд";
      allocWrap.appendChild(addBtn);

      row.appendChild(Object.assign(document.createElement("div"), { textContent: def.name }));
      row.appendChild(Object.assign(document.createElement("div"), { textContent: unitCategoryLabel(def.source) }));
      row.appendChild(Object.assign(document.createElement("div"), { textContent: String(def.baseSize) }));
      row.appendChild(Object.assign(document.createElement("div"), { textContent: String(minSize) }));
      row.appendChild(allocWrap);
      return row;
    }

    function open(plan, mode) {
      if (!o.modal || !o.rows) return;
      const prepared = plan ? Object.assign({}, plan) : {};
      if (mode && !prepared.mode) prepared.mode = mode;
      setPlan(prepared);
      const calc = toPlanCalc(prepared);
      const ruler = String((calc.realm && calc.realm.ruler) || prepared.ruler || "").trim() || "Без правителя";
      if (o.title) o.title.textContent = `Арьербан — ${ruler}`;
      if (o.subtitle) o.subtitle.textContent = prepared.royalKingdomId
        ? `Королевский призыв по ${prepared.royalKingdomId}: доменных провинций ${(calc.domainPids || []).length}, вассальных армий ${calc.supportingArmies || 0}`
        : (prepared.domainOnly ? `Доменных провинций: ${(calc.domainPids || []).length}, без созыва вассалов` : `Доменных провинций: ${(calc.domainPids || []).length}, доп. вассальных армий: ${calc.supportingArmies || 0}`);
      if (o.pools) o.pools.textContent = `Пулы доменного призыва: рыцари ${(calc.pools && calc.pools.knights) || 0}, нехты ${(calc.pools && calc.pools.nehts) || 0}, сержанты ${(calc.pools && calc.pools.sergeants) || 0}, ополчение ${(calc.pools && calc.pools.militia) || 0}.`;
      if (o.remaining) o.remaining.textContent = `Нераспределено: ${formatRemainingPools(calc, null)}.`;
      if (o.validation) o.validation.textContent = "";

      o.rows.innerHTML = "";
      const defs = toDomainDefs(prepared);
      for (const category of categories) {
        const bucket = defs.filter((def) => def.source === category);
        if (!bucket.length) continue;
        const header = document.createElement("div");
        header.className = "arrierban-category";
        header.innerHTML = `<div class="arrierban-category__title">${unitCategoryLabel(category)}</div>`;
        o.rows.appendChild(header);
        for (const def of bucket) {
          const row = buildArrierbanDomainRow(def);
          const input = row.querySelector('input[type="number"]');
          const found = Array.isArray(prepared.default_units) ? prepared.default_units.find((u) => String(u && u.unit_id || "") === String(def.id || "")) : null;
          if (input && found) input.value = String(Math.max(0, Math.floor(Number(found.size) || 0)));
          o.rows.appendChild(row);
        }
      }

      o.modal.classList.add("open");
      o.modal.setAttribute("aria-hidden", "false");
    }

    function close() {
      if (!o.modal) return;
      o.modal.classList.remove("open");
      o.modal.setAttribute("aria-hidden", "true");
      setPlan(null);
    }

    function updateRemaining() {
      const plan = getPlan();
      if (!plan || !o.rows || !o.remaining) return;
      const allocations = { militia: 0, sergeants: 0, nehts: 0, knights: 0 };
      const inputs = Array.from(o.rows.querySelectorAll('input[type="number"]'));
      for (const input of inputs) {
        const source = String(input.dataset.source || "");
        if (!(source in allocations)) continue;
        allocations[source] += Math.max(0, Math.floor(Number(input.value) || 0));
      }
      o.remaining.textContent = `Нераспределено: ${formatRemainingPools(toPlanCalc(plan), allocations)}.`;
    }

    function collect() {
      const plan = getPlan();
      if (!plan || !o.rows) return { ok: false, units: [], error: "Нет данных призыва." };
      const calc = toPlanCalc(plan);
      const allocations = { militia: 0, sergeants: 0, nehts: 0, knights: 0 };
      const units = [];
      const inputs = Array.from(o.rows.querySelectorAll('input[type="number"]'));
      const catalog = toCatalog(plan);
      for (const input of inputs) {
        const size = Math.max(0, Math.floor(Number(input.value) || 0));
        if (size <= 0) continue;
        const baseSize = Math.max(1, Number(input.dataset.baseSize) || 1);
        const minSize = Math.max(1, Math.ceil(baseSize * 0.1));
        if (size < minSize) return { ok: false, units: [], error: `Размер отряда ${input.dataset.unitId} меньше минимального (${minSize}).` };
        const source = String(input.dataset.source || "");
        allocations[source] = (allocations[source] || 0) + size;
        const unitCfg = catalog[input.dataset.unitId] || null;
        units.push({ source, unit_id: input.dataset.unitId, unit_name: String(unitCfg && unitCfg.name || input.dataset.unitId), size, base_size: baseSize });
      }
      for (const key of Object.keys(allocations)) {
        const cap = Number(calc && calc.pools && calc.pools[key] || 0);
        if (allocations[key] > cap) return { ok: false, units: [], error: `Превышен лимит для ${unitCategoryLabel(key)}: ${allocations[key]} из ${cap}.` };
      }
      if (o.remaining) o.remaining.textContent = `Нераспределено: ${formatRemainingPools(calc, allocations)}.`;
      return { ok: true, units, allocations };
    }

    function bindEvents() {
      if (o.closeBtn) o.closeBtn.addEventListener("click", close);
      if (o.modal) o.modal.addEventListener("click", (evt) => { if (evt.target === o.modal) close(); });
      if (o.rows) o.rows.addEventListener("click", (evt) => {
        const btn = evt.target && evt.target.closest ? evt.target.closest("button[data-action]") : null;
        if (!btn) return;
        const action = btn.dataset.action;
        const allocWrap = btn.closest('.arrierban-row-alloc');
        if (!allocWrap) return;
        if (action === "add-arrierban-row") {
          const addBtn = allocWrap.querySelector('button[data-action="add-arrierban-row"]');
          const sample = allocWrap.querySelector('input[type="number"]');
          if (!sample || !addBtn) return;
          const entry = createArrierbanUnitInput({ id: sample.dataset.unitId || "", source: sample.dataset.source || "", baseSize: Math.max(1, Number(sample.dataset.baseSize) || 1) }, 0);
          allocWrap.insertBefore(entry, addBtn);
          updateRemaining();
        } else if (action === "remove-arrierban-row") {
          const entry = btn.closest('.arrierban-row-alloc__entry');
          if (!entry) return;
          const entries = allocWrap.querySelectorAll('.arrierban-row-alloc__entry');
          if (entries.length <= 1) {
            const input = entry.querySelector('input[type="number"]');
            if (input) input.value = "0";
          } else {
            entry.remove();
          }
          updateRemaining();
        }
      });
      if (o.rows) o.rows.addEventListener("input", (evt) => {
        if (evt.target && evt.target.matches && evt.target.matches('input[type="number"]')) updateRemaining();
      });
    }

    return { unitCategoryLabel, resolveUnitCategory, open, close, updateRemaining, collect, bindEvents };
  }

  window.AdminWarArrierban = { unitCategoryLabel, resolveUnitCategory, createArrierbanModalController };
})();
