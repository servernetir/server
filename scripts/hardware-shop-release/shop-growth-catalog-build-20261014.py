"""Curated public identities plus original prose, original schematics and verified primary CPU facts."""
from pathlib import Path
from lxml import html
from PIL import Image,ImageOps
from io import BytesIO
import json,re,html as h,importlib.util,hashlib
ROOT=Path(__file__).resolve().parents[1];OUT=ROOT/'output/shop-growth-20261014'
W=Path('C:/Users/admin/.codex/worktrees/shop-catalog-seo/servernet/website')
spec=importlib.util.spec_from_file_location('crawl',Path(__file__).with_name('shop-growth-crawl-20261014.py'));c=importlib.util.module_from_spec(spec);spec.loader.exec_module(c)
entries=json.loads((OUT/'identities.json').read_text(encoding='utf8'))
live=json.loads((OUT/'snapshot/catalog.json').read_text(encoding='utf8'))
guidance=json.loads((W/'database/catalog/hardware-guidance-20261014.json').read_text(encoding='utf8'))
intel={r['slug']:r for r in json.loads((OUT/'intel-matches.json').read_text())}
existing={r['slug']:r for r in live['servers']+live['parts']}
def tri(fa,en,tr):return {'fa':fa,'en':en,'tr':tr}
names={'cpu':tri('پردازندهٔ سرور','server processor','sunucu işlemcisi'),'ram':tri('حافظهٔ سرور','server memory','sunucu belleği'),'disk':tri('تجهیزات ذخیره‌سازی','storage hardware','depolama donanımı'),'nic':tri('کارت شبکه یا اتصال استوریج','network or storage adapter','ağ veya depolama adaptörü'),'psu':tri('منبع تغذیهٔ سرور','server power supply','sunucu güç kaynağı'),'raid':tri('کنترلر یا قطعهٔ ذخیره‌سازی','storage controller or component','depolama denetleyicisi veya parçası'),'other':tri('قطعهٔ جانبی سرور','server accessory','sunucu aksesuarı'),'chassis':tri('شاسی و تجهیزات نصب','chassis and installation hardware','kasa ve kurulum donanımı'),'server':tri('پلتفرم سرور','server platform','sunucu platformu'),'gpu':tri('شتاب‌دهندهٔ گرافیکی','graphics accelerator','grafik hızlandırıcısı')}
labels={
 'model':tri('مدل / شناسه','Model / identifier','Model / tanımlayıcı'),
 'part_number':tri('شماره‌قطعهٔ اعلام‌شده','Listed part number','Listelenen parça numarası'),
 'gb':tri('ظرفیت اسمی','Nominal capacity','Nominal kapasite'),
 'speed_mts':tri('سرعت اسمی حافظه','Nominal memory speed','Nominal bellek hızı'),
 'memory_type':tri('نوع حافظه','Memory type','Bellek türü'),
 'interface':tri('رابط در نام مدل','Interface in model identifier','Model tanımlayıcısındaki arayüz'),
 'socket':tri('سوکت پردازنده','CPU socket','İşlemci soketi'),
 'cores':tri('هستهٔ پردازشی','CPU cores','İşlemci çekirdekleri'),
 'threads':tri('رشتهٔ پردازشی','CPU threads','İşlemci iş parçacıkları'),
 'cache_mb':tri('حافظهٔ کش','Cache','Önbellek'),
 'ghz':tri('فرکانس پایه','Base clock','Temel frekans'),
 'ghz_turbo':tri('حداکثر فرکانس توربو','Maximum turbo clock','Azami turbo frekansı'),
 'reference_clock':tri('فرکانس درج‌شده در مرجع Intel','Clock listed in the Intel reference','Intel referansında listelenen frekans'),
 'tdp':tri('توان طراحی حرارتی (TDP)','Thermal design power (TDP)','Termal tasarım gücü (TDP)'),
 'lifecycle':tri('وضعیت عرضهٔ سازنده','Manufacturer marketing status','Üretici pazarlama durumu'),
 'form':tri('نوع پلتفرم','Platform form','Platform biçimi'),
}
units={'gb':'GB','speed_mts':'MT/s','cache_mb':'MB','ghz':'GHz','ghz_turbo':'GHz','tdp':'W'}
def read_ark(slug):
 p=OUT/'research/intel-ark'/(slug+'.html')
 if not p.exists():return {}
 doc=html.fromstring(p.read_bytes());fields={}
 for node in doc.xpath('//div[contains(concat(" ",normalize-space(@class)," ")," row-key ")]'):
  key=' '.join(' '.join(node.xpath('./text()')).split());vs=node.getparent().getparent().xpath('.//div[contains(concat(" ",normalize-space(@class)," ")," row-value ")]')
  if vs:fields[key]=' '.join(vs[0].text_content().split())
 strip=lambda v:re.sub('[^a-z0-9]','',re.sub(r'silver|gold|platinum|bronze','',v.lower()))
 expected=strip(intel.get(slug,{}).get('model',''))
 actual=strip(fields.get('Processor Number',''))
 return fields if expected and actual==expected else {}
def attributes(e):
 n=e['name_fa']+' '+e['model'];lo=n.lower();a={'kind':e['subtype']};verified=[]
 if e['category']=='cpu' and e['slug'] in intel:
  r=intel[e['slug']];a.update(cache_mb=r['cache_mb'],reference_clock=r['reference_clock']);verified+=['cache_mb','reference_clock']
  fields=read_ark(e['slug'])
  for field,key in [('Total Cores','cores'),('Total Threads','threads'),('Processor Base Frequency','ghz'),('Max Turbo Frequency','ghz_turbo'),('TDP','tdp')]:
   match=re.match(r'^(\d+(?:\.\d+)?)\b',fields.get(field,''))
   if match:a[key]=float(match[1]);verified.append(key)
  if fields.get('Sockets Supported'):a['socket']=fields['Sockets Supported'];verified.append('socket')
  if fields.get('Memory Types'):a['memory_type']=fields['Memory Types'];verified.append('memory_type')
  if fields.get('Marketing Status'):a['lifecycle']=fields['Marketing Status'];verified.append('lifecycle')
 if e['category'] in ['ram','disk']:
  cap=re.search(r'(\d+(?:\.\d+)?)\s*(gb|tb|گیگابایت|ترابایت|گیگ)',lo)
  if cap:a['gb']=float(cap[1])*(1000 if cap[2] in ['tb','ترابایت'] else 1)
 if e['category']=='ram':
  speed=re.search(r'\b(800|1066|1333|1600|1866|2133|2400|2666|2933|3200|4800|5600|6400|8000)([rl])?\b',lo)
  if speed:a['speed_mts']=int(speed[1])
  mt=re.search(r'\b(ddr[345])\b',lo)
  if mt:a['memory_type']=mt[1].upper()
  if 'lrdimm' in lo or (speed and speed[2]=='l'):a['memory_type']=(a.get('memory_type','')+' ECC LRDIMM').strip()
  elif 'rdimm' in lo or (speed and speed[2]=='r'):a['memory_type']=(a.get('memory_type','')+' ECC RDIMM').strip()
 if e['category'] in ['disk','nic']:
  interfaces=[v for v in ['NVMe','SAS','SATA','Fibre Channel','SFP28','SFP+','RJ45'] if v.lower() in lo]
  if interfaces:a['interface']=' / '.join(interfaces)
 if e['kind']=='server':
  form='Blade' if 'bl460' in e['slug'] or 'c7000' in e['slug'] else 'Tower' if '-ml' in e['slug'] or 'microserver' in e['slug'] else 'Rack' if '-dl' in e['slug'] or 'poweredge-r' in e['slug'] else 'Edge / modular'
  a['form']=form
 pn=re.findall(r'\b(?:[0-9]{6}|P[0-9]{5})-(?:B21|001|002|003)\b',n.upper())
 if pn:a['part_number']=pn[0]
 return a,verified
def specs(model,attrs,server=False):
 rows=[]
 values={'model':model,**{k:v for k,v in attrs.items() if k in labels}}
 for k,v in values.items():
  if isinstance(v,float) and v.is_integer():v=int(v)
  val=str(v)+(' '+units[k] if k in units else '')
  row={'label':labels[k]}
  if server:row.update(tri(val,val,val))
  else:row['value']=tri(val,val,val)
  rows.append(row)
 return rows
def schema_svg(e,a,path):
 subtype=e['subtype'];model=h.escape(e['model'][:64]);detail=' · '.join(str(a[k])+(' '+units[k] if k in units else '') for k in ['cores','cache_mb','gb','speed_mts','interface'] if k in a)[:75]
 shape=''
 if subtype=='cpu':
  shape='<rect x="80" y="128" width="236" height="236" rx="18" fill="#0c765b"/><rect x="106" y="154" width="184" height="184" rx="9" fill="url(#metal)" stroke="#93a2ac" stroke-width="3"/>'
  for i in range(14):
   y=144+i*15;shape+=f'<path d="M65 {y}h15m236 0h15" stroke="#cbb16e" stroke-width="7"/>'
  shape+='<text x="198" y="240" text-anchor="middle" font-family="Arial,sans-serif" font-size="19" fill="#324250">CPU</text>'
 elif subtype=='ram':
  shape='<rect x="40" y="173" width="318" height="128" rx="8" fill="#13674b"/>'
  for i in range(8):shape+=f'<rect x="{57+i*36}" y="193" width="26" height="57" rx="3" fill="#162a31"/><rect x="{57+i*36}" y="278" width="28" height="20" fill="#d1b877"/>'
  shape+='<rect x="82" y="257" width="175" height="14" rx="3" fill="#e9f0ed"/>'
 elif subtype in ['server','chassis','storage']:
  if a.get('form')=='Tower':
   shape='<rect x="95" y="92" width="192" height="282" rx="12" fill="url(#dark)"/>'
   for i in range(5):shape+=f'<rect x="115" y="{130+i*40}" width="145" height="28" rx="4" fill="#0f1c2a" stroke="#657584"/>'
  else:
   shape='<rect x="32" y="170" width="335" height="135" rx="12" fill="url(#dark)"/>'
   for i in range(6):shape+=f'<rect x="{48+i*46}" y="195" width="38" height="86" rx="4" fill="#101b28" stroke="#657584"/><circle cx="{77+i*46}" cy="267" r="3" fill="#4fe0ab"/>'
 elif subtype in ['disk','caddy']:
  shape='<rect x="93" y="108" width="207" height="267" rx="16" fill="url(#metal)" stroke="#90a1ae" stroke-width="3"/><rect x="112" y="140" width="170" height="123" rx="5" fill="#f0f5f6"/><path d="M98 337h198v27H98z" fill="#243444"/><rect x="270" y="340" width="22" height="20" fill="#a42550"/><circle cx="161" cy="304" r="12" fill="#798b96"/>'
 elif subtype=='psu':
  shape='<path d="M58 163l205-30 80 58-205 33z" fill="#b9c7ce"/><path d="M58 163v115l80 57V224z" fill="#657985"/><path d="M138 224l205-33v115l-205 29z" fill="#293d4b"/><circle cx="196" cy="267" r="34" fill="#111f2d" stroke="#8ea2ae" stroke-width="3"/><rect x="254" y="234" width="54" height="44" rx="5" fill="#182834"/>'
 elif subtype=='cooling':
  shape='<rect x="83" y="126" width="236" height="236" rx="18" fill="#912447"/><circle cx="201" cy="244" r="93" fill="#172d37"/><circle cx="201" cy="244" r="25" fill="#9eb0ba"/>'
  for i in range(7):shape+=f'<path d="M201 244q-25-78 54-70q6 60-54 70" fill="#405b69" transform="rotate({i*51.43} 201 244)"/>'
 elif subtype in ['nic','raid','gpu','riser','motherboard','sfp']:
  shape='<rect x="63" y="128" width="270" height="209" rx="8" fill="#13694f"/><rect x="34" y="109" width="22" height="247" rx="3" fill="url(#metal)"/><rect x="163" y="172" width="97" height="94" rx="7" fill="#1d333e"/>'
  for i in range(11):shape+=f'<path d="M{171+i*7} 177v85" stroke="#708b97" stroke-width="3"/>'
  for i in range(3):shape+=f'<rect x="25" y="{138+i*60}" width="48" height="41" rx="3" fill="#263e4c" stroke="#96aab5"/>'
  for i in range(17):shape+=f'<rect x="{130+i*10}" y="331" width="6" height="18" fill="#c7ad6c"/>'
 elif subtype in ['rail','cable']:
  shape='<path d="M43 201l284-77 23 37-284 83zM43 294l284-77 23 37-284 83z" fill="url(#metal)" stroke="#8399a6" stroke-width="4"/>'
 else:
  shape='<rect x="76" y="135" width="242" height="208" rx="18" fill="url(#metal)"/><path d="M106 193h182M106 235h182M106 277h138" stroke="#3b6175" stroke-width="14"/>'
 svg=f'''<svg xmlns="http://www.w3.org/2000/svg" width="960" height="720" viewBox="0 0 960 720" role="img" aria-labelledby="title desc"><title id="title">{model} — technical reference illustration</title><desc id="desc">Original schematic for identifying the component class, not a photograph of inventory.</desc><defs><linearGradient id="metal" x2="0.8" y2="1"><stop stop-color="#e9f0f2"/><stop offset="1" stop-color="#9baeb9"/></linearGradient><linearGradient id="dark" x2="1" y2="1"><stop stop-color="#496472"/><stop offset="1" stop-color="#162935"/></linearGradient></defs><rect width="960" height="720" rx="30" fill="#f6fafb"/><path d="M0 610h960v110H0z" fill="#eaf2f6"/><text x="54" y="66" font-family="Arial,sans-serif" font-size="24" letter-spacing="3" fill="#087c91">SERVERNET / HARDWARE</text><g transform="translate(65 120) scale(1.25)">{shape}</g><text x="690" y="238" font-family="Arial,sans-serif" font-size="21" font-weight="bold" fill="#0b7e92">{h.escape(subtype.upper())}</text><text x="690" y="290" font-family="Arial,sans-serif" font-size="17" fill="#536f82">MODEL</text><text x="54" y="648" font-family="Arial,sans-serif" font-size="{25 if len(e['model'])<54 else 19}" font-weight="bold" fill="#193c50">{model}</text><text x="54" y="684" font-family="Arial,sans-serif" font-size="17" fill="#58778a">{h.escape(detail) or 'PART NUMBER + CONFIGURATION CHECK'}</text><text x="690" y="347" font-family="Arial,sans-serif" font-size="13" fill="#698491">SCHEMATIC</text><text x="690" y="370" font-family="Arial,sans-serif" font-size="13" fill="#698491">NOT A STOCK PHOTO</text></svg>'''
 path.write_text(svg,encoding='utf8')
def brand(e):
 n=e['name_fa'].lower()
 if e['category']=='cpu':return 'Intel'
 if any(x in n for x in ['samsung','سامسونگ']):return 'Samsung'
 if re.search(r'\bwd\b',n) or 'وسترن' in n:return 'Western Digital'
 if 'seagate' in n or 'سیگیت' in n:return 'Seagate'
 if 'nvidia' in n or 'tesla' in n:return 'NVIDIA'
 if 'dell' in n or 'دل ' in n:return 'Dell'
 return 'HPE' if any(x in n for x in ['hp','hpe','اچ پی','اچ‌پی']) or e['kind']=='server' else 'Server hardware'
def paragraphs(e,a):
 n=e['model'];cat=e['category'];kind=e['subtype'];rowdetail='؛ '.join(labels[k]['fa']+': '+str(v)+(' '+units[k] if k in units else '') for k,v in a.items() if k in labels and k!='lifecycle')
 detail_en='; '.join(labels[k]['en']+': '+str(v)+(' '+units[k] if k in units else '') for k,v in a.items() if k in labels and k!='lifecycle')
 detail_tr='; '.join(labels[k]['tr']+': '+str(v)+(' '+units[k] if k in units else '') for k,v in a.items() if k in labels and k!='lifecycle')
 first=tri(f'{n} در ردهٔ {names[cat]["fa"]} قرار می‌گیرد. این صفحه برای شناسایی مدل، بررسی تفاوت گزینه‌ها و آماده‌کردن استعلام کانفیگ است. '+(f'مشخصه‌های این مدل: {rowdetail}.' if rowdetail else 'برای انتخاب نسخهٔ دقیق، نام مدل را همراه شماره‌قطعهٔ درج‌شده روی قطعه تطبیق دهید.'), f'{n} is listed as {names[cat]["en"]}. Use this page to identify the model, compare alternatives and prepare a configuration enquiry. '+(f'Model identifiers and specifications: {detail_en}.' if detail_en else 'Match the model with the part number printed on the physical component before choosing a variant.'),f'{n}, {names[cat]["tr"]} sınıfındadır. Modeli tanımlamak, alternatifleri karşılaştırmak ve yapılandırma talebi hazırlamak için bu sayfayı kullanın. '+(f'Model tanımlayıcıları ve özellikleri: {detail_tr}.' if detail_tr else 'Bir sürümü seçmeden önce model ile fiziksel parçanın etiketindeki parça numarasını eşleştirin.'))
 second=tri(f'در مقایسهٔ {n}، تفاوت کانفیگ یا پسوند مدل را حذف نکنید. وجود نام مشابه، جایگزینی قطعی را نشان نمی‌دهد. مدل کامل سرور، قطعات فعلی و نسخهٔ firmware را همراه درخواست بفرستید تا قطعهٔ مناسب و اقلام جانبی لازم مشخص شود.',f'When comparing {n}, retain the full model suffix and configuration. A similar name does not establish interchangeability. Include the exact server, installed components and firmware with your enquiry to identify the required variant and accessories.',f'{n} karşılaştırmasında tam model ekini ve yapılandırmayı koruyun. Benzer ad değiştirilebilirliği göstermez. Uygun sürüm ve aksesuarları belirlemek için tam sunucu modelini, takılı parçaları ve firmware bilgisini paylaşın.')
 third=tri(f'برای {n}، قیمت و زمان تحویل باید به نسخهٔ قابل تأمین مربوط باشد. وضعیت نو، استوک یا بازسازی‌شده، عکس برچسب، نتیجهٔ تست سلامت و شرایط گارانتی را در پیش‌فاکتور مشخص کنید. این صفحه به‌تنهایی تأیید موجودی انبار یا وعدهٔ تحویل فوری نیست.',f'Pricing and delivery for {n} must refer to the variant actually supplied. Record new/used/refurbished condition, label photographs, health testing and warranty terms in the quotation. This catalog entry alone does not confirm stock or immediate delivery.',f'{n} fiyatı ve teslimat süresi tedarik edilecek sürüm için belirtilmelidir. Yeni/kullanılmış/yenilenmiş durum, etiket fotoğrafı, sağlık testi ve garanti şartlarını teklifte kaydedin. Bu katalog kaydı stok veya hemen teslimat onayı değildir.')
 body={loc:'\n\n'.join([first[loc],second[loc],third[loc]]) for loc in ['fa','en','tr']}
 return first,body
allslugs={e['slug'] for e in entries}
for r in live['servers']:
 if r['slug'] not in allslugs:entries.append({'slug':r['slug'],'kind':'server','category':'server','subtype':'server','model':r['name']['en'],'name_fa':r['name']['fa'],'sources':[],'images':[],'existing':True})
for r in live['parts']:
 if r['slug'] not in allslugs:entries.append({'slug':r['slug'],'kind':'part','category':r['category'],'subtype':r['category'],'model':r['name']['en'],'name_fa':r['name']['fa'],'sources':[],'images':[],'existing':True})
assets=W/'public/assets/hardware';assets.mkdir(parents=True,exist_ok=True)
meta={};records=[]
for index,e in enumerate(entries):
 assert re.fullmatch('[a-z0-9-]{1,230}',e['slug']),e['slug']
 a,verified=attributes(e)
 if e['existing'] and e['kind']=='part':a={**(existing[e['slug']].get('attrs') or {}),**a}
 murl=intel[e['slug']].get('ark_url',intel[e['slug']]['reference_url']) if e['slug'] in intel else 'https://support.hpe.com/' if brand(e)=='HPE' else 'https://www.intel.com/content/www/us/en/products/details/processors/xeon.html' if brand(e)=='Intel' else None
 schematic='/assets/hardware/'+e['slug']+'-reference.svg';schema_svg(e,a,assets/(e['slug']+'-reference.svg'))
 summary,body=paragraphs(e,a)
 name=tri(e['model'] if e['kind']=='server' else e['name_fa'],e['model'],e['model'])
 if e['kind']=='part' and e['subtype'] in ['battery','rail','riser','accessory','cooling','motherboard','caddy','cable','licence','sfp','storage']:
  titles={'battery':('Cache battery','Önbellek bataryası'),'rail':('Rail kit','Ray kiti'),'riser':('Riser','Riser'),'accessory':('Accessory','Aksesuar'),'cooling':('Cooling component','Soğutma parçası'),'motherboard':('System board','Anakart'),'caddy':('Drive caddy','Disk kızağı'),'cable':('Cable','Kablo'),'licence':('Licence','Lisans'),'sfp':('Transceiver','Alıcı verici'),'storage':('Storage system','Depolama sistemi')}
  name['en']=titles[e['subtype']][0]+' — '+e['model'];name['tr']=titles[e['subtype']][1]+' — '+e['model']
 summary=tri(f'{e["model"]}؛ مشخصات، کاربرد و مقایسه گزینه‌ها. راهنمای سازگاری و استعلام کانفیگ، موجودی و قیمت از سرورنت.',f'{e["model"]}: specifications, applications and alternatives. Check compatibility and request configuration, availability and pricing.',f'{e["model"]}: özellikler, kullanım ve alternatifler. Uyumluluğu kontrol edin; yapılandırma, stok ve fiyat bilgisi alın.')
 summary={loc:(s[:157].rsplit(' ',1)[0]+'…' if len(s)>160 else s) for loc,s in summary.items()}
 rows=specs(e['model'],a,e['kind']=='server')
 item={'slug':e['slug'],'kind':e['kind'],'data':{'brand':'hp' if e['kind']=='server' else brand(e),'condition':'catalog','active':True,'sort':1000+index*10,'price_contact':True,'popular':False,'name':name,'body':body,'specs':rows,'gallery':[schematic]}}
 if e['kind']=='server':item['data'].update(tag=names['server'],hero_d=summary,description=summary,strengths=None,weaknesses=None)
 else:item['data'].update(category=e['category'],tagline=names[e['category']],summary=summary,in_stock=False,compat_gens=[],attrs=a)
 if e['existing']:
  # Existing operator content, stock and prices stay authoritative. Enrichment fills empty content only.
  r=existing[e['slug']];item['existing_id']=r['id'];item['before']={k:r.get(k) for k in ['gallery','body']};item['patch']={}
  if not r.get('gallery'):item['patch']['gallery']=[schematic]
  if not r.get('body') or not any((r.get('body') or {}).values()):item['patch']['body']=body
  item['data']=None
 meta[e['slug']]={'subtype':e['subtype'],'thumbnail':schematic,'schematic':True,'photo_reference':True,'manufacturer_url':murl,'primary_verified_fields':verified}
 records.append(item)
assert len(records)==len({r['slug'] for r in records})
catalogdir=W/'database/catalog';catalogdir.mkdir(exist_ok=True)
(catalogdir/'hardware-products-20261014.json').write_text(json.dumps(records,ensure_ascii=False,indent=2),encoding='utf8')
(catalogdir/'hardware-presentation-20261014.json').write_text(json.dumps(meta,ensure_ascii=False,indent=2),encoding='utf8')
(W/'config/hardware_catalog.php').write_text("<?php\n\nreturn json_decode(file_get_contents(__DIR__.'/../database/catalog/hardware-presentation-20261014.json'), true, 512, JSON_THROW_ON_ERROR);\n",encoding='utf8')
(OUT/'catalog-build-summary.json').write_text(json.dumps({'records':len(records),'new_servers':sum(r['kind']=='server' and 'existing_id' not in r for r in records),'new_parts':sum(r['kind']=='part' and 'existing_id' not in r for r in records),'existing_enrichments':sum(bool(r.get('patch')) for r in records),'official_cpu_identifications':sum(bool(m['primary_verified_fields']) for m in meta.values())},indent=2))
print((OUT/'catalog-build-summary.json').read_text())
