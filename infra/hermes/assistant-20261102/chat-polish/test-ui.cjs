'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict'),{webcrypto}=require('node:crypto');
const view=fs.readFileSync(path.join(__dirname,'hermes.blade.php'),'utf8');
const script=view.match(/<script>\s*\(\(\)=>\{([\s\S]*?)<\/script>/)[0].replace(/^<script>|<\/script>$/g,'');
const requiredIds=['main','view-chat','view-memory','view-status','assistantMenu','backToChat','message','send','gate'];
for(const id of requiredIds)assert.equal((view.match(new RegExp('id="'+id+'"','g'))||[]).length,1,id+' must occur once');
assert(!view.includes('id="showMemory"'));assert(!view.includes('id="composerMemoryCount"'));
assert(!view.includes('class="sidebar"'));assert(!view.includes('id="learn"'));
const baseline=fs.readFileSync(path.join(__dirname,'../araz-name/hermes.blade.php'),'utf8'),css=view.match(/<style>([\s\S]*?)<\/style>/)[1];
const oldFocus='.composer:focus-within{border-color:#70c6df88;box-shadow:0 0 0 3px #5db1d40c}',newFocus='.composer:focus-within{border-color:#70c6df88;box-shadow:none}.composer textarea:focus,.composer textarea:focus-visible{outline:none;box-shadow:none}';
assert(view.includes(newFocus),'composer textarea removes the inner outline for mouse and keyboard focus');
assert.equal(view.replace(newFocus,oldFocus),baseline,'only the composer focus CSS may change');
assert(css.includes(':focus-visible{outline:2px solid var(--accent);outline-offset:3px}'),'button/select keyboard focus cue remains');
// Specificity resolves the existing universal pseudo-class above textarea's
// base reset; the new composer-only selectors win without affecting controls.
const outlineRules=[];
for(const match of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)){const property=match[2].match(/(?:^|;)outline:([^;]+)/);if(property)for(const selector of match[1].split(','))outlineRules.push({selector:selector.trim(),outline:property[1]})}
function outlineFor(selectors){const matching=outlineRules.filter(rule=>selectors.includes(rule.selector)).map((rule,order)=>({...rule,order,specificity:[(rule.selector.match(/#/g)||[]).length,(rule.selector.match(/[.:][A-Za-z_-]/g)||[]).length,(rule.selector.match(/\btextarea\b/g)||[]).length]}));matching.sort((a,b)=>a.specificity[0]-b.specificity[0]||a.specificity[1]-b.specificity[1]||a.specificity[2]-b.specificity[2]||a.order-b.order);return matching.at(-1)?.outline}
assert.equal(outlineFor(['textarea',':focus-visible','.composer textarea:focus','.composer textarea:focus-visible']),'none','keyboard-focused composer textarea has no inner outline');
assert.equal(outlineFor(['textarea','.composer textarea:focus']),'none','pointer-focused composer textarea has no inner outline');
assert.equal(outlineFor([':focus-visible']),'2px solid var(--accent)','keyboard-focused buttons/selects retain their outline');
assert(css.includes('.composer:focus-within{border-color:#70c6df88;box-shadow:none}'),'container retains one border focus cue without a glow');
assert.equal((view.match(/data-i18n="thinking"/g)||[]).length,1,'one static processing indicator');
assert(view.includes("<title>{{ $locale === 'fa' ? 'آراز' : 'Araz' }} · ServerNet</title>"));
assert(view.includes("'X-Hermes-Session'"));assert(view.includes("'/bale/hermes/'"));assert(view.includes("hermes-miniapp-session"));
const noop=()=>{};
class ClassList {constructor(v=''){this.set=new Set(v.split(/\s+/).filter(Boolean))}add(x){this.set.add(x)}remove(x){this.set.delete(x)}contains(x){return this.set.has(x)}toggle(x,force){const on=force===undefined?!this.set.has(x):force;on?this.set.add(x):this.set.delete(x);return on}}
class Element {
 constructor(tag='div',attrs={}){this.tagName=tag.toUpperCase();this.attrs={...attrs};this.children=[];this.dataset={};this.classList=new ClassList(attrs.class);this.style={setProperty:(k,v)=>{this.style[k]=v}};this.listeners={};this.open=false;this.value='';this.textContent='';this.disabled=false;this.scrollHeight=55;this.clientHeight=500;this.scrollTop=0;for(const [k,v]of Object.entries(attrs))if(k.startsWith('data-'))this.dataset[k.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=v}
 addEventListener(t,fn){(this.listeners[t]??=[]).push(fn)}dispatchEvent(e){for(const fn of this.listeners[e.type]||[])fn(e)}setAttribute(k,v){this.attrs[k]=v}append(...items){this.children.push(...items)}replaceChildren(...items){this.children=items}focus(){this.focused=true}scrollTo(v){this.scrollTop=v.top}showModal(){this.open=true}close(){this.open=false;this.dispatchEvent({type:'close'})}getBoundingClientRect(){return {left:10,right:400,top:10,bottom:600}}requestSubmit(){return this.onsubmit({preventDefault:noop})}
}
function attributes(tag){return Object.fromEntries([...tag.matchAll(/([\w-]+)="([^"]*)"/g)].map(m=>[m[1],m[2]]))}
async function harness(lang,{authenticated=true,chatFails=false,messages=[]}={}){
 const elements=[],ids={};for(const m of view.matchAll(/<(\w+)([^>]*?)>/g)){const attrs=attributes(m[0]),e=new Element(m[1],attrs);elements.push(e);if(attrs.id)ids[attrs.id]=e}
 // The existing operations partial is independently deployed and not rewritten here.
 ids['view-operations']=new Element('section',{class:'hidden'});
 const calls=[],timers=[],documentListeners={};let currentState={messages,memories:[{id:'note',text:'preference',updated_at:1}]};
 const document={getElementById:id=>{assert(ids[id],'missing element '+id);return ids[id]},querySelectorAll:q=>elements.filter(e=>q==='[data-i18n]'?'i18n'in e.dataset:q==='[data-label]'?'label'in e.dataset:q==='[data-view]'?'view'in e.dataset:q==='[data-prompt]'?'prompt'in e.dataset:false),createElement:t=>new Element(t),addEventListener:(t,fn)=>{(documentListeners[t]??=[]).push(fn)},documentElement:new Element('html'),body:new Element('body'),fonts:{ready:Promise.resolve()},visibilityState:'visible'};
 const ctx={document,window:{Bale:authenticated?{WebApp:{initData:'synthetic-auth-fixture',colorScheme:'dark',ready:noop}}:undefined,innerHeight:760,visualViewport:{height:760,addEventListener:noop},matchMedia:()=>({matches:true}),addEventListener:noop},navigator:{clipboard:{writeText:async()=>{}}},sessionStorage:{getItem:()=>null,setItem:noop,removeItem:noop},location:{href:'https://example.invalid/bale/hermes',assign:noop},crypto:webcrypto,TextEncoder,AbortController,Intl,Date,Promise,URL,JSON,Event:class{constructor(type){this.type=type}},ResizeObserver:class{observe(){}},requestAnimationFrame:fn=>{fn();return 1},cancelAnimationFrame:noop,setTimeout:(fn,delay)=>{timers.push({fn,delay});return timers.length},clearTimeout:noop,console,fetch:async(url,options)=>{calls.push({url,options});if(url.endsWith('/chat')){if(chatFails)throw new Error('synthetic transport failure');const body=JSON.parse(options.body);currentState={...currentState,messages:[{id:body.id,role:'user',text:body.text,at:Date.now()/1000,status:'pending'}]};return response({ok:true,state:currentState})}if(url.endsWith('/memory')){const body=JSON.parse(options.body);currentState={...currentState,memories:[...currentState.memories,{id:'new-note',text:body.text,updated_at:1}]};return response({ok:true,state:currentState})}return response({ok:true,state:currentState,session:{token:'synthetic-only',expires_at:Date.now()/1000+1000}})}};
 function response(data){return {ok:true,status:200,headers:{get:()=> 'application/json'},json:async()=>data}}
 const labels={unknown:'unknown',private:'private',connecting:'connecting',send:'send',placeholder:'message',memory_placeholder:'note',theme:'theme',new_chat:'new chat',memory_saved:'saved'};
 const rendered=script.replace('@json($labels)',JSON.stringify(labels)).replace('@json($locale)',JSON.stringify(lang)).replace("@include('bale.hermes-operations-script')",'function loadOperations(){}').replace('if(app?.ready)app.ready();start();','if(app?.ready)app.ready();window.__startupForTest=start();');
 // Await the actual authentication/start promise; event-loop tick counts race WebCrypto.
 vm.runInNewContext(rendered,ctx,{timeout:1000});assert(ctx.window.__startupForTest,'startup promise was not captured');await ctx.window.__startupForTest;
 return {ids,elements,calls,timers,document,documentListeners,ctx};
}
(async()=>{
 let cases=0;
 for(const lang of ['fa','en','tr']){
  const h=await harness(lang),{$:unused}=h;
  assert(!h.ids.main.classList.contains('hidden'));assert(!h.ids['view-chat'].classList.contains('hidden'));assert(h.ids['view-memory'].classList.contains('hidden'));assert(h.ids['backToChat'].classList.contains('hidden'));cases++;
  assert(h.ids.openMenu.attrs['aria-label']);assert(h.ids.message.attrs['aria-busy']==='false');assert.equal(h.document.documentElement.style['--app-height'],'760px');cases++;
  h.ids.openMenu.onclick();assert(h.ids.assistantMenu.open);assert.equal(h.ids.openMenu.attrs['aria-expanded'],'true');
  h.elements.find(e=>e.dataset.view==='memory').onclick();assert(!h.ids.assistantMenu.open);assert(!h.ids['view-memory'].classList.contains('hidden'));assert(h.ids.composerArea.classList.contains('hidden'));assert(!h.ids.backToChat.classList.contains('hidden'));cases++;
  for(const f of h.documentListeners.keydown)f({key:'Escape',preventDefault:noop});assert(!h.ids['view-chat'].classList.contains('hidden'));assert(h.ids.backToChat.classList.contains('hidden'));cases++;
  h.ids.message.value='Synthetic status request';await h.ids.chatForm.onsubmit({preventDefault:noop});assert.equal(h.calls.filter(c=>c.url.endsWith('/chat')).length,1);assert(h.ids.send.disabled);assert(!h.ids.typing.classList.contains('hidden'));assert.equal(h.ids.message.value,'');
  const thinking=h.elements.find(e=>e.dataset.i18n==='thinking').textContent;assert.notEqual(h.ids.connection.textContent,thinking);assert.equal(h.ids.messages.children[0].children.filter(e=>e.className==='delivery').length,0,'fresh pending bubble must not duplicate processing text');cases++;
  await h.ids.chatForm.onsubmit({preventDefault:noop});assert.equal(h.calls.filter(c=>c.url.endsWith('/chat')).length,1);cases++;
 }
 for(const lang of ['fa','en','tr']){const named=await harness(lang,{messages:[{id:'synthetic-answer',role:'assistant',text:'Synthetic answer',at:1,status:'done'}]}),brand=lang==='fa'?'آراز':'Araz';assert.equal(named.elements.find(e=>e.dataset.i18n==='brand').textContent,brand);assert(named.ids.menuTitle.textContent.includes(brand));assert(named.ids.messages.children[0].children[0].textContent.startsWith(brand+' · '));cases++}
 const unauth=await harness('fa',{authenticated:false});assert(unauth.ids.main.classList.contains('hidden'));assert.equal(unauth.calls.length,0);cases++;
 const failed=await harness('fa',{chatFails:true});failed.ids.message.value='Synthetic no replay';await failed.ids.chatForm.onsubmit({preventDefault:noop});assert.equal(failed.calls.filter(c=>c.url.endsWith('/chat')).length,1);assert(!failed.ids.notice.classList.contains('hidden'));await failed.ids.retry.onclick();assert.equal(failed.calls.filter(c=>c.url.endsWith('/chat')).length,1);assert(failed.calls.some(c=>c.url.endsWith('/state')));cases++;
 const learn=await harness('en');learn.ids.message.value='remember: keep reports concise';await learn.ids.chatForm.onsubmit({preventDefault:noop});assert(learn.calls.some(c=>c.url.endsWith('/memory')));assert(!learn.ids['view-chat'].classList.contains('hidden'));assert(learn.ids['view-memory'].classList.contains('hidden'));cases++;
 console.log(`${cases} UI behavior cases passed (fa/en/tr, menu/back, one processing indicator, Araz branding, composer focus, pending guard, no replay, notes, unauthenticated gate); no network calls.`);
})().catch(e=>{console.error(e);process.exit(1)});
