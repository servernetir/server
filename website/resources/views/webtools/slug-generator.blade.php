<div class="wt-single">
  <input type="text" id="s-in" class="wt-input-lg" placeholder="{{ __('ui.wt_slug_ph') }}">
  <div class="wt-result sm" id="s-out" dir="ltr">—</div>
</div>
<div class="wt-fields">
  <label class="wt-chk"><input type="checkbox" id="s-tr" checked> {{ __('ui.wt_slug_translit') }}</label>
  <label class="wt-range">{{ __('ui.wt_slug_sep') }}
    <select id="s-sep" class="wt-select"><option value="-">-</option><option value="_">_</option></select>
  </label>
</div>
<div class="wt-bar">
  <button class="btn btn-glass" id="s-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  <span class="wt-status" id="s-msg"></span>
</div>

<script>
(function () {
  /* نگاشت حروف فارسی/عربی به لاتین، تا آدرس نهایی در همه‌جا قابل‌اشتراک باشد
     (URLهای فارسی هنگام کپی به درصدکدگذاری تبدیل و ناخوانا می‌شوند). */
  const MAP = {
    'آ':'a','أ':'a','إ':'a','ا':'a','ب':'b','پ':'p','ت':'t','ث':'s','ج':'j','چ':'ch','ح':'h','خ':'kh',
    'د':'d','ذ':'z','ر':'r','ز':'z','ژ':'zh','س':'s','ش':'sh','ص':'s','ض':'z','ط':'t','ظ':'z',
    'ع':'a','غ':'gh','ف':'f','ق':'gh','ک':'k','ك':'k','گ':'g','ل':'l','م':'m','ن':'n','و':'v',
    'ه':'h','ة':'h','ی':'y','ي':'y','ئ':'y','ؤ':'o','ء':'',
    '۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9',
    '٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9'
  };

  const $ = id => document.getElementById(id);
  const CHARS = @json(__('ui.wt_chars'));

  function build() {
    let s = $('s-in').value.trim().toLowerCase();
    if ($('s-tr').checked) {
      s = Array.from(s).map(c => (MAP[c] !== undefined ? MAP[c] : c)).join('');
    }
    const sep = $('s-sep').value;

    // همه‌ی چیزهای غیرحرف/غیرعدد (شامل نیم‌فاصله و کاراکترهای جهت‌دهی) به فاصله،
    // بعد با جداکننده می‌چسبانیم — بدون ساختن RegExp پویا.
    const parts = s
      .replace(/[‌‎‏]/g, ' ')
      .replace(/[^\p{L}\p{N}]+/gu, ' ')
      .trim()
      .split(/\s+/)
      .filter(Boolean);

    const out = parts.join(sep);
    $('s-out').textContent = out || '—';
    $('s-msg').textContent = out ? out.length + ' ' + CHARS : '';
    $('s-msg').className = 'wt-status' + (out.length > 75 ? ' err' : '');
  }

  ['s-in', 's-tr'].forEach(id => $(id).addEventListener('input', build));
  $('s-sep').addEventListener('change', build);
  $('s-copy').onclick = e => wtCopy(e.target, $('s-out').textContent);
})();
</script>
