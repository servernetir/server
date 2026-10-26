#!/usr/bin/env python3
"""Plan/apply/rollback the reviewed infrastructure settings, TRY and lifecycle delta.

Exactly twenty runtime files are allowed. The pinned release core preserves
unrelated live edits and stops on conflicting patches. Default is plan-only;
no provider API, settings, schema, scheduler, cache or public-asset action runs.
Keep this wrapper beside the exact deploy-vpstc.py core.
"""
from __future__ import annotations

import hashlib
import importlib.util
import json
from pathlib import Path

CORE_SHA256 = '00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de'
BASE = '984d7d04fa3a29816465ed63bb165a47f4cec380'
RUNTIME_PATHS = (
    'app/Services/ExchangeRate.php',
    'app/Services/Cloud/VpsTcPricing.php',
    'app/Services/Cloud/CloudOfferSelection.php',
    'app/Services/Cloud/InfrastructureSettingsStatus.php',
    'app/Services/Cloud/CloudCatalogRepricer.php',
    'app/Services/Cloud/CloudDominance.php',
    'app/Services/Cloud/VpsTcClient.php',
    'app/Models/CloudPlan.php',
    'app/Http/Controllers/Account/CloudServerController.php',
    'app/Http/Controllers/Account/CloudStoreController.php',
    'app/Http/Controllers/Admin/CloudController.php',
    'app/Http/Controllers/Admin/VpsTcController.php',
    'app/Http/Controllers/Admin/SettingsController.php',
    'app/Console/Commands/FetchDollar.php',
    'resources/views/admin/partials/vpstc-settings-panel.blade.php',
    'resources/views/admin/settings/_infra-summary.blade.php',
    'resources/views/admin/settings/_save.blade.php',
    'resources/views/admin/settings/pricing.blade.php',
    'resources/views/admin/settings/infra.blade.php',
    'resources/views/admin/cloud-vpstc.blade.php',
)
NEW_PATHS = frozenset((
    'app/Services/Cloud/CloudOfferSelection.php',
    'app/Services/Cloud/InfrastructureSettingsStatus.php',
    'resources/views/admin/partials/vpstc-settings-panel.blade.php',
    'resources/views/admin/settings/_infra-summary.blade.php',
))


class WrapperError(RuntimeError):
    pass


def load_scoped_core(core_path: Path | None = None):
    path = core_path or Path(__file__).with_name('deploy-vpstc.py')
    if hashlib.sha256(path.read_bytes()).hexdigest() != CORE_SHA256:
        raise WrapperError('pinned_release_core_hash_mismatch')
    spec = importlib.util.spec_from_file_location('infrastructure_settings_scoped_release_core', path)
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
