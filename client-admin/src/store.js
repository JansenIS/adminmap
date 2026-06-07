const DB_NAME = 'adminmap-client';
const DB_VERSION = 1;
const SETTINGS_KEY = 'settings';
const PROVINCES_KEY = 'provinces';
const QUEUE_KEY = 'queue';
const VERSION_KEY = 'version';

let dbPromise;

function openDb() {
  if (dbPromise) return dbPromise;
  dbPromise = new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains('kv')) db.createObjectStore('kv');
    };
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(request.result);
  });
  return dbPromise;
}

async function getValue(key, fallback) {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction('kv', 'readonly');
    const request = tx.objectStore('kv').get(key);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => resolve(request.result ?? fallback);
  });
}

async function setValue(key, value) {
  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction('kv', 'readwrite');
    const request = tx.objectStore('kv').put(value, key);
    request.onerror = () => reject(request.error);
    tx.oncomplete = () => resolve(value);
    tx.onerror = () => reject(tx.error);
  });
}

export async function loadSettings() {
  return getValue(SETTINGS_KEY, {
    serverUrl: 'http://127.0.0.1:8080',
    serverProtocol: 'http',
    serverHost: '127.0.0.1',
    serverPort: '8080',
    serverBasePath: '',
    layerMode: 'provinces',
    batchSize: 25,
    passthroughQuery: '',
  });
}

export function saveSettings(settings) {
  return setValue(SETTINGS_KEY, settings);
}

export function loadCachedProvinces() {
  return getValue(PROVINCES_KEY, []);
}

export function saveCachedProvinces(provinces) {
  return setValue(PROVINCES_KEY, provinces);
}

export function loadCachedVersion() {
  return getValue(VERSION_KEY, null);
}

export function saveCachedVersion(version) {
  return setValue(VERSION_KEY, version);
}

export function loadQueue() {
  return getValue(QUEUE_KEY, []);
}

export function saveQueue(queue) {
  return setValue(QUEUE_KEY, queue);
}

export async function clearLocalCache() {
  await setValue(PROVINCES_KEY, []);
  await setValue(VERSION_KEY, null);
  await setValue(QUEUE_KEY, []);
}
