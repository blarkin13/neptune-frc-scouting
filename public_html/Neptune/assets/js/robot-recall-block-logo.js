(() => {
  'use strict';

  function quantize(v, step = 32) {
    return Math.max(0, Math.min(255, Math.round(v / step) * step));
  }

  function render(canvas, fallback, src, options = {}) {
    if (!(canvas instanceof HTMLCanvasElement) || !src) return;
    const key = String(options.key ?? src);
    if (canvas.dataset.logoKey === key) return;
    canvas.dataset.logoKey = key;

    const grid = Math.max(12, Math.min(28, Number(options.grid) || 18));
    const size = Math.max(320, Math.min(900, Number(options.size) || 512));
    const img = new Image();
    img.decoding = 'async';

    img.onload = () => {
      try {
        const iw = img.naturalWidth || img.width;
        const ih = img.naturalHeight || img.height;
        if (!iw || !ih) throw new Error('Logo has no dimensions');

        const scale = grid / Math.max(iw, ih);
        const lw = Math.max(1, Math.round(iw * scale));
        const lh = Math.max(1, Math.round(ih * scale));
        const logical = document.createElement('canvas');
        logical.width = lw; logical.height = lh;
        const lctx = logical.getContext('2d', { willReadFrequently: true });
        if (!lctx) throw new Error('Canvas unavailable');
        lctx.clearRect(0, 0, lw, lh);
        lctx.imageSmoothingEnabled = true;
        lctx.drawImage(img, 0, 0, lw, lh);
        const data = lctx.getImageData(0, 0, lw, lh);

        canvas.width = size; canvas.height = size;
        const ctx = canvas.getContext('2d');
        if (!ctx) throw new Error('Canvas unavailable');
        ctx.clearRect(0, 0, size, size);
        ctx.imageSmoothingEnabled = false;

        const inner = Math.round(size * .92);
        const cell = Math.max(4, Math.floor(inner / Math.max(lw, lh)));
        const fw = lw * cell, fh = lh * cell;
        const x0 = Math.floor((size - fw) / 2), y0 = Math.floor((size - fh) / 2);
        const bevel = Math.max(1, Math.round(cell * .06));

        for (let y = 0; y < lh; y++) {
          for (let x = 0; x < lw; x++) {
            const i = (y * lw + x) * 4;
            const a = data.data[i + 3];
            if (a < 90) continue;
            const r = quantize(data.data[i]), g = quantize(data.data[i + 1]), b = quantize(data.data[i + 2]);
            const left = x0 + x * cell, top = y0 + y * cell;
            ctx.globalAlpha = a / 255;
            ctx.fillStyle = `rgb(${r} ${g} ${b})`;
            ctx.fillRect(left, top, cell, cell);
            if (cell >= 13) {
              ctx.fillStyle = `rgb(${Math.min(255,r+18)} ${Math.min(255,g+18)} ${Math.min(255,b+18)})`;
              ctx.fillRect(left, top, cell, bevel); ctx.fillRect(left, top, bevel, cell);
              ctx.fillStyle = `rgb(${Math.max(0,r-22)} ${Math.max(0,g-22)} ${Math.max(0,b-22)})`;
              ctx.fillRect(left, top + cell - bevel, cell, bevel); ctx.fillRect(left + cell - bevel, top, bevel, cell);
            }
          }
        }
        ctx.globalAlpha = 1;
        canvas.hidden = false;
        if (fallback instanceof HTMLImageElement) fallback.hidden = true;
      } catch (_) {
        canvas.hidden = true;
        if (fallback instanceof HTMLImageElement) { fallback.src = src; fallback.hidden = false; }
      }
    };
    img.onerror = () => {
      canvas.hidden = true;
      if (fallback instanceof HTMLImageElement) { fallback.src = src; fallback.hidden = false; }
    };
    img.src = src;
  }

  window.NeptuneRobotRecallBlockLogo = { render };
})();
