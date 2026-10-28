/* Synthetic Chromium integration. No production login, database, API or service. */
const {test} = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const fs = require('node:fs/promises');
const path = require('node:path');
const os = require('node:os');
const {spawn} = require('node:child_process');

const app = path.resolve(__dirname, '../..');
const chrome = process.env.TEST_CHROME || '/usr/bin/google-chrome';
const words = {
  fa: {menu:'فهرست', close:'بستن', auth_logout:'خروج'},
  en: {menu:'Menu', close:'Close', auth_logout:'Sign out'},
  tr: {menu:'Menü', close:'Kapat', auth_logout:'Çıkış'}
};
const matrix = [];
for (const locale of ['fa','en','tr']) {
  for (const theme of ['dark','light']) {
    for (const width of [320,390,1000,1001,1440]) {
      matrix.push({locale,theme,width});
    }
  }
}
matrix.push({locale:'fa',theme:'dark',width:390,cvb:true});

function fixture(layout, config) {
  const start = layout.indexOf('<section class="pnl-wrap">');
  const end = layout.indexOf('</section>', start);
  assert.ok(start >= 0 && end > start, 'real panel shell exists');
  let shell = layout.slice(start, end + '</section>'.length);
  shell = shell.replace(/\{\{--[\s\S]*?--\}\}/g, '');
  shell = shell.replace(/@include\('panel\.avatar'[^\n]*\)/g, '');
  shell = shell.replace('@yield(\'panel\')',
    '<div'+(config.cvb?' class="cvb-wrap"':'')+'>'+
    '<h1 id="fixture-title">Account workspace</h1>'+
    '<button id="outside" type="button">Outside action</button>'+
    '<div id="existing-inert" inert>Existing disabled region</div>'+
    '<div id="destination">Destination</div></div>');
  shell = shell.replace(/<nav class="pnl-nav">[\s\S]*?<\/nav>/,
    '<nav class="pnl-nav">'+
    '<a id="nav-first" href="#destination">Overview</a>'+
    '<a id="nav-last" href="#destination">Profile</a>'+
    '<a id="nav-hidden" href="#destination" hidden>Hidden</a>'+
    '<button id="nav-disabled" disabled>Disabled</button></nav>');
  shell = shell.replace(/@auth\('customer'\)|@endauth|@csrf/g, '');
  shell = shell.replace(/\{\{\s*lroute\('logout'\)\s*\}\}/g, '#logout');
  shell = shell.replace(/\{\{\s*\$pnlUser\['name'\]\s*\?\?\s*''\s*\}\}/g, 'Test customer');
  shell = shell.replace(/\{\{\s*\$pnlUser\['code'\]\s*\?\?\s*''\s*\}\}/g, 'TEST-001');
  shell = shell.replace(/\{\{\s*__\('ui\.([^']+)'\)\s*\}\}/g, (_,key) => {
    assert.ok(words[config.locale][key], 'known fixture translation: '+key);
    return words[config.locale][key];
  });
  assert.doesNotMatch(shell, /\{\{|@(?:include|yield|foreach|auth|csrf)/,
    'only dynamic Blade data is replaced');
  return '<!doctype html><html lang="'+config.locale+'" dir="'+
    (config.locale==='fa'?'rtl':'ltr')+'" data-theme="'+config.theme+'"><head>'+
    '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'+
    '<link rel="stylesheet" href="/assets/css/site.css">'+
    '<link rel="stylesheet" href="/assets/css/panel.css">'+
    '<style>body{min-height:2200px}.fixture-header{padding:8px}.pnl-main{min-height:250px}'+
    '#destination{margin-top:80px}iframe{border:0}</style></head>'+
    '<body style="overflow:auto!important"><header class="fixture-header" id="fixture-header">'+
    '<a href="#destination">ServerNet</a></header><main id="main">'+shell+'</main>'+
    '<footer id="fixture-footer" inert>Existing inert footer</footer>'+
    '<script>window.__panelErrors=[];addEventListener("error",function(e){'+
    'if(e.message)window.__panelErrors.push(e.message)});'+
    'addEventListener("unhandledrejection",function(e){window.__panelErrors.push(String(e.reason))});'+
    'window.__fixtureConfig='+JSON.stringify(config)+';</script>'+
    '<script src="/assets/js/panel-navigation.js"></script>'+
    '<script src="/scenario.js"></script></body></html>';
}

async function browserScenario() {
  const c = window.__fixtureConfig;
  const report = {config:c,status:'failed',checks:[]};
  const wait = ms => new Promise(resolve => setTimeout(resolve,ms));
  const frame = () => new Promise(resolve=>requestAnimationFrame(()=>resolve()));
  const check = (value,label) => {
    if (!value) throw new Error(label);
    report.checks.push(label);
  };
  const until = async (predicate,label) => {
    for (let i=0;i<80;i++) {
      if (predicate()) return;
      await wait(20);
      await frame();
    }
    throw new Error('Timed out: '+label);
  };
  const visible = el => !!el && !!el.getClientRects().length &&
    getComputedStyle(el).visibility !== 'hidden' && !el.closest('[hidden],[inert]');
  try {
    await frame();
    const menu=document.getElementById('pnl-menu'), side=document.getElementById('pnl-side');
    const bd=document.getElementById('pnl-bd'), close=document.getElementById('pnl-close');
    const first=document.getElementById('nav-first'), outside=document.getElementById('outside');
    const last=side.querySelector('.pnl-signout button');
    check(menu && side && bd && close && last, 'actual layout controls exist');
    check(menu.getAttribute('aria-controls')==='pnl-side', 'menu controls actual sidebar');
    check(menu.type==='button' && close.type==='button', 'navigation buttons cannot submit forms');
    let outsideClicks=0;
    outside.addEventListener('click',()=>outsideClicks++);
    side.querySelector('form').addEventListener('submit',e=>e.preventDefault());
    side.querySelectorAll('a[href]').forEach(link=>link.addEventListener('click',e=>e.preventDefault()));
    function keyboard(key,shiftKey=false) {
      const event=new KeyboardEvent('keydown',{key,shiftKey,bubbles:true,cancelable:true});
      document.activeElement.dispatchEvent(event);
      return event;
    }
    async function open() {
      menu.focus();
      menu.click();
      await until(()=>document.activeElement===close, 'focus moves inside drawer');
      check(side.getAttribute('role')==='dialog' &&
        side.getAttribute('aria-modal')==='true', 'mobile drawer has modal semantics');
      check(menu.getAttribute('aria-expanded')==='true' &&
        side.getAttribute('aria-hidden')==='false' && !side.hasAttribute('inert'),
        'open accessible state');
    }
    async function closed() {
      await until(()=>!side.classList.contains('open'), 'drawer closes');
      check(menu.getAttribute('aria-expanded')==='false' &&
        side.getAttribute('aria-hidden')==='true' && side.hasAttribute('inert'),
        'closed mobile navigation is unavailable to keyboard');
      check(document.activeElement===menu, 'closing restores menu focus');
      check(document.body.style.getPropertyValue('overflow')==='auto' &&
        document.body.style.getPropertyPriority('overflow')==='important',
        'original overflow value and priority restored');
      check(!document.querySelector('.pnl-main').hasAttribute('inert') &&
        !document.getElementById('fixture-header').hasAttribute('inert') &&
        document.getElementById('fixture-footer').hasAttribute('inert') &&
        document.getElementById('existing-inert').hasAttribute('inert'),
        'background restoration preserves existing inert regions');
    }
    if(c.width<=1000) {
      check(side.hasAttribute('inert') && side.getAttribute('aria-hidden')==='true',
        'initial closed mobile sidebar is inert');
      outside.focus(); first.focus();
      check(document.activeElement===outside, 'closed navigation cannot receive focus');
      await open();
      check(document.querySelector('.pnl-main').hasAttribute('inert') &&
        document.querySelector('.pnl-mobar').hasAttribute('inert') &&
        document.getElementById('fixture-header').hasAttribute('inert'),
        'nested background and global header are isolated');
      check(!bd.closest('[inert]') && !side.closest('[inert]'),
        'drawer and backdrop stay interactive');
      check(getComputedStyle(document.body).overflow==='hidden', 'background scrolling is locked');
      last.focus();
      check(keyboard('Tab').defaultPrevented && document.activeElement===close,
        'Tab wraps from last visible control');
      check(keyboard('Tab',true).defaultPrevented && document.activeElement===last,
        'Shift Tab wraps and ignores hidden or disabled controls');
      outside.focus();
      check(side.contains(document.activeElement), 'background focus is blocked');
      await wait(350);
      const box=side.getBoundingClientRect();
      const y=Math.min(innerHeight-2,Math.max(box.bottom+4,(box.bottom+innerHeight)/2));
      const hit=document.elementFromPoint(Math.floor(innerWidth/2),Math.floor(y));
      check(getComputedStyle(bd).display!=='none' &&
        getComputedStyle(bd).visibility==='visible' &&
        getComputedStyle(bd).pointerEvents==='auto', 'backdrop is rendered and interactive');
      check(hit===bd, 'actual hit testing reaches backdrop outside drawer');
      hit.click();
      await closed();
      check(outsideClicks===0, 'backdrop interaction does not reach background actions');
      await open(); check(keyboard('Escape').defaultPrevented, 'Escape is handled'); await closed();
      await open(); close.click(); await closed();
      await open(); first.click(); await closed();
      await open();
      frameElement.style.width='1001px';
      await until(()=>innerWidth===1001 && !side.classList.contains('open'), 'desktop transition');
      check(!side.hasAttribute('inert') && !side.hasAttribute('aria-hidden') &&
        !side.hasAttribute('aria-modal') && side.getAttribute('role')!=='dialog',
        'desktop navigation restores original semantics');
      check(document.body.style.getPropertyValue('overflow')==='auto' &&
        !document.querySelector('.pnl-main').hasAttribute('inert'),
        'desktop transition releases scroll and background');
      check(document.activeElement!==document.body && visible(document.activeElement),
        'desktop transition never leaves focus on hidden mobile close button');
      if(c.cvb) check(!visible(side), 'server ordering desktop sidebar remains hidden');
      frameElement.style.width=String(c.width)+'px';
      await until(()=>innerWidth===c.width && side.hasAttribute('inert'), 'mobile transition');
      await open(); close.click(); await closed();
    } else {
      check(visible(side) && !visible(close), 'desktop sidebar is visible without mobile close');
      check(!side.hasAttribute('inert') && !side.hasAttribute('aria-hidden') &&
        !side.hasAttribute('aria-modal'), 'initial desktop sidebar is ordinary navigation');
      first.focus(); check(document.activeElement===first, 'desktop links receive focus');
      menu.click();
      check(!side.classList.contains('open') &&
        document.body.style.getPropertyValue('overflow')==='auto',
        'hidden mobile trigger cannot lock desktop');
      frameElement.style.width='390px';
      await until(()=>innerWidth===390 && side.hasAttribute('inert'), 'desktop to mobile');
      check(document.activeElement===menu, 'hidden desktop navigation releases focus to mobile menu');
      await open(); close.click(); await closed();
    }
    if(c.reduced) {
      check(matchMedia('(prefers-reduced-motion: reduce)').matches, 'reduced motion is active');
      check(getComputedStyle(side).transitionDuration.split(',').every(x=>parseFloat(x)===0) &&
        getComputedStyle(bd).transitionDuration.split(',').every(x=>parseFloat(x)===0),
        'reduced motion removes drawer and backdrop transitions');
    }
    check(window.__panelErrors.length===0,'no uncaught browser errors');
    report.status='passed';
  } catch(error) {
    report.error=String(error.stack||error);
    report.debug={width:innerWidth,active:document.activeElement?.outerHTML,errors:window.__panelErrors,
      nodes:['pnl-menu','pnl-side','pnl-bd','pnl-close'].map(id=>{const el=document.getElementById(id),s=el&&getComputedStyle(el);return {id,html:el?.outerHTML.slice(0,400),inert:!!el?.closest('[inert]'),display:s?.display,visibility:s?.visibility,transition:s?.transition,rect:el?.getBoundingClientRect().toJSON()};})};
  }
  parent.postMessage({kind:'panel-nav-result',report},location.origin);
}

function outer(cases) {
  return '<!doctype html><html><head><meta charset="utf-8"></head><body style="margin:0">'+
    '<iframe id="fixture" style="border:0;height:1000px;display:block"></iframe>'+
    '<pre id="qa-result" hidden></pre><script>'+
    'const cases='+JSON.stringify(cases)+';let index=0,done=false;const results=[];'+
    'const frame=document.getElementById("fixture");'+
    'function finish(error){if(done)return;done=true;clearTimeout(timer);'+
    'document.getElementById("qa-result").textContent=JSON.stringify({results,error});}'+
    'function next(){if(index===cases.length){finish(null);return;}const c=cases[index++];'+
    'frame.style.width=c.width+"px";frame.src="/fixture?case="+encodeURIComponent(JSON.stringify(c));}'+
    'addEventListener("message",function(e){if(done||e.origin!==location.origin||'+
    'e.source!==frame.contentWindow||e.data.kind!=="panel-nav-result")return;'+
    'results.push(e.data.report);next();});'+
    'const timer=setTimeout(()=>finish("Browser scenario timeout"),55000);next();'+
    '</script></body></html>';
}

async function chromiumReport(profile, reduced, fixtureUrl) {
  assert.equal(new URL(fixtureUrl).hostname, '127.0.0.1');
  const args = [
    '--headless=new', '--no-first-run', '--no-default-browser-check',
    '--disable-background-networking', '--user-data-dir='+profile,
    '--window-size=1480,1100', '--remote-debugging-address=127.0.0.1',
    '--remote-debugging-port=0'
  ];
  if (reduced) args.push('--force-prefers-reduced-motion');
  args.push('about:blank');
  const child = spawn(chrome, args, {
    windowsHide:true, stdio:['ignore','ignore','pipe']
  });
  child.stderr.setEncoding('utf8');
  let stderr = '', socket;
  const pending = new Map();
  try {
    const endpoint = await new Promise((resolve,reject) => {
      const timer = setTimeout(() => reject(
        new Error('Chrome debugging endpoint timeout: '+stderr.slice(-2000))
      ), 12000);
      const fail = error => {clearTimeout(timer); reject(error);};
      child.once('error',fail);
      child.once('exit',(code,signal) => fail(new Error(
        'Chrome exited '+code+'/'+signal+': '+stderr.slice(-2000)
      )));
      child.stderr.on('data',chunk => {
        stderr = (stderr+chunk).slice(-32000);
        const match = stderr.match(/DevTools listening on (ws:\/\/127\.0\.0\.1:\d+\/\S+)/);
        if (match) {clearTimeout(timer); resolve(new URL(match[1]));}
      });
    });
    let target;
    for (let attempt=0;attempt<20 && !target;attempt++) {
      const response = await fetch('http://127.0.0.1:'+endpoint.port+'/json/list',
        {signal:AbortSignal.timeout(10000)});
      assert.ok(response.ok,'Chrome target listing succeeded');
      target = (await response.json()).find(item => item.type==='page');
      if (!target) await new Promise(resolve=>setTimeout(resolve,100));
    }
    assert.ok(target && target.webSocketDebuggerUrl,'Chrome page target exists');
    const pageSocket = new URL(target.webSocketDebuggerUrl);
    assert.equal(pageSocket.protocol,'ws:');
    assert.equal(pageSocket.hostname,'127.0.0.1');
    assert.equal(pageSocket.port,endpoint.port);
    socket = new WebSocket(pageSocket);
    let serial = 0;
    const rejectPending = error => {
      for (const entry of pending.values()) {
        clearTimeout(entry.timer); entry.reject(error);
      }
      pending.clear();
    };
    socket.addEventListener('message',event => {
      const message = JSON.parse(String(event.data));
      const entry = pending.get(message.id);
      if (!entry) return;
      pending.delete(message.id); clearTimeout(entry.timer);
      if (message.error) entry.reject(new Error(message.error.message));
      else entry.resolve(message.result);
    });
    socket.addEventListener('close',() => rejectPending(new Error('Chrome CDP closed')));
    socket.addEventListener('error',() => rejectPending(new Error('Chrome CDP error')));
    await new Promise((resolve,reject) => {
      const timer=setTimeout(()=>reject(new Error('Chrome CDP connection timeout')),10000);
      socket.addEventListener('open',()=>{clearTimeout(timer);resolve();},{once:true});
      socket.addEventListener('error',()=>{
        clearTimeout(timer);reject(new Error('Cannot connect to Chrome CDP'));
      },{once:true});
    });
    const send = (method,params={}) => new Promise((resolve,reject) => {
      const id = ++serial;
      const timer = setTimeout(() => {
        pending.delete(id); reject(new Error('CDP timeout: '+method));
      },10000);
      pending.set(id,{resolve,reject,timer});
      socket.send(JSON.stringify({id,method,params}));
    });
    await send('Page.enable');
    await send('Page.bringToFront');
    const navigation = await send('Page.navigate',{url:fixtureUrl});
    assert.ok(!navigation.errorText,navigation.errorText);
    const deadline = Date.now()+70000;
    let lastError = '';
    while (Date.now()<deadline) {
      try {
        const result = await send('Runtime.evaluate',{
          expression:'document.getElementById("qa-result")?.textContent || ""',
          returnByValue:true
        });
        if (result.exceptionDetails) throw new Error(
          JSON.stringify(result.exceptionDetails)
        );
        if (result.result?.value) return result.result.value;
      } catch (error) {
        if (!/context.*destroyed|cannot find context/i.test(error.message)) throw error;
        lastError = error.message;
      }
      await new Promise(resolve => setTimeout(resolve,100));
    }
    throw new Error('Fixture report timeout: '+lastError+' '+stderr.slice(-1500));
  } finally {
    for (const entry of pending.values()) clearTimeout(entry.timer);
    pending.clear();
    if (socket) socket.close();
    if (child.pid && child.exitCode===null && child.signalCode===null) {
      await new Promise(resolve => {
        let timer = setTimeout(() => {
          if (!child.kill('SIGKILL')) {clearTimeout(timer); resolve(); return;}
          timer = setTimeout(resolve,3000);
        },3000);
        child.once('exit',() => {clearTimeout(timer); resolve();});
        if (!child.kill()) {clearTimeout(timer); resolve();}
      });
    }
  }
}

for (const reduced of [false,true]) {
  test('panel navigation in Chromium'+(reduced?' with reduced motion':''), {timeout:120000}, async () => {
    const layout=await fs.readFile(path.join(app,'resources/views/panel/layout.blade.php'),'utf8');
    assert.match(layout,/asset_ver\('assets\/js\/panel-navigation\.js'\)/);
    const assets=new Map();
    for (const name of ['css/site.css','css/panel.css','js/panel-navigation.js']) {
      assets.set('/assets/'+name,await fs.readFile(path.join(app,'public/assets',name)));
    }
    const cases=reduced
      ? [{locale:'fa',theme:'dark',width:390,reduced:true},
         {locale:'en',theme:'light',width:390,reduced:true}]
      : matrix;
    const server=http.createServer((req,res)=>{
      try {
        const url=new URL(req.url,'http://localhost');
        res.setHeader('Content-Security-Policy',
          "default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "+
          "frame-src 'self'; font-src 'self'; img-src data:; connect-src 'none'; form-action 'none'");
        if(assets.has(url.pathname)) {
          res.setHeader('Content-Type',url.pathname.endsWith('.css')?'text/css':'application/javascript');
          res.end(assets.get(url.pathname)); return;
        }
        if(url.pathname==='/scenario.js') {
          res.setHeader('Content-Type','application/javascript');
          res.end('('+browserScenario.toString()+')();'); return;
        }
        res.setHeader('Content-Type','text/html; charset=utf-8');
        if(url.pathname==='/') {res.end(outer(cases));return;}
        if(url.pathname==='/fixture') {
          res.end(fixture(layout,JSON.parse(url.searchParams.get('case'))));return;
        }
        res.statusCode=404;res.end('');
      } catch(error) {res.statusCode=500;res.end(String(error.message));}
    });
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    const profile=await fs.mkdtemp(path.join(os.tmpdir(),'servernet-panel-nav-'));
    try {
      const raw = await chromiumReport(
        profile, reduced, 'http://127.0.0.1:'+server.address().port
      );
      const report = JSON.parse(raw);
      assert.equal(report.error,null,JSON.stringify(report));
      assert.equal(report.results.length,cases.length);
      for (const result of report.results) {
        assert.equal(result.status,'passed',JSON.stringify(result));
        assert.ok(result.checks.length>=10,JSON.stringify(result));
      }
      console.log(JSON.stringify({reduced,cases:report.results.map(r=>({
        ...r.config,checks:r.checks.length,status:r.status
      }))}));
    } finally {
      await new Promise(resolve=>server.close(resolve));
      assert.equal(path.dirname(profile),os.tmpdir());
      assert.ok(path.basename(profile).startsWith('servernet-panel-nav-'));
      await fs.rm(profile,{recursive:true,force:true});
    }
  });
}
