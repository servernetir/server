<style>
.cbz-top{display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start}
.cbz-left{flex:0 0 auto;width:min(100%,330px)}
.cbz-right{flex:1 1 260px;min-width:246px;display:flex;flex-direction:column;gap:13px}
.cbz-stage{width:100%;aspect-ratio:1/1.25;background:var(--surface);border:1px solid var(--line-2);border-radius:var(--r);overflow:hidden;touch-action:none;user-select:none;-webkit-user-select:none}
.cbz-svg{width:100%;height:100%;display:block;cursor:crosshair}
.cbz-frame{fill:var(--surface-2);stroke:var(--line-2);stroke-width:1;vector-effect:non-scaling-stroke}
.cbz-grid line{stroke:var(--line);stroke-width:1;vector-effect:non-scaling-stroke}
.cbz-diag{stroke:var(--line-2);stroke-width:1;stroke-dasharray:3 4;vector-effect:non-scaling-stroke}
.cbz-lead{stroke-width:1.5;stroke-dasharray:4 3;vector-effect:non-scaling-stroke}
.cbz-lead.p1{stroke:var(--cyan)}
.cbz-lead.p2{stroke:var(--violet)}
.cbz-curve{fill:none;stroke:var(--cyan);stroke-width:2.6;stroke-linecap:round;vector-effect:non-scaling-stroke}
.cbz-end{fill:var(--muted)}
.cbz-guide{stroke:var(--dim);stroke-width:1;stroke-dasharray:2 3;vector-effect:non-scaling-stroke;opacity:.75}
.cbz-run{fill:var(--green)}
.cbz-smp{fill:none;stroke:var(--green);stroke-width:2;vector-effect:non-scaling-stroke}
.cbz-h{stroke:var(--surface);stroke-width:2;vector-effect:non-scaling-stroke;cursor:grab}
.cbz-h.p1{fill:var(--cyan)}
.cbz-h.p2{fill:var(--violet)}
.cbz-h:focus{outline:none;stroke:var(--text)}
.cbz-hint{font-size:11.5px;color:var(--dim);line-height:1.9;margin-top:9px}
.cbz-nums{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.cbz-num{display:flex;flex-direction:column;gap:5px}
.cbz-num span{font-size:11.5px;color:var(--dim);letter-spacing:.04em;direction:ltr;text-align:start}
.cbz-num.p1 span{color:var(--cyan)}
.cbz-num.p2 span{color:var(--violet)}
.cbz-num input{width:100%;direction:ltr;background:var(--surface-2);border:1px solid var(--line-2);border-radius:10px;padding:9px 11px;color:var(--text);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13.5px;outline:none}
.cbz-num input:focus{border-color:var(--cyan)}
.cbz-lbl{font-size:12.5px;font-weight:600;color:var(--dim)}
.cbz-txt{width:100%;direction:ltr;background:var(--surface-2);border:1px solid var(--line-2);border-radius:12px;padding:12px 14px;color:var(--text);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13.5px;outline:none}
.cbz-txt:focus{border-color:var(--cyan)}
.cbz-note{display:none;align-items:center;gap:9px;font-size:12px;color:var(--muted);background:var(--surface-2);border:1px solid var(--line);border-radius:11px;padding:9px 12px;line-height:1.8}
.cbz-note.on{display:flex}
.cbz-note .icon{width:14px;height:14px;flex:none;color:var(--violet)}
.cbz-sec{font-size:11.5px;color:var(--dim);text-transform:uppercase;letter-spacing:.09em;margin:22px 0 12px;padding-top:16px;border-top:1px solid var(--line)}
.cbz-lanes{display:flex;flex-direction:column;gap:16px}
.cbz-lane{display:flex;flex-direction:column;gap:7px}
.cbz-lane>span{font-size:11.5px;color:var(--dim);text-align:start}
.cbz-track{position:relative;height:30px;direction:ltr;background:var(--surface-2);border:1px solid var(--line);border-radius:99px}
.cbz-mv{position:absolute;top:50%;inset-inline-start:3px;width:20px;height:20px;margin-top:-10px;border-radius:50%;background:var(--cyan);will-change:transform}
.cbz-ring{position:absolute;top:50%;inset-inline-start:3px;width:20px;height:20px;margin-top:-10px;border-radius:50%;border:2px solid var(--violet);box-sizing:border-box;will-change:transform}
.cbz-tick{position:absolute;top:50%;inset-inline-start:10px;width:6px;height:6px;margin-top:-3px;border-radius:50%;background:var(--dim)}
.cbz-barwrap{height:26px;background:var(--surface-2);border:1px solid var(--line);border-radius:9px;overflow:hidden;direction:ltr}
.cbz-bar{height:100%;width:0;background:linear-gradient(90deg,var(--cyan),var(--violet))}
.cbz-fadewrap{height:60px;display:flex;align-items:center;justify-content:center;background:var(--surface-2);border:1px solid var(--line);border-radius:12px}
.cbz-fade{width:48px;height:48px;border-radius:13px;background:linear-gradient(135deg,var(--cyan),var(--violet));opacity:0}
.cbz-prow{display:flex;flex-wrap:wrap;gap:14px 20px;align-items:center;margin-top:16px}
.cbz-read{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;color:var(--cyan);direction:ltr}
.cbz-legend{display:flex;flex-wrap:wrap;gap:6px 18px;font-size:11.5px;color:var(--muted);margin-top:14px}
.cbz-legend i{display:inline-block;width:11px;height:11px;border-radius:50%;margin-inline-end:6px;vertical-align:-1px}
.cbz-legend .a{background:var(--cyan)}
.cbz-legend .b{border:2px solid var(--violet);box-sizing:border-box}
.cbz-legend .c{background:var(--dim)}
</style>

<div class="cbz">
  <div class="cbz-top">
    <div class="cbz-left">
      <div class="cbz-stage" id="cb-stage">
        <svg class="cbz-svg" id="cb-svg" viewBox="0 -9 100 118" preserveAspectRatio="xMidYMid meet">
          <rect class="cbz-frame" x="0" y="0" width="100" height="100"/>
          <g class="cbz-grid" id="cb-grid"></g>
          <line class="cbz-diag" x1="0" y1="100" x2="100" y2="0"/>
          <line class="cbz-guide" id="cb-gv"/>
          <line class="cbz-guide" id="cb-gh"/>
          <line class="cbz-lead p1" id="cb-l1"/>
          <line class="cbz-lead p2" id="cb-l2"/>
          <path class="cbz-curve" id="cb-curve"/>
          <circle class="cbz-end" id="cb-e0" cx="0" cy="100" r="2"/>
          <circle class="cbz-end" id="cb-e1" cx="100" cy="0" r="2"/>
          <circle class="cbz-smp" id="cb-smp" cx="0" cy="0" r="4"/>
          <circle class="cbz-run" id="cb-run" cx="0" cy="100" r="3"/>
          <circle class="cbz-h p1" id="cb-h1" tabindex="0" aria-label="{{ __('ui.wt_cbz_p1') }}"/>
          <circle class="cbz-h p2" id="cb-h2" tabindex="0" aria-label="{{ __('ui.wt_cbz_p2') }}"/>
        </svg>
      </div>
      <p class="cbz-hint">{{ __('ui.wt_cbz_hint') }}</p>
    </div>

    <div class="cbz-right">
      <div>
        <div class="cbz-lbl">{{ __('ui.wt_cbz_preset') }}</div>
        <select id="cb-preset" class="wt-select" style="width:100%;margin-top:6px">
          <option value="">{{ __('ui.wt_cbz_custom') }}</option>
          <optgroup label="{{ __('ui.wt_cbz_g_css') }}">
            <option value="linear">linear</option>
            <option value="ease" selected>ease</option>
            <option value="ease-in">ease-in</option>
            <option value="ease-out">ease-out</option>
            <option value="ease-in-out">ease-in-out</option>
          </optgroup>
          <optgroup label="{{ __('ui.wt_cbz_g_ease') }}">
            <option value="easeInSine">easeInSine</option>
            <option value="easeOutSine">easeOutSine</option>
            <option value="easeInOutSine">easeInOutSine</option>
            <option value="easeInQuad">easeInQuad</option>
            <option value="easeOutQuad">easeOutQuad</option>
            <option value="easeInOutQuad">easeInOutQuad</option>
            <option value="easeInCubic">easeInCubic</option>
            <option value="easeOutCubic">easeOutCubic</option>
            <option value="easeInOutCubic">easeInOutCubic</option>
            <option value="easeInQuart">easeInQuart</option>
            <option value="easeOutQuart">easeOutQuart</option>
            <option value="easeInOutQuart">easeInOutQuart</option>
            <option value="easeInQuint">easeInQuint</option>
            <option value="easeOutQuint">easeOutQuint</option>
            <option value="easeInOutQuint">easeInOutQuint</option>
            <option value="easeInExpo">easeInExpo</option>
            <option value="easeOutExpo">easeOutExpo</option>
            <option value="easeInOutExpo">easeInOutExpo</option>
            <option value="easeInCirc">easeInCirc</option>
            <option value="easeOutCirc">easeOutCirc</option>
            <option value="easeInOutCirc">easeInOutCirc</option>
          </optgroup>
          <optgroup label="{{ __('ui.wt_cbz_g_over') }}">
            <option value="easeInBack">easeInBack</option>
            <option value="easeOutBack">easeOutBack</option>
            <option value="easeInOutBack">easeInOutBack</option>
          </optgroup>
          <optgroup label="{{ __('ui.wt_cbz_g_ui') }}">
            <option value="material-standard">material-standard</option>
            <option value="material-decelerate">material-decelerate</option>
            <option value="material-accelerate">material-accelerate</option>
            <option value="material-sharp">material-sharp</option>
            <option value="swift-out">swift-out</option>
          </optgroup>
        </select>
      </div>

      <div class="cbz-nums">
        <label class="cbz-num p1"><span>x1</span><input type="number" id="cb-n1" step="0.01" min="0" max="1" dir="ltr"></label>
        <label class="cbz-num p1"><span>y1</span><input type="number" id="cb-n2" step="0.01" dir="ltr"></label>
        <label class="cbz-num p2"><span>x2</span><input type="number" id="cb-n3" step="0.01" min="0" max="1" dir="ltr"></label>
        <label class="cbz-num p2"><span>y2</span><input type="number" id="cb-n4" step="0.01" dir="ltr"></label>
      </div>

      <div>
        <div class="cbz-lbl">{{ __('ui.wt_cbz_import') }}</div>
        <input type="text" id="cb-imp" class="cbz-txt" style="margin-top:6px" dir="ltr" spellcheck="false" placeholder="cubic-bezier(0.4, 0, 0.2, 1)">
      </div>

      <div class="wt-bar" style="margin-top:0">
        <button type="button" class="btn btn-glass" id="cb-apply">{{ __('ui.wt_cbz_apply') }}</button>
        <button type="button" class="btn btn-glass" id="cb-rev">{{ __('ui.wt_cbz_reverse') }}</button>
        <button type="button" class="btn btn-glass" id="cb-reset">{{ __('ui.wt_cbz_reset') }}</button>
      </div>

      <span class="wt-status" id="cb-msg"
            data-range="{{ __('ui.wt_cbz_err_range') }}"
            data-parse="{{ __('ui.wt_cbz_err_parse') }}"
            data-ok="{{ __('ui.wt_cbz_applied') }}"></span>

      <div class="cbz-note" id="cb-over">
        <svg class="icon"><use href="#i-zap"/></svg>
        <span>{{ __('ui.wt_cbz_overshoot') }}</span>
      </div>
    </div>
  </div>

  <div class="cbz-sec">{{ __('ui.wt_cbz_preview') }}</div>

  <div class="cbz-lanes">
    <div class="cbz-lane">
      <span>{{ __('ui.wt_cbz_lane_move') }}</span>
      <div class="cbz-track" id="cb-track">
        <span class="cbz-tick" id="cb-tick"></span>
        <span class="cbz-mv" id="cb-mv"></span>
        <span class="cbz-ring" id="cb-ring"></span>
      </div>
    </div>
    <div class="cbz-lane">
      <span>{{ __('ui.wt_cbz_lane_bar') }}</span>
      <div class="cbz-barwrap"><div class="cbz-bar" id="cb-bar"></div></div>
    </div>
    <div class="cbz-lane">
      <span>{{ __('ui.wt_cbz_lane_fade') }}</span>
      <div class="cbz-fadewrap"><div class="cbz-fade" id="cb-fade"></div></div>
    </div>
  </div>

  <div class="cbz-legend">
    <span><i class="a"></i>{{ __('ui.wt_cbz_leg_js') }}</span>
    <span><i class="b"></i>{{ __('ui.wt_cbz_leg_css') }}</span>
    <span><i class="c"></i>{{ __('ui.wt_cbz_leg_lin') }}</span>
  </div>

  <div class="cbz-prow">
    <label class="wt-range">{{ __('ui.wt_cbz_duration') }}<input type="range" id="cb-dur" min="150" max="3000" step="50" value="900"><b id="cb-durn" dir="ltr">900</b></label>
    <button type="button" class="btn btn-glass" id="cb-play" data-play="{{ __('ui.wt_cbz_play') }}" data-pause="{{ __('ui.wt_cbz_pause') }}">{{ __('ui.wt_cbz_pause') }}</button>
    <span class="cbz-read" id="cb-live" dir="ltr"></span>
  </div>

  <div class="cbz-sec">{{ __('ui.wt_cbz_sampler') }}</div>
  <div class="cbz-prow" style="margin-top:0">
    <label class="wt-range">{{ __('ui.wt_cbz_progress') }}<input type="range" id="cb-sx" min="0" max="1" step="0.001" value="0.5"></label>
    <span class="cbz-read" id="cb-sout" dir="ltr"></span>
  </div>

  <div class="wt-two" style="margin-top:22px">
    <div class="wt-pane">
      <label>{{ __('ui.wt_cbz_value') }}</label>
      <input type="text" id="cb-val" class="cbz-txt" readonly dir="ltr">
      <div class="wt-bar">
        <button type="button" class="btn btn-glass" id="cb-cv" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
      </div>
    </div>
    <div class="wt-pane">
      <label>{{ __('ui.wt_cbz_css') }}</label>
      <textarea id="cb-css" class="wt-ta" rows="4" readonly dir="ltr"></textarea>
      <div class="wt-bar">
        <button type="button" class="btn btn-glass" id="cb-cc" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var svg = $('cb-svg'), msg = $('cb-msg');

  var PRESETS = {
    'linear': [0, 0, 1, 1],
    'ease': [0.25, 0.1, 0.25, 1],
    'ease-in': [0.42, 0, 1, 1],
    'ease-out': [0, 0, 0.58, 1],
    'ease-in-out': [0.42, 0, 0.58, 1],
    'easeInSine': [0.12, 0, 0.39, 0],
    'easeOutSine': [0.61, 1, 0.88, 1],
    'easeInOutSine': [0.37, 0, 0.63, 1],
    'easeInQuad': [0.11, 0, 0.5, 0],
    'easeOutQuad': [0.5, 1, 0.89, 1],
    'easeInOutQuad': [0.45, 0, 0.55, 1],
    'easeInCubic': [0.32, 0, 0.67, 0],
    'easeOutCubic': [0.33, 1, 0.68, 1],
    'easeInOutCubic': [0.65, 0, 0.35, 1],
    'easeInQuart': [0.5, 0, 0.75, 0],
    'easeOutQuart': [0.25, 1, 0.5, 1],
    'easeInOutQuart': [0.76, 0, 0.24, 1],
    'easeInQuint': [0.64, 0, 0.78, 0],
    'easeOutQuint': [0.22, 1, 0.36, 1],
    'easeInOutQuint': [0.83, 0, 0.17, 1],
    'easeInExpo': [0.7, 0, 0.84, 0],
    'easeOutExpo': [0.16, 1, 0.3, 1],
    'easeInOutExpo': [0.87, 0, 0.13, 1],
    'easeInCirc': [0.55, 0, 1, 0.45],
    'easeOutCirc': [0, 0.55, 0.45, 1],
    'easeInOutCirc': [0.85, 0, 0.15, 1],
    'easeInBack': [0.36, 0, 0.66, -0.56],
    'easeOutBack': [0.34, 1.56, 0.64, 1],
    'easeInOutBack': [0.68, -0.6, 0.32, 1.6],
    'material-standard': [0.4, 0, 0.2, 1],
    'material-decelerate': [0, 0, 0.2, 1],
    'material-accelerate': [0.4, 0, 1, 1],
    'material-sharp': [0.4, 0, 0.6, 1],
    'swift-out': [0.55, 0, 0.1, 1]
  };

  var s = { x1: 0.25, y1: 0.1, x2: 0.25, y2: 1 };
  var Y_MIN = -1.5, Y_MAX = 2.5;
  var cssValue = 'cubic-bezier(0.25, 0.1, 0.25, 1)';
  var clamp = function (v, a, b) { return v < a ? a : (v > b ? b : v); };

  /* ---------- bezier maths ----------
     A CSS timing function is a cubic bezier with P0 (0,0) and P3 (1,1).
     Both axes reduce to a cubic polynomial in t: at^3 + bt^2 + ct.        */
  function coef(a, b) {
    var c = 3 * a, bb = 3 * (b - a) - c, aa = 1 - c - bb;
    return [aa, bb, c];
  }
  function bez(t, c) { return ((c[0] * t + c[1]) * t + c[2]) * t; }
  function dbez(t, c) { return (3 * c[0] * t + 2 * c[1]) * t + c[2]; }

  /* x(t) is monotonic while x1,x2 stay in 0..1, so we can invert it:
     Newton-Raphson first, bisection as the guaranteed fallback.          */
  function solveT(x, cx) {
    if (!(x > 0)) return 0;
    if (x >= 1) return 1;
    var t = x, i, e, d, v;
    for (i = 0; i < 8; i++) {
      e = bez(t, cx) - x;
      if (Math.abs(e) < 1e-9) return t;
      d = dbez(t, cx);
      if (Math.abs(d) < 1e-9) break;
      t = t - e / d;
    }
    var lo = 0, hi = 1;
    t = clamp(x, 0, 1);
    for (i = 0; i < 64; i++) {
      v = bez(t, cx);
      if (Math.abs(v - x) < 1e-9) return t;
      if (v > x) { hi = t; } else { lo = t; }
      t = (lo + hi) / 2;
    }
    return t;
  }

  var CX = coef(s.x1, s.x2), CY = coef(s.y1, s.y2);
  function recoef() { CX = coef(s.x1, s.x2); CY = coef(s.y1, s.y2); }
  function ease(x) { return bez(solveT(x, CX), CY); }

  /* ---------- formatting ---------- */
  function fm(n) {
    var r = Math.round(n * 10000) / 10000;
    if (r === 0) r = 0;
    var out = r.toFixed(4);
    while (out.charAt(out.length - 1) === '0') { out = out.slice(0, -1); }
    if (out.charAt(out.length - 1) === '.') { out = out.slice(0, -1); }
    return out;
  }
  function f3(n) { return (Math.round(n * 1000) / 1000).toFixed(3); }
  function value() {
    return 'cubic-bezier(' + fm(s.x1) + ', ' + fm(s.y1) + ', ' + fm(s.x2) + ', ' + fm(s.y2) + ')';
  }
  var NL = String.fromCharCode(10);
  function cssBlock(v) {
    var d = +$('cb-dur').value;
    return 'transition-timing-function: ' + v + ';' + NL +
           'animation-timing-function: ' + v + ';' + NL +
           'transition: all ' + d + 'ms ' + v + ';' + NL +
           '--ease-custom: ' + v + ';';
  }

  /* ---------- svg geometry ---------- */
  function sx(x) { return x * 100; }
  function sy(y) { return (1 - y) * 100; }
  var A = function (el, k, v) { el.setAttribute(k, v); };

  function viewBox() {
    var top = Math.max(1, s.y1, s.y2) + 0.09;
    var bot = Math.min(0, s.y1, s.y2) - 0.09;
    A(svg, 'viewBox', '0 ' + f3(sy(top)) + ' 100 ' + f3((top - bot) * 100));
  }
  function scale() {
    var m = svg.getScreenCTM ? svg.getScreenCTM() : null;
    return (m && m.a > 0) ? m.a : 3;
  }
  function toUser(cx, cy) {
    var m = svg.getScreenCTM ? svg.getScreenCTM() : null;
    if (!m) return null;
    var p;
    if (window.DOMPoint && DOMPoint.prototype.matrixTransform) {
      p = new DOMPoint(cx, cy).matrixTransform(m.inverse());
    } else {
      p = svg.createSVGPoint(); p.x = cx; p.y = cy; p = p.matrixTransform(m.inverse());
    }
    return { x: p.x / 100, y: 1 - p.y / 100 };
  }

  (function grid() {
    var g = '', i, v;
    for (i = 1; i < 4; i++) {
      v = i * 25;
      g += '<line x1="' + v + '" y1="0" x2="' + v + '" y2="100"/>';
      g += '<line x1="0" y1="' + v + '" x2="100" y2="' + v + '"/>';
    }
    $('cb-grid').innerHTML = g;
  })();

  function drawCurve() {
    viewBox();
    var k = scale();
    var hr = Math.min(7, 9 / k), rr = Math.min(4, 5.5 / k), sr = Math.min(4.5, 6 / k), er = Math.min(1.8, 2.4 / k);

    A($('cb-curve'), 'd', 'M 0 100 C ' + f3(sx(s.x1)) + ' ' + f3(sy(s.y1)) + ', ' +
      f3(sx(s.x2)) + ' ' + f3(sy(s.y2)) + ', 100 0');

    var l1 = $('cb-l1');
    A(l1, 'x1', '0'); A(l1, 'y1', '100'); A(l1, 'x2', f3(sx(s.x1))); A(l1, 'y2', f3(sy(s.y1)));
    var l2 = $('cb-l2');
    A(l2, 'x1', '100'); A(l2, 'y1', '0'); A(l2, 'x2', f3(sx(s.x2))); A(l2, 'y2', f3(sy(s.y2)));

    var h1 = $('cb-h1');
    A(h1, 'cx', f3(sx(s.x1))); A(h1, 'cy', f3(sy(s.y1))); A(h1, 'r', f3(hr));
    var h2 = $('cb-h2');
    A(h2, 'cx', f3(sx(s.x2))); A(h2, 'cy', f3(sy(s.y2))); A(h2, 'r', f3(hr));

    A($('cb-run'), 'r', f3(rr));
    A($('cb-smp'), 'r', f3(sr));
    A($('cb-e0'), 'r', f3(er));
    A($('cb-e1'), 'r', f3(er));

    $('cb-over').classList.toggle('on', s.y1 < 0 || s.y1 > 1 || s.y2 < 0 || s.y2 > 1);
  }

  function matchPreset() {
    var k, p, hit = '';
    for (k in PRESETS) {
      p = PRESETS[k];
      if (Math.abs(p[0] - s.x1) < 1e-9 && Math.abs(p[1] - s.y1) < 1e-9 &&
          Math.abs(p[2] - s.x2) < 1e-9 && Math.abs(p[3] - s.y2) < 1e-9) { hit = k; break; }
    }
    $('cb-preset').value = hit;
  }

  function syncOut(fields) {
    var v = value();
    cssValue = v;
    $('cb-val').value = v;
    $('cb-css').value = cssBlock(v);
    if (fields) {
      $('cb-n1').value = fm(s.x1);
      $('cb-n2').value = fm(s.y1);
      $('cb-n3').value = fm(s.x2);
      $('cb-n4').value = fm(s.y2);
    }
    matchPreset();
  }

  function sampler() {
    var x = +$('cb-sx').value, y = ease(x);
    $('cb-sout').textContent = 'f(' + f3(x) + ') = ' + fm(y);
    A($('cb-smp'), 'cx', f3(sx(x)));
    A($('cb-smp'), 'cy', f3(sy(y)));
  }

  /* fields=false while typing in a number box, so the caret is not stolen */
  function render(fields) {
    recoef();
    drawCurve();
    syncOut(fields !== false);
    sampler();
    if (!drag) restart();
  }

  function setState(a, b, c, d) {
    s.x1 = clamp(a, 0, 1);
    s.y1 = clamp(b, Y_MIN, Y_MAX);
    s.x2 = clamp(c, 0, 1);
    s.y2 = clamp(d, Y_MIN, Y_MAX);
    render(true);
  }

  /* ---------- dragging ---------- */
  var drag = 0;

  function moveTo(which, p) {
    var x = Math.round(clamp(p.x, 0, 1) * 1000) / 1000;
    var y = Math.round(clamp(p.y, Y_MIN, Y_MAX) * 1000) / 1000;
    if (which === 1) { s.x1 = x; s.y1 = y; } else { s.x2 = x; s.y2 = y; }
    render(true);
  }
  function onMove(e) {
    if (!drag) return;
    e.preventDefault();
    var p = toUser(e.clientX, e.clientY);
    if (p) moveTo(drag, p);
  }
  function onUp() {
    drag = 0;
    document.removeEventListener('pointermove', onMove);
    document.removeEventListener('pointerup', onUp);
    restart();
  }
  svg.addEventListener('pointerdown', function (e) {
    var p = toUser(e.clientX, e.clientY);
    if (!p) return;
    e.preventDefault();
    if (e.target === $('cb-h1')) drag = 1;
    else if (e.target === $('cb-h2')) drag = 2;
    else drag = Math.hypot(p.x - s.x1, p.y - s.y1) <= Math.hypot(p.x - s.x2, p.y - s.y2) ? 1 : 2;
    (drag === 1 ? $('cb-h1') : $('cb-h2')).focus();
    moveTo(drag, p);
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', onUp);
  });

  function keyNudge(which, e) {
    var step = e.shiftKey ? 0.1 : 0.01, dx = 0, dy = 0;
    if (e.key === 'ArrowLeft') dx = -step;
    else if (e.key === 'ArrowRight') dx = step;
    else if (e.key === 'ArrowUp') dy = step;
    else if (e.key === 'ArrowDown') dy = -step;
    else return;
    e.preventDefault();
    moveTo(which, {
      x: (which === 1 ? s.x1 : s.x2) + dx,
      y: (which === 1 ? s.y1 : s.y2) + dy
    });
  }
  $('cb-h1').addEventListener('keydown', function (e) { keyNudge(1, e); });
  $('cb-h2').addEventListener('keydown', function (e) { keyNudge(2, e); });

  /* ---------- numeric fields ---------- */
  [['cb-n1', 'x1'], ['cb-n2', 'y1'], ['cb-n3', 'x2'], ['cb-n4', 'y2']].forEach(function (pair) {
    var el = $(pair[0]);
    el.addEventListener('input', function () {
      var v = parseFloat(el.value);
      if (!isFinite(v)) return;
      s[pair[1]] = (pair[1] === 'x1' || pair[1] === 'x2') ? clamp(v, 0, 1) : clamp(v, Y_MIN, Y_MAX);
      render(false);
    });
    el.addEventListener('blur', function () { render(true); });
  });

  /* ---------- presets ---------- */
  $('cb-preset').addEventListener('change', function () {
    var p = PRESETS[this.value];
    if (p) { setState(p[0], p[1], p[2], p[3]); say(''); }
  });

  /* ---------- import ---------- */
  function say(key) {
    msg.textContent = key ? (msg.getAttribute('data-' + key) || '') : '';
    msg.classList.toggle('err', key === 'range' || key === 'parse');
  }
  function parseInput(raw) {
    var t = String(raw).trim().toLowerCase();
    if (!t) return { err: 'parse' };
    if (PRESETS[t]) return { v: PRESETS[t].slice() };
    var nums = t.replace(/cubic-bezier/g, '').match(/[-+]?[0-9]*[.]?[0-9]+/g);
    if (!nums || nums.length !== 4) return { err: 'parse' };
    var v = nums.map(parseFloat), i;
    for (i = 0; i < 4; i++) { if (!isFinite(v[i])) return { err: 'parse' }; }
    if (v[0] < 0 || v[0] > 1 || v[2] < 0 || v[2] > 1) return { err: 'range' };
    return { v: v };
  }
  function doImport() {
    var r = parseInput($('cb-imp').value);
    if (r.err) { say(r.err); return; }
    setState(r.v[0], r.v[1], r.v[2], r.v[3]);
    say('ok');
  }
  $('cb-apply').addEventListener('click', doImport);
  $('cb-imp').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); doImport(); }
  });

  /* reversing an easing mirrors both control points through the centre */
  $('cb-rev').addEventListener('click', function () {
    setState(1 - s.x2, 1 - s.y2, 1 - s.x1, 1 - s.y1);
    say('');
  });
  $('cb-reset').addEventListener('click', function () {
    $('cb-imp').value = '';
    setState(0.25, 0.1, 0.25, 1);
    say('');
  });

  /* ---------- preview ---------- */
  var track = $('cb-track'), mv = $('cb-mv'), ring = $('cb-ring'), tick = $('cb-tick');
  var bar = $('cb-bar'), fade = $('cb-fade'), live = $('cb-live');
  var travel = 200, playing = true, lastP = 0, raf = 0, t0 = 0, lastEl = -1;
  var PAUSE = 480;

  function measure() {
    travel = Math.max(40, (track.clientWidth || 260) - 26);
  }
  measure();
  if (window.ResizeObserver) {
    new ResizeObserver(measure).observe(track);
    new ResizeObserver(function () { drawCurve(); }).observe($('cb-stage'));
  }
  window.addEventListener('resize', function () { measure(); drawCurve(); });

  function paint(p, v) {
    lastP = p;
    mv.style.transform = 'translateX(' + (v * travel).toFixed(2) + 'px)';
    tick.style.transform = 'translateX(' + (p * travel).toFixed(2) + 'px)';
    bar.style.width = (clamp(v, 0, 1.4) * 100).toFixed(2) + '%';
    fade.style.opacity = clamp(v, 0, 1).toFixed(3);
    fade.style.transform = 'scale(' + (0.35 + 0.65 * clamp(v, 0, 1.35)).toFixed(3) + ')';
    live.textContent = 'x ' + f3(p) + '  ->  y ' + fm(v);
    A($('cb-run'), 'cx', f3(sx(p)));
    A($('cb-run'), 'cy', f3(sy(v)));
    var gv = $('cb-gv');
    A(gv, 'x1', f3(sx(p))); A(gv, 'y1', '100'); A(gv, 'x2', f3(sx(p))); A(gv, 'y2', f3(sy(v)));
    var gh = $('cb-gh');
    A(gh, 'x1', '0'); A(gh, 'y1', f3(sy(v))); A(gh, 'x2', f3(sx(p))); A(gh, 'y2', f3(sy(v)));
  }

  /* the hollow ring is animated by the browser's own CSS engine using the
     generated value - if our sampling is correct it tracks the filled dot */
  function cssRun(dur) {
    measure();
    ring.style.transition = 'none';
    ring.style.transform = 'translateX(0px)';
    void ring.offsetWidth;
    ring.style.transition = 'transform ' + dur + 'ms ' + cssValue;
    ring.style.transform = 'translateX(' + travel.toFixed(2) + 'px)';
  }

  function frame(now) {
    raf = 0;
    if (!playing) return;
    measure();
    var dur = +$('cb-dur').value;
    var el = (now - t0) % (dur + PAUSE);
    if (el < lastEl || lastEl < 0) cssRun(dur);
    lastEl = el;
    var p = clamp(el / dur, 0, 1);
    paint(p, ease(p));
    raf = requestAnimationFrame(frame);
  }

  function restart() {
    if (!playing) { paint(lastP, ease(lastP)); return; }
    t0 = (window.performance && performance.now) ? performance.now() : Date.now();
    lastEl = -1;
    if (!raf) raf = requestAnimationFrame(frame);
  }

  $('cb-play').addEventListener('click', function () {
    playing = !playing;
    this.textContent = playing ? this.getAttribute('data-pause') : this.getAttribute('data-play');
    if (playing) { restart(); }
    else {
      if (raf) { cancelAnimationFrame(raf); raf = 0; }
      ring.style.transition = 'none';
      ring.style.transform = 'translateX(' + (ease(lastP) * travel).toFixed(2) + 'px)';
    }
  });

  $('cb-dur').addEventListener('input', function () {
    $('cb-durn').textContent = this.value;
    syncOut(false);
    restart();
  });

  $('cb-sx').addEventListener('input', sampler);
  $('cb-cv').addEventListener('click', function (e) { wtCopy(e.currentTarget, $('cb-val').value); });
  $('cb-cc').addEventListener('click', function (e) { wtCopy(e.currentTarget, $('cb-css').value); });

  $('cb-durn').textContent = $('cb-dur').value;
  render(true);
  requestAnimationFrame(function () { measure(); drawCurve(); });
})();
</script>
