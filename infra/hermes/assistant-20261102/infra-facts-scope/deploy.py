#!/usr/bin/env python3
"""Guarded Araz infrastructure-facts scope patch. No app bootstrap or operational calls.

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
spec = importlib.util.spec_from_file_location('araz_infrastructure_facts_scope_release_core', CORE)
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

RELEASE = 'araz-infra-facts-scope-20261102'
BASE_COMMIT = 'a606cf9670b2f05cedaf10dbd286f30460e323c4'
MANIFEST = 'infra/hermes/assistant-20261102/infra-facts-scope/release-manifest.json'
# Replace the pure formatter before its narrow mixed-query Memory fallback.
# Historical prepared receipt/manifests remain immutable; this layer rolls back first.
SOURCES = {'app/Services/Hermes/HermesProviderReply.php': 'infra/hermes/assistant-20261102/infra-facts-scope/HermesProviderReply.php',
 'app/Services/Hermes/HermesConversationMemory.php': 'infra/hermes/assistant-20261102/infra-facts-scope/HermesConversationMemory.php'}
TARGETS = tuple(SOURCES)
EXPECTED_BEFORE = {'app/Services/Hermes/HermesProviderReply.php': '08f78c81c5bbbaf458d660f308f93d3dc0d85668c06ce6c08ebfe0ae1c2668e9',
 'app/Services/Hermes/HermesConversationMemory.php': '3ec15970f5e485e15e555fe8a0b169273f88447557eb5652f8b1a82f34e1000c'}
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
