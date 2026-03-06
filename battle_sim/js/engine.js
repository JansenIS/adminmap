(function(){
  "use strict";

  const {UNIT_CATALOG, TOKEN_PROFILES, TERRAIN_ZONES, TERRAIN_MODS} = window.DATA;

  const scenario = {
    map: { w: 2200, h: 1400, preset: "plains", lod: true, seed: (Math.random()*1e9)|0, noise:null, contours:null, objects:{rivers:[], buildings:[], forts:[]}, _editor:null },
    units: [],
    selectedId: null,
    hoverId: null,
  };

  const battle = {
    started:false,
    over:false,
    winner:null,
    turn:0,
    phase:"setup",        // setup | movement | ranged | melee | morale
    active:"blue",        // whose action in movement/ranged
    rp:{blue:0, red:0},   // RP-bonus -3..+3
  };

  const LOG = [];
  function log(text, cls="mut"){
    const t = new Date();
    const stamp = String(t.getUTCHours()).padStart(2,'0')+":"+String(t.getUTCMinutes()).padStart(2,'0')+":"+String(t.getUTCSeconds()).padStart(2,'0');
    LOG.unshift({stamp, text, cls});
    if(LOG.length>120) LOG.pop();
  }

  function getUnit(id){ return scenario.units.find(u=>u.id===id) || null; }
  function getSelected(){ return scenario.selectedId ? getUnit(scenario.selectedId) : null; }
  function selectUnit(u){
    scenario.selectedId = u ? u.id : null;
  }

  function computeXpl(u){
    const baseSize = Math.max(1, u.baseSize|0);
    const baseXpl = Math.max(0, +u.baseXpl || 0);
    const size = Math.max(0, u.men|0);
    return (size / baseSize) * baseXpl;
  }

  function xplPerMan(u){
    const baseSize = Math.max(1, u.baseSize|0);
    const baseXpl = Math.max(0, +u.baseXpl || 0);
    return baseXpl / baseSize;
  }

  function totalXpl(side, opts={}){
    const includeRouted = !!opts.includeRouted;
    let s=0;
    for(const u of scenario.units){
      if(u.side!==side) continue;
      if(u.state==="destroyed") continue;
      if(!includeRouted && u.state==="routed") continue;
      s += computeXpl(u);
    }
    return s;
  }

  function sideStartXpl(side){
    let s=0;
    for(const u of scenario.units){
      if(u.side!==side) continue;
      s += (u.startSizeStartBattle!=null ? (u.startSizeStartBattle/Math.max(1,u.baseSize))*u.baseXpl : computeXpl(u));
    }
    return s;
  }

  function terrainTypeAt(x,y){
    for(const z of TERRAIN_ZONES){
      if(window.U.pointInPoly(x,y,z.poly)) return z.type;
    }
    return "plain";
  }

  function moveMultiplier(u, x=u.x, y=u.y){
    const t = terrainTypeAt(x,y);
    if(t==="plain") return 1.0;
    const m = TERRAIN_MODS.move[t];
    if(!m) return 1.0;
    return m[u.kind] ?? 1.0;
  }


  // --- Map objects (rivers, buildings, fortifications) ---
  function ensureMapObjects(){
    if(!scenario.map.objects) scenario.map.objects = {rivers:[], buildings:[], forts:[]};
    if(!scenario.map.objects.rivers) scenario.map.objects.rivers=[];
    if(!scenario.map.objects.buildings) scenario.map.objects.buildings=[];
    if(!scenario.map.objects.forts) scenario.map.objects.forts=[];
  }

  function serializeMap(){
    ensureMapObjects();
    return {
      w: scenario.map.w,
      h: scenario.map.h,
      preset: scenario.map.preset,
      seed: scenario.map.seed,
      objects: JSON.parse(JSON.stringify(scenario.map.objects)),
    };
  }

  function loadMap(obj){
    if(!obj || typeof obj!=="object") return false;
    scenario.map.w = Number(obj.w)||scenario.map.w;
    scenario.map.h = Number(obj.h)||scenario.map.h;
    if(typeof obj.preset==="string") scenario.map.preset = obj.preset;
    if(Number.isFinite(obj.seed)) scenario.map.seed = obj.seed|0;
    scenario.map.objects = obj.objects && typeof obj.objects==="object" ? obj.objects : {rivers:[], buildings:[], forts:[]};
    ensureMapObjects();
    // normalize ids
    for(const r of scenario.map.objects.rivers){ if(!r.id) r.id = window.U.uid(); if(!Array.isArray(r.pts)) r.pts=[]; r.width = Number(r.width)||20; }
    for(const b of scenario.map.objects.buildings){ if(!b.id) b.id = window.U.uid(); b.x=Number(b.x)||0; b.y=Number(b.y)||0; b.w=Number(b.w)||80; b.h=Number(b.h)||60; b.angle=Number(b.angle)||0; }
    for(const f of scenario.map.objects.forts){ if(!f.id) f.id = window.U.uid(); if(!Array.isArray(f.pts)) f.pts=[]; f.thickness=Number(f.thickness)||16; f.towerR=Number(f.towerR)||20; }
    return true;
  }

  function clearMapObjects(){
    scenario.map.objects = {rivers:[], buildings:[], forts:[]};
  }

  function fortContainsPoint(f, x, y){
    if(!f || !Array.isArray(f.pts) || f.pts.length<3) return false;
    return window.U.pointInPoly(x,y,f.pts);
  }

  function fortClosestTower(f, x, y){
    let best=null;
    for(let i=0;i<f.pts.length;i++){
      const p=f.pts[i];
      const d = window.U.dist(x,y,p.x,p.y);
      if(best===null || d<best.d) best={i, d, x:p.x, y:p.y};
    }
    return best;
  }

  function fortClosestWall(f, x, y){
    let best=null;
    const pts=f.pts;
    for(let i=0;i<pts.length;i++){
      const a=pts[i], b=pts[(i+1)%pts.length];
      const c = window.U.closestPointOnSeg(a.x,a.y,b.x,b.y,x,y);
      const d = window.U.dist(x,y,c.x,c.y);
      if(best===null || d<best.d) best={i, d, t:c.t, x:c.x, y:c.y};
    }
    return best;
  }

  function findFortAtPoint(x,y){
    ensureMapObjects();
    for(const f of scenario.map.objects.forts){
      if(fortContainsPoint(f,x,y)) return f;
    }
    return null;
  }

  function fortGarrisonForPoint(x,y){
    ensureMapObjects();
    let best=null;
    for(const f of scenario.map.objects.forts){
      if(!f.pts || f.pts.length<3) continue;

      // tower priority
      const tw = fortClosestTower(f,x,y);
      if(tw && tw.d <= (f.towerR*1.15)){
        if(!best || best.priority<3 || tw.d < best.d){
          best = {priority:3, kind:"tower", fortId:f.id, vx:tw.x, vy:tw.y, vIndex:tw.i, d:tw.d};
        }
        continue;
      }

      // wall snap
      const wl = fortClosestWall(f,x,y);
      if(wl && wl.d <= (f.thickness*0.75)){
        if(!best || best.priority<2 || wl.d < best.d){
          best = {priority:2, kind:"wall", fortId:f.id, sx:wl.x, sy:wl.y, segIndex:wl.i, t:wl.t, d:wl.d};
        }
        continue;
      }

      // inside
      if(fortContainsPoint(f,x,y)){
        if(!best || best.priority<1){
          best = {priority:1, kind:"inside", fortId:f.id, d:0};
        }
      }
    }
    return best;
  }

  function updateUnitGarrison(u, opts={snap:true}){
    if(!u) return;
    ensureMapObjects();
    const g = fortGarrisonForPoint(u.x,u.y);
    if(!g){ u.garrison = null; return; }
    u.garrison = { fortId: g.fortId, kind: g.kind, segIndex: g.segIndex, t: g.t, vIndex: g.vIndex };
    if(opts.snap){
      if(g.kind==="tower"){ u.x=g.vx; u.y=g.vy; }
      if(g.kind==="wall"){ u.x=g.sx; u.y=g.sy; }
    }
  }

  function targetCoverBonus(target){
    ensureMapObjects();
    let cov = coverPenalty(target.x, target.y);
    const g = target.garrison;
    if(g && g.fortId){
      if(g.kind==="inside") cov += 0.12;
      else if(g.kind==="wall") cov += 0.18;
      else if(g.kind==="tower") cov += 0.22;
    }else{
      // if inside a fort, small cover too
      const f = findFortAtPoint(target.x,target.y);
      if(f) cov += 0.10;
    }
    return window.U.clamp(cov, 0, 0.6);
  }

  function segmentIntersectsFortWalls(ax,ay,bx,by,f){
    const pts=f.pts;
    const thick = Number(f.thickness)||16;
    for(let i=0;i<pts.length;i++){
      const p=pts[i], q=pts[(i+1)%pts.length];
      const d = window.U.segSegDist(ax,ay,bx,by,p.x,p.y,q.x,q.y);
      if(d <= thick/2) return true;
    }
    return false;
  
  }

  // --- Unit collision vs map obstacles (rivers/buildings/fort walls) ---
  function pointHitsRivers(px,py){
    for(const r of scenario.map.objects.rivers){
      if(!r.pts || r.pts.length<2) continue;
      const w = Number(r.width)||20;
      const rad = w/2;
      for(let i=0;i<r.pts.length-1;i++){
        const a=r.pts[i], b=r.pts[i+1];
        if(window.U.pointSegDist(px,py,a.x,a.y,b.x,b.y) <= rad) return true;
      }
    }
    return false;
  }

  function pointHitsBuildings(px,py){
    for(const b of scenario.map.objects.buildings){
      if(window.U.pointInRotRect(px,py,b.x,b.y,b.w,b.h,b.angle||0)) return true;
    }
    return false;
  }

  function pointHitsFortWalls(px,py){
    for(const f of scenario.map.objects.forts){
      if(!f.pts || f.pts.length<3) continue;
      const pts=f.pts;
      const thick = Number(f.thickness)||16;
      const rad = thick/2;
      for(let i=0;i<pts.length;i++){
        const p=pts[i], q=pts[(i+1)%pts.length];
        if(window.U.pointSegDist(px,py,p.x,p.y,q.x,q.y) <= rad) return true;
      }
    }
    return false;
  }

  function pointHitsAnyObstacle(px,py){
    ensureMapObjects();
    return pointHitsRivers(px,py) || pointHitsBuildings(px,py) || pointHitsFortWalls(px,py);
  }

  function sampleLocalTokens(u, maxSamples=320){
    if(!u.layout) rebuildUnitLayout(u);
    const toks = (u.layout && u.layout.tokens) ? u.layout.tokens : [];
    const n = toks.length;
    if(n<=maxSamples) return toks;
    const step = Math.max(1, Math.floor(n / maxSamples));
    const out=[];
    for(let i=0;i<n;i+=step) out.push(toks[i]);
    return out;
  }

  function tokenStride(u){
    if(!u || !u.layout) return 1;
    const all = Math.max(1, u.layout.tokenCap || 0);
    const shown = Math.max(1, (u.layout.tokens && u.layout.tokens.length) || 0);
    return all / shown;
  }

  function buildTokenGrid(points, cell){
    const grid = new Map();
    const inv = 1 / Math.max(1e-6, cell);
    const key = (ix,iy)=> ix + "," + iy;
    for(let i=0;i<points.length;i++){
      const p = points[i];
      const ix = Math.floor(p.x * inv);
      const iy = Math.floor(p.y * inv);
      const k = key(ix,iy);
      let arr = grid.get(k);
      if(!arr){ arr=[]; grid.set(k,arr); }
      arr.push(p);
    }
    return {grid, inv, key};
  }

  function getTokenWorldPoints(u, pose=null){
    if(!u.layout) rebuildUnitLayout(u);
    const toks = (u.layout && u.layout.tokens) ? u.layout.tokens : [];
    const x = pose?.x ?? u.x;
    const y = pose?.y ?? u.y;
    const ang = pose?.ang ?? u.angle;
    const cs = Math.cos(ang||0), sn = Math.sin(ang||0);
    const pts = new Array(toks.length);
    for(let i=0;i<toks.length;i++){
      const t=toks[i];
      pts[i] = {x: x + t.x*cs - t.y*sn, y: y + t.x*sn + t.y*cs, i};
    }
    return pts;
  }

  // Returns per-token contacts and deep-overlap count for A-vs-B token sets.
  function tokenContactStats(pointsA, profA, pointsB, profB){
    const rA = tokenRadiusForProf(profA || {});
    const rB = tokenRadiusForProf(profB || {});
    const rSum = rA + rB;
    const r2 = rSum*rSum;
    const deep = Math.max(0.001, rSum * 0.7);
    const deep2 = deep*deep;
    const spacing = Math.max(3, Math.min((profA?.spacing)||6, (profB?.spacing)||6));
    const cell = Math.max(3.5, Math.max(rSum * 1.2, spacing * 1.4));
    const {grid, inv, key} = buildTokenGrid(pointsB, cell);
    const touchedA = new Uint8Array(pointsA.length);
    let deepCount = 0;

    for(let i=0;i<pointsA.length;i++){
      const p = pointsA[i];
      const ix = Math.floor(p.x * inv);
      const iy = Math.floor(p.y * inv);
      let touched = false;
      for(let dx=-1;dx<=1;dx++){
        for(let dy=-1;dy<=1;dy++){
          const arr = grid.get(key(ix+dx, iy+dy));
          if(!arr) continue;
          for(let k=0;k<arr.length;k++){
            const q=arr[k];
            const ddx = p.x - q.x;
            const ddy = p.y - q.y;
            const d2 = ddx*ddx + ddy*ddy;
            if(d2 <= r2) touched = true;
            if(d2 < deep2) deepCount++;
          }
        }
      }
      if(touched) touchedA[i] = 1;
    }
    return {touchedA, deepCount, rSum};
  }

  // Returns {ok:boolean, reason:string}
  function canPlaceUnitPose(u, x,y, ang){
    ensureMapObjects();
    if(!u || u.state==="destroyed") return {ok:true, reason:""};
    const toks = sampleLocalTokens(u, 300);
    const c=Math.cos(ang||0), s=Math.sin(ang||0);

    // quick AABB bounds check by sampling extremes (cheap)
    for(const t of toks){
      const wx = x + (t.x*c - t.y*s);
      const wy = y + (t.x*s + t.y*c);
      if(pointHitsAnyObstacle(wx,wy)) return {ok:false, reason:"obstacle"};
    }

    // unit-vs-unit collision: allies can't even touch; enemies can touch but can't deeply overlap.
    ensureLayouts();
    const movingPoints = getTokenWorldPoints(u, {x,y,ang});
    const movingProf = u.layout?.prof || {};
    for(const other of scenario.units){
      if(!other || other.id===u.id) continue;
      if(other.state==="destroyed") continue;
      if(!other.layout || !other.layout.tokens || other.layout.tokens.length===0) continue;

      const approx = (u.layout?.collisionR ?? 28) + (other.layout?.collisionR ?? 28) + 10;
      if(window.U.dist(x,y,other.x,other.y) > approx) continue;

      const otherPoints = getTokenWorldPoints(other);
      const st = tokenContactStats(movingPoints, movingProf, otherPoints, other.layout?.prof || {});
      const anyContact = st.touchedA.some(v=>v===1);
      if(!anyContact) continue;
      if(other.side===u.side) return {ok:false, reason:"ally_contact"};
      if(st.deepCount>0) return {ok:false, reason:"enemy_overlap"};
    }

    return {ok:true, reason:""};
  }

  function segmentBlocked(ax,ay,bx,by, attacker=null, target=null){
    ensureMapObjects();
    for(const b of scenario.map.objects.buildings){
      if(window.U.segIntersectsRotRect(ax,ay,bx,by,b.x,b.y,b.w,b.h,b.angle||0)) return true;
    }

    const af = attacker ? findFortAtPoint(ax,ay) : null;
    const tf = target ? findFortAtPoint(bx,by) : null;
    for(const f of scenario.map.objects.forts){
      if(!f.pts || f.pts.length<3) continue;
      if(attacker && af && af.id===f.id) continue;
      if(attacker && target && af && tf && af.id===tf.id && af.id===f.id) continue;
      if(segmentIntersectsFortWalls(ax,ay,bx,by,f)) return true;
    }
    return false;
  }

  function blocksLineOfFire(attacker, target){
    return segmentBlocked(attacker.x, attacker.y, target.x, target.y, attacker, target);
  }


  function coverPenalty(x,y){
    const t = terrainTypeAt(x,y);
    return TERRAIN_MODS.cover[t] ?? 0;
  }

  function getMoveRange(u){
    const base = u.stats.move;
    const mult = moveMultiplier(u);
    return base * mult;
  }

  function canActUnit(u){
    if(!battle.started) return true;
    if(battle.over) return false;
    if(u.state==="destroyed" || u.state==="routed") return false;
    if(u.side!==battle.active) return false;
    return true;
  }

  
// --- Engagement (melee contact) detection ---
// Important: melee is triggered only by actual figure-to-figure collision (tokens), not by formation radius.
const _engCache = {t:0, phase:null, turn:0, pairs:[]};

function tokenRadiusForProf(prof){
  let r = (prof && prof.size) ? prof.size : 1.2;
  const sh = prof ? prof.shape : "dot";
  if(sh==="rect" || sh==="sq") r *= 1.35;
  else if(sh==="tri") r *= 1.15;
  else r *= 1.10;
  return Math.max(0.9, r);
}

function tokenWorld(u, t, cs, sn){
  const x = u.x + t.x*cs - t.y*sn;
  const y = u.y + t.x*sn + t.y*cs;
  return {x,y};
}

function hasTokenContact(a, b){
  if(!a.layout || !b.layout) return false;
  const ta = a.layout.tokens || [];
  const tb = b.layout.tokens || [];
  if(ta.length===0 || tb.length===0) return false;

  // Build hash for the smaller token set
  let build=a, scan=b, tBuild=ta, tScan=tb;
  if(ta.length > tb.length){
    build=b; scan=a; tBuild=tb; tScan=ta;
  }

  const profB = build.layout.prof || {};
  const profS = scan.layout.prof || {};
  const rB = tokenRadiusForProf(profB);
  const rS = tokenRadiusForProf(profS);
  const rSum = rB + rS;
  const r2 = rSum*rSum;

  const spacing = Math.max(3, Math.min(profB.spacing||6, profS.spacing||6));
  const cell = Math.max(3.5, spacing * 1.6);
  const inv = 1 / cell;

  const csB = Math.cos(build.angle), snB = Math.sin(build.angle);
  const csS = Math.cos(scan.angle),  snS = Math.sin(scan.angle);

  const grid = new Map(); // key -> array of [x,y]
  function key(ix,iy){ return ix + "," + iy; }

  for(let i=0;i<tBuild.length;i++){
    const t = tBuild[i];
    const p = tokenWorld(build, t, csB, snB);
    const ix = Math.floor(p.x*inv);
    const iy = Math.floor(p.y*inv);
    const k = key(ix,iy);
    let arr = grid.get(k);
    if(!arr){ arr=[]; grid.set(k,arr); }
    arr.push([p.x,p.y]);
  }

  for(let i=0;i<tScan.length;i++){
    const t = tScan[i];
    const p = tokenWorld(scan, t, csS, snS);
    const ix = Math.floor(p.x*inv);
    const iy = Math.floor(p.y*inv);

    for(let dx=-1; dx<=1; dx++){
      for(let dy=-1; dy<=1; dy++){
        const arr = grid.get(key(ix+dx, iy+dy));
        if(!arr) continue;
        for(let k=0;k<arr.length;k++){
          const q = arr[k];
          const ddx = p.x - q[0];
          const ddy = p.y - q[1];
          if(ddx*ddx + ddy*ddy <= r2){
            return true;
          }
        }
      }
    }
  }
  return false;
}

function markEngagements(force=false){
  if(!force){
    const now = performance.now();
    if(_engCache.phase===battle.phase && _engCache.turn===battle.turn && (now - _engCache.t) < 140){
      return _engCache.pairs;
    }
  }

  ensureLayouts();

  const pairs = [];
  for(let i=0;i<scenario.units.length;i++){
    const a=scenario.units[i];
    if(a.state==="destroyed" || a.state==="routed") continue;

    for(let j=i+1;j<scenario.units.length;j++){
      const b=scenario.units[j];
      if(b.state==="destroyed" || b.state==="routed") continue;
      if(a.side===b.side) continue;

      const ra = a.layout?.collisionR ?? 28;
      const rb = b.layout?.collisionR ?? 28;
      const pad = tokenRadiusForProf(a.layout?.prof) + tokenRadiusForProf(b.layout?.prof);
      const d = window.U.dist(a.x,a.y,b.x,b.y);
      if(d > (ra+rb+pad)) continue;

      if(hasTokenContact(a,b)){
        pairs.push([a.id,b.id]);
      }
    }
  }

  _engCache.t = performance.now();
  _engCache.phase = battle.phase;
  _engCache.turn = battle.turn;
  _engCache.pairs = pairs;
  return pairs;
}

  function facingVector(u){
    // angle is radians, 0 means facing "up" (-y) in our layout
    const a = u.angle;
    return {x: Math.sin(a), y: -Math.cos(a)};
  }

  function flankType(def, atk){
    // returns "front"|"flank"|"rear" but chatillon and noFlanks have only "front"
    if(def.formation==="chatillon" || def.stats.noFlanks) return "front";
    const fv = facingVector(def);
    const vx = atk.x - def.x;
    const vy = atk.y - def.y;
    const len = Math.hypot(vx,vy) || 1e-9;
    const ux = vx/len, uy = vy/len;
    const dot = fv.x*ux + fv.y*uy; // 1 front, -1 rear
    const ang = Math.acos(window.U.clamp(dot,-1,1)); // 0 front, pi rear
    const deg = window.U.deg(ang);
    if(deg > 130) return "rear";
    if(deg > 70) return "flank";
    return "front";
  }

  function applyLoss(target, sizeLoss, sourceText, shock=40){
    if(sizeLoss<=0) return 0;
    const before = target.men;
    target.men = Math.max(0, target.men - sizeLoss);
    const after = target.men;
    const realLoss = before-after;

    if(realLoss>0){
      const lossPct = realLoss / Math.max(1,before);
      const moraleDrop = lossPct * shock * 100; // shock in [0.2..0.7] mapped
      target.morale = window.U.clamp(target.morale - moraleDrop, 0, 100);
      target.losses = (target.losses||0) + realLoss;
      target.dirtyLayout = true;
      if(target.men<=0){
        target.state="destroyed";
        log(`✖ ${target.name} уничтожен (${sourceText})`, "bad");
      }else{
        log(`• ${target.name}: потери ${realLoss} (${sourceText})`, "mut");
      }
    }
    return realLoss;
  }

  function xplToSizeLoss(target, xplDamage){
    const ppm = xplPerMan(target);
    if(ppm<=0) return 0;
    const frac = xplDamage / ppm;
    let loss = Math.floor(frac);
    const rem = frac - loss;
    if(Math.random() < rem) loss += 1;
    return loss;
  }

  function rangedAttack(attacker, target){
    if(!battle.started || battle.phase!=="ranged") return {ok:false, reason:"not_ranged"};
    if(!canActUnit(attacker)) return {ok:false, reason:"no_act"};
    if(attacker.firedTurn===battle.turn) return {ok:false, reason:"already_fired"};
    if(!attacker.stats.ranged) return {ok:false, reason:"no_ranged"};

    ensureLayouts();
    const r = attacker.stats.ranged.range;
    const attackerPts = getTokenWorldPoints(attacker);
    const targetPts = getTokenWorldPoints(target);
    if(attackerPts.length===0 || targetPts.length===0) return {ok:false, reason:"no_tokens"};

    const rp = window.U.clamp(battle.rp[attacker.side]||0, -3, 3);
    const cov = targetCoverBonus(target);

    let acc = attacker.stats.ranged.acc ?? 0.5;
    acc += (attacker.morale-50)/200; // +/-0.25
    acc += rp*0.03;
    acc -= cov;
    acc = window.U.clamp(acc, 0.05, 0.95);

    attacker.firedTurn = battle.turn;

    // token-level range + LoS: only models that can reach visible target models can shoot.
    const tProf = target.layout?.prof || {};
    const aProf = attacker.layout?.prof || {};
    const cell = Math.max(4, (Math.max((aProf.spacing||6), (tProf.spacing||6)) * 1.6));
    const {grid, inv, key} = buildTokenGrid(targetPts, cell);
    let inRangeTokens = 0;
    let shootersTokens = 0;
    const r2 = r*r;
    const search = Math.ceil(r / cell) + 1;

    for(let i=0;i<attackerPts.length;i++){
      const p = attackerPts[i];
      const ix = Math.floor(p.x * inv);
      const iy = Math.floor(p.y * inv);
      let best = null;
      for(let dx=-search; dx<=search; dx++){
        for(let dy=-search; dy<=search; dy++){
          const arr = grid.get(key(ix+dx, iy+dy));
          if(!arr) continue;
          for(let k=0;k<arr.length;k++){
            const q = arr[k];
            const ddx = p.x - q.x;
            const ddy = p.y - q.y;
            const d2 = ddx*ddx + ddy*ddy;
            if(d2 > r2) continue;
            if(!best || d2 < best.d2) best = {x:q.x, y:q.y, d2};
          }
        }
      }
      if(!best) continue;
      inRangeTokens++;
      if(!segmentBlocked(p.x, p.y, best.x, best.y, attacker, target)) shootersTokens++;
    }

    if(inRangeTokens===0) return {ok:false, reason:"out_of_range"};
    if(shootersTokens===0) return {ok:false, reason:"blocked"};

    const shootingModels = Math.max(0, Math.min(attacker.men, Math.round(shootersTokens * tokenStride(attacker))));
    const hit = (Math.random() < acc);
    if(!hit){
      log(`↯ ${attacker.name} стреляет по ${target.name}: промах`, "mut");
      // slight morale drain for being shot at
      target.morale = window.U.clamp(target.morale - 2, 0, 100);
      return {ok:true, hit:false, loss:0, inRangeTokens, shootersTokens, shootingModels};
    }

    // damage in XPL
    let xplDmg = (shootingModels * acc) * xplPerMan(attacker) * attacker.stats.ranged.power * window.U.rand(0.8,1.2);
    xplDmg *= (1 + rp*0.05);
    xplDmg *= (1 - (target.stats.armor||0)*0.6);

    // cap by % of target size
    const capPct = attacker.stats.ranged.capPct ?? 0.08;
    let loss = xplToSizeLoss(target, xplDmg);
    const cap = Math.max(1, Math.floor(target.men * capPct));
    loss = Math.min(loss, cap);

    const shock = 0.35 + (capPct*2.2); // ranged shock
    applyLoss(target, loss, `огонь ${attacker.name}`, shock);
    return {ok:true, hit:true, loss, inRangeTokens, shootersTokens, shootingModels};
  }

  function countEngagedModels(attacker, defender){
    ensureLayouts();
    if(!attacker.layout || !defender.layout) return 0;
    const aPts = getTokenWorldPoints(attacker);
    const dPts = getTokenWorldPoints(defender);
    if(aPts.length===0 || dPts.length===0) return 0;
    const st = tokenContactStats(aPts, attacker.layout.prof || {}, dPts, defender.layout.prof || {});
    let touched = 0;
    for(let i=0;i<st.touchedA.length;i++) if(st.touchedA[i]) touched++;
    const models = Math.round(touched * tokenStride(attacker));
    return Math.max(0, Math.min(attacker.men, models));
  }

  function resolveMeleePhase(){
    if(!battle.started || battle.phase!=="melee") return;
    const pairs = markEngagements(true);
    if(pairs.length===0){
      log("— Ближнего боя нет (контактов не обнаружено)", "mut");
      return;
    }

    log(`⚔ Ближний бой: контактов ${pairs.length}`, "mut");

    for(const [ida,idb] of pairs){
      const a=getUnit(ida), b=getUnit(idb);
      if(!a||!b) continue;
      if(a.state!=="ready" && a.state!=="engaged" && a.state!=="") {}
      if(a.state==="destroyed"||a.state==="routed") continue;
      if(b.state==="destroyed"||b.state==="routed") continue;

      const fa = flankType(a,b);
      const fb = flankType(b,a);

      const rpA = window.U.clamp(battle.rp[a.side]||0, -3, 3);
      const rpB = window.U.clamp(battle.rp[b.side]||0, -3, 3);

      const engagedA = countEngagedModels(a, b);
      const engagedB = countEngagedModels(b, a);
      if(engagedA<=0 && engagedB<=0) continue;

      let aMul = 1.0, bMul = 1.0;
      if(fa==="flank") aMul *= 1.25;
      if(fa==="rear")  aMul *= 1.45;
      if(fb==="flank") bMul *= 1.25;
      if(fb==="rear")  bMul *= 1.45;

      // pikes vs cav bonus
      if(a.stats.tags && a.stats.tags.includes("antiCav") && (b.kind==="cav"||b.kind==="heavycav")) aMul*=1.25;
      if(b.stats.tags && b.stats.tags.includes("antiCav") && (a.kind==="cav"||a.kind==="heavycav")) bMul*=1.25;

      // morale effect on melee power
      const aMor = window.U.clamp(a.morale/100, 0.4, 1.15);
      const bMor = window.U.clamp(b.morale/100, 0.4, 1.15);

      let xplDmgToB = engagedA * xplPerMan(a) * a.stats.melee.power * window.U.rand(0.85,1.25) * aMul * aMor * (1 + rpA*0.05);
      let xplDmgToA = engagedB * xplPerMan(b) * b.stats.melee.power * window.U.rand(0.85,1.25) * bMul * bMor * (1 + rpB*0.05);

      xplDmgToB *= (1 - (b.stats.armor||0)*0.6);
      xplDmgToA *= (1 - (a.stats.armor||0)*0.6);

      let lossB = xplToSizeLoss(b, xplDmgToB);
      let lossA = xplToSizeLoss(a, xplDmgToA);

      // cap (melee tends to be bloodier)
      const capB = Math.max(1, Math.floor(b.men * (a.stats.melee.capPct ?? 0.18)));
      const capA = Math.max(1, Math.floor(a.men * (b.stats.melee.capPct ?? 0.18)));
      lossB = Math.min(lossB, capB);
      lossA = Math.min(lossA, capA);

      // extra shock when flanked/rear-hit
      const shockB = (fa==="rear") ? 0.80 : (fa==="flank") ? 0.65 : 0.55;
      const shockA = (fb==="rear") ? 0.80 : (fb==="flank") ? 0.65 : 0.55;

      const la = applyLoss(a, lossA, `ближний бой с ${b.name} (${fb})`, shockA);
      const lb = applyLoss(b, lossB, `ближний бой с ${a.name} (${fa})`, shockB);

      if(la>0 || lb>0){
        if(fa!=="front" || fb!=="front"){
          log(`↺ Фланги: ${a.name} атакует ${b.name} (${fa}), ${b.name} атакует ${a.name} (${fb})`, "mut");
        }
      }
    }
  }

  function resolveMoralePhase(){
    if(!battle.started || battle.phase!=="morale") return;
    let routed=0;

    for(const u of scenario.units){
      if(u.state==="destroyed"||u.state==="routed") continue;
      // losses-based morale recovery/decay
      const lossPct = (u.losses||0) / Math.max(1, u.startSizeStartBattle||u.men||1);
      // small passive decay if heavily engaged
      if(lossPct>0.15) u.morale = window.U.clamp(u.morale - 1.5, 0, 100);

      const rp = window.U.clamp(battle.rp[u.side]||0, -3, 3);
      const discipline = (u.stats.morale||60) / 100; // 0.5..0.9
      let threshold = 28 + (discipline*10) + rp*2; // higher discipline => safer
      threshold = window.U.clamp(threshold, 20, 45);

      if(u.morale < threshold){
        // rout check
        const roll = window.U.randi(1,100);
        const chance = window.U.clamp((threshold - u.morale)*2.2, 5, 65);
        if(roll <= chance){
          u.state="routed";
          routed++;
          log(`‼ ${u.name} бежит (мораль ${u.morale.toFixed(0)}; шанс ${chance.toFixed(0)}%; бросок ${roll})`, "bad");
        }else{
          log(`… ${u.name} держится (мораль ${u.morale.toFixed(0)}; шанс ${chance.toFixed(0)}%; бросок ${roll})`, "mut");
        }
      }
    }

    if(routed===0) log("— Паники нет: все удержали строй", "ok");
  }

  function checkBattleEnd(){
    const bStart = sideStartXpl("blue");
    const rStart = sideStartXpl("red");
    const bNow = totalXpl("blue");
    const rNow = totalXpl("red");

    const bAlive = scenario.units.some(u=>u.side==="blue" && u.state!=="destroyed" && u.state!=="routed");
    const rAlive = scenario.units.some(u=>u.side==="red"  && u.state!=="destroyed" && u.state!=="routed");

    if(!bAlive || bNow < bStart*0.20){
      battle.over=true; battle.winner="red";
      log(`🏁 Бой окончен: победа Красных (Синие ${bNow.toFixed(2)}/${bStart.toFixed(2)} XPL)`, "bad");
    } else if(!rAlive || rNow < rStart*0.20){
      battle.over=true; battle.winner="blue";
      log(`🏁 Бой окончен: победа Синих (Красные ${rNow.toFixed(2)}/${rStart.toFixed(2)} XPL)`, "ok");
    }
  }

  function startBattle(){
    if(battle.started) return;
    battle.started=true;
    battle.turn=1;
    battle.phase="movement";
    battle.active="blue";
    battle.over=false;
    battle.winner=null;

    for(const u of scenario.units){
      u.startSizeStartBattle = u.men|0;
      u.losses = 0;
      u.firedTurn = 0;
      u.movedTurn = 0;
      u.state = "ready";
      u.moraleBase = u.stats.morale;
      u.morale = u.moraleBase;
      u.dirtyLayout = true;
    }
    log("▶ Старт боя. Ход 1: движение Синих.", "ok");
  }

  function nextPhase(){
    if(!battle.started) return;

    if(battle.over) return;

    if(battle.phase==="movement"){
      battle.phase="ranged";
      log(`▶ Фаза стрельбы: ${battle.active==="blue" ? "Синие" : "Красные"}.`, "ok");
    } else if(battle.phase==="ranged"){
      battle.phase="melee";
      log("▶ Фаза ближнего боя (разрешение контактов).", "ok");
      resolveMeleePhase();
    } else if(battle.phase==="melee"){
      battle.phase="morale";
      log("▶ Фаза морали (проверки паники).", "ok");
      resolveMoralePhase();
      checkBattleEnd();
    } else if(battle.phase==="morale"){
      // swap active and increment turn if blue->red? Actually alternate per-side full phases.
      if(battle.active==="blue"){
        battle.active="red";
        battle.phase="movement";
        log("▶ Ход продолжается: движение Красных.", "ok");
      }else{
        battle.active="blue";
        battle.turn += 1;
        battle.phase="movement";
        // reset per-turn flags
        for(const u of scenario.units){
          u.firedTurn = 0;
          u.movedTurn = 0;
        }
        log(`▶ Ход ${battle.turn}: движение Синих.`, "ok");
      }
    }
  }

  // --- Terrain generation (value noise + marching squares contours) ---
  function genNoise(seed, w, h, scale=0.012){
    const data = new Float32Array(w*h);
    let s = seed>>>0;
    const rnd = ()=> (s = (s*1664525 + 1013904223)>>>0) / 4294967296;
    const grads = new Float32Array((w+2)*(h+2));
    for(let i=0;i<grads.length;i++) grads[i]=rnd()*2-1;
    const smoothstep=t=>t*t*(3-2*t);
    function sample(x,y){
      const xi=Math.floor(x), yi=Math.floor(y);
      const xf=x-xi, yf=y-yi;
      const a=grads[yi*(w+2)+xi];
      const b=grads[yi*(w+2)+xi+1];
      const c=grads[(yi+1)*(w+2)+xi];
      const d=grads[(yi+1)*(w+2)+xi+1];
      const u=smoothstep(xf), v=smoothstep(yf);
      return U.lerp(U.lerp(a,b,u), U.lerp(c,d,u), v);
    }
    for(let y=0;y<h;y++){
      for(let x=0;x<w;x++){
        const nx=x*scale, ny=y*scale;
        data[y*w+x]=sample(nx,ny);
      }
    }
    return data;
  }

  function computeContours(field,W,H,levels){
    const segsByLevel = levels.map(()=>[]);
    const idx = (x,y)=>field[y*W+x];
    const interp=(a,b,t)=>a+(b-a)*t;

    for(let li=0; li<levels.length; li++){
      const L=levels[li];
      const segs=[];
      for(let y=0;y<H-1;y++){
        for(let x=0;x<W-1;x++){
          const v0=idx(x,y), v1=idx(x+1,y), v2=idx(x+1,y+1), v3=idx(x,y+1);
          let mask=0;
          if(v0>L) mask|=1;
          if(v1>L) mask|=2;
          if(v2>L) mask|=4;
          if(v3>L) mask|=8;
          if(mask===0 || mask===15) continue;

          const x0=x, y0=y;
          const t01 = (L-v0)/(v1-v0);
          const t12 = (L-v1)/(v2-v1);
          const t23 = (L-v3)/(v2-v3);
          const t30 = (L-v0)/(v3-v0);

          const e0 = {x: interp(x0, x0+1, t01), y: y0};
          const e1 = {x: x0+1, y: interp(y0, y0+1, t12)};
          const e2 = {x: interp(x0, x0+1, t23), y: y0+1};
          const e3 = {x: x0, y: interp(y0, y0+1, t30)};

          switch(mask){
            case 1: case 14: segs.push([e3,e0]); break;
            case 2: case 13: segs.push([e0,e1]); break;
            case 3: case 12: segs.push([e3,e1]); break;
            case 4: case 11: segs.push([e1,e2]); break;
            case 5: segs.push([e3,e0]); segs.push([e1,e2]); break;
            case 6: case 9: segs.push([e0,e2]); break;
            case 7: case 8: segs.push([e3,e2]); break;
            case 10: segs.push([e0,e1]); segs.push([e2,e3]); break;
          }
        }
      }
      segsByLevel[li]=segs;
    }
    return {levels, segsByLevel, W, H};
  }

  function buildTerrain(){
    const W=220, H=140;
    const preset=scenario.map.preset;
    let scale=0.03;
    if(preset==="plains") scale=0.028;
    if(preset==="hills") scale=0.02;
    if(preset==="ridge") scale=0.018;
    if(preset==="marsh") scale=0.025;

    const base = genNoise(scenario.map.seed, W, H, scale);
    const out = new Float32Array(W*H);
    for(let y=0;y<H;y++){
      for(let x=0;x<W;x++){
        let v=base[y*W+x]*0.6;
        const fx=x/(W-1), fy=y/(H-1);
        if(preset==="ridge"){
          const ridge = Math.abs(fx-0.5);
          v += (0.35 - ridge)*0.9;
        }
        if(preset==="marsh"){
          v -= 0.18;
          v += Math.sin(fx*8)*0.05 + Math.cos(fy*7)*0.04;
        }
        if(preset==="plains"){
          v *= 0.6;
        }
        out[y*W+x]=v;
      }
    }
    scenario.map.noise = {W,H,data:out};
    scenario.map.contours = computeContours(out,W,H,[ -0.35,-0.2,-0.05,0.1,0.25,0.4 ]);
  }

  // --- Formation layout (WORLD units) ---
  function rebuildUnitLayout(u){
    const prof = TOKEN_PROFILES[u.kind] || TOKEN_PROFILES.inf;
    const cell = prof.spacing;

    const tokenCap = Math.max(1, u.men|0);
    const maxTokens = Math.min(tokenCap, scenario.map.lod ? 3500 : 25000);
    const tokens=[];

    const pushToken=(x,y)=>tokens.push({x,y});

    let footprint = {shape:"rect", w:40,h:40};

    if(u.formation==="line"){
      const cols = Math.max(2, Math.ceil(Math.sqrt(maxTokens)*1.6));
      const rows = Math.ceil(maxTokens/cols);
      const w = cols*cell;
      const h = rows*cell;
      let k=0;
      for(let r=0;r<rows;r++){
        for(let c=0;c<cols;c++){
          if(k>=maxTokens) break;
          const x=(c-(cols-1)/2)*cell;
          const y=(r-(rows-1)/2)*cell;
          pushToken(x,y);
          k++;
        }
      }
      footprint={shape:"rect", w, h};
    }
    else if(u.formation==="sleeve"){
      const rows = 6;
      const cols = Math.ceil(maxTokens/rows);
      const w = cols*cell;
      const h = rows*cell;
      let k=0;
      for(let r=0;r<rows;r++){
        for(let c=0;c<cols;c++){
          if(k>=maxTokens) break;
          const x=(c-(cols-1)/2)*cell;
          const y=(r-(rows-1)/2)*cell;
          pushToken(x,y);
          k++;
        }
      }
      footprint={shape:"rect", w, h};
    }
    else if(u.formation==="block"){
      const side = Math.ceil(Math.sqrt(maxTokens));
      const cols = side, rows = Math.ceil(maxTokens/side);
      const w = cols*cell;
      const h = rows*cell;
      let k=0;
      for(let r=0;r<rows;r++){
        for(let c=0;c<cols;c++){
          if(k>=maxTokens) break;
          const x=(c-(cols-1)/2)*cell;
          const y=(r-(rows-1)/2)*cell;
          pushToken(x,y);
          k++;
        }
      }
      footprint={shape:"rect", w, h};
    }
    else if(u.formation==="wedge"){
      const rows = Math.ceil(Math.sqrt(maxTokens));
      const maxCols = rows*2-1;
      const length = rows*cell;
      const width = maxCols*cell;
      let k=0;
      for(let r=0;r<rows;r++){
        const cols = r*2+1;
        const y = (r - (rows-1)/2)*cell;
        for(let c=0;c<cols;c++){
          if(k>=maxTokens) break;
          const x = (c-(cols-1)/2)*cell;
          pushToken(x,y);
          k++;
        }
      }
      footprint={shape:"wedge", length, width};
    }
    else if(u.formation==="chatillon"){
      // donut fill
      const targetArea = maxTokens * (cell*cell);
      const outerR = Math.max(18, Math.sqrt(targetArea/Math.PI));
      const innerR = outerR*0.45;
      let k=0;
      for(let r=innerR; r<=outerR; r+=cell){
        const circ = Math.max(6, Math.floor((2*Math.PI*r)/cell));
        for(let i=0;i<circ;i++){
          if(k>=maxTokens) break;
          const ang = (i/circ)*Math.PI*2;
          const x = Math.cos(ang)*r;
          const y = Math.sin(ang)*r;
          pushToken(x,y);
          k++;
        }
        if(k>=maxTokens) break;
      }
      footprint={shape:"ring", outerR, innerR};
    }

    // collision radius for engagements/labels
    let collisionR = 28;
    if(footprint.shape==="ring") collisionR = footprint.outerR;
    else if(footprint.shape==="wedge") collisionR = Math.max(footprint.length, footprint.width)*0.55;
    else collisionR = Math.max(footprint.w, footprint.h)*0.55;

    u.layout = {
      prof,
      tokens,
      tokenCap,
      footprint,
      collisionR,
    };
    u.dirtyLayout = false;
  }

  function ensureLayouts(){
    for(const u of scenario.units){
      if(!u.layout || u.dirtyLayout) rebuildUnitLayout(u);
    }
  }

  function addUnitFromUI(payload){
    const tpl = UNIT_CATALOG[payload.type];
    if(!tpl) return null;

    const id = U.uid();
    const name = payload.name?.trim() || tpl.name;
    const u = {
      id,
      type: payload.type,
      name,
      side: payload.side,
      formation: payload.formation,
      kind: tpl.kind,
      baseSize: payload.baseSize|0,
      baseXpl: +payload.baseXpl,
      men: payload.men|0,
      x: payload.x ?? (payload.side==="blue" ? -520 : 520),
      y: payload.y ?? (payload.side==="blue" ? 240 : -240),
      // 0 rad looks to the top of the map (-Y), so blue (top side) should face down to enemy.
      angle: payload.side==="blue" ? Math.PI : 0,
      state: "ready",
      losses: 0,
      firedTurn: 0,
      movedTurn: 0,
      stats: {
        move: tpl.move,
        ranged: tpl.ranged || null,
        melee: tpl.melee || {power:0.02, capPct:0.12},
        armor: tpl.armor || 0,
        morale: tpl.morale || 60,
        tags: tpl.tags || null,
        noFlanks: !!tpl.noFlanks
      },
      moraleBase: tpl.morale || 60,
      morale: tpl.morale || 60,
      dirtyLayout: true,
      layout: null,
      startSizeStartBattle: null,
    };

    scenario.units.push(u);
    rebuildUnitLayout(u);
    log(`＋ Добавлен отряд: ${u.name} (${u.side}, ${u.men})`, "ok");
    return u;
  }

  function removeUnit(u){
    const i=scenario.units.findIndex(x=>x.id===u.id);
    if(i>=0) scenario.units.splice(i,1);
    if(scenario.selectedId===u.id) scenario.selectedId=null;
    log(`− Удалён отряд: ${u.name}`, "mut");
  }

  function resetAll(){
    scenario.units.length=0;
    scenario.selectedId=null;
    battle.started=false;
    battle.over=false;
    battle.winner=null;
    battle.turn=0;
    battle.phase="setup";
    battle.active="blue";
    LOG.length=0;
    scenario.map.seed = (Math.random()*1e9)|0;
    scenario.map.noise=null;
    scenario.map.contours=null;
    buildTerrain();
    log("Сценарий сброшен.", "mut");
  }

  // init terrain once
  buildTerrain();

  window.ENGINE = {
    scenario,
    battle,
    LOG,
    log,

    getUnit,
    getSelected,
    selectUnit,

    computeXpl,
    totalXpl,
    ensureLayouts,

    terrainTypeAt,
    getMoveRange,

    // map I/O
    serializeMap,
    loadMap,
    clearMapObjects,

    // forts
    updateUnitGarrison,
    findFortAtPoint,

    addUnitFromUI,
    removeUnit,
    resetAll,

    startBattle,
    nextPhase,

    rangedAttack,
    resolveMeleePhase,
    resolveMoralePhase,
    checkBattleEnd,

    rebuildUnitLayout,
    markEngagements,
    flankType,
    canPlaceUnitPose,
  };
})();
