#!/usr/bin/env python3
"""Guarded two-file release. No purchases, provider mutations, migrations or cache flushes."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess

BASE = '20a55d08db0adadfec2510e0b63bedf260deb65d'
PATHS = ('app/Services/Cloud/SaladOllamaImage.php', 'app/Services/Cloud/SaladOperations.php')
CORE_SHA = '00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--target', required=True)
    parser.add_argument('--root', required=True)
    parser.add_argument('--install', action='store_true')
    args = parser.parse_args()
    directory = Path(__file__).resolve().parent
    core_path = directory/'deploy-vpstc.py'
    if core_path.is_symlink() or hashlib.sha256(core_path.read_bytes()).hexdigest() != CORE_SHA:
        raise RuntimeError('release_core_hash_mismatch')
    spec = importlib.util.spec_from_file_location('ollama_release_core', core_path)
    core = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(core)
    core.BASE = BASE
    core.RUNTIME_PATHS = PATHS
    core.NEW_PATHS = frozenset()
    php = '/opt/cpanel/ea-php84/root/usr/bin/php'
    root = core.checked_root(args.root)
    if root != Path('/home/servernetcloud/servernet_app'):
        raise RuntimeError('unexpected_production_root')
    core.validate_sha(args.target)
    with core.release_locks(root):
        release, plan = core.build_plan(root, args.target, php)
        print(json.dumps({'phase':'prepared','release_dir':str(release),'plan_sha256':plan['plan_sha256'],
            'files':plan['files']}), flush=True)
        if not args.install: return
        # The commit pins both the release script and the catalog mutation.
        catalog = (directory/'catalog.php').read_bytes()
        if catalog != fetch_repo_file(args.target, 'scripts/ollama-official-20261122/catalog.php'):
            raise RuntimeError('catalog_script_commit_mismatch')
        lint = subprocess.run([php,'-l',str(directory/'catalog.php')], capture_output=True)
        if lint.returncode: raise RuntimeError('catalog_script_lint_failed')
        result = core.apply_plan(root, release, plan, php)
        try:
            applied = subprocess.run([php,str(directory/'catalog.php'),str(root),str(release)],
                capture_output=True, text=True, timeout=60)
            if applied.returncode: raise RuntimeError('catalog_alignment_failed')
            catalog_result = json.loads(applied.stdout.strip())
        except Exception:
            core.explicit_rollback(root, release, plan)
            raise
        from activate import activate
        fpm_result = activate(directory, release)
        print(json.dumps({'phase':'installed','runtime':result,'catalog':catalog_result,
            'fpm':fpm_result, 'web_opcache_activation_required':False}), flush=True)


def fetch_repo_file(commit, path):
    import urllib.request
    request = urllib.request.Request(f'https://raw.githubusercontent.com/servernetir/server/{commit}/{path}')
    with urllib.request.urlopen(request, timeout=30) as response:
        data = response.read(2_000_001)
    if len(data)>2_000_000: raise RuntimeError('source_too_large')
    return data

if __name__=='__main__':
    try: main()
    except Exception as exc:
        print(json.dumps({'ok':False,'reason':str(exc) if isinstance(exc,RuntimeError) else type(exc).__name__}))
        raise SystemExit(1)
