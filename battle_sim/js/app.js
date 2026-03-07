(function(){
  "use strict";

  // Fail early with a readable error if script order is broken.
  if(!window.ENGINE) throw new Error("ENGINE not loaded: ensure js/engine.js loads without errors before js/app.js");
  if(!window.RENDER) throw new Error("RENDER not loaded: ensure js/render.js loads without errors before js/app.js");
  if(!window.DATA) throw new Error("DATA not loaded: ensure js/data.js is loaded before js/app.js");
  if(!window.U) throw new Error("U not loaded: ensure js/utils.js is loaded before js/app.js");

  const {scenario, battle, LOG} = window.ENGINE;
  const E = window.ENGINE;
  const R = window.RENDER;
  const {UNIT_CATALOG} = window.DATA;
  const U = window.U;
  const qs = new URLSearchParams(window.location.search || "");
  let storedBattleToken = "";
  try {
    storedBattleToken = String(localStorage.getItem('battle_sim_token') || sessionStorage.getItem('battle_sim_token') || '').trim();
  } catch(_e) {
    storedBattleToken = "";
  }
  const battleToken = String(qs.get("battle_token") || qs.get("token") || storedBattleToken || "").trim();
  if(battleToken){
    try {
      localStorage.setItem('battle_sim_token', battleToken);
      sessionStorage.setItem('battle_sim_token', battleToken);
    } catch(_e) {}
  }

  const tokenMode = {
    enabled: battleToken !== "",
    side: null,
    ready: false,
    bothReady: false,
    sessionStatus: "setup",
    locked: false,
    lastRealtimeRev: 0,
    emergencyFromArmies: false,
  };

  // --- DOM ---
  const $ = (id)=>document.getElementById(id);

  const el = {
    phaseTag: $("phaseTag"),
    turnTag: $("turnTag"),
    activeTag: $("activeTag"),

    btnStart: $("btnStart"),
    btnNext: $("btnNext"),
    btnReset: $("btnReset"),

    rpBlue: $("rpBlue"),
    rpRed: $("rpRed"),

    unitType: $("unitType"),
    side: $("side"),
    formation: $("formation"),
    count: $("count"),
    baseCount: $("baseCount"),
    baseXpl: $("baseXpl"),
    xpl: $("xpl"),
    name: $("name"),
    addUnit: $("addUnit"),

    selCard: $("selCard"),
    selTitle: $("selTitle"),
    selSide: $("selSide"),
    selMen: $("selMen"),
    selXpl: $("selXpl"),
    selMorale: $("selMorale"),
    selFormation: $("selFormation"),
    btnChangeFormationMove: $("btnChangeFormationMove"),
    selAngle: $("selAngle"),
    selMoraleEdit: $("selMoraleEdit"),
    btnHeal: $("btnHeal"),
    btnKill: $("btnKill"),
    btnRemove: $("btnRemove"),

    sumBlue: $("sumBlue"),
    sumRed: $("sumRed"),
    log: $("log"),
    roster: $("roster"),
    hud: $("hud"),
  };

  // --- Map editor DOM ---
  el.tabBattle = $("tabBattle");
  el.tabMap = $("tabMap");
  el.battlePanel = $("battlePanel");
  el.mapPanel = $("mapPanel");

  el.mapToolSelect = $("mapToolSelect");
  el.mapToolRiver  = $("mapToolRiver");
  el.mapToolHouse  = $("mapToolHouse");
  el.mapToolFort   = $("mapToolFort");
  el.mapToolErase  = $("mapToolErase");

  el.riverWidth = $("riverWidth");
  el.houseW = $("houseW");
  el.houseH = $("houseH");
  el.fortThick = $("fortThick");
  el.towerR = $("towerR");

  el.btnFinishDraw = $("btnFinishDraw");
  el.btnUndoPoint  = $("btnUndoPoint");
  el.btnClearMap   = $("btnClearMap");
  el.btnExportMap  = $("btnExportMap");
  el.btnImportMap  = $("btnImportMap");
  el.mapJson = $("mapJson");


  const PHASE_NAMES = {
    setup: "подготовка",
    movement: "движение",
    ranged: "стрельба",
    melee: "ближний бой",
    morale: "мораль",
  };

  function sideForTokenPlayer(){
    if(!tokenMode.enabled) return null;
    return tokenMode.side === "A" ? "blue" : (tokenMode.side === "B" ? "red" : null);
  }

  function isOwnUnit(u){
    const ownSide = sideForTokenPlayer();
    if(!ownSide) return true;
    return !!u && u.side === ownSide;
  }

  function deployBandForSide(side){
    const h = Number(scenario.map.h || 1400);
    const hh = h/2;
    const third = h/3;
    if(side === "blue") return { yMin: -hh, yMax: -hh + third };
    if(side === "red") return { yMin: hh - third, yMax: hh };
    return { yMin: -hh, yMax: hh };
  }

  function unitInsideAllowedDeployBand(u){
    if(!tokenMode.enabled || battle.started) return true;
    if(!u) return false;
    const ownSide = sideForTokenPlayer();
    if(!ownSide) return true;
    const side = u.side;
    const band = deployBandForSide(side);
    return Number(u.y) >= band.yMin && Number(u.y) <= band.yMax;
  }

  function clampUnitToDeployBand(u){
    if(!tokenMode.enabled || battle.started || !u) return;
    const band = deployBandForSide(u.side);
    u.y = U.clamp(u.y, band.yMin, band.yMax);
  }


  // --- UI Mode (Battle / Map editor) ---
  let uiMode = "battle"; // "battle" | "map"

  function setUIMode(mode){
    if(tokenMode.enabled && mode==="map") {
      uiMode = "battle";
      E.log("В token-режиме редактор карты отключен.", "mut");
    } else {
      uiMode = (mode==="map") ? "map" : "battle";
    }
    if(el.tabBattle) el.tabBattle.classList.toggle("active", uiMode==="battle");
    if(el.tabMap) el.tabMap.classList.toggle("active", uiMode==="map");
    if(el.battlePanel) el.battlePanel.style.display = (uiMode==="battle") ? "" : "none";
    if(el.mapPanel) el.mapPanel.style.display = (uiMode==="map") ? "" : "none";

    // reset transient editor drag state
    ensureEditor();
    scenario.map._editor.dragging = false;
    scenario.map._editor.drag = null;
    scenario.map._editor.drawing = null;
    refreshAll();
  }

  function num(idEl, def=0){
    const v = Number(idEl && idEl.value);
    return Number.isFinite(v) ? v : def;
  }

  function ensureEditor(){
    if(!scenario.map.objects) scenario.map.objects = {rivers:[], buildings:[], forts:[]};
    if(!scenario.map._editor){
      scenario.map._editor = {
        tool: "select", // select|river|house|fort|erase
        selected: null, // {type,id}
        drawing: null,  // {type, pts:[]}
        dragging:false,
        drag:null,      // {type,id, startWX,startWY, orig}
      };
    }
    return scenario.map._editor;
  }

  function setTool(t){
    const ed = ensureEditor();
    ed.tool = t;
    ed.drawing = null;
    // update button states
    const btns = [
      ["select", el.mapToolSelect],
      ["river",  el.mapToolRiver],
      ["house",  el.mapToolHouse],
      ["fort",   el.mapToolFort],
      ["erase",  el.mapToolErase],
    ];
    for(const [k,b] of btns){
      if(b) b.classList.toggle("active", k===t);
    }
    refreshAll();
  }

  // --- Map object hit testing (screen -> world) ---
  function hitTestMapObject(wx, wy){
    const objs = scenario.map.objects || {rivers:[], buildings:[], forts:[]};

    // buildings (top priority)
    for(let i=(objs.buildings||[]).length-1;i>=0;i--){
      const b = objs.buildings[i];
      if(U.pointInRotRect(wx, wy, b.x, b.y, b.w, b.h, b.angle||0)){
        return {type:"building", id:b.id};
      }
    }

    // forts: near any wall segment (within thickness/2 + towerR)
    for(let i=(objs.forts||[]).length-1;i>=0;i--){
      const f = objs.forts[i];
      const pts = f.pts||[];
      if(pts.length<3) continue;
      const thick = Math.max(2, Number(f.thickness)||16);
      const towerR = Math.max(2, Number(f.towerR)||20);
      const tol = (thick*0.5) + towerR;
      // vertices (towers)
      for(const p of pts){
        if(U.dist(wx,wy,p.x,p.y) <= tol) return {type:"fort", id:f.id};
      }
      // segments
      for(let j=0;j<pts.length;j++){
        const a=pts[j], c=pts[(j+1)%pts.length];
        const d = U.pointSegDist(a.x,a.y,c.x,c.y, wx, wy);
        if(d <= thick*0.55) return {type:"fort", id:f.id};
      }
    }

    // rivers: near any segment (within width/2)
    for(let i=(objs.rivers||[]).length-1;i>=0;i--){
      const r = objs.rivers[i];
      const pts = r.pts||[];
      if(pts.length<2) continue;
      const w = Math.max(2, Number(r.width)||20);
      for(let j=0;j<pts.length-1;j++){
        const a=pts[j], c=pts[j+1];
        const d = U.pointSegDist(a.x,a.y,c.x,c.y, wx, wy);
        if(d <= w*0.6) return {type:"river", id:r.id};
      }
    }

    return null;
  }

  function getObj(ref){
    if(!ref) return null;
    const objs = scenario.map.objects || {};
    if(ref.type==="building") return (objs.buildings||[]).find(o=>o.id===ref.id)||null;
    if(ref.type==="river") return (objs.rivers||[]).find(o=>o.id===ref.id)||null;
    if(ref.type==="fort") return (objs.forts||[]).find(o=>o.id===ref.id)||null;
    return null;
  }

  function deleteObj(ref){
    if(!ref) return;
    const objs = scenario.map.objects || {};
    const list =
      (ref.type==="building") ? (objs.buildings||[]) :
      (ref.type==="river") ? (objs.rivers||[]) :
      (ref.type==="fort") ? (objs.forts||[]) : null;
    if(!list) return;
    const idx = list.findIndex(o=>o.id===ref.id);
    if(idx>=0) list.splice(idx,1);
  }

  function finishDrawing(){
    const ed = ensureEditor();
    if(!ed.drawing) return;
    const d = ed.drawing;
    const objs = scenario.map.objects;

    if(d.type==="river"){
      if(d.pts.length>=2){
        objs.rivers.push({id:U.uid(), pts: d.pts.slice(), width: num(el.riverWidth, 20)});
      }
    }else if(d.type==="fort"){
      if(d.pts.length>=3){
        objs.forts.push({
          id:U.uid(),
          pts: d.pts.slice(),
          thickness: num(el.fortThick, 16),
          towerR: num(el.towerR, 20),
        });
      }
    }
    ed.drawing = null;
    refreshAll();
  }

  function undoPoint(){
    const ed = ensureEditor();
    if(ed.drawing && ed.drawing.pts && ed.drawing.pts.length){
      ed.drawing.pts.pop();
      refreshAll();
    }
  }


  function fmtX(v){ return (Math.round(v*100)/100).toFixed(2); }
  function sideName(s){ return s==="blue" ? "Синие" : "Красные"; }

  // --- Populate unit type select ---
  function populateCatalog(){
    el.unitType.innerHTML = "";
    const keys = Object.keys(UNIT_CATALOG);
    // stable order: by baseXpl then baseSize then name
    keys.sort((a,b)=>{
      const A=UNIT_CATALOG[a], B=UNIT_CATALOG[b];
      const d1=(A.baseXpl||0)-(B.baseXpl||0);
      if(d1) return d1;
      const d2=(A.baseSize||0)-(B.baseSize||0);
      if(d2) return d2;
      return (A.name||"").localeCompare(B.name||"", "ru");
    });

    for(const k of keys){
      const opt=document.createElement("option");
      opt.value=k;
      opt.textContent = `${UNIT_CATALOG[k].name} (${UNIT_CATALOG[k].baseSize}/${UNIT_CATALOG[k].baseXpl} XPL)`;
      el.unitType.appendChild(opt);
    }
    // default
    el.unitType.value = keys.includes("militia") ? "militia" : keys[0];
    applyCatalogDefaults();
  }

  function applyCatalogDefaults(){
    const tpl = UNIT_CATALOG[el.unitType.value];
    if(!tpl) return;
    el.baseCount.value = tpl.baseSize;
    el.baseXpl.value = tpl.baseXpl;
    // keep count if user set custom, but if empty or <=0 set to base
    const c = parseInt(el.count.value,10);
    if(!c || c<=0) el.count.value = tpl.baseSize;
    updateComputedXpl();
  }

  function updateComputedXpl(){
    const men = Math.max(0, parseInt(el.count.value,10)||0);
    const baseSize = Math.max(1, parseInt(el.baseCount.value,10)||1);
    const baseXpl = Math.max(0, parseFloat(el.baseXpl.value)||0);
    const xpl = (men/baseSize)*baseXpl;
    el.xpl.value = fmtX(xpl);
  }

  // --- UI refresh ---
  function updateTopTags(){
    el.phaseTag.textContent = `Фаза: ${PHASE_NAMES[battle.phase]||battle.phase}`;
    el.turnTag.textContent = `Ход: ${battle.turn|0}`;
    el.activeTag.textContent = battle.started ? `Активный: ${sideName(battle.active)}` : "Активный: —";
    el.activeTag.classList.toggle("blue", battle.active==="blue");
    el.activeTag.classList.toggle("red", battle.active==="red");
  }

  function updateTotals(){
    el.sumBlue.textContent = `Синие: ${fmtX(E.totalXpl("blue"))} XPL`;
    el.sumRed.textContent  = `Красные: ${fmtX(E.totalXpl("red"))} XPL`;
  }

  function renderLog(){
    // newest at top
    const html = LOG.map(e=>{
      const cls = e.cls || "mut";
      const safe = String(e.text).replace(/[&<>]/g, m=>({ "&":"&amp;","<":"&lt;",">":"&gt;" }[m]));
      return `<div class="e ${cls}"><span style="opacity:.65">${e.stamp}</span> ${safe}</div>`;
    }).join("");
    el.log.innerHTML = html;
  }

function esc(s){
  return String(s).replace(/[&<>]/g, m=>({ "&":"&amp;","<":"&lt;",">":"&gt;" }[m]));
}

function formationName(f){
  switch(f){
    case "line": return "Линия";
    case "block": return "Блок";
    case "wedge": return "Клин";
    case "sleeve": return "Рукава";
    case "chatillon": return "Шатильон";
    default: return f||"—";
  }
}

function renderRoster(){
  if(!el.roster) return;

  const sel = E.getSelected();
  const selId = sel ? sel.id : null;

  const units = scenario.units.slice().sort((a,b)=>{
    // side first, then routed last, then name
    if(a.side !== b.side) return a.side==="blue" ? -1 : 1;
    const ar = a.routed ? 1 : 0;
    const br = b.routed ? 1 : 0;
    if(ar !== br) return ar - br;
    return String(a.name||"").localeCompare(String(b.name||""), "ru");
  });

  if(units.length===0){
    el.roster.innerHTML = `<div class="muted" style="color: var(--muted); font-size:12px;">Нет отрядов. Добавь отряд и размести его на поле.</div>`;
    return;
  }

  const html = units.map(u=>{
    const xpl = fmtX(E.computeXpl(u));
    const morale = Math.round(u.morale);
    const status = u.routed ? "БЕЖИТ" : (u.destroyed ? "УНИЧТОЖЕН" : "");
    const badge = status ? `<span class="badge ${u.routed ? "routed":""}">${status}</span>` : "";
    const cls = "it" + (u.id===selId ? " sel" : "");
    const dotCls = "dot" + (u.side==="red" ? " red" : "");
    const sub = `${(u.men|0)} · ${formationName(u.formation)} · Мораль ${morale}/100`;
    return `
      <div class="${cls}" data-id="${u.id}">
        <div class="${dotCls}"></div>
        <div class="left">
          <div class="name">${esc(u.name)}</div>
          <div class="submeta">${esc(sub)}</div>
        </div>
        <div style="display:flex; flex-direction:column; align-items:flex-end; gap:4px;">
          <div class="meta">${xpl} XPL</div>
          ${badge}
        </div>
      </div>
    `;
  }).join("");

  el.roster.innerHTML = html;
}


  function updateSelCard(){
    const u = E.getSelected();
    if(!u){
      el.selCard.style.display="none";
      return;
    }
    el.selCard.style.display="block";
    el.selTitle.textContent = u.name;
    el.selSide.textContent  = sideName(u.side);
    el.selMen.textContent   = String(u.men|0);
    el.selXpl.textContent   = fmtX(E.computeXpl(u));
    el.selMorale.textContent = `${Math.round(u.morale)} / 100`;
    el.selFormation.value = u.formation;
    // Movement-phase formation change toggle
    if(el.btnChangeFormationMove){
      const show = battle.started && battle.phase==="movement" && u.side===battle.active && u.state!=="destroyed" && u.state!=="routed" && u.movedTurn!==battle.turn;
      el.btnChangeFormationMove.style.display = show ? "inline-flex" : "none";
      el.btnChangeFormationMove.textContent = changeFormationMode ? "Выбери строй в списке (действие потратится)" : "Сменить строй (вместо движения)";
    }
    el.selAngle.value = Math.round(U.deg(u.angle));
    el.selMoraleEdit.value = Math.round(u.morale);
  }

  function syncControlsEnabled(){
    const started = battle.started;
    el.btnStart.disabled = started || scenario.units.length===0;
    el.btnNext.disabled = !started || battle.over;
    // Setup controls locked during battle
    const lock = started;
    el.unitType.disabled = lock;
    el.side.disabled = lock;
    el.formation.disabled = lock;
    el.count.disabled = lock;
    el.baseCount.disabled = lock;
    el.baseXpl.disabled = lock;
    el.name.disabled = lock;
    el.addUnit.disabled = lock || tokenMode.enabled;
    if(tokenMode.enabled){
      el.side.disabled = true;
      if(el.tabMap) el.tabMap.disabled = true;
    }
  }

  function refreshAll(){
    updateComputedXpl();
    updateTopTags();
    updateTotals();
    updateSelCard();
    syncControlsEnabled();
    renderRoster();
    renderLog();
  }

  // --- Actions ---
  function addUnit(){
    if(tokenMode.enabled){
      E.log("В боевой сессии по токену ручное добавление отрядов отключено.", "mut");
      refreshAll();
      return;
    }
    const tpl = UNIT_CATALOG[el.unitType.value];
    if(!tpl) return;

    const side = el.side.value;
    const payload = {
      type: el.unitType.value,
      name: el.name.value,
      side,
      formation: el.formation.value,
      men: Math.max(1, parseInt(el.count.value,10)||1),
      baseSize: Math.max(1, parseInt(el.baseCount.value,10)||tpl.baseSize),
      baseXpl: Math.max(0, parseFloat(el.baseXpl.value)||tpl.baseXpl),
    };

    // Spawn with small vertical jitter so stacks are visible
    const jitter = (Math.random()*260 - 130);
    payload.x = (side==="blue" ? -520 : 520) + (Math.random()*30-15);
    payload.y = (side==="blue" ? 240 : -240) + jitter;

    const u = E.addUnitFromUI(payload);
    if(u) E.selectUnit(u);
    el.name.value="";
    refreshAll();
  }

  function startBattle(){
    if(tokenMode.enabled && !tokenMode.ready){
      E.log("Сначала нажмите «Готов» в сессии боя.", "warn");
      refreshAll();
      return;
    }
    if(tokenMode.enabled && !tokenMode.bothReady){
      E.log("Ожидаем готовность второй стороны.", "warn");
      refreshAll();
      return;
    }
    battle.rp.blue = U.clamp(parseInt(el.rpBlue.value,10)||0, -3, 3);
    battle.rp.red  = U.clamp(parseInt(el.rpRed.value,10)||0, -3, 3);
    E.startBattle();
    refreshAll();
  }

  function nextPhase(){
    battle.rp.blue = U.clamp(parseInt(el.rpBlue.value,10)||0, -3, 3);
    battle.rp.red  = U.clamp(parseInt(el.rpRed.value,10)||0, -3, 3);
    E.nextPhase();
    refreshAll();
  }

  function resetAll(){
    E.resetAll();
    refreshAll();
  }

  function setSelectedFormation(){
    const u = E.getSelected();
    if(!u) return;

    const newForm = el.selFormation.value;

    // During battle: formation changes are only allowed in movement phase via the dedicated button
    if(battle.started){
      if(!(battle.phase==="movement" && changeFormationMode)){
        // revert UI to actual value
        el.selFormation.value = u.formation;
        E.log("Смена строя доступна только в фазе движения через кнопку.", "mut");
        return;
      }
      if(u.side!==battle.active || u.state==="destroyed" || u.state==="routed"){
        el.selFormation.value = u.formation;
        E.log("Нельзя менять строй: неактивная сторона/юнит выведен.", "mut");
        return;
      }
      if(u.movedTurn===battle.turn){
        el.selFormation.value = u.formation;
        E.log("Этот отряд уже потратил действие в этой фазе.", "mut");
        changeFormationMode=false;
        refreshAll();
        return;
      }
    }else{
      // Setup phase: allow freely
    }

    const oldForm = u.formation;
    u.formation = newForm;
    u.dirtyLayout = true;
    E.rebuildUnitLayout(u);

    // collision check with new footprint
    if(E.canPlaceUnitPose){
      const ok = E.canPlaceUnitPose(u, u.x, u.y, (u.angle||0)).ok;
      if(!ok){
        // revert
        u.formation = oldForm;
        u.dirtyLayout = true;
        E.rebuildUnitLayout(u);
        el.selFormation.value = oldForm;
        E.log("⛔ Нельзя сменить строй: формация пересекает препятствие.", "mut");
        return;
      }
    }

    if(battle.started && battle.phase==="movement" && changeFormationMode){
      u.movedTurn = battle.turn; // consumes movement action
      changeFormationMode = false;
      E.log("Смена строя выполнена (действие движения потрачено).", "ok");
    }

    refreshAll();
  }


  function setSelectedAngleDeg(){
    const u = E.getSelected();
    if(!u) return;
    const deg = parseFloat(el.selAngle.value)||0;
    u.angle = U.rad(deg);
    refreshAll();
  }

  function setSelectedMorale(){
    const u = E.getSelected();
    if(!u) return;
    u.morale = U.clamp(parseFloat(el.selMoraleEdit.value)||u.morale, 0, 100);
    refreshAll();
  }

  function healSelected(){
    const u = E.getSelected();
    if(!u) return;
    u.men = Math.max(1, u.baseSize|0);
    u.morale = U.clamp(u.moraleBase||u.morale, 0, 100);
    u.state = "ready";
    u.dirtyLayout = true;
    refreshAll();
    E.log(`✚ ${u.name}: восстановлен до базы`, "ok");
  }

  function kill10(){
    const u = E.getSelected();
    if(!u) return;
    const before = u.men|0;
    const after = Math.max(0, Math.floor(before*0.9));
    u.men = after;
    u.dirtyLayout = true;
    u.morale = U.clamp(u.morale - 6, 0, 100);
    if(u.men<=0) u.state="destroyed";
    refreshAll();
    E.log(`☠ ${u.name}: −10% (${before} → ${after})`, "bad");
  }

  function removeSelected(){
    const u = E.getSelected();
    if(!u) return;
    E.removeUnit(u);
    refreshAll();
  }

  // --- Input (drag unit with LMB; pan with RMB; wheel rotate (movement only)) ---
    let changeFormationMode = false;

const input = {
    lDown:false,
    rDown:false,
    draggingUnit:false,
    draggingPan:false,
    dragUnitId:null,
    startWX:0, startWY:0,
    startUX:0, startUY:0,
    lastValidUX:0, lastValidUY:0,
    moveRange:0,
    panStartX:0, panStartY:0,
    panStartOx:0, panStartOy:0,
    moved:false,
    justDraggedAt:0,
    lastX:0, lastY:0,
  };

  function clampUnitToMap(u){
    const hw = scenario.map.w/2;
    const hh = scenario.map.h/2;
    u.x = U.clamp(u.x, -hw, hw);
    u.y = U.clamp(u.y, -hh, hh);
  }

  function canDragUnit(u){
    if(!u) return false;
    if(u.state==="destroyed" || u.state==="routed") return false;
    if(tokenMode.enabled && !isOwnUnit(u)) return false;
    if(!battle.started) return true; // deployment
    if(battle.over) return false;
    if(battle.phase!=="movement") return false;
    if(u.side!==battle.active) return false;
    // one move per side-turn
    if(u.movedTurn===battle.turn) return false;
    return true;
  }

  function onMouseDown(e){
    const rect = R.canvas.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;

    // hover tracking for overlays (movement/range/melee previews)
    const hovered = R.pickUnitScreen(x,y);
    E.scenario.hoverId = hovered ? hovered.id : null;

    input.lastX=x; input.lastY=y;

    if(e.button===2){
      input.rDown=true;
      input.draggingPan=true;
      input.panStartX=x; input.panStartY=y;
      input.panStartOx=R.view.ox; input.panStartOy=R.view.oy;
      return;
    }

    if(e.button!==0) return;

    // --- Map editor mode ---
    if(uiMode==="map"){
      const ed = ensureEditor();
      input.lDown = true;
      input.moved = false;

      const w = R.screenToWorld(x,y);

      if(ed.tool==="house"){
        const b = {id:U.uid(), x:w.x, y:w.y, w:num(el.houseW,80), h:num(el.houseH,60), angle:0};
        scenario.map.objects.buildings.push(b);
        ed.selected = {type:"building", id:b.id};
        refreshAll();
        return;
      }

      if(ed.tool==="river" || ed.tool==="fort"){
        if(!ed.drawing){
          if(ed.tool==="river"){
            ed.drawing = {type:"river", pts: [], width: num(el.riverWidth, 20)};
          }else{
            ed.drawing = {type:"fort", pts: [], thickness: num(el.fortThick, 16), towerR: num(el.towerR, 20)};
          }
        }else{
          // keep preview params in sync while drawing
          if(ed.drawing.type==="river") ed.drawing.width = num(el.riverWidth, ed.drawing.width||20);
          if(ed.drawing.type==="fort"){
            ed.drawing.thickness = num(el.fortThick, ed.drawing.thickness||16);
            ed.drawing.towerR = num(el.towerR, ed.drawing.towerR||20);
          }
        }
        ed.drawing.pts.push({x:w.x, y:w.y});
        refreshAll();
        return;
      }

      if(ed.tool==="erase"){
        const hit = hitTestMapObject(w.x,w.y);
        if(hit){
          deleteObj(hit);
          ed.selected = null;
          refreshAll();
        }
        return;
      }

      // select + drag
      const hit = hitTestMapObject(w.x,w.y);
      ed.selected = hit;
      if(hit){
        const obj = getObj(hit);
        if(obj){
          ed.dragging = true;
          ed.drag = {
            ref: hit,
            startWX: w.x, startWY: w.y,
            orig: JSON.parse(JSON.stringify(obj)),
          };
        }
      }else{
        ed.dragging = false;
        ed.drag = null;
      }
      refreshAll();
      return;
    }

    if(e.button!==0) return;
    input.lDown=true;
    input.moved=false;

    const picked = R.pickUnitScreen(x,y);

    // Ranged phase targeting: clicking an enemy should not switch selection away from the shooter
if(battle.started && battle.phase==="ranged" && picked){
  const shooter = E.getSelected();
  const canShoot = (shooter && shooter.stats.ranged && shooter.side===battle.active && shooter.state==="ready" && shooter.firedTurn!==battle.turn);
  if(canShoot && picked.side!==shooter.side && picked.state!=="destroyed"){
    const r = shooter.stats.ranged.range;
    const d = window.U.dist(shooter.x, shooter.y, picked.x, picked.y);
    if(d<=r){
      // keep shooter selected; actual shot happens on click
      refreshAll();
      return;
    }else{
      // keep shooter selected and provide feedback
      E.log("Цель вне дальности стрельбы.","mut");
      refreshAll();
      return;
    }
  }
}

    if(picked){
      if(tokenMode.enabled && !isOwnUnit(picked)){
        E.log("В сессии по токену можно управлять только своими войсками.", "mut");
        refreshAll();
        return;
      }
      E.selectUnit(picked);
      refreshAll();

      if(canDragUnit(picked)){
        input.draggingUnit=true;
        input.dragUnitId=picked.id;
        const w = R.screenToWorld(x,y);
        input.startWX=w.x; input.startWY=w.y;
        input.startUX=picked.x; input.startUY=picked.y;
        input.lastValidUX=picked.x; input.lastValidUY=picked.y;
        input.moveRange = battle.started ? E.getMoveRange(picked) : 999999;
      }
    }else{
      // click empty: deselect
      E.selectUnit(null);
      refreshAll();
    }
  }

  function onMouseMove(e){
    const rect = R.canvas.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;

    // hover tracking for overlays (movement/range/melee previews)
    const hovered = R.pickUnitScreen(x,y);
    E.scenario.hoverId = hovered ? hovered.id : null;

    const dx = x - input.lastX;
    const dy = y - input.lastY;
    input.lastX=x; input.lastY=y;

    if(input.draggingPan && input.rDown){
      // Pan: ox/oy act like view center in world coords.
      R.view.ox = input.panStartOx - (x - input.panStartX)/R.view.zoom;
      R.view.oy = input.panStartOy - (y - input.panStartY)/R.view.zoom;
      R.clampPan();
      return;
    }

    // --- Map editor drag ---
    if(uiMode==="map"){
      const ed = ensureEditor();
      if(ed.dragging && ed.drag && input.lDown){
        const w = R.screenToWorld(x,y);
        const dxw = w.x - ed.drag.startWX;
        const dyw = w.y - ed.drag.startWY;

        const ref = ed.drag.ref;
        const obj = getObj(ref);
        if(obj){
          if(ref.type==="building"){
            obj.x = ed.drag.orig.x + dxw;
            obj.y = ed.drag.orig.y + dyw;
          }else if(ref.type==="river"){
            obj.pts = ed.drag.orig.pts.map(p=>({x:p.x+dxw, y:p.y+dyw}));
          }else if(ref.type==="fort"){
            obj.pts = ed.drag.orig.pts.map(p=>({x:p.x+dxw, y:p.y+dyw}));
          }
        }
        input.moved = true;
        refreshAll();
      }
      return;
    }

    if(input.draggingUnit && input.lDown){
      const u = E.getUnit(input.dragUnitId);
      if(!u){ input.draggingUnit=false; return; }

      const w = R.screenToWorld(x,y);
      let tx = input.startUX + (w.x - input.startWX);
      let ty = input.startUY + (w.y - input.startWY);

      // clamp by move range (from start)
      const dist = U.dist(input.startUX, input.startUY, tx, ty);
      const r = input.moveRange;
      if(dist > r){
        const k = r / Math.max(1e-6, dist);
        tx = input.startUX + (tx - input.startUX)*k;
        ty = input.startUY + (ty - input.startUY)*k;
      }


      // collision vs obstacles (rivers/buildings/fort walls)
      const ang = (u.angle||0);
      if(E.canPlaceUnitPose){
        const res0 = E.canPlaceUnitPose(u, tx, ty, ang);
        if(!res0.ok){
          // pull back along movement vector to last valid position
          let ax = input.lastValidUX, ay = input.lastValidUY;
          let bx = tx, by = ty;
          for(let it=0; it<9; it++){
            const mx = (ax+bx)/2, my = (ay+by)/2;
            const r1 = E.canPlaceUnitPose(u, mx, my, ang);
            if(r1.ok){ ax=mx; ay=my; } else { bx=mx; by=my; }
          }
          tx = ax; ty = ay;
        }
      }

      u.x = tx; u.y = ty;
      clampUnitToDeployBand(u);
      if(E.canPlaceUnitPose && E.canPlaceUnitPose(u, u.x, u.y, (u.angle||0)).ok){
        input.lastValidUX = u.x; input.lastValidUY = u.y;
      }

      clampUnitToMap(u);

      if(dist > 2) input.moved=true;
      return;
    }
  }

  function onMouseUp(e){
    if(e.button===2){
      input.rDown=false;
      input.draggingPan=false;
      return;
    }
    if(e.button!==0) return;
    input.lDown=false;

    if(uiMode==="map"){
      const ed = ensureEditor();
      ed.dragging = false;
      ed.drag = null;
      refreshAll();
      return;
    }

    if(input.draggingUnit){
      const u = E.getUnit(input.dragUnitId);
      if(u && tokenMode.enabled && !battle.started && !unitInsideAllowedDeployBand(u)){
        clampUnitToDeployBand(u);
        E.log("Расстановка разрешена только на своей трети карты. Центральная полоса запрещена.", "warn");
      }
      if(u && battle.started && battle.phase==="movement" && u.side===battle.active){
        // consume move if actually moved
        if(input.moved){
          u.movedTurn = battle.turn;
          E.log(`⇢ ${u.name}: перемещение`, "mut");
          input.justDraggedAt = performance.now();
        }
      }
      input.draggingUnit=false;
      input.dragUnitId=null;
      refreshAll();
    }
  }

  function onWheel(e){
    const rect = R.canvas.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;

    // hover tracking for overlays (movement/range/melee previews)
    const hovered = R.pickUnitScreen(x,y);
    E.scenario.hoverId = hovered ? hovered.id : null;


    if(uiMode==="map"){
      const ed = ensureEditor();
            // Wheel over selected building rotates it (Shift works anywhere)
      if(ed.selected && ed.selected.type==="building"){
        const wp = R.screenToWorld(x,y);
        const hit = hitTestMapObject(wp.x, wp.y);
        const overSelected = hit && hit.type==="building" && hit.id===ed.selected.id;
        if(e.shiftKey || overSelected){
          const b = getObj(ed.selected);
          if(b){
            const dir = (e.deltaY>0) ? 1 : -1;
            b.angle = (Number(b.angle)||0) + dir*(Math.PI/24); // 7.5°
            refreshAll();
            e.preventDefault();
            return;
          }
        }
      }
      // Default: zoom
      const z0 = R.view.zoom;
      const k = Math.exp((-e.deltaY)*0.0015);
      R.zoomAtScreen(x,y, z0*k);
      refreshAll();
      e.preventDefault();
      return;
    }

    const sel = E.getSelected();
    const overSel = sel && R.isCursorOverUnit(x,y,sel);

    // Rotate only in movement phase (active side). Otherwise zoom.
    if(((battle.started && battle.phase==="movement" && sel && sel.side===battle.active && sel.state!=="destroyed" && sel.state!=="routed") || (!battle.started && sel && sel.state!=="destroyed" && sel.state!=="routed")) && overSel){
      e.preventDefault();
      const dir = (e.deltaY>0) ? 1 : -1;
      const step = U.rad(5);
      const nextAng = U.wrapAngleRad((sel.angle||0) + dir*step);
      if(E.canPlaceUnitPose){
        const ok = E.canPlaceUnitPose(sel, sel.x, sel.y, nextAng).ok;
        if(!ok){
          E.log("⛔ Поворот невозможен: препятствие.", "mut");
          refreshAll();
          return;
        }
      }
      sel.angle = nextAng;
      el.selAngle.value = Math.round(U.deg(sel.angle));
      refreshAll();
      return;
    }
    // Zoom
    e.preventDefault();
    const z0 = R.view.zoom;
    const k = (e.deltaY>0) ? 0.9 : 1.1;
    R.zoomAtScreen(x,y, z0*k);
    refreshAll();
    return;
  }

  // --- Click in ranged phase: selected shooter attacks clicked target ---
  function onClick(e){
    if(uiMode==="map") return;
  // Ranged target selection: select a shooter (own unit), then click an enemy within range.
  if(!battle.started || battle.over) return;
  if(battle.phase!=="ranged") return;

  const rect = R.canvas.getBoundingClientRect();
  const x = e.clientX - rect.left;
  const y = e.clientY - rect.top;

  const shooter = E.getSelected();
  if(!shooter || !shooter.stats.ranged){
    // If you click on your own ranged unit, selection already happens in onMouseDown.
    // Keep a short hint if you click on an enemy without a shooter selected.
    const t = R.pickUnitScreen(x,y);
    if(t && t.side!==battle.active){
      E.log("Выбери стрелка (свой отряд) и кликни по цели в радиусе.","mut");
      refreshAll();
    }
    return;
  }

  if(shooter.side!==battle.active){
    E.log("Сейчас ход другой стороны.","mut");
    refreshAll();
    return;
  }
  if(shooter.state!=="ready"){
    E.log("Этот отряд не готов к действию.","mut");
    refreshAll();
    return;
  }
  if(shooter.firedTurn===battle.turn){
    E.log("Этот отряд уже стрелял в этом ходу.","mut");
    refreshAll();
    return;
  }

  const target = R.pickUnitScreen(x,y);
  if(!target) return;
  if(target.side===shooter.side) return;
  if(target.state==="destroyed") return;

  const r = shooter.stats.ranged.range;
  const d = window.U.dist(shooter.x, shooter.y, target.x, target.y);
  if(d>r){
    E.log("Цель вне дальности стрельбы.","mut");
    refreshAll();
    return;
  }

  if(tokenMode.enabled){
    const auid = String(shooter._battleUid || ((shooter._battleArmyUid || '') + '#' + String(Number(shooter._battleUnitIdx)||0)));
    const tuid = String(target._battleUid || ((target._battleArmyUid || '') + '#' + String(Number(target._battleUnitIdx)||0)));
    sendBattleActions([{ type:'ranged_attack', uid: auid, target_uid: tuid }])
      .then(async ()=>{ await loadTokenBattleScenario({ hydrate: true }); refreshAll(); })
      .catch((e)=>{ E.log('Выстрел отклонён сервером: ' + (e && e.message ? e.message : e), 'mut'); refreshAll(); });
    return;
  }
  const res = E.rangedAttack(shooter, target);
  if(!res || !res.ok){
    E.log("Выстрел не выполнен.","mut");
  }
  refreshAll();
}


  async function sendBattleActions(actions){
    if(!tokenMode.enabled) return null;
    const rr = await fetch('/api/war/battle/commit/', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ token: battleToken, base_rev: Number(tokenMode.lastRealtimeRev || 0), actions: Array.isArray(actions)?actions:[] })
    });
    const jj = await rr.json();
    if(!rr.ok || !jj.ok) throw new Error((jj && (jj.reason || jj.error)) || ('HTTP ' + rr.status));
    if(jj && jj.realtime && jj.realtime.state && Number.isFinite(Number(jj.realtime.state.rev))) tokenMode.lastRealtimeRev = Number(jj.realtime.state.rev);
    return jj;
  }

// --- Bind events ---
  function bind(){
    populateCatalog();
    updateComputedXpl();
    ensureEditor();
    setTool("select");
    setUIMode("battle");

    el.unitType.addEventListener("change", applyCatalogDefaults);
    el.count.addEventListener("input", updateComputedXpl);
    el.baseCount.addEventListener("input", updateComputedXpl);
    el.baseXpl.addEventListener("input", updateComputedXpl);

    el.addUnit.addEventListener("click", addUnit);

    el.btnStart.addEventListener("click", startBattle);
    el.btnNext.addEventListener("click", nextPhase);
    el.btnReset.addEventListener("click", resetAll);

    // Tabs
    if(el.tabBattle) el.tabBattle.addEventListener("click", ()=>setUIMode("battle"));
    if(el.tabMap) el.tabMap.addEventListener("click", ()=>setUIMode("map"));

    // Map tools
    if(el.mapToolSelect) el.mapToolSelect.addEventListener("click", ()=>setTool("select"));
    if(el.mapToolRiver)  el.mapToolRiver.addEventListener("click", ()=>setTool("river"));
    if(el.mapToolHouse)  el.mapToolHouse.addEventListener("click", ()=>setTool("house"));
    if(el.mapToolFort)   el.mapToolFort.addEventListener("click", ()=>setTool("fort"));
    if(el.mapToolErase)  el.mapToolErase.addEventListener("click", ()=>setTool("erase"));

    if(el.btnFinishDraw) el.btnFinishDraw.addEventListener("click", finishDrawing);
    if(el.btnUndoPoint)  el.btnUndoPoint.addEventListener("click", undoPoint);
    if(el.btnClearMap)   el.btnClearMap.addEventListener("click", ()=>{
      E.clearMapObjects();
      ensureEditor().selected=null;
      ensureEditor().drawing=null;
      refreshAll();
    });

    if(el.btnExportMap) el.btnExportMap.addEventListener("click", ()=>{
      const obj = E.serializeMap();
      if(el.mapJson) el.mapJson.value = JSON.stringify(obj, null, 2);
    });
    if(el.btnImportMap) el.btnImportMap.addEventListener("click", ()=>{
      if(!el.mapJson) return;
      try{
        const obj = JSON.parse(el.mapJson.value || "{}");
        E.loadMap(obj);
        refreshAll();
      }catch(err){
        E.log("Ошибка JSON карты: "+err.message, "bad");
      }
    });

    el.rpBlue.addEventListener("input", ()=>{ battle.rp.blue = U.clamp(parseInt(el.rpBlue.value,10)||0, -3, 3); });
    el.rpRed.addEventListener("input",  ()=>{ battle.rp.red  = U.clamp(parseInt(el.rpRed.value,10)||0, -3, 3); });

    el.selFormation.addEventListener("change", setSelectedFormation);
    if(el.btnChangeFormationMove) el.btnChangeFormationMove.addEventListener("click", ()=>{
      const u = E.getSelected();
      if(!u) return;
      if(!(battle.started && battle.phase==="movement")){ E.log("Смена строя доступна в фазе движения.", "mut"); return; }
      if(u.side!==battle.active){ E.log("Можно менять строй только активной стороне.", "mut"); return; }
      if(u.movedTurn===battle.turn){ E.log("Отряд уже потратил действие.", "mut"); return; }
      changeFormationMode = !changeFormationMode;
      if(changeFormationMode) E.log("Выбери новый строй в выпадающем списке. Это заменит движение.", "mut");
      refreshAll();
    });
    el.selAngle.addEventListener("change", setSelectedAngleDeg);
    el.selMoraleEdit.addEventListener("change", setSelectedMorale);

    el.btnHeal.addEventListener("click", healSelected);
    el.btnKill.addEventListener("click", kill10);
    el.btnRemove.addEventListener("click", removeSelected);

    if(el.roster){
      el.roster.addEventListener("click", (ev)=>{
const it = ev.target.closest && ev.target.closest(".it");
if(!it) return;
const id = it.getAttribute("data-id");
if(!id) return;

const clicked = E.getUnit(id);
const sel = E.getSelected();

// In ranged phase: if a shooter is selected, clicking an enemy fires (if in range).
if(battle.started && battle.phase==="ranged" && sel && sel.stats.ranged && clicked && clicked.side!==sel.side && clicked.state!=="destroyed"){
  const canShoot = (sel.side===battle.active && sel.state==="ready" && sel.firedTurn!==battle.turn);
  if(!canShoot){
    E.log("Этот отряд не может стрелять сейчас (не активная сторона / уже стрелял / не готов).","mut");
  }else{
    const r = sel.stats.ranged.range;
    const d = window.U.dist(sel.x, sel.y, clicked.x, clicked.y);
    if(d>r){
      E.log("Цель вне дальности стрельбы.","mut");
    }else{
      if(tokenMode.enabled){
        const auid = String(sel._battleUid || ((sel._battleArmyUid || '') + '#' + String(Number(sel._battleUnitIdx)||0)));
        const tuid = String(clicked._battleUid || ((clicked._battleArmyUid || '') + '#' + String(Number(clicked._battleUnitIdx)||0)));
        sendBattleActions([{ type:'ranged_attack', uid: auid, target_uid: tuid }])
          .then(async ()=>{ await loadTokenBattleScenario({ hydrate: true }); refreshAll(); })
          .catch((e)=>{ E.log('Выстрел отклонён сервером: ' + (e && e.message ? e.message : e), 'mut'); refreshAll(); });
      }else{
        E.rangedAttack(sel, clicked);
      }
    }
  }
  refreshAll();
  return;
}

// Default: select
const clickedUnit = E.getUnit(id);
if(tokenMode.enabled && clickedUnit && !isOwnUnit(clickedUnit)){
  E.log("Выбор вражеских отрядов в token-режиме отключён.", "mut");
  refreshAll();
  return;
}
E.selectUnit(clickedUnit);

// Alt+клик: центрировать камеру
if(ev.altKey){
  const u = E.getSelected();
  if(u){ R.view.ox = u.x; R.view.oy = u.y; }
}
refreshAll();
      });
      el.roster.addEventListener("dblclick", (ev)=>{
        const it = ev.target.closest && ev.target.closest(".it");
        if(!it) return;
        const id = it.getAttribute("data-id");
        if(!id) return;
        const uu = E.getUnit(id);
        if(tokenMode.enabled && uu && !isOwnUnit(uu)){
          E.log("Выбор вражеских отрядов в token-режиме отключён.", "mut");
          refreshAll();
          return;
        }
        E.selectUnit(uu);
        const u = E.getSelected();
        if(u){ R.view.ox = u.x; R.view.oy = u.y; }
        refreshAll();
      });
    }

    // Canvas input
    R.canvas.addEventListener("contextmenu", (e)=>e.preventDefault());
    R.canvas.addEventListener("mousedown", onMouseDown);
    R.canvas.addEventListener("mouseleave", ()=>{ input.lDown=false; input.rDown=false; input.draggingPan=false; input.draggingUnit=false; input.dragUnitId=null; E.scenario.hoverId=null; refreshAll(); });
    window.addEventListener("mousemove", onMouseMove, {passive:true});
    window.addEventListener("mouseup", onMouseUp, {passive:true});
    R.canvas.addEventListener("wheel", onWheel, {passive:false});
    // click for ranged targeting
    R.canvas.addEventListener("click", onClick);
    R.canvas.addEventListener("dblclick", (e)=>{ if(uiMode==="map"){ e.preventDefault(); finishDrawing(); } });
  }

  // --- UI loop ---
  function loopUI(){
    // HUD (cheap)
    el.hud.textContent = `Zoom ${R.view.zoom.toFixed(2)} | Center ${Math.round(R.view.ox)},${Math.round(R.view.oy)} | ${battle.started ? (PHASE_NAMES[battle.phase]||battle.phase) : "setup"}`;

    // Throttle DOM-heavy updates
    const now = performance.now();
    if(!loopUI._t) loopUI._t = 0;
    if(now - loopUI._t > 160){
      loopUI._t = now;
      refreshAll();
    }

    requestAnimationFrame(loopUI);
  }

  async function loadTokenBattleScenario(opts){
    const hydrate = !opts || opts.hydrate !== false;
    if(!tokenMode.enabled) return;
    const res = await fetch('/api/war/battle/session/?token=' + encodeURIComponent(battleToken), { cache: 'no-store' });
    const json = await res.json();
    if(!res.ok || !json || !json.ok) throw new Error((json && json.error) || ('HTTP ' + res.status));

    tokenMode.side = String(json.side || '');
    tokenMode.ready = !!(json && json.battle && json.battle.ready && json.battle.ready[tokenMode.side]);
    tokenMode.bothReady = !!(json && json.battle && json.battle.ready && json.battle.ready.A && json.battle.ready.B);
    tokenMode.sessionStatus = String((json && json.battle && json.battle.status) || 'setup');
    const realtimeState = json && json.battle && json.battle.realtime && json.battle.realtime.state ? json.battle.realtime.state : null;
    if(realtimeState && Number.isFinite(Number(realtimeState.rev))) tokenMode.lastRealtimeRev = Number(realtimeState.rev);

    const mine = Array.isArray(json.my_armies) ? json.my_armies : [];
    const enemy = Array.isArray(json.enemy_armies) ? json.enemy_armies : [];

    if(hydrate){
      E.resetAll();
    }

    const ownSide = sideForTokenPlayer() || 'blue';
    const enemySide = ownSide === 'blue' ? 'red' : 'blue';

    function autoDeployAngle(side){
      return side === 'blue' ? Math.PI : 0;
    }

    function ensureTokenUnitCatalogEntry(type, source){
      const unitType = String(type || '').trim();
      if(!unitType) return '';
      if(UNIT_CATALOG[unitType]) return unitType;

      const src = String(source || '').trim();
      const bySource = {
        militia: 'militia',
        sergeants: 'militia_tr',
        nehts: 'foot_nehts',
        knights: 'foot_knights',
        other: 'city100',
      };
      const fallbackType = bySource[src] || 'militia';
      const fallbackTpl = UNIT_CATALOG[fallbackType] || UNIT_CATALOG.militia;
      if(!fallbackTpl) return '';

      UNIT_CATALOG[unitType] = Object.assign({}, fallbackTpl, {
        name: `${fallbackTpl.name} (${unitType})`,
      });
      return unitType;
    }

    function findFreeDeployPose(u, baseX, baseY, angle, band){
      const mapW = Number(scenario.map.w || 2200);
      const mapH = Number(scenario.map.h || 1400);
      const halfW = mapW/2;
      const halfH = mapH/2;
      const xMin = -halfW + 70;
      const xMax = halfW - 70;
      const yMin = Math.max(-halfH + 60, Number(band.yMin));
      const yMax = Math.min(halfH - 60, Number(band.yMax));

      const ringStep = 56;
      const maxRing = 10;
      const candidates = [{x:baseX, y:baseY}];
      for(let r=1;r<=maxRing;r++){
        const d = r * ringStep;
        candidates.push(
          {x:baseX + d, y:baseY},
          {x:baseX - d, y:baseY},
          {x:baseX, y:baseY + d},
          {x:baseX, y:baseY - d},
          {x:baseX + d, y:baseY + d},
          {x:baseX + d, y:baseY - d},
          {x:baseX - d, y:baseY + d},
          {x:baseX - d, y:baseY - d}
        );
      }

      for(const c of candidates){
        const x = U.clamp(c.x, xMin, xMax);
        const y = U.clamp(c.y, yMin, yMax);
        const place = E.canPlaceUnitPose ? E.canPlaceUnitPose(u, x, y, angle) : {ok:true};
        if(place.ok) return {x, y, angle};
      }

      return {
        x: U.clamp(baseX, xMin, xMax),
        y: U.clamp(baseY, yMin, yMax),
        angle,
      };
    }

    function armyUnitsAsList(army){
      const raw = army && army.units;
      if(Array.isArray(raw)) return raw.map((row, idx) => ({ idx, row }));
      if(raw && typeof raw === 'object'){
        const out = [];
        for(const [k, row] of Object.entries(raw)){
          const parsed = Number(k);
          out.push({ idx: Number.isFinite(parsed) ? parsed : out.length, row });
        }
        out.sort((a,b)=>a.idx-b.idx);
        return out;
      }
      return [];
    }

    function flattenArmyRows(rows){
      const out = [];
      for(const army of rows){
        for(const item of armyUnitsAsList(army)){
          out.push({
            uid: String((army && army.army_uid) || '') + '#' + String(item.idx),
            army,
            idx: Number(item.idx),
            row: item.row,
          });
        }
      }
      return out;
    }

    function spawnArmyRows(rows, side){
      const flatRows = flattenArmyRows(rows);
      const allUnits = [];
      for(const item of flatRows){
        allUnits.push({ army: item.army, idx: item.idx, row: item.row, uid: item.uid });
      }

      const total = Math.max(1, allUnits.length);
      const mapW = Number(scenario.map.w || 2200);
      const band = deployBandForSide(side);
      const cols = Math.max(3, Math.ceil(Math.sqrt(total)));
      const xSpan = Math.max(500, mapW * 0.72);
      const xStart = -(xSpan/2);
      const xStep = cols > 1 ? (xSpan/(cols-1)) : 0;
      const yStep = 120;
      const forward = side === 'blue' ? 1 : -1;
      const anchorY = side === 'blue' ? band.yMin + 80 : band.yMax - 80;
      const angle = autoDeployAngle(side);

      for(const [order, item] of allUnits.entries()){
        const {army, idx, row, uid} = item;
          const type = ensureTokenUnitCatalogEntry(String(row && row.unit_id || '').trim(), String(row && row.source || '').trim());
          const tpl = UNIT_CATALOG[type];
          if(!tpl) continue;
          const col = order % cols;
          const depth = Math.floor(order / cols);
          const targetX = xStart + col * xStep;
          const targetY = anchorY + forward * depth * yStep;
          const payload = {
            type,
            side,
            formation: 'line',
            men: Math.max(1, Number(row.size) || 1),
            baseSize: Math.max(1, Number(tpl.baseSize) || 1),
            baseXpl: Math.max(0, Number(tpl.baseXpl) || 0),
            name: String(tpl.name || type),
            x: targetX,
            y: targetY,
          };
          const u = E.addUnitFromUI(payload);
          if(u){
            const pose = findFreeDeployPose(u, targetX, targetY, angle, band);
            u.x = pose.x;
            u.y = pose.y;
            u.angle = pose.angle;
            u._battleArmyUid = String(army && army.army_uid || "");
            u._battleUnitIdx = Number(idx);
            u._battleUid = String(uid || '');
            clampUnitToDeployBand(u);
          }
        }
      }
    }

    if(hydrate){
      const useRealtime = !!(realtimeState && Array.isArray(realtimeState.units) && realtimeState.units.length && !tokenMode.emergencyFromArmies);
      if(useRealtime){
        const realtimeUnits = Array.isArray(realtimeState.units) ? realtimeState.units.slice() : [];
        const realtimeUidSet = new Set();
        const armyDefaults = new Map();

        function registerArmyDefaults(rows, side){
          for(const item of flattenArmyRows(rows)){
            const uid = String(item && item.uid || '').trim();
            if(!uid) continue;
            const srcRow = item && item.row ? item.row : {};
            armyDefaults.set(uid, {
              uid,
              army_uid: String(item.army && item.army.army_uid || ''),
              unit_idx: Number(item.idx),
              unit_id: String(srcRow.unit_id || ''),
              source: String(srcRow.source || ''),
              side,
              men: Math.max(0, Number(srcRow.size) || 0),
            });
          }
        }

        registerArmyDefaults(mine, ownSide);
        registerArmyDefaults(enemy, enemySide);

        for(const row of realtimeUnits){
          const uid = String(row && row.uid || '').trim();
          if(!uid) continue;
          realtimeUidSet.add(uid);

          const defaults = armyDefaults.get(uid);
          if(!defaults) continue;

          const curMen = Math.max(0, Number(row.men) || 0);
          const hasValidMen = curMen > 0;
          const defaultMen = Math.max(0, Number(defaults.men) || 0);
          if(!hasValidMen && defaultMen > 0){
            row.men = defaultMen;
          }
          if(!row.unit_id && defaults.unit_id) row.unit_id = defaults.unit_id;
          if(!row.source && defaults.source) row.source = defaults.source;
          if(!row.army_uid && defaults.army_uid) row.army_uid = defaults.army_uid;
          if(!Number.isFinite(Number(row.unit_idx))) row.unit_idx = defaults.unit_idx;
          if(!row.side && defaults.side) row.side = defaults.side;
        }

        for(const [uid, defaults] of armyDefaults.entries()){
          if(realtimeUidSet.has(uid)) continue;
          if(defaults.men <= 0) continue;
          realtimeUnits.push({
            uid: defaults.uid,
            army_uid: defaults.army_uid,
            unit_idx: defaults.unit_idx,
            unit_id: defaults.unit_id,
            source: defaults.source,
            side: defaults.side,
            formation: 'line',
            men: defaults.men,
          });
        }

        for(const row of realtimeUnits){
          const type = ensureTokenUnitCatalogEntry(String(row && row.unit_id || '').trim(), String(row && row.source || '').trim());
          const tpl = UNIT_CATALOG[type];
          if(!tpl) continue;
          const payload = {
            type,
            side: String(row.side || 'blue') === 'red' ? 'red' : 'blue',
            formation: String(row.formation || 'line'),
            men: Math.max(0, Number(row.men) || 0),
            baseSize: Math.max(1, Number(row.baseSize || row.base_size || tpl.baseSize) || 1),
            baseXpl: Math.max(0, Number(row.baseXpl || row.base_xpl || tpl.baseXpl) || 0),
            name: String(tpl.name || type),
            x: Number(row.x || 0),
            y: Number(row.y || 0),
          };
          const u = E.addUnitFromUI(payload);
          if(!u) continue;
          u.angle = Number(row.angle || 0);
          u.morale = Number(row.morale || 60);
          u.state = String(row.state || 'ready');
          u._battleArmyUid = String(row.army_uid || '');
          u._battleUnitIdx = Number(row.unit_idx || 0);
          u._battleUid = String(row.uid || '');
        }
      }else{
        spawnArmyRows(mine, ownSide);
        spawnArmyRows(enemy, enemySide);
      }
      E.selectUnit(null);
      if(tokenMode.enabled){
        const ownCount = scenario.units.filter((u)=>String(u && u.side || '')===ownSide).length;
        const enemyCount = scenario.units.filter((u)=>String(u && u.side || '')===enemySide).length;
        E.log(`Hydration: my_armies=${mine.length}, enemy_armies=${enemy.length}, spawned=${scenario.units.length} (own=${ownCount}, enemy=${enemyCount}), mode=${tokenMode.emergencyFromArmies ? 'emergency' : 'normal'}`, 'mut');
      }
    }

    const prevCard = document.getElementById('tokenSessionCard');
    if(prevCard) prevCard.remove();
    const card = document.createElement('div');
    card.className = 'card';
    card.id = 'tokenSessionCard';
    const sideLabel = ownSide === 'blue' ? 'Синие (сторона A)' : 'Красные (сторона B)';
    card.innerHTML = `<div class="cardTitle">Токен-сессия боя</div><div class="cardBody"><div class="small">Вы играете за: <b>${sideLabel}</b>. Расстановка только на своей трети карты (центр закрыт). Статус: <b>${tokenMode.sessionStatus}</b>.</div><div class="row" style="margin-top:8px;"><button class="btn" id="tokenReadyBtn">Готов</button><button class="btn" id="tokenPushStateBtn">Отправить ход (lockstep)</button><button class="btn" id="tokenPullStateBtn">Получить состояние</button><button class="btn" id="tokenSessionRefreshBtn">Обновить статус</button><button class="btn" id="tokenEmergencyBtn">Аварийный режим: OFF</button><button class="btn" id="tokenRestartBtn">Перезагрузить бой</button><button class="btn" id="tokenRecreateBtn">Полный перезапуск (с нуля)</button><button class="btn" id="tokenFinishBtn">Сохранить итог боя</button><button class="btn" id="tokenReloadBtn">Перезагрузить</button></div></div>`;
    const panel = document.querySelector('.panel');
    if(panel && panel.firstChild) panel.insertBefore(card, panel.firstChild);
    const readyBtn = document.getElementById('tokenReadyBtn');
    const pushBtn = document.getElementById('tokenPushStateBtn');
    const pullBtn = document.getElementById('tokenPullStateBtn');
    const refreshBtn = document.getElementById('tokenSessionRefreshBtn');
    const emergencyBtn = document.getElementById('tokenEmergencyBtn');
    const finishBtn = document.getElementById('tokenFinishBtn');
    const restartBtn = document.getElementById('tokenRestartBtn');
    const recreateBtn = document.getElementById('tokenRecreateBtn');
    const reloadBtn = document.getElementById('tokenReloadBtn');
    if(readyBtn){
      readyBtn.addEventListener('click', async ()=>{
        const rr = await fetch('/api/war/battle/ready/', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ token: battleToken, ready: true })});
        const jj = await rr.json();
        if(!rr.ok || !jj.ok) throw new Error((jj && jj.error) || ('HTTP ' + rr.status));
        tokenMode.ready = true;
        E.log('Сторона отмечена как готовая.', 'ok');
        await loadTokenBattleScenario({ hydrate: false });
        refreshAll();
      });
    }
    if(pushBtn){
      pushBtn.addEventListener('click', async ()=>{
        const own = sideForTokenPlayer() || 'blue';
        const actions = [];
        for(const u of scenario.units){
          if(String(u.side||'') !== own) continue;
          const uid = String(u._battleUid || ((u._battleArmyUid || '') + '#' + String(Number(u._battleUnitIdx)||0)));
          if(!uid) continue;
          actions.push({ type:'move', uid, x:Number(u.x||0), y:Number(u.y||0), angle:Number(u.angle||0) });
        }
        actions.push({ type:'advance_phase' });
        await sendBattleActions(actions);
        E.log('Команды отправлены на authoritative lockstep-сервер.', 'ok');
        await loadTokenBattleScenario({ hydrate: true });
        refreshAll();
      });
    }
    if(pullBtn){
      pullBtn.addEventListener('click', async ()=>{
        await loadTokenBattleScenario({ hydrate: true });
        refreshAll();
      });
    }
    if(refreshBtn){
      refreshBtn.addEventListener('click', async ()=>{
        await loadTokenBattleScenario({ hydrate: false });
        refreshAll();
      });
    }
    if(emergencyBtn){
      emergencyBtn.textContent = 'Аварийный режим: ' + (tokenMode.emergencyFromArmies ? 'ON' : 'OFF');
      emergencyBtn.addEventListener('click', async ()=>{
        tokenMode.emergencyFromArmies = !tokenMode.emergencyFromArmies;
        emergencyBtn.textContent = 'Аварийный режим: ' + (tokenMode.emergencyFromArmies ? 'ON' : 'OFF');
        E.log('Аварийный режим ' + (tokenMode.emergencyFromArmies ? 'включён' : 'выключен') + '. Источник данных: ' + (tokenMode.emergencyFromArmies ? 'списки армий' : 'realtime + списки армий'), 'ok');
        await loadTokenBattleScenario({ hydrate: true });
        refreshAll();
      });
    }
    if(restartBtn){
      restartBtn.addEventListener('click', async ()=>{
        const rr = await fetch('/api/war/battle/restart/', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ token: battleToken })
        });
        const jj = await rr.json();
        if(!rr.ok || !jj.ok) throw new Error((jj && jj.error) || ('HTTP ' + rr.status));
        E.log('Бой перезапущен и состояние обновлено.', 'ok');
        await loadTokenBattleScenario({ hydrate: true });
        refreshAll();
      });
    }
    if(recreateBtn){
      recreateBtn.addEventListener('click', async ()=>{
        const rr = await fetch('/api/war/battle/recreate/', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ token: battleToken })
        });
        const jj = await rr.json();
        if(!rr.ok || !jj.ok) throw new Error((jj && jj.error) || ('HTTP ' + rr.status));
        E.log('Бой полностью пересоздан с нуля.', 'ok');
        await loadTokenBattleScenario({ hydrate: true });
        refreshAll();
      });
    }
    if(finishBtn){
      finishBtn.addEventListener('click', async ()=>{
        const remaining = {};
        for(const u of scenario.units){
          const armyUid = String(u && u._battleArmyUid || '').trim();
          const idx = Number(u && u._battleUnitIdx);
          if(!armyUid || !Number.isFinite(idx) || idx < 0) continue;
          remaining[armyUid + '#' + String(idx)] = Math.max(0, Math.floor(Number(u.men) || 0));
        }
        const rr = await fetch('/api/war/battle/finish/', {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ token: battleToken, remaining_units: remaining })
        });
        const jj = await rr.json();
        if(!rr.ok || !jj.ok) throw new Error((jj && jj.error) || ('HTTP ' + rr.status));
        E.log('Итог боя сохранён на карту.', 'ok');
        await loadTokenBattleScenario({ hydrate: false });
        refreshAll();
      });
    }
    if(reloadBtn){
      reloadBtn.addEventListener('click', ()=>window.location.reload());
    }
  }

  async function bootstrap(){
    bind();
    if(tokenMode.enabled){
      try {
        await loadTokenBattleScenario({ hydrate: true });
      } catch (err) {
        E.log('Ошибка загрузки токен-сессии: ' + (err && err.message ? err.message : err), 'bad');
      }
    }
    refreshAll();
    if(tokenMode.enabled){
      setInterval(async ()=>{
        try{
          const prev = Number(tokenMode.lastRealtimeRev||0);
          await loadTokenBattleScenario({ hydrate: false });
          const next = Number(tokenMode.lastRealtimeRev||0);
          if(next > prev) await loadTokenBattleScenario({ hydrate: true });
        }catch(_e){}
      }, 2500);
    }
    requestAnimationFrame(loopUI);
  }

  bootstrap();

})();
