#!/usr/bin/env python3
"""Prepare then install one owner-approved reliability fix, with native pure tests."""
import hashlib
import importlib.util
import json
from pathlib import Path
import os
import stat
import subprocess
import sys
import time

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parent.parent
helper = ROOT / 'scripts/deploy-hermes-reliability-20261029.py'
if hashlib.sha256(helper.read_bytes()).hexdigest() != '52c50e48c647aa81ccd3da89c12cd39187e58791864559977baeca94e7467310':
    raise SystemExit('Reviewed release core differs')
spec = importlib.util.spec_from_file_location('guarded_release', helper)
core = importlib.util.module_from_spec(spec)
spec.loader.exec_module(core)
RELEASE = 'hermes-knowledge-due-20261029'
BEFORE = 'dd59914f397f9c5f1860deb356d3107ebcfc58d5ee4336ad344b63413bf420b7'
AFTER = '76e708c5b5671ade71b868eb18e1c0d2f3c6f8c5513310c483a47eae5769905a'
DESTINATION = 'servernet_app/app/Services/Hermes/HermesKnowledge.php'


def main():
    home = Path('/home/servernetcloud')
    if Path.home() != home or os.getuid() == 0:
        raise core.ReleaseError('unexpected_account')
    os.umask(0o077)
    core.RELEASE = RELEASE
    with core.locks(home):
        for name, (_, expected) in core.FILES.items():
            core.check(core.regular(home, 'servernet_app/app/Console/Commands/' + name), expected)
        source = core.regular(ROOT, 'infra/hermes/knowledge-recovery-20261029/HermesKnowledge.php')
        data = core.check(source, AFTER)
        target = core.regular(home, DESTINATION)
        parent = home / 'deploy-backups'
        if parent.is_symlink() or not parent.is_dir():
            raise core.ReleaseError('invalid_private_backup_parent')
        release = parent / RELEASE
        if release.is_symlink():
            raise core.ReleaseError('invalid_private_release')
        if not release.exists():
            original = core.check(target, BEFORE)
            mode = stat.S_IMODE(target.stat().st_mode)
            tests = subprocess.run([core.PHP, str(ROOT / 'scripts/test-hermes-knowledge-due-20261029.php')],
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30)
            if tests.returncode or json.loads(tests.stdout.decode()) != {'status': 'passed', 'checks': 8}:
                raise core.ReleaseError('pure_source_tests_failed')
            cron = core.cron_snapshot()
            release.mkdir(mode=0o700)
            core.atomic_write(release / 'HermesKnowledge.php.before', original, 0o600)
            core.atomic_write(release / 'HermesKnowledge.php.after', data, 0o600)
            core.php_lint(release / 'HermesKnowledge.php.after')
            receipt = {'phase': 'prepared', 'destination': DESTINATION, 'before_sha256': BEFORE,
                       'after_sha256': AFTER, 'mode': mode, 'cron_before': cron,
                       'pure_source_checks': 8, 'tls_validation_unchanged': True,
                       'prepared_utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())}
            core.receipt_write(release, receipt)
            print(json.dumps(receipt, sort_keys=True))
            return
        receipt = json.loads(core.regular(release, 'receipt.json').read_text())
        if receipt.get('phase') != 'prepared' or receipt.get('after_sha256') != AFTER or receipt.get('destination') != DESTINATION:
            raise core.ReleaseError('release_sealed_or_contract_drift')
        if core.cron_snapshot() != receipt['cron_before']:
            raise core.ReleaseError('cron_drift')
        core.check(target, BEFORE)
        core.check(core.regular(release, 'HermesKnowledge.php.before'), BEFORE)
        core.check(core.regular(release, 'HermesKnowledge.php.after'), AFTER)
        if stat.S_IMODE(target.stat().st_mode) != receipt['mode']:
            raise core.ReleaseError('mode_drift')
        core.php_lint(release / 'HermesKnowledge.php.after')
        receipt['phase'] = 'installing'
        core.receipt_write(release, receipt)
        replaced = False
        try:
            core.atomic_write(target, data, receipt['mode'])
            replaced = True
            core.check(target, AFTER)
            core.php_lint(target)
            receipt['cron_after'] = core.cron_snapshot()
            if receipt['cron_after'] != receipt['cron_before']:
                raise core.ReleaseError('cron_drift_after_install')
            receipt['phase'] = 'verified'
            receipt['verified_utc'] = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
            core.receipt_write(release, receipt)
        except Exception:
            if replaced:
                core.check(target, AFTER)
                core.atomic_write(target, core.check(release / 'HermesKnowledge.php.before', BEFORE), receipt['mode'])
            receipt['phase'] = 'rolled_back'
            core.receipt_write(release, receipt)
            raise
        print(json.dumps(receipt, sort_keys=True))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'status': 'stopped', 'exception_class': type(error).__name__,
                          'reason': str(error) if isinstance(error, core.ReleaseError) else 'review_private_task_status'}))
        sys.exit(1)
