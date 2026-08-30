<style>
.csl-note{display:flex;align-items:center;gap:9px;font-size:12.5px;color:var(--dim);margin-bottom:4px}
.csl-note .icon{width:14px;height:14px;color:var(--green);flex:none}
.csl-txt{background:var(--surface-2);border:1px solid var(--line-2);border-radius:9px;color:var(--text);padding:7px 11px;font-family:var(--font-body);font-size:13px;outline:none;min-width:170px}
.csl-txt:focus{border-color:var(--cyan)}
.csl-seg{display:inline-flex;gap:6px}
.csl-seg button{padding:6px 12px;border:1px solid var(--line-2);background:var(--surface-2);color:var(--muted);border-radius:9px;cursor:pointer;font:inherit;font-size:12.5px;transition:border-color .15s,color .15s,background .15s}
.csl-seg button.on{border-color:var(--cyan);color:var(--cyan);background:color-mix(in srgb, var(--cyan) 14%, transparent)}
.csl-gal{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:18px}
@media(max-width:1000px){.csl-gal{grid-template-columns:repeat(3,1fr)}}
@media(max-width:700px){.csl-gal{grid-template-columns:repeat(2,1fr)}}
.csl-card{display:flex;flex-direction:column;gap:9px;padding:10px;border:1px solid var(--line-2);background:var(--surface);border-radius:15px;cursor:pointer;font:inherit;color:var(--text);transition:border-color .2s,transform .2s}
.csl-card:hover{border-color:var(--cyan);transform:translateY(-2px)}
.csl-card.on{border-color:var(--cyan);box-shadow:inset 0 0 0 1px var(--cyan)}
.csl-stage{display:grid;place-items:center;height:var(--csl-h,140px);border-radius:11px;border:1px solid var(--line);background:var(--surface-2);overflow:hidden}
.csl-name{font-size:12.5px;color:var(--muted);text-align:center}
.csl-card.on .csl-name{color:var(--cyan);font-weight:700}
/* پس‌زمینه‌های دستیِ پیش‌نمایش: عمداً در هر دو تم ثابت‌اند */
.csl-gal.bg-dark .csl-stage{background:#0b1220;border-color:#1e293b}
.csl-gal.bg-light .csl-stage{background:#f1f5f9;border-color:#cbd5e1}
.csl-sel{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--cyan)}
</style>

@php
    $cslLoaders = ['ring','dual','dots','bars','pulse','ripple','spokes','bounce','ellipsis','flip','orbit','grid'];
@endphp

<div class="csl-note">
  <svg class="icon"><use href="#i-check"/></svg>
  <span>{{ __('ui.wt_csl_note') }}</span>
</div>

<div class="wt-fields" style="border:0;padding-top:0">
  <label class="wt-range">{{ __('ui.wt_csl_size') }}: <b id="csl-size-n">48</b>px<input type="range" id="csl-size" min="12" max="120" step="1" value="48"></label>
  <label class="wt-range">{{ __('ui.wt_csl_thick') }}: <b id="csl-th-n">4</b>px<input type="range" id="csl-th" min="1" max="14" step="1" value="4"></label>
  <label class="wt-range">{{ __('ui.wt_csl_speed') }}: <b id="csl-dur-n">1.2</b>s<input type="range" id="csl-dur" min="0.2" max="3" step="0.1" value="1.2"></label>
</div>

<div class="wt-fields">
  <label class="wt-range">{{ __('ui.wt_csl_color1') }} <input type="color" id="csl-c1" class="wt-color sm" value="#22d3ee"></label>
  <label class="wt-range">{{ __('ui.wt_csl_color2') }} <input type="color" id="csl-c2" class="wt-color sm" value="#8b5cf6"></label>
  <span class="wt-range">{{ __('ui.wt_csl_bg') }}
    <span class="csl-seg">
      <button type="button" id="csl-bg-auto" class="on">{{ __('ui.wt_csl_bg_auto') }}</button>
      <button type="button" id="csl-bg-dark">{{ __('ui.wt_csl_bg_dark') }}</button>
      <button type="button" id="csl-bg-light">{{ __('ui.wt_csl_bg_light') }}</button>
    </span>
  </span>
</div>

<div class="wt-fields">
  <label class="wt-range">{{ __('ui.wt_csl_label') }} <input type="text" id="csl-label" class="csl-txt" maxlength="60" value="{{ __('ui.wt_csl_label_def') }}"></label>
  <label class="wt-chk"><input type="checkbox" id="csl-rm"> {{ __('ui.wt_csl_rm') }}</label>
  <button type="button" class="btn btn-glass" id="csl-reset" style="padding:8px 16px;font-size:13px">{{ __('ui.wt_csl_reset') }}</button>
</div>

<p class="wt-status" style="margin-top:14px">{{ __('ui.wt_csl_pick') }}</p>

<div class="csl-gal" id="csl-gal">
  @foreach($cslLoaders as $cslKey)
  <button type="button" class="csl-card" data-k="{{ $cslKey }}" aria-pressed="false">
    <span class="csl-stage" aria-hidden="true"></span>
    <span class="csl-name">{{ __('ui.wt_csl_n_'.$cslKey) }}</span>
  </button>
  @endforeach
</div>

<style id="csl-live"></style>

<div class="wt-two" style="margin-top:20px">
  <div class="wt-pane">
    <label>{{ __('ui.wt_csl_html') }} — <span class="csl-sel" id="csl-selname" dir="ltr">.ldr-ring</span></label>
    <textarea id="csl-out-html" class="wt-ta" rows="4" readonly dir="ltr" spellcheck="false"></textarea>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_csl_css') }}</label>
    <textarea id="csl-out-css" class="wt-ta" rows="16" readonly dir="ltr" spellcheck="false"></textarea>
  </div>
</div>

<div class="wt-bar">
  <button type="button" class="btn btn-glass" id="csl-cp-html" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_csl_copy_html') }}</button>
  <button type="button" class="btn btn-glass" id="csl-cp-css" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_csl_copy_css') }}</button>
  <button type="button" class="btn btn-primary" id="csl-cp-all" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_csl_copy_all') }}</button>
</div>

@verbatim
<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var gal = $('csl-gal'), live = $('csl-live');
  var outH = $('csl-out-html'), outC = $('csl-out-css');
  var KEYS = ['ring','dual','dots','bars','pulse','ripple','spokes','bounce','ellipsis','flip','orbit','grid'];
  var KIDS = { ring:0, dual:0, dots:3, bars:5, pulse:0, ripple:0, spokes:8, bounce:1, ellipsis:4, flip:0, orbit:0, grid:9 };
  var DEFAULTS = { size:48, th:4, dur:1.2, c1:'#22d3ee', c2:'#8b5cf6' };
  var sel = 'ring';

  var clamp = function (n, a, b) { return Math.min(b, Math.max(a, n)); };
  var num = function (n) { n = Math.round(n * 100) / 100; if (n === 0) n = 0; return String(n); };
  var px  = function (n) { return num(n) + 'px'; };
  var sec = function (n) { return num(n) + 's'; };

  function rgbOf(h) {
    h = String(h).replace('#', '');
    if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
    return [parseInt(h.slice(0, 2), 16) || 0, parseInt(h.slice(2, 4), 16) || 0, parseInt(h.slice(4, 6), 16) || 0];
  }
  function rgba(h, a) {
    var c = rgbOf(h);
    return 'rgba(' + c[0] + ', ' + c[1] + ', ' + c[2] + ', ' + a + ')';
  }
  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* ---- CSS builders ------------------------------------------------- */
  function block(selector, decls) {
    return selector + ' {\n' + decls.map(function (d) { return '  ' + d + ';'; }).join('\n') + '\n}';
  }
  function kf(name, steps) {
    return '@keyframes ' + name + ' {\n'
      + steps.map(function (s) { return '  ' + s[0] + ' { ' + s[1] + ' }'; }).join('\n')
      + '\n}';
  }

  function params() {
    var S  = clamp(parseFloat($('csl-size').value) || 48, 12, 120);
    var TH = clamp(parseFloat($('csl-th').value) || 1, 1, Math.max(1, Math.floor(S / 2) - 1));
    var D  = clamp(parseFloat($('csl-dur').value) || 1, 0.2, 3);
    var C1 = $('csl-c1').value, C2 = $('csl-c2').value;
    return { S: S, TH: TH, D: D, C1: C1, C2: C2, TR: rgba(C2, '0.22') };
  }

  function cssFor(k, p) {
    var S = p.S, TH = p.TH, D = p.D, C1 = p.C1, C2 = p.C2, out = [], i, n, w, h, g, d, b, y, c;

    if (k === 'ring') {
      out.push(block('.ldr-ring', [
        'box-sizing: border-box',
        'width: ' + px(S), 'height: ' + px(S),
        'border: ' + px(TH) + ' solid ' + p.TR,
        'border-top-color: ' + C1,
        'border-radius: 50%',
        'animation: ldr-ring-spin ' + sec(D) + ' linear infinite'
      ]));
      out.push(kf('ldr-ring-spin', [['to', 'transform: rotate(360deg)']]));

    } else if (k === 'dual') {
      var inner = clamp(TH * 2, 2, Math.max(2, S / 2 - TH - 2));
      out.push(block('.ldr-dual', ['position: relative', 'box-sizing: border-box', 'width: ' + px(S), 'height: ' + px(S)]));
      out.push(block('.ldr-dual::before,\n.ldr-dual::after', [
        'content: ""', 'position: absolute', 'box-sizing: border-box',
        'border: ' + px(TH) + ' solid transparent', 'border-radius: 50%'
      ]));
      out.push(block('.ldr-dual::before', [
        'inset: 0', 'border-top-color: ' + C1, 'border-bottom-color: ' + C1,
        'animation: ldr-dual-spin ' + sec(D) + ' linear infinite'
      ]));
      out.push(block('.ldr-dual::after', [
        'inset: ' + px(inner), 'border-inline-start-color: ' + C2, 'border-inline-end-color: ' + C2,
        'animation: ldr-dual-spin ' + sec(D * 0.7) + ' linear infinite reverse'
      ]));
      out.push(kf('ldr-dual-spin', [['to', 'transform: rotate(360deg)']]));

    } else if (k === 'dots') {
      d = S * 0.28; g = S * 0.16;
      out.push(block('.ldr-dots', ['display: inline-flex', 'align-items: center', 'gap: ' + px(g)]));
      out.push(block('.ldr-dots span', [
        'width: ' + px(d), 'height: ' + px(d), 'border-radius: 50%', 'background: ' + C1,
        'animation: ldr-dots-b ' + sec(D) + ' ease-in-out infinite'
      ]));
      out.push(block('.ldr-dots span:nth-child(2)', ['background: ' + C2, 'animation-delay: ' + sec(D * 0.16)]));
      out.push(block('.ldr-dots span:nth-child(3)', ['animation-delay: ' + sec(D * 0.32)]));
      out.push(kf('ldr-dots-b', [
        ['0%, 80%, 100%', 'opacity: .25; transform: scale(.6)'],
        ['40%', 'opacity: 1; transform: scale(1)']
      ]));

    } else if (k === 'bars') {
      w = Math.max(2, S * 0.14); g = Math.max(2, S * 0.1);
      out.push(block('.ldr-bars', ['display: inline-flex', 'align-items: center', 'gap: ' + px(g), 'height: ' + px(S)]));
      out.push(block('.ldr-bars span', [
        'width: ' + px(w), 'height: 100%', 'border-radius: ' + px(w / 2), 'background: ' + C1,
        'animation: ldr-bars-s ' + sec(D) + ' ease-in-out infinite'
      ]));
      out.push(block('.ldr-bars span:nth-child(even)', ['background: ' + C2]));
      for (n = 2; n <= 5; n++) {
        out.push(block('.ldr-bars span:nth-child(' + n + ')', ['animation-delay: ' + sec(D * 0.12 * (n - 1))]));
      }
      out.push(kf('ldr-bars-s', [['0%, 100%', 'transform: scaleY(.3)'], ['50%', 'transform: scaleY(1)']]));

    } else if (k === 'pulse') {
      out.push(block('.ldr-pulse', [
        'width: ' + px(S), 'height: ' + px(S), 'border-radius: 50%', 'background: ' + C1,
        'animation: ldr-pulse-p ' + sec(D) + ' ease-out infinite'
      ]));
      out.push(kf('ldr-pulse-p', [
        ['0%',   'transform: scale(.6); box-shadow: 0 0 0 0 ' + rgba(C2, '0.55')],
        ['70%',  'transform: scale(1); box-shadow: 0 0 0 ' + px(S * 0.4) + ' ' + rgba(C2, '0')],
        ['100%', 'transform: scale(.6); box-shadow: 0 0 0 0 ' + rgba(C2, '0')]
      ]));

    } else if (k === 'ripple') {
      out.push(block('.ldr-ripple', ['position: relative', 'width: ' + px(S), 'height: ' + px(S)]));
      out.push(block('.ldr-ripple::before,\n.ldr-ripple::after', [
        'content: ""', 'position: absolute', 'inset: 0', 'margin: auto', 'box-sizing: border-box',
        'width: 0', 'height: 0', 'border: ' + px(TH) + ' solid ' + C1, 'border-radius: 50%',
        'animation: ldr-ripple-r ' + sec(D) + ' cubic-bezier(0, .2, .8, 1) infinite'
      ]));
      out.push(block('.ldr-ripple::after', ['border-color: ' + C2, 'animation-delay: ' + sec(D / 2)]));
      out.push(kf('ldr-ripple-r', [
        ['0%',   'width: 0; height: 0; opacity: 1'],
        ['100%', 'width: ' + px(S) + '; height: ' + px(S) + '; opacity: 0']
      ]));

    } else if (k === 'spokes') {
      w = Math.max(2, S * 0.1); h = Math.max(4, S * 0.28);
      out.push(block('.ldr-spokes', ['position: relative', 'width: ' + px(S), 'height: ' + px(S)]));
      out.push(block('.ldr-spokes i', ['position: absolute', 'inset: 0', 'animation: ldr-spokes-f ' + sec(D) + ' linear infinite']));
      out.push(block('.ldr-spokes i::before', [
        'content: ""', 'display: block', 'margin: 0 auto',
        'width: ' + px(w), 'height: ' + px(h), 'border-radius: ' + px(w), 'background: ' + C1
      ]));
      for (n = 1; n <= 8; n++) {
        out.push(block('.ldr-spokes i:nth-child(' + n + ')', [
          'transform: rotate(' + ((n - 1) * 45) + 'deg)',
          'animation-delay: ' + sec(-(8 - n) / 8 * D)
        ]));
      }
      out.push(kf('ldr-spokes-f', [['0%', 'opacity: 1'], ['100%', 'opacity: .15']]));

    } else if (k === 'bounce') {
      b = S * 0.42; y = S - b;
      out.push(block('.ldr-bounce', [
        'display: flex', 'align-items: flex-start', 'justify-content: center',
        'width: ' + px(S), 'height: ' + px(S)
      ]));
      out.push(block('.ldr-bounce span', [
        'width: ' + px(b), 'height: ' + px(b), 'border-radius: 50%',
        'background: linear-gradient(135deg, ' + C1 + ', ' + C2 + ')',
        'transform-origin: center bottom',
        'animation: ldr-bounce-b ' + sec(D) + ' cubic-bezier(.5, .05, .5, .95) infinite'
      ]));
      out.push(kf('ldr-bounce-b', [
        ['0%, 100%', 'transform: translateY(0) scale(1, 1)'],
        ['50%',      'transform: translateY(' + px(y) + ') scale(1.12, .88)']
      ]));

    } else if (k === 'ellipsis') {
      d = S * 0.26; g = S * 0.14;
      out.push(block('.ldr-ellipsis', ['display: inline-flex', 'align-items: center', 'gap: ' + px(g), 'direction: ltr']));
      out.push(block('.ldr-ellipsis span', [
        'width: ' + px(d), 'height: ' + px(d), 'border-radius: 50%', 'background: ' + C1,
        'animation-duration: ' + sec(D),
        'animation-timing-function: cubic-bezier(0, 1, 1, 0)',
        'animation-iteration-count: infinite'
      ]));
      out.push(block('.ldr-ellipsis span:nth-child(1)', ['animation-name: ldr-ellipsis-in']));
      out.push(block('.ldr-ellipsis span:nth-child(2),\n.ldr-ellipsis span:nth-child(3)', ['animation-name: ldr-ellipsis-move']));
      out.push(block('.ldr-ellipsis span:nth-child(4)', ['background: ' + C2, 'animation-name: ldr-ellipsis-out']));
      out.push(kf('ldr-ellipsis-in',   [['0%', 'transform: scale(0)'], ['100%', 'transform: scale(1)']]));
      out.push(kf('ldr-ellipsis-out',  [['0%', 'transform: scale(1)'], ['100%', 'transform: scale(0)']]));
      out.push(kf('ldr-ellipsis-move', [['0%', 'transform: translateX(0)'], ['100%', 'transform: translateX(' + px(d + g) + ')']]));

    } else if (k === 'flip') {
      var per = px(S * 3);
      out.push(block('.ldr-flip', [
        'width: ' + px(S), 'height: ' + px(S), 'border-radius: ' + px(S * 0.16),
        'background: linear-gradient(135deg, ' + C1 + ', ' + C2 + ')',
        'animation: ldr-flip-f ' + sec(D) + ' ease-in-out infinite'
      ]));
      out.push(kf('ldr-flip-f', [
        ['0%',   'transform: perspective(' + per + ') rotateX(0deg) rotateY(0deg)'],
        ['50%',  'transform: perspective(' + per + ') rotateX(-180deg) rotateY(0deg)'],
        ['100%', 'transform: perspective(' + per + ') rotateX(-180deg) rotateY(-180deg)']
      ]));

    } else if (k === 'orbit') {
      b = Math.max(4, S * 0.22);
      out.push(block('.ldr-orbit', [
        'position: relative', 'box-sizing: border-box',
        'width: ' + px(S), 'height: ' + px(S),
        'border: ' + px(TH) + ' solid ' + p.TR, 'border-radius: 50%',
        'animation: ldr-orbit-r ' + sec(D) + ' linear infinite'
      ]));
      out.push(block('.ldr-orbit::after', [
        'content: ""', 'position: absolute', 'inset: 0 0 auto 0', 'margin: 0 auto',
        'width: ' + px(b), 'height: ' + px(b), 'border-radius: 50%', 'background: ' + C1,
        'transform: translateY(-' + px(b / 2 + TH / 2) + ')'
      ]));
      out.push(kf('ldr-orbit-r', [['to', 'transform: rotate(360deg)']]));

    } else if (k === 'grid') {
      g = Math.max(2, Math.round(S * 0.11)); c = (S - 2 * g) / 3;
      out.push(block('.ldr-grid', [
        'display: grid', 'grid-template-columns: repeat(3, ' + px(c) + ')',
        'gap: ' + px(g), 'width: ' + px(S), 'height: ' + px(S)
      ]));
      out.push(block('.ldr-grid span', [
        'width: ' + px(c), 'height: ' + px(c), 'border-radius: ' + px(Math.max(1, c * 0.22)),
        'background: ' + C1, 'animation: ldr-grid-f ' + sec(D) + ' ease-in-out infinite'
      ]));
      out.push(block('.ldr-grid span:nth-child(even)', ['background: ' + C2]));
      var groups = [[1], [2, 4], [3, 5, 7], [6, 8], [9]];
      for (i = 0; i < groups.length; i++) {
        out.push(block(
          groups[i].map(function (x) { return '.ldr-grid span:nth-child(' + x + ')'; }).join(',\n'),
          ['animation-delay: ' + sec(-(i * D * 0.1))]
        ));
      }
      out.push(kf('ldr-grid-f', [
        ['0%, 70%, 100%', 'opacity: .18; transform: scale(.72)'],
        ['35%', 'opacity: 1; transform: scale(1)']
      ]));
    }
    return out.join('\n\n');
  }

  function reducedMotion(k, p) {
    var s = '.ldr-' + k;
    var sels = [s, s + ' *', s + '::before', s + '::after'].join(',\n  ');
    return '@media (prefers-reduced-motion: reduce) {\n  ' + sels
      + ' {\n    animation-duration: ' + sec(Math.min(10, p.D * 4)) + ';\n  }\n}';
  }

  /* ---- markup builder ----------------------------------------------- */
  function htmlFor(k) {
    var lbl = esc(($('csl-label').value || 'Loading').trim() || 'Loading');
    var open = '<div class="ldr-' + k + '" role="status" aria-label="' + lbl + '">';
    var n = KIDS[k] || 0;
    if (!n) return open + '</div>';
    var t = (k === 'spokes') ? 'i' : 'span', kids = '', i;
    for (i = 0; i < n; i++) kids += '<' + t + '></' + t + '>';
    return open + '\n  ' + kids + '\n</div>';
  }

  /* ---- render -------------------------------------------------------- */
  function render() {
    var p = params();
    $('csl-size-n').textContent = num(p.S);
    $('csl-th-n').textContent   = num(p.TH);
    $('csl-dur-n').textContent  = num(p.D);

    gal.style.setProperty('--csl-h', Math.round(Math.max(120, p.S * 1.9)) + 'px');

    live.textContent = KEYS.map(function (k) { return cssFor(k, p); }).join('\n\n');

    var css = cssFor(sel, p);
    if ($('csl-rm').checked) css += '\n\n' + reducedMotion(sel, p);
    outC.value = css;
    outH.value = htmlFor(sel);
    $('csl-selname').textContent = '.ldr-' + sel;
  }

  function select(k) {
    if (KEYS.indexOf(k) < 0) return;
    sel = k;
    Array.prototype.forEach.call(gal.querySelectorAll('.csl-card'), function (c) {
      var on = c.getAttribute('data-k') === k;
      c.classList.toggle('on', on);
      c.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    render();
  }

  /* ---- init ---------------------------------------------------------- */
  Array.prototype.forEach.call(gal.querySelectorAll('.csl-card'), function (c) {
    var k = c.getAttribute('data-k');
    c.querySelector('.csl-stage').innerHTML = htmlFor(k);
    c.addEventListener('click', function () { select(k); });
  });

  ['csl-size', 'csl-th', 'csl-dur', 'csl-c1', 'csl-c2', 'csl-rm'].forEach(function (id) {
    $(id).addEventListener('input', render);
    $(id).addEventListener('change', render);
  });
  $('csl-label').addEventListener('input', function () { outH.value = htmlFor(sel); });

  [['csl-bg-auto', ''], ['csl-bg-dark', 'bg-dark'], ['csl-bg-light', 'bg-light']].forEach(function (pair) {
    $(pair[0]).addEventListener('click', function () {
      gal.classList.remove('bg-dark', 'bg-light');
      if (pair[1]) gal.classList.add(pair[1]);
      Array.prototype.forEach.call(document.querySelectorAll('.csl-seg button'), function (b) { b.classList.remove('on'); });
      $(pair[0]).classList.add('on');
    });
  });

  $('csl-reset').addEventListener('click', function () {
    $('csl-size').value = DEFAULTS.size;
    $('csl-th').value   = DEFAULTS.th;
    $('csl-dur').value  = DEFAULTS.dur;
    $('csl-c1').value   = DEFAULTS.c1;
    $('csl-c2').value   = DEFAULTS.c2;
    $('csl-rm').checked = false;
    select('ring');
  });

  $('csl-cp-html').addEventListener('click', function (e) { wtCopy(e.currentTarget, outH.value); });
  $('csl-cp-css').addEventListener('click',  function (e) { wtCopy(e.currentTarget, outC.value); });
  $('csl-cp-all').addEventListener('click',  function (e) { wtCopy(e.currentTarget, outH.value + '\n\n<style>\n' + outC.value + '\n</style>'); });

  select('ring');
})();
</script>
@endverbatim
