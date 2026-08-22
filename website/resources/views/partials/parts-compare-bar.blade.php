{{--
  نوارِ شناورِ مقایسه.

  🔴 چرا انتخاب در `sessionStorage` می‌نشیند و نه در URL:
  کاربر قطعه‌ها را از **چند دستهٔ مختلف** انتخاب می‌کند (یک پردازنده، یک رم).
  اگر انتخاب در URL بود، رفتن از `/parts/cpu` به `/parts/ram` پاکش می‌کرد و
  مقایسهٔ بین‌دسته‌ای — که دقیقاً کاری است که سرورساز می‌خواهد — ناممکن می‌شد.

  ⚠️ `sessionStorage` نه `localStorage`: انتخابِ دیروز نباید امروز روی نوار
  بنشیند. مقایسه یک تصمیمِ همان‌جلسه است.
--}}
<div class="sp-bar" id="sp-bar" hidden>
    <span class="sp-bar-n" id="sp-bar-n"></span>
    <div class="sp-bar-list" id="sp-bar-list"></div>
    <span class="sp-bar-hint" id="sp-bar-hint"></span>
    <a class="btn btn-primary btn-sm" id="sp-bar-go" href="#">{{ __('ui.parts_compare') }}</a>
    <button type="button" class="sp-bar-clear" id="sp-bar-clear">{{ __('ui.parts_compare_clear') }}</button>
</div>

<script>
(function () {
  var KEY = 'snet-cmp';
  var MAX = {{ \App\Http\Controllers\PartsShopController::COMPARE_MAX }};
  var BASE = @json(lroute('parts.compare'));
  var LBL = @json(__('ui.parts_compare_open', ['count' => '%n']));
  var MAXMSG = @json(__('ui.parts_compare_max', ['count' => \App\Http\Controllers\PartsShopController::COMPARE_MAX]));

  var bar = document.getElementById('sp-bar');
  if (!bar) return;
  var nEl = document.getElementById('sp-bar-n');
  var listEl = document.getElementById('sp-bar-list');
  var goEl = document.getElementById('sp-bar-go');
  var hintEl = document.getElementById('sp-bar-hint');

  function read() {
    try { return JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { return []; }
  }
  function write(v) {
    try { sessionStorage.setItem(KEY, JSON.stringify(v)); } catch (e) {}
  }

  function render() {
    var sel = read();
    bar.hidden = sel.length === 0;
    if (!sel.length) return;

    nEl.textContent = LBL.replace('%n', sel.length);
    goEl.href = BASE + '?parts=' + encodeURIComponent(sel.join(','));

    listEl.textContent = '';
    sel.forEach(function (slug) {
      var card = document.querySelector('.sp-card[data-slug="' + slug + '"] h3');
      var chip = document.createElement('button');
      chip.type = 'button';
      chip.className = 'sp-bar-chip';
      chip.textContent = card ? card.textContent.trim() : slug;
      chip.addEventListener('click', function () { toggle(slug, false); });
      listEl.appendChild(chip);
    });

    // چک‌باکس‌ها با وضعیتِ ذخیره‌شده هماهنگ می‌شوند — بی‌این، برگشت با دکمهٔ
    // «قبلی» نوارِ پر و چک‌باکس‌های خالی نشان می‌داد.
    var full = sel.length >= MAX;
    hintEl.textContent = full ? MAXMSG : '';

    document.querySelectorAll('.sp-cmp-box').forEach(function (box) {
      box.checked = sel.indexOf(box.value) > -1;
      // ⚠️ فقط تیک‌نخورده‌ها قفل می‌شوند؛ وگرنه کاربر نمی‌توانست انتخابش را پس بگیرد
      box.disabled = full && !box.checked;
    });
  }

  /*
   * 🔴 پنجرهٔ هشدارِ بومیِ مرورگر این‌جا حذف نشد — نیازش حذف شد.
   *
   * نسخهٔ اول وقتی کاربر پنجمی را می‌زد، همان پنجره را بالا می‌آورد. سه ایراد
   * داشت: قواعدِ سایت پنجرهٔ بومی را ممنوع می‌کند، ظاهرش با هیچ‌جای سایت
   * نمی‌خواند، و — مهم‌تر — کاربر را **بعد از** کلیک تنبیه می‌کرد.
   *
   * حالا وقتی سقف پر شد، چک‌باکس‌های تیک‌نخورده `disabled` می‌شوند و علتش
   * روی خودِ نوار نوشته می‌شود. کاربر پیش از کلیک می‌بیند، نه بعدش.
   */
  function toggle(slug, on) {
    var sel = read();
    var i = sel.indexOf(slug);

    if (on && i === -1) {
      if (sel.length >= MAX) { render(); return; }
      sel.push(slug);
    } else if (!on && i > -1) {
      sel.splice(i, 1);
    }

    write(sel);
    render();
  }

  document.addEventListener('change', function (e) {
    var box = e.target.closest ? e.target.closest('.sp-cmp-box') : null;
    if (box) toggle(box.value, box.checked);
  });

  var clear = document.getElementById('sp-bar-clear');
  if (clear) clear.addEventListener('click', function () {
    write([]);
    document.querySelectorAll('.sp-cmp-box').forEach(function (b) { b.checked = false; b.disabled = false; });
    render();
  });

  render();
})();
</script>
