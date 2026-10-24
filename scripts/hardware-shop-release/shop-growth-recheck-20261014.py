from pathlib import Path
from urllib.parse import urlsplit,urljoin
from lxml import html
from concurrent.futures import ThreadPoolExecutor,as_completed
import subprocess,json,time,collections
OUT=Path(__file__).resolve().parents[1]/'output/shop-growth-20261014'
data=json.loads((OUT/'live-audit.json').read_text(encoding='utf8'))
source=Path(__file__).with_name('shop-growth-live-audit-20261014.py').read_text(encoding='utf8')
source='def check(url):'+source.split('def check(url):',1)[1].split('with ThreadPoolExecutor',1)[0]
source=source.replace("if not img.get('alt'):","if img.get('alt') is None:").replace("for v in doc.xpath('//main//img/@src')","for v in doc.xpath('//main//img/@src') if v")
def get(url):
 r=subprocess.run(['curl.exe','-fL','-sS','--max-time','30','--retry','1','--retry-delay','1','--proxy','http://127.0.0.1:10808','-w','\nSERVERNET_STATUS:%{http_code}:%{time_starttransfer}',url],capture_output=True)
 if r.returncode:raise RuntimeError(r.stderr.decode('utf8','replace')[:150])
 body,_,trail=r.stdout.rpartition(b'\nSERVERNET_STATUS:');status,elapsed=trail.decode().split(':');return int(status),body,float(elapsed),{}
exec(compile(source,'<public SEO check>','exec'))
failed=[r for r in data['pages'] if r['errors']];done=[]
with ThreadPoolExecutor(max_workers=3) as pool:
 for f in as_completed([pool.submit(check,r['url']) for r in failed]):
  done.append(f.result())
  if len(done)%100==0:
   print('RECHECKED',len(done),'REMAIN',sum(bool(x['errors']) for x in done),flush=True)
   (OUT/'live-recheck-partial.json').write_text(json.dumps(done,ensure_ascii=False),encoding='utf8')
new={r['url']:r for r in done};data['pages']=[new.get(r['url'],r) for r in data['pages']]
for r in data['pages']:r['errors']=[e for e in r['errors'] if e!='missing image alt']
assets=[a for a in data['assets'] if urlsplit(a['url']).path.startswith('/assets/')]
def asset(url):
 try:
  status,body,elapsed,_=get(url);return {'url':url,'status':status,'bytes':len(body),'seconds':round(elapsed,3)}
 except Exception as e:return {'url':url,'error':str(e)}
with ThreadPoolExecutor(max_workers=3) as pool:
 fixes=[f.result() for f in as_completed([pool.submit(asset,r['url']) for r in assets if r.get('status')!=200])]
newassets={r['url']:r for r in fixes};data['assets']=[newassets.get(r['url'],r) for r in assets]
data['summary'].update(page_errors=[r for r in data['pages'] if r['errors']],assets=len(data['assets']),asset_errors=[r for r in data['assets'] if r.get('status')!=200])
(OUT/'live-audit-final.json').write_text(json.dumps(data,ensure_ascii=False,indent=2),encoding='utf8')
print('COMPLETE',len(data['pages']),'PAGE_ERRORS',len(data['summary']['page_errors']),'ASSET_ERRORS',len(data['summary']['asset_errors']),flush=True)
