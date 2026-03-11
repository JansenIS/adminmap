(() => {
  const state = {
    routes: [],
    activeIndex: -1,
    mapImage: null,
    scale: 1,
  };

  const el = {
    canvas: document.getElementById('mapCanvas'),
    routeList: document.getElementById('routeList'),
    routeId: document.getElementById('routeId'),
    routeName: document.getElementById('routeName'),
    routeNavigable: document.getElementById('routeNavigable'),
    routeCargoClass: document.getElementById('routeCargoClass'),
    routeDirection: document.getElementById('routeDirection'),
    routePorts: document.getElementById('routePorts'),
    runOutput: document.getElementById('runOutput'),
    loadBtn: document.getElementById('loadBtn'),
    saveBtn: document.getElementById('saveBtn'),
    runBtn: document.getElementById('runBtn'),
    newRouteBtn: document.getElementById('newRouteBtn'),
    finishRouteBtn: document.getElementById('finishRouteBtn'),
    deleteRouteBtn: document.getElementById('deleteRouteBtn')
  };

  const ctx = el.canvas.getContext('2d');

  function uid(prefix='river') {
    return `${prefix}_${Math.random().toString(36).slice(2, 8)}`;
  }

  async function loadMap() {
    const img = new Image();
    img.src = '../../map.png';
    await img.decode();
    state.mapImage = img;
    el.canvas.width = img.width;
    el.canvas.height = img.height;
    draw();
  }

  async function loadRoutes() {
    const res = await fetch('./api/load_routes.php');
    const json = await res.json();
    state.routes = Array.isArray(json.routes) ? json.routes : [];
    if (!state.routes.length) {
      state.routes.push(blankRoute());
    }
    state.activeIndex = 0;
    syncFormFromActive();
    renderRouteList();
    draw();
  }

  function blankRoute() {
    return {
      id: uid('river'),
      name: 'Новая река',
      navigable: true,
      cargo_class: 'major',
      direction: 'forward',
      polyline: [],
      ports: []
    };
  }

  function activeRoute() {
    return state.routes[state.activeIndex] || null;
  }

  function renderRouteList() {
    el.routeList.innerHTML = '';
    state.routes.forEach((route, index) => {
      const div = document.createElement('div');
      div.className = 'routeItem' + (index === state.activeIndex ? ' active' : '');
      div.textContent = `${route.name || route.id} (${route.polyline?.length || 0} т.)`;
      div.onclick = () => {
        state.activeIndex = index;
        syncFormFromActive();
        renderRouteList();
        draw();
      };
      el.routeList.appendChild(div);
    });
  }

  function syncFormFromActive() {
    const route = activeRoute();
    if (!route) return;
    el.routeId.value = route.id || '';
    el.routeName.value = route.name || '';
    el.routeNavigable.value = String(!!route.navigable);
    el.routeCargoClass.value = route.cargo_class || 'major';
    el.routeDirection.value = route.direction || 'forward';
    el.routePorts.value = JSON.stringify(route.ports || [], null, 2);
  }

  function syncActiveFromForm() {
    const route = activeRoute();
    if (!route) return;
    route.id = el.routeId.value.trim() || uid('river');
    route.name = el.routeName.value.trim() || route.id;
    route.navigable = el.routeNavigable.value === 'true';
    route.cargo_class = el.routeCargoClass.value;
    route.direction = el.routeDirection.value;
    try {
      route.ports = JSON.parse(el.routePorts.value || '[]');
    } catch (e) {
      console.warn('ports json parse failed', e);
    }
    renderRouteList();
    draw();
  }

  function draw() {
    if (!state.mapImage) return;
    ctx.clearRect(0, 0, el.canvas.width, el.canvas.height);
    ctx.drawImage(state.mapImage, 0, 0);
    state.routes.forEach((route, idx) => {
      const active = idx === state.activeIndex;
      const pts = route.polyline || [];
      if (!pts.length) return;
      ctx.save();
      ctx.lineWidth = active ? 4 : 2;
      ctx.strokeStyle = active ? '#38bdf8' : '#f59e0b';
      ctx.fillStyle = active ? '#22d3ee' : '#fde68a';
      ctx.beginPath();
      ctx.moveTo(pts[0][0], pts[0][1]);
      for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i][0], pts[i][1]);
      ctx.stroke();
      pts.forEach((p, i) => {
        ctx.beginPath();
        ctx.arc(p[0], p[1], active ? 4 : 3, 0, Math.PI * 2);
        ctx.fill();
        if (active) {
          ctx.fillStyle = '#ffffff';
          ctx.font = '11px Arial';
          ctx.fillText(String(i), p[0] + 6, p[1] - 6);
          ctx.fillStyle = '#22d3ee';
        }
      });
      (route.ports || []).forEach((port) => {
        const pt = port.point || [0, 0];
        ctx.fillStyle = '#ef4444';
        ctx.fillRect(pt[0] - 4, pt[1] - 4, 8, 8);
        ctx.fillStyle = '#ffffff';
        ctx.font = '12px Arial';
        ctx.fillText(port.name || port.id || 'port', pt[0] + 8, pt[1] + 4);
      });
      ctx.restore();
    });
  }

  function canvasPoint(event) {
    const rect = el.canvas.getBoundingClientRect();
    const x = Math.round((event.clientX - rect.left) * (el.canvas.width / rect.width));
    const y = Math.round((event.clientY - rect.top) * (el.canvas.height / rect.height));
    return [x, y];
  }

  function removeNearestPoint(route, point, maxDist = 14) {
    let best = -1;
    let bestD = Infinity;
    (route.polyline || []).forEach((p, i) => {
      const dx = p[0] - point[0];
      const dy = p[1] - point[1];
      const d = Math.sqrt(dx * dx + dy * dy);
      if (d < bestD) { bestD = d; best = i; }
    });
    if (best >= 0 && bestD <= maxDist) route.polyline.splice(best, 1);
  }

  el.canvas.addEventListener('click', (event) => {
    const route = activeRoute();
    if (!route) return;
    const point = canvasPoint(event);
    if (event.shiftKey) {
      removeNearestPoint(route, point);
    } else {
      route.polyline.push(point);
    }
    syncFormFromActive();
    renderRouteList();
    draw();
  });

  el.loadBtn.onclick = loadRoutes;
  el.newRouteBtn.onclick = () => {
    state.routes.push(blankRoute());
    state.activeIndex = state.routes.length - 1;
    syncFormFromActive();
    renderRouteList();
    draw();
  };
  el.finishRouteBtn.onclick = () => {
    syncActiveFromForm();
  };
  el.deleteRouteBtn.onclick = () => {
    if (state.activeIndex < 0) return;
    state.routes.splice(state.activeIndex, 1);
    if (!state.routes.length) state.routes.push(blankRoute());
    state.activeIndex = Math.max(0, state.activeIndex - 1);
    syncFormFromActive();
    renderRouteList();
    draw();
  };

  [el.routeId, el.routeName, el.routeNavigable, el.routeCargoClass, el.routeDirection, el.routePorts]
    .forEach(node => node.addEventListener('change', syncActiveFromForm));

  el.saveBtn.onclick = async () => {
    syncActiveFromForm();
    const res = await fetch('./api/save_routes.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ routes: state.routes })
    });
    const json = await res.json();
    el.runOutput.textContent = JSON.stringify(json, null, 2);
  };

  el.runBtn.onclick = async () => {
    syncActiveFromForm();
    const res = await fetch('./api/run.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ dry_run: false })
    });
    const json = await res.json();
    el.runOutput.textContent = JSON.stringify(json, null, 2);
  };

  (async () => {
    await loadMap();
    await loadRoutes();
  })();
})();
