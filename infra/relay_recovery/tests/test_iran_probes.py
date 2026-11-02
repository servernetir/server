"""Actual Iran probe argv and acceptance contracts, with no live subprocesses."""
from __future__ import annotations

import copy
from pathlib import Path
import sys
import unittest

BASE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BASE))
import iran_probes as mod
import protocol


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
                                '--socks5-hostname', '127.0.0.1:10819', '-fsS', '--max-time', '5',
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


if __name__ == '__main__':
    unittest.main()
