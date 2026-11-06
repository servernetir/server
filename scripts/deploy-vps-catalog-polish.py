#!/usr/bin/env python3
"""Follow-up display-only delta after the country catalogue release; same guards and rollback."""
import importlib.util
from pathlib import Path


def main():
    path = Path(__file__).with_name('deploy-vps-catalog.py')
    spec = importlib.util.spec_from_file_location('vps_catalog_release', path)
    release = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(release)
    release.BASE = '769ebd170e036d0eca2b637a49a4ff325b307081'
    release.RECONCILED = {}
    release.ASSETS = ('assets/js/vps-catalog.js',)
    release.SOURCES = (
        'app/Services/Cloud/PublicVpsCatalog.php',
        'app/Http/Controllers/CountryVpsController.php',
        'lang/fa/vps-catalog.php', 'lang/en/vps-catalog.php', 'lang/tr/vps-catalog.php',
        'resources/views/pages/vps-country.blade.php',
        'resources/views/partials/vps-plan-table.blade.php',
        'public/assets/js/vps-catalog.js',
    )
    release.MIRRORS = {'site-public/assets/js/vps-catalog.js': 'public/assets/js/vps-catalog.js'}
    return release.main()


if __name__ == '__main__':
    raise SystemExit(main())
