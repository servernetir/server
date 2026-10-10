const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const s=fs.readFileSync('website/public/assets/js/site.js','utf8');
for(const mobile of [true,false]){
 const input={value:'example',focus(){}};
 const chips=Array.from({length:9},(_,i)=>({dataset:{tld:i===8?'.dev':'.com'},classList:{add(){},remove(){},contains(){return false;}},addEventListener(n,fn){this[n]=fn;}}));
 const strip={querySelectorAll:()=>chips};
 const document={documentElement:{dir:'rtl'},getElementById:id=>id==='tld-strip'?strip:id==='domain-input'?input:null,querySelector:()=>null,querySelectorAll:()=>[],addEventListener(){}};
 const window={matchMedia:q=>({matches:q.includes('max-width')&&mobile}),addEventListener(){},location:{pathname:'/'}};
 const ctx={document,window,IntersectionObserver:function(){this.observe=()=>{};},requestAnimationFrame:()=>1,setInterval:()=>1,setTimeout:()=>1,clearTimeout(){},localStorage:{getItem:()=>null,setItem(){}},performance:{now:()=>0},URLSearchParams};
 vm.runInNewContext(s,ctx);
 chips[8].click();assert.equal(input.value,'example.dev');
}
console.log('SITE_RUNTIME_OK mobile/desktop: all script scopes execute, 9-chip selector updates domain');
