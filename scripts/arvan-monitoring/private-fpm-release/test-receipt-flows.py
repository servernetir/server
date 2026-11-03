#!/usr/bin/env python3
"""Exercise the real activation entry point in disposable CLI copies and synthetic files.

Only runtime/root guards, helper hash and fault sites in the test COPY are changed.
No production path, Composer application, real FPM socket or network is used.
"""
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import unittest

HERE = Path(__file__).resolve().parent
PHP = os.environ.get('ARVAN_TEST_PHP') or shutil.which('php')
SOURCE = (HERE / 'private-fpm-activation.php').read_text()
HELPER = (HERE.parent / 'arvan-release-v3' / 'web-release' / 'deploy.php').read_text()
RELEASE = 'arvan-delivery-20261103-v3q4m7dz'
PATHS = ['app/Services/Cloud/ArvanConsoleBroker.php', 'app/Models/CloudInstance.php',
         'lang/fa/ui.php', 'lang/en/ui.php', 'lang/tr/ui.php',
         'resources/views/account/cloud-server.blade.php']


def digest(value):
    return hashlib.sha256(value).hexdigest()


def replace_once(text, old, new):
    if text.count(old) != 1:
        raise AssertionError('Fault/test-copy anchor must be unique: ' + old[:75])
    return text.replace(old, new)


class ActivationReceiptFlows(unittest.TestCase):
    def setUp(self):
        if not PHP:
            self.fail('A local PHP runtime is required for isolated tests.')
        self.temp = tempfile.TemporaryDirectory(prefix='arvan-fpm-receipt-test-')
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name) / 'home'
        self.app = self.home / 'servernet_app'
        self.stage = self.app / 'storage/app/private' / RELEASE
        self.primary = self.stage / 'private-fpm-activation.php'
        self.document_root = self.home / 'public_html'
        self.document_root.mkdir(parents=True, mode=0o755)
        self.stage.mkdir(parents=True, mode=0o700)
        self.stage.chmod(0o700)
        self.write(self.app / 'storage/framework/arvan-delivery-release.lock', b'')
        self.source_bytes = {}
        plan = {'schema': 1, 'files': []}
        for index, relative in enumerate(PATHS):
            payload = ('<?php // immutable installed source ' + str(index) + '\n').encode()
            path = self.app / relative
            self.write(path, payload, 0o644)
            self.source_bytes[relative] = payload
            plan['files'].append({'path': relative, 'before': digest(payload), 'after': digest(payload),
                                  'mode': 0o644, 'uid': os.getuid(), 'gid': os.getgid()})
        self.plan_bytes = (json.dumps(plan, separators=(',', ':')) + '\n').encode()
        self.plan_hash = digest(self.plan_bytes)
        self.write(self.stage / 'plan.json', self.plan_bytes)
        self.write(self.stage / 'receipt.json', json.dumps({'status': 'source_installed_activation_pending',
                                                          'plan_sha256': self.plan_hash}).encode())
        helper = replace_once(HELPER, "const RELEASE_ROOT = '/home/servernetcloud/servernet_app';",
                              'const RELEASE_ROOT = ' + repr(str(self.app)) + ';')
        helper = replace_once(helper, 'function writeNew(string $path, string $bytes, int $mode = 0600): void {',
                              'function writeNew(string $path, string $bytes, int $mode = 0600): void {\n    if (!empty($GLOBALS["af_test_no_more_writes"])) { throw new RuntimeException("synthetic_no_new_stage_writes"); }')
        self.write(self.stage / 'deploy.php', helper.encode())
        self.helper_hash = digest(helper.encode())
        # Only standalone compiler classes, with a deterministic synthetic compiled result.
        vendor = b'''<?php
namespace Illuminate\\Filesystem { class Filesystem {} }
namespace Illuminate\\View\\Compilers {
class BladeCompiler {
 public function __construct($files, $cache) {}
 public function setPath($source) {}
 public function compileString($bytes) { return '<?php echo "new compiled"; ?>'; }
}}
'''
        self.write(self.app / 'vendor/autoload.php', vendor)
        self.compiled = []
        self.before = {}
        for name in ['a', 'b']:
            path = self.app / ('storage/framework/views/' + name + '.php')
            content = ('<?php echo "old ' + name + '"; ?><?php /**PATH '
                       + str(self.app / PATHS[-1]) + ' ENDPATH**/ ?>').encode()
            self.write(path, content, 0o640)
            self.compiled.append(path)
            self.before[path] = content
        self.receipt = self.stage / 'activation-console.servernet.cloud.json'

    def write(self, path, content, mode=0o600):
        path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        path.write_bytes(content)
        path.chmod(mode)

    def prepare_copy(self, fault='none'):
        code = replace_once(SOURCE, "const AF_HOME = '/home/servernetcloud';",
                            'const AF_HOME = ' + repr(str(self.home)) + ';')
        import re
        code, count = re.subn(r"const AF_HELPER_SHA = '[^']+';",
                              "const AF_HELPER_SHA = '" + self.helper_hash + "';", code)
        self.assertEqual(count, 1)
        code = replace_once(code, "PHP_SAPI !== 'fpm-fcgi'", 'false /* CLI TEST COPY ONLY */')
        code = replace_once(code, "version_compare(PHP_VERSION, '8.4.0', '<')", 'false /* TEST COPY ONLY */')
        code = replace_once(code, "!== 'servernetcloud'", "!== " + repr(__import__('pwd').getpwuid(os.geteuid()).pw_name))
        # Record the actual compiled state at the receipt boundary. Deny later
        # helper writes so reversal must consume the already prepared files.
        observation = 'file_put_contents(' + repr(str(self.stage / 'receipt-boundary.json')) + ', json_encode([' + ','.join('file_get_contents(' + repr(str(p)) + ')' for p in self.compiled) + ']));'
        code = replace_once(code, 'function afWriteReceiptTemp(string $path, string $bytes): void {',
                            'function afWriteReceiptTemp(string $path, string $bytes): void {\n    $GLOBALS["af_test_no_more_writes"] = true; ' + observation)
        if fault == 'write':
            code = replace_once(code, "    $handle = fopen($path, 'xb');",
                                "    throw new RuntimeException('synthetic_receipt_write_failure');\n    $handle = fopen($path, 'xb');")
        elif fault == 'short_write':
            code = replace_once(code, '$written = fwrite($handle, substr($bytes, $offset));',
                                '$written = false; /* TEST COPY short write */')
        elif fault == 'flush':
            code = replace_once(code, '!fflush($handle)', 'true /* TEST COPY flush failure */')
        elif fault == 'fsync':
            code = replace_once(code, '!fsync($handle)', 'true /* TEST COPY fsync failure */')
        elif fault == 'temp_hash':
            code = replace_once(code, "if (hash_file('sha256', $path) !== sha($bytes))",
                                "if (true /* TEST COPY hash verification failure */)")
        elif fault == 'candidate_after_write':
            code = replace_once(code, '    afWriteReceiptTemp($temp, $bytes);',
                                "    afWriteReceiptTemp($temp, $bytes);\n    file_put_contents($temp, 'foreign candidate bytes');")
        elif fault == 'link':
            code = replace_once(code, '!@link($temp, $path)', 'true /* TEST COPY link failure */')
        elif fault == 'temp_unlink':
            code = replace_once(code, '!unlink($temp)', 'true /* TEST COPY first temp unlink fails */')
        elif fault == 'foreign_publication':
            code = replace_once(code, '    if (!@link($temp, $path))',
                                "    file_put_contents($path, 'foreign receipt'); chmod($path, 0600);\n    if (!@link($temp, $path))")
        elif fault == 'published_hash':
            code = replace_once(code, '    $saved = afReadPrivate($path, $owner);',
                                "    $saved = afReadPrivate($path, $owner);\n    if (!isset($GLOBALS['af_test_once'])) { $GLOBALS['af_test_once'] = true; $saved['sha256'] = 'synthetic'; }")
        elif fault in ['foreign_bytes', 'foreign_inode', 'compiled_drift', 'source_drift', 'lock_after_publication']:
            action = {
                'foreign_bytes': "file_put_contents($path, 'foreign receipt');",
                'foreign_inode': "file_put_contents($path.'.foreign', file_get_contents($path)); chmod($path.'.foreign', 0600); rename($path.'.foreign', $path);",
                'compiled_drift': 'file_put_contents(' + repr(str(self.compiled[1])) + ", 'concurrent compiled edit');",
                'source_drift': 'file_put_contents(' + repr(str(self.app / PATHS[0])) + ", 'concurrent source edit');",
                'lock_after_publication': 'file_put_contents(' + repr(str(self.app / 'storage/framework/arvan-delivery-release.lock.foreign')) + ', ""); chmod(' + repr(str(self.app / 'storage/framework/arvan-delivery-release.lock.foreign')) + ', 0600); rename(' + repr(str(self.app / 'storage/framework/arvan-delivery-release.lock.foreign')) + ', ' + repr(str(self.app / 'storage/framework/arvan-delivery-release.lock')) + ');',
            }[fault]
            code = replace_once(code, '    $saved = afReadPrivate($path, $owner);',
                                "    if (!isset($GLOBALS['af_test_once'])) { $GLOBALS['af_test_once'] = true; " + action + " }\n    $saved = afReadPrivate($path, $owner);")
        elif fault == 'lock_replaced':
            lock = str(self.app / 'storage/framework/arvan-delivery-release.lock')
            code = replace_once(code, '                $owned = $compiledPrepared[$index];',
                                '                if ($index === 1) { file_put_contents(' + repr(lock + '.foreign') + ', ""); chmod(' + repr(lock + '.foreign') + ', 0600); rename(' + repr(lock + '.foreign') + ', ' + repr(lock) + '); }\n                $owned = $compiledPrepared[$index];')
        elif fault == 'binding_before_publication':
            code = replace_once(code, "        afVerifyTransportReceipt($result, $primary['sha256'], $_SERVER['HTTP_HOST']);",
                                "        $result['transport_variant'] = 'legacy-public';\n        afVerifyTransportReceipt($result, $primary['sha256'], $_SERVER['HTTP_HOST']);")
        elif fault != 'none':
            self.fail('Unknown fault')
        self.write(self.primary, code.encode())

    def invoke(self, action='activate', host='console.servernet.cloud', overrides=None, expect_json=True):
        self.write(self.stage / 'invoke.php', ('''<?php
$_SERVER['SERVERNET_LOCAL_RELEASE'] = %s;
$_SERVER['SERVERNET_RELEASE_ACTION'] = %s;
$_SERVER['SERVERNET_EXPECTED_PLAN_SHA256'] = %s;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = %s;
$_SERVER['SCRIPT_FILENAME'] = %s;
$_SERVER['SCRIPT_NAME'] = '/__servernet_private_arvan_release_20261103_v3q4m7dz.php';
$_SERVER['DOCUMENT_ROOT'] = %s;
%s
require %s;
''' % (repr(RELEASE), repr(action), repr(self.plan_hash), repr(host), repr(str(self.primary)),
       repr(str(self.document_root)), '\n'.join('$_SERVER[' + repr(k) + '] = ' + repr(v) + ';' for k, v in (overrides or {}).items()),
       repr(str(self.primary)))).encode())
        result = subprocess.run([PHP, '-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
                                 str(self.stage / 'invoke.php')], cwd=self.app,
                                capture_output=True, text=True, timeout=20)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertFalse(result.stderr, result.stderr)
        if not expect_json:
            return result.stdout
        try:
            return json.loads(result.stdout)
        except json.JSONDecodeError:
            self.fail(result.stdout)

    def assert_receipt_boundary_reached_after_both_renames(self):
        observed = json.loads((self.stage / 'receipt-boundary.json').read_text())
        self.assertEqual(len(observed), 2)
        self.assertTrue(all('new compiled' in item for item in observed))

    def assert_sources_preserved(self, except_path=None):
        for relative, payload in self.source_bytes.items():
            if relative != except_path:
                self.assertEqual((self.app / relative).read_bytes(), payload, relative)

    def assert_rolled_back(self):
        self.assertFalse(self.receipt.exists())
        for path, payload in self.before.items():
            self.assertEqual(path.read_bytes(), payload)
            self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o640)
        self.assert_sources_preserved()

    def test_success_and_historical_retry_preserve_durable_receipt_and_artifact_inodes(self):
        self.prepare_copy()
        result = self.invoke()
        self.assertEqual(result['status'], 'ok')
        self.assertEqual(result['compiled_matches'], 2)
        before = {p: (p.read_bytes(), p.stat().st_ino) for p in self.compiled + [self.receipt]}
        result = self.invoke()
        self.assertEqual(result['status'], 'ok')
        self.assertTrue(result['activation_already_recorded'])
        for path, expected in before.items():
            self.assertEqual((path.read_bytes(), path.stat().st_ino), expected)
        self.assert_sources_preserved()

    def test_receipt_failures_after_compiled_renames_restore_prepared_artifacts(self):
        for fault in ['write', 'short_write', 'flush', 'fsync', 'temp_hash', 'link', 'temp_unlink', 'published_hash']:
            with self.subTest(fault=fault):
                self.prepare_copy(fault)
                result = self.invoke()
                self.assertEqual(result['status'], 'stopped')
                self.assert_receipt_boundary_reached_after_both_renames()
                self.assertFalse(result['receipt_reconciliation_required'])
                self.assertTrue(result['receipt_withdrawn_or_absent'])
                self.assertEqual(result['compiled_rollback_errors'], [])
                self.assert_rolled_back()

    def test_foreign_receipt_bytes_or_inode_are_preserved_with_installed_artifacts(self):
        for fault in ['foreign_publication', 'foreign_bytes', 'foreign_inode']:
            with self.subTest(fault=fault):
                # Each case needs a fresh scope; remove only known synthetic receipt/artifacts.
                if self.receipt.exists():
                    self.receipt.unlink()
                for path, content in self.before.items():
                    self.write(path, content, 0o640)
                self.prepare_copy(fault)
                result = self.invoke()
                self.assertEqual(result['status'], 'stopped')
                self.assert_receipt_boundary_reached_after_both_renames()
                self.assertTrue(result['receipt_reconciliation_required'])
                self.assertFalse(result['receipt_withdrawn_or_absent'])
                self.assertTrue(self.receipt.exists())
                for path in self.compiled:
                    self.assertIn(b'new compiled', path.read_bytes())
                if fault != 'foreign_inode':
                    self.assertEqual(self.receipt.read_bytes(), b'foreign receipt')
                self.assert_sources_preserved()

    def test_compiled_drift_is_preserved_while_other_owned_artifact_rolls_back(self):
        self.prepare_copy('compiled_drift')
        result = self.invoke()
        self.assertEqual(result['status'], 'stopped')
        self.assertEqual(result['compiled_rollback_errors'], ['b.php'])
        self.assertFalse(self.receipt.exists())
        self.assertEqual(self.compiled[0].read_bytes(), self.before[self.compiled[0]])
        self.assertEqual(self.compiled[1].read_bytes(), b'concurrent compiled edit')
        self.assert_sources_preserved()

    def test_replaced_mutex_stops_second_rename_and_does_not_roll_back_under_foreign_lock(self):
        self.prepare_copy('lock_replaced')
        result = self.invoke()
        self.assertEqual(result['status'], 'stopped')
        self.assertEqual(result['reason'], 'release_lock_identity_changed')
        self.assertEqual(result['compiled_rollback_errors'], [])
        self.assertTrue(result['receipt_reconciliation_required'])
        self.assertFalse(result['receipt_withdrawn_or_absent'])
        self.assertFalse(self.receipt.exists())
        self.assertIn(b'new compiled', self.compiled[0].read_bytes())
        self.assertEqual(self.compiled[1].read_bytes(), self.before[self.compiled[1]])
        self.assert_sources_preserved()

    def test_candidate_drift_after_writer_verification_is_not_published_or_claimed(self):
        self.prepare_copy('candidate_after_write')
        result = self.invoke()
        self.assertEqual(result['status'], 'stopped')
        self.assertEqual(result['reason'], 'activation_receipt_candidate_changed')
        self.assert_receipt_boundary_reached_after_both_renames()
        self.assert_rolled_back()
        candidates = list(self.stage.glob('activation-receipt-*.json'))
        self.assertEqual(len(candidates), 1)
        self.assertEqual(candidates[0].read_bytes(), b'foreign candidate bytes')

    def test_mutex_replacement_after_publication_preserves_receipt_and_installed_artifacts(self):
        self.prepare_copy('lock_after_publication')
        result = self.invoke()
        self.assertEqual(result['status'], 'stopped')
        self.assertEqual(result['reason'], 'release_lock_identity_changed')
        self.assertTrue(result['receipt_reconciliation_required'])
        self.assertFalse(result['receipt_withdrawn_or_absent'])
        self.assertEqual(result['compiled_rollback_errors'], [])
        self.assertEqual(json.loads(self.receipt.read_text())['status'], 'ok')
        for path in self.compiled:
            self.assertIn(b'new compiled', path.read_bytes())
        self.assert_sources_preserved()

    def test_source_drift_after_receipt_publication_preserves_source_and_restores_compiled(self):
        self.prepare_copy('source_drift')
        result = self.invoke()
        self.assertEqual(result['status'], 'stopped')
        self.assertFalse(self.receipt.exists())
        self.assertEqual((self.app / PATHS[0]).read_bytes(), b'concurrent source edit')
        self.assert_sources_preserved(except_path=PATHS[0])
        for path, payload in self.before.items():
            self.assertEqual(path.read_bytes(), payload)

    def assert_binding(self, result, host='console.servernet.cloud'):
        self.assertEqual(result['release'], RELEASE)
        self.assertEqual(result['transport_variant'], 'private-stage-fcgi-v1')
        self.assertEqual(result['primary_path'], str(self.primary))
        self.assertEqual(result['primary_sha256'], digest(self.primary.read_bytes()))
        self.assertEqual(result['host'], host)

    def test_observe_success_is_bound_and_does_not_write_receipt_or_compiled_artifacts(self):
        self.prepare_copy()
        before = {p: (p.read_bytes(), p.stat().st_ino) for p in self.compiled}
        result = self.invoke(action='observe')
        self.assertEqual(result['status'], 'ok')
        self.assert_binding(result)
        self.assertFalse(self.receipt.exists())
        self.assertEqual(list(self.stage.glob('compiled-*')), [])
        for path, expected in before.items():
            self.assertEqual((path.read_bytes(), path.stat().st_ino), expected)
        self.assert_sources_preserved()

    def test_wrong_or_missing_internal_cgi_paths_refuse_before_observation_writes(self):
        self.prepare_copy()
        before = {p: (p.read_bytes(), p.stat().st_ino) for p in self.compiled}
        for key, value in [('SCRIPT_FILENAME', str(self.home / 'public_html/activate.php')),
                           ('SCRIPT_NAME', '/activate.php'), ('DOCUMENT_ROOT', str(self.stage)),
                           ('SCRIPT_FILENAME', ''), ('SCRIPT_NAME', ''), ('DOCUMENT_ROOT', '')]:
            with self.subTest(key=key, value=value):
                self.assertEqual(self.invoke(action='observe', overrides={key: value}, expect_json=False), '')
                self.assertFalse(self.receipt.exists())
                self.assertEqual(list(self.stage.glob('compiled-*')), [])
                for path, expected in before.items():
                    self.assertEqual((path.read_bytes(), path.stat().st_ino), expected)
                self.assert_sources_preserved()

    @staticmethod
    def changed_bindings(original):
        yield 'legacy_unbound', {k: v for k, v in original.items()
                                 if k not in ['transport_variant', 'primary_path', 'primary_sha256']}
        for field, value in [('release', 'other-release'), ('transport_variant', 'public-primary-v3'),
                             ('primary_path', '/home/servernetcloud/public_html/activate.php'),
                             ('primary_sha256', '0' * 64), ('host', 'other.example')]:
            changed = dict(original)
            changed[field] = value
            yield field, changed

    def test_own_legacy_or_changed_transport_receipt_is_preserved_and_never_replayed(self):
        self.prepare_copy()
        self.assertEqual(self.invoke()['status'], 'ok')
        original = json.loads(self.receipt.read_text())
        self.assert_binding(original)
        before = {p: (p.read_bytes(), p.stat().st_ino) for p in self.compiled}
        for case, bad in self.changed_bindings(original):
            with self.subTest(case=case):
                self.write(self.receipt, json.dumps(bad).encode())
                receipt_before = (self.receipt.read_bytes(), self.receipt.stat().st_ino)
                result = self.invoke()
                self.assertEqual(result['status'], 'stopped')
                self.assertEqual(result['reason'], 'activation_receipt_transport_changed')
                self.assertEqual((self.receipt.read_bytes(), self.receipt.stat().st_ino), receipt_before)
                for path, expected in before.items():
                    self.assertEqual((path.read_bytes(), path.stat().st_ino), expected)
                self.assert_sources_preserved()

    def test_peer_legacy_or_changed_transport_receipt_is_preserved_and_never_reused(self):
        self.prepare_copy()
        self.assertEqual(self.invoke(host='servernet.cloud')['status'], 'ok')
        peer = self.stage / 'activation-servernet.cloud.json'
        original = json.loads(peer.read_text())
        self.assert_binding(original, 'servernet.cloud')
        before = {p: (p.read_bytes(), p.stat().st_ino) for p in self.compiled}
        for case, bad in self.changed_bindings(original):
            with self.subTest(case=case):
                self.write(peer, json.dumps(bad).encode())
                peer_before = (peer.read_bytes(), peer.stat().st_ino)
                result = self.invoke()
                self.assertEqual(result['status'], 'stopped')
                self.assertEqual(result['reason'], 'activation_receipt_transport_changed')
                self.assertEqual((peer.read_bytes(), peer.stat().st_ino), peer_before)
                self.assertFalse(self.receipt.exists())
                for path, expected in before.items():
                    self.assertEqual((path.read_bytes(), path.stat().st_ino), expected)
                self.assert_sources_preserved()

    def test_bound_peer_receipt_reuses_compiled_artifacts_and_binds_new_pool_receipt(self):
        self.prepare_copy()
        self.assertEqual(self.invoke(host='servernet.cloud')['status'], 'ok')
        before = {p: (p.read_bytes(), p.stat().st_ino) for p in self.compiled}
        result = self.invoke()
        self.assertEqual(result['status'], 'ok')
        self.assertTrue(result['compiled_prepared_by_prior_pool'])
        self.assert_binding(result)
        self.assert_binding(json.loads(self.receipt.read_text()))
        for path, expected in before.items():
            self.assertEqual((path.read_bytes(), path.stat().st_ino), expected)
        self.assert_sources_preserved()

    def test_bad_transport_binding_before_publication_restores_owned_compiled_artifacts(self):
        self.prepare_copy('binding_before_publication')
        result = self.invoke()
        self.assertEqual(result['status'], 'stopped')
        self.assertEqual(result['reason'], 'activation_receipt_transport_changed')
        self.assert_rolled_back()


if __name__ == '__main__':
    unittest.main()
