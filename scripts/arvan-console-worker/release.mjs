// Fixed-target release helper: never modifies DNS, routes or other Workers.
import fs from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {spawn} from 'node:child_process';
const [mode, dir] = process.argv.slice(2);
if (!['inspect','deploy','verify'].includes(mode) || !dir) throw Error('Usage: release.mjs inspect|deploy|verify private-directory');
const token = (await fs.readFile(dir+'/token','utf8')).trim();
if(mode==='deploy') {
 try { await fs.access(dir+'/receipt.json'); throw Error('Already deployed; inspect receipt instead'); }
 catch(error) { if(error.code!=='ENOENT') throw error; }
}
const base='https://api.cloudflare.com/client/v4/accounts/d2eb08d2ffac5e355636c821dece2dbd/workers/scripts/servernet-vnc';
const hash=x=>createHash('sha256').update(x).digest('hex');
function moduleSource(raw) {
 const normalized=raw.replaceAll('\r\n','\n');
 if(!normalized.startsWith('--')) return normalized;
 if((normalized.match(/Content-Disposition:/g)||[]).length!==1) throw Error('Unexpected multipart modules');
 return normalized.slice(normalized.indexOf('\n\n')+2,normalized.lastIndexOf('\n--')).trimEnd();
}
async function api(suffix, options={}) {
 // Optional existing system proxy; credential travels on stdin, never argv/logs.
 let r;
 if(process.env.SN_RELEASE_PROXY) {
  const request=new Request(base+suffix,options);
  const args=['--silent','--show-error','--max-time','30','--proxy',process.env.SN_RELEASE_PROXY,'--config','-','--request',request.method,'--write-out','\n%{http_code}',base+suffix];
  if(options.body) {
   await fs.writeFile(dir+'/upload-body',Buffer.from(await request.arrayBuffer()),{mode:0o600});
   args.push('--header','Content-Type: '+request.headers.get('content-type'),'--data-binary','@'+dir+'/upload-body');
  }
  const raw=await new Promise((resolve,reject)=>{
   const child=spawn('curl.exe',args,{windowsHide:true}); let out='';
   child.stdout.on('data',x=>out+=x); child.stderr.resume();
   child.on('error',reject); child.on('close',code=>code===0?resolve(out):reject(Error('curl failed: '+code)));
   child.stdin.end('header = "Authorization: Bearer '+token+'"\n');
  });
  const i=raw.lastIndexOf('\n'); r=new Response(raw.slice(0,i),{status:Number(raw.slice(i+1))});
 } else r=await fetch(base+suffix,{...options,headers:{Authorization:'Bearer '+token,...options.headers},signal:AbortSignal.timeout(30000)});
 if(!r.ok) throw Error('Cloudflare HTTP '+r.status+' on '+suffix);
 return r;
}
const settings=(await (await api('/settings')).json()).result;
const source=await (await api('')).text();
if(mode==='verify') {
 const deployments=(await (await api('/deployments')).json()).result;
 const latest=deployments.deployments?.[0];
 console.log(JSON.stringify({deployment:latest&&{id:latest.id,versions:latest.versions,created_on:latest.created_on},bindings:settings.bindings.map(b=>({name:b.name,type:b.type})),sourceHash:hash(moduleSource(source))}));
} else if(mode==='inspect') {
 await fs.writeFile(dir+'/settings-before.json',JSON.stringify(settings),{flag:'wx',mode:0o600});
 await fs.writeFile(dir+'/source-before.txt',source,{flag:'wx',mode:0o600});
 console.log(JSON.stringify({sourceHash:hash(source),settingsKeys:Object.keys(settings),bindings:settings.bindings.map(b=>({name:b.name,type:b.type})),compatibility_date:settings.compatibility_date}));
} else {
 const oldSettings=JSON.parse(await fs.readFile(dir+'/settings-before.json','utf8'));
 if(JSON.stringify(oldSettings)!==JSON.stringify(settings) || hash(moduleSource(source))!==hash(moduleSource(await fs.readFile(dir+'/source-before.txt','utf8')))) throw Error('Concurrent Worker change');
 const code=await fs.readFile(new URL('./worker.mjs',import.meta.url),'utf8');
 const metadata={main_module:'worker.mjs',compatibility_date:settings.compatibility_date,keep_bindings:[...new Set(settings.bindings.map(b=>b.type))]};
 for(const k of ['compatibility_flags','observability','logpush','usage_model','limits','tail_consumers','tags']) if(settings[k]!==undefined) metadata[k]=settings[k];
 if(settings.placement?.mode) metadata.placement=settings.placement;
 const form=new FormData();
 form.set('metadata',new Blob([JSON.stringify(metadata)],{type:'application/json'}));
 form.set('worker.mjs',new Blob([code],{type:'application/javascript+module'}),'worker.mjs');
 const result=await (await api('',{method:'PUT',body:form})).json();
 if(!result.success) throw Error('Worker upload not successful');
 const after=(await (await api('/settings')).json()).result;
 const deployed=await (await api('')).text();
 const equalBindings=JSON.stringify(settings.bindings)===JSON.stringify(after.bindings);
 const receipt={utc:new Date().toISOString(),version:result.result?.version_id,codeHash:hash(code),bindingsPreserved:equalBindings,sourceContainsCode:moduleSource(deployed)===moduleSource(code).trimEnd()};
 await fs.writeFile(dir+'/receipt.json',JSON.stringify(receipt,null,2),{flag:'wx'});
 console.log(JSON.stringify(receipt));
 if(!equalBindings || !receipt.sourceContainsCode) throw Error('Post-release comparison failed');
}
