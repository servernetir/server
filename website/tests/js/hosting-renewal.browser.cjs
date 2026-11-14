/* Synthetic Laravel HTML only. CSP prevents account/payment requests. */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const {spawn} = require('node:child_process');
const app = path.resolve(__dirname, '../..');

async function chromiumReport(profile, reduced, url) {
  assert.equal(new URL(url).hostname, '127.0.0.1');
  // This static form needs no debugging socket. Chrome's native DOM export
  // includes the report after the iframe load handler has run.
  const args = ['--headless=new', '--no-first-run', '--no-default-browser-check',
    '--disable-background-networking', '--disable-gpu', '--disable-dev-shm-usage',
    '--user-data-dir='+profile, '--window-size=1480,1300', '--dump-dom',
    '--virtual-time-budget=4000', url];
  const output = await new Promise((resolve,reject) => {
    const child = spawn(process.env.TEST_CHROME || '/usr/bin/google-chrome', args,
      {stdio:['ignore','pipe','pipe']});
    let stdout = '', stderr = '';
    const timer = setTimeout(() => {child.kill(); reject(Error('Headless DOM export timeout: '+stderr.slice(-1000)));}, 45000);
    child.stdout.setEncoding('utf8'); child.stderr.setEncoding('utf8');
    child.stdout.on('data', chunk => {stdout += chunk;});
    child.stderr.on('data', chunk => {stderr = (stderr+chunk).slice(-4000);});
    child.once('error', error => {clearTimeout(timer); reject(error);});
    child.once('close', code => {clearTimeout(timer); code === 0 ? resolve(stdout) : reject(Error('Chrome exited '+code+': '+stderr));});
  });
  const result = output.match(/<pre id="qa-result">([^<]*)<\/pre>/);
  assert.ok(result && result[1], 'Chromium returned an actual form report');
  return result[1].replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
}

function scenario() {
  const result = {passed:false, checks:0};
  const check = (ok, label) => {if (!ok) throw Error(label); result.checks++;};
  try {
    const root = document.querySelector('[data-renewal-workspace]');
    check(!!root, 'actual Laravel renewal workspace');
    check(innerWidth === window.fixtureWidth, 'actual requested viewport');
    check(document.documentElement.scrollWidth <= innerWidth + 1, 'page fits viewport');
    check(root.scrollWidth <= root.clientWidth + 1, 'workspace fits container');
    check(!/renewal\.[a-z_]|ui\.sr_/.test(root.textContent), 'translations resolved');
    check(getComputedStyle(root).direction === (document.documentElement.lang === 'fa' ? 'rtl' : 'ltr'), 'locale direction');
    const cards = [...root.querySelectorAll('[data-renewal-period]')];
    const forms = [...root.querySelectorAll('form')];
    const state = window.fixtureState;
    check(cards.length === (state === 'empty' ? 0 : 4), 'catalog periods rendered');
    check(forms.length === (['empty','blocked'].includes(state) ? 0 : 4), 'protected states cannot submit');
    if (state === 'empty') check(root.querySelectorAll('.rn-empty a').length === 2, 'empty state offers support and back links');
    if (state === 'blocked') check(!!root.querySelector('[role=alert]'), 'payment blocker is visible');
    for (const card of cards) {
      check(card.scrollWidth <= card.clientWidth + 1, 'card has no overflow');
      check(card.querySelector('.rn-price strong').getBoundingClientRect().height > 20, 'final amount is prominent');
      check(card.querySelector('h3').textContent.trim().length > 2, 'period name is readable');
    }
    for (const form of forms) {
      check(form.method === 'post', 'invoice creation remains POST');
      check(new URL(form.action).pathname.startsWith(window.fixturePrefix+'/account/'), 'locale preserved');
      check(form.querySelector('[name=_token]').value.length > 20, 'CSRF token retained');
      check(form.querySelector('[name=quote_hash]').value.length === 64, 'quote signature retained');
      check(form.querySelector('[name=cycle]').value === form.closest('[data-renewal-period]').dataset.renewalPeriod, 'button targets its own period');
      const button = form.querySelector('button');
      check(button.getBoundingClientRect().height >= 44, 'touch target at least 44px');
      check(button.getBoundingClientRect().width <= cardWidth(form), 'button fits card');
      form.addEventListener('submit', event => {event.preventDefault(); form.dataset.checked = 'yes';});
      button.focus(); check(document.activeElement === button, 'keyboard accessible');
      button.click(); check(form.dataset.checked === 'yes', 'button submits original form');
    }
    result.passed = true;
  } catch (error) {result.error = error.message;}
  function cardWidth(form) {return form.closest('[data-renewal-period]').clientWidth;}
  const out = document.createElement('pre'); out.id = 'qa-result'; out.textContent = JSON.stringify(result); document.body.append(out);
}

test('renewal workspace in three locales, both themes, three widths and four payment states', {timeout:360000}, async () => {
  const directory = process.env.HOSTING_RENEWAL_PREVIEW_DIR;
  assert.ok(directory);
  const pages = new Map();
  for (const locale of ['fa','en','tr']) for (const state of ['catalog','contract','blocked','empty']) {
    const html = await fs.readFile(path.join(directory, locale+'-'+state+'.html'), 'utf8');
    pages.set(locale+'-'+state, html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
      .replace(/https?:\/\/(?:(?:console\.)?servernet\.cloud|localhost)(?=\/assets\/)/g, ''));
  }
  const server = http.createServer(async (req,res) => {
    try {
      const url = new URL(req.url, 'http://127.0.0.1');
      res.setHeader('Content-Security-Policy', "default-src 'none'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; frame-src 'self'; connect-src 'none'; form-action 'none'");
      if (url.pathname.startsWith('/assets/')) {
        const file = path.resolve(app, 'public'+url.pathname);
        if (!file.startsWith(path.join(app, 'public/assets')+path.sep)) {res.writeHead(404); res.end(); return;}
        const ext = path.extname(file);
        res.setHeader('Content-Type', ({'.css':'text/css','.woff2':'font/woff2','.woff':'font/woff','.svg':'image/svg+xml'})[ext] || 'application/octet-stream');
        res.end(await fs.readFile(file)); return;
      }
      const locale = url.searchParams.get('locale');
      const state = url.searchParams.get('state');
      assert.ok(pages.has(locale+'-'+state));
      const width = Number(url.searchParams.get('width'));
      res.setHeader('Content-Type', 'text/html;charset=utf-8');
      if (url.searchParams.has('outer')) {
        url.searchParams.delete('outer');
        res.end('<!doctype html><iframe style="border:0;width:'+width+'px;height:1200px" src="'+url.pathname+url.search.replace(/&/g,'&amp;')+'" onload="document.getElementById(\'qa-result\').textContent=this.contentDocument.getElementById(\'qa-result\').textContent"></iframe><pre id="qa-result"></pre>'); return;
      }
      let html = pages.get(locale+'-'+state).replace('<html ', '<html data-theme="'+url.searchParams.get('theme')+'" ');
      html = html.replace('</body>', '<script>window.fixtureState='+JSON.stringify(state)+';window.fixtureWidth='+width+';window.fixturePrefix='+JSON.stringify(locale === 'fa' ? '' : '/'+locale)+';('+scenario.toString()+')();</script></body>');
      res.end(html);
    } catch (error) {res.writeHead(500); res.end(String(error));}
  });
  await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
  let cases = 0, checks = 0;
  try {
    for (const locale of ['fa','en','tr']) for (const theme of ['dark','light']) for (const width of [390,768,1600]) for (const state of ['catalog','contract','blocked','empty']) {
      const profile = await fs.mkdtemp(path.join(os.tmpdir(),'renewal-workspace-ui-'));
      try {
        const url = 'http://127.0.0.1:'+server.address().port+'/?locale='+locale+'&state='+state+'&theme='+theme+'&width='+width+'&outer=1';
        const report = JSON.parse(await chromiumReport(profile,false,url));
        assert.equal(report.passed,true,JSON.stringify({locale,theme,width,state,report}));
        cases++; checks += report.checks;
      } finally {await fs.rm(profile,{recursive:true,force:true,maxRetries:5,retryDelay:100});}
    }
    console.log(JSON.stringify({cases,checks}));
  } finally {await new Promise(resolve => server.close(resolve));}
});
