<style>
  .imc-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;text-align:center;
    padding:52px 24px;border:2px dashed var(--line-2);border-radius:16px;background:var(--surface-2);cursor:pointer;
    transition:border-color .2s,background .2s}
  .imc-drop:hover,.imc-drop.drag{border-color:var(--cyan);background:rgba(34,211,238,.06)}
  .imc-drop-ic{width:40px;height:40px;color:var(--dim)}
  .imc-drop-t{color:var(--muted);font-size:14px;max-width:34ch}
  .imc-drop-btn{pointer-events:none}
  .imc-wrap{display:flex;justify-content:center;margin-bottom:12px}
  .imc-stage{position:relative;display:inline-block;line-height:0;max-width:100%;overflow:hidden;border-radius:12px;
    touch-action:none;-webkit-user-select:none;user-select:none;background:var(--surface-2)}
  .imc-stage canvas{display:block;max-width:100%;width:auto;height:auto}
  .imc-crop{position:absolute;box-sizing:border-box;border:1.5px solid #fff;box-shadow:0 0 0 9999px rgba(0,0,0,.5);
    cursor:move;touch-action:none}
  .imc-crop::before{content:'';position:absolute;inset:0;pointer-events:none;
    background:
      linear-gradient(#ffffff66 0 0) 33.333% 0/1px 100% no-repeat,
      linear-gradient(#ffffff66 0 0) 66.666% 0/1px 100% no-repeat,
      linear-gradient(#ffffff66 0 0) 0 33.333%/100% 1px no-repeat,
      linear-gradient(#ffffff66 0 0) 0 66.666%/100% 1px no-repeat}
  .imc-h{position:absolute;width:16px;height:16px;background:#fff;border:1px solid rgba(0,0,0,.35);border-radius:4px;
    box-shadow:0 1px 3px rgba(0,0,0,.5);box-sizing:border-box;touch-action:none;z-index:2}
  .imc-h[data-h=nw]{top:-8px;inset-inline-start:-8px;cursor:nwse-resize}
  .imc-h[data-h=ne]{top:-8px;inset-inline-end:-8px;cursor:nesw-resize}
  .imc-h[data-h=se]{bottom:-8px;inset-inline-end:-8px;cursor:nwse-resize}
  .imc-h[data-h=sw]{bottom:-8px;inset-inline-start:-8px;cursor:nesw-resize}
  .imc-h[data-h=n]{top:-8px;inset-inline-start:calc(50% - 8px);cursor:ns-resize}
  .imc-h[data-h=s]{bottom:-8px;inset-inline-start:calc(50% - 8px);cursor:ns-resize}
  .imc-h[data-h=e]{top:calc(50% - 8px);inset-inline-end:-8px;cursor:ew-resize}
  .imc-h[data-h=w]{top:calc(50% - 8px);inset-inline-start:-8px;cursor:ew-resize}
  .imc-seg{display:inline-flex;border:1px solid var(--line-2);border-radius:10px;overflow:hidden}
  .imc-seg-btn{background:var(--surface-2);color:var(--muted);border:none;border-inline-start:1px solid var(--line-2);
    padding:7px 15px;font-family:var(--font-body);font-size:13px;cursor:pointer;transition:background .15s,color .15s}
  .imc-seg-btn:first-child{border-inline-start:none}
  .imc-seg-btn.on{background:var(--grad);color:#fff}
  .imc-seg-btn:hover:not(.on){color:var(--text)}
  .imc-fields .btn{padding:8px 16px;font-size:13px}
  .imc-hint{margin-top:2px}
  html[data-theme="light"] .imc-h{border-color:rgba(0,0,0,.25)}
</style>

<div class="imc" id="imc">
  <div class="imc-drop" id="imc-drop" role="button" tabindex="0" aria-label="{{ __('ui.imc_choose') }}">
    <svg class="icon imc-drop-ic"><use href="#i-box"/></svg>
    <span class="imc-drop-t">{{ __('ui.imc_drop') }}</span>
    <span class="btn btn-glass imc-drop-btn">{{ __('ui.imc_choose') }}</span>
  </div>
  <input type="file" id="imc-file" accept="image/*" hidden>

  <div class="imc-editor" id="imc-editor" hidden>
    <div class="imc-wrap">
      <div class="imc-stage" id="imc-stage" dir="ltr">
        <canvas id="imc-canvas"></canvas>
        <div class="imc-crop" id="imc-crop">
          <span class="imc-h" data-h="nw"></span>
          <span class="imc-h" data-h="n"></span>
          <span class="imc-h" data-h="ne"></span>
          <span class="imc-h" data-h="e"></span>
          <span class="imc-h" data-h="se"></span>
          <span class="imc-h" data-h="s"></span>
          <span class="imc-h" data-h="sw"></span>
          <span class="imc-h" data-h="w"></span>
        </div>
      </div>
    </div>
    <p class="wt-status imc-hint">{{ __('ui.imc_hint') }}</p>

    <div class="wt-fields imc-fields">
      <label class="wt-range">{{ __('ui.imc_aspect') }}</label>
      <div class="imc-seg" id="imc-seg">
        <button type="button" class="imc-seg-btn on" data-ar="free">{{ __('ui.imc_free') }}</button>
        <button type="button" class="imc-seg-btn" data-ar="1:1">{{ __('ui.imc_square') }}</button>
        <button type="button" class="imc-seg-btn" data-ar="4:3">{{ __('ui.imc_43') }}</button>
        <button type="button" class="imc-seg-btn" data-ar="16:9">{{ __('ui.imc_169') }}</button>
      </div>
      <button type="button" class="btn btn-glass" id="imc-reset">{{ __('ui.imc_reset') }}</button>
    </div>

    <div class="wt-out-box">
      <div class="wt-out-row"><span>{{ __('ui.imc_original') }}</span><b dir="ltr" id="imc-v-orig">—</b></div>
      <div class="wt-out-row"><span>{{ __('ui.imc_position') }}</span><b dir="ltr" id="imc-v-pos">—</b></div>
      <div class="wt-out-row"><span>{{ __('ui.imc_output') }}</span><b dir="ltr" id="imc-v-out">—</b></div>
    </div>

    <div class="wt-bar">
      <label class="wt-range">{{ __('ui.imc_format') }}
        <select id="imc-fmt" class="wt-select"><option value="png">PNG</option><option value="jpeg">JPEG</option></select>
      </label>
      <button type="button" class="btn btn-primary" id="imc-dl">
        <svg class="icon"><use href="#i-arrow"/></svg>{{ __('ui.imc_download') }}
      </button>
      <button type="button" class="btn btn-glass" id="imc-change">{{ __('ui.imc_change') }}</button>
    </div>
    <p class="wt-status err" id="imc-err" hidden>{{ __('ui.imc_error') }}</p>
  </div>
</div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const PX = @json(__('ui.imc_px'));
  const drop = $('imc-drop'), file = $('imc-file'), editor = $('imc-editor'), err = $('imc-err');
  const stage = $('imc-stage'), canvas = $('imc-canvas'), ctx = canvas.getContext('2d'), cropEl = $('imc-crop');
  const seg = $('imc-seg');
  const MAXPREVIEW = 1400;

  let srcImg = null, nw = 0, nh = 0, MIN = 8;
  let crop = { x: 0, y: 0, w: 0, h: 0 };
  let currentAr = 0;               // 0 = free

  const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
  const parseAr = s => { if (s === 'free') return 0; const p = s.split(':'); return (+p[0]) / (+p[1]); };

  /* ---- load ------------------------------------------------------- */
  function pick() { file.value = ''; file.click(); }
  drop.addEventListener('click', pick);
  drop.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); } });
  $('imc-change').addEventListener('click', pick);
  file.addEventListener('change', e => { if (e.target.files && e.target.files[0]) loadFile(e.target.files[0]); });

  ['dragenter', 'dragover'].forEach(ev => $('imc').addEventListener(ev, e => {
    e.preventDefault(); drop.classList.add('drag');
  }));
  ['dragleave', 'drop'].forEach(ev => $('imc').addEventListener(ev, e => {
    e.preventDefault(); if (ev === 'dragleave' && e.target !== drop) return; drop.classList.remove('drag');
  }));
  $('imc').addEventListener('drop', e => {
    const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) loadFile(f);
  });

  function loadFile(f) {
    if (!f.type || f.type.indexOf('image/') !== 0) { fail(); return; }
    const r = new FileReader();
    r.onerror = fail;
    r.onload = () => {
      const img = new Image();
      img.onload = () => setup(img);
      img.onerror = fail;
      img.src = r.result;
    };
    r.readAsDataURL(f);
  }
  function fail() { err.hidden = false; }

  function setup(img) {
    nw = img.naturalWidth; nh = img.naturalHeight;
    if (!nw || !nh) { fail(); return; }
    err.hidden = true;
    srcImg = img;
    MIN = Math.max(8, Math.round(Math.min(nw, nh) * 0.04));

    const sc = Math.min(1, MAXPREVIEW / Math.max(nw, nh));
    canvas.width = Math.max(1, Math.round(nw * sc));
    canvas.height = Math.max(1, Math.round(nh * sc));
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

    crop = { x: 0, y: 0, w: nw, h: nh };
    setAr('free');
    drop.hidden = true; editor.hidden = false;
    render();
  }

  /* ---- aspect presets --------------------------------------------- */
  function fitRect(ar) {
    let w = nw, h = nw / ar;
    if (h > nh) { h = nh; w = nh * ar; }
    return { x: (nw - w) / 2, y: (nh - h) / 2, w: w, h: h };
  }
  function setAr(token) {
    currentAr = parseAr(token);
    [].forEach.call(seg.children, b => b.classList.toggle('on', b.dataset.ar === token));
    if (currentAr > 0 && srcImg) crop = fitRect(currentAr);
  }
  seg.addEventListener('click', e => {
    const b = e.target.closest('.imc-seg-btn'); if (!b) return;
    setAr(b.dataset.ar); render();
  });
  $('imc-reset').addEventListener('click', () => {
    if (!srcImg) return;
    crop = { x: 0, y: 0, w: nw, h: nh };
    setAr('free'); render();
  });

  /* ---- render ------------------------------------------------------ */
  function render() {
    if (!srcImg) return;
    cropEl.style.insetInlineStart = (crop.x / nw * 100) + '%';
    cropEl.style.top = (crop.y / nh * 100) + '%';
    cropEl.style.width = (crop.w / nw * 100) + '%';
    cropEl.style.height = (crop.h / nh * 100) + '%';
    $('imc-v-orig').textContent = nw + ' × ' + nh + ' ' + PX;
    $('imc-v-pos').textContent = 'X ' + Math.round(crop.x) + ' · Y ' + Math.round(crop.y);
    $('imc-v-out').textContent = Math.round(crop.w) + ' × ' + Math.round(crop.h) + ' ' + PX;
  }

  /* ---- resize maths ------------------------------------------------ */
  function doResize(h, ix, iy, s) {
    const north = h.indexOf('n') > -1, south = h.indexOf('s') > -1,
          west = h.indexOf('w') > -1, east = h.indexOf('e') > -1;
    const corner = (north || south) && (east || west);
    const l0 = s.x, t0 = s.y, r0 = s.x + s.w, b0 = s.y + s.h;

    if (!currentAr) {                                    // free
      let l = l0, t = t0, r = r0, b = b0;
      if (west) l = Math.min(ix, r - MIN);
      if (east) r = Math.max(ix, l + MIN);
      if (north) t = Math.min(iy, b - MIN);
      if (south) b = Math.max(iy, t + MIN);
      l = clamp(l, 0, nw); r = clamp(r, 0, nw); t = clamp(t, 0, nh); b = clamp(b, 0, nh);
      if (r - l < MIN) { if (west) l = Math.max(0, r - MIN); else r = Math.min(nw, l + MIN); }
      if (b - t < MIN) { if (north) t = Math.max(0, b - MIN); else b = Math.min(nh, t + MIN); }
      crop = { x: l, y: t, w: r - l, h: b - t };
      return;
    }

    const ar = currentAr;
    if (corner) {
      const ax = west ? r0 : l0, ay = north ? b0 : t0;
      const dirX = west ? -1 : 1, dirY = north ? -1 : 1;
      const maxW = dirX > 0 ? nw - ax : ax, maxH = dirY > 0 ? nh - ay : ay;
      const roomMax = Math.min(maxW, maxH * ar);
      let w = Math.min(Math.abs(ix - ax), roomMax);
      w = Math.max(w, Math.min(Math.max(MIN, MIN * ar), roomMax));
      const hh = w / ar;
      crop = { x: dirX > 0 ? ax : ax - w, y: dirY > 0 ? ay : ay - hh, w: w, h: hh };
    } else if (east || west) {                           // horizontal edge
      const cy = (t0 + b0) / 2, maxH = 2 * Math.min(cy, nh - cy);
      const room = Math.min(east ? nw - l0 : r0, maxH * ar);
      let w = east ? ix - l0 : r0 - ix;
      w = Math.min(w, room);
      w = Math.max(w, Math.min(Math.max(MIN, MIN * ar), room));
      const hh = w / ar;
      crop = { x: east ? l0 : r0 - w, y: cy - hh / 2, w: w, h: hh };
    } else {                                             // vertical edge (n / s)
      const cx = (l0 + r0) / 2, maxW = 2 * Math.min(cx, nw - cx);
      const room = Math.min(south ? nh - t0 : b0, maxW / ar);
      let hh = south ? iy - t0 : b0 - iy;
      hh = Math.min(hh, room);
      hh = Math.max(hh, Math.min(Math.max(MIN, MIN / ar), room));
      const w = hh * ar;
      crop = { x: cx - w / 2, y: south ? t0 : b0 - hh, w: w, h: hh };
    }
    // defensive bounds
    crop.x = clamp(crop.x, 0, nw); crop.y = clamp(crop.y, 0, nh);
    if (crop.x + crop.w > nw) crop.w = nw - crop.x;
    if (crop.y + crop.h > nh) crop.h = nh - crop.y;
  }

  /* ---- pointer interaction ---------------------------------------- */
  let mode = null, activeHandle = null, startCrop = null, startX = 0, startY = 0, box = null;

  cropEl.addEventListener('pointerdown', e => {
    if (!srcImg) return;
    e.preventDefault();
    box = canvas.getBoundingClientRect();
    activeHandle = e.target && e.target.dataset ? e.target.dataset.h : null;
    mode = activeHandle ? 'resize' : 'move';
    startCrop = { x: crop.x, y: crop.y, w: crop.w, h: crop.h };
    startX = e.clientX; startY = e.clientY;
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
    window.addEventListener('pointercancel', onUp);
  });

  function onMove(e) {
    if (!mode) return;
    e.preventDefault();
    if (mode === 'move') {
      const dx = (e.clientX - startX) / box.width * nw;
      const dy = (e.clientY - startY) / box.height * nh;
      crop = {
        x: clamp(startCrop.x + dx, 0, nw - startCrop.w),
        y: clamp(startCrop.y + dy, 0, nh - startCrop.h),
        w: startCrop.w, h: startCrop.h
      };
    } else {
      const ix = clamp((e.clientX - box.left) / box.width * nw, 0, nw);
      const iy = clamp((e.clientY - box.top) / box.height * nh, 0, nh);
      doResize(activeHandle, ix, iy, startCrop);
    }
    render();
  }
  function onUp() {
    mode = null; activeHandle = null;
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onUp);
    window.removeEventListener('pointercancel', onUp);
  }

  /* ---- download ---------------------------------------------------- */
  $('imc-dl').addEventListener('click', () => {
    if (!srcImg) return;
    let sx = clamp(Math.round(crop.x), 0, nw - 1);
    let sy = clamp(Math.round(crop.y), 0, nh - 1);
    let sw = clamp(Math.round(crop.w), 1, nw - sx);
    let sh = clamp(Math.round(crop.h), 1, nh - sy);

    const fmt = $('imc-fmt').value;
    const oc = document.createElement('canvas');
    oc.width = sw; oc.height = sh;
    const octx = oc.getContext('2d');
    if (fmt === 'jpeg') { octx.fillStyle = '#fff'; octx.fillRect(0, 0, sw, sh); }
    octx.drawImage(srcImg, sx, sy, sw, sh, 0, 0, sw, sh);

    const type = fmt === 'jpeg' ? 'image/jpeg' : 'image/png';
    const ext = fmt === 'jpeg' ? 'jpg' : 'png';
    oc.toBlob(blob => {
      if (!blob) return;
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = 'cropped-' + sw + 'x' + sh + '.' + ext;
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1500);
    }, type, fmt === 'jpeg' ? 0.92 : undefined);
  });
})();
</script>
