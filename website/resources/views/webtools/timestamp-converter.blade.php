<div class="wt-two">
  <div class="wt-pane">
    <label>Unix Timestamp</label>
    <input type="number" id="s-ts" class="wt-input-lg" dir="ltr" placeholder="1784451801">
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_ts_datetime') }}</label>
    <input type="datetime-local" id="s-dt" class="wt-input-lg" step="1" dir="ltr">
  </div>
</div>
<div class="wt-bar">
  <button class="btn btn-primary" id="s-now">{{ __('ui.wt_ts_now') }}</button>
  <label class="wt-chk"><input type="checkbox" id="s-ms"> {{ __('ui.wt_ts_ms') }}</label>
  <span class="wt-status" id="s-msg"></span>
</div>
<div class="wt-out-box" id="s-out"></div>
<script>
(function () {
  const $ = id => document.getElementById(id);
  const L = { utc:@json(__('ui.wt_ts_utc')), local:@json(__('ui.wt_ts_local')),
              iso:'ISO 8601', rel:@json(__('ui.wt_ts_relative')), bad:@json(__('ui.wt_date_err')) };
  let busy = false;

  function rel(ms) {
    const d = Math.round((ms - Date.now()) / 1000);
    const abs = Math.abs(d);
    const U = [[31536000,'y'],[2592000,'mo'],[86400,'d'],[3600,'h'],[60,'m'],[1,'s']];
    for (const [sec,label] of U) if (abs >= sec) return (d<0?'-':'+') + Math.floor(abs/sec) + label;
    return '0s';
  }

  function show(ms) {
    const d = new Date(ms);
    if (isNaN(d.getTime())) { $('s-out').innerHTML=''; $('s-msg').textContent=L.bad; $('s-msg').className='wt-status err'; return; }
    $('s-msg').textContent=''; $('s-msg').className='wt-status';
    const rows = [
      [L.iso,   d.toISOString()],
      [L.utc,   d.toUTCString()],
      [L.local, d.toLocaleString()],
      [L.rel,   rel(ms)],
    ];
    $('s-out').innerHTML = rows.map(r =>
      '<div class="wt-out-row"><span>'+r[0]+'</span><b dir="ltr">'+r[1]+'</b></div>').join('');
  }

  function fromTs() {
    if (busy) return;
    const raw = $('s-ts').value.trim();
    if (!raw) return;
    let n = Number(raw);
    if (!isFinite(n)) return;
    // ثانیه یا میلی‌ثانیه: بر اساس تیک، وگرنه از روی طول عدد حدس می‌زنیم
    const ms = $('s-ms').checked ? n : (String(Math.abs(Math.trunc(n))).length > 10 ? n : n * 1000);
    busy = true;
    const d = new Date(ms);
    if (!isNaN(d.getTime())) {
      const pad = x => String(x).padStart(2,'0');
      $('s-dt').value = d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())
                     +'T'+pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds());
    }
    busy = false;
    show(ms);
  }

  function fromDt() {
    if (busy) return;
    const v = $('s-dt').value;
    if (!v) return;
    const ms = new Date(v).getTime();
    if (isNaN(ms)) return;
    busy = true;
    $('s-ts').value = $('s-ms').checked ? ms : Math.floor(ms/1000);
    busy = false;
    show(ms);
  }

  $('s-ts').addEventListener('input', fromTs);
  $('s-dt').addEventListener('input', fromDt);
  $('s-ms').addEventListener('change', fromTs);
  $('s-now').onclick = () => { $('s-ts').value = $('s-ms').checked ? Date.now() : Math.floor(Date.now()/1000); fromTs(); };
  $('s-now').click();
})();
</script>
