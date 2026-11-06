/* Browser QA of real PHP-rendered, synthetic fixtures. Never signs into a real service. */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const http = require('node:http');
const {chromium} = require(process.env.TEST_PLAYWRIGHT || 'playwright');

test('Madar: actual rendered forms, RTL/LTR, responsive cards and conversation', {timeout: 240_000}, async () => {
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
    const widths = [320,390,650,768,1000,1001,1280,1440];
    const checkLayout = async (page, label) => {
      const overflow = await page.locator('.mdr').evaluate(root => {
        const bounds = root.getBoundingClientRect();
        return [...root.querySelectorAll('*')].filter(el => {
          const rect = el.getBoundingClientRect();
          return rect.width && getComputedStyle(el).display !== 'none' && (rect.left < bounds.left - 2 || rect.right > bounds.right + 2);
        }).map(el => el.className || el.tagName).slice(0,8);
      });
      assert.deepEqual(overflow, [], label + ': no overflowing workspace content');
      const box = await page.locator('.mdr').boundingBox();
      const width = page.viewportSize().width;
      assert.ok(box.x >= -1 && box.x + box.width <= width + 1, label + ': workspace fits');
    };
    const checkContrast = async (page, selectors, label) => {
      const samples = await page.evaluate(selectors => {
        const rgba = color => (color.match(/[\d.]+/g) || []).map(Number);
        const blend = (fg,bg) => fg.slice(0,3).map((v,i) => v*(fg[3] ?? 1) + bg[i]*(1-(fg[3] ?? 1)));
        const background = el => {
          if (!el) return [255,255,255];
          const c = rgba(getComputedStyle(el).backgroundColor);
          return blend(c, background(el.parentElement));
        };
        const lum = rgb => rgb.map(v => v/255).map(v => v <= .04045 ? v/12.92 : ((v+.055)/1.055)**2.4)
          .reduce((sum,v,i) => sum + v*[.2126,.7152,.0722][i],0);
        return selectors.flatMap(selector => [...document.querySelectorAll(selector)].filter(el => el.getBoundingClientRect().width).map(el => {
          const bg = background(el), fg = blend(rgba(getComputedStyle(el).color), bg);
          const a=lum(fg), b=lum(bg);
          return {selector, ratio:(Math.max(a,b)+.05)/(Math.min(a,b)+.05)};
        }));
      }, selectors);
      for (const sample of samples) assert.ok(sample.ratio >= 4.5, label + ': ' + sample.selector + ' contrast ' + sample.ratio.toFixed(2));
    };
    for (const locale of ['fa','en','tr']) for (const width of widths) {
      const page = await browser.newPage({viewport:{width,height:1000}});
      const errors = [];
      page.on('pageerror', e => errors.push(e.message));
      await page.route('**/*', route => new URL(route.request().url()).origin === 'http://127.0.0.1:8767'
        ? route.continue() : route.abort());
      for (const theme of ['dark','light']) {
        const label = locale+'/'+width+'/'+theme;
        await page.goto('http://127.0.0.1:8767/'+locale+'.html');
        if ((await page.locator('html').getAttribute('data-theme') === 'light') !== (theme === 'light')) {
          await page.locator('#theme-toggle').click();
        }
        assert.equal(await page.evaluate(() => document.documentElement.dataset.theme === 'light' ? 'light' : 'dark'), theme);
        assert.equal(await page.evaluate(() => localStorage.getItem('snet-theme') || 'dark'), theme);
        await page.evaluate(() => document.fonts.ready);
        assert.equal(await page.locator('html').getAttribute('dir'), locale === 'fa' ? 'rtl' : 'ltr');
        assert.equal(await page.locator('.mdr-campaign').count(), 8);
        assert.equal(await page.locator('.mdr-steps>div').count(), 3);
        assert.equal(await page.locator('.mdr-price b').count(), 8);
        const prefix = locale === 'fa' ? '' : '/'+locale;
        assert.ok((await page.locator('#claim form').getAttribute('action')).endsWith(prefix+'/account/madar/claims'));
        await page.locator('.mdr-campaign summary').first().click();
        assert.equal(await page.locator('.mdr-campaign details').first().getAttribute('open'), '');
        await checkLayout(page,label);
        await checkContrast(page,['.mdr-campaign h3','.mdr-campaign p','.mdr-price b','.mdr-form-card label'],label);
        const hero = await page.locator('.mdr-hero').evaluate(el => {
          const style=getComputedStyle(el);
          return {bg:style.getPropertyValue('--mdr-hero-a').trim(),color:style.color};
        });
        assert.equal(hero.bg, theme === 'light' ? '#edf6ff' : '#101b32');
        assert.equal(hero.color, theme === 'light' ? 'rgb(23, 38, 71)' : 'rgb(241, 245, 255)');
        const form = page.locator('#claim form');
        assert.equal(await form.evaluate(f => f.checkValidity()), false);
        await form.locator('[name=title]').fill('Reproducible GPU workflow');
        await form.locator('[name=body]').fill('Published real output, reproducible GPU workflow and the relevant service link.');
        await form.locator('[name=evidence_url]').fill('https://github.com/creator/real-project');
        await form.locator('[name=distribution_url]').fill('https://www.linkedin.com/posts/real-project');
        await form.locator('[name=rights_confirmed]').check();
        await form.locator('[name=disclosure_confirmed]').check();
        await form.locator('[name=terms]').check();
        assert.equal(await form.evaluate(f => f.checkValidity()), true);
        const publicOption = await form.locator('option[data-distribution="1"]').first().getAttribute('value');
        await form.locator('[name=mission_id]').selectOption(publicOption);
        assert.equal(await form.locator('[name=distribution_url]').evaluate(el => el.required), true);
        await form.locator('[name=distribution_url]').fill('');
        assert.equal(await form.evaluate(f => f.checkValidity()), false);
        const privateOption = await form.locator('option[data-public="0"]').getAttribute('value');
        await form.locator('[name=mission_id]').selectOption(privateOption);
        assert.equal(await form.locator('[name=disclosure_confirmed]').isVisible(), false);
        assert.equal(await form.locator('[name=evidence_url]').evaluate(el => el.required), false);
        assert.equal(await form.locator('[name=distribution_url]').evaluate(el => el.required), false);
        const body = await page.locator('.mdr').innerText();
        assert.doesNotMatch(body, /madar\.(campaigns|statuses|errors|terms)/);
        assert.deepEqual(errors, []);
        if (locale === 'fa' && [390,1440].includes(width) && process.env.MADAR_SCREENSHOTS) {
          await fs.mkdir(process.env.MADAR_SCREENSHOTS, {recursive:true});
          await page.screenshot({path:path.join(process.env.MADAR_SCREENSHOTS, 'madar-fa-'+width+'-'+theme+'.png'),fullPage:true});
        }
        await page.goto('http://127.0.0.1:8767/'+locale+'-thread.html');
        assert.equal(await page.evaluate(() => document.documentElement.dataset.theme === 'light' ? 'light' : 'dark'), theme);
        assert.equal(await page.locator('.mdr-message-form').count(), 1);
        assert.ok((await page.locator('.mdr-message-form').getAttribute('action')).includes(prefix+'/account/madar/'));
        assert.ok(await page.locator('.mdr-message-body').count() > 0);
        await checkLayout(page,label+'/thread');
        await checkContrast(page,['.mdr-message-body','.mdr-reward h2','.mdr-action-card p'],label+'/thread');
        assert.deepEqual(errors, []);
        count++;
      }
      await page.close();
    }
    console.log(JSON.stringify({cases:count, locales:3, themes:2, widths, production_access:false}));
  } finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
});
