"""Offline cryptographic candidate-profile boundary; no guest or network calls."""
import base64
import copy
from pathlib import Path
import sys
import tempfile
import unittest

BASE=Path(__file__).resolve().parents[1];sys.path.insert(0,str(BASE))
import isolated_acceptance as mod
import protocol
import windows_probe_client as windows

NOW=1_800_000_000
KEY=b'guest-only-key-for-fixtures-12345!'


class IsolatedAcceptanceTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup)
        self.now=NOW;self.key=KEY[:32]
        self.replay=protocol.ReplayStore(Path(self.tmp.name)/'guest-replay.sqlite3')
        self.verifier=mod.IsolatedAcceptanceVerifier(self.key,self.replay,now=lambda:self.now)
        self.context=dict(schema=protocol.SCHEMA,agent_id='ir-agent',incident_id='incident-1',
            generation=2,revision=7,config_hash='a'*64,jobs_hash='b'*64,
            job=dict(id='candidate-accept-7',op='accept',target=dict(host='9.9.9.9',port=22,provider_id='51'),params=dict(
                acceptance_profile=mod.PROFILE,stage_profile='restored',restore_epoch=1,restore_hash='c'*64,
                expected_registry_hash='d'*64,dependency_baseline_sha256='e'*64,proxy_binding_sha256='f'*64,
                resource_identity=dict(provider='hetzner',project_id='project-1',provider_id='51',generation=2),
                resource_config_hash='a'*64)))
        self.recipe=self.receipt()

    def bound(self):
        c=self.context;p=c['job']['params']
        return dict(scope={k:c[k] for k in mod.SCOPE},target=c['job']['target'],stage_profile=p['stage_profile'],
            resource_identity=p['resource_identity'],resource_config_hash=p['resource_config_hash'],
            restore_epoch=p.get('restore_epoch'),restore_hash=p.get('restore_hash'),proxy_binding_sha256=p['proxy_binding_sha256'])

    def signed_guest(self,body,nonce='1'*32):
        raw=protocol.canonical_json(body)
        return dict(body_base64=base64.b64encode(raw).decode(),
            headers=windows.sign(self.key,'RESPONSE',raw,self.now,nonce),request_nonce=body['request_nonce'])

    def receipt(self):
        p=self.context['job']['params'];bound=self.bound()
        ct=dict(schema=mod.CT_SCHEMA,**bound,read_only=True,fresh_probe=True,historical_outcome=False,
                accepted=True,generation_verified=True,socks='127.0.0.1:10829',observed_at=self.now-1)
        registry=dict(schema=mod.REGISTRY_SCHEMA,scope=bound['scope'],target=bound['target'],read_only=True,
            coverage_complete=True,resource_count=6,registry_sha256=p['expected_registry_hash'],
            before_state_sha256=p['dependency_baseline_sha256'],after_state_sha256=p['dependency_baseline_sha256'],
            before_observed_at=self.now-2,after_observed_at=self.now)
        body=dict(schema=mod.GUEST_SCHEMA,path_profile=mod.PATH_PROFILE,**bound,
            registry_sha256=p['expected_registry_hash'],dependency_baseline_sha256=p['dependency_baseline_sha256'],
            execution_origin='guest',source_ip='10.10.10.50',egress_ip='9.9.9.9',
            observed_at=self.now-1,request_nonce='2'*32,ok=True,tunnel_ok=True,probe_ok=True)
        return dict(action='accept',read_only=True,ok=True,checks=dict(accepted=True,generation_verified=True),
            isolated_evidence=dict(schema=mod.SCHEMA,ct=ct,registry=registry,guest=self.signed_guest(body)))

    def guest_body(self):
        return protocol.decode_json(base64.b64decode(self.recipe['isolated_evidence']['guest']['body_base64']))

    def test_real_hmac_exact_profile_scope_and_digests_yield_only_isolated_proof(self):
        proof=self.verifier.verify(self.context,self.recipe)
        self.assertTrue(proof['verified']);self.assertEqual(mod.PATH_PROFILE,proof['path_profile'])
        self.assertFalse(proof['default_path_verified']);self.assertFalse(proof['global_cutover_performed'])
        self.assertEqual(NOW-1,proof['observed_at'])
        self.assertNotIn('critical_consumers',proof)
        self.assertNotIn(self.key.decode(),str(proof));self.assertNotIn('headers',proof)

    def test_ct_staged_or_accepted_booleans_do_not_supply_guest_and_registry_proofs(self):
        for evidence in (None,{'schema':mod.SCHEMA,'ct':{'staged':True},'registry':{},'guest':{}},
                         {**self.recipe['isolated_evidence'],'guest':{}}):
            with self.subTest(evidence=evidence):
                self.recipe['isolated_evidence']=evidence
                with self.assertRaises(protocol.ProtocolError):self.verifier.verify(self.context,self.recipe)

    def test_signed_default_path_cannot_be_relabelled_as_candidate_acceptance(self):
        body=self.guest_body();body.update(schema=windows.WIRE_SCHEMA,path_profile='default_path_v1')
        self.recipe['isolated_evidence']['guest']=self.signed_guest(body)
        with self.assertRaisesRegex(protocol.ProtocolError,'guest_candidate_scope'):
            self.verifier.verify(self.context,self.recipe)

    def test_historical_stage_never_becomes_fresh_even_with_current_signed_guest(self):
        for location in ('checks','ct'):
            value=copy.deepcopy(self.recipe)
            (value['checks'] if location=='checks' else value['isolated_evidence']['ct'])['historical_outcome']=True
            with self.subTest(location=location),self.assertRaises(protocol.ProtocolError):
                self.verifier.verify(self.context,value)

    def test_registry_digests_and_coverage_are_mandatory_and_exact(self):
        for field,bad in (('before_state_sha256','0'*64),('after_state_sha256','0'*64),
                          ('registry_sha256','0'*64),('coverage_complete',False),('resource_count',0)):
            value=copy.deepcopy(self.recipe);value['isolated_evidence']['registry'][field]=bad
            with self.subTest(field=field),self.assertRaisesRegex(protocol.ProtocolError,'dependency_digests'):
                self.verifier.verify(self.context,value)

    def test_registry_snapshot_must_bracket_actual_guest_and_ct_observations(self):
        self.recipe['isolated_evidence']['registry']['after_observed_at']=NOW-2
        with self.assertRaises(protocol.ProtocolError):self.verifier.verify(self.context,self.recipe)

    def test_restored_epoch_identity_proxy_and_guest_source_cannot_drift(self):
        for field,bad in (('restore_epoch',2),('restore_hash','0'*64),('proxy_binding_sha256','0'*64),
                          ('stage_profile','trial'),('source_ip','10.10.10.20'),('execution_origin','ct113'),
                          ('egress_ip','8.8.8.8')):
            body=self.guest_body();body[field]=bad
            value=copy.deepcopy(self.recipe);value['isolated_evidence']['guest']=self.signed_guest(body)
            with self.subTest(field=field),self.assertRaises(protocol.ProtocolError):
                self.verifier.verify(self.context,value)

    def test_invalid_signature_and_replay_are_denied(self):
        tampered=copy.deepcopy(self.recipe)
        tampered['isolated_evidence']['guest']['headers']['X-VM-Signature']='0'*64
        with self.assertRaisesRegex(protocol.ProtocolError,'authentication_failed'):
            self.verifier.verify(self.context,tampered)
        self.verifier.verify(self.context,self.recipe)
        with self.assertRaisesRegex(protocol.ProtocolError,'vm_replay'):
            self.verifier.verify(self.context,self.recipe)

    def test_stale_proofs_do_not_gain_current_timestamps(self):
        self.now+=31
        with self.assertRaises(protocol.ProtocolError):self.verifier.verify(self.context,self.recipe)

    def test_trial_profile_cannot_inherit_restored_epoch_or_wrong_path(self):
        context=copy.deepcopy(self.context);context['job']['params']['stage_profile']='trial'
        with self.assertRaisesRegex(protocol.ProtocolError,'trial_epoch'):mod.parameters(context)
        context['job']['op']='probe'
        with self.assertRaisesRegex(protocol.ProtocolError,'accept_profile'):mod.parameters(context)

    def test_nested_json_types_cannot_alias_bound_integer_identity(self):
        for section in ('ct','guest','registry'):
            value=copy.deepcopy(self.recipe)
            if section=='guest':
                body=self.guest_body();body['scope']['generation']=2.0
                value['isolated_evidence']['guest']=self.signed_guest(body)
            else:value['isolated_evidence'][section]['scope']['generation']=2.0
            with self.subTest(section=section),self.assertRaises(protocol.ProtocolError):
                self.verifier.verify(self.context,value)


if __name__=='__main__':unittest.main()
