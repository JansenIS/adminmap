const DEFAULT_TIMEOUT_MS = 20_000;

function joinUrl(baseUrl, path) {
  const base = String(baseUrl || '').trim().replace(/\/+$/, '');
  const cleanPath = String(path || '').replace(/^\/+/, '');
  if (!base) throw new Error('Не указан адрес основного сервера.');
  return `${base}/${cleanPath}`;
}

async function requestJson(baseUrl, path, options = {}) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), options.timeoutMs ?? DEFAULT_TIMEOUT_MS);
  const headers = new Headers(options.headers || {});
  headers.set('Accept', 'application/json');
  if (options.body !== undefined) headers.set('Content-Type', 'application/json; charset=utf-8');

  try {
    const response = await fetch(joinUrl(baseUrl, path), {
      method: options.method || 'GET',
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: controller.signal,
    });

    const text = response.status === 304 ? '' : await response.text();
    const data = text ? JSON.parse(text) : { notModified: true };
    if (!response.ok) {
      const message = data?.error || data?.message || `HTTP ${response.status}`;
      const error = new Error(message);
      error.status = response.status;
      error.payload = data;
      throw error;
    }
    return data;
  } finally {
    clearTimeout(timeout);
  }
}

export function normalizeServerUrl(input) {
  const value = String(input || '').trim();
  if (!value) return '';
  try {
    const url = new URL(value);
    return url.origin + url.pathname.replace(/\/+$/, '');
  } catch (_error) {
    return value.replace(/\/+$/, '');
  }
}

export async function loadVersion(baseUrl) {
  return requestJson(baseUrl, 'api/map/version/');
}

export async function loadBootstrap(baseUrl) {
  return requestJson(baseUrl, 'api/map/bootstrap/?profile=compact');
}

export async function loadProvincesPage(baseUrl, offset = 0, limit = 500) {
  const params = new URLSearchParams({ offset: String(offset), limit: String(limit), profile: 'compact' });
  return requestJson(baseUrl, `api/provinces/?${params.toString()}`);
}

export async function loadAllProvinces(baseUrl, onProgress = () => {}) {
  const limit = 500;
  let offset = 0;
  let total = Number.POSITIVE_INFINITY;
  const items = [];

  while (offset < total) {
    const page = await loadProvincesPage(baseUrl, offset, limit);
    total = Number(page.total || 0);
    const chunk = Array.isArray(page.items) ? page.items : [];
    items.push(...chunk);
    offset += chunk.length;
    onProgress({ loaded: items.length, total });
    if (chunk.length === 0) break;
  }

  return items;
}

export async function loadLayer(baseUrl, mode = 'provinces', version = '') {
  const params = new URLSearchParams({ mode });
  if (version) params.set('version', version);
  return requestJson(baseUrl, `api/render/layer/?${params.toString()}`);
}

export function tileUrl(baseUrl, mode, z, x, y) {
  const params = new URLSearchParams({ mode, z: String(z), x: String(x), y: String(y) });
  return joinUrl(baseUrl, `api/tiles/?${params.toString()}`);
}

export async function applyChanges(baseUrl, changes, ifMatch) {
  if (!Array.isArray(changes) || changes.length === 0) return { ok: true, applied: 0 };
  return requestJson(baseUrl, 'api/changes/apply/', {
    method: 'POST',
    headers: ifMatch ? { 'If-Match': ifMatch } : {},
    body: { changes, if_match: ifMatch || '' },
    timeoutMs: 30_000,
  });
}
