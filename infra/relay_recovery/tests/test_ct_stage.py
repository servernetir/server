"""Isolated fake CT filesystem/process tests; no live services or networking."""
import base64
import copy
import hashlib
import json
from pathlib import Path
import sqlite3
import struct
import tempfile
import unittest
from unittest.mock import patch

from infra.relay_recovery import ct_stage as ct
from infra.relay_recovery.protocol import ProtocolError,canonical_json

NOW=1_800_000_000


def key(value):
    return 'ssh-ed25519 '+base64.b64encode(struct.pack('!I',11)+b'ssh-ed25519'+struct.pack('!I',32)+bytes([value])*32).decode()


class CtStageTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.root.chmod(0o700)
        for name in ('state','jobs','units'):(self.root/name).mkdir(mode=0o700)
        self.ssh=self.root/'relay.key';self.ssh.write_text('fixture never used');self.ssh.chmod(0o600)
        self.cfg=dict(schema=ct.CONFIG_SCHEMA,agent_id='pve-ir',state_dir=str(self.root/'state'),
            job_dir=str(self.root/'jobs'),unit_dir=str(self.root/'units'),ssh_key_file=str(self.ssh),ssh_port=22,
            restored_host_key_sha256=ct.fingerprint(key(2)),root_owned=False,_job_expires_at=NOW+30)
        self.calls=[];self.ctx=self.context();self.now=NOW;self.active=True;self.jobs=False
        self.socket=True;self.main='8123';self.process_host='91.107.179.25';self.egress='91.107.179.25'
        self.fail_restart=False;self.last_socks=ct.STAGE_SOCKS;self.global_known=None

    def context(self,op='stage',profile='trial',job_id=None):
        params=dict(stage_profile=profile,ssh_host_public_key=key(1 if profile=='trial'else 2),
                    ssh_host_key_sha256=ct.fingerprint(key(1 if profile=='trial'else 2)))
        if profile=='restored':params.update(restore_epoch=1,restore_hash='d'*64)
        if op in ('apply','rollback'):
            params.update(old_identity=dict(provider='hetzner',project_id='p',provider_id='111',generation=1),
                          old_ipv4='155.103.69.128',old_config_hash='a'*64,expected_registry_hash='c'*64)
        return dict(schema='servernet.relay.recovery.v1',agent_id='pve-ir',incident_id='incident-1',
            generation=2,revision=4,config_hash='b'*64,jobs_hash='e'*64,
            job=dict(id=job_id or op+'-1',op=op,target=dict(host='91.107.179.25',port=22,provider_id='222'),params=params))

    def runner(self,argv,data,timeout):
        self.calls.append((list(argv),data,timeout));self.assertEqual(data,b'')
        self.assertLessEqual(timeout,4)
        if argv[:2]==['/usr/bin/systemctl','daemon-reload']:return 0,b''
        if argv[:2]==['/usr/bin/systemctl','restart']:
            if self.fail_restart:raise ProtocolError('fixture_timeout')
            return 0,b''
        if argv[:2]==['/usr/bin/systemctl','is-active']:
            return (0,('\n'.join(['active']*(len(argv)-2))+'\n').encode())if self.active else(3,b'failed\n')
        if argv[:2]==['/usr/bin/systemctl','show']:return 0,(self.main+'\n').encode()
        if argv[:2]==['/usr/bin/systemctl','list-jobs']:
            return 0,('4 '+ct.SERVICE+' start running\n').encode()if self.jobs else b''
        if argv[0]=='/usr/bin/ss':
            self.last_socks=ct.STAGE_SOCKS if ':10829'in argv[-1]else ct.GLOBAL_SOCKS
            return 0,(f'LISTEN 0 128 {self.last_socks} 0.0.0.0:* users:(("ssh",pid=8123,fd=4))\n'.encode()if self.socket else b'')
        if argv[0]=='/usr/bin/ps':
            pin=self.helper()._pin(self.ctx)if self.last_socks==ct.STAGE_SOCKS else self.global_known
            return 0,f'/usr/bin/ssh -F /dev/null -N -D {self.last_socks} -i {self.ssh} -o StrictHostKeyChecking=yes -o HostKeyAlgorithms=ssh-ed25519 -o IdentitiesOnly=yes -o GlobalKnownHostsFile=/dev/null -o UserKnownHostsFile={pin} root@{self.process_host}\n'.encode()
        if argv[0]=='/usr/bin/curl':
            self.assertEqual(argv[1],'-q');self.assertEqual(argv[-2:],[str(2),'https://api.ipify.org'])
            return 0,(self.egress+'\n').encode()
        raise AssertionError('Unexpected fixed command')

    def helper(self):return ct.CtStage(self.cfg,runner=self.runner,clock=lambda:self.now,monotonic=lambda:0)

    def registry(self):
        self.ctx=self.context('apply','restored')
        old=self.ctx['job']['params']['old_ipv4']
        files=[];self.original={}
        contents={'endpoint.json':canonical_json({'remote':{'host':old},'untouched':'value'}),
                  'endpoint.env':('TARGET='+old+':22\n').encode(),
                  'known_hosts':(old+' '+key(2)+'\n').encode()}
        for name,content in contents.items():
            path=self.root/name;path.write_bytes(content);path.chmod(0o644);self.original[str(path)]=content
            entry=dict(path=str(path),sha256=hashlib.sha256(content).hexdigest(),unit='vm119-fixed-relay.service',
                       format='json'if name.endswith('.json')else'text_tokens')
            if entry['format']=='json':entry['pointers']=[['remote','host']]
            else:entry['token_count']=1
            files.append(entry)
        self.global_known=self.root/'known_hosts'
        body=dict(schema=ct.REGISTRY_SCHEMA,old_identity=self.ctx['job']['params']['old_identity'],old_ipv4=old,
            old_config_hash='a'*64,source_host_key_sha256=ct.fingerprint(key(2)),allowed_units=['vm119-fixed-relay.service'],
            verify_unit='vm119-fixed-relay.service',known_hosts_file=str(self.global_known),files=files)
        path=self.root/'registry.json';path.write_bytes(canonical_json(body));path.chmod(0o600)
        digest=hashlib.sha256(path.read_bytes()).hexdigest();self.cfg.update(registry_file=str(path),registry_sha256=digest)
        self.ctx['job']['params']['expected_registry_hash']=digest
        fence=dict(schema=ct.FENCE_SCHEMA,verified=True,received_at=NOW,old_identity=body['old_identity'],old_ipv4=old,
            old_config_hash='a'*64,target=self.ctx['job']['target'],restore_epoch=1,restore_hash='d'*64,
            source_fenced=True,provider_off=True)
        fencepath=self.root/'fence.json';fencepath.write_bytes(canonical_json(fence));fencepath.chmod(0o600)
        self.cfg['old_fence_file']=str(fencepath)
        return body

    def test_stage_isolated_actual_ssh_pin_and_egress(self):
        result=self.helper().run('stage',self.ctx)
        self.assertTrue(result['ok']);self.assertTrue(result['checks']['staged'])
        unit=(self.root/'units'/ct.SERVICE).read_text()
        self.assertIn('127.0.0.1:10829',unit);self.assertIn('StrictHostKeyChecking=yes',unit)
        self.assertNotIn('tun119',unit);self.assertNotIn('table119',unit);self.assertNotIn('10819',unit)
        self.assertEqual({tuple(c[0][:2])for c in self.calls if c[0][0]=='/usr/bin/systemctl'and c[0][1]in ('restart','daemon-reload')},
                         {('/usr/bin/systemctl','restart'),('/usr/bin/systemctl','daemon-reload')})
        self.assertTrue(all(c[0][-1]==ct.SERVICE for c in self.calls if c[0][:2]==['/usr/bin/systemctl','restart']))

    def test_stage_requires_real_egress_and_owned_main_pid(self):
        for wrong in ('egress','main'):
            with self.subTest(wrong=wrong):
                if wrong=='egress':self.egress='1.1.1.1'
                else:self.main='9999'
                self.ctx['job']['id']='stage-'+wrong
                self.assertFalse(self.helper().run('stage',self.ctx)['ok'])
                self.egress='91.107.179.25';self.main='8123'

    def test_target_port_and_effective_ssh_port_must_match_without_config_overrides(self):
        wrong=copy.deepcopy(self.ctx);wrong['job']['target']['port']=443
        with self.assertRaises(ProtocolError):self.helper().run('stage',wrong)
        self.assertFalse(self.calls)
        helper=self.helper();pin=helper._pin(self.ctx)
        args=['ssh','-F','/dev/null','-N','-D',ct.STAGE_SOCKS,'-i',str(self.ssh),
            '-o','StrictHostKeyChecking=yes','-o','HostKeyAlgorithms=ssh-ed25519','-o','IdentitiesOnly=yes',
            '-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(pin),'root@91.107.179.25']
        self.assertTrue(helper._ssh_args(args,self.ctx['job']['target'],ct.STAGE_SOCKS,pin))
        for inserted in (['-p','443'],['-p','22','-o','Port=443'],['-o','Port=22','-o','port=22'],
                         ['-F','/root/.ssh/config'],['-o','GlobalKnownHostsFile=/etc/ssh/ssh_known_hosts'],
                         ['-o','ProxyCommand=sh -c invalid'],['-o','UpdateHostKeys=yes'],['-o','VerifyHostKeyDNS=yes']):
            with self.subTest(inserted=inserted):
                self.assertFalse(helper._ssh_args(args[:-1]+inserted+args[-1:],self.ctx['job']['target'],ct.STAGE_SOCKS,pin))

    def test_invalid_key_pin_or_arbitrary_network_fields_rejected(self):
        for changed in ({'ssh_host_key_sha256':'SHA256:wrong'},{'ssh_host_public_key':key(1)+' injected'},
                        {'command':'reboot'},{'stage_profile':'restored'},{'restore_epoch':1}):
            bad=copy.deepcopy(self.ctx);bad['job']['params'].update(changed)
            with self.subTest(changed=changed),self.assertRaises(ProtocolError):ct.validate_context(bad)
        self.assertFalse(self.calls)

    def test_trial_and_restored_have_distinct_immutable_pins(self):
        self.assertTrue(self.helper().run('stage',self.ctx)['ok']);trial=self.helper()._pin(self.ctx);before=trial.read_bytes()
        self.ctx=self.context('stage','restored','restored-stage')
        self.assertTrue(self.helper().run('stage',self.ctx)['ok'])
        self.assertNotEqual(self.helper()._pin(self.ctx),trial);self.assertEqual(trial.read_bytes(),before)
        self.assertIn(key(2),self.helper()._pin(self.ctx).read_text())

    def test_restored_profile_cannot_use_unknown_source_pin(self):
        self.ctx=self.context('stage','restored');self.cfg['restored_host_key_sha256']=ct.fingerprint(key(1))
        with self.assertRaises(ProtocolError):self.helper().run('stage',self.ctx)
        self.assertFalse(self.calls)

    def test_existing_unowned_stage_unit_or_pin_is_never_overwritten(self):
        unit=self.root/'units'/ct.SERVICE;unit.write_text('unreviewed service');unit.chmod(0o644)
        with self.assertRaises(ProtocolError):self.helper().run('stage',self.ctx)
        self.assertEqual(unit.read_text(),'unreviewed service')

    def test_job_file_hash_nonce_and_ttl_transport(self):
        nonce='a'*32;path=self.root/'jobs'/(nonce+'.json')
        body=canonical_json(dict(schema=ct.JOB_SCHEMA,nonce=nonce,created_at=NOW,expires_at=NOW+30,action='stage',context=self.ctx))
        path.write_bytes(body);path.chmod(0o600);sha=hashlib.sha256(body).hexdigest()
        self.assertEqual(ct.load_job(self.cfg,path,sha,nonce,'stage',NOW),self.ctx)
        for args in ((path,'f'*64,nonce,'stage',NOW),(path,sha,'b'*32,'stage',NOW),(path,sha,nonce,'stage',NOW+31)):
            with self.assertRaises(ProtocolError):ct.load_job(self.cfg,*args)
        self.assertEqual(ct.load_job(self.cfg,path,sha,nonce,'reconcile',NOW+100),self.ctx)

    def test_symlink_or_public_job_is_rejected(self):
        path=self.root/'jobs'/('a'*32+'.json');path.write_text('{}');path.chmod(0o644)
        with self.assertRaises(ProtocolError):ct.load_job(self.cfg,path,hashlib.sha256(b'{}').hexdigest(),'a'*32,'stage',NOW)
        path.unlink();real=self.root/'real';real.write_text('{}');real.chmod(0o600);path.symlink_to(real)
        with self.assertRaises(ProtocolError):ct.load_job(self.cfg,path,hashlib.sha256(b'{}').hexdigest(),'a'*32,'stage',NOW)

    def test_crash_after_unit_write_preserves_intent_and_prevents_repeat(self):
        self.fail_restart=True
        with self.assertRaises(ProtocolError):self.helper().run('stage',self.ctx)
        with sqlite3.connect(self.root/'state'/'stage.sqlite3')as db:
            self.assertEqual(db.execute('SELECT phase FROM operations').fetchone()[0],'FILES')
        self.assertTrue((self.root/'units'/ct.SERVICE).exists())
        before=len(self.calls)
        with self.assertRaises(ProtocolError):self.helper().run('stage',self.ctx)
        self.assertEqual(len(self.calls),before)
        self.fail_restart=False
        self.assertTrue(self.helper().run('reconcile',self.ctx)['reconciled'])
        self.assertEqual(sum(c[0][:2]==['/usr/bin/systemctl','restart']for c in self.calls),1)

    def test_reconcile_is_read_only_and_can_resolve_known_failed_stage(self):
        self.fail_restart=True
        with self.assertRaises(ProtocolError):self.helper().run('stage',self.ctx)
        self.fail_restart=False;self.socket=False
        before={str(p):p.read_bytes()for p in self.root.rglob('*')if p.is_file()};self.calls.clear()
        result=self.helper().run('reconcile',self.ctx)
        self.assertTrue(result['read_only']);self.assertTrue(result['reconciled']);self.assertFalse(result['ok'])
        self.assertEqual(before,{str(p):p.read_bytes()for p in self.root.rglob('*')if p.is_file()})
        self.assertFalse(any(c[0][:2]in (['/usr/bin/systemctl','restart'],['/usr/bin/systemctl','daemon-reload'])for c in self.calls))

    def test_pending_systemd_job_keeps_unknown_reconciliation(self):
        self.fail_restart=True
        with self.assertRaises(ProtocolError):self.helper().run('stage',self.ctx)
        self.fail_restart=False;self.jobs=True
        self.assertFalse(self.helper().run('reconcile',self.ctx)['reconciled'])

    def test_apply_requires_reviewed_registry_and_fresh_fenced_old_proof(self):
        self.ctx=self.context('apply','restored')
        with self.assertRaises(ProtocolError):self.helper().run('apply',self.ctx)
        self.registry();fence=Path(self.cfg['old_fence_file']);data=json.loads(fence.read_text());data['provider_off']=False
        fence.write_bytes(canonical_json(data))
        with self.assertRaises(ProtocolError):self.helper().run('apply',self.ctx)
        self.assertEqual({p:Path(p).read_bytes()for p in self.original},self.original)

    def test_apply_changes_only_exact_registered_endpoints_and_backs_up_first(self):
        self.registry();result=self.helper().run('apply',self.ctx)
        self.assertTrue(result['ok']);self.assertTrue(result['checks']['applied'])
        self.assertEqual(json.loads((self.root/'endpoint.json').read_text())['remote']['host'],'91.107.179.25')
        self.assertEqual((self.root/'endpoint.env').read_text(),'TARGET=91.107.179.25:22\n')
        for p,old in self.original.items():
            self.assertIn(old,[b.read_bytes()for b in (self.root/'state'/'backups').rglob('*.bin')])
            self.assertEqual(Path(p).stat().st_mode&0o777,0o644)
        self.assertEqual([c[0][-1]for c in self.calls if c[0][:2]==['/usr/bin/systemctl','restart']],['vm119-fixed-relay.service'])

    def test_apply_cas_failure_never_writes_any_endpoint(self):
        self.registry();(self.root/'endpoint.env').write_text('concurrent edit')
        expected={p:Path(p).read_bytes()for p in self.original}
        with self.assertRaises(ProtocolError):self.helper().run('apply',self.ctx)
        self.assertEqual({p:Path(p).read_bytes()for p in self.original},expected);self.assertFalse(self.calls)

    def test_global_pin_must_be_dedicated_exact_single_target_file(self):
        body=self.registry()
        known=Path(body['known_hosts_file']);known.write_bytes(known.read_bytes()+('91.107.179.25 '+key(3)+'\n').encode())
        body['files'][-1]['sha256']=hashlib.sha256(known.read_bytes()).hexdigest()
        registry=Path(self.cfg['registry_file']);registry.write_bytes(canonical_json(body))
        digest=hashlib.sha256(registry.read_bytes()).hexdigest()
        self.cfg['registry_sha256']=digest;self.ctx['job']['params']['expected_registry_hash']=digest
        with self.assertRaises(ProtocolError):self.helper().run('apply',self.ctx)
        self.assertFalse(self.calls)

    def test_apply_partial_crash_is_durable_and_readonly_reconcile_does_not_repair(self):
        self.registry();original=ct._atomic;written=[]
        def crash(path,data,*args,**kw):
            original(path,data,*args,**kw)
            if str(path)in self.original:
                written.append(str(path));raise RuntimeError('simulated crash after first replacement')
        with patch.object(ct,'_atomic',side_effect=crash),self.assertRaises(ProtocolError):self.helper().run('apply',self.ctx)
        self.assertEqual(len(written),1)
        with sqlite3.connect(self.root/'state'/'stage.sqlite3')as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM operations').fetchone()[0],1)
        before={p:Path(p).read_bytes()for p in self.original};self.calls.clear()
        result=self.helper().run('reconcile',self.ctx)
        self.assertTrue(result['reconciled']);self.assertFalse(result['ok'])
        self.assertEqual({p:Path(p).read_bytes()for p in self.original},before)
        self.assertFalse(any(c[0][:2]==['/usr/bin/systemctl','restart']for c in self.calls))

    def test_rollback_is_disabled_until_explicit_local_authorization(self):
        self.registry();self.helper().run('apply',self.ctx)
        self.ctx['job']['op']='rollback';self.ctx['job']['id']='rollback-1'
        with self.assertRaises(ProtocolError):self.helper().run('rollback',self.ctx)

    def test_explicit_rollback_restores_only_recorded_backups_without_unfencing(self):
        self.registry();self.helper().run('apply',self.ctx);self.cfg['rollback_enabled']=True
        self.ctx['job']['op']='rollback';self.ctx['job']['id']='rollback-1';self.calls.clear()
        self.process_host='155.103.69.128';self.egress='155.103.69.128'
        result=self.helper().run('rollback',self.ctx)
        self.assertTrue(result['checks']['rolled_back'])
        self.assertEqual({p:Path(p).read_bytes()for p in self.original},self.original)
        self.assertFalse(any('power' in ' '.join(c[0])or'ufence'in' '.join(c[0])for c in self.calls))

    def test_deadline_expiry_before_dispatch_creates_no_effect(self):
        self.now=NOW+31
        with self.assertRaises(ProtocolError):self.helper().run('stage',self.ctx)
        self.assertFalse(self.calls);self.assertFalse((self.root/'units'/ct.SERVICE).exists())


if __name__=='__main__':unittest.main()
