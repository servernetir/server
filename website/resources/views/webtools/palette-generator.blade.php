@php
  $pgSchemes = [
      'comp'   => __('ui.wt_pg_s_comp'),
      'analog' => __('ui.wt_pg_s_analog'),
      'triad'  => __('ui.wt_pg_s_triad'),
      'split'  => __('ui.wt_pg_s_split'),
      'tetrad' => __('ui.wt_pg_s_tetrad'),
      'mono'   => __('ui.wt_pg_s_mono'),
  ];
@endphp

<style>
  .pg-sub-line{margin-top:9px;font-size:12.5px;color:var(--dim);font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
  .pg-hd{font-size:12.5px;font-weight:600;color:var(--dim);margin:20px 0 10px}
  .pg-schemes{display:flex;flex-wrap:wrap;gap:8px}
  .pg-sch{font-family:var(--font-body);font-size:13px;padding:8px 15px;border-radius:10px;border:1px solid var(--line-2);background:var(--surface-2);color:var(--muted);cursor:pointer;transition:border-color .15s,color .15s,background .15s}
  .pg-sch:hover{border-color:var(--cyan);color:var(--text)}
  .pg-sch.pg-on{background:linear-gradient(135deg,rgba(34,211,238,.2),rgba(139,92,246,.2));border-color:var(--cyan);color:var(--text);font-weight:600}

  .pg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(142px,1fr));gap:12px;margin-top:16px}
  .pg-sw{border:1px solid var(--line);border-radius:14px;overflow:hidden;background:var(--surface-2);transition:border-color .15s}
  .pg-sw.pg-locked{border-color:var(--cyan)}
  .pg-wrapchip{position:relative}
  .pg-chip{display:block;width:100%;height:98px;border:0;padding:0;cursor:pointer;font-family:var(--font-disp);font-size:20px;font-weight:700;letter-spacing:.5px;opacity:1}
  .pg-chip:hover{filter:brightness(1.06)}
  .pg-badge{position:absolute;inset-block-start:8px;inset-inline-start:8px;font-style:normal;font-size:10.5px;line-height:1;padding:4px 7px;border-radius:99px;border:1px solid currentColor;opacity:.72;pointer-events:none;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
  .pg-lock{position:absolute;inset-block-start:6px;inset-inline-end:6px;width:29px;height:29px;border-radius:9px;border:1px solid currentColor;background:transparent;display:grid;place-items:center;cursor:pointer;opacity:.5;transition:opacity .15s}
  .pg-lock:hover{opacity:1}
  .pg-lock .icon{width:14px;height:14px}
  .pg-sw.pg-locked .pg-lock{opacity:1}
  .pg-meta{padding:9px 11px;display:flex;flex-direction:column;gap:3px}
  .pg-hex{font-size:13.5px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--text);letter-spacing:.4px}
  .pg-hsl{font-size:11.5px;color:var(--dim);font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
  .pg-wrapchip::after{content:'✓';position:absolute;inset-block-start:50%;inset-inline-start:50%;transform:translate(-50%,-50%) scale(.5);width:34px;height:34px;border-radius:50%;background:var(--green);color:#04110b;font-size:18px;font-weight:700;display:grid;place-items:center;opacity:0;transition:.16s;pointer-events:none}
  .pg-sw.pg-copied .pg-wrapchip::after{opacity:1;transform:translate(-50%,-50%) scale(1)}

  .pg-lab{display:inline-flex;align-items:center;gap:8px;font-size:13.5px;color:var(--muted)}
  .pg-inp{background:var(--surface-2);border:1px solid var(--line-2);border-radius:9px;color:var(--text);padding:6px 10px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;width:110px;outline:none}
  .pg-inp:focus{border-color:var(--cyan)}

  .pg-all{display:flex;flex-direction:column;gap:8px}
  .pg-row{display:grid;grid-template-columns:minmax(130px,168px) 1fr;gap:14px;align-items:center;background:var(--surface-2);border:1px solid var(--line);border-radius:12px;padding:9px 12px;cursor:pointer;text-align:start;font-family:var(--font-body);transition:border-color .15s}
  .pg-row:hover{border-color:var(--cyan)}
  .pg-row.pg-on{border-color:var(--cyan);background:linear-gradient(135deg,rgba(34,211,238,.09),rgba(139,92,246,.09))}
  .pg-rl{font-size:13px;color:var(--text)}
  .pg-rl i{display:block;font-style:normal;font-size:11px;color:var(--dim);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;margin-top:3px}
  .pg-chips{display:flex;height:36px;border-radius:9px;overflow:hidden;border:1px solid var(--line-2)}
  .pg-chips i{flex:1;display:block}
  @media(max-width:560px){.pg-row{grid-template-columns:1fr}}
</style>

<div class="wt-two">
  <div class="wt-pane">
    <label>{{ __('ui.wt_pg_base') }}</label>
    <input type="color" id="pg-pick" class="wt-color" value="#22d3ee">
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_pg_hex') }}</label>
    <input type="text" id="pg-hex" class="wt-input-lg" dir="ltr" spellcheck="false" autocomplete="off" value="#22D3EE">
    <div class="pg-sub-line" id="pg-baseinfo" dir="ltr"></div>
  </div>
</div>

<div class="wt-fields">
  <label class="wt-range">{{ __('ui.wt_pg_hue') }} <b id="pg-hv" dir="ltr">188</b>
    <input type="range" id="pg-h" min="0" max="359" step="1" value="188">
  </label>
  <label class="wt-range">{{ __('ui.wt_pg_sat') }} <b id="pg-sv" dir="ltr">86</b>
    <input type="range" id="pg-s" min="0" max="100" step="1" value="86">
  </label>
  <label class="wt-range">{{ __('ui.wt_pg_light') }} <b id="pg-lv" dir="ltr">53</b>
    <input type="range" id="pg-l" min="0" max="100" step="1" value="53">
  </label>
  <button type="button" class="btn btn-glass" id="pg-rand">
    <svg class="icon"><use href="#i-zap"/></svg> {{ __('ui.wt_pg_random') }}
  </button>
</div>

<div class="wt-status err" id="pg-err" style="display:none;margin-top:10px"></div>

<div class="pg-hd">{{ __('ui.wt_pg_scheme') }}</div>
<div class="pg-schemes" id="pg-schemes">
  @foreach ($pgSchemes as $pgKey => $pgLabel)
    <button type="button" class="pg-sch" data-k="{{ $pgKey }}">{{ $pgLabel }}</button>
  @endforeach
</div>

<div class="wt-fields" id="pg-anglerow" style="display:none">
  <label class="wt-range">{{ __('ui.wt_pg_angle') }} <b id="pg-av" dir="ltr">30</b>
    <input type="range" id="pg-a" min="10" max="90" step="1" value="30">
  </label>
</div>

<div class="pg-grid" id="pg-grid"></div>
<div class="wt-status" id="pg-hint" style="margin-top:12px">{{ __('ui.wt_pg_copyhint') }}</div>

<div class="wt-fields">
  <label class="pg-lab">{{ __('ui.wt_pg_format') }}
    <select id="pg-fmt" class="wt-select">
      <option value="css">CSS</option>
      <option value="scss">SCSS</option>
      <option value="json">JSON</option>
    </select>
  </label>
  <label class="pg-lab">{{ __('ui.wt_pg_prefix') }}
    <input type="text" id="pg-prefix" class="pg-inp" dir="ltr" spellcheck="false" autocomplete="off" maxlength="24" value="brand">
  </label>
</div>

<div class="wt-pane" style="margin-top:14px">
  <label>{{ __('ui.wt_pg_export') }}</label>
  <textarea id="pg-out" class="wt-ta" rows="9" readonly dir="ltr"></textarea>
</div>
<div class="wt-bar">
  <button type="button" class="btn btn-primary" id="pg-copy" data-done="{{ __('ui.wt_copied') }}">
    <svg class="icon"><use href="#i-code"/></svg> {{ __('ui.wt_copy') }}
  </button>
</div>

<div class="pg-hd">{{ __('ui.wt_pg_all') }}</div>
<div class="pg-all" id="pg-all"></div>
<div class="wt-status" style="margin-top:10px">{{ __('ui.wt_pg_allhint') }}</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var NL  = String.fromCharCode(10);
  var DEG = String.fromCharCode(176);
  var SCH = @json($pgSchemes);
  var T = {
    lock:   @json(__('ui.wt_pg_lock')),
    unlock: @json(__('ui.wt_pg_unlock')),
    bad:    @json(__('ui.wt_pg_bad')),
    copyone:@json(__('ui.wt_pg_copyone')),
    spec:   @json(__('ui.wt_pg_specimen'))
  };

  var KEYS = [];
  for (var kk in SCH) { if (Object.prototype.hasOwnProperty.call(SCH, kk)) KEYS.push(kk); }

  var DEF_ANGLE = { analog: 30, split: 30, tetrad: 90 };

  // base colour #22D3EE expressed in HSL
  var st = { h: 187.941176, s: 85.714286, l: 53.333333, scheme: 'comp', angle: 30, locks: {} };

  /* ---------- colour maths ---------- */
  function clampN(n, a, b) { return n < a ? a : (n > b ? b : n); }
  function norm(h) { return ((h % 360) + 360) % 360; }

  // chroma form of HSL -> RGB (CSS Color 4). Kept in sixths rather than
  // thirds-of-a-turn so hsl(30,100%,50%) lands on 128 like a browser, not 127.
  function hsl2rgb(h, s, l) {                 // h in degrees, s/l in 0..1
    var c = (1 - Math.abs(2 * l - 1)) * s;
    var hp = norm(h) / 60;
    var x = c * (1 - Math.abs((hp % 2) - 1));
    var m = l - c / 2;
    var r = 0, g = 0, b = 0;
    if (hp < 1)      { r = c; g = x; }
    else if (hp < 2) { r = x; g = c; }
    else if (hp < 3) { g = c; b = x; }
    else if (hp < 4) { g = x; b = c; }
    else if (hp < 5) { r = x; b = c; }
    else             { r = c; b = x; }
    return [(r + m) * 255, (g + m) * 255, (b + m) * 255];
  }

  function rgb2hsl(r, g, b) {                 // -> [h 0..360, s 0..100, l 0..100]
    r /= 255; g /= 255; b /= 255;
    var mx = Math.max(r, g, b), mn = Math.min(r, g, b), l = (mx + mn) / 2;
    if (mx === mn) return [0, 0, l * 100];
    var d = mx - mn;
    var s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn);
    var h;
    if (mx === r)      h = (g - b) / d + (g < b ? 6 : 0);
    else if (mx === g) h = (b - r) / d + 2;
    else               h = (r - g) / d + 4;
    return [h * 60, s * 100, l * 100];
  }

  function h2(n) { return clampN(Math.round(n), 0, 255).toString(16).toUpperCase().padStart(2, '0'); }
  function toHex(rgb) { return '#' + h2(rgb[0]) + h2(rgb[1]) + h2(rgb[2]); }

  function parseHex(str) {                    // no regex: a stray backslash would break one
    var s = String(str).trim().toLowerCase();
    if (s.charAt(0) === '#') s = s.slice(1);
    var ok = '0123456789abcdef', i;
    for (i = 0; i < s.length; i++) if (ok.indexOf(s.charAt(i)) < 0) return null;
    if (s.length === 3) s = s.charAt(0) + s.charAt(0) + s.charAt(1) + s.charAt(1) + s.charAt(2) + s.charAt(2);
    if (s.length !== 6) return null;
    return [parseInt(s.slice(0, 2), 16), parseInt(s.slice(2, 4), 16), parseInt(s.slice(4, 6), 16)];
  }

  function lum(rgb) {                          // WCAG relative luminance
    var a = [rgb[0], rgb[1], rgb[2]].map(function (v) {
      v = clampN(Math.round(v), 0, 255) / 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
  }
  function readable(rgb) { return lum(rgb) > 0.179 ? '#000000' : '#FFFFFF'; }

  /* ---------- scheme geometry ---------- */
  function offsetsFor(k, a) {
    if (k === 'comp')   return [0, 180];
    if (k === 'analog') return [-a, 0, a];
    if (k === 'triad')  return [0, 120, 240];
    if (k === 'split')  return [0, 180 - a, 180 + a];
    if (k === 'tetrad') return [0, a, 180, 180 + a];
    return [0, 0, 0, 0, 0];                     // mono: hue fixed, lightness steps
  }
  // Five lightness stops 15 points apart. The whole 60-point window is slid into
  // 6..94 rather than clamping each stop, so a near-white base still yields five
  // distinct tints instead of three identical ones.
  function monoL(i, l) { return clampN(l - 30, 6, 34) + i * 15; }

  function buildPal(k, a, useLocks) {
    var offs = offsetsFor(k, a), out = [], i;
    for (i = 0; i < offs.length; i++) {
      if (useLocks && st.locks[i]) {
        var lrgb = parseHex(st.locks[i]);
        out.push({ hex: st.locks[i], rgb: lrgb, hsl: rgb2hsl(lrgb[0], lrgb[1], lrgb[2]), off: offs[i], locked: true });
      } else {
        var h = norm(st.h + offs[i]);
        var l = (k === 'mono') ? monoL(i, st.l) : st.l;
        var rgb = hsl2rgb(h, st.s / 100, l / 100);
        out.push({ hex: toHex(rgb), rgb: rgb, hsl: [h, st.s, l], off: offs[i], locked: false });
      }
    }
    return out;
  }

  /* ---------- formatting ---------- */
  function hslText(c) {
    return 'hsl(' + Math.round(norm(c.hsl[0])) + ', ' + Math.round(c.hsl[1]) + '%, ' + Math.round(c.hsl[2]) + '%)';
  }
  function badgeText(k, c, i) {
    if (c.locked) return '';
    if (k === 'mono') return 'L ' + Math.round(c.hsl[2]) + '%';
    var o = Math.round(c.off);
    return (o > 0 ? '+' : '') + o + DEG;
  }
  function geometryText(k, a) {
    var offs = offsetsFor(k, a), i, parts = [];
    if (k === 'mono') return 'L ' + Math.round(monoL(0, st.l)) + '% - ' + Math.round(monoL(4, st.l)) + '%';
    for (i = 0; i < offs.length; i++) parts.push(Math.round(norm(offs[i])) + DEG);
    return parts.join(' / ');
  }
  function cleanPrefix(v) {
    var s = String(v).toLowerCase(), ok = 'abcdefghijklmnopqrstuvwxyz0123456789-', out = '', i, ch;
    for (i = 0; i < s.length; i++) { ch = s.charAt(i); if (ok.indexOf(ch) >= 0) out += ch; }
    while (out.charAt(0) === '-') out = out.slice(1);
    return out;
  }

  function exportText(pal) {
    var p = cleanPrefix($('pg-prefix').value) || 'color';
    var f = $('pg-fmt').value, out = '', i;
    var head = SCH[st.scheme] + ' - ' + baseHex() + ' - ' + geometryText(st.scheme, st.angle);
    if (f === 'css') {
      out = '/* ' + head + ' */' + NL + ':root {' + NL;
      for (i = 0; i < pal.length; i++) out += '  --' + p + '-' + (i + 1) + ': ' + pal[i].hex + ';' + NL;
      out += '}';
      return out;
    }
    if (f === 'scss') {
      out = '// ' + head + NL;
      for (i = 0; i < pal.length; i++) out += '$' + p + '-' + (i + 1) + ': ' + pal[i].hex + ';' + NL;
      return out.slice(0, out.length - 1);
    }
    out = '{' + NL;
    for (i = 0; i < pal.length; i++) {
      out += '  "' + p + '-' + (i + 1) + '": "' + pal[i].hex + '"' + (i < pal.length - 1 ? ',' : '') + NL;
    }
    return out + '}';
  }

  /* ---------- copy helper (swatch clicks) ---------- */
  function copyText(text, done) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text, done); });
    } else { fallbackCopy(text, done); }
  }
  function fallbackCopy(text, done) {
    try {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select(); document.execCommand('copy');
      document.body.removeChild(ta); if (done) done();
    } catch (e) { /* clipboard unavailable */ }
  }

  /* ---------- render ---------- */
  function baseHex() { return toHex(hsl2rgb(st.h, st.s / 100, st.l / 100)); }

  function syncInputs(skipHexField) {
    var bh = baseHex();
    $('pg-pick').value = bh.toLowerCase();
    if (!skipHexField) $('pg-hex').value = bh;
    $('pg-h').value = Math.round(norm(st.h)) % 360;
    $('pg-s').value = Math.round(st.s);
    $('pg-l').value = Math.round(st.l);
    $('pg-hv').textContent = Math.round(norm(st.h)) % 360;
    $('pg-sv').textContent = Math.round(st.s);
    $('pg-lv').textContent = Math.round(st.l);
    $('pg-av').textContent = st.angle;
    $('pg-a').value = st.angle;
    $('pg-baseinfo').textContent = bh + '  ' + 'hsl(' + (Math.round(norm(st.h)) % 360) + ', '
      + Math.round(st.s) + '%, ' + Math.round(st.l) + '%)';
  }

  function renderPalette() {
    var pal = buildPal(st.scheme, st.angle, true), html = '', i;
    for (i = 0; i < pal.length; i++) {
      var c = pal[i], txt = readable(c.rgb), bg = badgeText(st.scheme, c, i);
      html += '<div class="pg-sw' + (c.locked ? ' pg-locked' : '') + '" data-i="' + i + '">'
        + '<div class="pg-wrapchip">'
        + '<button type="button" class="pg-chip" data-act="copy" data-i="' + i + '" data-hex="' + c.hex + '"'
        + ' title="' + T.copyone + '" style="background:' + c.hex + ';color:' + txt + '">' + T.spec + '</button>'
        + (bg ? '<em class="pg-badge" dir="ltr" style="color:' + txt + '">' + bg + '</em>' : '')
        + '<button type="button" class="pg-lock" data-act="lock" data-i="' + i + '"'
        + ' title="' + (c.locked ? T.unlock : T.lock) + '" style="color:' + txt + '">'
        + '<svg class="icon"><use href="#i-lock"/></svg></button>'
        + '</div>'
        + '<div class="pg-meta">'
        + '<span class="pg-hex" dir="ltr">' + c.hex + '</span>'
        + '<span class="pg-hsl" dir="ltr">' + hslText(c) + '</span>'
        + '</div></div>';
    }
    $('pg-grid').innerHTML = html;
    $('pg-out').value = exportText(pal);
  }

  function renderAll() {
    var html = '', n, i;
    for (n = 0; n < KEYS.length; n++) {
      var k = KEYS[n];
      var a = (k === st.scheme) ? st.angle : (DEF_ANGLE[k] !== undefined ? DEF_ANGLE[k] : 0);
      var pal = buildPal(k, a, false), chips = '';
      for (i = 0; i < pal.length; i++) chips += '<i style="background:' + pal[i].hex + '"></i>';
      html += '<button type="button" class="pg-row' + (k === st.scheme ? ' pg-on' : '') + '" data-k="' + k + '">'
        + '<span class="pg-rl">' + SCH[k] + '<i dir="ltr">' + geometryText(k, a) + '</i></span>'
        + '<span class="pg-chips">' + chips + '</span></button>';
    }
    $('pg-all').innerHTML = html;
  }

  function render() {
    var showAngle = (st.scheme === 'analog' || st.scheme === 'split' || st.scheme === 'tetrad');
    $('pg-anglerow').style.display = showAngle ? 'flex' : 'none';
    var btns = $('pg-schemes').getElementsByTagName('button'), i;
    for (i = 0; i < btns.length; i++) {
      if (btns[i].getAttribute('data-k') === st.scheme) btns[i].classList.add('pg-on');
      else btns[i].classList.remove('pg-on');
    }
    renderPalette();
    renderAll();
  }

  function setBaseFromRgb(rgb) {
    var hsl = rgb2hsl(rgb[0], rgb[1], rgb[2]);
    st.h = hsl[0]; st.s = hsl[1]; st.l = hsl[2];
  }

  /* ---------- events ---------- */
  $('pg-pick').addEventListener('input', function () {
    var rgb = parseHex($('pg-pick').value);
    if (!rgb) return;
    setBaseFromRgb(rgb); $('pg-err').style.display = 'none';
    syncInputs(false); render();
  });

  $('pg-hex').addEventListener('input', function () {
    var raw = $('pg-hex').value.trim();
    if (raw.length < 3) { $('pg-err').style.display = 'none'; return; }
    var rgb = parseHex(raw);
    if (!rgb) {
      $('pg-err').textContent = T.bad; $('pg-err').style.display = 'block';
      return;
    }
    $('pg-err').style.display = 'none';
    setBaseFromRgb(rgb); syncInputs(true); render();
  });

  ['h', 's', 'l'].forEach(function (ch) {
    $('pg-' + ch).addEventListener('input', function () {
      var v = +$('pg-' + ch).value;
      if (ch === 'h') st.h = v; else if (ch === 's') st.s = v; else st.l = v;
      $('pg-err').style.display = 'none';
      syncInputs(false); render();
    });
  });

  $('pg-a').addEventListener('input', function () {
    st.angle = +$('pg-a').value;
    $('pg-av').textContent = st.angle;
    render();
  });

  $('pg-rand').addEventListener('click', function () {
    st.h = Math.floor(Math.random() * 360);
    st.s = 45 + Math.floor(Math.random() * 46);   // 45..90
    st.l = 40 + Math.floor(Math.random() * 26);   // 40..65
    $('pg-err').style.display = 'none';
    syncInputs(false); render();
  });

  $('pg-schemes').addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('button[data-k]') : null;
    if (!b) return;
    st.scheme = b.getAttribute('data-k');
    st.locks = {};                                     // slot count changes per scheme
    if (DEF_ANGLE[st.scheme] !== undefined) st.angle = DEF_ANGLE[st.scheme];
    syncInputs(false); render();
  });

  $('pg-all').addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('button[data-k]') : null;
    if (!b) return;
    st.scheme = b.getAttribute('data-k');
    st.locks = {};
    if (DEF_ANGLE[st.scheme] !== undefined) st.angle = DEF_ANGLE[st.scheme];
    syncInputs(false); render();
  });

  $('pg-grid').addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('button[data-act]') : null;
    if (!b) return;
    var i = +b.getAttribute('data-i');
    if (b.getAttribute('data-act') === 'lock') {
      if (st.locks[i]) delete st.locks[i];
      else st.locks[i] = buildPal(st.scheme, st.angle, true)[i].hex;
      render();
      return;
    }
    var card = b.parentNode.parentNode;
    copyText(b.getAttribute('data-hex'), function () {
      card.classList.add('pg-copied');
      setTimeout(function () { card.classList.remove('pg-copied'); }, 1200);
    });
  });

  $('pg-fmt').addEventListener('change', renderPalette);
  $('pg-prefix').addEventListener('input', renderPalette);
  $('pg-copy').addEventListener('click', function (e) { wtCopy(e.currentTarget, $('pg-out').value); });

  syncInputs(false);
  render();
})();
</script>
