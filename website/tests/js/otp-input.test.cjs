/*
| منطقِ خالصِ ورودیِ کد یک‌بارمصرف.
|
| 🔴 چرا با node و نه PHPUnit: این کد جاوااسکریپت است و PHPUnit فقط می‌تواند
| بگوید «رشته‌ای در HTML هست». درسِ ثبت‌شدهٔ این پروژه: «کد ۲۰۰ یعنی هیچ» —
| بارها صفحه سالم برگشته و جاوااسکریپتش مرده بوده.
|
| 🔴 و باگی که این تست برای تکرارنشدنش نوشته شد: iOS کدِ پیامک را **یک‌جا** در
| خانهٔ اول می‌ریزد، و نسخهٔ قبلی با `value.slice(-1)` از کلِ کد فقط رقمِ آخر را
| نگه می‌داشت. یعنی پرکردنِ خودکار کد را نابود می‌کرد. روی دسکتاپ و اندروید
| دیده نمی‌شد چون آن‌جا رقم‌به‌رقم تایپ می‌شود.
|
| اجرا:  node tests/js/otp-input.test.js
*/
const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, '../../public/assets/js/otp-input.js'), 'utf8');

// شبیه‌سازیِ کمینهٔ window — فایل خودش را به آن می‌چسباند
const w = {
  document: { readyState: 'complete', addEventListener() {}, querySelector: () => null, querySelectorAll: () => [], documentElement: { getAttribute: () => 'fa' } },
  addEventListener() {},
  isSecureContext: false,
};

new Function('window', src)(w);
const OTP = w.SNOtp;

let pass = 0, fail = 0;
const say = (ok, name, extra = '') => { ok ? pass++ : fail++; console.log((ok ? '  ✔ ' : '  ✘ ') + name + (extra ? '  → ' + extra : '')); };
const eq = (a, b, name) => say(JSON.stringify(a) === JSON.stringify(b), name, JSON.stringify(a));

const EMPTY = ['', '', '', '', '', ''];

// ═══════════════ نرمال‌سازیِ رقم ═══════════════

say(OTP.digits('۱۲۳۴۵۶') === '123456', 'ارقامِ فارسی به لاتین تبدیل می‌شوند', OTP.digits('۱۲۳۴۵۶'));
say(OTP.digits('١٢٣٤٥٦') === '123456', 'ارقامِ عربی هم', OTP.digits('١٢٣٤٥٦'));
say(OTP.digits('کد: 483920 است') === '483920', 'رقم‌ها از متنِ فارسی بیرون کشیده می‌شوند', OTP.digits('کد: 483920 است'));
say(OTP.digits(null) === '' && OTP.digits(undefined) === '', 'ورودیِ نال نمی‌شکند');
say(OTP.digits('۱۲3٤5۶') === '123456', 'مخلوطِ سه نوع رقم', OTP.digits('۱۲3٤5۶'));

// ═══════════════ 🔴 پرکردنِ خودکارِ iOS ═══════════════

{
  // iOS کلِ کد را در خانهٔ **اول** می‌ریزد
  const r = OTP.distribute(EMPTY, '123456', 0);

  eq(r.values, ['1', '2', '3', '4', '5', '6'], '🔴 کدِ کاملِ iOS روی خانه‌ها پخش می‌شود (نه بریده)');
  say(r.focus === 5, 'تمرکز روی خانهٔ آخر می‌رود', String(r.focus));
  say(OTP.isComplete(r.values), 'کد کامل شناخته می‌شود ⇒ ارسالِ خودکار');
}

{
  // همان، ولی با ارقامِ فارسی که اپراتور گاهی می‌فرستد
  const r = OTP.distribute(EMPTY, '۸۲۴۶۲۹', 0);

  eq(r.values, ['8', '2', '4', '6', '2', '9'], 'کدِ فارسیِ خودکار هم درست پخش می‌شود');
}

// ═══════════════ تایپِ دستی — نباید بشکند ═══════════════

{
  let s = EMPTY;
  '483920'.split('').forEach((d, i) => { s = OTP.distribute(s, d, i).values; });

  eq(s, ['4', '8', '3', '9', '2', '0'], 'تایپِ رقم‌به‌رقم مثلِ قبل کار می‌کند');
}

{
  // اصلاحِ یک رقمِ اشتباه در وسط
  const r = OTP.distribute(['4', '8', '3', '9', '2', '0'], '7', 2);

  eq(r.values, ['4', '8', '7', '9', '2', '0'], 'رقمِ وسط جایگزین می‌شود، نه اضافه');
  say(r.focus === 3, 'و تمرکز به خانهٔ بعد می‌رود', String(r.focus));
}

{
  const r = OTP.distribute(['1', '2', '3', '4', '5', '6'], '', 3);

  eq(r.values, ['1', '2', '3', '', '5', '6'], 'پاک‌کردنِ یک خانه فقط همان را خالی می‌کند');
}

// ═══════════════ چسباندن ═══════════════

{
  const r = OTP.distribute(EMPTY, 'کد ورود شما: 652506', 0);

  eq(r.values, ['6', '5', '2', '5', '0', '6'], 'چسباندنِ کلِ متنِ پیامک هم کد را درمی‌آورد');
}

{
  // سرریز نباید از مرزِ آرایه بیرون بزند
  const r = OTP.distribute(EMPTY, '1234567890', 0);

  eq(r.values, ['1', '2', '3', '4', '5', '6'], 'رقمِ اضافه دور ریخته می‌شود، نه اینکه بشکند');
}

{
  // شروع از خانهٔ چهارم با سه رقم
  const r = OTP.distribute(['1', '2', '3', '', '', ''], '456', 3);

  eq(r.values, ['1', '2', '3', '4', '5', '6'], 'سرریز از نقطهٔ شروع درست است');
}

// ═══════════════ کامل‌بودن ═══════════════

say(OTP.isComplete(['1', '2', '3', '4', '5', '6']) === true, 'شش رقم = کامل');
say(OTP.isComplete(['1', '2', '3', '4', '5', '']) === false, 'پنج رقم = ناکامل');
say(OTP.isComplete(EMPTY) === false, 'خالی = ناکامل');

console.log(`\n✔ ${pass}   ✘ ${fail}`);
process.exit(fail ? 1 : 0);
