/*
| اجرای کدِ گرهٔ n8n در همان شکلی که n8n اجرایش می‌کند (بدنه در تابع، `$json`
| از بیرون)، با پاکتی که **خودِ PHP** ساخته.
*/
const fs = require('fs');
const { execFileSync } = require('child_process');

const SECRET = 'test-shared-secret';
const CHAT = '6170924327';
const SENDER = '2017652664';

const code = fs.readFileSync(__dirname + '/verify-and-map-template.js', 'utf8');

// n8n بدنهٔ Code node را داخلِ یک تابع می‌گذارد، پس `return` سطحِ بالا مجاز است
const run = new Function('$json', code);

const envelope = execFileSync('C:/php/php.exe', [__dirname + '/make-envelope.php', SECRET], { encoding: 'utf8' });

const cfg = {
  baleReceiverBotToken: 'RECV', allowedRelayChatId: CHAT, allowedSenderBotId: SENDER,
  relaySecret: SECRET, ipPanelApiKey: 'KEY', ipPanelSenderNumber: '+983000505',
  ipPanelEndpoint: 'https://edge.ippanel.com/v1/api/send', maxMessageAgeSeconds: 180,
};

const update = (text, over = {}) => ({
  ...cfg,
  body: {
    update_id: 99,
    message: {
      message_id: 5,
      chat: { id: Number(CHAT) },
      from: { id: Number(SENDER), is_bot: true },
      text,
      ...over,
    },
  },
});

let pass = 0, fail = 0;
const say = (ok, name, extra = '') => { ok ? pass++ : fail++; console.log((ok ? '  ✔ ' : '  ✘ ') + name + (extra ? '  → ' + extra : '')); };

// ── ۱) مسیرِ خوش‌بینانه ──
{
  const r = run(update(envelope))[0].json;
  say(r.valid === true, 'پاکتِ معتبرِ PHP پذیرفته شد', r.valid ? '' : r.reason);
  say(r.ipPanelBody?.code === 'u507b9k77p8oim0', 'کدِ الگوی OTP درست است', r.ipPanelBody?.code);
  say(JSON.stringify(r.ipPanelBody?.params) === '{"otp":"483920"}', 'متغیرِ otp از کلیدِ code گرفته شد', JSON.stringify(r.ipPanelBody?.params));
  say(JSON.stringify(r.ipPanelBody?.recipients) === '["+989142223343"]', 'گیرنده درست است');
  say(r.ipPanelBody?.sending_type === 'pattern', 'نوعِ ارسال pattern است');
  say(r.bale_message_id === 5, 'شناسهٔ پیامِ بله برای حذف نگه داشته شد');
}

// ── ۲) امضای دستکاری‌شده ──
{
  const tampered = envelope.slice(0, -1) + (envelope.slice(-1) === 'a' ? 'b' : 'a');
  const r = run(update(tampered))[0].json;
  say(r.valid === false && r.reason === 'bad_signature', 'امضای دستکاری‌شده رد شد', r.reason);
}

// ── ۳) بدنهٔ دستکاری‌شده با امضای قدیمی ──
{
  const [pre, sig] = envelope.split('.');
  const b64 = pre.slice('SMS_RELAY_V1:'.length);
  const evil = Buffer.from(JSON.stringify({
    version: 1, template: 'otp', mobile: '+989120000000',
    params: { code: '000000' }, request_id: 'x', issued_at: Math.floor(Date.now() / 1000),
  })).toString('base64url');
  const r = run(update('SMS_RELAY_V1:' + evil + '.' + sig))[0].json;
  say(r.valid === false && r.reason === 'bad_signature', 'تغییرِ شماره با امضای قدیمی رد شد', r.reason);
}

// ── ۴) گروهِ غیرمجاز ──
{
  const r = run(update(envelope, { chat: { id: 111 } }))[0].json;
  say(r.valid === false && r.reason === 'chat_not_allowed', 'گروهِ دیگر رد شد', r.reason);
}

// ── ۵) فرستندهٔ غیرمجاز ──
{
  const r = run(update(envelope, { from: { id: 777, is_bot: true } }))[0].json;
  say(r.valid === false && r.reason === 'sender_not_allowed', 'فرستندهٔ دیگر رد شد', r.reason);
}

// ── ۶) پاکتِ کهنه (خارج از پنجرهٔ ۱۸۰ ثانیه) ──
{
  const old = execFileSync('C:/php/php.exe', ['-r',
    '$p=["version"=>1,"template"=>"otp","mobile"=>"+989142223343","params"=>["code"=>"1"],"request_id"=>"x","issued_at"=>time()-9999];' +
    '$j=json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);' +
    '$b=rtrim(strtr(base64_encode($j),"+/","-_"),"=");' +
    'echo "SMS_RELAY_V1:".$b.".".hash_hmac("sha256",$b,"' + SECRET + '");'], { encoding: 'utf8' });
  const r = run(update(old))[0].json;
  say(r.valid === false && r.reason === 'expired_or_invalid_timestamp', 'پاکتِ کهنه (replay) رد شد', r.reason);
}

// ── ۷) الگوی ناشناخته ──
{
  const unk = execFileSync('C:/php/php.exe', ['-r',
    '$p=["version"=>1,"template"=>"no_such","mobile"=>"+989142223343","params"=>["code"=>"1"],"request_id"=>"x","issued_at"=>time()];' +
    '$j=json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);' +
    '$b=rtrim(strtr(base64_encode($j),"+/","-_"),"=");' +
    'echo "SMS_RELAY_V1:".$b.".".hash_hmac("sha256",$b,"' + SECRET + '");'], { encoding: 'utf8' });
  const r = run(update(unk))[0].json;
  say(r.valid === false && r.reason === 'unknown_template', 'الگوی ناشناخته رد شد', r.reason);
}

// ── ۸) پیامِ بی‌متن (همان پروبِ من) نباید بشکند ──
{
  const r = run({ ...cfg, body: { update_id: 1, ping: 'x' } })[0].json;
  say(r.valid === false && r.reason === 'no_text_message', 'بدنهٔ بی‌پیام بی‌خطا رد شد', r.reason);
}

// ── ۸ب) مسیرِ **مستقیم** — همان پاکت، بی‌پوششِ بله ──
{
  const r = run({ ...cfg, body: { envelope } })[0].json;
  say(r.valid === true, 'پاکتِ مستقیم (بی‌بله) پذیرفته شد', r.valid ? '' : r.reason);
  say(r.ipPanelBody?.code === 'u507b9k77p8oim0', 'مسیرِ مستقیم همان کدِ الگو را می‌دهد');
  say(r.bale_message_id === null, 'بی‌شناسهٔ پیامِ بله — گرهٔ حذف نباید تلاش کند', String(r.bale_message_id));
  say(r.config === undefined, '🔴 رازها در خروجی نیستند (تاریخچهٔ اجرا ذخیره‌شان نکند)');
}

// ── ۸ج) مسیرِ مستقیم هم بی‌امضای درست رد می‌شود ──
{
  const bad = envelope.slice(0, -1) + (envelope.slice(-1) === 'a' ? 'b' : 'a');
  const r = run({ ...cfg, body: { envelope: bad } })[0].json;
  say(r.valid === false && r.reason === 'bad_signature', 'مسیرِ مستقیم: امضای غلط رد شد', r.reason);
}

// ── ۸د) مسیرِ بله هنوز کار می‌کند (برگشت‌پذیری) ──
{
  const r = run(update(envelope))[0].json;
  say(r.valid === true && r.bale_message_id === 5, 'مسیرِ بله دست‌نخورده کار می‌کند');
}

// ── ۹) متغیرِ جاافتاده ──
{
  const miss = execFileSync('C:/php/php.exe', ['-r',
    '$p=["version"=>1,"template"=>"invoice","mobile"=>"+989142223343","params"=>["number"=>"۱۲۳"],"request_id"=>"x","issued_at"=>time()];' +
    '$j=json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);' +
    '$b=rtrim(strtr(base64_encode($j),"+/","-_"),"=");' +
    'echo "SMS_RELAY_V1:".$b.".".hash_hmac("sha256",$b,"' + SECRET + '");'], { encoding: 'utf8' });
  const r = run(update(miss))[0].json;
  say(r.valid === false && r.reason === 'missing_param' && r.missing === 'amount', 'متغیرِ جاافتاده صریح گزارش شد', r.reason + '/' + r.missing);
}

// ── ۱۰) پیامِ فارسیِ واقعی با ارقامِ فارسی در متغیر ──
{
  const fa = execFileSync('C:/php/php.exe', ['-r',
    '$p=["version"=>1,"template"=>"invoice","mobile"=>"+989142223343","params"=>["number"=>"SN-۱۰۴۸۲۹","amount"=>"۲٬۵۰۰٬۰۰۰"],"request_id"=>"x","issued_at"=>time()];' +
    '$j=json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);' +
    '$b=rtrim(strtr(base64_encode($j),"+/","-_"),"=");' +
    'echo "SMS_RELAY_V1:".$b.".".hash_hmac("sha256",$b,"' + SECRET + '");'], { encoding: 'utf8' });
  const r = run(update(fa))[0].json;
  say(r.valid === true, 'متنِ فارسی با ارقامِ فارسی پذیرفته شد', r.valid ? '' : r.reason);
  say(r.ipPanelBody?.params?.amount === '۲٬۵۰۰٬۰۰۰', 'ارقامِ فارسی دست‌نخورده رسیدند', r.ipPanelBody?.params?.amount);
}

console.log(`\n✔ ${pass}   ✘ ${fail}`);
process.exit(fail ? 1 : 0);
