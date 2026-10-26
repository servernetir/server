#!/usr/bin/env python3
"""Narrow pending-request presentation release. Default: prepare only, no live writes."""
import argparse
import difflib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import urllib.request

BASE = '2c251bef0c499ca99dc3e95963ff40017f226c6f'  # Only the unchanged view anchors are read from this revision.
VIEW = 'resources/views/account/cloud-server.blade.php'
PARTIAL = 'resources/views/account/partials/gpu-capacity-wait.blade.php'
CSS = 'public/assets/css/cloud-request.css'
LANGS = [f'lang/{locale}/ui.php' for locale in ('fa', 'en', 'tr')]
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'


def digest(data):
    return hashlib.sha256(data).hexdigest()


def once(text, old, new):
    pattern = r'\r?\n'.join(re.escape(line) for line in old.split('\n'))
    matches = list(re.finditer(pattern, text))
    if len(matches) != 1:
        raise RuntimeError('Live anchor differs; review before retrying')
    match = matches[0]
    ending = '\r\n' if '\r\n' in match.group() else '\n'
    return text[:match.start()] + new.replace('\n', ending) + text[match.end():]


def candidates(source, base_view, home):
    output = {}
    for path in [CSS, PARTIAL]:
        target = 'servernet_app/' + path
        if (home / target).exists():
            raise RuntimeError('New target already exists: ' + target)
        output[target] = source[path]
    public_css = 'public_html/assets/css/cloud-request.css'
    if (home / public_css).exists():
        raise RuntimeError('New public asset already exists')
    output[public_css] = source[CSS]
    for path in LANGS:
        text = (home / 'servernet_app' / path).read_bytes().decode()
        if 'gpu_request_status' in text:
            raise RuntimeError('Translation keys already present')
        new = source[path].decode()
        block = new[new.index('    // Pending cloud request presentation.'):new.rfind('];')]
        output['servernet_app/' + path] = once(text, '\n];', '\n' + block + '];').encode()
    text = (home / 'servernet_app' / VIEW).read_bytes().decode()
    old_lines = base_view.decode().splitlines(keepends=True)
    new_lines = source[VIEW].decode().splitlines(keepends=True)
    groups = difflib.SequenceMatcher(None, old_lines, new_lines, autojunk=False).get_grouped_opcodes(n=3)
    for group in groups:
        old = ''.join(old_lines[group[0][1]:group[-1][2]])
        new = ''.join(new_lines[group[0][3]:group[-1][4]])
        text = once(text, old, new)
    output['servernet_app/' + VIEW] = text.encode()
    return output


def run_php(home, stage, compile_live=False):
    root = home / 'servernet_app'
    for path in LANGS:
        target = root / path if compile_live else stage / 'staged/servernet_app' / path
        subprocess.run([PHP, '-l', str(target)], check=True, stdout=subprocess.DEVNULL)
    # Compile only the two changed templates; never clear shared cache or all views.
    code = '''require $argv[1].'/vendor/autoload.php';
$app=require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$compiler=$app->make('blade.compiler');
foreach(array_slice($argv,3) as $i=>$file){
 if($argv[2]==='live'){$compiler->compile($file);}
 else{file_put_contents($argv[2].'/compiled-'.$i.'.php',$compiler->compileString(file_get_contents($file)));}
}'''
    mode = 'live' if compile_live else str(stage)
    paths = [str((root if compile_live else stage / 'staged/servernet_app') / path) for path in (VIEW, PARTIAL)]
    subprocess.run([PHP, '-r', code, str(root), mode, *paths], check=True, stdout=subprocess.DEVNULL)
    if not compile_live:
        for path in stage.glob('compiled-*.php'):
            subprocess.run([PHP, '-l', str(path)], check=True, stdout=subprocess.DEVNULL)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source-sha', required=True)
    parser.add_argument('--release', required=True)
    parser.add_argument('--execute', action='store_true')
    args = parser.parse_args()
    if not re.fullmatch(r'[0-9a-f]{40}', args.source_sha) or not re.fullmatch(r'pending-request-ui-[a-z0-9-]+', args.release):
        raise SystemExit('Invalid immutable source or release ID')
    home = Path.home()
    stage = home / '.servernet-ui-releases' / args.release
    handles = []
    for name in ('.servernet-web-release.lock', 'servernet-release.lock'):
        handle = (home / name).open('a')
        fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        handles.append(handle)
    if args.execute:
        manifest = json.loads((stage / 'manifest.json').read_text())
        if manifest['source_sha'] != args.source_sha or (stage / 'receipt.json').exists():
            raise SystemExit('Release already executed or source differs; inspect receipt')
    else:
        if stage.exists():
            raise SystemExit('Stage exists; inspect it before retrying')
        base = f'https://raw.githubusercontent.com/servernetir/server/{args.source_sha}/website/'
        source = {}
        for path in (VIEW, PARTIAL, CSS, *LANGS):
            with urllib.request.urlopen(base + path, timeout=30) as response:
                source[path] = response.read()
        with urllib.request.urlopen(f'https://raw.githubusercontent.com/servernetir/server/{BASE}/website/{VIEW}', timeout=30) as response:
            base_view = response.read()
        output = candidates(source, base_view, home)
        stage.mkdir(parents=True, mode=0o700)
        manifest = {'source_sha': args.source_sha, 'files': {}}
        for relative, data in output.items():
            live = home / relative
            before = live.read_bytes() if live.exists() else None
            staged = stage / 'staged' / relative
            staged.parent.mkdir(parents=True, exist_ok=True)
            staged.write_bytes(data)
            if before is not None:
                backup = stage / 'before' / relative
                backup.parent.mkdir(parents=True, exist_ok=True)
                backup.write_bytes(before)
            manifest['files'][relative] = {'before': digest(before) if before is not None else None, 'after': digest(data)}
        (stage / 'manifest.json').write_text(json.dumps(manifest, indent=2))
        run_php(home, stage)
        (stage / 'preflight-ok').write_text('Staged PHP and Blade lint passed.\n')
        print(json.dumps({'status': 'prepared', 'files': len(output), 'live_writes': 0, 'lint': 'passed'}))
        return
    if not (stage / 'preflight-ok').exists():
        raise SystemExit('Successful preflight required')
    files = manifest['files']
    for relative, item in files.items():
        live = home / relative
        actual = digest(live.read_bytes()) if live.exists() else None
        if actual != item['before'] or digest((stage / 'staged' / relative).read_bytes()) != item['after']:
            raise SystemExit('Live or staged drift: ' + relative)
    installed = []
    try:
        # CSS, partial and translations precede activation in the shared view.
        for relative, item in files.items():
            live = home / relative
            if (digest(live.read_bytes()) if live.exists() else None) != item['before']:
                raise RuntimeError('Concurrent live edit: ' + relative)
            temporary = live.with_name(live.name + '.pending-ui-stage')
            with temporary.open('xb') as handle:
                handle.write((stage / 'staged' / relative).read_bytes())
            os.chmod(temporary, stat.S_IMODE(live.stat().st_mode) if live.exists() else 0o644)
            os.replace(temporary, live)
            installed.append(relative)
        run_php(home, stage, compile_live=True)
        for relative, item in files.items():
            if digest((home / relative).read_bytes()) != item['after']:
                raise RuntimeError('Final hash mismatch: ' + relative)
        receipt = {'status': 'installed', 'files': len(installed), 'hashes_verified': True, 'targeted_blade_compilation': True}
        (stage / 'receipt.json').write_text(json.dumps(receipt))
        print(json.dumps(receipt))
    except BaseException:
        for relative in reversed(installed):
            live = home / relative
            if digest(live.read_bytes()) != files[relative]['after']:
                raise RuntimeError('Later live drift; manual rollback required: ' + relative)
            backup = stage / 'before' / relative
            if backup.exists():
                live.write_bytes(backup.read_bytes())
            else:
                live.unlink()  # Only this release's newly-created, still-matching file.
        raise


if __name__ == '__main__':
    main()
