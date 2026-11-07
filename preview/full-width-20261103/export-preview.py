"""Export the interactive preview as one offline HTML file with its own fonts."""
from pathlib import Path
import argparse
import base64
import re

ROOT = Path(__file__).resolve().parent

def build(output: Path):
    html = (ROOT/'index.html').read_text()
    def inline_css(match):
        css = (ROOT/match.group(1)).read_text()
        def embed_font(font):
            path = (ROOT/font.group(1)).resolve()
            if ROOT not in path.parents or path.suffix != '.woff2':
                raise ValueError(f'Unexpected embedded asset: {font.group(1)}')
            data = base64.b64encode(path.read_bytes()).decode()
            return "url('data:font/woff2;base64,"+data+"')"
        css = re.sub(r"url\(['\"]?([^)'\"]+)['\"]?\)", embed_font, css)
        return '<style>\n'+css+'\n</style>'
    html = re.sub(r'<link rel="stylesheet" href="([^"]+)">',inline_css,html)
    def inline_js(match):
        script = (ROOT/match.group(1)).read_text()
        if '</script' in script.lower(): raise ValueError('Script requires HTML-safe escaping')
        return '<script>\n'+script+'\n</script>'
    # Keep deferred script semantics after removing src: move script blocks to body end.
    scripts=[]
    for match in re.finditer(r'<script src="([^"]+)" defer></script>',html): scripts.append(inline_js(match))
    html=re.sub(r'\s*<script src="([^"]+)" defer></script>','',html)
    html=html.replace('</body>','\n'+'\n'.join(scripts)+'\n</body>')
    assert not re.search(r'<(?:link|script)[^>]+(?:src|href)=',html)
    output.parent.mkdir(parents=True,exist_ok=True); output.write_text(html)
    print(f'{output}: {output.stat().st_size:,} bytes; styles, scripts and fonts embedded')

if __name__ == '__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--output',type=Path,required=True)
    build(parser.parse_args().output.resolve())
