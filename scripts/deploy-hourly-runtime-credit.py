#!/usr/bin/env python3
"""Plan/apply/rollback the funded hourly-credit delta, preserving live overlays.

No migration, ledger write, provider command, scheduler execution or cache flush.
New helpers precede callers. Use the exact pinned deploy-vpstc.py core beside this
file. Activation and observing the existing scheduler are separate acceptance steps.
"""
import hashlib
import importlib.util
from pathlib import Path

BASE = '14a88e98f85c840fdbef63bdc059549a084a5e74'
CORE_SHA256 = '00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de'
RUNTIME_PATHS = (
    'app/Services/Cloud/HourlyGpuStart.php',
    'app/Services/Cloud/HourlyHold.php',
    'app/Services/Cloud/InterruptibleBillingClock.php',
    'app/Services/Cloud/HourlyCycleConverter.php',
    'app/Services/Cloud/GpuCapacityOrders.php',
    'app/Http/Controllers/Account/CloudServerController.php',
    'app/Http/Controllers/Account/CloudStoreController.php',
    'app/Console/Commands/CloudMeterHourly.php',
    'lang/fa/ui.php', 'lang/en/ui.php', 'lang/tr/ui.php',
    'resources/views/account/cloud-store.blade.php',
    'resources/views/account/cloud-server.blade.php',
)


def load_scoped_core():
    path = Path(__file__).with_name('deploy-vpstc.py')
    if path.is_symlink() or hashlib.sha256(path.read_bytes()).hexdigest() != CORE_SHA256:
        raise RuntimeError('pinned_release_core_hash_mismatch')
    spec = importlib.util.spec_from_file_location('hourly_credit_release_core', path)
    core = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(core)
    core.BASE = BASE
    core.RUNTIME_PATHS = RUNTIME_PATHS
    core.NEW_PATHS = frozenset(RUNTIME_PATHS[:1])
    return core


if __name__ == '__main__':
    raise SystemExit(load_scoped_core().main())
