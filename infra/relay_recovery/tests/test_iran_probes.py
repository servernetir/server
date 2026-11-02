"""Actual Iran probe argv and acceptance contracts, with no live subprocesses."""
from __future__ import annotations

import copy
from pathlib import Path
import sys
import tempfile
import unittest

BASE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BASE))
import iran_probes as mod
import protocol
import windows_probe_client as windows


class ProbeTests(unittest.TestCase):
    def setUp(self):
        self.calls = []
        self.raw = {}
        self.ct_enabled = True
        self.socks_ip = b'91.107.179.25\n'
        self.ssh_args = b'/usr/bin/ssh -N -D 127.0.0.1:10819 -o ExitOnForwardFailure=yes root@91.107.179.25\n'
        self.qga = False
        self.guest = dict(exitcode=0, exited=1, **{'out-data': '{"source_ip":"10.10.10.50","egress_ip":"91.107.179.25"}'})
        self.files = {'/proc/uptime': b'87654.32 123456.0\n',
                      '/sys/class/net/vmbr0/operstate': b'up\n', '/sys/class/net/vmbr0/flags': b'0x1003\n'}
        self.tcp_ok = True

    def context(self):
        return dict(schema=protocol.SCHEMA, agent_id='pve-ir', incident_id='incident-1',
                    generation=2, revision=5, config_hash='a' * 64, jobs_hash='b' * 64,
                    job=dict(id='probe-1', op='probe', target=dict(host='91.107.179.25', port=443, provider_id='173913007')))

    def runner(self, argv, data, timeout):
        self.calls.append((list(argv), data, timeout))
        self.assertEqual(data, b'')
        override = self.raw.get(tuple(argv))
        if override is not None:
            return override
        if argv[:4] == [mod.PCT, 'exec', '113', '--']:
            args = argv[4:]
            if not self.ct_enabled:
                return 1, b'not running'
            if args[0] == '/usr/bin/systemctl':
                return 0, b'active\nactive\n'
            if args[:4] == ['/usr/sbin/ip', '-j', 'link', 'show']:
                return 0, b'[{"ifname":"tun119","flags":["POINTOPOINT","UP","LOWER_UP"],"operstate":"UNKNOWN"}]'
            if args[:4] == ['/usr/sbin/ip', '-j', 'route', 'show']:
                return 0, b'[{"dst":"default","dev":"tun119","scope":"link"},{"type":"blackhole","dst":"default","metric":32767}]'
            if args[:4] == ['/usr/sbin/ip', '-j', 'route', 'get']:
                return 0, b'[{"dst":"1.1.1.1","dev":"tun119","table":119}]'
            if args[0] == '/usr/bin/ss':
                return 0, b'LISTEN 0 128 127.0.0.1:10819 0.0.0.0:* users:(("ssh",pid=8312,fd=4))\n'
            if args[0] == '/usr/bin/ps':
                self.assertEqual(args, ['/usr/bin/ps', '-p', '8312', '-o', 'args='])
                return 0, self.ssh_args
            if args[0] == '/usr/bin/curl':
                return 0, self.socks_ip
        if argv == [mod.QM, 'guest', 'cmd', '109', 'ping']:
            return (0, b'') if self.qga else (255, b'QEMU guest agent is not running')
        if argv[:4] == [mod.QM, 'guest', 'exec', '109']:
            return 0, protocol.canonical_json(self.guest)
        raise AssertionError('Unexpected fixed argv')

    def tcp(self, host, port, timeout):
        self.assertEqual((host, port, timeout), ('65.109.176.14', 443, 3))
        return self.tcp_ok

    def read(self, path):
        if path not in self.files:
            raise OSError('missing fixture')
        return self.files[path]

    def probes(self):
        return mod.Probes(runner=self.runner, tcp=self.tcp, read=self.read)

    def test_host_actual_uptime_interface_and_fixed_independent_control(self):
        result = self.probes().run('host', self.context())
        self.assertTrue(result['ok'])
        self.assertTrue(result['read_only'])
        self.assertEqual(result['probe_kind'], 'host')
        self.assertTrue(result['checks']['direct_control'])
        self.assertEqual(result['target'], self.context()['job']['target'])

    def test_host_interface_uptime_and_independent_failure_are_not_green(self):
        for path, wrong in [('/proc/uptime', b'nan 0'), ('/proc/uptime', b'0 0'),
                            ('/sys/class/net/vmbr0/operstate', b'down'),
                            ('/sys/class/net/vmbr0/flags', b'0x1002')]:
            old = self.files[path]
            self.files[path] = wrong
            with self.subTest(path=path, wrong=wrong):
                self.assertFalse(self.probes().run('host', self.context())['ok'])
            self.files[path] = old
        self.tcp_ok = False
        self.assertFalse(self.probes().run('host', self.context())['ok'])

    def test_ct_requires_actual_socks_egress_and_route_services(self):
        result = self.probes().run('ct113', self.context())
        self.assertTrue(result['ok'])
        checks = result['checks']
        self.assertEqual(checks['execution_origin'], 'ct113')
        self.assertEqual(checks['egress_ip'], '91.107.179.25')
        self.assertTrue(checks['source_route_tun119'])
        curl = [call[0] for call in self.calls if '/usr/bin/curl' in call[0]]
        self.assertEqual(curl, [[mod.PCT, 'exec', '113', '--', '/usr/bin/curl',
                                '-q', '--socks5-hostname', '127.0.0.1:10819', '-fsS', '--max-time', '5',
                                'https://api.ipify.org']])
        self.assertTrue(all(timeout <= 6 for _, _, timeout in self.calls))

    def test_source_route_is_only_diagnostic_and_never_guest_proof(self):
        key = (mod.PCT, 'exec', '113', '--', '/usr/sbin/ip', '-j', 'route', 'get', '1.1.1.1', 'from', mod.SOURCE_IP)
        self.raw[key] = (1, b'no source route')
        result = self.probes().run('ct113', self.context())
        self.assertTrue(result['ok'])
        self.assertFalse(result['checks']['source_route_tun119'])
        self.assertFalse(self.probes().run('vm109', self.context())['ok'])
        self.assertFalse(any(call[0][:4] == [mod.QM, 'guest', 'exec', '109'] for call in self.calls))

    def test_egress_exact_target_required_and_no_request_target_enters_argv(self):
        self.socks_ip = b'1.1.1.1'
        self.assertFalse(self.probes().run('ct113', self.context())['ok'])
        self.assertTrue(all('91.107.179.25' not in item for argv, _, _ in self.calls for item in argv))
        self.calls.clear()
        ctx = self.context(); ctx['job']['target']['host'] = '1.1.1.1'
        self.ssh_args = b'/usr/bin/ssh -N -D127.0.0.1:10819 root@1.1.1.1'
        self.assertTrue(self.probes().run('ct113', ctx)['ok'])

    def test_any_ct_prerequisite_failure_blocks(self):
        base = (mod.PCT, 'exec', '113', '--')
        variants = [
            (('/usr/bin/systemctl', 'is-active', *mod.CT_UNITS), b'active\ninactive\n'),
            (('/usr/sbin/ip', '-j', 'link', 'show', 'dev', 'tun119'), b'[{"ifname":"tun119","flags":[]}]'),
            (('/usr/sbin/ip', '-j', 'route', 'show', 'table', '119'), b'[{"dst":"default","dev":"tun119"}]'),
            (('/usr/sbin/ip', '-j', 'route', 'show', 'table', '119'), b'[{"dst":"default","type":"blackhole"}]'),
            (('/usr/bin/ss', '-H', '-ltnp', '( sport = :10819 )'), b'LISTEN 0 128 127.0.0.1:10819 0.0.0.0:* users:(("xray",pid=8312,fd=4))'),
            (('/usr/bin/ss', '-H', '-ltnp', '( sport = :10819 )'), b'LISTEN 0 128 0.0.0.0:10819 0.0.0.0:* users:(("ssh",pid=8312,fd=4))'),
        ]
        for argv, data in variants:
            self.raw[base + argv] = (0, data)
            with self.subTest(argv=argv, data=data):
                self.assertFalse(self.probes().run('ct113', self.context())['ok'])
            self.raw.clear()

    def test_listener_process_must_match_exact_ssh_dynamic_port_and_target(self):
        for args in (b'/usr/bin/ssh -N -D127.0.0.1:1080 root@91.107.179.25',
                     b'/usr/bin/ssh -N -D127.0.0.1:10819 root@155.103.69.128',
                     b'/usr/bin/xray root@91.107.179.25 -D127.0.0.1:10819'):
            self.ssh_args = args
            with self.subTest(args=args):
                self.assertFalse(self.probes().run('ct113', self.context())['ok'])

    def test_ct_unavailable_or_malformed_data_is_false(self):
        self.ct_enabled = False
        self.assertFalse(self.probes().run('ct113', self.context())['ok'])
        self.ct_enabled = True
        self.raw[(mod.PCT, 'exec', '113', '--', '/usr/sbin/ip', '-j', 'route', 'show', 'table', '119')] = (0, b'not json')
        self.assertFalse(self.probes().run('ct113', self.context())['ok'])

    def test_vm_without_qga_is_explicit_false_and_never_mutated(self):
        result = self.probes().run('vm109', self.context())
        self.assertFalse(result['ok'])
        self.assertFalse(result['checks']['qga_available'])
        self.assertEqual(self.calls, [([mod.QM, 'guest', 'cmd', '109', 'ping'], b'', 1.25)])

    def test_vm_real_guest_execution_source_and_egress_exact(self):
        self.qga = True
        result = self.probes().run('vm109', self.context())
        self.assertTrue(result['ok'])
        self.assertEqual(result['checks']['source_ip'], mod.SOURCE_IP)
        self.assertEqual(result['checks']['execution_origin'], 'guest')
        argv = self.calls[-1][0]
        self.assertEqual(argv[-1], mod.GUEST_SCRIPT)
        self.assertIn("& 'C:\\Windows\\System32\\curl.exe' -q --noproxy", argv[-1])
        self.assertNotIn('& curl.exe', argv[-1])
        self.assertIn("--interface '10.10.10.50'", argv[-1])
        self.assertNotIn('91.107.179.25', argv[-1])

    def test_vm_cannot_pass_on_host_route_wrong_source_or_stale_other_egress(self):
        self.qga = True
        for out in ('{"source_ip":"10.10.10.20","egress_ip":"91.107.179.25"}',
                    '{"source_ip":"10.10.10.50","egress_ip":"155.103.69.128"}',
                    '{"source_ip":"10.10.10.50","egress_ip":"127.0.0.1"}',
                    'route dev tun119 src 10.10.10.50'):
            self.guest['out-data'] = out
            with self.subTest(out=out):
                self.assertFalse(self.probes().run('vm109', self.context())['ok'])
        self.guest['out-data'] = '{"source_ip":"10.10.10.50","egress_ip":"91.107.179.25"}'
        self.guest['exitcode'] = 1
        self.assertFalse(self.probes().run('vm109', self.context())['ok'])

    def test_subprocess_failure_output_limit_and_timeout_fail_closed(self):
        def runner(argv, data, timeout):
            raise protocol.ProtocolError('adapter_timeout')
        probes = mod.Probes(runner=runner)
        self.assertFalse(probes.run('ct113', self.context())['ok'])
        self.assertFalse(probes.run('vm109', self.context())['ok'])
        probes = mod.Probes(runner=lambda a, d, t: (0, b'x' * 8193))
        self.assertFalse(probes.run('ct113', self.context())['ok'])

    def test_context_injection_rejected_before_any_probe(self):
        for key, value in [('host', '91.107.179.25; reboot'), ('port', 8443)]:
            ctx = self.context(); ctx['job']['target'][key] = value
            with self.assertRaises(protocol.ProtocolError):
                self.probes().run('ct113', ctx)
        ctx = self.context(); ctx['job']['params'] = {'argv': ['reboot']}
        with self.assertRaises(protocol.ProtocolError):
            self.probes().run('ct113', ctx)
        self.assertEqual(self.calls, [])

    def test_results_are_scope_bound_and_do_not_disclose_raw_local_details(self):
        ctx = self.context()
        result = self.probes().run('ct113', ctx)
        self.assertTrue(all(result[k] == ctx[k] for k in mod.SCOPE))
        output = protocol.canonical_json(result)
        self.assertNotIn(b'root@', output)
        self.assertNotIn(b'8312', output)
        self.assertNotIn(b'1.1.1.1', output)

    def _windows_fixture(self, result_patch=None, *, fault=None):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        config = Path(temporary.name) / 'windows-probe.json'
        config.write_bytes(b'{"schema":"local-test-fixture"}')
        config.chmod(0o600)
        calls = []
        def client(context, *, config_path):
            calls.append((copy.deepcopy(context), config_path))
            if fault:
                raise fault
            result = dict(schema='servernet.relay.iran-probe.v1', probe_kind='vm109', read_only=True,
                          ok=True, checks=dict(execution_origin='guest', source_ip=mod.SOURCE_IP,
                              egress_ip=context['job']['target']['host'], tunnel_ok=True, probe_ok=True),
                          target=context['job']['target'], **{k: context[k] for k in mod.SCOPE})
            if result_patch:
                result_patch(result)
            return result
        instance = mod.Probes(runner=self.runner, tcp=self.tcp, read=self.read,
                              windows_config=config, windows_explicit=True,
                              windows_probe=client, root_owned=False)
        return instance, config, calls

    def test_private_http_guest_probe_is_preferred_and_exact_scope_bound(self):
        probes, config, calls = self._windows_fixture()
        self.qga = True
        ctx = self.context()
        result = probes.run('vm109', ctx)
        self.assertTrue(result['ok'])
        self.assertTrue(result['checks']['windows_channel_verified'])
        self.assertEqual(result['checks']['source_ip'], mod.SOURCE_IP)
        self.assertEqual(calls, [(ctx, str(config))])
        self.assertEqual(self.calls, [])

    def test_configured_http_error_never_falls_back_to_qga(self):
        probes, _, _ = self._windows_fixture(fault=protocol.ProtocolError('invalid_hmac_or_replay'))
        self.qga = True
        result = probes.run('vm109', self.context())
        self.assertFalse(result['ok'])
        self.assertFalse(result['checks']['windows_channel_verified'])
        self.assertEqual(self.calls, [])
        self.assertNotIn(b'invalid_hmac_or_replay', protocol.canonical_json(result))

    def test_http_wrong_scope_target_or_readonly_receipt_never_passes(self):
        for key, value in [('generation', 999), ('config_hash', 'f' * 64), ('read_only', False),
                           ('target', dict(host='1.1.1.1', port=443, provider_id='9999')),
                           ('probe_kind', 'ct113')]:
            probes, _, _ = self._windows_fixture(lambda result: result.update({key: value}))
            with self.subTest(key=key):
                self.assertFalse(probes.run('vm109', self.context())['ok'])
        self.assertEqual(self.calls, [])

    def test_http_requires_actual_guest_source_egress_and_positive_path_receipt(self):
        for key, value in [('execution_origin', 'ct113'), ('source_ip', '10.10.10.20'),
                           ('egress_ip', '155.103.69.128'), ('tunnel_ok', False), ('probe_ok', False)]:
            probes, _, _ = self._windows_fixture(lambda result: result['checks'].update({key: value}))
            with self.subTest(key=key):
                self.assertFalse(probes.run('vm109', self.context())['ok'])
        probes, _, _ = self._windows_fixture(lambda result: result.update(ok=False))
        self.assertFalse(probes.run('vm109', self.context())['ok'])
        self.assertEqual(self.calls, [])

    def test_http_missing_or_unbounded_checks_fail_closed(self):
        for value in ({}, {'secret': 'x' * 2049}, 'not-a-dict'):
            probes, _, _ = self._windows_fixture(lambda result: result.update(checks=value))
            with self.subTest(value=str(value)[:30]):
                self.assertFalse(probes.run('vm109', self.context())['ok'])
        self.assertEqual(self.calls, [])

    def test_windows_config_must_be_private_regular_file_and_no_symlink(self):
        probes, config, calls = self._windows_fixture()
        config.chmod(0o644)
        self.assertFalse(probes.run('vm109', self.context())['ok'])
        self.assertFalse(calls)
        config.chmod(0o600)
        link = config.with_name('linked-config.json')
        link.symlink_to(config)
        probes.windows_config = link
        self.assertFalse(probes.run('vm109', self.context())['ok'])
        self.assertFalse(calls)
        self.assertEqual(self.calls, [])

    def test_only_absent_default_windows_config_allows_readonly_qga_fallback(self):
        probes, config, calls = self._windows_fixture()
        config.unlink()
        self.qga = True
        self.assertFalse(probes.run('vm109', self.context())['ok'])
        self.assertEqual(self.calls, [])
        probes.windows_explicit = False
        self.assertTrue(probes.run('vm109', self.context())['ok'])
        self.assertFalse(calls)
        self.assertEqual(self.calls[0][0], [mod.QM, 'guest', 'cmd', '109', 'ping'])

    def test_http_guest_failure_does_not_change_actual_host_or_ct_probes(self):
        probes, _, _ = self._windows_fixture(fault=OSError('private transport failure'))
        self.assertTrue(probes.run('host', self.context())['ok'])
        self.assertTrue(probes.run('ct113', self.context())['ok'])
        self.assertFalse(probes.run('vm109', self.context())['ok'])

    def test_network_cannot_select_windows_config_client_or_endpoint(self):
        probes, _, calls = self._windows_fixture()
        for key in ('windows_config', 'client_path', 'endpoint'):
            ctx = self.context(); ctx['job']['params'] = {key: '/tmp/untrusted'}
            with self.subTest(key=key), self.assertRaises(protocol.ProtocolError):
                probes.run('vm109', ctx)
        self.assertFalse(calls)

    def _actual_windows_client(self, *, tamper=False):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        root = Path(temporary.name)
        key = b'w' * 32
        key_file = root / 'guest.key'; key_file.write_bytes(key); key_file.chmod(0o600)
        config_path = root / 'windows-probe.json'
        config_path.write_bytes(protocol.canonical_json(dict(schema=windows.SCHEMA, agent_id='pve-ir',
            key_file=str(key_file), state_dir=str(root / 'client-state'), host=windows.HOST, port=windows.PORT)))
        config_path.chmod(0o600)
        now = 1_800_000_000
        request_replay = protocol.ReplayStore(root / 'request-nonces.sqlite3')
        def transport(host, port, path, body, headers, timeout):
            self.assertEqual((host, port, path, timeout), (windows.HOST, windows.PORT, windows.PATH, windows.DEADLINE))
            windows.verify(key, 'POST', body, headers, now, request_replay)
            request = protocol.decode_json(body)
            response = dict(schema=windows.WIRE_SCHEMA, op='probe', request_nonce=headers['X-VM-Nonce'],
                scope=request['scope'], target=request['target'], observed_at=now, read_only=True,
                execution_origin='guest', source_ip=mod.SOURCE_IP, egress_ip=request['target']['host'],
                tunnel_ok=True, probe_ok=True, ok=True)
            raw = protocol.canonical_json(response)
            signed = windows.sign(key, 'RESPONSE', raw, now, 'e' * 32)
            if tamper:
                signed['X-VM-Signature'] = 'f' * 64
            return 200, signed, raw
        def public_api(context, config_path=windows.DEFAULT_CONFIG):
            cfg = windows.load_config(config_path, root_owned=False)
            return windows.WindowsProbeClient(cfg, transport=transport, clock=lambda: now,
                monotonic=lambda: 0, root_owned=False).probe(context)
        return mod.Probes(runner=self.runner, tcp=self.tcp, read=self.read, windows_config=config_path,
                          windows_explicit=True, windows_probe=public_api, root_owned=False)

    def test_actual_hmac_windows_client_interoperates_with_production_guest_probe(self):
        result = self._actual_windows_client().run('vm109', self.context())
        self.assertTrue(result['ok'])
        self.assertTrue(result['checks']['windows_channel_verified'])
        self.assertEqual(result['checks']['source_ip'], mod.SOURCE_IP)
        self.assertEqual(result['checks']['egress_ip'], self.context()['job']['target']['host'])
        self.assertEqual(self.calls, [])

    def test_actual_hmac_client_bad_signature_never_uses_qga_or_fails_ct_path(self):
        self.qga = True
        probes = self._actual_windows_client(tamper=True)
        self.assertFalse(probes.run('vm109', self.context())['ok'])
        self.assertEqual(self.calls, [])
        self.assertTrue(probes.run('ct113', self.context())['ok'])


if __name__ == '__main__':
    unittest.main()
