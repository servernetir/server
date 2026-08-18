/*
| تستِ گرهٔ «Cloud Phone Relay».
|
|   node cloud-phone-relay.test.js
|
| ═══ چرا پاکت را PHP می‌سازد ═══
|
| درسِ `verify-and-map-template.test.js`: اگر پاکت را در جاوااسکریپت هم بسازیم،
| فقط ثابت می‌کنیم کدِ ما با **خودش** می‌خوانَد. چیزی که باید بسنجیم هم‌خوانیِ
| دو زبان است — base64url، UTF-8 فارسی، و HMAC. پس پاکت را همان
| `OutgoingCallService::encode()` می‌سازد (بازتولیدشده در یک اسکریپتِ کوچکِ PHP)
| و جاوااسکریپت فقط تأییدش می‌کند.
|
| اگر PHP روی این ماشین نباشد، تستِ هم‌خوانی **رد** می‌شود نه اینکه بی‌صدا سبز
| بماند.
*/

const fs = require('fs');
const path = require('path');
const vm = require('vm');
const { execFileSync } = require('child_process');

const NODE_FILE = path.join(__dirname, 'cloud-phone-relay.node.js');

let pass = 0;
let fail = 0;
let skipped = 0;

function ok(cond, label) {
  if (cond) { pass++; console.log('  \x1b[32m✓\x1b[0m ' + label); }
  else { fail++; console.log('  \x1b[31m✗\x1b[0m ' + label); }
}
function eq(actual, expected, label) {
  const a = JSON.stringify(actual), e = JSON.stringify(expected);
  ok(a === e, label + (a === e ? '' : `\n      expected: ${e}\n      actual:   ${a}`));
}

// ── ساختِ پاکت با PHP، دقیقاً مثلِ OutgoingCallService ───────────────────
function phpEnvelope(payload, secret) {
  const script = `<?php
$payload = json_decode($argv[1], true);
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$b64  = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
echo 'CLOUD_PHONE_V1:'.$b64.'.'.hash_hmac('sha256', $b64, $argv[2]);
`;
  const tmp = path.join(__dirname, '.envelope.tmp.php');
  fs.writeFileSync(tmp, script, 'utf8');
  try {
    return execFileSync('php', [tmp, JSON.stringify(payload), secret], { encoding: 'utf8' });
  } finally {
    fs.unlinkSync(tmp);
  }
}

function hasPhp() {
  try { execFileSync('php', ['-v'], { stdio: 'ignore' }); return true; } catch { return false; }
}

// ── اجرای گره در سندباکسی مثلِ n8n ───────────────────────────────────────
/*
| ⚠️ `this` در گرهٔ n8n به آبجکتِ اجرا اشاره می‌کند. برای شبیه‌سازی، تابع را
| با `Reflect.apply` روی یک شیءِ حاوی helpers صدا می‌زنیم.
*/
function runNodeWithThis(config, httpImpl) {
  const source = fs.readFileSync(NODE_FILE, 'utf8');
  const calls = [];

  const sandbox = {
    Object, JSON, String, Number, Math, Date, Uint8Array, Uint32Array, Array, Error,
  };
  sandbox.$json = config;

  const factory = new vm.Script('(function(){ return async function(){\n' + source + '\n}; })()')
    .runInContext(vm.createContext(sandbox), { timeout: 10000 });

  const ctx = {
    helpers: {
      httpRequest: async (opts) => {
        calls.push(opts);
        return httpImpl ? httpImpl(opts) : { statusCode: 200 };
      },
    },
  };

  return Reflect.apply(factory, ctx, []).then((result) => ({ result, calls }));
}

const SECRET = 'shared-secret-for-tests';
const TOKEN = 'the-real-phone-token';

const baseConfig = (envelope, over = {}) => Object.assign({
  relaySecret: SECRET,
  phoneToken: TOKEN,
  apiBase: 'https://coreapi.daftareshoma.com',
  fromNumber: '02171057757',
  body: { envelope },
}, over);

const payload = (over = {}) => Object.assign({
  version: 1,
  action: 'outgoing_call',
  to_number: '9121112222',
  from_number: '9142223343',
  caller_extension: '71057757',
  request_id: 'e7a1c2d4-0000-4000-8000-000000000001',
  issued_at: Math.floor(Date.now() / 1000),
}, over);

(async () => {
  console.log('\nCloud Phone Relay — گرهٔ n8n\n');

  if (!hasPhp()) {
    console.log('  \x1b[31mPHP روی این ماشین نیست — تستِ هم‌خوانیِ دو زبان اجرا نشد.\x1b[0m');
    console.log('  این تست بدونِ PHP بی‌معناست، پس صریحاً شکست می‌خورد.\n');
    process.exit(1);
  }

  // ── ۱. مسیرِ خوش‌بینانه ──────────────────────────────────────────────
  console.log('  ── هم‌خوانیِ PHP و JS ──');
  {
    const env = phpEnvelope(payload(), SECRET);
    const { result, calls } = await runNodeWithThis(baseConfig(env));

    eq(result[0].json.status, 'sent', 'پاکتِ ساختهٔ PHP در JS تأیید می‌شود');
    ok(calls.length === 1, 'دقیقاً یک درخواست به API رفت');
    eq(calls[0].url, 'https://coreapi.daftareshoma.com/api/Customize/OutgoingCall', 'اندپوینتِ درست');
    eq(calls[0].body.to_number, '09121112222', 'مقصد، با صفرِ ابتدایی');
    eq(calls[0].body.from_number, '09142223343', 'پایی که اول زنگ می‌خورد، از پاکت');
    eq(calls[0].body.caller_extension, '71057757', 'خطِ ابری، از پاکت');
    ok(calls[0].headers.Authorization === 'Bearer ' + TOKEN, 'توکن از Relay Config به هدر می‌رود');
  }

  // ── ۲. امضا ──────────────────────────────────────────────────────────
  console.log('\n  ── امضا ──');
  {
    const env = phpEnvelope(payload(), SECRET);

    // دستکاریِ یک کاراکترِ امضا
    const tampered = env.slice(0, -1) + (env.slice(-1) === 'a' ? 'b' : 'a');
    const r1 = await runNodeWithThis(baseConfig(tampered));
    eq(r1.result[0].json.reason, 'bad_signature', 'امضای دستکاری‌شده رد می‌شود');
    ok(r1.calls.length === 0, 'و هیچ تماسی برقرار نمی‌شود');

    // 🔴 تعویضِ شمارهٔ مقصد با امضای قدیمی — حملهٔ واقعی
    const good = phpEnvelope(payload(), SECRET);
    const evil = phpEnvelope(payload({ to_number: '9199999999' }), SECRET);
    const swapped = evil.split('.')[0] + '.' + good.split('.').slice(1).join('.');
    const r2 = await runNodeWithThis(baseConfig(swapped));
    eq(r2.result[0].json.reason, 'bad_signature', 'تعویضِ بدنه با امضای قدیمی رد می‌شود');
    ok(r2.calls.length === 0, 'و هیچ تماسی برقرار نمی‌شود');

    // رازِ اشتباه
    const r3 = await runNodeWithThis(baseConfig(phpEnvelope(payload(), 'wrong-secret')));
    eq(r3.result[0].json.reason, 'bad_signature', 'رازِ ناهماهنگ رد می‌شود');
  }

  // ── ۳. بازپخش ────────────────────────────────────────────────────────
  console.log('\n  ── بازپخش ──');
  {
    const old = phpEnvelope(payload({ issued_at: Math.floor(Date.now() / 1000) - 400 }), SECRET);
    const r = await runNodeWithThis(baseConfig(old));
    eq(r.result[0].json.reason, 'stale_envelope', 'پاکتِ خارج از پنجرهٔ ۱۸۰ ثانیه رد می‌شود');
    ok(r.calls.length === 0, 'و هیچ تماسی برقرار نمی‌شود');

    const future = phpEnvelope(payload({ issued_at: Math.floor(Date.now() / 1000) + 400 }), SECRET);
    const r2 = await runNodeWithThis(baseConfig(future));
    eq(r2.result[0].json.reason, 'stale_envelope', 'پاکتِ از آینده هم رد می‌شود');
  }

  // ── ۴. پیکربندیِ جاافتاده باید ببندد ─────────────────────────────────
  console.log('\n  ── fail-closed ──');
  {
    const env = phpEnvelope(payload(), SECRET);

    const r1 = await runNodeWithThis(baseConfig(env, { relaySecret: '' }));
    eq(r1.result[0].json.reason, 'relay_not_configured', 'رازِ خالی وب‌هوک را می‌بندد، نه باز');
    ok(r1.calls.length === 0, 'و هیچ تماسی برقرار نمی‌شود');

    const r2 = await runNodeWithThis(baseConfig(env, { phoneToken: '' }));
    eq(r2.result[0].json.reason, 'token_not_configured', 'توکنِ خالی هم می‌بندد');
    ok(r2.calls.length === 0, 'و هیچ تماسی برقرار نمی‌شود');
  }

  // ── ۵. پاکت‌های بدشکل ────────────────────────────────────────────────
  console.log('\n  ── ورودیِ بدشکل ──');
  {
    const cases = [
      ['', 'no_envelope'],
      ['salam', 'unsupported_envelope'],
      ['CLOUD_PHONE_V1:nodot', 'malformed_envelope'],
    ];
    for (const [env, reason] of cases) {
      const r = await runNodeWithThis(baseConfig(env));
      eq(r.result[0].json.reason, reason, `«${env || '(خالی)'}» ⇒ ${reason}`);
    }

    const wrongAction = phpEnvelope(payload({ action: 'delete_everything' }), SECRET);
    const r = await runNodeWithThis(baseConfig(wrongAction));
    eq(r.result[0].json.reason, 'unknown_action', 'اکشنِ ناشناخته رد می‌شود');
    ok(r.calls.length === 0, 'و هیچ تماسی برقرار نمی‌شود');

    const badVersion = phpEnvelope(payload({ version: 2 }), SECRET);
    eq((await runNodeWithThis(baseConfig(badVersion))).result[0].json.reason, 'bad_version', 'نسخهٔ ناشناخته رد می‌شود');

    const noDest = phpEnvelope(payload({ to_number: '' }), SECRET);
    eq((await runNodeWithThis(baseConfig(noDest))).result[0].json.reason, 'no_destination', 'مقصدِ خالی رد می‌شود');

    const noFrom = phpEnvelope(payload({ from_number: '' }), SECRET);
    eq((await runNodeWithThis(baseConfig(noFrom))).result[0].json.reason, 'no_from_number', 'شمارهٔ تماس‌گیرندهٔ خالی رد می‌شود');

    /*
    | ⚠️ خطِ ابریِ خالی در پاکت **رد نمی‌شود** — از Relay Config برداشته می‌شود.
    | عمدی است: خط یکی است و ثابت، و اگر روزی پاکتِ قدیمی‌تری بیاید نباید
    | تماسش بیفتد.
    */
    const noExt = phpEnvelope(payload({ caller_extension: '' }), SECRET);
    const rNoExt = await runNodeWithThis(baseConfig(noExt, { extension: '71057757' }));
    eq(rNoExt.result[0].json.status, 'sent', 'خطِ ابریِ نبود از Relay Config پر می‌شود');
    eq(rNoExt.calls[0].body.caller_extension, '71057757', 'و همان مقدار به API می‌رود');

    const noExtAnywhere = phpEnvelope(payload({ caller_extension: '' }), SECRET);
    const rNone = await runNodeWithThis(baseConfig(noExtAnywhere, { extension: '', fromNumber: '' }));
    eq(rNone.result[0].json.reason, 'no_extension', 'ولی اگر هیچ‌جا نبود، رد می‌شود');
  }

  // ── ۶. پاسخِ API ─────────────────────────────────────────────────────
  console.log('\n  ── پاسخِ تأمین‌کننده ──');
  {
    const env = phpEnvelope(payload(), SECRET);

    const r401 = await runNodeWithThis(baseConfig(env), () => ({ statusCode: 401 }));
    eq(r401.result[0].json.status, 'failed', '۴۰۱ شکست است، نه موفقیت');
    eq(r401.result[0].json.reason, 'api_status_401', 'و علتش به لاراول می‌رسد (توکن منقضی)');

    const r500 = await runNodeWithThis(baseConfig(env), () => ({ statusCode: 500 }));
    eq(r500.result[0].json.status, 'failed', '۵۰۰ شکست است');

    const rThrow = await runNodeWithThis(baseConfig(env), () => { throw new Error('ECONNRESET'); });
    eq(rThrow.result[0].json.status, 'failed', 'خطای شبکه شکست است، نه سکوت');
    eq(rThrow.result[0].json.reason, 'request_failed', 'و علتش صریح است');

    const r204 = await runNodeWithThis(baseConfig(env), () => ({ statusCode: 204 }));
    eq(r204.result[0].json.status, 'sent', 'هر ۲xx موفق است (این اندپوینت بدنه ندارد)');
  }

  // ── ۷. یونیکد ────────────────────────────────────────────────────────
  console.log('\n  ── یونیکد ──');
  {
    /*
    | ⚠️ payload امروز فیلدِ فارسی ندارد، ولی فردا ممکن است داشته باشد (نامِ
    | کارشناس، برچسب). اگر base64url/UTF-8 دو زبان واگرا باشند، امضا می‌شکند —
    | و آن روز کسی به این فایل شک نمی‌کند.
    */
    const env = phpEnvelope(payload({ note: 'تماس با مشتریِ ویژه — ۰۹۱۴' }), SECRET);
    const r = await runNodeWithThis(baseConfig(env));
    eq(r.result[0].json.status, 'sent', 'پاکتِ حاویِ فارسی و ارقامِ فارسی سالم عبور می‌کند');
  }

  console.log('\n' + '─'.repeat(60));
  console.log(`  ${pass} ادعای سبز، ${fail} قرمز` + (skipped ? `، ${skipped} رد‌شده` : ''));
  console.log('─'.repeat(60) + '\n');
  process.exit(fail === 0 ? 0 : 1);
})();
