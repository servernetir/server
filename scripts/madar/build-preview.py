"""Build a self-contained review preview from a PHP-rendered synthetic fixture."""
from html.parser import HTMLParser
from pathlib import Path
import base64
import re
import sys


class Workspace(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=False)
        self.depth = 0
        self.parts = []
        self.done = False

    def handle_starttag(self, tag, attrs):
        if self.done:
            return
        if not self.depth:
            if 'mdr' not in dict(attrs).get('class', '').split():
                return
            self.depth = 1
        elif tag not in {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'}:
            self.depth += 1
        self.parts.append(self.get_starttag_text())

    def handle_startendtag(self, tag, attrs):
        if self.depth:
            self.parts.append(self.get_starttag_text())

    def handle_endtag(self, tag):
        if self.depth:
            self.parts.append('</' + tag + '>')
            self.depth -= 1
            if not self.depth:
                self.done = True

    def handle_data(self, data):
        if self.depth:
            self.parts.append(data)

    def handle_entityref(self, name):
        self.handle_data('&' + name + ';')

    def handle_charref(self, name):
        self.handle_data('&#' + name + ';')


root = Path(__file__).resolve().parents[2]
source = Path(sys.argv[1]).read_text()
parser = Workspace()
parser.feed(source)
assert parser.done, 'Rendered Madar workspace is missing or incomplete'
main = ''.join(parser.parts)
main = re.sub(r'(<input\b[^>]*type="hidden"[^>]*value=")[^"]*(")', r'\1preview\2', main)
main = re.sub(r'action="[^"]*"', 'action="#preview"', main)
main = re.sub(r'href="([^"]*)"', lambda m: m.group(0) if m[1].startswith('#')
              else 'href="#claim"' if '#claim' in m[1] else 'href="#my-work"', main)
ids = set(re.findall(r'<use href="#([^"]+)"', main))
symbols = ''.join(m[0] for m in re.finditer(r'<symbol id="([^"]+)"[\s\S]*?</symbol>', source) if m[1] in ids)
font = base64.b64encode((root / 'website/public/assets/font/woff2/IRANSans-web.woff2').read_bytes()).decode()
css = (root / 'website/public/assets/css/madar.css').read_text()
base = """@font-face{font-family:IRANSans;src:url(data:font/woff2;base64,FONT)}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:IRANSans,system-ui;line-height:1.8;padding:28px}:root{--bg:#080b16;--surface:#111725;--line:rgba(255,255,255,.1);--text:#e6eaf3;--muted:#96a3ba}html[data-theme=light]{--bg:#f3f5fb;--surface:white;--line:#dce2ef;--text:#111827;--muted:#526079}main{max-width:1080px;margin:auto}.preview-bar{max-width:1080px;margin:0 auto 20px;display:flex;justify-content:space-between;gap:15px;align-items:center;font-size:12px}.preview-bar button{font:inherit;border:1px solid var(--line);border-radius:8px;background:var(--surface);color:var(--text);padding:7px 14px;cursor:pointer}.pnl-btn{display:inline-flex;gap:8px;align-items:center;justify-content:center;padding:10px 16px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:12px;color:inherit;background:var(--surface);text-decoration:none;cursor:pointer}.pnl-btn.primary{background:linear-gradient(100deg,#3b82f6,#22d3ee);color:#04121d;border-color:transparent;font-weight:700}.icon{width:1em;height:1em;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}a{color:inherit}#preview-note{position:fixed;bottom:20px;inset-inline-end:20px;background:#21354a;color:white;padding:14px 20px;border-radius:12px;max-width:300px;z-index:9}[hidden]{display:none!important}@media(max-width:650px){body{padding:15px}.preview-bar{align-items:flex-start}}""".replace('FONT', font)
page = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>پیش‌نمایش مدار سرورنت</title><style>' + base + css + '</style></head><body><div class="preview-bar"><span>پیش‌نمایش مدار سرورنت · دادهٔ آزمایشی · نصب تولید انجام نشده</span><button id="theme-toggle" type="button">روشن / تیره</button></div><svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">' + symbols + '</svg><main>' + main + '</main><div id="preview-note" role="status" hidden>این صفحه پیش‌نمایش است؛ مدرکی ارسال نمی‌شود.</div><script>document.getElementById("theme-toggle").addEventListener("click",function(){document.documentElement.dataset.theme=document.documentElement.dataset.theme==="light"?"dark":"light"});document.querySelectorAll("form").forEach(function(f){f.addEventListener("submit",function(e){e.preventDefault();document.getElementById("preview-note").hidden=false})});</script></body></html>'
assert not re.search(r'(?:src|href)="https?://', page), 'Preview must not call external resources'
destination = Path(sys.argv[2])
page = page.replace('</script></body>', '</script><script>'+(root / 'website/public/assets/js/madar.js').read_text()+'</script></body>')
destination.write_text('\n'.join(line.rstrip() for line in page.splitlines()).rstrip()+'\n')
print('Preview saved:', destination, destination.stat().st_size, 'bytes')
