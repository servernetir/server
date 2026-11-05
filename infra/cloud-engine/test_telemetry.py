import hashlib
import json
import os
import socket
import struct
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

import console_gateway as gateway
import traffic_collector as collector


def masked(data, opcode=2, final=True):
    mask = b'1234'
    if len(data) >= 126:
        header = bytes([(0x80 if final else 0) | opcode, 0xfe]) + struct.pack('!H', len(data))
    else:
        header = bytes([(0x80 if final else 0) | opcode, 0x80 | len(data)])
    return header + mask + bytes(value ^ mask[index % 4] for index, value in enumerate(data))


class FakeSocket:
    def __init__(self, data):
        self.data, self.sent = data, b''
    def recv(self, count):
        data, self.data = self.data[:count], self.data[count:]
        return data
    def sendall(self, data):
        self.sent += data


class FakeWebSocket:
    def __init__(self, data):
        self.data, self.sent = list(data), []
    def recv(self):
        return self.data.pop(0)
    def send_binary(self, data):
        self.sent.append(data)


class GatewayTests(unittest.TestCase):
    def test_masked_frames_and_fragment_order(self):
        client = FakeSocket(masked(b'ab', final=False) + masked(b'cd', opcode=0))
        stream = gateway.BrowserStream(client)
        self.assertEqual(b'abcd', stream.exact(4))
        for bad in (b'\x82\x01x', masked(b'x', opcode=0), b'\x82\xff' + struct.pack('!Q', 2**30)):
            with self.assertRaises((ValueError, EOFError)):
                gateway.BrowserStream(FakeSocket(bad)).chunk()

    def test_ping_does_not_enter_vnc_stream(self):
        client = FakeSocket(masked(b'ping', opcode=9) + masked(b'RFB'))
        self.assertEqual(b'RFB', gateway.BrowserStream(client).chunk())
        self.assertEqual(b'\x8a\x04ping', client.sent)

    def test_upstream_auth_terminates_ticket_before_browser_none_handshake(self):
        challenge = bytes(range(16))
        upstream = FakeWebSocket([b'RFB 003.008\n', b'\x01\x02', challenge, b'\0\0\0\0', b'server-init'])
        stream = gateway.authenticate_rfb(upstream, 'secret')
        # Fixture key is the VNC bit-reversal of ASCII 'secret', not UTF-8/PVE output.
        from Crypto.Cipher import DES
        response = DES.new(bytes.fromhex('cea6c64ea62e0000'), DES.MODE_ECB).encrypt(challenge)
        self.assertEqual([b'RFB 003.008\n', b'\x02', response], upstream.sent)
        browser = FakeSocket(masked(b'RFB 003.008\n') + masked(b'\x01') + masked(b'\x01'))
        gateway.browser_rfb(browser, upstream, stream)
        self.assertNotIn(b'secret', browser.sent)
        self.assertNotIn(response, browser.sent)
        self.assertIn(b'\x01\x01', browser.sent)
        self.assertEqual(b'\x01', upstream.sent[-1])
        self.assertEqual(b'server-init', stream.exact(11))

    def test_upstream_auth_failure_and_expired_session_fail_closed(self):
        upstream = FakeWebSocket([b'RFB 003.008\n', b'\x01\x02', bytes(16), b'\0\0\0\1'])
        with self.assertRaisesRegex(ValueError, 'auth_failed'):
            gateway.authenticate_rfb(upstream, 'secret')
        with self.assertRaises(TimeoutError):
            gateway.BrowserStream(FakeSocket(masked(b'x')), time.time()-1).chunk()
        with self.assertRaises(ValueError):
            gateway.expiry('2020-01-01T00:00:00Z')

    def test_target_allowlist_and_tls_are_required(self):
        config = object.__new__(gateway.Config)
        config.hosts = {'pve.example.test:8006'}
        for target in ({'endpoint': 'http://pve.example.test:8006'}, {'endpoint': 'https://other.test:8006'},
                       {'endpoint': 'https://pve.example.test:443'}, {'endpoint': 'https://user@pve.example.test:8006'}):
            with self.assertRaises(ValueError):
                config.upstream(target)

    def test_real_local_socket_frame_transport(self):
        left, right = socket.socketpair()
        try:
            left.sendall(masked(b'generated-rfb-frame'))
            self.assertEqual(b'generated-rfb-frame', gateway.BrowserStream(right).chunk())
            gateway.send_frame(right, b'return')
            self.assertEqual(b'\x82\x06return', left.recv(100))
        finally:
            left.close(); right.close()


class CollectorTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.stats = self.root/'tap100i0'/'statistics'
        self.stats.mkdir(parents=True)
        (self.stats.parent/'ifindex').write_text('7')
        self.assignment = {'vm_uuid': 'fixture-vm', 'vmid': 100}
        self.counters(100, 200)

    def tearDown(self):
        self.tmp.cleanup()

    def counters(self, host_rx, host_tx):
        (self.stats/'rx_bytes').write_text(str(host_rx))
        (self.stats/'tx_bytes').write_text(str(host_tx))

    def test_guest_direction_is_reverse_of_host_tap_and_restart_state_persists(self):
        first = collector.observation(self.assignment, None, 'boot-a', self.root, 100)
        self.assertEqual((200, 100), (first['in_bytes'], first['out_bytes']))
        self.counters(1124, 2248)
        second = collector.observation(self.assignment, first, 'boot-a', self.root, 110)
        self.assertEqual(first['epoch'], second['epoch']); self.assertEqual(2, second['sequence'])
        self.assertEqual(2048, second['in_bytes']-first['in_bytes'])
        self.assertEqual(1024, second['out_bytes']-first['out_bytes'])
        restarted = collector.observation(self.assignment, second, 'boot-b', self.root, 120)
        self.assertNotEqual(first['epoch'], restarted['epoch']); self.assertEqual(1, restarted['sequence'])
        self.counters(1, 2)
        reset = collector.observation(self.assignment, second, 'boot-a', self.root, 130)
        self.assertNotEqual(second['epoch'], reset['epoch'])

    def test_durable_outbox_retries_identical_sample_after_lost_response(self):
        state, receipts = {}, []
        config = {'assignments': [self.assignment], 'endpoint': 'https://example.test/ingest'}
        def lost(endpoint, secret, payload, ca):
            receipts.append(payload.copy())
            raise RuntimeError('simulated_lost_response')
        path = self.root/'state.json'
        with self.assertRaises(RuntimeError):
            collector.collect_once(config, state, b'fixture', path, self.root, 'boot-a', lost)
        self.assertIsNotNone(json.loads(path.read_text())['fixture-vm']['pending'])
        self.counters(9000, 10000)
        def duplicate(endpoint, secret, payload, ca):
            receipts.append(payload.copy()); return {'status': 'duplicate'}
        restored = json.loads(path.read_text())
        collector.collect_once(config, restored, b'fixture', path, self.root, 'boot-a', duplicate)
        self.assertEqual(receipts[0], receipts[1]); self.assertIsNone(restored['fixture-vm']['pending'])
        self.assertEqual(0, path.stat().st_mode & 0o077)

    def test_missing_interface_returns_as_new_epoch(self):
        first = collector.observation(self.assignment, None, 'boot-a', self.root, 100)
        first['missing'] = True
        second = collector.observation(self.assignment, first, 'boot-a', self.root, 110)
        self.assertNotEqual(first['epoch'], second['epoch']); self.assertEqual(1, second['sequence'])

    def test_recreated_tap_with_higher_counters_starts_new_epoch(self):
        first = collector.observation(self.assignment, None, 'boot-a', self.root, 100)
        self.counters(9000, 10000)
        (self.stats.parent/'ifindex').write_text('8')
        second = collector.observation(self.assignment, first, 'boot-a', self.root, 110)
        self.assertNotEqual(first['epoch'], second['epoch'])
        self.assertEqual(1, second['sequence'])

    def test_non_https_ingestion_never_sends_secret(self):
        with self.assertRaises(ValueError):
            collector.transmit('http://example.test/', b'secret', {})


if __name__ == '__main__':
    unittest.main()
