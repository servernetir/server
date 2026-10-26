#!/usr/bin/env python3
"""Narrow, hash-guarded additive deployment. Default is a no-write preflight.
Usage: python3 SCRIPT SOURCE_WEBSITE LIVE_APP PRIVATE_STAGE [--execute]
Never restores a database, changes credentials, or enables the supervisor.
"""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys

src, live, stage = (Path(p).resolve() for p in sys.argv[1:4])
execute = sys.argv[4:] == ['--execute']
if len(sys.argv) not in [4, 5] or (len(sys.argv) == 5 and not execute):
    raise SystemExit('Invalid arguments')
php = '/opt/cpanel/ea-php84/root/usr/bin/php'
new = ['app/Services/Growth/HermesSupervisor.php', 'app/Console/Commands/RunHermesSupervisor.php',
       'resources/views/admin/growth/hermes-supervisor.blade.php']
shared = ['app/Http/Controllers/Admin/GrowthController.php', 'routes/ai-growth.php', 'routes/console.php',
          'resources/views/admin/growth/index.blade.php', 'lang/fa/growth.php', 'lang/en/growth.php', 'lang/tr/growth.php']

def once(text, old, replacement):
    if text.count(old) != 1:
        raise RuntimeError('Shared-file anchor differs; review instead of replacing')
    return text.replace(old, replacement, 1)

def digest(data):
    return hashlib.sha256(data).hexdigest()

def patches():
    output = {}
    for name in new:
        if (live/name).exists():
            raise RuntimeError('New target already exists: '+name)
        output[name] = (src/name).read_bytes()
    for name in shared:
        text = (live/name).read_text()
        if name.endswith('GrowthController.php'):
            if 'function supervisor(' in text or "'supervisor' =>" in text:
                raise RuntimeError('Controller already has supervision')
            text = once(text, 'use App\\Services\\Growth\\GrowthJobs;', 'use App\\Services\\Growth\\GrowthJobs;\nuse App\\Services\\Growth\\HermesSupervisor;')
            text = once(text, "'autopilot' => app(GrowthAutopilot::class)->status(),", "'autopilot' => app(GrowthAutopilot::class)->status(),\n            'supervisor' => in_array($tab, ['hermes', 'automation'], true) ? app(HermesSupervisor::class)->status() : null,")
            source = (src/name).read_text()
            method = source[source.index('    public function supervisor('):source.index('    public function googleConnect(')]
            text = once(text, '    public function googleConnect(', method+'    public function googleConnect(')
        elif name == 'routes/ai-growth.php':
            text = once(text, "    Route::post('/settings', [GrowthController::class, 'settings']);", "    Route::post('/settings', [GrowthController::class, 'settings']);\n    Route::post('/supervisor', [GrowthController::class, 'supervisor']);")
        elif name == 'routes/console.php':
            if "'hermes:supervise'" in text:
                raise RuntimeError('Supervisor schedule already exists')
            text += "\n// Bounded knowledge/receipt supervision; independently paused until explicitly enabled.\n\\Illuminate\\Support\\Facades\\Schedule::command('hermes:supervise')->hourly()->withoutOverlapping(10);\n"
        elif name.endswith('index.blade.php'):
            if "admin.growth.hermes-supervisor" in text:
                raise RuntimeError('Supervisor view already included')
            text = once(text, "  @if($tab==='calendar')", "  @if(in_array($tab,['hermes','automation'],true))@include('admin.growth.hermes-supervisor')@endif\n  @if($tab==='calendar')")
        else:
            if "'supervisor_title'" in text:
                raise RuntimeError('Translations already present')
            source = (src/name).read_text()
            rows = '\n'.join(line for line in source.splitlines() if line.startswith("    'supervisor_"))+'\n'
            pos = text.rfind('];')
            if pos < 0:
                raise RuntimeError('Translation array ending missing')
            text = text[:pos]+rows+text[pos:]
        output[name] = text.encode()
    return output

# Coordinate with both release locks used by the existing web deployment process.
locks = []
for path in [Path.home()/'.servernet-web-release.lock', Path.home()/'servernet-release.lock']:
    handle = path.open('a')
    fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
    locks.append(handle)
if stage.exists():
    raise SystemExit('Stage exists. Inspect its receipt; never rerun blindly.')
proposed = patches()
old = {name: (live/name).read_bytes() if (live/name).exists() else None for name in proposed}
if not execute:
    print(json.dumps({'status':'preflight_ok','files':len(proposed),'new':len(new),'writes':0}))
    raise SystemExit(0)
stage.mkdir(mode=0o700, parents=True)
manifest = {}
for name, data in proposed.items():
    target = stage/'staged'/name
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(data)
    subprocess.run([php, '-l', str(target)], check=True, stdout=subprocess.DEVNULL)
    if old[name] is not None:
        before = stage/'before'/name
        before.parent.mkdir(parents=True, exist_ok=True)
        before.write_bytes(old[name])
    manifest[name] = {'before':digest(old[name]) if old[name] is not None else None,'after':digest(data)}
(stage/'manifest.json').write_text(json.dumps(manifest, indent=2))
installed = []
try:
    for name,data in proposed.items():
        target = live/name
        current = target.read_bytes() if target.exists() else None
        if current != old[name]:
            raise RuntimeError('Live drift before write: '+name)
        target.parent.mkdir(parents=True, exist_ok=True)
        sibling = target.with_name(target.name+'.hermes-staged')
        if sibling.exists():
            raise RuntimeError('Stale sibling needs review')
        sibling.write_bytes(data)
        os.replace(sibling, target)
        installed.append(name)
    subprocess.run([php, 'artisan', 'view:cache'], cwd=live, check=True, stdout=subprocess.DEVNULL)
    for name,data in proposed.items():
        if (live/name).read_bytes() != data:
            raise RuntimeError('Installed hash mismatch')
    (stage/'receipt.json').write_text(json.dumps({'status':'installed','files':len(installed),'enabled':False}))
    print(json.dumps({'status':'installed','files':len(installed),'stage':str(stage),'enabled':False}))
except BaseException:
    for name in reversed(installed):
        target = live/name
        if target.read_bytes() != proposed[name]:
            raise RuntimeError('Rollback stopped on later drift: '+name)
        if old[name] is None:
            target.unlink()
        else:
            target.write_bytes(old[name])
    (stage/'receipt.json').write_text(json.dumps({'status':'rolled_back','files':len(installed)}))
    raise
