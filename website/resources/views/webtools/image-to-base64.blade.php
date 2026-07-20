<style>
  .i2b-drop{display:block;width:100%;border:2px dashed var(--line-2);border-radius:16px;
    background:var(--surface-2);padding:34px 20px;text-align:center;cursor:pointer;
    transition:border-color .2s,background .2s}
  .i2b-drop:hover,.i2b-drop:focus-visible,.i2b-over{border-color:var(--cyan);background:var(--surface)}
  .i2b-drop .icon{width:38px;height:38px;color:var(--cyan)}
  .i2b-drop b{display:block;color:var(--text);font-size:15px;font-weight:600;margin-top:10px}
  .i2b-drop span{display:block;color:var(--dim);font-size:12.5px;margin-top:6px}
  .i2b-prev{display:flex;align-items:center;gap:16px;margin-top:18px}
  .i2b-thumb{width:88px;height:88px;flex:none;border-radius:12px;border:1px solid var(--line-2);
    overflow:hidden;display:grid;place-items:center;background-color:var(--surface-2);
    background-image:linear-gradient(45deg,var(--line) 25%,transparent 25%,transparent 75%,var(--line) 75%),
      linear-gradient(45deg,var(--line) 25%,transparent 25%,transparent 75%,var(--line) 75%);
    background-size:14px 14px;background-position:0 0,7px 7px}
  .i2b-thumb img{max-width:100%;max-height:100%;display:block}
  .i2b-meta{min-width:0}
  .i2b-meta b{display:block;font-size:14px;color:var(--text);word-break:break-all;
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
</style>

<div class="i2b">
  <input type="file" id="i2b-file" accept="image/*" hidden>
  <div class="i2b-drop" id="i2b-drop" role="button" tabindex="0">
    <svg class="icon"><use href="#i-plus"/></svg>
    <b>{{ __('ui.wt_i2b_drop') }}</b>
    <span>{{ __('ui.wt_i2b_hint') }}</span>
  </div>
  <span class="wt-status err" id="i2b-err" style="display:none;margin-top:12px"></span>

  <div id="i2b-result" hidden>
    <div class="i2b-prev">
      <span class="i2b-thumb"><img id="i2b-img" alt=""></span>
      <div class="i2b-meta">
        <b id="i2b-name" dir="ltr"></b>
        <span class="wt-status" id="i2b-warn"></span>
      </div>
    </div>

    <div class="wt-out-box" id="i2b-stats"></div>

    <div class="wt-pane" style="margin-top:16px">
      <label>{{ __('ui.wt_i2b_datauri') }}</label>
      <textarea id="i2b-uri" class="wt-ta" rows="4" readonly spellcheck="false" dir="ltr"></textarea>
    </div>
    <div class="wt-bar">
      <button class="btn btn-glass" id="i2b-copyuri" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
    </div>

    <div class="wt-out-box" id="i2b-snips" style="margin-top:16px"></div>
  </div>
</div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const L = {
    type:   @json(__('ui.wt_i2b_type')),
    dims:   @json(__('ui.wt_i2b_dims')),
    orig:   @json(__('ui.wt_i2b_orig')),
    b64:    @json(__('ui.wt_i2b_b64size')),
    len:    @json(__('ui.wt_i2b_len')),
    inc:    @json(__('ui.wt_i2b_increase')),
    chars:  @json(__('ui.wt_i2b_chars')),
    ideal:  @json(__('ui.wt_i2b_ideal')),
    warn:   @json(__('ui.wt_i2b_warn')),
    toobig: @json(__('ui.wt_i2b_toobig')),
    notimg: @json(__('ui.wt_i2b_notimg')),
    html:   @json(__('ui.wt_i2b_html')),
    css:    @json(__('ui.wt_i2b_css')),
    md:     @json(__('ui.wt_i2b_md')),
    copy:   @json(__('ui.wt_copy')),
    copied: @json(__('ui.wt_copied'))
  };

  const MAX   = 15 * 1024 * 1024;   // سقف پذیرش فایل تا مرورگر قفل نکند
  const IDEAL = 8 * 1024;           // زیر این حجم برای درج مستقیم عالی است
  const WARN  = 100 * 1024;         // بالای این حجم برای data URI توصیه نمی‌شود

  const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
                            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const num = n => n.toLocaleString('en-US');

  function fmtBytes(n){
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n/1024).toFixed(n < 10240 ? 2 : 1) + ' KB';
    return (n/1048576).toFixed(2) + ' MB';
  }
  const shortUri = u => u.length > 60 ? (u.slice(0,44) + '…' + u.slice(-8)) : u;
  function snippet(kind, u){
    if (kind === 'html') return '<img src="' + u + '" alt="">';
    if (kind === 'css')  return 'background-image: url("' + u + '");';
    return '![](' + u + ')';
  }

  function showErr(msg){
    const e = $('i2b-err'); e.textContent = msg; e.style.display = 'block';
    $('i2b-result').hidden = true;
  }
  const hideErr = () => { $('i2b-err').style.display = 'none'; };

  function render(file, uri){
    const ci     = uri.indexOf(',');
    const b64    = ci >= 0 ? uri.slice(ci + 1) : '';
    const mime   = file.type || (uri.slice(5, uri.indexOf(';')) || uri.slice(5, ci));
    const orig   = file.size;
    const uriLen = uri.length;
    const inc    = orig ? ((uriLen - orig) / orig * 100) : 0;

    const row = (k,v) => '<div class="wt-out-row"><span>' + esc(k) +
      '</span><b dir="ltr">' + esc(v) + '</b></div>';

    $('i2b-name').textContent = file.name || 'image';

    $('i2b-stats').innerHTML =
        row(L.type, mime)
      + '<div class="wt-out-row"><span>' + esc(L.dims) +
        '</span><b dir="ltr" id="i2b-dimval">…</b></div>'
      + row(L.orig, fmtBytes(orig))
      + row(L.b64,  num(b64.length) + ' ' + L.chars)
      + row(L.len,  num(uriLen) + ' ' + L.chars)
      + row(L.inc,  '+' + inc.toFixed(1) + '%');

    // ابعاد را پس از بارگذاری تصویر می‌خوانیم
    const im = $('i2b-img');
    im.onload  = () => { const d = $('i2b-dimval'); if (d)
      d.textContent = (im.naturalWidth && im.naturalHeight)
        ? (im.naturalWidth + ' × ' + im.naturalHeight) : '—'; };
    im.onerror = () => { const d = $('i2b-dimval'); if (d) d.textContent = '—'; };
    im.src = uri;

    $('i2b-uri').value = uri;
    $('i2b-copyuri').onclick = () => wtCopy($('i2b-copyuri'), uri);

    const rows = [
      ['html', L.html],
      ['css',  L.css],
      ['md',   L.md]
    ];
    $('i2b-snips').innerHTML = rows.map((r,i) =>
      '<div class="wt-out-row"><span>' + esc(r[1]) + '</span>'
      + '<b dir="ltr">' + esc(snippet(r[0], shortUri(uri))) + '</b>'
      + '<button class="wt-mini" id="i2b-sc' + i + '" data-done="' + esc(L.copied) + '">'
      + esc(L.copy) + '</button></div>'
    ).join('');
    rows.forEach((r,i) => { const b = $('i2b-sc' + i);
      b.onclick = () => wtCopy(b, snippet(r[0], uri)); });

    const w = $('i2b-warn');
    if (uriLen > WARN)      { w.textContent = L.warn;  w.className = 'wt-status err'; }
    else if (uriLen <= IDEAL){ w.textContent = L.ideal; w.className = 'wt-status ok'; }
    else                    { w.textContent = '';      w.className = 'wt-status'; }

    $('i2b-result').hidden = false;
  }

  function handle(file){
    hideErr();
    if (!file) return;
    if (!/^image\//.test(file.type)) { showErr(L.notimg); return; }
    if (file.size > MAX) { showErr(L.toobig); return; }
    const fr = new FileReader();
    fr.onload  = () => render(file, fr.result);
    fr.onerror = () => showErr(L.notimg);
    fr.readAsDataURL(file);
  }

  const drop = $('i2b-drop'), input = $('i2b-file');
  drop.onclick = () => input.click();
  drop.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } };
  input.onchange = () => { if (input.files[0]) handle(input.files[0]); input.value = ''; };

  ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => {
    e.preventDefault(); drop.classList.add('i2b-over');
  }));
  drop.addEventListener('dragleave', () => drop.classList.remove('i2b-over'));
  drop.addEventListener('drop', e => {
    e.preventDefault(); drop.classList.remove('i2b-over');
    const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
    if (f) handle(f);
  });
})();
</script>
