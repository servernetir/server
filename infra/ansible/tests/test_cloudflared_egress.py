"""Offline kernel-command fixtures: never contact a host or mutate networking."""
import contextlib
import io
import json
from pathlib import Path
import runpy
import subprocess
import sys
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / 'roles/proxmox_host/files/servernet-cloudflared-egress.py'
NETWORKS = ['198.41.192.0/24', '198.41.200.0/24']


class EgressPolicyTest(unittest.TestCase):
    def execute(self, remove=False, conflict=False, cidr_json=False):
        commands = []

        def kernel(args, **kwargs):
            commands.append(args)
            ct = args[:4] == ['pct', 'exec', '113', '--']
            cmd = args[4:] if ct else args
            table = '119' if ct else '7844'
            output = ''
            if cmd == ['ip', '-j', 'rule', 'show']:
                rules = [{'priority': 1000, 'src': '10.10.10.65', 'table': '119'}]
                for network in NETWORKS:
                    rules.append({'priority': 900, 'src': '10.10.10.1',
                                  'dst': network if cidr_json else network.split('/')[0],
                                  'dstlen': 24, 'ipproto': 'tcp', 'dport': 7844,
                                  'table': table})
                if conflict and ct:
                    rules = [rules[0], {'priority': 900, 'src': '10.10.10.88', 'table': '141'}]
                output = json.dumps(rules)
            elif cmd[:4] == ['ip', 'route', 'show', 'table']:
                output = 'default dev tun119 scope link\n' if ct else (
                    'default via 10.10.10.20 dev vmbr1 metric 10\nblackhole default metric 1000\n')
            elif cmd[:3] == ['ip', 'route', 'get']:
                output = 'dev tun119 table 119\n' if ct else 'via 10.10.10.20 dev vmbr1\n'
            return subprocess.CompletedProcess(args, 0, output, '')

        with patch.object(sys, 'argv', [str(SCRIPT)] + (['--remove'] if remove else [])), \
             patch('subprocess.run', side_effect=kernel), contextlib.redirect_stdout(io.StringIO()):
            if conflict:
                with self.assertRaisesRegex(AssertionError, 'priority conflict'):
                    runpy.run_path(str(SCRIPT), run_name='__main__')
            else:
                runpy.run_path(str(SCRIPT), run_name='__main__')
        return [args[4:] if args[:4] == ['pct', 'exec', '113', '--'] else args for args in commands]

    def test_reconciliation_keeps_existing_rules_with_split_prefix_json(self):
        commands = self.execute()
        self.assertFalse(any(cmd[:3] in (['ip', 'rule', 'add'], ['ip', 'rule', 'del']) for cmd in commands))
        self.assertFalse(any('-A' in cmd or '-D' in cmd for cmd in commands if cmd[0] == 'iptables'))

    def test_reconciliation_accepts_cidr_destination_json(self):
        commands = self.execute(cidr_json=True)
        self.assertFalse(any(cmd[:3] == ['ip', 'rule', 'add'] for cmd in commands))

    def test_rollback_removes_only_the_owned_policies_and_nat(self):
        commands = self.execute(remove=True)
        policies = [cmd for cmd in commands if cmd[:3] == ['ip', 'rule', 'del']]
        self.assertEqual(len(policies), 4)
        for cmd in policies:
            self.assertIn('10.10.10.1/32', cmd)
            self.assertIn('7844', cmd)
            self.assertIn(cmd[cmd.index('to') + 1], NETWORKS)
        nat = [cmd for cmd in commands if cmd[0] == 'iptables' and '-D' in cmd]
        self.assertEqual(len(nat), 2)
        self.assertTrue(all('servernet-cloudflared-egress' in cmd for cmd in nat))
        self.assertFalse(any('flush' in cmd or '-F' in cmd for cmd in commands))

    def test_foreign_priority_conflict_cannot_delete_or_add_policy(self):
        commands = self.execute(conflict=True)
        self.assertFalse(any(cmd[:3] in (['ip', 'rule', 'add'], ['ip', 'rule', 'del']) for cmd in commands))
        self.assertFalse(any('-A' in cmd or '-D' in cmd for cmd in commands if cmd[0] == 'iptables'))


if __name__ == '__main__':
    unittest.main()
