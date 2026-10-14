"""Bounded public crawl of actual sitemap URLs, with SEO and asset checks."""
from pathlib import Path
from urllib.request import build_opener,ProxyHandler,Request
from urllib.parse import urlsplit,urljoin
from lxml import etree,html
from concurrent.futures import ThreadPoolExecutor,as_completed
import json,time,collections,threading
ROOT=Path(__file__).resolve().parents[1];OUT=ROOT/'output/shop-growth-20261014';thread=threading.local()
def get(url):
 if not hasattr(thread,'opener'):thread.opener=build_opener(ProxyHandler({'http':'http://127.0.0.1:10808','https':'http://127.0.0.1:10808'}))
 t=time.monotonic()
 with thread.opener.open(Request(url,headers={'User-Agent':'ServerNet catalog quality check/1.0'}),timeout=30) as r:return r.status,r.read(),time.monotonic()-t,dict(r.headers)
status,data,_,_=get('https://servernet.cloud/hardware-sitemap.xml');assert status==200
(OUT/'live-hardware-sitemap.xml').write_bytes(data)
xml=etree.fromstring(data);urls=xml.xpath('//*[local-name()="url"]/*[local-name()="loc"]/text()');images=xml.xpath('//*[local-name()="image"]/*[local-name()="loc"]/text()')
assert len(urls)==len(set(urls));assert len(urls)>2000
results=[]
def check(url):
 issue=[]
 try:
  status,body,elapsed,headers=get(url);doc=html.fromstring(body)
  if status!=200:issue.append('status '+str(status))
  if len(doc.xpath('//h1'))!=1:issue.append('H1 count')
  canonical=doc.xpath('//link[@rel="canonical"]/@href')
  if canonical!=[url]:issue.append('canonical '+str(canonical))
  title=' '.join(doc.xpath('//title/text()'));desc=doc.xpath('//meta[@name="description"]/@content')
  if not title or not desc or not desc[0].strip():issue.append('missing title or description')
  if any('noindex' in v.lower() for v in doc.xpath('//meta[@name="robots"]/@content')):issue.append('noindex')
  for node in doc.xpath('//script[@type="application/ld+json"]'):
   try:json.loads(node.text or '')
   except Exception:issue.append('invalid JSON LD')
  imgs=[urljoin(url,v) for v in doc.xpath('//main//img/@src')]
  for img in doc.xpath('//main//img'):
   if not img.get('alt'):issue.append('missing image alt')
  return {'url':url,'status':status,'seconds':round(elapsed,3),'bytes':len(body),'title':title,'images':imgs,'errors':issue}
 except Exception as e:return {'url':url,'errors':[type(e).__name__+': '+str(e)[:200]]}
with ThreadPoolExecutor(max_workers=4) as pool:
 for f in as_completed([pool.submit(check,u) for u in urls]):
  results.append(f.result())
  if len(results)%150==0:print('CHECKED',len(results),'ERRORS',sum(bool(x['errors']) for x in results),flush=True)
failed=[x for x in results if x['errors']]
if failed:
 print('RETRY transient',len(failed),flush=True)
 for row in failed:
  if all('HTTPError' in s or 'URLError' in s or 'TimeoutError' in s for s in row['errors']):
   new=check(row['url']);results[results.index(row)]=new
allimages=set(images)
for r in results:allimages.update(r.get('images',[]))
assets=[]
def asset(u):
 try:
  status,body,elapsed,headers=get(u);return {'url':u,'status':status,'bytes':len(body),'seconds':round(elapsed,3)}
 except Exception as e:return {'url':u,'error':type(e).__name__}
with ThreadPoolExecutor(max_workers=4) as pool:assets=[f.result() for f in as_completed([pool.submit(asset,u) for u in sorted(allimages) if urlsplit(u).netloc=='servernet.cloud'])]
seen={};duplicates=[]
for r in results:
 title=r.get('title');loc='/'+('/en/' if '/en/' in r['url'] else '/tr/' if '/tr/' in r['url'] else 'fa/')
 if title:
  if (loc,title) in seen:duplicates.append([seen[loc,title],r['url']])
  seen[loc,title]=r['url']
summary={'pages':len(results),'page_errors':[r for r in results if r['errors']],'assets':len(assets),'asset_errors':[r for r in assets if r.get('status')!=200],'duplicate_titles':duplicates,'sitemap_images':len(images)}
(OUT/'live-audit.json').write_text(json.dumps({'summary':summary,'pages':results,'assets':assets},ensure_ascii=False,indent=2),encoding='utf8')
print(json.dumps(summary,ensure_ascii=False),flush=True)
