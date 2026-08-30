/*
| اجرای گرهٔ iran-probe همان‌طور که n8n اجرایش می‌کند: بدنه داخل تابعِ async،
| `$json` از بیرون، و `this.helpers` قابلِ تزریق — پس مسیرِ HTTP با استاب
| سنجیده می‌شود، بی‌هیچ تماسِ شبکه.
|
| اجرا:  node iran-probe.test.js
*/
const fs = require('fs');

const code = fs.readFileSync(__dirname + '/iran-probe.js', 'utf8');
// n8n بدنهٔ Code node را در تابعِ async می‌گذارد؛ همان شکل بازسازی می‌شود
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;
const runner = new AsyncFunction('$json', code);
const run = (json, helpers) => runner.call(helpers ? { helpers } : {}, json);

let pass = 0, fail = 0;
const say = (ok, label, extra) => {
  if (ok) { pass++; console.log('  ✔ ' + label); }
  else { fail++; console.log('  ✘ ' + label + (extra ? '   ← ' + JSON.stringify(extra) : '')); }
};

const TOKEN = 'probe-secret';
const req = (target, over = {}) => ({
  probeToken: TOKEN,
  headers: { 'x-probe-token': TOKEN },
  body: { target },
  ...over,
});

(async () => {
  // ── توکن ──
  {
    const r = (await run(req('https://example.com/', { headers: {} })))[0].json;
    say(r.ok === false && r.error === 'bad_token', 'بدون توکن رد می‌شود');
  }
  {
    const r = (await run(req('https://example.com/', { headers: { 'x-probe-token': 'wrong' } })))[0].json;
    say(r.ok === false && r.error === 'bad_token', 'توکن غلط رد می‌شود');
  }
  {
    const r = (await run(req('https://example.com/', { probeToken: '' })))[0].json;
    say(r.ok === false && r.error === 'bad_token', 'توکنِ پیکربندی‌نشده یعنی همه‌چیز بسته — fail-closed');
  }

  // ── هدف ──
  {
    const r = (await run(req('ftp://example.com/')))[0].json;
    say(r.ok === false && r.error === 'bad_scheme', 'فقط http/https');
  }
  {
    const r = (await run(req('not a url')))[0].json;
    say(r.ok === false && r.error === 'bad_target', 'هدفِ بی‌شکل رد می‌شود');
  }
  for (const bad of ['http://10.1.2.3/', 'http://192.168.1.1/', 'http://172.16.0.9/', 'http://127.0.0.1/', 'http://169.254.169.254/', 'http://localhost/']) {
    const r = (await run(req(bad)))[0].json;
    say(r.ok === false && r.error === 'private_target', 'هدفِ خصوصی رد می‌شود: ' + bad, r);
  }

  // ── مسیرِ HTTP با استابِ helper ──
  {
    let seen = null;
    const helpers = { httpRequest: async (opt) => { seen = opt; return { statusCode: 200 }; } };
    const r = (await run(req('https://example.com/'), helpers))[0].json;
    say(r.ok === true && r.status === 200 && typeof r.total_ms === 'number', 'موفق: status و total_ms برمی‌گردد', r);
    say(seen && seen.url === 'https://example.com/' && seen.ignoreHttpStatusErrors === true, 'کدِ غیر ۲۰۰ خطا حساب نمی‌شود (ignoreHttpStatusErrors)', seen);
  }
  {
    const helpers = { httpRequest: async () => ({ statusCode: 403 }) };
    const r = (await run(req('https://example.com/'), helpers))[0].json;
    say(r.ok === true && r.status === 403, 'کدِ 403 هم «سنجیده شد» است — قضاوت با لاراول', r);
  }
  {
    const helpers = { httpRequest: async () => { throw new Error('timeout'); } };
    const r = (await run(req('https://example.com/'), helpers))[0].json;
    say(r.ok === false && r.error === 'fetch_failed', 'شکستِ fetch خطای صریح می‌دهد، نه exception', r);
  }

  console.log(`\n✔ ${pass}   ✘ ${fail}`);
  process.exit(fail ? 1 : 0);
})();
