<style>
.bgp-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px}
.bgp-tab{display:inline-flex;align-items:center;gap:9px;padding:5px;padding-inline-end:14px;border:1px solid var(--line-2);background:var(--surface-2);color:var(--muted);border-radius:12px;cursor:pointer;font-family:var(--font-body);font-size:12.8px;line-height:1;transition:border-color .15s,color .15s}
.bgp-tab:hover{border-color:var(--cyan);color:var(--text)}
.bgp-tab[aria-pressed="true"]{border-color:var(--cyan);color:var(--cyan);box-shadow:inset 0 0 0 1px var(--cyan)}
.bgp-sw{width:30px;height:30px;flex:none;border-radius:8px;box-shadow:inset 0 0 0 1px var(--line-2)}
.bgp-stage{position:relative}
.bgp-preview{width:100%;height:clamp(180px,30vw,300px);border-radius:16px;box-shadow:inset 0 0 0 1px var(--line-2)}
.bgp-badge{position:absolute;inset-block-start:10px;inset-inline-start:12px;font-size:11.5px;color:var(--dim);background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:3px 9px;font-variant-numeric:tabular-nums;pointer-events:none}
.bgp-num,.bgp-hex,.bgp-sel{background:var(--surface-2);border:1px solid var(--line-2);border-radius:9px;color:var(--text);padding:6px 9px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;outline:none;transition:border-color .15s}
.bgp-num:focus,.bgp-hex:focus,.bgp-sel:focus{border-color:var(--cyan)}
.bgp-num{width:64px;text-align:center}
.bgp-hex{width:100px;text-transform:lowercase}
.bgp-sel{width:160px}
.bgp-off{opacity:.34}
.bgp-note{font-size:12.5px;color:var(--muted);line-height:1.95;margin:16px 0 0;border-inline-start:2px solid var(--cyan);padding-inline-start:11px}
.bgp-info{margin-top:26px;border:1px solid var(--line);border-radius:16px;background:var(--surface-2);padding:22px;display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:24px;align-items:start}
.bgp-info h4{font-family:var(--font-disp);font-size:14.5px;color:var(--text);margin:0 0 10px}
.bgp-info p{font-size:12.8px;line-height:2.05;color:var(--muted);margin:0 0 9px}
.bgp-info p:last-child{margin-bottom:0}
.bgp-info code{color:var(--cyan);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;direction:ltr;unicode-bidi:isolate}
</style>

<div class="bgp-tabs" id="bgp-tabs" role="group" aria-label="{{ __('ui.wt_bgp_pattern') }}">
  <button type="button" class="bgp-tab" data-p="stripes"><span class="bgp-sw" data-sw="stripes"></span>{{ __('ui.wt_bgp_p_stripes') }}</button>
  <button type="button" class="bgp-tab" data-p="checks"><span class="bgp-sw" data-sw="checks"></span>{{ __('ui.wt_bgp_p_checks') }}</button>
  <button type="button" class="bgp-tab" data-p="dots"><span class="bgp-sw" data-sw="dots"></span>{{ __('ui.wt_bgp_p_dots') }}</button>
  <button type="button" class="bgp-tab" data-p="zigzag"><span class="bgp-sw" data-sw="zigzag"></span>{{ __('ui.wt_bgp_p_zigzag') }}</button>
  <button type="button" class="bgp-tab" data-p="diagonal"><span class="bgp-sw" data-sw="diagonal"></span>{{ __('ui.wt_bgp_p_diagonal') }}</button>
  <button type="button" class="bgp-tab" data-p="grid"><span class="bgp-sw" data-sw="grid"></span>{{ __('ui.wt_bgp_p_grid') }}</button>
  <button type="button" class="bgp-tab" data-p="carbon"><span class="bgp-sw" data-sw="carbon"></span>{{ __('ui.wt_bgp_p_carbon') }}</button>
</div>

<div class="bgp-stage">
  <div class="bgp-preview" id="bgp-prev" role="img" aria-label="{{ __('ui.wt_bgp_preview') }}"></div>
  <span class="bgp-badge" id="bgp-badge" dir="ltr" data-l="{{ __('ui.wt_bgp_repeat') }}"></span>
</div>

<div class="wt-fields">
  <div class="wt-range"><span>{{ __('ui.wt_bgp_bgcolor') }}</span>
    <input type="color" id="bgp-c1" class="wt-color sm" value="#0f172a">
    <input type="text" id="bgp-h1" class="bgp-hex" value="#0f172a" dir="ltr" spellcheck="false" maxlength="7">
  </div>
  <div class="wt-range"><span>{{ __('ui.wt_bgp_fgcolor') }}</span>
    <input type="color" id="bgp-c2" class="wt-color sm" value="#22d3ee">
    <input type="text" id="bgp-h2" class="bgp-hex" value="#22d3ee" dir="ltr" spellcheck="false" maxlength="7">
  </div>
  <span class="wt-status" id="bgp-status" data-bad="{{ __('ui.wt_bgp_badhex') }}"></span>
</div>

<div class="wt-fields">
  <div class="wt-range" id="bgp-sw-wrap"><span>{{ __('ui.wt_bgp_size') }}</span>
    <input type="range" id="bgp-s" min="6" max="200" step="1" value="40">
    <input type="number" id="bgp-sn" class="bgp-num" min="6" max="200" step="1" value="40" dir="ltr">
  </div>
  <div class="wt-range" id="bgp-tw"><span>{{ __('ui.wt_bgp_thick') }}</span>
    <input type="range" id="bgp-t" min="1" max="100" step="1" value="50">
    <b id="bgp-tn" dir="ltr">50%</b>
  </div>
  <div class="wt-range" id="bgp-aw"><span>{{ __('ui.wt_bgp_angle') }}</span>
    <input type="range" id="bgp-a" min="0" max="360" step="1" value="90">
    <input type="number" id="bgp-an" class="bgp-num" min="0" max="360" step="1" value="90" dir="ltr">
  </div>
</div>

<div class="wt-bar">
  <button type="button" class="btn btn-glass" id="bgp-swap"><svg class="icon"><use href="#i-restore"/></svg>{{ __('ui.wt_bgp_swap') }}</button>
  <button type="button" class="btn btn-glass" id="bgp-rand"><svg class="icon"><use href="#i-sparkles"/></svg>{{ __('ui.wt_bgp_random') }}</button>
  <button type="button" class="btn btn-glass" id="bgp-reset"><svg class="icon"><use href="#i-x"/></svg>{{ __('ui.wt_bgp_reset') }}</button>
</div>

<p class="bgp-note">{{ __('ui.wt_bgp_note') }}</p>

<div class="wt-fields">
  <div class="wt-range"><span>{{ __('ui.wt_bgp_selector') }}</span>
    <input type="text" id="bgp-sel" class="bgp-sel" value=".pattern" dir="ltr" spellcheck="false" maxlength="60">
  </div>
  <label class="wt-chk"><input type="checkbox" id="bgp-wrap" checked> {{ __('ui.wt_bgp_wrap') }}</label>
</div>

<div class="wt-pane" style="margin-top:18px">
  <label>{{ __('ui.wt_bgp_css') }}</label>
  <textarea id="bgp-out" class="wt-ta" rows="9" readonly dir="ltr" spellcheck="false"></textarea>
  <div class="wt-bar">
    <button type="button" class="btn btn-primary" id="bgp-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  </div>
</div>

<div class="bgp-info">
  <div>
    <h4>{{ __('ui.wt_bgp_how') }}</h4>
    <p>{{ __('ui.wt_bgp_how_1') }}</p>
    <p>{{ __('ui.wt_bgp_how_2') }}</p>
    <p>{{ __('ui.wt_bgp_how_3') }}</p>
  </div>
  <div>
    <h4>{{ __('ui.wt_bgp_tip') }}</h4>
    <p>{{ __('ui.wt_bgp_tip_1') }}</p>
    <p>{{ __('ui.wt_bgp_tip_2') }}</p>
    <p>{{ __('ui.wt_bgp_tip_3') }}</p>
  </div>
</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var NL = String.fromCharCode(10);

  // Which controls are meaningful for each pattern.
  var ANGLE_OK = { stripes: 1, diagonal: 1 };
  var THICK_OK = { stripes: 1, diagonal: 1, dots: 1, zigzag: 1, grid: 1 };
  // Sensible thickness default per pattern (percent of tile size).
  var DEF_T = { stripes: 50, checks: 50, dots: 50, zigzag: 50, diagonal: 20, grid: 8, carbon: 50 };
  // Tile size used to draw the little swatch inside each tab button.
  var SW_S = { stripes: 9, checks: 7, dots: 10, zigzag: 15, diagonal: 11, grid: 10, carbon: 15 };

  var DEF = { p: 'stripes', c1: '#0f172a', c2: '#22d3ee', s: 40, a: 90, t: 50 };
  var cur = DEF.p, c1 = DEF.c1, c2 = DEF.c2;

  function clamp(n, a, b) { return Math.min(b, Math.max(a, n)); }

  function h2r(h) {
    var s = String(h).charAt(0) === '#' ? String(h).slice(1) : String(h);
    if (s.length === 3) s = s.charAt(0) + s.charAt(0) + s.charAt(1) + s.charAt(1) + s.charAt(2) + s.charAt(2);
    var n = parseInt(s, 16);
    if (isNaN(n)) n = 0;
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  }
  function r2h(a) {
    var out = '#';
    for (var i = 0; i < 3; i++) {
      var v = clamp(Math.round(a[i]), 0, 255).toString(16);
      out += v.length === 1 ? '0' + v : v;
    }
    return out;
  }
  function mix(a, b, t) {
    var A = h2r(a), B = h2r(b);
    return r2h([A[0] + (B[0] - A[0]) * t, A[1] + (B[1] - A[1]) * t, A[2] + (B[2] - A[2]) * t]);
  }
  function normHex(v) {
    var s = String(v).trim().toLowerCase();
    if (s.charAt(0) === '#') s = s.slice(1);
    if (/^[0-9a-f]{3}$/.test(s)) s = s.charAt(0) + s.charAt(0) + s.charAt(1) + s.charAt(1) + s.charAt(2) + s.charAt(2);
    return /^[0-9a-f]{6}$/.test(s) ? '#' + s : null;
  }
  // Numbers are rounded to 2 decimals so the emitted CSS stays readable.
  function px(n) { return (Math.round(n * 100) / 100) + 'px'; }

  /* ------------------------------------------------------------------
     Pattern builder.
     Returns { color, image:[layers], size, position, repeat }
     Every pattern paints c2 (and derived tints) over a flat c1 base, so
     "swap colours" is always meaningful and the CSS stays short.
  ------------------------------------------------------------------ */
  function build(p, cA, cB, S, A, TH) {
    S  = clamp(Math.round(S), 4, 400);
    A  = ((Math.round(A) % 360) + 360) % 360;
    TH = clamp(Math.round(TH), 1, 100);

    var T = clamp(Math.round(S * TH / 100), 1, S);   // band width in px
    var layers = [], bsize = '', bpos = '', rep = px(S) + ' × ' + px(S);

    // One hard-stop repeating band: solid c2 for T px, then a gap up to S px.
    function band(deg) {
      return 'repeating-linear-gradient(' + deg + 'deg, ' + cB + ' 0 ' + px(T) +
             ', transparent ' + px(T) + ' ' + px(S) + ')';
    }

    if (p === 'stripes') {
      layers.push(band(A));
      rep = px(S);

    } else if (p === 'diagonal') {
      // Two perpendicular band sets => cross-hatch. 90deg default -> 135/225.
      layers.push(band((A + 45) % 360), band((A + 135) % 360));
      rep = px(S);

    } else if (p === 'grid') {
      // 90deg and 180deg anchor the gradient line to the left and top edges,
      // so the rules stay put when the element is resized.
      layers.push(band(90), band(180));

    } else if (p === 'checks') {
      // A conic gradient split into four quarters gives a 2x2 checkerboard
      // per tile, so the tile is twice the square size the user asked for.
      layers.push('conic-gradient(from 0deg at 50% 50%, ' + cB + ' 0 25%, transparent 0 50%, ' +
                  cB + ' 0 75%, transparent 0)');
      bsize = px(S * 2) + ' ' + px(S * 2);
      rep = px(S * 2) + ' × ' + px(S * 2);

    } else if (p === 'dots') {
      var R = clamp(S * TH / 200, 0.5, S / 2);
      // The +0.5px stop is the antialiasing feather; a hard stop shows jaggies.
      layers.push('radial-gradient(circle at 50% 50%, ' + cB + ' ' + px(R) +
                  ', transparent ' + px(R + 0.5) + ')');
      bsize = px(S) + ' ' + px(S);

    } else if (p === 'zigzag') {
      // Four 45deg corner wedges per tile; shifting two of them half a tile
      // sideways turns the diamonds into a continuous chevron.
      var P = clamp(Math.round(TH / 2), 5, 50);
      var wedge = function (deg) {
        return 'linear-gradient(' + deg + 'deg, ' + cB + ' ' + P + '%, transparent ' + P + '%)';
      };
      layers.push(wedge(135), wedge(225), wedge(315), wedge(45));
      bsize = px(S) + ' ' + px(S);
      bpos = '-' + px(S / 2) + ' 0, -' + px(S / 2) + ' 0, 0 0, 0 0';

    } else { // carbon
      var q = S / 4, h = S / 2;
      var sA = mix(cA, cB, 0.10), sB = mix(cA, cB, 0.34), vv = mix(cA, cB, 0.20);
      var b1 = mix(cA, cB, 0.24), b2 = mix(cA, cB, 0.16), b3 = mix(cA, cB, 0.40);
      layers.push(
        'linear-gradient(27deg, '  + sA + ' ' + px(q) + ', transparent ' + px(q) + ')',
        'linear-gradient(207deg, ' + sA + ' ' + px(q) + ', transparent ' + px(q) + ')',
        'linear-gradient(27deg, '  + sB + ' ' + px(q) + ', transparent ' + px(q) + ')',
        'linear-gradient(207deg, ' + sB + ' ' + px(q) + ', transparent ' + px(q) + ')',
        'linear-gradient(90deg, '  + vv + ' ' + px(h) + ', transparent ' + px(h) + ')',
        'linear-gradient(' + b1 + ' 25%, ' + b2 + ' 25%, ' + b2 + ' 50%, transparent 50%, transparent 75%, ' + b3 + ' 75%)'
      );
      bsize = px(S) + ' ' + px(S);
      bpos = '0 ' + px(q) + ', ' + px(h) + ' 0, 0 ' + px(h) + ', ' + px(h) + ' ' + px(q) + ', 0 0, 0 0';
    }

    return { color: cA, image: layers, size: bsize, position: bpos, repeat: rep };
  }

  function paint(el, b) {
    el.style.backgroundColor    = b.color;
    el.style.backgroundImage    = b.image.join(', ');
    el.style.backgroundSize     = b.size || 'auto';
    el.style.backgroundPosition = b.position || '0 0';
    el.style.backgroundRepeat   = 'repeat';
  }

  function cssText(b) {
    var lines = ['background-color: ' + b.color + ';'];
    lines.push(b.image.length === 1
      ? 'background-image: ' + b.image[0] + ';'
      : 'background-image:' + NL + '  ' + b.image.join(',' + NL + '  ') + ';');
    if (b.size)     lines.push('background-size: ' + b.size + ';');
    if (b.position) lines.push('background-position: ' + b.position + ';');
    return lines.join(NL);
  }

  // ---- wiring ---------------------------------------------------------
  var prev = $('bgp-prev'), out = $('bgp-out'), badge = $('bgp-badge');
  var si = $('bgp-s'), sn = $('bgp-sn'), ti = $('bgp-t'), ai = $('bgp-a'), an = $('bgp-an');
  var tabs = [].slice.call(document.querySelectorAll('#bgp-tabs .bgp-tab'));
  var swatches = [].slice.call(document.querySelectorAll('.bgp-sw'));

  function render() {
    var S = clamp(+si.value || DEF.s, 6, 200);
    var A = clamp(+ai.value || 0, 0, 360);
    var TH = clamp(+ti.value || 1, 1, 100);

    $('bgp-tn').textContent = TH + '%';

    var angleOn = !!ANGLE_OK[cur], thickOn = !!THICK_OK[cur];
    $('bgp-aw').classList.toggle('bgp-off', !angleOn);
    $('bgp-tw').classList.toggle('bgp-off', !thickOn);
    ai.disabled = an.disabled = !angleOn;
    ti.disabled = !thickOn;

    var b = build(cur, c1, c2, S, A, TH);
    paint(prev, b);
    badge.textContent = badge.getAttribute('data-l') + ' ' + b.repeat;

    var txt = cssText(b);
    if ($('bgp-wrap').checked) {
      var sel = $('bgp-sel').value.trim() || '.pattern';
      txt = sel + ' {' + NL +
            txt.split(NL).map(function (l) { return '  ' + l; }).join(NL) + NL + '}';
    }
    out.value = txt;

    swatches.forEach(function (el) {
      var p = el.getAttribute('data-sw');
      paint(el, build(p, c1, c2, SW_S[p], 90, DEF_T[p]));
    });
  }

  function setSize(v) {
    var n = clamp(Math.round(v) || DEF.s, 6, 200);
    si.value = n; sn.value = n; render();
  }
  function setAngle(v) {
    var n = clamp(Math.round(v) || 0, 0, 360);
    ai.value = n; an.value = n; render();
  }

  function pick(p) {
    cur = p;
    tabs.forEach(function (b) {
      b.setAttribute('aria-pressed', b.getAttribute('data-p') === p ? 'true' : 'false');
    });
    ti.value = DEF_T[p];
    render();
  }

  function flagHex(bad) {
    var st = $('bgp-status');
    st.textContent = bad ? st.getAttribute('data-bad') : '';
    st.classList.toggle('err', !!bad);
  }

  tabs.forEach(function (b) {
    b.addEventListener('click', function () { pick(b.getAttribute('data-p')); });
  });

  [['bgp-c1', 'bgp-h1', 1], ['bgp-c2', 'bgp-h2', 2]].forEach(function (pair) {
    var picker = $(pair[0]), text = $(pair[1]), slot = pair[2];
    picker.addEventListener('input', function () {
      var v = picker.value.toLowerCase();
      if (slot === 1) { c1 = v; } else { c2 = v; }
      text.value = v; flagHex(false); render();
    });
    text.addEventListener('input', function () {
      var n = normHex(text.value);
      if (!n) { flagHex(true); return; }
      if (slot === 1) { c1 = n; } else { c2 = n; }
      picker.value = n; flagHex(false); render();
    });
    text.addEventListener('blur', function () {
      text.value = slot === 1 ? c1 : c2; flagHex(false);
    });
  });

  si.addEventListener('input', function () { setSize(+si.value); });
  sn.addEventListener('input', function () { if (sn.value !== '') setSize(+sn.value); });
  sn.addEventListener('change', function () { setSize(+sn.value); });
  ai.addEventListener('input', function () { setAngle(+ai.value); });
  an.addEventListener('input', function () { if (an.value !== '') setAngle(+an.value); });
  an.addEventListener('change', function () { setAngle(+an.value); });
  ti.addEventListener('input', render);
  $('bgp-sel').addEventListener('input', render);
  $('bgp-wrap').addEventListener('change', render);

  $('bgp-swap').addEventListener('click', function () {
    var t = c1; c1 = c2; c2 = t;
    $('bgp-c1').value = c1; $('bgp-h1').value = c1;
    $('bgp-c2').value = c2; $('bgp-h2').value = c2;
    flagHex(false); render();
  });

  // HSL keeps the random pairs readable: a deep base plus a vivid accent.
  function hsl2hex(H, Sp, Lp) {
    var s = Sp / 100, l = Lp / 100;
    var k = function (n) { return (n + H / 30) % 12; };
    var a = s * Math.min(l, 1 - l);
    var f = function (n) { return l - a * Math.max(-1, Math.min(Math.min(k(n) - 3, 9 - k(n)), 1)); };
    return r2h([f(0) * 255, f(8) * 255, f(4) * 255]);
  }

  $('bgp-rand').addEventListener('click', function () {
    var H = Math.floor(Math.random() * 360);
    var H2 = (H + 140 + Math.floor(Math.random() * 90)) % 360;
    c1 = hsl2hex(H, 42, 12 + Math.random() * 8);
    c2 = hsl2hex(H2, 78, 52 + Math.random() * 12);
    $('bgp-c1').value = c1; $('bgp-h1').value = c1;
    $('bgp-c2').value = c2; $('bgp-h2').value = c2;
    ti.value = 8 + Math.floor(Math.random() * 62);
    ai.value = an.value = Math.floor(Math.random() * 24) * 15;
    si.value = sn.value = 12 + Math.floor(Math.random() * 78);
    flagHex(false); render();
  });

  $('bgp-reset').addEventListener('click', function () {
    c1 = DEF.c1; c2 = DEF.c2;
    $('bgp-c1').value = c1; $('bgp-h1').value = c1;
    $('bgp-c2').value = c2; $('bgp-h2').value = c2;
    si.value = sn.value = DEF.s;
    ai.value = an.value = DEF.a;
    $('bgp-sel').value = '.pattern';
    $('bgp-wrap').checked = true;
    flagHex(false);
    pick(DEF.p);
  });

  $('bgp-copy').addEventListener('click', function (e) { wtCopy(e.currentTarget, out.value); });

  pick(DEF.p);
})();
</script>
