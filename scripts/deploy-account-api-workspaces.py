#!/usr/bin/env python3
"""Release the two customer API workspaces with the existing guarded web runner.

Default: prepare ten targets without writing active files. Install only with --execute.
"""
import difflib
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import sys
import urllib.request

helper = Path(__file__).with_name('deploy-pending-request-ui.py')
if hashlib.sha256(helper.read_bytes()).hexdigest() != '2b7844c9bb91d28170965ba76ca277c3d5b48e4aabf7617393c09a0ff3dfbc92':
    raise SystemExit('Shared runner differs; review before release')
spec = importlib.util.spec_from_file_location('guarded_pending_ui', helper)
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)

runner.VIEW = 'resources/views/account/identity.blade.php'
runner.PARTIAL = 'resources/views/account/ai.blade.php'
runner.CSS = 'public/assets/css/account-api.css'
runner.LANGS = [f'lang/{locale}/{product}.php'
                for locale in ('fa', 'en', 'tr') for product in ('identity', 'ai')]
MARKERS = {
    'identity': '    // Customer identity API workspace presentation.',
    'ai': '    // Customer AI workspace presentation.',
}


def fetch(url):
    request = urllib.request.Request(url, headers={'User-Agent': 'ServerNet-Guarded-Release'})
    with urllib.request.urlopen(request, timeout=30) as response:
        return response.read()


def patched(live, before, after):
    # Git content is LF. Match each live hunk with the shared CRLF-aware guard.
    old_lines = before.decode().replace('\r\n', '\n').splitlines(keepends=True)
    new_lines = after.decode().replace('\r\n', '\n').splitlines(keepends=True)
    text = live.decode()
    for group in difflib.SequenceMatcher(None, old_lines, new_lines, autojunk=False).get_grouped_opcodes(n=3):
        old = ''.join(old_lines[group[0][1]:group[-1][2]])
        new = ''.join(new_lines[group[0][3]:group[-1][4]])
        text = runner.once(text, old, new)
    return text.encode()


def translation(live, source, product):
    text = source.decode().replace('\r\n', '\n')
    marker = MARKERS[product]
    if text.count(marker) != 1 or text.rfind('];') < text.index(marker):
        raise RuntimeError('Workspace translation block differs')
    block = text[text.index(marker):text.rfind('];')]
    keys = re.findall(r"^\s*'([^']+)'\s*=>", block, flags=re.M)
    if not keys or len(keys) != len(set(keys)) or any(not key.startswith('workspace_') for key in keys):
        raise RuntimeError('Unexpected keys in workspace translation block')
    current = live.decode()
    for key in keys:
        if re.search(r"['\"]" + re.escape(key) + r"['\"]\s*=>", current):
            raise RuntimeError('Workspace translation key already exists: ' + key)
    return runner.once(current, '\n];', '\n\n' + block + '];').encode()


def candidates(source, unused_base_view, home):
    # Use the exact published commit parent; never import another developer's diff.
    args = sys.argv
    sha = args[args.index('--source-sha') + 1]
    commit = json.loads(fetch(f'https://api.github.com/repos/servernetir/server/git/commits/{sha}'))
    if len(commit['parents']) != 1:
        raise RuntimeError('A single reviewed source parent is required')
    parent = commit['parents'][0]['sha']
    before_url = f'https://raw.githubusercontent.com/servernetir/server/{parent}/website/'

    output = {}
    for relative in ('servernet_app/' + runner.CSS, 'public_html/assets/css/account-api.css'):
        if (home / relative).exists():
            raise RuntimeError('New workspace asset already exists; inspect prior release: ' + relative)
        output[relative] = source[runner.CSS]
    # Translations and CSS precede the two views that use them.
    for path in runner.LANGS:
        product = Path(path).stem
        target = 'servernet_app/' + path
        output[target] = translation((home / target).read_bytes(), source[path], product)
    for path in (runner.VIEW, runner.PARTIAL):
        target = 'servernet_app/' + path
        output[target] = patched((home / target).read_bytes(), fetch(before_url + path), source[path])
    if len(output) != 10:
        raise RuntimeError('Unexpected release target count')
    return output


if __name__ == '__main__':
    runner.candidates = candidates
    runner.main()
