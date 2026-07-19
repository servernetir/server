<div class="wt-single">
  <input type="number" id="y-in" class="wt-input-lg" placeholder="1024" step="any" dir="ltr">
</div>
<div class="wt-fields" style="border:0;padding-top:14px">
  <label class="wt-range">{{ __('ui.wt_from') }}
    <select id="y-unit" class="wt-select">
      <option value="0">B</option><option value="1">KB</option><option value="2" selected>MB</option>
      <option value="3">GB</option><option value="4">TB</option><option value="5">PB</option>
    </select>
  </label>
  <label class="wt-chk"><input type="checkbox" id="y-si"> {{ __('ui.wt_si') }}</label>
</div>
<div class="wt-out-box" id="y-out" style="margin-top:16px"></div>
<script>
(function () {
  const $ = id => document.getElementById(id);
  const U = ['B','KB','MB','GB','TB','PB'];
  function run() {
    const v = parseFloat($('y-in').value);
    if (isNaN(v)) { $('y-out').innerHTML=''; return; }
    const base = $('y-si').checked ? 1000 : 1024;   // SI = 1000، دودویی = 1024
    const bytes = v * Math.pow(base, +$('y-unit').value);
    $('y-out').innerHTML = U.map((u,i) => {
      const n = bytes / Math.pow(base, i);
      const s = n >= 1000 ? n.toFixed(2) : n >= 1 ? n.toFixed(3) : n.toPrecision(4);
      return '<div class="wt-out-row"><span>'+u+'</span><b dir="ltr">'+parseFloat(s).toLocaleString('en-US')+'</b></div>';
    }).join('');
  }
  ['y-in','y-unit','y-si'].forEach(id => $(id).addEventListener('input', run));
  $('y-unit').addEventListener('change', run);
  run();
})();
</script>
