/*
| ورودیِ کد یک‌بارمصرف — خانه‌های شش‌تایی، پرکردنِ خودکار از پیامک، و شمارشِ معکوس.
|
| ═══ چرا یک فایلِ مشترک و نه اسکریپتِ درون‌خطی ═══
|
| همین منطق در `auth/login-code.blade.php` و `auth/register/verify.blade.php`
| دو نسخهٔ جدا داشت. هر دو نسخه **همان باگ** را داشتند و رفعِ یکی، دیگری را
| درست نمی‌کرد — همان الگویی که در این پروژه بارها تکرار شده.
|
| ═══ 🔴 باگی که این فایل برای رفعش نوشته شد ═══
|
| iOS کدِ پیامک را از نوارِ پیشنهادِ صفحه‌کلید **یک‌جا** در خانهٔ اول می‌ریزد:
| `input.value = "123456"`. نسخهٔ قبلی می‌گفت `this.value = v.slice(-1)` و
| نتیجه‌اش این بود که از کلِ کد فقط رقمِ آخر می‌مانْد — یعنی پرکردنِ خودکار
| **کد را نابود می‌کرد** و کاربر باید دستی تایپ می‌کرد.
|
| ⚠️ این خرابی روی دسکتاپ و اندروید دیده نمی‌شود، چون آن‌جا هر بار یک رقم
| تایپ می‌شود. فقط روی آیفون رخ می‌داد — و چون صفحه ۲۰۰ می‌داد و ظاهرش سالم
| بود، از تست‌های معمول رد می‌شد.
|
| ═══ دو مسیرِ پرکردنِ خودکار، و چرا هر دو لازم‌اند ═══
|
|   iOS / Safari   → `autocomplete="one-time-code"` روی خانهٔ اول. بی‌هیچ
|                    جاوااسکریپتی کار می‌کند و به **قالبِ متنِ پیامک کاری
|                    ندارد** — خودش کد را حدس می‌زند.
|
|   Android/Chrome → WebOTP (`navigator.credentials.get({otp})`). سریع‌تر و
|                    بی‌نیاز به لمسِ کاربر، ولی 🔴 **فقط** وقتی متنِ پیامک با
|                    این خط تمام شود:  `@<میزبان> #<کد>`
|                    اگر آن خط نباشد، این API هرگز چیزی برنمی‌گرداند — و چون
|                    بی‌صدا منتظر می‌مانَد، تشخیصش سخت است.
|
| ⚠️ **هیچ‌کدام تایپِ دستی را نمی‌بندند.** کاربرِ دسکتاپ، کاربری که پیامک روی
| گوشیِ دیگری آمده، و کاربری که اجازهٔ خواندنِ پیامک را نداده، همه باید بتوانند
| خودشان بنویسند یا بچسبانند. هر دو مسیر «اضافه‌شونده»اند نه جایگزین.
*/
(function (w) {
  'use strict';

  var LEN = 6;

  /** ارقامِ فارسی/عربی → لاتین، بعد حذفِ هر چیزِ غیرِ رقم */
  function digits(s) {
    return String(s == null ? '' : s)
      .replace(/[۰-۹]/g, function (d) { return String(d.charCodeAt(0) - 1776); })
      .replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 1632); })
      .replace(/[^0-9]/g, '');
  }

  /**
   * پخشِ متن روی خانه‌ها از یک نقطهٔ شروع.
   *
   * 🔴 قلبِ رفع: ورودی می‌تواند **چند رقم** باشد (پرکردنِ خودکارِ iOS،
   * چسباندن، یا WebOTP) و در آن حالت باید روی خانه‌های بعدی سرریز کند، نه
   * اینکه به یک رقم بریده شود.
   *
   * @param {string[]} current مقادیرِ فعلی
   * @param {string}   text    متنِ ورودی (هر شکلی)
   * @param {number}   at      از کدام خانه شروع شود
   * @returns {{values:string[], focus:number}}
   */
  function distribute(current, text, at) {
    var out = current.slice(),
        d = digits(text),
        i;

    if (d === '') {
      out[at] = '';

      return { values: out, focus: at };
    }

    // ⚠️ اگر کاربر روی خانه‌ای که پُر است یک رقمِ تازه بزند، همان یک رقم
    //    جایگزین می‌شود — نه اینکه به آخرِ کد بچسبد.
    for (i = 0; i < d.length && at + i < out.length; i++) out[at + i] = d.charAt(i);

    return { values: out, focus: Math.min(at + d.length, out.length - 1) };
  }

  function isComplete(values) {
    return values.join('').length === LEN;
  }

  // ───────────────────────── لایهٔ DOM ─────────────────────────

  function mountBoxes(root) {
    var boxes = [].slice.call(root.querySelectorAll('#otp input')),
        hidden = root.querySelector('#code'),
        form = root.querySelector('#otp-form');

    if (!boxes.length || !hidden || !form) return null;

    function read() { return boxes.map(function (b) { return b.value; }); }

    function write(state) {
      state.values.forEach(function (v, i) {
        boxes[i].value = v;
        boxes[i].classList.toggle('filled', v !== '');
      });

      hidden.value = state.values.join('');
      (boxes[state.focus] || boxes[LEN - 1]).focus();

      // ارسالِ خودکار وقتی کد کامل شد — چه دستی، چه چسبانده، چه خودکار
      if (isComplete(state.values)) submit();
    }

    var sent = false;

    function submit() {
      if (sent) return;

      sent = true;
      stopWebOtp();
      form.requestSubmit ? form.requestSubmit() : form.submit();
    }

    boxes.forEach(function (box, i) {
      box.addEventListener('input', function () {
        write(distribute(read().map(function (v, k) { return k === i ? '' : v; }), this.value, i));
      });

      box.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace' && !this.value && i > 0) boxes[i - 1].focus();
        if (e.key === 'ArrowLeft' && i > 0) { boxes[i - 1].focus(); e.preventDefault(); }
        if (e.key === 'ArrowRight' && i < boxes.length - 1) { boxes[i + 1].focus(); e.preventDefault(); }
      });

      box.addEventListener('paste', function (e) {
        e.preventDefault();
        var t = (e.clipboardData || w.clipboardData).getData('text');
        // چسباندن همیشه از خانهٔ اول شروع می‌شود: کاربر کلِ کد را می‌چسباند
        write(distribute(read().map(function () { return ''; }), t, 0));
      });
    });

    form.addEventListener('submit', function () {
      sent = true;
      stopWebOtp();
      hidden.value = read().join('');

      var b = form.querySelector('.auth-btn');
      if (b) { b.classList.add('busy'); b.disabled = true; }
    });

    return { fill: function (code) { write(distribute(['', '', '', '', '', ''], code, 0)); } };
  }

  /** فیلدِ تک‌خانه‌ای (صفحهٔ امنیت، سرویس‌ها، ورودِ مدیر) */
  function mountSingle(root) {
    var input = root.querySelector('input[autocomplete="one-time-code"]');

    if (!input || input.getAttribute('maxlength') === '1') return null;

    return { fill: function (code) {
      input.value = digits(code).slice(0, LEN);
      input.dispatchEvent(new Event('input', { bubbles: true }));
    } };
  }

  // ───────────────────────── WebOTP ─────────────────────────

  var abort = null;

  function stopWebOtp() {
    if (abort) { try { abort.abort(); } catch (e) {} abort = null; }
  }

  /**
   * WebOTP — فقط اندروید/کروم، فقط HTTPS، فقط اگر پیامک خطِ `@میزبان #کد` داشته باشد.
   *
   * ⚠️ همه‌چیز در try/catch و بی‌صدا: اگر مرورگر پشتیبانی نکند، کاربر اجازه
   * ندهد، یا پیامک قالبِ لازم را نداشته باشد، **هیچ اتفاقی نمی‌افتد** و کاربر
   * مثلِ همیشه دستی وارد می‌کند. هرگز نباید خطایی به کاربر نشان داده شود؛
   * این یک راحتیِ اضافه است، نه مسیرِ اصلی.
   */
  function startWebOtp(target) {
    if (!('OTPCredential' in w) || !w.navigator || !w.navigator.credentials) return;
    if (!w.isSecureContext) return;

    try {
      abort = new AbortController();
    } catch (e) {
      return;
    }

    // ⚠️ اگر کاربر تب را عوض کند، درخواست را رها کن — وگرنه تا ابد باز می‌مانَد
    w.addEventListener('pagehide', stopWebOtp, { once: true });

    w.navigator.credentials.get({ otp: { transport: ['sms'] }, signal: abort.signal })
      .then(function (otp) {
        if (otp && otp.code) target.fill(otp.code);
      })
      .catch(function () { /* رها شد، پشتیبانی نشد، یا کاربر رد کرد — بی‌صدا */ });
  }

  // ───────────────────────── شمارشِ معکوسِ ارسالِ دوباره ─────────────────────────

  function countdown(root, locale) {
    var btn = root.querySelector('#resend'),
        cd = root.querySelector('#cd'),
        n = 60;

    if (!btn || !cd) return;

    var fa = function (x) {
      return locale === 'fa'
        ? String(x).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(+d); })
        : String(x);
    };

    cd.textContent = '(' + fa(n) + ')';

    var t = setInterval(function () {
      if (--n <= 0) { clearInterval(t); btn.disabled = false; cd.textContent = ''; return; }
      cd.textContent = '(' + fa(n) + ')';
    }, 1000);
  }

  function init() {
    var root = w.document,
        target = mountBoxes(root) || mountSingle(root);

    if (target) startWebOtp(target);

    countdown(root, (root.documentElement.getAttribute('lang') || 'fa').slice(0, 2));
  }

  if (w.document.readyState === 'loading') {
    w.document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // برای تست — منطقِ خالص، بی‌وابستگی به DOM
  w.SNOtp = { digits: digits, distribute: distribute, isComplete: isComplete, LEN: LEN };
})(typeof window !== 'undefined' ? window : this);
