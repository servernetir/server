#!/usr/bin/env python3
"""Read-only acceptance of a completed growth conversion release."""
import hashlib
import json
import sys
from pathlib import Path

if len(sys.argv) not in (3, 4):
    raise SystemExit('Usage: verify-growth-conversion.py LIVE STAGE [API_ROUTES_SHA256]')
live, stage = (Path(p).resolve() for p in sys.argv[1:3])
manifest = json.loads((stage / 'manifest.json').read_bytes())
receipt = json.loads((stage / 'receipt.json').read_bytes())
if receipt.get('status') != 'installed' or len(manifest) != 14:
    raise SystemExit('Expected completed 14-file release')
sha = lambda data: hashlib.sha256(data).hexdigest()
failures = []
for name, hashes in manifest.items():
    relative = Path(name)
    if relative.is_absolute() or '..' in relative.parts:
        raise SystemExit('Invalid manifest path')
    current = (live / relative).read_bytes()
    staged = (stage / 'staged' / relative).read_bytes()
    if sha(current) != hashes['after'] or current != staged:
        failures.append(name)
    if hashes['before'] is not None:
        if sha((stage / 'before' / relative).read_bytes()) != hashes['before']:
            failures.append('backup:' + name)
routes = (live / 'routes/web.php').read_bytes()
api_matches = None
if len(sys.argv) == 4:
    api_matches = sha(routes.replace(b'\r\n', b'\n')) == sys.argv[3]
route_count = routes.count(b"name('report.consultation')")
result = {
    'status': 'raw_verified' if not failures and route_count == 1 else 'mismatch',
    'files': len(manifest),
    'failures': failures,
    'consultation_route_count': route_count,
    'routes_crlf': routes.count(b'\r\n'),
    'api_matches_lf_normalized_routes': api_matches,
    'application_writes': 0,
    'database_access': False,
}
print(json.dumps(result))
raise SystemExit(0 if result['status'] == 'raw_verified' else 1)
