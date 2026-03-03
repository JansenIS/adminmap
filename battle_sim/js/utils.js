(function(){
  "use strict";

  const U = {};

  U.clamp = (v,a,b)=>Math.max(a, Math.min(b, v));
  U.lerp = (a,b,t)=>a+(b-a)*t;
  U.rad = (deg)=>deg * Math.PI / 180;
  U.deg = (rad)=>rad * 180 / Math.PI;
  U.dist = (ax,ay,bx,by)=>Math.hypot(ax-bx, ay-by);

  U.rand = (a=0,b=1)=>a + Math.random()*(b-a);
  U.randi = (a,b)=>Math.floor(U.rand(a,b+1));
  U.rollD6 = (n=1)=>{ let s=0; for(let i=0;i<n;i++) s += U.randi(1,6); return s; };

  U.uid = ()=>Math.random().toString(16).slice(2,10);

  U.pointInPoly = (x,y,poly)=>{
    // poly: [{x,y},...]
    let inside=false;
    for(let i=0,j=poly.length-1;i<poly.length;j=i++){
      const xi=poly[i].x, yi=poly[i].y;
      const xj=poly[j].x, yj=poly[j].y;
      const intersect = ((yi>y)!==(yj>y)) && (x < (xj-xi)*(y-yi)/((yj-yi)||1e-9) + xi);
      if(intersect) inside=!inside;
    }
    return inside;
  };

  U.wrapAngleRad = (a)=>{
    while(a<=-Math.PI) a+=Math.PI*2;
    while(a> Math.PI) a-=Math.PI*2;
    return a;
  };

  U.angleDiffRad = (a,b)=>U.wrapAngleRad(a-b);

  
  // --- Geometry helpers for map editor & LOS ---
  U.mulberry32 = (seed)=>{
    let t = seed>>>0;
    return function(){
      t += 0x6D2B79F5;
      let r = Math.imul(t ^ (t >>> 15), 1 | t);
      r ^= r + Math.imul(r ^ (r >>> 7), 61 | r);
      return ((r ^ (r >>> 14)) >>> 0) / 4294967296;
    };
  };

  U.closestPointOnSeg = (ax,ay,bx,by,px,py)=>{
    const abx = bx-ax, aby = by-ay;
    const apx = px-ax, apy = py-ay;
    const denom = abx*abx + aby*aby;
    const t = denom>1e-9 ? U.clamp((apx*abx + apy*aby)/denom, 0, 1) : 0;
    return { x: ax + abx*t, y: ay + aby*t, t };
  };

  U.pointSegDist = (ax,ay,bx,by,px,py)=>{
    const c = U.closestPointOnSeg(ax,ay,bx,by,px,py);
    return Math.hypot(px-c.x, py-c.y);
  };

  U.segsIntersect = (ax,ay,bx,by,cx,cy,dx,dy)=>{
    // Proper segment intersection (including touching)
    const o = (px,py,qx,qy,rx,ry)=>Math.sign((qx-px)*(ry-py)-(qy-py)*(rx-px));
    const o1 = o(ax,ay,bx,by,cx,cy);
    const o2 = o(ax,ay,bx,by,dx,dy);
    const o3 = o(cx,cy,dx,dy,ax,ay);
    const o4 = o(cx,cy,dx,dy,bx,by);

    const onSeg = (px,py,qx,qy,rx,ry)=>{
      return Math.min(px,qx)-1e-9 <= rx && rx <= Math.max(px,qx)+1e-9 &&
             Math.min(py,qy)-1e-9 <= ry && ry <= Math.max(py,qy)+1e-9 &&
             Math.abs((qx-px)*(ry-py)-(qy-py)*(rx-px)) <= 1e-9;
    };

    if(o1===0 && onSeg(ax,ay,bx,by,cx,cy)) return true;
    if(o2===0 && onSeg(ax,ay,bx,by,dx,dy)) return true;
    if(o3===0 && onSeg(cx,cy,dx,dy,ax,ay)) return true;
    if(o4===0 && onSeg(cx,cy,dx,dy,bx,by)) return true;

    return (o1!==o2) && (o3!==o4);
  };

  U.segSegDist = (ax,ay,bx,by,cx,cy,dx,dy)=>{
    if(U.segsIntersect(ax,ay,bx,by,cx,cy,dx,dy)) return 0;
    const d1 = U.pointSegDist(ax,ay,bx,by,cx,cy);
    const d2 = U.pointSegDist(ax,ay,bx,by,dx,dy);
    const d3 = U.pointSegDist(cx,cy,dx,dy,ax,ay);
    const d4 = U.pointSegDist(cx,cy,dx,dy,bx,by);
    return Math.min(d1,d2,d3,d4);
  };

  U.segIntersectsCircle = (ax,ay,bx,by,cx,cy,r)=>{
    return U.pointSegDist(ax,ay,bx,by,cx,cy) <= r;
  };

  U.pointInRotRect = (px,py,rx,ry,w,h,ang)=>{
    // rect centered at (rx,ry), width w, height h, rotated by ang (rad)
    const ca = Math.cos(-ang), sa = Math.sin(-ang);
    const dx = px - rx, dy = py - ry;
    const lx = dx*ca - dy*sa;
    const ly = dx*sa + dy*ca;
    return Math.abs(lx) <= w/2 && Math.abs(ly) <= h/2;
  };

  U.segIntersectsRotRect = (ax,ay,bx,by,rx,ry,w,h,ang)=>{
    // Transform segment into rect-local space, then check intersection with AABB.
    const ca = Math.cos(-ang), sa = Math.sin(-ang);
    const tx = (x,y)=>({x:(x-rx)*ca - (y-ry)*sa, y:(x-rx)*sa + (y-ry)*ca});
    const a = tx(ax,ay), b = tx(bx,by);

    const minX = -w/2, maxX = w/2, minY = -h/2, maxY = h/2;

    // Liang-Barsky clipping
    let t0=0, t1=1;
    const dx = b.x - a.x, dy = b.y - a.y;
    const clip = (p,q)=>{
      if(Math.abs(p) < 1e-12) return q>=0;
      const r = q/p;
      if(p < 0){ if(r > t1) return false; if(r > t0) t0 = r; }
      else { if(r < t0) return false; if(r < t1) t1 = r; }
      return true;
    };
    if(clip(-dx, a.x - minX) && clip(dx, maxX - a.x) && clip(-dy, a.y - minY) && clip(dy, maxY - a.y)){
      return t0<=t1;
    }
    return false;
  };

  window.U = U;
})();
