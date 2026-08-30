@php
    $cmxL = [
        'bad'    => __('ui.wt_cmx_bad'),
        'clip'   => __('ui.wt_cmx_clip'),
        'mapped' => __('ui.wt_cmx_mapped'),
        'copied' => __('ui.wt_copied'),
        'delta'  => __('ui.wt_cmx_delta'),
        'jnd'    => __('ui.wt_cmx_jnd'),
        'hint'   => __('ui.wt_cmx_hint'),
    ];
@endphp
<style>
.cmx-pick{display:flex;gap:10px;align-items:center}
.cmx-pick input[type=color]{width:66px;height:48px;flex:none;padding:3px}
.cmx-hexin{flex:1;min-width:0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:15px;padding:13px 15px;letter-spacing:.5px}
.cmx-hexin.bad{border-color:#ff6b6b}
.cmx-btn{padding:8px 15px;font-size:12.5px;display:inline-flex;align-items:center;gap:7px}
.cmx-btn .icon{width:14px;height:14px}
.cmx-split{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:var(--dim)}
.cmx-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(196px,1fr));gap:14px;margin-top:18px}
.cmx-card{background:var(--surface-2);border:1px solid var(--line-2);border-radius:15px;padding:14px;display:flex;flex-direction:column;gap:10px}
.cmx-card.is-ref{border-color:rgba(34,211,238,.45)}
.cmx-h{display:flex;align-items:center;gap:7px;font-size:12.5px;font-weight:700;color:var(--muted)}
.cmx-h .icon{width:15px;height:15px;color:var(--cyan);flex:none}
.cmx-sw{height:92px;border-radius:11px;border:1px solid var(--line-2)}
.cmx-row{display:flex;align-items:center;gap:10px}
.cmx-hex{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:17px;font-weight:700;letter-spacing:.6px}
.cmx-meta{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;color:var(--dim);line-height:1.85}
.cmx-warn{font-size:11px;line-height:1.6;color:#c2410c;background:rgba(251,146,60,.14);border:1px solid rgba(251,146,60,.34);
  border-radius:8px;padding:5px 8px;display:none}
.cmx-warn.on{display:block}
html[data-theme="light"] .cmx-warn{color:#9a3412;background:rgba(251,146,60,.2);border-color:rgba(251,146,60,.5)}
.cmx-note{font-size:11.5px;color:var(--muted);line-height:1.8;margin-top:auto}
.cmx-strips{margin-top:22px;display:flex;flex-direction:column;gap:15px}
.cmx-lab{font-size:12px;font-weight:700;color:var(--dim);margin-bottom:7px}
.cmx-strip{display:flex;height:48px;border-radius:12px;overflow:hidden;border:1px solid var(--line-2)}
.cmx-step{flex:1 1 0;min-width:0;border:0;padding:0;cursor:pointer}
.cmx-step:hover{outline:2px solid var(--text);outline-offset:-2px;position:relative;z-index:2}
.cmx-hexes{display:flex;margin-top:5px}
.cmx-hexes span{flex:1 1 0;min-width:0;text-align:center;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
  font-size:9.5px;letter-spacing:-.2px;color:var(--dim);overflow:hidden;white-space:nowrap}
@media(max-width:520px){.cmx-hexes{display:none}}
</style>

<div class="wt-two">
  <div class="wt-pane">
    <label>{{ __('ui.wt_cmx_a') }}</label>
    <div class="cmx-pick">
      <input type="color" id="cmx-ca" class="wt-color" value="#22d3ee">
      <input type="text" id="cmx-ta" class="wt-input-lg cmx-hexin" dir="ltr" spellcheck="false" maxlength="24" value="#22d3ee">
    </div>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_cmx_b') }}</label>
    <div class="cmx-pick">
      <input type="color" id="cmx-cb" class="wt-color" value="#f59e0b">
      <input type="text" id="cmx-tb" class="wt-input-lg cmx-hexin" dir="ltr" spellcheck="false" maxlength="24" value="#f59e0b">
    </div>
  </div>
</div>

<div class="wt-fields">
  <label class="wt-range">{{ __('ui.wt_cmx_ratio') }}: <b id="cmx-rv">50</b>%
    <input type="range" id="cmx-r" min="0" max="100" step="1" value="50">
  </label>
  <span class="cmx-split" id="cmx-sp" dir="ltr">A 50% / B 50%</span>
  <label class="wt-range">{{ __('ui.wt_cmx_steps') }}: <b id="cmx-sv">9</b>
    <input type="range" id="cmx-s" min="3" max="12" step="1" value="9">
  </label>
  <label class="wt-chk"><input type="checkbox" id="cmx-gm"> {{ __('ui.wt_cmx_gamut') }}</label>
  <button type="button" class="btn btn-glass cmx-btn" id="cmx-swap"><svg class="icon"><use href="#i-restore"/></svg>{{ __('ui.wt_cmx_swap') }}</button>
  <button type="button" class="btn btn-glass cmx-btn" id="cmx-rand"><svg class="icon"><use href="#i-sparkles"/></svg>{{ __('ui.wt_cmx_random') }}</button>
</div>

<div class="cmx-grid">
  <div class="cmx-card is-ref">
    <div class="cmx-h"><svg class="icon"><use href="#i-box"/></svg>{{ __('ui.wt_cmx_srgb') }}</div>
    <div class="cmx-sw" id="cmx-sw0"></div>
    <div class="cmx-row"><b class="cmx-hex" id="cmx-hex0" dir="ltr">#000000</b>
      <button type="button" class="wt-mini" id="cmx-cp0" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button></div>
    <div class="cmx-meta" id="cmx-m0" dir="ltr"></div>
    <div class="cmx-warn" id="cmx-w0"></div>
    <p class="cmx-note">{{ __('ui.wt_cmx_srgb_d') }}</p>
  </div>
  <div class="cmx-card">
    <div class="cmx-h"><svg class="icon"><use href="#i-zap"/></svg>{{ __('ui.wt_cmx_lin') }}</div>
    <div class="cmx-sw" id="cmx-sw1"></div>
    <div class="cmx-row"><b class="cmx-hex" id="cmx-hex1" dir="ltr">#000000</b>
      <button type="button" class="wt-mini" id="cmx-cp1" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button></div>
    <div class="cmx-meta" id="cmx-m1" dir="ltr"></div>
    <div class="cmx-warn" id="cmx-w1"></div>
    <p class="cmx-note">{{ __('ui.wt_cmx_lin_d') }}</p>
  </div>
  <div class="cmx-card">
    <div class="cmx-h"><svg class="icon"><use href="#i-sparkles"/></svg>{{ __('ui.wt_cmx_oklab') }}</div>
    <div class="cmx-sw" id="cmx-sw2"></div>
    <div class="cmx-row"><b class="cmx-hex" id="cmx-hex2" dir="ltr">#000000</b>
      <button type="button" class="wt-mini" id="cmx-cp2" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button></div>
    <div class="cmx-meta" id="cmx-m2" dir="ltr"></div>
    <div class="cmx-warn" id="cmx-w2"></div>
    <p class="cmx-note">{{ __('ui.wt_cmx_oklab_d') }}</p>
  </div>
  <div class="cmx-card">
    <div class="cmx-h"><svg class="icon"><use href="#i-flow"/></svg>{{ __('ui.wt_cmx_oklch') }}</div>
    <div class="cmx-sw" id="cmx-sw3"></div>
    <div class="cmx-row"><b class="cmx-hex" id="cmx-hex3" dir="ltr">#000000</b>
      <button type="button" class="wt-mini" id="cmx-cp3" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button></div>
    <div class="cmx-meta" id="cmx-m3" dir="ltr"></div>
    <div class="cmx-warn" id="cmx-w3"></div>
    <p class="cmx-note">{{ __('ui.wt_cmx_oklch_d') }}</p>
  </div>
</div>

<div class="wt-bar"><span class="wt-status" id="cmx-de" dir="auto"></span></div>

<div class="cmx-strips" id="cmx-strips">
  <div>
    <div class="cmx-lab">{{ __('ui.wt_cmx_srgb') }}</div>
    <div class="cmx-strip" id="cmx-st0"></div>
    <div class="cmx-hexes" id="cmx-hx0" dir="ltr"></div>
  </div>
  <div>
    <div class="cmx-lab">{{ __('ui.wt_cmx_lin') }}</div>
    <div class="cmx-strip" id="cmx-st1"></div>
    <div class="cmx-hexes" id="cmx-hx1" dir="ltr"></div>
  </div>
  <div>
    <div class="cmx-lab">{{ __('ui.wt_cmx_oklab') }}</div>
    <div class="cmx-strip" id="cmx-st2"></div>
    <div class="cmx-hexes" id="cmx-hx2" dir="ltr"></div>
  </div>
  <div>
    <div class="cmx-lab">{{ __('ui.wt_cmx_oklch') }}</div>
    <div class="cmx-strip" id="cmx-st3"></div>
    <div class="cmx-hexes" id="cmx-hx3" dir="ltr"></div>
  </div>
</div>

<div class="wt-bar"><span class="wt-status" id="cmx-msg"></span></div>

<div class="wt-pane" style="margin-top:16px">
  <label>{{ __('ui.wt_cmx_css') }}</label>
  <textarea id="cmx-css" class="wt-ta" rows="12" readonly dir="ltr"></textarea>
</div>
<div class="wt-bar">
  <button type="button" class="btn btn-primary" id="cmx-copycss" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy_all') }}</button>
</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var L = @json($cmxL);
  var NL = String.fromCharCode(10);

  /* ---------- basics ---------- */
  function clamp(n, a, b) { return n < a ? a : (n > b ? b : n); }
  function h2(n) { return clamp(Math.round(n), 0, 255).toString(16).padStart(2, '0'); }
  function toHex(a) { return '#' + h2(a[0]) + h2(a[1]) + h2(a[2]); }

  function parseColor(s) {
    s = String(s).trim().toLowerCase();
    var m = s.match(/^rgba?[(]([0-9.]+)[ ,]+([0-9.]+)[ ,]+([0-9.]+)/);
    if (m) return [clamp(+m[1], 0, 255), clamp(+m[2], 0, 255), clamp(+m[3], 0, 255)].map(Math.round);
    s = s.replace(/^#/, '');
    if (/^[0-9a-f]{3}$/.test(s)) s = s[0] + s[0] + s[1] + s[1] + s[2] + s[2];
    if (!/^[0-9a-f]{6}$/.test(s)) return null;
    return [parseInt(s.slice(0, 2), 16), parseInt(s.slice(2, 4), 16), parseInt(s.slice(4, 6), 16)];
  }

  /* ---------- sRGB transfer function (IEC 61966-2-1) ---------- */
  function toLinear(c) { return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
  function toGamma(c) { return c <= 0.0031308 ? 12.92 * c : 1.055 * Math.pow(c, 1 / 2.4) - 0.055; }

  /* ---------- OKLab (Bjorn Ottosson) ---------- */
  function linToOklab(r, g, b) {
    var l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
    var m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
    var s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
    return [
      0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s,
      1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s,
      0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s
    ];
  }
  function oklabToLin(lab) {
    var l_ = lab[0] + 0.3963377774 * lab[1] + 0.2158037573 * lab[2];
    var m_ = lab[0] - 0.1055613458 * lab[1] - 0.0638541728 * lab[2];
    var s_ = lab[0] - 0.0894841775 * lab[1] - 1.2914855480 * lab[2];
    var l = l_ * l_ * l_, m = m_ * m_ * m_, s = s_ * s_ * s_;
    return [
       4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
      -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
      -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s
    ];
  }
  function rgbToOklab(rgb) {
    return linToOklab(toLinear(rgb[0] / 255), toLinear(rgb[1] / 255), toLinear(rgb[2] / 255));
  }
  function oklabToLch(lab) {
    return [lab[0], Math.sqrt(lab[1] * lab[1] + lab[2] * lab[2]),
      (Math.atan2(lab[2], lab[1]) * 180 / Math.PI + 360) % 360];
  }
  function lchToOklab(lch) {
    var h = lch[2] * Math.PI / 180;
    return [lch[0], lch[1] * Math.cos(h), lch[1] * Math.sin(h)];
  }

  /* ---------- gamut handling ---------- */
  function inGamut(lin) {
    for (var i = 0; i < 3; i++) { if (lin[i] < -0.0001 || lin[i] > 1.0001) return false; }
    return true;
  }
  function clipLin(lin) { return [clamp(lin[0], 0, 1), clamp(lin[1], 0, 1), clamp(lin[2], 0, 1)]; }
  function linToRgb(lin) {
    return [Math.round(toGamma(clamp(lin[0], 0, 1)) * 255),
            Math.round(toGamma(clamp(lin[1], 0, 1)) * 255),
            Math.round(toGamma(clamp(lin[2], 0, 1)) * 255)];
  }
  function dEok(a, b) {
    return Math.sqrt(Math.pow(a[0] - b[0], 2) + Math.pow(a[1] - b[1], 2) + Math.pow(a[2] - b[2], 2));
  }

  /* CSS Color 4 section 13.2 — chroma reduction in OKLCH with local MINDE clipping */
  function gamutMap(lab) {
    if (lab[0] >= 1) return [1, 1, 1];
    if (lab[0] <= 0) return [0, 0, 0];
    var JND = 0.02, EPS = 0.0001;
    var lch = oklabToLch(lab);
    var min = 0, max = lch[1], minInGamut = true, cur = [lch[0], lch[1], lch[2]];
    var guard = 0;
    while (max - min > EPS && guard++ < 60) {
      var chroma = (min + max) / 2;
      cur[1] = chroma;
      var curLab = lchToOklab(cur);
      var curLin = oklabToLin(curLab);
      if (minInGamut && inGamut(curLin)) { min = chroma; continue; }
      var cl = clipLin(curLin);
      var E = dEok(linToOklab(cl[0], cl[1], cl[2]), curLab);
      if (E < JND) {
        if (JND - E < EPS) return cl;
        minInGamut = false;
        min = chroma;
      } else max = chroma;
    }
    cur[1] = min;
    return clipLin(oklabToLin(lchToOklab(cur)));
  }

  /* turn an OKLab value into an sRGB result, reporting whether it left the gamut */
  function resolve(lab, mapIt) {
    var lin = oklabToLin(lab);
    var out = !inGamut(lin);
    return { rgb: linToRgb(out && mapIt ? gamutMap(lab) : clipLin(lin)), out: out };
  }

  /* ---------- the four mixers ---------- */
  function lerp(a, b, t) { return a + (b - a) * t; }

  function mixSrgb(A, B, t) {
    return { rgb: [Math.round(lerp(A[0], B[0], t)), Math.round(lerp(A[1], B[1], t)),
                   Math.round(lerp(A[2], B[2], t))], out: false };
  }
  function mixLinear(A, B, t) {
    var lin = [lerp(toLinear(A[0] / 255), toLinear(B[0] / 255), t),
               lerp(toLinear(A[1] / 255), toLinear(B[1] / 255), t),
               lerp(toLinear(A[2] / 255), toLinear(B[2] / 255), t)];
    return { rgb: linToRgb(lin), out: false };
  }
  function mixOklab(A, B, t, mapIt) {
    var a = rgbToOklab(A), b = rgbToOklab(B);
    return resolve([lerp(a[0], b[0], t), lerp(a[1], b[1], t), lerp(a[2], b[2], t)], mapIt);
  }
  function mixOklch(A, B, t, mapIt) {
    var a = oklabToLch(rgbToOklab(A)), b = oklabToLch(rgbToOklab(B));
    var ha = a[2], hb = b[2];
    /* an achromatic endpoint has no meaningful hue — borrow the other one */
    if (a[1] < 0.0005) ha = hb;
    if (b[1] < 0.0005) hb = ha;
    var d = hb - ha;                     /* shorter hue arc, as CSS color-mix does */
    if (d > 180) d -= 360;
    if (d < -180) d += 360;
    return resolve(lchToOklab([lerp(a[0], b[0], t), lerp(a[1], b[1], t), ha + d * t]), mapIt);
  }

  var SPACES = [
    { key: 'srgb',        fn: function (A, B, t) { return mixSrgb(A, B, t); } },
    { key: 'srgb-linear', fn: function (A, B, t) { return mixLinear(A, B, t); } },
    { key: 'oklab',       fn: function (A, B, t, m) { return mixOklab(A, B, t, m); } },
    { key: 'oklch',       fn: function (A, B, t, m) { return mixOklch(A, B, t, m); } }
  ];

  /* ---------- state ---------- */
  var cur = { a: [34, 211, 238], b: [245, 158, 11] };

  function esc(t) {
    return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function metaOf(rgb) {
    var lch = oklabToLch(rgbToOklab(rgb));
    return 'rgb(' + rgb[0] + ', ' + rgb[1] + ', ' + rgb[2] + ')<br>L ' + (lch[0] * 100).toFixed(1) +
           '%  C ' + lch[1].toFixed(3) + '  H ' + Math.round(lch[2]) + 'deg';
  }
  function oklchText(rgb) {
    var lch = oklabToLch(rgbToOklab(rgb));
    return 'oklch(' + (lch[0] * 100).toFixed(2) + '% ' + lch[1].toFixed(4) + ' ' + lch[2].toFixed(2) + ')';
  }

  function render() {
    var t = +$('cmx-r').value / 100;
    var n = +$('cmx-s').value;
    var mapIt = $('cmx-gm').checked;
    var pct = Math.round(t * 100);

    $('cmx-rv').textContent = pct;
    $('cmx-sv').textContent = n;
    $('cmx-sp').textContent = 'A ' + (100 - pct) + '% / B ' + pct + '%';

    var res = SPACES.map(function (s) { return s.fn(cur.a, cur.b, t, mapIt); });
    var hexes = res.map(function (r) { return toHex(r.rgb); });

    res.forEach(function (r, i) {
      $('cmx-sw' + i).style.background = hexes[i];
      $('cmx-hex' + i).textContent = hexes[i];
      $('cmx-m' + i).innerHTML = metaOf(r.rgb);
      var w = $('cmx-w' + i);
      w.textContent = r.out ? (mapIt ? L.mapped : L.clip) : '';
      w.classList.toggle('on', !!r.out);
      $('cmx-cp' + i).onclick = function () { wtCopy($('cmx-cp' + i), hexes[i]); };
    });

    var dv = dEok(rgbToOklab(res[0].rgb), rgbToOklab(res[2].rgb));
    $('cmx-de').textContent = L.delta + ': ' + dv.toFixed(4) + '  (' + (dv / 0.02).toFixed(1) + ' ' + L.jnd + ')';

    /* intermediate steps, endpoint to endpoint */
    var ramps = SPACES.map(function () { return []; });
    for (var i = 0; i < n; i++) {
      var tt = n === 1 ? 0 : i / (n - 1);
      for (var m = 0; m < SPACES.length; m++) ramps[m].push(toHex(SPACES[m].fn(cur.a, cur.b, tt, mapIt).rgb));
    }
    ramps.forEach(function (cols, m) {
      $('cmx-st' + m).innerHTML = cols.map(function (h) {
        return '<button type="button" class="cmx-step" data-h="' + esc(h) + '" title="' + esc(h) +
               '" aria-label="' + esc(h) + '" style="background:' + esc(h) + '"></button>';
      }).join('');
      $('cmx-hx' + m).innerHTML = cols.map(function (h) { return '<span>' + esc(h.slice(1)) + '</span>'; }).join('');
    });

    var ha = toHex(cur.a), hb = toHex(cur.b);
    var lines = [
      '--color-a: ' + ha + ';',
      '--color-b: ' + hb + ';',
      '--mix-srgb: ' + hexes[0] + ';',
      '--mix-srgb-linear: ' + hexes[1] + ';',
      '--mix-oklab: ' + hexes[2] + ';',
      '--mix-oklch: ' + hexes[3] + ';',
      '--mix-oklab-lch: ' + oklchText(res[2].rgb) + ';',
      '',
      '/* native equivalents, no build step needed */',
      '--css-srgb: color-mix(in srgb, ' + ha + ' ' + (100 - pct) + '%, ' + hb + ');',
      '--css-oklab: color-mix(in oklab, ' + ha + ' ' + (100 - pct) + '%, ' + hb + ');',
      '--css-oklch: color-mix(in oklch, ' + ha + ' ' + (100 - pct) + '%, ' + hb + ');',
      '',
      '--ramp-oklab: linear-gradient(90deg, ' + ramps[2].join(', ') + ');'
    ];
    $('cmx-css').value = lines.join(NL);
  }

  /* ---------- wiring ---------- */
  function msg(text, isErr) {
    var el = $('cmx-msg');
    el.textContent = text || L.hint;
    el.classList.toggle('err', !!isErr);
  }

  function syncFrom(key, rgb, colorId, textId) {
    cur[key] = rgb;
    $(colorId).value = toHex(rgb);
    $(textId).value = toHex(rgb);
    $(textId).classList.remove('bad');
  }

  function bindPair(colorId, textId, key) {
    $(colorId).addEventListener('input', function () {
      var c = parseColor($(colorId).value);
      if (!c) return;
      $(textId).value = toHex(c);
      $(textId).classList.remove('bad');
      cur[key] = c;
      msg('');
      render();
    });
    $(textId).addEventListener('input', function () {
      var c = parseColor($(textId).value);
      if (!c) { $(textId).classList.add('bad'); msg(L.bad, true); return; }
      $(textId).classList.remove('bad');
      $(colorId).value = toHex(c);
      cur[key] = c;
      msg('');
      render();
    });
  }

  bindPair('cmx-ca', 'cmx-ta', 'a');
  bindPair('cmx-cb', 'cmx-tb', 'b');

  $('cmx-r').addEventListener('input', render);
  $('cmx-s').addEventListener('input', render);
  $('cmx-gm').addEventListener('change', render);

  $('cmx-swap').addEventListener('click', function () {
    var tmp = cur.a;
    syncFrom('a', cur.b, 'cmx-ca', 'cmx-ta');
    syncFrom('b', tmp, 'cmx-cb', 'cmx-tb');
    msg('');
    render();
  });

  $('cmx-rand').addEventListener('click', function () {
    var rnd = function () { return [0, 0, 0].map(function () { return Math.floor(Math.random() * 256); }); };
    syncFrom('a', rnd(), 'cmx-ca', 'cmx-ta');
    syncFrom('b', rnd(), 'cmx-cb', 'cmx-tb');
    msg('');
    render();
  });

  $('cmx-strips').addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('.cmx-step') : null;
    if (!b) return;
    var h = b.getAttribute('data-h');
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(h).then(function () { msg(h + ' — ' + L.copied); }).catch(function () {});
    }
  });

  $('cmx-copycss').addEventListener('click', function (e) { wtCopy(e.currentTarget, $('cmx-css').value); });

  msg('');
  render();
})();
</script>
