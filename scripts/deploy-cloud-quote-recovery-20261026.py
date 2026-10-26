#!/usr/bin/env python3
"""Plan/apply/rollback only the reviewed CloudCatalogRepricer quote recovery.

Default is plan-only. Uses the exact pinned file-release core and the installed
runtime commit as base. No FX refresh, API, setting, sale, cache or cron action.
Keep this wrapper beside the exact deploy-vpstc.py core.
"""
from __future__ import annotations

import hashlib
import importlib.util
import json
from pathlib import Path

CORE_SHA256 = '00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de'
BASE = 'fb791fc47e1133d18be1c1d0583a435175d2db77'
RUNTIME_PATHS = ('app/Services/Cloud/CloudCatalogRepricer.php',)


class WrapperError(RuntimeError):
    pass


def load_scoped_core(core_path: Path | None = None):
    path = core_path or Path(__file__).with_name('deploy-vpstc.py')
    if hashlib.sha256(path.read_bytes()).hexdigest() != CORE_SHA256:
        raise WrapperError('pinned_release_core_hash_mismatch')
    spec = importlib.util.spec_from_file_location('cloud_quote_recovery_release_core', path)
    core = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(core)
    core.BASE = BASE
    core.RUNTIME_PATHS = RUNTIME_PATHS
    core.NEW_PATHS = frozenset()
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
