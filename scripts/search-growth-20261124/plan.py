#!/usr/bin/env python3
"""Build exact reviewed file hashes and small overlays from private live preimages."""
import hashlib
import json
from pathlib import Path
import sys

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
VIEWS = ['layouts.site', 'pages.ai.home', 'pages.blog-post', 'pages.gpu', 'pages.n8n',
         'partials.seo-python-fit', 'partials.search-growth-guide', 'partials.gpu-styles',
         'partials.growth-guides', 'partials.chat', 'partials.vps-plan-table']


def sha(data):
    return hashlib.sha256(data).hexdigest()


def block(text, start, end):
    if text.count(start) != 1 and '\r\n' in text:
        start = start.replace('\n', '\r\n')
    assert text.count(start) == 1
    at = text.index(start)
    last = text.index(end, at) + len(end)
    return text[at:last]


def edits_for(relative, before, source):
    edits = []
    if relative == 'resources/views/layouts/site.blade.php':
        start, end = '@php\n    $siteCssSource', "@stack('head')"
        replacement = block(source, start, end)
        if before.count('\n') == before.count('\r\n'):
            replacement = replacement.replace('\r\n', '\n').replace('\n', '\r\n')
        edits.append((block(before, start, end), replacement))
    elif relative == 'resources/views/pages/gpu.blade.php':
        edits.append(('<link rel="stylesheet" href="{{ asset_ver(\'assets/css/gpu-alternatives.css\') }}">', "@include('partials.gpu-styles')"))
        old = "    @include('partials.growth-guides', ['growthPage' => 'gpu'])"
        edits.append((old, "    @include('partials.search-growth-guide', ['searchTopic' => 'gpu'])\n" + old))
    elif relative == 'resources/views/partials/chat.blade.php':
        old = '<link rel="stylesheet" href="{{ asset_ver(\'assets/css/chat-morph.css\') }}">'
        edits.append((old, block(source, '@php $chatMorphCss', '@endif')))
    elif relative == 'resources/views/partials/vps-plan-table.blade.php':
        old = '<link rel="stylesheet" href="{{ asset_ver(\'assets/css/vps-catalog.css\') }}">'
        edits.append((old, block(source, '@php $vpsCatalogCss', '@endif')))
    return [{'old': old, 'new': new, 'count': 1} for old, new in edits]


def main():
    snapshot = Path(sys.argv[1]).resolve()
    blueprint = json.loads((HERE / 'blueprint.json').read_text())
    rows, all_edits = [], {}
    for row in blueprint['files']:
        relative = row['path']
        source = 'website/' + relative
        before = (snapshot / relative).read_bytes() if row['before'] is not None else None
        assert (sha(before) if before is not None else None) == row['before'], relative
        after = (REPO / source).read_bytes()
        edits = edits_for(relative, before.decode() if before else '', after.decode())
        if edits:
            after = before
            for edit in edits:
                assert after.count(edit['old'].encode()) == edit['count'], relative
                after = after.replace(edit['old'].encode(), edit['new'].encode())
            all_edits[source] = edits
        record = {'source': source, 'destination': 'servernet_app/' + relative,
                  'before': row['before'], 'after': sha(after)}
        if edits:
            record['patch'] = True
        rows.append(record)
    helpers = {}
    for name, relative in [
        ('activation_common.py', 'scripts/mcp-auto-approval-20261122/activate.py'),
        ('fcgi.py', 'scripts/mcp-auto-approval-20261122/fcgi.py'),
        ('activate.php', 'scripts/search-growth-20261124/activate.php'),
        ('lint.php', 'scripts/search-growth-20261124/lint.php'),
    ]:
        helpers[name] = {'path': relative, 'sha256': sha((REPO / relative).read_bytes())}
    (HERE / 'manifest.json').write_text(json.dumps({'files': rows, 'views': VIEWS, 'helpers': helpers}, indent=2) + '\n')
    (HERE / 'edits.json').write_text(json.dumps(all_edits, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({'files': len(rows), 'overlays': len(all_edits), 'compiled_views': len(VIEWS)}))


if __name__ == '__main__':
    main()
