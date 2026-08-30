<style>
.imf-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;min-height:126px;border:2px dashed var(--line-2);border-radius:16px;background:var(--surface-2);cursor:pointer;text-align:center;padding:20px;transition:border-color .2s,background .2s}
.imf-drop:hover,.imf-drop.imf-over{border-color:var(--cyan);background:rgba(34,211,238,.06)}
.imf-drop .icon.imf-dropic{width:36px;height:36px;color:var(--cyan)}
.imf-drop-t{font-size:15px;font-weight:600;color:var(--text)}
.imf-drop-h{font-size:12.5px;color:var(--dim);max-width:440px;line-height:1.7}
.imf-samplebar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:12px}
.imf-samplebar .btn{padding:8px 15px;font-size:12.5px}
.imf-samplebar .btn .icon{width:15px;height:15px}
.imf-stage{margin-top:18px;background:var(--surface-2);border:1px solid var(--line);border-radius:16px;padding:13px;display:flex;flex-direction:column;gap:9px;align-items:center}
.imf-cvwrap{width:100%;border-radius:12px;display:grid;place-items:center;min-height:150px;padding:10px;background-color:var(--surface);background-image:linear-gradient(45deg,rgba(128,128,128,.14) 25%,transparent 25%,transparent 75%,rgba(128,128,128,.14) 75%),linear-gradient(45deg,rgba(128,128,128,.14) 25%,transparent 25%,transparent 75%,rgba(128,128,128,.14) 75%);background-size:18px 18px;background-position:0 0,9px 9px}
.imf-cvwrap canvas{max-width:100%;max-height:420px;display:block;border-radius:5px}
.imf-meta{font-size:12px;color:var(--muted);font-family:ui-monospace,monospace;text-align:center;word-break:break-all}
.imf-sec{margin-top:20px;padding-top:16px;border-top:1px solid var(--line)}
.imf-sec-h{font-size:12.5px;font-weight:600;color:var(--dim);margin-bottom:11px}
.imf-presets{display:flex;flex-wrap:wrap;gap:9px}
.imf-preset{font-family:var(--font-body);font-size:12.5px;padding:7px 15px;border-radius:99px;border:1px solid var(--line-2);background:var(--surface-2);color:var(--muted);cursor:pointer;transition:border-color .18s,color .18s,background .18s}
.imf-preset:hover{border-color:var(--cyan);color:var(--cyan)}
.imf-preset.on{background:rgba(34,211,238,.14);border-color:var(--cyan);color:var(--cyan);font-weight:700}
.imf-sliders{display:grid;grid-template-columns:repeat(auto-fit,minmax(258px,1fr));gap:13px 28px}
.imf-row{display:grid;grid-template-columns:minmax(76px,auto) 1fr 54px;align-items:center;gap:11px}
.imf-row>span{font-size:13px;color:var(--muted)}
.imf-row input[type=range]{width:100%;min-width:0;accent-color:var(--cyan)}
.imf-row b{font-size:12.5px;color:var(--cyan);font-family:ui-monospace,monospace;text-align:center}
.imf-row.imf-off b{color:var(--dim);font-weight:400}
.imf-cssbox{margin-top:18px}
.imf-cssbox .wt-ta{font-size:13px}
</style>

<div class="imf-wrap">

  <div class="wt-pane">
    <label>{{ __('ui.wt_imf_source') }}</label>
    <div class="imf-drop" id="imf-drop" role="button" tabindex="0" aria-label="{{ __('ui.wt_imf_drop') }}">
      <svg class="icon imf-dropic"><use href="#i-cloud"/></svg>
      <div class="imf-drop-t">{{ __('ui.wt_imf_drop') }}</div>
      <div class="imf-drop-h">{{ __('ui.wt_imf_hint') }}</div>
    </div>
    <input type="file" id="imf-file" accept="image/*" hidden>
    <div class="imf-samplebar">
      <button class="btn btn-glass" id="imf-sample" type="button">
        <svg class="icon"><use href="#i-sparkles"/></svg><span>{{ __('ui.wt_imf_sample') }}</span>
      </button>
    </div>
  </div>

  <div class="imf-stage">
    <div class="imf-cvwrap"><canvas id="imf-cv" width="600" height="400"></canvas></div>
    <div class="imf-meta" id="imf-meta" dir="ltr">600 × 400 px</div>
  </div>

  <div class="imf-sec">
    <div class="imf-sec-h">{{ __('ui.wt_imf_presets') }}</div>
    <div class="imf-presets" id="imf-presets">
      <button class="imf-preset on" type="button" data-p="reset">{{ __('ui.wt_imf_p_reset') }}</button>
      <button class="imf-preset" type="button" data-p="noir">{{ __('ui.wt_imf_p_noir') }}</button>
      <button class="imf-preset" type="button" data-p="vintage">{{ __('ui.wt_imf_p_vintage') }}</button>
      <button class="imf-preset" type="button" data-p="vivid">{{ __('ui.wt_imf_p_vivid') }}</button>
      <button class="imf-preset" type="button" data-p="faded">{{ __('ui.wt_imf_p_faded') }}</button>
      <button class="imf-preset" type="button" data-p="dreamy">{{ __('ui.wt_imf_p_dreamy') }}</button>
      <button class="imf-preset" type="button" data-p="negative">{{ __('ui.wt_imf_p_negative') }}</button>
      <button class="imf-preset" type="button" data-p="neon">{{ __('ui.wt_imf_p_neon') }}</button>
    </div>
  </div>

  <div class="imf-sec">
    <div class="imf-sec-h">{{ __('ui.wt_imf_adjust') }}</div>
    <div class="imf-sliders">
      <div class="imf-row imf-off" id="imf-r-bri">
        <span>{{ __('ui.wt_imf_brightness') }}</span>
        <input type="range" id="imf-bri" min="0" max="200" step="1" value="100" aria-label="{{ __('ui.wt_imf_brightness') }}">
        <b id="imf-v-bri" dir="ltr">100%</b>
      </div>
      <div class="imf-row imf-off" id="imf-r-con">
        <span>{{ __('ui.wt_imf_contrast') }}</span>
        <input type="range" id="imf-con" min="0" max="200" step="1" value="100" aria-label="{{ __('ui.wt_imf_contrast') }}">
        <b id="imf-v-con" dir="ltr">100%</b>
      </div>
      <div class="imf-row imf-off" id="imf-r-sat">
        <span>{{ __('ui.wt_imf_saturate') }}</span>
        <input type="range" id="imf-sat" min="0" max="300" step="1" value="100" aria-label="{{ __('ui.wt_imf_saturate') }}">
        <b id="imf-v-sat" dir="ltr">100%</b>
      </div>
      <div class="imf-row imf-off" id="imf-r-gray">
        <span>{{ __('ui.wt_imf_grayscale') }}</span>
        <input type="range" id="imf-gray" min="0" max="100" step="1" value="0" aria-label="{{ __('ui.wt_imf_grayscale') }}">
        <b id="imf-v-gray" dir="ltr">0%</b>
      </div>
      <div class="imf-row imf-off" id="imf-r-sep">
        <span>{{ __('ui.wt_imf_sepia') }}</span>
        <input type="range" id="imf-sep" min="0" max="100" step="1" value="0" aria-label="{{ __('ui.wt_imf_sepia') }}">
        <b id="imf-v-sep" dir="ltr">0%</b>
      </div>
      <div class="imf-row imf-off" id="imf-r-hue">
        <span>{{ __('ui.wt_imf_hue') }}</span>
        <input type="range" id="imf-hue" min="0" max="360" step="1" value="0" aria-label="{{ __('ui.wt_imf_hue') }}">
        <b id="imf-v-hue" dir="ltr">0°</b>
      </div>
      <div class="imf-row imf-off" id="imf-r-inv">
        <span>{{ __('ui.wt_imf_invert') }}</span>
        <input type="range" id="imf-inv" min="0" max="100" step="1" value="0" aria-label="{{ __('ui.wt_imf_invert') }}">
        <b id="imf-v-inv" dir="ltr">0%</b>
      </div>
      <div class="imf-row imf-off" id="imf-r-blur">
        <span>{{ __('ui.wt_imf_blur') }}</span>
        <input type="range" id="imf-blur" min="0" max="20" step="1" value="0" aria-label="{{ __('ui.wt_imf_blur') }}">
        <b id="imf-v-blur" dir="ltr">0px</b>
      </div>
    </div>
  </div>

  <div class="wt-pane imf-cssbox">
    <label>{{ __('ui.wt_imf_css') }}</label>
    <textarea id="imf-css" class="wt-ta" rows="2" readonly dir="ltr">filter: none;</textarea>
  </div>

  <div class="wt-bar">
    <button class="btn btn-primary" id="imf-dl" type="button">{{ __('ui.wt_imf_download') }}</button>
    <button class="btn btn-glass" id="imf-copy" type="button" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
    <button class="btn btn-glass" id="imf-cmp" type="button" aria-pressed="false">{{ __('ui.wt_imf_compare') }}</button>
    <button class="btn btn-glass" id="imf-reset" type="button">{{ __('ui.wt_imf_reset') }}</button>
    <span class="wt-status" id="imf-status"></span>
  </div>

</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };

  var T = {
    ready:      @json(__('ui.wt_imf_ready')),
    scaled:     @json(__('ui.wt_imf_scaled')),
    sampleNote: @json(__('ui.wt_imf_sample_note')),
    errType:    @json(__('ui.wt_imf_err_type')),
    errRead:    @json(__('ui.wt_imf_err_read')),
    noFilter:   @json(__('ui.wt_imf_nofilter')),
    comparing:  @json(__('ui.wt_imf_comparing'))
  };

  var MAX = 2400;                    // long-side cap: keeps the live preview interactive
  var DEG = String.fromCharCode(176);
  var CROSS = String.fromCharCode(215);

  var cv = $('imf-cv'), ctx = cv.getContext('2d');
  var src = null, W = 600, H = 400, baseName = 'image', srcURL = null;
  var raf = 0, compare = false, curFilter = 'none';

  // Does this browser support filters on the 2D context? (needed for the PNG export)
  var ctxOK = false;
  try { ctx.filter = 'blur(1px)'; ctxOK = (ctx.filter === 'blur(1px)'); ctx.filter = 'none'; } catch (e) { ctxOK = false; }

  // Fixed application order. Colour maths first, blur last, so the printed string
  // is reproducible and matches what the canvas actually rendered.
  var ORDER = [
    ['bri',  'brightness', '%'],
    ['con',  'contrast',   '%'],
    ['sat',  'saturate',   '%'],
    ['gray', 'grayscale',  '%'],
    ['sep',  'sepia',      '%'],
    ['hue',  'hue-rotate', 'deg'],
    ['inv',  'invert',     '%'],
    ['blur', 'blur',       'px']
  ];
  var DEF = { bri: 100, con: 100, sat: 100, gray: 0, sep: 0, hue: 0, inv: 0, blur: 0 };

  var PRESETS = {
    noir:     { gray: 100, con: 140, bri: 95 },
    vintage:  { sep: 65, sat: 130, con: 95, bri: 105 },
    vivid:    { sat: 160, con: 115 },
    faded:    { sat: 60, con: 85, bri: 110 },
    dreamy:   { blur: 2, bri: 110, sat: 120 },
    negative: { inv: 100 },
    neon:     { hue: 180, sat: 180 }
  };

  function val(k) { var n = parseFloat($('imf-' + k).value); return isNaN(n) ? DEF[k] : n; }

  function status(msg, err) {
    var s = $('imf-status');
    s.textContent = msg || '';
    s.className = 'wt-status' + (err ? ' err' : '');
  }

  function build() {
    var parts = [], i, k, v;
    for (i = 0; i < ORDER.length; i++) {
      k = ORDER[i][0]; v = val(k);
      if (v !== DEF[k]) parts.push(ORDER[i][1] + '(' + v + ORDER[i][2] + ')');
    }
    return parts.length ? parts.join(' ') : 'none';
  }

  function matches(p) {
    for (var i = 0; i < ORDER.length; i++) {
      var k = ORDER[i][0];
      var want = (p && p[k] !== undefined) ? p[k] : DEF[k];
      if (val(k) !== want) return false;
    }
    return true;
  }

  function markPresets() {
    var btns = $('imf-presets').getElementsByClassName('imf-preset');
    for (var i = 0; i < btns.length; i++) {
      var name = btns[i].getAttribute('data-p');
      var p = (name === 'reset') ? null : PRESETS[name];
      if (matches(p)) btns[i].classList.add('on'); else btns[i].classList.remove('on');
    }
  }

  function draw() {
    if (!src) return;
    if (ctxOK) {
      cv.style.filter = 'none';
      ctx.filter = 'none';
      ctx.clearRect(0, 0, W, H);
      ctx.filter = compare ? 'none' : curFilter;
      ctx.drawImage(src, 0, 0, W, H);
      ctx.filter = 'none';
    } else {
      ctx.clearRect(0, 0, W, H);
      ctx.drawImage(src, 0, 0, W, H);
      cv.style.filter = compare ? 'none' : curFilter;   // preview-only fallback
    }
  }

  // Readouts and the CSS string are updated synchronously so they always match the
  // slider; only the (potentially expensive) canvas repaint is coalesced into a frame.
  function update() {
    for (var i = 0; i < ORDER.length; i++) {
      var k = ORDER[i][0], v = val(k);
      $('imf-v-' + k).textContent = v + (k === 'hue' ? DEG : ORDER[i][2]);
      if (v === DEF[k]) $('imf-r-' + k).classList.add('imf-off');
      else $('imf-r-' + k).classList.remove('imf-off');
    }
    curFilter = build();
    $('imf-css').value = 'filter: ' + curFilter + ';';
    markPresets();
  }

  function schedule() {
    update();
    if (raf) return;
    raf = requestAnimationFrame(function () { raf = 0; draw(); });
  }

  function apply() { update(); draw(); }

  function setImage(s, w, h, name) {
    var scale = Math.min(1, MAX / Math.max(w, h));
    W = Math.max(1, Math.round(w * scale));
    H = Math.max(1, Math.round(h * scale));
    cv.width = W; cv.height = H;
    src = s; baseName = name || 'image';
    $('imf-meta').textContent = W + ' ' + CROSS + ' ' + H + ' px';
    draw();
    return scale < 1;
  }

  // Deterministic 600x400 colour chart: 8 flat swatches of 150x200.
  // Row 1: red, green, blue, white.  Row 2: cyan, magenta, yellow, 50% grey.
  function sampleChart() {
    var c = document.createElement('canvas');
    c.width = 600; c.height = 400;
    var g = c.getContext('2d');
    var cols = ['#FF0000', '#00FF00', '#0000FF', '#FFFFFF', '#00FFFF', '#FF00FF', '#FFFF00', '#808080'];
    for (var i = 0; i < 8; i++) {
      g.fillStyle = cols[i];
      g.fillRect((i % 4) * 150, i < 4 ? 0 : 200, 150, 200);
    }
    return c;
  }

  function loadSample() {
    if (srcURL) { URL.revokeObjectURL(srcURL); srcURL = null; }
    $('imf-file').value = '';
    setImage(sampleChart(), 600, 400, 'color-chart');
    status(T.sampleNote);
  }

  function openFile(f) {
    if (!f) return;
    if (String(f.type).indexOf('image/') !== 0) { status(T.errType, true); return; }
    if (srcURL) URL.revokeObjectURL(srcURL);
    srcURL = URL.createObjectURL(f);
    var im = new Image();
    im.onload = function () {
      if (!im.naturalWidth || !im.naturalHeight) { status(T.errRead, true); return; }
      var nm = f.name || 'image', dot = nm.lastIndexOf('.');
      var name = (dot > 0 ? nm.slice(0, dot) : nm) || 'image';
      var shrunk = setImage(im, im.naturalWidth, im.naturalHeight, name);
      status(shrunk ? T.scaled : T.ready);
    };
    im.onerror = function () { status(T.errRead, true); };
    im.src = srcURL;
  }

  /* ---- events ---- */

  var drop = $('imf-drop'), file = $('imf-file');
  drop.addEventListener('click', function () { file.click(); });
  drop.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); file.click(); }
  });
  file.addEventListener('change', function (e) {
    if (e.target.files && e.target.files[0]) openFile(e.target.files[0]);
  });
  ['dragenter', 'dragover'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('imf-over'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('imf-over'); });
  });
  drop.addEventListener('drop', function (e) {
    var f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) openFile(f);
  });

  $('imf-sample').addEventListener('click', loadSample);

  for (var i = 0; i < ORDER.length; i++) {
    $('imf-' + ORDER[i][0]).addEventListener('input', schedule);
  }

  $('imf-presets').addEventListener('click', function (e) {
    var b = e.target && e.target.closest ? e.target.closest('.imf-preset') : null;
    if (!b) return;
    var name = b.getAttribute('data-p');
    var p = (name === 'reset') ? {} : (PRESETS[name] || {});
    for (var j = 0; j < ORDER.length; j++) {
      var k = ORDER[j][0];
      $('imf-' + k).value = (p[k] !== undefined) ? p[k] : DEF[k];
    }
    apply();
  });

  $('imf-reset').addEventListener('click', function () {
    for (var j = 0; j < ORDER.length; j++) { var k = ORDER[j][0]; $('imf-' + k).value = DEF[k]; }
    apply();
  });

  $('imf-cmp').addEventListener('click', function () {
    compare = !compare;
    if (compare) this.classList.add('ok'); else this.classList.remove('ok');
    this.setAttribute('aria-pressed', compare ? 'true' : 'false');
    status(compare ? T.comparing : '');
    draw();
  });

  $('imf-copy').addEventListener('click', function () { window.wtCopy(this, $('imf-css').value); });

  $('imf-dl').addEventListener('click', function () {
    if (!src) return;
    var wasCompare = compare;
    compare = false;
    draw();                       // guarantee the bitmap matches the current filter
    cv.toBlob(function (blob) {
      if (wasCompare) { compare = true; draw(); }
      if (!blob) { status(T.errRead, true); return; }
      var u = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = u; a.download = baseName + '-filtered.png';
      document.body.appendChild(a); a.click(); a.parentNode.removeChild(a);
      setTimeout(function () { URL.revokeObjectURL(u); }, 4000);
    }, 'image/png');
  });

  /* ---- init ---- */
  update();
  loadSample();
  if (!ctxOK) { $('imf-dl').disabled = true; status(T.noFilter, true); }
})();
</script>
