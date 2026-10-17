"""Offline tests for PEETA routing scope, return symmetry, and rollback."""
import contextlib
import io
import json
from pathlib import Path
import runpy
import subprocess
import sys
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / 'roles/proxmox_host/files/servernet-vm116-cloudflare-egress.py'


class PeetaPolicyTest(unittest.TestCase):
    def load(self, ct=False):
        calls=[]
        def kernel(args, **kwargs):
            calls.append(args)
            output=''
            if args==['hostname']:output='ir.servernet.cloud\n'
            elif args[:3]==['ip','-j','-4']:output='[]'
            elif args[:3]==['pct','exec','113']:output='{}'
            elif args[:2]==['lxc-info','-n']:output='1234\n'
            return subprocess.CompletedProcess(args,0,output,'')
        with patch.object(sys,'argv',[str(SCRIPT)]+(['--ct'] if ct else [])+['snapshot']), \
             patch('subprocess.run',side_effect=kernel), \
             patch.object(Path,'read_bytes',return_value=b'host-helper-fixture'), \
             contextlib.redirect_stdout(io.StringIO()):
            scope=runpy.run_path(str(SCRIPT),run_name='__main__')
        return scope,calls

    def test_outbound_uses_only_vm116_exact_edge_tcp_destination(self):
        scope,_=self.load()
        self.assertEqual(len(scope['EDGES']),20)
        for edge in scope['EDGES']:
            rule=scope['rule'](edge)
            self.assertEqual(rule,['priority','901','from','10.10.10.61/32','to',edge+'/32',
                                   'ipproto','tcp','dport','7844','lookup','17844'])

    def test_return_uses_cloudflare_source_port_and_host_return_table(self):
        scope,_=self.load(ct=True)
        edge=scope['EDGES'][0]
        self.assertEqual(scope['rule'](edge,True),
            ['priority','902','from',edge+'/32','to','10.10.10.61/32',
             'ipproto','tcp','sport','7844','lookup','17845'])
        self.assertEqual(scope['rule'](edge)[-1],'119')

    def test_rule_matching_handles_split_cidr_and_rejects_other_guests(self):
        scope,_=self.load();edge=scope['EDGES'][0]
        row={'priority':901,'src':'10.10.10.61','dst':edge,'dstlen':32,
             'ipproto':'tcp','dport':7844,'table':'17844'}
        self.assertEqual(scope['matching']([row],edge),[row])
        for replacement in [{'src':'10.10.10.1'},{'src':'10.10.10.62'},
                            {'ipproto':'udp'},{'dport':443},{'table':'7844'}]:
            self.assertEqual(scope['matching']([{**row,**replacement}],edge),[])

    def test_rollback_cannot_flush_global_state_or_touch_shared_tables(self):
        scope,_=self.load();commands=[]
        def fake(args,**kwargs):
            commands.append(args)
            if args==['lxc-info','-n','113','-pH']:out='1234\n'
            else:out=''
            return subprocess.CompletedProcess(args,0,out,'')
        globals_=scope['rollback'].__globals__
        with patch.dict(globals_,{'run':fake,'policy':lambda *a,**k:None,'ct':lambda action:{}}):
            scope['rollback']()
        self.assertEqual(sum(cmd[0]=='conntrack' for cmd in commands),20)
        self.assertEqual(sum(cmd[0]=='nsenter' for cmd in commands),20)
        for cmd in commands:
            self.assertNotIn('flush',cmd)
            self.assertNotIn('-F',cmd)
            if 'conntrack' in cmd:
                self.assertIn('10.10.10.61',cmd)
                self.assertIn('--orig-dst',cmd)
                self.assertIn('--dport',cmd)
                self.assertIn('7844',cmd)
            if cmd[:3]==['ip','route','del']:self.assertIn('17844',cmd)


if __name__=='__main__':unittest.main()
