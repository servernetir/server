/* Real server-rendered Blade in isolated Chromium. No user, API, payment or VM. */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const {chromiumReport} = require('./panel-navigation.browser.cjs');
const app = path.resolve(__dirname, '../..');
const fixtures = process.env.N8N_BROWSER_FIXTURES;

function scenario() {
  const report = {config:window.fixtureConfig,checks:[],status:'failed'};
  const check = (value,label) => {if(!value)throw new Error(label);report.checks.push(label);};
  try {
    const root = document.querySelector('[data-n8n-config]');
    const form = root.querySelector('[data-n8n-order]');
    form.addEventListener('submit',e=>{e.preventDefault();throw new Error('No order submission is allowed');});
    const hourly = form.elements.namedItem('billing_mode');
    const monthly = root.querySelector('[name="cycle"][value="monthly"]');
    const price = () => root.querySelector('[data-n8n-summary-price]').textContent;
    const dePrice = price();
    check(monthly.checked && !hourly.checked && !!form.elements.namedItem('plan').value,'real Blade initializes a selected German monthly plan');
    for(let i=0;i<3;i++) {
      hourly.click();check(hourly.checked && price()!==dePrice,'hourly changes price '+i);
      monthly.click();check(!hourly.checked && monthly.checked && price()===dePrice,'already checked monthly radio exits hourly '+i);
    }
    for(const cycle of ['quarterly','semiannual','yearly','monthly']) {
      const radio = root.querySelector('[name="cycle"][value="'+cycle+'"]');
      hourly.click();radio.click();
      check(!hourly.checked && radio.checked && root.querySelector('.n8n-terms').dataset.mode==='cycle','period selection exits hourly: '+cycle);
    }
    const iran = root.querySelector('[data-n8n-country="IR"]');
    if(iran) {
      iran.click();
      check(!form.elements.namedItem('plan').value && root.querySelector('[data-n8n-details]').hidden,'country change clears the German order');
      root.querySelector('[data-n8n-country-packages="IR"] [data-n8n-plan]').click();
      check(!hourly.disabled && !hourly.closest('[hidden]'),'Iran exposes hourly on a selected package');
      const iranPrice=price();hourly.click();check(hourly.checked,'Iran enables hourly');monthly.click();
      check(!hourly.checked && price()===iranPrice,'Iran returns from hourly to monthly');
    }
    root.querySelector('[data-n8n-country="TR"]').click();
    check(!form.elements.namedItem('plan').value,'Turkey clears the prior package');
    root.querySelector('[data-n8n-country-packages="TR"] [data-n8n-plan]').click();
    check(hourly.disabled && !hourly.checked && !!hourly.closest('[hidden]'),'Turkey cannot offer hourly');
    check(!root.querySelector('button[type="submit"]').disabled,'a valid periodic package remains selectable');
    check(document.documentElement.scrollWidth<=document.documentElement.clientWidth,'no horizontal page overflow');
    check(window.fixtureErrors.length===0,'no uncaught script errors');
    report.status='passed';
  } catch(error) {report.error=String(error.stack||error);}
  parent.postMessage({kind:'n8n-result',report},location.origin);
}

function outer(cases) {
  return '<!doctype html><html><body style="margin:0"><iframe id="frame" style="height:1600px;border:0"></iframe><pre id="qa-result" hidden></pre><script>'+ 
    'const cases='+JSON.stringify(cases)+';let i=0;const results=[];const frame=document.getElementById("frame");'+
    'function next(){if(i===cases.length){document.getElementById("qa-result").textContent=JSON.stringify({results,error:null});return;}'+
    'const c=cases[i++];frame.style.width=c.width+"px";frame.src="/fixture?case="+encodeURIComponent(JSON.stringify(c));}'+
    'addEventListener("message",e=>{if(e.origin!==location.origin||e.source!==frame.contentWindow||e.data.kind!=="n8n-result")return;results.push(e.data.report);next();});next();</script></body></html>';
}

for(const reduced of [false,true]) test('n8n periods in actual Blade and Chromium'+(reduced?' with reduced motion':''),{timeout:120000},async()=>{
  assert.ok(fixtures,'Generate synthetic Blade fixtures with N8N_BROWSER_FIXTURES first');
  const markup = {};
  for(const locale of ['fa','en','tr']) markup[locale]=await fs.readFile(path.join(fixtures,locale+'.html'),'utf8');
  const assets = new Map();
  for(const asset of ['css/site.css','css/n8n.css','js/n8n-configurator.js']) assets.set('/assets/'+asset,await fs.readFile(path.join(app,'public/assets',asset)));
  const cases=[];
  for(const locale of ['fa','en','tr'])for(const width of [390,1440])for(const theme of ['dark','light']) cases.push({locale,width,theme,reduced});
  const server=http.createServer((req,res)=>{
    const url=new URL(req.url,'http://localhost');
    res.setHeader('Content-Security-Policy',"default-src 'none';script-src 'self' 'unsafe-inline';style-src 'self' 'unsafe-inline';frame-src 'self';img-src 'none';font-src 'none';form-action 'none';connect-src 'none'");
    if(assets.has(url.pathname)){res.setHeader('Content-Type',url.pathname.endsWith('.css')?'text/css':'application/javascript');res.end(assets.get(url.pathname));return;}
    if(url.pathname==='/scenario.js'){res.setHeader('Content-Type','application/javascript');res.end('('+scenario.toString()+')();');return;}
    res.setHeader('Content-Type','text/html; charset=utf-8');
    if(url.pathname==='/'){res.end(outer(cases));return;}
    if(url.pathname==='/fixture'){
      const c=JSON.parse(url.searchParams.get('case'));
      res.end('<!doctype html><html lang="'+c.locale+'" dir="'+(c.locale==='fa'?'rtl':'ltr')+'" data-theme="'+c.theme+'"><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/site.css"><link rel="stylesheet" href="/assets/css/n8n.css"></head><body><main class="n8n-page">'+markup[c.locale]+'</main><script>window.fixtureConfig='+JSON.stringify(c)+';window.fixtureErrors=[];addEventListener("error",e=>{if(e.message)fixtureErrors.push(e.message)});addEventListener("unhandledrejection",e=>fixtureErrors.push(String(e.reason)));</script><script src="/assets/js/n8n-configurator.js"></script><script src="/scenario.js"></script></body></html>');return;
    }
    res.statusCode=404;res.end('');
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const profile=await fs.mkdtemp(path.join(os.tmpdir(),'servernet-n8n-'));
  try {
    const report=JSON.parse(await chromiumReport(profile,reduced,'http://127.0.0.1:'+server.address().port));
    assert.equal(report.error,null);assert.equal(report.results.length,cases.length);
    for(const item of report.results)assert.equal(item.status,'passed',JSON.stringify(item));
    console.log(JSON.stringify({reduced,cases:report.results.length,checks:report.results.reduce((n,r)=>n+r.checks.length,0)}));
  } finally {
    await new Promise(resolve=>server.close(resolve));
    await fs.rm(profile,{recursive:true,force:true,maxRetries:5,retryDelay:100});
  }
});
