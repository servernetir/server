"""Package the real compiled hero as a public, synthetic design review artifact."""
from pathlib import Path
import sys
root = Path(__file__).resolve().parents[2]
source = Path(sys.argv[1])
destination = root/'docs/project/home-four-slides-2026-11-23'
destination.mkdir(parents=True, exist_ok=True)
css = (root/'website/public/assets/css/studio-carousel.css').read_text()
js = (root/'website/public/assets/js/studio-carousel.js').read_text()
for locale in ['fa','en','tr']:
    page = (source/(locale+'.html')).read_text()
    page = page.replace('<head>', '<head><meta name="robots" content="noindex,nofollow">')
    for name in ['site','atlas','atlas-public']:
        page = page.replace('href="assets/css/'+name+'.css"', 'href="https://servernet.cloud/assets/css/'+name+'.css"')
    page = page.replace('<link rel="stylesheet" href="assets/css/studio-carousel.css">', '<style>'+css+'</style>')
    page = page.replace('<script src="assets/js/studio-carousel.js"></script>', '<script>'+js+'</script>')
    page = page.replace('assets/img/atlas-earth.svg', 'https://servernet.cloud/assets/img/atlas-earth.svg')
    page = page.replace('assets/img/studio-coffee-campaign.webp', 'studio-coffee-campaign.webp')
    (destination/('preview-'+locale+'.html')).write_text(page)
print('Built three synthetic preview pages')
