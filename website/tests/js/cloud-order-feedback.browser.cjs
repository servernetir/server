/* Rendered synthetic Laravel pages and the actual polling function. No real account/API. */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const vm = require('node:vm');
const {spawn} = require('node:child_process');
const {chromiumReport} = require('./panel-navigation.browser.cjs');
const app = path.resolve(__dirname, '../..');

test('polling follows capacity failure, stable failure, review, closure and recovery', async () => {
  const source = await fs.readFile(path.join(app, 'resources/views/account/cloud-server.blade.php'), 'utf8');
  const start = source.indexOf('  function poll(){');
  const end = source.indexOf('\n  setTimeout(poll, tick);', start);
  assert.ok(start > 0 && end > start);
  const actual = source.slice(start, end);
  for (const [current, response, expected] of [
    [null, {delivery_issue:'capacity'}, 1],
    ['capacity', {delivery_issue:'capacity'}, 0],
    ['capacity', {}, 1],
    [null, {delivery_issue:'review'}, 1],
    ['capacity', {delivery_issue:'review'}, 1],
    ['review', {delivery_issue:'review'}, 0],
    [null, {closed:true}, 1],
    [null, {ready:true}, 1],
  ]) {
    let reloads = 0;
    const context = {
      tries:0, statusUrl:'/synthetic/status', tick:5000, deliveryIssue:current,
      capacityWaiting:false, capacityState:null, building:true, pill:{style:{}},
      T:{}, document:{getElementById:()=>null}, paintStage(){}, paintBuild(){},
      setTimeout(){}, fetch:async()=>({json:async()=>response}),
      window:{location:{reload(){reloads++;}}},
    };
    vm.runInNewContext(actual+'\npoll();', context);
    await new Promise(setImmediate);
    assert.equal(reloads, expected, JSON.stringify({current,response}));
  }
});

function scenario() {
  const result = {passed:false, checks:0};
  const check = (ok, label) => {if (!ok) throw new Error(label); result.checks++;};
  try {
    const issue = document.querySelector('[data-cloud-order-issue="capacity"]');
    check(!!issue, 'capacity card from Laravel response');
    check(innerWidth === window.fixtureWidth, 'real requested viewport width');
    check(!document.getElementById('cb-steps'), 'failed order has no build progress');
    check(document.documentElement.scrollWidth <= innerWidth + 1, 'no horizontal page overflow');
    check(issue.scrollWidth <= issue.clientWidth + 1, 'no card overflow');
    const choose = issue.querySelector('[data-cloud-choose-another]');
    check(!!choose && choose.href.includes('billing_mode=hourly'), 'hourly selection preserved');
    check(new URL(choose.href).pathname.startsWith(window.fixturePrefix+'/account/'), 'locale preserved');
    check(choose.getBoundingClientRect().height >= 44, 'accessible touch target');
    choose.focus();
    check(document.activeElement === choose, 'primary action keyboard accessible');
    check(getComputedStyle(choose).outlineStyle !== 'none', 'keyboard focus visible');
    check(getComputedStyle(issue).borderRadius !== '0px', 'component stylesheet applied');
    check(getComputedStyle(issue).direction === (document.documentElement.lang === 'fa' ? 'rtl' : 'ltr'), 'correct reading direction');
    check(!issue.textContent.includes('resource_unavailable'), 'raw provider error absent');
    result.passed = true;
  } catch (error) {result.error=error.message;}
  const out=document.createElement('pre'); out.id='qa-result'; out.textContent=JSON.stringify(result); document.body.append(out);
}

test('rendered capacity card in fa/en/tr, light/dark and mobile/desktop Chromium', {timeout:180000}, async () => {
  const root = process.env.CLOUD_ORDER_PREVIEWS;
  assert.ok(root, 'CLOUD_ORDER_PREVIEWS points to isolated PHPUnit output');
  const pages = new Map();
  for (const locale of ['fa','en','tr']) {
    const html = await fs.readFile(path.join(root, locale+'.html'), 'utf8');
    // Preserve server-rendered layout/styles; remove all application scripts so
    // these visual fixtures can never call real routes or modify an account.
    pages.set(locale, html.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '')
      .replace(/https?:\/\/(?:console\.)?servernet\.cloud(?=\/assets\/)/g, ''));
  }
  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://127.0.0.1');
      res.setHeader('Content-Security-Policy', "default-src 'none'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; frame-src 'self'; connect-src 'none'; form-action 'none'");
      if (url.pathname.startsWith('/assets/')) {
        const file = path.resolve(app, 'public'+url.pathname);
        if (!file.startsWith(path.join(app, 'public/assets')+path.sep)) {res.writeHead(404);res.end();return;}
        const ext=path.extname(file);
        res.setHeader('Content-Type', ({'.css':'text/css','.woff2':'font/woff2','.woff':'font/woff','.svg':'image/svg+xml','.js':'application/javascript'})[ext]||'application/octet-stream');
        res.end(await fs.readFile(file)); return;
      }
      const locale=url.searchParams.get('locale')||'fa';
      assert.ok(pages.has(locale));
      if (url.searchParams.has('outer')) {
        const inner=new URL(url, 'http://127.0.0.1'); inner.searchParams.delete('outer');
        const width=Number(url.searchParams.get('width'));
        res.setHeader('Content-Type','text/html;charset=utf-8');
        res.end('<!doctype html><iframe style="border:0;width:'+width+'px;height:1100px" src="'+inner.pathname+inner.search.replace(/&/g,'&amp;')+'" onload="document.getElementById(\'qa-result\').textContent=this.contentDocument.getElementById(\'qa-result\').textContent"></iframe><pre id="qa-result"></pre>'); return;
      }
      let html=pages.get(locale).replace(/data-theme="(?:light|dark)"/g, 'data-theme="'+(url.searchParams.get('theme')||'dark')+'"');
      if (url.searchParams.has('check')) {
        html=html.replace('</body>', '<script>window.fixtureWidth='+Number(url.searchParams.get('width'))+';window.fixturePrefix='+JSON.stringify(locale==='fa'?'':'/'+locale)+';('+scenario.toString()+')();</script></body>');
      }
      res.setHeader('Content-Type', 'text/html;charset=utf-8'); res.end(html);
    } catch(error) {res.writeHead(500);res.end(String(error));}
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const base='http://127.0.0.1:'+server.address().port;
  let cases=0, checks=0;
  try {
    for (const locale of ['fa','en','tr']) for (const theme of ['dark','light']) for (const width of [390,1440]) {
      const profile=await fs.mkdtemp(path.join(os.tmpdir(),'cloud-order-qa-'));
      // An iframe gives the rendered page a real mobile/desktop viewport.
      const query='?locale='+locale+'&theme='+theme+'&width='+width+'&outer=1&check=1';
      const report=JSON.parse(await chromiumReport(profile,false,base+query));
      assert.equal(report.passed,true,JSON.stringify({locale,theme,width,report}));
      checks+=report.checks; cases++;
      await fs.rm(profile,{recursive:true,force:true,maxRetries:5,retryDelay:100});
    }
    console.log(JSON.stringify({cases,checks}));
    const out=path.join(root,'screenshots'); await fs.mkdir(out,{recursive:true});
    for (const [label,width] of [['desktop',1440],['mobile',390]]) {
      const profile=await fs.mkdtemp(path.join(os.tmpdir(),'cloud-order-shot-'));
      const args=['--headless=new','--no-sandbox','--disable-gpu','--hide-scrollbars','--no-proxy-server','--user-data-dir='+profile,'--window-size='+width+',1100','--screenshot='+path.join(out,label+'.png'),'--virtual-time-budget=2500',base+'?locale=fa&theme=dark'];
      await new Promise((resolve,reject)=>{const child=spawn(process.env.TEST_CHROME||'/usr/bin/google-chrome',args,{stdio:'ignore'}); child.once('error',reject);child.once('exit',code=>code===0?resolve():reject(new Error('Screenshot exit '+code)));});
      await fs.rm(profile,{recursive:true,force:true,maxRetries:5,retryDelay:100});
    }
  } finally {await new Promise(resolve=>server.close(resolve));}
});
