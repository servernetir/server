/* Browser QA of real PHP-rendered, synthetic fixtures. Never signs into a real service. */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const http = require('node:http');
const {chromium} = require(process.env.TEST_PLAYWRIGHT || 'playwright');

test('Madar: actual rendered forms, RTL/LTR, responsive cards and conversation', {timeout: 120_000}, async () => {
  const fixtures = process.env.MADAR_PREVIEW_DIR;
  assert.ok(fixtures, 'PHP-rendered fixture directory must be supplied');
  const publicRoot = path.resolve(__dirname, '../../public');
  const mime = {'.html':'text/html; charset=utf-8','.css':'text/css','.js':'text/javascript',
    '.svg':'image/svg+xml','.woff2':'font/woff2','.woff':'font/woff','.png':'image/png'};
  const server = http.createServer(async (req,res) => {
    const url = new URL(req.url, 'http://127.0.0.1:8767');
    const assets = url.pathname.startsWith('/assets/');
    const root = assets ? publicRoot : path.resolve(fixtures);
    const file = path.resolve(root, '.' + url.pathname);
    if (!file.startsWith(root + path.sep) || req.method !== 'GET') { res.writeHead(404).end(); return; }
    try {
      const data = await fs.readFile(file);
      res.writeHead(200, {'Content-Type':mime[path.extname(file)] || 'application/octet-stream'}).end(data);
    } catch { res.writeHead(404).end(); }
  });
  await new Promise(resolve => server.listen(8767,'127.0.0.1',resolve));
  let browser;
  let count = 0;
  try {
    browser = await chromium.launch({executablePath:process.env.TEST_CHROME || '/usr/bin/google-chrome',
      headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
    for (const locale of ['fa','en','tr']) for (const width of [360,768,1440]) for (const theme of ['dark','light']) {
      const page = await browser.newPage({viewport:{width,height:1000}});
      const errors = [];
      page.on('pageerror', e => errors.push(e.message));
      await page.route('**/*', route => new URL(route.request().url()).origin === 'http://127.0.0.1:8767'
        ? route.continue() : route.abort());
      await page.goto('http://127.0.0.1:8767/'+locale+'.html');
      await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
      await page.evaluate(() => document.fonts.ready);
      assert.equal(await page.locator('html').getAttribute('dir'), locale === 'fa' ? 'rtl' : 'ltr');
      assert.equal(await page.locator('.mdr-campaign').count(), 5);
      assert.equal(await page.locator('.mdr-steps>div').count(), 4);
      const prefix = locale === 'fa' ? '' : '/'+locale;
      assert.ok((await page.locator('#apply form').getAttribute('action')).endsWith(prefix+'/account/madar'));
      await page.locator('.mdr-campaign summary').first().click();
      assert.equal(await page.locator('.mdr-campaign details').first().getAttribute('open'), '');
      assert.equal(await page.locator('#apply form').evaluate(f => f.checkValidity()), false);
      await page.locator('#apply input[name=title]').fill('Reproducible GPU workflow');
      await page.locator('#apply textarea[name=body]').fill('A real programming project with an explained, reproducible GPU workflow.');
      await page.locator('#apply input[name=terms]').check();
      assert.equal(await page.locator('#apply form').evaluate(f => f.checkValidity()), true);
      const box = await page.locator('.mdr').boundingBox();
      assert.ok(box.x >= -1 && box.x + box.width <= width + 1, locale+'/'+width+'/'+theme+': workspace fits');
      const body = await page.locator('.mdr').innerText();
      assert.doesNotMatch(body, /madar\.(campaigns|statuses|errors|terms)/);
      assert.deepEqual(errors, []);
      if (locale === 'fa' && theme === 'dark' && width !== 768 && process.env.MADAR_SCREENSHOTS) {
        await fs.mkdir(process.env.MADAR_SCREENSHOTS, {recursive:true});
        await page.screenshot({path:path.join(process.env.MADAR_SCREENSHOTS, 'madar-fa-'+width+'.png'),fullPage:true});
      }
      await page.goto('http://127.0.0.1:8767/'+locale+'-thread.html');
      assert.equal(await page.locator('.mdr-message-form').count(), 1);
      assert.ok((await page.locator('.mdr-message-form').getAttribute('action')).includes(prefix+'/account/madar/'));
      assert.ok(await page.locator('.mdr-message-body').count() > 0);
      assert.deepEqual(errors, []);
      await page.close();
      count++;
    }
    console.log(JSON.stringify({cases:count, locales:3, themes:2, widths:[360,768,1440], production_access:false}));
  } finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
});
