const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const playwright = require('playwright');

// Full HTML exported by StudioHomeHeroTest from an isolated Laravel request.
// Route every request locally; this suite never visits the real website or
// contacts external services, and exercises the exact production CSS order.
const fixtures = process.env.STUDIO_HOME_BROWSER_FIXTURES;
const publicRoot = path.resolve(__dirname, '../../public');
const origin = 'http://localhost';
const engines = process.env.HOME_PARTS_ENGINE ? [process.env.HOME_PARTS_ENGINE] : ['chromium', 'firefox', 'webkit'];
const sizes = [
  [1440, 770, '13-inch laptop'], [1280, 680, 'compact laptop'],
  [1512, 781, '15-inch laptop'], [1440, 721, 'height breakpoint'],
  [1366, 650, 'short laptop'], [1920, 720, 'wide and short'],
  [1920, 1080, 'large desktop'], [1152, 616, '125% zoom equivalent'],
  [1000, 580, 'desktop minimum'], [1024, 579, 'short normal flow'],
  [999, 768, 'tablet breakpoint'], [960, 514, '150% zoom equivalent'],
  [390, 844, 'mobile'], [320, 568, 'narrow mobile']
];

async function settle(page) {
  await page.evaluate(async () => {
    await document.fonts.ready;
    window.scrollTo({top:0,left:0,behavior:'instant'});
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  });
}

async function snapshot(page) {
  return page.evaluate(() => {
    const rect = el => {
      const r = el.getBoundingClientRect();
      return {x:r.x, y:r.y, right:r.right, bottom:r.bottom, w:r.width, h:r.height};
    };
    const box = selector => rect(document.querySelector(selector));
    return {
      viewport: {w:document.documentElement.clientWidth, h:innerHeight},
      horizontalOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
      internalScroll: [document.querySelector('.sn-home-intro').scrollTop, document.querySelector('.sn-hero-carousel').scrollTop],
      header:box('#header-wrap'), slide:box('#hero-parts'), scene:box('.sn-parts-scene'),
      head:box('.sn-parts-scene-head'), foot:box('.sn-parts-scene-foot'),
      copy:box('.sn-parts-copy'), visual:box('.sn-parts-visual'), art:box('.sn-parts-art'),
      controls:box('.sn-hero-controls'), proof:box('.sn-proof-rail'),
      details:['.sn-parts-collection','.sn-parts-proliant','.sn-parts-art-caption','.sn-parts-scene-note > span:last-child','.sn-parts-chassis'].map(selector => ({selector,...box(selector)})),
      labels:[...document.querySelectorAll('.sn-parts-hotspot-label')].map(rect),
      links:[...document.querySelectorAll('[data-parts-category]')].map(rect),
      active:document.querySelector('[data-hero-slide].is-active')?.dataset.heroSlide,
      labelCount:document.querySelectorAll('.sn-parts-hotspot-label').length,
      artState:document.querySelector('#hero-parts').dataset.partsArt,
    };
  });
}

function assertGeometry(g, id, fitFirstFold) {
  const fail = (condition, reason) => assert.ok(condition, `${id}: ${reason}; ${JSON.stringify(g)}`);
  fail(g.active === 'parts' && g.artState === 'ready' && g.labelCount === 4, 'all real parts displayed');
  fail(!g.horizontalOverflow, 'no horizontal overflow');
  fail(g.internalScroll.every(v=>v===0), 'glass stage never becomes an invisible scroll container');
  fail(g.head.y >= Math.max(g.header.bottom, g.slide.y) + 2, 'collection title is below the fixed header');
  fail(g.foot.bottom <= g.controls.y - 2, 'caption and chassis link are above the glass controls');
  fail(g.copy.y >= g.slide.y - 1 && g.copy.bottom <= g.controls.y - 2, 'copy stays in its own stage');
  fail(g.art.y >= g.visual.y - 1 && g.art.bottom <= g.visual.bottom + 1, 'art fits its allocated row');
  fail(g.art.w > 180 && Math.abs(g.art.w / g.art.h - 1.5) < .02, 'the server has a usable, undistorted 3:2 aspect');
  for (const d of g.details) {
    fail(d.w > 0 && d.h > 0 && d.x >= -1 && d.right <= g.viewport.w + 1, `${d.selector} is within the horizontal viewport`);
    fail(d.y >= g.header.bottom + 2 && d.bottom <= g.controls.y - 2, `${d.selector} is not obscured by either glass bar`);
  }
  for (let i=0; i<g.labels.length; i++) {
    const a = g.labels[i], link = g.links[i];
    fail(a.y >= g.head.bottom - 1 && a.bottom < g.foot.y + 1, 'piece label does not overlap title or caption');
    fail(a.x >= -1 && a.right <= g.viewport.w + 1 && link.w >= 43.9 && link.h >= 43.9, 'label and 44px-minimum target fit');
    for (const b of g.labels.slice(i+1)) fail(a.right <= b.x + 1 || b.right <= a.x + 1 || a.bottom <= b.y + 1 || b.bottom <= a.y + 1, 'piece labels do not collide');
  }
  if (fitFirstFold) fail(g.proof.bottom <= g.viewport.h + 2, 'a normal desktop fits the whole first fold');
}

async function assertHitTarget(page, selector) {
  const result = await page.locator(selector).evaluate(el => {
    const h = document.getElementById('header-wrap').getBoundingClientRect().bottom;
    let r = el.getBoundingClientRect();
    if (r.top < h + 2 || r.bottom > innerHeight - 2) el.scrollIntoView({block:'center',behavior:'instant'});
    r = el.getBoundingClientRect();
    const hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
    return {ok:hit === el || el.contains(hit), hit:hit?.className?.baseVal || String(hit?.className)};
  });
  assert.ok(result.ok, `${selector} is actually clickable: ${JSON.stringify(result)}`);
}

for (const engine of engines) test(`${engine}: hardware scene fits the real header and controls in all locales`, {timeout:180000}, async () => {
  assert.ok(fixtures && fs.existsSync(path.join(fixtures, 'fa.html')), 'export the actual Laravel browser fixtures first');
  assert.ok(playwright[engine], `unknown browser engine: ${engine}`);
  const browser = await playwright[engine].launch();
  try {
    const context = await browser.newContext({viewport:{width:1440,height:770},deviceScaleFactor:2,reducedMotion:'reduce'});
    await context.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.origin !== origin) return route.abort();
      if (['/','/en','/tr'].includes(url.pathname)) {
        const locale = url.pathname === '/' ? 'fa' : url.pathname.slice(1);
        return route.fulfill({path:path.join(fixtures,locale+'.html'),contentType:'text/html; charset=utf-8'});
      }
      const asset = path.resolve(publicRoot,'.'+decodeURIComponent(url.pathname));
      if (asset.startsWith(publicRoot+path.sep) && fs.existsSync(asset) && fs.statSync(asset).isFile()) return route.fulfill({path:asset});
      return route.abort();
    });
    const page = await context.newPage();
    const pageErrors = [];
    page.on('pageerror', err => pageErrors.push(err.message));
    let count = 0;
    for (const locale of ['fa','en','tr']) {
      await page.setViewportSize({width:1440,height:770});
      await page.goto(origin+(locale==='fa'?'/':'/'+locale),{waitUntil:'domcontentloaded'});
      await page.locator('[data-hero-carousel][data-carousel-ready]').waitFor({state:'visible'});
      await page.locator('[data-hero-target="parts"]').click();
      await page.locator('#hero-parts[data-parts-art="ready"]').waitFor({state:'visible'});
      for (const [width,height,name] of sizes) {
        await page.setViewportSize({width,height});
        // A focus/scroll-into-view request must not internally scroll the
        // decorative clipped halo, even when document scroll is reset.
        await page.locator('[data-hero-target="parts"]').evaluate(el=>el.scrollIntoView({block:'center',behavior:'instant'}));
        await settle(page);
        const g = await snapshot(page);
        assertGeometry(g,`${engine}/${locale}/${width}x${height} ${name}`,width>=1000&&height>=650);
        await assertHitTarget(page,'.sn-parts-chassis');
        count++;
      }
      // Actual resize observer must respect a taller header and intrinsic text.
      await page.setViewportSize({width:1440,height:770});
      await page.evaluate(() => { document.documentElement.dataset.theme='light'; document.documentElement.style.fontSize='125%'; });
      const taller = await page.addStyleTag({content:'#header-wrap .topbar .container{height:68px!important}'});
      await settle(page);
      assertGeometry(await snapshot(page),`${engine}/${locale}/light enlarged font and header`,false);
      await assertHitTarget(page,'.sn-parts-chassis');
      count++;
      await taller.evaluate(el => el.remove());
      await page.evaluate(() => { document.documentElement.style.fontSize=''; });
      // Exercise the exact measured fallback, even when cqh is supported.
      const fallback = await page.addStyleTag({content:'.sn-home-intro .sn-parts-visual{container-type:normal!important}.sn-home-intro .sn-parts-art{width:min(100%,var(--sn-parts-art-limit,100%))!important}'});
      await page.setViewportSize({width:1280,height:680});
      await settle(page);
      assertGeometry(await snapshot(page),`${engine}/${locale}/measured fallback`,true);
      count++;
      await fallback.evaluate(el => el.remove());
    }
    assert.deepEqual(pageErrors,[],'no uncaught JavaScript errors in the actual homepage');
    console.log(`${engine}: ${count} responsive cases; all three languages, actual bars, zoom equivalents, light theme and measured fallback`);
    await context.close();
  } finally { await browser.close(); }
});
