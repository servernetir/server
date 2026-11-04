"""Offline promotion bundle tests with actual private journal schemas.

Provider reads are finite fakes. No network, subprocess, purchase, CRM mutation
or recovery scheduler is invoked by these tests.
"""
import copy
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock
import uuid

from infra.relay_recovery import core, production_hooks as hooks, promotion_bundle as bundles
from infra.relay_recovery.production_driver import DriverStore
from infra.relay_recovery.protocol import canonical_json
from infra.relay_recovery.restore_workflow import WorkflowStore


NOW = 1_800_000_000
SOURCE = dict(provider='hetzner', project_id='p', provider_id='40', generation=1)
TARGET = dict(provider='hetzner', project_id='p', provider_id='51', generation=2)
CONFIG_HASH, SOURCE_HASH, RESTORE_HASH = 'd'*64, 'c'*64, 'e'*64
PARENT = 'a'*40
WORKFLOW = '0194bb81-8188-40cb-80b7-16a3d241559d'


class ReadOnlyProvider:
    def __init__(self, fixture):
        self.f = fixture
        self.calls = []
        self.source_status, self.target_status = 'off', 'running'
        self.location = 'fsn1'
        self.ipv4 = '8.8.4.4'
        self.ipv6 = None
        self.delay = 0
        self.store = SimpleNamespace(read_resource=self.read_resource)

    def read_resource(self, provider_id):
        self.calls.append(('read_resource', provider_id))
        identity = SOURCE if provider_id == SOURCE['provider_id'] else TARGET
        return SimpleNamespace(identity=copy.deepcopy(identity), provider_id=provider_id)

    @staticmethod
    def _identity(record):
        return record.identity

    def _client(self):
        return self

    def _verified_server(self, client, record):
        self.calls.append(('GET', record.provider_id))
        self.f.now += self.delay
        return dict(status=self.source_status if record.provider_id == '40' else self.target_status,
                    public_net={'ipv6': self.ipv6})

    def probe_target(self, identity):
        self.calls.append(('probe_target', copy.deepcopy(identity)))
        return dict(identity=copy.deepcopy(identity), ipv4=self.ipv4, location_code=self.location)


class Fixture(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.root.chmod(0o700)
        self.now = NOW
        self.config = dict(state_dir=str(self.root), policy=dict(enabled=True, owner_id='1', service_id='7001',
            project_id='p', receipt_max_age=90, data_max_age=300, critical_consumers=['vm109', 'storage']),
            production_driver=dict(enabled=True))
        self.registry = dict(schema=hooks.REGISTRY_SCHEMA, owner_id='1', service_id='7001', project_id='p',
            revision=1, consumers=['vm109', 'storage'], resources=[
                dict(id='consumer-vm109', kind='consumer_endpoint', consumer='vm109', binding_sha256='1'*64),
                dict(id='consumer-storage', kind='consumer_endpoint', consumer='storage', binding_sha256='2'*64),
                dict(id='publisher', kind='publisher_binding', publisher_id='upstream-set', binding_sha256='3'*64),
                dict(id='panel', kind='panel_binding', instance_id=5, exit_upstream_ids=[7, 8])])
        self.enrollment = dict(schema=bundles.SCHEMA, owner_id='1', service_id='7001', project_id='p',
            registry_hash='', locations={'fsn1': 'de-falkenstein', 'hel1': 'fi-helsinki'})
        self.context = dict(schema='servernet.relay.production-driver.v1', owner_id='1', service_id='7001',
            project_id='p', source_identity=copy.deepcopy(SOURCE), identity=copy.deepcopy(TARGET),
            config_hash=CONFIG_HASH, registry_hash='', parent_intent_id=PARENT, incident_id='incident-1',
            restore_epoch=1, restore_hash=RESTORE_HASH, gate_hash='', checkpoint='cp-1')
        self.evidence = dict(candidate_health=self.scoped(agent_id='ir-agent', direct_control=True,
                host_healthy=True, internet_healthy=True, tunnel_ok=True, probe_ok=True),
            critical_receipts=[self.scoped(consumer=c, ok=True) for c in self.registry['consumers']],
            backup=self.scoped(durable=True, restore_verified=True, checkpoint='cp-1', covers_promotion_checkpoint='cp-1'),
            fencing=self.scoped(old_generation=1, verified_fenced=True),
            restore_ready=self.scoped(pre_cutover_ready=True, source_identity=copy.deepcopy(SOURCE),
                workflow_id=WORKFLOW, snapshot_id='snapshot-1', phase='PRE_CUTOVER_READY',
                acceptance_started_at=NOW-1202, acceptance_last_observed_at=NOW-2,
                acceptance_seconds=1200, migration_complete=False, global_cutover_complete=False))
        self.context['gate_hash'] = core.digest({k: self.evidence[k] for k in bundles.GATE_FIELDS})
        self.permit = dict(schema='servernet.relay.driver.permit.v1', intent_id=str(uuid.uuid4()),
            parent_intent_id=PARENT, kind='CRM_PROMOTION', context_hash='', created_at=NOW-1, expires_at=NOW+25)
        self.external_permit = dict(schema=self.permit['schema'], intent_id=str(uuid.uuid4()),
            parent_intent_id=PARENT, kind='EXTERNAL_CUTOVER', context_hash='', created_at=NOW-5, expires_at=NOW+20)
        self.proof = dict(schema=bundles.OBSERVATION, operation='cutover_dispatch', verified=True, read_only=True,
            observed_at=NOW-2, received_at=NOW-1, context_hash='', intent_id=self.external_permit['intent_id'],
            state='complete', no_old_references=True, all_external_resources_verified=True,
            resource_proofs=[self.resource_proof(r) for r in self.registry['resources'] if r['kind'] != 'panel_binding'],
            critical_receipts=[self.scoped(consumer=c, ok=True, read_only=True, source_identity=copy.deepcopy(SOURCE))
                for c in self.registry['consumers']])
        self.action = core.Intent(PARENT, 'PROMOTE', 'incident-1', 2, 5,
            dict(identity=copy.deepcopy(TARGET), old_identity=copy.deepcopy(SOURCE),
                checkpoint='cp-1', gate_hash=self.context['gate_hash']), NOW-3, NOW+27).to_dict()
        self.state = dict(revision=6, phase='CUTOVER', active={**SOURCE, 'config_hash': SOURCE_HASH},
            candidate={**TARGET, 'config_hash': CONFIG_HASH, 'restore_epoch': 1, 'restore_hash': RESTORE_HASH},
            retiring=None, incident_id='incident-1', pending_action=PARENT, clock_unsafe=False, last_now=NOW-1)
        fence = dict(service_id='7001', provider_id='40', generation=1, config_hash=SOURCE_HASH,
            machine_uuid_sha256='4'*64, ok=True, physical_identity_verified=True, guard_installed=True,
            identity_fenced=True, persistent_fence=True, all_units_inactive=True,
            docker=dict(exact_inventory=True, daemon_inactive=True, no_shims=True, running_ids=[]))
        self.workflow = dict(id=WORKFLOW, phase='PRE_CUTOVER_READY', source_fenced=True,
            source={**SOURCE, 'config_hash': SOURCE_HASH}, candidate={**TARGET, 'config_hash': CONFIG_HASH},
            create_intent_id='f'*40, incident_id='incident-1', fence_attempted=True,
            restore_epoch=1, restore_hash=RESTORE_HASH, proofs={'fence': fence},
            latest_health=self.scoped(workflow_id=WORKFLOW, ok=True, read_only=True,
                source_identity=copy.deepcopy(SOURCE), observed_at=NOW-40, received_at=NOW-40))
        self.driver_store = DriverStore(self.root)
        self.journal = core.Journal(self.root/'controller.sqlite3')
        self.workflow_store = WorkflowStore(self.root)
        self.provider = ReadOnlyProvider(self)
        self.authorizations = []
        self.rebind_registry()
        self.save()
        self.writer = self.make_writer()

    def scoped(self, **fields):
        value = dict(verified=True, observed_at=NOW-2, received_at=NOW-2, incident_id='incident-1',
            generation=2, identity=copy.deepcopy(TARGET), config_hash=CONFIG_HASH,
            restore_epoch=1, restore_hash=RESTORE_HASH)
        value.update(fields)
        return value

    def resource_proof(self, resource):
        detail = dict(resource=copy.deepcopy(resource), read_only=True, observed_at=NOW-2,
            evidence=dict(identity=copy.deepcopy(TARGET), config_hash=CONFIG_HASH, actual_binding_sha256='9'*64))
        return dict(id=resource['id'], verified=True, verifier='offline-actual-state-reader-v1',
                    proof_sha256=core.digest(detail), proof=detail)

    def private_json(self, name, value):
        path = self.root/name
        raw = canonical_json(value)
        path.write_bytes(raw)
        path.chmod(0o600)
        return dict(path=str(path), sha256=hashlib.sha256(raw).hexdigest())

    def rebind_registry(self):
        self.config['production_driver']['registry'] = self.private_json('registry.json', self.registry)
        self.context['registry_hash'] = self.config['production_driver']['registry']['sha256']
        self.enrollment['registry_hash'] = self.context['registry_hash']
        self.config['production_driver']['promotion_bundle'] = self.private_json('bundle-enrollment.json', self.enrollment)
        self.permit['context_hash'] = core.digest(self.context)
        normal = {k: self.context[k] for k in hooks.CONTEXT_FIELDS}
        self.external_permit['context_hash'] = core.digest(normal)
        self.proof['context_hash'] = core.digest(normal)

    def rebind_gate(self):
        self.context['gate_hash'] = core.digest({k: self.evidence[k] for k in bundles.GATE_FIELDS})
        self.action['payload']['gate_hash'] = self.context['gate_hash']
        self.permit['context_hash'] = core.digest(self.context)
        self.save_driver()
        self.save_core()

    def save(self, *, external_phase='VERIFIED', crm_phase='DISPATCHED'):
        self.save_driver(external_phase=external_phase, crm_phase=crm_phase)
        self.save_core()
        self.save_workflow()

    def save_driver(self, *, external_phase='VERIFIED', crm_phase='DISPATCHED'):
        normal = {k: self.context[k] for k in hooks.CONTEXT_FIELDS}
        with self.driver_store.transaction() as db:
            db.execute('DELETE FROM steps')
            for permit, kind, context, phase, result in (
                (self.permit, 'CRM_PROMOTION', self.context, crm_phase, None),
                (self.external_permit, 'EXTERNAL_CUTOVER', normal, external_phase, core.canonical(self.proof))):
                db.execute('INSERT INTO steps VALUES(?,?,?,?,?,?)',
                    (permit['intent_id'], PARENT, kind, core.canonical(dict(permit=permit, context=context)), phase, result))
            db.execute('INSERT OR REPLACE INTO promotion_gates VALUES(?,?)', (PARENT, core.canonical(self.evidence)))

    def save_core(self):
        with self.journal.transaction() as db:
            db.execute('INSERT OR REPLACE INTO state VALUES(1,?)', (core.canonical(self.state),))
            db.execute('INSERT OR REPLACE INTO actions VALUES(?,?,?,?,?,NULL)',
                (PARENT, 'PROMOTE', 'incident-1', self.action['created_at'], core.canonical(self.action)))

    def save_workflow(self):
        with self.workflow_store.transaction() as db:
            db.execute('INSERT OR REPLACE INTO workflows VALUES(?,?,?)',
                (WORKFLOW, '51', core.canonical(self.workflow)))

    def make_writer(self, **kwargs):
        kwargs.setdefault('clock', lambda: self.now)
        return bundles.PromotionBundleWriter(self.config, provider=self.provider, allow_test_paths=True, **kwargs)

    def authorize(self, permit):
        self.authorizations.append(copy.deepcopy(permit))
        return permit == self.permit

    def publish(self, **kwargs):
        return self.writer(kwargs.get('permit', self.permit), kwargs.get('context', self.context),
                           kwargs.get('authorize', self.authorize))

    @property
    def destination(self):
        return self.root/('promotion-'+PARENT+'.json')

    def rejects(self, code, **kwargs):
        with self.assertRaisesRegex(hooks.IntegrationError, code):
            self.publish(**kwargs)
        self.assertFalse(self.destination.exists())

    def add_cloudflare(self, *, observed_at=NOW-2, proxied=True):
        resource = dict(id='cloudflare', kind='cloudflare_record', zone_id='a'*32, record_id='b'*32,
                        hostname='relay.example.com')
        self.registry['resources'].append(resource)
        origin = dict(schema=hooks.ORIGIN_SCHEMA, verified=True, read_only=True, identity=copy.deepcopy(TARGET),
            config_hash=CONFIG_HASH, hostname=resource['hostname'], origin_ipv4=self.provider.ipv4,
            machine_uuid_sha256='5'*64, origin_verified=True, edge_route_verified=True,
            observed_at=observed_at, received_at=observed_at,
            proofs=[dict(path=path, observed_at=observed_at,
                         peer_ipv4=self.provider.ipv4 if path == 'direct' else '104.16.1.1',
                         body_sha256='6'*64) for path in ('direct', 'edge')])
        authorities = [dict(server='9.9.9.9', verified=True, observed_at=observed_at,
            answer=dict(authoritative=True, name=resource['hostname'], type='A', addresses=[self.provider.ipv4]))]
        detail = dict(resource=resource, record=dict(id=resource['record_id'], type='A', name=resource['hostname'], content=self.provider.ipv4,
            proxied=proxied, ttl=1), origin=origin if proxied else None,
            authoritative=[] if proxied else authorities, observed_at=observed_at,
            content_verified=True, fresh_at_completion=True, completed_at=NOW-1)
        self.proof['resource_proofs'].append(dict(id=resource['id'], verified=True,
            verifier='cloudflare-api-and-authenticated-origin-v1', proof_sha256=core.digest(detail), proof=detail))
        self.config['cloudflare'] = dict(zones=[dict(zone_id=resource['zone_id'],
            record_ids=[resource['record_id']], authoritative_ips=['9.9.9.9'])])
        self.rebind_registry()
        self.save_driver()
        self.writer = self.make_writer()


class PromotionBundleTests(Fixture):
    def test_complete_durable_evidence_publishes_atomic_private_file_without_effects(self):
        result = self.publish()
        self.assertEqual(result, json.loads(self.destination.read_bytes()))
        self.assertEqual(canonical_json(result), self.destination.read_bytes())
        self.assertEqual(0o600, self.destination.stat().st_mode & 0o777)
        self.assertEqual('de-falkenstein', result['location_code'])
        self.assertEqual('8.8.4.4', result['ipv4'])
        self.assertTrue(all(result['checks'].values()))
        self.assertLessEqual(result['proof_expires_at'], self.permit['expires_at'])
        self.assertGreaterEqual(len(self.authorizations), 3)
        self.assertFalse(list(self.root.glob('promotion-bundle-*.tmp')))
        self.assertTrue(all(call[0] in {'GET', 'read_resource', 'probe_target'} for call in self.provider.calls))

    def test_default_clock_produces_integer_timestamp(self):
        with mock.patch.object(bundles.time, 'time', return_value=NOW+0.75):
            self.writer = bundles.PromotionBundleWriter(self.config, provider=self.provider, allow_test_paths=True)
            self.assertTrue(self.publish()['all_checks_passed'])

    def test_disabled_or_unavailable_driver_authority_writes_nothing(self):
        self.rejects('live_driver_claim_required', authorize=lambda p: False)
        self.writer.config['production_driver']['enabled'] = False
        self.rejects('context')
        self.assertEqual([], self.provider.calls)

    def test_expired_or_not_durably_dispatched_permit_cannot_publish(self):
        self.now = self.permit['expires_at']
        self.rejects('live_driver_claim_required')
        self.now = NOW
        self.writer = self.make_writer()
        self.save_driver(crm_phase='COMMITTED')
        self.rejects('uncommitted_claim')
        self.assertEqual([], self.provider.calls)

    def test_permit_context_hash_kind_or_parent_cannot_be_substituted(self):
        for fields in ({'context_hash': '0'*64}, {'kind': 'EXTERNAL_CUTOVER'}, {'parent_intent_id': 'f'*40}):
            with self.subTest(fields=fields):
                self.rejects('live_driver_claim_required', permit={**self.permit, **fields})

    def test_registry_and_enrollment_bytes_require_the_exact_private_pin(self):
        registry = Path(self.config['production_driver']['registry']['path'])
        registry.write_bytes(canonical_json({**self.registry, 'revision': 2}))
        self.rejects('pin_mismatch')
        registry.write_bytes(canonical_json(self.registry))
        path = Path(self.config['production_driver']['promotion_bundle']['path'])
        path.write_bytes(canonical_json({**self.enrollment, 'service_id': '7002'}))
        self.rejects('pin_mismatch')

    def test_missing_or_invalid_panel_binding_cannot_publish(self):
        self.registry['resources'] = [r for r in self.registry['resources'] if r['kind'] != 'panel_binding']
        self.rebind_registry(); self.save_driver(); self.writer = self.make_writer()
        self.rejects('panel_pin')

    def test_wrong_scope_enrollment_and_unknown_location_cannot_publish(self):
        self.enrollment['registry_hash'] = '0'*64
        self.config['production_driver']['promotion_bundle'] = self.private_json('bundle-enrollment.json', self.enrollment)
        self.writer = self.make_writer()
        self.rejects('enrollment_scope')
        self.enrollment['registry_hash'] = self.context['registry_hash']
        self.config['production_driver']['promotion_bundle'] = self.private_json('bundle-enrollment.json', self.enrollment)
        self.writer = self.make_writer(); self.provider.location = 'unregistered-location'
        self.rejects('location_not_enrolled')

    def test_external_ack_or_missing_readback_is_insufficient(self):
        self.save_driver(external_phase='DISPATCHED')
        self.rejects('external_not_verified')
        self.save_driver()
        with self.driver_store.transaction() as db:
            db.execute("DELETE FROM steps WHERE kind='EXTERNAL_CUTOVER'")
        self.rejects('external_not_verified')

    def test_external_readback_must_be_recent_complete_read_only_and_exact_context(self):
        baseline = copy.deepcopy(self.proof)
        for fields in ({'observed_at': NOW-31}, {'received_at': NOW+1}, {'read_only': False},
                       {'context_hash': '0'*64}, {'no_old_references': False}, {'state': 'pending'}):
            with self.subTest(fields=fields):
                self.proof = {**baseline, **fields}; self.save_driver()
                self.rejects('stale_evidence|external_proof_incomplete')

    def test_resource_coverage_digest_and_exact_pinned_binding_are_required(self):
        baseline = copy.deepcopy(self.proof)
        for change in ('missing', 'hash', 'binding', 'unverified'):
            with self.subTest(change=change):
                self.proof = copy.deepcopy(baseline)
                resource = self.proof['resource_proofs'][0]
                if change == 'missing': self.proof['resource_proofs'].pop()
                elif change == 'hash': resource['proof_sha256'] = '0'*64
                elif change == 'binding':
                    resource['proof']['resource']['binding_sha256'] = '0'*64
                    resource['proof_sha256'] = core.digest(resource['proof'])
                else: resource['verified'] = False
                self.save_driver(); self.rejects('resource_coverage|resource_invalid')

    def test_stale_or_future_resource_observation_cannot_extend_deadline(self):
        resource = self.proof['resource_proofs'][0]
        for at in (NOW-91, NOW+1):
            with self.subTest(at=at):
                resource['proof']['observed_at'] = at
                resource['proof_sha256'] = core.digest(resource['proof'])
                self.save_driver(); self.rejects('resource_invalid|proof_expired|stale_evidence')

    def test_critical_receipts_cover_each_consumer_and_exact_restore_and_source(self):
        baseline = copy.deepcopy(self.proof)
        for fields in ({'source_identity': {**SOURCE, 'provider_id': '99'}}, {'identity': SOURCE},
                       {'restore_epoch': 2}, {'restore_hash': '0'*64}, {'consumer': 'wrong'},
                       {'observed_at': NOW-91}, {'ok': False}):
            with self.subTest(fields=fields):
                self.proof = copy.deepcopy(baseline)
                self.proof['critical_receipts'][0].update(fields)
                self.save_driver(); self.rejects('critical_scope|critical_coverage|stale_evidence|gate_evidence_stale_or_unscoped')

    def test_gate_hash_and_parent_action_candidate_source_generation_are_exact(self):
        baseline = copy.deepcopy(self.action)
        for field, value in (('identity', SOURCE), ('old_identity', TARGET), ('checkpoint', 'wrong'),
                             ('gate_hash', '0'*64), ('generation', 3)):
            with self.subTest(field=field):
                self.action = copy.deepcopy(baseline)
                if field == 'generation': self.action[field] = value
                else: self.action['payload'][field] = value
                self.save_core(); self.rejects('parent_scope')
        self.action = baseline; self.save_core()
        self.evidence['backup']['checkpoint'] = 'different'
        self.save_driver(); self.rejects('parent_scope')

    def test_saved_gate_expired_or_future_receipts_reject_even_with_matching_hash(self):
        baseline = copy.deepcopy(self.evidence)
        for key, maximum in (('candidate_health', 90), ('backup', 300), ('fencing', 90), ('restore_ready', 90)):
            for at in (NOW-maximum-1, NOW+1):
                with self.subTest(key=key, at=at):
                    self.evidence = copy.deepcopy(baseline)
                    self.evidence[key].update(observed_at=at, received_at=at)
                    self.rebind_gate()
                    self.rejects('gate_evidence_stale_or_unscoped')

    def test_saved_gate_critical_scope_and_freshness_reject_even_with_matching_hash(self):
        baseline = copy.deepcopy(self.evidence)
        for fields in ({'observed_at': NOW-91}, {'received_at': NOW+1},
                       {'config_hash': '0'*64}, {'restore_epoch': 2}, {'incident_id': 'other'}):
            with self.subTest(fields=fields):
                self.evidence = copy.deepcopy(baseline)
                self.evidence['critical_receipts'][0].update(fields)
                self.rebind_gate()
                self.rejects('gate_evidence_stale_or_unscoped')

    def test_saved_gate_deadline_is_preserved_after_provider_read_delays(self):
        self.evidence['backup'].update(observed_at=NOW-295, received_at=NOW-295)
        self.rebind_gate()
        self.provider.delay = 3
        self.rejects('proof_expired')
        self.assertEqual(NOW+6, self.now)

    def test_wrong_restore_workflow_or_fence_never_proves_restoration(self):
        baseline = copy.deepcopy(self.workflow)
        for change in ('workflow', 'source', 'config', 'epoch', 'fence', 'docker'):
            with self.subTest(change=change):
                self.workflow = copy.deepcopy(baseline)
                if change == 'workflow': self.workflow['id'] = str(uuid.uuid4())
                elif change == 'source': self.workflow['source']['provider_id'] = '99'
                elif change == 'config': self.workflow['candidate']['config_hash'] = '0'*64
                elif change == 'epoch': self.workflow['restore_epoch'] = 2
                elif change == 'fence': self.workflow['proofs']['fence']['persistent_fence'] = False
                else: self.workflow['proofs']['fence']['docker']['running_ids'] = ['unexpected-writer']
                self.save_workflow(); self.rejects('workflow_unverified')

    def test_running_source_or_stopped_candidate_cannot_publish(self):
        self.provider.source_status = 'running'; self.rejects('source_not_off')
        self.provider.source_status = 'off'; self.provider.target_status = 'off'
        self.rejects('candidate_not_running')

    def test_provider_record_for_another_source_cannot_prove_the_old_relay_off(self):
        read_resource = self.provider.store.read_resource
        def wrong_source(provider_id):
            record = read_resource(provider_id)
            if provider_id == SOURCE['provider_id']:
                record.identity = {**SOURCE, 'provider_id': '99'}
            return record
        self.provider.store.read_resource = wrong_source
        self.rejects('source_identity')
        self.assertNotIn(('GET', SOURCE['provider_id']), self.provider.calls)

    def test_proxied_cloudflare_requires_origin_and_its_exact_provider_address(self):
        self.add_cloudflare()
        baseline = copy.deepcopy(self.proof)
        for change in ('missing', 'address'):
            with self.subTest(change=change):
                self.proof = copy.deepcopy(baseline)
                resource = self.proof['resource_proofs'][-1]
                if change == 'missing': resource['proof']['origin'] = None
                else: resource['proof']['origin']['origin_ipv4'] = '9.9.9.9'
                resource['proof_sha256'] = core.digest(resource['proof'])
                self.save_driver()
                self.rejects('origin_required|origin_address')

    def test_native_core_backup_fence_and_critical_receipt_shapes_publish(self):
        for key in ('backup', 'fencing'):
            for field in ('restore_epoch', 'restore_hash'):
                self.evidence[key].pop(field)
        for receipt in self.proof['critical_receipts']:
            receipt.pop('read_only')
            receipt.pop('source_identity')
        self.rebind_gate()
        engine = core.Engine(core.Config(**self.config['policy']), self.journal)
        self.assertTrue(engine._data_gate(self.state, self.state['candidate'], self.evidence, self.now))
        self.assertTrue(self.publish()['all_checks_passed'])

    def test_cloudflare_wrong_actual_content_rejects_even_with_verified_flags(self):
        self.add_cloudflare()
        resource = self.proof['resource_proofs'][-1]
        resource['proof']['record']['content'] = '9.9.9.9'
        resource['proof_sha256'] = core.digest(resource['proof'])
        self.save_driver()
        self.rejects('cloudflare_record_mismatch')

    def test_dns_only_cloudflare_requires_actual_exact_authoritative_answers(self):
        self.add_cloudflare(proxied=False)
        baseline = copy.deepcopy(self.proof)
        for change in ('missing', 'wrong_answer', 'extra_answer'):
            with self.subTest(change=change):
                self.proof = copy.deepcopy(baseline)
                resource = self.proof['resource_proofs'][-1]
                if change == 'missing': resource['proof']['authoritative'] = []
                else:
                    resource['proof']['authoritative'][0]['answer']['addresses'] = (
                        ['9.9.9.9'] if change == 'wrong_answer' else [self.provider.ipv4, '9.9.9.9'])
                resource['proof_sha256'] = core.digest(resource['proof'])
                self.save_driver()
                self.rejects('cloudflare_authority_missing|cloudflare_authority_unverified')
        self.proof = baseline
        self.save_driver()
        self.assertTrue(self.publish()['all_checks_passed'])

    def test_origin_proof_has_fifteen_second_deadline_even_after_provider_reads(self):
        self.add_cloudflare(observed_at=NOW-10)
        self.provider.delay = 3
        self.rejects('proof_expired')
        self.assertEqual(NOW+6, self.now)

    def test_origin_wrong_identity_config_or_hostname_is_rejected(self):
        self.add_cloudflare()
        baseline = copy.deepcopy(self.proof)
        for fields in ({'identity': SOURCE}, {'config_hash': '0'*64}, {'hostname': 'other.example.com'},
                       {'observed_at': NOW+1}, {'edge_route_verified': False}):
            with self.subTest(fields=fields):
                self.proof = copy.deepcopy(baseline)
                resource = self.proof['resource_proofs'][-1]
                resource['proof']['origin'].update(fields)
                resource['proof_sha256'] = core.digest(resource['proof'])
                self.save_driver(); self.rejects('origin_invalid|origin_expired|proof_expired')

    def test_authority_revoked_during_final_write_preserves_existing_file(self):
        original = self.publish()
        calls = []
        def revoked(permit):
            calls.append(permit)
            return len(calls) < 3
        with self.assertRaisesRegex(hooks.IntegrationError, 'live_driver_claim_required'):
            self.publish(authorize=revoked)
        self.assertEqual(original, json.loads(self.destination.read_bytes()))
        self.assertFalse(list(self.root.glob('promotion-bundle-*.tmp')))

    def test_symlink_destination_cannot_replace_unrelated_file(self):
        unrelated = self.root/'unrelated.json'; unrelated.write_bytes(b'untouched'); unrelated.chmod(0o600)
        self.destination.symlink_to(unrelated)
        with self.assertRaises((ValueError, hooks.IntegrationError)): self.publish()
        self.assertTrue(self.destination.is_symlink()); self.assertEqual(b'untouched', unrelated.read_bytes())

    def test_symlink_or_hardlinked_database_is_rejected(self):
        path = self.root/'controller.sqlite3'
        saved = self.root/'controller-real.sqlite3'; path.rename(saved); path.symlink_to(saved)
        with self.assertRaises((ValueError, hooks.IntegrationError)): self.publish()
        path.unlink(); os.link(saved, path)
        self.rejects('private_database')


if __name__ == '__main__': unittest.main()
