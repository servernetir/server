#!/usr/bin/env python3
"""Scoped, hash-pinned credit-alert delta; plan by default, no application bootstrap."""
from __future__ import annotations

import hashlib
import importlib.util
from pathlib import Path
import sys

sys.dont_write_bytecode = True
BASE = '187cf36d489b3a53229df9fa1a50315dca23e930'
CORE_HASH = '00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de'
SOURCES = {
    'app/Services/Notify/SaladCreditAlert.php': {
        'base': None,
        'target': '9f533dce4de19cd60404d63d3e630f612117c83fc68748d22d3cde90b9fa2b01',
    },
    'app/Services/Bale/BaleNotifier.php': {
        'base': '0de5a92153c75e877bf6c5f4c00278954892aec5921e43cc6a8df4b18a4f00d5',
        'target': '5a63fe65bf5c00b353e1637550cbe59e3cf8017441c51bd0b87dac7f4522f000',
    },
    'app/Services/Notify/AdminNotifier.php': {
        'base': 'b15ebeb8619dcd06d882f44d3c7c3287ffc9f6f49727289fae4f1e9ea0817a75',
        'target': '323a7c2acf0c2e7a0ca83242b1c44573757d2530b856414147ddf665e2813444',
    },
    'app/Services/Cloud/SaladClient.php': {
        'base': '37aea263adce7a225c15e160955040266e4f97c199e2f7017d1124ef7d2cdab3',
        'target': 'faed40691b0a830df4fb849b38493dc335eecc7dcedd7dfc043aa527ca77cde7',
    },
}


def load_scoped_core(core_path: Path | None = None):
    path = core_path or Path(__file__).with_name('deploy-vpstc.py')
    if path.is_symlink() or hashlib.sha256(path.read_bytes()).hexdigest() != CORE_HASH:
        raise RuntimeError('pinned_core_mismatch')
    spec = importlib.util.spec_from_file_location('salad_credit_release_core', path)
    core = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(core)
    core.BASE = BASE
    core.RUNTIME_PATHS = tuple(SOURCES)
    core.NEW_PATHS = frozenset(p for p, row in SOURCES.items() if row['base'] is None)
    original_build = core.build_plan

    def build(root, target, php, reader=core.fetch_source):
        def verified(commit, relative):
            data = reader(commit, relative)
            expected = SOURCES[relative]['base' if commit == BASE else 'target']
            actual = None if data is None else hashlib.sha256(data).hexdigest()
            if actual != expected:
                raise core.ReleaseError('pinned_source_mismatch:' + relative)
            return data
        return original_build(root, target, php, verified)

    core.build_plan = build
    return core


if __name__ == '__main__':
    try:
        raise SystemExit(load_scoped_core().main())
    except RuntimeError:
        print('{"status":"failed","reason":"pinned_core_mismatch"}')
        raise SystemExit(2)
