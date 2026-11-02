#!/usr/bin/env python3
"""Guarded Nava follow-up source release. No app bootstrap or operational calls.

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
spec = importlib.util.spec_from_file_location('nava_ui_reviewed_release_core', CORE)
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

RELEASE = 'nava-assistant-ui-20261102'
BASE_COMMIT = '6443ab136e52ae51d1af73347971dd07ead10cb1'
MANIFEST = 'infra/hermes/assistant-20261102/ui-followup/release-manifest.json'
# Existing helpers precede their backwards-compatible consumer; the UI is last.
# Baselines are exact after-hashes from the verified initial assistant release.
SOURCES = {'app/Services/Hermes/HermesAssistantContext.php': 'infra/hermes/assistant-20261102/ui-followup/HermesAssistantContext.php',
 'app/Services/Hermes/HermesRelayMonitor.php': 'infra/hermes/assistant-20261102/ui-followup/HermesRelayMonitor.php',
 'app/Services/Hermes/HermesConversationMemory.php': 'infra/hermes/assistant-20261102/ui-followup/HermesConversationMemory.php',
 'resources/views/bale/hermes.blade.php': 'infra/hermes/assistant-20261102/ui-followup/hermes.blade.php'}
TARGETS = tuple(SOURCES)
EXPECTED_BEFORE = {'app/Services/Hermes/HermesAssistantContext.php': '561ccb90f03ace6ef11518edab619a7c4b93f223f810c9781f0c102f3220b233',
 'app/Services/Hermes/HermesRelayMonitor.php': '968efeaa7e3d24628e788ca39104e7874895ea93dadc6ac020c7608a4037bb57',
 'app/Services/Hermes/HermesConversationMemory.php': 'eb4382007c12f7eeac185a3e2962969f2fef68559c04ca60dfed84f282500a53',
 'resources/views/bale/hermes.blade.php': '5101a4669ead8159363efd237f60b9393e6ecf6ec39ec7b14b03d64e62c1efbf'}
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
