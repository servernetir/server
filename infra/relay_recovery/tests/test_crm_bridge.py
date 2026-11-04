"""Actual private journals/bundle writer, finite fake PHP pipe; no live app/API."""
import copy
import hashlib
import json
from pathlib import Path
from types import SimpleNamespace
import unittest
from unittest import mock

from infra.relay_recovery import core, crm_bridge, provider_adapter
from infra.relay_recovery.production_driver import ProductionDriver
from infra.relay_recovery.tests.test_promotion_bundle import Fixture, NOW, SOURCE, TARGET, PARENT


class CrmBridgeTests(Fixture):
    def setUp(self):
        super().setUp()
        self.config['binding'] = dict(instance_id=5, owner_id=1, service_id=7001)
        self.config['exit_upstream_ids'] = [7, 8]
        php, script, private = self.root/'php', self.root/'bridge.php', self.root/'php-config.json'
        for path in (php, script, private):
            path.write_text('offline-pinned-fixture')
            path.chmod(0o700 if path == php else 0o600)
        self.config['bridge'] = dict(php=str(php), php_sha256=hashlib.sha256(php.read_bytes()).hexdigest(),
            path=str(script), sha256=hashlib.sha256(script.read_bytes()).hexdigest(), config=str(private))
        self.calls, self.responses = [], []
        self.delay = 0
        self.install_claim()
        self.original_bundle = self.publish()
        self.original_bundle_bytes = self.destination.read_bytes()
        self.provider.calls.clear()
        self.authorizations.clear()
        self.bridge = self.make_bridge()

    def make_bridge(self):
        return crm_bridge.PromotionBridge(self.config, provider=self.provider, runner=self.runner,
                                          clock=lambda: self.now, allow_test_paths=True)

    def install_claim(self):
        with self.driver_store.transaction() as db:
            db.execute('DELETE FROM hook_dispatches')
            body = db.execute('SELECT body FROM steps WHERE id=?', (self.permit['intent_id'],)).fetchone()[0]
            db.execute('INSERT INTO hook_dispatches VALUES(?,?,?)', (self.permit['intent_id'], body, NOW))

    def receipt(self, **changes):
        value = dict(schema='servernet.relay.crm-promotion.v1', intent_id=PARENT, incident_id='incident-1',
            old_identity=copy.deepcopy(SOURCE), new_identity=copy.deepcopy(TARGET), instance_id=5,
            service_id=7001, owner_id=1, ipv4='8.8.4.4', ipv6=None, location_code='de-falkenstein',
            config_hash=self.context['config_hash'], gate_hash=self.context['gate_hash'], checkpoint='cp-1',
            exit_upstream_ids=[7, 8], committed_at=NOW,
            continuation_id=self.permit['intent_id'], continuation_sha256=core.digest(self.permit),
            upstream_bindings=[
                dict(id=7, role='relay', type='ssh', host='8.8.4.4', port=22, enabled=True,
                     link_host=None, link_port=None),
                dict(id=8, role='exit', type='vless', host='', port=None, enabled=False,
                     link_host='relay.example.com', link_port=443)])
        value.update(changes)
        return value

    def result(self, *, read_only=True, **changes):
        value = dict(ok=True, outcome='promoted', changed=not read_only, receipt=self.receipt(),
            read_only=read_only, observed_at=self.now, received_at=self.now, actual_upstreams_verified=True)
        value.update(changes)
        return value

    def runner(self, argv, data, timeout):
        request = json.loads(data)
        self.calls.append((argv, request, timeout))
        self.now += self.delay
        value = self.responses.pop(0) if self.responses else self.result(read_only=request['op'] == 'reconcile_promotion')
        if isinstance(value, bytes):
            return 0, value
        return 0, json.dumps(value).encode()

    def apply(self, **changes):
        return self.bridge.apply(changes.get('permit', self.permit), changes.get('context', self.context),
                                 changes.get('authorize', self.authorize))

    def reconcile(self, **changes):
        return self.bridge.reconcile(changes.get('permit', self.permit), changes.get('context', self.context))

    def reject(self, action):
        with self.assertRaisesRegex(crm_bridge.CrmBridgeError, '^crm_bridge_failed$') as error:
            action()
        self.assertNotIn('sensitive', str(error.exception))

    def test_actual_bundle_then_finite_private_request_without_completion_ack(self):
        result = self.apply()
        self.assertEqual({'state': 'dispatched', 'intent_id': self.permit['intent_id']}, result)
        self.assertNotIn('verified', result)
        self.assertTrue(self.destination.is_file())
        self.assertEqual(0o600, self.destination.stat().st_mode & 0o777)
        self.assertEqual({'op': 'promote', 'intent_id': PARENT, 'continuation': self.permit}, self.calls[0][1])
        self.assertEqual([self.config['bridge'][k] for k in ('php', 'path', 'config')], self.calls[0][0])
        self.assertLessEqual(self.calls[0][2], 20)
        self.assertGreaterEqual(len(self.authorizations), 3)

    def test_actual_readback_becomes_native_driver_observation(self):
        result = self.reconcile()
        driver = SimpleNamespace(now=lambda: self.now, policy=SimpleNamespace(receipt_max_age=90))
        self.assertTrue(ProductionDriver._observed(driver, result, 'crm_promotion', self.context))
        self.assertEqual('complete', result['state'])
        self.assertTrue(result['panel_committed'])
        self.assertEqual(self.permit['intent_id'], result['intent_id'])
        receipt = result['resource_proofs'][0]['proof']
        self.assertEqual(PARENT, receipt['intent_id'])
        self.assertNotIn('restore_epoch', receipt)
        self.assertNotIn('restore_hash', receipt)
        self.assertEqual(core.digest(receipt), result['resource_proofs'][0]['proof_sha256'])
        self.assertEqual(self.original_bundle_bytes, self.destination.read_bytes())
        self.assertEqual([], self.provider.calls)
        self.assertEqual({'op': 'reconcile_promotion', 'intent_id': PARENT}, self.calls[0][1])

    def test_expired_disabled_already_active_legacy_readback_needs_no_fresh_bundle(self):
        self.now += 100
        self.config['policy']['enabled'] = self.config['production_driver']['enabled'] = False
        self.state.update(phase='HEALTHY', pending_action=None, active={**TARGET,
            'config_hash': self.context['config_hash']}, candidate=None)
        self.save_core()
        with self.driver_store.transaction() as db:
            db.execute('DELETE FROM hook_dispatches')
        self.destination.unlink()
        receipt = self.receipt()
        for key in crm_bridge.CONTINUATION_FIELDS:
            del receipt[key]
        self.responses = [self.result(receipt=receipt)]
        self.bridge = self.make_bridge()
        result = self.reconcile()
        self.assertTrue(result['panel_committed'])
        self.assertEqual([], self.provider.calls)
        self.assertFalse(self.destination.exists())

    def test_not_promoted_is_pending_and_not_verified_success(self):
        self.responses = [dict(ok=True, outcome='not_promoted', changed=False)]
        self.assertEqual({'state': 'pending', 'intent_id': self.permit['intent_id']}, self.reconcile())

    def test_missing_or_changed_dispatch_claim_blocks_mutation_but_not_reconcile(self):
        with self.driver_store.transaction() as db:
            db.execute('DELETE FROM hook_dispatches')
        self.reject(self.apply)
        self.assertEqual([], self.calls)
        self.assertEqual(self.original_bundle_bytes, self.destination.read_bytes())
        self.assertEqual('complete', self.reconcile()['state'])
        self.install_claim()
        with self.driver_store.transaction() as db:
            # Construct an inconsistent historical fixture; no production
            # update/delete API is supplied by the wrapper.
            db.execute('DELETE FROM hook_dispatches')
            db.execute('INSERT INTO hook_dispatches VALUES(?,?,?)', (self.permit['intent_id'], '{}', NOW))
        self.reject(self.apply)

    def test_wrong_context_permit_or_durable_phase_blocks_before_php(self):
        for changes in ({'kind': 'EXTERNAL_CUTOVER'}, {'context_hash': '0'*64}, {'expires_at': NOW},
                        {'created_at': True}, {'created_at': NOW+1}, {'intent_id': 'not-a-uuid'}):
            with self.subTest(changes=changes):
                self.reject(lambda: self.apply(permit={**self.permit, **changes}))
        self.reject(lambda: self.apply(context={**self.context, 'checkpoint': 'other'}))
        self.save_driver(crm_phase='COMMITTED')
        self.reject(self.reconcile)
        self.assertEqual([], self.calls)

    def test_authorizer_and_original_bundle_gates_are_mandatory(self):
        self.reject(lambda: self.apply(authorize=lambda _: False))
        self.evidence['backup']['durable'] = False
        self.rebind_gate(); self.install_claim()
        self.reject(self.apply)
        self.assertEqual([], self.calls)

    def test_expiry_during_local_pin_reads_prevents_process_dispatch(self):
        original = provider_adapter._pinned
        def pinned(path, digest, **kwargs):
            value = original(path, digest, **kwargs)
            if path == self.config['bridge']['path']:
                self.now += 30
            return value
        with mock.patch.object(provider_adapter, '_pinned', side_effect=pinned):
            self.reject(self.apply)
        self.assertEqual([], self.calls)

    def test_shorter_origin_deadline_bounds_the_php_process_timeout(self):
        self.add_cloudflare(observed_at=NOW-10)
        self.install_claim()
        self.bridge = self.make_bridge()
        self.apply()
        self.assertEqual(5, self.calls[0][2])

    def test_legacy_receipt_without_continuation_uses_original_parent_window(self):
        receipt = self.receipt(committed_at=NOW-2)
        for key in crm_bridge.CONTINUATION_FIELDS:
            del receipt[key]
        self.responses = [self.result(receipt=receipt)]
        self.assertEqual('complete', self.reconcile()['state'])
        self.action['payload']['checkpoint'] = 'changed'
        self.save_core()
        self.responses = [self.result(receipt=receipt)]
        self.reject(self.reconcile)

    def test_new_claim_needs_retained_original_bundle_only_for_promoted_completion(self):
        self.destination.unlink()
        self.responses = [dict(ok=True, outcome='not_promoted', changed=False)]
        self.assertEqual('pending', self.reconcile()['state'])
        self.reject(self.reconcile)
        self.assertFalse(self.destination.exists())

    def test_actual_bundle_addresses_and_location_bind_mutation_and_readback(self):
        for field, value in (('ipv4', '1.1.1.1'), ('ipv6', '2001:4860:4860::8888'),
                             ('location_code', 'fi-helsinki')):
            with self.subTest(field=field):
                receipt = self.receipt(**{field: value})
                if field == 'ipv4': receipt['upstream_bindings'][0]['host'] = value
                self.responses = [self.result(read_only=False, receipt=receipt)]
                self.reject(self.apply)
                self.responses = [self.result(receipt=receipt)]
                self.reject(self.reconcile)

    def test_response_identity_and_id_numbers_do_not_coerce_bool_or_float(self):
        for changes in ({'old_identity': {**SOURCE, 'generation': True}},
                        {'new_identity': {**TARGET, 'generation': 2.0}}, {'exit_upstream_ids': [7.0, 8]}):
            with self.subTest(changes=changes):
                self.responses = [self.result(receipt=self.receipt(**changes))]
                self.reject(self.reconcile)

    def test_expired_commit_ack_stays_unknown_then_only_readonly_reconciliation(self):
        self.delay = 30
        self.reject(self.apply)
        self.assertEqual(1, len(self.calls))
        self.assertEqual('promote', self.calls[0][1]['op'])
        self.delay = 0
        self.assertEqual('complete', self.reconcile()['state'])
        self.assertEqual(['promote', 'reconcile_promotion'], [c[1]['op'] for c in self.calls])

    def test_private_php_pins_and_fixed_errors_do_not_leak_stdout(self):
        Path(self.config['bridge']['path']).write_text('changed-sensitive-fixture')
        self.reject(self.reconcile)
        self.assertEqual([], self.calls)

    def test_untrusted_or_oversized_json_never_becomes_a_receipt(self):
        values = [b'{"ok":true,"ok":false}', b'{"ok":NaN}', b'x'*65537,
                  b'{"ok":false,"error":"sensitive-test-only"}', b'not-json-sensitive']
        for value in values:
            with self.subTest(value=value[:20]):
                self.responses = [value]
                self.reject(self.reconcile)

    def test_response_whitelist_rejects_credentials_and_mutation_as_readback(self):
        for changes in ({'secret': 'sensitive-test-only'}, {'read_only': False}, {'changed': True},
                        {'actual_upstreams_verified': False}, {'observed_at': NOW-31}, {'observed_at': NOW-1},
                        {'received_at': NOW+1}, {'observed_at': NOW+1}):
            with self.subTest(changes=changes):
                self.responses = [self.result(**changes)]
                self.reject(self.reconcile)

    def test_receipt_subject_original_claim_and_panel_scope_are_exact(self):
        for changes in ({'intent_id': 'b'*40}, {'new_identity': SOURCE}, {'old_identity': TARGET},
                        {'owner_id': 2}, {'service_id': 7002}, {'instance_id': True}, {'instance_id': 6},
                        {'checkpoint': 'other'}, {'gate_hash': '0'*64}, {'config_hash': '0'*64},
                        {'committed_at': NOW-2}, {'committed_at': NOW+26},
                        {'continuation_id': 'other'}, {'continuation_sha256': '0'*64},
                        {'exit_upstream_ids': [8, 7]}, {'secret': 'sensitive-test-only'}):
            with self.subTest(changes=changes):
                self.responses = [self.result(receipt=self.receipt(**changes))]
                self.reject(self.reconcile)

    def test_native_link_and_host_routes_allow_dns_and_disabled_rows_but_reject_drift(self):
        self.assertEqual('complete', self.reconcile()['state'])
        for change in ('id', 'host', 'link_port', 'missing', 'credential'):
            with self.subTest(change=change):
                receipt = self.receipt()
                if change == 'id': receipt['upstream_bindings'][0]['id'] = 8
                if change == 'host': receipt['upstream_bindings'][0]['host'] = '1.1.1.1'
                if change == 'link_port': receipt['upstream_bindings'][1]['link_port'] = True
                if change == 'missing': receipt['upstream_bindings'].pop()
                if change == 'credential': receipt['upstream_bindings'][1]['secret'] = 'sensitive-test-only'
                self.responses = [self.result(receipt=receipt)]
                self.reject(self.reconcile)

    def test_registry_panel_binding_and_context_cannot_broaden_private_php_selection(self):
        self.config['exit_upstream_ids'] = [7, 8, 9]
        self.bridge = self.make_bridge()
        self.reject(self.reconcile)
        self.assertEqual([], self.calls)

    def test_existing_credential_api_cannot_dispatch_promotion_or_protection(self):
        for op in ('promote', 'reconcile_promotion', 'protect'):
            with self.assertRaises(provider_adapter.ProviderEffectError):
                self.bridge.pipe.request(op)
        self.assertEqual([], self.calls)

    def test_private_transport_also_rejects_arbitrary_ops_and_unguarded_promotion(self):
        for request in ({'op': 'protect'}, {'op': 'describe', 'extra': 'ignored'},
                        {'op': 'reconcile_promotion', 'intent_id': 'arbitrary'},
                        {'op': 'promote', 'intent_id': PARENT, 'continuation': self.permit}):
            with self.subTest(request=request):
                with self.assertRaises(provider_adapter.ProviderEffectError):
                    self.bridge.pipe._exchange(request)
        self.assertEqual([], self.calls)


if __name__ == '__main__':
    unittest.main()
