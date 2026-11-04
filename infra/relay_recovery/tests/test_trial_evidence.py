"""Real signed isolated route into a read-only driver/restore evidence reader."""
import copy
import hashlib
import json
from pathlib import Path
import sqlite3
import unittest
import uuid
from types import SimpleNamespace
from unittest.mock import patch

from infra.relay_recovery import core, isolated_acceptance, protocol, trial_evidence
from infra.relay_recovery.production_driver import ProductionDriver
from infra.relay_recovery.restore_workflow import RestoreWorkflow
from infra.relay_recovery.tests import test_iran_effects as pipeline


class TrialEvidenceTests(unittest.TestCase):
    def setUp(self):
        self.f = pipeline.BridgeTests('test_actual_signed_pull_cli_producer_native_ct_guest_and_single_final_verifier')
        self.f.setUp(); self.addCleanup(self.f.doCleanups)
        f = self.f
        f.cfg['policy']['agent_id'] = 'ir-agent'
        f.active['ipv4'] = pipeline.OLD; f.candidate['ipv4'] = pipeline.NEW
        f.state.update(active=f.active, candidate=f.candidate)
        option = {k: f.context[k] for k in ('owner_id', 'service_id', 'project_id', 'config_hash')}
        option.update(provider='hetzner', architecture='x86', image='1234', location='fsn1')
        self.action = dict(id=pipeline.PARENT, kind='CREATE', payload=dict(next_generation=2, option=option))
        f.save_claim()
        with sqlite3.connect(f.root/'controller.sqlite3') as db:
            db.execute('INSERT INTO actions VALUES(?,?,?)', (pipeline.PARENT, 'CREATE', core.canonical(self.action)))
        f.publish(); f.deliver()
        self.fixture, self.reference = f.native_isolated()
        self.accept = f.publisher.publish_isolated_accept(f.permit['intent_id'], self.reference)
        f.remote.run_once(f.now); f.now += 1; f.remote.run_once(f.now)
        self.outcome = f.publisher.reconcile(self.accept['publication_id'])
        self.assertEqual(self.outcome['phase'], 'SUCCEEDED')
        self.bootstrap()
        self.reader = self.bridge()

    def bridge(self, reference=None):
        return trial_evidence.TrialEvidenceBridge(self.f.cfg, reference or self.reference,
            clock=lambda: self.f.now, allow_test_paths=True)

    def bootstrap(self):
        # Exact public-only BootstrapStore output schemas. No private host key
        # or user-data file is available to the reader in this fixture.
        f = self.f; params = f.params
        self.directory = f.root/'bootstrap'/pipeline.PARENT
        self.directory.mkdir(parents=True, mode=0o700)
        source = {**f.context['source_identity'], 'config_hash': f.active['config_hash']}
        scope = dict(schema='servernet.relay.bootstrap.scope.v1', intent_id=pipeline.PARENT,
            owner_id=f.context['owner_id'], service_id=f.context['service_id'], source_identity=source,
            generation=2, image='1234', location='fsn1', config_hash=f.config_hash)
        fields = {k: scope[k] for k in ('owner_id', 'service_id', 'source_identity', 'generation', 'image', 'location', 'config_hash')}
        trial = dict(schema='servernet.relay.bootstrap.artifact.v1', intent_id=pipeline.PARENT, profile='trial',
            scope_hash=core.digest(scope), user_data_sha256='d'*64, public_host_key=params['ssh_host_public_key'],
            public_host_fingerprint=params['ssh_host_key_sha256'], host_key_origin='trial',
            machine_id_receipt_required=False, machine_id_ready=False, **fields)
        line = pipeline.NEW+' '+params['ssh_host_public_key']
        candidate = dict(schema='servernet.relay.bootstrap.binding.v1', intent_id=pipeline.PARENT,
            identity=f.context['identity'], ipv4=pipeline.NEW, scope_hash=core.digest(scope),
            trial_user_data_sha256=trial['user_data_sha256'], public_host_key=params['ssh_host_public_key'], known_hosts_line=line, **fields)
        for name, value in [('scope.json', scope), ('trial.json', trial), ('candidate.json', candidate)]:
            f.private(self.directory/name, protocol.canonical_json(value))
        f.private(self.directory/'known_hosts', (line+'\n').encode())
        f.private(self.directory/'host_ed25519.pub', (params['ssh_host_public_key']+'\n').encode())

    def observe(self):
        return self.reader.observe(self.f.permit, self.f.context)

    def reject(self):
        with self.assertRaisesRegex(trial_evidence.TrialEvidenceError, '^trial_evidence_unavailable$'):
            self.observe()

    def update_state(self, change):
        f = self.f
        with sqlite3.connect(f.root/'controller.sqlite3') as db:
            state = json.loads(db.execute('SELECT body FROM state').fetchone()[0]); change(state)
            db.execute('UPDATE state SET body=?', (core.canonical(state),))

    def update_job(self, publication, change):
        with self.f.adapter._db() as db:
            row = db.execute('SELECT body FROM iran_jobs WHERE id=?', (publication,)).fetchone()
            body = json.loads(row[0]); change(body)
            db.execute('UPDATE iran_jobs SET body=? WHERE id=?', (core.canonical(body), publication))

    def workflow(self):
        f = self.f
        return dict(schema='servernet.relay.restore.workflow.v1', workflow_id=str(uuid.uuid4()),
            create_intent_id=pipeline.PARENT, source_identity=f.context['source_identity'], identity=f.context['identity'],
            source_config_hash=f.active['config_hash'], config_hash=f.config_hash, incident_id=f.context['incident_id'],
            generation=2, candidate_ipv4=pipeline.NEW, host_fingerprint=f.params['ssh_host_key_sha256'],
            restore_epoch=None, restore_hash=None, now=f.now)

    @staticmethod
    def census(*roots):
        return {str(p): (p.stat().st_mode, hashlib.sha256(p.read_bytes()).hexdigest())
                for root in roots for p in Path(root).rglob('*') if p.is_file()}

    def test_real_signed_proof_maps_without_any_io_effect_or_nonce_consumption(self):
        before = self.census(self.f.base, self.fixture.root)
        calls = (len(self.f.cli_calls), len(self.fixture.peer.calls), len(self.f.transport_headers))
        with patch.object(isolated_acceptance.IsolatedAcceptanceVerifier, 'verify', side_effect=AssertionError('no reverify')), \
             patch.object(pipeline.effects.IranEffectPublisher, 'publish', side_effect=AssertionError('no publication')), \
             patch.object(pipeline.effects.IranEffectPublisher, 'publish_isolated_accept', side_effect=AssertionError('no publication')), \
             patch.object(pipeline.effects.IranEffectPublisher, 'reconcile', side_effect=AssertionError('no ingestion')), \
             patch.object(pipeline.ca.Adapter, '_ingest', side_effect=AssertionError('no ingestion')):
            proof = self.observe(); repeated = self.observe(); workflow = self.reader.trial_network(self.workflow())
        self.assertEqual(proof, repeated)
        self.assertEqual(proof['schema'], 'servernet.relay.driver.observation.v1')
        self.assertEqual(proof['operation'], 'stage_trial'); self.assertEqual(proof['intent_id'], self.f.permit['intent_id'])
        self.assertEqual(proof['context_hash'], core.digest(self.f.context))
        for key in ('verified', 'read_only', 'isolation_verified', 'network_verified', 'ct_egress_verified', 'live_dependencies_unchanged'):
            self.assertIs(proof[key], True)
        self.assertIs(proof['default_path_verified'], False); self.assertIs(proof['global_cutover_performed'], False)
        self.assertEqual(proof['received_at'], self.outcome['received_at'])
        self.assertEqual(proof['observed_at'], self.outcome['receipt']['checks']['adapter']['isolated_candidate']['observed_at'])
        self.assertEqual(proof['proof_expires_at'], proof['observed_at']+30)
        self.assertTrue(workflow['network_ok']); self.assertTrue(workflow['isolated_egress_ok']); self.assertTrue(workflow['staging_isolated'])
        self.assertEqual(before, self.census(self.f.base, self.fixture.root))
        self.assertEqual(calls, (len(self.f.cli_calls), len(self.fixture.peer.calls), len(self.f.transport_headers)))
        with sqlite3.connect(self.fixture.root/'verifier-state'/'isolated-guest-responses.sqlite3') as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM replay').fetchone()[0], 1)
        for forbidden in ('ssh-ed25519', 'body_base64', 'X-VM-Signature', 'PRIVATE KEY'):
            self.assertNotIn(forbidden, protocol.canonical_json(proof).decode())

    def test_actual_driver_reconcile_accepts_reader_without_reentering_apply(self):
        driver = object.__new__(ProductionDriver)
        driver.store = self.f.driver; driver.policy = self.f.adapter.policy; driver.now = lambda: self.f.now
        driver.hooks = SimpleNamespace(trial_reconcile=self.reader.observe,
            trial_apply=lambda *args: self.fail('ambiguous dispatched step cannot apply again'),
            cutover_reconcile=lambda *args: self.fail('wrong namespace'), crm_reconcile=lambda *args: self.fail('wrong namespace'))
        proof = driver._hook_step(pipeline.PARENT, 'TRIAL_STAGE', self.f.context)
        self.assertEqual(proof['acceptance_publication_id'], self.accept['publication_id'])
        self.assertEqual(driver.store.step(pipeline.PARENT, 'TRIAL_STAGE')['phase'], 'VERIFIED')
        terminal = driver.store.step(pipeline.PARENT, 'TRIAL_STAGE')
        self.f.now = proof['proof_expires_at']+1
        with patch.object(driver.hooks, 'trial_reconcile', wraps=self.reader.observe) as read:
            with self.assertRaises(trial_evidence.TrialEvidenceError):
                driver._hook_step(pipeline.PARENT, 'TRIAL_STAGE', self.f.context)
        self.assertEqual(read.call_count, 1)
        self.assertEqual(terminal, driver.store.step(pipeline.PARENT, 'TRIAL_STAGE'))

    def test_original_thirty_second_clock_is_not_policy_ninety_or_read_time(self):
        proof = self.observe(); self.f.now = proof['observed_at']+29
        self.assertEqual(self.observe()['received_at'], proof['received_at'])
        self.f.now += 1; self.reject()
        self.assertTrue(self.f.publisher.reconcile(self.accept['publication_id'])['fresh_observation'])

    def test_actual_restore_bound_cannot_consume_expired_signed_reader_proof(self):
        context=self.workflow();proof=self.reader.trial_network(context)
        workflow=object.__new__(RestoreWorkflow);workflow.policy=self.f.adapter.policy;workflow.now=lambda:self.f.now
        workflow._scope=lambda w:self.f.state
        saved=dict(id=context['workflow_id'],create_intent_id=pipeline.PARENT,source=self.f.active,candidate=self.f.candidate,
            incident_id=self.f.context['incident_id'],candidate_ipv4=pipeline.NEW,host_fingerprint=context['host_fingerprint'])
        self.assertTrue(workflow._bound(proof,saved));self.f.now=proof['proof_expires_at']
        self.assertFalse(workflow._bound(proof,saved))

    def test_expired_mutation_permit_can_only_supply_a_fresh_read_proof(self):
        # The mutation lease is not renewed; a read can still report evidence
        # measured during the separately bounded isolated acceptance job.
        self.f.now = self.f.permit['expires_at']
        proof = self.observe()
        self.assertEqual(proof['intent_id'], self.f.permit['intent_id'])
        self.assertEqual(self.f.permit['expires_at'], pipeline.NOW+30)

    def test_missing_claim_or_changed_permit_is_not_a_stage_receipt(self):
        with self.f.driver.transaction() as db: db.execute('DELETE FROM hook_dispatches')
        self.reject()

    def test_committed_without_dispatch_or_conflict_cannot_be_verified(self):
        with self.f.driver.transaction() as db: db.execute("UPDATE steps SET phase='COMMITTED'")
        self.reject()

    def test_driver_claim_type_alias_and_wrong_parent_are_rejected(self):
        for key, value in [('created_at', True), ('parent_intent_id', 'b'*40), ('kind', 'EXTERNAL_CUTOVER')]:
            permit = {**self.f.permit, key: value}
            with self.subTest(key=key), self.assertRaises(trial_evidence.TrialEvidenceError):
                self.reader.observe(permit, self.f.context)

    def test_each_current_scope_drift_blocks_even_an_existing_success(self):
        changes = [lambda s: s.update(incident_id='other'), lambda s: s['active'].update(provider_id='99'),
            lambda s: s['candidate'].update(provider_id='99'), lambda s: s['candidate'].update(config_hash='f'*64),
            lambda s: s['candidate'].update(ipv4='1.1.1.1'), lambda s: s.update(clock_unsafe=True),
            lambda s: s.update(phase='CUTOVER'), lambda s: s['candidate'].update(restore_epoch=1, restore_hash='f'*64)]
        for change in changes:
            with self.subTest(change=change):
                self.update_state(lambda s: s.update(copy.deepcopy(self.f.state))); self.update_state(change); self.reject()

    def test_private_enrollment_pin_cannot_be_replaced_or_rebound(self):
        path = Path(self.reference['path']); original = path.read_bytes()
        for key in ('proxy_binding_sha256', 'dependency_baseline_sha256', 'producer_config_sha256', 'verifier_sha256', 'expected_registry_hash'):
            value = json.loads(original); value[key] = 'f'*64; raw = protocol.canonical_json(value); path.write_bytes(raw)
            with self.subTest(key=key):
                self.reject()
                reader = self.bridge(dict(path=str(path), sha256=hashlib.sha256(raw).hexdigest()))
                with self.assertRaises(trial_evidence.TrialEvidenceError): reader.observe(self.f.permit, self.f.context)
        path.write_bytes(original)

    def test_original_manifest_history_and_stage_ssh_binding_are_required(self):
        with self.f.adapter._db() as db: db.execute('DELETE FROM manifests WHERE revision=?', (self.accept['manifest']['revision'],))
        self.reject()

    def test_bootstrap_public_pin_and_binding_are_not_inferred_from_stage_ack(self):
        for name in ('scope.json', 'trial.json', 'candidate.json', 'known_hosts', 'host_ed25519.pub'):
            path = self.directory/name; original = path.read_bytes(); path.write_bytes(b'{}')
            with self.subTest(name=name): self.reject()
            path.write_bytes(original)

    def test_bootstrap_metadata_must_bind_original_create_and_current_source_hash(self):
        path = self.directory/'scope.json'; value = json.loads(path.read_bytes()); value['source_identity']['config_hash'] = 'f'*64
        path.write_bytes(protocol.canonical_json(value)); self.reject()

    def test_terminal_hash_and_original_receipt_are_rechecked(self):
        with self.f.adapter._db() as db:
            row = db.execute('SELECT hash,body FROM iran_outcomes WHERE publication=?', (self.accept['publication_id'],)).fetchone()
            value = json.loads(row['body']); value['receipt']['checks']['adapter']['default_path_verified'] = True
            db.execute('UPDATE iran_outcomes SET body=? WHERE hash=?', (core.canonical(value), row['hash']))
        self.reject()

    def test_pending_failed_unknown_or_conflicting_accept_is_not_accepted(self):
        for phase in ('PENDING', 'FAILED', 'CONFLICT'):
            with self.subTest(phase=phase):
                with self.f.adapter._db() as db: db.execute('UPDATE iran_jobs SET phase=? WHERE id=?', (phase, self.accept['publication_id']))
                self.reject()

    def test_newer_default_or_pending_accept_never_falls_back_to_old_success(self):
        self.f.publisher.publish_accept(self.f.permit['intent_id'])
        self.reject()

    def test_accept_metadata_must_retain_exact_original_stage_enrollment_and_ssh(self):
        self.update_job(self.accept['publication_id'], lambda b: b['isolated_accept'].update(ssh_binding_sha256='f'*64))
        self.reject()

    def test_sidecar_or_database_replacement_is_a_hold_without_creating_files(self):
        sidecar = self.f.root/'controller-evidence.sqlite3-wal'; sidecar.write_bytes(b'not ignored')
        self.reject(); sidecar.unlink()
        self.assertTrue(self.observe()['verified'])

    def test_restore_callback_has_exact_workflow_pin_and_no_restored_relabel(self):
        good = self.workflow()
        for key, value in [('candidate_ipv4', '1.1.1.1'), ('host_fingerprint', 'SHA256:wrong'), ('source_config_hash', 'f'*64),
                ('identity', {**good['identity'], 'generation': True}), ('restore_epoch', 1), ('create_intent_id', 'f'*40)]:
            with self.subTest(key=key), self.assertRaises(trial_evidence.TrialEvidenceError):
                self.reader.trial_network({**good, key: value})

    def test_clock_rollback_is_not_a_new_receipt(self):
        self.observe(); self.f.now -= 1; self.reject()

    def test_deadline_expiring_during_final_enrollment_read_cannot_return_success(self):
        original = self.reader._enrollment; calls = []
        def slower(context):
            result = original(context); calls.append(1)
            if len(calls) == 2: self.f.now += 30
            return result
        with patch.object(self.reader, '_enrollment', side_effect=slower): self.reject()

    def test_bootstrap_pin_drift_during_final_enrollment_read_is_not_old_trust(self):
        original = self.reader._enrollment; calls = []
        def changed(context):
            result = original(context); calls.append(1)
            if len(calls) == 2: (self.directory/'known_hosts').write_bytes(b'changed public pin\n')
            return result
        with patch.object(self.reader, '_enrollment', side_effect=changed): self.reject()

    def test_deadline_expiring_after_database_teardown_cannot_return_success(self):
        original = self.reader._database
        from contextlib import contextmanager
        @contextmanager
        def slower(name):
            with original(name) as db: yield db
            if name == 'production-driver.sqlite3': self.f.now += 30
        with patch.object(self.reader, '_database', side_effect=slower): self.reject()

    def test_workflow_checks_cannot_outlive_the_original_proof(self):
        class SlowContext(dict):
            def __getitem__(context, key):
                result = super().__getitem__(key)
                if key == 'now': self.f.now += 30
                return result
        with self.assertRaises(trial_evidence.TrialEvidenceError):
            self.reader.trial_network(SlowContext(self.workflow()))


if __name__ == '__main__':
    unittest.main()
