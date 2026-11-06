#!/usr/bin/env python3
"""Guarded four-file manager-report release; no app bootstrap.

Run prepare, inspect its private receipt, then apply (which verifies and seals).
The dependency is the already-reviewed installer at the exact hash below. Its
shared filesystem locks, private backups, before/after hashes, mode and cron
checks, atomic writes and guarded rollback remain in force. No cache flush,
cron edit, worker invocation, Google API request or report send is performed.
Activation and the explicitly requested sample are separate acceptance steps.
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
spec = importlib.util.spec_from_file_location('bale_report_layout_reviewed_release_core', CORE)
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

RELEASE = 'bale-report-layout-20261031'
BASE_COMMIT = '0861aab6d5452db065a7e1cbaaa3c9d3cd0bddeb'
MANIFEST = 'scripts/bale-report-layout-20261031/release-manifest.json'
# A new class must exist before the digest or router can resolve it. Install the
# backwards-compatible sender before any caller that uses its navigation support.
TARGETS = (
    'app/Services/Bale/Management/ManagerBusinessCalendarSnapshot.php',
    'app/Services/Bale/BaleSender.php',
    'app/Services/Bale/Management/BaleManagerDailyDigest.php',
    'app/Services/Bale/Admin/AdminBaleRouter.php',
)
EXPECTED_BEFORE = {
    TARGETS[0]: None,
    TARGETS[1]: 'b6127d4e9fe90313e3519690142b7345d33439834b2c3d2fd0a94f7f9ff9426b',
    TARGETS[2]: '33a351d019bf28bd82da44fc3f8e7d22ca0d592a9bcd1f6850a882f055a8b9a9',
    TARGETS[3]: '427923cfe9e3324af4ac13b39883a3fcb9100627033df29e42360ff1de789de5',
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
