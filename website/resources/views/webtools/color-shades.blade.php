<style>
.cs-meta{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:9px;font-size:12.5px;color:var(--dim)}
.cs-meta code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px;color:var(--muted)}
.cs-name{background:var(--surface-2);border:1px solid var(--line-2);border-radius:9px;color:var(--text);
  padding:6px 10px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;width:132px;outline:none}
.cs-name:focus{border-color:var(--cyan)}
.cs-legend{display:flex;align-items:flex-start;gap:9px;font-size:12px;line-height:1.9;color:var(--dim);margin:18px 0 11px}
.cs-legend .icon{width:14px;height:14px;color:var(--cyan);flex:none;margin-top:4px}
.cs-ramp{display:flex;flex-direction:column;gap:6px}
.cs-row{display:flex;flex-wrap:wrap;align-items:center;gap:9px 14px;background:var(--surface-2);
  border:1px solid var(--line);border-radius:12px;padding:9px 13px}
.cs-row.is-base{border-color:var(--cyan)}
.cs-chip{flex:none;width:118px;height:42px;border-radius:9px;border:1px solid var(--line-2);
  display:flex;align-items:center;justify-content:center;gap:10px;font-size:11.5px;font-weight:700;font-family:var(--font-body)}
.cs-chip .cs-aw{color:#fff}
.cs-chip .cs-ab{color:#000}
.cs-lab{flex:none;width:64px;display:flex;flex-direction:column;gap:2px}
.cs-lab b{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:14.5px;color:var(--text)}
.cs-lab i{font-style:normal;font-size:10.5px;color:var(--cyan)}
.cs-hexbtn{flex:none;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13.5px;letter-spacing:.4px;
  color:var(--text);background:var(--surface);border:1px solid var(--line-2);border-radius:8px;
  padding:6px 12px;cursor:pointer;min-width:104px;text-align:center}
.cs-hexbtn:hover{border-color:var(--cyan);color:var(--cyan)}
.cs-hexbtn.ok{background:rgba(52,211,153,.16);color:var(--green);border-color:rgba(52,211,153,.4)}
.cs-sp{flex:1 1 20px;min-width:0}
.cs-cr{flex:none;display:inline-flex;align-items:center;gap:7px;font-size:12px;color:var(--dim)}
.cs-cr b{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13.5px;color:var(--text);
  min-width:44px;text-align:start}
.cs-g{font-style:normal;font-size:10.5px;font-weight:700;border-radius:6px;padding:2px 7px;border:1px solid transparent;white-space:nowrap}
.cs-g.aaa{color:var(--green);background:rgba(52,211,153,.14);border-color:rgba(52,211,153,.32)}
.cs-g.aa{color:var(--cyan);background:rgba(34,211,238,.13);border-color:rgba(34,211,238,.3)}
.cs-g.lg{color:#fbbf24;background:rgba(251,191,36,.13);border-color:rgba(251,191,36,.3)}
.cs-g.no{color:#ff8080;background:rgba(255,107,107,.12);border-color:rgba(255,107,107,.3)}
html[data-theme="light"] .cs-g.lg{color:#8a5c04;background:rgba(251,191,36,.2);border-color:rgba(251,191,36,.5)}
html[data-theme="light"] .cs-g.no{color:#b32424;background:rgba(255,107,107,.15);border-color:rgba(255,107,107,.45)}
.cs-outs{margin-top:20px}
@media(max-width:640px){
  .cs-chip{width:96px;height:38px}
  .cs-sp{display:none}
  .cs-hexbtn{min-width:96px}
}
</style>

<div class="wt-two">
  <div class="wt-pane">
    <label for="cs-pick">{{ __('ui.wt_cs_base') }}</label>
    <input type="color" id="cs-pick" class="wt-color" value="#22d3ee">
  </div>
  <div class="wt-pane">
    <label for="cs-in">{{ __('ui.wt_cs_any') }}</label>
    <input type="text" id="cs-in" class="wt-input-lg" dir="ltr" spellcheck="false"
           placeholder="#22d3ee / rgb(34,211,238) / hsl(188,86%,53%)">
    <div class="cs-meta">
      <code id="cs-hsl" dir="ltr"></code>
      <span class="wt-status err" id="cs-err"></span>
    </div>
  </div>
</div>

<div class="wt-fields">
  <label class="wt-range" for="cs-name">{{ __('ui.wt_cs_name') }}
    <input type="text" id="cs-name" class="cs-name" dir="ltr" spellcheck="false" value="brand" maxlength="24">
  </label>
  <label class="wt-chk"><input type="checkbox" id="cs-soft"> {{ __('ui.wt_cs_soft') }}</label>
  <button type="button" class="btn btn-glass" id="cs-rand" style="padding:8px 15px;font-size:13px">{{ __('ui.wt_cs_random') }}</button>
</div>

<div class="cs-legend">
  <svg class="icon"><use href="#i-shield"/></svg>
  <span>{{ __('ui.wt_cs_legend') }}</span>
</div>

<div class="cs-ramp" id="cs-ramp"></div>

<div class="wt-io cs-outs">
  <div class="wt-pane">
    <label for="cs-css">{{ __('ui.wt_cs_css') }}</label>
    <textarea id="cs-css" class="wt-ta" rows="13" readonly dir="ltr"></textarea>
    <div class="wt-bar">
      <button type="button" class="btn btn-glass" id="cs-css-c" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
    </div>
  </div>
  <div class="wt-pane">
    <label for="cs-tw">{{ __('ui.wt_cs_tw') }}</label>
    <textarea id="cs-tw" class="wt-ta" rows="13" readonly dir="ltr"></textarea>
    <div class="wt-bar">
      <button type="button" class="btn btn-glass" id="cs-tw-c" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
    </div>
  </div>
</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };

  /* a lone backslash in a JS string is stripped by the build step — build newlines by code */
  var NL = String.fromCharCode(10);

  var L = {
    base:   @json(__('ui.wt_cs_basetag')),
    white:  @json(__('ui.wt_cs_w')),
    black:  @json(__('ui.wt_cs_b')),
    sample: @json(__('ui.wt_cs_sample')),
    large:  @json(__('ui.wt_cs_glarge')),
    fail:   @json(__('ui.wt_cs_gfail')),
    bad:    @json(__('ui.wt_cs_bad')),
    ctitle: @json(__('ui.wt_cs_copyhex')),
    copied: @json(__('ui.wt_copied'))
  };

  /* ---- ramp definition: step -> mix amount toward white (<500) or black (>500) ---- */
  var STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900];
  var MIX   = [0.95, 0.90, 0.75, 0.55, 0.30, 0, 0.15, 0.35, 0.55, 0.72];

  var clamp = function (n, a, b) { return Math.min(b, Math.max(a, n)); };
  var hx = function (n) { return clamp(Math.round(n), 0, 255).toString(16).padStart(2, '0'); };

  function hsl2rgb(h, s, l) {
    h = ((h % 360) + 360) % 360 / 360;
    if (s === 0) { var v = l * 255; return [v, v, v]; }
    var q = l < 0.5 ? l * (1 + s) : l + s - l * s, p = 2 * l - q;
    var f = function (t) {
      t = (t + 1) % 1;
      if (t < 1 / 6) return p + (q - p) * 6 * t;
      if (t < 1 / 2) return q;
      if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
      return p;
    };
    return [f(h + 1 / 3) * 255, f(h) * 255, f(h - 1 / 3) * 255];
  }

  function rgb2hsl(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    var mx = Math.max(r, g, b), mn = Math.min(r, g, b), l = (mx + mn) / 2;
    if (mx === mn) return [0, 0, l * 100];
    var d = mx - mn, s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn), h;
    if (mx === r) h = (g - b) / d + (g < b ? 6 : 0);
    else if (mx === g) h = (b - r) / d + 2;
    else h = (r - g) / d + 4;
    return [h * 60, s * 100, l * 100];
  }

  /* ---- WCAG 2.1 relative luminance + contrast ratio ---- */
  function lum(c) {
    var a = c.map(function (v) {
      v /= 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
  }
  function ratio(a, b) {
    var l1 = lum(a), l2 = lum(b);
    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
  }
  function grade(r) {
    if (r >= 7)   return ['aaa', 'AAA'];
    if (r >= 4.5) return ['aa', 'AA'];
    if (r >= 3)   return ['lg', L.large];
    return ['no', L.fail];
  }

  function parse(s) {
    s = String(s).trim().toLowerCase();
    var m;
    if ((m = s.match(/^#?([0-9a-f]{3})$/))) {
      var t = m[1];
      return [parseInt(t[0] + t[0], 16), parseInt(t[1] + t[1], 16), parseInt(t[2] + t[2], 16)];
    }
    if ((m = s.match(/^#?([0-9a-f]{6})$/))) {
      var q = m[1];
      return [parseInt(q.slice(0, 2), 16), parseInt(q.slice(2, 4), 16), parseInt(q.slice(4, 6), 16)];
    }
    if ((m = s.match(/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)/))) {
      return [clamp(+m[1], 0, 255), clamp(+m[2], 0, 255), clamp(+m[3], 0, 255)];
    }
    if ((m = s.match(/^hsla?\(\s*(-?[\d.]+)(?:deg)?[,\s]+([\d.]+)%[,\s]+([\d.]+)%/))) {
      return hsl2rgb(+m[1], clamp(+m[2], 0, 100) / 100, clamp(+m[3], 0, 100) / 100);
    }
    return null;
  }

  /* ---- build the 10-step ramp from one base colour ----
     step 500 is the untouched base; below it we interpolate L toward 100%,
     above it toward 0%. "soften" additionally pulls saturation down at the
     extremes so tints/shades do not read as neon. Hue never moves.        */
  function buildRamp(rgb, soft) {
    var hsl = rgb2hsl(rgb[0], rgb[1], rgb[2]);
    var h = hsl[0], s = hsl[1], l = hsl[2];
    return STEPS.map(function (step, i) {
      var m = MIX[i], nl, ns = s;
      if (step < 500) { nl = l + (100 - l) * m; if (soft) ns = s * (1 - m); }
      else if (step === 500) { nl = l; }
      else { nl = l * (1 - m); if (soft) ns = s * (1 - m); }
      var c = hsl2rgb(h, ns / 100, nl / 100).map(function (v) { return clamp(Math.round(v), 0, 255); });
      return {
        step: step,
        rgb: c,
        hex: '#' + hx(c[0]) + hx(c[1]) + hx(c[2]),
        w: ratio(c, [255, 255, 255]),
        k: ratio(c, [0, 0, 0])
      };
    });
  }

  function safeName(v) {
    v = String(v).trim().toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');
    return v || 'brand';
  }

  function esc(t) {
    return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function crCell(label, val) {
    var g = grade(val);
    return '<span class="cs-cr"><span>' + esc(label) + '</span><b dir="ltr">' + val.toFixed(2)
         + '</b><i class="cs-g ' + g[0] + '">' + esc(g[1]) + '</i></span>';
  }

  function render() {
    var rgb = parse($('cs-in').value);
    if (!rgb) { $('cs-err').textContent = L.bad; return; }
    $('cs-err').textContent = '';

    rgb = rgb.map(function (v) { return clamp(Math.round(v), 0, 255); });
    var baseHex = '#' + hx(rgb[0]) + hx(rgb[1]) + hx(rgb[2]);
    $('cs-pick').value = baseHex;

    var hsl = rgb2hsl(rgb[0], rgb[1], rgb[2]);
    $('cs-hsl').textContent = 'hsl(' + Math.round(hsl[0]) + ', ' + Math.round(hsl[1]) + '%, '
                            + Math.round(hsl[2]) + '%)';

    var ramp = buildRamp(rgb, $('cs-soft').checked);

    $('cs-ramp').innerHTML = ramp.map(function (r) {
      return '<div class="cs-row' + (r.step === 500 ? ' is-base' : '') + '">'
        + '<div class="cs-chip" style="background:' + r.hex + '">'
        +   '<span class="cs-aw">' + esc(L.sample) + '</span>'
        +   '<span class="cs-ab">' + esc(L.sample) + '</span>'
        + '</div>'
        + '<div class="cs-lab"><b dir="ltr">' + r.step + '</b>'
        +   (r.step === 500 ? '<i>' + esc(L.base) + '</i>' : '') + '</div>'
        + '<button type="button" class="cs-hexbtn" dir="ltr" data-v="' + r.hex
        +   '" title="' + esc(L.ctitle) + '" aria-label="' + esc(L.ctitle) + ' ' + r.hex + '"'
        +   ' data-done="' + esc(L.copied) + '">' + r.hex + '</button>'
        + '<span class="cs-sp"></span>'
        + crCell(L.white, r.w)
        + crCell(L.black, r.k)
        + '</div>';
    }).join('');

    Array.prototype.forEach.call($('cs-ramp').querySelectorAll('.cs-hexbtn'), function (b) {
      b.addEventListener('click', function () { wtCopy(b, b.dataset.v); });
    });

    var name = safeName($('cs-name').value);
    var pad = function (n) { return (n + ':').padEnd(5, ' '); };

    var css = [':root {'];
    ramp.forEach(function (r) {
      css.push('  --' + name + '-' + pad(r.step) + ' ' + r.hex + ';');
    });
    css.push('}');
    $('cs-css').value = css.join(NL);

    var key = /^[a-z_$][a-z0-9_$]*$/i.test(name) ? name : "'" + name + "'";
    var tw = ['// tailwind.config.js', 'module.exports = {', '  theme: {', '    extend: {', '      colors: {'];
    tw.push('        ' + key + ': {');
    ramp.forEach(function (r) {
      tw.push('          ' + r.step + ": '" + r.hex + "',");
    });
    tw.push('        },', '      },', '    },', '  },', '};');
    $('cs-tw').value = tw.join(NL);
  }

  $('cs-pick').addEventListener('input', function () { $('cs-in').value = $('cs-pick').value; render(); });
  ['cs-in', 'cs-name'].forEach(function (id) { $(id).addEventListener('input', render); });
  $('cs-soft').addEventListener('change', render);
  $('cs-rand').addEventListener('click', function () {
    var h = Math.floor(Math.random() * 360),
        s = 45 + Math.floor(Math.random() * 45),
        l = 40 + Math.floor(Math.random() * 22);
    var c = hsl2rgb(h, s / 100, l / 100).map(function (v) { return clamp(Math.round(v), 0, 255); });
    $('cs-in').value = '#' + hx(c[0]) + hx(c[1]) + hx(c[2]);
    render();
  });
  $('cs-css-c').addEventListener('click', function (e) { wtCopy(e.currentTarget, $('cs-css').value); });
  $('cs-tw-c').addEventListener('click', function (e) { wtCopy(e.currentTarget, $('cs-tw').value); });

  $('cs-in').value = '#22d3ee';
  render();
})();
</script>
