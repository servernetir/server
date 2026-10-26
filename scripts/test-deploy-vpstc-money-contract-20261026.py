#!/usr/bin/env python3
"""Focused offline tests for money helper ordering, source scope and file safety."""
import hashlib
import importlib.util
import os
from pathlib import Path
import shutil
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('vpstc_money_wrapper', Path(__file__).with_name('deploy-vpstc-money-contract-20261026.py'))
wrapper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(wrapper)
PHP = os.environ.get('PHP_BIN') or shutil.which('php') or '/opt/cpanel/ea-php84/root/usr/bin/php'
TARGET = 'a' * 40
OLD = b'<?php\n$first = 1;\n' + b'// stable context\n' * 10 + b'$last = 1;\n'
NEW = OLD.replace(b'$last = 1;', b'$last = 2;')
PATHS = (
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


class MoneyReleaseIdentityTest(unittest.TestCase):
    def test_pinned_core_installed_base_exact_scope_and_dependency_order(self):
        path = Path(wrapper.__file__).with_name('deploy-vpstc.py')
        self.assertEqual('00d016eb781d37d31f14e1c57afd386321b308060c7f06e9d111f2c4636638de', wrapper.CORE_SHA256)
        self.assertEqual(wrapper.CORE_SHA256, hashlib.sha256(path.read_bytes()).hexdigest())
        core = wrapper.load_scoped_core()
        self.assertEqual('ffa7205fc48a524af814586ccfa66683496fee85', core.BASE)
        self.assertEqual(PATHS, core.RUNTIME_PATHS)
        self.assertEqual(frozenset(PATHS[:2]), core.NEW_PATHS)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for excluded in ('.env', 'routes/web.php', 'database/migrations/money.php',
                             'app/Models/Setting.php', 'app/Services/Dns/CloudflareDns.php'):
                with self.assertRaises(core.ReleaseError):
                    core.checked_file(root, excluded)
            altered = root / 'core.py'
            altered.write_bytes(path.read_bytes() + b'\nraise AssertionError("untrusted core must not execute")\n')
            with self.assertRaisesRegex(wrapper.WrapperError, 'pinned_release_core_hash_mismatch'):
                wrapper.load_scoped_core(altered)


@unittest.skipUnless(Path(PHP).is_file(), 'Set PHP_BIN to an isolated PHP runtime')
class MoneyReleaseFixtureTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'application'
        self.root.mkdir()
        self.core = wrapper.load_scoped_core()
        for relative in PATHS[2:]:
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(OLD)
            path.chmod(0o640)
        self.dns = self.root / 'app/Services/Dns/CloudflareDns.php'
        self.dns.parent.mkdir(parents=True)
        self.dns.write_bytes(b'<?php\n// newer independent DNS commit\n')
        self.requests = []

    def source(self, commit, relative):
        self.requests.append((commit, relative))
        return (None if relative in self.core.NEW_PATHS else OLD) if commit == wrapper.BASE else NEW

    def plan(self):
        with self.core.release_locks(self.root):
            return self.core.build_plan(self.root, TARGET, PHP, self.source)

    def test_readonly_plan_stages_only_two_new_helpers_and_exact_nine_file_delta(self):
        directory, plan = self.plan()
        self.assertEqual(list(PATHS), [row['path'] for row in plan['files']])
        self.assertEqual(18, len(self.requests))
        self.assertEqual(set(PATHS), {relative for _, relative in self.requests})
        self.assertEqual(set(PATHS[:2]), {row['path'] for row in plan['files'] if row['before_sha256'] is None})
        for helper in PATHS[:2]:
            self.assertFalse((self.root / helper).exists())
            self.assertEqual(self.core.digest(NEW), self.core.digest((directory / 'candidate' / helper).read_bytes()))
        self.assertEqual(OLD, (self.root / PATHS[2]).read_bytes())
        self.assertFalse((directory / 'receipt.json').exists())
        self.core.load_plan(self.root, TARGET, directory, plan['plan_sha256'])

    def test_helpers_install_before_callers_and_rollback_preserves_unrelated_dns_and_live_drift(self):
        before = OLD.replace(b'$first = 1;', b'$first = 3;')
        file = self.root / PATHS[2]
        file.write_bytes(before)
        dns_hash = self.core.digest(self.dns.read_bytes())
        directory, plan = self.plan()
        written = []
        def writer(path, data, mode):
            written.append(path.relative_to(self.root).as_posix())
            self.core.atomic_write(path, data, mode)
        with self.core.release_locks(self.root):
            checked = self.core.load_plan(self.root, TARGET, directory, plan['plan_sha256'])
            result = self.core.apply_plan(self.root, directory, checked, PHP, writer=writer)
            self.assertEqual(list(PATHS), written)
            self.assertEqual(9, result['files_installed'])
            self.assertEqual(before.replace(b'$last = 1;', b'$last = 2;'), file.read_bytes())
            self.assertEqual(dns_hash, self.core.digest(self.dns.read_bytes()))
            for helper in PATHS[:2]:
                self.assertEqual(0o644, (self.root / helper).stat().st_mode & 0o777)
            restored = self.core.explicit_rollback(self.root, directory, checked)
        self.assertEqual('rolled_back', restored['status'])
        self.assertEqual(9, restored['files_restored'])
        self.assertEqual(before, file.read_bytes())
        self.assertEqual(0o640, file.stat().st_mode & 0o777)
        self.assertTrue(all(not (self.root / helper).exists() for helper in PATHS[:2]))
        self.assertEqual(dns_hash, self.core.digest(self.dns.read_bytes()))

    def test_unknown_new_helper_or_changed_live_file_stops_without_application_writes(self):
        helper = self.root / PATHS[0]
        helper.write_bytes(b'<?php\n// another actor owns this helper\n')
        helper_hash = self.core.digest(helper.read_bytes())
        with self.assertRaisesRegex(self.core.ReleaseError, 'unexpected_existing_new_file'):
            self.plan()
        self.assertEqual(helper_hash, self.core.digest(helper.read_bytes()))
        self.assertEqual(OLD, (self.root / PATHS[2]).read_bytes())
        helper.unlink()  # Disposable local fixture only; production is never touched.
        directory, plan = self.plan()
        changed = self.root / PATHS[-1]
        current = OLD + b'// newer administrator panel change\n'
        changed.write_bytes(current)
        checked = self.core.load_plan(self.root, TARGET, directory, plan['plan_sha256'])
        with self.assertRaisesRegex(self.core.ReleaseError, 'live_hash_or_mode_changed'):
            self.core.apply_plan(self.root, directory, checked, PHP)
        self.assertFalse(helper.exists())
        self.assertEqual(self.core.digest(current), self.core.digest(changed.read_bytes()))
        self.assertFalse((directory / 'receipt.json').exists())


if __name__ == '__main__':
    unittest.main()
