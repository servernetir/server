"""Controller/gateway binding tests: no live API or process execution."""
import copy
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import tempfile
import unittest
import uuid
from unittest.mock import patch

from infra.relay_recovery import controller_adapter as ca
from infra.relay_recovery import gateway, protocol
from infra.relay_recovery.core import identity


NOW = 1_800_000_000


class Provider:
    def quotes(self): return []
    def inventory(self, active):
        return {'verified': True, 'received_at': NOW, 'complete': True,
                'managed_vm_count': 1, 'project_id': 'example-project', 'active_identity': active}
    def execute(self, action, payload):
        return {'schema': 'servernet.relay.effect.v1', 'receipt': {'kind': action}}


class CollectorTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name); self.root.chmod(0o700)
        self.key = self.root/'key'; self.key.write_bytes(b'k'*32); self.key.chmod(0o600)
        self.cfg = {'state_dir': str(self.root),
            'agents': {'ir-agent': {'enabled': True, 'key_file': str(self.key)}},
            'policy': {'enabled': False, 'owner_id': 'example-owner',
                'service_id': 'example-service', 'project_id': 'example-project'},
            'binding': {'owner_id': 'example-owner', 'service_id': 'example-service',
                        'ipv4': '8.8.8.8'}, 'collector': {'recipes': {}}}
        self.active = {'provider': 'hetzner', 'project_id': 'example-project',
                       'provider_id': '123', 'generation': 1, 'config_hash': 'a'*64}
        self.cfg['active'] = self.active
        self.state = {'active': self.active, 'candidate': None, 'retiring': None, 'incident_id': None}
        self.recipe_results = {}
        self.calls = []

    def adapter(self, now=NOW):
        return ca.Adapter(self.cfg, now, provider=Provider(), runner=self.runner)

    def runner(self, argv, raw, timeout):
        request = json.loads(raw)
        self.calls.append(request)
        result = self.recipe_results.get(request['kind'])
        return (0, protocol.canonical_json(result)) if result else (1, b'{}')

    def recipe(self, name, **values):
        path = self.root/name
        path.write_text('pinned local test recipe'); path.chmod(0o700)
        self.cfg['collector']['recipes'][name] = {'path': str(path),
                         'sha256': hashlib.sha256(path.read_bytes()).hexdigest()}
        self.recipe_results[name] = {'schema': 'servernet.relay.collector.v1',
             'kind': name, 'ok': True, 'observed_at': NOW, **values}

    def report(self, manifest, *, now=NOW+1, observed=None, ok=True,
               detail=None, mutate=None, job_index=0):
        detail = detail if detail is not None else {'host_healthy': True,
            'internet_healthy': True, 'tunnel_ok': True, 'path_probe_ok': True,
            'critical_consumers': {'proxmox': True, 'vm109': True, 'vpn': True,
                                   'dns': True, 'storage': True}}
        receipt = {k: manifest[k] for k in ('incident_id', 'generation', 'revision',
                                            'config_hash', 'jobs_hash')}
        receipt.update(job_id=manifest['jobs'][job_index]['id'], op='probe', ok=ok,
                       observed_at=now if observed is None else observed,
                       checks={'candidate_tcp': True, 'adapter': detail})
        if mutate: receipt.update(mutate)
        report = {'agent_id': 'ir-agent', 'observed_at': now,
                  'direct_control_tcp': [True], 'jobs': [receipt]}
        body = protocol.canonical_json({'agent_id': 'ir-agent', 'op': 'poll', 'report': report})
        headers = protocol.sign_message(b'k'*32, 'ir-agent', 'POST',
            '/relay-recovery-agent.php', body, now)
        result = gateway.handle({'method': 'POST', 'path': '/relay-recovery-agent.php',
                                 'body': body.decode(), 'headers': headers}, self.cfg, now)
        self.assertEqual(result['status'], 200)
        return receipt

    def collect(self, now=NOW+2):
        return self.adapter(now).collect({'state': self.state})['evidence']

    def test_first_tick_publishes_probe_but_never_claims_missing_integrations_ready(self):
        evidence = self.collect(NOW)
        manifest = json.loads((self.root/'manifest-ir-agent.json').read_text())
        self.assertEqual([j['op'] for j in manifest['jobs']], ['probe'])
        self.assertFalse(evidence['preflight']['ready'])
        self.assertNotIn('health', evidence)
        self.assertFalse(evidence['preflight']['checks']['owner_bound'])

    def test_authenticated_report_maps_exact_published_job_to_active(self):
        obj = self.adapter()
        m = obj.publish(self.state, 'ir-agent')
        self.report(m)
        self.recipe('independent_control', relay_independent=True)
        evidence = self.collect()
        self.assertTrue(evidence['health']['direct_control'])
        self.assertTrue(evidence['health']['tunnel_ok'])
        self.assertEqual(evidence['health']['generation'], 1)
        self.assertEqual(evidence['health']['received_at'], NOW+1)
        self.assertEqual(evidence['health']['observed_at'], NOW+1)
        self.assertTrue(all(r['ok'] for r in evidence['critical_receipts']))

    def test_unpublished_or_tampered_receipt_cannot_create_health(self):
        m = self.adapter().publish(self.state, 'ir-agent')
        for changed in ({'job_id': 'unknown'}, {'config_hash': 'b'*64},
                        {'generation': 2}, {'jobs_hash': 'b'*64}, {'revision': True}):
            self.report(m, mutate=changed)
            self.assertNotIn('health', self.collect())

    def test_poll_of_old_success_preserves_observation_and_receiver_times(self):
        m = self.adapter().publish(self.state, 'ir-agent')
        self.report(m)
        self.collect()
        # Re-delivery has a fresh gateway acceptance but original job observed_at.
        self.report(m, now=NOW+20, observed=NOW+1)
        evidence = self.collect(NOW+21)
        self.assertEqual(evidence['health']['received_at'], NOW+1)
        self.assertEqual(evidence['health']['observed_at'], NOW+1)
        self.report(m, now=NOW+100, observed=NOW+1)
        self.assertNotIn('health', self.collect(NOW+101))

    def test_stale_and_future_remote_observation_are_not_promoted_to_fresh(self):
        m = self.adapter().publish(self.state, 'ir-agent')
        for observed in (NOW-100, NOW+50):
            self.report(m, observed=observed)
            self.assertNotIn('health', self.collect())

    def test_tcp_only_receipt_is_not_full_health_and_missing_consumers_are_false(self):
        m = self.adapter().publish(self.state, 'ir-agent')
        self.report(m, detail={})
        evidence = self.collect()
        self.assertIsNone(evidence['health']['tunnel_ok'])
        self.assertIsNone(evidence['health']['host_healthy'])
        self.assertEqual(len(evidence['critical_receipts']), 5)
        self.assertFalse(any(r['ok'] for r in evidence['critical_receipts']))

    def test_failure_receipt_cannot_reuse_successful_checks(self):
        m = self.adapter().publish(self.state, 'ir-agent')
        self.report(m, ok=False, detail={'host_healthy':True,'internet_healthy':True,
            'tunnel_ok':False,'path_probe_ok':False})
        evidence = self.collect()
        self.assertFalse(evidence['health']['probe_ok'])
        self.assertFalse(any(r['ok'] for r in evidence['critical_receipts']))

    def test_older_success_retransmission_does_not_mask_later_failure(self):
        m = self.adapter().publish(self.state, 'ir-agent')
        self.report(m, observed=NOW+1); self.collect()
        self.report(m, now=NOW+5, observed=NOW+5, ok=False,
                    detail={'path_probe_ok':False,'tunnel_ok':False}); self.collect(NOW+6)
        self.report(m, now=NOW+8, observed=NOW+1)
        self.assertFalse(self.collect(NOW+9)['health']['probe_ok'])

    def test_one_monotonic_manifest_probes_active_and_candidate(self):
        candidate = {**self.active, 'provider_id': '456', 'generation': 2,
                     'config_hash': 'b'*64, 'ipv4': '1.1.1.1'}
        self.state.update(candidate=candidate, incident_id='incident-test')
        a = self.adapter(); first = a.publish(self.state, 'ir-agent')
        second = self.adapter().publish(self.state, 'ir-agent')
        self.assertEqual((first['generation'], second['generation']), (2, 2))
        self.assertGreater(second['revision'], first['revision'])
        self.assertEqual([j['params']['resource_identity']['generation'] for j in first['jobs']], [1, 2])
        self.assertEqual([j['target']['provider_id'] for j in first['jobs']], ['123', '456'])
        self.assertTrue(all(j['op'] == 'probe' for j in first['jobs']))

    def test_wrong_recipe_hash_or_unscoped_backup_does_not_satisfy_preflight(self):
        self.recipe('independent_control', relay_independent=True)
        self.cfg['collector']['recipes']['independent_control']['sha256'] = '0'*64
        self.recipe('backup', durable=True, restore_verified=True, checkpoint='example',
                    identity={**identity(self.active), 'generation': 99},
                    config_hash=self.active['config_hash'], incident_id=None)
        e = self.collect()
        self.assertFalse(e['preflight']['checks']['independent_control'])
        self.assertFalse(e['preflight']['checks']['restore_current'])
        self.assertNotIn('backup', e)

    def test_failed_candidate_removal_never_rolls_back_agent_manifest_cursor(self):
        journal = __import__('infra.relay_recovery.agent', fromlist=['AgentJournal']).AgentJournal(self.root/'agent.sqlite3')
        initial = self.adapter().publish(self.state, 'ir-agent')
        journal.accept_manifest('ir-agent', initial, NOW)
        self.state['candidate'] = {**self.active, 'provider_id': '456', 'generation': 2,
                                   'config_hash': 'b'*64, 'ipv4': '1.1.1.1'}
        trial = self.adapter().publish(self.state, 'ir-agent')
        journal.accept_manifest('ir-agent', trial, NOW)
        self.state['candidate'] = None
        resumed = self.adapter().publish(self.state, 'ir-agent')
        journal.accept_manifest('ir-agent', resumed, NOW)
        self.assertGreater(resumed['generation'], trial['generation'])
        self.assertEqual(resumed['jobs'][0]['params']['resource_identity']['generation'], 1)

    def test_disabled_agent_cannot_inject_evidence_or_receive_manifest(self):
        self.cfg['agents']['ir-agent']['enabled'] = False
        e = self.collect()
        self.assertNotIn('health', e)
        self.assertFalse((self.root/'manifest-ir-agent.json').exists())

    def test_private_database_symlink_is_rejected(self):
        external = self.root/'external'; external.write_text('private'); external.chmod(0o600)
        (self.root/'controller-evidence.sqlite3').symlink_to(external)
        with self.assertRaises(ca.CollectorError): self.adapter()

    def test_uncommitted_promotion_is_never_delegated_to_recipe(self):
        from infra.relay_recovery.core import Journal
        Journal(self.root/'controller.sqlite3').initialize(self.active, NOW)
        with self.assertRaisesRegex(ca.CollectorError, 'uncommitted_promotion'):
            self.adapter().effect('PROMOTE', {'intent': {'id': 'not-authorized'}})
        self.assertEqual(self.calls, [])

    def health_poll(self, now):
        report = {'agent_id': 'ir-agent', 'observed_at': now,
                  'direct_control_tcp': [True], 'jobs': []}
        body = protocol.canonical_json({'agent_id': 'ir-agent', 'op': 'poll', 'report': report})
        headers = protocol.sign_message(b'k'*32, 'ir-agent', 'POST', '/relay-recovery-agent.php', body, now)
        response = gateway.handle({'method': 'POST', 'path': '/relay-recovery-agent.php',
                                   'body': body.decode(), 'headers': headers}, self.cfg, now)
        self.assertEqual(response['status'], 200)

    def test_acknowledged_inbox_receipts_survive_latest_health_only_poll(self):
        manifest = self.adapter().publish(self.state, 'ir-agent')
        self.report(manifest, now=NOW+1)
        self.report(manifest, now=NOW+2, ok=False,
                    detail={'path_probe_ok':False,'tunnel_ok':False})
        self.health_poll(NOW+3)
        evidence = self.collect(NOW+4)
        self.assertFalse(evidence['health']['probe_ok'])
        with sqlite3.connect(self.root/'controller-evidence.sqlite3') as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM receipts').fetchone()[0], 2)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM ingested_reports').fetchone()[0], 2)
        with sqlite3.connect(self.root/'agent-reports.sqlite3') as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM inbox WHERE consumed_at IS NULL').fetchone()[0], 0)

    def test_crash_after_evidence_commit_before_consumption_is_idempotent(self):
        manifest = self.adapter().publish(self.state, 'ir-agent')
        self.report(manifest)
        adapter = self.adapter(NOW+2)
        with patch.object(adapter, '_mark_consumed', side_effect=RuntimeError('simulated crash')):
            with self.assertRaises(RuntimeError): adapter._ingest('ir-agent')
        with sqlite3.connect(self.root/'controller-evidence.sqlite3') as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM receipts').fetchone()[0], 1)
        with sqlite3.connect(self.root/'agent-reports.sqlite3') as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM inbox WHERE consumed_at IS NULL').fetchone()[0], 1)
        self.adapter(NOW+3)._ingest('ir-agent')
        with sqlite3.connect(self.root/'controller-evidence.sqlite3') as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM receipts').fetchone()[0], 1)
            self.assertEqual(db.execute('SELECT received_at FROM receipts').fetchone()[0], NOW+1)
        with sqlite3.connect(self.root/'agent-reports.sqlite3') as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM inbox WHERE consumed_at IS NULL').fetchone()[0], 0)

    def test_delayed_inbox_is_consumed_durably_but_never_becomes_fresh_health(self):
        manifest = self.adapter().publish(self.state, 'ir-agent')
        self.report(manifest)
        self.health_poll(NOW+100)
        self.assertNotIn('health', self.collect(NOW+101))
        with sqlite3.connect(self.root/'controller-evidence.sqlite3') as db:
            self.assertEqual(db.execute('SELECT received_at FROM receipts').fetchone()[0], NOW+1)
        with sqlite3.connect(self.root/'agent-reports.sqlite3') as db:
            self.assertIsNotNone(db.execute('SELECT consumed_at FROM inbox').fetchone()[0])

    def test_production_provider_uses_advancing_default_clock(self):
        adapter = ca.Adapter(self.cfg)
        with patch('infra.relay_recovery.provider_adapter.ProviderEffects') as constructor:
            adapter._provider()
            constructor.assert_called_once_with(self.cfg)

    def test_fencing_capability_must_be_explicit_fresh_and_bound_to_active(self):
        # A real fence receipt or a recipe file alone does not establish the
        # separately required read-only enforcement readiness before CREATE.
        self.recipe('fencing', identity=identity(self.active),
                    config_hash=self.active['config_hash'], incident_id=None,
                    old_generation=1, verified_fenced=True)
        self.assertFalse(self.collect()['preflight']['checks']['fencing_enforced'])
        self.recipe('fencing_capability', identity=identity(self.active),
                    config_hash=self.active['config_hash'], incident_id=None)
        self.assertFalse(self.collect()['preflight']['checks']['fencing_enforced'])
        result = self.recipe_results['fencing_capability']
        result.update(enforcement_ready=True, capability_verified=True)
        for mutation in ({'identity':{**identity(self.active),'generation':2}},
                         {'config_hash':'b'*64}, {'incident_id':'another-incident'},
                         {'observed_at':NOW-100}, {'enforcement_ready':'true'},
                         {'capability_verified':False}):
            saved = copy.deepcopy(result)
            result.update(mutation)
            self.assertFalse(self.collect()['preflight']['checks']['fencing_enforced'])
            result.clear(); result.update(saved)
        self.assertTrue(self.collect()['preflight']['checks']['fencing_enforced'])

    def test_preflight_can_be_ready_before_old_fenced_but_promotion_remains_blocked(self):
        from infra.relay_recovery import core
        self.cfg['policy']['enabled'] = True
        self.recipe('independent_control', relay_independent=True)
        self.recipe('owner_binding', **{k:self.cfg['policy'][k]
                                       for k in ('owner_id','service_id','project_id')})
        self.recipe('budget_authorization', currency='EUR',
                    policy_sha256=hashlib.sha256(protocol.canonical_json(self.cfg['policy'])).hexdigest())
        self.recipe('cutover_readiness', full_promotion_verified=True)
        self.recipe('cutover')  # only its local executable pin is inspected
        self.recipe('fencing_capability', identity=identity(self.active),
                    config_hash=self.active['config_hash'], incident_id=None,
                    enforcement_ready=True, capability_verified=True)
        self.recipe('backup', identity=identity(self.active),
                    config_hash=self.active['config_hash'], incident_id=None,
                    checkpoint='restored-checkpoint', durable=True, restore_verified=True)
        self.recipe('registry', identity=identity(self.active),
                    config_hash=self.active['config_hash'], incident_id=None,
                    active_refs={'complete':True,'count':0}, ttl={'known':True,'expires_at':NOW})
        class ReadyProvider(Provider):
            def inventory(inner, active):
                return {**super().inventory(active),'provider_access':True}
        def collect(state):
            return ca.Adapter(self.cfg,NOW,provider=ReadyProvider(),runner=self.runner).collect({'state':state})['evidence']
        before = collect(self.state)
        self.assertTrue(before['preflight']['ready'])
        self.assertNotIn('fencing',before)
        self.assertNotIn('verified_fenced',before['fencing_capability'])
        journal = core.Journal(self.root/'controller.sqlite3'); journal.initialize(self.active,NOW)
        engine = core.Engine(core.Config(**self.cfg['policy']),journal)
        candidate = {**self.active,'provider_id':'456','generation':2,'config_hash':'b'*64,'created_at':NOW-1300}
        with journal.transaction() as db:
            state = journal._read(db)
            state.update(candidate=candidate,phase='VALIDATING',incident_id='incident-test',
                         candidate_health={'quality':'ok','healthy_since':NOW-1300})
            journal._write(db,state)
        state = journal.snapshot()
        for name in ('backup','registry'):
            self.recipe_results[name].update(identity=identity(candidate),
                config_hash=candidate['config_hash'],incident_id=state['incident_id'])
        self.recipe_results['fencing_capability']['incident_id'] = state['incident_id']
        evidence = collect(state)
        self.assertTrue(evidence['preflight']['ready'])
        base = {'verified':True,'received_at':NOW,'incident_id':state['incident_id'],
                'generation':2,'config_hash':candidate['config_hash']}
        evidence['candidate_health'] = {**base,'agent_id':'ir-agent','direct_control':True,
            'host_healthy':True,'internet_healthy':True,'tunnel_ok':True,'probe_ok':True}
        evidence['critical_receipts'] = [{**base,'consumer':name,'ok':True}
                                        for name in engine.config.critical_consumers]
        self.assertTrue(engine._preflight_gate(evidence,NOW))
        self.assertTrue(engine._all_critical(state,candidate,evidence,NOW))
        self.assertFalse(engine._data_gate(state,candidate,evidence,NOW))
        intent = engine.plan(state,evidence,NOW)
        self.assertEqual((intent.kind,intent.reason),('HOLD','CANDIDATE_VALIDATION'))

    def healthy_ct_without_guest(self, manifest, job_index=0):
        """Exercise the real Iran adapter's path/consumer split, then send its
        result through the authenticated gateway into the collector."""
        from infra.relay_recovery.iran_adapter import IranAdapter
        config = {'root_owned':False, 'vm109_source_ip':'10.10.10.50',
                  'probes':{'host':{},'ct113':{},'vm109':{}},
                  'consumer_probes':{'dns':{},'storage':{}}}
        adapter = IranAdapter(config,tcp=lambda *_:True,icmp=lambda *_:None)
        context = {k:manifest[k] for k in ('incident_id','generation','revision','config_hash')}
        context['job'] = manifest['jobs'][job_index]
        def local_probe(spec, name, context, *, probe):
            if name == 'host':
                return {'ok':True,'checks':{'host_healthy':True,'direct_control':True,'internet_healthy':True}}
            if name == 'ct113':
                return {'ok':True,'checks':{'execution_origin':'ct113','tunnel_ok':True,
                        'probe_ok':True,'egress_ip':context['job']['target']['host']}}
            if name == 'vm109': return {'ok':False,'checks':{}}
            return {'ok':True,'checks':{'consumer':name,'probe_ok':True}}
        with patch.object(adapter,'_run',side_effect=local_probe):
            ok, detail = adapter._probe(context)
        self.assertTrue(ok)
        self.assertTrue(detail['path_probe_ok'])
        self.assertFalse(detail['critical_consumers']['vm109'])
        self.report(manifest,ok=ok,detail=detail,job_index=job_index)

    def test_real_ct_path_healthy_with_guest_absent_never_triggers_buy_after_three_hours(self):
        from infra.relay_recovery import core
        self.recipe('independent_control',relay_independent=True)
        manifest = self.adapter().publish(self.state,'ir-agent')
        self.healthy_ct_without_guest(manifest)
        evidence = self.collect()
        self.assertTrue(evidence['health']['probe_ok'])
        self.assertTrue(evidence['health']['tunnel_ok'])
        self.assertFalse(next(r for r in evidence['critical_receipts'] if r['consumer']=='vm109')['ok'])
        config = core.Config(**{**self.cfg['policy'],'enabled':True})
        journal = core.Journal(self.root/'controller.sqlite3'); journal.initialize(self.active,NOW)
        engine = core.Engine(config,journal)
        for at in range(NOW+2,NOW+10863,60):
            current = copy.deepcopy(evidence)
            current['health'].update(received_at=at,observed_at=at)
            state = engine.observe(current,at)
            intent = engine.plan(state,current,at)
            self.assertEqual((intent.kind,intent.reason),('HOLD','HEALTHY'))
        self.assertEqual(state['health']['quality'],'ok')
        self.assertNotIn('failure_started_at',state['health'])

    def test_real_ct_guest_split_keeps_candidate_vm109_gate_closed(self):
        from infra.relay_recovery import core
        self.recipe('independent_control',relay_independent=True)
        config = core.Config(**{**self.cfg['policy'],'enabled':True})
        journal = core.Journal(self.root/'controller.sqlite3'); journal.initialize(self.active,NOW)
        engine = core.Engine(config,journal)
        candidate = {**self.active,'provider_id':'456','generation':2,'config_hash':'b'*64,
                     'created_at':NOW-1300,'ipv4':'1.1.1.1'}
        with journal.transaction() as db:
            state = journal._read(db)
            state.update(candidate=candidate,phase='VALIDATING',incident_id='incident-test',
                         candidate_health={'quality':'ok','healthy_since':NOW-1300})
            journal._write(db,state)
        state = journal.snapshot()
        manifest = self.adapter().publish(state,'ir-agent')
        self.healthy_ct_without_guest(manifest,job_index=1)
        evidence = self.adapter(NOW+2).collect({'state':state})['evidence']
        self.assertEqual(engine._quality(evidence['candidate_health'],candidate,NOW+2),'ok')
        base = {'verified':True,'received_at':NOW+2,'incident_id':state['incident_id'],
                'generation':2,'config_hash':candidate['config_hash']}
        evidence['backup'] = {**base,'durable':True,'restore_verified':True,'checkpoint':'checkpoint'}
        evidence['fencing'] = {**base,'old_generation':1,'verified_fenced':True}
        evidence['preflight'] = {'verified':True,'received_at':NOW+2,'ready':True,
            'owner_id':config.owner_id,'service_id':config.service_id,'project_id':config.project_id,
            'checks':{k:True for k in config.required_preflight}}
        self.assertTrue(engine._data_gate(state,candidate,evidence,NOW+2))
        self.assertFalse(engine._all_critical(state,candidate,evidence,NOW+2))
        intent = engine.plan(state,evidence,NOW+2)
        self.assertEqual((intent.kind,intent.reason),('HOLD','CANDIDATE_VALIDATION'))
        # The isolated blocker is VM109 acceptance, not network quality.
        next(r for r in evidence['critical_receipts'] if r['consumer']=='vm109')['ok'] = True
        self.assertEqual(engine.plan(state,evidence,NOW+2).kind,'PROMOTE')

    def test_legacy_guest_combined_failure_without_explicit_path_is_unknown(self):
        manifest = self.adapter().publish(self.state,'ir-agent')
        self.report(manifest,ok=False,detail={'host_healthy':True,'internet_healthy':True,
            'tunnel_ok':False,'critical_consumers':{'vm109':False}})
        evidence = self.collect()
        self.assertIsNone(evidence['health']['probe_ok'])
        self.assertIsNone(evidence['health']['tunnel_ok'])

    def candidate_state(self, *, epoch=None, recorded=NOW+10):
        candidate = {**self.active, 'provider_id':'456', 'generation':2,
                     'config_hash':'b'*64, 'ipv4':'1.1.1.1', 'created_at':NOW}
        if epoch is not None:
            candidate.update(restore_epoch=epoch, restore_hash='d'*64, restore_recorded_at=recorded)
        return {'active':self.active, 'candidate':candidate, 'retiring':None, 'incident_id':'incident-test'}

    def test_committed_restore_epoch_rejects_old_green_then_accepts_only_new_signed_jobs(self):
        from infra.relay_recovery import core
        self.cfg['policy']['enabled'] = True
        config = core.Config(**self.cfg['policy'])
        journal = core.Journal(self.root/'controller.sqlite3'); journal.initialize(self.active,NOW)
        engine = core.Engine(config,journal)
        with journal.transaction() as db:
            state = journal._read(db); state.update(self.candidate_state(),phase='VALIDATING')
            journal._write(db,state)
        self.state = engine.snapshot()
        self.recipe('independent_control',relay_independent=True)
        before = self.adapter().publish(self.state,'ir-agent')
        self.report(before,job_index=1)
        old_evidence = self.collect()
        engine.observe(old_evidence,NOW+2)
        self.assertEqual(engine.snapshot()['candidate_health']['quality'],'ok')
        candidate = engine.snapshot()['candidate']
        scoped = dict(verified=True,received_at=NOW+10,incident_id=self.state['incident_id'],
                      generation=2,config_hash=candidate['config_hash'],identity=identity(candidate),
                      source_identity=identity(self.active),workflow_id=str(uuid.uuid4()),workflow_committed=True)
        engine.begin_restore({**scoped,'purpose':'candidate_restore','expected_restore_epoch':1},NOW+10)
        engine.record_restore_epoch({**scoped,'snapshot_id':'123456','restore_epoch':1,'restore_hash':'d'*64,
            'snapshot_available':True,'source_fenced':True,'boot_verified':True,'quarantine_verified':True,
            'machine_id_sha256':'e'*64,'boot_id_sha256':'f'*64},NOW+10)
        self.state = engine.snapshot()
        old = self.collect(NOW+11)
        self.assertNotIn('candidate_health',old)
        self.assertEqual(engine._quality(old.get('candidate_health'),self.state['candidate'],NOW+11),'unknown')
        self.assertFalse(engine._all_critical(self.state,self.state['candidate'],old,NOW+11))
        # Each later observation comes from a newly persisted exact manifest and
        # an authenticated gateway report, not a relabelled stored success.
        for minute in range(21):
            at = NOW+20+minute*60
            manifest = self.adapter(at).publish(self.state,'ir-agent')
            active_params, candidate_params = [job['params'] for job in manifest['jobs']]
            self.assertNotIn('restore_epoch',active_params)
            self.assertEqual((candidate_params['restore_epoch'],candidate_params['restore_hash']),(1,'d'*64))
            self.report(manifest,now=at+1,job_index=1)
            self.recipe_results['independent_control']['observed_at'] = at+2
            current = self.collect(at+2)
            self.assertEqual(current['candidate_health']['restore_epoch'],1)
            self.assertEqual(current['candidate_health']['restore_hash'],'d'*64)
            self.assertTrue(all(row['restore_epoch']==1 and row['restore_hash']=='d'*64
                                for row in current['critical_receipts']))
            self.state = engine.observe(current,at+2)
            self.assertEqual(engine._quality(current['candidate_health'],self.state['candidate'],at+2),'ok')
            self.assertTrue(engine._all_critical(self.state,self.state['candidate'],current,at+2))
            base = {'verified':True,'received_at':at+2,'incident_id':self.state['incident_id'],
                    'generation':2,'config_hash':candidate['config_hash']}
            current['preflight'] = {'verified':True,'received_at':at+2,'ready':True,
                'owner_id':config.owner_id,'service_id':config.service_id,'project_id':config.project_id,
                'checks':{name:True for name in config.required_preflight}}
            current['backup'] = {**base,'durable':True,'restore_verified':True,'checkpoint':'restored'}
            current['fencing'] = {**base,'old_generation':1,'verified_fenced':True}
            self.assertEqual(engine.plan(self.state,current,at+2).kind,'PROMOTE' if minute==20 else 'HOLD')

    def test_missing_wrong_or_pre_restore_job_scope_is_unknown_not_relay_failure(self):
        from infra.relay_recovery import core
        current = self.candidate_state(epoch=1)
        variants = [self.candidate_state(), self.candidate_state(epoch=2), self.candidate_state(epoch=1,recorded=NOW)]
        wrong_hash = self.candidate_state(epoch=1); wrong_hash['candidate']['restore_hash']='c'*64
        variants.append(wrong_hash)
        for index, issued_state in enumerate(variants):
            issued = NOW if index in (0,2) else NOW+11
            manifest = self.adapter(issued).publish(issued_state,'ir-agent')
            self.report(manifest,now=NOW+13+index,job_index=1,ok=False,
                        detail={'path_probe_ok':False,'tunnel_ok':False})
            evidence = self.adapter(NOW+20).collect({'state':current})['evidence']
            with self.subTest(index=index):
                self.assertNotIn('candidate_health',evidence)
                engine = core.Engine(core.Config(**self.cfg['policy']),self._initialized_journal())
                self.assertEqual(engine._quality(evidence.get('candidate_health'),current['candidate'],NOW+20),'unknown')

    def _initialized_journal(self):
        from infra.relay_recovery import core
        journal = core.Journal(self.root/'test-health.sqlite3'); journal.initialize(self.active,NOW)
        return journal

    def test_restore_epoch_receiver_must_follow_record_even_with_allowed_remote_clock_skew(self):
        self.state = self.candidate_state(epoch=1)
        manifest = self.adapter(NOW+11).publish(self.state,'ir-agent')
        # Future remote observation within permitted clock skew is not proof of
        # a post-restore controller receipt if its receive time precedes epoch.
        self.report(manifest,now=NOW+9,observed=NOW+12,job_index=1)
        self.assertNotIn('candidate_health',self.adapter(NOW+14).collect({'state':self.state})['evidence'])

    def test_restore_epoch_is_not_copied_to_unrelated_local_backup_receipts(self):
        self.state = self.candidate_state(epoch=1)
        candidate = self.state['candidate']
        self.recipe('backup',identity=identity(candidate),config_hash=candidate['config_hash'],
                    incident_id=self.state['incident_id'],durable=True,restore_verified=True,checkpoint='copy')
        evidence = self.adapter(NOW+11).collect({'state':self.state})['evidence']
        self.assertIn('backup',evidence)
        self.assertNotIn('restore_epoch',evidence['backup'])
        self.assertNotIn('restore_hash',evidence['backup'])

    def test_invalid_or_future_local_restore_metadata_cannot_publish_authorizing_jobs(self):
        for changed in ({'restore_epoch':True},{'restore_hash':'not-a-hash'},
                        {'restore_recorded_at':True},{'restore_recorded_at':NOW+100}):
            state = self.candidate_state(epoch=1); state['candidate'].update(changed)
            with self.subTest(changed=changed), self.assertRaises(ca.CollectorError):
                self.adapter(NOW+11).publish(state,'ir-agent')


if __name__ == '__main__': unittest.main()
