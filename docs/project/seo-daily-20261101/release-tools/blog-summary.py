#!/usr/bin/env python3
"""Collect sanitized private release receipts; never bootstrap the application."""
import json
import os
from pathlib import Path
import tempfile

ROOT=Path('/home/servernetcloud/.seo-blog-authors-20261101')
if ROOT.resolve()!=ROOT or ROOT.is_symlink() or ROOT.stat().st_uid!=os.getuid():
    raise SystemExit('private_stage_invalid')
data={'release':'seo-blog-authors-20261101','source_scope':['app/Services/BlogRepository.php'],'receipts':{}}
files=[ROOT/name for name in ['stage-verified.json','preview.json','intent.json','applied.json','rollback.json']]
files+=sorted(ROOT.glob('fpm-*.json'))
for path in files:
    if not path.exists():continue
    if path.is_symlink() or path.stat().st_uid!=os.getuid() or path.stat().st_size>1048576:
        raise SystemExit('receipt_invalid')
    data['receipts'][path.name]=json.loads(path.read_text(encoding='utf-8'))
fd,tmp=tempfile.mkstemp(prefix='.summary-',dir=ROOT)
try:
    with os.fdopen(fd,'w',encoding='utf-8') as stream:json.dump(data,stream,indent=2);stream.write('\n')
    os.chmod(tmp,0o600)
    os.replace(tmp,ROOT/'release-summary.json')
finally:
    if os.path.exists(tmp):os.unlink(tmp)
print(json.dumps({'status':'written','file':'release-summary.json','receipts':len(data['receipts'])}))
