#!/usr/bin/env python3
"""Guarded Hermes assistant source release. No app bootstrap or operational calls.

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
ROOT = Path(__file__).resolve().parents[3]
CORE = ROOT / 'infra/hermes/bale-manager-20261031/deploy.py'
CORE_SHA256 = '1ff1b407633f8c03680eca4a196b4e1868e6136044f37ffb3a7b1a64145ba9cc'
if hashlib.sha256(CORE.read_bytes()).hexdigest() != CORE_SHA256:
    raise SystemExit('Reviewed release core differs')
spec = importlib.util.spec_from_file_location('hermes_assistant_reviewed_release_core', CORE)
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

RELEASE = 'hermes-assistant-20261102'
BASE_COMMIT = '6443ab136e52ae51d1af73347971dd07ead10cb1'
MANIFEST = 'infra/hermes/assistant-20261102/release-manifest.json'
# New helpers precede their backwards-compatible consumers. The UI is last.
SOURCES = {'app/Services/Hermes/HermesAssistantContext.php': 'website/app/Services/Hermes/HermesAssistantContext.php',
 'app/Services/Hermes/HermesRepositoryKnowledge.php': 'website/app/Services/Hermes/HermesRepositoryKnowledge.php',
 'app/Services/Hermes/HermesRelayMonitor.php': 'website/app/Services/Hermes/HermesRelayMonitor.php',
 'app/Services/Hermes/HermesKnowledge.php': 'infra/hermes/assistant-20261102/HermesKnowledge.php',
 'app/Services/Hermes/HermesConversationMemory.php': 'infra/hermes/assistant-20261102/HermesConversationMemory.php',
 'app/Console/Commands/HermesKnowledgeRefresh.php': 'infra/hermes/assistant-20261102/HermesKnowledgeRefresh.php',
 'app/Http/Controllers/HermesMiniAppController.php': 'infra/hermes/assistant-20261102/HermesMiniAppController.php',
 'resources/views/bale/hermes.blade.php': 'infra/hermes/assistant-20261102/hermes.blade.php'}
TARGETS = tuple(SOURCES)
EXPECTED_BEFORE = {'app/Services/Hermes/HermesAssistantContext.php': None,
 'app/Services/Hermes/HermesRepositoryKnowledge.php': None,
 'app/Services/Hermes/HermesRelayMonitor.php': None,
 'app/Services/Hermes/HermesKnowledge.php': 'c47d352778e71db8b80b529b0563df36fcf48f339d4fe95f8738504325cf8a2f',
 'app/Services/Hermes/HermesConversationMemory.php': '19040a28acba5a51e49cb6bd26b8cb733ffceb23e30db4883adde781f481f9e9',
 'app/Console/Commands/HermesKnowledgeRefresh.php': 'cf9b4d15406c9650850c62a28ab9d788f72c5d57010c03797d61a6bc0ed454af',
 'app/Http/Controllers/HermesMiniAppController.php': 'b3f4001c766cd58ba44bf8f448d9082af3cb61c8bfb6a8bafa092cdd4b0842c3',
 'resources/views/bale/hermes.blade.php': '1f3d9d59f86bd93a437dd7f30507991cecbd5df19600ab2d9a4bde66ed71d12f'}
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
