"""Protected pipe -> signed gateway -> real agent -> guest -> one DNS verifier.

Only PHP process, SSH byte transport and DNS packets are fixture I/O. Metadata
presence never substitutes for the native authoritative SOA result.
"""
import copy
import hashlib
import json
from pathlib import Path
import tempfile
import unittest

from infra.relay_recovery import agent, controller_adapter as ca, gateway, iran_adapter, protocol
from infra.relay_recovery.tests import test_controller_adapter as controller
from infra.relay_recovery.tests.test_dns_guest_transport import GuestFixture

NOW=controller.NOW


class SignedDnsFlowTests(unittest.TestCase):
    def setUp(self):
        self.parent=controller.DnsHandoffPublisherTests();self.parent.setUp();self.addCleanup(self.parent.doCleanups)
        self.root=self.parent.root;self.cfg=self.parent.cfg;self.state=self.parent.state;self.backend=self.parent.fixture
        self.cfg['policy']['critical_consumers']=['dns']
        self.cfg['collector']['manifest_ttl']=180
        self.bundles=self.root/'bundles';self.bundles.mkdir(mode=0o700)
        bundle=dict(schema=iran_adapter.BUNDLE_SCHEMA,agent_id='ir-agent',candidate_ports=[22,443],recipes={})
        raw=protocol.canonical_json(bundle);self.bundle_hash=hashlib.sha256(raw).hexdigest()
        self.private(self.bundles/(self.bundle_hash+'.json'),raw)
        self.state['active']['config_hash']=self.bundle_hash
        self.state.update(candidate=None,retiring=None,incident_id=None,phase='HEALTHY')
        self.backend.binding['config_hash']=self.bundle_hash
        self.cfg['dns_ownership']=self.backend.pin('enrollment.json',self.backend.enrollment)
        self.parent.save_state(self.state)
        guest_dir=self.root/'guest-fixture';guest_dir.mkdir(mode=0o700)
        self.guest=GuestFixture(guest_dir,config_hash=self.bundle_hash)
        identity=dict(provider='hetzner',project_id='project-1',provider_id='51',generation=2)
        enrollment=self.guest.enrollment
        enrollment.update(binding=copy.deepcopy(self.backend.binding),source_identity=identity,
            primary=dict(ipv4='9.9.9.9',port=5300,provider_id='51',generation=2),
            ownership_enrollment_sha256=self.cfg['dns_ownership']['sha256'])
        self.guest.native_pin=self.guest.pin('native.json',protocol.canonical_json(enrollment))
        self.guest.transport_cfg.update(native_enrollment=self.guest.native_pin)
        self.guest.transport_cfg['guest']['config']=self.guest.native_pin
        self.guest.transport_pin=self.guest.pin('transport.json',protocol.canonical_json(self.guest.transport_cfg))
        self.controller=ca.Adapter(self.cfg,NOW,provider=controller.Provider(),runner=self.backend.runner,allow_test_paths=True)
        self.controller.clock=lambda:self.backend.now
        specs={}
        for name in ('host','ct113','vm109','dns'):
            executable=self.private(self.root/('fixed-'+name),b'fixture fixed executable');executable.chmod(0o700)
            specs[name]=dict(action=name,argv=[str(executable)],executable_sha256=hashlib.sha256(executable.read_bytes()).hexdigest(),inputs=[],timeout=8)
        # The SPEC pins actual local private configs; remote installed modules
        # and interpreter have independent guest pins in the transport config.
        specs['dns']['inputs']=[dict(self.guest.transport_pin),dict(self.guest.native_pin)]
        self.iran_calls=[];self.legacy_reject_dns=False;self.health_contexts={}
        ir_cfg=dict(agent_id='ir-agent',root_owned=False,manifest_dir=str(self.bundles),candidate_ports=[22,443],
            vm109_source_ip='192.168.10.109',probes={name:specs[name] for name in ('host','ct113','vm109')},
            consumer_probes={'dns':specs['dns']},recipes={})
        self.iran=iran_adapter.IranAdapter(ir_cfg,runner=self.native_runner,tcp=lambda *a:True,icmp=lambda *a:None,now=lambda:self.backend.now)
        pve=self.root/'agent';pve.mkdir(mode=0o700)
        self.requests=[];self.tamper_response=False;self.now=NOW
        config=dict(agent_id='ir-agent',key=b'k'*32,state_dir=str(pve),candidate_ports=[22,443],
            controller_url='https://controller.invalid/relay-recovery-agent.php',controller_path='/relay-recovery-agent.php',
            control_targets=[dict(host='control.invalid',port=443)],root_owned=False)
        self.remote=agent.Agent(config,transport=self.http,adapter=self.iran.run,tcp=lambda *a:True,icmp=lambda *a:None,monotonic=lambda:0)

    @staticmethod
    def private(path,raw):path.write_bytes(raw);path.chmod(0o600);return path

    def native_runner(self,argv,body,timeout):
        name=Path(argv[0]).name.removeprefix('fixed-');context=protocol.decode_json(body);self.iran_calls.append(name)
        if name=='dns':
            result=self.guest.transport().run(context)
            return (0 if result['ok'] else 1),protocol.canonical_json(result)
        if self.legacy_reject_dns:
            # The deployed finite legacy child grammar has no DNS namespace.
            if 'dns_ownership' in context['job'].get('params',{}):
                raise protocol.ProtocolError('adapter_params')
            self.health_contexts[name]=context
        common=dict(schema=iran_adapter.PROBE_SCHEMA,probe_kind=name,read_only=True,ok=True,
                    target=context['job']['target'],**{key:context[key] for key in iran_adapter.SCOPE})
        checks=(dict(host_healthy=True,direct_control=True,internet_healthy=True) if name=='host' else
                dict(execution_origin='ct113' if name=='ct113' else 'guest',tunnel_ok=True,probe_ok=True,
                     egress_ip=context['job']['target']['host'],source_ip='192.168.10.109'))
        if name=='storage':checks=dict(consumer='storage',probe_ok=True)
        return 0,protocol.canonical_json(dict(common,checks=checks))

    def http(self,url,body,headers):
        self.requests.append((body,dict(headers)))
        result=gateway.handle(dict(method='POST',path='/relay-recovery-agent.php',body=body.decode(),headers=headers),self.cfg,self.now)
        raw=result['body'].encode()
        if self.tamper_response:raw+=b' '
        return result['status'],result['headers'],raw

    def deliver(self):
        manifest=self.controller.publish(self.state,'ir-agent')
        self.remote.run_once(self.now)
        self.now+=1;self.backend.now=self.now;self.guest.now=self.now
        self.remote.run_once(self.now)
        evidence=ca.Adapter(self.cfg,self.now+1,provider=controller.Provider(),runner=self.backend.runner,
            allow_test_paths=True).collect({'state':self.state})['evidence']
        return manifest,evidence

    def test_actual_single_signed_route_reaches_guest_measurement_and_collector(self):
        manifest,evidence=self.deliver()
        self.assertEqual(len(self.backend.calls),2) # first source read + next collector refresh (no stale source fallback)
        self.assertTrue(evidence['critical_receipts'][0]['ok'])
        self.assertEqual(evidence['critical_receipts'][0]['consumer'],'dns')
        self.assertEqual(len(self.guest.ssh_calls),1)
        self.assertEqual(len(self.guest.dns_calls),8)
        self.assertEqual(self.iran_calls.count('dns'),1)
        payload=protocol.decode_json(self.guest.ssh_calls[0][1])
        handoff=payload['job']['params']['dns_ownership']
        self.assertEqual(handoff['artifact']['observed_at'],NOW)
        self.assertEqual(handoff['artifact']['expires_at'],NOW+900)
        self.assertEqual(payload['jobs_hash'],manifest['jobs_hash'])
        # Ordinary request nonce is consumed once at the gateway. The typed
        # guest verifier adds no second consumer of this same signed envelope.
        raw,headers=self.requests[0]
        replay=gateway.handle(dict(method='POST',path='/relay-recovery-agent.php',body=raw.decode(),headers=headers),self.cfg,NOW)
        self.assertEqual(replay['status'],403)
        self.assertFalse(evidence['preflight']['ready'])

    def test_real_primary_failure_keeps_dns_false_despite_protected_metadata(self):
        self.guest.fail_query=True
        _,evidence=self.deliver()
        self.assertFalse(evidence['critical_receipts'][0]['ok'])
        self.assertTrue(self.guest.dns_calls)

    def test_tampered_response_hmac_never_reaches_adapter_or_guest(self):
        self.controller.publish(self.state,'ir-agent');self.tamper_response=True
        with self.assertRaisesRegex(protocol.ProtocolError,'authentication_failed'):
            self.remote.run_once(self.now)
        self.assertEqual(self.iran_calls,[]);self.assertEqual(self.guest.ssh_calls,[]);self.assertEqual(self.guest.dns_calls,[])

    def test_no_protected_source_does_not_reuse_previous_owner_artifact(self):
        self.backend.responses=[b'failed protected read']
        manifest,evidence=self.deliver()
        self.assertNotIn('dns_ownership',manifest['jobs'][0]['params'])
        self.assertFalse(evidence['critical_receipts'][0]['ok'])
        self.assertEqual(self.guest.ssh_calls,[]);self.assertEqual(self.guest.dns_calls,[])

    def test_native_clock_expiry_cannot_be_renewed_by_signed_delivery(self):
        self.guest.now=NOW+900
        manifest=self.controller.publish(self.state,'ir-agent');self.remote.run_once(self.now)
        report=protocol.decode_json(self.remote.report_path.read_bytes())
        self.assertFalse(report['jobs'][0]['checks']['adapter']['critical_consumers']['dns'])
        self.assertEqual(self.guest.ssh_calls,[]);self.assertEqual(self.guest.dns_calls,[])
        self.assertEqual(manifest['jobs'][0]['params']['dns_ownership']['artifact']['observed_at'],NOW)

    def test_signed_dns_data_does_not_regress_existing_non_dns_child_grammar(self):
        self.legacy_reject_dns=True
        _,evidence=self.deliver()
        self.assertTrue(evidence['critical_receipts'][0]['ok'])
        full=protocol.decode_json(self.guest.ssh_calls[0][1])
        expected=copy.deepcopy(full);expected['job']['params'].pop('dns_ownership')
        self.assertEqual(set(self.health_contexts),{'host','ct113','vm109'})
        self.assertTrue(all(value==expected for value in self.health_contexts.values()))
        self.assertIn('dns_ownership',full['job']['params'])

    def test_projection_preserves_optional_deadline_and_does_not_mutate_full_input(self):
        self.legacy_reject_dns=True
        storage=self.private(self.root/'fixed-storage',b'fixed existing consumer');storage.chmod(0o700)
        self.iran.config['consumer_probes']['storage']=dict(action='storage',argv=[str(storage)],
            executable_sha256=hashlib.sha256(storage.read_bytes()).hexdigest(),inputs=[],timeout=8)
        manifest=self.controller.publish(self.state,'ir-agent')
        context=dict(schema=protocol.SCHEMA,agent_id='ir-agent',job=copy.deepcopy(manifest['jobs'][0]),
                     **{key:manifest[key] for key in ('incident_id','generation','revision','config_hash','jobs_hash')})
        context['job']['params']['expires_at']=NOW+20
        original=protocol.canonical_json(context)
        self.assertTrue(self.iran.run('probe',context)['ok'])
        self.assertEqual(protocol.canonical_json(context),original)
        expected=copy.deepcopy(context);expected['job']['params'].pop('dns_ownership')
        self.assertEqual(set(self.health_contexts),{'host','ct113','vm109','storage'})
        self.assertTrue(all(value==expected for value in self.health_contexts.values()))
        full=protocol.decode_json(self.guest.ssh_calls[0][1])
        self.assertEqual(full,context);self.assertEqual(full['job']['params']['expires_at'],NOW+20)

    def test_projection_cannot_hide_invalid_dns_data_from_initial_validation(self):
        manifest=self.controller.publish(self.state,'ir-agent')
        context=dict(schema=protocol.SCHEMA,agent_id='ir-agent',job=copy.deepcopy(manifest['jobs'][0]),
                     **{key:manifest[key] for key in ('incident_id','generation','revision','config_hash','jobs_hash')})
        context['job']['params']['dns_ownership']['source_receipt_sha256']='0'*64
        with self.assertRaises(protocol.ProtocolError):self.iran.run('probe',context)
        self.assertEqual(self.iran_calls,[]);self.assertEqual(self.guest.ssh_calls,[])


if __name__=='__main__':unittest.main()
