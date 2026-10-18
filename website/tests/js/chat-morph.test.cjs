const {test, after} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const {execFileSync} = require('node:child_process');
const {JSDOM} = require('jsdom');
const root = path.resolve(__dirname, '../../..');
const fixtures = fs.mkdtempSync(path.join(os.tmpdir(), 'servernet-chat-test-'));
for (const locale of ['fa','en','tr']) execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname,'fixtures/render-chat.php'),locale,fixtures]);
after(() => fs.rmSync(fixtures, {recursive:true,force:true}));
const code = fs.readFileSync(path.join(root, 'website/public/assets/js/chat-morph.js'), 'utf8');
const legacy = fs.readFileSync(path.join(root, 'website/public/assets/js/site.js'), 'utf8').split('/* ---- AI CHAT WIDGET ---- */')[1].split('})();')[0];
const tick = () => new Promise(resolve => setImmediate(resolve));
function setup(locale = 'fa', fetcher) {
  const dom = new JSDOM(fs.readFileSync(path.join(fixtures, `partial-${locale}.html`), 'utf8'), {url:'https://servernet.cloud/',runScripts:'outside-only',pretendToBeVisual:true});
  const w = dom.window;
  w.matchMedia = () => ({matches:true});
  w.fetch = fetcher;
  w.eval(code);
  w.eval('(function(){' + legacy + '})();');
  return {dom, w, doc:w.document};
}
function send(w, text) {
  w.document.getElementById('chat-text').value = text;
  w.document.getElementById('chat-form').dispatchEvent(new w.Event('submit',{cancelable:true}));
}
test('one request, pending state, real reply and safe actions; legacy handler does not duplicate', async () => {
  let resolve, calls = [];
  const {dom,w,doc} = setup('fa', (...args) => { calls.push(args); return new Promise(r => resolve = r); });
  try {
    doc.getElementById('chat-fab').click();
    send(w,'سلام'); send(w,'دوباره');
    assert.equal(calls.length,1);
    assert.equal(doc.getElementById('chat-panel').dataset.phase,'thinking');
    assert.equal(doc.getElementById('chat-text').disabled,true);
    resolve({ok:true,json:async()=>({reply:'پاسخ واقعی <script>alert(1)</script>',actions:[{url:'javascript:alert(1)',label:'bad'},{url:null,label:'null'},{url:'/vps',label:'VPS'}]})});
    await tick(); await tick();
    assert.equal(doc.querySelectorAll('.chat-msg.bot').length,1);
    assert.equal(doc.querySelector('.chat-msg.bot').textContent,'پاسخ واقعی <script>alert(1)</script>');
    assert.equal(doc.querySelector('.chat-msg.bot script'),null);
    assert.equal(doc.querySelectorAll('.chat-actions a').length,1);
    assert.equal(doc.getElementById('chat-panel').dataset.phase,'idle');
    assert.equal(doc.getElementById('chat-text').disabled,false);
  } finally {dom.window.close();}
});
test('failure offers retry with the same session and original message', async () => {
  let calls = [];
  const {dom,w,doc} = setup('en', async (_url, options) => {
    calls.push(JSON.parse(options.body));
    return calls.length === 1 ? {ok:false,status:429} : {ok:true,json:async()=>({reply:'Recovered',actions:[]})};
  });
  try {
    send(w,'Hello'); await tick(); await tick();
    assert.ok(doc.querySelector('.chat-retry'));
    doc.querySelector('.chat-retry').click(); await tick(); await tick();
    assert.equal(calls.length,2); assert.deepEqual(calls[0],calls[1]);
    assert.equal(doc.querySelectorAll('.chat-msg.user').length,1);
    assert.equal(doc.querySelector('.chat-msg.bot').textContent,'Recovered');
  } finally {dom.window.close();}
});
test('closing while pending never steals focus when the response arrives; reopening keeps history', async () => {
  let resolve;
  const {dom,w,doc} = setup('en', () => new Promise(r => resolve=r));
  try {
    doc.getElementById('chat-fab').click(); send(w,'Hello');
    doc.getElementById('chat-close').click();
    resolve({ok:true,json:async()=>({reply:'Here',actions:[]})}); await tick();await tick();
    assert.equal(doc.activeElement.id,'chat-fab');
    assert.ok(doc.getElementById('chat-panel').hasAttribute('inert'));
    doc.getElementById('chat-fab').click();
    assert.equal(doc.querySelectorAll('.chat-msg').length,2);
    assert.equal(doc.getElementById('chat-panel').hasAttribute('inert'),false);
  } finally {dom.window.close();}
});
test('all locales render with correct direction and no untranslated keys; messages use automatic direction', async () => {
  for (const locale of ['fa','en','tr']) {
    const {dom,w,doc} = setup(locale,async()=>({ok:true,json:async()=>({reply:'English response',actions:[]})}));
    try {
      assert.equal(doc.getElementById('chat-panel').dir,locale==='fa'?'rtl':'ltr');
      assert.doesNotMatch(doc.body.textContent,/chat\.[a-z_]+/);
      send(w,'English message');await tick();await tick();
      assert.equal(doc.querySelector('.chat-msg').dir,'auto');
    } finally {dom.window.close();}
  }
});
test('empty response is an error; suggestions submit only one real prompt', async () => {
  let calls=0;
  const {dom,doc} = setup('fa',async()=>{calls++;return {ok:true,json:async()=>({reply:' ',actions:[]})};});
  try {
    doc.querySelector('[data-chat-prompt]').click();await tick();await tick();
    assert.equal(calls,1);assert.ok(doc.querySelector('.chat-msg.error'));assert.ok(doc.querySelector('.chat-retry'));
  } finally {dom.window.close();}
});
