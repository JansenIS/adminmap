import './styles.css';
import {
  applyChanges,
  loadAllProvinces,
  loadBootstrap,
  loadLayer,
  loadVersion,
  normalizeServerUrl,
  tileUrl,
} from './api.js';
import { clientConfig, tools as MODULES } from './modules.js';
import {
  clearLocalCache,
  loadCachedProvinces,
  loadCachedVersion,
  loadQueue,
  loadSettings,
  saveCachedProvinces,
  saveCachedVersion,
  saveQueue,
  saveSettings,
} from './store.js';

const FIELD_LABELS = {
  name: 'Название',
  owner: 'Владелец',
  suzerain: 'Сюзерен',
  senior: 'Сеньор',
  terrain: 'Ландшафт',
  kingdom_id: 'Королевство',
  great_house_id: 'Большой дом',
  minor_house_id: 'Малый дом',
  free_city_id: 'Вольный город',
  special_territory_id: 'Особая территория',
  wiki_description: 'Описание wiki',
};

const LAYER_OPTIONS = [
  ['provinces', 'Провинции'],
  ['kingdoms', 'Королевства'],
  ['great_houses', 'Большие дома'],
  ['minor_houses', 'Малые дома'],
  ['free_cities', 'Вольные города'],
  ['special_territories', 'Особые территории'],
];

const CLIENT_MODE = clientConfig.mode;
const DEFAULT_MODULE = clientConfig.defaultModule;

const state = {
  settings: null,
  version: null,
  bootstrap: null,
  provinces: [],
  queue: [],
  selectedPid: null,
  selectedModuleId: DEFAULT_MODULE,
  query: '',
  moduleQuery: '',
  activeView: 'launcher',
  status: 'Загрузка локального состояния…',
  online: navigator.onLine,
  map: {
    scale: 1,
    x: 0,
    y: 0,
    dragging: false,
    lastPointer: null,
    tileCache: new Map(),
  },
};

const app = document.querySelector('#app');

function html(strings, ...values) {
  return strings.reduce((acc, part, index) => `${acc}${part}${values[index] ?? ''}`, '');
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function setStatus(message) {
  state.status = message;
  const status = document.querySelector('[data-role="status"]');
  if (status) status.textContent = message;
}

function currentVersionHash() {
  return state.version?.map_version || state.version?.version || '';
}

function splitServerUrl(settings) {
  const current = normalizeServerUrl(settings.serverUrl || 'http://127.0.0.1:8080');
  try {
    const url = new URL(current);
    settings.serverProtocol ||= url.protocol.replace(':', '') || 'http';
    settings.serverHost ||= url.hostname || '127.0.0.1';
    settings.serverPort ||= url.port || (settings.serverProtocol === 'https' ? '443' : '8080');
    settings.serverBasePath ||= url.pathname === '/' ? '' : url.pathname.replace(/^\/+|\/+$/g, '');
  } catch (_error) {
    settings.serverProtocol ||= 'http';
    settings.serverHost ||= current.replace(/^https?:\/\//, '').split(/[/:]/)[0] || '127.0.0.1';
    settings.serverPort ||= '8080';
    settings.serverBasePath ||= '';
  }
}

function buildServerUrl(settings) {
  const protocol = settings.serverProtocol || 'http';
  const host = String(settings.serverHost || '127.0.0.1').trim();
  const port = String(settings.serverPort || '').trim();
  const basePath = String(settings.serverBasePath || '').trim().replace(/^\/+|\/+$/g, '');
  const portPart = port ? `:${port}` : '';
  const pathPart = basePath ? `/${basePath}` : '';
  return normalizeServerUrl(`${protocol}://${host}${portPart}${pathPart}`);
}

function applyServerFields(settings) {
  splitServerUrl(settings);
  settings.serverUrl = buildServerUrl(settings);
}

function selectedProvince() {
  return state.provinces.find((province) => Number(province.pid) === Number(state.selectedPid)) || null;
}

function selectedModule() {
  return MODULES.find((tool) => tool.id === state.selectedModuleId) || MODULES[0];
}

function filteredModules() {
  const query = state.moduleQuery.trim().toLowerCase();
  if (!query) return MODULES;
  return MODULES.filter((tool) => [tool.title, tool.description, tool.path, ...(tool.tags || [])]
    .join(' ')
    .toLowerCase()
    .includes(query));
}

function filteredProvinces() {
  const query = state.query.trim().toLowerCase();
  if (!query) return state.provinces.slice(0, 250);
  return state.provinces
    .filter((province) => {
      const haystack = [province.pid, province.name, province.owner, province.kingdom_id, province.great_house_id]
        .map((value) => String(value ?? '').toLowerCase())
        .join(' ');
      return haystack.includes(query);
    })
    .slice(0, 250);
}

function appendQuery(path, extraQuery) {
  const cleanPath = String(path || '').replace(/^\/+/, '');
  const query = String(extraQuery || '').trim().replace(/^\?/, '').replace(/^&/, '');
  if (!query) return cleanPath;
  return `${cleanPath}${cleanPath.includes('?') ? '&' : '?'}${query}`;
}

function remoteUrl(tool = selectedModule()) {
  const base = normalizeServerUrl(state.settings?.serverUrl || '');
  if (!base) return '';
  return `${base}/${appendQuery(tool.path, state.settings?.passthroughQuery)}`;
}

function render() {
  const selected = selectedProvince();
  const tool = selectedModule();
  app.innerHTML = html`
    <div class="shell ${CLIENT_MODE}">
      <aside class="sidebar">
        <section class="brand card">
          <div>
            <p class="eyebrow">${CLIENT_MODE === 'admin' ? 'Adminmap Admin Desktop' : 'Adminmap User Desktop'}</p>
            <h1>${CLIENT_MODE === 'admin' ? 'Админский клиент' : 'Пользовательский клиент'}</h1>
          </div>
          <span class="badge ${state.online ? 'ok' : 'warn'}">${state.online ? 'online' : 'offline'}</span>
        </section>

        <section class="card stack">
          <div class="server-grid">
            <label class="field">
              <span>Схема</span>
              <select data-action="server-protocol">
                <option value="http" ${state.settings.serverProtocol === 'http' ? 'selected' : ''}>http</option>
                <option value="https" ${state.settings.serverProtocol === 'https' ? 'selected' : ''}>https</option>
              </select>
            </label>
            <label class="field wide-server">
              <span>IP / host удалённого сервера</span>
              <input data-action="server-host" list="server-host-presets" value="${escapeHtml(state.settings.serverHost || '')}" placeholder="192.168.1.10 или example.org" />
              <datalist id="server-host-presets">
                <option value="127.0.0.1"></option>
                <option value="localhost"></option>
              </datalist>
            </label>
            <label class="field">
              <span>Порт</span>
              <input data-action="server-port" value="${escapeHtml(state.settings.serverPort || '')}" placeholder="8080" inputmode="numeric" />
            </label>
            <label class="field wide-server">
              <span>Base path, если проект не в корне домена</span>
              <input data-action="server-base-path" value="${escapeHtml(state.settings.serverBasePath || '')}" placeholder="adminmap" />
            </label>
          </div>
          <div class="server-current">
            <span>${escapeHtml(state.settings.serverUrl)}</span>
            <button class="secondary tiny" data-action="server-local" type="button">127.0.0.1:8080</button>
          </div>
          <label class="field">
            <span>Общие query-параметры для модулей (например token=...)</span>
            <input data-action="passthrough-query" value="${escapeHtml(state.settings.passthroughQuery || '')}" placeholder="token=..." />
          </label>
          <div class="toolbar two">
            <button data-action="connect">Подключиться</button>
            <button class="secondary" data-action="clear-cache">Сбросить JSON-кеш</button>
          </div>
          <p class="hint">Полный функционал открыт через встроенные серверные модули. Runtime-cache и скачанные ассеты разрешены; в git не коммитятся только бинарные артефакты.</p>
        </section>

        <section class="card stack">
          <div class="split">
            <div>
              <p class="eyebrow">Синхронизация локальных правок</p>
              <strong>${state.queue.length} в очереди</strong>
            </div>
            <span class="badge">${escapeHtml(currentVersionHash()).slice(0, 8) || 'no ver'}</span>
          </div>
          <label class="field inline">
            <span>Пакет</span>
            <input data-action="batch-size" type="number" min="1" max="200" value="${Number(state.settings.batchSize || 25)}" />
          </label>
          <div class="toolbar two">
            <button data-action="sync">Выгрузить пакетами</button>
            <button class="secondary" data-action="refresh">Обновить данные</button>
          </div>
        </section>

        <section class="card stack grow">
          <div class="tabs">
            <button class="tab ${state.activeView === 'launcher' ? 'active' : ''}" data-action="view" data-view="launcher">Функции</button>
            <button class="tab ${state.activeView === 'local-map' ? 'active' : ''}" data-action="view" data-view="local-map">Локальная карта</button>
            <button class="tab ${state.activeView === 'embedded' ? 'active' : ''}" data-action="view" data-view="embedded">Открыто</button>
          </div>
          ${renderSidebarPanel()}
        </section>
      </aside>

      <main class="workspace">
        <header class="topbar card">
          <div>
            <p class="eyebrow">Состояние</p>
            <div data-role="status">${escapeHtml(state.status)}</div>
          </div>
          ${state.activeView === 'local-map' ? renderLayerSelect() : renderActiveModuleActions(tool)}
        </header>
        ${state.activeView === 'launcher' ? renderLauncher() : ''}
        ${state.activeView === 'embedded' ? renderEmbedded(tool) : ''}
        ${state.activeView === 'local-map' ? renderLocalMap(selected) : ''}
      </main>
    </div>
  `;
  bindEvents();
  if (state.activeView === 'local-map') drawMap();
}

function renderSidebarPanel() {
  if (state.activeView === 'local-map') {
    return html`
      <div class="split"><p class="eyebrow">Провинции</p><span>${state.provinces.length}</span></div>
      <input data-action="search" value="${escapeHtml(state.query)}" placeholder="PID, имя, владелец, дом…" />
      <div class="province-list">${filteredProvinces().map((province) => renderProvinceRow(province)).join('')}</div>
    `;
  }
  return html`
    <input data-action="module-search" value="${escapeHtml(state.moduleQuery)}" placeholder="Поиск функции: карта, приказы, wiki…" />
    <div class="module-list">${filteredModules().map((tool) => renderModuleRow(tool)).join('')}</div>
  `;
}

function renderLayerSelect() {
  return html`
    <label class="field compact">
      <span>Слой</span>
      <select data-action="layer-mode">
        ${LAYER_OPTIONS.map(([value, label]) => `<option value="${value}" ${state.settings.layerMode === value ? 'selected' : ''}>${label}</option>`).join('')}
      </select>
    </label>
  `;
}

function renderActiveModuleActions(tool) {
  const url = remoteUrl(tool);
  return html`
    <div class="active-module-actions">
      <span class="badge">${escapeHtml(tool.title)}</span>
      <button class="secondary" data-action="reload-frame">Перезагрузить</button>
      <button data-action="open-external" data-url="${escapeHtml(url)}">Открыть в браузере</button>
    </div>
  `;
}

function renderLauncher() {
  const grouped = filteredModules().reduce((acc, tool) => {
    const group = tool.tags?.[0] || 'прочее';
    if (!acc[group]) acc[group] = [];
    acc[group].push(tool);
    return acc;
  }, {});
  return html`
    <section class="launcher card">
      <div class="launcher-head">
        <div>
          <p class="eyebrow">${CLIENT_MODE === 'admin' ? 'Все админские и пользовательские функции' : 'Все пользовательские функции'}</p>
          <h2>Единый desktop launcher</h2>
        </div>
        <p class="hint">Выберите модуль — он откроется внутри клиента с теми же API и UX, что на основном сервере.</p>
      </div>
      <div class="module-grid">
        ${Object.entries(grouped).map(([group, tools]) => html`
          <section class="module-group">
            <h3>${escapeHtml(group)}</h3>
            ${tools.map((tool) => renderModuleCard(tool)).join('')}
          </section>
        `).join('')}
      </div>
    </section>
  `;
}

function renderEmbedded(tool) {
  const url = remoteUrl(tool);
  return html`
    <section class="embedded card">
      <div class="frame-title">
        <div>
          <p class="eyebrow">${escapeHtml(tool.path)}</p>
          <h2>${escapeHtml(tool.title)}</h2>
        </div>
        <span>${escapeHtml(tool.description)}</span>
      </div>
      <iframe data-role="module-frame" src="${escapeHtml(url)}" title="${escapeHtml(tool.title)}"></iframe>
    </section>
  `;
}

function renderLocalMap(selected) {
  return html`
    <section class="map-card card">
      <canvas id="mapCanvas" width="1280" height="720" aria-label="Карта тайлов"></canvas>
      <div class="map-help">Колесо — zoom · перетаскивание — pan · двойной клик — сброс вида</div>
    </section>
    <section class="editor card">
      ${selected ? renderEditor(selected) : '<div class="empty">Выберите провинцию слева, чтобы подготовить локальные правки.</div>'}
    </section>
  `;
}

function renderModuleRow(tool) {
  const active = tool.id === state.selectedModuleId ? 'active' : '';
  return html`
    <button class="module-row ${active}" data-action="select-module" data-id="${escapeHtml(tool.id)}">
      <span>
        <strong>${escapeHtml(tool.title)}</strong>
        <small>${escapeHtml(tool.path)}</small>
      </span>
    </button>
  `;
}

function renderModuleCard(tool) {
  return html`
    <button class="module-card" data-action="open-module" data-id="${escapeHtml(tool.id)}">
      <strong>${escapeHtml(tool.title)}</strong>
      <span>${escapeHtml(tool.description)}</span>
      <small>${escapeHtml(tool.path)}</small>
    </button>
  `;
}

function renderProvinceRow(province) {
  const active = Number(province.pid) === Number(state.selectedPid) ? 'active' : '';
  const queued = state.queue.some((entry) => entry.kind === 'province' && Number(entry.pid) === Number(province.pid));
  return html`
    <button class="province-row ${active}" data-action="select-province" data-pid="${Number(province.pid)}">
      <span class="pid">#${Number(province.pid)}</span>
      <span>
        <strong>${escapeHtml(province.name || 'Без названия')}</strong>
        <small>${escapeHtml(province.owner || province.kingdom_id || '—')}</small>
      </span>
      ${queued ? '<span class="dot" title="Есть локальные правки"></span>' : ''}
    </button>
  `;
}

function renderEditor(province) {
  return html`
    <div class="editor-head">
      <div>
        <p class="eyebrow">Редактор провинции</p>
        <h2>#${Number(province.pid)} · ${escapeHtml(province.name || 'Без названия')}</h2>
      </div>
      <button data-action="queue-province">Добавить в очередь</button>
    </div>
    <form class="edit-grid" data-role="province-form">
      ${Object.entries(FIELD_LABELS).map(([field, label]) => renderField(field, label, province[field])).join('')}
    </form>
  `;
}

function renderField(field, label, value) {
  const escaped = escapeHtml(value || '');
  if (field === 'wiki_description') {
    return html`<label class="field wide"><span>${label}</span><textarea name="${field}" rows="4">${escaped}</textarea></label>`;
  }
  return html`<label class="field"><span>${label}</span><input name="${field}" value="${escaped}" /></label>`;
}

function bindEvents() {
  const serverFieldMap = {
    'server-protocol': 'serverProtocol',
    'server-host': 'serverHost',
    'server-port': 'serverPort',
    'server-base-path': 'serverBasePath',
  };
  Object.entries(serverFieldMap).forEach(([action, key]) => {
    document.querySelector(`[data-action="${action}"]`)?.addEventListener('change', async (event) => {
      state.settings[key] = event.target.value.trim();
      applyServerFields(state.settings);
      await saveSettings(state.settings);
      render();
    });
  });
  document.querySelector('[data-action="server-local"]')?.addEventListener('click', async () => {
    state.settings.serverProtocol = 'http';
    state.settings.serverHost = '127.0.0.1';
    state.settings.serverPort = '8080';
    state.settings.serverBasePath = '';
    applyServerFields(state.settings);
    await saveSettings(state.settings);
    render();
  });
  document.querySelector('[data-action="passthrough-query"]')?.addEventListener('change', async (event) => {
    state.settings.passthroughQuery = event.target.value.trim().replace(/^\?/, '');
    await saveSettings(state.settings);
    render();
  });
  document.querySelector('[data-action="batch-size"]')?.addEventListener('change', async (event) => {
    state.settings.batchSize = Math.max(1, Math.min(200, Number(event.target.value || 25)));
    await saveSettings(state.settings);
  });
  document.querySelector('[data-action="search"]')?.addEventListener('input', (event) => {
    state.query = event.target.value;
    render();
  });
  document.querySelector('[data-action="module-search"]')?.addEventListener('input', (event) => {
    state.moduleQuery = event.target.value;
    render();
  });
  document.querySelector('[data-action="layer-mode"]')?.addEventListener('change', async (event) => {
    state.settings.layerMode = event.target.value;
    state.map.tileCache.clear();
    await saveSettings(state.settings);
    await refreshLayerOnly();
  });
  document.querySelector('[data-action="connect"]')?.addEventListener('click', refreshFromServer);
  document.querySelector('[data-action="refresh"]')?.addEventListener('click', refreshFromServer);
  document.querySelector('[data-action="sync"]')?.addEventListener('click', syncQueue);
  document.querySelector('[data-action="clear-cache"]')?.addEventListener('click', resetCache);
  document.querySelector('[data-action="reload-frame"]')?.addEventListener('click', () => {
    const frame = document.querySelector('[data-role="module-frame"]');
    if (frame) frame.src = frame.src;
  });
  document.querySelector('[data-action="open-external"]')?.addEventListener('click', (event) => {
    window.open(event.currentTarget.dataset.url, '_blank', 'noopener,noreferrer');
  });
  document.querySelectorAll('[data-action="view"]').forEach((button) => {
    button.addEventListener('click', () => {
      state.activeView = button.dataset.view;
      render();
    });
  });
  document.querySelectorAll('[data-action="select-module"]').forEach((button) => {
    button.addEventListener('click', () => {
      state.selectedModuleId = button.dataset.id;
      state.activeView = 'embedded';
      render();
    });
  });
  document.querySelectorAll('[data-action="open-module"]').forEach((button) => {
    button.addEventListener('click', () => {
      state.selectedModuleId = button.dataset.id;
      state.activeView = 'embedded';
      render();
    });
  });
  document.querySelectorAll('[data-action="select-province"]').forEach((button) => {
    button.addEventListener('click', () => {
      state.selectedPid = Number(button.dataset.pid);
      render();
    });
  });
  document.querySelector('[data-action="queue-province"]')?.addEventListener('click', queueSelectedProvince);
  bindCanvas();
}

function bindCanvas() {
  const canvas = document.querySelector('#mapCanvas');
  if (!canvas) return;
  canvas.addEventListener('wheel', (event) => {
    event.preventDefault();
    const direction = event.deltaY > 0 ? -1 : 1;
    state.map.scale = Math.max(0.7, Math.min(8, state.map.scale + direction * 0.25));
    drawMap();
  }, { passive: false });
  canvas.addEventListener('pointerdown', (event) => {
    state.map.dragging = true;
    state.map.lastPointer = { x: event.clientX, y: event.clientY };
    canvas.setPointerCapture(event.pointerId);
  });
  canvas.addEventListener('pointermove', (event) => {
    if (!state.map.dragging || !state.map.lastPointer) return;
    state.map.x += event.clientX - state.map.lastPointer.x;
    state.map.y += event.clientY - state.map.lastPointer.y;
    state.map.lastPointer = { x: event.clientX, y: event.clientY };
    drawMap();
  });
  canvas.addEventListener('pointerup', () => {
    state.map.dragging = false;
    state.map.lastPointer = null;
  });
  canvas.addEventListener('dblclick', () => {
    state.map.scale = 1;
    state.map.x = 0;
    state.map.y = 0;
    drawMap();
  });
}

function drawMap() {
  const canvas = document.querySelector('#mapCanvas');
  if (!canvas || !state.settings?.serverUrl) return;
  const ctx = canvas.getContext('2d');
  const { width, height } = canvas;
  ctx.clearRect(0, 0, width, height);
  ctx.fillStyle = '#101722';
  ctx.fillRect(0, 0, width, height);

  const z = Math.max(0, Math.min(3, Math.round(state.map.scale - 1)));
  const tileSize = 256 * state.map.scale;
  const cols = Math.ceil(width / tileSize) + 2;
  const rows = Math.ceil(height / tileSize) + 2;
  const startX = Math.floor(-state.map.x / tileSize) - 1;
  const startY = Math.floor(-state.map.y / tileSize) - 1;

  for (let y = startY; y < startY + rows; y += 1) {
    for (let x = startX; x < startX + cols; x += 1) {
      if (x < 0 || y < 0) continue;
      drawTile(ctx, z, x, y, state.map.x + x * tileSize, state.map.y + y * tileSize, tileSize);
    }
  }

  ctx.strokeStyle = 'rgba(255,255,255,.08)';
  ctx.lineWidth = 1;
  ctx.strokeRect(0.5, 0.5, width - 1, height - 1);
}

function drawTile(ctx, z, x, y, dx, dy, size) {
  const key = `${state.settings.serverUrl}|${state.settings.layerMode}|${z}|${x}|${y}`;
  let image = state.map.tileCache.get(key);
  if (!image) {
    image = new Image();
    image.crossOrigin = 'anonymous';
    image.onload = drawMap;
    image.onerror = () => setStatus(`Не удалось загрузить тайл z${z}/${x}/${y}`);
    image.src = tileUrl(state.settings.serverUrl, state.settings.layerMode, z, x, y);
    state.map.tileCache.set(key, image);
  }
  if (image.complete && image.naturalWidth > 0) {
    ctx.drawImage(image, dx, dy, size, size);
  } else {
    ctx.fillStyle = (x + y) % 2 ? 'rgba(255,255,255,.025)' : 'rgba(255,255,255,.04)';
    ctx.fillRect(dx, dy, size, size);
  }
}

async function queueSelectedProvince() {
  const province = selectedProvince();
  const form = document.querySelector('[data-role="province-form"]');
  if (!province || !form) return;
  const formData = new FormData(form);
  const changes = {};
  for (const [field, value] of formData.entries()) {
    const next = String(value ?? '').trim();
    const prev = String(province[field] ?? '').trim();
    if (next !== prev) changes[field] = next;
  }
  if (Object.keys(changes).length === 0) {
    setStatus('Нет изменений для добавления в очередь.');
    return;
  }

  state.queue = state.queue.filter((entry) => !(entry.kind === 'province' && Number(entry.pid) === Number(province.pid)));
  state.queue.push({ kind: 'province', pid: Number(province.pid), changes });
  state.provinces = state.provinces.map((item) => Number(item.pid) === Number(province.pid) ? { ...item, ...changes } : item);
  await saveQueue(state.queue);
  await saveCachedProvinces(state.provinces);
  setStatus(`Правки провинции #${province.pid} добавлены в локальную очередь.`);
  render();
}

async function refreshFromServer() {
  try {
    setStatus('Проверка версии сервера…');
    applyServerFields(state.settings);
    await saveSettings(state.settings);
    state.version = await loadVersion(state.settings.serverUrl);
    await saveCachedVersion(state.version);

    setStatus('Загрузка справочников…');
    state.bootstrap = await loadBootstrap(state.settings.serverUrl);

    setStatus('Загрузка провинций с сервера…');
    state.provinces = await loadAllProvinces(state.settings.serverUrl, ({ loaded, total }) => {
      setStatus(`Загрузка провинций: ${loaded}/${total || '…'}`);
    });
    await saveCachedProvinces(state.provinces);

    await refreshLayerOnly();
    setStatus(`Готово: ${state.provinces.length} провинций загружено.`);
    render();
  } catch (error) {
    setStatus(`Ошибка подключения: ${error.message}`);
  }
}

async function refreshLayerOnly() {
  try {
    await loadLayer(state.settings.serverUrl, state.settings.layerMode, currentVersionHash());
    state.map.tileCache.clear();
    drawMap();
  } catch (error) {
    setStatus(`Слой будет загружаться тайлами: ${error.message}`);
    drawMap();
  }
}

async function syncQueue() {
  if (state.queue.length === 0) {
    setStatus('Очередь пуста.');
    return;
  }

  try {
    let remaining = [...state.queue];
    const batchSize = Number(state.settings.batchSize || 25);
    let appliedTotal = 0;

    while (remaining.length > 0) {
      const batch = remaining.slice(0, batchSize);
      setStatus(`Выгрузка пакета ${appliedTotal + 1}–${appliedTotal + batch.length}…`);
      const result = await applyChanges(state.settings.serverUrl, batch, currentVersionHash());
      appliedTotal += Number(result.applied || batch.length);
      remaining = remaining.slice(batch.length);
      state.queue = remaining;
      await saveQueue(state.queue);
      state.version = await loadVersion(state.settings.serverUrl);
      await saveCachedVersion(state.version);
    }

    setStatus(`Синхронизация завершена: применено ${appliedTotal}.`);
    await refreshFromServer();
  } catch (error) {
    await saveQueue(state.queue);
    const details = error.payload?.expected_version ? ` Ожидается версия ${error.payload.expected_version}.` : '';
    setStatus(`Ошибка выгрузки: ${error.message}.${details}`);
    render();
  }
}

async function resetCache() {
  await clearLocalCache();
  state.provinces = [];
  state.queue = [];
  state.version = null;
  state.map.tileCache.clear();
  setStatus('Локальный JSON-кеш очищен. Runtime-cache/ассеты клиента не блокируются и управляются окружением.');
  render();
}

async function boot() {
  state.settings = await loadSettings();
  applyServerFields(state.settings);
  state.settings.passthroughQuery ||= '';
  state.provinces = await loadCachedProvinces();
  state.version = await loadCachedVersion();
  state.queue = await loadQueue();
  state.status = state.provinces.length > 0
    ? `Открыт локальный кеш: ${state.provinces.length} провинций.`
    : 'Кеш пуст. Выберите модуль или нажмите «Подключиться», чтобы загрузить локальные данные.';
  window.addEventListener('online', () => { state.online = true; render(); });
  window.addEventListener('offline', () => { state.online = false; render(); });
  render();
}

boot().catch((error) => {
  app.innerHTML = `<pre class="fatal">${escapeHtml(error.stack || error.message)}</pre>`;
});
