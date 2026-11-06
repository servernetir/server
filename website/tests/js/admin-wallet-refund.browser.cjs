/* Actual synthetic admin Blade output and local assets only. No customer or banking actions. */
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs/promises');
const path=require('node:path');
const os=require('node:os');
const http=require('node:http');
const {chromiumReport}=require('./panel-navigation.browser.cjs');
const app=path.resolve(__dirname,'../..');
const fixtureDir=process.env.ADMIN_WALLET_REFUND_UI_FIXTURES;
const cases=[];
for(const locale of ['fa','en','tr']) for(const theme of ['dark','light']) {
  for(const state of ['requested','approved','paid','rejected','cancelled','legacy','validation']) cases.push({locale,theme,state,width:390});
}
for(const width of [320,1440]) for(const theme of ['dark','light']) {
  for(const state of ['requested','approved','paid']) cases.push({locale:'fa',theme,state,width});
}

function fixture(source,c) {
  let html=source.replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi,'')
    .replace(/<link\b[^>]*\brel=["']stylesheet["'][^>]*>/gi,'');
  html=html.replace(/<html\b[^>]*>/i,tag=>tag.replace(/\sdata-theme=["'][^"']*["']/i,'').replace('>',' data-theme="'+c.theme+'">'));
  html=html.replace(/<\/head>/i,
    ['admin','jdate','site-select','admin-wallet-refund'].map(name=>'<link rel="stylesheet" href="/assets/css/'+name+'.css">').join('')+
    '<style>html{scroll-behavior:auto!important}</style></head>');
  return html.replace(/<\/body>/i,'<script>window.__uiErrors=[];addEventListener("error",e=>{if(e.message)__uiErrors.push(e.message)});'+
    'addEventListener("unhandledrejection",e=>__uiErrors.push(String(e.reason)));window.__case='+JSON.stringify(c)+';</script>'+
    '<script src="/assets/js/site-select.js"></script><script src="/scenario.js"></script></body>');
}

async function scenario() {
  const c=window.__case,report={...c,status:'failed',checks:[]};
  const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
  const frame=()=>new Promise(resolve=>requestAnimationFrame(()=>resolve()));
  const check=(value,label)=>{if(!value)throw Error(label);report.checks.push(label);};
  const until=async(predicate,label)=>{for(let i=0;i<80;i++){if(predicate())return;await wait(20);await frame();}throw Error('Timed out: '+label);};
  const overflow=()=>Math.max(document.documentElement.scrollWidth,document.body.scrollWidth)<=innerWidth+1;
  try {
    await frame();
    const root=document.querySelector('.ar-workspace');
    check(!!root,'admin refund workspace renders');
    check(innerWidth===c.width,'actual responsive viewport');
    check(root.getBoundingClientRect().width>100,'workspace remains visible');
    check(overflow(),'long identifiers and owner names do not overflow document');
    check(root.dataset.refundStatus===(c.state==='legacy'?'paid':c.state==='validation'?'approved':c.state),'correct refund state');
    check(!/\brefund\.[a-z_]+/.test(root.textContent),'all workspace copy is translated');
    document.querySelectorAll('form').forEach(form=>form.addEventListener('submit',event=>event.preventDefault()));
    for(const field of root.querySelectorAll('input:not([type="hidden"]),select,textarea')) {
      check(!!field.labels?.length && Array.from(field.labels).some(label=>label.textContent.trim()),'programmatic field label: '+field.name);
    }
    for(const form of root.querySelectorAll('form')) {
      check(form.method==='post' && !!form.querySelector('[name="_token"]')?.value,'POST and CSRF retained');
    }
    const approved=['approved','validation'].includes(c.state);
    check(!!root.querySelector('[data-refund-form="approve"]')===(c.state==='requested'),'approval form follows workflow state');
    check(!!root.querySelector('[data-refund-form="paid"]')===approved,'payout form follows workflow state');
    if(['paid','legacy'].includes(c.state)) {
      check(!!root.querySelector('[data-funding-evidence="'+(c.state==='paid'?'snapshot':'legacy')+'"]'),'funding evidence identity is explicit');
      check(!root.querySelector('form'),'settled requests have no action form');
    }
    if(c.state==='validation') {
      check(!!root.querySelector('[role="alert"]'),'validation feedback remains visible');
      check(root.querySelector('[name="funding_account_id"]').value==='502','partner choice survives validation');
      check(root.querySelector('[name="bank_reference"]').value==='SYNTHETIC-REF-77','reference survives validation');
    }
    if(c.state==='requested' || approved) {
      const name=approved?'funding_account_id':'source_payment_id';
      const select=root.querySelector('select[name="'+name+'"]');
      check(!!select,'native provenance or payout selector exists');
      await until(()=>!!select.closest('.sn-select')?.querySelector('.sn-select-trigger'),'shared picker enhanced');
      const trigger=select.closest('.sn-select').querySelector('.sn-select-trigger');
      check(trigger.type==='button' && !!trigger.getAttribute('aria-label'),'picker retains accessible field name');
      if(approved) {
        check(select.required && select.querySelectorAll('optgroup').length===2,'company bank and partner current account remain distinct');
        check(root.querySelector('[name="evidence"]').required && root.querySelector('[name="transferred"]').required,'receipt and transfer attestation are required');
      }
      trigger.scrollIntoView({behavior:'instant',block:'center'});await frame();trigger.click();
      await until(()=>!!document.querySelector('.sn-select-menu'),'picker opens');await wait(250);
      const menu=document.querySelector('.sn-select-menu'),box=menu.getBoundingClientRect();
      check(box.width>0 && box.left>=-1 && box.right<=innerWidth+1 && box.top>=-1 && box.bottom<=innerHeight+1,'picker portal stays within viewport');
      check(getComputedStyle(menu).animationName.includes('sn-select-open'),'picker uses shared smooth opening animation');
      if(approved) for(const group of select.querySelectorAll('optgroup')) {
        const item=menu.querySelector('[data-index="'+group.querySelector('option').index+'"]');
        check(item.textContent.includes(group.label),'enhanced menu exposes the payer account type');
      }
      const option=Array.from(select.options).find(option=>option.value && !option.disabled);
      check(!!option,'registered option exists');
      menu.querySelector('[data-index="'+option.index+'"]').click();
      check(select.value===option.value && new FormData(select.form).get(name)===option.value,'presentation choice and form payload agree');
      if(approved) check(trigger.textContent.includes(option.parentElement.label),'chosen payer retains company or partner classification');
      check(document.activeElement===trigger && !document.querySelector('.sn-select-menu'),'selection closes menu and restores focus');
      trigger.click();await until(()=>!!document.querySelector('.sn-select-menu'),'picker reopens');
      trigger.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));
      check(!document.querySelector('.sn-select-menu') && trigger.getAttribute('aria-expanded')==='false','Escape dismisses picker');
      check(overflow(),'opened picker does not widen the document');
    }
    check(window.__uiErrors.length===0,'no uncaught browser errors');
    report.status='passed';
  } catch(error) {
    report.error=String(error.stack||error);
    report.debug={width:innerWidth,scrollWidth:document.documentElement.scrollWidth,active:document.activeElement?.outerHTML.slice(0,400),errors:window.__uiErrors};
  }
  parent.postMessage({kind:'admin-refund-ui-result',report},location.origin);
}

function outer() {
  return '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0">'+
    '<iframe id="fixture" style="display:block;border:0;height:1000px"></iframe><pre id="qa-result" hidden></pre><script>'+
    'const cases='+JSON.stringify(cases)+';let index=0,done=false;const results=[];const frame=document.getElementById("fixture");'+
    'function finish(error){if(done)return;done=true;clearTimeout(timer);document.getElementById("qa-result").textContent=JSON.stringify({results,error});}'+
    'function next(){if(index===cases.length){finish(null);return;}const i=index++,c=cases[i];frame.style.width=c.width+"px";frame.src="/fixture?case="+i;}'+
    'addEventListener("message",event=>{if(done||event.origin!==location.origin||event.source!==frame.contentWindow||event.data.kind!=="admin-refund-ui-result")return;results.push(event.data.report);next();});'+
    'const timer=setTimeout(()=>finish("Admin browser scenario timeout"),60000);next();</script></body></html>';
}

test('admin wallet refund detail in Chromium: 54 rendered cases',{timeout:120000},async()=>{
  assert.ok(fixtureDir,'ADMIN_WALLET_REFUND_UI_FIXTURES must name the synthetic PHP fixture directory');
  const fixtures=new Map(),assets=new Map();
  for(const c of cases){const name=c.locale+'-'+c.state+'.html';if(!fixtures.has(name))fixtures.set(name,await fs.readFile(path.join(fixtureDir,name),'utf8'));}
  for(const name of ['css/admin.css','css/jdate.css','css/site-select.css','css/admin-wallet-refund.css','js/site-select.js'])assets.set('/assets/'+name,await fs.readFile(path.join(app,'public/assets',name)));
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
  const profile=await fs.mkdtemp(path.join(os.tmpdir(),'servernet-admin-refund-ui-'));
  try {
    const report=JSON.parse(await chromiumReport(profile,false,'http://127.0.0.1:'+server.address().port));
    assert.equal(report.error,null,JSON.stringify(report));assert.equal(report.results.length,cases.length);
    for(const result of report.results)assert.equal(result.status,'passed',JSON.stringify(result));
    console.log(JSON.stringify({cases:report.results.map(result=>({...result,checks:result.checks.length}))}));
  }finally{
    await new Promise(resolve=>server.close(resolve));
    assert.equal(path.dirname(profile),os.tmpdir());assert.ok(path.basename(profile).startsWith('servernet-admin-refund-ui-'));
    await fs.rm(profile,{recursive:true,force:true,maxRetries:10,retryDelay:100});
  }
});
