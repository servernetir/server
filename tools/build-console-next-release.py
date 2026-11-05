#!/usr/bin/env python3
"""Build a deterministic, narrowly scoped release from reviewed working-tree bytes.

No upload, activation, Git mutation or production access occurs. Shared files are
guarded against the exact develop base; drift needs review and a rebuilt release.
"""
import argparse
import difflib
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import zipfile

REPOSITORY = Path(__file__).resolve().parents[1]
DEFAULT_BASE = 'e133689778abfb3abbd8579965ecd938216a181c'
INSTALLER = Path(__file__).with_name('console-next-install.py')
sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('console_next_installer', INSTALLER)
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)


def git(*args, check=True):
    return subprocess.run(['git', '-C', str(REPOSITORY), *args], check=check, stdout=subprocess.PIPE, stderr=subprocess.PIPE)


def build(base, output):
    base_sha = git('rev-parse', '--verify', base + '^{commit}').stdout.decode().strip()
    entries, payload, patches = [], {}, []
    for source in installer.ALLOWLIST:
        path = REPOSITORY / source
        installer.reject_symlinks(path)
        if not path.is_file():
            raise installer.ReleaseError('Missing reviewed release dependency: ' + source)
        data = path.read_bytes()
        original = git('show', base_sha + ':' + source, check=False)
        before = original.stdout if original.returncode == 0 else None
        root = 'webroot' if source.startswith('website/public/') else 'app'
        target = source[len('website/public/'):] if root == 'webroot' else source[len('website/'):]
        entries.append({'source_path': source, 'target_root': root, 'target_path': target,
                        'destination_relative_to_home': ('public_html/' if root == 'webroot' else 'servernet_app/') + target,
                        'expected_before_sha256_or_absent': installer.digest(before) if before is not None else None,
                        'after_sha256': installer.digest(data), 'operation': 'replace' if before is not None else 'add',
                        'payload_path': 'payload/' + source, 'mode': 0o644})
        payload['payload/' + source] = data
        patches.extend(difflib.unified_diff((before or b'').decode().splitlines(keepends=True), data.decode().splitlines(keepends=True),
                                          fromfile='a/' + source if before is not None else '/dev/null', tofile='b/' + source))
    manifest = {'schema': 1, 'base_sha': base_sha, 'source_head': git('rev-parse', 'HEAD').stdout.decode().strip(),
                'installer_sha256': installer.digest(INSTALLER.read_bytes()), 'files': entries,
                'activation': 'manual_scoped_artisan_command_after_runtime_review',
                'route_cache': 'operator_review_required_no_cache_commands_in_installer',
                'operations_excluded': ['delete', 'env', 'database', 'migrations', 'jobs', 'gateway', 'service_reload']}
    identity = json.dumps(manifest, sort_keys=True, separators=(',', ':')).encode()
    manifest['release_id'] = 'console-next-' + installer.digest(identity)[:16]
    installer.validate_manifest(manifest)
    manifest_bytes = (json.dumps(manifest, sort_keys=True, indent=2) + '\n').encode()
    installer.reject_symlinks(output.absolute())
    output.mkdir(parents=True, exist_ok=True)
    for name in ('console-next-release.zip', 'manifest.json', 'console-next.patch', 'console-next-release.sha256'):
        installer.reject_symlinks(output / name)
    archive = output / 'console-next-release.zip'
    content = {'manifest.json': manifest_bytes, 'installer.py': INSTALLER.read_bytes(), **payload}
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as bundle:
        for name in sorted(content):
            info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            info.external_attr = (0o100700 if name == 'installer.py' else 0o100644) << 16
            bundle.writestr(info, content[name], compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    (output / 'manifest.json').write_bytes(manifest_bytes)
    (output / 'console-next.patch').write_text(''.join(patches))
    checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
    (output / 'console-next-release.sha256').write_text(checksum + '  console-next-release.zip\n')
    print(json.dumps({'status': 'built_not_installed', 'release_id': manifest['release_id'], 'base_sha': base_sha,
                      'files': len(entries), 'archive': str(archive), 'sha256': checksum}, sort_keys=True))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base', default=DEFAULT_BASE)
    parser.add_argument('--output', type=Path, default=Path('/workspace/artifacts/servernet-redesign/live-candidate'))
    args = parser.parse_args()
    build(args.base, args.output)
