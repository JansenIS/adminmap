/** Утилиты: RNG, Heap, Dijkstra */

export class XorShift32 {
  /** @param {number} seed */
  constructor(seed = 1) {
    this.x = (seed | 0) || 1;
  }
  /** @returns {number} in [0,1) */
  next() {
    // xorshift32
    let x = this.x | 0;
    x ^= x << 13;
    x ^= x >>> 17;
    x ^= x << 5;
    this.x = x | 0;
    // convert to uint then to [0,1)
    return ((this.x >>> 0) / 4294967296);
  }
  /** @returns {number} стандартное нормальное распределение (Box-Muller) */
  nextNorm() {
    const u1 = Math.max(this.next(), 1e-12);
    const u2 = this.next();
    return Math.sqrt(-2 * Math.log(u1)) * Math.cos(2 * Math.PI * u2);
  }
  /** @param {number} a */
  int(a) { return Math.floor(this.next() * a); }
}

/** Мин-куча для Dijkstra */
export class MinHeap {
  constructor() { this.a = []; }
  size() { return this.a.length; }
  /** @param {{k:number,v:number}} item */
  push(item) {
    const a = this.a;
    a.push(item);
    let i = a.length - 1;
    while (i > 0) {
      const p = (i - 1) >> 1;
      if (a[p].k <= a[i].k) break;
      [a[p], a[i]] = [a[i], a[p]];
      i = p;
    }
  }
  /** @returns {{k:number,v:number}|undefined} */
  pop() {
    const a = this.a;
    if (!a.length) return undefined;
    const top = a[0];
    const last = a.pop();
    if (a.length && last) {
      a[0] = last;
      let i = 0;
      for (;;) {
        const l = i * 2 + 1, r = l + 1;
        let m = i;
        if (l < a.length && a[l].k < a[m].k) m = l;
        if (r < a.length && a[r].k < a[m].k) m = r;
        if (m === i) break;
        [a[m], a[i]] = [a[i], a[m]];
        i = m;
      }
    }
    return top;
  }
}

/**
 * Dijkstra: граф как adjacency list: edges[u] = Array<{to:number,w:number}>
 * @param {Array<Array<{to:number,w:number}>>} edges
 * @param {number} src
 * @returns {Float32Array} dist
 */
export function dijkstra(edges, src) {
  const n = edges.length;
  const dist = new Float32Array(n);
  dist.fill(Infinity);
  dist[src] = 0;

  const heap = new MinHeap();
  heap.push({ k: 0, v: src });

  while (heap.size()) {
    const cur = heap.pop();
    if (!cur) break;
    const d = cur.k;
    const u = cur.v;
    if (d !== dist[u]) continue;
    for (const e of edges[u]) {
      const nd = d + e.w;
      if (nd < dist[e.to]) {
        dist[e.to] = nd;
        heap.push({ k: nd, v: e.to });
      }
    }
  }
  return dist;
}

/** clamp */
export function clamp(x, a, b) { return Math.max(a, Math.min(b, x)); }
