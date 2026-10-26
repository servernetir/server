#!/usr/bin/env python3
"""Plan/apply/rollback only the reviewed VPS.TC money contract runtime bundle.

Nine explicit runtime files; both new money helpers precede every caller.
Uses the installed commit as base and the exact pinned file-release core.
Default is plan-only. No application bootstrap, provider API, quote, order,
payment, renewal, settings, migration, cache, cron or public-asset action runs.
Keep this wrapper beside the exact deploy-vpstc.py core.
"""
from __future__ import annotations

import hashlib
import importlib.util
import json
from pathlib import Path

CORE_SHA256 = '00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de'
BASE = 'ffa7205fc48a524af814586ccfa66683496fee85'
RUNTIME_PATHS = (
    'app/Services/Cloud/VpsTcInvoiceAmounts.php',
    'app/Services/Cloud/VpsTcMoneyContract.php',
    'app/Services/Cloud/VpsTcApi.php',
    'app/Services/Cloud/VpsTcPricing.php',
    'app/Services/Cloud/VpsTcCatalog.php',
    'app/Services/Cloud/VpsTcPreflight.php',
    'app/Services/Cloud/VpsTcClient.php',
    'app/Services/Cloud/VpsTcRenewal.php',
    'resources/views/admin/partials/vpstc-settings-panel.blade.php',
)
NEW_PATHS = frozenset(RUNTIME_PATHS[:2])


class WrapperError(RuntimeError):
    pass


def load_scoped_core(core_path: Path | None = None):
    path = core_path or Path(__file__).with_name('deploy-vpstc.py')
    if hashlib.sha256(path.read_bytes()).hexdigest() != CORE_SHA256:
        raise WrapperError('pinned_release_core_hash_mismatch')
    spec = importlib.util.spec_from_file_location('vpstc_money_contract_release_core', path)
    core = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(core)
    core.BASE = BASE
    core.RUNTIME_PATHS = RUNTIME_PATHS
    core.NEW_PATHS = NEW_PATHS
    core.__doc__ = __doc__
    return core


def main() -> int:
    try:
        return load_scoped_core().main()
    except (WrapperError, OSError) as exc:
        reason = str(exc) if isinstance(exc, WrapperError) else type(exc).__name__
        print(json.dumps({'status': 'failed', 'reason': reason}, sort_keys=True))
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
