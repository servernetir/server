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
    manifest = json.loads(fetch(commit, 'scripts/home-four-slides-20261123/manifest.json'))
    records = manifest['preview']
    assert [row['name'] for row in records] == ['review.html', 'preview-fa.html', 'preview-en.html', 'preview-tr.html', 'studio-coffee-campaign.webp']
    folder = HOME/'public_html'/('_home_four_review_'+commit[:12])
    assert not folder.is_symlink()
    with (HOME/'servernet_app/storage/framework/servernet-release.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if mode == 'create' and not folder.exists():
            payload = [(row, fetch(commit, row['source'])) for row in records]
            for row, data in payload:
                assert sha(data) == row['sha256']
                if row['name'].endswith('.html'): assert b'noindex,nofollow' in data
            folder.mkdir(mode=0o755)
            for row, data in payload:
                path = folder/row['name']
                with path.open('xb') as handle: handle.write(data)
                path.chmod(0o644)
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
