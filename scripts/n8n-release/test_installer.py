import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from unittest.mock import Mock
import urllib.error

spec = importlib.util.spec_from_file_location('n8n_installer', Path(__file__).parents[2] / 'website/resources/n8n/install.py')
installer = importlib.util.module_from_spec(spec)
spec.loader.exec_module(installer)


class InstallerTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.spec = {'service_id': 17, 'hostname': 'n8n-17.example.test', 'nonce': 'allocation-marker',
                     'version': '2.48.7', 'email': 'owner@example.test', 'password': 'PRIVATE_INITIAL_PASSWORD',
                     'firstName': 'Test', 'lastName': 'Owner', 'private': True,
                     'vcpu': 1, 'ram_mb': 1, 'disk_gb': 1}
        (self.root / 'request.json').write_text(json.dumps(self.spec))

    def test_owner_is_initialized_before_public_listener_starts_and_secrets_are_not_in_compose(self):
        calls = []

        def api(path, payload=None):
            calls.append(path)
            return {'status': 'ok'} if path.startswith('/health') else {'data': {'email': self.spec['email']}}

        with patch.object(installer, 'ROOT', self.root), patch.object(installer, 'install_docker'), patch.object(installer, 'pull_image') as images, \
                patch.object(installer, 'api', side_effect=api), \
                patch.object(installer, 'run', side_effect=lambda command, **kw: calls.append(command)):
            installer.install()
        images.assert_any_call('docker.n8n.io/n8nio/n8n:2.48.7', 'ghcr.io/n8n-io/n8n:2.48.7')
        setup = calls.index('/rest/owner/setup')
        public = next(i for i, value in enumerate(calls) if isinstance(value, list) and value[-1] == 'caddy')
        self.assertLess(setup, public)
        self.assertNotIn(self.spec['password'], (self.root / 'compose.yaml').read_text())
        self.assertIn('127.0.0.1:5678:5678', (self.root / 'compose.yaml').read_text())
        self.assertNotIn('5432:5432', (self.root / 'compose.yaml').read_text())
        self.assertFalse((self.root / 'request.json').exists())
        self.assertEqual(17, json.loads((self.root / 'ready.json').read_text())['service_id'])

    def test_lost_owner_setup_response_verifies_same_login_without_resetting_owner_or_keys(self):
        (self.root / '.env').write_text('N8N_ENCRYPTION_KEY=EXISTING_KEY\nPOSTGRES_PASSWORD=EXISTING_DB_PASSWORD\n')

        def api(path, payload=None):
            if path == '/rest/owner/setup':
                raise urllib.error.HTTPError('http://127.0.0.1', 400, 'already setup', {}, None)
            if path == '/rest/login':
                self.assertEqual(self.spec['email'], payload['emailOrLdapLoginId'])
                return {'data': {'email': self.spec['email']}}
            return {'status': 'ok'}

        with patch.object(installer, 'ROOT', self.root), patch.object(installer, 'install_docker'), patch.object(installer, 'pull_image'), \
                patch.object(installer, 'api', side_effect=api), patch.object(installer, 'run'):
            installer.install()
        self.assertIn('EXISTING_KEY', (self.root / '.env').read_text())
        self.assertTrue((self.root / 'owner-initialized').exists())

    def test_wrong_owner_receipt_never_opens_the_public_listener(self):
        with patch.object(installer, 'ROOT', self.root), patch.object(installer, 'install_docker'), patch.object(installer, 'pull_image'), \
                patch.object(installer, 'api', return_value={'data': {'email': 'someone-else@example.test'}}), \
                patch.object(installer, 'run') as run:
            with self.assertRaisesRegex(RuntimeError, 'owner_setup_unconfirmed'):
                installer.install()
        self.assertFalse(any(call.args[0][-1] == 'caddy' for call in run.call_args_list))
        self.assertFalse((self.root / 'ready.json').exists())

    def test_docker_repository_403_uses_signed_ubuntu_packages(self):
        error = urllib.error.HTTPError('https://download.docker.com', 403, 'blocked', {}, None)
        with patch.object(installer, 'ROOT', self.root), \
                patch.object(installer.shutil, 'which', return_value=None), \
                patch.object(Path, 'read_text', return_value='ID=ubuntu\nVERSION_CODENAME=noble\n'), \
                patch.object(Path, 'mkdir'), \
                patch.object(installer.subprocess, 'check_output', return_value='amd64'), \
                patch.object(installer.urllib.request, 'urlopen', side_effect=error), \
                patch.object(installer, 'run') as run:
            installer.install_docker()
        commands = [call.args[0] for call in run.call_args_list]
        self.assertIn(['apt-get', 'install', '-y', '-qq', 'docker.io', 'docker-compose-v2'], commands)
        self.assertNotIn(['apt-get', 'install', '-y', '-qq', 'docker-ce'], commands)
        self.assertIn(['systemctl', 'enable', '--now', 'docker'], commands)

    def test_explicit_registry_denial_uses_the_official_mirror_and_preserves_the_compose_tag(self):
        results = [Mock(returncode=1), Mock(returncode=1, stderr=b'403 Forbidden')]
        with patch.object(installer.subprocess, 'run', side_effect=results), patch.object(installer, 'run') as run:
            installer.pull_image('caddy:2-alpine', 'public.ecr.aws/docker/library/caddy:2-alpine')
        self.assertEqual(['docker', 'pull', 'public.ecr.aws/docker/library/caddy:2-alpine'], run.call_args_list[0].args[0])
        self.assertEqual(['docker', 'tag', 'public.ecr.aws/docker/library/caddy:2-alpine', 'caddy:2-alpine'], run.call_args_list[1].args[0])

    def test_working_runtime_is_reused_without_package_network_calls(self):
        with patch.object(installer.shutil, 'which', return_value='/usr/bin/docker'), \
                patch.object(installer.subprocess, 'run', return_value=Mock(returncode=0)), \
                patch.object(installer, 'run') as run:
            installer.install_docker(private=True)
        run.assert_not_called()

    def test_private_ubuntu_uses_distribution_packages_before_contacting_vendor(self):
        with patch.object(installer.shutil, 'which', return_value=None), \
                patch.object(Path, 'read_text', return_value='ID=ubuntu\nVERSION_CODENAME=noble\n'), \
                patch.object(Path, 'exists', return_value=False), \
                patch.object(installer.subprocess, 'check_output', return_value='amd64'), \
                patch.object(installer.urllib.request, 'urlopen') as vendor, \
                patch.object(installer, 'run') as run:
            installer.install_docker(private=True)
        vendor.assert_not_called()
        self.assertIn(['apt-get', 'install', '-y', '-qq', 'curl', 'ca-certificates', 'docker.io', 'docker-compose-v2'],
                      [call.args[0] for call in run.call_args_list])


if __name__ == '__main__':
    unittest.main()
