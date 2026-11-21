#!/usr/bin/env python3
"""Build a reviewed overlay manifest against a specified develop commit/ref."""
import difflib
import hashlib
import json
from pathlib import Path
import subprocess
import sys

base = sys.argv[1]
paths = set(subprocess.check_output(['git', 'diff', '--name-only', base, '--', 'website']).decode().splitlines())
paths |= set(subprocess.check_output(['git', 'ls-files', '--others', '--exclude-standard', 'website']).decode().splitlines())
files = []
for full in sorted(paths):
    if full.startswith('website/tests/'):
        continue
    path = full[len('website/'):]
    if not Path(full).is_file():
        raise SystemExit('Deletion is not supported: '+full)
    after = Path(full).read_bytes()
    old = subprocess.run(['git', 'show', base+':'+full], capture_output=True)
    item = {'path': path, 'sha256': hashlib.sha256(after).hexdigest()}
    if old.returncode:
        item['kind'] = 'new'
    elif path in ['composer.json', 'composer.lock']:
        item['kind'] = 'replace'
    else:
        item['kind'] = 'patch'
        a = old.stdout.decode().splitlines(keepends=True)
        b = after.decode().splitlines(keepends=True)
        item['patches'] = [
            {'before': ''.join(a[g[0][1]:g[-1][2]]), 'after': ''.join(b[g[0][3]:g[-1][4]])}
            for g in difflib.SequenceMatcher(a=a, b=b, autojunk=False).get_grouped_opcodes(n=1)
        ]
        if path == 'app/Models/User.php':
            item['patches'] = [
                {'before': 'class User extends Authenticatable\n',
                 'after': 'class User extends Authenticatable implements \\Laravel\\Passport\\Contracts\\OAuthenticatable\n'},
                {'before': '    use HasFactory, HasTwoFactor, Notifiable;\n',
                 'after': '    use HasFactory, HasTwoFactor, Notifiable;\n    use \\Laravel\\Passport\\HasApiTokens;\n'},
            ]
    files.append(item)
manifest = {'files': files, 'migration_paths': [f['path'] for f in files if f['path'].startswith('database/migrations/')]}
Path('scripts/admin-mcp-release-20261121.json').write_text(json.dumps(manifest, indent=2, ensure_ascii=False)+'\n')
print(f'Manifest: {len(files)} files, {len(manifest["migration_paths"])} migrations')
