#!/usr/bin/env python3
"""Plan/apply/rollback the funded hourly-credit delta, preserving live overlays.

No migration, ledger write, provider command, scheduler execution or cache flush.
New helpers precede callers. Use the exact pinned deploy-vpstc.py core beside this
file. Activation and observing the existing scheduler are separate acceptance steps.
"""
import hashlib
import importlib.util
from pathlib import Path
import re

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


def merge_translations(before, base, desired):
    """Merge only uniquely identified one-line translations; reject key conflicts.

    A concurrent release can append unrelated translations at the array end,
    where a context patch would otherwise conflict. Reproduce the entire source
    delta first; any non-translation change still fails closed.
    """
    pattern = re.compile(r'''^    ['"]([^'"]+)['"] => .*,$''', re.MULTILINE)
    def rows(text):
        values = {}
        for match in pattern.finditer(text):
            key = match.group(1)
            if key in values:
                raise RuntimeError('duplicate_translation_key:' + key)
            values[key] = match.group(0)
        return values
    original, wanted, current = (data.decode('utf-8').replace('\r\n', '\n')
                                 for data in (base, desired, before))
    old, new = rows(original), rows(wanted)
    if set(old) - set(new):
        raise RuntimeError('translation_removal_requires_review')
    changes = {key: value for key, value in new.items() if old.get(key) != value}
    def apply(text, source=False):
        existing = rows(text)
        for key, value in changes.items():
            if key in old:
                if existing.get(key) == value:
                    continue
                if existing.get(key) != old[key]:
                    raise RuntimeError('concurrent_translation_conflict:' + key)
                text = text.replace(old[key], value, 1)
            elif key in existing:
                if existing[key] != value:
                    raise RuntimeError('concurrent_translation_conflict:' + key)
            else:
                if not text.endswith('];\n'):
                    raise RuntimeError('translation_array_ending_requires_review')
                text = text[:-3] + value + '\n];\n'
        return text
    if apply(original, source=True) != wanted:
        raise RuntimeError('non_translation_source_delta')
    merged = apply(current).encode()
    return merged.replace(b'\n', b'\r\n') if b'\r\n' in before else merged


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
    original_merge = core.merge_candidate
    def merge(release, relative, before, base, desired):
        if relative in ('lang/fa/ui.php', 'lang/en/ui.php', 'lang/tr/ui.php'):
            return merge_translations(before, base, desired)
        return original_merge(release, relative, before, base, desired)
    core.merge_candidate = merge
    return core


if __name__ == '__main__':
    raise SystemExit(load_scoped_core().main())
