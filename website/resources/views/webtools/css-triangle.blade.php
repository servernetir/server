<style>
.ctg-stage{position:relative;display:grid;place-items:center;min-height:290px;padding:34px;border-radius:16px;border:1px solid var(--line);background-color:var(--surface-2);background-image:linear-gradient(var(--line) 1px,transparent 1px),linear-gradient(90deg,var(--line) 1px,transparent 1px);background-size:24px 24px;background-position:center;overflow:auto}
.ctg-stage.ctg-showbox #ctg-tri{outline:1px dashed var(--cyan);outline-offset:0}
.ctg-badge{position:absolute;inset-block-start:10px;inset-inline-start:12px;font-size:11.5px;color:var(--dim);background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:3px 9px;font-variant-numeric:tabular-nums}
.ctg-note{font-size:12.5px;color:var(--muted);line-height:1.9;margin-top:12px;border-inline-start:2px solid var(--cyan);padding-inline-start:11px}
.ctg-note:empty{display:none}
.ctg-lbl{font-size:11.5px;color:var(--dim);text-transform:uppercase;letter-spacing:.09em;margin:20px 0 9px}
.ctg-dirs{direction:ltr;display:grid;grid-template-columns:repeat(3,1fr);gap:9px;max-width:340px}
.ctg-cell{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;min-height:72px;padding:8px 4px;border:1px solid var(--line-2);border-radius:12px;background:var(--surface-2);color:var(--muted);font-family:var(--font-body);font-size:10px;line-height:1.25;text-align:center;cursor:pointer;appearance:none;transition:border-color .15s,color .15s}
.ctg-cell:hover{border-color:var(--cyan);color:var(--text)}
.ctg-cell[aria-pressed="true"]{border-color:var(--cyan);color:var(--cyan);box-shadow:inset 0 0 0 1px var(--cyan)}
.ctg-mid{display:flex;align-items:center;justify-content:center;border:1px dashed var(--line);border-radius:12px;color:var(--dim)}
.ctg-mid .icon{width:18px;height:18px;opacity:.45}
.ctg-m{width:0;height:0;border:0 solid transparent;flex:none}
.ctg-m.up{border-inline:9px solid transparent;border-block-end:12px solid currentColor}
.ctg-m.down{border-inline:9px solid transparent;border-block-start:12px solid currentColor}
.ctg-m.left{border-block:9px solid transparent;border-inline-end:12px solid currentColor}
.ctg-m.right{border-block:9px solid transparent;border-inline-start:12px solid currentColor}
.ctg-m.tl{border-block-start:14px solid currentColor;border-inline-end:14px solid transparent}
.ctg-m.tr{border-block-start:14px solid currentColor;border-inline-start:14px solid transparent}
.ctg-m.bl{border-block-end:14px solid currentColor;border-inline-end:14px solid transparent}
.ctg-m.br{border-block-end:14px solid currentColor;border-inline-start:14px solid transparent}
.ctg-num{width:70px;background:var(--surface-2);border:1px solid var(--line-2);border-radius:9px;color:var(--text);padding:5px 8px;font-family:var(--font-body);font-size:13px;text-align:center;outline:none}
.ctg-num:focus,.ctg-txt:focus{border-color:var(--cyan)}
.ctg-txt{background:var(--surface-2);border:1px solid var(--line-2);border-radius:9px;color:var(--text);padding:6px 10px;font-family:var(--font-body);font-size:13px;min-width:130px;outline:none}
.ctg-hint{font-size:11.5px;color:var(--dim);line-height:1.9;margin-top:8px}
.ctg-info{margin-top:24px;border:1px solid var(--line);border-radius:16px;background:var(--surface-2);padding:22px;display:grid;grid-template-columns:repeat(auto-fit,minmax(235px,1fr));gap:24px;align-items:start}
.ctg-info h4{font-family:var(--font-disp);font-size:14.5px;color:var(--text);margin:0 0 10px}
.ctg-info p{font-size:12.8px;line-height:2.05;color:var(--muted);margin:0 0 9px}
.ctg-info p:last-child{margin-bottom:0}
.ctg-info code{color:var(--cyan);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}
.ctg-wedgewrap{display:grid;place-items:center;gap:12px;padding-block:4px}
.ctg-wedge{width:0;height:0;border:34px solid;border-block-start-color:var(--cyan);border-inline-end-color:var(--violet);border-block-end-color:var(--green);border-inline-start-color:var(--blue)}
.ctg-cap{font-size:11.5px;color:var(--dim);text-align:center;max-width:230px;line-height:1.9}
</style>

<div class="ctg-stage ctg-showbox" id="ctg-stage">
  <span class="ctg-badge" id="ctg-badge" dir="ltr"></span>
  <div id="ctg-tri"></div>
</div>
<div class="ctg-note" id="ctg-note" data-log="{{ __('ui.wt_ctg_note_logical') }}" data-phys="{{ __('ui.wt_ctg_note_physical') }}"></div>

<div class="ctg-lbl">{{ __('ui.wt_ctg_dir') }}</div>
<div class="ctg-dirs" id="ctg-dirs">
  <button type="button" class="ctg-cell" data-dir="tl"><span class="ctg-m tl"></span>{{ __('ui.wt_ctg_tl') }}</button>
  <button type="button" class="ctg-cell" data-dir="up"><span class="ctg-m up"></span>{{ __('ui.wt_ctg_up') }}</button>
  <button type="button" class="ctg-cell" data-dir="tr"><span class="ctg-m tr"></span>{{ __('ui.wt_ctg_tr') }}</button>
  <button type="button" class="ctg-cell" data-dir="left"><span class="ctg-m left"></span>{{ __('ui.wt_ctg_left') }}</button>
  <div class="ctg-mid"><svg class="icon"><use href="#i-box"/></svg></div>
  <button type="button" class="ctg-cell" data-dir="right"><span class="ctg-m right"></span>{{ __('ui.wt_ctg_right') }}</button>
  <button type="button" class="ctg-cell" data-dir="bl"><span class="ctg-m bl"></span>{{ __('ui.wt_ctg_bl') }}</button>
  <button type="button" class="ctg-cell" data-dir="down"><span class="ctg-m down"></span>{{ __('ui.wt_ctg_down') }}</button>
  <button type="button" class="ctg-cell" data-dir="br"><span class="ctg-m br"></span>{{ __('ui.wt_ctg_br') }}</button>
</div>

<div class="wt-fields">
  <div class="wt-range"><span>{{ __('ui.wt_ctg_width') }}</span>
    <input type="range" id="ctg-w" min="2" max="300" value="80">
    <input type="number" id="ctg-wn" class="ctg-num" min="2" max="300" value="80" dir="ltr">
  </div>
  <div class="wt-range"><span>{{ __('ui.wt_ctg_height') }}</span>
    <input type="range" id="ctg-h" min="2" max="300" value="40">
    <input type="number" id="ctg-hn" class="ctg-num" min="2" max="300" value="40" dir="ltr">
  </div>
  <label class="wt-chk"><input type="checkbox" id="ctg-lock"> {{ __('ui.wt_ctg_lock') }}</label>
</div>

<div class="wt-fields">
  <div class="wt-range"><span>{{ __('ui.wt_ctg_color') }}</span>
    <input type="color" id="ctg-color" class="wt-color sm" value="#22d3ee">
    <input type="text" id="ctg-hex" class="ctg-txt" value="#22d3ee" dir="ltr" spellcheck="false" style="min-width:112px">
  </div>
  <div class="wt-range"><span>{{ __('ui.wt_ctg_selector') }}</span>
    <input type="text" id="ctg-sel" class="ctg-txt" value=".triangle" dir="ltr" spellcheck="false" maxlength="60">
  </div>
  <span class="wt-status" id="ctg-status" data-bad="{{ __('ui.wt_ctg_badhex') }}"></span>
</div>

<div class="wt-fields">
  <label class="wt-chk"><input type="checkbox" id="ctg-logical"> {{ __('ui.wt_ctg_logical') }}</label>
  <label class="wt-chk"><input type="checkbox" id="ctg-showbox" checked> {{ __('ui.wt_ctg_showbox') }}</label>
</div>

<div class="wt-two" style="margin-top:18px">
  <div class="wt-pane">
    <label>{{ __('ui.wt_ctg_css_border') }}</label>
    <textarea id="ctg-out" class="wt-ta" rows="7" readonly dir="ltr"></textarea>
    <div class="wt-bar"><button type="button" class="btn btn-glass" id="ctg-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button></div>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_ctg_css_clip') }}</label>
    <textarea id="ctg-clip" class="wt-ta" rows="7" readonly dir="ltr"></textarea>
    <div class="wt-bar"><button type="button" class="btn btn-glass" id="ctg-copy2" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button></div>
    <div class="ctg-hint">{{ __('ui.wt_ctg_clip_hint') }}</div>
  </div>
</div>

<div class="ctg-info">
  <div>
    <h4>{{ __('ui.wt_ctg_how') }}</h4>
    <p>{{ __('ui.wt_ctg_how_1') }}</p>
    <p>{{ __('ui.wt_ctg_how_2') }}</p>
    <p>{{ __('ui.wt_ctg_how_3') }}</p>
  </div>
  <div class="ctg-wedgewrap">
    <div class="ctg-wedge"></div>
    <div class="ctg-cap">{{ __('ui.wt_ctg_cap') }}</div>
  </div>
  <div>
    <h4>{{ __('ui.wt_ctg_rtl_title') }}</h4>
    <p>{{ __('ui.wt_ctg_rtl_1') }}</p>
    <p>{{ __('ui.wt_ctg_rtl_2') }}</p>
  </div>
</div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const NL = String.fromCharCode(10);

  // s = start side (left in LTR), e = end side (right in LTR)
  const PHYS = { t:'border-top', e:'border-right', b:'border-bottom', s:'border-left' };
  const LOG  = { t:'border-block-start', e:'border-inline-end', b:'border-block-end', s:'border-inline-start' };

  const CLIP = {
    up:    'polygon(50% 0, 100% 100%, 0 100%)',
    down:  'polygon(0 0, 100% 0, 50% 100%)',
    left:  'polygon(0 50%, 100% 0, 100% 100%)',
    right: 'polygon(0 0, 100% 50%, 0 100%)',
    tl:    'polygon(0 0, 100% 0, 0 100%)',
    tr:    'polygon(0 0, 100% 0, 100% 100%)',
    bl:    'polygon(0 0, 100% 100%, 0 100%)',
    br:    'polygon(100% 0, 100% 100%, 0 100%)'
  };

  // Each entry: [side key, border width, colour or null for transparent]
  function sides(dir, w, h, c) {
    const hw = w / 2, hh = h / 2;
    switch (dir) {
      case 'up':    return [['s',hw,null],['e',hw,null],['b',h,c]];
      case 'down':  return [['s',hw,null],['e',hw,null],['t',h,c]];
      case 'left':  return [['t',hh,null],['b',hh,null],['e',w,c]];
      case 'right': return [['t',hh,null],['b',hh,null],['s',w,c]];
      case 'tl':    return [['t',h,c],['e',w,null]];
      case 'tr':    return [['t',h,c],['s',w,null]];
      case 'bl':    return [['b',h,c],['e',w,null]];
      case 'br':    return [['b',h,c],['s',w,null]];
    }
    return [];
  }

  let dir = 'up';
  let hex = '#22d3ee';

  const fmt = n => (Math.round(n * 1000) / 1000).toString();
  const clamp = v => Math.min(300, Math.max(2, Math.round(v)));
  const W = () => +$('ctg-w').value;
  const H = () => +$('ctg-h').value;
  const setW = v => { $('ctg-w').value = v; $('ctg-wn').value = v; };
  const setH = v => { $('ctg-h').value = v; $('ctg-hn').value = v; };

  // straight triangles lock to equilateral, diagonals lock to a 45 degree right triangle
  function ratio() {
    if (dir === 'up' || dir === 'down') return Math.sqrt(3) / 2;
    if (dir === 'left' || dir === 'right') return 2 / Math.sqrt(3);
    return 1;
  }

  function render() {
    const w = W(), h = H();
    const map = $('ctg-logical').checked ? LOG : PHYS;
    const list = sides(dir, w, h, hex);
    const decls = list.map(s => map[s[0]] + ': ' + fmt(s[1]) + 'px solid ' + (s[2] || 'transparent') + ';');
    const sel = ($('ctg-sel').value.trim() || '.triangle');

    $('ctg-out').value = sel + ' {' + NL + '  ' +
      ['width: 0;', 'height: 0;'].concat(decls).join(NL + '  ') + NL + '}';

    $('ctg-clip').value = sel + ' {' + NL +
      '  width: ' + fmt(w) + 'px;' + NL +
      '  height: ' + fmt(h) + 'px;' + NL +
      '  background: ' + hex + ';' + NL +
      '  clip-path: ' + CLIP[dir] + ';' + NL + '}';

    $('ctg-tri').style.cssText = 'width:0;height:0;border:0 solid transparent;' + decls.join('');
    $('ctg-badge').textContent = fmt(w) + ' × ' + fmt(h) + ' px';

    const note = $('ctg-note');
    note.textContent = (dir === 'up' || dir === 'down') ? ''
      : ($('ctg-logical').checked ? note.dataset.log : note.dataset.phys);
  }

  function resize(which, val) {
    const v = clamp(val);
    if (which === 'w') { setW(v); if ($('ctg-lock').checked) setH(clamp(v * ratio())); }
    else { setH(v); if ($('ctg-lock').checked) setW(clamp(v / ratio())); }
    render();
  }

  function pickDir(d) {
    dir = d;
    document.querySelectorAll('#ctg-dirs .ctg-cell').forEach(b =>
      b.setAttribute('aria-pressed', b.dataset.dir === d ? 'true' : 'false'));
    if ($('ctg-lock').checked) setH(clamp(W() * ratio()));
    render();
  }

  function normHex(v) {
    let s = v.trim().toLowerCase();
    if (s.charAt(0) === '#') s = s.slice(1);
    if (/^[0-9a-f]{3}$/.test(s)) s = s.charAt(0)+s.charAt(0)+s.charAt(1)+s.charAt(1)+s.charAt(2)+s.charAt(2);
    return /^[0-9a-f]{6}$/.test(s) ? '#' + s : null;
  }

  document.querySelectorAll('#ctg-dirs .ctg-cell').forEach(b =>
    b.addEventListener('click', () => pickDir(b.dataset.dir)));

  $('ctg-w').addEventListener('input', () => resize('w', +$('ctg-w').value));
  $('ctg-h').addEventListener('input', () => resize('h', +$('ctg-h').value));
  ['wn', 'hn'].forEach(k => {
    const el = $('ctg-' + k);
    const go = () => { if (el.value !== '') resize(k.charAt(0), +el.value); };
    el.addEventListener('input', go);
    el.addEventListener('change', () => { if (el.value === '') el.value = k === 'wn' ? W() : H(); go(); });
  });

  $('ctg-lock').addEventListener('change', () => {
    if ($('ctg-lock').checked) setH(clamp(W() * ratio()));
    render();
  });

  $('ctg-color').addEventListener('input', () => {
    hex = $('ctg-color').value.toLowerCase();
    $('ctg-hex').value = hex;
    $('ctg-status').textContent = '';
    $('ctg-status').classList.remove('err');
    render();
  });

  $('ctg-hex').addEventListener('input', () => {
    const n = normHex($('ctg-hex').value);
    if (n) {
      hex = n;
      $('ctg-color').value = n;
      $('ctg-status').textContent = '';
      $('ctg-status').classList.remove('err');
      render();
    } else {
      $('ctg-status').textContent = $('ctg-status').dataset.bad;
      $('ctg-status').classList.add('err');
    }
  });
  $('ctg-hex').addEventListener('blur', () => { $('ctg-hex').value = hex; $('ctg-status').textContent = ''; $('ctg-status').classList.remove('err'); });

  $('ctg-sel').addEventListener('input', render);
  $('ctg-logical').addEventListener('change', render);
  $('ctg-showbox').addEventListener('change', () =>
    $('ctg-stage').classList.toggle('ctg-showbox', $('ctg-showbox').checked));

  $('ctg-copy').onclick = e => wtCopy(e.currentTarget, $('ctg-out').value);
  $('ctg-copy2').onclick = e => wtCopy(e.currentTarget, $('ctg-clip').value);

  pickDir('up');
})();
</script>
