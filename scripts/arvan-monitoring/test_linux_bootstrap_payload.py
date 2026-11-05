"""PHP-owned source/payload checks. Never execute a valid guest installer launcher."""
from __future__ import annotations

import base64
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
CLASS = ROOT / 'website/app/Services/Cloud/ArvanLinuxBootstrap.php'
RESOURCES = ROOT / 'website/resources/arvan-monitoring'
PHP = os.environ.get('PHP_BINARY', 'php')
HARNESS = r'''
require $argv[1];
try { echo json_encode(['ok'=>true, 'built'=>\App\Services\Cloud\ArvanLinuxBootstrap::build($argv[2])], JSON_THROW_ON_ERROR); }
catch (Throwable $error) { echo json_encode(['ok'=>false, 'reason'=>$error->getMessage()], JSON_THROW_ON_ERROR); }
'''


class LinuxBootstrapPayloadTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if shutil.which(PHP) is None:
            raise RuntimeError('PHP_BINARY must select an available PHP executable')

    def build(self, family='ubuntu', path=CLASS):
        out = subprocess.run([PHP, '-r', HARNESS, str(path), family], text=True, capture_output=True, timeout=5)
        self.assertEqual(out.returncode, 0, out.stderr)
        self.assertEqual(out.stderr, '')
        return json.loads(out.stdout)

    def copy_fixture(self, root):
        target = Path(root) / 'app/Services/Cloud/ArvanLinuxBootstrap.php'
        target.parent.mkdir(parents=True)
        shutil.copyfile(CLASS, target)
        resources = Path(root) / 'resources/arvan-monitoring'
        resources.mkdir(parents=True)
        for name in ['install-linux-exporter.py', 'bootstrap-linux-exporter.py']:
            shutil.copyfile(RESOURCES / name, resources / name)
        return target, resources

    def test_embedded_bytes_match_exact_reviewed_sources_and_pins(self):
        result = self.build()['built']
        script = result['script']
        self.assertTrue(script.startswith('#!/bin/sh\n'))
        self.assertIn("exec /usr/bin/python3 -I -S - <<'SNET_ARVAN_BOOTSTRAP_V1'", script)
        self.assertLessEqual(len(script.encode()), 32768)
        self.assertEqual(hashlib.sha256(script.encode()).hexdigest(), result['metadata']['source_sha256'])
        for label, name in [('installer', 'install-linux-exporter.py'), ('wrapper', 'bootstrap-linux-exporter.py')]:
            encoded, expected = re.search(label + r" = source\('([^']+)', '([a-f0-9]{64})'\)", script).groups()
            source = gzip.decompress(base64.b64decode(encoded, validate=True))
            self.assertEqual(source, (RESOURCES / name).read_bytes())
            self.assertEqual(hashlib.sha256(source).hexdigest(), expected)
            self.assertEqual(expected, result['metadata'][label + '_sha256'])
        self.assertEqual((RESOURCES / 'install-linux-exporter.py').read_bytes(),
                         (ROOT / 'scripts/arvan-monitoring/install-linux-exporter.py').read_bytes())
        self.assertEqual(result['metadata']['state'], 'requested')
        self.assertEqual(result['metadata']['execution'], 'unknown')
        self.assertEqual(result['metadata']['central_enrollment'], 'not_performed')
        self.assertEqual(result['metadata']['billing'], 'not_performed')

    def test_no_guest_identity_secret_or_customer_input_needed(self):
        ubuntu = self.build('ubuntu')['built']
        debian = self.build('debian')['built']
        self.assertEqual(ubuntu['script'], debian['script'])
        self.assertNotIn('Authorization', ubuntu['script'])
        self.assertNotIn('SYNTHETIC_PROVIDER_SECRET', ubuntu['script'])
        self.assertNotIn('SYNTHETIC_VM_PASSWORD', ubuntu['script'])
        self.assertNotIn('user_data', ubuntu['script'])

    def test_unsupported_family_refuses_before_resource_reads(self):
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / 'app/Services/Cloud/ArvanLinuxBootstrap.php'
            target.parent.mkdir(parents=True)
            shutil.copyfile(CLASS, target)
            for family in ['windows', 'rocky', '', 'Ubuntu', 'ubuntu\n']:
                with self.subTest(family=family):
                    self.assertEqual(self.build(family, target), {'ok': False, 'reason': 'unsupported_bootstrap_family'})

    def test_tampered_source_refuses_without_loading_it(self):
        for name in ['install-linux-exporter.py', 'bootstrap-linux-exporter.py']:
            with self.subTest(name=name), tempfile.TemporaryDirectory() as directory:
                target, resources = self.copy_fixture(directory)
                (resources / name).write_bytes(b'raise RuntimeError("SOURCE_EXECUTION_CANARY")\n')
                result = self.build(path=target)
                self.assertEqual(result, {'ok': False, 'reason': 'bootstrap_source_digest_mismatch'})
                self.assertNotIn('SOURCE_EXECUTION_CANARY', json.dumps(result))

    def test_missing_symlink_directory_and_oversized_assets_refuse(self):
        for kind in ['missing', 'symlink', 'directory', 'oversized']:
            with self.subTest(kind=kind), tempfile.TemporaryDirectory() as directory:
                target, resources = self.copy_fixture(directory)
                source = resources / 'bootstrap-linux-exporter.py'
                source.unlink()
                if kind == 'symlink':
                    source.symlink_to(RESOURCES / source.name)
                elif kind == 'directory':
                    source.mkdir()
                elif kind == 'oversized':
                    source.write_bytes(b'x' * 65537)
                self.assertEqual(self.build(path=target), {'ok': False, 'reason': 'bootstrap_source_unavailable'})

    def test_actual_create_never_claims_or_posts_when_bootstrap_pin_fails(self):
        with tempfile.TemporaryDirectory() as directory:
            target, resources = self.copy_fixture(directory)
            (resources / 'bootstrap-linux-exporter.py').write_bytes(b'raise RuntimeError("SOURCE_EXECUTION_CANARY")\n')
            out = subprocess.run([PHP, str(ROOT / 'scripts/arvan-monitoring/test-create-bootstrap.php'), str(target)],
                                 text=True, capture_output=True, timeout=5)
            self.assertEqual(out.returncode, 0, out.stderr)
            self.assertEqual(out.stderr, '')
            self.assertEqual(json.loads(out.stdout), {'ok': False, 'transient': True, 'post_count': 0, 'claim_count': 0})
            self.assertNotIn('SOURCE_EXECUTION_CANARY', out.stdout)

    def test_shell_and_python_syntax_without_executing_valid_launcher(self):
        script = self.build()['built']['script']
        out = subprocess.run(['sh', '-n'], input=script, text=True, capture_output=True, timeout=5)
        self.assertEqual(out.returncode, 0, out.stderr)
        python = script.split("<<'SNET_ARVAN_BOOTSTRAP_V1'\n", 1)[1].rsplit('\nSNET_ARVAN_BOOTSTRAP_V1', 1)[0]
        compile(python, '<synthetic-launcher-syntax>', 'exec')

    def test_launch_pin_failure_precedes_any_wrapper_execution(self):
        script = self.build()['built']['script']
        python = script.split("<<'SNET_ARVAN_BOOTSTRAP_V1'\n", 1)[1].rsplit('\nSNET_ARVAN_BOOTSTRAP_V1', 1)[0]
        bad_source = base64.b64encode(gzip.compress(b'raise RuntimeError("SOURCE_EXECUTION_CANARY")')).decode()
        python = re.sub(r"installer = source\('[^']+',", "installer = source('" + bad_source + "',", python, count=1)
        # The installer pin fails before loading/executing even the wrapper.
        out = subprocess.run([sys.executable, '-c', python], text=True, capture_output=True, timeout=5)
        self.assertEqual(out.returncode, 1)
        self.assertEqual(out.stderr, '')
        result = json.loads(out.stdout)
        self.assertEqual(result['status'], 'refused')
        self.assertEqual(result['reason'], 'bootstrap_source_invalid')
        self.assertEqual(result['central_enrollment'], 'not_performed')
        self.assertEqual(result['billing'], 'not_performed')
        self.assertNotIn('SOURCE_EXECUTION_CANARY', out.stdout)


if __name__ == '__main__':
    unittest.main()
