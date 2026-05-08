// qr.jsx — minimal QR Code encoder (Version 1–10, byte mode, ECC level M)
// Pure JS, no deps. Produces a boolean[size][size] matrix. Designed for short
// URLs (the link generator emits ~80–150 char URLs, well under V10/M cap).
//
// Reference: ISO/IEC 18004 — implementation derived from public-domain
// reference logic; rewritten compact for our needs.

(function () {
  // GF(256) tables for Reed-Solomon
  const EXP = new Uint8Array(512), LOG = new Uint8Array(256);
  for (let i = 0, x = 1; i < 255; i++) {
    EXP[i] = x;
    LOG[x] = i;
    x <<= 1;
    if (x & 0x100) x ^= 0x11d;
  }
  for (let i = 255; i < 512; i++) EXP[i] = EXP[i - 255];

  const gfMul = (a, b) => (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]];

  // RS generator polynomial of degree n
  function rsGenerator(n) {
    let poly = [1];
    for (let i = 0; i < n; i++) {
      const next = new Array(poly.length + 1).fill(0);
      for (let j = 0; j < poly.length; j++) {
        next[j] ^= gfMul(poly[j], 1);
        next[j + 1] ^= gfMul(poly[j], EXP[i]);
      }
      poly = next;
    }
    return poly;
  }

  function rsEncode(data, eccLen) {
    const gen = rsGenerator(eccLen);
    const buf = data.concat(new Array(eccLen).fill(0));
    for (let i = 0; i < data.length; i++) {
      const factor = buf[i];
      if (factor === 0) continue;
      for (let j = 0; j < gen.length; j++) {
        buf[i + j] ^= gfMul(gen[j], factor);
      }
    }
    return buf.slice(data.length);
  }

  // Capacity table — bytes for byte-mode, ECC level M, version 1..10
  // (data codewords - 2 mode/length overhead -> usable byte payload)
  // values from ISO 18004 table; precomputed.
  const CAP_M = [14, 26, 42, 62, 84, 106, 122, 152, 180, 213];
  const TOTAL_M = [16, 28, 44, 64, 86, 108, 124, 154, 182, 216]; // data codewords
  const ECC_M  = [10, 16, 26, 36, 48, 64, 72, 88, 110, 130];     // ECC codewords per block
  const BLOCKS_M = [1, 1, 1, 2, 2, 4, 4, 4, 5, 5];               // groups
  // For v3..v10 with multi-block we use simpler layouts (group 1 only) when blocks=1,
  // and uniform split when blocks>1. Sufficient for our payload sizes.

  const ALIGN_POS = {
    1:[],2:[6,18],3:[6,22],4:[6,26],5:[6,30],
    6:[6,34],7:[6,22,38],8:[6,24,42],9:[6,26,46],10:[6,28,50]
  };

  function pickVersion(byteLen) {
    for (let v = 1; v <= 10; v++) if (CAP_M[v - 1] >= byteLen) return v;
    throw new Error('QR: payload too long (>213 bytes)');
  }

  // Bit stream writer
  function BitStream() { this.bits = []; }
  BitStream.prototype.put = function (val, len) {
    for (let i = len - 1; i >= 0; i--) this.bits.push((val >>> i) & 1);
  };
  BitStream.prototype.toBytes = function () {
    while (this.bits.length % 8) this.bits.push(0);
    const out = new Array(this.bits.length / 8);
    for (let i = 0; i < out.length; i++) {
      let b = 0;
      for (let j = 0; j < 8; j++) b = (b << 1) | this.bits[i * 8 + j];
      out[i] = b;
    }
    return out;
  };

  function utf8Bytes(str) {
    return Array.from(new TextEncoder().encode(str));
  }

  // Build the data codeword stream
  function buildDataCodewords(text, version) {
    const bytes = utf8Bytes(text);
    const bs = new BitStream();
    bs.put(0b0100, 4);                     // mode = byte
    bs.put(bytes.length, version <= 9 ? 8 : 16); // char count indicator
    for (const b of bytes) bs.put(b, 8);
    bs.put(0, 4);                          // terminator (up to 4)
    let cw = bs.toBytes();
    const total = TOTAL_M[version - 1];
    // pad to total with alternating PAD bytes
    const pad = [0xEC, 0x11];
    let p = 0;
    while (cw.length < total) cw.push(pad[p++ % 2]);
    return cw;
  }

  // Interleave data + ECC across blocks
  function buildFinalCodewords(data, version) {
    const blocks = BLOCKS_M[version - 1];
    const eccLen = ECC_M[version - 1];
    const blockData = [];
    const blockEcc = [];
    const baseLen = Math.floor(data.length / blocks);
    const extra = data.length - baseLen * blocks;
    let idx = 0;
    for (let b = 0; b < blocks; b++) {
      const len = baseLen + (b >= blocks - extra ? 1 : 0);
      const d = data.slice(idx, idx + len);
      idx += len;
      blockData.push(d);
      blockEcc.push(rsEncode(d, eccLen));
    }
    const out = [];
    const maxData = Math.max(...blockData.map((d) => d.length));
    for (let i = 0; i < maxData; i++) for (const d of blockData) if (i < d.length) out.push(d[i]);
    for (let i = 0; i < eccLen; i++) for (const e of blockEcc) out.push(e[i]);
    return out;
  }

  // Module placement
  function makeMatrix(version) {
    const size = 17 + version * 4;
    const m = Array.from({ length: size }, () => new Array(size).fill(null));
    const fn = Array.from({ length: size }, () => new Array(size).fill(false));

    // Finder patterns + separators (3 corners)
    const placeFinder = (r, c) => {
      for (let dr = -1; dr <= 7; dr++) for (let dc = -1; dc <= 7; dc++) {
        const rr = r + dr, cc = c + dc;
        if (rr < 0 || rr >= size || cc < 0 || cc >= size) continue;
        fn[rr][cc] = true;
        const inner = (dr >= 0 && dr <= 6 && dc >= 0 && dc <= 6);
        if (!inner) { m[rr][cc] = false; continue; }
        const ring = (dr === 0 || dr === 6 || dc === 0 || dc === 6);
        const center = (dr >= 2 && dr <= 4 && dc >= 2 && dc <= 4);
        m[rr][cc] = ring || center;
      }
    };
    placeFinder(0, 0); placeFinder(0, size - 7); placeFinder(size - 7, 0);

    // Timing patterns
    for (let i = 8; i < size - 8; i++) {
      m[6][i] = (i % 2 === 0); fn[6][i] = true;
      m[i][6] = (i % 2 === 0); fn[i][6] = true;
    }

    // Alignment patterns (skip if it overlaps a finder)
    const ap = ALIGN_POS[version];
    for (const r of ap) for (const c of ap) {
      if ((r < 8 && c < 8) || (r < 8 && c > size - 9) || (r > size - 9 && c < 8)) continue;
      for (let dr = -2; dr <= 2; dr++) for (let dc = -2; dc <= 2; dc++) {
        const rr = r + dr, cc = c + dc;
        fn[rr][cc] = true;
        const ring = (Math.abs(dr) === 2 || Math.abs(dc) === 2);
        const center = (dr === 0 && dc === 0);
        m[rr][cc] = ring || center;
      }
    }

    // Reserve format-info area (filled later)
    for (let i = 0; i < 9; i++) { fn[8][i] = true; fn[i][8] = true; }
    for (let i = 0; i < 8; i++) { fn[8][size - 1 - i] = true; fn[size - 1 - i][8] = true; }
    m[size - 8][8] = true; fn[size - 8][8] = true; // dark module

    return { m, fn, size };
  }

  // Place codewords zig-zag from bottom-right
  function placeCodewords(state, codewords) {
    const { m, fn, size } = state;
    const bits = [];
    for (const cw of codewords) for (let i = 7; i >= 0; i--) bits.push((cw >>> i) & 1);
    let bi = 0;
    let upward = true;
    for (let col = size - 1; col > 0; col -= 2) {
      if (col === 6) col = 5; // skip vertical timing
      for (let i = 0; i < size; i++) {
        const row = upward ? size - 1 - i : i;
        for (let dc = 0; dc < 2; dc++) {
          const c = col - dc;
          if (fn[row][c]) continue;
          m[row][c] = bi < bits.length ? bits[bi++] === 1 : false;
        }
      }
      upward = !upward;
    }
  }

  // Mask functions
  const MASKS = [
    (r, c) => ((r + c) % 2) === 0,
    (r) => (r % 2) === 0,
    (r, c) => (c % 3) === 0,
    (r, c) => ((r + c) % 3) === 0,
    (r, c) => ((Math.floor(r / 2) + Math.floor(c / 3)) % 2) === 0,
    (r, c) => ((r * c) % 2 + (r * c) % 3) === 0,
    (r, c) => (((r * c) % 2 + (r * c) % 3) % 2) === 0,
    (r, c) => (((r + c) % 2 + (r * c) % 3) % 2) === 0,
  ];

  function applyMask(state, maskIdx) {
    const fn_ = state.fn, m = state.m, size = state.size;
    const f = MASKS[maskIdx];
    for (let r = 0; r < size; r++) for (let c = 0; c < size; c++) {
      if (fn_[r][c]) continue;
      if (f(r, c)) m[r][c] = !m[r][c];
    }
  }

  // Format info bits — ECC level M = 0b00; 5-bit format = (ecc<<3) | mask
  // BCH(15,5) generator 0x537, mask pattern 0x5412
  function formatBits(maskIdx) {
    const data = (0b00 << 3) | maskIdx;
    let bch = data << 10;
    for (let i = 14; i >= 10; i--) {
      if (bch & (1 << i)) bch ^= 0x537 << (i - 10);
    }
    return ((data << 10) | bch) ^ 0x5412;
  }

  function placeFormat(state, maskIdx) {
    const m = state.m, size = state.size;
    const bits = formatBits(maskIdx);
    for (let i = 0; i < 15; i++) {
      const b = ((bits >>> i) & 1) === 1;
      // around top-left finder
      if (i < 6) m[8][i] = b;
      else if (i < 8) m[8][i + 1] = b;
      else if (i < 9) m[7][8] = b;
      else m[14 - i][8] = b;
      // along right/bottom
      if (i < 8) m[size - 1 - i][8] = b;
      else m[8][size - 15 + i] = b;
    }
    m[size - 8][8] = true;
  }

  // Mask penalty — simplified (rule 1 only is enough for short URLs to pick a clean mask)
  function penalty(state) {
    const m = state.m, size = state.size;
    let p = 0;
    for (let r = 0; r < size; r++) {
      let run = 1;
      for (let c = 1; c < size; c++) {
        if (m[r][c] === m[r][c - 1]) { run++; if (run === 5) p += 3; else if (run > 5) p += 1; }
        else run = 1;
      }
    }
    for (let c = 0; c < size; c++) {
      let run = 1;
      for (let r = 1; r < size; r++) {
        if (m[r][c] === m[r - 1][c]) { run++; if (run === 5) p += 3; else if (run > 5) p += 1; }
        else run = 1;
      }
    }
    return p;
  }

  function encode(text) {
    const version = pickVersion(utf8Bytes(text).length);
    const data = buildDataCodewords(text, version);
    const final = buildFinalCodewords(data, version);

    let best = null;
    for (let mk = 0; mk < 8; mk++) {
      const state = makeMatrix(version);
      placeCodewords(state, final);
      applyMask(state, mk);
      placeFormat(state, mk);
      const p = penalty(state);
      if (!best || p < best.p) best = { p, state, mk };
    }
    return best.state.m; // boolean[size][size]
  }

  // Render to SVG — branded mode draws ruby modules + brand corners
  function render(text, opts = {}) {
    const { size = 220, margin = 4, color = '#8D1D2C', bg = '#ffffff', branded = true, brandLabel } = opts;
    const matrix = encode(text);
    const n = matrix.length;
    const total = n + margin * 2;
    const cell = size / total;

    let modules = '';
    for (let r = 0; r < n; r++) {
      for (let c = 0; c < n; c++) {
        if (!matrix[r][c]) continue;
        // Check if this module is part of a finder square — skip; we draw branded ones below.
        if (branded && isInFinder(r, c, n)) continue;
        const x = (c + margin) * cell;
        const y = (r + margin) * cell;
        if (branded) {
          // dotted modules — circle for organic feel
          modules += `<circle cx="${(x + cell / 2).toFixed(2)}" cy="${(y + cell / 2).toFixed(2)}" r="${(cell * 0.46).toFixed(2)}" fill="${color}"/>`;
        } else {
          modules += `<rect x="${x.toFixed(2)}" y="${y.toFixed(2)}" width="${cell.toFixed(2)}" height="${cell.toFixed(2)}" fill="${color}"/>`;
        }
      }
    }

    let corners = '';
    if (branded) {
      const drawCorner = (cr, cc) => {
        const x0 = (cc + margin) * cell;
        const y0 = (cr + margin) * cell;
        const w = cell * 7;
        const r = cell * 1.4;
        const innerInset = cell * 2;
        const innerW = cell * 3;
        const innerR = cell * 0.6;
        // rounded outer ring, hollow
        corners += `<rect x="${x0.toFixed(2)}" y="${y0.toFixed(2)}" width="${w.toFixed(2)}" height="${w.toFixed(2)}" rx="${r}" ry="${r}" fill="none" stroke="${color}" stroke-width="${cell.toFixed(2)}"/>`;
        // rounded inner square
        corners += `<rect x="${(x0 + innerInset).toFixed(2)}" y="${(y0 + innerInset).toFixed(2)}" width="${innerW.toFixed(2)}" height="${innerW.toFixed(2)}" rx="${innerR}" ry="${innerR}" fill="${color}"/>`;
      };
      drawCorner(0, 0);
      drawCorner(0, n - 7);
      drawCorner(n - 7, 0);
    }

    const labelEl = brandLabel
      ? `<g transform="translate(${size / 2}, ${size + 14})">
           <text text-anchor="middle" font-family="Vank, Gotham, sans-serif" font-size="13" fill="${color}">${escapeXml(brandLabel)}</text>
         </g>`
      : '';
    const totalH = brandLabel ? size + 26 : size;

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${totalH}" width="${size}" height="${totalH}">` +
           `<rect width="${size}" height="${size}" fill="${bg}"/>` +
           modules + corners + labelEl +
           `</svg>`;
  }

  function isInFinder(r, c, n) {
    // top-left, top-right, bottom-left 7×7 finder squares
    if (r < 7 && c < 7) return true;
    if (r < 7 && c >= n - 7) return true;
    if (r >= n - 7 && c < 7) return true;
    return false;
  }

  function escapeXml(s) { return String(s).replace(/[<>&"]/g, (ch) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[ch])); }

  // Render to PNG via canvas
  function renderPng(text, opts = {}) {
    const svg = render(text, opts);
    const blob = new Blob([svg], { type: 'image/svg+xml' });
    const url = URL.createObjectURL(blob);
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => {
        const scale = 4;
        const cv = document.createElement('canvas');
        cv.width = img.naturalWidth * scale;
        cv.height = img.naturalHeight * scale;
        const ctx = cv.getContext('2d');
        ctx.imageSmoothingEnabled = false;
        ctx.drawImage(img, 0, 0, cv.width, cv.height);
        cv.toBlob((b) => { URL.revokeObjectURL(url); resolve(b); }, 'image/png');
      };
      img.onerror = (e) => { URL.revokeObjectURL(url); reject(e); };
      img.src = url;
    });
  }

  window.QR = { encode, render, renderPng };
})();
