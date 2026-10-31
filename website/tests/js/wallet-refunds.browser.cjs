/* Real synthetic Blade output, local assets and Chromium. No customer actions. */
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs/promises');
const path=require('node:path');
const os=require('node:os');
const http=require('node:http');
const {chromiumReport}=require('./panel-navigation.browser.cjs');
const app=path.resolve(__dirname,'../..');
const fixtureDir=process.env.WALLET_REFUND_UI_FIXTURES;
const cases=[];
for(const locale of ['fa','en','tr']) for(const theme of ['dark','light']) {
  for(const state of ['form','history']) for(const width of [320,390,768,1440]) cases.push({locale,theme,state,width});
  for(const state of ['disabled','pending','no-bank','empty','validation']) cases.push({locale,theme,state,width:390});
}

function fixture(source,c) {
  let html=source.replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi,'')
    .replace(/<link\b[^>]*\brel=["']stylesheet["'][^>]*>/gi,'');
  html=html.replace(/<html\b[^>]*>/i,tag=>tag.replace(/\sdata-theme=["'][^"']*["']/i,'').replace('>',' data-theme="'+c.theme+'">'));
  html=html.replace(/<\/head>/i,
    ['site','panel','site-select','wallet-refunds'].map(name=>'<link rel="stylesheet" href="/assets/css/'+name+'.css">').join('')+
    '<style>html{scroll-behavior:auto!important}</style></head>');
  return html.replace(/<\/body>/i,'<script>window.__uiErrors=[];addEventListener("error",e=>{if(e.message)__uiErrors.push(e.message)});'+
    'addEventListener("unhandledrejection",e=>__uiErrors.push(String(e.reason)));window.__case='+JSON.stringify(c)+';</script>'+
    '<script src="/assets/js/site-select.js"></script><script src="/assets/js/panel-navigation.js"></script>'+
    '<script src="/scenario.js"></script></body>');
}

async function scenario() {
  const c=window.__case,report={...c,status:'failed',checks:[]};
  const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
  const frame=()=>new Promise(resolve=>requestAnimationFrame(()=>resolve()));
  const check=(value,label)=>{if(!value)throw Error(label);report.checks.push(label);};
  const until=async(predicate,label)=>{for(let i=0;i<80;i++){if(predicate())return;await wait(20);await frame();}throw Error('Timed out: '+label);};
  try {
    await frame();
    const root=document.querySelector('.wr-workspace');
    check(!!root,'refund workspace rendered');
    check(innerWidth===c.width,'actual responsive viewport');
    check(root.getBoundingClientRect().width>100,'workspace visible');
    const overflow=()=>Math.max(document.documentElement.scrollWidth,document.body.scrollWidth)<=innerWidth+1;
    check(overflow(),'no document overflow');
    check(root.querySelectorAll('h1').length===1,'one workspace heading');
    check(!/\brefund\.[a-z_]+/.test(root.textContent),'no untranslated keys');
    check(!/€|\bEUR\b/.test(root.textContent),'refund amounts remain IRT in every locale');
    for(const field of root.querySelectorAll('input:not([type="hidden"]),select,textarea')) {
      check(!!field.labels?.length && Array.from(field.labels).some(label=>label.textContent.trim()),'field has a programmatic label: '+field.name);
    }
    document.querySelectorAll('form').forEach(form=>form.addEventListener('submit',event=>event.preventDefault()));
    if(['disabled','pending','no-bank'].includes(c.state)) {
      const marker='[data-refund-state="'+c.state+'"]';
      check(root.matches(marker) || !!root.querySelector(marker),'correct account gate');
      check(!root.querySelector('.wr-request-form'),'gated state has no request form');
    }
    if(c.state==='empty') check(root.querySelectorAll('.wr-history-card').length===0,'empty history has no fabricated requests');
    if(c.state==='history') for(const status of ['requested','approved','paid','rejected','cancelled']) {
      check(!!root.querySelector('.wr-history-card[data-status="'+status+'"]'),'history state visible: '+status);
    }
    if(['form','validation'].includes(c.state)) {
      const form=root.querySelector('.wr-request-form');
      check(!!form && form.method==='post','request form keeps POST');
      const amount=form.querySelector('[name="amount_irt"]'),bank=form.querySelector('[name="bank_account_id"]');
      const note=form.querySelector('[name="note"]'),ack=form.querySelector('[name="acknowledge"]');
      check(amount.required && amount.maxLength===24 && amount.dir==='ltr','amount contract preserved');
      check(bank.required && note.required && note.minLength===5 && note.maxLength===2000,'bank and reason validation preserved');
      check(ack.required && ack.type==='checkbox' && ack.value==='1','explicit acknowledgement preserved');
      check(!!form.querySelector('[name="_token"]') && !!form.querySelector('[name="idempotency_key"]')?.value,'request security fields retained');
      await until(()=>!!bank.closest('.sn-select')?.querySelector('.sn-select-trigger'),'shared bank picker enhanced');
      const trigger=bank.closest('.sn-select').querySelector('.sn-select-trigger');
      check(trigger.type==='button' && !!trigger.getAttribute('aria-label'),'bank picker is a labelled presentation control');
      trigger.scrollIntoView({behavior:'instant',block:'center'});await frame();trigger.click();
      await until(()=>!!document.querySelector('.sn-select-menu'),'bank menu opens');await wait(250);
      const menu=document.querySelector('.sn-select-menu'),box=menu.getBoundingClientRect();
      check(box.width>0 && box.left>=-1 && box.right<=innerWidth+1 && box.top>=-1 && box.bottom<=innerHeight+1,'bank popup stays inside viewport');
      check(getComputedStyle(menu).animationName.includes('sn-select-open'),'bank picker uses shared opening animation');
      const option=Array.from(bank.options).find(option=>option.value && !option.disabled);
      check(!!option,'verified bank option exists');
      menu.querySelector('[data-index="'+option.index+'"]').click();
      check(bank.value===option.value && new FormData(form).get('bank_account_id')===option.value,'native bank selection and form payload agree');
      check(document.activeElement===trigger,'bank selection restores picker focus');
      check(!document.querySelector('.sn-select-menu'),'choosing a bank closes popup');
      trigger.click();await until(()=>!!document.querySelector('.sn-select-menu'),'bank menu reopens');
      trigger.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));
      check(!document.querySelector('.sn-select-menu') && trigger.getAttribute('aria-expanded')==='false','Escape closes bank popup');
      check(overflow(),'bank picker never causes document overflow');
      if(c.state==='validation') check(!!root.querySelector('[role="alert"]'),'validation feedback remains visible');
    }
    check(window.__uiErrors.length===0,'no uncaught browser errors');
    report.status='passed';
  } catch(error) {
    report.error=String(error.stack||error);
    report.debug={width:innerWidth,scrollWidth:document.documentElement.scrollWidth,active:document.activeElement?.outerHTML.slice(0,400),errors:window.__uiErrors};
  }
  parent.postMessage({kind:'refund-ui-result',report},location.origin);
}

function outer() {
  return '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0">'+
    '<iframe id="fixture" style="display:block;border:0;height:1000px"></iframe><pre id="qa-result" hidden></pre><script>'+
    'const cases='+JSON.stringify(cases)+';let index=0,done=false;const results=[];const frame=document.getElementById("fixture");'+
    'function finish(error){if(done)return;done=true;clearTimeout(timer);document.getElementById("qa-result").textContent=JSON.stringify({results,error});}'+
    'function next(){if(index===cases.length){finish(null);return;}const i=index++,c=cases[i];frame.style.width=c.width+"px";frame.src="/fixture?case="+i;}'+
    'addEventListener("message",event=>{if(done||event.origin!==location.origin||event.source!==frame.contentWindow||event.data.kind!=="refund-ui-result")return;results.push(event.data.report);next();});'+
    'const timer=setTimeout(()=>finish("Browser scenario timeout"),60000);next();</script></body></html>';
}

test('wallet refund workspace in Chromium: 78 rendered cases',{timeout:120000},async()=>{
  assert.ok(fixtureDir,'WALLET_REFUND_UI_FIXTURES must name the synthetic PHP fixture directory');
  const fixtures=new Map(),assets=new Map();
  for(const c of cases){const name=c.locale+'-'+c.state+'.html';if(!fixtures.has(name))fixtures.set(name,await fs.readFile(path.join(fixtureDir,name),'utf8'));}
  for(const name of ['css/site.css','css/panel.css','css/site-select.css','css/wallet-refunds.css','js/site-select.js','js/panel-navigation.js'])assets.set('/assets/'+name,await fs.readFile(path.join(app,'public/assets',name)));
  const server=http.createServer((req,res)=>{
    try {
      const url=new URL(req.url,'http://localhost');
      res.setHeader('Content-Security-Policy',"default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; frame-src 'self'; font-src 'self'; img-src data:; connect-src 'none'; form-action 'none'");
      if(assets.has(url.pathname)){res.setHeader('Content-Type',url.pathname.endsWith('.css')?'text/css':'application/javascript');res.end(assets.get(url.pathname));return;}
      if(url.pathname==='/scenario.js'){res.setHeader('Content-Type','application/javascript');res.end('('+scenario.toString()+')();');return;}
      res.setHeader('Content-Type','text/html; charset=utf-8');
      if(url.pathname==='/'){res.end(outer());return;}
      if(url.pathname==='/fixture'){
        const index=Number(url.searchParams.get('case'));assert.ok(Number.isInteger(index)&&index>=0&&index<cases.length);
        const c=cases[index];res.end(fixture(fixtures.get(c.locale+'-'+c.state+'.html'),c));return;
      }
      res.statusCode=404;res.end('');
    }catch(error){res.statusCode=500;res.end(String(error.message));}
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const profile=await fs.mkdtemp(path.join(os.tmpdir(),'servernet-refund-ui-'));
  try {
    const report=JSON.parse(await chromiumReport(profile,false,'http://127.0.0.1:'+server.address().port));
    assert.equal(report.error,null,JSON.stringify(report));assert.equal(report.results.length,cases.length);
    for(const result of report.results)assert.equal(result.status,'passed',JSON.stringify(result));
    console.log(JSON.stringify({cases:report.results.map(result=>({...result,checks:result.checks.length}))}));
  }finally{
    await new Promise(resolve=>server.close(resolve));
    assert.equal(path.dirname(profile),os.tmpdir());assert.ok(path.basename(profile).startsWith('servernet-refund-ui-'));
    await fs.rm(profile,{recursive:true,force:true,maxRetries:10,retryDelay:100});
  }
});
