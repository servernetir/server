<div class="wt-fields" style="border:0;padding:0;margin-bottom:16px">
  <label class="wt-chk"><input type="checkbox" id="r-all" checked> {{ __('ui.wt_rb_allowall') }}</label>
  <label class="wt-chk"><input type="checkbox" id="r-admin" checked> {{ __('ui.wt_rb_admin') }}</label>
  <label class="wt-chk"><input type="checkbox" id="r-ai"> {{ __('ui.wt_rb_ai') }}</label>
</div>
<div class="wt-two">
  <div class="wt-pane">
    <label>{{ __('ui.wt_rb_sitemap') }}</label>
    <input type="text" id="r-sm" class="wt-input-lg" dir="ltr" placeholder="https://example.com/sitemap.xml">
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_rb_disallow') }}</label>
    <textarea id="r-dis" class="wt-ta" rows="3" dir="ltr" placeholder="/cart&#10;/checkout"></textarea>
  </div>
</div>
<div class="wt-pane" style="margin-top:16px">
  <label>robots.txt</label>
  <textarea id="r-out" class="wt-ta" rows="12" readonly dir="ltr"></textarea>
</div>
<div class="wt-bar">
  <button class="btn btn-glass" id="r-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  <span class="wt-status">{{ __('ui.wt_rb_note') }}</span>
</div>
<script>
(function () {
  const $ = id => document.getElementById(id);
  const AI = ['GPTBot','ClaudeBot','CCBot','Google-Extended','anthropic-ai','PerplexityBot'];
  function run() {
    const out = ['User-agent: *'];
    if ($('r-all').checked) out.push('Allow: /');
    else out.push('Disallow: /');
    if ($('r-admin').checked) {
      ['/admin/','/wp-admin/','/cgi-bin/','/tmp/'].forEach(p => out.push('Disallow: ' + p));
      out.push('Allow: /wp-admin/admin-ajax.php');
    }
    $('r-dis').value.split('\n').map(x => x.trim()).filter(Boolean)
      .forEach(p => out.push('Disallow: ' + (p.startsWith('/') ? p : '/' + p)));
    if ($('r-ai').checked) {
      out.push('');
      AI.forEach(b => { out.push('User-agent: ' + b); out.push('Disallow: /'); out.push(''); });
      if (out[out.length-1] === '') out.pop();
    }
    const sm = $('r-sm').value.trim();
    if (sm) { out.push(''); out.push('Sitemap: ' + sm); }
    $('r-out').value = out.join('\n');
  }
  ['r-all','r-admin','r-ai','r-sm','r-dis'].forEach(id => {
    $(id).addEventListener('input', run); $(id).addEventListener('change', run);
  });
  $('r-copy').onclick = e => wtCopy(e.target, $('r-out').value);
  run();
})();
</script>
