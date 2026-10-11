"""Subset original fonts; preserve metrics and shaping, retain full-font fallback."""
from pathlib import Path
from html.parser import HTMLParser
import json,sys,hashlib
ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'output/seo-country-lcp-20261011'
sys.path.insert(0,str(ROOT/'output/seo-lcp-content-20261010/build-tools'))
from fontTools import subset
from fontTools.ttLib import TTFont
class BodyText(HTMLParser):
    def __init__(self):super().__init__();self.skip=0;self.text=[]
    def handle_starttag(self,tag,attrs):
        if tag in ['script','style']:self.skip+=1
    def handle_endtag(self,tag):
        if tag in ['script','style']:self.skip-=1
    def handle_data(self,data):
        if not self.skip:self.text.append(data)
text=''.join(chr(i) for i in range(32,127))
for file in (OUT/'snapshot').glob('*.html'):
    p=BodyText();p.feed(file.read_text(encoding='utf-8'));text+=''.join(p.text)
records=[]
for weight,name in [(400,'IRANSans-web.woff2'),(700,'IRANSans-Bold-web.woff2')]:
    source=OUT/'snapshot/public/assets/font/woff2'/name
    target=OUT/('critical-'+str(weight)+'.woff2')
    options=subset.Options();options.flavor='woff2';options.layout_features=['*'];options.name_IDs=['*'];options.name_legacy=True;options.name_languages=['*']
    font=subset.load_font(str(source),options);subsetter=subset.Subsetter(options=options);subsetter.populate(text=text);subsetter.subset(font);subset.save_font(font,str(target),options)
    original=TTFont(source);result=TTFont(target)
    assert original['head'].unitsPerEm==result['head'].unitsPerEm
    for table,fields in [('hhea',['ascent','descent','lineGap']),('OS/2',['sTypoAscender','sTypoDescender','sTypoLineGap','usWinAscent','usWinDescent'])]:
        for field in fields:assert getattr(original[table],field)==getattr(result[table],field)
    for c in set(map(ord,text)):
        if c in original.getBestCmap():
            assert c in result.getBestCmap()
            assert original['hmtx'][original.getBestCmap()[c]]==result['hmtx'][result.getBestCmap()[c]]
    remaining=set(original.getBestCmap())-set(result.getBestCmap())
    records.append({'weight':weight,'source':'public/assets/font/woff2/'+name,'source_sha256':hashlib.sha256(source.read_bytes()).hexdigest(),'subset':target.name,'asset':'public/assets/font/woff2/IRANSans-location-20261011-'+str(weight)+'.woff2','subset_sha256':hashlib.sha256(target.read_bytes()).hexdigest(),'unicode_range':','.join('U+%X'%c for c in sorted(result.getBestCmap())),'remaining_unicode_range':','.join('U+%X'%c for c in sorted(remaining)),'original_bytes':source.stat().st_size,'subset_bytes':target.stat().st_size,'glyphs':len(result.getGlyphOrder())})
(OUT/'font-build.json').write_text(json.dumps(records,indent=2),encoding='utf-8')
print('FONT_METRICS_AND_COVERAGE_OK',[(r['weight'],r['original_bytes'],r['subset_bytes']) for r in records])
