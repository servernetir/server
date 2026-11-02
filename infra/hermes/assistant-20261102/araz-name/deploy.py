#!/usr/bin/env python3
"""Guarded Araz name-only source release. No app bootstrap or operational calls.

The pinned deployment core supplies shared web flocks, private exact backups,
source/target hash checks, dependency ordering, atomic file writes, mode and cron
preservation, and rollback that refuses concurrent edits. The private PHP
launcher must hold the real Hermes native cache locks for the whole phase.
This installs source only; model, live UI and recurring-worker acceptance remain
separate. No migrations, settings, provider call, notification or global cache
operation occurs in this program.
"""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import sys

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[4]
CORE = ROOT / 'infra/hermes/bale-manager-20261031/deploy.py'
CORE_SHA256 = '1ff1b407633f8c03680eca4a196b4e1868e6136044f37ffb3a7b1a64145ba9cc'
if hashlib.sha256(CORE.read_bytes()).hexdigest() != CORE_SHA256:
    raise SystemExit('Reviewed release core differs')
spec = importlib.util.spec_from_file_location('araz_name_reviewed_release_core', CORE)
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

RELEASE = 'araz-assistant-name-20261102'
BASE_COMMIT = '6c4c96119dcc2e12ce2ab548d82f40ae55662c58'
MANIFEST = 'infra/hermes/assistant-20261102/araz-name/release-manifest.json'
# Existing helpers precede their backwards-compatible consumer; the UI is last.
# Baselines are exact after-hashes from the verified initial assistant release.
SOURCES = {'app/Services/Hermes/HermesAssistantContext.php': 'infra/hermes/assistant-20261102/araz-name/HermesAssistantContext.php', 'app/Services/Hermes/HermesRelayMonitor.php': 'infra/hermes/assistant-20261102/araz-name/HermesRelayMonitor.php', 'app/Services/Hermes/HermesConversationMemory.php': 'infra/hermes/assistant-20261102/araz-name/HermesConversationMemory.php', 'app/Services/Hermes/HermesOperations.php': 'infra/hermes/assistant-20261102/araz-name/HermesOperations.php', 'app/Services/Hermes/HermesSupportOwnerReview.php': 'infra/hermes/assistant-20261102/araz-name/HermesSupportOwnerReview.php', 'app/Services/Hermes/HermesOwnerNotification.php': 'infra/hermes/assistant-20261102/araz-name/HermesOwnerNotification.php', 'resources/views/bale/hermes.blade.php': 'infra/hermes/assistant-20261102/araz-name/hermes.blade.php'}
TARGETS = tuple(SOURCES)
EXPECTED_BEFORE = {'app/Services/Hermes/HermesAssistantContext.php': 'd75c9aad90c1b69204f4c3dc6f4b3f1d6c6b107cd2fbe0711dc28b8b3d6c05fc', 'app/Services/Hermes/HermesRelayMonitor.php': 'c308c899a6f26da64c1f811b573f77e6797473d001b13333219f63e2595709bd', 'app/Services/Hermes/HermesConversationMemory.php': '1b5f01c23a37b4ec67790aac22e8983f12a3508a28f6734e2e28c7c7036b1848', 'app/Services/Hermes/HermesOperations.php': 'b80f4aa5265eed0b25c84ba72cbf31140f96bd11cebbfa252a77b1431b4bc81b', 'app/Services/Hermes/HermesSupportOwnerReview.php': '0efe4fa6be2a43393b3d548e60b1dfa585d87ab9ab9c43ba5151950fdf761228', 'app/Services/Hermes/HermesOwnerNotification.php': '4a03f699b6108ac1f1df93b8a0a065bd639a8344595a20ffc937487afbe41f04', 'resources/views/bale/hermes.blade.php': '8a4e9d240ba8c9bd9bfaff8175d20d4dc9ef0050b659c28ba5b1708f982869a5'}
base.RELEASE = RELEASE


def manifest(source):
    package = base.load_manifest(base.safe_path(source, MANIFEST))
    if set(package) != {'release', 'base_commit', 'files'} or package.get('base_commit') != BASE_COMMIT:
        raise base.ReleaseError('release_metadata_invalid')
    if tuple(row['target'] for row in package['files']) != TARGETS:
        raise base.ReleaseError('release_scope_or_order_invalid')
    for row in package['files']:
        if row['source'] != SOURCES[row['target']] or row['before'] != EXPECTED_BEFORE[row['target']]:
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
        receipt = base.run(home, source, package, 'verify', lint, observe_cron)
    return {'release': RELEASE, 'phase': receipt['phase'], 'files': len(TARGETS),
            'cron_unchanged': None if phase == 'rollback' else True,
            'framework_bootstrapped': False, 'schema_or_settings_changed': False,
            'messages_sent': 0, 'provider_calls': 0}


if __name__ == '__main__':
    try:
        home = Path('/home/servernetcloud')
        if Path.home() != home or os.getuid() == 0 or os.environ.get('HERMES_ASSISTANT_LOCKS_HELD') != '1':
            raise base.ReleaseError('unexpected_account_or_missing_native_lock_contract')
        if len(sys.argv) != 3:
            raise base.ReleaseError('usage_phase_source_root')
        print(json.dumps(run(home, Path(sys.argv[2]), sys.argv[1]), sort_keys=True))
    except Exception as error:
        print(json.dumps({'status': 'stopped', 'error_class': type(error).__name__,
                          'reason': str(error) if isinstance(error, base.ReleaseError) else 'inspect_private_release_status'}))
        sys.exit(1)
