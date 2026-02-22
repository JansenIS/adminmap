/* app_core.js
 * Raster province interaction engine with optional emblem layer.
 */

(function () {
  "use strict";

  function clamp(n, a, b) { return Math.max(a, Math.min(b, n)); }

  function hexToRgb(hex) {
    const m = /^#?([0-9a-f]{6})$/i.exec(hex || "");
    if (!m) return [255, 0, 0];
    const n = parseInt(m[1], 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  }

  function rgbToHex(r, g, b) {
    return "#" + [r, g, b].map(v => (v | 0).toString(16).padStart(2, "0")).join("");
  }

  function toBase64Utf8(str) {
    return btoa(unescape(encodeURIComponent(str)));
  }

  function parseSvgBox(svgText) {
    // Prefer numeric width/height if not percent; otherwise fall back to viewBox.
    const wMatch = svgText.match(/\bwidth\s*=\s*["']\s*([0-9.]+)\s*([a-z%]*)\s*["']/i);
    const hMatch = svgText.match(/\bheight\s*=\s*["']\s*([0-9.]+)\s*([a-z%]*)\s*["']/i);
    if (wMatch && hMatch) {
      const unitW = (wMatch[2] || "").trim().toLowerCase();
      const unitH = (hMatch[2] || "").trim().toLowerCase();
      const w = parseFloat(wMatch[1]);
      const h = parseFloat(hMatch[1]);
      const isPercent = (unitW === "%") || (unitH === "%");
      if (!isPercent && isFinite(w) && isFinite(h) && w > 0 && h > 0) return { w, h };
    }

    const vb = svgText.match(/\bviewBox\s*=\s*["']\s*([0-9.\-eE]+)\s+([0-9.\-eE]+)\s+([0-9.\-eE]+)\s+([0-9.\-eE]+)\s*["']/i);
    if (vb) {
      const w = parseFloat(vb[3]);
      const h = parseFloat(vb[4]);
      if (isFinite(w) && isFinite(h) && w > 0 && h > 0) return { w, h };
    }

    return { w: 2000, h: 2400 };
  }

  class RasterProvinceMap {
    constructor(opts) {
      this.opts = opts || {};

      this.baseImg = document.getElementById(this.opts.baseImgId || "baseMap");
      this.fillCanvas = document.getElementById(this.opts.fillCanvasId || "fill");
      this.emblemCanvas = document.getElementById(this.opts.emblemCanvasId || "emblems");
      this.hoverCanvas = document.getElementById(this.opts.hoverCanvasId || "hover");

      if (!this.baseImg || !this.fillCanvas || !this.hoverCanvas) {
        throw new Error("Missing required DOM elements for map (base image / canvases).");
      }
      if (!this.emblemCanvas) {
        this.emblemCanvas = document.createElement("canvas");
      }

      this.fillCtx = this.fillCanvas.getContext("2d", { willReadFrequently: true });
      this.emblemCtx = this.emblemCanvas.getContext("2d", { willReadFrequently: true });
      this.hoverCtx = this.hoverCanvas.getContext("2d", { willReadFrequently: true });

      this.W = 0;
      this.H = 0;

      this.keyPerPixel = null;
      this.provincesByKey = new Map();
      this.fillByKey = new Map();

      this.emblemByKey = new Map();   // key -> {src, box:{w,h}, margin, scale}
      this.clipMaskByKey = new Map(); // key -> canvas mask
      this.imgCache = new Map();      // src -> Promise<Image>

      this.hoverKey = 0;
      this.selectedKey = 0;

      this.onHover = typeof this.opts.onHover === "function" ? this.opts.onHover : null;
      this.onClick = typeof this.opts.onClick === "function" ? this.opts.onClick : null;
      this.onReady = typeof this.opts.onReady === "function" ? this.opts.onReady : null;

      this._boundMouseMove = this._handleMouseMove.bind(this);
      this._boundMouseLeave = this._handleMouseLeave.bind(this);
      this._boundClick = this._handleClick.bind(this);
    }

    async init() {
      await this._ensureBaseLoaded();
      this.W = this.baseImg.naturalWidth;
      this.H = this.baseImg.naturalHeight;

      this.fillCanvas.width = this.W; this.fillCanvas.height = this.H;
      this.emblemCanvas.width = this.W; this.emblemCanvas.height = this.H;
      this.hoverCanvas.width = this.W; this.hoverCanvas.height = this.H;

      const provincesMeta = await this._loadJSON(this.opts.provincesMetaUrl || "provinces.json");
      if (!provincesMeta || !Array.isArray(provincesMeta.provinces)) throw new Error("Invalid provinces.json");

      for (const p of provincesMeta.provinces) {
        this.provincesByKey.set((p.key >>> 0), p);
      }

      const maskImg = await this._loadImage(this.opts.maskUrl || "provinces_id.png");
      if (maskImg.naturalWidth !== this.W || maskImg.naturalHeight !== this.H) {
        throw new Error("provinces_id.png size mismatch vs map.png");
      }

      this.keyPerPixel = this._buildKeyPerPixel(maskImg);

      this.baseImg.addEventListener("mousemove", this._boundMouseMove);
      this.baseImg.addEventListener("mouseleave", this._boundMouseLeave);
      this.baseImg.addEventListener("click", this._boundClick);

      if (this.onReady) this.onReady({ W: this.W, H: this.H, provincesByKey: this.provincesByKey });
    }

    getProvinceMeta(key) {
      return this.provincesByKey.get(key >>> 0) || null;
    }

    eventToImageXY(evt) {
      const rect = this.baseImg.getBoundingClientRect();
      const x = (evt.clientX - rect.left) * (this.W / rect.width);
      const y = (evt.clientY - rect.top) * (this.H / rect.height);
      return [Math.floor(clamp(x, 0, this.W - 1)), Math.floor(clamp(y, 0, this.H - 1))];
    }

    pickKeyAt(x, y, radius) {
      const r = (radius == null ? 5 : radius) | 0;
      const idx0 = y * this.W + x;
      const k0 = this.keyPerPixel[idx0] >>> 0;
      if (k0) return k0;

      for (let rr = 1; rr <= r; rr++) {
        const x0 = clamp(x - rr, 0, this.W - 1);
        const x1 = clamp(x + rr, 0, this.W - 1);
        const y0 = clamp(y - rr, 0, this.H - 1);
        const y1 = clamp(y + rr, 0, this.H - 1);

        for (let xx = x0; xx <= x1; xx++) {
          let k = this.keyPerPixel[y0 * this.W + xx] >>> 0; if (k) return k;
          k = this.keyPerPixel[y1 * this.W + xx] >>> 0; if (k) return k;
        }
        for (let yy = y0; yy <= y1; yy++) {
          let k = this.keyPerPixel[yy * this.W + x0] >>> 0; if (k) return k;
          k = this.keyPerPixel[yy * this.W + x1] >>> 0; if (k) return k;
        }
      }
      return 0;
    }

    // Fill
    setFill(key, rgba) {
      const k = key >>> 0;
      if (!k) return;
      this.fillByKey.set(k, rgba);
      this.paintProvince(this.fillCtx, k, rgba, false);
    }

    clearFill(key) {
      const k = key >>> 0;
      if (!k) return;
      this.fillByKey.delete(k);
      this.paintProvince(this.fillCtx, k, [0, 0, 0, 0], true);
    }

    clearAllFills() {
      this.fillByKey.clear();
      this.fillCtx.clearRect(0, 0, this.W, this.H);
    }

    // Hover
    clearHover() {
      this.hoverCtx.clearRect(0, 0, this.W, this.H);
      this.hoverKey = 0;
    }

    setHoverHighlight(key, rgba) {
      const k = key >>> 0;
      if (!k) { this.clearHover(); return; }
      this.hoverCtx.clearRect(0, 0, this.W, this.H);
      this.paintProvince(this.hoverCtx, k, rgba || [255, 255, 255, 60], false);
      this.hoverKey = k;
    }

    // Emblems
    setEmblem(key, src, box, options) {
      const k = key >>> 0;
      if (!k) return;
      if (!src) { this.clearEmblem(k); return; }

      const opt = options || {};
      const margin = (opt.margin == null ? 0.12 : +opt.margin);
      const scale = (opt.scale == null ? 1.0 : +opt.scale);

      const b = box && typeof box.w === "number" && typeof box.h === "number" ? box : { w: 2000, h: 2400 };

      this.emblemByKey.set(k, { src, box: b, margin, scale });
      this._drawSingleEmblem(k).catch(() => {});
    }

    clearEmblem(key) {
      const k = key >>> 0;
      if (!k) return;
      this.emblemByKey.delete(k);
      const meta = this.getProvinceMeta(k);
      if (!meta) return;
      const [x0, y0, x1, y1] = meta.bbox;
      this.emblemCtx.clearRect(x0, y0, x1 - x0, y1 - y0);
    }

    clearAllEmblems() {
      this.emblemByKey.clear();
      this.emblemCtx.clearRect(0, 0, this.W, this.H);
    }

    async repaintAllEmblems() {
      this.emblemCtx.clearRect(0, 0, this.W, this.H);
      for (const k of this.emblemByKey.keys()) {
        await this._drawSingleEmblem(k);
      }
    }

    async _drawSingleEmblem(key) {
      const k = key >>> 0;
      const emblem = this.emblemByKey.get(k);
      if (!emblem) return;

      const meta = this.getProvinceMeta(k);
      if (!meta) return;

      const [x0, y0, x1, y1] = meta.bbox;
      const bw = x1 - x0, bh = y1 - y0;
      if (bw <= 0 || bh <= 0) return;

      this.emblemCtx.clearRect(x0, y0, bw, bh);
      const img = await this._loadImageCached(emblem.src);

      const marginFrac = clamp(emblem.margin, 0, 0.45);
      const innerW = Math.max(1, Math.floor(bw * (1 - 2 * marginFrac)));
      const innerH = Math.max(1, Math.floor(bh * (1 - 2 * marginFrac)));

      const boxW = (emblem.box && emblem.box.w) ? emblem.box.w : 2000;
      const boxH = (emblem.box && emblem.box.h) ? emblem.box.h : 2400;
      const aspect = boxW / boxH;

      let tw = innerW;
      let th = Math.round(tw / aspect);
      if (th > innerH) { th = innerH; tw = Math.round(th * aspect); }

      tw = Math.max(1, Math.floor(tw * clamp(emblem.scale, 0.2, 3.0)));
      th = Math.max(1, Math.floor(th * clamp(emblem.scale, 0.2, 3.0)));

      const cx = (meta.centroid && meta.centroid[0] != null) ? +meta.centroid[0] : (x0 + bw / 2);
      const cy = (meta.centroid && meta.centroid[1] != null) ? +meta.centroid[1] : (y0 + bh / 2);

      let dx = Math.round(cx - x0 - tw / 2);
      let dy = Math.round(cy - y0 - th / 2);
      dx = clamp(dx, 0, Math.max(0, bw - tw));
      dy = clamp(dy, 0, Math.max(0, bh - th));

      const patch = document.createElement("canvas");
      patch.width = bw;
      patch.height = bh;
      const pctx = patch.getContext("2d", { willReadFrequently: true });

      pctx.clearRect(0, 0, bw, bh);
      pctx.imageSmoothingEnabled = true;
      pctx.drawImage(img, dx, dy, tw, th);

      const mask = this._getClipMaskCanvas(k);
      pctx.globalCompositeOperation = "destination-in";
      pctx.drawImage(mask, 0, 0);
      pctx.globalCompositeOperation = "source-over";

      this.emblemCtx.drawImage(patch, x0, y0);
    }

    _getClipMaskCanvas(key) {
      const k = key >>> 0;
      const cached = this.clipMaskByKey.get(k);
      if (cached) return cached;

      const meta = this.getProvinceMeta(k);
      if (!meta) {
        const d = document.createElement("canvas"); d.width = 1; d.height = 1; return d;
      }

      const [x0, y0, x1, y1] = meta.bbox;
      const bw = x1 - x0, bh = y1 - y0;

      const c = document.createElement("canvas");
      c.width = bw; c.height = bh;
      const ctx = c.getContext("2d", { willReadFrequently: true });

      const img = ctx.createImageData(bw, bh);
      const data = img.data;

      let p = 0;
      for (let yy = 0; yy < bh; yy++) {
        const row = (y0 + yy) * this.W;
        for (let xx = 0; xx < bw; xx++, p += 4) {
          const kk = this.keyPerPixel[row + (x0 + xx)] >>> 0;
          if (kk === k) {
            data[p + 0] = 255; data[p + 1] = 255; data[p + 2] = 255; data[p + 3] = 255;
          } else {
            data[p + 3] = 0;
          }
        }
      }
      ctx.putImageData(img, 0, 0);
      this.clipMaskByKey.set(k, c);
      return c;
    }

    async _loadImageCached(src) {
      const s = String(src || "");
      if (!s) throw new Error("Empty image src");
      if (this.imgCache.has(s)) return this.imgCache.get(s);

      const prom = new Promise((resolve, reject) => {
        const im = new Image();
        im.decoding = "async";
        im.onload = () => resolve(im);
        im.onerror = () => reject(new Error("Failed to load image"));
        im.src = s;
      });

      this.imgCache.set(s, prom);
      return prom;
    }

    // Low-level paint helper
    paintProvince(ctx, key, rgba, clear) {
      const meta = this.provincesByKey.get(key >>> 0);
      if (!meta) return;

      const [x0, y0, x1, y1] = meta.bbox;
      const w = x1 - x0, h = y1 - y0;
      if (w <= 0 || h <= 0) return;

      const imgData = ctx.getImageData(x0, y0, w, h);
      const data = imgData.data;

      const rr = rgba[0] | 0, gg = rgba[1] | 0, bb = rgba[2] | 0, aa = rgba[3] | 0;

      let p = 0;
      for (let yy = y0; yy < y1; yy++) {
        const row = yy * this.W;
        for (let xx = x0; xx < x1; xx++, p += 4) {
          const kk = this.keyPerPixel[row + xx] >>> 0;
          if (kk !== (key >>> 0)) continue;

          if (clear) {
            data[p + 3] = 0;
          } else {
            data[p + 0] = rr;
            data[p + 1] = gg;
            data[p + 2] = bb;
            data[p + 3] = aa;
          }
        }
      }
      ctx.putImageData(imgData, x0, y0);
    }

    // Events
    _handleMouseMove(evt) {
      if (!this.keyPerPixel) return;
      const [x, y] = this.eventToImageXY(evt);
      const key = this.pickKeyAt(x, y, 4);

      if (!key) {
        if (this.hoverKey) this.clearHover();
        if (this.onHover) this.onHover({ key: 0, meta: null, evt });
        return;
      }

      if (key !== this.hoverKey) this.setHoverHighlight(key, [255, 255, 255, 60]);
      if (this.onHover) this.onHover({ key, meta: this.getProvinceMeta(key), evt });
    }

    _handleMouseLeave(evt) {
      this.clearHover();
      if (this.onHover) this.onHover({ key: 0, meta: null, evt });
    }

    _handleClick(evt) {
      if (!this.keyPerPixel) return;
      const [x, y] = this.eventToImageXY(evt);
      const key = this.pickKeyAt(x, y, 6);
      if (!key) return;
      this.selectedKey = key >>> 0;
      if (this.onClick) this.onClick({ key, meta: this.getProvinceMeta(key), evt });
    }

    // Bootstrap helpers
    _buildKeyPerPixel(maskImg) {
      const off = document.createElement("canvas");
      off.width = this.W; off.height = this.H;
      const offCtx = off.getContext("2d", { willReadFrequently: true });

      offCtx.imageSmoothingEnabled = false;
      offCtx.clearRect(0, 0, this.W, this.H);
      offCtx.drawImage(maskImg, 0, 0);

      const maskData = offCtx.getImageData(0, 0, this.W, this.H).data;
      const arr = new Uint32Array(this.W * this.H);

      for (let i = 0, p = 0; i < arr.length; i++, p += 4) {
        const alpha = maskData[p + 3];
        if (!alpha) { arr[i] = 0; continue; }
        arr[i] = ((maskData[p] << 16) | (maskData[p + 1] << 8) | (maskData[p + 2])) >>> 0;
      }
      return arr;
    }

    async _ensureBaseLoaded() {
      if (this.baseImg.complete && this.baseImg.naturalWidth) return;
      await new Promise((resolve, reject) => {
        this.baseImg.addEventListener("load", resolve, { once: true });
        this.baseImg.addEventListener("error", () => reject(new Error("Failed to load base map image")), { once: true });
      });
    }

    async _loadJSON(url) {
      const res = await fetch(url, { cache: "no-store" });
      if (!res.ok) throw new Error("HTTP " + res.status + " for " + url);
      return res.json();
    }

    _loadImage(url) {
      return new Promise((resolve, reject) => {
        const im = new Image();
        im.decoding = "async";
        im.onload = () => resolve(im);
        im.onerror = () => reject(new Error("Failed to load image: " + url));
        im.src = url;
      });
    }
  }

  window.RasterProvinceMap = RasterProvinceMap;
  window.MapUtils = { clamp, hexToRgb, rgbToHex, toBase64Utf8, parseSvgBox };
})();
