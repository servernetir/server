/* Local isolated Chrome smoke test. No production sessions, database or paid API. */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const { execFile } = require('node:child_process');
const app = path.resolve(__dirname, '../..');
const php = process.env.TEST_PHP || 'php';
const phpArgs = JSON.parse(process.env.TEST_PHP_ARGS || '[]');
const chrome = process.env.TEST_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const output = process.env.TEST_OUTPUT || path.join(os.tmpdir(), 'servernet-jdate-browser');
const run = (file, args) => new Promise((resolve, reject) => execFile(file, args, { cwd: app, windowsHide: true, maxBuffer: 5 * 1024 * 1024 }, (error, stdout, stderr) => error ? reject(Object.assign(error, { stdout, stderr })) : resolve({ stdout, stderr })));
for (const width of [390, 1440]) test('Jalali picker in isolated Chrome at '+width+'px', async () => {
  await fs.mkdir(output, { recursive: true });
  const component = (await run(php, [...phpArgs, path.join(app, 'tests/Support/finance-jdate-bridge.php'), app, 'component'])).stdout;
  const scenario = await fs.readFile(path.join(__dirname,'jdate-scenario.js'),'utf8');
  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://localhost');
      if(url.pathname==='/admin/jdate') {
        const r = await run(php, [...phpArgs, path.join(app, 'tests/Support/finance-jdate-bridge.php'), app, url.search.slice(1)]);
        res.setHeader('Content-Type','application/json');res.end(r.stdout);return;
      }
      if(url.pathname==='/'){res.setHeader('Content-Type','text/html; charset=utf-8');res.end('<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0;background:#e2e8f0"><iframe src="/fixture" style="border:0;width:'+width+'px;height:880px;display:block"></iframe><pre id="qa-result" hidden></pre></body></html>');return;}
      if(url.pathname==='/test.js'){res.setHeader('Content-Type','application/javascript');res.end(scenario);return;}
      if(url.pathname.startsWith('/assets/')) {
        const name=path.join(app,'public',url.pathname);res.setHeader('Content-Type',url.pathname.endsWith('.css')?'text/css':'application/javascript');res.end(await fs.readFile(name));return;
      }
      res.setHeader('Content-Type','text/html; charset=utf-8');
      res.end('<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/assets/css/admin.css"><link rel="stylesheet" href="/assets/css/jdate.css"><style>body{padding:16px;font-family:var(--font);width:100%}h1{font-size:20px}button,input,textarea{font-family:inherit}form{display:flex;gap:16px;flex-wrap:wrap;margin:20px 0;padding:15px;border:1px solid #334155;border-radius:12px}label{display:flex;gap:7px;flex-direction:column}#qa-result{font-size:10px;direction:ltr;white-space:pre-wrap;max-width:100%}</style></head><body><h1>انتخاب تاریخ در حسابداری</h1>'+component+'<pre id="qa-result"></pre><script src="/assets/js/jdate.js"></script><script src="/assets/js/finance-dates.js"></script><script src="/test.js"></script></body></html>');
    }catch(e){res.statusCode=500;res.end(String(e.message));}
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const profile = await fs.mkdtemp(path.join(os.tmpdir(),'servernet-jdate-chrome-'));
  try {
    const r=await run(chrome,['--headless=new','--no-first-run','--no-default-browser-check','--disable-background-networking','--user-data-dir='+profile,'--window-size='+Math.max(500,width)+',900','--virtual-time-budget=15000','--dump-dom','--screenshot='+path.join(output,'picker-'+width+'.png'),'http://127.0.0.1:'+server.address().port]);
    const match=r.stdout.match(/<pre id="qa-result"[^>]*>([\s\S]*?)<\/pre>/);
    const report=match?JSON.parse(match[1].replace(/&quot;/g,'"').replace(/&amp;/g,'&')):{error:r.stdout.slice(-1000)};
    await fs.writeFile(path.join(output,'picker-'+width+'.json'),JSON.stringify(report,null,2));
    assert.equal(report.status,'passed',JSON.stringify(report));
    assert.ok(report.checks.length>=10);
  }finally{await new Promise(resolve=>server.close(resolve));}
});
