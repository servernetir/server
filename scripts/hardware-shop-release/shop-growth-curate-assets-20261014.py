"""Use only owner images and explicitly credited open-license reference photographs."""
from pathlib import Path
from urllib.request import build_opener,ProxyHandler,Request
from urllib.parse import urlsplit,urlunsplit
from PIL import Image,ImageOps
from lxml import html
from io import BytesIO
import json,re
ROOT=Path(__file__).resolve().parents[1];OUT=ROOT/'output/shop-growth-20261014'
W=Path('C:/Users/admin/.codex/worktrees/shop-catalog-seo/servernet/website')
rows={x['i']:x['rows'] for x in map(json.loads,(OUT/'commons-options.jsonl').read_text(encoding='utf8').splitlines())}
opener=build_opener(ProxyHandler({'http':'http://127.0.0.1:10808','https':'http://127.0.0.1:10808'}))
assets=W/'public/assets/hardware'; cache=OUT/'licensed-photos';cache.mkdir(exist_ok=True)
choices={'xeon-e5-v2':(0,1),'ddr3-ecc':(3,0),'ddr4-lrdimm':(2,0),'ddr5-rdimm':(4,0),'sas-drive':(6,1),'ml350-family':(10,0),'c7000':(14,3)}
credits={}
for key,(i,j) in choices.items():
 r=rows[i][j];u=urlsplit(r['url']);url=urlunsplit((u.scheme,u.netloc,u.path,'',''))
 dest=cache/(key+'.original')
 if not dest.exists():
  try:dest.write_bytes(opener.open(Request(url,headers={'User-Agent':'ServerNetCatalog/1.0'}),timeout=35).read())
  except Exception as e:print(key,type(e).__name__,flush=True);continue
 im=ImageOps.exif_transpose(Image.open(dest)).convert('RGB');im.thumbnail((960,720))
 canvas=Image.new('RGB',(960,720),'white');canvas.paste(im,((960-im.width)//2,(720-im.height)//2))
 canvas.save(assets/(key+'.webp'),'WEBP',quality=84,method=6)
 credits[key]={'path':'/assets/hardware/'+key+'.webp','title':r['title'][5:],'source':r['page'],'author':html.fromstring('<span>'+r['artist']+'</span>').text_content(),'license':r['license'],'license_url':r['license_url'],'changes':'Resized, white canvas, converted to WebP'}
meta=json.loads((W/'database/catalog/hardware-presentation-20261014.json').read_text(encoding='utf8'))
products=json.loads((W/'database/catalog/hardware-products-20261014.json').read_text(encoding='utf8'))
live=json.loads((OUT/'snapshot/catalog.json').read_text(encoding='utf8'));owner={r['slug']:r for r in live['servers'] if r.get('gallery')}
counts={'licensed_reference':0,'owner_photo':0,'schematic':0}
for item in products:
 slug=item['slug'];key=None;m=meta[slug];sub=m['subtype'];data=item.get('data') or {}
 attrs=data.get('attrs') or {};name=slug.lower()
 if sub=='cpu' and re.search(r'e5-\d+-v2',name):key='xeon-e5-v2'
 if sub=='ram':
  mt=str(attrs.get('memory_type',''))+' '+name
  if 'ddr3' in mt.lower():key='ddr3-ecc'
  elif 'ddr4' in mt.lower() and ('lrdimm' in mt.lower() or re.search(r'\d{4}l\b',name)):key='ddr4-lrdimm'
  elif 'ddr5' in mt.lower():key='ddr5-rdimm'
 if sub=='disk' and 'sas' in name and 'ssd' not in name:key='sas-drive'
 if sub=='server' and 'ml350' in name:key='ml350-family'
 if 'c7000' in name:key='c7000'
 if key in credits:
  photo=credits[key];m.update(thumbnail=photo['path'],schematic=False,credit=photo,reference_family=key)
  gallery=[photo['path'],'/assets/hardware/'+slug+'-reference.svg']
  if data:item['data']['gallery']=gallery
  elif 'gallery' in item.get('patch',{}):item['patch']['gallery']=gallery
  counts['licensed_reference']+=1
 elif slug in owner:
  original=owner[slug]['gallery'][0];dest=cache/(slug+'.original')
  if not dest.exists():dest.write_bytes(opener.open('https://servernet.cloud'+original,timeout=35).read())
  im=ImageOps.exif_transpose(Image.open(dest)).convert('RGB');im.thumbnail((960,720));canvas=Image.new('RGB',(960,720),'white');canvas.paste(im,((960-im.width)//2,(720-im.height)//2));canvas.save(assets/(slug+'-photo.webp'),'WEBP',quality=84,method=6)
  m.update(thumbnail='/assets/hardware/'+slug+'-photo.webp',schematic=False,photo_reference=False,optimized_gallery=['/assets/hardware/'+slug+'-photo.webp'])
  counts['owner_photo']+=1
 else:counts['schematic']+=1
for filename,value in [('hardware-presentation-20261014.json',meta),('hardware-products-20261014.json',products)]:
 (W/'database/catalog'/filename).write_text(json.dumps(value,ensure_ascii=False,indent=2),encoding='utf8')
(OUT/'asset-provenance.json').write_text(json.dumps({'credits':credits,'counts':counts},ensure_ascii=False,indent=2),encoding='utf8')
print(json.dumps(counts),flush=True)
