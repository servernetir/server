'use strict';
// Render the deployed Blade-source candidate with synthetic auth/state only.
const fs=require('node:fs'),path=require('node:path'),http=require('node:http'),assert=require('node:assert/strict');
const {chromium}=require('playwright');
const source=fs.readFileSync(path.join(__dirname,'hermes.blade.php'),'utf8');
const labels={unknown:'Unknown',private:'Private',connecting:'Connecting',session_expired:'Expired',send:'Send',placeholder:'Message',memory_placeholder:'Note',new_chat:'New chat',you:'You',copy:'Copy',uncertain:'Outcome uncertain; command was not repeated.'};
function render(lang){return source.replaceAll('{{ $locale }}',lang).replaceAll("{{ $locale === 'fa' ? 'rtl' : 'ltr' }}",lang==='fa'?'rtl':'ltr').replaceAll("{{ $locale === 'fa' ? 'آراز' : 'Araz' }}",lang==='fa'?'آراز':'Araz').replace('@json($labels)',JSON.stringify(labels)).replace('@json($locale)',JSON.stringify(lang)).replace(/<script src="https:\/\/tapi.bale.ai\/miniapp.js"><\/script>/,'').replace("@include('bale.hermes-operations')",'<section id="view-operations" class="hidden"></section>').replace("@include('bale.hermes-operations-script')",'function loadOperations(){}');}
(async()=>{
 const server=http.createServer((req,res)=>{res.setHeader('Content-Type','text/html; charset=utf-8');res.end(render(new URL(req.url,'http://localhost').searchParams.get('lang')||'fa'))});
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 let browser;
 try{
  browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',args:['--no-sandbox']});
  let cases=0;
  for(const lang of ['fa','en','tr'])for(const width of [390,1280]){
   const context=await browser.newContext({viewport:{width,height:844},reducedMotion:'reduce'}),page=await context.newPage(),errors=[];
   page.on('pageerror',e=>errors.push(e.message));
   let reads=0;const now=Date.now()/1000;
   const pending={id:'q',role:'user',text:'Synthetic question',at:now,status:'pending'};
   let state={messages:[pending],memories:[]};
   await page.addInitScript(()=>{window.Bale={WebApp:{initData:'synthetic-only',colorScheme:'dark',ready(){}}}});
   await page.route('**/bale/hermes/**',async route=>{
    if(route.request().url().includes('/assets/'))return route.fulfill({status:404,body:''});
    if(route.request().url().endsWith('/state'))reads++;
    await route.fulfill({json:{ok:true,state,session:{token:'synthetic-only',expires_at:Date.now()/1000+600}}});
   });
   await page.goto(`http://127.0.0.1:${server.address().port}/?lang=${lang}`);
   await page.locator('#main').waitFor({state:'visible'});
   await page.waitForFunction(()=>document.querySelector('#messages article'));
   await page.evaluate(()=>{window.__originalBubble=document.querySelector('#messages article');window.__bubbleChanges=0;new MutationObserver(()=>window.__bubbleChanges++).observe(document.querySelector('#messages'),{childList:true,subtree:true})});
   // Exercise the actual visibility-resume handler three times with unchanged state.
   for(let i=0;i<3;i++){
    const before=reads;await Promise.all([page.waitForResponse(r=>r.url().endsWith('/state')),page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')))]);
    await page.waitForFunction(()=>document.querySelector('#message').getAttribute('aria-busy')==='true');
    assert(reads>before);
   }
   assert(await page.evaluate(()=>window.__originalBubble===document.querySelector('#messages article')));
   assert.equal(await page.evaluate(()=>window.__bubbleChanges),0,'unchanged polls do not mutate bubbles');
   state={messages:[{...pending,status:'uncertain'}],memories:[]};
   await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));
   await page.locator('#typing').waitFor({state:'hidden'});
   assert.equal(await page.locator('#message').getAttribute('aria-busy'),'false');
   assert(await page.locator('#send').isDisabled(),'uncertainty guard remains');
   state={messages:[{...pending,status:'done'},{id:'answer',role:'assistant',text:'Synthetic completed answer',at:now,status:'done'}],memories:[]};
   await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));
   await page.getByText('Synthetic completed answer',{exact:true}).waitFor();
   assert(await page.locator('#send').isEnabled());assert.equal(errors.length,0,errors.join('\n'));
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'no horizontal overflow');
   cases++;await context.close();
  }
  console.log(`${cases} Chromium cases passed: fa/en/tr at 390/1280px, unchanged-poll DOM stability, uncertain state and late reply. All API calls synthetic.`);
 }finally{if(browser)await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(e=>{console.error(e);process.exitCode=1});
