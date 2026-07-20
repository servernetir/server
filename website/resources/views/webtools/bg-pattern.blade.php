<style>
.bgp{display:flex;flex-direction:column;gap:16px}
.bgp-tabs{display:flex;flex-wrap:wrap;gap:8px}
.bgp-tab{display:inline-flex;align-items:center;gap:8px;padding:5px;padding-inline-end:13px;border:1px solid var(--line);background:var(--surface-2);color:var(--text);border-radius:var(--r);cursor:pointer;font:inherit;font-size:.83rem;line-height:1;transition:border-color .15s,background .15s}
.bgp-tab:hover{border-color:var(--line-2)}
.bgp-tab.on{border-color:var(--cyan);background:color-mix(in srgb,var(--cyan) 16%,transparent)}
.bgp-sw{width:28px;height:28px;flex:none;border-radius:7px;border:1px solid var(--line-2);background-color:var(--surface)}
.bgp-preview{width:100%;height:clamp(170px,30vw,280px);border:1px solid var(--line-2);border-radius:16px}
.bgp-fields{display:flex;flex-wrap:wrap;gap:14px 24px;align-items:center;padding-top:16px;border-top:1px solid var(--line)}
.bgp-off{opacity:.34}
.bgp-note{font-size:.78rem;color:var(--muted);line-height:1.75}
.bgp-out{min-height:150px}
</style>

<div class="bgp">
  <div class="bgp-tabs" role="group" aria-label="{{ __('ui.wt_bgp_pattern') }}">
    <button type="button" class="bgp-tab on" data-p="stripes" aria-pressed="true"><span class="bgp-sw" data-sw="stripes"></span>{{ __('ui.wt_bgp_p_stripes') }}</button>
    <button type="button" class="bgp-tab" data-p="checks" aria-pressed="false"><span class="bgp-sw" data-sw="checks"></span>{{ __('ui.wt_bgp_p_checks') }}</button>
    <button type="button" class="bgp-tab" data-p="dots" aria-pressed="false"><span class="bgp-sw" data-sw="dots"></span>{{ __('ui.wt_bgp_p_dots') }}</button>
    <button type="button" class="bgp-tab" data-p="zigzag" aria-pressed="false"><span class="bgp-sw" data-sw="zigzag"></span>{{ __('ui.wt_bgp_p_zigzag') }}</button>
    <button type="button" class="bgp-tab" data-p="diagonal" aria-pressed="false"><span class="bgp-sw" data-sw="diagonal"></span>{{ __('ui.wt_bgp_p_diagonal') }}</button>
    <button type="button" class="bgp-tab" data-p="grid" aria-pressed="false"><span class="bgp-sw" data-sw="grid"></span>{{ __('ui.wt_bgp_p_grid') }}</button>
    <button type="button" class="bgp-tab" data-p="carbon" aria-pressed="false"><span class="bgp-sw" data-sw="carbon"></span>{{ __('ui.wt_bgp_p_carbon') }}</button>
  </div>

  <div class="bgp-preview" id="bgp-prev" role="img" aria-label="{{ __('ui.wt_bgp_preview') }}"></div>

  <div class="bgp-fields">
    <label class="wt-range">{{ __('ui.wt_bgp_bgcolor') }} <input type="color" id="bgp-c1" value="#0f172a" class="wt-color sm"></label>
    <label class="wt-range">{{ __('ui.wt_bgp_fgcolor') }} <input type="color" id="bgp-c2" value="#22d3ee" class="wt-color sm"></label>
    <label class="wt-range">{{ __('ui.wt_bgp_size') }}: <b id="bgp-sn">40</b>px<input type="range" id="bgp-s" min="8" max="160" step="1" value="40"></label>
    <label class="wt-range" id="bgp-tw">{{ __('ui.wt_bgp_thick') }}: <b id="bgp-tn">50</b>%<input type="range" id="bgp-t" min="1" max="100" step="1" value="50"></label>
    <label class="wt-range" id="bgp-aw">{{ __('ui.wt_bgp_angle') }}: <b id="bgp-an">90</b>°<input type="range" id="bgp-a" min="0" max="360" step="1" value="90"></label>
  </div>

  <p class="bgp-note">{{ __('ui.wt_bgp_note') }}</p>

  <div class="wt-bar" style="margin-top:0">
    <button type="button" class="btn btn-glass" id="bgp-swap"><svg class="icon"><use href="#i-restore"/></svg>{{ __('ui.wt_bgp_swap') }}</button>
    <button type="button" class="btn btn-glass" id="bgp-rand"><svg class="icon"><use href="#i-sparkles"/></svg>{{ __('ui.wt_bgp_random') }}</button>
    <button type="button" class="btn btn-glass" id="bgp-reset">{{ __('ui.wt_bgp_reset') }}</button>
    <label class="wt-chk"><input type="checkbox" id="bgp-wrap"> {{ __('ui.wt_bgp_wrap') }}</label>
  </div>

  <div class="wt-pane">
    <label>{{ __('ui.wt_bgp_css') }}</label>
    <textarea id="bgp-out" class="wt-ta bgp-out" rows="7" readonly dir="ltr" spellcheck="false"></textarea>
  </div>

  <div class="wt-bar" style="margin-top:0">
    <button type="button" class="btn btn-primary" id="bgp-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  </div>
</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };

  var ANGLE_OK = { stripes: 1, diagonal: 1 };
  var THICK_OK = { stripes: 1, diagonal: 1, dots: 1, zigzag: 1, grid: 1 };
  var DEF_T    = { stripes: 50, checks: 50, dots: 50, zigzag: 50, diagonal: 18, grid: 10, carbon: 50 };

  var DEFAULTS = { p: 'stripes', c1: '#0f172a', c2: '#22d3ee', s: 40, a: 90, t: 50 };
  var cur = DEFAULTS.p;

  function clamp(n, a, b) { return Math.min(b, Math.max(a, n)); }
  function h2r(h) {
    h = String(h).replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    var n = parseInt(h, 16);
    if (isNaN(n)) n = 0;
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  }
  function r2h(a) {
    return '#' + a.map(function (v) {
      return clamp(Math.round(v), 0, 255).toString(16).padStart(2, '0');
    }).join('');
  }
  function mix(a, b, t) {
    var A = h2r(a), B = h2r(b);
    return r2h([0, 1, 2].map(function (i) { return A[i] + (B[i] - A[i]) * t; }));
  }
  function px(n) { return (Math.round(n * 100) / 100) + 'px'; }

  // ---- pattern builder -------------------------------------------------
  // returns { color, image:[layers], size, position }
  function build(p, c1, c2, S, A, TH) {
    S = clamp(Math.round(S), 4, 400);
    A = ((Math.round(A) % 360) + 360) % 360;
    TH = clamp(Math.round(TH), 1, 100);

    var T = clamp(Math.round(S * TH / 100), 1, S);
    var layers = [], bsize = '', bpos = '';

    var line = function (deg) {
      return 'repeating-linear-gradient(' + deg + 'deg, ' + c2 + ' 0 ' + px(T) +
             ', transparent ' + px(T) + ' ' + px(S) + ')';
    };

    if (p === 'stripes') {
      layers.push(line(A));

    } else if (p === 'diagonal') {
      layers.push(line((A + 45) % 360), line((A + 135) % 360));

    } else if (p === 'grid') {
      // 90deg / 180deg anchor the gradient at the inline-start and top edges,
      // so the lines never shift when the element is resized.
      layers.push(line(90), line(180));

    } else if (p === 'checks') {
      layers.push('conic-gradient(' + c2 + ' 0 25%, ' + c1 + ' 0 50%, ' + c2 + ' 0 75%, ' + c1 + ' 0)');
      bsize = px(S) + ' ' + px(S);

    } else if (p === 'dots') {
      var R = clamp(S * TH / 200, 0.5, S / 2);
      layers.push('radial-gradient(circle at 50% 50%, ' + c2 + ' ' + px(R) +
                  ', transparent ' + px(R + 1) + ')');
      bsize = px(S) + ' ' + px(S);

    } else if (p === 'zigzag') {
      var P = clamp(Math.round(TH / 2), 5, 50);
      var g = function (deg) {
        return 'linear-gradient(' + deg + 'deg, ' + c2 + ' ' + P + '%, transparent ' + P + '%)';
      };
      layers.push(g(135), g(225), g(315), g(45));
      bsize = px(S) + ' ' + px(S);
      bpos = '-' + px(S / 2) + ' 0, -' + px(S / 2) + ' 0, 0 0, 0 0';

    } else { // carbon
      var q = S / 4, h = S / 2, t3 = S * 0.75;
      var w1 = mix(c1, c2, 0.55), w2 = mix(c1, c2, 0.30),
          w3 = mix(c1, c2, 0.18), w4 = mix(c1, c2, 0.10), w5 = mix(c1, c2, 0.24);
      layers.push(
        'linear-gradient(27deg, ' + c2 + ' ' + px(q) + ', transparent ' + px(q) + ')',
        'linear-gradient(207deg, ' + c2 + ' ' + px(q) + ', transparent ' + px(q) + ')',
        'linear-gradient(27deg, ' + w1 + ' ' + px(q) + ', transparent ' + px(q) + ')',
        'linear-gradient(207deg, ' + w1 + ' ' + px(q) + ', transparent ' + px(q) + ')',
        'linear-gradient(90deg, ' + w2 + ' ' + px(h) + ', transparent ' + px(h) + ')',
        'linear-gradient(' + w3 + ' 25%, ' + w4 + ' 25%, ' + w4 + ' 50%, transparent 50%, transparent 75%, ' + w5 + ' 75%)'
      );
      bsize = px(S) + ' ' + px(S);
      bpos = '0 ' + px(q) + ', ' + px(h) + ' 0, 0 ' + px(h) + ', ' + px(h) + ' ' + px(t3) + ', 0 0, 0 0';
    }

    return { color: c1, image: layers, size: bsize, position: bpos };
  }

  function paint(el, b) {
    el.style.backgroundColor = b.color;
    el.style.backgroundImage = b.image.join(', ');
    el.style.backgroundSize = b.size || 'auto';
    el.style.backgroundPosition = b.position || '0 0';
    el.style.backgroundRepeat = 'repeat';
  }

  function cssText(b) {
    var out = 'background-color: ' + b.color + ';\n';
    out += b.image.length === 1
      ? 'background-image: ' + b.image[0] + ';\n'
      : 'background-image:\n  ' + b.image.join(',\n  ') + ';\n';
    if (b.size) out += 'background-size: ' + b.size + ';\n';
    if (b.position) out += 'background-position: ' + b.position + ';\n';
    return out.replace(/\n$/, '');
  }

  // ---- wiring ----------------------------------------------------------
  var prev = $('bgp-prev'), out = $('bgp-out');
  var c1i = $('bgp-c1'), c2i = $('bgp-c2'), si = $('bgp-s'), ai = $('bgp-a'), ti = $('bgp-t');
  var swatches = Array.prototype.slice.call(document.querySelectorAll('.bgp-sw'));
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.bgp-tab'));

  function render() {
    var c1 = c1i.value, c2 = c2i.value;
    var S = +si.value, A = +ai.value, TH = +ti.value;

    $('bgp-sn').textContent = S;
    $('bgp-an').textContent = A;
    $('bgp-tn').textContent = TH;

    var angleOn = !!ANGLE_OK[cur], thickOn = !!THICK_OK[cur];
    $('bgp-aw').classList.toggle('bgp-off', !angleOn);
    $('bgp-tw').classList.toggle('bgp-off', !thickOn);
    ai.disabled = !angleOn;
    ti.disabled = !thickOn;

    var b = build(cur, c1, c2, S, A, TH);
    paint(prev, b);

    var txt = cssText(b);
    if ($('bgp-wrap').checked) {
      txt = '.pattern {\n  ' + txt.split('\n').join('\n  ') + '\n}';
    }
    out.value = txt;

    swatches.forEach(function (sw) {
      var p = sw.getAttribute('data-sw');
      paint(sw, build(p, c1, c2, 16, 90, DEF_T[p]));
    });
  }

  tabs.forEach(function (btn) {
    btn.addEventListener('click', function () {
      cur = btn.getAttribute('data-p');
      tabs.forEach(function (b2) {
        var on = b2 === btn;
        b2.classList.toggle('on', on);
        b2.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      ti.value = DEF_T[cur];
      render();
    });
  });

  [c1i, c2i, si, ai, ti, $('bgp-wrap')].forEach(function (el) {
    el.addEventListener('input', render);
    el.addEventListener('change', render);
  });

  $('bgp-swap').addEventListener('click', function () {
    var tmp = c1i.value; c1i.value = c2i.value; c2i.value = tmp; render();
  });

  function rndHex() {
    return '#' + Array.from({ length: 3 }, function () {
      return Math.floor(Math.random() * 256).toString(16).padStart(2, '0');
    }).join('');
  }

  $('bgp-rand').addEventListener('click', function () {
    c1i.value = rndHex();
    c2i.value = rndHex();
    si.value = 12 + Math.floor(Math.random() * 85);
    ai.value = Math.floor(Math.random() * 24) * 15;
    ti.value = 8 + Math.floor(Math.random() * 62);
    render();
  });

  $('bgp-reset').addEventListener('click', function () {
    cur = DEFAULTS.p;
    tabs.forEach(function (b2) {
      var on = b2.getAttribute('data-p') === cur;
      b2.classList.toggle('on', on);
      b2.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    c1i.value = DEFAULTS.c1; c2i.value = DEFAULTS.c2;
    si.value = DEFAULTS.s; ai.value = DEFAULTS.a; ti.value = DEFAULTS.t;
    $('bgp-wrap').checked = false;
    render();
  });

  $('bgp-copy').addEventListener('click', function (e) { wtCopy(e.currentTarget, out.value); });

  render();
})();
</script>
