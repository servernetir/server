"""Durable lifecycle integration tests: local fakes only, no paid API calls."""
from __future__ import annotations

import copy
import dataclasses
import hashlib
import importlib.util
import json
from pathlib import Path
import sqlite3
import sys
import tempfile
import threading
import time
import unittest
import uuid
from concurrent.futures import ThreadPoolExecutor
from decimal import Decimal
from urllib.parse import urlsplit

INFRA = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(INFRA))
from relay_recovery import core, hetzner as h
from relay_recovery.provider_adapter import CredentialBridge, ProviderEffects, ProviderEffectError

SPEC = importlib.util.spec_from_file_location('provider_tariff_fixture', Path(__file__).with_name('test_hetzner.py'))
tariff_fixture = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = tariff_fixture
SPEC.loader.exec_module(tariff_fixture)


def labels(generation=1):
    return {'sn-managed': h.MANAGER, 'sn-owner': '1', 'sn-service': '711',
            'sn-generation': str(generation), 'sn-incident': 'adoption-v1', 'sn-intent': 'old-intent'}


def raw_server(server_id='40', name='relay-old', ownership=None):
    first = 61 if server_id == '40' else 71
    return {'id': int(server_id), 'name': name, 'labels': ownership if ownership is not None else labels(),
            'server_type': {'name': 'cx-test'}, 'location': {'name': 'fsn1'},
            'public_net': {'ipv4': {'id': first, 'ip': '203.0.113.' + server_id}, 'ipv6': {'id': first+1}},
            'volumes': [], 'private_net': [], 'status': 'running'}


class FakeBridge:
    def __init__(self):
        self.result = {'ok': True, 'binding': {'owner_id': 1, 'service_id': 711, 'provider_id': '40',
                                              'ipv4': '203.0.113.40'}, 'internal_protected': True}
        self.calls = []

    def request(self, operation):
        self.calls.append(operation)
        return copy.deepcopy(self.result)


class World:
    def __init__(self):
        self.servers, self.ips, self.calls = {'40': raw_server()}, {}, []
        self.tariff = tariff_fixture.TariffTransport()
        self.post_timeout, self.before_post = False, None
        self.ip_delete_status, self.disappear_on_delete = 204, True
        self.forced_status, self.image_architecture = {}, 'x86'
        self.before_request = None
        self._link_ips(self.servers['40'])

    def _link_ips(self, server):
        for ip in server['public_net'].values():
            self.ips[str(ip['id'])] = {'id': ip['id'], 'labels': {}, 'assignee_type': 'server',
                                       'assignee_id': server['id']}

    def __call__(self, method, url, body):
        path = urlsplit(url).path.removeprefix('/v1')
        self.calls.append((method, path, copy.deepcopy(body)))
        if self.before_request:
            self.before_request(method, path)
        if (method, path) in self.forced_status:
            return h.Response(self.forced_status[method, path], {})
        meta = {'pagination': {'next_page': None}}
        if path in ('/locations', '/server_types', '/pricing'):
            result = self.tariff(method, url, body)
            return h.Response(result.status, result.body)
        if method == 'GET' and path == '/images/100':
            return h.Response(200, {'image': {'id': 100, 'status': 'available', 'architecture': self.image_architecture,
                                             'deprecation': None}})
        if method == 'GET' and path == '/servers':
            return h.Response(200, {'servers': copy.deepcopy(list(self.servers.values())), 'meta': meta})
        if method == 'POST' and path == '/servers':
            if self.before_post:
                self.before_post()
            server = raw_server('51', body['name'], copy.deepcopy(body['labels']))
            server['server_type']['name'], server['location']['name'] = body['server_type'], body['location']
            self.servers['51'] = server
            self._link_ips(server)
            if self.post_timeout:
                raise h.TransportUnknown('fake_timeout_after_effect')
            return h.Response(201, {'server': copy.deepcopy(server), 'root_password': 'never-log-this'})
        parts = path.split('/')
        if len(parts) == 4 and parts[1] in ('servers', 'primary_ips') and parts[3] == 'actions' and method == 'GET':
            return h.Response(200, {'actions': [], 'meta': meta})
        if len(parts) == 3 and parts[1] == 'servers':
            sid = parts[2]
            if method == 'GET':
                return h.Response(200, {'server': copy.deepcopy(self.servers[sid])}) if sid in self.servers else h.Response(404, {})
            if method == 'PUT':
                self.servers[sid]['labels'] = copy.deepcopy(body['labels'])
                return h.Response(200, {'server': copy.deepcopy(self.servers[sid])})
            if method == 'DELETE':
                if self.disappear_on_delete:
                    self.servers.pop(sid, None)
                    for ip in self.ips.values():
                        if str(ip.get('assignee_id')) == sid:
                            ip.update(assignee_id=None, assignee_type='unassigned')
                return h.Response(204, {})
        if len(parts) == 3 and parts[1] == 'primary_ips':
            pid = parts[2]
            if method == 'GET':
                return h.Response(200, {'primary_ip': copy.deepcopy(self.ips[pid])}) if pid in self.ips else h.Response(404, {})
            if method == 'PUT':
                self.ips[pid]['labels'] = copy.deepcopy(body['labels'])
                return h.Response(200, {'primary_ip': copy.deepcopy(self.ips[pid])})
            if method == 'DELETE':
                if self.ip_delete_status == 204:
                    self.ips.pop(pid, None)
                return h.Response(self.ip_delete_status, {})
        raise AssertionError((method, path))


class ProviderEffectsTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.root.chmod(0o700)
        bootstrap = self.root/'bootstrap.txt'
        bootstrap.write_text('#cloud-config\n')
        bootstrap.chmod(0o600)
        self.now = 1_700_000_000
        self.policy = core.Config(enabled=True, owner_id='1', service_id='711', project_id='p',
            outage_seconds=180, suspect_seconds=60, recover_seconds=60, candidate_seconds=60,
            observe_seconds=120, receipt_max_age=30, observation_max_gap=30, intent_ttl=15,
            candidate_deadline=3600)
        self.active = dict(provider='hetzner', project_id='p', provider_id='40', generation=1, config_hash='cfg-1')
        self.config = {'state_dir': str(self.root), 'policy': dataclasses.asdict(self.policy),
            'active': self.active, 'binding': {'owner_id': '1', 'service_id': '711', 'provider_id': '40', 'ipv4': '203.0.113.40'},
            'provider': {'bootstrap': {'path': str(bootstrap), 'sha256': hashlib.sha256(bootstrap.read_bytes()).hexdigest()},
                         'ssh_key_ids': ['9'], 'adoption_enabled': False}}
        self.world, self.bridge = World(), FakeBridge()
        self.api = h.Hetzner(self.world, now=lambda: self.now)
        self.effects = ProviderEffects(self.config, now=lambda: self.now, client_factory=lambda: self.api, bridge=self.bridge)
        self.effects.store.save_resource(self.managed(self.world.servers['40'], promoted=True))
        self.journal = core.Journal(self.root/'controller.sqlite3')
        self.journal.initialize(self.active, self.now)
        self.engine = core.Engine(self.policy, self.journal)

    def managed(self, raw, promoted=False):
        return h.ManagedServer(str(raw['id']), raw['name'], copy.deepcopy(raw['labels']),
            int(raw['labels']['sn-generation']), tuple(str(v['id']) for v in raw['public_net'].values()),
            raw['public_net']['ipv4']['ip'], self.now, self.now if promoted else None, promoted)

    def state(self):
        return self.engine.snapshot()

    def scoped(self, resource=None, **kw):
        state = self.state(); resource = resource or state['active']
        value=dict(verified=True, received_at=self.now, incident_id=state['incident_id'],
                   generation=resource['generation'], config_hash=resource['config_hash'])
        if 'restore_epoch' in resource:
            value.update(restore_epoch=resource['restore_epoch'],restore_hash=resource['restore_hash'],observed_at=self.now)
        value.update(kw);return value

    def evidence(self):
        return {'health': self.scoped(agent_id='ir-agent', direct_control=True, host_healthy=True,
                                     internet_healthy=True, tunnel_ok=False, probe_ok=False),
            'preflight': {'verified': True, 'received_at': self.now, 'owner_id': '1', 'service_id': '711',
                'project_id': 'p', 'ready': True, 'checks': {k: True for k in self.policy.required_preflight}},
            'inventory': {'verified': True, 'received_at': self.now, 'complete': True, 'managed_vm_count': 1,
                          'project_id': 'p', 'active_identity': core.identity(self.state()['active'])},
            'options': [{'verified': True, 'received_at': self.now, 'country': 'DE', 'location': 'fsn1',
                'available': True, 'compatible': True, 'provider': 'hetzner', 'project_id': 'p', 'owner_id': '1',
                'service_id': '711', 'currency': 'EUR', 'architecture': 'x86', 'config_hash': 'cfg-2',
                'server_type': 'cx-test', 'image': '100', 'monthly_gross_micro': 7_000_000,
                'trial_max_gross_micro': 100_000}]}

    def create_intent(self):
        self.engine.observe(self.evidence(), self.now)
        for _ in range(6):
            self.now += 30
            self.engine.observe(self.evidence(), self.now)
        evidence = self.evidence()
        intent = self.engine.plan(self.state(), evidence, self.now)
        self.assertEqual('CREATE', intent.kind)
        self.engine.commit_intent(intent, evidence, self.now)
        return intent, evidence

    def run_effect(self, intent, evidence, record=True):
        envelope = self.effects.execute(intent.kind, {'intent': intent.to_dict(), 'evidence': evidence})
        if record:
            self.engine.record_effect(intent.id, envelope['receipt'], self.now)
        return envelope['receipt']

    def reconcile_intent(self, evidence=None):
        self.now += 1
        evidence = evidence or self.evidence()
        intent = self.engine.plan(self.state(), evidence, self.now)
        self.engine.commit_intent(intent, evidence, self.now)
        return intent, evidence

    def candidate(self):
        intent, evidence = self.create_intent()
        receipt = self.run_effect(intent, evidence)
        self.assertEqual('created', receipt['outcome'])
        return self.state()['candidate']

    def cleanup_intent(self, evidence_age=0):
        candidate = self.state()['candidate']
        evidence = self.evidence()
        evidence['candidate_rejected'] = self.scoped(candidate, rejected=True)
        evidence['cleanup'] = self.scoped(candidate, identity=core.identity(candidate), never_promoted=True, no_actual_writes=True)
        evidence['cleanup']['received_at'] -= evidence_age
        intent = self.engine.plan(self.state(), evidence, self.now)
        self.assertEqual('DELETE_CANDIDATE', intent.kind)
        self.engine.commit_intent(intent, evidence, self.now)
        return intent, evidence

    def promotion_evidence(self):
        state = self.state(); target = state['candidate'] or state['active']
        evidence = self.evidence()
        health = self.scoped(target, agent_id='ir-agent', direct_control=True, host_healthy=True,
                             internet_healthy=True, tunnel_ok=True, probe_ok=True)
        evidence['candidate_health' if state['candidate'] else 'health'] = health
        evidence.update(critical_receipts=[self.scoped(target, consumer=c, ok=True) for c in self.policy.critical_consumers],
            backup=self.scoped(target, checkpoint='cp-2', durable=True, restore_verified=True),
            fencing=self.scoped(target, old_generation=(state['retiring'] or state['active'])['generation'], verified_fenced=True))
        if 'restore_epoch' in target:
            start=target['restore_recorded_at']
            evidence['restore_ready']=self.scoped(target,identity=core.identity(target),
                source_identity=core.identity(state['retiring'] or state['active']),
                workflow_id=target['restore_workflow_id'],snapshot_id=target['restore_snapshot_id'],
                phase='PRE_CUTOVER_READY',pre_cutover_ready=True,global_cutover_complete=False,migration_complete=False,
                acceptance_started_at=start,acceptance_last_observed_at=self.now,acceptance_seconds=self.now-start)
        return evidence

    def retirement_intent(self):
        candidate=self.candidate()
        restore=self.scoped(candidate,identity=core.identity(candidate),source_identity=core.identity(self.state()['active']),
            workflow_id=str(uuid.uuid4()),workflow_committed=True)
        self.engine.begin_restore({**restore,'purpose':'candidate_restore','expected_restore_epoch':1},self.now)
        self.engine.record_restore_epoch({**restore,'snapshot_id':'123456','restore_epoch':1,'restore_hash':'d'*64,
            'snapshot_available':True,'source_fenced':True,'boot_verified':True,'quarantine_verified':True,
            'machine_id_sha256':'e'*64,'boot_id_sha256':'f'*64},self.now)
        for _ in range(40):
            self.now += 30
            self.engine.observe(self.promotion_evidence(), self.now)
        evidence = self.promotion_evidence()
        promote = self.engine.plan(self.state(), evidence, self.now)
        self.assertEqual('PROMOTE', promote.kind)
        self.engine.commit_intent(promote, evidence, self.now)
        self.engine.record_effect(promote.id, dict(intent_id=promote.id, kind=promote.kind, outcome='promoted',
            verified=True, received_at=self.now, identity=core.identity(self.state()['candidate']), all_checks_passed=True), self.now)
        for _ in range(5):
            self.now += 30
            self.engine.observe(self.promotion_evidence(), self.now)
        evidence = self.promotion_evidence()
        evidence['backup']['covers_promotion_checkpoint'] = 'cp-2'
        evidence['ttl'] = self.scoped(known=True, expires_at=self.now)
        evidence['active_refs'] = self.scoped(complete=True, count=0)
        retire = self.engine.plan(self.state(), evidence, self.now)
        self.assertEqual('RETIRE', retire.kind)
        self.engine.commit_intent(retire, evidence, self.now)
        return retire, evidence

    def test_no_effect_without_exact_committed_intent(self):
        intent, evidence = self.create_intent()
        changed = intent.to_dict(); changed['payload']['name'] = 'other-name'
        with self.assertRaisesRegex(ProviderEffectError, 'exact_committed'):
            self.effects.execute('CREATE', {'intent': changed, 'evidence': evidence})
        self.assertEqual([], self.world.calls)

    def test_disabled_effect_and_unadopted_active_never_purchase(self):
        intent, evidence = self.create_intent()
        self.effects.policy = dataclasses.replace(self.policy, enabled=False)
        with self.assertRaisesRegex(ProviderEffectError, 'disabled'):
            self.run_effect(intent, evidence, record=False)
        self.assertEqual([], self.world.calls)
        self.effects.policy = self.policy
        self.world.servers['40']['labels']['sn-owner'] = 'other'
        with self.assertRaises(ProviderEffectError):
            self.run_effect(intent, evidence, record=False)
        self.assertFalse(any(m == 'POST' for m, _, _ in self.world.calls))

    def test_pre_post_claim_is_durable_and_identity_is_captured(self):
        intent, evidence = self.create_intent()
        def inspect_claim():
            with sqlite3.connect(self.effects.store.path) as db:
                row = db.execute('SELECT phase,spec FROM creates WHERE id=?', (intent.id,)).fetchone()
            self.assertEqual('POST_CLAIMED', row[0])
            self.assertEqual(intent.id, json.loads(row[1])['intent_id'])
        self.world.before_post = inspect_claim
        receipt = self.run_effect(intent, evidence)
        self.assertEqual('51', receipt['identity']['provider_id'])
        self.assertEqual('VALIDATING', self.state()['phase'])
        record = self.effects.store.read_resource('51')
        self.assertEqual(('71', '72'), record.primary_ip_ids)
        self.assertNotIn('never-log-this', repr(receipt))
        self.assertEqual(record.labels, self.world.ips['71']['labels'])

    def test_timeout_actual_create_reconciles_without_purchase_or_label_put(self):
        intent, evidence = self.create_intent()
        self.world.post_timeout = True
        self.assertEqual('unknown', self.run_effect(intent, evidence)['outcome'])
        follow, evidence = self.reconcile_intent()
        self.world.calls = []
        receipt = self.run_effect(follow, evidence)
        self.assertEqual(('found', '51'), (receipt['outcome'], receipt['identity']['provider_id']))
        self.assertTrue(all(method == 'GET' for method, _, _ in self.world.calls))
        self.assertEqual({}, self.world.ips['71']['labels'])

    def test_intent_expiry_during_image_get_prevents_post_and_proves_no_submission(self):
        intent, evidence = self.create_intent()
        def latency(method, path):
            if (method, path) == ('GET', '/images/100'):
                self.now += 16
        self.world.before_request = latency
        with self.assertRaisesRegex(ProviderEffectError, 'expired'):
            self.run_effect(intent, evidence, record=False)
        self.assertFalse(any(m == 'POST' for m, _, _ in self.world.calls))
        self.world.before_request = None
        follow, evidence = self.reconcile_intent()
        self.assertEqual('absent', self.run_effect(follow, evidence)['outcome'])
        self.assertEqual('HEALTHY', self.state()['phase'])

    def test_capacity_change_immediately_before_post_never_exceeds_bound(self):
        intent, evidence = self.create_intent()
        def concurrent_capacity(method, path):
            if (method, path) == ('GET', '/images/100'):
                self.world.servers['90'] = raw_server('90')
        self.world.before_request = concurrent_capacity
        with self.assertRaisesRegex(ProviderEffectError, 'capacity_changed'):
            self.run_effect(intent, evidence, record=False)
        self.assertFalse(any(m == 'POST' for m, _, _ in self.world.calls))

    def test_crash_before_submission_can_prove_absence_but_claimed_cannot(self):
        intent, _ = self.create_intent()
        follow, evidence = self.reconcile_intent()
        receipt = self.run_effect(follow, evidence, record=False)
        self.assertEqual('absent', receipt['outcome'])
        self.assertTrue(receipt['definitive_rejection'])
        self.assertTrue(all(m == 'GET' for m, _, _ in self.world.calls))
        with self.effects.store.transaction() as db:
            db.execute('UPDATE creates SET phase=? WHERE id=?', ('POST_CLAIMED', intent.id))
        self.assertEqual('unknown', self.run_effect(follow, evidence, record=False)['outcome'])
        self.assertFalse(any(m == 'POST' for m, _, _ in self.world.calls))

    def test_price_drift_before_post_does_not_purchase(self):
        intent, evidence = self.create_intent()
        self.world.tariff.vat = '100.00'  # inconsistent gross/net is unknown, never a cheap price
        with self.assertRaises(h.AdapterError):
            self.run_effect(intent, evidence, record=False)
        self.assertFalse(any(m == 'POST' for m, _, _ in self.world.calls))
        self.world.tariff.vat = '19.00'
        self.world.tariff.bad_price = True
        with self.assertRaises(h.AdapterError):
            self.run_effect(intent, evidence, record=False)
        self.assertFalse(any(m == 'POST' for m, _, _ in self.world.calls))

    def test_valid_price_increase_and_incompatible_image_are_definitive_no_submission(self):
        intent, evidence = self.create_intent()
        original_amount = self.world.tariff.amount
        self.world.tariff.amount = lambda net: original_amount(str(Decimal(net)*2))
        result = self.run_effect(intent, evidence, record=False)
        self.assertEqual(('failed', True), (result['outcome'], result['definitive_rejection']))
        self.assertFalse(any(m == 'POST' for m, _, _ in self.world.calls))
        self.world.tariff.amount, self.world.image_architecture = original_amount, 'arm'
        result = self.run_effect(intent, evidence)
        self.assertEqual(('failed', True), (result['outcome'], result['definitive_rejection']))
        self.assertEqual('HEALTHY', self.state()['phase'])
        self.assertFalse(any(m == 'POST' for m, _, _ in self.world.calls))

    def test_concurrent_same_intent_has_one_atomic_submission_claim(self):
        intent, evidence = self.create_intent()
        spec = self.effects._creation_spec(intent.to_dict())
        with self.effects.store.transaction() as db:
            db.execute('INSERT INTO creates VALUES(?,?,?,NULL)',
                       (intent.id, core.canonical(dataclasses.asdict(spec)), 'SPEC_SAVED'))
        barrier, first_waiting = threading.Barrier(2), threading.Event()
        quotes = self.api.quotes(expected_currency='EUR')
        class Client:
            creates = 0
            def reconcile_candidate(_, spec): return h.OperationResult('absent')
            def quotes(_, **kw):
                first_waiting.set()
                barrier.wait(timeout=2)
                if threading.current_thread().name.endswith('_1'): time.sleep(.05)
                return quotes
            def _pages(_, *args, **kw): return self.api._pages(*args, **kw)
            def create_candidate(_, spec, quote, authorize=None):
                if authorize: authorize()
                Client.creates += 1
                return h.OperationResult('create_unknown', 'ambiguous_submission')
        self.effects.client_factory = lambda: Client()
        self.effects._scope_anchor = lambda client, state: self.effects.store.read_resource('40')
        def invoke(): return self.run_effect(intent, evidence, record=False)
        with ThreadPoolExecutor(max_workers=2) as pool:
            first = pool.submit(invoke)
            self.assertTrue(first_waiting.wait(timeout=2))
            second = pool.submit(invoke)
            self.assertEqual('unknown', first.result(timeout=3)['outcome'])
            self.assertEqual('unknown', second.result(timeout=3)['outcome'])
        self.assertEqual(1, Client.creates)

    def test_fresh_cleanup_permit_finishes_interrupted_ip_tag_then_deletes(self):
        intent, evidence = self.create_intent()
        self.world.post_timeout = True
        self.run_effect(intent, evidence)
        follow, evidence = self.reconcile_intent()
        self.run_effect(follow, evidence)
        delete, evidence = self.cleanup_intent()
        self.assertEqual('deleted', self.run_effect(delete, evidence)['outcome'])
        self.assertEqual({'40'}, set(self.world.servers))
        self.assertEqual({'61', '62'}, set(self.world.ips))

    def test_readonly_delete_reconcile_and_new_permit_for_residual_ips(self):
        self.candidate()
        delete, evidence = self.cleanup_intent()
        self.world.ip_delete_status = 500
        self.assertEqual('pending', self.run_effect(delete, evidence)['outcome'])
        follow, evidence = self.reconcile_intent()
        self.world.calls = []
        result = self.run_effect(follow, evidence)
        self.assertEqual('cleanup_required', result['outcome'])
        self.assertTrue(all(m == 'GET' for m, _, _ in self.world.calls))
        self.assertEqual('VALIDATING', self.state()['phase'])
        self.world.ip_delete_status = 204
        delete, evidence = self.cleanup_intent()
        self.assertEqual('deleted', self.run_effect(delete, evidence)['outcome'])

    def test_changed_cleanup_or_active_target_never_deletes(self):
        self.candidate()
        delete, evidence = self.cleanup_intent()
        evidence['cleanup']['no_actual_writes'] = False
        self.world.calls = []
        with self.assertRaisesRegex(ProviderEffectError, 'cleanup_evidence'):
            self.run_effect(delete, evidence, record=False)
        self.assertEqual([], self.world.calls)

    def test_network_latency_cannot_rejuvenate_cleanup_evidence(self):
        self.candidate()
        delete, evidence = self.cleanup_intent(evidence_age=25)
        delayed = []
        def latency(method, path):
            if not delayed and (method, path) == ('GET', '/servers/51'):
                delayed.append(True)
                self.now += 6
        self.world.before_request = latency
        self.world.calls = []
        with self.assertRaisesRegex(ProviderEffectError, 'cleanup_evidence'):
            self.run_effect(delete, evidence, record=False)
        self.assertFalse(any(method == 'DELETE' for method, _, _ in self.world.calls))

    def test_expiry_after_server_delete_stops_ip_delete_until_new_permit(self):
        self.candidate()
        delete, evidence = self.cleanup_intent()
        def latency(method, path):
            if '51' not in self.world.servers and (method, path) == ('GET', '/primary_ips/71'):
                self.now += 16
        self.world.before_request = latency
        self.world.calls = []
        with self.assertRaisesRegex(ProviderEffectError, 'expired'):
            self.run_effect(delete, evidence, record=False)
        self.assertNotIn('51', self.world.servers)
        self.assertTrue({'71', '72'} <= set(self.world.ips))
        self.assertFalse(any(method == 'DELETE' and path.startswith('/primary_ips/')
                             for method, path, _ in self.world.calls))

    def test_401_in_delete_reconciliation_is_not_absence(self):
        self.candidate()
        delete, evidence = self.cleanup_intent()
        self.world.disappear_on_delete = False
        self.run_effect(delete, evidence)
        follow, evidence = self.reconcile_intent()
        self.world.forced_status['GET', '/servers/51'] = 401
        self.world.calls = []
        self.assertEqual('unknown', self.run_effect(follow, evidence)['outcome'])
        self.assertTrue(all(m == 'GET' for m, _, _ in self.world.calls))

    def test_retirement_rechecks_all_gates_and_never_deletes_active(self):
        retire, evidence = self.retirement_intent()
        changed = copy.deepcopy(evidence)
        changed['critical_receipts'][0]['ok'] = False
        self.world.calls = []
        with self.assertRaisesRegex(ProviderEffectError, 'retirement_gates'):
            self.run_effect(retire, changed, record=False)
        self.assertEqual([], self.world.calls)
        receipt = self.run_effect(retire, evidence)
        self.assertEqual('deleted', receipt['outcome'])
        self.assertEqual('40', receipt['identity']['provider_id'])
        self.assertEqual({'51'}, set(self.world.servers))
        self.assertEqual({'71', '72'}, set(self.world.ips))
        self.assertTrue(self.effects.store.read_resource('51').has_writes)
        self.assertIsNone(self.state()['retiring'])

    def test_inventory_and_probe_are_exact_id_readonly(self):
        self.candidate()
        self.world.calls = []
        inventory = self.effects.inventory(self.active)
        self.assertEqual(2, inventory['managed_vm_count'])
        target = self.effects.probe_target(self.state()['candidate'])
        self.assertEqual('203.0.113.51', target['ipv4'])
        self.assertTrue(self.effects.image_compatible('100'))
        self.assertTrue(all(m == 'GET' for m, _, _ in self.world.calls))
        self.world.servers['51']['public_net']['ipv4']['id'] = 900
        with self.assertRaisesRegex(ProviderEffectError, 'identity_changed'):
            self.effects.probe_target(self.state()['candidate'])
        with self.assertRaises(ProviderEffectError):
            self.effects.inventory(self.active)

    def test_inventory_unknown_managed_resource_is_not_empty_success(self):
        self.world.servers['90'] = raw_server('90')
        with self.assertRaisesRegex(ProviderEffectError, 'not_durably_adopted'):
            self.effects.inventory(self.active)
        self.world.servers.pop('90')
        self.bridge.result['internal_protected'] = False
        with self.assertRaisesRegex(ProviderEffectError, 'app_binding'):
            self.effects.inventory(self.active)

    def test_inventory_after_promotion_uses_current_credentials_not_seed_describe(self):
        self.retirement_intent()
        active = self.state()['active']
        self.assertEqual('51', active['provider_id'])
        calls = []
        def bridge_request(operation):
            calls.append(operation)
            if operation == 'describe':
                raise ProviderEffectError('original_seed_has_changed')
            return {'ok': True, 'binding': {'owner_id': 1, 'service_id': 711, 'provider_id': '51',
                                           'ipv4': '203.0.113.51'}, 'internal_protected': True,
                    'hetzner_token': 'private-promoted-scope-token'}
        self.bridge.request = bridge_request
        inventory = self.effects.inventory(core.identity(active))
        self.assertTrue(inventory['complete'])
        self.assertEqual(core.identity(active), inventory['active_identity'])
        self.assertEqual(2, inventory['managed_vm_count'])
        self.assertEqual(['credentials'], calls)
        self.assertNotIn('private-promoted-scope-token', repr(inventory))
        with self.assertRaisesRegex(ProviderEffectError, 'original_seed'):
            self.effects.adoption_plan()

    def test_adoption_plan_is_readonly_and_mutation_requires_explicit_setup(self):
        self.world.servers['40']['labels'] = {'keep': 'this'}
        plan = self.effects.adoption_plan()
        self.assertTrue(plan['requires_label_write'])
        self.assertTrue(all(m == 'GET' for m, _, _ in self.world.calls))
        with self.assertRaisesRegex(ProviderEffectError, 'not_enabled'):
            self.effects.adopt(plan['plan_id'])
        # Fresh setup store has no previously captured immutable ownership.
        with self.effects.store.transaction() as db:
            db.execute('DELETE FROM resources')
        self.config['provider']['adoption_enabled'] = True
        result = self.effects.adopt(plan['plan_id'])
        self.assertTrue(result['adopted'])
        self.assertEqual('this', self.world.servers['40']['labels']['keep'])
        self.assertEqual(self.world.servers['40']['labels'], self.world.ips['61']['labels'])
        self.assertTrue(self.effects.store.read_resource('40').has_writes)
        self.assertTrue(self.effects.adopt(plan['plan_id'])['adopted'])

    def test_adoption_rejects_conflicting_ownership_or_post_plan_drift(self):
        self.world.servers['40']['labels']['sn-owner'] = 'other'
        with self.assertRaisesRegex(ProviderEffectError, 'ownership_conflict'):
            self.effects.adoption_plan()
        self.world.servers['40']['labels'] = {}
        plan = self.effects.adoption_plan()
        self.config['provider']['adoption_enabled'] = True
        self.world.servers['40']['public_net']['ipv4']['id'] = 900
        with self.assertRaisesRegex(ProviderEffectError, 'identity_changed'):
            self.effects.adopt(plan['plan_id'])
        self.assertFalse(any(m in ('POST', 'PUT', 'DELETE') for m, _, _ in self.world.calls))


class CredentialBridgeTests(unittest.TestCase):
    def test_fixed_pinned_local_argv_private_stdin_and_sanitized_failures(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            php, script, config = root/'php', root/'bridge.php', root/'config.json'
            for path in (php, script, config):
                path.write_text('fake')
                path.chmod(0o700 if path == php else 0o600)
            entry = dict(php=str(php), php_sha256=hashlib.sha256(php.read_bytes()).hexdigest(),
                         path=str(script), sha256=hashlib.sha256(script.read_bytes()).hexdigest(), config=str(config))
            calls = []
            def runner(argv, data, timeout):
                calls.append((argv, json.loads(data), timeout))
                return 0, b'{"ok":true,"hetzner_token":"sensitive-test-only"}'
            bridge = CredentialBridge({'bridge': entry}, runner=runner)
            self.assertEqual('sensitive-test-only', bridge.request('credentials')['hetzner_token'])
            self.assertEqual(([str(php), str(script), str(config)], {'op': 'credentials'}, 20), calls[0])
            script.write_text('changed-sensitive-test-only')
            with self.assertRaisesRegex(ProviderEffectError, '^bridge_failed$') as error:
                bridge.request('credentials')
            self.assertNotIn('sensitive', str(error.exception))
            self.assertEqual(1, len(calls))
            with self.assertRaises(ProviderEffectError):
                bridge.request('protect')


if __name__ == '__main__':
    unittest.main()
