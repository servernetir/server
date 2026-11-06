#!/usr/bin/env python3
"""Guarded five-file manager comparison release; no app bootstrap.

Run prepare, inspect its private receipt, then apply (which verifies and seals).
The dependency is the already-reviewed installer at the exact hash below. Its
shared filesystem locks, private backups, before/after hashes, mode and cron
checks, atomic writes and guarded rollback remain in force. No cache flush,
cron edit, worker invocation, Google API request or report send is performed.
Activation and a read-only preview are separate acceptance steps. No sample is sent.
"""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import sys

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
CORE = ROOT / 'infra/hermes/bale-manager-20261031/deploy.py'
CORE_SHA256 = '1ff1b407633f8c03680eca4a196b4e1868e6136044f37ffb3a7b1a64145ba9cc'
if hashlib.sha256(CORE.read_bytes()).hexdigest() != CORE_SHA256:
    raise SystemExit('Reviewed release core differs')
spec = importlib.util.spec_from_file_location('bale_month_comparison_reviewed_release_core', CORE)
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

RELEASE = 'bale-month-comparison-20261031'
BASE_COMMIT = '978f7e94ef652413da51143bf2232cf2df75103d'
MANIFEST = 'scripts/bale-month-comparison-20261031/release-manifest.json'
# Install source-period labels first, then the new cache contract, then its consumer.
TARGETS = (
    'lang/fa/bale-reporting.php',
    'lang/en/bale-reporting.php',
    'lang/tr/bale-reporting.php',
    'app/Services/Bale/Management/ManagerTrafficSnapshot.php',
    'app/Services/Bale/Management/BaleManagerDailyDigest.php',
)
EXPECTED_BEFORE = {
    TARGETS[0]: 'd9dd16154b1a8fe2b22423243444979a6f3d47cd292fd677be7a800949894c3c',
    TARGETS[1]: '689ce23b178a584676d2c0710a5d995949cad2618d05b15901e197be6350dfdd',
    TARGETS[2]: 'cd06f71edd0ce00d95bf93f5951db251be97138674941c06b3a561dadad120a5',
    TARGETS[3]: '76e00824777135b5ae2571ac1b1ed356657a6bf81efc26d07f9b241efd17b588',
    TARGETS[4]: 'f3d5f7364393d4690c0cae5433178172ae0641c548bb75da42bf0c942bc1b74d',
}
base.RELEASE = RELEASE


def manifest(source):
    package = base.load_manifest(base.safe_path(source, MANIFEST))
    if set(package) != {'release', 'base_commit', 'files'} or package.get('base_commit') != BASE_COMMIT:
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
        if Path.home() != home or os.getuid() == 0 or os.environ.get('BALE_MANAGER_LOCKS_HELD') != '1':
            raise base.ReleaseError('unexpected_account_or_missing_native_lock_contract')
        if len(sys.argv) != 3:
            raise base.ReleaseError('usage_phase_source_root')
        print(json.dumps(run(home, Path(sys.argv[2]), sys.argv[1]), sort_keys=True))
    except Exception as error:
        print(json.dumps({'status': 'stopped', 'error_class': type(error).__name__,
                          'reason': str(error) if isinstance(error, base.ReleaseError) else 'inspect_private_release_status'}))
        sys.exit(1)
