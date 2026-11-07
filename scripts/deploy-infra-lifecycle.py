#!/usr/bin/env python3
"""Use the reviewed file-only installer for bounded lifecycle release layers.

Prepare first; inspect the private, linted manifest before passing its hash to
--apply. No Laravel bootstrap, migration, provider operation, customer message,
wallet change or cache flush is performed by this installer.
"""
import argparse
import hashlib
import importlib.util
from pathlib import Path
import subprocess
import sys

CORE_HASH = '00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de'
CORE_PATH = Path(__file__).with_name('deploy-vpstc.py')
LAYERS = {
    'release-confirmation': (
        'app/Services/Cloud/LightNodeClient.php',
        'app/Services/Cloud/CloudProvisioner.php',
        'app/Services/Provisioning/ProvisioningService.php',
        'app/Console/Commands/CloudReleaseRetry.php',
        'app/Services/SystemHealth.php',
        'routes/console.php',
    ),
    'aeza-review': (
        'app/Services/Cloud/AezaLifecycleReview.php',
        'app/Services/Cloud/AezaClient.php',
        'app/Services/Cloud/CloudProvisioner.php',
        'app/Services/Provisioning/ProvisioningService.php',
        'app/Services/Billing/BillingLifecycleActions.php',
        'app/Services/SystemHealth.php',
    ),
}


def main():
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument('--layer', choices=tuple(LAYERS), required=True)
    parser.add_argument('--base', required=True)
    args, installer_args = parser.parse_known_args()
    if hashlib.sha256(CORE_PATH.read_bytes()).hexdigest() != CORE_HASH:
        raise SystemExit('Reviewed installer core differs; no release action performed')
    spec = importlib.util.spec_from_file_location('lifecycle_reviewed_release_core', CORE_PATH)
    core = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(core)
    core.BASE = core.validate_sha(args.base)
    core.RUNTIME_PATHS = LAYERS[args.layer]
    core.NEW_PATHS = frozenset({'app/Services/Cloud/AezaLifecycleReview.php'}) & set(core.RUNTIME_PATHS)
    repository = Path(__file__).resolve().parents[1]

    def git_source(commit, relative):
        result = subprocess.run(['git', 'show', commit + ':website/' + relative],
                                cwd=repository, capture_output=True)
        if result.returncode:
            return None
        return result.stdout

    ordinary_plan = core.build_plan

    def checked_plan(root, target, php):
        for relative in core.RUNTIME_PATHS:
            desired = git_source(target, relative)
            candidate = repository / 'website' / relative
            if desired is None or not candidate.is_file() or candidate.read_bytes() != desired:
                raise core.ReleaseError('qa_source_differs_from_pinned_target:' + relative)
        return ordinary_plan(root, target, php, source_reader=git_source)

    core.build_plan = checked_plan
    sys.argv = [sys.argv[0]] + installer_args
    return core.main()


if __name__ == '__main__':
    raise SystemExit(main())
