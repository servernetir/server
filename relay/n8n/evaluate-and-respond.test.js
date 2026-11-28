/*
| گرهٔ «ارزیابیِ پاسخ» و «پاسخ» — با شکلِ **واقعیِ** پاسخِ آی‌پی‌پنل.
|
| 🔴 چرا این تست نوشته شد: نسخهٔ اولِ ارزیابی دنبالِ `res.code` و `res.status` و
| `res.data.message_id` می‌گشت. هیچ‌کدام وجود ندارند. پس پیامکی که واقعاً رفته
| بود «شکست» گزارش شد، `OtpService` چالش را حذف کرد، و مشتری کدی روی گوشی داشت
| که در دیتابیس نبود.
|
| ⚠️ نمونه‌های زیر **کپیِ عینیِ** پاسخ‌هایی هستند که در اجرای واقعیِ n8n دیده
| شدند (اجرای ۸۳۹۴ و ۸۳۹۳)، نه حدسِ ما از شکلِ API. اگر از روی حدس بنویسیم،
| تست همان اشتباهی را تأیید می‌کند که باگ را ساخت.
*/
const fs = require('fs');

const evaluate = new Function('$json', '$', fs.readFileSync(__dirname + '/evaluate-response.js', 'utf8'));
const respond = new Function('$', fs.readFileSync(__dirname + '/respond.js', 'utf8'));

const PREV = {
  valid: true, template: 'otp', request_id: 'req-1', mobile: '+989142223343',
  ipPanelBody: { sending_type: 'pattern', code: 'u507b9k77p8oim0' },
  bale_message_id: null,
};

/** شبیه‌سازیِ `$('نامِ گره')` در n8n */
const ref = (map) => (name) => ({ first: () => ({ json: map[name] }) });

const run = (ippanelResponse) => {
  const ev = evaluate(ippanelResponse, ref({ 'Verify & Map Template': PREV }))[0].json;
  const out = respond(ref({ 'Evaluate Response': ev }))[0].json;

  return { ev, out };
};

let pass = 0, fail = 0;
const say = (ok, name, extra = '') => { ok ? pass++ : fail++; console.log((ok ? '  ✔ ' : '  ✘ ') + name + (extra ? '  → ' + extra : '')); };

// ── ۱) پاسخِ موفقِ واقعی (اجرای ۸۳۹۴) ──
{
  const { ev, out } = run({
    data: { message_outbox_ids: [1462501007] },
    meta: { status: true, message: 'انجام شد', message_parameters: [], message_code: '200-1' },
  });

  say(ev.delivered === true, '🔴 پاسخِ موفقِ واقعی «رفت» شمرده شد', String(ev.delivered));
  say(out.status === 'sent', 'گرهٔ پاسخ sent می‌دهد', out.status);
  say(out.outbox_id === 1462501007, 'شناسهٔ صندوقِ خروجی برای ردیابی برمی‌گردد', String(out.outbox_id));
  say(out.reason === undefined, 'در موفقیت دلیلی نمی‌فرستد');
}

// ── ۲) توکنِ نامعتبر (اجرای ۸۳۹۳ — قبل از Credential) ──
{
  const { ev, out } = run({
    error: {
      message: '401 - "{\\"data\\":{\\"data\\":null},\\"meta\\":{\\"status\\":false,\\"message\\":\\"Invalid token\\"}}"',
      status: 401,
    },
  });

  say(ev.delivered === false, 'توکنِ نامعتبر شکست شمرده شد');
  say(out.status === 'failed', 'گرهٔ پاسخ failed می‌دهد');
  say(out.reason === 'provider_failed', '401 maps to stable provider_failed');
  say(!JSON.stringify(out).includes('Invalid token'), 'raw provider text is not returned');
}

// ── ۳) شکستِ صریحِ اپراتور ──
{
  const { out } = run({ data: { data: null }, meta: { status: false, message: 'اعتبار کافی نیست', message_code: '400-3' } });

  say(out.status === 'failed', 'meta.status=false شکست است', out.status);
  say(out.reason === 'provider_failed', 'explicit provider rejection is classified');
  say(!JSON.stringify(out).includes('اعتبار کافی نیست'), 'raw provider text is not returned');
}

/*
| ── ۴) فایل‌کلوز ──
| پاسخِ ناشناخته باید **شکست** باشد. ادعای دروغینِ موفقیت بدتر است، چون کسی
| دنبالِ پیامکی که «رفته» نمی‌گردد و مشتری بی‌خبر پشتِ درِ بسته می‌مانَد.
*/
{
  say(run({})[0] === undefined || run({}).out.status === 'failed', 'پاسخِ خالی شکست است', run({}).out.status);
  say(run({ something: 'else' }).out.status === 'failed', 'پاسخِ ناشناخته شکست است');
  say(run({ meta: { status: 'true' } }).out.status === 'failed', '⚠️ رشتهٔ "true" با بولینِ true اشتباه نمی‌شود');
}

// ── ۵) صندوقِ خروجیِ خالی نباید موفقیت جا بزند ──
{
  say(run({ data: { message_outbox_ids: [] } }).out.status === 'failed',
    'آرایهٔ خالیِ شناسه = شکست', run({ data: { message_outbox_ids: [] } }).out.status);
}

// ── ۶) Rate limits are classified from structured HTTP status only ──
{
  const diagnostic = "Try spacing your requests out using the batching settings under 'Options'";
  for (const response of [
    { error: { status: 429, message: diagnostic } },
    { error: { statusCode: 429, message: diagnostic } },
    { statusCode: '429', message: diagnostic },
  ]) {
    const { ev, out } = run(response);
    say(ev.delivered === false && out.status === 'failed', '429 is a definitive failure');
    say(out.reason === 'rate_limited', 'structured 429 is classified accurately');
    say(!JSON.stringify(out).includes(diagnostic), 'raw diagnostic is not returned');
  }
  say(run({ error: { message: '429 ' + diagnostic } }).out.reason === 'upstream_rejected',
    'text alone cannot establish HTTP 429');
  say(run({ error: { status: 500, message: diagnostic } }).out.reason === 'upstream_rejected',
    'ambiguous 500 is not classified as rate-limited');
  const conflicting429 = run({ data: { message_outbox_ids: [42] }, meta: { status: true }, error: { status: 429 } }).out;
  say(conflicting429.status === 'failed' && conflicting429.reason === 'rate_limited',
    'verified HTTP 429 cannot be accepted despite contradictory success evidence');
  const conflicting500 = run({ data: { message_outbox_ids: [42] }, meta: { status: true }, error: { status: 500 } }).out;
  say(conflicting500.status === 'failed' && conflicting500.reason === 'upstream_rejected',
    'verified HTTP 500 cannot be accepted despite contradictory success evidence');
  const conflictingStatuses = run({ data: { message_outbox_ids: [42] }, error: { status: 200 }, statusCode: 429 }).out;
  say(conflictingStatuses.status === 'failed' && conflictingStatuses.reason === 'rate_limited',
    'a structured error status takes precedence over a conflicting success status');
}

// ── ۷) هیچ رازی در پاسخ نیست ──
{
  const { out } = run({ meta: { status: true, message: 'انجام شد' }, data: { message_outbox_ids: [7] } });

  say(!('config' in out) && !JSON.stringify(out).includes('ipPanelApiKey'),
    '🔴 پاسخ هیچ پیکربندی/رازی برنمی‌گرداند');
}

console.log(`\n✔ ${pass}   ✘ ${fail}`);
process.exit(fail ? 1 : 0);
