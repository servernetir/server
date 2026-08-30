<style>
  .imp-drop{display:block;border:2px dashed var(--line-2);border-radius:14px;padding:32px 20px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;background:var(--surface-2)}
  .imp-drop:hover,.imp-drop.imp-over{border-color:var(--cyan);background:rgba(34,211,238,.07)}
  .imp-drop .icon{width:34px;height:34px;color:var(--dim);margin-bottom:10px}
  .imp-drop b{display:block;color:var(--text);font-size:15px;font-weight:600;margin-bottom:6px}
  .imp-drop small{display:block;color:var(--dim);font-size:12.5px;line-height:1.6}
  .imp-stage{display:none;margin-top:18px}
  .imp-top{display:grid;grid-template-columns:auto 1fr;gap:18px;align-items:center}
  @media(max-width:640px){.imp-top{grid-template-columns:1fr}}
  .imp-thumb{max-width:170px;max-height:150px;width:auto;border-radius:12px;border:1px solid var(--line-2);background:var(--surface-2);display:block}
  .imp-bar{display:flex;height:40px;border-radius:11px;overflow:hidden;border:1px solid var(--line-2)}
  .imp-bar i{display:block;min-width:2px}
  .imp-swgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(116px,1fr));gap:12px;margin-top:16px}
  .imp-sw{position:relative;border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--surface-2);cursor:pointer;padding:0;text-align:start;font-family:var(--font-body);transition:transform .15s,border-color .15s}
  .imp-sw:hover{transform:translateY(-2px);border-color:var(--cyan)}
  .imp-chip{display:block;height:64px}
  .imp-meta{padding:9px 11px}
  .imp-hex{display:block;font-size:13px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--text)}
  .imp-pct{display:block;font-size:12px;color:var(--muted);margin-top:2px}
  .imp-sw::after{content:'✓';position:absolute;inset-block-start:8px;inset-inline-end:8px;width:22px;height:22px;border-radius:50%;background:var(--green);color:#04110b;font-size:13px;font-weight:700;display:grid;place-items:center;opacity:0;transform:scale(.5);transition:.16s}
  .imp-sw.imp-copied::after{opacity:1;transform:scale(1)}
  .imp-avgchip{display:inline-block;width:22px;height:22px;border-radius:6px;border:1px solid var(--line-2);flex:none}
  .imp-hd{font-size:12.5px;font-weight:600;color:var(--dim);margin:20px 0 4px}
  .imp-hint{font-size:12px;color:var(--dim);margin-top:9px;text-align:center}
</style>

<label class="imp-drop" id="imp-drop" for="imp-file">
  <svg class="icon"><use href="#i-layout"/></svg>
  <b>{{ __('ui.wt_imp_drop') }}</b>
  <small>{{ __('ui.wt_imp_hint') }}</small>
  <input type="file" id="imp-file" accept="image/*" hidden>
</label>

<div class="wt-status err" id="imp-err" style="display:none;margin-top:12px"></div>

<div class="wt-fields" id="imp-controls" style="display:none">
  <label class="wt-range">{{ __('ui.wt_imp_colors') }}: <b id="imp-nlab">8</b>
    <input type="range" id="imp-n" min="6" max="10" step="1" value="8">
  </label>
</div>

<div class="imp-stage" id="imp-stage">
  <div class="imp-top">
    <img class="imp-thumb" id="imp-thumb" alt="">
    <div style="min-width:0">
      <div class="imp-hd">{{ __('ui.wt_imp_dominant') }}</div>
      <div class="imp-bar" id="imp-barrow"></div>
    </div>
  </div>

  <div class="imp-swgrid" id="imp-grid"></div>
  <div class="imp-hint">{{ __('ui.wt_imp_copyhint') }}</div>

  <div class="wt-out-box" style="margin-top:16px">
    <div class="wt-out-row">
      <span>{{ __('ui.wt_imp_average') }}</span>
      <i class="imp-avgchip" id="imp-avgchip"></i>
      <b dir="ltr" id="imp-avghex"></b>
      <button class="wt-mini" id="imp-avgcopy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
    </div>
  </div>

  <div class="wt-pane" style="margin-top:16px">
    <label>{{ __('ui.wt_imp_css') }}</label>
    <textarea id="imp-css" class="wt-ta" rows="5" readonly dir="ltr"></textarea>
  </div>
  <div class="wt-bar">
    <button class="btn btn-glass" id="imp-csscopy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  </div>
</div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const ERR = @json(__('ui.wt_imp_err'));
  const MAXS = 160;                 // max sampling dimension — caps work per image
  const state = { px: null, avg: null, total: 0 };

  const clamp = (n, a, b) => n < a ? a : n > b ? b : n;
  const h2 = n => clamp(Math.round(n), 0, 255).toString(16).padStart(2, '0');
  const hex = c => ('#' + h2(c[0]) + h2(c[1]) + h2(c[2])).toUpperCase();
  const fmtPct = p => (p >= 10 ? Math.round(p) : Math.round(p * 10) / 10) + '%';

  // ---- median-cut clustering -> up to `target` buckets ----
  function bucket(px) {
    let mnR = 255, mnG = 255, mnB = 255, mxR = 0, mxG = 0, mxB = 0, sR = 0, sG = 0, sB = 0;
    for (let i = 0; i < px.length; i++) {
      const p = px[i], r = p[0], g = p[1], b = p[2];
      if (r < mnR) mnR = r; if (r > mxR) mxR = r;
      if (g < mnG) mnG = g; if (g > mxG) mxG = g;
      if (b < mnB) mnB = b; if (b > mxB) mxB = b;
      sR += r; sG += g; sB += b;
    }
    const n = px.length || 1;
    return {
      px: px, count: px.length,
      range: [mxR - mnR, mxG - mnG, mxB - mnB],
      avg: [sR / n, sG / n, sB / n]
    };
  }

  function medianCut(px, target) {
    let buckets = [bucket(px)];
    while (buckets.length < target) {
      let idx = -1, best = -1;
      for (let i = 0; i < buckets.length; i++) {
        const b = buckets[i];
        if (b.px.length < 2) continue;
        const r = Math.max(b.range[0], b.range[1], b.range[2]);
        if (r > best) { best = r; idx = i; }
      }
      if (idx < 0 || best <= 0) break;           // nothing left to split
      const b = buckets[idx];
      let ch = 0;
      if (b.range[1] > b.range[ch]) ch = 1;
      if (b.range[2] > b.range[ch]) ch = 2;
      b.px.sort((p, q) => p[ch] - q[ch]);
      const mid = b.px.length >> 1;
      buckets.splice(idx, 1, bucket(b.px.slice(0, mid)), bucket(b.px.slice(mid)));
    }
    return buckets;
  }

  // ---- copy helper (keeps swatch markup intact) ----
  function copy(text, cb) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(cb, () => fallback(text, cb));
    } else { fallback(text, cb); }
  }
  function fallback(text, cb) {
    try {
      const ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select(); document.execCommand('copy');
      document.body.removeChild(ta); cb && cb();
    } catch (e) {}
  }

  // ---- render for current slider value ----
  function render() {
    if (!state.px) return;
    const n = Math.min(+$('imp-n').value, state.px.length) || 1;
    const buckets = medianCut(state.px, n).sort((a, b) => b.count - a.count);
    const total = state.total;

    // proportional bar
    $('imp-barrow').innerHTML = buckets.map(b => {
      const hx = hex(b.avg);
      return '<i style="flex:' + b.count + ';background:' + hx + '" title="' + hx + ' · ' + fmtPct(b.count / total * 100) + '"></i>';
    }).join('');

    // swatch cards
    $('imp-grid').innerHTML = buckets.map(b => {
      const hx = hex(b.avg);
      return '<button type="button" class="imp-sw" data-hex="' + hx + '">' +
        '<span class="imp-chip" style="background:' + hx + '"></span>' +
        '<span class="imp-meta"><span class="imp-hex" dir="ltr">' + hx + '</span>' +
        '<span class="imp-pct" dir="ltr">' + fmtPct(b.count / total * 100) + '</span></span></button>';
    }).join('');
    $('imp-grid').querySelectorAll('.imp-sw').forEach(sw => {
      sw.addEventListener('click', () => copy(sw.dataset.hex, () => {
        sw.classList.add('imp-copied');
        setTimeout(() => sw.classList.remove('imp-copied'), 1300);
      }));
    });

    // average
    const ahex = hex(state.avg);
    $('imp-avgchip').style.background = ahex;
    $('imp-avghex').textContent = ahex;

    // CSS block
    let css = ':root {\n';
    buckets.forEach((b, i) => {
      css += '  --color-' + (i + 1) + ': ' + hex(b.avg) + '; /* ' + fmtPct(b.count / total * 100) + ' */\n';
    });
    css += '  --color-avg: ' + ahex + ';\n}';
    $('imp-css').value = css;
  }

  // ---- decode image -> sample pixels ----
  function process(file) {
    $('imp-err').style.display = 'none';
    const reader = new FileReader();
    reader.onerror = fail;
    reader.onload = () => {
      const img = new Image();
      img.onerror = fail;
      img.onload = () => {
        const iw = img.naturalWidth, ih = img.naturalHeight;
        if (!iw || !ih) return fail();
        const scale = Math.min(1, MAXS / Math.max(iw, ih));
        const w = Math.max(1, Math.round(iw * scale)), h = Math.max(1, Math.round(ih * scale));
        const cv = document.createElement('canvas');
        cv.width = w; cv.height = h;
        const cx = cv.getContext('2d', { willReadFrequently: true });
        cx.drawImage(img, 0, 0, w, h);
        let data;
        try { data = cx.getImageData(0, 0, w, h).data; } catch (e) { return fail(); }

        const px = []; let sR = 0, sG = 0, sB = 0, nn = 0;
        for (let i = 0; i < data.length; i += 4) {
          if (data[i + 3] < 125) continue;       // skip transparent pixels
          const r = data[i], g = data[i + 1], b = data[i + 2];
          px.push([r, g, b]); sR += r; sG += g; sB += b; nn++;
        }
        if (!nn) return fail();

        state.px = px; state.total = nn;
        state.avg = [sR / nn, sG / nn, sB / nn];

        $('imp-thumb').src = img.src;
        $('imp-controls').style.display = 'flex';
        $('imp-stage').style.display = 'block';
        render();
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(file);
  }
  function fail() {
    $('imp-err').textContent = ERR;
    $('imp-err').style.display = 'block';
  }

  // ---- wiring ----
  $('imp-file').addEventListener('change', e => {
    const f = e.target.files && e.target.files[0];
    if (f) process(f);
  });
  const drop = $('imp-drop');
  ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => {
    e.preventDefault(); drop.classList.add('imp-over');
  }));
  ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => {
    e.preventDefault(); drop.classList.remove('imp-over');
  }));
  drop.addEventListener('drop', e => {
    const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) process(f);
  });
  $('imp-n').addEventListener('input', () => { $('imp-nlab').textContent = $('imp-n').value; render(); });
  $('imp-avgcopy').addEventListener('click', e => wtCopy(e.currentTarget, $('imp-avghex').textContent));
  $('imp-csscopy').addEventListener('click', e => wtCopy(e.currentTarget, $('imp-css').value));
})();
</script>
