/* Real controller/view fixtures, isolated browser rendering; never contacts production. */
const {chromium}=require('playwright');
const fs=require('node:fs');
const path=require('node:path');
const app=path.resolve(__dirname,'../..');
const fixtures=process.env.CONSOLE_NEXT_FIXTURES||'/workspace/artifacts/servernet-redesign/live-candidate/fixtures';
const out=process.env.CONSOLE_NEXT_BROWSER_OUT||'/workspace/artifacts/servernet-redesign/live-candidate/browser';
const origin='http://console-next.test';
fs.mkdirSync(out,{recursive:true});
const records=['fa','en','tr'].flatMap(locale=>JSON.parse(fs.readFileSync(path.join(fixtures,locale+'-manifest.json'),'utf8')));
const docs=new Map(records.map(record=>[record.path,record]));
const failures=[],layouts=[],scenarios=[];
let assertions=0;
function check(value,message){assertions++;if(!value)failures.push(message);}
function mime(file){return ({'.css':'text/css','.js':'application/javascript','.svg':'image/svg+xml','.woff2':'font/woff2','.png':'image/png','.jpg':'image/jpeg','.webp':'image/webp','.ico':'image/x-icon'})[path.extname(file)]||'application/octet-stream';}
async function attach(page){
 const errors=[],unexpected=[],mutations=[],readonlyFixtures=[];
 page.on('pageerror',error=>errors.push(error.message));
 await page.addInitScript(()=>{window.__cnRaf=0;const raf=window.requestAnimationFrame.bind(window);window.requestAnimationFrame=function(fn){window.__cnRaf++;return raf(fn);};});
 await page.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url());
  if(!['GET','HEAD'].includes(req.method())){mutations.push(req.method()+' '+url.pathname);return route.abort();}
  if(req.isNavigationRequest()&&docs.has(url.pathname))return route.fulfill({contentType:'text/html',body:fs.readFileSync(path.join(fixtures,docs.get(url.pathname).file))});
  if(url.pathname.startsWith('/assets/')||url.pathname==='/favicon.svg'){
   const local=path.resolve(app,'public','.'+decodeURIComponent(url.pathname));
   if(local.startsWith(path.join(app,'public')+path.sep)&&fs.existsSync(local)&&fs.statSync(local).isFile())return route.fulfill({contentType:mime(local),body:fs.readFileSync(local)});
   unexpected.push('Missing asset '+url.pathname);return route.fulfill({status:404,body:''});
  }
  if(/\/(?:stats|metrics|status|domain-connection)$/.test(url.pathname)&&url.pathname.includes('/account/next/')){
   readonlyFixtures.push(url.pathname);
   // Existing views must handle an unavailable provider without real infrastructure calls.
   return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:false,delivery_issue:null})});
  }
  if(req.resourceType()==='image')return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'});
  unexpected.push(url.href);return route.abort();
 });
 return {errors,unexpected,mutations,readonlyFixtures};
}
async function ready(page,record){
 await page.goto(origin+record.path,{waitUntil:'networkidle'});
 await page.waitForFunction(()=>document.documentElement.dataset.cnReady==='true');
 await page.evaluate(()=>document.fonts.ready);
}
async function metrics(page,record,width){
 const m=await page.evaluate(()=>({width:innerWidth,scrollWidth:document.documentElement.scrollWidth,headings:document.querySelectorAll('main h1').length,main:document.querySelectorAll('main').length,hamburgers:[...document.querySelectorAll('.cn-menu-toggle')].filter(n=>n.getClientRects().length).length,legacyHamburgers:document.querySelectorAll('#hamburger,#pnl-menu').length}));
 check(m.scrollWidth<=m.width+1,`${record.locale}/${width}/${record.page}: no horizontal page overflow (${m.scrollWidth})`);
 check(m.main===1&&m.headings===1,`${record.locale}/${width}/${record.page}: one real page heading and main landmark`);
 check(m.legacyHamburgers===0,`${record.locale}/${width}/${record.page}: legacy duplicate menus are absent`);
 if(width<=1000)check(m.hamburgers===1,`${record.locale}/${width}/${record.page}: one unified hamburger`);
 layouts.push({locale:record.locale,page:record.page,...m});
}
async function contrast(page,label){
 const buttons=await page.locator('.pnl-btn.primary,.cn-button.primary,.svc-qbtn.primary').evaluateAll(nodes=>{
  const color=s=>{let n=(s.match(/[0-9.]+/g)||[]).map(Number);return [n[0]||0,n[1]||0,n[2]||0,n[3]??1];};
  const over=(a,b)=>{const alpha=a[3]+b[3]*(1-a[3]);return [0,1,2].map(i=>(a[i]*a[3]+b[i]*b[3]*(1-a[3]))/(alpha||1)).concat(alpha);};
  const lum=a=>{const c=a.slice(0,3).map(x=>{x/=255;return x<=.04045?x/12.92:((x+.055)/1.055)**2.4;});return c[0]*.2126+c[1]*.7152+c[2]*.0722;};
  return nodes.filter(n=>n.getClientRects().length&&!n.disabled).map(node=>{
   let s=getComputedStyle(node),bg=color(s.backgroundColor);
   for(let p=node.parentElement;bg[3]<1&&p;p=p.parentElement)bg=over(bg,color(getComputedStyle(p).backgroundColor));
   if(bg[3]<1)bg=over(bg,[255,255,255,1]);
   const stops=(s.backgroundImage.match(/rgba?\([^)]*\)/g)||[]).map(x=>over(color(x),bg));
   return {label:node.textContent.trim().slice(0,50),ratio:Math.min(...(stops.length?stops:[bg]).map(b=>{const a=lum(over(color(s.color),b)),c=lum(b);return (Math.max(a,c)+.05)/(Math.min(a,c)+.05);} ))};
  });
 });
 check(buttons.every(b=>b.ratio>=4.5),label+': primary action contrast '+JSON.stringify(buttons));
}
async function globeBrightness(page){
 return page.locator('[data-network-globe]').evaluate(canvas=>{
  const w=canvas.width,h=canvas.height,pixels=canvas.getContext('2d').getImageData(Math.floor(w*.43),Math.floor(h*.4),Math.max(1,Math.floor(w*.14)),Math.max(1,Math.floor(h*.24))).data;
  let count=0,total=0;for(let i=0;i<pixels.length;i+=4)if(pixels[i+3]>200){count++;total+=pixels[i]*.2126+pixels[i+1]*.7152+pixels[i+2]*.0722;}return count?total/count:0;
 });
}
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CONSOLE_NEXT_CHROMIUM||'/usr/bin/chromium',args:['--no-sandbox'],headless:true});
 for(const record of records){for(const width of [390,1440]){
  const page=await browser.newPage({viewport:{width,height:width<1000?844:1000},reducedMotion:'reduce'}),d=await attach(page);
  await ready(page,record);await metrics(page,record,width);
  if(record.page==='cloud-store'&&width<=1000){
   const dock=page.locator('.cvb-dock'),nav=page.locator('.cn-mobile-nav');
   if(await dock.isVisible()){const a=await dock.boundingBox(),b=await nav.boundingBox();check(a.y+a.height<=b.y+1,`${record.locale}/${width}: checkout dock stays above mobile navigation`);}
  }
  check(await page.locator('html').getAttribute('lang')===record.locale,`${record.locale}/${width}/${record.page}: locale`);
  if(record.locale==='fa'){
   await contrast(page,`${record.page}/${width} dark`);
   if(['home','hosting','profile','cloud-store','dns-zone','invoice','security'].includes(record.page))await page.screenshot({path:path.join(out,`${record.page}-fa-${width}-dark.png`)});
   if(['home','hosting','profile'].includes(record.page)){
    await page.locator('[data-cn-theme]').click();
    check(await page.locator('html').getAttribute('data-theme')==='light',`${record.page}/${width}: light appearance`);
    await contrast(page,`${record.page}/${width} light`);
    if(await page.locator('[data-network-globe]').count()){
     await page.locator('[data-network-globe][data-theme="light"]').waitFor();
     check(await globeBrightness(page)>160,`${record.page}/${width}: actual globe pixels are light`);
    }
    await page.screenshot({path:path.join(out,`${record.page}-fa-${width}-light.png`)});
   }
  }
  check(d.errors.length===0,`${record.locale}/${width}/${record.page}: JS errors ${d.errors.join('; ')}`);
  check(d.unexpected.length===0,`${record.locale}/${width}/${record.page}: unexpected requests ${d.unexpected.join('; ')}`);
  check(d.mutations.length===0,`${record.locale}/${width}/${record.page}: no real mutations`);
  if(record.page==='cloud-store'){
   // The existing configurator uses a bounded layout frame; it must not animate continuously.
   const settledFrames=await page.evaluate(()=>window.__cnRaf);await page.waitForTimeout(150);
   check(await page.evaluate(()=>window.__cnRaf)===settledFrames,`${record.locale}/${width}/${record.page}: reduced motion has no ongoing frame loop`);
  }else check(await page.evaluate(()=>window.__cnRaf)===0,`${record.locale}/${width}/${record.page}: reduced motion schedules no decorative frames`);
  scenarios.push({locale:record.locale,page:record.page,width,...d});await page.close();
 }}
 // Exercise actual navigation controls independently from controller fake-data tests.
 const home=records.find(r=>r.locale==='fa'&&r.page==='home');
 const page=await browser.newPage({viewport:{width:390,height:844},reducedMotion:'reduce'}),d=await attach(page);await ready(page,home);
 const trigger=page.locator('.cn-menu-toggle');await trigger.click();
 check(await page.locator('body').getAttribute('data-cn-drawer')==='open','Mobile drawer opens');
 check(await page.locator('.cn-workspace').evaluate(n=>n.inert)&&await page.locator('.cn-header').evaluate(n=>n.inert),'Drawer isolates the background');
 check(await page.locator('.cn-drawer-site-group').count()===4,'One drawer contains the four actual website menus');
 await page.locator('#cn-sidebar').evaluate(n=>[...n.querySelectorAll('a[href],button,summary')].filter(x=>x.getClientRects().length).at(-1).focus());
 await page.keyboard.press('Tab');check(await page.locator('#cn-sidebar').evaluate(n=>n.contains(document.activeElement)),'Drawer traps keyboard focus');
 await page.keyboard.press('Escape');check(await trigger.evaluate(n=>document.activeElement===n),'Escape restores mobile trigger focus');
 await page.locator('.cn-mobile-nav [data-cn-open-drawer]').click();
 const drawerBounds=await page.locator('.cn-sidebar').boundingBox();
 check(drawerBounds.x>=-1&&drawerBounds.x+drawerBounds.width<=391,'Opened drawer is inside the mobile viewport');
 const profile=records.find(r=>r.locale==='fa'&&r.page==='profile');
 await page.locator(`.cn-sidebar-nav a[href$="${profile.path}"]`).click();await page.waitForURL('**'+profile.path);
 check(await page.locator('body').getAttribute('data-cn-drawer')==='closed','Real account navigation closes drawer and loads the profile');
 await page.locator('[data-cn-open-search]').click();check(await page.locator('#cn-search-dialog').evaluate(n=>n.open),'Workspace search opens');
 await page.locator('[data-cn-search-input]').fill('NO_MATCHING_CUSTOMER_SECTION');check(await page.locator('[data-cn-search-empty]').isVisible(),'Search has an honest empty state');
 await page.locator('[data-cn-search-input]').fill('hosting');check(await page.locator('[data-cn-search-results] a').count()===1,'Search filters real account links');
 await page.keyboard.press('Escape');check(await page.locator('[data-cn-open-search]').evaluate(n=>document.activeElement===n),'Search restores keyboard focus');
 await page.locator('.cn-user-trigger').click();
 const old=await page.locator('.cn-user-menu .cn-old-link').getAttribute('href');check(new URL(old).pathname==='/account/profile','Old-console link returns to equivalent original profile');
 await page.keyboard.press('Escape');await page.locator('.cn-user-trigger').click();
 await page.locator('.cn-language-links a[lang="en"]').click();await page.waitForURL('**/en/account/next/profile');
 check(await page.locator('html').getAttribute('lang')==='en','Actual locale link preserves candidate profile');
 await page.setViewportSize({width:1440,height:1000});await page.locator('.cn-site-dropdown').first().locator('summary').click();
 check(await page.locator('.cn-site-dropdown[open]').count()===1,'Main website menu opens');
 await page.keyboard.press('Escape');check(await page.locator('.cn-site-dropdown[open]').count()===0,'Escape closes actual website menu');
 check(await page.locator('#chat-fab').count()===1,'Existing global support chat remains available once');
 await page.locator('#chat-fab').click();
 check(await page.locator('#chat-panel').evaluate(n=>!n.inert),'Existing chat opens with its original behavior');
 await page.keyboard.press('Escape');
 check(d.errors.length===0&&d.unexpected.length===0&&d.mutations.length===0,'Navigation interaction has no errors or unexpected requests');
 await page.close();await browser.close();
 const report={passed:failures.length===0,assertions,layoutCases:layouts.length,failures,layouts,scenarios};
 fs.writeFileSync(path.join(out,'results.json'),JSON.stringify(report,null,2));
 console.log(JSON.stringify({passed:report.passed,assertions,layoutCases:layouts.length,failures:failures.slice(0,16)}));
 if(!report.passed)process.exitCode=1;
})().catch(error=>{fs.writeFileSync(path.join(out,'failure.txt'),error.stack||String(error));fs.writeFileSync(path.join(out,'results.json'),JSON.stringify({passed:false,assertions,failures,layouts,error:String(error)},null,2));console.error(String(error).slice(0,1200));process.exit(1);});
