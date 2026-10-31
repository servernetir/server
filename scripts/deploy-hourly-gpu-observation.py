#!/usr/bin/env python3
"""Scoped follow-up to the installed hourly-credit release; four PHP files only."""
import hashlib
import importlib.util
from pathlib import Path

BASE = '038298240b07baef6f740db9537f7436f1ea3270'
CORE_SHA256 = '00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de'
RUNTIME_PATHS = (
    'app/Services/Cloud/HourlyGpuStart.php',
    'app/Services/Cloud/InterruptibleBillingClock.php',
    'app/Http/Controllers/Account/CloudServerController.php',
    'app/Console/Commands/CloudMeterHourly.php',
)


def load_scoped_core():
    path = Path(__file__).with_name('deploy-vpstc.py')
    if path.is_symlink() or hashlib.sha256(path.read_bytes()).hexdigest() != CORE_SHA256:
        raise RuntimeError('pinned_release_core_hash_mismatch')
    spec = importlib.util.spec_from_file_location('gpu_observation_release_core', path)
    core = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(core)
    core.BASE = BASE
    core.RUNTIME_PATHS = RUNTIME_PATHS
    core.NEW_PATHS = frozenset()
    return core


if __name__ == '__main__':
    raise SystemExit(load_scoped_core().main())
