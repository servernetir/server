<style>
.gm-stage{position:relative;display:grid;place-items:center;min-height:300px;border-radius:18px;border:1px solid var(--line);overflow:hidden;padding:36px 22px;isolation:isolate}
.gm-bd-aurora{background:radial-gradient(circle at 18% 22%,#22d3ee 0,transparent 46%),radial-gradient(circle at 84% 26%,#8b5cf6 0,transparent 46%),radial-gradient(circle at 50% 92%,#3b82f6 0,transparent 52%),linear-gradient(135deg,#0b1220,#141b36)}
.gm-bd-sunset{background:radial-gradient(circle at 20% 82%,#f97316 0,transparent 50%),radial-gradient(circle at 80% 18%,#ec4899 0,transparent 50%),radial-gradient(circle at 52% 48%,#7c3aed 0,transparent 58%),linear-gradient(160deg,#1c0f2b,#2b1030)}
.gm-bd-mesh{background:radial-gradient(circle at 14% 30%,#34d399 0,transparent 46%),radial-gradient(circle at 86% 66%,#22d3ee 0,transparent 46%),radial-gradient(circle at 56% 8%,#a3e635 0,transparent 42%),linear-gradient(120deg,#052e2b,#083344)}
.gm-bd-stripes{background:repeating-linear-gradient(45deg,#ef4444 0 34px,#f8fafc 34px 68px,#2563eb 68px 102px,#facc15 102px 136px)}
.gm-bd-grid{background-image:linear-gradient(rgba(255,255,255,.55) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.55) 1px,transparent 1px),linear-gradient(135deg,#0ea5e9,#8b5cf6);background-size:30px 30px,30px 30px,100% 100%}
.gm-card{position:relative;z-index:1;inline-size:min(340px,100%);padding:24px 22px;color:#fff;text-align:start;transition:background .12s ease,border-radius .12s ease}
.gm-card h3{font-family:var(--font-disp);font-size:19px;font-weight:700;margin:0 0 9px;color:#fff}
.gm-card p{margin:0 0 16px;font-size:13.5px;line-height:1.85;color:rgba(255,255,255,.84)}
.gm-pill{display:inline-block;padding:7px 15px;border-radius:999px;font-size:12.5px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.32);color:#fff}
.gm-chips{display:flex;flex-wrap:wrap;gap:9px;margin-top:14px;align-items:center}
.gm-chip{inline-size:48px;block-size:31px;border-radius:9px;border:1px solid var(--line-2);cursor:pointer;padding:0}
.gm-chip.on{outline:2px solid var(--cyan);outline-offset:2px}
.gm-sec{font-size:11.5px;color:var(--dim);text-transform:uppercase;letter-spacing:.09em;margin:18px 0 2px}
.gm-inp{background:var(--surface-2);border:1px solid var(--line-2);border-radius:9px;color:var(--text);padding:6px 10px;font-family:ui-monospace,monospace;font-size:13px;inline-size:170px;outline:none}
.gm-inp:focus{border-color:var(--cyan)}
.gm-note{display:flex;gap:10px;align-items:flex-start;margin-top:14px;padding:12px 14px;border-radius:12px;background:var(--surface-2);border:1px solid var(--line);border-inline-start:3px solid var(--cyan);font-size:12.5px;line-height:1.95;color:var(--muted)}
.gm-note .icon{inline-size:16px;block-size:16px;margin-top:5px;color:var(--cyan)}
.gm-presets{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.gm-presets .btn{font-size:12.5px;padding:7px 13px}
.gm-off{opacity:.4}
html[data-theme="light"] .gm-stage{border-color:rgba(0,0,0,.14)}
html[data-theme="light"] .gm-chip{border-color:rgba(0,0,0,.16)}
html[data-theme="light"] .gm-note{background:rgba(0,0,0,.03);border-color:rgba(0,0,0,.08);border-inline-start-color:var(--cyan)}
</style>

<div class="gm-stage gm-bd-aurora" id="gm-stage">
  <div class="gm-card" id="gm-card">
    <h3>{{ __('ui.wt_gm_card_title') }}</h3>
    <p>{{ __('ui.wt_gm_card_text') }}</p>
    <span class="gm-pill">{{ __('ui.wt_gm_card_btn') }}</span>
  </div>
</div>

<div class="gm-chips" id="gm-chips">
  <span class="wt-status">{{ __('ui.wt_gm_backdrop') }}</span>
  <button type="button" class="gm-chip gm-bd-aurora on" data-bd="aurora" title="{{ __('ui.wt_gm_bd_aurora') }}" aria-label="{{ __('ui.wt_gm_bd_aurora') }}"></button>
  <button type="button" class="gm-chip gm-bd-sunset" data-bd="sunset" title="{{ __('ui.wt_gm_bd_sunset') }}" aria-label="{{ __('ui.wt_gm_bd_sunset') }}"></button>
  <button type="button" class="gm-chip gm-bd-mesh" data-bd="mesh" title="{{ __('ui.wt_gm_bd_mesh') }}" aria-label="{{ __('ui.wt_gm_bd_mesh') }}"></button>
  <button type="button" class="gm-chip gm-bd-stripes" data-bd="stripes" title="{{ __('ui.wt_gm_bd_stripes') }}" aria-label="{{ __('ui.wt_gm_bd_stripes') }}"></button>
  <button type="button" class="gm-chip gm-bd-grid" data-bd="grid" title="{{ __('ui.wt_gm_bd_grid') }}" aria-label="{{ __('ui.wt_gm_bd_grid') }}"></button>
</div>

<div class="gm-sec">{{ __('ui.wt_gm_layer') }}</div>
<div class="wt-fields" style="border:0;margin-top:6px">
  <label class="wt-range">{{ __('ui.wt_gm_blur') }}: <b id="gm-blurn" dir="ltr">12px</b><input type="range" id="gm-blur" min="0" max="40" value="12"></label>
  <label class="wt-range">{{ __('ui.wt_gm_opacity') }}: <b id="gm-opn" dir="ltr">15%</b><input type="range" id="gm-op" min="0" max="100" value="15"></label>
  <label class="wt-range">{{ __('ui.wt_gm_tint') }} <input type="color" id="gm-tint" value="#ffffff" class="wt-color sm"></label>
</div>
<div class="wt-fields" style="border:0;margin-top:2px">
  <label class="wt-range">{{ __('ui.wt_gm_saturate') }}: <b id="gm-satn" dir="ltr">160%</b><input type="range" id="gm-sat" min="50" max="260" step="5" value="160"></label>
  <label class="wt-range">{{ __('ui.wt_gm_bright') }}: <b id="gm-brin" dir="ltr">100%</b><input type="range" id="gm-bri" min="50" max="160" step="5" value="100"></label>
</div>

<div class="gm-sec">{{ __('ui.wt_gm_edge') }}</div>
<div class="wt-fields" style="border:0;margin-top:6px">
  <label class="wt-range">{{ __('ui.wt_gm_bwidth') }}: <b id="gm-bwn" dir="ltr">1px</b><input type="range" id="gm-bw" min="0" max="6" value="1"></label>
  <label class="wt-range">{{ __('ui.wt_gm_bopacity') }}: <b id="gm-bopn" dir="ltr">28%</b><input type="range" id="gm-bop" min="0" max="100" value="28"></label>
  <label class="wt-range">{{ __('ui.wt_gm_bcolor') }} <input type="color" id="gm-bc" value="#ffffff" class="wt-color sm"></label>
  <label class="wt-range">{{ __('ui.wt_gm_radius') }}: <b id="gm-radn" dir="ltr">16px</b><input type="range" id="gm-rad" min="0" max="60" value="16"></label>
</div>

<div class="gm-sec">{{ __('ui.wt_gm_shadow') }}</div>
<div class="wt-fields" style="border:0;margin-top:6px">
  <label class="wt-range">{{ __('ui.wt_gm_soffset') }}: <b id="gm-syn" dir="ltr">8px</b><input type="range" id="gm-sy" min="0" max="60" value="8"></label>
  <label class="wt-range">{{ __('ui.wt_gm_sblur') }}: <b id="gm-sbn" dir="ltr">32px</b><input type="range" id="gm-sb" min="0" max="100" value="32"></label>
  <label class="wt-range">{{ __('ui.wt_gm_sopacity') }}: <b id="gm-sopn" dir="ltr">24%</b><input type="range" id="gm-sop" min="0" max="100" value="24"></label>
</div>
<div class="wt-fields" style="border:0;margin-top:2px">
  <label class="wt-chk"><input type="checkbox" id="gm-hl" checked> {{ __('ui.wt_gm_highlight') }}</label>
  <label class="wt-range" id="gm-hlw">{{ __('ui.wt_gm_hlopacity') }}: <b id="gm-hlopn" dir="ltr">30%</b><input type="range" id="gm-hlop" min="0" max="100" value="30"></label>
</div>

<div class="gm-sec">{{ __('ui.wt_gm_presets') }}</div>
<div class="gm-presets" id="gm-presets">
  <button type="button" class="btn btn-glass" data-p="subtle">{{ __('ui.wt_gm_p_subtle') }}</button>
  <button type="button" class="btn btn-glass" data-p="frosted">{{ __('ui.wt_gm_p_frosted') }}</button>
  <button type="button" class="btn btn-glass" data-p="heavy">{{ __('ui.wt_gm_p_heavy') }}</button>
  <button type="button" class="btn btn-glass" data-p="dark">{{ __('ui.wt_gm_p_dark') }}</button>
  <button type="button" class="btn btn-glass" data-p="crisp">{{ __('ui.wt_gm_p_crisp') }}</button>
</div>

<div class="wt-fields" style="margin-top:18px">
  <label class="wt-range">{{ __('ui.wt_gm_selector') }} <input type="text" id="gm-sel" class="gm-inp" value=".glass-card" dir="ltr" spellcheck="false"></label>
  <label class="wt-chk"><input type="checkbox" id="gm-fb"> {{ __('ui.wt_gm_fallback') }}</label>
  <label class="wt-range gm-off" id="gm-fbw">{{ __('ui.wt_gm_fbopacity') }}: <b id="gm-fbopn" dir="ltr">75%</b><input type="range" id="gm-fbop" min="0" max="100" value="75" disabled></label>
</div>

<div class="wt-pane" style="margin-top:16px">
  <label>CSS</label>
  <textarea id="gm-out" class="wt-ta" rows="11" readonly dir="ltr" spellcheck="false"></textarea>
</div>

<div class="wt-bar">
  <button type="button" class="btn btn-glass" id="gm-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  <button type="button" class="btn btn-glass" id="gm-reset">{{ __('ui.wt_gm_reset') }}</button>
</div>

<div class="gm-note">
  <svg class="icon"><use href="#i-globe"/></svg>
  <span>{{ __('ui.wt_gm_note') }}</span>
</div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const AT = String.fromCharCode(64);   // at-sign, kept out of the Blade parser
  const NL = String.fromCharCode(10);   // newline, kept out of the Blade string escaper

  const BACKDROPS = ['aurora', 'sunset', 'mesh', 'stripes', 'grid'];

  // 0-100 -> css alpha, trimmed:  15 -> "0.15", 8 -> "0.08", 100 -> "1", 0 -> "0"
  const al = v => String(parseFloat((Math.max(0, Math.min(100, v)) / 100).toFixed(3)));

  function rgba(hex, pct) {
    const h = String(hex || '#ffffff').replace('#', '');
    const r = parseInt(h.slice(0, 2), 16) || 0;
    const g = parseInt(h.slice(2, 4), 16) || 0;
    const b = parseInt(h.slice(4, 6), 16) || 0;
    return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + al(pct) + ')';
  }

  function selector() {
    const raw = String($('gm-sel').value || '')
      .replace(/[<>;{}]/g, '')
      .split(' ').filter(Boolean).join(' ');
    return raw.length ? raw : '.glass-card';
  }

  function run() {
    const blur = +$('gm-blur').value;
    const op   = +$('gm-op').value;
    const sat  = +$('gm-sat').value;
    const bri  = +$('gm-bri').value;
    const bw   = +$('gm-bw').value;
    const bop  = +$('gm-bop').value;
    const rad  = +$('gm-rad').value;
    const sy   = +$('gm-sy').value;
    const sb   = +$('gm-sb').value;
    const sop  = +$('gm-sop').value;
    const hlon = $('gm-hl').checked;
    const hlop = +$('gm-hlop').value;
    const fbon = $('gm-fb').checked;
    const fbop = +$('gm-fbop').value;

    $('gm-blurn').textContent = blur + 'px';
    $('gm-opn').textContent   = op + '%';
    $('gm-satn').textContent  = sat + '%';
    $('gm-brin').textContent  = bri + '%';
    $('gm-bwn').textContent   = bw + 'px';
    $('gm-bopn').textContent  = bop + '%';
    $('gm-radn').textContent  = rad + 'px';
    $('gm-syn').textContent   = sy + 'px';
    $('gm-sbn').textContent   = sb + 'px';
    $('gm-sopn').textContent  = sop + '%';
    $('gm-hlopn').textContent = hlop + '%';
    $('gm-fbopn').textContent = fbop + '%';

    $('gm-hlop').disabled = !hlon;
    $('gm-hlw').classList.toggle('gm-off', !hlon);
    $('gm-fbop').disabled = !fbon;
    $('gm-fbw').classList.toggle('gm-off', !fbon);

    const tintHex = $('gm-tint').value;
    const tint = rgba(tintHex, op);
    const bcol = rgba($('gm-bc').value, bop);

    const parts = [];
    if (blur > 0) parts.push('blur(' + blur + 'px)');
    if (sat !== 100) parts.push('saturate(' + sat + '%)');
    if (bri !== 100) parts.push('brightness(' + bri + '%)');
    const filt = parts.join(' ');

    const sh = [];
    if (sop > 0) sh.push('0 ' + sy + 'px ' + sb + 'px rgba(0, 0, 0, ' + al(sop) + ')');
    if (hlon) sh.push('inset 0 1px 0 rgba(255, 255, 255, ' + al(hlop) + ')');

    // live preview
    const card = $('gm-card').style;
    card.background = tint;
    card.setProperty('-webkit-backdrop-filter', filt || 'none');
    card.setProperty('backdrop-filter', filt || 'none');
    card.border = bw > 0 ? bw + 'px solid ' + bcol : 'none';
    card.borderRadius = rad + 'px';
    card.boxShadow = sh.length ? sh.join(', ') : 'none';

    // css output
    const sel = selector();
    const L = [];
    L.push(sel + ' {');
    L.push('  background: ' + tint + ';');
    if (filt) {
      L.push('  -webkit-backdrop-filter: ' + filt + ';');
      L.push('  backdrop-filter: ' + filt + ';');
    }
    if (bw > 0) L.push('  border: ' + bw + 'px solid ' + bcol + ';');
    L.push('  border-radius: ' + rad + 'px;');
    if (sh.length) L.push('  box-shadow: ' + sh.join(', ') + ';');
    L.push('}');

    if (fbon) {
      L.push('');
      L.push(AT + 'supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {');
      L.push('  ' + sel + ' {');
      L.push('    background: ' + rgba(tintHex, fbop) + ';');
      L.push('  }');
      L.push('}');
    }

    $('gm-out').value = L.join(NL);
  }

  const PRESETS = {
    subtle:  { blur:6,  op:8,  sat:120, bri:100, tint:'#ffffff', bw:1, bop:18, bc:'#ffffff', rad:14, sy:4,  sb:16, sop:16, hl:true,  hlop:22 },
    frosted: { blur:12, op:15, sat:160, bri:100, tint:'#ffffff', bw:1, bop:28, bc:'#ffffff', rad:16, sy:8,  sb:32, sop:24, hl:true,  hlop:30 },
    heavy:   { blur:26, op:26, sat:190, bri:105, tint:'#ffffff', bw:1, bop:40, bc:'#ffffff', rad:24, sy:14, sb:48, sop:34, hl:true,  hlop:45 },
    dark:    { blur:16, op:35, sat:150, bri:100, tint:'#000000', bw:1, bop:14, bc:'#ffffff', rad:18, sy:10, sb:40, sop:45, hl:true,  hlop:12 },
    crisp:   { blur:20, op:12, sat:180, bri:100, tint:'#ffffff', bw:0, bop:28, bc:'#ffffff', rad:28, sy:10, sb:30, sop:20, hl:false, hlop:30 }
  };

  function applyPreset(name) {
    const p = PRESETS[name];
    if (!p) return;
    $('gm-blur').value = p.blur; $('gm-op').value  = p.op;
    $('gm-sat').value  = p.sat;  $('gm-bri').value = p.bri;
    $('gm-tint').value = p.tint; $('gm-bw').value  = p.bw;
    $('gm-bop').value  = p.bop;  $('gm-bc').value  = p.bc;
    $('gm-rad').value  = p.rad;  $('gm-sy').value  = p.sy;
    $('gm-sb').value   = p.sb;   $('gm-sop').value = p.sop;
    $('gm-hl').checked = p.hl;   $('gm-hlop').value = p.hlop;
    run();
  }

  function setBackdrop(name) {
    if (BACKDROPS.indexOf(name) < 0) return;
    const stage = $('gm-stage');
    BACKDROPS.forEach(b => stage.classList.remove('gm-bd-' + b));
    stage.classList.add('gm-bd-' + name);
    document.querySelectorAll('#gm-chips .gm-chip').forEach(c =>
      c.classList.toggle('on', c.dataset.bd === name));
  }

  ['gm-blur','gm-op','gm-tint','gm-sat','gm-bri','gm-bw','gm-bop','gm-bc','gm-rad',
   'gm-sy','gm-sb','gm-sop','gm-hl','gm-hlop','gm-fb','gm-fbop','gm-sel'].forEach(id => {
    $(id).addEventListener('input', run);
    $(id).addEventListener('change', run);
  });

  document.querySelectorAll('#gm-chips .gm-chip').forEach(c =>
    c.addEventListener('click', () => setBackdrop(c.dataset.bd)));

  document.querySelectorAll('#gm-presets [data-p]').forEach(b =>
    b.addEventListener('click', () => applyPreset(b.dataset.p)));

  $('gm-reset').addEventListener('click', () => {
    $('gm-sel').value = '.glass-card';
    $('gm-fb').checked = false;
    $('gm-fbop').value = 75;
    setBackdrop('aurora');
    applyPreset('frosted');
  });

  $('gm-copy').onclick = e => wtCopy(e.target, $('gm-out').value);

  run();
})();
</script>
