"""Read-only release plan from current live bytes and a pinned source revision."""
import hashlib
import json
from pathlib import Path
import re
import sys
import urllib.request

HOME = Path('/home/servernetcloud')
PREFIX = 'scripts/home-vps-gpu-public-store-20261123/'

def fetch(commit, path):
    with urllib.request.urlopen('https://raw.githubusercontent.com/servernetir/server/'+commit+'/'+path, timeout=30) as response:
        return response.read()

def main():
    commit = sys.argv[1]
    assert re.fullmatch('[0-9a-f]{40}', commit)
    blueprint = json.loads(fetch(commit, PREFIX+'blueprint.json'))
    edits = json.loads(fetch(commit, PREFIX+'edits.json'))
    rows = []
    for row in blueprint['files']:
        destination = HOME/row['destination']
        assert '..' not in destination.parts and not destination.is_symlink()
        before = destination.read_bytes() if destination.exists() else None
        assert not row.get('new') or before is None, 'New destination already exists: '+row['destination']
        assert not row.get('patch') or before is not None
        if row.get('patch'):
            after = before
            for edit in edits[row['source']]:
                old, new = edit['old'].encode(), edit['new'].encode()
                assert after.count(old) == edit['count'], 'Unexpected anchor: '+row['source']+' '+edit['old'][:85]
                after = after.replace(old, new)
        else:
            after = fetch(commit, row['source'])
        rows.append(dict(row, before=hashlib.sha256(before).hexdigest() if before is not None else None,
                         after=hashlib.sha256(after).hexdigest()))
    print(json.dumps({'files': rows}))

if __name__ == '__main__': main()
