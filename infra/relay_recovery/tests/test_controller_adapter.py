"""Controller/gateway binding tests: no live API or process execution."""
import copy
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import tempfile
import unittest
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
               detail=None, mutate=None):
        detail = detail if detail is not None else {'host_healthy': True,
            'internet_healthy': True, 'tunnel_ok': True,
            'critical_consumers': {'proxmox': True, 'vm109': True, 'vpn': True,
                                   'dns': True, 'storage': True}}
        receipt = {k: manifest[k] for k in ('incident_id', 'generation', 'revision',
                                            'config_hash', 'jobs_hash')}
        receipt.update(job_id=manifest['jobs'][0]['id'], op='probe', ok=ok,
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
        self.report(m, ok=False)
        evidence = self.collect()
        self.assertFalse(evidence['health']['probe_ok'])
        self.assertFalse(any(r['ok'] for r in evidence['critical_receipts']))

    def test_older_success_retransmission_does_not_mask_later_failure(self):
        m = self.adapter().publish(self.state, 'ir-agent')
        self.report(m, observed=NOW+1); self.collect()
        self.report(m, now=NOW+5, observed=NOW+5, ok=False); self.collect(NOW+6)
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
        self.report(manifest, now=NOW+2, ok=False)
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


if __name__ == '__main__': unittest.main()
