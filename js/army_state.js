(function () {
  "use strict";

  const REALM_TYPES = ["kingdoms", "great_houses", "minor_houses", "free_cities", "special_territories"];

  function sanitizeUnits(list) {
    if (!Array.isArray(list)) return [];
    const out = [];
    for (const row of list) {
      if (!row || typeof row !== "object") continue;
      const size = Math.max(0, Math.floor(Number(row.size) || 0));
      if (size <= 0) continue;
      out.push({
        unit_id: String(row.unit_id || "").trim(),
        source: String(row.source || "").trim(),
        size,
      });
    }
    return out;
  }

  function realmDefaultArmyPid(state, type, id, realm) {
    const fieldByType = { kingdoms: "kingdom_id", great_houses: "great_house_id", minor_houses: "minor_house_id", free_cities: "free_city_id", special_territories: "special_territory_id" };
    const field = fieldByType[type] || "";
    let capitalPid = Number(realm && realm.capital_pid) >>> 0;
    if (!capitalPid && Array.isArray(realm && realm.province_pids) && realm.province_pids.length > 0) capitalPid = Number(realm.province_pids[0]) >>> 0;
    if (!capitalPid && field) {
      for (const pd of Object.values((state && state.provinces) || {})) {
        if (!pd || typeof pd !== "object") continue;
        if (String(pd[field] || "").trim() !== String(id || "").trim()) continue;
        capitalPid = Number(pd.pid) >>> 0;
        if (capitalPid) break;
      }
    }
    return capitalPid;
  }

  function buildFromRealms(state, prevByUid, turnYear) {
    const out = [];
    for (const type of REALM_TYPES) {
      const bucket = state && state[type] && typeof state[type] === "object" ? state[type] : {};
      for (const [id, realm] of Object.entries(bucket)) {
        if (!realm || typeof realm !== "object") continue;
        const isCallupActive = !!realm.arrierban_active;
        const defaultPid = realmDefaultArmyPid(state, type, id, realm);
        const realmName = String(realm.name || id || "");

        const domainUnits = sanitizeUnits(realm.arrierban_units || []);
        if (domainUnits.length) {
          const uid = `${type}:${id}:domain`;
          const prev = prevByUid.get(uid) || {};
          const prevTurn = Number(prev.moved_turn_year);
          out.push({
            army_uid: uid,
            realm_type: type,
            realm_id: String(id),
            realm_name: realmName,
            army_kind: "domain",
            army_id: "domain",
            army_name: "Доменная армия",
            callup_state: isCallupActive ? "active" : "dismissed",
            muster_pid: Number(defaultPid) >>> 0,
            current_pid: Number(prev.current_pid || defaultPid) >>> 0,
            moved_this_turn: prevTurn === Number(turnYear) ? !!prev.moved_this_turn : false,
            moved_turn_year: prevTurn === Number(turnYear) ? prevTurn : null,
            units: domainUnits,
            strength_total: domainUnits.reduce((s, u) => s + (Number(u.size) || 0), 0),
          });
        }

        const feudalArmies = Array.isArray(realm.arrierban_vassal_armies) ? realm.arrierban_vassal_armies : [];
        if (feudalArmies.length) {
          feudalArmies.forEach((army, idx) => {
            const units = sanitizeUnits(army && army.units || []);
            if (!units.length) return;
            const armyId = String((army && army.army_id) || `feudal_${idx + 1}`);
            const uid = `${type}:${id}:${armyId}`;
            const prev = prevByUid.get(uid) || {};
            const prevTurn = Number(prev.moved_turn_year);
            const musterPid = Number((army && army.muster_pid) || defaultPid) >>> 0;
            out.push({
              army_uid: uid,
              realm_type: type,
              realm_id: String(id),
              realm_name: realmName,
              army_kind: String((army && army.army_kind) || "vassal"),
              army_id: armyId,
              army_name: String((army && army.army_name) || armyId),
              callup_state: isCallupActive ? "active" : "dismissed",
              muster_pid: musterPid,
              current_pid: Number(prev.current_pid || musterPid || defaultPid) >>> 0,
              moved_this_turn: prevTurn === Number(turnYear) ? !!prev.moved_this_turn : false,
              moved_turn_year: prevTurn === Number(turnYear) ? prevTurn : null,
              units,
              strength_total: units.reduce((s, u) => s + (Number(u.size) || 0), 0),
            });
          });
        } else {
          const legacyUnits = sanitizeUnits(realm.arrierban_vassal_units || []);
          if (legacyUnits.length) {
            const uid = `${type}:${id}:feudal_legacy`;
            const prev = prevByUid.get(uid) || {};
            const prevTurn = Number(prev.moved_turn_year);
            out.push({
              army_uid: uid,
              realm_type: type,
              realm_id: String(id),
              realm_name: realmName,
              army_kind: "vassal",
              army_id: "feudal_legacy",
              army_name: "Феодальная армия",
              callup_state: isCallupActive ? "active" : "dismissed",
              muster_pid: Number(defaultPid) >>> 0,
              current_pid: Number(prev.current_pid || defaultPid) >>> 0,
              moved_this_turn: prevTurn === Number(turnYear) ? !!prev.moved_this_turn : false,
              moved_turn_year: prevTurn === Number(turnYear) ? prevTurn : null,
              units: legacyUnits,
              strength_total: legacyUnits.reduce((s, u) => s + (Number(u.size) || 0), 0),
            });
          }
        }
      }
    }
    return out.filter((row) => Number(row.current_pid) > 0);
  }

  function ensureRegistry(state, opts) {
    if (!state || typeof state !== "object") return [];
    const turnYear = Number(opts && opts.turnYear);
    const prev = Array.isArray(state.army_registry) ? state.army_registry : [];
    const prevByUid = new Map();
    for (const row of prev) {
      if (!row || typeof row !== "object") continue;
      const uid = String(row.army_uid || row.war_army_id || "").trim();
      if (!uid) continue;
      prevByUid.set(uid, row);
    }
    state.army_registry = buildFromRealms(state, prevByUid, turnYear);
    return state.army_registry;
  }

  window.AdminMapArmyState = { ensureRegistry, sanitizeUnits };
})();
