"""Bounded post-deploy check of content, offers, analytics code and buy URLs."""
from pathlib import Path
from urllib.request import urlopen,Request
import re,json,html,hashlib,argparse
ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'output/seo-country-lcp-20261011'
parser=argparse.ArgumentParser();parser.add_argument('--baseline',type=Path,default=OUT/'snapshot');args=parser.parse_args()
after=OUT/'live-after';after.mkdir(exist_ok=True)
def offers(value):
    if isinstance(value,dict):
        result=[value] if value.get('@type')=='Offer' else []
        return result+[o for child in value.values() for o in offers(child)]
    if isinstance(value,list):return [o for child in value for o in offers(child)]
    return []
def read_page(text):
    text=text.replace('\r\n','\n')
    schemas=[json.loads(j) for j in re.findall(r'<script type="application/ld\+json">(.*?)</script>',text,re.S)]
    buy=[html.unescape(x) for x in re.findall(r'<a class="cvl-buy(?: [^"]*)?" href="([^"]+)"',text)]
    analytics=[s for s in re.findall(r'<script\b[^>]*>(.*?)</script>',text,re.S) if 'ServerNetAnalytics' in s or 'snet-gtm' in s]
    return {'offers':[o for s in schemas for o in offers(s)],'buy':buy,'analytics':analytics,'schemas':len(schemas)}
records=[]
for locale in ['fa','en','tr']:
    for city in ['ae-dubai','ph-manila']:
        url='https://servernet.cloud'+('' if locale=='fa' else '/'+locale)+'/cloud/'+city
        with urlopen(Request(url,headers={'User-Agent':'ServerNet-SEO-Audit/1.0'}),timeout=40) as r:
            assert r.status==200
            content=r.read()
        name=locale+'-'+city+'.html'
        (after/name).write_bytes(content)
        text=content.decode('utf-8')
        before=read_page((args.baseline/name).read_text(encoding='utf-8'))
        current=read_page(text)
        assert len(re.findall('<h1[ >]',text))==1
        assert 'href="'+url+'"' in re.search(r'<link rel="canonical"[^>]+>',text)[0]
        assert '<style id="cloud-location-lcp">' in text
        assert not re.search(r'<link rel="stylesheet"[^>]*site(?:\.min)?\.css',text)
        head=text.split('</head>',1)[0]
        assert not re.search(r'<link rel="stylesheet"[^>]*server-menu\.css',head)
        assert len(re.findall(r'<link rel="preload"[^>]*IRANSans-location-20261011-400\.woff2',head))==1
        assert re.search(r'<link rel="preload"[^>]*IRANSans-location-20261011-400\.woff2[^>]*media="\(min-width:1021px\)"',head)
        assert '--font-body:Tahoma,Arial,sans-serif' in head
        assert 'data:font/woff2;base64,' not in head
        assert not re.search(r'<link rel="preload"[^>]*IRANSans-location-20261011-700\.woff2',head)
        menu=re.search(r'<div class="server-menu".*?<img[^>]+>',text,re.S)
        assert menu and 'loading="lazy"' in menu[0]
        flag=re.search(r'<span class="cvl-flag".*?<img[^>]+>',text,re.S)
        assert flag and 'loading="eager"' in flag[0]
        assert current['buy']==before['buy'], 'Live catalog drift; inspect before accepting new baseline: '+url
        assert current['analytics']==before['analytics'], 'Analytics code changed: '+url
        records.append({'url':url,'status':200,'buy_urls':len(current['buy']),'buy_urls_unchanged':current['buy']==before['buy'],'existing_buy_urls_preserved':True,'additional_live_buy_urls':len(set(current['buy'])-set(before['buy'])),'analytics_code_unchanged':True,'offers_unchanged':current['offers']==before['offers'],'existing_offers_preserved':all(o in current['offers'] for o in before['offers']),'schema_count':current['schemas'],'inline_css_bytes':len(re.search(r'<style id="cloud-location-lcp">(.*?)</style>',text,re.S)[1].encode()),'sha256':hashlib.sha256(content).hexdigest()})
(OUT/'verification.json').write_text(json.dumps(records,indent=2),encoding='utf-8')
print('LIVE_VERIFICATION_OK',len(records),'pages; offers drift:',sum(not x['offers_unchanged'] for x in records))
