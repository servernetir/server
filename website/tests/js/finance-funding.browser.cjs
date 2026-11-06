/* Synthetic real accounting Blade output, local assets, and no financial mutations. */
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs/promises');
const path=require('node:path');
const os=require('node:os');
const http=require('node:http');
const {chromiumReport}=require('./panel-navigation.browser.cjs');
const app=path.resolve(__dirname,'../..');
const fixtureDir=process.env.FINANCE_FUNDING_UI_FIXTURES;
const cases=[];
for(const locale of ['fa','en','tr']) for(const theme of ['dark','light']) for(const width of [320,1440]) cases.push({locale,theme,width});

function fixture(source,c) {
  let html=source.replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi,'')
    .replace(/<link\b[^>]*\brel=["']stylesheet["'][^>]*>/gi,'');
  html=html.replace(/<html\b[^>]*>/i,tag=>tag.replace(/\sdata-theme=["'][^"']*["']/i,'').replace('>',' data-theme="'+c.theme+'">'));
  html=html.replace(/<\/head>/i,
    ['admin','jdate','site-select','finance-funding'].map(name=>'<link rel="stylesheet" href="/assets/css/'+name+'.css">').join('')+
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
    check(innerWidth===c.width,'actual responsive viewport');
    check(document.querySelector('form[data-account-create]')?.getBoundingClientRect().width>100,'financial workspace is visible');
    check(overflow(),'financial forms and long owner names do not widen the document');
    document.querySelectorAll('form').forEach(form=>form.addEventListener('submit',event=>event.preventDefault()));
    for(const form of document.querySelectorAll('form[data-account-create],form[data-account-update],form[data-finance-voucher],form[data-supplier-debit]')) {
      check(form.method==='post' && !!form.querySelector('[name="_token"]')?.value,'financial form keeps POST and CSRF');
      check(!/\bfinance\.[a-z_]+/.test(form.textContent),'financial form has translated copy');
    }
    for(const input of document.querySelectorAll('input[name="card_number"],input[name="iban"]')) check(input.value==='','private bank identifiers are not prefilled');
    const voucher=document.querySelector('form[data-finance-voucher]');
    check(voucher.querySelector('[name="funding_mode"]').value==='advance','funding purpose survives validation');
    check(voucher.querySelector('[name="related_voucher_id"]').value==='741','related evidence survives validation');
    const supplier=document.querySelector('form[data-supplier-debit]');
    check(supplier.enctype==='multipart/form-data' && supplier.querySelector('[name="evidence"]').required,'supplier proof is a required file upload');
    check(supplier.querySelector('[name="business_entry_id"]').required,'supplier debit links the existing business cost');
    const select=voucher.querySelector('select[name="funding_account_id"]');
    await until(()=>!!select?.closest('.sn-select')?.querySelector('.sn-select-trigger'),'shared account picker enhanced');
    const trigger=select.closest('.sn-select').querySelector('.sn-select-trigger');
    check(trigger.type==='button' && !!trigger.getAttribute('aria-label'),'account picker has an accessible name');
    const option=Array.from(select.options).find(option=>option.textContent.includes('Synthetic partner lira'));
    check(!!option && option.textContent.includes('TRY') && option.textContent.includes('Synthetic Partner'),'account choice exposes owner and currency');
    trigger.scrollIntoView({behavior:'instant',block:'center'});await frame();trigger.click();
    await until(()=>!!document.querySelector('.sn-select-menu'),'account picker opens');await wait(250);
    const menu=document.querySelector('.sn-select-menu'),box=menu.getBoundingClientRect();
    check(box.width>0 && box.left>=-1 && box.right<=innerWidth+1 && box.top>=-1 && box.bottom<=innerHeight+1,'account picker portal stays in the viewport');
    check(getComputedStyle(menu).animationName.includes('sn-select-open'),'account picker uses shared smooth opening');
    const item=menu.querySelector('[data-index="'+option.index+'"]');
    check(item.querySelector('.sn-select-option-label')?.textContent===option.label,'enhanced picker retains full account type, owner and currency');
    item.click();
    check(select.value===option.value && new FormData(voucher).get('funding_account_id')===option.value,'chosen account is the submitted account');
    check(trigger.textContent.includes('TRY') && trigger.textContent.includes('Synthetic Partner'),'selected account remains identifiable');
    check(document.activeElement===trigger && !document.querySelector('.sn-select-menu'),'selection restores focus and closes portal');
    trigger.click();await until(()=>!!document.querySelector('.sn-select-menu'),'account picker reopens');
    trigger.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));
    check(!document.querySelector('.sn-select-menu') && trigger.getAttribute('aria-expanded')==='false','Escape closes the picker');
    check(overflow(),'interactive picker does not cause horizontal document overflow');
    check(window.__uiErrors.length===0,'no uncaught browser errors');
    report.status='passed';
  } catch(error) {
    report.error=String(error.stack||error);
    report.debug={width:innerWidth,scrollWidth:document.documentElement.scrollWidth,active:document.activeElement?.outerHTML.slice(0,400),errors:window.__uiErrors,
      overflow:Array.from(document.body.querySelectorAll('*')).map(el=>{const r=el.getBoundingClientRect();return {tag:el.tagName,cls:String(el.className).slice(0,100),parent:el.parentElement?.className,left:r.left,right:r.right,width:r.width};}).filter(x=>x.width>0 && (x.right>innerWidth+1 || x.left< -1)).sort((a,b)=>b.right-a.right).slice(0,16)};
  }
  parent.postMessage({kind:'finance-funding-ui-result',report},location.origin);
}

function outer() {
  return '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0">'+
    '<iframe id="fixture" style="display:block;border:0;height:1000px"></iframe><pre id="qa-result" hidden></pre><script>'+
    'const cases='+JSON.stringify(cases)+';let index=0,done=false;const results=[];const frame=document.getElementById("fixture");'+
    'function finish(error){if(done)return;done=true;clearTimeout(timer);document.getElementById("qa-result").textContent=JSON.stringify({results,error});}'+
    'function next(){if(index===cases.length){finish(null);return;}const i=index++,c=cases[i];frame.style.width=c.width+"px";frame.src="/fixture?case="+i;}'+
    'addEventListener("message",event=>{if(done||event.origin!==location.origin||event.source!==frame.contentWindow||event.data.kind!=="finance-funding-ui-result")return;results.push(event.data.report);next();});'+
    'const timer=setTimeout(()=>finish("Finance browser scenario timeout"),60000);next();</script></body></html>';
}

test('financial funding and account identity in Chromium: 12 rendered cases',{timeout:120000},async()=>{
  assert.ok(fixtureDir,'FINANCE_FUNDING_UI_FIXTURES must name the synthetic PHP fixture directory');
  const fixtures=new Map(),assets=new Map();
  for(const locale of ['fa','en','tr'])fixtures.set(locale,await fs.readFile(path.join(fixtureDir,locale+'.html'),'utf8'));
  for(const name of ['css/admin.css','css/jdate.css','css/site-select.css','css/finance-funding.css','js/site-select.js'])assets.set('/assets/'+name,await fs.readFile(path.join(app,'public/assets',name)));
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
        const c=cases[index];res.end(fixture(fixtures.get(c.locale),c));return;
      }
      res.statusCode=404;res.end('');
    }catch(error){res.statusCode=500;res.end(String(error.message));}
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const profile=await fs.mkdtemp(path.join(os.tmpdir(),'servernet-finance-funding-ui-'));
  try {
    const report=JSON.parse(await chromiumReport(profile,false,'http://127.0.0.1:'+server.address().port));
    assert.equal(report.error,null,JSON.stringify(report));assert.equal(report.results.length,cases.length);
    for(const result of report.results)assert.equal(result.status,'passed',JSON.stringify(result));
    console.log(JSON.stringify({cases:report.results.map(result=>({...result,checks:result.checks.length}))}));
  }finally{
    await new Promise(resolve=>server.close(resolve));
    assert.equal(path.dirname(profile),os.tmpdir());assert.ok(path.basename(profile).startsWith('servernet-finance-funding-ui-'));
    await fs.rm(profile,{recursive:true,force:true,maxRetries:10,retryDelay:100});
  }
});
