"""Offline stateful snapshot API tests. No credentials or real HTTP calls."""
from __future__ import annotations

import copy
from datetime import datetime, timezone
from decimal import Decimal
import importlib.util
import json
from pathlib import Path
import sys
import unittest
from urllib.parse import urlsplit
import uuid

INFRA = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(INFRA))
from relay_recovery import core, hetzner as h
from relay_recovery.snapshot_adapter import SnapshotAdapter, SnapshotError

SPEC = importlib.util.spec_from_file_location('snapshot_provider_fixture', Path(__file__).with_name('test_provider_adapter.py'))
fixture = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = fixture
SPEC.loader.exec_module(fixture)


class SnapshotWorld:
    def __init__(self, fixture_test):
        self.test = fixture_test
        self.base = fixture_test.world
        self.calls = self.base.calls
        self.actions, self.images = {}, {}
        self.timeout_commands = set()
        self.response_status = {}
        self.shutdown_off, self.image_status, self.action_status = True, 'available', 'success'
        self.delete_disappears, self.rebuild_ip_drift = True, False
        self.before_request = None
        self.snapshot_net = '0.01'

    def decorate(self, raw):
        raw.setdefault('primary_disk_size', 40)
        raw['server_type'].setdefault('architecture', 'x86')
        raw['server_type'].setdefault('disk', 20)  # Actual disk deliberately differs from plan.
        raw['public_net']['ipv6'].setdefault('ip', '2001:db8:'+str(raw['id'])+'::/64')
        return raw

    def action(self, command, server_id, image_id=None):
        aid = str(500+len(self.actions))
        value = {'id': int(aid), 'command': command, 'status': self.action_status,
                 'started': datetime.fromtimestamp(self.test.now, timezone.utc).isoformat(),
                 'resources': [{'id': int(server_id), 'type': 'server'}], 'error': None}
        if image_id is not None: value['resources'].append({'id': int(image_id), 'type': 'image'})
        self.actions[aid] = value
        return copy.deepcopy(value)

    def __call__(self, method, url, body):
        path = urlsplit(url).path.removeprefix('/v1')
        if self.before_request: self.before_request(method, path, body)
        if (method, path) in self.response_status:
            self.calls.append((method, path, copy.deepcopy(body)))
            return h.Response(self.response_status[method, path], {})
        meta = {'pagination': {'next_page': None}}
        parts = path.split('/')
        if method == 'GET' and path == '/pricing':
            response = self.base(method, url, body)
            response.body['pricing']['image'] = {'price_per_gb_month': {
                'net': self.snapshot_net, 'gross': str(Decimal(self.snapshot_net)*Decimal('1.19'))}}
            return response
        if method == 'GET' and len(parts) == 4 and parts[1] == 'servers' and parts[3] == 'actions':
            self.calls.append((method, path, copy.deepcopy(body)))
            actions = [copy.deepcopy(a) for a in self.actions.values()
                       if any(r.get('type') == 'server' and str(r.get('id')) == parts[2] for r in a['resources'])]
            return h.Response(200, {'actions': actions, 'meta': meta})
        if method == 'GET' and len(parts) == 3 and parts[1] == 'actions':
            self.calls.append((method, path, copy.deepcopy(body)))
            return h.Response(200, {'action': copy.deepcopy(self.actions[parts[2]])}) if parts[2] in self.actions else h.Response(404, {})
        if method == 'POST' and len(parts) == 5 and parts[1] == 'servers' and parts[3] == 'actions':
            self.calls.append((method, path, copy.deepcopy(body)))
            server_id, operation = parts[2], parts[4]
            server = self.decorate(self.base.servers[server_id])
            if operation == 'shutdown':
                action = self.action('shutdown_server', server_id)
                if self.shutdown_off: server['status'] = 'off'
                response = {'action': action}
            elif operation == 'create_image':
                image_id = '201'
                image = {'id': 201, 'type': body['type'], 'description': body['description'], 'labels': body['labels'],
                         'created_from': {'id': int(server_id), 'name': server['name']}, 'bound_to': None,
                         'architecture': 'x86', 'disk_size': 40, 'image_size': 10.5, 'status': self.image_status,
                         'deprecation': None, 'deleted': None, 'protection': {'delete': False}}
                self.images[image_id] = copy.deepcopy(image)
                response = {'action': self.action('create_image', server_id, image_id), 'image': image}
            elif operation == 'rebuild':
                server['image'] = {'id': body['image']}
                server['status'] = 'running'
                if self.rebuild_ip_drift: server['public_net']['ipv4']['ip'] = '203.0.113.99'
                response = {'action': self.action('rebuild_server', server_id), 'root_password': 'provider-secret-never-return'}
            else:
                raise AssertionError('Unapproved operation: '+operation)
            if operation in self.timeout_commands: raise h.TransportUnknown('timeout_after_actual_effect')
            return h.Response(201, copy.deepcopy(response))
        if method == 'GET' and path == '/images':
            self.calls.append((method, path, copy.deepcopy(body)))
            return h.Response(200, {'images': copy.deepcopy(list(self.images.values())), 'meta': meta})
        if len(parts) == 3 and parts[1] == 'images' and parts[2] != '100':
            self.calls.append((method, path, copy.deepcopy(body)))
            image_id = parts[2]
            if method == 'GET':
                return h.Response(200, {'image': copy.deepcopy(self.images[image_id])}) if image_id in self.images else h.Response(404, {})
            if method == 'DELETE':
                if self.delete_disappears: self.images.pop(image_id, None)
                return h.Response(204, {})
        if method == 'GET' and len(parts) == 4 and parts[1] == 'images' and parts[3] == 'actions':
            self.calls.append((method, path, copy.deepcopy(body)))
            return h.Response(200, {'actions': [], 'meta': meta})
        response = self.base(method, url, body)
        if method == 'GET' and len(parts) == 3 and parts[1] == 'servers' and response.status == 200:
            response.body['server'] = self.decorate(response.body['server'])
        return response


class SnapshotTests(unittest.TestCase):
    def setUp(self):
        self.f = fixture.ProviderEffectsTests()
        self.f.setUp()
        self.addCleanup(self.f.doCleanups)
        with self.f.journal.transaction() as db:
            state = self.f.journal._read(db)
            state['active']['config_hash'] = 'a'*64
            self.f.journal._write(db, state)
        self.world = SnapshotWorld(self.f)
        self.f.api = h.Hetzner(self.world, now=lambda: self.f.now)
        self.f.effects.client_factory = lambda: self.f.api
        self.authorizations = []
        self.adapter = SnapshotAdapter(self.f.effects, now=lambda: self.f.now,
                                       authorize=lambda permit: self.authorizations.append(permit))
        self.workflow = str(uuid.uuid4())

    def permit(self, kind, **extra):
        result = dict(intent_id=str(uuid.uuid4()), workflow_id=self.workflow, kind=kind,
            incident_id='relay-incident-1', source_identity=core.identity(self.f.state()['active']),
            config_hash='a'*64, created_at=self.f.now, expires_at=self.f.now+30)
        result.update(extra)
        return result

    def shutdown(self):
        permit = self.permit('PREPARE_SHUTDOWN')
        self.assertEqual('pending', self.adapter.begin_shutdown(permit)['state'])
        result = self.adapter.reconcile(permit['intent_id'])
        self.assertEqual('complete', result['state'])
        self.assertTrue(result['server_off'])
        return permit

    def snapshot(self):
        shutdown = self.shutdown()
        permit = self.permit('PREPARE_SNAPSHOT', currency='EUR', max_storage_monthly_micro=1_000_000)
        self.assertEqual('pending', self.adapter.begin_snapshot(permit, shutdown['intent_id'])['state'])
        result = self.adapter.reconcile(permit['intent_id'])
        self.assertEqual('complete', result['state'])
        return permit, result

    def candidate(self):
        return self.f.candidate()

    def rebuild_permit(self):
        return self.permit('PREPARE_REBUILD', candidate_identity=core.identity(self.f.state()['candidate']),
                           candidate_no_writes=True, candidate_never_promoted=True)

    def coverage(self, snapshot, **extra):
        result = dict(verified=True, received_at=self.f.now, snapshot_id='201',
            source_identity=snapshot['source_identity'], config_hash=snapshot['config_hash'],
            durable=True, restore_verified=True, covers_snapshot=True, restore_in_use=False, coverage_id='durable-checkpoint-2')
        result.update(extra)
        return result

    def test_no_promote_or_missing_callback_can_authorize_preparation(self):
        with self.assertRaisesRegex(SnapshotError, 'explicit_workflow'):
            self.adapter.begin_shutdown(self.permit('PROMOTE'))
        disabled = SnapshotAdapter(self.f.effects, now=lambda:self.f.now)
        with self.assertRaisesRegex(SnapshotError, 'writes_disabled'):
            disabled.begin_shutdown(self.permit('PREPARE_SHUTDOWN'))
        self.assertEqual([], self.world.calls)

    def test_shutdown_is_graceful_and_needs_successful_action_and_actual_off(self):
        self.world.shutdown_off = False
        permit = self.permit('PREPARE_SHUTDOWN')
        self.adapter.begin_shutdown(permit)
        result = self.adapter.reconcile(permit['intent_id'])
        self.assertEqual('pending', result['state'])
        self.assertFalse(result['server_off'])
        self.world.base.servers['40']['status'] = 'off'
        self.f.now += 5
        self.assertEqual('complete', self.adapter.reconcile(permit['intent_id'])['state'])
        self.assertFalse(any('poweroff' in path for _,path,_ in self.world.calls))

    def test_durable_claim_precedes_every_post_and_same_intent_never_resubmits(self):
        permit = self.permit('PREPARE_SHUTDOWN')
        observed = []
        def inspect(method, path, body):
            if method == 'POST':
                row = self.adapter.store.operation(permit['intent_id'])
                observed.append(row['phase'])
                self.assertEqual('40', json.loads(row['body'])['spec']['target_id'])
        self.world.before_request = inspect
        self.adapter.begin_shutdown(permit)
        self.adapter.begin_shutdown(permit)
        self.adapter.begin_shutdown(permit)
        self.assertEqual(['CLAIMED'], observed)
        self.assertEqual(1, sum(m == 'POST' for m,_,_ in self.world.calls))

    def test_timeout_shutdown_reconciles_readonly_and_never_falls_back_to_hard_off(self):
        self.world.timeout_commands.add('shutdown')
        permit = self.permit('PREPARE_SHUTDOWN')
        self.assertEqual('unknown', self.adapter.begin_shutdown(permit)['state'])
        self.world.calls.clear()
        result = self.adapter.reconcile(permit['intent_id'])
        self.assertEqual('complete', result['state'])
        self.assertTrue(all(m == 'GET' for m,_,_ in self.world.calls))

    def test_snapshot_reserves_actual_full_disk_and_captures_storage_cost(self):
        permit, result = self.snapshot()
        record = result['snapshot']
        self.assertEqual(('201', '40', '10.5'), (record['id'], record['disk_size'], record['image_size']))
        self.assertEqual(124_950, record['monthly_micro'])
        row = self.adapter.store.operation(permit['intent_id'])
        quote = json.loads(row['body'])['spec']['storage_reservation']
        self.assertEqual(('40', 476_000), (quote['reserved_gb'], quote['reserved_monthly_micro']))
        self.assertEqual('201', row['image_id'])
        self.assertEqual(124_950,self.adapter.storage_inventory()['reserved_monthly_micro'])

    def test_aggregate_retained_snapshot_reservations_cannot_exceed_cap(self):
        self.snapshot()
        self.workflow=str(uuid.uuid4())
        shutdown=self.permit('PREPARE_SHUTDOWN')
        self.assertEqual('complete',self.adapter.begin_shutdown(shutdown)['state'])
        before=sum(m=='POST' for m,_,_ in self.world.calls)
        permit=self.permit('PREPARE_SNAPSHOT',currency='EUR',max_storage_monthly_micro=500_000)
        with self.assertRaisesRegex(SnapshotError,'aggregate_snapshot_storage'):
            self.adapter.begin_snapshot(permit,shutdown['intent_id'])
        self.assertEqual(before,sum(m=='POST' for m,_,_ in self.world.calls))

    def test_new_purchase_revalues_retained_storage_at_current_price(self):
        self.snapshot()
        self.workflow=str(uuid.uuid4())
        shutdown=self.permit('PREPARE_SHUTDOWN')
        self.adapter.begin_shutdown(shutdown)
        self.world.snapshot_net='0.1'
        permit=self.permit('PREPARE_SNAPSHOT',currency='EUR',max_storage_monthly_micro=5_000_000)
        before=sum(m=='POST' for m,_,_ in self.world.calls)
        with self.assertRaisesRegex(SnapshotError,'aggregate_snapshot_storage'):
            self.adapter.begin_snapshot(permit,shutdown['intent_id'])
        self.assertEqual(before,sum(m=='POST' for m,_,_ in self.world.calls))

    def test_snapshot_timeout_reconciles_action_and_exact_labelled_image_only_gets(self):
        shutdown = self.shutdown()
        self.world.timeout_commands.add('create_image')
        permit = self.permit('PREPARE_SNAPSHOT', currency='EUR', max_storage_monthly_micro=1_000_000)
        self.assertEqual('unknown', self.adapter.begin_snapshot(permit, shutdown['intent_id'])['state'])
        self.world.calls.clear()
        result = self.adapter.reconcile(permit['intent_id'])
        self.assertEqual('complete', result['state'])
        self.assertEqual('201', result['image_id'])
        self.assertTrue(all(m == 'GET' for m,_,_ in self.world.calls))

    def test_unknown_snapshot_absence_and_new_uuid_do_not_buy_again(self):
        shutdown = self.shutdown()
        permit = self.permit('PREPARE_SNAPSHOT', currency='EUR', max_storage_monthly_micro=1_000_000)
        self.world.response_status['POST','/servers/40/actions/create_image'] = 503
        self.adapter.begin_snapshot(permit, shutdown['intent_id'])
        self.world.calls.clear()
        self.assertEqual('unknown', self.adapter.reconcile(permit['intent_id'])['state'])
        self.assertTrue(all(m == 'GET' for m,_,_ in self.world.calls))
        with self.assertRaisesRegex(SnapshotError, 'previous_action'):
            self.adapter.begin_snapshot(self.permit('PREPARE_SNAPSHOT',currency='EUR',max_storage_monthly_micro=1_000_000),
                                        shutdown['intent_id'])
        self.assertTrue(all(m == 'GET' for m,_,_ in self.world.calls))

    def test_definitive_rejection_allows_only_a_new_authorized_uuid_retry(self):
        first=self.permit('PREPARE_SHUTDOWN')
        self.world.response_status['POST','/servers/40/actions/shutdown']=400
        self.assertEqual('failed',self.adapter.begin_shutdown(first)['state'])
        self.world.response_status.pop(('POST','/servers/40/actions/shutdown'))
        self.assertEqual('failed',self.adapter.begin_shutdown(first)['state'])
        second=self.permit('PREPARE_SHUTDOWN')
        self.assertEqual('pending',self.adapter.begin_shutdown(second)['state'])
        self.assertEqual('complete',self.adapter.reconcile(second['intent_id'])['state'])
        self.assertEqual(2,sum(m=='POST' for m,_,_ in self.world.calls))

    def test_label_source_id_and_action_conflicts_remain_unproven(self):
        shutdown = self.shutdown()
        permit = self.permit('PREPARE_SNAPSHOT',currency='EUR',max_storage_monthly_micro=1_000_000)
        self.adapter.begin_snapshot(permit,shutdown['intent_id'])
        self.world.images['201']['created_from']['id'] = 999
        self.assertEqual('unknown',self.adapter.reconcile(permit['intent_id'])['state'])
        self.f.now += 5
        self.world.images['201']['created_from']['id'] = 40
        self.world.images['201']['labels']['sn-owner'] = 'other'
        self.assertEqual('unknown',self.adapter.reconcile(permit['intent_id'])['state'])

    def test_budget_currency_and_attached_volume_are_hard_blockers(self):
        shutdown = self.shutdown()
        with self.assertRaisesRegex(SnapshotError,'budget'):
            self.adapter.begin_snapshot(self.permit('PREPARE_SNAPSHOT',currency='EUR',max_storage_monthly_micro=1),shutdown['intent_id'])
        self.world.base.tariff.currency = 'USD'
        with self.assertRaisesRegex(SnapshotError,'currency'):
            self.adapter.quote_storage(40)
        self.world.base.tariff.currency = 'EUR'
        self.world.base.servers['40']['volumes'] = [100]
        with self.assertRaisesRegex(SnapshotError,'volumes'):
            self.adapter.begin_snapshot(self.permit('PREPARE_SNAPSHOT',currency='EUR',max_storage_monthly_micro=1_000_000),shutdown['intent_id'])
        self.assertEqual(1,sum(m=='POST' for m,_,_ in self.world.calls))

    def test_rebuild_uses_same_candidate_and_explicit_cloud_init_preserves_ips(self):
        self.candidate()
        snapshot,_ = self.snapshot()
        permit = self.rebuild_permit()
        before = copy.deepcopy(self.world.base.servers['51']['public_net'])
        secret = '#cloud-config\nprivate-value: do-not-store-plaintext\n'
        result = self.adapter.begin_rebuild(permit,snapshot['intent_id'],secret)
        self.assertEqual('pending',result['state'])
        result = self.adapter.reconcile(permit['intent_id'])
        self.assertEqual('complete',result['state'])
        self.assertTrue(result['public_ips_preserved'])
        self.assertFalse(result['application_verified'])
        self.assertEqual(before['ipv4'],self.world.base.servers['51']['public_net']['ipv4'])
        posts=[(p,b) for m,p,b in self.world.calls if m=='POST' and p.endswith('/rebuild')]
        self.assertEqual([('/servers/51/actions/rebuild',{'image':201,'user_data':secret})],posts)
        row=self.adapter.store.operation(permit['intent_id'])
        self.assertNotIn(secret,row['body'])
        self.assertNotIn('provider-secret',repr(result))

    def test_rebuild_timeout_reconcile_is_readonly_and_ip_drift_never_success(self):
        self.candidate()
        snapshot,_=self.snapshot()
        self.world.timeout_commands.add('rebuild')
        permit=self.rebuild_permit()
        self.assertEqual('unknown',self.adapter.begin_rebuild(permit,snapshot['intent_id'],'#cloud-config\n')['state'])
        self.world.calls.clear()
        self.world.base.servers['51']['public_net']['ipv6']['ip']='2001:db8:99::/64'
        self.assertEqual('unknown',self.adapter.reconcile(permit['intent_id'])['state'])
        self.assertTrue(all(m=='GET' for m,_,_ in self.world.calls))

    def test_insufficient_candidate_disk_and_active_target_cannot_rebuild(self):
        self.candidate()
        snapshot,_=self.snapshot()
        self.world.base.servers['51']['primary_disk_size']=20
        with self.assertRaisesRegex(SnapshotError,'incompatible'):
            self.adapter.begin_rebuild(self.rebuild_permit(),snapshot['intent_id'],'#cloud-config\n')
        permit=self.rebuild_permit();permit['candidate_identity']=permit['source_identity']
        with self.assertRaisesRegex(SnapshotError,'not_current_candidate'):
            self.adapter.begin_rebuild(permit,snapshot['intent_id'],'#cloud-config\n')
        self.assertFalse(any(m=='POST' and p.endswith('/rebuild') for m,p,_ in self.world.calls))

    def test_delete_exact_snapshot_needs_external_coverage_and_404_proof(self):
        snapshot,_=self.snapshot()
        permit=self.permit('DELETE_SNAPSHOT')
        coverage=self.coverage(snapshot)
        result=self.adapter.delete_snapshot(permit,snapshot['intent_id'],coverage)
        self.assertEqual('complete',result['state'])
        self.assertTrue(result['absence_verified'])
        self.assertEqual({},self.world.images)
        self.assertEqual([('/images/201',None)],[(p,b) for m,p,b in self.world.calls if m=='DELETE'])
        self.assertEqual(0,self.adapter.storage_inventory()['reserved_monthly_micro'])

    def test_partial_failed_image_is_captured_and_can_be_cleaned_with_fresh_coverage(self):
        shutdown=self.shutdown()
        self.world.action_status,self.world.image_status='error','creating'
        snapshot=self.permit('PREPARE_SNAPSHOT',currency='EUR',max_storage_monthly_micro=1_000_000)
        self.adapter.begin_snapshot(snapshot,shutdown['intent_id'])
        self.assertEqual('failed',self.adapter.reconcile(snapshot['intent_id'])['state'])
        self.assertEqual(476_000,self.adapter.storage_inventory()['reserved_monthly_micro'])
        result=self.adapter.delete_snapshot(self.permit('DELETE_SNAPSHOT'),snapshot['intent_id'],self.coverage(snapshot))
        self.assertTrue(result['absence_verified'])
        self.assertEqual(0,self.adapter.storage_inventory()['reserved_monthly_micro'])

    def test_unknown_snapshot_deletion_prevents_a_new_restore_claim(self):
        self.candidate()
        snapshot,_=self.snapshot()
        self.world.delete_disappears=False
        result=self.adapter.delete_snapshot(self.permit('DELETE_SNAPSHOT'),snapshot['intent_id'],self.coverage(snapshot))
        self.assertEqual('present',result['state'])
        with self.assertRaisesRegex(SnapshotError,'deletion_outcome_unknown'):
            self.adapter.begin_rebuild(self.rebuild_permit(),snapshot['intent_id'],'#cloud-config\n')
        self.assertFalse(any(m=='POST' and p.endswith('/rebuild') for m,p,_ in self.world.calls))

    def test_delete_ack_without_absence_and_401_are_not_proofs(self):
        snapshot,_=self.snapshot()
        self.world.delete_disappears=False
        permit=self.permit('DELETE_SNAPSHOT')
        result=self.adapter.delete_snapshot(permit,snapshot['intent_id'],self.coverage(snapshot))
        self.assertEqual('present',result['state'])
        self.f.now+=5
        self.world.response_status['GET','/images/201']=401
        self.world.calls.clear()
        self.assertEqual('unknown',self.adapter.reconcile(permit['intent_id'])['state'])
        self.assertTrue(all(m=='GET' for m,_,_ in self.world.calls))

    def test_inflight_restore_stale_coverage_and_wrong_project_block_delete(self):
        self.candidate()
        snapshot,_=self.snapshot()
        rebuild=self.rebuild_permit()
        self.world.action_status='running'
        self.adapter.begin_rebuild(rebuild,snapshot['intent_id'],'#cloud-config\n')
        with self.assertRaisesRegex(SnapshotError,'used_by_restore'):
            self.adapter.delete_snapshot(self.permit('DELETE_SNAPSHOT'),snapshot['intent_id'],self.coverage(snapshot))
        with self.assertRaisesRegex(SnapshotError,'coverage'):
            self.adapter.delete_snapshot(self.permit('DELETE_SNAPSHOT'),snapshot['intent_id'],self.coverage(snapshot,received_at=self.f.now-31))
        self.world.response_status['GET','/servers/40']=404
        with self.assertRaises(h.AdapterError):
            self.adapter.delete_snapshot(self.permit('DELETE_SNAPSHOT'),snapshot['intent_id'],self.coverage(snapshot))
        self.assertFalse(any(m=='DELETE' for m,_,_ in self.world.calls))

    def test_authority_expiry_after_slow_reads_never_posts(self):
        permit=self.permit('PREPARE_SHUTDOWN')
        def latency(method,path,body):
            if path=='/servers/40/actions' and method=='GET':self.f.now+=31
        self.world.before_request=latency
        with self.assertRaisesRegex(SnapshotError,'expired'):
            self.adapter.begin_shutdown(permit)
        self.assertFalse(any(m=='POST' for m,_,_ in self.world.calls))
        self.assertEqual('not_submitted',self.adapter.reconcile(permit['intent_id'])['state'])

    def test_authorization_callback_cannot_return_after_expiry_then_write(self):
        permit=self.permit('PREPARE_SHUTDOWN')
        self.adapter.authorize=lambda p:setattr(self.f,'now',self.f.now+31)
        with self.assertRaisesRegex(SnapshotError,'expired'):
            self.adapter.begin_shutdown(permit)
        self.assertEqual([],self.world.calls)


if __name__=='__main__':unittest.main()
