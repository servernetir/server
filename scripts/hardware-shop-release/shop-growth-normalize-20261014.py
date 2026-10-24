"""Build product identity coverage; no competitor prose, price, stock or warranty is published."""
from pathlib import Path
import json,re,hashlib,collections,unicodedata
ROOT=Path(__file__).resolve().parents[1];OUT=ROOT/'output/shop-growth-20261014'
raw=json.loads((OUT/'research/competitor-products.json').read_text(encoding='utf8'))
live=json.loads((OUT/'snapshot/catalog.json').read_text(encoding='utf8'))
digits=str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩','01234567890123456789')
def clean(s):return re.sub(r'\s+',' ',str(s).translate(digits).replace('\u200c',' ').replace('ي','ی').replace('ك','ک')).strip()
def ascii_slug(s):return re.sub('[^a-z0-9]+','-',s.lower()).strip('-')
def classify(r):
 n=re.sub(r'^(?:خرید و قیمت|قیمت و خرید|خرید|قیمت)\s*','',clean(r['name'])).lower();c=r.get('category','').lower();path=' '.join(r.get('breadcrumbs',[])).lower()
 if any(w in n+c+path for w in ['لپ تاپ','workstation','ورک استیشن','تین کلاینت','thin-client','مداربسته','مودم','modem','سوئیچ','network-switch','cisco-switch','روتر','لایسنس ویندوز']):return None
 if n.startswith('سرور') and re.search(r'\b(?:dl|ml|bl|c)\s*\d{2,4}',n):return ('server','server','server')
 if any(w in n for w in ['پردازنده','سی پی یو','xeon','cpu ']):return ('part','cpu','cpu')
 if re.search(r'(^|\s)رم(\s|$)|ddr[345]|rdimm|lrdimm',n):return ('part','ram','ram')
 if 'باتری' in n or 'باطری' in n:return ('part','raid','battery')
 if any(w in n for w in ['کارت شبکه','ethernet','مژول sfp','ماژول sfp','qsfp','کارت hba','hba ','اچ بی ای','sn1000']) or re.search(r'\b(?:nc\d{3}|\d{3}flr|\d{3}sfp)',n):return ('part','nic','sfp' if 'sfp' in n and 'کارت' not in n else 'nic')
 if any(w in n for w in ['رید','raid','smart array']):return ('part','raid','raid')
 if any(w in n for w in ['پاور','منبع تغذیه','power supply']):return ('part','psu','psu')
 if any(w in n for w in ['کارت گرافیک','tesla','nvidia','quadro']):return ('part','gpu','gpu')
 if 'کدی' in n or 'caddy' in n:return ('part','other','caddy')
 if any(w in n for w in ['هیت سینک','heatsink','heat sink','فن سرور','fan ']):return ('part','other','cooling')
 if 'مادربرد' in n or 'مادر برد' in n or 'system board' in n:return ('part','other','motherboard')
 if any(w in n for w in ['ریل','rail','کیبل','بازوی نگهدارنده']):return ('part','other','rail')
 if any(w in n for w in ['رایزر','riser','بزل','bezel','قفسه','cage','بک پلین','backplane']):return ('part','other','riser' if 'رایزر' in n or 'riser' in n else 'accessory')
 if any(w in n for w in ['ilo','لایسنس','ویندوز سرور']):return ('part','other','licence')
 if any(w in n for w in ['هارد','hdd','ssd','iodrive','storevirtual','disk enclosure','msa ','استوریج','storageworks']):return ('part','disk','storage' if any(w in n for w in ['استوریج','storevirtual','enclosure','msa ']) else 'disk')
 if any(w in n for w in ['شاسی','barebone','بربون','کابینت','رک ']):return ('part','chassis','chassis')
 if any(w in n for w in ['سرور','server','proliant','edgeline']) or re.search(r'\b(?:dl|ml|bl)\s*\d{3}',n):return ('server','server','server')
 if any(w in n for w in ['کابل','پچکورد','پچ کورد','باطری','باتری','اس اف پی','sfp','fiber']):return ('part','other','cable' if 'کابل' in n else 'accessory')
 fallback={'cpu-server':('cpu','cpu'),'server-rome':('ram','ram'),'reed-server-controller':('raid','raid'),'power-server':('psu','psu'),'server-network-card':('nic','nic'),'server-fan':('other','cooling'),'server-motherboard':('other','motherboard'),'rail-kit-server':('other','rail')}
 if c in fallback:return ('part',*fallback[c])
 return ('part','other','accessory') if any(w in path for w in ['لوازم و تجهیزات جانبی سرور','هیت سینک','تجهیزات شبکه']) else None
def identity(r,t):
 n=clean(r['name']);lo=n.lower();kind,cat,sub=t
 if cat=='cpu':
  m=re.search(r'e([3-7])[- ]?(\d{4}[a-z]?)(?:\s*[- ]?v\s*([1-6]))?',lo)
  if m:return 'xeon-e'+m[1]+'-'+m[2]+('-v'+m[3] if m[3] else ''), 'Intel Xeon E'+m[1]+'-'+m[2].upper()+(' v'+m[3] if m[3] else '')
  m=re.search(r'(silver|gold|platinum)\s*[- ]?(\d{4}[a-z]?)',lo)
  if not m:
   number=re.search(r'\b([4568]\d{3}[a-z]?)\b',lo)
   cls=next((x for x in ['silver','gold','platinum'] if x in lo),None)
   if number and cls:return 'xeon-'+cls+'-'+number[1],'Intel Xeon '+cls.title()+' '+number[1].upper()
  if m:return 'xeon-'+m[1]+'-'+m[2],'Intel Xeon '+m[1].title()+' '+m[2].upper()
  m=re.search(r'\b(2[346789]\d{2}[a-z]?)\s*v\s*([1-4])',lo)
  if m:return 'xeon-e5-'+m[1]+'-v'+m[2], 'Intel Xeon E5-'+m[1].upper()+' v'+m[2]
 if kind=='server':
  m=re.search(r'\b(dl|ml|bl)\s*(\d{2,3})([pce]?)[ -]*(?:gen|g)\s*(\d{1,2})(\s*(?:plus|\+))?',lo)
  if not m:
   reverse=re.search(r'\b(?:gen|g)\s*(\d{1,2})\s*(?:مدل\s*)?(dl|ml|bl)\s*(\d{2,3})([pce]?)',lo)
   if reverse:
    model=reverse[2].upper()+reverse[3]+reverse[4];return 'hpe-proliant-'+model.lower()+'-gen'+reverse[1],'HPE ProLiant '+model+' Gen'+reverse[1]
  if m:
   model=m[1].upper()+m[2]+m[3];gen=m[4];plus=' Plus' if m[5] else ''
   return 'hpe-proliant-'+model.lower()+'-gen'+gen+('-plus' if plus else ''),'HPE ProLiant '+model+' Gen'+gen+plus
  if 'micro' in lo:return 'hpe-proliant-microserver-gen10','HPE ProLiant MicroServer Gen10'
  m=re.search(r'\br\s*([46789]\d{2})\b',lo)
  if m:return 'dell-poweredge-r'+m[1],'Dell PowerEdge R'+m[1]
 # Same exact part number is the strongest deduplication key for replaceable components.
 pn=re.findall(r'\b(?:[0-9]{6}|P[0-9]{5})-(?:B21|001|002|003)\b',n.upper())
 if pn and cat!='cpu':
  token=pn[0];return 'hpe-'+cat+'-'+token.lower(), 'HPE '+token
 technical=' '.join(re.findall(r'[A-Za-z0-9][A-Za-z0-9./+_-]*',n))
 if not technical:
  tail=r['source_url'].split('/')[-1];technical=' '.join(re.findall(r'[A-Za-z]+[A-Za-z0-9./+_-]*|[0-9]+[A-Za-z]+[A-Za-z0-9./+_-]*',tail))
 key=ascii_slug(technical)
 # Do not merge ambiguous identities based only on a capacity or a wattage.
 short=len(key)<5 or re.fullmatch(r'[\d-]+(?:gb|tb|w)?',key or '')
 if short:key=(key+'-' if key else '')+hashlib.sha256(n.lower().encode()).hexdigest()[:8]
 name=technical if technical else sub.title()+' '+key
 return cat+('-'+sub if sub not in ['cpu','ram','disk','raid','nic','psu','gpu','chassis'] else '')+'-'+key,name
entries={};coverage=[];excluded=[]
existing={x['slug']:x for x in live['servers']+live['parts']}
for r in sorted(raw,key=lambda x:(x['source']!='vira',x['source_url'])):
 t=classify(r)
 if t is None:excluded.append({'url':r['source_url'],'name':r['name'],'reason':'standalone network, laptop, workstation or other non-server item'});continue
 key,model=identity(r,t);kind,category,sub=t
 if key in entries and entries[key]['kind']!=kind:key=kind+'-'+key
 if key not in entries:
  entries[key]={'slug':key,'kind':kind,'category':category,'subtype':sub,'model':model,'name_fa':re.sub(r'^(?:خرید و قیمت|خرید|قیمت)\s*','',clean(r['name'])),'sources':[],'images':[],'facts':[],'evidence_titles':[],'existing':key in existing}
 e=entries[key];e['sources'].append(r['source_url']);e['images']+=r['images'];e['evidence_titles'].append(r['name'])
 # Facts are evidence for identity verification, not bulk-transplanted page prose.
 for k,v in r['facts']:
  pair=[clean(k),clean(v)]
  if pair not in e['facts']:e['facts'].append(pair)
 e['research_facts']=e.get('research_facts','')+'\n'+r['short_facts']+'\n'+r['research_text'][:5000]
 coverage.append({'source_url':r['source_url'],'source_name':r['name'],'target_slug':key,'kind':kind,'category':category})
for e in entries.values():e['images']=list(dict.fromkeys(e['images']))
(OUT/'identities.json').write_text(json.dumps(list(entries.values()),ensure_ascii=False,indent=2),encoding='utf8')
(OUT/'coverage.json').write_text(json.dumps({'covered':coverage,'excluded':excluded},ensure_ascii=False,indent=2),encoding='utf8')
print(json.dumps({'sources':len(raw),'in_scope_sources':len(coverage),'excluded':len(excluded),'unique_products':len(entries),'kinds':dict(collections.Counter(e['kind'] for e in entries.values())),'categories':dict(collections.Counter(e['category'] for e in entries.values())),'existing_exact_matches':sum(e['existing'] for e in entries.values()),'new_without_images':sum(not e['images'] for e in entries.values())},ensure_ascii=False))
for e in entries.values():
 if e['kind']=='server':print(e['slug'], '|',e['name_fa'])
