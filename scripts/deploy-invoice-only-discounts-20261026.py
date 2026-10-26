#!/usr/bin/env python3
"""Dated, guarded UI/access release. No migrations, settings or financial writes."""
import fcntl, hashlib, json, os, re, subprocess, sys
from pathlib import Path

src = Path(sys.argv[1]).resolve()
live = Path('/home/servernetcloud/servernet_app')
stage = live.parent / 'deploy-backups/invoice-only-discounts-20261026'
mode = (live.parent / 'invoice-only-discount-phase-20261026').read_text().strip()
if src != live.parent / 'invoice-only-source-20261026' or mode not in ['preflight', 'apply', 'verify']:
    raise SystemExit('Unexpected source or sealed/invalid phase')
locks = []
for name in ['.servernet-web-release.lock', 'servernet-release.lock']:
    handle = (live.parent / name).open('a')
    fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
    locks.append(handle)
php = '/opt/cpanel/ea-php84/root/usr/bin/php'
plan = json.loads((src / 'docs/project/invoice-only-discounts-20261026/overlay.json').read_text())
def sha(data): return hashlib.sha256(data).hexdigest() if data is not None else None
def read(path): return path.read_bytes() if path.exists() else None
def atomic(path, data):
    if data is None:
        path.unlink()
        return
    path.parent.mkdir(parents=True, exist_ok=True)
    temp = path.with_name(path.name + '.invoice-only-staged')
    if temp.exists(): raise RuntimeError('Stale sibling: ' + str(path))
    temp.write_bytes(data); temp.chmod(0o644); os.replace(temp, path)
def php_json(code):
    result = subprocess.run([php, '-r', code], cwd=live, capture_output=True, text=True)
    if result.returncode: raise RuntimeError('PHP step failed: ' + result.stdout[-1500:] + result.stderr[-1000:])
    return json.loads(result.stdout)
boot = "chdir(" + json.dumps(str(live)) + ");require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
state_code = "echo json_encode(['default'=>App\\Models\\Setting::get('invoice_discount_entry_enabled','0'),'overrides'=>App\\Models\\Customer::where('discount_entry_visibility','!=','inherit')->count(),'customers'=>App\\Models\\Customer::count(),'driver'=>Illuminate\\Support\\Facades\\DB::connection()->getDriverName()]);"
for entry in plan['files']:
    path = entry['path']
    if Path(path).is_absolute() or '..' in Path(path).parts: raise RuntimeError('Invalid target')
    if sha(read(src / 'website' / path)) != entry['source_sha256']: raise RuntimeError('Source manifest mismatch: ' + path)
if mode == 'preflight':
    if stage.exists(): raise SystemExit('Stage exists; inspect receipts before reuse')
    output = {}; before = {}
    for entry in plan['files']:
        path = entry['path']; data = read(live / path); before[path] = data
        if entry['kind'] == 'add':
            if data is not None: raise RuntimeError('New target exists: ' + path)
            output[path] = (src / 'website' / path).read_bytes()
        elif entry['kind'] == 'delete':
            if data is None or data.decode().replace('\r\n', '\n') != entry['base']: raise RuntimeError('Retired view drift')
            output[path] = None
        else:
            if data is None: raise RuntimeError('Missing shared target: ' + path)
            text = data.decode()
            for hunk in entry['hunks']:
                pattern = r'\r?\n'.join(re.escape(line) for line in hunk['old'].split('\n'))
                matches = list(re.finditer(pattern, text))
                if len(matches) != 1: raise RuntimeError('Changed/nonunique live hunk: ' + path)
                match = matches[0]; ending = '\r\n' if '\r\n' in match.group(0) else '\n'
                text = text[:match.start()] + hunk['new'].replace('\n', ending) + text[match.end():]
            output[path] = text.encode()
    state = php_json(boot + state_code)
    stage.mkdir(parents=True, mode=0o700)
    manifest = {}
    for path, data in output.items():
        for subdir, body in [('before', before[path]), ('staged', data)]:
            if body is None: continue
            target = stage / subdir / path; target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body); target.chmod(0o600)
        if data is not None: subprocess.run([php, '-l', str(stage / 'staged' / path)], check=True, stdout=subprocess.DEVNULL)
        manifest[path] = {'before': sha(before[path]), 'after': sha(data)}
    (stage / 'manifest.json').write_text(json.dumps(manifest, indent=2))
    (stage / 'preflight.json').write_text(json.dumps({'status': 'ready', 'files': len(manifest), 'state': state, 'application_writes': 0}))
    print(json.dumps({'status': 'ready', 'files': len(manifest)})); raise SystemExit(0)
manifest = json.loads((stage / 'manifest.json').read_text())
if mode == 'verify':
    if not all(sha(read(live / path)) == value['after'] for path, value in manifest.items()): raise RuntimeError('Installed hash drift')
    result = subprocess.run([php, str(src / 'scripts/verify-invoice-only-discounts-20261026.php'), str(live)], cwd=live, capture_output=True, text=True)
    if result.returncode: raise RuntimeError('Acceptance failed: ' + result.stdout[-1800:] + result.stderr[-1000:])
    report = json.loads(result.stdout); (stage / 'acceptance.json').write_text(json.dumps(report, indent=2)); print(json.dumps(report)); raise SystemExit(0)
if (stage / 'installed.json').exists(): raise SystemExit('Already installed; do not repeat apply')
for path, value in manifest.items():
    if sha(read(live / path)) != value['before'] or sha(read(stage / 'staged' / path)) != value['after']:
        raise RuntimeError('Live/stage drift before apply: ' + path)
installed = []
try:
    order = sorted(manifest, key=lambda p: 5 if manifest[p]['after'] is None else 0 if manifest[p]['before'] is None else 4 if p == 'routes/web.php' else 3 if '/Controllers/' in p else 1)
    for path in order:
        if sha(read(live / path)) != manifest[path]['before']: raise RuntimeError('Live drift: ' + path)
        atomic(live / path, read(stage / 'staged' / path)); installed.append(path)
    blades = [str(live / path) for path, value in manifest.items() if path.endswith('.blade.php') and value['after']]
    php_json(boot + "Illuminate\\Support\\Facades\\Artisan::call('route:clear');foreach(json_decode(" + json.dumps(json.dumps(blades)) + ",true) as $p)app('blade.compiler')->compile($p);echo json_encode(['ok'=>true]);")
    before_state = json.loads((stage / 'preflight.json').read_text())['state']
    after_state = php_json(boot + state_code)
    if after_state['default'] != before_state['default'] or after_state['overrides'] != before_state['overrides']: raise RuntimeError('Preference state changed')
    if not all(sha(read(live / path)) == value['after'] for path, value in manifest.items()): raise RuntimeError('Installed hash drift')
    report = {'status': 'installed', 'files': len(installed), 'preference_state_preserved': True, 'migrations': 0, 'financial_sql_writes': 0}
    (stage / 'installed.json').write_text(json.dumps(report, indent=2)); print(json.dumps(report))
except BaseException:
    for path in reversed(installed):
        if sha(read(live / path)) != manifest[path]['after']: raise RuntimeError('Later live drift; manual rollback needed')
        atomic(live / path, read(stage / 'before' / path))
    (stage / 'failed.json').write_text(json.dumps({'restored': len(installed)})); raise
