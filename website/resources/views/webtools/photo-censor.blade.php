@php
  $pcTxt = [
    'px'       => __('ui.wt_pc_px'),
    'pixelate' => __('ui.wt_pc_pixelate'),
    'blur'     => __('ui.wt_pc_blur'),
    'solid'    => __('ui.wt_pc_solid'),
    'block'    => __('ui.wt_pc_block'),
    'radius'   => __('ui.wt_pc_radius'),
    'scaled'   => __('ui.wt_pc_scaled'),
    'remove'   => __('ui.wt_pc_remove'),
  ];
@endphp

<style>
  .pc-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;text-align:center;
    padding:52px 24px;border:2px dashed var(--line-2);border-radius:16px;background:var(--surface-2);cursor:pointer;
    transition:border-color .2s,background .2s}
  .pc-drop:hover,.pc-drop.drag{border-color:var(--cyan);background:rgba(34,211,238,.06)}
  .pc-drop-ic{width:40px;height:40px;color:var(--dim)}
  .pc-drop-t{color:var(--muted);font-size:14px;max-width:36ch}
  .pc-drop-btn{pointer-events:none}

  .pc-tools{margin-top:0;padding-top:0;border-top:none;margin-bottom:16px}
  /* .wt-range با display:inline-flex بر ویژگی hidden غلبه می‌کند؛ اینجا خنثی می‌شود */
  .pc-tools .wt-range[hidden]{display:none}
  .pc-seg{display:inline-flex;border:1px solid var(--line-2);border-radius:10px;overflow:hidden}
  .pc-seg-btn{background:var(--surface-2);color:var(--muted);border:none;border-inline-start:1px solid var(--line-2);
    padding:7px 15px;font-family:var(--font-body);font-size:13px;cursor:pointer;transition:background .15s,color .15s}
  .pc-seg-btn:first-child{border-inline-start:none}
  .pc-seg-btn.on{background:var(--grad);color:#fff}
  .pc-seg-btn:hover:not(.on){color:var(--text)}

  .pc-wrap{display:flex;justify-content:center;margin-bottom:10px}
  .pc-stage{position:relative;display:inline-block;line-height:0;max-width:100%;overflow:hidden;border-radius:12px;
    touch-action:none;-webkit-user-select:none;user-select:none;background:var(--surface-2);cursor:crosshair}
  .pc-stage canvas{display:block;max-width:100%;width:auto;height:auto}
  .pc-layer{position:absolute;inset:0;pointer-events:none}
  .pc-reg{position:absolute;box-sizing:border-box;border:1px dashed var(--cyan);box-shadow:0 0 0 1px rgba(0,0,0,.45)}
  .pc-x{position:absolute;top:0;inset-inline-end:0;width:20px;height:20px;display:grid;place-items:center;padding:0;
    background:rgba(0,0,0,.62);color:#fff;border:none;border-radius:0 0 0 7px;cursor:pointer;pointer-events:auto}
  .pc-x .icon{width:11px;height:11px}
  .pc-x:hover{background:#ff6b6b}
  .pc-sel{position:absolute;box-sizing:border-box;border:1px dashed #fff;background:rgba(34,211,238,.22);
    pointer-events:none}
  .pc-hint{margin-top:2px;line-height:1.9}

  .pc-note{display:flex;align-items:flex-start;gap:9px;margin-top:16px;padding:12px 14px;border-radius:12px;
    background:rgba(34,211,238,.07);border:1px solid var(--line-2);font-size:12.5px;color:var(--muted);line-height:1.85}
  .pc-note .icon{width:15px;height:15px;color:var(--cyan);flex:none;margin-top:3px}

  html[data-theme="light"] .pc-reg{box-shadow:0 0 0 1px rgba(255,255,255,.85)}
  html[data-theme="light"] .pc-sel{border-color:#0b1220}
  html[data-theme="light"] .pc-x{background:rgba(15,23,42,.72)}
  html[data-theme="light"] .pc-note{background:rgba(34,211,238,.1)}
</style>

<div class="pc" id="pc">

  <div class="pc-drop" id="pc-drop" role="button" tabindex="0" aria-label="{{ __('ui.wt_pc_choose') }}">
    <svg class="icon pc-drop-ic"><use href="#i-box"/></svg>
    <span class="pc-drop-t">{{ __('ui.wt_pc_drop') }}</span>
    <span class="btn btn-glass pc-drop-btn">{{ __('ui.wt_pc_choose') }}</span>
  </div>
  <input type="file" id="pc-file" accept="image/*" hidden>

  <div class="pc-editor" id="pc-editor" hidden>

    <div class="wt-fields pc-tools">
      <label class="wt-range">{{ __('ui.wt_pc_mode') }}</label>
      <div class="pc-seg" id="pc-seg">
        <button type="button" class="pc-seg-btn on" data-m="pixelate">{{ __('ui.wt_pc_pixelate') }}</button>
        <button type="button" class="pc-seg-btn" data-m="blur">{{ __('ui.wt_pc_blur') }}</button>
        <button type="button" class="pc-seg-btn" data-m="solid">{{ __('ui.wt_pc_solid') }}</button>
      </div>
      <label class="wt-range" id="pc-str-wrap">
        <span id="pc-str-label">{{ __('ui.wt_pc_block') }}</span>
        <input type="range" id="pc-str" min="4" max="80" step="2" value="16">
        <b dir="ltr" id="pc-str-val">16</b>
      </label>
    </div>

    <div class="pc-wrap">
      <div class="pc-stage" id="pc-stage" dir="ltr">
        <canvas id="pc-canvas"></canvas>
        <div class="pc-layer" id="pc-layer"></div>
        <div class="pc-sel" id="pc-sel" hidden></div>
      </div>
    </div>

    <p class="wt-status pc-hint">{{ __('ui.wt_pc_hint') }}</p>
    <p class="wt-status pc-hint" id="pc-scaled" hidden></p>

    <div class="pc-note">
      <svg class="icon"><use href="#i-lock"/></svg>
      <span>{{ __('ui.wt_pc_baked') }}</span>
    </div>

    <div class="wt-out-box">
      <div class="wt-out-row"><span>{{ __('ui.wt_pc_size') }}</span><b dir="ltr" id="pc-v-size">&mdash;</b></div>
      <div class="wt-out-row"><span>{{ __('ui.wt_pc_regions') }}</span><b dir="ltr" id="pc-v-count">0</b></div>
      <div class="wt-out-row"><span>{{ __('ui.wt_pc_last') }}</span><b id="pc-v-last">&mdash;</b></div>
    </div>

    <div class="wt-bar">
      <button type="button" class="btn btn-glass" id="pc-undo">
        <svg class="icon"><use href="#i-restore"/></svg>{{ __('ui.wt_pc_undo') }}
      </button>
      <button type="button" class="btn btn-glass" id="pc-clear">
        <svg class="icon"><use href="#i-x"/></svg>{{ __('ui.wt_pc_clear') }}
      </button>
      <label class="wt-range">{{ __('ui.wt_pc_format') }}
        <select id="pc-fmt" class="wt-select"><option value="png">PNG</option><option value="jpeg">JPEG</option></select>
      </label>
      <button type="button" class="btn btn-primary" id="pc-dl">
        <svg class="icon"><use href="#i-arrow"/></svg>{{ __('ui.wt_pc_download') }}
      </button>
      <button type="button" class="btn btn-glass" id="pc-change">{{ __('ui.wt_pc_change') }}</button>
    </div>

    <p class="wt-status err" id="pc-limit" hidden>{{ __('ui.wt_pc_limit') }}</p>
  </div>

  <p class="wt-status err" id="pc-err" hidden>{{ __('ui.wt_pc_error') }}</p>
</div>

<script>
(function () {
  const $ = function (id) { return document.getElementById(id); };
  const T = @json($pcTxt);

  const MAX_PIXELS  = 24000000;   /* حداکثر مساحت بوم برای جلوگیری از پرشدن حافظه */
  const MAX_PREVIEW = 1200;       /* حداکثر ضلع پیش‌نمایش */
  const MAX_REGIONS = 40;

  const root   = $('pc');
  const drop   = $('pc-drop');
  const fileIn = $('pc-file');
  const editor = $('pc-editor');
  const errEl  = $('pc-err');
  const limitEl= $('pc-limit');
  const stage  = $('pc-stage');
  const pcan   = $('pc-canvas');
  const pctx   = pcan.getContext('2d');
  const layer  = $('pc-layer');
  const sel    = $('pc-sel');
  const seg    = $('pc-seg');
  const strIn  = $('pc-str');
  const strVal = $('pc-str-val');
  const strLab = $('pc-str-label');
  const strWrap= $('pc-str-wrap');

  /* بوم اصل تصویر (دست‌نخورده) و بوم کاری (سانسورشده) */
  const base = document.createElement('canvas');
  const bctx = base.getContext('2d');
  const full = document.createElement('canvas');
  const fctx = full.getContext('2d', { willReadFrequently: true });
  const tmp  = document.createElement('canvas');
  const tctx = tmp.getContext('2d');

  let ready = false, nw = 0, nh = 0;
  let regions = [];
  let mode = 'pixelate';
  const strength = { pixelate: 16, blur: 12 };
  const RANGES = { pixelate: { min: 4, max: 80, step: 2 }, blur: { min: 2, max: 40, step: 1 } };

  const clamp = function (v, a, b) { return Math.min(b, Math.max(a, v)); };

  /* ─────────────────── بارگذاری فایل ─────────────────── */

  function pick() { fileIn.value = ''; fileIn.click(); }
  drop.addEventListener('click', pick);
  drop.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); }
  });
  $('pc-change').addEventListener('click', pick);
  fileIn.addEventListener('change', function (e) {
    if (e.target.files && e.target.files[0]) loadFile(e.target.files[0]);
  });

  ['dragenter', 'dragover'].forEach(function (ev) {
    root.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('drag'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    root.addEventListener(ev, function (e) {
      e.preventDefault();
      if (ev === 'dragleave' && e.target !== drop) return;
      drop.classList.remove('drag');
    });
  });
  root.addEventListener('drop', function (e) {
    const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) loadFile(f);
  });

  function fail() { errEl.hidden = false; }

  function loadFile(f) {
    if (!f.type || f.type.indexOf('image/') !== 0) { fail(); return; }
    const r = new FileReader();
    r.onerror = fail;
    r.onload = function () {
      const img = new Image();
      img.onload = function () { setup(img); };
      img.onerror = fail;
      img.src = r.result;
    };
    r.readAsDataURL(f);
  }

  function setup(img) {
    const ow = img.naturalWidth, oh = img.naturalHeight;
    if (!ow || !oh) { fail(); return; }
    errEl.hidden = true;
    limitEl.hidden = true;

    let k = 1;
    if (ow * oh > MAX_PIXELS) k = Math.sqrt(MAX_PIXELS / (ow * oh));
    nw = Math.max(1, Math.round(ow * k));
    nh = Math.max(1, Math.round(oh * k));

    base.width = nw; base.height = nh;
    bctx.clearRect(0, 0, nw, nh);
    bctx.drawImage(img, 0, 0, nw, nh);

    full.width = nw; full.height = nh;

    const ps = Math.min(1, MAX_PREVIEW / Math.max(nw, nh));
    pcan.width  = Math.max(1, Math.round(nw * ps));
    pcan.height = Math.max(1, Math.round(nh * ps));

    const sc = $('pc-scaled');
    if (k < 1) { sc.textContent = T.scaled + ' ' + nw + ' × ' + nh + ' ' + T.px; sc.hidden = false; }
    else { sc.hidden = true; }

    regions = [];
    ready = true;
    drop.hidden = true;
    editor.hidden = false;
    render();
  }

  /* ─────────────────── الگوریتم‌های پوشاندن ─────────────────── */

  /* پیکسلی: ناحیه در ابعاد کوچک ترسیم و با خاموش‌بودن نرم‌سازی دوباره بزرگ می‌شود */
  function applyPixelate(r, block) {
    const b  = Math.max(2, Math.round(block));
    const sw = Math.max(1, Math.ceil(r.w / b));
    const sh = Math.max(1, Math.ceil(r.h / b));
    const gw = Math.min(sw * b, nw - r.x);
    const gh = Math.min(sh * b, nh - r.y);

    tmp.width = sw; tmp.height = sh;
    tctx.imageSmoothingEnabled = true;
    tctx.clearRect(0, 0, sw, sh);
    tctx.drawImage(full, r.x, r.y, gw, gh, 0, 0, sw, sh);

    fctx.save();
    fctx.beginPath();
    fctx.rect(r.x, r.y, r.w, r.h);
    fctx.clip();
    fctx.imageSmoothingEnabled = false;
    fctx.drawImage(tmp, 0, 0, sw, sh, r.x, r.y, sw * b, sh * b);
    fctx.restore();
  }

  /* محو: سه پاس فیلتر جعبه‌ای (تقریب گاوسی) فقط روی پیکسل‌های همان ناحیه */
  function hPass(src, dst, w, h, r) {
    const win = 2 * r + 1;
    for (let y = 0; y < h; y++) {
      const row = y * w * 4;
      for (let c = 0; c < 4; c++) {
        let sum = src[row + c] * (r + 1);
        for (let i = 1; i <= r; i++) sum += src[row + Math.min(i, w - 1) * 4 + c];
        for (let x = 0; x < w; x++) {
          dst[row + x * 4 + c] = sum / win;
          sum += src[row + Math.min(x + r + 1, w - 1) * 4 + c] - src[row + Math.max(x - r, 0) * 4 + c];
        }
      }
    }
  }
  function vPass(src, dst, w, h, r) {
    const win = 2 * r + 1, st = w * 4;
    for (let x = 0; x < w; x++) {
      const col = x * 4;
      for (let c = 0; c < 4; c++) {
        let sum = src[col + c] * (r + 1);
        for (let i = 1; i <= r; i++) sum += src[Math.min(i, h - 1) * st + col + c];
        for (let y = 0; y < h; y++) {
          dst[y * st + col + c] = sum / win;
          sum += src[Math.min(y + r + 1, h - 1) * st + col + c] - src[Math.max(y - r, 0) * st + col + c];
        }
      }
    }
  }
  function applyBlur(r, radius) {
    const w = r.w, h = r.h;
    if (w < 2 || h < 2) return;
    let rad = Math.round(radius);
    rad = Math.max(1, Math.min(rad, Math.max(1, Math.floor(Math.min(w, h) / 2) - 1)));
    const passes = (w * h > 3000000) ? 1 : 3;
    const id = fctx.getImageData(r.x, r.y, w, h);
    const a = id.data;
    const b = new Uint8ClampedArray(a.length);
    for (let p = 0; p < passes; p++) { hPass(a, b, w, h, rad); vPass(b, a, w, h, rad); }
    fctx.putImageData(id, r.x, r.y);
  }

  function applyRegion(r) {
    if (r.mode === 'solid') {
      fctx.save();
      fctx.fillStyle = '#000000';
      fctx.fillRect(r.x, r.y, r.w, r.h);
      fctx.restore();
    } else if (r.mode === 'blur') {
      applyBlur(r, r.val);
    } else {
      applyPixelate(r, r.val);
    }
  }

  /* ─────────────────── رندر ─────────────────── */

  function render() {
    if (!ready) return;
    fctx.setTransform(1, 0, 0, 1, 0, 0);
    fctx.clearRect(0, 0, nw, nh);
    fctx.imageSmoothingEnabled = true;
    fctx.drawImage(base, 0, 0);
    for (let i = 0; i < regions.length; i++) applyRegion(regions[i]);

    pctx.clearRect(0, 0, pcan.width, pcan.height);
    pctx.imageSmoothingEnabled = true;
    pctx.drawImage(full, 0, 0, pcan.width, pcan.height);

    buildOverlays();
    readout();
  }

  function buildOverlays() {
    while (layer.firstChild) layer.removeChild(layer.firstChild);
    regions.forEach(function (r, i) {
      const d = document.createElement('div');
      d.className = 'pc-reg';
      d.style.insetInlineStart = (r.x / nw * 100) + '%';
      d.style.top    = (r.y / nh * 100) + '%';
      d.style.width  = (r.w / nw * 100) + '%';
      d.style.height = (r.h / nh * 100) + '%';

      const x = document.createElement('button');
      x.type = 'button';
      x.className = 'pc-x';
      x.setAttribute('aria-label', T.remove);
      x.dataset.i = String(i);
      const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      svg.setAttribute('class', 'icon');
      const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
      use.setAttribute('href', '#i-x');
      svg.appendChild(use);
      x.appendChild(svg);
      d.appendChild(x);
      layer.appendChild(d);
    });
  }

  layer.addEventListener('click', function (e) {
    const b = e.target.closest ? e.target.closest('.pc-x') : null;
    if (!b) return;
    e.preventDefault();
    e.stopPropagation();
    const i = parseInt(b.dataset.i, 10);
    if (i >= 0 && i < regions.length) { regions.splice(i, 1); limitEl.hidden = true; render(); }
  });

  function readout() {
    $('pc-v-size').textContent  = nw + ' × ' + nh + ' ' + T.px;
    $('pc-v-count').textContent = String(regions.length);

    const last = $('pc-v-last');
    while (last.firstChild) last.removeChild(last.firstChild);
    if (!regions.length) { last.appendChild(document.createTextNode('—')); return; }
    const r = regions[regions.length - 1];
    const s = document.createElement('span');
    s.dir = 'ltr';
    s.textContent = 'X ' + r.x + ' · Y ' + r.y + ' · ' + r.w + ' × ' + r.h + ' ' + T.px;
    last.appendChild(s);
    last.appendChild(document.createTextNode(' · ' + (T[r.mode] || r.mode)));
  }

  /* ─────────────────── تنظیمات ─────────────────── */

  function setMode(m) {
    mode = m;
    [].forEach.call(seg.children, function (b) { b.classList.toggle('on', b.dataset.m === m); });
    if (m === 'solid') { strWrap.hidden = true; return; }
    strWrap.hidden = false;
    const cfg = RANGES[m];
    strIn.min = String(cfg.min);
    strIn.max = String(cfg.max);
    strIn.step = String(cfg.step);
    strIn.value = String(strength[m]);
    strVal.textContent = String(strength[m]);
    strLab.textContent = (m === 'blur') ? T.radius : T.block;
  }
  seg.addEventListener('click', function (e) {
    const b = e.target.closest('.pc-seg-btn');
    if (!b) return;
    setMode(b.dataset.m);
  });
  strIn.addEventListener('input', function () {
    const v = parseInt(strIn.value, 10) || RANGES[mode].min;
    strength[mode] = v;
    strVal.textContent = String(v);
  });
  setMode('pixelate');

  /* ─────────────────── کشیدن ناحیه ─────────────────── */

  let drawing = false, box = null, ax = 0, ay = 0, cur = null;

  function toRect(cx, cy) {
    const x1 = clamp(ax - box.left, 0, box.width);
    const y1 = clamp(ay - box.top,  0, box.height);
    const x2 = clamp(cx - box.left, 0, box.width);
    const y2 = clamp(cy - box.top,  0, box.height);
    return { l: Math.min(x1, x2), t: Math.min(y1, y2), w: Math.abs(x2 - x1), h: Math.abs(y2 - y1) };
  }

  stage.addEventListener('pointerdown', function (e) {
    if (!ready) return;
    if (e.target.closest && e.target.closest('.pc-x')) return;
    if (regions.length >= MAX_REGIONS) { limitEl.hidden = false; return; }
    e.preventDefault();
    box = pcan.getBoundingClientRect();
    if (!box.width || !box.height) return;
    ax = e.clientX; ay = e.clientY;
    drawing = true;
    cur = toRect(e.clientX, e.clientY);
    paintSel();
    sel.hidden = false;
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
    window.addEventListener('pointercancel', onUp);
  });

  function paintSel() {
    sel.style.insetInlineStart = (cur.l / box.width * 100) + '%';
    sel.style.top    = (cur.t / box.height * 100) + '%';
    sel.style.width  = (cur.w / box.width * 100) + '%';
    sel.style.height = (cur.h / box.height * 100) + '%';
  }

  function onMove(e) {
    if (!drawing) return;
    e.preventDefault();
    cur = toRect(e.clientX, e.clientY);
    paintSel();
  }

  function onUp() {
    if (!drawing) return;
    drawing = false;
    sel.hidden = true;
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onUp);
    window.removeEventListener('pointercancel', onUp);
    if (!cur || cur.w < 5 || cur.h < 5) return;

    const kx = nw / box.width, ky = nh / box.height;
    let x = Math.round(cur.l * kx);
    let y = Math.round(cur.t * ky);
    let w = Math.round(cur.w * kx);
    let h = Math.round(cur.h * ky);
    x = clamp(x, 0, nw - 1);
    y = clamp(y, 0, nh - 1);
    w = clamp(w, 1, nw - x);
    h = clamp(h, 1, nh - y);
    if (w < 2 || h < 2) return;

    regions.push({ x: x, y: y, w: w, h: h, mode: mode, val: (mode === 'solid' ? 0 : strength[mode]) });
    if (regions.length >= MAX_REGIONS) limitEl.hidden = false;
    render();
  }

  /* ─────────────────── واگرد / پاک‌کردن ─────────────────── */

  $('pc-undo').addEventListener('click', function () {
    if (!regions.length) return;
    regions.pop();
    limitEl.hidden = true;
    render();
  });
  $('pc-clear').addEventListener('click', function () {
    if (!regions.length) return;
    regions = [];
    limitEl.hidden = true;
    render();
  });

  /* ─────────────────── دانلود (پیکسل‌های پخته‌شده) ─────────────────── */

  $('pc-dl').addEventListener('click', function () {
    if (!ready) return;
    const fmt  = $('pc-fmt').value;
    const type = (fmt === 'jpeg') ? 'image/jpeg' : 'image/png';
    const ext  = (fmt === 'jpeg') ? 'jpg' : 'png';

    let out = full;
    if (fmt === 'jpeg') {
      out = document.createElement('canvas');
      out.width = nw; out.height = nh;
      const octx = out.getContext('2d');
      octx.fillStyle = '#ffffff';
      octx.fillRect(0, 0, nw, nh);
      octx.drawImage(full, 0, 0);
    }

    out.toBlob(function (blob) {
      if (!blob) return;
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'censored-' + nw + 'x' + nh + '.' + ext;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
    }, type, (fmt === 'jpeg') ? 0.92 : undefined);
  });
})();
</script>
