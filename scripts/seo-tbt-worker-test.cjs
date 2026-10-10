const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
let source=fs.readFileSync('website/resources/views/partials/gtm-head.blade.php','utf8');
source=source.slice(source.indexOf('(function(w,d,id)'),source.indexOf('</script>'))
 .replace(/  @auth\('customer'\)[\s\S]*?  @endauth/,'')
 .replace(/  @foreach\([\s\S]*?  @endforeach/,'').replace('@json($gtmId)','"GTM-TEST"');
const bootstrap=fs.readFileSync('website/public/assets/js/snet-analytics-worker-0.15.0-snet3/partytown.js','utf8');
(async()=>{
 for(const mode of ['gpu','hourly-en','hourly-tr','desktop','checkout','account','other-product','unsupported','boot-error','library-fallback']){
  const scripts=[],idle=[],listeners={};
  const path=mode==='hourly-en'?'/en/vps/hourly':mode==='hourly-tr'?'/tr/vps/hourly':mode==='checkout'?'/order/python-1':mode==='account'?'/account':mode==='other-product'?'/hosting/python':'/gpu';
  const d={readyState:'complete',getElementById:id=>scripts.find(s=>s.id===id),
   createElement(tag){return {nodeName:tag.toUpperCase(),type:'',remove(){const i=scripts.indexOf(this);if(i>=0)scripts.splice(i,1);}};},
   head:{appendChild:s=>scripts.push(s)},querySelectorAll:()=>scripts.filter(s=>s.type==='text/partytown'),addEventListener(){}};
  const w={location:{pathname:path},isSecureContext:true,Worker:mode==='unsupported'?undefined:function(){},navigator:{serviceWorker:{register:()=>Promise.reject(new Error('simulated worker registration failure'))}},
   matchMedia:()=>({matches:mode!=='desktop'}),dataLayer:[],addEventListener:(n,cb)=>listeners[n]=cb,removeEventListener:n=>delete listeners[n],requestIdleCallback:cb=>idle.push(cb)};
  const ctx={window:w,document:d,Date,String,Number,encodeURIComponent};
  vm.runInNewContext(source,ctx);
  w.ServerNetAnalytics.trackFunnel('product_page_view',{sku:'gpu'});
  w.ServerNetAnalytics.beginCheckout({item_id:'test-sku',price:19500},'hourly','IRT');
  const queued=w.dataLayer;assert(queued.some(e=>e.event==='begin_checkout'&&e.ecommerce.currency==='IRR'&&e.ecommerce.value===195000));
  for(const cb of idle)cb();
  const expectedWorker=['gpu','hourly-en','hourly-tr','boot-error','library-fallback'].includes(mode);
  assert.equal(scripts.find(s=>s.id==='snet-gtm').type,expectedWorker?'text/partytown':'');
  if(mode==='boot-error')scripts.find(s=>s.id==='snet-analytics-worker').onerror();
  if(mode==='library-fallback'){
   const bootstrapContext={...ctx,navigator:w.navigator,top:w,console:{error(){}},setTimeout:()=>1,clearTimeout(){},MutationObserver:function(){this.observe=()=>{};}};
   vm.runInNewContext(bootstrap,bootstrapContext);
   await new Promise(resolve=>setImmediate(resolve));
   assert(scripts.some(s=>s.type===''&&s.src?.includes('googletagmanager.com')));
  }
  if(mode==='boot-error'||mode==='library-fallback'){
   assert.equal(w.dataLayer,queued);assert(queued.some(e=>e.event==='begin_checkout'));
   w.ServerNetAnalytics.track('configure_product',{cpu_cores:8});assert.equal(queued.at(-1).event,'configure_product');
  }
 }
 console.log('TBT_WORKER_TEST_OK: 10 paths; queued checkout/IRR; desktop/account/order normal; asset and actual library fallback retain queue');
})().catch(e=>{console.error(e);process.exitCode=1;});
