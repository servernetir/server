"""Make local-only visual previews. No analytics scripts execute in these fixtures."""
from pathlib import Path
import re,shutil
ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'output/seo-country-lcp-20261011'
WT=Path('C:/Users/admin/.codex/worktrees/seo-mobile-performance/servernet/website')
preview=OUT/'preview'; preview.mkdir(exist_ok=True)
css=(WT/'resources/css/cloud-location-lcp.css').read_text(encoding='utf-8').replace('/assets/font/','https://servernet.cloud/assets/font/')
for weight in [400,700]:
    name='IRANSans-location-20261011-'+str(weight)+'.woff2'
    source=WT/'public/assets/font/woff2'/name
    if source.exists():
        dest=preview/'assets/font/woff2'/name;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source,dest)
        css=css.replace('https://servernet.cloud/assets/font/woff2/'+name,'/assets/font/woff2/'+name)
for f in (OUT/('snapshot-current' if (OUT/'snapshot-current').exists() else 'snapshot-final' if (OUT/'snapshot-final').exists() else 'snapshot-reconciled' if (OUT/'snapshot-reconciled').exists() else 'snapshot')).glob('*.html'):
    html=f.read_text(encoding='utf-8')
    html=re.sub(r'<script(?![^>]*\bsrc=)(?![^>]*application/ld\+json)[^>]*>.*?</script>','',html,flags=re.S)
    pattern=r'<link rel="stylesheet" href="https://servernet.cloud/assets/css/site\.min\.css[^\"]*">'
    assert len(re.findall(pattern,html))==1
    (preview/('before-'+f.name)).write_text(html,encoding='utf-8')
    optimized=re.sub(pattern,'<style id="cloud-location-lcp">'+css+'</style>',html)
    if 'data:font/woff2;base64,' in css or 'font-display:optional' in css:
        optimized=re.sub(r'<link rel="preload"[^>]*IRANSans(?:-Bold)?-web\.woff2[^>]*>','',optimized)
    elif 'IRANSans-location-20261011-' in css:
        optimized=optimized.replace('https://servernet.cloud/assets/font/woff2/IRANSans-web.woff2','/assets/font/woff2/IRANSans-location-20261011-400.woff2').replace('https://servernet.cloud/assets/font/woff2/IRANSans-Bold-web.woff2','/assets/font/woff2/IRANSans-location-20261011-700.woff2')
    if '/* Server navigation: countries first' in css:
        optimized=re.sub(r'<link rel="stylesheet"[^>]*server-menu\.css[^>]*>','',optimized)
    (preview/('after-'+f.name)).write_text(optimized,encoding='utf-8')
print('LOCAL_PREVIEW_READY',12)
