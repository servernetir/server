"""Offline resource integration tests; all HTTP/provider/SSH transports fake."""
import base64
import copy
import hashlib
import os
from pathlib import Path
import sqlite3
import tempfile
from types import SimpleNamespace
import unittest
import uuid

from infra.relay_recovery import core, production_hooks as hooks
from infra.relay_recovery.protocol import ReplayStore, canonical_json, ProtocolError


NOW = 1_800_000_000
OLD, NEW = '8.8.8.8', '8.8.4.4'
Z, R, EXTRA = 'a' * 32, 'b' * 32, 'c' * 32
SOURCE = dict(provider='hetzner', project_id='p', provider_id='40', generation=1)
TARGET = dict(provider='hetzner', project_id='p', provider_id='51', generation=2)
HASH, MACHINE = 'd' * 64, 'e' * 64
RESOURCE = dict(id='cf-relay', kind='cloudflare_record', zone_id=Z, record_id=R, hostname='relay.example.com')


class Provider:
    def __init__(self):
        self.addresses = {'40': OLD, '51': NEW}
        self.calls = []

    def probe_target(self, identity):
        self.calls.append(copy.deepcopy(identity))
        return dict(identity=copy.deepcopy(identity), ipv4=self.addresses[identity['provider_id']])


class API:
    def __init__(self):
        self.records = {R: dict(id=R, type='A', name='relay.example.com', content=OLD,
                               proxied=False, ttl=1, tags=['unchanged'], modified_on='before')}
        self.calls = []
        self.timeout = None

    def __call__(self, method, path, payload):
        self.calls.append((method, path, copy.deepcopy(payload)))
        if '?' in path:
            return 200, dict(success=True, errors=[], result=copy.deepcopy(list(self.records.values())),
                             result_info=dict(page=1, total_pages=1))
        rid = path.rsplit('/', 1)[1]
        if method == 'PATCH':
            if self.timeout == 'before':
                raise TimeoutError('private transport detail')
            self.records[rid]['content'] = payload['content']
            self.records[rid]['modified_on'] = 'after'
            if self.timeout == 'after':
                raise TimeoutError('private transport detail')
        return 200, dict(success=True, errors=[], result=copy.deepcopy(self.records[rid]))


class PrivateFixture(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.root.chmod(0o700)

    def private(self, name, raw):
        path = self.root / name
        path.write_bytes(raw)
        path.chmod(0o600)
        return path


class RegistryTests(PrivateFixture):
    def setUp(self):
        super().setUp()
        self.registry = dict(schema=hooks.REGISTRY_SCHEMA, owner_id='1', service_id='7001', project_id='p',
                             revision=1, consumers=['vm109'], resources=[copy.deepcopy(RESOURCE)])
        self.path = self.private('registry.json', canonical_json(self.registry))
        self.config = dict(policy=dict(owner_id='1', service_id='7001', project_id='p', critical_consumers=['vm109']),
                           production_driver=dict(registry=dict(path=str(self.path), sha256=core.digest(self.registry))))

    def load(self):
        return hooks.load_registry(self.config, allow_test_paths=True)

    def save(self):
        self.path.write_bytes(canonical_json(self.registry))
        self.config['production_driver']['registry']['sha256'] = core.digest(self.registry)

    def test_unknown_resource_is_retained_and_explicitly_blocks_integration(self):
        self.registry['resources'].append(dict(id='publisher', kind='upstream_publisher', owner='not-yet-enrolled'))
        self.save()
        loaded = self.load()
        self.assertEqual(self.registry, loaded)
        result = hooks.integration_blockers(loaded)
        self.assertEqual(['upstream_publisher'], result['unsupported_resource_kinds'])
        self.assertFalse(result['effects_enabled'])

    def test_pin_mismatch_duplicate_resource_or_wrong_consumer_are_rejected(self):
        self.path.write_bytes(canonical_json({**self.registry, 'revision': 2}))
        with self.assertRaisesRegex(hooks.IntegrationError, 'pin_mismatch'):
            self.load()
        self.registry['resources'].append(copy.deepcopy(RESOURCE)); self.save()
        with self.assertRaisesRegex(hooks.IntegrationError, 'resource_identity'):
            self.load()
        self.registry['resources'].pop(); self.registry['consumers'] = ['vpn']; self.save()
        with self.assertRaisesRegex(hooks.IntegrationError, 'consumers'):
            self.load()

    def test_registry_rejects_noncanonical_and_extra_cf_authority_fields(self):
        self.path.write_bytes(b' ' + canonical_json(self.registry))
        self.config['production_driver']['registry']['sha256'] = hashlib.sha256(self.path.read_bytes()).hexdigest()
        with self.assertRaisesRegex(hooks.IntegrationError, 'canonical'):
            self.load()
        self.registry['resources'][0]['url'] = 'https://arbitrary.example/path'; self.save()
        with self.assertRaisesRegex(hooks.IntegrationError, 'cloudflare_fields'):
            self.load()

    def test_private_pins_reject_symlink_and_hardlinked_secret(self):
        alias = self.root / 'alias.json'
        alias.symlink_to(self.path)
        self.config['production_driver']['registry']['path'] = str(alias)
        with self.assertRaises((ProtocolError, hooks.IntegrationError)):
            self.load()
        alias.unlink(); os.link(self.path, alias)
        self.config['production_driver']['registry']['path'] = str(self.path)
        with self.assertRaisesRegex(hooks.IntegrationError, 'private_file'):
            self.load()

    def test_default_off_build_does_no_io_and_enabled_incomplete_never_arms(self):
        self.assertEqual({}, hooks.build_integrations({}))
        self.assertEqual({}, hooks.build_integrations({'policy': {'enabled': True}, 'production_driver': {'enabled': False}}))
        self.config['policy']['enabled'] = True
        self.config['production_driver']['enabled'] = True
        # Real production uses a non-/tmp private path. Check the explicit full
        # integration gate independently from the test-only /tmp relaxation.
        from unittest.mock import patch
        with patch.object(hooks, 'load_registry', return_value=self.registry):
            with self.assertRaisesRegex(hooks.IntegrationError, 'global_integration_incomplete'):
                hooks.build_integrations(self.config)


class OriginTests(PrivateFixture):
    def setUp(self):
        super().setUp()
        self.key, self.now, self.elapsed = b'o' * 32, [NOW], [1.0]
        self.provider = Provider()
        self.machine_reads, self.calls = [], []
        enrollment = dict(schema=hooks.ORIGIN_SCHEMA, service_id='7001', identity=TARGET, config_hash=HASH,
                          origin_ipv4=NEW, hostnames=['relay.example.com'])
        self.enrollment = enrollment
        self.replay = ReplayStore(self.root / 'origin-replay.sqlite3')
        self.responder = hooks.OriginChallengeResponder(enrollment, self.key, self.replay,
            clock=lambda: self.now[0], machine_reader=lambda: self.machine_reads.append('read') or MACHINE)
        self.mutate = None
        self.nonces = iter(f'{n:032x}' for n in range(1, 100))
        self.client = hooks.OriginProbeClient(self.key, provider=self.provider, service_id='7001',
            clock=lambda: self.now[0], monotonic=lambda: self.elapsed[0], transport=self.transport,
            nonce=lambda: next(self.nonces), authoritative_servers={Z: ['9.9.9.9']},
            dns=lambda server, name, typ: dict(authoritative=True, name=name, type=typ, addresses=['104.16.1.1']))

    def transport(self, hostname, address, headers, timeout, *, edge=False, edge_ipv4=None):
        self.calls.append(dict(hostname=hostname, address=address, headers=copy.deepcopy(headers), timeout=timeout,
                               edge=edge, edge_ipv4=edge_ipv4))
        result = self.responder.respond('GET', hooks.ORIGIN_PATH, headers)
        return self.mutate(result, edge) if self.mutate else result

    def probe(self):
        return self.client.probe(RESOURCE, TARGET, HASH, MACHINE)

    def signed_change(self, result, transform):
        from infra.relay_recovery.protocol import decode_json
        status, headers, raw = result
        body = decode_json(raw); transform(body)
        raw = canonical_json(body)
        return status, {**headers, 'X-SN-Origin-Signature': hooks._response_signature(self.key, raw)}, raw

    def test_direct_and_public_edge_are_independent_fresh_authenticated_challenges(self):
        result = self.probe()
        self.assertTrue(result['origin_verified']); self.assertTrue(result['edge_route_verified'])
        self.assertEqual([False, True], [c['edge'] for c in self.calls])
        self.assertEqual([NEW, NEW], [c['address'] for c in self.calls])
        self.assertNotEqual(self.calls[0]['headers']['X-SN-Origin-Nonce'], self.calls[1]['headers']['X-SN-Origin-Nonce'])
        self.assertEqual('104.16.1.1', self.calls[1]['edge_ipv4'])
        self.assertEqual(2, len(self.provider.calls))
        self.assertNotIn(self.key.decode(), repr(result))

    def test_edge_to_old_origin_fails_even_when_direct_candidate_is_healthy(self):
        original = self.responder
        old = hooks.OriginChallengeResponder({**self.enrollment, 'identity': SOURCE, 'origin_ipv4': OLD},
            self.key, ReplayStore(self.root / 'old-replay.sqlite3'), clock=lambda: NOW, machine_reader=lambda: 'f' * 64)
        def transport(hostname, address, headers, timeout, *, edge=False, edge_ipv4=None):
            return (old if edge else original).respond('GET', hooks.ORIGIN_PATH, headers)
        self.client.transport = transport
        with self.assertRaisesRegex(hooks.IntegrationError, 'transport_failed'):
            self.probe()

    def test_authenticated_wrong_identity_nonce_machine_or_integer_true_is_rejected(self):
        transforms = [lambda b: b.update(identity=SOURCE), lambda b: b.update(nonce='f' * 32),
                      lambda b: b.update(machine_uuid_sha256='f' * 64), lambda b: b.update(read_only=1)]
        for transform in transforms:
            with self.subTest(transform=transform):
                self.mutate = lambda result, edge, change=transform: self.signed_change(result, change)
                with self.assertRaisesRegex(hooks.IntegrationError, 'response_binding'):
                    self.probe()

    def test_stale_signed_response_and_whole_request_deadline_are_rejected(self):
        self.mutate = lambda result, edge: self.signed_change(result, lambda b: b.update(observed_at=NOW - 20))
        with self.assertRaisesRegex(hooks.IntegrationError, 'response_stale'):
            self.probe()
        def elapsed(result, edge):
            self.elapsed[0] += 9
            return result
        self.mutate = elapsed
        with self.assertRaisesRegex(hooks.IntegrationError, 'response_unavailable'):
            self.probe()

    def test_invalid_signature_duplicate_header_and_redirect_never_pass(self):
        changes = [lambda result: (result[0], {**result[1], 'X-SN-Origin-Signature': '0' * 64}, result[2]),
                   lambda result: (result[0], list(result[1].items()) + [('x-sn-origin-signature', '0' * 64)], result[2]),
                   lambda result: (302, result[1], result[2])]
        for change in changes:
            self.mutate = lambda result, edge, change=change: change(result)
            with self.assertRaises(hooks.IntegrationError):
                self.probe()

    def test_provider_address_drift_after_probes_invalidates_acceptance(self):
        def drift(result, edge):
            if edge: self.provider.addresses['51'] = '1.1.1.1'
            return result
        self.mutate = drift
        with self.assertRaisesRegex(hooks.IntegrationError, 'provider_changed'):
            self.probe()

    def test_authoritative_dns_must_select_an_edge_distinct_from_the_candidate(self):
        for answer in [dict(authoritative=True, name=RESOURCE['hostname'], type='A', addresses=[NEW]),
                       dict(authoritative=False, name=RESOURCE['hostname'], type='A', addresses=['104.16.1.1'])]:
            self.client.dns = lambda *args, answer=answer: answer
            with self.assertRaises(hooks.IntegrationError):
                self.probe()
        self.assertEqual([], self.calls)

    def test_responder_authentication_precedes_dmi_and_replay_survives_restart(self):
        headers = hooks.origin_request_headers(self.key, 'relay.example.com', NEW, 'a' * 32, NOW)
        bad = {**headers, 'X-SN-Origin-Signature': '0' * 64}
        with self.assertRaisesRegex(hooks.IntegrationError, 'request_signature'):
            self.responder.respond('GET', hooks.ORIGIN_PATH, bad)
        self.assertEqual([], self.machine_reads)
        self.responder.respond('GET', hooks.ORIGIN_PATH, headers)
        restarted = hooks.OriginChallengeResponder(self.enrollment, self.key,
            ReplayStore(self.root / 'origin-replay.sqlite3'), clock=lambda: NOW, machine_reader=lambda: MACHINE)
        with self.assertRaisesRegex(hooks.IntegrationError, 'request_replay'):
            restarted.respond('GET', hooks.ORIGIN_PATH, headers)

    def test_responder_is_fixed_path_host_method_and_fresh_request_only(self):
        headers = hooks.origin_request_headers(self.key, 'relay.example.com', NEW, 'b' * 32, NOW)
        for method, path, altered in [('POST', hooks.ORIGIN_PATH, headers), ('GET', '/arbitrary', headers),
                ('GET', hooks.ORIGIN_PATH, {**headers, 'Host': 'other.example.com'}),
                ('GET', hooks.ORIGIN_PATH, {**headers, 'X-SN-Origin-At': str(NOW - 99)})]:
            with self.assertRaises(hooks.IntegrationError):
                self.responder.respond(method, path, altered)
        self.assertEqual([], self.machine_reads)


class CloudflareResourceTests(PrivateFixture):
    def setUp(self):
        super().setUp()
        self.api, self.provider, self.clock = API(), Provider(), [NOW]
        self.registry = dict(schema=hooks.REGISTRY_SCHEMA, owner_id='1', service_id='7001', project_id='p',
            revision=1, consumers=['vm109'], resources=[copy.deepcopy(RESOURCE)])
        self.config = dict(state_dir=str(self.root), policy=dict(enabled=True),
            production_driver=dict(enabled=True, registry=dict(sha256=core.digest(self.registry))),
            cloudflare=dict(old_ipv4=OLD, zones=[dict(zone_id=Z, record_ids=[R], authoritative_ips=['9.9.9.9'])]))
        self.context = dict(schema='servernet.relay.production-driver.v1', owner_id='1', service_id='7001', project_id='p',
            source_identity=SOURCE, identity=TARGET, config_hash=HASH, registry_hash=core.digest(self.registry),
            parent_intent_id=str(uuid.uuid4()), incident_id='incident-1', restore_epoch=1, restore_hash='c' * 64)
        self.adapter = self.make()

    def make(self, **options):
        return hooks.CloudflareResources(self.config, self.registry, provider=self.provider,
            transport=self.api, clock=lambda: self.clock[0], dns=lambda server, name, typ: dict(
                authoritative=True, name=name, type=typ, addresses=[NEW], ttl=300), **options)

    def permit(self):
        return dict(schema='servernet.relay.driver.permit.v1', kind='EXTERNAL_CUTOVER', intent_id=str(uuid.uuid4()),
            parent_intent_id=self.context['parent_intent_id'], context_hash=core.digest(self.context),
            created_at=NOW, expires_at=NOW + 30)

    def patches(self):
        return [call for call in self.api.calls if call[0] == 'PATCH']

    def test_plan_reads_exact_records_and_apply_only_changes_content_with_live_permit(self):
        plan = self.adapter.plan(self.context)
        self.assertTrue(plan['ready']); self.assertEqual([], self.patches())
        result = self.adapter.apply(self.context, plan['plan_id'], self.permit(), lambda permit: True)
        self.assertTrue(result['api_verified']); self.assertEqual([{'content': NEW}], [p[2] for p in self.patches()])
        self.assertEqual(['unchanged'], self.api.records[R]['tags'])
        proof = self.adapter.inspect(self.context, expect_candidate=True)
        self.assertTrue(proof['all_resources_verified']); self.assertTrue(proof['no_old_references'])
        self.assertFalse(proof['cas_atomic'])

    def test_unregistered_old_reference_or_record_hostname_drift_blocks_plan(self):
        self.api.records[EXTRA] = {**self.api.records[R], 'id': EXTRA}
        self.assertFalse(self.adapter.plan(self.context)['ready'])
        self.api.records.pop(EXTRA)
        self.api.records[R]['name'] = 'unexpected.example.com'
        with self.assertRaisesRegex(hooks.IntegrationError, 'hostname_drift'):
            self.adapter.plan(self.context)
        self.assertEqual([], self.patches())

    def test_disabled_or_expired_authority_never_writes_and_midwrite_revocation_stops(self):
        plan = self.adapter.plan(self.context)
        self.adapter.config['policy']['enabled'] = False
        with self.assertRaisesRegex(hooks.IntegrationError, 'effects_disabled'):
            self.adapter.apply(self.context, plan['plan_id'], self.permit(), lambda permit: True)
        self.adapter.config['policy']['enabled'] = True
        permit = self.permit(); permit['expires_at'] = NOW
        with self.assertRaisesRegex(hooks.IntegrationError, 'driver_permit'):
            self.adapter.apply(self.context, plan['plan_id'], permit, lambda permit: True)
        with self.assertRaisesRegex(Exception, 'permit_rejected'):
            self.adapter.apply(self.context, plan['plan_id'], self.permit(), lambda permit: False)
        self.assertEqual([], self.patches())

    def test_timeout_after_write_reconcile_does_not_send_another_patch(self):
        plan = self.adapter.plan(self.context); self.api.timeout = 'after'
        result = self.adapter.apply(self.context, plan['plan_id'], self.permit(), lambda permit: True)
        self.assertTrue(result['api_verified'])
        count = len(self.patches())
        self.assertTrue(self.adapter.reconcile(self.context, plan['plan_id'])['api_verified'])
        self.assertEqual(count, len(self.patches()))

    def test_proxied_record_requires_authenticated_origin_and_edge_proof(self):
        self.api.records[R].update(content=NEW, proxied=True)
        with self.assertRaisesRegex(hooks.IntegrationError, 'origin_probe_missing'):
            self.adapter.inspect(self.context, expect_candidate=True, expected_machine=MACHINE)
        observed = []
        origin = SimpleNamespace(probe=lambda *args: observed.append(args) or dict(verified=True, edge_route_verified=False))
        result = self.make(origin_probe=origin).inspect(self.context, expect_candidate=True, expected_machine=MACHINE)
        self.assertFalse(result['all_resources_verified']); self.assertEqual(TARGET, observed[0][1])

    def test_api_content_without_authoritative_publication_is_not_verified(self):
        self.api.records[R]['content'] = NEW
        self.adapter.dns = lambda server, name, typ: dict(authoritative=True, name=name, type=typ, addresses=[OLD])
        result = self.adapter.inspect(self.context, expect_candidate=True)
        self.assertFalse(result['all_resources_verified'])
        self.assertTrue(result['resource_proofs'][0]['proof']['content_verified'])
        self.assertFalse(result['resource_proofs'][0]['verified'])

    def test_unregistered_extra_dns_answer_does_not_prove_single_relay_acceptance(self):
        self.api.records[R]['content'] = NEW
        self.adapter.dns = lambda server, name, typ: dict(authoritative=True, name=name, type=typ,
                                                         addresses=[NEW, '1.1.1.1'])
        self.assertFalse(self.adapter.inspect(self.context, expect_candidate=True)['all_resources_verified'])

    def test_slow_serial_scan_preserves_earliest_observation_and_expires_old_proofs(self):
        self.api.records[R]['content'] = NEW
        self.api.records[EXTRA] = {**self.api.records[R], 'id': EXTRA}
        self.registry['resources'].append({**RESOURCE, 'id': 'cf-extra', 'record_id': EXTRA})
        self.config['cloudflare']['zones'][0]['record_ids'].append(EXTRA)
        self.config['production_driver']['registry']['sha256'] = core.digest(self.registry)
        self.context['registry_hash'] = core.digest(self.registry)
        adapter = self.make()
        original = self.api
        def slow(method, path, payload):
            result = original(method, path, payload)
            if method == 'GET' and path.endswith('/' + EXTRA): self.clock[0] += 100
            return result
        adapter.transport = slow
        result = adapter.inspect(self.context, expect_candidate=True)
        self.assertEqual(NOW, result['observed_at']); self.assertEqual(NOW + 100, result['received_at'])
        self.assertFalse(result['all_resources_verified']); self.assertFalse(result['no_old_references'])
        self.assertFalse(result['resource_proofs'][0]['proof']['fresh_at_completion'])

    def test_registry_mapping_and_context_pin_must_match_exactly(self):
        self.config['cloudflare']['zones'][0]['record_ids'].append(EXTRA)
        with self.assertRaisesRegex(hooks.IntegrationError, 'coverage'):
            self.make()
        self.config['cloudflare']['zones'][0]['record_ids'].pop()
        with self.assertRaisesRegex(hooks.IntegrationError, 'registry_scope'):
            self.adapter.plan({**self.context, 'registry_hash': 'f' * 64})


class LifecycleFactoryTests(PrivateFixture):
    def setUp(self):
        super().setUp()
        self.provider, self.calls = Provider(), []
        self.private('ssh.key', b'not-an-actual-secret')
        self.private('hmac.key', b'h' * 32)
        algorithm = b'ssh-ed25519'
        public = len(algorithm).to_bytes(4, 'big') + algorithm + b'x' * 32
        self.fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(public).digest()).decode().rstrip('=')
        self.source_known = self.private('source.known_hosts', OLD.encode() + b' ssh-ed25519 ' + base64.b64encode(public) + b'\n')
        self.candidate_known = self.private('candidate.known_hosts', NEW.encode() + b' ssh-ed25519 ' + base64.b64encode(public) + b'\n')
        self.source = {**SOURCE, 'config_hash': 'a' * 64}
        self.candidate = {**TARGET, 'config_hash': HASH}
        self.state = dict(active=self.source, candidate=self.candidate)
        with sqlite3.connect(self.root / 'controller.sqlite3') as db:
            db.execute('CREATE TABLE state(id INTEGER PRIMARY KEY, body TEXT NOT NULL)')
        (self.root / 'controller.sqlite3').chmod(0o600)
        self.save_state()
        self.source_cfg = dict(schema='servernet.relay.lifecycle-client.v1', service_id='7001', provider_id='40', generation=1,
            config_hash='a' * 64, source_host=OLD, ssh_key_file=str(self.root / 'ssh.key'),
            known_hosts_file=str(self.source_known), expected_host_key_sha256=self.fingerprint,
            controller_key_file=str(self.root / 'hmac.key'), state_dir=str(self.root / 'source-state'))
        source_path = self.private('source.json', canonical_json(self.source_cfg))
        self.config = dict(state_dir=str(self.root), policy=dict(project_id='p', service_id='7001'), restore_workflow=dict(lifecycle=dict(
            source_configs={'40': dict(path=str(source_path), sha256=hashlib.sha256(source_path.read_bytes()).hexdigest())},
            candidate_template=dict(ssh_key_file=str(self.root / 'ssh.key'), controller_key_file=str(self.root / 'hmac.key')))))
        self.artifact = SimpleNamespace(profile='restored', host_key_origin='source', known_hosts_path=str(self.candidate_known),
                                        public_host_fingerprint=self.fingerprint)
        self.factory = self.make()

    def save_state(self):
        with sqlite3.connect(self.root / 'controller.sqlite3') as db:
            db.execute('INSERT OR REPLACE INTO state VALUES(1,?)', (core.canonical(self.state),))

    def make(self):
        def client(config, **kwargs):
            self.calls.append(copy.deepcopy(config))
            return SimpleNamespace(config=config, **kwargs)
        return hooks.LifecycleFactory(self.config, provider=self.provider, client_factory=client,
                                      clock=lambda: NOW, allow_test_paths=True)

    def test_source_enrollment_is_pinned_exact_generation_and_provider_address(self):
        client = self.factory(self.source, None, lambda permit: True)
        self.assertEqual(OLD, client.config['source_host'])
        self.assertEqual(self.fingerprint, client.config['expected_host_key_sha256'])
        self.assertEqual(1, len(self.calls))

    def test_restored_candidate_enrollment_survives_restart_and_becomes_new_source(self):
        self.factory(self.candidate, self.artifact, lambda permit: True)
        self.state = dict(active=self.candidate, candidate=None); self.save_state()
        restarted = self.make()
        client = restarted(self.candidate, None, lambda permit: True)
        self.assertEqual('51', client.config['provider_id']); self.assertEqual(2, client.config['generation'])
        self.assertEqual(HASH, client.config['config_hash'])
        self.assertEqual(str(self.candidate_known), client.config['known_hosts_file'])
        self.assertNotIn('51', self.config['restore_workflow']['lifecycle']['source_configs'])

    def test_no_startup_seed_fallback_for_new_active_generation(self):
        self.state = dict(active=self.candidate, candidate=None); self.save_state()
        with self.assertRaisesRegex(hooks.IntegrationError, 'source_lifecycle_pin_missing'):
            self.factory(self.candidate, None, lambda permit: True)
        self.assertEqual([], self.calls)

    def test_wrong_restored_pin_trial_or_uncommitted_target_never_creates_client(self):
        for changes in [dict(profile='trial'), dict(host_key_origin='trial')]:
            artifact = SimpleNamespace(**{**vars(self.artifact), **changes})
            with self.assertRaisesRegex(hooks.IntegrationError, 'source_host_pin_required'):
                self.factory(self.candidate, artifact, lambda permit: True)
        wrong = {**self.candidate, 'generation': 3}
        with self.assertRaisesRegex(hooks.IntegrationError, 'core_generation_mismatch'):
            self.factory(wrong, self.artifact, lambda permit: True)
        self.assertEqual([], self.calls)

    def test_provider_address_drift_rejects_saved_generation_instead_of_rebinding(self):
        self.factory(self.candidate, self.artifact, lambda permit: True)
        self.provider.addresses['51'] = '1.1.1.1'
        with self.assertRaisesRegex(hooks.IntegrationError, 'enrollment_scope_mismatch'):
            self.factory(self.candidate, self.artifact, lambda permit: True)


if __name__ == '__main__':
    unittest.main()
