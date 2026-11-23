"""Create/remove only a pinned, synthetic, noindex HTML design review."""
from pathlib import Path
import fcntl
import hashlib
import json
import os
import re
import sys
import urllib.request

HOME = Path('/home/servernetcloud')
def sha(data): return hashlib.sha256(data).hexdigest()
def fetch(commit, path):
    with urllib.request.urlopen('https://raw.githubusercontent.com/servernetir/server/'+commit+'/'+path, timeout=15) as response:
        return response.read()

def main():
    mode, commit = sys.argv[1:3]
    assert mode in ['create', 'remove'] and re.fullmatch('[0-9a-f]{40}', commit)
    assert Path.home() == HOME and os.getuid() != 0
    manifest = json.loads(fetch(commit, 'scripts/home-email-studio-20261123/manifest.json'))
    records = manifest['preview']
    assert [row['name'] for row in records] == ['review.html', 'carousel-preview.css', 'mail-preview.css', 'carousel-preview.js']
    folder = HOME/'public_html'/('_home_mail_review_'+commit[:12])
    private = HOME/'deploy-backups'/('home-mail-review-'+commit[:12])
    private.mkdir(exist_ok=True, mode=0o700)
    receipt = private/'frames.json'
    assert not folder.is_symlink()
    with (HOME/'servernet_app/storage/framework/servernet-release.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if mode == 'create' and not folder.exists():
            payload = [(row, fetch(commit, row['source'])) for row in records]
            for row, data in payload:
                assert sha(data) == row['sha256']
                if row['name'].endswith('.html'): assert b'noindex,nofollow' in data
            frames = []
            for locale, path in [('fa', '/'), ('en', '/en'), ('tr', '/tr')]:
                url = 'https://servernet.cloud'+path
                with urllib.request.urlopen(url, timeout=30) as response:
                    html = response.read().decode()
                html = html[:html.index('<div class="sn-atlas sn-home">')]
                html = re.sub(r'(<meta name="csrf-token" content=")[^"]*', r'\1preview-only', html)
                html = html.replace('<head>', '<head><base href="'+url+'"><meta name="robots" content="noindex,nofollow">', 1)
                for asset, name in [('css/studio-carousel.css', 'carousel-preview.css'), ('js/studio-carousel.js', 'carousel-preview.js')]:
                    pattern = r'(?:https://servernet\.cloud)?/assets/'+re.escape(asset)+r'(?:\?v=[^"\s<>]*)?'
                    html, count = re.subn(pattern, 'https://servernet.cloud/'+folder.name+'/'+name, html)
                    assert count == 1, 'Unexpected public asset count: '+asset
                html = html.replace('</head>', '<link rel="stylesheet" href="https://servernet.cloud/'+folder.name+'/mail-preview.css"></head>')
                part = manifest['preview_partials'][locale]
                partial = fetch(commit, part['source'])
                assert sha(partial) == part['sha256']
                html += partial.decode().replace('src="assets/', 'src="https://servernet.cloud/assets/')+'</main></body></html>'
                data = html.encode()
                assert b'noindex,nofollow' in data and b'content="preview-only"' in data
                row = {'name': 'frame-'+locale+'.html', 'sha256': sha(data)}
                frames.append(row)
                payload.append((row, data))
            folder.mkdir(mode=0o755)
            for row, data in payload:
                path = folder/row['name']
                with path.open('xb') as handle: handle.write(data)
                path.chmod(0o644)
            receipt.write_text(json.dumps(frames))
            receipt.chmod(0o600)
        frames = json.loads(receipt.read_text())
        assert [row['name'] for row in frames] == ['frame-fa.html', 'frame-en.html', 'frame-tr.html']
        records = records + frames
        assert folder.is_dir() and {p.name for p in folder.iterdir()} == {r['name'] for r in records}
        for row in records:
            path = folder/row['name']
            assert not path.is_symlink() and sha(path.read_bytes()) == row['sha256']
        if mode == 'remove':
            for row in records: (folder/row['name']).unlink()
            folder.rmdir()
            print(json.dumps({'status': 'review_removed'}))
        else:
            print(json.dumps({'status': 'review_ready', 'url': 'https://servernet.cloud/'+folder.name+'/review.html'}))

if __name__ == '__main__': main()
