@php
    $cmxL = [
        'bad'    => __('ui.wt_cmx_bad'),
        'clip'   => __('ui.wt_cmx_clip'),
        'copied' => __('ui.wt_copied'),
        'delta'  => __('ui.wt_cmx_delta'),
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
.cmx-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-top:18px}
.cmx-card{background:var(--surface-2);border:1px solid var(--line-2);border-radius:15px;padding:14px;display:flex;flex-direction:column;gap:10px}
.cmx-h{display:flex;align-items:center;gap:7px;font-size:12.5px;font-weight:700;color:var(--muted)}
.cmx-h .icon{width:15px;height:15px;color:var(--cyan);flex:none}
.cmx-sw{height:92px;border-radius:11px;border:1px solid var(--line-2)}
.cmx-row{display:flex;align-items:center;gap:10px}
.cmx-hex{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:17px;font-weight:700;letter-spacing:.6px}
.cmx-meta{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11.5px;color:var(--dim);line-height:1.85}
.cmx-warn{font-size:11.5px;color:#ff6b6b;min-height:1px}
.cmx-note{font-size:11.5px;color:var(--muted);line-height:1.8;margin-top:auto}
.cmx-strips{margin-top:22px;display:flex;flex-direction:column;gap:16px}
.cmx-lab{font-size:12px;font-weight:700;color:var(--dim);margin-bottom:7px}
.cmx-strip{display:flex;height:54px;border-radius:12px;overflow:hidden;border:1px solid var(--line-2)}
.cmx-step{flex:1 1 0;min-width:0;border:0;padding:0;cursor:pointer}
.cmx-step:hover{outline:2px solid var(--text);outline-offset:-2px;position:relative;z-index:2}
.cmx-hexes{display:flex;margin-top:5px}
.cmx-hexes span{flex:1 1 0;min-width:0;text-align:center;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:9.5px;letter-spacing:-.2px;color:var(--dim);overflow:hidden;white-space:nowrap}
</style>

<div class="wt-two">
  <div class="wt-pane">
    <label>{{ __('ui.wt_cmx_a') }}</label>
    <div class="cmx-pick">
      <input type="color" id="cmx-ca" class="wt-color" value="#22d3ee">
      <input type="text" id="cmx-ta" class="wt-input-lg cmx-hexin" dir="ltr" spellcheck="false" maxlength="7" value="#22d3ee">
    </div>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_cmx_b') }}</label>
    <div class="cmx-pick">
      <input type="color" id="cmx-cb" class="wt-color" value="#f59e0b">
      <input type="text" id="cmx-tb" class="wt-input-lg cmx-hexin" dir="ltr" spellcheck="false" maxlength="7" value="#f59e0b">
    </div>
  </div>
</div>

<div class="wt-fields">
  <label class="wt-range">{{ __('ui.wt_cmx_ratio') }}: <b id="cmx-rv">50</b>%
    <input type="range" id="cmx-r" min="0" max="100" step="1" value="50">
  </label>
  <label class="wt-range">{{ __('ui.wt_cmx_steps') }}: <b id="cmx-sv">9</b>
    <input type="range" id="cmx-s" min="3" max="12" step="1" value="9">
  </label>
  <button type="button" class="btn btn-glass cmx-btn" id="cmx-swap"><svg class="icon"><use href="#i-restore"/></svg>{{ __('ui.wt_cmx_swap') }}</button>
  <button type="button" class="btn btn-glass cmx-btn" id="cmx-rand"><svg class="icon"><use href="#i-sparkles"/></svg>{{ __('ui.wt_cmx_random') }}</button>
</div>

<div class="cmx-grid">
  <div class="cmx-card">
    <div class="cmx-h"><svg class="icon"><use href="#i-box"/></svg>{{ __('ui.wt_cmx_srgb') }}</div>
    <div class="cmx-sw" id="cmx-sw1"></div>
    <div class="cmx-row"><b class="cmx-hex" id="cmx-hex1" dir="ltr">#000000</b>
      <button type="button" class="wt-mini" id="cmx-cp1" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button></div>
    <div class="cmx-meta" id="cmx-m1" dir="ltr"></div>
    <div class="cmx-warn" id="cmx-w1"></div>
    <p class="cmx-note">{{ __('ui.wt_cmx_srgb_d') }}</p>
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
  <textarea id="cmx-css" class="wt-ta" rows="8" readonly dir="ltr"></textarea>
</div>
<div class="wt-bar">
  <button type="button" class="btn btn-primary" id="cmx-copycss" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy_all') }}</button>
</div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const L = @json($cmxL);

  /* ---------- helpers ---------- */
  const clamp = (n, a, b) => n < a ? a : (n > b ? b : n);
  const h2 = n => clamp(Math.round(n), 0, 255).toString(16).padStart(2, '0');
  const toHex = a => '#' + h2(a[0]) + h2(a[1]) + h2(a[2]);

  function parseHex(s) {
    s = String(s).trim().toLowerCase().replace(/^#/, '');
    if (/^[0-9a-f]{3}$/.test(s)) s = s[0] + s[0] + s[1] + s[1] + s[2] + s[2];
    if (!/^[0-9a-f]{6}$/.test(s)) return null;
    return [parseInt(s.slice(0, 2), 16), parseInt(s.slice(2, 4), 16), parseInt(s.slice(4, 6), 16)];
  }

  /* ---------- sRGB transfer function ---------- */
  const toLinear = c => c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  const toGamma  = c => c <= 0.0031308 ? 12.92 * c : 1.055 * Math.pow(c, 1 / 2.4) - 0.055;

  /* ---------- OKLab (Björn Ottosson) ---------- */
  function rgbToOklab(rgb) {
    const r = toLinear(rgb[0] / 255), g = toLinear(rgb[1] / 255), b = toLinear(rgb[2] / 255);
    const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
    const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
    const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
    return [
      0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s,
      1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s,
      0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s
    ];
  }

  function oklabToRgb(lab) {
    const l_ = lab[0] + 0.3963377774 * lab[1] + 0.2158037573 * lab[2];
    const m_ = lab[0] - 0.1055613458 * lab[1] - 0.0638541728 * lab[2];
    const s_ = lab[0] - 0.0894841775 * lab[1] - 1.2914855480 * lab[2];
    const l = l_ * l_ * l_, m = m_ * m_ * m_, s = s_ * s_ * s_;
    const lin = [
       4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
      -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
      -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s
    ];
    let clipped = false;
    const out = lin.map(v => {
      if (v < -0.0001 || v > 1.0001) clipped = true;
      return Math.round(toGamma(clamp(v, 0, 1)) * 255);
    });
    return { rgb: out, clipped: clipped };
  }

  const oklabToLch = lab => [lab[0], Math.sqrt(lab[1] * lab[1] + lab[2] * lab[2]),
    (Math.atan2(lab[2], lab[1]) * 180 / Math.PI + 360) % 360];
  const lchToOklab = lch => {
    const h = lch[2] * Math.PI / 180;
    return [lch[0], lch[1] * Math.cos(h), lch[1] * Math.sin(h)];
  };

  /* ---------- the three mixers ---------- */
  const lerp = (a, b, t) => a + (b - a) * t;

  function mixSrgb(A, B, t) {
    return { rgb: [Math.round(lerp(A[0], B[0], t)), Math.round(lerp(A[1], B[1], t)), Math.round(lerp(A[2], B[2], t))], clipped: false };
  }

  function mixOklab(A, B, t) {
    const a = rgbToOklab(A), b = rgbToOklab(B);
    return oklabToRgb([lerp(a[0], b[0], t), lerp(a[1], b[1], t), lerp(a[2], b[2], t)]);
  }

  function mixOklch(A, B, t) {
    const a = oklabToLch(rgbToOklab(A)), b = oklabToLch(rgbToOklab(B));
    let ha = a[2], hb = b[2];
    if (a[1] < 0.0005) ha = hb;
    if (b[1] < 0.0005) hb = ha;
    let d = hb - ha;
    if (d > 180) d -= 360;
    if (d < -180) d += 360;
    return oklabToRgb(lchToOklab([lerp(a[0], b[0], t), lerp(a[1], b[1], t), ha + d * t]));
  }

  const deltaE = (p, q) => {
    const a = rgbToOklab(p), b = rgbToOklab(q);
    return Math.sqrt(Math.pow(a[0] - b[0], 2) + Math.pow(a[1] - b[1], 2) + Math.pow(a[2] - b[2], 2));
  };

  /* ---------- state ---------- */
  const cur = { a: [34, 211, 238], b: [245, 158, 11] };
  const MIX = [mixSrgb, mixOklab, mixOklch];

  function metaOf(rgb) {
    const lch = oklabToLch(rgbToOklab(rgb));
    return 'rgb(' + rgb[0] + ', ' + rgb[1] + ', ' + rgb[2] + ')<br>L ' +
      (lch[0] * 100).toFixed(1) + '%  C ' + lch[1].toFixed(3) + '  H ' + Math.round(lch[2]) + '°';
  }

  function render() {
    const t = +$('cmx-r').value / 100;
    const n = +$('cmx-s').value;
    $('cmx-rv').textContent = $('cmx-r').value;
    $('cmx-sv').textContent = n;

    const res = MIX.map(f => f(cur.a, cur.b, t));
    const hexes = res.map(r => toHex(r.rgb));

    res.forEach(function (r, i) {
      const k = i + 1;
      $('cmx-sw' + k).style.background = hexes[i];
      $('cmx-hex' + k).textContent = hexes[i];
      $('cmx-m' + k).innerHTML = metaOf(r.rgb);
      $('cmx-w' + k).textContent = r.clipped ? L.clip : '';
      $('cmx-cp' + k).onclick = function () { wtCopy($('cmx-cp' + k), hexes[i]); };
    });

    $('cmx-de').textContent = L.delta + ': ' + deltaE(res[0].rgb, res[1].rgb).toFixed(4);

    const ramps = [[], [], []];
    for (let i = 0; i < n; i++) {
      const tt = n === 1 ? 0 : i / (n - 1);
      for (let m = 0; m < 3; m++) ramps[m].push(toHex(MIX[m](cur.a, cur.b, tt).rgb));
    }
    ramps.forEach(function (cols, m) {
      $('cmx-st' + (m + 1)).innerHTML = cols.map(h =>
        '<button type="button" class="cmx-step" data-h="' + h + '" title="' + h + '" style="background:' + h + '"></button>').join('');
      $('cmx-hx' + (m + 1)).innerHTML = cols.map(h => '<span>' + h.slice(1) + '</span>').join('');
    });

    const pct = Math.round(t * 100);
    const lines = [
      '--color-a: ' + toHex(cur.a) + ';',
      '--color-b: ' + toHex(cur.b) + ';',
      '--mix-srgb: ' + hexes[0] + ';',
      '--mix-oklab: ' + hexes[1] + ';',
      '--mix-oklch: ' + hexes[2] + ';',
      '--mix-native: color-mix(in oklab, var(--color-a) ' + (100 - pct) + '%, var(--color-b));',
      '--ramp-oklab: linear-gradient(90deg, ' + ramps[1].join(', ') + ');'
    ];
    $('cmx-css').value = lines.join(String.fromCharCode(10));
  }

  /* ---------- wiring ---------- */
  function bindPair(colorId, textId, key) {
    $(colorId).addEventListener('input', function () {
      $(textId).value = $(colorId).value;
      $(textId).classList.remove('bad');
      cur[key] = parseHex($(colorId).value);
      msg('');
      render();
    });
    $(textId).addEventListener('input', function () {
      const c = parseHex($(textId).value);
      if (!c) { $(textId).classList.add('bad'); msg(L.bad, true); return; }
      $(textId).classList.remove('bad');
      $(colorId).value = toHex(c);
      cur[key] = c;
      msg('');
      render();
    });
  }

  function msg(text, isErr) {
    const el = $('cmx-msg');
    el.textContent = text || L.hint;
    el.classList.toggle('err', !!isErr);
  }

  bindPair('cmx-ca', 'cmx-ta', 'a');
  bindPair('cmx-cb', 'cmx-tb', 'b');

  $('cmx-r').addEventListener('input', render);
  $('cmx-s').addEventListener('input', render);

  $('cmx-swap').addEventListener('click', function () {
    const tmp = cur.a; cur.a = cur.b; cur.b = tmp;
    $('cmx-ca').value = $('cmx-ta').value = toHex(cur.a);
    $('cmx-cb').value = $('cmx-tb').value = toHex(cur.b);
    $('cmx-ta').classList.remove('bad'); $('cmx-tb').classList.remove('bad');
    msg('');
    render();
  });

  $('cmx-rand').addEventListener('click', function () {
    const rnd = () => [0, 0, 0].map(() => Math.floor(Math.random() * 256));
    cur.a = rnd(); cur.b = rnd();
    $('cmx-ca').value = $('cmx-ta').value = toHex(cur.a);
    $('cmx-cb').value = $('cmx-tb').value = toHex(cur.b);
    $('cmx-ta').classList.remove('bad'); $('cmx-tb').classList.remove('bad');
    msg('');
    render();
  });

  $('cmx-strips').addEventListener('click', function (e) {
    const b = e.target.closest ? e.target.closest('.cmx-step') : null;
    if (!b) return;
    const h = b.dataset.h;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(h).then(function () { msg(h + ' — ' + L.copied); }).catch(function () {});
    }
  });

  $('cmx-copycss').addEventListener('click', function (e) { wtCopy(e.currentTarget, $('cmx-css').value); });

  msg('');
  render();
})();
</script>
