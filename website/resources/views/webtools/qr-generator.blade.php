<style>
  .qrg-stage{background:#fff;border:1px solid var(--line-2);border-radius:14px;padding:20px;margin-top:16px;
    display:flex;justify-content:center;align-items:center;min-height:200px;overflow:auto}
  .qrg-stage canvas{display:block;max-width:100%;height:auto;image-rendering:pixelated}
  html[data-theme="light"] .qrg-stage{border-color:var(--line)}
  .qrg-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
  .qrg-meta[hidden]{display:none}
  .qrg-chip{display:inline-flex;align-items:center;gap:7px;padding:5px 11px;border:1px solid var(--line);
    border-radius:999px;background:var(--surface-2);font-size:12.5px;color:var(--muted)}
  .qrg-chip i{font-style:normal;color:var(--dim)}
  .qrg-chip b{color:var(--cyan);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:600}
  .qrg-note{margin-top:12px;font-size:12.5px;color:var(--dim);line-height:2;
    border-inline-start:2px solid var(--line-2);padding-inline-start:12px}
</style>

<div class="wt-pane">
  <label for="qrg-in">{{ __('ui.wt_qr_content') }}</label>
  <textarea id="qrg-in" class="wt-ta" dir="ltr" rows="3" spellcheck="false"
            placeholder="{{ __('ui.wt_qr_ph') }}">https://servernet.cloud</textarea>
</div>

<div class="wt-fields">
  <label class="wt-range">{{ __('ui.wt_qr_ec') }}
    <select id="qrg-ec" class="wt-select">
      <option value="L">{{ __('ui.wt_qr_ec_l') }}</option>
      <option value="M" selected>{{ __('ui.wt_qr_ec_m') }}</option>
      <option value="Q">{{ __('ui.wt_qr_ec_q') }}</option>
      <option value="H">{{ __('ui.wt_qr_ec_h') }}</option>
    </select>
  </label>
  <label class="wt-range">{{ __('ui.wt_qr_size') }}: <b id="qrg-px-l">8</b>
    <input type="range" id="qrg-px" min="2" max="20" step="1" value="8">
  </label>
  <label class="wt-range">{{ __('ui.wt_qr_mask') }}
    <select id="qrg-mask" class="wt-select">
      <option value="">{{ __('ui.wt_qr_auto') }}</option>
      <option value="0">0</option><option value="1">1</option>
      <option value="2">2</option><option value="3">3</option>
      <option value="4">4</option><option value="5">5</option>
      <option value="6">6</option><option value="7">7</option>
    </select>
  </label>
</div>

<div class="qrg-stage">
  <canvas id="qrg-canvas" width="10" height="10"></canvas>
</div>

<p class="wt-status" id="qrg-status"></p>

<div class="qrg-meta" id="qrg-meta" hidden>
  <span class="qrg-chip"><i>{{ __('ui.wt_qr_ver') }}</i><b id="qrg-m-ver" dir="ltr">&mdash;</b></span>
  <span class="qrg-chip"><i>{{ __('ui.wt_qr_modules') }}</i><b id="qrg-m-mod" dir="ltr">&mdash;</b></span>
  <span class="qrg-chip"><i>{{ __('ui.wt_qr_maskl') }}</i><b id="qrg-m-mask" dir="ltr">&mdash;</b></span>
  <span class="qrg-chip"><i>{{ __('ui.wt_qr_fmt') }}</i><b id="qrg-m-fmt" dir="ltr">&mdash;</b></span>
  <span class="qrg-chip"><i>{{ __('ui.wt_qr_bytes') }}</i><b id="qrg-m-cap" dir="ltr">&mdash;</b></span>
</div>

<div class="wt-bar">
  <button class="btn btn-primary" id="qrg-dl">{{ __('ui.wt_qr_download') }}</button>
</div>

<p class="qrg-note">{{ __('ui.wt_qr_note') }}</p>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var L = {
    empty:   @json(__('ui.wt_qr_empty')),
    toolong: @json(__('ui.wt_qr_toolong')),
    ok:      @json(__('ui.wt_qr_ok'))
  };

  /* ================= ISO/IEC 18004 tables, versions 1-10 =================
     EC[version][level] = [ecCodewordsPerBlock, group1Blocks, group1DataCW, group2Blocks, group2DataCW] */
  var EC = {
    1:  { L:[7,1,19,0,0],    M:[10,1,16,0,0],  Q:[13,1,13,0,0],  H:[17,1,9,0,0]   },
    2:  { L:[10,1,34,0,0],   M:[16,1,28,0,0],  Q:[22,1,22,0,0],  H:[28,1,16,0,0]  },
    3:  { L:[15,1,55,0,0],   M:[26,1,44,0,0],  Q:[18,2,17,0,0],  H:[22,2,13,0,0]  },
    4:  { L:[20,1,80,0,0],   M:[18,2,32,0,0],  Q:[26,2,24,0,0],  H:[16,4,9,0,0]   },
    5:  { L:[26,1,108,0,0],  M:[24,2,43,0,0],  Q:[18,2,15,2,16], H:[22,2,11,2,12] },
    6:  { L:[18,2,68,0,0],   M:[16,4,27,0,0],  Q:[24,4,19,0,0],  H:[28,4,15,0,0]  },
    7:  { L:[20,2,78,0,0],   M:[18,4,31,0,0],  Q:[18,2,14,4,15], H:[26,4,13,1,14] },
    8:  { L:[24,2,97,0,0],   M:[22,2,38,2,39], Q:[22,4,18,2,19], H:[26,4,14,2,15] },
    9:  { L:[30,2,116,0,0],  M:[22,3,36,2,37], Q:[20,4,16,4,17], H:[24,4,12,4,13] },
    10: { L:[18,2,68,2,69],  M:[26,4,43,1,44], Q:[24,6,19,2,20], H:[28,6,15,2,16] }
  };
  /* alignment pattern centre coordinates per version */
  var ALIGN = { 1:[], 2:[6,18], 3:[6,22], 4:[6,26], 5:[6,30], 6:[6,34],
                7:[6,22,38], 8:[6,24,42], 9:[6,26,46], 10:[6,28,50] };
  /* format-information EC level indicator: L=01 M=00 Q=11 H=10 */
  var ECBITS = { L:1, M:0, Q:3, H:2 };
  var MAXVER = 10, QUIET = 4, MAXPX = 2000;

  /* ================= GF(256), primitive polynomial 0x11D ================= */
  var EXP = new Array(512), LOG = new Array(256);
  (function () {
    var x = 1;
    for (var i = 0; i < 255; i++) { EXP[i] = x; LOG[x] = i; x = x << 1; if (x & 0x100) x ^= 0x11D; }
    for (var j = 255; j < 512; j++) EXP[j] = EXP[j - 255];
  })();
  function gmul(a, b) { if (a === 0 || b === 0) return 0; return EXP[LOG[a] + LOG[b]]; }

  /* generator polynomial: product of (x - alpha^i), i = 0..n-1 */
  function genPoly(n) {
    var g = [1];
    for (var i = 0; i < n; i++) {
      var ng = [];
      for (var k = 0; k <= g.length; k++) ng.push(0);
      for (var j = 0; j < g.length; j++) { ng[j] ^= g[j]; ng[j + 1] ^= gmul(g[j], EXP[i]); }
      g = ng;
    }
    return g;
  }
  /* Reed-Solomon remainder = the EC codewords */
  function rsEncode(data, n) {
    var g = genPoly(n), res = [];
    for (var i = 0; i < n; i++) res.push(0);
    for (var d = 0; d < data.length; d++) {
      var factor = data[d] ^ res[0];
      res.shift(); res.push(0);
      for (var j = 0; j < n; j++) res[j] ^= gmul(g[j + 1], factor);
    }
    return res;
  }

  /* ================= UTF-8 bytes (byte mode payload) ================= */
  function utf8(str) {
    var out = [];
    for (var i = 0; i < str.length; i++) {
      var c = str.charCodeAt(i);
      if (c < 0x80) out.push(c);
      else if (c < 0x800) out.push(0xC0 | (c >> 6), 0x80 | (c & 63));
      else if (c >= 0xD800 && c <= 0xDBFF && i + 1 < str.length) {
        var c2 = str.charCodeAt(i + 1);
        var cp = 0x10000 + ((c - 0xD800) << 10) + (c2 - 0xDC00);
        i++;
        out.push(0xF0 | (cp >> 18), 0x80 | ((cp >> 12) & 63), 0x80 | ((cp >> 6) & 63), 0x80 | (cp & 63));
      } else out.push(0xE0 | (c >> 12), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63));
    }
    return out;
  }

  /* character count indicator: 8 bits for versions 1-9, 16 bits for 10-26 */
  function cci(v) { return v < 10 ? 8 : 16; }
  function dataCW(v, lv) { var t = EC[v][lv]; return t[1] * t[2] + t[3] * t[4]; }
  function maxBytes(v, lv) { return Math.floor((dataCW(v, lv) * 8 - 4 - cci(v)) / 8); }
  function pickVersion(n, lv) {
    for (var v = 1; v <= MAXVER; v++) if (4 + cci(v) + n * 8 <= dataCW(v, lv) * 8) return v;
    return 0;
  }

  /* ================= data codewords: encode, pad, block, interleave ================= */
  function makeCodewords(bytes, v, lv) {
    var t = EC[v][lv], cap = dataCW(v, lv) * 8, bits = [];
    function push(val, len) { for (var i = len - 1; i >= 0; i--) bits.push((val >> i) & 1); }

    push(4, 4);                       // byte mode indicator 0100
    push(bytes.length, cci(v));
    for (var i = 0; i < bytes.length; i++) push(bytes[i], 8);
    push(0, Math.min(4, cap - bits.length));            // terminator, truncated near capacity
    while (bits.length % 8 !== 0) bits.push(0);          // pad to codeword boundary
    var pad = [0xEC, 0x11], pi = 0;
    while (bits.length < cap) { push(pad[pi], 8); pi ^= 1; }

    var cw = [];
    for (var b = 0; b < bits.length; b += 8) {
      var byteVal = 0;
      for (var j = 0; j < 8; j++) byteVal = (byteVal << 1) | bits[b + j];
      cw.push(byteVal);
    }

    var blocks = [], ecb = [], idx = 0, g;
    for (g = 0; g < t[1]; g++) { var d1 = cw.slice(idx, idx + t[2]); idx += t[2]; blocks.push(d1); ecb.push(rsEncode(d1, t[0])); }
    for (g = 0; g < t[3]; g++) { var d2 = cw.slice(idx, idx + t[4]); idx += t[4]; blocks.push(d2); ecb.push(rsEncode(d2, t[0])); }

    var maxLen = Math.max(t[2], t[4]), out = [], k, bb;
    for (k = 0; k < maxLen; k++) for (bb = 0; bb < blocks.length; bb++) if (k < blocks[bb].length) out.push(blocks[bb][k]);
    for (k = 0; k < t[0]; k++) for (bb = 0; bb < ecb.length; bb++) out.push(ecb[bb][k]);
    return out;
  }

  /* ================= BCH: format (15,5) and version (18,6) ================= */
  function fmtBits(lv, mask) {
    var d = (ECBITS[lv] << 3) | mask, r = d << 10;
    for (var i = 14; i >= 10; i--) if (r & (1 << i)) r ^= 0x537 << (i - 10);
    return ((d << 10) | r) ^ 0x5412;
  }
  function verBits(v) {
    var rem = v;
    for (var i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
    return (v << 12) | rem;
  }

  /* ================= the 8 mask conditions ================= */
  function maskFn(k, r, c) {
    if (k === 0) return (r + c) % 2 === 0;
    if (k === 1) return r % 2 === 0;
    if (k === 2) return c % 3 === 0;
    if (k === 3) return (r + c) % 3 === 0;
    if (k === 4) return (Math.floor(r / 2) + Math.floor(c / 3)) % 2 === 0;
    if (k === 5) return ((r * c) % 2) + ((r * c) % 3) === 0;
    if (k === 6) return (((r * c) % 2) + ((r * c) % 3)) % 2 === 0;
    return (((r + c) % 2) + ((r * c) % 3)) % 2 === 0;
  }

  /* format information occupies 15 modules twice, plus the always-dark module */
  function writeFormat(m, fn, size, fmt, mark) {
    function s(r, c, val) { m[r][c] = val; if (mark) fn[r][c] = 1; }
    var i;
    for (i = 0; i <= 5; i++) s(i, 8, (fmt >> i) & 1);
    s(7, 8, (fmt >> 6) & 1); s(8, 8, (fmt >> 7) & 1); s(8, 7, (fmt >> 8) & 1);
    for (i = 9; i < 15; i++) s(8, 14 - i, (fmt >> i) & 1);
    for (i = 0; i < 8; i++) s(8, size - 1 - i, (fmt >> i) & 1);
    for (i = 8; i < 15; i++) s(size - 15 + i, 8, (fmt >> i) & 1);
    s(size - 8, 8, 1);                                   // dark module at (4V+9, 8)
  }

  /* function patterns: timing, finders + separators, alignment, format, version */
  function buildBase(v) {
    var size = v * 4 + 17, m = [], fn = [], r, c, i, j;
    for (r = 0; r < size; r++) {
      m.push([]); fn.push([]);
      for (c = 0; c < size; c++) { m[r].push(0); fn[r].push(0); }
    }
    function set(rr, cc, val) { if (rr < 0 || cc < 0 || rr >= size || cc >= size) return; m[rr][cc] = val; fn[rr][cc] = 1; }

    for (i = 0; i < size; i++) { set(6, i, i % 2 === 0 ? 1 : 0); set(i, 6, i % 2 === 0 ? 1 : 0); }

    var centres = [[3, 3], [3, size - 4], [size - 4, 3]];
    for (i = 0; i < centres.length; i++) {
      for (var dy = -4; dy <= 4; dy++) for (var dx = -4; dx <= 4; dx++) {
        var dist = Math.max(Math.abs(dx), Math.abs(dy));
        set(centres[i][0] + dy, centres[i][1] + dx, (dist !== 2 && dist !== 4) ? 1 : 0);
      }
    }

    var al = ALIGN[v];
    for (i = 0; i < al.length; i++) for (j = 0; j < al.length; j++) {
      if ((i === 0 && j === 0) || (i === 0 && j === al.length - 1) || (i === al.length - 1 && j === 0)) continue;
      for (var ay = -2; ay <= 2; ay++) for (var ax = -2; ax <= 2; ax++)
        set(al[i] + ay, al[j] + ax, Math.max(Math.abs(ax), Math.abs(ay)) !== 1 ? 1 : 0);
    }

    writeFormat(m, fn, size, 0, true);                   // reserve the format areas

    if (v >= 7) {
      var vb = verBits(v);
      for (i = 0; i < 18; i++) {
        var bit = (vb >> i) & 1, a = size - 11 + i % 3, b = Math.floor(i / 3);
        set(b, a, bit); set(a, b, bit);
      }
    }
    return { m: m, fn: fn, size: size };
  }

  /* zig-zag placement: two-module columns right to left, alternating up/down, skipping column 6 */
  function placeData(base, bits) {
    var m = base.m, fn = base.fn, size = base.size, bi = 0, up = true;
    for (var col = size - 1; col > 0; col -= 2) {
      if (col === 6) col--;
      for (var i = 0; i < size; i++) {
        var r = up ? (size - 1 - i) : i;
        for (var k = 0; k < 2; k++) {
          var c = col - k;
          if (!fn[r][c]) m[r][c] = bi < bits.length ? bits[bi++] : 0;   // remainder bits stay light
        }
      }
      up = !up;
    }
  }

  /* standard penalty rules N1=3 N2=3 N3=40 N4=10 */
  function penalty(m, size) {
    var p = 0, dark = 0, r, c, run;
    for (r = 0; r < size; r++) {
      run = 1;
      for (c = 1; c < size; c++) {
        if (m[r][c] === m[r][c - 1]) run++;
        else { if (run >= 5) p += 3 + (run - 5); run = 1; }
      }
      if (run >= 5) p += 3 + (run - 5);
    }
    for (c = 0; c < size; c++) {
      run = 1;
      for (r = 1; r < size; r++) {
        if (m[r][c] === m[r - 1][c]) run++;
        else { if (run >= 5) p += 3 + (run - 5); run = 1; }
      }
      if (run >= 5) p += 3 + (run - 5);
    }
    for (r = 0; r < size - 1; r++) for (c = 0; c < size - 1; c++) {
      var q = m[r][c];
      if (q === m[r][c + 1] && q === m[r + 1][c] && q === m[r + 1][c + 1]) p += 3;
    }
    /* 1:1:3:1:1 ratio with four light modules on either side -> 10111010000 / 00001011101 */
    var w;
    for (r = 0; r < size; r++) {
      w = 0;
      for (c = 0; c < size; c++) { w = ((w << 1) | m[r][c]) & 0x7FF; if (c >= 10 && (w === 0x5D0 || w === 0x05D)) p += 40; }
    }
    for (c = 0; c < size; c++) {
      w = 0;
      for (r = 0; r < size; r++) { w = ((w << 1) | m[r][c]) & 0x7FF; if (r >= 10 && (w === 0x5D0 || w === 0x05D)) p += 40; }
    }
    for (r = 0; r < size; r++) for (c = 0; c < size; c++) if (m[r][c]) dark++;
    p += Math.floor(Math.abs(dark * 100 / (size * size) - 50) / 5) * 10;
    return p;
  }

  function encode(text, lv, forced) {
    var bytes = utf8(text), v = pickVersion(bytes.length, lv);
    if (!v) return null;
    var cw = makeCodewords(bytes, v, lv), bits = [];
    for (var i = 0; i < cw.length; i++) for (var j = 7; j >= 0; j--) bits.push((cw[i] >> j) & 1);

    var base = buildBase(v), size = base.size;
    placeData(base, bits);

    var best = null;
    for (var k = 0; k < 8; k++) {
      if (forced !== null && forced !== k) continue;
      var mm = [], r, c;
      for (r = 0; r < size; r++) mm.push(base.m[r].slice());
      for (r = 0; r < size; r++) for (c = 0; c < size; c++)
        if (!base.fn[r][c] && maskFn(k, r, c)) mm[r][c] ^= 1;
      writeFormat(mm, base.fn, size, fmtBits(lv, k), false);
      var sc = penalty(mm, size);
      if (best === null || sc < best.score) best = { score: sc, mask: k, m: mm };
    }
    return { version: v, size: size, mask: best.mask, m: best.m, level: lv,
             format: fmtBits(lv, best.mask), bytes: bytes.length, cap: maxBytes(v, lv) };
  }

  /* ================= render ================= */
  var canvas = $('qrg-canvas'), status = $('qrg-status'), meta = $('qrg-meta'), ready = false;

  function clear(msg, isErr) {
    ready = false;
    meta.hidden = true;
    canvas.width = 10; canvas.height = 10;
    canvas.getContext('2d').clearRect(0, 0, 10, 10);
    ['version', 'size', 'mask', 'format', 'ec', 'bytes'].forEach(function (a) { canvas.removeAttribute('data-' + a); });
    status.className = isErr ? 'wt-status err' : 'wt-status';
    status.textContent = msg || '';
  }

  function draw(res, px) {
    var total = res.size + QUIET * 2;
    if (total * px > MAXPX) px = Math.max(1, Math.floor(MAXPX / total));
    var dim = total * px;
    canvas.width = dim; canvas.height = dim;
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, dim, dim);
    ctx.fillStyle = '#000';
    for (var r = 0; r < res.size; r++) for (var c = 0; c < res.size; c++)
      if (res.m[r][c]) ctx.fillRect((c + QUIET) * px, (r + QUIET) * px, px, px);

    var hex = '0x' + ('0000' + res.format.toString(16).toUpperCase()).slice(-4);
    canvas.setAttribute('data-version', res.version);
    canvas.setAttribute('data-size', res.size);
    canvas.setAttribute('data-mask', res.mask);
    canvas.setAttribute('data-format', hex);
    canvas.setAttribute('data-ec', res.level);
    canvas.setAttribute('data-bytes', res.bytes);

    $('qrg-m-ver').textContent  = res.version;
    $('qrg-m-mod').textContent  = res.size + ' x ' + res.size;
    $('qrg-m-mask').textContent = res.mask;
    $('qrg-m-fmt').textContent  = hex;
    $('qrg-m-cap').textContent  = res.bytes + ' / ' + res.cap;
    meta.hidden = false;
    status.className = 'wt-status';
    status.textContent = L.ok;
    ready = true;
  }

  function run() {
    $('qrg-px-l').textContent = $('qrg-px').value;
    var text = $('qrg-in').value;
    if (!text) { clear(L.empty, false); return; }
    var lv = $('qrg-ec').value;
    var mv = $('qrg-mask').value;
    var forced = mv === '' ? null : parseInt(mv, 10);
    var res = encode(text, lv, forced);
    if (!res) { clear(L.toolong, true); return; }
    draw(res, parseInt($('qrg-px').value, 10));
  }

  ['qrg-in', 'qrg-ec', 'qrg-px', 'qrg-mask'].forEach(function (id) {
    $(id).addEventListener('input', run);
    $(id).addEventListener('change', run);
  });

  $('qrg-dl').addEventListener('click', function () {
    if (!ready) return;
    var a = document.createElement('a');
    a.download = 'qr-v' + canvas.getAttribute('data-version') + '-' + canvas.getAttribute('data-ec') + '.png';
    a.href = canvas.toDataURL('image/png');
    document.body.appendChild(a); a.click(); a.remove();
  });

  run();
})();
</script>
