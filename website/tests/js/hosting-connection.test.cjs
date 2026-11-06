const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/js/hosting-connection.js', 'utf8');
function fixture(fetcher, options = {}) {
  const nodes = Object.fromEntries(['status', 'time', 'observed', 'button', 'copy'].map(k => [k, {textContent: '', disabled: false, parentElement: {hidden: true}, listeners: {}, addEventListener(event, fn) { this.listeners[event] = fn; }}]));
  nodes.copy.dataset = {copyNs: 'ns1.example.test'};
  const box = {dataset: {url: options.url || '/account/services/1/domain-connection', messages: JSON.stringify({checking:'Checking', connected:'Connected', propagating:'Pending', needs_setup:'Setup', unknown:'Unknown', unconfigured:'Review', checked:'Checked', copy:'Copy', copied:'Copied', copy_failed:'Copy manually'})}, querySelector(s) {return nodes[({'[data-connection-status]':'status','[data-connection-time]':'time','[data-connection-observed]':'observed','[data-check-connection]':'button'})[s]];}, querySelectorAll() {return [nodes.copy];}};
  let observe;
  const document = {readyState: 'complete', documentElement:{lang:'en'}, querySelectorAll() {return [box];}};
  const context = {document, location:{href:'https://panel.example.test/account',origin:'https://panel.example.test'}, URL, Date, AbortController, fetch:fetcher, navigator:{clipboard:{writeText:options.copy || (async()=>{})}}, setTimeout:()=>1, clearTimeout:()=>{}, window:{}};
  if(options.lazy) {context.window.IntersectionObserver = true; context.IntersectionObserver = class {constructor(fn) {observe=fn;} observe() {} disconnect() {}};}
  vm.runInNewContext(source,context);
  return {box,nodes,observe,check:()=>nodes.button.listeners.click()};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const response=d=>({ok:true,json:async()=>d});
test('successful check shows DNS evidence and enables refresh', async()=>{const f=fixture(async()=>response({state:'connected',observed_ns:['ns1.example.test'],checked_at:'2026-11-02T12:00:00Z'}));await tick(); assert.equal(f.box.dataset.state,'connected');assert.equal(f.nodes.observed.textContent,'ns1.example.test');assert.equal(f.nodes.observed.parentElement.hidden,false);assert.equal(f.nodes.button.disabled,false);});
test('unrecognized response cannot produce a green badge', async()=>{const f=fixture(async()=>response({state:'anything'}));await tick();assert.equal(f.box.dataset.state,'unknown');});
test('network and expired-session errors show unknown and allow retry', async()=>{for (const fetcher of [async()=>{throw Error('network');},async()=>({ok:false,status:401})]){const f=fixture(fetcher);await tick();assert.equal(f.box.dataset.state,'unknown');assert.equal(f.nodes.button.disabled,false);}});
test('in-flight duplicate clicks do not send duplicate queries', async()=>{let done,calls=0;const f=fixture(()=>{calls++;return new Promise(r=>done=r);});await f.check();assert.equal(calls,1);done(response({state:'needs_setup'}));await tick();assert.equal(f.box.dataset.state,'needs_setup');});
test('offscreen cards wait until visible',async()=>{let calls=0;const f=fixture(async()=>{calls++;return response({state:'propagating'});},{lazy:true});assert.equal(calls,0);f.observe([{isIntersecting:true}]);await tick();assert.equal(calls,1);});
test('a foreign origin is never requested',async()=>{let calls=0;const f=fixture(async()=>{calls++;},{url:'https://other.example.test/check'});await tick();assert.equal(calls,0);assert.equal(f.box.dataset.state,'unknown');});
test('copy reports success only after clipboard succeeds',async()=>{const f=fixture(async()=>response({state:'unknown'}),{copy:async()=>{throw Error('permission');}});await f.nodes.copy.listeners.click();assert.equal(f.nodes.copy.textContent,'Copy manually');});
