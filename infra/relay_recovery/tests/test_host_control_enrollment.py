"""Private host-control enrollment; all TCP and host reads are isolated fixtures."""
from __future__ import annotations

import io
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock

BASE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BASE))
import iran_probes as mod
import protocol


class HostControlEnrollmentTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.config = self.root / 'control.json'
        self.enrollment = dict(schema=mod.HOST_CONTROL_SCHEMA,
                               agent_id='pve-ir', ipv4='8.8.8.8')
        self.write(self.enrollment)
        self.calls = []
        self.tcp_result = True

    def write(self, value=None, *, raw=None):
        self.config.write_bytes(raw if raw is not None else protocol.canonical_json(value))
        self.config.chmod(0o600)

    def context(self):
        return dict(schema=protocol.SCHEMA, agent_id='pve-ir', incident_id='public-fixture',
                    generation=1, revision=1, config_hash='a' * 64, jobs_hash='b' * 64,
                    job=dict(id='probe-1', op='probe', params={},
                             target=dict(host='1.1.1.1', port=443, provider_id='12345')))

    def tcp(self, host, port, *, timeout):
        self.calls.append((host, port, timeout))
        return self.tcp_result

    def read(self, path):
        return {'/proc/uptime': b'1000 0', '/sys/class/net/vmbr0/operstate': b'up',
                '/sys/class/net/vmbr0/flags': b'0x1003'}[path]

    def probes(self, **changes):
        options = dict(tcp=self.tcp, read=self.read, host_control_config=self.config,
                       root_owned=False)
        options.update(changes)
        return mod.Probes(**options)

    def assert_unenrolled(self, instance=None, context=None):
        result = (instance or self.probes()).run('host', context or self.context())
        self.assertFalse(result['ok'])
        self.assertTrue(result['checks']['host_healthy'])
        self.assertIs(result['checks']['direct_control'], False)
        self.assertIs(result['checks']['internet_healthy'], False)
        self.assertEqual(self.calls, [])
        return result

    def test_explicit_valid_enrollment_uses_only_fixed_port_and_is_not_disclosed(self):
        context = self.context()
        result = self.probes().run('host', context)
        self.assertTrue(result['ok'])
        self.assertTrue(result['read_only'])
        self.assertEqual(self.calls, [('8.8.8.8', 443, 3)])
        self.assertEqual(result['target'], context['job']['target'])
        self.assertTrue(all(result[k] == context[k] for k in mod.SCOPE))
        wire = protocol.canonical_json(result)
        self.assertNotIn(b'8.8.8.8', wire)
        self.assertNotIn(str(self.config).encode(), wire)
        self.assertNotIn(mod.HOST_CONTROL_SCHEMA.encode(), wire)

    def test_missing_or_unspecified_file_fails_closed_without_tcp(self):
        self.assert_unenrolled(self.probes(host_control_config=None))
        self.config.unlink()
        self.assert_unenrolled()

    def test_invalid_schema_scope_duplicates_or_noncanonical_bytes_never_select_a_host(self):
        invalid = [dict(self.enrollment, schema='other'),
                   dict(self.enrollment, agent_id='different-agent'),
                   dict(self.enrollment, agent_id=1),
                   dict(self.enrollment, port=443),
                   dict(self.enrollment, url='https://8.8.8.8'),
                   {'schema': mod.HOST_CONTROL_SCHEMA, 'agent_id': 'pve-ir'},
                   dict(self.enrollment, ipv4=None)]
        for value in invalid:
            self.write(value)
            with self.subTest(value=value):
                self.assert_unenrolled()
        for raw in (b'[]', b'not json', protocol.canonical_json(self.enrollment) + b'\n',
                    b'{"schema":"servernet.relay.host-control.v1","agent_id":"pve-ir",'
                    b'"ipv4":"8.8.8.8","ipv4":"9.9.9.9"}', b''):
            self.write(raw=raw)
            with self.subTest(raw=raw):
                self.assert_unenrolled()

    def test_only_canonical_global_unicast_ipv4_is_allowed(self):
        for value in ('localhost', 'https://8.8.8.8', '8.8.8.8:443', '8.8.8.8/path',
                      '127.0.0.1', '10.0.0.1', '100.64.0.1', '192.0.2.1',
                      '224.0.0.1', '255.255.255.255', '2001:4860:4860::8888',
                      '008.8.8.8', ' 8.8.8.8', '8.8.8.8\n', 134744072):
            self.write(dict(self.enrollment, ipv4=value))
            with self.subTest(value=value):
                self.assert_unenrolled()

    def test_control_cannot_be_the_relay_under_test(self):
        context = self.context()
        context['job']['target']['host'] = self.enrollment['ipv4']
        self.assert_unenrolled(context=context)

    def test_tcp_failure_or_nonboolean_success_cannot_prove_control(self):
        for value in (False, 1, None):
            self.tcp_result = value
            with self.subTest(value=value):
                result = self.probes().run('host', self.context())
                self.assertFalse(result['ok'])
                self.assertIs(result['checks']['direct_control'], False)
        self.assertEqual(self.calls, [('8.8.8.8', 443, 3)] * 3)

    def test_private_regular_file_requires_exact_0600_and_no_hardlinks(self):
        for mode in (0o644, 0o660, 0o400, 0o000):
            self.config.chmod(mode)
            with self.subTest(mode=mode):
                self.assert_unenrolled()
        self.config.chmod(0o600)
        link = self.root / 'hardlink.json'
        os.link(self.config, link)
        self.assert_unenrolled()

    def test_symlink_file_and_parent_are_rejected(self):
        link = self.root / 'linked.json'
        link.symlink_to(self.config)
        self.assert_unenrolled(self.probes(host_control_config=link))
        parent = self.root / 'parent'
        parent.symlink_to(self.root, target_is_directory=True)
        self.assert_unenrolled(self.probes(host_control_config=parent / 'control.json'))

    def test_relative_or_dotdot_path_is_rejected(self):
        self.assert_unenrolled(self.probes(host_control_config=Path('control.json')))
        child = self.root / 'child'
        child.mkdir()
        self.assert_unenrolled(self.probes(host_control_config=child / '..' / 'control.json'))

    def test_oversized_file_is_rejected_before_opening_and_does_not_leak_details(self):
        self.write(raw=b'x' * (mod.MAX_HOST_CONTROL + 1))
        with mock.patch.object(mod.os, 'open', side_effect=AssertionError('must not open oversized file')):
            result = self.assert_unenrolled()
        self.assertNotIn(str(self.config).encode(), protocol.canonical_json(result))

    def test_reused_probe_refuses_endpoint_content_permission_and_inode_drift(self):
        for change in ('address', 'permission', 'same_content_new_inode'):
            self.write(self.enrollment)
            instance = self.probes()
            self.assertTrue(instance.run('host', self.context())['ok'])
            self.calls.clear()
            if change == 'address':
                self.write(dict(self.enrollment, ipv4='9.9.9.9'))
            elif change == 'permission':
                self.config.chmod(0o644)
            else:
                replacement = self.root / 'replacement.json'
                replacement.write_bytes(self.config.read_bytes())
                replacement.chmod(0o600)
                os.replace(replacement, self.config)
            with self.subTest(change=change):
                self.assert_unenrolled(instance)

    def test_replacement_between_lstat_and_read_is_rejected_before_tcp(self):
        real_open = os.open
        def replaced(path, flags, *args, **kwargs):
            fd = real_open(path, flags, *args, **kwargs)
            if Path(path) == self.config:
                replacement = self.root / 'replacement.json'
                replacement.write_bytes(protocol.canonical_json(dict(self.enrollment, ipv4='9.9.9.9')))
                replacement.chmod(0o600)
                os.replace(replacement, self.config)
            return fd
        with mock.patch.object(mod.os, 'open', side_effect=replaced):
            self.assert_unenrolled()

    def test_production_ownership_rules_remain_enabled_by_default(self):
        # /tmp ancestry (or a non-root fixture owner) cannot be a production
        # enrollment. Only the explicit isolated-constructor option allows it.
        instance = mod.Probes(tcp=self.tcp, read=self.read, host_control_config=self.config)
        self.assertIs(instance.root_owned, True)
        self.assert_unenrolled(instance)

    def test_nonroot_owner_is_rejected_before_any_parent_or_network_check(self):
        instance = mod.Probes(tcp=self.tcp, read=self.read, host_control_config=self.config)
        real_lstat = Path.lstat
        def foreign_owner(path, *args, **kwargs):
            info = real_lstat(path, *args, **kwargs)
            if path == self.config:
                fields = list(info)
                fields[4] = 65534
                return os.stat_result(fields)
            return info
        with mock.patch.object(Path, 'lstat', foreign_owner):
            with self.assertRaisesRegex(protocol.ProtocolError, '^private_file_owner$'):
                instance._host_control('pve-ir')
            self.assert_unenrolled(instance)

    def test_network_context_cannot_select_enrollment_endpoint_port_or_fixture_mode(self):
        instance = self.probes()
        for name, value in [('host_control_config', str(self.config)), ('ipv4', '9.9.9.9'),
                            ('url', 'https://9.9.9.9'), ('port', 8443), ('root_owned', False)]:
            context = self.context()
            context['job']['params'] = {name: value}
            with self.subTest(name=name), mock.patch.object(instance, '_host_control',
                                                           side_effect=AssertionError('must validate first')):
                with self.assertRaises(protocol.ProtocolError):
                    instance.run('host', context)
        self.assertEqual(self.calls, [])

    def test_cli_forwards_only_explicit_local_path_and_keeps_root_policy(self):
        seen = []
        context = self.context()
        class FixtureProbes:
            def __init__(self, **kwargs):
                seen.append(kwargs)
            def run(self, kind, supplied, agent_id):
                self_outer.assertEqual((kind, supplied, agent_id), ('host', context, 'pve-ir'))
                return dict(schema=mod.iran_adapter.PROBE_SCHEMA, probe_kind='host', read_only=True,
                            ok=False, checks={'direct_control': False}, target=context['job']['target'],
                            **{k: context[k] for k in mod.SCOPE})
        self_outer = self
        argv = ['--kind', 'host', '--agent-id', 'pve-ir', '--host-control-config', str(self.config)]
        stdin = mock.Mock(buffer=io.BytesIO(protocol.canonical_json(context)))
        stdout = mock.Mock(buffer=io.BytesIO())
        with mock.patch.object(mod, 'Probes', FixtureProbes), mock.patch.object(mod.os, 'geteuid', return_value=0), \
                mock.patch.object(mod.sys, 'stdin', stdin), mock.patch.object(mod.sys, 'stdout', stdout):
            self.assertEqual(mod.main(argv), 1)
        self.assertEqual(seen[0]['host_control_config'], str(self.config))
        self.assertIs(seen[0]['root_owned'], True)
        self.assertFalse(protocol.decode_json(stdout.buffer.getvalue())['ok'])

    def test_cli_has_no_fixture_ownership_bypass_option(self):
        with mock.patch.object(mod.sys, 'stderr', io.StringIO()):
            with self.assertRaises(SystemExit) as error:
                mod.main(['--kind', 'host', '--root-owned', 'false'])
        self.assertEqual(error.exception.code, 2)


if __name__ == '__main__':
    unittest.main()
