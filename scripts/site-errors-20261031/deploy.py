#!/usr/bin/env python3
"""Guarded three-file CLI release; no Laravel bootstrap or external action.

Run prepare, inspect its private receipt, then apply (which verifies and seals).
The dependency is the already-reviewed installer at the exact hash below. Its
shared filesystem locks, private backups, before/after hashes, mode and cron
checks, atomic writes and guarded rollback remain in force. These compatible
CLI changes are loaded by a new scheduled/explicit PHP process; this helper
does not flush caches, change cron, run workers, or send a report.
"""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import sys

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
CORE = ROOT / 'infra/hermes/bale-manager-20261031/deploy.py'
CORE_SHA256 = '1ff1b407633f8c03680eca4a196b4e1868e6136044f37ffb3a7b1a64145ba9cc'
if hashlib.sha256(CORE.read_bytes()).hexdigest() != CORE_SHA256:
    raise SystemExit('Reviewed release core differs')
spec = importlib.util.spec_from_file_location('site_errors_reviewed_release_core', CORE)
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

RELEASE = 'site-errors-20261031'
MANIFEST = 'scripts/site-errors-20261031/release-manifest.json'
# Dependency first: the new command may call sendSample, the old one never does.
TARGETS = (
    'app/Services/Bale/Management/BaleManagerDigestDelivery.php',
    'app/Console/Commands/BaleManagerDigest.php',
    'app/Console/Commands/CheckContentLinks.php',
)
EXPECTED_BEFORE = {
    TARGETS[0]: '8e5eb8adcc8235d290eb5afb90380584fe2c45807fe6155fda540e75971a8ca0',
    TARGETS[1]: 'aabe4e1b50959720dae637a705b5ad2f23e3528d6223cef6081580c7debf7b77',
    TARGETS[2]: 'c2587fee3ff26e52509d51d00bb7c42e89cf78e3e8a10b3d5e6d7ae29f48523b',
}
base.RELEASE = RELEASE


def manifest(source):
    package = base.load_manifest(base.safe_path(source, MANIFEST))
    if set(package) != {'release', 'base_commit', 'files'} or not re.fullmatch(r'[0-9a-f]{40}', str(package.get('base_commit', ''))):
        raise base.ReleaseError('release_metadata_invalid')
    files = package['files']
    if tuple(row['target'] for row in files) != TARGETS:
        raise base.ReleaseError('release_scope_or_order_invalid')
    for row in files:
        if row['source'] != 'website/' + row['target'] or row['before'] != EXPECTED_BEFORE[row['target']]:
            raise base.ReleaseError('release_source_or_baseline_invalid')
        if row['after'] == row['before']:
            raise base.ReleaseError('release_noop_invalid')
    return package


def run(home, source, phase, lint=base.php_lint, observe_cron=base.cron_snapshot):
    home, source = Path(home), Path(source)
    if phase not in ('prepare', 'apply', 'verify', 'rollback'):
        raise base.ReleaseError('invalid_phase')
    package = manifest(source)
    receipt = base.run(home, source, package, phase, lint, observe_cron)
    if phase == 'apply':
        # A second independent verification obtains both release locks again;
        # any newer file or mode edit stops it without overwriting that edit.
        receipt = base.run(home, source, package, 'verify', lint, observe_cron)
    return {'release': RELEASE, 'phase': receipt['phase'], 'files': len(TARGETS),
            'cron_unchanged': None if phase == 'rollback' else True, 'framework_bootstrapped': False,
            'schema_or_settings_changed': False, 'messages_sent': 0}


if __name__ == '__main__':
    try:
        home = Path('/home/servernetcloud')
        if Path.home() != home or os.getuid() == 0:
            raise base.ReleaseError('unexpected_account')
        if len(sys.argv) != 3:
            raise base.ReleaseError('usage_phase_source_root')
        print(json.dumps(run(home, Path(sys.argv[2]), sys.argv[1]), sort_keys=True))
    except Exception as error:
        print(json.dumps({'status': 'stopped', 'error_class': type(error).__name__,
                          'reason': str(error) if isinstance(error, base.ReleaseError) else 'inspect_private_release_status'}))
        sys.exit(1)
