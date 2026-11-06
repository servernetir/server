#!/usr/bin/env python3
"""One-file, layered read-only Openprovider balance adapter follow-up.

Reuses the reviewed installer. It changes no schema, policy, cron or app state.
Acceptance checks the original package with exactly this one pinned override.
Rollback this layer before the main manager release.
"""
import importlib.util
import json
import os
from pathlib import Path
import sys

spec = importlib.util.spec_from_file_location('bale_manager_base_release', Path(__file__).with_name('deploy.py'))
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
MAIN = 'bale-manager-20261031'
RELEASE = 'bale-manager-openprovider-20261031'
PACKAGE = 'infra/hermes/bale-manager-20261031/'
TARGET = 'app/Services/Domain/OpenProviderClient.php'
SOURCE = 'website/' + TARGET
base.RELEASE = RELEASE


def main_manifest(source):
    base.RELEASE = MAIN
    try:
        return base.load_manifest(base.safe_path(source, PACKAGE + 'release-manifest.json'))
    finally:
        base.RELEASE = RELEASE


def manifests(source):
    followup = base.load_manifest(base.safe_path(source, PACKAGE + 'openprovider-manifest.json'))
    if len(followup['files']) != 1 or followup['files'][0]['source'] != SOURCE or followup['files'][0]['target'] != TARGET:
        raise base.ReleaseError('followup_scope_invalid')
    row = followup['files'][0]
    main = main_manifest(source)
    originals = [entry for entry in main['files'] if entry['target'] == TARGET]
    if len(originals) != 1 or row['before'] != originals[0]['after'] or row['after'] == row['before']:
        raise base.ReleaseError('followup_layer_chain_invalid')
    return main, followup


def private_receipt(home, release):
    directory = base.safe_path(home, 'deploy-backups/' + release)
    if not directory.is_dir() or directory.stat().st_mode & 0o077:
        raise base.ReleaseError('private_layer_receipt_invalid')
    return json.loads(base.read_regular(base.safe_path(directory, 'receipt.json')), object_pairs_hook=base.reject_duplicates)


def accept_composed(home, main, followup):
    # Re-lock and re-check the entire current package; no time-of-check gap from
    # the preceding one-file verification is treated as acceptance evidence.
    with base.locks(home):
        original = private_receipt(home, MAIN)
        latest = private_receipt(home, RELEASE)
        if original.get('release') != MAIN or original.get('manifest') != main or original.get('phase') != 'verified':
            raise base.ReleaseError('main_verified_receipt_required')
        base.validate_receipt(latest, followup)
        if latest.get('phase') != 'verified':
            raise base.ReleaseError('followup_verified_receipt_required')
        modes = original.get('modes')
        if not isinstance(modes, list) or len(modes) != len(main['files']):
            raise base.ReleaseError('main_receipt_modes_invalid')
        for index, row in enumerate(main['files']):
            base.check(base.target(home, row), followup['files'][0]['after'] if row['target'] == TARGET else row['after'])
            if (base.target(home, row).stat().st_mode & 0o7777) != modes[index]:
                raise base.ReleaseError('composed_target_mode_drift')
    return len(main['files'])


def run(home, source, phase, lint=base.php_lint, observe_cron=base.cron_snapshot):
    home, source = Path(home), Path(source)
    if phase not in ('prepare', 'apply', 'verify', 'accept', 'rollback'):
        raise base.ReleaseError('invalid_phase')
    main, followup = manifests(source)
    receipt = base.run(home, source, followup, 'verify' if phase == 'accept' else phase, lint, observe_cron)
    result = {'release': RELEASE, 'phase': receipt['phase'], 'files': 1, 'cron_unchanged': phase != 'rollback'}
    if phase == 'accept':
        result['composed_files_verified'] = accept_composed(home, main, followup)
    return result


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
                          'reason': str(error) if isinstance(error, base.ReleaseError) else 'inspect_private_followup_status'}))
        sys.exit(1)
