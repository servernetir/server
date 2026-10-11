"""Fetch a bounded public baseline for the two requested city pages."""
from pathlib import Path
import json, re, hashlib
from urllib.request import urlopen, Request
from urllib.parse import urljoin
ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'output/seo-country-lcp-20261011'
snapshot=OUT/'snapshot'
assert not snapshot.exists(), 'Inspect the existing baseline; use a fresh dated audit instead of overwriting it'
snapshot.mkdir(parents=True,exist_ok=False)
seen={}
def fetch(url):
    with urlopen(Request(url,headers={'User-Agent':'ServerNet-SEO-Audit/1.0'}),timeout=40) as r:
        assert r.status==200
        return r.read()
for locale in ['', '/en', '/tr']:
    for slug in ['ae-dubai','ph-manila']:
        url='https://servernet.cloud'+locale+'/cloud/'+slug
        content=fetch(url)
        name=(locale.strip('/') or 'fa')+'-'+slug+'.html'
        (snapshot/name).write_bytes(content)
        for path in re.findall(r'(?:href|src)="([^"\s]+)"',content.decode('utf-8')):
            if '/assets/' in path and any(e in path for e in ['.css','.js','.woff2']) and 'partytown' not in path:
                absolute=urljoin(url,path)
                if absolute in seen:continue
                asset=fetch(absolute)
                rel=absolute.split('servernet.cloud/')[1].split('?')[0]
                dest=snapshot/'public'/rel;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(asset)
                seen[absolute]={'path':'public/'+rel,'sha256':hashlib.sha256(asset).hexdigest(),'bytes':len(asset)}
(OUT/'public-before.json').write_text(json.dumps(seen,indent=2),encoding='utf-8')
print('PUBLIC_BASELINE_OK',6,'pages',len(seen),'assets')
