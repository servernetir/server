import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {createHash} from 'node:crypto';
import before from './fixtures/servernet-ir-worker.before.mjs';
import after from '../servernet-ir-worker.mjs';

const additions = new Map([
  ['/bale/hermes/assets/fonts/IRANSans-regular.woff2', ['GET','HEAD']],
  ['/bale/hermes/assets/fonts/IRANSans-medium.woff2', ['GET','HEAD']],
  ['/bale/hermes/assets/fonts/IRANSans-bold.woff2', ['GET','HEAD']],
  ['/bale/hermes/errors', ['GET']],
  ['/bale/hermes/automation', ['GET']],
  ['/bale/hermes/client-errors', ['POST']],
]);
const normalize = source => source.replaceAll('\r\n','\n');
const headersObject = headers => Object.fromEntries(new Headers(headers));
const defaultHeaders = {'Content-Type':'application/json','Set-Cookie':'upstream-private=test; Secure','Cache-Control':'public, max-age=86400','X-Request-Id':'safe-request-123'};

// Every outbound request goes to this stub. No network can be used by the Worker.
async function invoke(worker, options={}) {
  const {url='https://servernet.ir/bale/hermes/errors',method='GET',headers={},body,status=200,responseBody='{"ok":true}',responseHeaders=defaultHeaders,throws=false}=options;
  const calls=[];
  const original=globalThis.fetch;
  globalThis.fetch=async (input,init) => {
    const direct=input instanceof Request;
    const outboundHeaders=direct?input.headers:init.headers;
    const outboundBody=direct?input.body:init.body;
    calls.push({url:direct?input.url:input,method:direct?input.method:init.method,headers:headersObject(outboundHeaders),body:outboundBody?await new Response(outboundBody).text():null,redirect:init?.redirect,cf:init?.cf});
    if(throws)throw new Error('private upstream diagnostic must never escape');
    return new Response(method==='HEAD'||status===204?null:responseBody,{status,headers:responseHeaders});
  };
  try {
    const request=new Request(url,{method,headers,...(body===undefined?{}:{body})});
    const response=await worker.fetch(request);
    return {status:response.status,headers:headersObject(response.headers),body:await response.text(),calls};
  } finally { globalThis.fetch=original; }
}

test('fresh baseline and only six exact allowlist additions are present',()=>{
  const original=fs.readFileSync(new URL('./fixtures/servernet-ir-worker.before.mjs',import.meta.url),'utf8');
  const changed=fs.readFileSync(new URL('../servernet-ir-worker.mjs',import.meta.url),'utf8');
  assert.equal(createHash('sha256').update(original).digest('hex'),'b650382905ed1ea3407d52f6b32b1089024b05e544c3d0edad0ae21d71a8f28a');
  let lines=normalize(changed).split('\n');
  for(const [path,methods]of additions){
    const expected=`        '${path}': [${methods.map(m=>`'${m}'`).join(', ')}],`;
    assert.equal(lines.filter(line=>line===expected).length,1,`exact new entry ${path}`);
    lines=lines.filter(line=>line!==expected);
  }
  assert.equal(lines.join('\n'),normalize(original),'all executable legacy bytes remain identical after normalizing CRLF');
});

for(const [path,methods]of additions)for(const method of methods){
  test(`${method} ${path} proxies only to the fixed cloud origin`,async()=>{
    const query='?probe=1&upstream=https%3A%2F%2Funtrusted.example';
    const body=method==='POST'?JSON.stringify({code:'network_error',operation:'/bale/hermes/chat'}):undefined;
    const options={url:'https://servernet.ir'+path+query,method,body,headers:{Accept:'application/json','Content-Type':'application/json','X-Bale-Init-Data':'signed-fixture','X-Hermes-Session':'session-fixture',Cookie:'private-client-cookie=1',Authorization:'Bearer forbidden-forwarding','X-Unrelated':'discard'}};
    const denied=await invoke(before,options);
    assert.equal(denied.status,404);assert.equal(denied.calls.length,0);
    const result=await invoke(after,options);
    assert.equal(result.status,200);assert.equal(result.calls.length,1);
    const call=result.calls[0];
    assert.equal(call.url,'https://servernet.cloud'+path+query);
    assert.equal(call.method,method);
    assert.deepEqual(call.headers,{accept:'application/json','content-type':'application/json','x-bale-init-data':'signed-fixture','x-hermes-session':'session-fixture'});
    assert.equal(call.body,body??null);
    assert.equal(call.redirect,'manual');
    assert.deepEqual(call.cf,{cacheEverything:false,cacheTtl:0});
    assert.equal(result.headers['cache-control'],'private, no-store');
    assert.equal(result.headers['set-cookie'],undefined);
    assert.equal(result.headers['x-request-id'],'safe-request-123');
  });
}

test('new endpoint method guards reject every unsupported method before fetching',async()=>{
  for(const[path,allowed]of additions)for(const method of ['GET','HEAD','POST','PUT','PATCH','DELETE','OPTIONS']){
    if(allowed.includes(method))continue;
    const result=await invoke(after,{url:'https://servernet.ir'+path,method});
    assert.equal(result.status,405,method+' '+path);
    assert.equal(JSON.parse(result.body||'{}').code,'method_not_allowed');
    assert.equal(result.calls.length,0);
  }
});

test('path and host variants never widen the Hermes proxy',async()=>{
  const cases=[
    'https://servernet.ir/bale/hermes/errors/',
    'https://servernet.ir/bale/hermes/automation/extra',
    'https://servernet.ir/bale/hermes/client-errors-extra',
    'https://servernet.ir/bale/hermes/assets/fonts/IRANSans-regular.woff',
    'https://servernet.ir/bale/hermes/assets/fonts/IRANSans-Regular.woff2',
    'https://servernet.ir/bale/hermes/assets/fonts/IRANSans-regular%2ewoff2',
    'https://servernet.ir/bale/hermes/assets/fonts/other.woff2',
    'https://servernet.ir/bale/hermes-unrelated',
    'https://sub.servernet.ir/bale/hermes/errors',
    'https://servernet.cloud/bale/hermes/errors',
  ];
  for(const url of cases){
    const old=await invoke(before,{url}),current=await invoke(after,{url});
    assert.deepEqual(current,old,url);assert.equal(current.calls.length,0,url);
  }
});

test('65536-byte declared request cap is preserved on new paths',async()=>{
  for(const[path,allowed]of additions){
    const method=allowed.includes('POST')?'POST':'GET';
    const rejected=await invoke(after,{url:'https://servernet.ir'+path,method,headers:{'Content-Length':'65537'}});
    assert.equal(rejected.status,413);assert.equal(rejected.calls.length,0);
    assert.equal(JSON.parse(rejected.body).code,'request_too_large');
    const accepted=await invoke(after,{url:'https://servernet.ir'+path,method,headers:{'Content-Length':'65536'}});
    assert.equal(accepted.status,200);assert.equal(accepted.calls.length,1);
  }
});

test('upstream auth denial remains intact and private without either cookie direction',async()=>{
  const result=await invoke(after,{status:403,responseBody:'{"ok":false,"code":"owner_unauthorized"}',headers:{Cookie:'private=1','X-Hermes-Session':'invalid-fixture'}});
  assert.equal(result.status,403);
  assert.equal(JSON.parse(result.body).code,'owner_unauthorized');
  assert.equal(result.calls[0].headers.cookie,undefined);
  assert.equal(result.headers['set-cookie'],undefined);
  assert.equal(result.headers['cache-control'],'private, no-store');
});

test('upstream redirects are never followed or exposed as HTML redirects',async()=>{
  for(const status of [301,302,303,307,308]){
    const result=await invoke(after,{status,responseBody:'<!DOCTYPE html>secret',responseHeaders:{Location:'https://untrusted.example', 'Content-Type':'text/html','Set-Cookie':'private=1'}});
    assert.equal(result.status,502);assert.equal(result.calls[0].redirect,'manual');
    assert.deepEqual(JSON.parse(result.body),{ok:false,code:'service_unavailable'});
    assert.equal(result.headers.location,undefined);assert.equal(result.headers['set-cookie'],undefined);
  }
});

test('network exceptions produce safe JSON without private diagnostic text',async()=>{
  const result=await invoke(after,{throws:true});
  assert.equal(result.status,502);
  assert.deepEqual(JSON.parse(result.body),{ok:false,code:'service_unavailable'});
  assert.ok(!result.body.includes('private upstream'));
  assert.equal(result.headers['cache-control'],'private, no-store');
});

test('all prior Hermes and unrelated legacy behavior matches the captured Worker',async()=>{
  const cases=[
    ['/bale/hermes','GET'],['/bale/hermes','HEAD'],['/bale/hermes/session','GET'],['/bale/hermes/overview','GET'],['/bale/hermes/state','GET'],
    ['/bale/hermes/chat','POST'],['/bale/hermes/memory','POST'],['/bale/hermes/history/clear','POST'],['/bale/hermes/chat','GET'],['/bale/hermes/session','POST'],
    ['/','GET'],['/?lang=en','GET'],['/?lang=tr','GET'],['/about-us','GET'],['/webdesign','GET'],['/category/sample','GET'],['/tag/sample','GET'],['/author/sample','GET'],
    ['/website-design-in-urmia','GET'],['/طراحی-سایت-در-خوی','GET'],['/urmiyede','GET'],['/unknown-old-page','GET'],['/%ZZ','GET'],
    ['/wp-admin','GET'],['/wp-content/image.png','GET'],['/sms-relay.php','POST'],['/index.php','GET'],
  ];
  for(const[path,method]of cases){
    const options={url:'https://servernet.ir'+path,method,headers:{Cookie:'legacy=1','X-Bale-Init-Data':'fixture','X-Hermes-Session':'session'},...(method==='POST'?{body:'{"test":1}'}:{})};
    assert.deepEqual(await invoke(after,options),await invoke(before,options),method+' '+path);
  }
  for(const port of ['2082','2083','2086','2087','2095','2096']){
    const options={url:'https://servernet.ir:'+port+'/arbitrary',headers:{Cookie:'legacy=1'}};
    assert.deepEqual(await invoke(after,options),await invoke(before,options),'panel port '+port);
  }
});
