"""Read public pages selected from the live sitemap, including visible copy and JSON-LD."""
from concurrent.futures import ThreadPoolExecutor,as_completed
from pathlib import Path
from urllib.request import Request,urlopen
from urllib.parse import urlparse
from html.parser import HTMLParser
import json,re,xml.etree.ElementTree as ET

ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'output/refund-policy-20261018';OUT.mkdir(parents=True,exist_ok=True)
class Text(HTMLParser):
    def __init__(self):super().__init__();self.parts=[];self.skip=0
    def handle_starttag(self,t,a):
        if t in ('style','script','noscript'):self.skip+=1
    def handle_endtag(self,t):
        if t in ('style','script','noscript'):self.skip=max(0,self.skip-1)
        if t in ('p','li','h1','h2','h3','div','section'):self.parts.append('\n')
    def handle_data(self,d):
        if not self.skip:self.parts.append(d)
def fetch(url):
    with urlopen(Request(url,headers={'User-Agent':'ServerNet-Policy-Audit/20261018'}),timeout=30) as r:
        return r.status,r.geturl(),r.read().decode('utf-8')
status,_,xml=fetch('https://servernet.cloud/sitemap.xml')
tree=ET.fromstring(xml)
urls=[e.text for e in tree.iter() if e.tag.endswith('}loc') or e.tag=='loc']
relevant=re.compile(r'^/(?:en/|tr/)?(?:vps/|cloud/|dedicated/|servers/|order/|terms$|sla$|hosting/|reseller/)')
urls=sorted(set(u for u in urls if urlparse(u).netloc=='servernet.cloud' and relevant.search(urlparse(u).path)))
for suffix in ('terms','en/terms','tr/terms','vps/hourly','en/vps/hourly','tr/vps/hourly'):
    urls.append('https://servernet.cloud/'+suffix)
urls=sorted(set(urls))
promise=re.compile(r'ضمانت[^\n.؛]{0,45}(?:بازگشت|۱۴|14)|14[- ]day[^\n.]{0,45}(?:guarantee|refund)|money[- ]back guarantee|14 gün[^\n.]{0,45}(?:garanti|iade)|para iade garanti',re.I)
qualified=re.compile(r'ایران|Iran|İran|non.refund|no.*refund|خارج.*شمول|مشمول.*نیست|kapsam dış|iade edilmez',re.I)
def audit(url):
    try:
        status,final,html=fetch(url);p=Text();p.feed(html)
        text=re.sub(r'[ \t\r\f]+',' ',''.join(p.parts))
        sentences=[s.strip() for s in text.splitlines() if s.strip()]
        hits=[]
        for i,s in enumerate(sentences):
            if promise.search(s):
                context=' '.join(sentences[max(0,i-1):i+2])
                hits.append({'text':s[:500],'qualified':bool(qualified.search(context))})
        # Product schema must not advertise a finite return window on server orders.
        schemas=[]
        for source in re.findall(r'<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>',html,re.S|re.I):
            if 'MerchantReturnFiniteReturnWindow' in source:schemas.append(source[:600])
        path=urlparse(url).path
        serverpage=bool(re.search(r'/(vps|cloud|dedicated|servers)/',path))
        return {'url':url,'status':status,'final_url':final,'promise_hits':hits,
                'unqualified':sum(not h['qualified'] for h in hits),
                'server_return_schema':bool(serverpage and schemas),'return_schema_count':len(schemas),'bytes':len(html)}
    except Exception as e:return {'url':url,'error':type(e).__name__+': '+str(e)[:180]}
results=[]
with ThreadPoolExecutor(max_workers=4) as pool:
    jobs={pool.submit(audit,u):u for u in urls}
    for n,job in enumerate(as_completed(jobs),1):
        results.append(job.result())
        if n%40==0:print('AUDITED',n,'/',len(urls),flush=True)
results.sort(key=lambda r:r['url'])
summary={'pages':len(results),'http_200':sum(r.get('status')==200 for r in results),
         'errors':[r for r in results if r.get('error') or r.get('status')!=200],
         'review':[r for r in results if r.get('unqualified') or r.get('server_return_schema')],
         'qualified_promise_pages':sum(bool(r.get('promise_hits')) for r in results)}
(OUT/'public-pages.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf-8')
(OUT/'public-summary.json').write_text(json.dumps(summary,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps(summary,ensure_ascii=False),flush=True)
