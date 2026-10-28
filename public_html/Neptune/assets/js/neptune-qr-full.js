/* Neptune QR (full): QR Model 2, versions 1-40, error correction L/M/Q/H,
   alphanumeric and byte modes. Used for scout hand-off codes, which are far larger
   than the 78-byte Neptune QR v4-L used for the "open on phone" link.
   Follows the ISO/IEC 18004 construction (same structure as Project Nayuki's
   reference encoder). window.NeptuneQRFull.encode(text, {ecl:'M'}) -> {size, dark(x,y)}. */
(function (root) {
  'use strict';
  const ECC_PER_BLOCK = {
    L: [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    M: [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
    Q: [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    H: [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30]
  };
  const NUM_BLOCKS = {
    L: [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
    M: [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
    Q: [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
    H: [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81]
  };
  const FORMAT_ECL = { L: 1, M: 0, Q: 3, H: 2 };
  const ALNUM = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

  function gfMul(x, y) {
    let z = 0;
    for (let i = 7; i >= 0; i--) { z = (z << 1) ^ ((z >>> 7) * 0x11D); z ^= ((y >>> i) & 1) * x; }
    return z & 0xFF;
  }
  function rsDivisor(degree) {
    const result = new Array(degree).fill(0); result[degree - 1] = 1;
    let rootVal = 1;
    for (let i = 0; i < degree; i++) {
      for (let j = 0; j < result.length; j++) {
        result[j] = gfMul(result[j], rootVal);
        if (j + 1 < result.length) result[j] ^= result[j + 1];
      }
      rootVal = gfMul(rootVal, 0x02);
    }
    return result;
  }
  function rsRemainder(data, divisor) {
    const result = divisor.map(() => 0);
    for (const b of data) {
      const factor = b ^ result.shift(); result.push(0);
      divisor.forEach((coef, i) => { result[i] ^= gfMul(coef, factor); });
    }
    return result;
  }
  function rawModules(ver) {
    let r = (16 * ver + 128) * ver + 64;
    if (ver >= 2) { const n = Math.floor(ver / 7) + 2; r -= (25 * n - 10) * n - 55; if (ver >= 7) r -= 36; }
    return r;
  }
  function dataCodewords(ver, ecl) {
    return Math.floor(rawModules(ver) / 8) - ECC_PER_BLOCK[ecl][ver] * NUM_BLOCKS[ecl][ver];
  }
  function isAlnum(text) { for (const c of text) if (ALNUM.indexOf(c) < 0) return false; return true; }
  function countBits(mode, ver) {
    const i = ver <= 9 ? 0 : ver <= 26 ? 1 : 2;
    return mode === 'alnum' ? [9, 11, 13][i] : [8, 16, 16][i];
  }
  function segmentBits(text) {
    const bits = [];
    const push = (val, len) => { for (let i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1); };
    if (isAlnum(text)) {
      let i = 0;
      for (; i + 1 < text.length; i += 2) push(ALNUM.indexOf(text[i]) * 45 + ALNUM.indexOf(text[i + 1]), 11);
      if (i < text.length) push(ALNUM.indexOf(text[i]), 6);
      return { mode: 'alnum', modeBits: 0x2, count: text.length, bits };
    }
    const bytes = new TextEncoder().encode(text);
    bytes.forEach(b => push(b, 8));
    return { mode: 'byte', modeBits: 0x4, count: bytes.length, bits };
  }

  function encode(text, opts) {
    const ecl = (opts && opts.ecl) || 'M';
    const seg = segmentBits(String(text));
    let ver = 1, used = 0;
    for (; ver <= 40; ver++) {
      used = 4 + countBits(seg.mode, ver) + seg.bits.length;
      if (used <= dataCodewords(ver, ecl) * 8) break;
    }
    if (ver > 40) throw new Error('Text is too long for one QR code.');
    const bits = [];
    const push = (val, len) => { for (let i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1); };
    push(seg.modeBits, 4); push(seg.count, countBits(seg.mode, ver));
    seg.bits.forEach(b => bits.push(b));
    const capBits = dataCodewords(ver, ecl) * 8;
    push(0, Math.min(4, capBits - bits.length));
    push(0, (8 - bits.length % 8) % 8);
    for (let pad = 0xEC; bits.length < capBits; pad ^= 0xEC ^ 0x11) push(pad, 8);
    const data = [];
    for (let i = 0; i < bits.length; i += 8) { let b = 0; for (let j = 0; j < 8; j++) b = (b << 1) | bits[i + j]; data.push(b); }

    // Split into blocks, add Reed-Solomon codewords, interleave.
    const numBlocks = NUM_BLOCKS[ecl][ver], blockEcc = ECC_PER_BLOCK[ecl][ver];
    const rawCw = Math.floor(rawModules(ver) / 8);
    const numShort = numBlocks - rawCw % numBlocks, shortLen = Math.floor(rawCw / numBlocks);
    const divisor = rsDivisor(blockEcc);
    const blocks = [];
    for (let i = 0, k = 0; i < numBlocks; i++) {
      const dat = data.slice(k, k + shortLen - blockEcc + (i < numShort ? 0 : 1));
      k += dat.length;
      const ecc = rsRemainder(dat, divisor);
      if (i < numShort) dat.push(0);
      blocks.push(dat.concat(ecc));
    }
    const codewords = [];
    for (let i = 0; i < blocks[0].length; i++) {
      blocks.forEach((block, j) => { if (i !== shortLen - blockEcc || j >= numShort) codewords.push(block[i]); });
    }

    const size = ver * 4 + 17;
    const mods = Array.from({ length: size }, () => new Array(size).fill(false));
    const isFn = Array.from({ length: size }, () => new Array(size).fill(false));
    const setFn = (x, y, dark) => { mods[y][x] = dark; isFn[y][x] = true; };
    for (let i = 0; i < size; i++) { setFn(6, i, i % 2 === 0); setFn(i, 6, i % 2 === 0); }
    const finder = (cx, cy) => {
      for (let dy = -4; dy <= 4; dy++) for (let dx = -4; dx <= 4; dx++) {
        const d = Math.max(Math.abs(dx), Math.abs(dy)), x = cx + dx, y = cy + dy;
        if (x >= 0 && x < size && y >= 0 && y < size) setFn(x, y, d !== 2 && d !== 4);
      }
    };
    finder(3, 3); finder(size - 4, 3); finder(3, size - 4);
    const align = [];
    if (ver > 1) {
      const n = Math.floor(ver / 7) + 2;
      const step = ver === 32 ? 26 : Math.ceil((ver * 4 + 4) / (n * 2 - 2)) * 2;
      align.push(6);
      for (let pos = size - 7; align.length < n; pos -= step) align.splice(1, 0, pos);
    }
    align.forEach((ay, i) => align.forEach((ax, j) => {
      if ((i === 0 && j === 0) || (i === 0 && j === align.length - 1) || (i === align.length - 1 && j === 0)) return;
      for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) setFn(ax + dx, ay + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
    }));
    const drawFormat = mask => {
      const d = FORMAT_ECL[ecl] << 3 | mask;
      let rem = d;
      for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
      const b = (d << 10 | rem) ^ 0x5412;
      const bit = i => ((b >>> i) & 1) !== 0;
      for (let i = 0; i <= 5; i++) setFn(8, i, bit(i));
      setFn(8, 7, bit(6)); setFn(8, 8, bit(7)); setFn(7, 8, bit(8));
      for (let i = 9; i < 15; i++) setFn(14 - i, 8, bit(i));
      for (let i = 0; i < 8; i++) setFn(size - 1 - i, 8, bit(i));
      for (let i = 8; i < 15; i++) setFn(8, size - 15 + i, bit(i));
      setFn(8, size - 8, true);
    };
    drawFormat(0);
    if (ver >= 7) {
      let rem = ver;
      for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
      const b = ver << 12 | rem;
      for (let i = 0; i < 18; i++) {
        const dark = ((b >>> i) & 1) !== 0, a = size - 11 + i % 3, c = Math.floor(i / 3);
        setFn(a, c, dark); setFn(c, a, dark);
      }
    }
    // Place data bits in the zigzag order.
    let bi = 0;
    for (let right = size - 1; right >= 1; right -= 2) {
      if (right === 6) right = 5;
      for (let vert = 0; vert < size; vert++) for (let j = 0; j < 2; j++) {
        const x = right - j, upward = ((right + 1) & 2) === 0, y = upward ? size - 1 - vert : vert;
        if (!isFn[y][x] && bi < codewords.length * 8) { mods[y][x] = ((codewords[bi >>> 3] >>> (7 - (bi & 7))) & 1) !== 0; bi++; }
      }
    }
    const maskFn = [
      (x, y) => (x + y) % 2 === 0, (x, y) => y % 2 === 0, x => x % 3 === 0, (x, y) => (x + y) % 3 === 0,
      (x, y) => (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0, (x, y) => x * y % 2 + x * y % 3 === 0,
      (x, y) => (x * y % 2 + x * y % 3) % 2 === 0, (x, y) => ((x + y) % 2 + x * y % 3) % 2 === 0
    ];
    const applyMask = m => { for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) if (!isFn[y][x] && maskFn[m](x, y)) mods[y][x] = !mods[y][x]; };
    const penalty = () => {
      let p = 0, dark = 0;
      for (let y = 0; y < size; y++) {
        for (const horiz of [true, false]) {
          let run = 1;
          for (let i = 1; i <= size; i++) {
            const same = i < size && (horiz ? mods[y][i] === mods[y][i - 1] : mods[i][y] === mods[i - 1][y]);
            if (same) run++; else { if (run >= 5) p += run - 2; run = 1; }
          }
        }
        for (let x = 0; x < size; x++) {
          if (mods[y][x]) dark++;
          if (x < size - 1 && y < size - 1) { const c = mods[y][x]; if (c === mods[y][x + 1] && c === mods[y + 1][x] && c === mods[y + 1][x + 1]) p += 3; }
        }
      }
      const total = size * size;
      p += (Math.ceil(Math.abs(dark * 20 - total * 10) / total) - 1) * 10;
      return p;
    };
    let best = 0, bestPenalty = Infinity;
    for (let m = 0; m < 8; m++) {
      applyMask(m); drawFormat(m);
      const p = penalty();
      if (p < bestPenalty) { best = m; bestPenalty = p; }
      applyMask(m);
    }
    applyMask(best); drawFormat(best);
    return { version: ver, size, dark: (x, y) => mods[y][x] };
  }

  function draw(canvas, text, opts) {
    const o = Object.assign({ ecl: 'M', quiet: 4, maxPx: 0, dark: '#000', light: '#fff' }, opts || {});
    const qr = encode(text, o);
    const cells = qr.size + o.quiet * 2;
    const scale = Math.max(1, Math.floor((o.maxPx || cells * 6) / cells));
    canvas.width = canvas.height = cells * scale;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = o.light; ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = o.dark;
    for (let y = 0; y < qr.size; y++) for (let x = 0; x < qr.size; x++) if (qr.dark(x, y)) ctx.fillRect((x + o.quiet) * scale, (y + o.quiet) * scale, scale, scale);
    return qr;
  }

  root.NeptuneQRFull = { encode, draw, isAlnum, ALNUM };
})(typeof window !== 'undefined' ? window : globalThis);
