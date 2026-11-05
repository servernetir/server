#!/usr/bin/env python3
"""Guarded Araz infrastructure-context source release. No app bootstrap or operational calls.

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
spec = importlib.util.spec_from_file_location('araz_infrastructure_context_release_core', CORE)
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

RELEASE = 'araz-infra-context-20261102'
BASE_COMMIT = '1d08a1d32821d047c34aae3c86ef369aa51e207c'
MANIFEST = 'infra/hermes/assistant-20261102/infra-context/release-manifest.json'
# Introduce the bounded projection helper before its owner-context consumers.
# No native/VM source or controller recovery code is part of this layer.
SOURCES = {'app/Services/Hermes/HermesInfrastructureContext.php': 'website/app/Services/Hermes/HermesInfrastructureContext.php',
 'app/Services/Hermes/HermesOperations.php': 'infra/hermes/assistant-20261102/infra-context/HermesOperations.php',
 'app/Services/Hermes/HermesConversationMemory.php': 'infra/hermes/assistant-20261102/infra-context/HermesConversationMemory.php'}
TARGETS = tuple(SOURCES)
EXPECTED_BEFORE = {'app/Services/Hermes/HermesInfrastructureContext.php': None,
 'app/Services/Hermes/HermesOperations.php': '0597b2a649cdcfc1075b0eefd0ce6340ef20ad71dc278c224345a4a23b6719b2',
 'app/Services/Hermes/HermesConversationMemory.php': '2638d036aca53989ffe9aaa34798909c4ce8dbc2341274700e8f1f794a9d5a35'}
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
