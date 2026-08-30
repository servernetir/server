<div class="wt-io">
  <div class="wt-pane">
    <label>{{ __('ui.wt_input') }}</label>
    <textarea id="e-in" class="wt-ta" rows="10" spellcheck="false" placeholder="<div class=&quot;box&quot;>سلام & خوش آمدید</div>"></textarea>
  </div>
  <div class="wt-pane">
    <label>{{ __('ui.wt_output') }}</label>
    <textarea id="e-out" class="wt-ta" rows="10" readonly spellcheck="false"></textarea>
  </div>
</div>
<div class="wt-fields" style="border:0;padding-top:14px">
  <label class="wt-chk"><input type="checkbox" id="e-all"> {{ __('ui.wt_he_all') }}</label>
</div>
<div class="wt-bar">
  <button class="btn btn-primary" id="e-enc">{{ __('ui.wt_encode') }}</button>
  <button class="btn btn-glass" id="e-dec">{{ __('ui.wt_decode') }}</button>
  <button class="btn btn-glass" id="e-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  <span class="wt-status" id="e-msg"></span>
</div>
<script>
(function () {
  const $ = id => document.getElementById(id);
  const NAMED = { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' };

  function encode(s) {
    // همیشه پنج کاراکتر خطرناک؛ با تیک «همه»، هر چیز غیر ASCII هم عددی می‌شود
    let out = s.replace(/[&<>"']/g, c => NAMED[c]);
    if ($('e-all').checked) {
      out = Array.from(out).map(ch => {
        const cp = ch.codePointAt(0);
        return cp > 126 ? '&#' + cp + ';' : ch;
      }).join('');
    }
    return out;
  }

  function decode(s) {
    // decode امن: از DOMParser استفاده می‌کنیم تا اسکریپت اجرا نشود
    const doc = new DOMParser().parseFromString('<!doctype html><body>' + s, 'text/html');
    return doc.body.textContent || '';
  }

  const go = fn => {
    try {
      $('e-out').value = $('e-in').value ? fn($('e-in').value) : '';
      $('e-msg').textContent = ''; $('e-msg').className = 'wt-status';
    } catch (err) {
      $('e-out').value = ''; $('e-msg').textContent = String(err.message); $('e-msg').className = 'wt-status err';
    }
  };

  $('e-enc').onclick = () => go(encode);
  $('e-dec').onclick = () => go(decode);
  $('e-all').onchange = () => { if ($('e-out').value) go(encode); };
  $('e-copy').onclick = e => wtCopy(e.target, $('e-out').value);
})();
</script>
