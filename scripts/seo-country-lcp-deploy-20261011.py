"""Execute only this audited SEO manifest; abort if production has changed."""
import argparse
import hashlib
import json
import os
import shutil
import subprocess
from pathlib import Path

HERE = Path(__file__).resolve().parent
APP = Path('/home/servernetcloud/servernet_app')
WEB = Path('/home/servernetcloud/public_html')
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'

def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else None

def target(rel):
    p = Path(rel)
    assert not p.is_absolute() and '..' not in p.parts
    assert rel in ['app/Services/Cloud/LandingPageStyles.php', 'resources/css/cloud-location-lcp.css', 'resources/css/cloud-location-lcp.json', 'resources/views/layouts/site.blade.php']
    return WEB / Path(*p.parts[1:]) if p.parts[0] == 'public' else APP / p

def run(args):
    r = subprocess.run(args, cwd=APP, text=True, capture_output=True)
    if r.returncode:
        raise RuntimeError(r.stdout + r.stderr)
    print(r.stdout.strip())

def atomic(src, dst):
    dst.parent.mkdir(parents=True, exist_ok=True)
    temp = dst.with_name(dst.name + '.seo-stage-country-lcp-20261011')
    assert not temp.exists(), temp
    shutil.copyfile(src, temp)
    os.chmod(temp, 0o644)
    os.replace(temp, dst)

def clear_pages():
    run([PHP, 'artisan', 'view:clear'])
    purge = HERE / 'purge-pages.php'
    purge.write_text("<?php require '/home/servernetcloud/servernet_app/vendor/autoload.php'; $app = require '/home/servernetcloud/servernet_app/bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); App\\Http\\Middleware\\PageCache::purge(); echo 'SEO_PAGE_CACHE_PURGED';")
    run([PHP, str(purge)])

def main():
    global HERE
    args = argparse.ArgumentParser()
    args.add_argument('--apply', action='store_true')
    args.add_argument('--rollback', action='store_true')
    args.add_argument('--packet-dir', type=Path, default=HERE,
                      help='Directory containing manifest.json, files/ and private backup/')
    a = args.parse_args()
    HERE = a.packet_dir.resolve()
    manifest = json.loads((HERE / 'manifest.json').read_text())
    marker = HERE / 'APPLIED.json'
    if a.rollback:
        assert marker.exists(), 'No completed deployment to roll back'
        for item in manifest:
            assert digest(target(item['path'])) == item['after'], 'Concurrent change: '+item['path']
            if item['before'] is not None:
                assert digest(HERE / 'backup' / item['path']) == item['before'], 'Backup mismatch: '+item['path']
        for item in reversed(manifest):
            dst = target(item['path'])
            if item['before'] is None:
                dst.unlink()
            else:
                atomic(HERE / 'backup' / item['path'], dst)
        clear_pages()
        marker.rename(HERE / 'ROLLED-BACK.json')
        print('SEO_ROLLBACK_OK')
        return
    assert not marker.exists(), 'Already applied; inspect, do not repeat'
    for item in manifest:
        assert digest(target(item['path'])) == item['before'], 'Production drift: '+item['path']
        assert digest(HERE / 'files' / item['path']) == item['after'], 'Packet mismatch'
    # Compile staged Blade templates and PHP-lint without executing app requests.
    compiler = HERE / 'lint-blade.php'
    compiler.write_text('''<?php
require '/home/servernetcloud/servernet_app/vendor/autoload.php';
$compiler = new Illuminate\\View\\Compilers\\BladeCompiler(new Illuminate\\Filesystem\\Filesystem, __DIR__.'/blade-check-cache');
foreach (array_slice($argv, 1) as $file) {
  $output = $file.'.compiled.php';
  file_put_contents($output, $compiler->compileString(file_get_contents($file)));
  passthru(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($output), $result);
  if ($result !== 0) exit($result);
}
''')
    blades = [str(HERE / 'files' / i['path']) for i in manifest if i['path'].endswith('.blade.php')]
    run([PHP, str(compiler)] + blades)
    for item in manifest:
        if item['path'].endswith('.php') and not item['path'].endswith('.blade.php'):
            run([PHP, '-l', str(HERE / 'files' / item['path'])])
    print('SEO_PREFLIGHT_OK', len(manifest))
    if not a.apply:
        return
    backup = HERE / 'backup'
    backup.mkdir(exist_ok=False)
    # Check again after lint; preserve other work on this shared server.
    for item in manifest:
        assert digest(target(item['path'])) == item['before'], 'Production drift after lint'
        if item['before'] is not None:
            dst = backup / item['path']
            dst.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(target(item['path']), dst)
    installed = []
    try:
        # Dependencies before templates; CSS source before generated CSS for mtime fallback.
        ordered = sorted(manifest, key=lambda i: (0 if i['before'] is None and not i['path'].endswith('site.min.css') else 1 if i['path'].endswith('site.css') else 2 if i['path'].endswith('site.min.css') else 3))
        for item in ordered:
            assert digest(target(item['path'])) == item['before'], 'Concurrent change during install'
            atomic(HERE / 'files' / item['path'], target(item['path']))
            installed.append(item)
        clear_pages()
        for item in manifest:
            assert digest(target(item['path'])) == item['after'], 'Installed hash mismatch'
        marker.write_text(json.dumps({'files': len(manifest), 'status': 'installed; verify public pages'}, indent=2))
        print('SEO_DEPLOY_OK', len(manifest))
    except Exception:
        for item in reversed(installed):
            dst = target(item['path'])
            if digest(dst) != item['after']:
                print('ROLLBACK_NEEDS_REVIEW', item['path'])
                continue
            if item['before'] is None:
                dst.unlink()
            else:
                atomic(backup / item['path'], dst)
        clear_pages()
        raise

if __name__ == '__main__':
    main()
