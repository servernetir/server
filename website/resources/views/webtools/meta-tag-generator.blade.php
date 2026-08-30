<div class="wt-two">
  <div class="wt-pane">
    <label>{{ __('ui.wt_mt_title') }}</label>
    <input type="text" id="m-t" class="wt-input-lg" maxlength="120">
    <span class="wt-status" id="m-t-c"></span>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_mt_url') }}</label>
    <input type="text" id="m-u" class="wt-input-lg" dir="ltr" placeholder="https://example.com/page">
  </div>
</div>
<div class="wt-pane" style="margin-top:14px">
  <label>{{ __('ui.wt_mt_desc') }}</label>
  <textarea id="m-d" class="wt-ta" rows="3" maxlength="320"></textarea>
  <span class="wt-status" id="m-d-c"></span>
</div>
<div class="wt-two" style="margin-top:14px">
  <div class="wt-pane">
    <label>{{ __('ui.wt_mt_image') }}</label>
    <input type="text" id="m-i" class="wt-input-lg" dir="ltr" placeholder="https://example.com/og.jpg">
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_mt_site') }}</label>
    <input type="text" id="m-s" class="wt-input-lg" placeholder="ServerNet">
  </div>
</div>

<div class="wt-preview" id="m-prev">
  <b class="wt-prev-h">{{ __('ui.wt_mt_preview') }}</b>
  <div class="wt-serp">
    <span class="wt-serp-u" id="pv-u">example.com › page</span>
    <span class="wt-serp-t" id="pv-t">—</span>
    <span class="wt-serp-d" id="pv-d">—</span>
  </div>
</div>

<div class="wt-pane" style="margin-top:16px">
  <label>{{ __('ui.wt_output') }}</label>
  <textarea id="m-out" class="wt-ta" rows="12" readonly dir="ltr"></textarea>
</div>
<div class="wt-bar">
  <button class="btn btn-glass" id="m-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
</div>

<script>
(function () {
  const $ = id => document.getElementById(id);
  const esc = s => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  const L = { chars: @json(__('ui.wt_chars')), ideal: @json(__('ui.wt_mt_ideal')) };

  /* بازه‌های توصیه‌شده‌ی گوگل: عنوان ۳۰–۶۰، توضیحات ۷۰–۱۶۰ کاراکتر */
  function counter(el, out, min, max) {
    const n = el.value.length;
    out.textContent = n + ' ' + L.chars + (n >= min && n <= max ? ' ✓' : ' — ' + L.ideal + ' ' + min + '–' + max);
    out.className = 'wt-status' + (n === 0 ? '' : (n >= min && n <= max ? ' ok' : ' err'));
  }

  function run() {
    const t = $('m-t').value.trim(), d = $('m-d').value.trim();
    const u = $('m-u').value.trim(), i = $('m-i').value.trim(), s = $('m-s').value.trim();

    counter($('m-t'), $('m-t-c'), 30, 60);
    counter($('m-d'), $('m-d-c'), 70, 160);

    $('pv-t').textContent = t || '—';
    $('pv-d').textContent = d || '—';
    try { $('pv-u').textContent = u ? new URL(u).hostname + ' › ' + new URL(u).pathname.replace(/^\//, '') : 'example.com › page'; }
    catch (e) { $('pv-u').textContent = u || 'example.com › page'; }

    const tags = [];
    if (t) tags.push('<title>' + esc(t) + '</title>');
    if (d) tags.push('<meta name="description" content="' + esc(d) + '">');
    if (u) tags.push('<link rel="canonical" href="' + esc(u) + '">');
    tags.push('');
    tags.push('<!-- Open Graph -->');
    if (t) tags.push('<meta property="og:title" content="' + esc(t) + '">');
    if (d) tags.push('<meta property="og:description" content="' + esc(d) + '">');
    if (u) tags.push('<meta property="og:url" content="' + esc(u) + '">');
    if (i) tags.push('<meta property="og:image" content="' + esc(i) + '">');
    if (s) tags.push('<meta property="og:site_name" content="' + esc(s) + '">');
    tags.push('<meta property="og:type" content="website">');
    tags.push('');
    tags.push('<!-- Twitter -->');
    tags.push('<meta name="twitter:card" content="' + (i ? 'summary_large_image' : 'summary') + '">');
    if (t) tags.push('<meta name="twitter:title" content="' + esc(t) + '">');
    if (d) tags.push('<meta name="twitter:description" content="' + esc(d) + '">');
    if (i) tags.push('<meta name="twitter:image" content="' + esc(i) + '">');

    $('m-out').value = tags.join('\n');
  }

  ['m-t', 'm-d', 'm-u', 'm-i', 'm-s'].forEach(id => $(id).addEventListener('input', run));
  $('m-copy').onclick = e => wtCopy(e.target, $('m-out').value);
  run();
})();
</script>
