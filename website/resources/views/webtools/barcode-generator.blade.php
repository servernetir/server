<style>
  .bc-stage{background:#fff;border:1px solid var(--line-2);border-radius:14px;padding:18px 14px;margin-top:16px;
    overflow-x:auto;display:block;text-align:center;min-height:130px}
  html[data-theme="light"] .bc-stage{border-color:var(--line)}
  .bc-stage canvas{display:inline-block;max-width:none;height:auto;image-rendering:pixelated;vertical-align:middle}
  .bc-stage.is-empty{display:flex;align-items:center;justify-content:center}
  .bc-stage.is-empty canvas{display:none}
  .bc-empty{display:none;color:#6b7280;font-size:13px}
  .bc-stage.is-empty .bc-empty{display:block}
  .bc-hint{margin-top:10px;display:block}
  .bc-sym{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;color:var(--muted);
    word-break:break-all;line-height:1.7}
</style>

<div class="wt-pane">
  <label for="bc-in">{{ __('ui.wt_bc_data') }}</label>
  <input type="text" id="bc-in" class="wt-input-lg" dir="ltr" spellcheck="false" autocomplete="off"
         placeholder="{{ __('ui.wt_bc_ph') }}" value="ServerNet-2026">
</div>

<div class="wt-fields">
  <label class="wt-range">{{ __('ui.wt_bc_symbology') }}
    <select id="bc-sym" class="wt-select">
      <option value="code128">{{ __('ui.wt_bc_c128') }}</option>
      <option value="ean13">{{ __('ui.wt_bc_ean') }}</option>
    </select>
  </label>
  <label class="wt-range">{{ __('ui.wt_bc_module') }}: <b id="bc-mw-l">2</b>
    <input type="range" id="bc-mw" min="1" max="6" step="1" value="2">
  </label>
  <label class="wt-range">{{ __('ui.wt_bc_height') }}: <b id="bc-h-l">80</b>
    <input type="range" id="bc-h" min="30" max="200" step="5" value="80">
  </label>
  <label class="wt-chk"><input type="checkbox" id="bc-txt" checked> {{ __('ui.wt_bc_showtext') }}</label>
</div>

<div class="bc-stage is-empty" id="bc-stage">
  <span class="bc-empty">{{ __('ui.wt_bc_empty') }}</span>
  <canvas id="bc-canvas" width="10" height="10"></canvas>
</div>
<span class="wt-status bc-hint" id="bc-status"></span>

<div class="wt-out-box" id="bc-out"></div>

<div class="wt-bar">
  <button class="btn btn-primary" id="bc-dl"><svg class="icon"><use href="#i-arrow"/></svg>{{ __('ui.wt_bc_download') }}</button>
  <button class="btn btn-glass" id="bc-cp"><svg class="icon"><use href="#i-list"/></svg>{{ __('ui.wt_bc_copy') }}</button>
</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var L = {
    errAscii: @json(__('ui.wt_bc_err_ascii')),
    errLong:  @json(__('ui.wt_bc_err_long')),
    errEan:   @json(__('ui.wt_bc_err_ean')),
    errChk:   @json(__('ui.wt_bc_err_eanchk')),
    lSym:     @json(__('ui.wt_bc_l_sym')),
    lSets:    @json(__('ui.wt_bc_l_sets')),
    lCheck:   @json(__('ui.wt_bc_l_check')),
    lEanChk:  @json(__('ui.wt_bc_l_eancheck')),
    lMods:    @json(__('ui.wt_bc_l_modules')),
    lSize:    @json(__('ui.wt_bc_l_size')),
    lQuiet:   @json(__('ui.wt_bc_l_quiet')),
    lValues:  @json(__('ui.wt_bc_l_values')),
    okC128:   @json(__('ui.wt_bc_ok_c128')),
    okEan:    @json(__('ui.wt_bc_ok_ean')),
    nC128:    @json(__('ui.wt_bc_c128')),
    nEan:     @json(__('ui.wt_bc_ean'))
  };

  /* Code 128 element-width table, index = symbol value 0..106.
     Each entry lists bar/space widths starting with a bar; values 0..105 total 11 modules,
     the stop pattern (106) totals 13. */
  var P = ("212222 222122 222221 121223 121322 131222 122213 122312 132212 221213 221312 231212 112232 122132 " +
    "122231 113222 123122 123221 223211 221132 221231 213212 223112 312131 311222 321122 321221 312212 322112 " +
    "322211 212123 212321 232121 111323 131123 131321 112313 132113 132311 211313 231113 231311 112133 112331 " +
    "132131 113123 113321 133121 313121 211331 231131 213113 213311 213131 311123 311321 331121 312113 312311 " +
    "332111 314111 221411 431111 111224 111422 121124 121421 141122 141221 112214 112412 122114 122411 142112 " +
    "142211 241211 221114 413111 241112 134111 111242 121142 121241 114212 124112 124211 411212 421112 421211 " +
    "212141 214121 412121 111143 111341 131141 114113 114311 411113 411311 113141 114131 311141 411131 211412 " +
    "211214 211232 2331112").split(" ");

  /* EAN-13 digit encodings, left-odd (L), left-even (G) and right (R), plus first-digit parity map */
  var EAN_L = ["0001101","0011001","0010011","0111101","0100011","0110001","0101111","0111011","0110111","0001011"];
  var EAN_G = ["0100111","0110011","0011011","0100001","0011101","0111001","0000101","0010001","0001001","0010111"];
  var EAN_R = ["1110010","1100110","1101100","1000010","1011100","1001110","1010000","1000100","1001000","1110100"];
  var EAN_PARITY = ["LLLLLL","LLGLGG","LLGGLG","LLGGGL","LGLLGG","LGGLLG","LGGGLL","LGLGLG","LGLGGL","LGGLGL"];

  var MAX_LEN = 120;      /* data characters accepted for Code 128 */
  var MAX_PX  = 3400;     /* hard cap on canvas width */
  var canvas = $('bc-canvas'), stage = $('bc-stage'), status = $('bc-status'), out = $('bc-out');
  var state = { ready: false, values: '' };

  /* ---------------- Code 128 encoder with automatic A/B/C switching ---------------- */
  function encode128(text) {
    var n = text.length;
    var cc = function (i) { return text.charCodeAt(i); };
    var isDig = function (i) { return i >= 0 && i < n && cc(i) >= 48 && cc(i) <= 57; };
    var digitsFrom = function (i) { var c = 0; while (isDig(i + c)) c++; return c; };
    /* pick A or B by looking ahead for the first character only one of them can carry */
    var chooseAB = function (i) {
      for (var k = i; k < n; k++) { var v = cc(k); if (v < 32) return 'A'; if (v > 95) return 'B'; }
      return 'B';
    };

    /* start in C when the data opens with 4+ digits, or is exactly an even all-digit string */
    var d0 = digitsFrom(0), mode;
    if (n >= 2 && d0 >= 2 && (d0 >= 4 || (d0 === n && d0 % 2 === 0))) mode = 'C';
    else mode = chooseAB(0);

    var startVal = mode === 'A' ? 103 : (mode === 'B' ? 104 : 105);
    var codes = [], setSeq = [mode], i = 0;

    while (i < n) {
      if (mode === 'C') {
        if (isDig(i) && isDig(i + 1)) { codes.push((cc(i) - 48) * 10 + (cc(i + 1) - 48)); i += 2; }
        else {
          var nm = chooseAB(i);
          codes.push(nm === 'A' ? 101 : 100);          /* from C: 101 = Code A, 100 = Code B */
          mode = nm; setSeq.push(nm);
        }
      } else {
        var dd = digitsFrom(i);
        /* an even run of 4+ digits is cheaper in C; an odd run emits one digit first, then realigns */
        if (dd >= 4 && dd % 2 === 0) { codes.push(99); mode = 'C'; setSeq.push('C'); continue; }
        var v = cc(i);
        if (mode === 'B') {
          if (v >= 32 && v <= 127) { codes.push(v - 32); i++; }
          else if (i + 1 < n && cc(i + 1) < 32) { codes.push(101); mode = 'A'; setSeq.push('A'); }
          else { codes.push(98); codes.push(v + 64); i++; }   /* SHIFT one control char into A */
        } else {
          if (v >= 32 && v <= 95) { codes.push(v - 32); i++; }
          else if (v < 32) { codes.push(v + 64); i++; }
          else if (i + 1 < n && cc(i + 1) > 95) { codes.push(100); mode = 'B'; setSeq.push('B'); }
          else { codes.push(98); codes.push(v - 32); i++; }   /* SHIFT one lowercase char into B */
        }
      }
    }

    /* modulo-103: start value plus each data value weighted by its 1-based position */
    var sum = startVal;
    for (var p = 0; p < codes.length; p++) sum += codes[p] * (p + 1);
    var check = sum % 103;

    return { symbols: [startVal].concat(codes, [check, 106]), check: check, sets: setSeq.join(' → ') };
  }

  function eanCheck(d12) {
    var s = 0;
    for (var i = 0; i < 12; i++) { var d = d12.charCodeAt(i) - 48; s += (i % 2 === 0) ? d : d * 3; }
    return (10 - (s % 10)) % 10;
  }

  /* ---------------- output helpers ---------------- */
  function clearStage() {
    state.ready = false; state.values = '';
    canvas.width = 10; canvas.height = 10;
    stage.className = 'bc-stage is-empty';
    out.textContent = '';
    ['data-symbols','data-ean','data-check','data-modules'].forEach(function (a) { canvas.removeAttribute(a); });
  }

  function err(msg) {
    status.className = 'wt-status err bc-hint';
    status.textContent = msg;
    clearStage();
  }

  function row(label, value) {
    var d = document.createElement('div'); d.className = 'wt-out-row';
    var s = document.createElement('span'); s.textContent = label;
    var b = document.createElement('b'); b.setAttribute('dir', 'ltr'); b.textContent = value;
    d.appendChild(s); d.appendChild(b); out.appendChild(d);
  }

  /* ---------------- Code 128 renderer: every bar the same height ---------------- */
  function drawBars(bits, mw, barH, text, meta) {
    var quiet = 10;                                   /* Code 128 requires >= 10 modules of quiet zone */
    var modW = mw, total = bits.length + quiet * 2;
    if (total * modW > MAX_PX) modW = Math.max(1, Math.floor(MAX_PX / total));
    var w = total * modW, pad = 12;
    var textH = text ? Math.round(Math.max(15, modW * 8)) : 0;
    var h = pad * 2 + barH + textH;

    canvas.width = w; canvas.height = h;
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, w, h);
    ctx.fillStyle = '#000000';
    var x = quiet * modW;
    for (var k = 0; k < bits.length; k++) { if (bits[k]) ctx.fillRect(x, pad, modW, barH); x += modW; }

    if (text) {
      ctx.font = Math.round(textH * 0.78) + 'px ui-monospace, SFMono-Regular, Menlo, monospace';
      ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
      ctx.fillText(text, w / 2, pad + barH + textH * 0.82);
    }
    meta.modW = modW; meta.quiet = quiet + ' + ' + quiet;
    finish(meta);
  }

  /* ---------------- EAN-13 renderer: guard bars run down past the digits ---------------- */
  function drawEAN(bits, guard, mw, barH, textFull, meta) {
    var qL = 11, qR = 7;                              /* asymmetric quiet zones mandated by EAN-13 */
    var modW = mw, total = bits.length + qL + qR;
    if (total * modW > MAX_PX) modW = Math.max(1, Math.floor(MAX_PX / total));
    var w = total * modW, pad = 12;
    var textH = textFull ? Math.round(Math.max(15, modW * 9)) : 0;
    var guardExtra = textFull ? Math.round(textH * 0.55) : 0;
    var h = pad * 2 + barH + textH;

    canvas.width = w; canvas.height = h;
    var ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, w, h);
    ctx.fillStyle = '#000000';
    var x = qL * modW;
    for (var k = 0; k < bits.length; k++) {
      if (bits[k]) ctx.fillRect(x, pad, modW, barH + (guard[k] ? guardExtra : 0));
      x += modW;
    }

    if (textFull) {
      var fs = Math.round(Math.max(11, modW * 9));
      ctx.font = fs + 'px ui-monospace, SFMono-Regular, Menlo, monospace';
      ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
      var ty = pad + barH + guardExtra * 0.15 + fs * 0.9;
      var digW = 7 * modW;
      ctx.fillText(textFull.charAt(0), (qL * modW) / 2, ty);               /* system digit sits in the quiet zone */
      var lStart = (qL + 3) * modW;                                        /* after the 3-module start guard */
      for (var a = 0; a < 6; a++) ctx.fillText(textFull.charAt(1 + a), lStart + digW * a + digW / 2, ty);
      var rStart = (qL + 50) * modW;                                       /* 11 quiet + 3 guard + 42 data + 5 centre */
      for (var b = 0; b < 6; b++) ctx.fillText(textFull.charAt(7 + b), rStart + digW * b + digW / 2, ty);
    }
    meta.modW = modW; meta.quiet = qL + ' + ' + qR;
    finish(meta);
  }

  function finish(meta) {
    canvas.setAttribute('data-modules', meta.modules);
    canvas.setAttribute('data-check', meta.check);
    stage.className = 'bc-stage';
    status.className = 'wt-status ok bc-hint';
    out.textContent = '';

    if (meta.kind === 'code128') {
      canvas.setAttribute('data-symbols', meta.symbols.join(','));
      canvas.removeAttribute('data-ean');
      state.values = meta.symbols.join(' ');
      status.textContent = L.okC128;
      row(L.lSym, L.nC128);
      row(L.lSets, meta.sets);
      row(L.lCheck, String(meta.check));
    } else {
      canvas.setAttribute('data-ean', meta.full);
      canvas.removeAttribute('data-symbols');
      state.values = meta.full;
      status.textContent = L.okEan;
      row(L.lSym, L.nEan + '  ' + meta.full);
      row(L.lEanChk, String(meta.check));
    }

    row(L.lMods, String(meta.modules));
    row(L.lQuiet, meta.quiet);
    row(L.lSize, canvas.width + ' × ' + canvas.height + ' px  (' + meta.modW + ' px/module)');

    if (meta.kind === 'code128') {
      var d = document.createElement('div'); d.className = 'wt-out-row';
      var s = document.createElement('span'); s.textContent = L.lValues;
      var b = document.createElement('b'); b.className = 'bc-sym'; b.setAttribute('dir', 'ltr');
      b.textContent = meta.symbols.join(' ');
      d.appendChild(s); d.appendChild(b); out.appendChild(d);
    }
    state.ready = true;
  }

  /* ---------------- pipelines ---------------- */
  function renderCode128(text, mw, barH, showText) {
    if (text.length > MAX_LEN) { err(L.errLong + ' (' + text.length + '/' + MAX_LEN + ')'); return; }
    for (var i = 0; i < text.length; i++) {
      if (text.charCodeAt(i) > 127) { err(L.errAscii + ' — "' + text.charAt(i) + '"'); return; }
    }
    var enc = encode128(text);
    var bits = [];
    for (var s = 0; s < enc.symbols.length; s++) {
      var pat = P[enc.symbols[s]], dark = 1;
      for (var e = 0; e < pat.length; e++) {
        var wd = pat.charCodeAt(e) - 48;
        for (var m = 0; m < wd; m++) bits.push(dark);
        dark ^= 1;                                     /* patterns always alternate bar, space, bar... */
      }
    }
    drawBars(bits, mw, barH, showText ? text : null,
      { kind: 'code128', symbols: enc.symbols, check: enc.check, sets: enc.sets, modules: bits.length });
  }

  function renderEAN(raw, mw, barH, showText) {
    var digits = raw.replace(/[^0-9]/g, '');
    if (!/^[0-9]{12,13}$/.test(digits)) { err(L.errEan); return; }
    var d12 = digits.slice(0, 12);
    var chk = eanCheck(d12);
    if (digits.length === 13 && (digits.charCodeAt(12) - 48) !== chk) {
      err(L.errChk + ' — ' + digits.charAt(12) + ' ≠ ' + chk); return;
    }
    var full = d12 + chk;
    var parity = EAN_PARITY[full.charCodeAt(0) - 48];

    var segs = [{ bits: '101', g: 1 }];
    for (var i = 0; i < 6; i++) {
      var ld = full.charCodeAt(1 + i) - 48;
      segs.push({ bits: parity.charAt(i) === 'L' ? EAN_L[ld] : EAN_G[ld], g: 0 });
    }
    segs.push({ bits: '01010', g: 1 });
    for (var j = 0; j < 6; j++) segs.push({ bits: EAN_R[full.charCodeAt(7 + j) - 48], g: 0 });
    segs.push({ bits: '101', g: 1 });

    var bits = [], guard = [];
    for (var s = 0; s < segs.length; s++) {
      var b = segs[s].bits;
      for (var c = 0; c < b.length; c++) { bits.push(b.charCodeAt(c) - 48); guard.push(segs[s].g); }
    }
    drawEAN(bits, guard, mw, barH, showText ? full : null,
      { kind: 'ean13', full: full, check: chk, modules: bits.length });
  }

  function run() {
    $('bc-mw-l').textContent = $('bc-mw').value;
    $('bc-h-l').textContent = $('bc-h').value;
    var raw = $('bc-in').value;
    if (!raw) { status.textContent = ''; status.className = 'wt-status bc-hint'; clearStage(); return; }
    var mw = +$('bc-mw').value, barH = +$('bc-h').value, showText = $('bc-txt').checked;
    if ($('bc-sym').value === 'ean13') renderEAN(raw, mw, barH, showText);
    else renderCode128(raw, mw, barH, showText);
  }

  ['bc-in','bc-sym','bc-mw','bc-h','bc-txt'].forEach(function (id) {
    $(id).addEventListener('input', run);
    $(id).addEventListener('change', run);
  });

  $('bc-dl').addEventListener('click', function () {
    if (!state.ready) return;
    var a = document.createElement('a');
    a.download = ($('bc-sym').value === 'ean13' ? 'ean13' : 'code128') + '-barcode.png';
    a.href = canvas.toDataURL('image/png');
    document.body.appendChild(a); a.click(); a.remove();
  });

  $('bc-cp').addEventListener('click', function () {
    if (state.ready && window.wtCopy) window.wtCopy(this, state.values);
  });

  run();
})();
</script>
