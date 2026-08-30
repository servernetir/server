<div class="wt-fields" style="border:0;padding:0;margin:0 0 16px">
  <label class="wt-range">{{ __('ui.wt_li_lang') }}
    <select id="l-lang" class="wt-select">
      <option value="fa">{{ __('ui.wt_li_fa') }}</option>
      <option value="la">{{ __('ui.wt_li_latin') }}</option>
    </select>
  </label>
  <label class="wt-range">{{ __('ui.wt_li_kind') }}
    <select id="l-kind" class="wt-select">
      <option value="p">{{ __('ui.wt_paragraphs') }}</option>
      <option value="s">{{ __('ui.wt_li_sentences') }}</option>
      <option value="w">{{ __('ui.wt_words') }}</option>
    </select>
  </label>
  <label class="wt-range">{{ __('ui.wt_count') }}: <b id="l-n">3</b>
    <input type="range" id="l-c" min="1" max="20" value="3">
  </label>
</div>
<textarea id="l-out" class="wt-ta" rows="12" readonly></textarea>
<div class="wt-bar">
  <button class="btn btn-primary" id="l-gen">{{ __('ui.wt_generate') }}</button>
  <button class="btn btn-glass" id="l-copy" data-done="{{ __('ui.wt_copied') }}">{{ __('ui.wt_copy') }}</button>
  <span class="wt-status" id="l-msg"></span>
</div>

<script>
(function () {
  const $ = id => document.getElementById(id);

  /* واژگان فارسی طبیعی — متن نمونه‌ی فارسی برای طراحی RTL خیلی واقعی‌تر از لاتین است */
  const FA = ('طراحی وب سایت کاربر تجربه رابط محتوا صفحه سرور میزبانی داده امنیت شبکه سرعت '
    + 'بهینه سازی موتور جستجو کسب کار سازمان مدیریت سیستم پشتیبانی مشتری خدمات فناوری '
    + 'اطلاعات پردازش ذخیره فضا ابری زیرساخت توسعه برنامه نویسی پایگاه ارتباط امکان').split(' ');

  const LA = ('lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod tempor '
    + 'incididunt ut labore et dolore magna aliqua enim ad minim veniam quis nostrud '
    + 'exercitation ullamco laboris nisi aliquip ex ea commodo consequat duis aute irure').split(' ');

  const pick = a => a[Math.floor(Math.random() * a.length)];

  function sentence(v) {
    const n = 6 + Math.floor(Math.random() * 9);
    const w = Array.from({ length: n }, () => pick(v));
    let s = w.join(' ');
    s = s.charAt(0).toUpperCase() + s.slice(1);
    return s + (v === FA ? '.' : '.');
  }

  function paragraph(v) {
    const n = 3 + Math.floor(Math.random() * 3);
    return Array.from({ length: n }, () => sentence(v)).join(' ');
  }

  function gen() {
    const v = $('l-lang').value === 'fa' ? FA : LA;
    const n = +$('l-c').value;
    const kind = $('l-kind').value;
    let out;

    if (kind === 'w') out = Array.from({ length: n * 10 }, () => pick(v)).join(' ');
    else if (kind === 's') out = Array.from({ length: n }, () => sentence(v)).join(' ');
    else out = Array.from({ length: n }, () => paragraph(v)).join('\n\n');

    $('l-out').value = out;
    $('l-out').dir = $('l-lang').value === 'fa' ? 'rtl' : 'ltr';
    const words = out.split(/\s+/).filter(Boolean).length;
    $('l-msg').textContent = words + ' ' + @json(__('ui.wt_words'));
  }

  $('l-c').oninput = () => { $('l-n').textContent = $('l-c').value; gen(); };
  $('l-lang').onchange = gen;
  $('l-kind').onchange = gen;
  $('l-gen').onclick = gen;
  $('l-copy').onclick = e => wtCopy(e.target, $('l-out').value);
  gen();
})();
</script>
