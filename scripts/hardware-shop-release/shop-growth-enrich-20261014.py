from pathlib import Path
import json,importlib.util
ROOT=Path(__file__).resolve().parents[1];OUT=ROOT/'output/shop-growth-20261014';W=Path('C:/Users/admin/.codex/worktrees/shop-catalog-seo/servernet/website')
spec=importlib.util.spec_from_file_location('build',Path(__file__).with_name('shop-growth-catalog-build-20261014.py'))
# Execute definitions only; never regenerate curated image manifests here.
source=Path(spec.origin).read_text(encoding='utf8').split('allslugs=')[0];b={'__file__':spec.origin};exec(compile(source,spec.origin,'exec'),b)
records=json.loads((W/'database/catalog/hardware-products-20261014.json').read_text(encoding='utf8'))
meta=json.loads((W/'database/catalog/hardware-presentation-20261014.json').read_text(encoding='utf8'))
live=json.loads((OUT/'preflight/catalog.json').read_text(encoding='utf8'));old={r['slug']:r for r in live['parts']+live['servers']}
entries={e['slug']:e for e in json.loads((OUT/'identities.json').read_text(encoding='utf8'))}
cpu=0
for r in records:
 if not r.get('existing_id') or r['kind']!='part' or r['slug'] not in entries or entries[r['slug']]['category']!='cpu':continue
 a,fields=b['attributes'](entries[r['slug']]);facts={k:v for k,v in a.items() if k in fields}
 if not facts:continue
 before=old[r['slug']];attrs={**(before.get('attrs') or {}),**facts};rows=b['specs'](entries[r['slug']]['model'],attrs)
 for field,value in [('attrs',attrs),('specs',rows)]:
  if before.get(field)!=value:r['before'][field]=before.get(field);r['patch'][field]=value
 cpu+=1
profiles={
 'hpe-proliant-dl380-gen11':('2U / 2P','DDR5 RDIMM','Intel Xeon Scalable 4th / 5th generation','https://www.hpe.com/au/en/collaterals/collateral.a50004307enw.html'),
 'hpe-proliant-dl360-gen11':('1U / 2P','DDR5 RDIMM','Intel Xeon Scalable 4th / 5th generation','https://www.hpe.com/us/en/collaterals/collateral.a50004306enw.html'),
 'hpe-proliant-dl560-gen11':('2U / 4P',None,None,'https://www.hpe.com/psnow/doc/a50006983enw'),
 'hpe-proliant-dl145-gen11':('Edge',None,'AMD EPYC 8004 / 8005','https://www.hpe.com/emea_europe/en/collaterals/collateral.a50009217enw.html'),
}
for r in records:
 if r['slug'] not in profiles or not r.get('data'):continue
 form,memory,cpu_family,url=profiles[r['slug']];values={'form':form}
 if memory:values['memory_type']=memory
 rows=b['specs'](entries[r['slug']]['model'],values,True)
 if cpu_family:rows.append({'label':b['tri']('خانوادهٔ پردازندهٔ پلتفرم','Platform CPU family','Platform işlemci ailesi'),**b['tri'](cpu_family,cpu_family,cpu_family)})
 r['data']['specs']=rows;meta[r['slug']]['manufacturer_url']=url
 for loc in ['fa','en','tr']:
  sentence={'fa':'مشخصات مرجع پلتفرم: ','en':'Platform reference: ','tr':'Platform referansı: '}[loc]+form+(' · '+memory if memory else '')+(' · '+cpu_family if cpu_family else '')+'.'
  r['data']['body'][loc]=sentence+'\n\n'+r['data']['body'][loc]
for name,value in [('hardware-products-20261014.json',records),('hardware-presentation-20261014.json',meta)]:
 (W/'database/catalog'/name).write_text(json.dumps(value,ensure_ascii=False,indent=2),encoding='utf8')
print('EXISTING CPU FACTS VERIFIED',cpu)
