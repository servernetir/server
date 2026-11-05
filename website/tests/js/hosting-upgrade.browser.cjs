/* Synthetic Laravel HTML only. CSP prevents account/payment requests. */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const {chromiumReport} = require('./panel-navigation.browser.cjs');
const app = path.resolve(__dirname, '../..');

function scenario() {
  const result = {passed:false, checks:0};
  const check = (ok, label) => {if (!ok) throw Error(label); result.checks++;};
  try {
    const root = document.querySelector('.hu-wrap');
    check(!!root, 'rendered upgrade panel exists');
    check(innerWidth === window.fixtureWidth, 'actual requested viewport');
    check(document.documentElement.scrollWidth <= innerWidth + 1, 'no horizontal overflow');
    check(root.scrollWidth <= root.clientWidth + 1, 'breakdown fits its container');
    check(!root.textContent.includes('ui.hu_'), 'translations resolved');
    check(getComputedStyle(root).direction === (document.documentElement.lang === 'fa' ? 'rtl' : 'ltr'), 'locale direction');
    const form = root.querySelector('form');
    check(form.method === 'post', 'invoice creation uses POST');
    check(new URL(form.action).pathname.startsWith(window.fixturePrefix+'/account/'), 'invoice action preserves locale');
    check(form.querySelector('[name="_token"]').value.length > 20, 'CSRF token is present');
    check(form.querySelector('[name="quote_hash"]').value.length === 64, 'signed quote present');
    check(form.querySelector('[name="product_id"]').value !== '', 'target product bound');
    const button = form.querySelector('button');
    check(button.getBoundingClientRect().height >= 40, 'usable touch target');
    form.addEventListener('submit', event => {event.preventDefault(); form.dataset.checked = 'yes';});
    button.focus(); check(document.activeElement === button, 'keyboard accessible');
    button.click(); check(form.dataset.checked === 'yes', 'primary action submits review form');
    check(root.querySelectorAll('.hu-row').length === 10, 'all billing components rendered');
    result.passed = true;
  } catch (error) {result.error = error.message;}
  const out = document.createElement('pre'); out.id = 'qa-result'; out.textContent = JSON.stringify(result); document.body.append(out);
}

test('hosting upgrade review in three locales, two themes and mobile/desktop', {timeout:180000}, async () => {
  const directory = process.env.HOSTING_UPGRADE_PREVIEW_DIR;
  assert.ok(directory);
  const pages = new Map();
  for (const locale of ['fa','en','tr']) {
    const html = await fs.readFile(path.join(directory, locale+'.html'), 'utf8');
    pages.set(locale, html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
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
      assert.ok(pages.has(locale));
      const width = Number(url.searchParams.get('width'));
      res.setHeader('Content-Type', 'text/html;charset=utf-8');
      if (url.searchParams.has('outer')) {
        url.searchParams.delete('outer');
        res.end('<!doctype html><iframe style="border:0;width:'+width+'px;height:1200px" src="'+url.pathname+url.search.replace(/&/g,'&amp;')+'" onload="document.getElementById(\'qa-result\').textContent=this.contentDocument.getElementById(\'qa-result\').textContent"></iframe><pre id="qa-result"></pre>'); return;
      }
      let html = pages.get(locale).replace('<html ', '<html data-theme="'+url.searchParams.get('theme')+'" ');
      html = html.replace('</body>', '<script>window.fixtureWidth='+width+';window.fixturePrefix='+JSON.stringify(locale === 'fa' ? '' : '/'+locale)+';('+scenario.toString()+')();</script></body>');
      res.end(html);
    } catch (error) {res.writeHead(500); res.end(String(error));}
  });
  await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
  let cases = 0, checks = 0;
  try {
    for (const locale of ['fa','en','tr']) for (const theme of ['dark','light']) for (const width of [390,1440]) {
      const profile = await fs.mkdtemp(path.join(os.tmpdir(),'hosting-upgrade-ui-'));
      try {
        const url = 'http://127.0.0.1:'+server.address().port+'/?locale='+locale+'&theme='+theme+'&width='+width+'&outer=1';
        const report = JSON.parse(await chromiumReport(profile,false,url));
        assert.equal(report.passed,true,JSON.stringify({locale,theme,width,report}));
        cases++; checks += report.checks;
      } finally {await fs.rm(profile,{recursive:true,force:true,maxRetries:5,retryDelay:100});}
    }
    console.log(JSON.stringify({cases,checks}));
  } finally {await new Promise(resolve => server.close(resolve));}
});
