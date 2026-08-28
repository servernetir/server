{{--
    کپیِ بلوک‌های کد — مشترکِ صفحاتِ مستنداتِ توسعه‌دهنده.

    ⚠️ هیچ کتابخانه‌ای؛ CSP این پروژه هر منبعِ خارجی را بی‌صدا بلاک می‌کند.

    🔴 چرا partial و نه کپیِ دوم در هر صفحه: این اسکریپت سه مسیرِ کپی دارد
    (clipboard API، execCommand، و انتخابِ دستی) و هر سه لازم‌اند. دو نسخهٔ
    جدا یعنی روزی یکی رفعِ اشکال بگیرد و دیگری نه — و شکستش دقیقاً همان
    خرابیِ خاموشی است که کامنتِ داخلِ خودش دربارهٔ آن هشدار می‌دهد.

    ورودی: $print — اگر true باشد، صفحه بعد از بارگذاری خودش چاپ می‌شود.
    صفحه‌ای که این را include می‌کند باید $print را تعریف کرده باشد.
--}}
@php($print = $print ?? false)
<script>
(function () {
  var LBL = @json(['copy' => __('ui.sec_copied')]);
  document.querySelectorAll('pre[data-copy]').forEach(function (pre) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'dev-copy';
    b.textContent = '⧉';
    b.setAttribute('aria-label', LBL.copy);
    /* ⚠️ کپی سه راه دارد و هر سه لازم‌اند:
       - `navigator.clipboard` فقط در بسترِ امن و با فوکوسِ سند کار می‌کند و
         روی شکست **Promiseِ ردشده** می‌دهد، نه استثنا. نسخهٔ اول `catch`
         نداشت، پس هر شکستی کاملاً بی‌صدا بود: کاربر دکمه را می‌زد، هیچ اتفاقی
         نمی‌افتاد، و فکر می‌کرد کپی شده.
       - راهِ دوم `execCommand` است برای مرورگرِ قدیمی‌تر.
       - و اگر هیچ‌کدام نشد، ✗ نشان داده می‌شود؛ متن هم انتخاب می‌شود تا کاربر
         دستی Ctrl+C بزند. «نشد» باید دیده شود. */
    function mark(ok) {
      b.textContent = ok ? '✓' : '✗';
      b.classList.toggle('is-bad', ! ok);
      setTimeout(function () { b.textContent = '⧉'; b.classList.remove('is-bad'); }, 1600);
    }

    function legacy() {
      try {
        var ta = document.createElement('textarea');
        ta.value = pre.textContent;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        var ok = document.execCommand('copy');
        document.body.removeChild(ta);
        return ok;
      } catch (e) { return false; }
    }

    function selectBlock() {
      try {
        var r = document.createRange();
        r.selectNodeContents(pre);
        var s = window.getSelection();
        s.removeAllRanges();
        s.addRange(r);
      } catch (e) {}
    }

    b.addEventListener('click', function () {
      var text = pre.textContent;

      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(
          function () { mark(true); },
          function () { var ok = legacy(); if (! ok) selectBlock(); mark(ok); }
        );
        return;
      }

      var ok = legacy();
      if (! ok) selectBlock();
      mark(ok);
    });
    pre.appendChild(b);
  });

  @if($print)
  window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
  @endif
})();
</script>
