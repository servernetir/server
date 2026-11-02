import importlib.util
import json
from pathlib import Path
import struct
import sys
import time
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('private_fpm_activation', Path(__file__).with_name('fpm-activation.py'))
client = importlib.util.module_from_spec(spec)
spec.loader.exec_module(client)


class FragmentedSocket:
    def __init__(self, payload):
        self.payload = payload

    def settimeout(self, timeout):
        if timeout <= 0:
            raise AssertionError('Nonpositive timeout')

    def recv(self, length):
        chunk, self.payload = self.payload[:min(length, 3)], self.payload[min(length, 3):]
        return chunk


class PrivateFastCgiTests(unittest.TestCase):
    def response(self, result=None, stderr=b'', app_status=0):
        result = result or {'release': client.RELEASE, 'action': 'observe', 'status': 'ok'}
        body = b'Content-Type: application/json; charset=utf-8\r\n\r\n' + json.dumps(result).encode()
        return (client.record(6, body[:31]) + client.record(6, body[31:]) + client.record(6)
                + client.record(7, stderr) + client.record(3, struct.pack('!IB3s', app_status, 0, b'\0' * 3)))

    def receive(self, payload):
        return client.receive(FragmentedSocket(payload), time.monotonic() + 20)

    def test_fastcgi_lengths_and_record_padding_match_the_wire_format(self):
        self.assertEqual(b'\x7f', client.encode_length(127))
        self.assertEqual(b'\x80\0\0\x80', client.encode_length(128))
        encoded = client.record(4, b'abc')
        self.assertEqual((1, 4, 1, 3, 5, 0), struct.unpack('!BBHHBB', encoded[:8]))
        self.assertEqual(16, len(encoded))
        self.assertEqual(b'\x01\x02kxy', client.encode_params({'k': 'xy'}))

    def test_fragmented_stdout_and_padding_are_reassembled_before_json_decoding(self):
        result = self.receive(self.response())
        self.assertEqual('ok', result['status'])
        self.assertEqual(client.RELEASE, result['release'])

    def test_stderr_and_nonzero_application_status_are_not_treated_as_success(self):
        with self.assertRaisesRegex(RuntimeError, 'stderr_present'):
            self.receive(self.response(stderr=b'synthetic private failure'))
        with self.assertRaisesRegex(RuntimeError, 'application_failed'):
            self.receive(self.response(app_status=1))

    def test_truncated_and_wrong_request_responses_fail_closed(self):
        with self.assertRaisesRegex(RuntimeError, 'incomplete_response'):
            self.receive(self.response()[:-2])
        bad = bytearray(self.response()); bad[3] = 2
        with self.assertRaisesRegex(RuntimeError, 'invalid_record'):
            self.receive(bytes(bad))

    def test_unbounded_or_wrong_release_output_is_rejected(self):
        with patch.object(client, 'MAX_OUTPUT', 8):
            with self.assertRaisesRegex(RuntimeError, 'output_too_large'):
                self.receive(self.response())
        with self.assertRaisesRegex(RuntimeError, 'invalid_response_body'):
            self.receive(self.response({'release': 'unreviewed-release', 'status': 'ok'}))

    def test_reading_past_the_total_deadline_is_rejected_before_socket_access(self):
        with self.assertRaisesRegex(RuntimeError, 'timeout'):
            client.read_exact(FragmentedSocket(b'a'), 1, time.monotonic() - 1)


if __name__ == '__main__':
    unittest.main()
