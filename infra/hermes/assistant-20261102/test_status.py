"""Status cannot infer remote integration health from the local health endpoint."""
import importlib.util
import io
import pathlib
import sys
import tempfile
import time
import unittest
from unittest import mock

HERE = pathlib.Path(__file__).resolve().parent
# jobs.py is unchanged and remains a dependency from the installed recovery package.
RECOVERY = HERE.parent / 'response-outcome-20261102'
if RECOVERY.is_dir():
    sys.path.insert(0, str(RECOVERY))
spec = importlib.util.spec_from_file_location('status_owner_ingress', HERE / 'owner_ingress.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class StatusTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = m.ROOT
        m.ROOT = pathlib.Path(self.tmp.name)
        self.config = {'owner_chat_id': '77', 'owner_user_id': '1'}
        self.payload = {'bot': 'servernetbot', 'owner_chat_id': '77', 'owner_user_id': '1',
                        'update_id': 456, 'timestamp': time.time(), 'text': '/hermes status'}

    def tearDown(self):
        m.ROOT = self.root
        self.tmp.cleanup()

    def run_status(self, *, status=200, error=None):
        response = io.BytesIO(b'{}')
        response.status = status
        with mock.patch.object(m, 'localkey', return_value='synthetic-only'), \
                mock.patch.object(m.urllib.request, 'urlopen', return_value=response, side_effect=error) as api, \
                mock.patch.object(m, 'reply_bale') as send:
            result = m.process(self.payload, self.config, False)
        api.assert_called_once()
        self.assertEqual(api.call_args.args[0].full_url, 'http://127.0.0.1:8642/health')
        send.assert_not_called()
        self.assertEqual(result['status'], 'completed')
        self.assertIn('وضعیت Proxmox و سایر اتصال‌ها در این بررسی تأیید نشده است.', result['reply'])
        self.assertNotIn('اتصال مدیریتی Proxmox فعال است', result['reply'])
        self.assertNotIn('PRIVATE', result['reply'])
        return result

    def test_success_claims_only_local_service_health(self):
        result = self.run_status()
        self.assertIn('به بررسی سلامت پاسخ داد', result['reply'])
        self.assertNotIn('نیاز به بررسی دارد', result['reply'])

    def test_non_success_keeps_local_and_remote_health_unconfirmed(self):
        result = self.run_status(status=503)
        self.assertIn('تأیید نشد و نیاز به بررسی دارد', result['reply'])
        self.assertNotIn('به بررسی سلامت پاسخ داد', result['reply'])

    def test_transport_failure_is_safe_and_not_retried(self):
        result = self.run_status(error=TimeoutError('PRIVATE_DETAILS'))
        self.assertIn('تأیید نشد و نیاز به بررسی دارد', result['reply'])
        with mock.patch.object(m.urllib.request, 'urlopen') as repeat:
            self.assertTrue(m.process(self.payload, self.config, False)['duplicate'])
        repeat.assert_not_called()

    def test_unavailable_local_key_does_not_leak_or_probe_network(self):
        with mock.patch.object(m, 'localkey', side_effect=RuntimeError('PRIVATE_KEY')), \
                mock.patch.object(m.urllib.request, 'urlopen') as api:
            result = m.process(self.payload, self.config, False)
        api.assert_not_called()
        self.assertIn('تأیید نشد و نیاز به بررسی دارد', result['reply'])
        self.assertIn('وضعیت Proxmox و سایر اتصال‌ها در این بررسی تأیید نشده است.', result['reply'])
        self.assertNotIn('PRIVATE', result['reply'])


if __name__ == '__main__':
    unittest.main(verbosity=2)
