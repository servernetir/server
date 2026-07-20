<style>
.brg-demo{display:grid;place-items:center;min-height:236px;border-radius:16px;background:var(--surface-2);border:1px solid var(--line);margin-bottom:18px;padding:26px}
.brg-box{width:220px;height:180px;background:linear-gradient(135deg,var(--cyan),var(--violet));box-shadow:0 12px 34px rgba(0,0,0,.28);border-radius:16px;transition:border-radius .12s ease;max-width:100%}
.brg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px 24px;border:0;margin:2px 0}
.brg-corner{display:flex;align-items:center;gap:10px;font-size:13px;color:var(--muted)}
.brg-corner input[type=range]{flex:1;min-width:70px;accent-color:var(--cyan)}
.brg-corner b{color:var(--cyan);min-width:52px;text-align:end;font-variant-numeric:tabular-nums;font-size:12.5px}
.brg-sec{font-size:11.5px;color:var(--dim);text-transform:uppercase;letter-spacing:.09em;margin:14px 0 4px}
.brg-presets{display:flex;flex-wrap:wrap;gap:8px}
.brg-presets .btn{font-size:12.5px;padding:7px 13px}
</style>

<div class="brg-demo"><div class="brg-box" id="brg-box"></div></div>

<div class="wt-fields" style="border:0">
  <label class="wt-range">{{ __('ui.wt_brg_mode') }}
    <select id="brg-mode" class="wt-select">
      <option value="simple">{{ __('ui.wt_brg_simple') }}</option>
      <option value="elliptical">{{ __('ui.wt_brg_elliptical') }}</option>
    </select>
  </label>
  <label class="wt-range" id="brg-unit-w">{{ __('ui.wt_brg_unit') }}
    <select id="brg-unit" class="wt-select"><option value="px">px</option><option value="%">%</option></select>
  </label>
  <label class="wt-chk" id="brg-link-w"><input type="checkbox" id="brg-link"> {{ __('ui.wt_brg_link') }}</label>
</div>

<div class="brg-grid" id="brg-simple">
  <label class="brg-corner">{{ __('ui.wt_brg_tl') }}<input type="range" id="brg-tl" min="0" max="200" value="16"><b id="brg-tln" dir="ltr"></b></label>
  <label class="brg-corner">{{ __('ui.wt_brg_tr') }}<input type="range" id="brg-tr" min="0" max="200" value="16"><b id="brg-trn" dir="ltr"></b></label>
  <label class="brg-corner">{{ __('ui.wt_brg_bl') }}<input type="range" id="brg-bl" min="0" max="200" value="16"><b id="brg-bln" dir="ltr"></b></label>
  <label class="brg-corner">{{ __('ui.wt_brg_br') }}<input type="range" id="brg-br" min="0" max="200" value="16"><b id="brg-brn" dir="ltr"></b></label>
</div>

<div id="brg-ell" style="display:none">
  <div class="brg-sec">{{ __('ui.wt_brg_horizontal') }}</div>
  <div class="brg-grid">
    <label class="brg-corner">{{ __('ui.wt_brg_tl') }}<input type="range" id="brg-h1" min="0" max="100" value="30"><b id="brg-h1n" dir="ltr"></b></label>
    <label class="brg-corner">{{ __('ui.wt_brg_tr') }}<input type="range" id="brg-h2" min="0" max="100" value="70"><b id="brg-h2n" dir="ltr"></b></label>
    <label class="brg-corner">{{ __('ui.wt_brg_bl') }}<input type="range" id="brg-h4" min="0" max="100" value="30"><b id="brg-h4n" dir="ltr"></b></label>
    <label class="brg-corner">{{ __('ui.wt_brg_br') }}<input type="range" id="brg-h3" min="0" max="100" value="70"><b id="brg-h3n" dir="ltr"></b></label>
  </div>
  <div class="brg-sec">{{ __('ui.wt_brg_vertical') }}</div>
  <div class="brg-grid">
    <label class="brg-corner">{{ __('ui.wt_brg_tl') }}<input type="range" id="brg-v1" min="0" max="100" value="30"><b id="brg-v1n" dir="ltr"></b></label>
    <label class="brg-corner">{{ __('ui.wt_brg_tr') }}<input type="range" id="brg-v2" min="0" max="100" value="30"><b id="brg-v2n" dir="ltr"></b></label>
    <label class="brg-corner">{{ __('ui.wt_brg_bl') }}<input type="range" id="brg-v4" min="0" max="100" value="70"><b id="brg-v4n" dir="ltr"></b></label>
    <label class="brg-corner">{{ __('ui.wt_brg_br') }}<input type="range" id="brg-v3" min="0" max="100" value="70"><b id="brg-v3n" dir="ltr"></b></label>
  </div>
</div>

<div class="brg-sec">{{ __('ui.wt_brg_presets') }}</div>
<div class="brg-presets" id="brg-presets">
  <button type="button" class="btn btn-glass" data-preset="rounded">{{ __('ui.wt_brg_rounded') }}</button>
  <button type="button" class="btn btn-glass" data-preset="pill">{{ __('ui.wt_brg_pill') }}</button>
  <button type="button" class="btn btn-glass" data-preset="circle">{{ __('ui.wt_brg_circle') }}</button>
  <button type="button" class="btn btn-glass" data-preset="squircle">{{ __('ui.wt_brg_squircle') }}</button>
  <button type="button" class="btn btn-glass" data-preset="blob">{{ __('ui.wt_brg_blob') }}</button>
  <button type="button" class="btn btn-glass" data-preset="shuffle">{{ __('ui.wt_brg_shuffle') }}</button>
  <button type="button" class="btn btn-glass" data-preset="none">{{ __('ui.wt_brg_none') }}</button>
</div>

<div class="wt-pane" style="margin-top:16px">
  <label>CSS</label>
  <textarea id="brg-out" class="wt-ta" rows="2" readonly dir="ltr"></textarea>
</div>
<div class="wt-bar"><button type="button" class="btn btn-glass" id="brg-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button></div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const CORNERS = ['tl','tr','br','bl'];
  const HK = ['h1','h2','h3','h4'];
  const VK = ['v1','v2','v3','v4'];

  function collapse(vals, unit){
    const [a,b,c,d] = vals, u = v => v + unit;
    if (a===b && b===c && c===d) return u(a);
    if (a===c && b===d) return u(a)+' '+u(b);
    if (b===d) return u(a)+' '+u(b)+' '+u(c);
    return u(a)+' '+u(b)+' '+u(c)+' '+u(d);
  }

  function apply(){
    let value;
    if ($('brg-mode').value === 'simple'){
      const unit = $('brg-unit').value;
      const vals = CORNERS.map(k => +$('brg-'+k).value);
      CORNERS.forEach((k,i) => $('brg-'+k+'n').textContent = vals[i] + unit);
      value = collapse(vals, unit);
    } else {
      const H = HK.map(k => +$('brg-'+k).value);
      const V = VK.map(k => +$('brg-'+k).value);
      HK.forEach((k,i) => $('brg-'+k+'n').textContent = H[i] + '%');
      VK.forEach((k,i) => $('brg-'+k+'n').textContent = V[i] + '%');
      const hs = collapse(H,'%'), vs = collapse(V,'%');
      value = hs === vs ? hs : hs + ' / ' + vs;
    }
    $('brg-box').style.borderRadius = value;
    $('brg-out').value = 'border-radius: ' + value + ';';
  }

  function onMode(){
    const simple = $('brg-mode').value === 'simple';
    $('brg-simple').style.display = simple ? '' : 'none';
    $('brg-ell').style.display = simple ? 'none' : '';
    $('brg-unit-w').style.opacity = simple ? '1' : '.4';
    $('brg-unit').disabled = !simple;
    $('brg-link-w').style.opacity = simple ? '1' : '.4';
    $('brg-link').disabled = !simple;
    apply();
  }

  function onUnit(){
    const max = $('brg-unit').value === 'px' ? 200 : 100;
    CORNERS.forEach(k => { const el = $('brg-'+k); el.max = max; if (+el.value > max) el.value = max; });
    apply();
  }

  const PRESETS = {
    rounded:  { m:'simple', u:'px', v:[16,16,16,16] },
    pill:     { m:'simple', u:'px', v:[200,200,200,200] },
    circle:   { m:'simple', u:'%',  v:[50,50,50,50] },
    squircle: { m:'simple', u:'%',  v:[30,30,30,30] },
    none:     { m:'simple', u:'px', v:[0,0,0,0] },
    blob:     { m:'ell', h:[63,37,30,70], vv:[42,45,58,50] }
  };

  function applyPreset(name){
    if (name === 'shuffle'){
      $('brg-mode').value = 'elliptical';
      const rnd = () => Math.floor(Math.random()*76) + 12;
      HK.forEach(k => $('brg-'+k).value = rnd());
      VK.forEach(k => $('brg-'+k).value = rnd());
      onMode();
      return;
    }
    const p = PRESETS[name];
    if (!p) return;
    $('brg-mode').value = p.m === 'ell' ? 'elliptical' : 'simple';
    if (p.m === 'simple'){
      $('brg-unit').value = p.u;
      const max = p.u === 'px' ? 200 : 100;
      CORNERS.forEach((k,i) => { const el = $('brg-'+k); el.max = max; el.value = p.v[i]; });
    } else {
      HK.forEach((k,i) => $('brg-'+k).value = p.h[i]);
      VK.forEach((k,i) => $('brg-'+k).value = p.vv[i]);
    }
    onMode();
  }

  CORNERS.forEach(k => {
    $('brg-'+k).addEventListener('input', () => {
      if ($('brg-link').checked){
        const v = $('brg-'+k).value;
        CORNERS.forEach(o => { if (o !== k) $('brg-'+o).value = v; });
      }
      apply();
    });
  });
  HK.concat(VK).forEach(k => $('brg-'+k).addEventListener('input', apply));
  $('brg-mode').addEventListener('change', onMode);
  $('brg-unit').addEventListener('change', onUnit);
  $('brg-link').addEventListener('change', apply);
  document.querySelectorAll('#brg-presets [data-preset]').forEach(b =>
    b.addEventListener('click', () => applyPreset(b.dataset.preset)));
  $('brg-copy').onclick = e => wtCopy(e.target, $('brg-out').value);

  onMode();
})();
</script>
