"""Real producer -> adapter -> signed gateway -> collector -> core semantics.

Only command/TCP/provider I/O is fake. An unavailable fixed external measurement
must never become a relay purchase; actual SSH or target failures remain bad.
"""
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from infra.relay_recovery import core, controller_adapter, gateway, iran_adapter, iran_probes, protocol


class PathStateTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.root.chmod(0o700)
        self.host_control_config=self.root/'host-control.json'
        self.host_control_config.write_bytes(protocol.canonical_json(dict(
            schema='servernet.relay.host-control.v1',agent_id='ir-agent',ipv4='8.8.8.8')))
        self.host_control_config.chmod(0o600)
        self.now=1_800_000_000;self.mode='healthy';self.target_tcp=True;self.bad_route_diagnostic=False
        self.link_output=None;self.table_output=None
        self.target='1.1.1.1';self.calls=[]
        self.active=dict(provider='hetzner',project_id='p',provider_id='123',generation=1,config_hash='a'*64)
        self.key=self.root/'agent.key';self.key.write_bytes(b'k'*32);self.key.chmod(0o600)
        recipe=self.root/'independent';recipe.write_bytes(b'fixed fake independent control');recipe.chmod(0o700)
        self.cfg=dict(state_dir=str(self.root),active=self.active,binding={'owner_id':'1','service_id':'7001','ipv4':self.target},
            policy={'enabled':True,'owner_id':'1','service_id':'7001','project_id':'p'},
            agents={'ir-agent':{'enabled':True,'key_file':str(self.key)}},
            collector={'recipes':{'independent_control':{'path':str(recipe),'sha256':hashlib.sha256(recipe.read_bytes()).hexdigest()}}})
        self.journal=core.Journal(self.root/'controller.sqlite3');self.journal.initialize(self.active,self.now)
        self.engine=core.Engine(core.Config(**self.cfg['policy']),self.journal)

    def command(self,argv,data,timeout):
        self.calls.append(list(argv));self.assertEqual(data,b'')
        command=argv[4:]
        if command[0]=='/usr/bin/systemctl':return 0,b'active\nactive\n'
        if command[:4]==['/usr/sbin/ip','-j','link','show']:
            if self.link_output is not None:return 0,self.link_output
            return 0,b'[{"ifname":"tun119","flags":["UP"]}]'
        if command[:4]==['/usr/sbin/ip','-j','route','show']:
            if self.table_output is not None:return 0,self.table_output
            return 0,b'[{"dst":"default","dev":"tun119"},{"dst":"default","type":"blackhole"}]'
        if command[:4]==['/usr/sbin/ip','-j','route','get']:
            if self.bad_route_diagnostic:return 0,b'not-json'
            return 0,b'[{"dev":"tun119","table":119}]'
        if command[0]=='/usr/bin/ss':
            return (0,b'')if self.mode=='tunnel_down'else(0,b'LISTEN 0 128 127.0.0.1:10819 0.0.0.0:* users:(("ssh",pid=100,fd=4))')
        if command[0]=='/usr/bin/ps':return 0,('/usr/bin/ssh -N -D127.0.0.1:10819 root@'+self.target).encode()
        if command[0]=='/usr/bin/curl':
            self.assertEqual(command[:4],['/usr/bin/curl','-q','--ipv4','--socks5-hostname'])
            self.assertEqual(command[-1],'https://api.ipify.org')
            if self.mode in ('measurement_down','tunnel_down','missing_ct'):return 22,b''
            if self.mode=='measurement_invalid':return 0,b'service unavailable'
            if self.mode=='wrong_egress':return 0,b'8.8.4.4'
            return 0,self.target.encode()
        raise AssertionError('Unexpected fixed command')

    def recipe_runner(self,argv,data,timeout):
        request=protocol.decode_json(data)
        return 0,protocol.canonical_json(dict(schema='servernet.relay.collector.v1',kind=request['kind'],
            ok=True,observed_at=self.now,relay_independent=True))

    def pipeline(self):
        class Provider:
            def quotes(inner):return []
            def inventory(inner,active):return {}
        collector=controller_adapter.Adapter(self.cfg,self.now,provider=Provider(),runner=self.recipe_runner)
        manifest=collector.publish(self.engine.snapshot(),'ir-agent')
        context={k:manifest[k]for k in ('agent_id','incident_id','generation','revision','config_hash','jobs_hash')}
        context.update(schema=protocol.SCHEMA,job=manifest['jobs'][0])
        files={'/proc/uptime':b'12345 23456','/sys/class/net/vmbr0/operstate':b'up',
               '/sys/class/net/vmbr0/flags':b'0x1003'}
        producer=iran_probes.Probes(runner=self.command,tcp=lambda *a,**kw:True,read=lambda p:files[p],
            host_control_config=self.host_control_config,root_owned=False)
        adapter=iran_adapter.IranAdapter({'probes':{'host':{},'ct113':{},'vm109':{}},
            'consumer_probes':{},'vm109_source_ip':'10.10.10.50'},tcp=lambda *a:self.target_tcp,icmp=lambda *a:None)
        def local(spec,kind,context,*,probe):
            if kind=='ct113'and self.mode=='missing_ct':raise protocol.ProtocolError('adapter_timeout')
            if kind in ('host','ct113'):return producer.run(kind,context,'ir-agent')
            return dict(ok=False,checks={})  # Guest readiness is independent.
        with patch.object(adapter,'_run',side_effect=local):ok,detail=adapter._probe(context)
        receipt={k:manifest[k]for k in ('incident_id','generation','revision','config_hash','jobs_hash')}
        receipt.update(job_id=manifest['jobs'][0]['id'],op='probe',ok=ok,observed_at=self.now,
                       checks={'candidate_tcp':self.target_tcp,'adapter':detail})
        report=dict(agent_id='ir-agent',observed_at=self.now,direct_control_tcp=[True],jobs=[receipt])
        body=protocol.canonical_json(dict(agent_id='ir-agent',op='poll',report=report))
        headers=protocol.sign_message(b'k'*32,'ir-agent','POST','/relay-recovery-agent.php',body,self.now)
        accepted=gateway.handle(dict(method='POST',path='/relay-recovery-agent.php',body=body.decode(),headers=headers),self.cfg,self.now)
        self.assertEqual(accepted['status'],200)
        evidence=collector.collect({'state':self.engine.snapshot()})['evidence']
        # All purchase gates are deliberately ready: only real path evidence
        # determines whether a CREATE could become eligible in these tests.
        evidence['preflight']=dict(verified=True,received_at=self.now,ready=True,owner_id='1',service_id='7001',project_id='p',
            checks={name:True for name in self.engine.config.required_preflight})
        evidence['inventory']=dict(verified=True,received_at=self.now,complete=True,managed_vm_count=1,project_id='p',
            active_identity=core.identity(self.active))
        evidence['options']=[dict(verified=True,received_at=self.now,country='DE',location='fsn1',available=True,compatible=True,
            provider='hetzner',project_id='p',owner_id='1',service_id='7001',currency='EUR',architecture='x86',config_hash='b'*64,
            monthly_gross_micro=5_000_000,trial_max_gross_micro=100_000,server_type='cx23',image='ubuntu-x86')]
        return detail,evidence

    def test_external_measurement_down_is_unknown_and_cannot_buy_after_three_hours(self):
        self.mode='measurement_down'
        for _ in range(182):
            detail,evidence=self.pipeline()
            self.assertIsNone(detail['tunnel_ok']);self.assertIsNone(detail['path_probe_ok'])
            self.assertIsNone(evidence['health']['tunnel_ok']);self.assertIsNone(evidence['health']['probe_ok'])
            self.assertEqual(self.engine._quality(evidence['health'],self.active,self.now),'unknown')
            state=self.engine.observe(evidence,self.now)
            self.assertEqual(self.engine.plan(state,evidence,self.now).kind,'HOLD')
            self.assertIsNone(state['incident_id'])
            self.now+=60
        with self.journal.transaction()as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM attempts').fetchone()[0],0)

    def test_invalid_measurement_or_missing_ct_receipt_is_unknown_not_bad(self):
        for mode in ('measurement_invalid','missing_ct'):
            self.mode=mode
            with self.subTest(mode=mode):
                detail,evidence=self.pipeline()
                self.assertIsNone(detail['tunnel_ok']);self.assertIsNone(detail['path_probe_ok'])
                self.assertEqual(self.engine._quality(evidence['health'],self.active,self.now),'unknown')
            self.now+=1

    def test_actual_ssh_tunnel_failure_stays_bad_and_can_reach_the_guarded_buy_gate(self):
        self.mode='tunnel_down'
        self.cfg['policy'].update(outage_seconds=180,suspect_seconds=60)
        self.engine=core.Engine(core.Config(**self.cfg['policy']),self.journal)
        for _ in range(4):
            detail,evidence=self.pipeline()
            self.assertIs(detail['tunnel_ok'],False);self.assertIs(detail['path_probe_ok'],False)
            self.assertEqual(self.engine._quality(evidence['health'],self.active,self.now),'bad')
            state=self.engine.observe(evidence,self.now);self.now+=60
        self.assertEqual(self.engine.plan(state,evidence,self.now-60).kind,'CREATE')

    def test_direct_target_tcp_failure_remains_bad_when_external_measurement_is_unknown(self):
        self.mode='measurement_down';self.target_tcp=False
        detail,evidence=self.pipeline()
        self.assertIsNone(detail['tunnel_ok']);self.assertIs(detail['path_probe_ok'],False)
        self.assertEqual(self.engine._quality(evidence['health'],self.active,self.now),'bad')

    def test_new_unknown_measurement_supersedes_old_confirmed_failure_and_blocks_purchase(self):
        self.cfg['policy'].update(outage_seconds=180,suspect_seconds=60)
        self.engine=core.Engine(core.Config(**self.cfg['policy']),self.journal)
        self.mode='tunnel_down'
        for _ in range(4):
            _,evidence=self.pipeline();state=self.engine.observe(evidence,self.now);self.now+=60
        self.assertEqual(self.engine.plan(state,evidence,self.now-60).kind,'CREATE')
        self.mode='measurement_down'
        _,current=self.pipeline()
        self.assertIsNone(current['health']['tunnel_ok'])
        self.assertIsNone(current['health']['probe_ok'])
        state=self.engine.observe(current,self.now)
        self.assertEqual(self.engine.plan(state,current,self.now).reason,'IR_UNREACHABLE_OR_STALE')
        with self.journal.transaction()as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM attempts').fetchone()[0],0)

    def test_successfully_measured_wrong_public_egress_is_definite_bad(self):
        self.mode='wrong_egress';detail,evidence=self.pipeline()
        self.assertIs(detail['tunnel_ok'],False);self.assertIs(detail['path_probe_ok'],False)
        self.assertEqual(self.engine._quality(evidence['health'],self.active,self.now),'bad')

    def test_broken_route_diagnostic_never_hides_a_definite_missing_ssh_socket(self):
        self.mode='tunnel_down';self.bad_route_diagnostic=True
        detail,evidence=self.pipeline()
        self.assertIs(detail['tunnel_ok'],False)
        self.assertIs(detail['path_probe_ok'],False)
        self.assertEqual(self.engine._quality(evidence['health'],self.active,self.now),'bad')

    def test_malformed_link_or_table_objects_are_unknown_instead_of_verified_absence(self):
        for name,body in [('link_output',b'[{}]'),('link_output',b'[{"ifname":"tun119"}]'),
                          ('table_output',b'[{}]'),('table_output',b'[{"dst":"default"}]')]:
            setattr(self,name,body)
            with self.subTest(name=name,body=body):
                detail,evidence=self.pipeline()
                self.assertIsNone(detail['tunnel_ok']);self.assertIsNone(detail['path_probe_ok'])
                self.assertEqual(self.engine._quality(evidence['health'],self.active,self.now),'unknown')
            setattr(self,name,None);self.now+=1

    def test_valid_empty_link_or_table_observation_still_proves_actual_failure(self):
        for name in ('link_output','table_output'):
            setattr(self,name,b'[]')
            with self.subTest(name=name):
                detail,evidence=self.pipeline()
                self.assertIs(detail['tunnel_ok'],False);self.assertIs(detail['path_probe_ok'],False)
                self.assertEqual(self.engine._quality(evidence['health'],self.active,self.now),'bad')
            setattr(self,name,None);self.now+=1


if __name__=='__main__':unittest.main()
