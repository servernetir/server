#!/usr/bin/env python3
"""Scoped country catalogue/UI release, including the separate public document root.

Uses the reviewed deployment core: two shared locks, private staging/backups,
before/after hashes, atomic file replacement and guarded rollback. No data writes,
migrations, provider calls or broad cache clearing. Default operation only plans.
"""
import hashlib
import importlib.util
from pathlib import Path

BASE = '335e0573b219adb689f9c5f008c87ece7d23b60f'
CORE_SHA256 = '00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de'
ASSETS = (
    'assets/css/vps-catalog.css', 'assets/css/choice-hints.css',
    'assets/js/vps-catalog.js', 'assets/js/choice-hints.js', 'assets/js/cloud-image-cards.js',
)
SOURCES = (
    'app/Services/Cloud/CloudPublicQuote.php',
    'app/Services/Cloud/PublicVpsCatalog.php',
    'app/Http/Controllers/CountryVpsController.php',
    *(f'lang/{language}/vps-catalog.php' for language in ('fa', 'en', 'tr')),
    'resources/views/partials/vps-plan-table.blade.php',
    'resources/views/pages/vps-country.blade.php',
    *(f'public/{asset}' for asset in ASSETS),
    'app/Services/Cloud/CloudCountry.php',
    'app/Http/Controllers/CatalogController.php',
    'app/Http/Controllers/CloudCatalogController.php',
    'app/Http/Controllers/HourlyVpsController.php',
    'app/Http/Controllers/SiteController.php',
    'resources/views/pages/vps-hourly.blade.php',
    'resources/views/account/cloud-store.blade.php',
)
MIRRORS = {f'site-public/{asset}': f'public/{asset}' for asset in ASSETS}
# These two inspected live baselines differ from Git. A changed snapshot or live
# destination fails closed; every other live overlay is retained by delta merge.
RECONCILED = {
    'app/Services/Cloud/CloudPublicQuote.php': (
        'before-app_Services_Cloud_CloudPublicQuote.php',
        '35b231a42b47aa95fc4245aca7bdb26dd041beaf79986c5de08ee7c2433648bf'),
    'public/assets/js/cloud-image-cards.js': (
        'before-public_assets_js_cloud-image-cards.js',
        '8049915f42568430d961afaee723c38cfc178c6d22840e8988f2ae20517c132d'),
}


def configure(core, snapshot_dir: Path, public_root: Path):
    core.BASE = BASE
    core.RUNTIME_PATHS = SOURCES + tuple(MIRRORS)
    core.NEW_PATHS = frozenset(SOURCES[:8] + tuple(
        f'public/{asset}' for asset in ASSETS if asset != 'assets/js/cloud-image-cards.js'
    ) + tuple(key for key in MIRRORS if not key.endswith('/cloud-image-cards.js')))
    original_fetch = core.fetch_source
    original_build = core.build_plan
    original_lint = core.lint

    def destination(root: Path, relative: str) -> Path:
        if relative not in core.RUNTIME_PATHS:
            raise core.ReleaseError('path_outside_release_scope')
        base = core.checked_root(public_root) if relative in MIRRORS else root
        tail = MIRRORS[relative].removeprefix('public/') if relative in MIRRORS else relative
        path = base / tail
        cursor = path
        while cursor != base:
            if cursor.is_symlink():
                raise core.ReleaseError('symlink_in_release_path:' + relative)
            cursor = cursor.parent
        if path.exists() and not path.is_file():
            raise core.ReleaseError('nonregular_release_file:' + relative)
        return path

    def source(commit: str, relative: str):
        if relative not in core.RUNTIME_PATHS:
            raise core.ReleaseError('source_path_outside_scope')
        if commit == BASE and relative in RECONCILED:
            name, expected = RECONCILED[relative]
            core.checked_root(snapshot_dir)
            path = snapshot_dir / name
            if path.is_symlink() or not path.is_file():
                raise core.ReleaseError('missing_reviewed_snapshot:' + relative)
            data = path.read_bytes()
            if hashlib.sha256(data).hexdigest() != expected:
                raise core.ReleaseError('reviewed_snapshot_hash_changed:' + relative)
            return data
        return original_fetch(commit, MIRRORS.get(relative, relative))

    def build(root, target, php, source_reader=source):
        # In particular, a newer edit to a reconciled file must not be rebased
        # implicitly: require the exact baseline that was reviewed for this task.
        for relative, (_, expected) in RECONCILED.items():
            live = destination(root, relative)
            if hashlib.sha256(live.read_bytes()).hexdigest() != expected:
                raise core.ReleaseError('reviewed_live_baseline_changed:' + relative)
        return original_build(root, target, php, source_reader=source_reader)

    def lint(php, path, relative):
        if relative.endswith('.php'):
            original_lint(php, path, relative)

    core.checked_file = destination
    core.build_plan = build
    core.lint = lint
    return core


def main():
    private_dir = Path(__file__).resolve().parent
    core_path = private_dir / 'deploy-core.py'
    if core_path.is_symlink() or hashlib.sha256(core_path.read_bytes()).hexdigest() != CORE_SHA256:
        raise RuntimeError('deployment_core_hash_mismatch')
    spec = importlib.util.spec_from_file_location('vps_catalog_deployment_core', core_path)
    core = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(core)
    configure(core, private_dir, private_dir.parent / 'public_html')
    return core.main()


if __name__ == '__main__':
    raise SystemExit(main())
