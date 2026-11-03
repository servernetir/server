"""PVE/CT job transport, durable dispatch and exact receipt tests; fake I/O."""
from __future__ import annotations
import base64
import copy
import hashlib
import os
from pathlib import Path
import sqlite3
import struct
import sys
import tempfile
import unittest

BASE=Path(__file__).resolve().parents[1];sys.path.insert(0,str(BASE))
import iran_stage as mod
import protocol


class NodeStageTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.root.chmod(0o700)
        self.now=1_800_000_000;self.calls=[];self.remote={};self.fail_dispatch=False
        self.bad_pin=False;self.bad_permissions=False;self.bad_result=None;self.push_status=0
        self.ct_wrapper='/usr/local/lib/servernet-relay-recovery/ct-stage'
        self.ct_config='/etc/servernet/relay-recovery/ct-stage.json'
        self.raw=dict(schema=mod.SCHEMA,agent_id='pve-ir',state_dir=str(self.root/'state'),
            pct_sha256='a'*64,ct_job_dir='/var/lib/servernet-relay-stage/jobs',ct_wrapper=self.ct_wrapper,
            ct_config=self.ct_config,ct_pins=[{'path':self.ct_wrapper,'sha256':'b'*64},
                {'path':self.ct_config,'sha256':'c'*64}],ct_registry_covers_ir_consumers=False)
        self.config=self.root/'node.json';self.write_config()
        blob=struct.pack('!I',11)+b'ssh-ed25519'+struct.pack('!I',32)+b'x'*32
        self.public='ssh-ed25519 '+base64.b64encode(blob).decode()
        self.fingerprint='SHA256:'+base64.b64encode(hashlib.sha256(blob).digest()).decode().rstrip('=')
        self.nonce_count=0

    def write_config(self):
        self.config.write_bytes(protocol.canonical_json(self.raw));self.config.chmod(0o600)
        self.cfg=mod.load_config(self.config,root_owned=False)

    def context(self,op='stage'):
        return dict(schema=protocol.SCHEMA,agent_id='pve-ir',incident_id='incident-1',generation=2,revision=3,
            config_hash='d'*64,jobs_hash='e'*64,job={'id':op+'-1','op':op,
                'target':{'host':'1.1.1.1','port':22,'provider_id':'10101'},
                'params':{'stage_profile':'trial','ssh_host_public_key':self.public,'ssh_host_key_sha256':self.fingerprint}})

    def nonce(self):
        self.nonce_count+=1;return f'{self.nonce_count:032x}'

    def runner(self,argv,data,timeout):
        self.calls.append((list(argv),data,timeout));self.assertEqual(data,b'')
        if '/usr/bin/stat'in argv:
            paths=argv[argv.index('/usr/bin/stat')+4:]
            lines=[]
            for path in paths:
                file=path in {p['path']for p in self.cfg['ct_pins']}
                kind='regular file'if file else'directory'
                mode='700'if path in (self.ct_wrapper,self.cfg['ct_job_dir'])else'600'if path==self.ct_config else'755'
                if self.bad_permissions and path==self.cfg['ct_job_dir']:mode='777'
                lines.append('0:'+mode+':'+kind+':'+path)
            return 0,('\n'.join(lines)+'\n').encode()
        if '/usr/bin/sha256sum'in argv:
            lines=[('0'*64 if self.bad_pin else p['sha256'])+'  '+p['path']for p in self.cfg['ct_pins']]
            return 0,('\n'.join(lines)+'\n').encode()
        if argv[:3]==[mod.PCT,'push','113']:
            self.assertEqual(argv[-6:],['--perms','0600','--user','0','--group','0'])
            path=Path(argv[3]);self.assertEqual(path.stat().st_mode&0o777,0o600)
            self.remote[argv[4]]=path.read_bytes();return self.push_status,b''
        if self.ct_wrapper in argv:
            if self.fail_dispatch:raise protocol.ProtocolError('adapter_timeout')
            path=argv[argv.index('--job-file')+1];raw=self.remote[path]
            self.assertEqual(hashlib.sha256(raw).hexdigest(),argv[argv.index('--job-sha256')+1])
            envelope=protocol.decode_json(raw);context=envelope['context'];action=argv[-1]
            self.assertEqual(envelope['nonce'],argv[argv.index('--nonce')+1])
            checks={'generation_verified':True}
            flag={'stage':'staged','apply':'applied','accept':'accepted','rollback':'rolled_back'}[context['job']['op']]
            checks[flag]=True
            result=dict(schema=mod.RECIPE_SCHEMA,action=action,read_only=action in('accept','reconcile'),ok=True,
                checks=checks,target=context['job']['target'],**{k:context[k]for k in mod.SCOPE})
            if action=='reconcile':result['reconciled']=True
            if self.bad_result:self.bad_result(result)
            return 0,protocol.canonical_json(result)
        raise AssertionError('Unexpected fixed command')

    def node(self):
        return mod.NodeStage(self.cfg,runner=self.runner,now=lambda:self.now,nonce=self.nonce,verify_pct=False)

    def test_stage_uses_explicit_private_push_then_fixed_no_stdin_helper(self):
        result=self.node().run('stage',self.context())
        self.assertTrue(result['ok']);self.assertTrue(result['checks']['staged'])
        commands=[c[0]for c in self.calls]
        self.assertEqual(commands[0][6],'/usr/bin/stat')
        self.assertTrue(any(c[:3]==[mod.PCT,'push','113']for c in commands))
        self.assertTrue(all('--pass-stdin'not in c for c in commands))
        with sqlite3.connect(Path(self.cfg['state_dir'])/'node-stage.sqlite3')as db:
            self.assertEqual(db.execute('SELECT state FROM operations').fetchone()[0],'COMPLETE')

    def test_pin_or_remote_private_directory_failure_prevents_even_push(self):
        for attribute in ('bad_pin','bad_permissions'):
            setattr(self,attribute,True)
            with self.subTest(attribute=attribute),self.assertRaises(mod.StageError):self.node().run('stage',self.context())
            self.assertFalse(any(c[0][1]=='push'for c in self.calls));setattr(self,attribute,False);self.calls.clear()

    def test_dispatch_unknown_survives_restart_and_cannot_repeat_or_buy_other_scope(self):
        self.fail_dispatch=True
        with self.assertRaises(mod.OutcomeUnknown):self.node().run('stage',self.context())
        count=len(self.calls)
        with self.assertRaises(mod.OutcomeUnknown):self.node().run('stage',self.context())
        other=self.context();other['revision']=4;other['job']['id']='next-stage'
        with self.assertRaises(mod.OutcomeUnknown):self.node().run('stage',other)
        self.assertEqual(len(self.calls),count)
        with sqlite3.connect(Path(self.cfg['state_dir'])/'node-stage.sqlite3')as db:
            self.assertEqual(db.execute('SELECT state FROM operations').fetchone()[0],'DISPATCHED')

    def test_reconcile_reuses_original_expired_job_and_never_pushes_or_mutates(self):
        self.fail_dispatch=True
        with self.assertRaises(mod.OutcomeUnknown):self.node().run('stage',self.context())
        old_files=copy.deepcopy(self.remote);self.calls.clear();self.fail_dispatch=False;self.now+=100
        result=self.node().run('reconcile',self.context())
        self.assertTrue(result['reconciled']);self.assertTrue(result['read_only']);self.assertTrue(result['ok'])
        self.assertEqual(self.remote,old_files)
        self.assertFalse(any(c[0][1]=='push'for c in self.calls))
        self.assertEqual([c[0][-1]for c in self.calls if len(c[0])>6 and c[0][6]==self.ct_wrapper],['reconcile'])

    def test_failure_before_dispatch_reconciles_as_no_write_without_ct_execution(self):
        self.push_status=1
        with self.assertRaises(mod.OutcomeUnknown):self.node().run('stage',self.context())
        self.calls.clear();result=self.node().run('reconcile',self.context())
        self.assertTrue(result['reconciled']);self.assertFalse(result['ok'])
        self.assertTrue(result['checks']['definitive_no_write']);self.assertFalse(self.calls)

    def test_missing_operation_reconciliation_stays_unknown(self):
        result=self.node().run('reconcile',self.context())
        self.assertFalse(result['reconciled']);self.assertFalse(result['ok']);self.assertFalse(self.calls)

    def test_completed_stage_is_idempotent_without_second_remote_effect(self):
        node=self.node();first=node.run('stage',self.context());self.calls.clear()
        self.assertEqual(node.run('stage',self.context()),first);self.assertFalse(self.calls)

    def test_network_pin_and_scope_injection_rejected_before_remote_calls(self):
        for change in ({'ssh_host_key_sha256':'SHA256:forged'}, {'stage_profile':'../../bad'}, {'argv':['reboot']}):
            context=self.context();context['job']['params'].update(change)
            with self.subTest(change=change),self.assertRaises(protocol.ProtocolError):self.node().run('stage',context)
        context=self.context();context['job']['target']['host']='1.1.1.1; reboot'
        with self.assertRaises(protocol.ProtocolError):self.node().run('stage',context)
        self.assertFalse(self.calls)

    def test_stage_ssh_port_must_match_the_reviewed_local_port_before_any_push(self):
        context=self.context();context['job']['target']['port']=443
        with self.assertRaisesRegex(mod.StageError,'stage_target_port'):
            self.node().run('stage',context)
        self.assertFalse(self.calls)

    def test_unproven_or_wrong_scoped_remote_success_preserves_unknown_dispatch(self):
        for mutation in ({'generation':999},{'read_only':True},{'checks':{}},{'target':{'host':'8.8.4.4'}}):
            context=self.context();context['revision']+=self.nonce_count
            self.bad_result=lambda result:result.update(mutation)
            with self.subTest(mutation=mutation),self.assertRaises(mod.OutcomeUnknown):self.node().run('stage',context)
            # Resolve the prior uncertain operation by its exact original scope
            # before the next independent fake mutation case.
            self.bad_result=None;self.node().run('reconcile',context)

    def test_apply_without_explicit_ct_only_inventory_is_hold_before_side_effect(self):
        context=self.context('apply');context['job']['params'].update(stage_profile='restored',restore_epoch=1,
            restore_hash='f'*64,old_identity={'provider':'hetzner','project_id':'p','provider_id':'123','generation':1},
            old_ipv4='8.8.8.8',old_config_hash='a'*64,expected_registry_hash='b'*64)
        result=self.node().run('apply',context)
        self.assertFalse(result['ok']);self.assertFalse(result['checks']['node_inventory_ready']);self.assertFalse(self.calls)

    def test_persistent_clock_regression_prevents_new_dispatch(self):
        self.node().run('stage',self.context());self.calls.clear();self.now-=1
        context=self.context();context['revision']=4
        with self.assertRaises(mod.StageError):self.node().run('stage',context)
        self.assertFalse(any(c[0][1]=='push'for c in self.calls))

    def test_config_disallows_symlinks_paths_and_missing_code_pins(self):
        path=self.root/'config-link';path.symlink_to(self.config)
        with self.assertRaises(protocol.ProtocolError):mod.load_config(path,root_owned=False)
        self.raw['ct_job_dir']='/tmp/../unreviewed';self.write_config_raises()
        self.raw['ct_job_dir']='/var/lib/servernet-relay-stage/jobs';self.raw['ct_pins']=self.raw['ct_pins'][:1]
        self.write_config_raises()

    def write_config_raises(self):
        self.config.write_bytes(protocol.canonical_json(self.raw))
        with self.assertRaises(protocol.ProtocolError):mod.load_config(self.config,root_owned=False)


if __name__=='__main__':unittest.main()
