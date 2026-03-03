(function(){
  "use strict";

  const canvas = document.getElementById("c");
  const ctx = canvas.getContext("2d", {alpha:false, desynchronized:true});
  ctx.imageSmoothingEnabled = true;

  // Hard fail early if script order is broken (prevents cryptic destructure errors)
  if(!window.DATA) throw new Error("DATA not loaded: ensure js/data.js is loaded before js/render.js");
  if(!window.U) throw new Error("U not loaded: ensure js/utils.js is loaded before js/render.js");
  if(!window.ENGINE) throw new Error("ENGINE not loaded: ensure js/engine.js loads without errors before js/render.js");

  const {COLORS, TERRAIN_ZONES} = window.DATA;
  const {scenario, battle} = window.ENGINE;
  // Convenience alias for engine helpers (getSelected/getUnit/log/etc.)
  const E = window.ENGINE;
  const U = window.U;


  // cached natural ground pattern
  let _grassPattern=null;
  function getGrassPattern(){
    if(_grassPattern) return _grassPattern;
    const c = document.createElement("canvas");
    c.width = 256; c.height = 256;
    const g = c.getContext("2d");
    g.fillStyle = "rgb(48,74,52)";
    g.fillRect(0,0,c.width,c.height);

    const rng = U.mulberry32((scenario.map.seed||12345)>>>0);
    // noise specks
    for(let i=0;i<7000;i++){
      const x = (rng()*c.width)|0;
      const y = (rng()*c.height)|0;
      const a = 0.10 + rng()*0.20;
      g.fillStyle = `rgba(255,255,255,${a*0.06})`;
      g.fillRect(x,y,1,1);
    }
    // darker patches
    for(let i=0;i<220;i++){
      const x = rng()*c.width, y = rng()*c.height;
      const r = 10 + rng()*36;
      g.beginPath();
      g.arc(x,y,r,0,Math.PI*2);
      g.fillStyle = `rgba(0,0,0,${0.06 + rng()*0.08})`;
      g.fill();
    }
    // subtle dry strokes
    g.globalAlpha = 0.12;
    for(let i=0;i<140;i++){
      const x = rng()*c.width, y = rng()*c.height;
      const len = 18 + rng()*60;
      const ang = rng()*Math.PI*2;
      g.strokeStyle = "rgba(180,170,120,0.35)";
      g.lineWidth = 1;
      g.beginPath();
      g.moveTo(x,y);
      g.lineTo(x + Math.cos(ang)*len, y + Math.sin(ang)*len);
      g.stroke();
    }
    g.globalAlpha = 1;

    _grassPattern = ctx.createPattern(c,"repeat");
    return _grassPattern;
  }

  const TAU = Math.PI * 2;


  

  // Cached terrain overlay to avoid "grid squares" look.
  let _fieldOverlay = null;
  let _fieldOverlayKey = "";

  function _noiseMeta(){
    const n = scenario.map.noise;
    if(!n || !n.data) return null;
    const W = Number(n.W ?? n.w);
    const H = Number(n.H ?? n.h);
    if(!W || !H) return null;
    return {n,W,H};
  }

  function getFieldOverlayCanvas(){
    const meta = _noiseMeta();
    if(!meta) return null;

    const preset = scenario.map.preset || "plains";
    const seed = (scenario.map.seed||0)>>>0;
    const key = `${seed}|${preset}|${meta.W}x${meta.H}`;
    if(_fieldOverlay && _fieldOverlayKey===key) return _fieldOverlay;

    const {n,W,H} = meta;
    // Find min/max once
    let mn=1e9, mx=-1e9;
    const arr = n.data;
    for(let i=0;i<arr.length;i++){
      const v = arr[i];
      if(v<mn) mn=v;
      if(v>mx) mx=v;
    }
    const span = (mx-mn) || 1;

    const sampleNorm = (u,v)=>{
      u = Math.max(0, Math.min(1, u));
      v = Math.max(0, Math.min(1, v));
      const x = u*(W-1);
      const y = v*(H-1);
      const x0 = x|0, y0 = y|0;
      const x1 = Math.min(W-1, x0+1);
      const y1 = Math.min(H-1, y0+1);
      const tx = x - x0;
      const ty = y - y0;
      const i00 = y0*W + x0;
      const i10 = y0*W + x1;
      const i01 = y1*W + x0;
      const i11 = y1*W + x1;
      const a = (arr[i00]-mn)/span;
      const b = (arr[i10]-mn)/span;
      const c = (arr[i01]-mn)/span;
      const d = (arr[i11]-mn)/span;
      const ab = a + (b-a)*tx;
      const cd = c + (d-c)*tx;
      return ab + (cd-ab)*ty;
    };

    const c = document.createElement("canvas");
    c.width = 512; c.height = 512;
    const g = c.getContext("2d", {willReadFrequently:true});
    const img = g.createImageData(c.width, c.height);
    const data = img.data;

    // Directional "sun" from top-left
    const lx = -0.65, ly = -0.45;

    for(let y=0;y<c.height;y++){
      const v = y/(c.height-1);
      for(let x=0;x<c.width;x++){
        const u = x/(c.width-1);
        const h0 = sampleNorm(u,v);

        // slope shading
        const du = 1/(c.width-1);
        const dv = 1/(c.height-1);
        const hx = sampleNorm(u+du, v) - sampleNorm(u-du, v);
        const hy = sampleNorm(u, v+dv) - sampleNorm(u, v-dv);
        const slope = (hx*lx + hy*ly);

        // micro variation for "fields"
        const stripes = Math.sin((u*22 + v*18 + h0*3.0)*TAU) * 0.06;
        const speck = Math.sin((u*67 + v*59)*TAU) * 0.02;

        let s = (h0-0.5)*0.55 + slope*1.10 + stripes*0.35 + speck*0.25;

        // presets nuance
        if(preset==="marsh"){
          s -= 0.08;
        }else if(preset==="hills" || preset==="ridge"){
          s += (h0-0.5)*0.20;
        }

        // turn into subtle lighten/darken overlay
        const a = Math.min(0.30, Math.abs(s)) * 255;
        const idx = (y*c.width + x)*4;
        if(s>=0){
          data[idx+0]=255;
          data[idx+1]=255;
          data[idx+2]=255;
          data[idx+3]=a*0.65;
        }else{
          data[idx+0]=0;
          data[idx+1]=0;
          data[idx+2]=0;
          data[idx+3]=a*0.75;
        }
      }
    }

    g.putImageData(img,0,0);

    _fieldOverlay = c;
    _fieldOverlayKey = key;
    return _fieldOverlay;
  }

  function drawReliefOverlay(mapW, mapH){
    // Smooth overlay
    const ov = getFieldOverlayCanvas();
    if(ov){
      ctx.save();
      ctx.globalAlpha = 0.55;
      ctx.imageSmoothingEnabled = true;
      ctx.drawImage(ov, -mapW/2, -mapH/2, mapW, mapH);
      ctx.restore();
    }

    // Contours (if provided by engine)
    const c = scenario.map.contours;
    if(c && c.segsByLevel && c.W && c.H){
      const CW = c.W, CH = c.H;
      ctx.save();
      ctx.globalAlpha = 0.16;
      ctx.lineWidth = Math.max(0.6, 1 / view.zoom);
      for(let li=0; li<c.segsByLevel.length; li++){
        const segs = c.segsByLevel[li];
        if(!segs || !segs.length) continue;
        // darker for higher levels
        ctx.strokeStyle = (li < (c.segsByLevel.length*0.5)) ? "rgba(0,0,0,0.35)" : "rgba(255,255,255,0.22)";
        ctx.beginPath();
        for(const seg of segs){
          const a = seg[0], b = seg[1];
          const ax = -mapW/2 + (a.x / CW)*mapW;
          const ay = -mapH/2 + (a.y / CH)*mapH;
          const bx = -mapW/2 + (b.x / CW)*mapW;
          const by = -mapH/2 + (b.y / CH)*mapH;
          ctx.moveTo(ax,ay);
          ctx.lineTo(bx,by);
        }
        ctx.stroke();
      }
      ctx.restore();
    }
  }
const view = {
    zoom: 1.0,
    minZ: 0.25,
    maxZ: 3.5,
    _initialized: false,
    _fitZ: 1.0,
    cx: 0, cy: 0,   // screen center
    ox: 0, // camera center X (world coords)
    oy: 0,
  };

  function resize(){
    const dpr = Math.max(1, Math.min(2, window.devicePixelRatio||1));
    const rect = canvas.getBoundingClientRect();
    canvas.width = Math.floor(rect.width * dpr);
    canvas.height = Math.floor(rect.height * dpr);
    ctx.setTransform(dpr,0,0,dpr,0,0);
    view.cx = rect.width/2;
    view.cy = rect.height/2;
    if(!view._initialized){
      view._initialized = true;
      fitToViewport();
    } else {
      clampPan();
    }
  }
  window.addEventListener("resize", resize, {passive:true});

function fitToViewport(){
  const rect = canvas.getBoundingClientRect();
  const w = scenario.map.w, h = scenario.map.h;
  if(rect.width < 10 || rect.height < 10) return;
  const zFit = Math.min(rect.width / w, rect.height / h);
  view._fitZ = zFit;
  // Keep user zoom bounds sensible relative to fit
  view.minZ = Math.max(0.06, zFit * 0.25);
  view.maxZ = Math.max(3.5, zFit * 8);
  view.zoom = U.clamp(zFit, view.minZ, view.maxZ);
  view.ox = 0;
  view.oy = 0;
  clampPan();
}
  resize();

  function worldToScreen(x,y){
    const sx = (x - view.ox) * view.zoom + view.cx;
    const sy = (y - view.oy) * view.zoom + view.cy;
    return {x:sx, y:sy};
  }
  function screenToWorld(x,y){
    const wx = (x - view.cx) / view.zoom + view.ox;
    const wy = (y - view.cy) / view.zoom + view.oy;
    return {x:wx, y:wy};
  }

  function setWorldTransform(){
    // Maps world->screen
    const tx = view.cx - view.ox * view.zoom;
    const ty = view.cy - view.oy * view.zoom;
    ctx.setTransform(view.zoom, 0, 0, view.zoom, tx, ty);
  }

  function resetTransform(){
    ctx.setTransform(1,0,0,1,0,0);
  }

  function clampPan(){
  // Camera center is (ox,oy) in world coords; world is centered at (0,0) with bounds [-w/2..w/2]
  const pad = 120;
  const w = scenario.map.w, h = scenario.map.h;
  const rect = canvas.getBoundingClientRect();
  const vw = rect.width / view.zoom;
  const vh = rect.height / view.zoom;

  const halfW = w/2, halfH = h/2;
  const minOx = -halfW + vw/2 - pad;
  const maxOx =  halfW - vw/2 + pad;
  const minOy = -halfH + vh/2 - pad;
  const maxOy =  halfH - vh/2 + pad;

  if(minOx > maxOx){
    view.ox = 0;
  }else{
    view.ox = U.clamp(view.ox, minOx, maxOx);
  }
  if(minOy > maxOy){
    view.oy = 0;
  }else{
    view.oy = U.clamp(view.oy, minOy, maxOy);
  }
}


  function zoomAtScreen(px,py, zNew){
    const z0 = view.zoom;
    const z1 = U.clamp(zNew, view.minZ, view.maxZ);
    if(Math.abs(z1-z0)<1e-6) return;
    const w0 = screenToWorld(px,py);
    view.zoom = z1;
    const w1 = screenToWorld(px,py);
    // shift so that the world point stays under cursor
    view.ox += (w0.x - w1.x);
    view.oy += (w0.y - w1.y);
    clampPan();
  }

  function drawTerrain(){
    // background outside map
    resetTransform();
    const H = canvas.getBoundingClientRect().height || canvas.height;
    const g = ctx.createLinearGradient(0,0,0,H);
    g.addColorStop(0, "rgb(12,18,22)");
    g.addColorStop(1, "rgb(6,8,10)");
    ctx.fillStyle = g;
    ctx.fillRect(0,0,canvas.width,canvas.height);

    setWorldTransform();

    const w = scenario.map.w, h = scenario.map.h;
    // ground
    ctx.fillStyle = getGrassPattern();
    ctx.fillRect(-w/2, -h/2, w, h);

    // terrain relief from noise field (smooth overlay + contours)
    drawReliefOverlay(w, h);

    // terrain zones tint (forests/hills/swamp) for readability
    for(const z of TERRAIN_ZONES){
      ctx.save();
      ctx.globalAlpha = 0.18;
      if(z.type==="forest") ctx.fillStyle = "rgb(22,44,26)";
      else if(z.type==="hill") ctx.fillStyle = "rgb(96,86,62)";
      else if(z.type==="swamp") ctx.fillStyle = "rgb(34,52,44)";
      else ctx.fillStyle = "rgb(64,64,64)";
      ctx.beginPath();
      for(let i=0;i<z.poly.length;i++){
        const p=z.poly[i];
        if(i===0) ctx.moveTo(p.x,p.y); else ctx.lineTo(p.x,p.y);
      }
      ctx.closePath();
      ctx.fill();
      ctx.restore();
    }

    // map objects (rivers/buildings/forts)
    drawMapObjectsWorld();

    // border
    ctx.save();
    ctx.lineWidth = 6 / view.zoom;
    ctx.strokeStyle = "rgba(255,255,255,0.14)";
    ctx.strokeRect(-w/2, -h/2, w, h);
    ctx.restore();
  }


  function drawMapObjectsWorld(){
    const objs = scenario.map.objects || {};
    if(objs.rivers){
      for(const r of objs.rivers) drawRiverWorld(r);
    }
    if(objs.forts){
      for(const f of objs.forts) drawFortWorld(f);
    }
    if(objs.buildings){
      for(const b of objs.buildings) drawBuildingWorld(b);
    }
  }

  
  function _traceSmoothPolyline(pts, closed){
    if(!pts || pts.length<2) return;
    if(pts.length===2){
      ctx.moveTo(pts[0].x, pts[0].y);
      ctx.lineTo(pts[1].x, pts[1].y);
      return;
    }
    // Quadratic smoothing through midpoints
    const last = pts.length-1;
    ctx.moveTo(pts[0].x, pts[0].y);
    for(let i=1;i<last;i++){
      const p = pts[i];
      const n = pts[i+1];
      const mx = (p.x+n.x)*0.5;
      const my = (p.y+n.y)*0.5;
      ctx.quadraticCurveTo(p.x,p.y,mx,my);
    }
    // tail
    ctx.lineTo(pts[last].x, pts[last].y);

    if(closed){
      // close gently
      ctx.lineTo(pts[0].x, pts[0].y);
      ctx.closePath();
    }
  }

  function drawRiverWorld(r){
    if(!r || !Array.isArray(r.pts) || r.pts.length<2) return;

    // Width is WORLD units (scales with zoom)
    const w = Math.max(2, Number(r.width)||20);

    ctx.save();
    ctx.lineJoin="round";
    ctx.lineCap="round";

    // river bed
    ctx.strokeStyle = "rgba(0,0,0,0.32)";
    ctx.lineWidth = (w*1.35);
    ctx.beginPath();
    _traceSmoothPolyline(r.pts,false);
    ctx.stroke();

    // water
    ctx.strokeStyle = "rgba(56,120,150,0.92)";
    ctx.lineWidth = w;
    ctx.beginPath();
    _traceSmoothPolyline(r.pts,false);
    ctx.stroke();

    // highlight (thin, screen-stable for readability)
    ctx.strokeStyle = "rgba(255,255,255,0.10)";
    ctx.lineWidth = Math.max(0.6, 1 / view.zoom);
    ctx.beginPath();
    _traceSmoothPolyline(r.pts,false);
    ctx.stroke();

    ctx.restore();
  }


  function drawBuildingWorld(b){
    if(!b) return;
    const x=Number(b.x)||0, y=Number(b.y)||0, w=Number(b.w)||80, h=Number(b.h)||60, a=Number(b.angle)||0;

    ctx.save();
    ctx.translate(x,y);
    ctx.rotate(a);

    ctx.fillStyle = "rgba(94,74,52,0.95)";
    ctx.strokeStyle = "rgba(10,10,10,0.55)";
    ctx.lineWidth = 2 / view.zoom;
    ctx.fillRect(-w/2, -h/2, w, h);
    ctx.strokeRect(-w/2, -h/2, w, h);

    // roof hint
    ctx.strokeStyle = "rgba(255,255,255,0.10)";
    ctx.lineWidth = 1 / view.zoom;
    ctx.beginPath();
    ctx.moveTo(-w/2,0);
    ctx.lineTo(0,-h/2);
    ctx.lineTo(w/2,0);
    ctx.stroke();

    ctx.restore();
  }

  
  function drawFortWorld(f){
    if(!f || !Array.isArray(f.pts) || f.pts.length<3) return;
    const pts=f.pts;
    // Thickness/radius are WORLD units (scale with zoom)
    const thick = Math.max(2, Number(f.thickness)||16);
    const towerR = Math.max(2, Number(f.towerR)||20);

    // wall stroke
    ctx.save();
    ctx.lineJoin="round";
    ctx.lineCap="round";
    ctx.strokeStyle = "rgba(70,70,78,0.95)";
    ctx.lineWidth = thick;
    ctx.beginPath();
    for(let i=0;i<pts.length;i++){
      const p=pts[i];
      if(i===0) ctx.moveTo(p.x,p.y); else ctx.lineTo(p.x,p.y);
    }
    ctx.closePath();
    ctx.stroke();

    // inner highlight (screen-stable thin)
    ctx.strokeStyle = "rgba(255,255,255,0.10)";
    ctx.lineWidth = Math.max(0.7, 1 / view.zoom);
    ctx.beginPath();
    for(let i=0;i<pts.length;i++){
      const p=pts[i];
      if(i===0) ctx.moveTo(p.x,p.y); else ctx.lineTo(p.x,p.y);
    }
    ctx.closePath();
    ctx.stroke();

    // towers
    for(const p of pts){
      ctx.fillStyle = "rgba(55,55,60,0.95)";
      ctx.beginPath();
      ctx.arc(p.x,p.y,towerR,0,Math.PI*2);
      ctx.fill();
      ctx.strokeStyle = "rgba(0,0,0,0.45)";
      ctx.lineWidth = Math.max(1.0, 2 / view.zoom);
      ctx.stroke();
    }

    ctx.restore();
  }


  function drawEditorWorld(){
    const ed = scenario.map._editor;
    if(!ed) return;

        // drawing preview (map editor)
    if(ed.drawing && Array.isArray(ed.drawing.pts) && ed.drawing.pts.length){
      const d = ed.drawing;
      const pts = d.pts;

      if(d.type==="river"){
        // preview in actual WORLD width
        drawRiverWorld({pts, width: d.width || 20});
      }else if(d.type==="fort"){
        const thick = Math.max(2, Number(d.thickness)||16);
        const towerR = Math.max(2, Number(d.towerR)||20);

        ctx.save();
        ctx.lineJoin="round"; ctx.lineCap="round";
        ctx.strokeStyle = "rgba(116,192,255,0.55)";
        ctx.lineWidth = thick;
        ctx.beginPath();
        for(let i=0;i<pts.length;i++){
          const p=pts[i];
          if(i===0) ctx.moveTo(p.x,p.y); else ctx.lineTo(p.x,p.y);
        }
        if(pts.length>=3) ctx.closePath();
        ctx.stroke();

        // towers preview
        for(const p of pts){
          ctx.fillStyle = "rgba(116,192,255,0.10)";
          ctx.strokeStyle = "rgba(116,192,255,0.65)";
          ctx.lineWidth = Math.max(0.8, 2/view.zoom);
          ctx.beginPath();
          ctx.arc(p.x,p.y,towerR,0,TAU);
          ctx.fill();
          ctx.stroke();
        }
        ctx.restore();
      }else{
        // generic polyline
        ctx.save();
        ctx.lineJoin="round"; ctx.lineCap="round";
        ctx.strokeStyle = "rgba(116,192,255,0.8)";
        ctx.lineWidth = Math.max(0.8, 2 / view.zoom);
        ctx.beginPath();
        for(let i=0;i<pts.length;i++){
          const p=pts[i];
          if(i===0) ctx.moveTo(p.x,p.y); else ctx.lineTo(p.x,p.y);
        }
        ctx.stroke();
        ctx.restore();
      }

      // control points (screen-stable)
      ctx.save();
      ctx.setLineDash([]);
      ctx.fillStyle = "rgba(116,192,255,0.18)";
      ctx.strokeStyle = "rgba(116,192,255,0.85)";
      ctx.lineWidth = Math.max(0.8, 2/view.zoom);
      for(const p of pts){
        ctx.beginPath();
        ctx.arc(p.x,p.y,6/view.zoom,0,TAU);
        ctx.fill();
        ctx.stroke();
      }
      ctx.restore();
    }

    // selected highlight
    if(ed.selected){
      const objs = scenario.map.objects || {};
      let obj=null;
      if(ed.selected.type==="building") obj=(objs.buildings||[]).find(o=>o.id===ed.selected.id);
      if(ed.selected.type==="river") obj=(objs.rivers||[]).find(o=>o.id===ed.selected.id);
      if(ed.selected.type==="fort") obj=(objs.forts||[]).find(o=>o.id===ed.selected.id);
      if(obj){
        ctx.save();
        ctx.setLineDash([10/view.zoom, 8/view.zoom]);
        ctx.strokeStyle = "rgba(255,255,255,0.55)";
        ctx.lineWidth = 2/view.zoom;

        if(ed.selected.type==="building"){
          ctx.translate(obj.x,obj.y);
          ctx.rotate(obj.angle||0);
          ctx.strokeRect(-obj.w/2, -obj.h/2, obj.w, obj.h);
        }else if(ed.selected.type==="river"){
          ctx.beginPath();
          for(let i=0;i<obj.pts.length;i++){
            const p=obj.pts[i];
            if(i===0) ctx.moveTo(p.x,p.y); else ctx.lineTo(p.x,p.y);
          }
          ctx.stroke();
        }else if(ed.selected.type==="fort"){
          ctx.beginPath();
          for(let i=0;i<obj.pts.length;i++){
            const p=obj.pts[i];
            if(i===0) ctx.moveTo(p.x,p.y); else ctx.lineTo(p.x,p.y);
          }
          ctx.closePath();
          ctx.stroke();
        }
        ctx.restore();
      }
    }
  }


  function drawToken(ctxLocal, prof, x, y){
    const s = prof.size;
    switch(prof.shape){
      case "dot":{
        ctxLocal.beginPath();
        // half-pixel nudges help avoid tiny dots rasterizing as squares at some zooms
        ctxLocal.arc(x+0.15, y+0.15, s, 0, Math.PI*2);
        ctxLocal.fill();
        break;
      }
      case "tri":{
        const h = s*1.65;
        ctxLocal.beginPath();
        ctxLocal.moveTo(x, y - h);
        ctxLocal.lineTo(x - s, y + s);
        ctxLocal.lineTo(x + s, y + s);
        ctxLocal.closePath();
        ctxLocal.fill();
        break;
      }
      case "sq":{
        ctxLocal.fillRect(x - s, y - s, s*2, s*2);
        break;
      }
      case "rect":{
        ctxLocal.fillRect(x - s*1.2, y - s*0.8, s*2.4, s*1.6);
        break;
      }
    }
  }

  function drawUnitWorld(u){
    if(!u.layout) return;

    // Selected highlight (no contours in normal state)
    const isSel = (u.id===scenario.selectedId);

    ctx.save();
    ctx.translate(u.x, u.y);
    ctx.rotate(u.angle);

    const col = COLORS[u.side] || COLORS.blue;
    ctx.fillStyle = col.token;

    // If routed/destroyed, fade
    if(u.state==="routed") ctx.globalAlpha = 0.45;
    if(u.state==="destroyed") ctx.globalAlpha = 0.20;

    // LOD stride by zoom
    const z = view.zoom;
    const tokens = u.layout.tokens;
    let stride = 1;
    if(z < 0.55) stride = 4;
    else if(z < 0.8) stride = 2;

    for(let i=0;i<tokens.length;i+=stride){
      const t = tokens[i];
      drawToken(ctx, u.layout.prof, t.x, t.y);
    }

    ctx.restore();
    ctx.globalAlpha = 1;

    // Selected marker (no footprint outline)
    if(isSel){
      ctx.save();
      ctx.translate(u.x, u.y);
      const s = 10 / view.zoom;
      ctx.strokeStyle = "rgba(240,248,255,0.30)";
      ctx.lineWidth = 2 / view.zoom;
      ctx.beginPath();
      ctx.moveTo(-s,0); ctx.lineTo(s,0);
      ctx.moveTo(0,-s); ctx.lineTo(0,s);
      ctx.stroke();
      ctx.restore();
    }
  }
function drawOverlaysWorld(){
  const {battle, scenario} = E;
  if(!battle.started || battle.over) return;

  const sel = E.getSelected();
  const hover = scenario.hoverId ? E.getUnit(scenario.hoverId) : null;

  // --- Movement radius (always visible for selected/hovered in movement phase) ---
  if(battle.phase==="movement"){
    const focus = sel || hover;
    if(focus){
      const r = E.getMoveRange(focus);
      const col = COLORS[focus.side] || COLORS.blue;
      if(r>0.5){
        const canMove = (focus.side===battle.active && focus.state==="ready" && focus.movedTurn!==battle.turn);
        ctx.save();
        ctx.lineWidth = 2 / view.zoom;
        ctx.setLineDash([10/view.zoom, 8/view.zoom]);
        ctx.strokeStyle = canMove ? col.move : "rgba(255,255,255,0.22)";
        ctx.fillStyle = canMove ? "rgba(90,208,255,0.07)" : "rgba(255,255,255,0.025)";
        ctx.beginPath();
        ctx.arc(focus.x, focus.y, r, 0, TAU);
        ctx.fill();
        ctx.stroke();
        ctx.restore();
      }
    }
  }

  // --- Ranged radius + target preview/selection ---
  if(battle.phase==="ranged"){
    // Show range for selected shooter; if none selected, show for hovered ranged unit (muted).
    const shooter = (sel && sel.stats.ranged) ? sel : ((hover && hover.stats.ranged) ? hover : null);
    if(shooter && shooter.stats.ranged){
      const r = shooter.stats.ranged.range;
      const col = COLORS[shooter.side] || COLORS.blue;
      const canShoot = (shooter.side===battle.active && shooter.state==="ready" && shooter.firedTurn!==battle.turn);

      // range circle
      ctx.save();
      ctx.lineWidth = 2 / view.zoom;
      ctx.setLineDash([12/view.zoom, 9/view.zoom]);
      ctx.strokeStyle = canShoot ? col.range : "rgba(255,255,255,0.22)";
      ctx.fillStyle = canShoot ? "rgba(255,210,60,0.055)" : "rgba(255,255,255,0.02)";
      ctx.beginPath();
      ctx.arc(shooter.x, shooter.y, r, 0, TAU);
      ctx.fill();
      ctx.stroke();
      ctx.restore();

      // Valid targets highlight only for the actually selected shooter (to avoid accidental fire on hover)
      if(canShoot && sel && sel.id===shooter.id){
        const targets = [];
        for(const u of scenario.units){
          if(u.side===shooter.side) continue;
          if(u.state==="destroyed") continue;
          const d = window.U.dist(shooter.x, shooter.y, u.x, u.y);
          if(d<=r) targets.push(u);
        }

        ctx.save();
        ctx.setLineDash([]);
        ctx.lineWidth = 2.5 / view.zoom;
        ctx.strokeStyle = "rgba(255,210,60,0.42)";
        for(const t of targets){
          const rr = Math.max(14, (t.layout?.collisionR||24) * 0.35);
          ctx.beginPath();
          ctx.arc(t.x, t.y, rr, 0, TAU);
          ctx.stroke();
        }

        // Hover target preview (line + stronger highlight)
        if(hover && hover.side!==shooter.side && hover.state!=="destroyed"){
          const d = window.U.dist(shooter.x, shooter.y, hover.x, hover.y);
          if(d<=r){
            ctx.strokeStyle = "rgba(255,210,60,0.9)";
            ctx.lineWidth = 3.5 / view.zoom;
            ctx.beginPath();
            ctx.moveTo(shooter.x, shooter.y);
            ctx.lineTo(hover.x, hover.y);
            ctx.stroke();

            const rr = Math.max(16, (hover.layout?.collisionR||24) * 0.45);
            ctx.beginPath();
            ctx.arc(hover.x, hover.y, rr, 0, TAU);
            ctx.stroke();
          }
        }
        ctx.restore();
      }
    }
  }

  // --- Melee highlight (units that actually touch figures) ---
  if(battle.phase==="melee"){
    const pairs = E.markEngagements();
    ctx.save();
    ctx.lineWidth = 3 / view.zoom;
    ctx.setLineDash([7/view.zoom, 7/view.zoom]);
    ctx.strokeStyle = "rgba(255,120,120,0.60)";
    for(const [aId,bId] of pairs){
      const a=E.getUnit(aId), b=E.getUnit(bId);
      if(!a||!b) continue;
      const ra = Math.max(14, (a.layout?.collisionR||24) * 0.28);
      const rb = Math.max(14, (b.layout?.collisionR||24) * 0.28);
      ctx.beginPath(); ctx.arc(a.x,a.y,ra,0,TAU); ctx.stroke();
      ctx.beginPath(); ctx.arc(b.x,b.y,rb,0,TAU); ctx.stroke();
    }
    ctx.restore();
  }
}

  // Labels in screen space (not affected by world transform)
  function drawLabelsScreen(){
    resetTransform();
    ctx.save();
    ctx.font = "12px system-ui, -apple-system, Segoe UI, Roboto, Arial";
    ctx.textAlign = "center";
    ctx.textBaseline = "bottom";
    for(const u of scenario.units){
      if(u.state === "destroyed") continue;
      const s = worldToScreen(u.x, u.y);
      // Push label above the formation footprint so it doesn't slide inside at zoom
      const r = (u.layout?.collisionR ?? 30) * view.zoom;
      const y = s.y - r - 10;
      const txt = `${u.name} (${u.men})  M:${Math.round(u.morale)}`;
      // subtle outline for readability
      ctx.lineWidth = 3;
      ctx.strokeStyle = "rgba(0,0,0,0.65)";
      ctx.strokeText(txt, s.x, y);
      const col = (u.side === "blue") ? COLORS.blue.token : COLORS.red.token;
      ctx.fillStyle = col;
      ctx.fillText(txt, s.x, y);
    }
    ctx.restore();
  }

  function render(){
    window.ENGINE.ensureLayouts();

    drawTerrain();
    setWorldTransform();

    // units
    for(const u of scenario.units){
      drawUnitWorld(u);
    }

    drawOverlaysWorld();
    drawEditorWorld();
    drawLabelsScreen();
  }

  // hit test: pick unit nearest to screen point
  function pickUnitScreen(px,py){
    const w = screenToWorld(px,py);
    let best=null, bestD=1e9;
    for(const u of scenario.units){
      if(u.state==="destroyed") continue;
      const r = (u.layout?.collisionR ?? 30);
      const d = U.dist(w.x,w.y,u.x,u.y);
      if(d<=r && d<bestD){
        best=u; bestD=d;
      }
    }
    return best;
  }

  function isCursorOverUnit(px,py, u){
    if(!u) return false;
    const w = screenToWorld(px,py);
    const r = (u.layout?.collisionR ?? 30);
    return (U.dist(w.x,w.y,u.x,u.y) <= r);
  }

  // animation loop
  let raf=0;
  function loop(){
    render();
    raf = requestAnimationFrame(loop);
  }
  loop();

  window.RENDER = {
    canvas, ctx, view,
    resize,
    fitToViewport,
    render,
    worldToScreen, screenToWorld,
    setWorldTransform, resetTransform,
    clampPan,
    zoomAtScreen,
    pickUnitScreen,
    isCursorOverUnit,
  };
})();