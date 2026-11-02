import hashlib
import json
import os
from pathlib import Path
import sqlite3
import tempfile
import unittest
from unittest import mock

import relay_notifier as m


class RelayNotifierTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.base = Path(self.temp.name)
        self.controller = self.base / 'controller'
        self.controller.mkdir(mode=0o700)
        self.target = self.base / 'notifier'
        self.config = {'state_dir': str(self.target), 'controller_state_dir': str(self.controller), 'owner_binding': 'owner-1'}
        self.now = 2000
        self.sent = []

    def tearDown(self):
        self.temp.cleanup()

    def sender(self, config, event):
        self.sent.append(event)
        return 'sent'

    def write(self, path, value):
        path.write_text(json.dumps(value))
        path.chmod(0o600)

    def source(self, quality='ok', since=1000, now=None, **extra):
        now = self.now if now is None else now
        self.write(self.controller / 'status.json', {'schema': 'servernet.relay.controller.v1', 'at': now, 'mode': 'observe_only', 'phase': 'HEALTHY'})
        state = {'clock_unsafe': False, 'active': {'generation': 1, 'config_hash': 'bound-config'}, 'incident_id': None if quality == 'ok' else 'relay-incident',
                 'health': {'quality': quality, 'last_at': now, ('recovery_since' if quality == 'ok' else 'bad_run_since'): since}}
        state.update(extra)
        database = self.controller / 'controller.sqlite3'
        db = sqlite3.connect(database)
        db.execute('CREATE TABLE IF NOT EXISTS state(id INTEGER PRIMARY KEY,body TEXT)')
        db.execute('INSERT OR REPLACE INTO state VALUES(1,?)', (json.dumps(state),))
        db.commit(); db.close()
        database.chmod(0o600)

    def run_now(self, now=None):
        return m.run(self.config, self.now if now is None else now, sender=self.sender)

    def test_initial_healthy_does_not_send(self):
        self.source()
        result = self.run_now()
        self.assertEqual(result['state'], 'healthy')
        self.assertEqual(self.sent, [])

    def test_inactive_missing_controller_reports_unavailable_once(self):
        self.write(self.controller / 'status.json', {'schema': 'servernet.relay.controller.v1', 'at': self.now, 'mode': 'blocked'})
        for _ in range(3):
            self.assertEqual(self.run_now()['state'], 'unknown')
        self.assertEqual([e['type'] for e in self.sent], ['relay_monitor_unavailable'])
        self.assertEqual(self.sent[0]['reason'], 'controller_not_active')

    def test_missing_source_is_unavailable_not_healthy(self):
        result = self.run_now()
        self.assertEqual(result['state'], 'unknown')
        self.assertEqual(self.sent[0]['type'], 'relay_monitor_unavailable')

    def test_failure_debounce_then_single_outage(self):
        self.source('bad', since=1900)
        self.assertEqual(self.run_now()['state'], 'suspect')
        self.assertEqual(self.sent, [])
        self.source('bad', since=1400)
        self.assertEqual(self.run_now()['state'], 'down')
        self.run_now()
        self.assertEqual([e['type'] for e in self.sent], ['relay_outage'])

    def test_transient_success_never_reports_recovered(self):
        self.source('bad')
        self.run_now()
        self.source('ok', since=1950)
        self.assertEqual(self.run_now()['state'], 'recovering')
        self.assertTrue(self.run_now()['outage_open'])
        self.assertEqual([e['type'] for e in self.sent], ['relay_outage'])

    def test_confirmed_recovery_sends_once(self):
        self.source('bad'); self.run_now()
        self.source('ok'); self.run_now(); self.run_now()
        self.assertEqual([e['type'] for e in self.sent], ['relay_outage', 'relay_recovered'])
        self.assertFalse(self.run_now()['outage_open'])

    def test_stale_success_never_reports_recovered(self):
        self.source('bad'); self.run_now()
        self.source('ok', now=1800)
        self.assertEqual(self.run_now()['state'], 'unknown')
        self.assertTrue(self.run_now()['outage_open'])
        self.assertEqual([e['type'] for e in self.sent], ['relay_outage', 'relay_monitor_unavailable'])

    def test_health_stale_even_when_controller_fresh(self):
        self.source('ok', health={'quality': 'ok', 'last_at': 1800, 'recovery_since': 1000})
        result = self.run_now()
        self.assertEqual(result['state'], 'unknown')
        self.assertEqual(result['reason'], 'health_stale_or_missing')

    def test_promotion_phase_healthy_empty_health_is_unknown(self):
        self.source('bad'); self.run_now()
        self.source('ok', active={'generation': 2, 'config_hash': 'new-generation'}, health={})
        result = self.run_now()
        self.assertEqual(result['state'], 'unknown')
        self.assertNotIn('relay_recovered', [e['type'] for e in self.sent])

    def test_unknown_then_fresh_bad_is_new_outage_not_false_restored(self):
        self.run_now()
        self.source('bad'); self.run_now()
        self.assertEqual([e['type'] for e in self.sent], ['relay_monitor_unavailable', 'relay_outage'])

    def test_unknown_then_fresh_healthy_reports_monitor_restored(self):
        self.run_now()
        self.source(); self.run_now(); self.run_now()
        self.assertEqual([e['type'] for e in self.sent], ['relay_monitor_unavailable', 'relay_monitor_restored'])

    def test_unknown_during_outage_then_confirmed_good_reports_recovered(self):
        self.source('bad'); self.run_now()
        self.source('ok', now=1800); self.run_now()
        self.source(); self.run_now()
        self.assertEqual([e['type'] for e in self.sent], ['relay_outage', 'relay_monitor_unavailable', 'relay_recovered'])

    def test_uncertain_send_is_never_replayed(self):
        self.source('bad')
        send = mock.Mock(return_value='uncertain')
        first = m.run(self.config, self.now, sender=send)
        second = m.run(self.config, self.now, sender=send)
        send.assert_called_once()
        self.assertEqual(first['notification']['status'], 'uncertain')
        self.assertEqual(second['notification']['status'], 'uncertain')

    def test_exception_send_is_uncertain_and_raw_error_never_escapes(self):
        self.source('bad')
        send = mock.Mock(side_effect=RuntimeError('PRIVATE_SECRET'))
        result = m.run(self.config, self.now, sender=send)
        self.assertEqual(result['notification']['status'], 'uncertain')
        self.assertNotIn('PRIVATE_SECRET', json.dumps(result))
        m.run(self.config, self.now, sender=send)
        send.assert_called_once()

    def test_dispatching_after_process_death_is_not_replayed(self):
        self.source('bad'); self.run_now()
        db = sqlite3.connect(self.target / 'notifications.sqlite3')
        db.execute("UPDATE events SET status='dispatching'"); db.commit(); db.close()
        result = self.run_now()
        self.assertEqual(result['notification']['status'], 'uncertain')
        self.assertEqual(len(self.sent), 1)

    def test_owner_binding_change_does_not_reuse_old_notice(self):
        self.run_now()
        self.config['owner_binding'] = 'owner-2'
        self.run_now()
        self.assertEqual(len(self.sent), 2)
        self.assertNotEqual(self.sent[0]['event_id'], self.sent[1]['event_id'])
        self.assertNotIn('owner-1', json.dumps(self.sent))

    def test_source_sqlite_is_unchanged(self):
        self.source()
        database = self.controller / 'controller.sqlite3'
        before = hashlib.sha256(database.read_bytes()).hexdigest()
        self.run_now()
        self.assertEqual(before, hashlib.sha256(database.read_bytes()).hexdigest())

    def test_clock_unknown_never_claims_healthy(self):
        self.source(clock_unsafe=True)
        self.assertEqual(self.run_now()['reason'], 'controller_clock_unverified')

    def test_future_and_boolean_timestamps_are_not_fresh(self):
        for at in (self.now + 1, True):
            self.assertFalse(m.fresh(at, self.now))

    def test_source_symlink_is_rejected(self):
        real = self.base / 'real.json'
        self.write(real, {'private': 'data'})
        (self.controller / 'status.json').symlink_to(real)
        self.assertEqual(self.run_now()['state'], 'unknown')

    def test_output_has_no_controller_identity_or_paths(self):
        self.source('bad')
        result = self.run_now()
        combined = json.dumps([result, self.sent])
        self.assertNotIn(str(self.controller), combined)
        self.assertNotIn('bound-config', combined)
        self.assertNotIn('relay-incident', combined)
        self.assertNotIn('generation', combined)

    def test_delivery_adapter_real_success_and_nonzero(self):
        script = self.base / 'sender.py'
        script.write_text("#!/usr/bin/env python3\nimport json,sys\ne=json.load(sys.stdin)\nassert e['type']=='relay_outage'\nprint(json.dumps({'sent':True}))\n")
        script.chmod(0o700)
        cfg = dict(self.config, delivery_command=[str(script)])
        self.assertEqual(m.deliver(cfg, {'type': 'relay_outage'}), 'sent')
        script.write_text("#!/usr/bin/env python3\nprint('{\"sent\":true}')\nraise SystemExit(2)\n")
        self.assertEqual(m.deliver(cfg, {'type': 'relay_outage'}), 'uncertain')

    def test_delivery_adapter_hash_change_and_unsafe_command_refused(self):
        script = self.base / 'sender.py'
        script.write_text('#!/bin/sh\necho bad\n'); script.chmod(0o700)
        cfg = dict(self.config, delivery_command=[str(script)], adapter_sha256='0' * 64)
        self.assertEqual(m.deliver(cfg, {}), 'uncertain')
        cfg = dict(self.config, delivery_command=['php', 'relative.php'])
        self.assertEqual(m.deliver(cfg, {}), 'uncertain')

    def test_delivery_output_is_bounded(self):
        script = self.base / 'sender.py'
        script.write_text("#!/usr/bin/env python3\nprint('x'*20000)\n")
        script.chmod(0o700)
        self.assertEqual(m.deliver(dict(self.config, delivery_command=[str(script)]), {}), 'uncertain')

    def test_private_config_policy_thresholds_are_used(self):
        self.source('bad', since=1900)
        controller_config = self.base / 'controller.json'
        self.write(controller_config, {'state_dir': str(self.controller), 'policy': {'suspect_seconds': 60, 'recover_seconds': 60}})
        self.config['controller_config'] = str(controller_config)
        self.assertEqual(self.run_now()['state'], 'down')


    def test_owner_binding_a_b_a_has_unique_global_sequences(self):
        for owner in ('owner-A', 'owner-B', 'owner-A'):
            self.config['owner_binding'] = owner
            self.assertEqual(self.run_now()['state'], 'unknown')
        self.assertEqual(len(self.sent), 3)
        self.assertEqual(len({event['event_id'] for event in self.sent}), 3)
        self.run_now()
        self.assertEqual(len(self.sent), 3)

    def test_empty_whitespace_oversized_config_hash_are_rejected(self):
        for value in ('', '   ', 'x' * 257):
            with self.subTest(value=repr(value[:10])):
                self.source(active={'generation': 1, 'config_hash': value})
                self.assertEqual(m.read_source(self.config, self.now)['quality'], 'unknown')
        self.source(active={'generation': 1, 'config_hash': 'nonempty-core-binding'})
        self.assertEqual(m.read_source(self.config, self.now)['quality'], 'ok')

    def probe_tick(self, value, at, now=None, reader=None):
        now = at if now is None else now
        return m.run(self.config, now, sender=self.sender,
                     reader=reader or m.read_source,
                     prober=lambda config, current: {'configured': True, 'available': value, 'at': at})

    def test_access_two_failures_work_with_inactive_controller(self):
        first = self.probe_tick(False, 2000)
        self.assertEqual(first['access_path']['state'], 'unknown')
        second = self.probe_tick(False, 2060)
        self.assertEqual(second['state'], 'unknown')
        self.assertEqual(second['access_path']['state'], 'unavailable')
        self.assertEqual([e['type'] for e in self.sent], ['relay_monitor_unavailable', 'relay_access_unavailable'])
        self.probe_tick(False, 2120)
        self.assertEqual(len(self.sent), 2)
        self.assertFalse(second['outage_open'])

    def test_access_initial_two_successes_are_silent(self):
        self.source()
        self.probe_tick(True, 2000)
        result = self.probe_tick(True, 2060)
        self.assertEqual(result['access_path']['state'], 'available')
        self.assertEqual(self.sent, [])

    def test_access_restored_requires_two_successes_and_dedups(self):
        self.probe_tick(False, 2000); self.probe_tick(False, 2060)
        result = self.probe_tick(True, 2120)
        self.assertEqual(result['access_path']['state'], 'unknown')
        self.assertNotIn('relay_access_restored', [e['type'] for e in self.sent])
        self.probe_tick(True, 2180); self.probe_tick(True, 2240)
        self.assertEqual([e['type'] for e in self.sent], ['relay_monitor_unavailable', 'relay_access_unavailable', 'relay_access_restored'])
        self.assertNotIn('relay_recovered', [e['type'] for e in self.sent])

    def test_access_duplicate_timestamp_does_not_advance_streak(self):
        self.probe_tick(False, 2000)
        self.probe_tick(False, 2000, now=2030)
        self.assertEqual([e['type'] for e in self.sent], ['relay_monitor_unavailable'])
        self.probe_tick(False, 2060)
        self.assertEqual(self.sent[-1]['type'], 'relay_access_unavailable')

    def test_access_stale_future_and_missing_samples_break_continuity(self):
        for value, at, now in [(False, 1800, 2060), (False, 2121, 2120), (None, None, 2180)]:
            self.probe_tick(False, 2000, now=2000)
            result = self.probe_tick(value, at, now=now)
            self.assertEqual(result['access_path']['state'], 'unknown')
            self.assertEqual(result['access_path']['reason'], 'access_probe_unavailable')
        self.assertNotIn('relay_access_unavailable', [e['type'] for e in self.sent])

    def test_access_large_sample_gap_requires_new_two_sample_run(self):
        self.probe_tick(False, 2000)
        self.probe_tick(False, 2200)
        self.assertNotIn('relay_access_unavailable', [e['type'] for e in self.sent])
        self.probe_tick(False, 2260)
        self.assertEqual(self.sent[-1]['type'], 'relay_access_unavailable')

    def test_simultaneous_source_and_access_events_are_both_durable(self):
        self.probe_tick(False, 2000)
        self.source('bad', now=2060)
        self.probe_tick(False, 2060)
        self.assertEqual([e['type'] for e in self.sent], ['relay_monitor_unavailable', 'relay_outage', 'relay_access_unavailable'])
        self.probe_tick(False, 2060)
        self.assertEqual(len(self.sent), 3)
        db = sqlite3.connect(self.target / 'notifications.sqlite3')
        self.assertEqual(db.execute("SELECT COUNT(*) FROM events WHERE status='sent'").fetchone()[0], 3)
        db.close()

    def test_real_optional_probe_command_checks_fresh_boolean_contract(self):
        script = self.base / 'probe.py'
        script.chmod(0o700) if script.exists() else None
        cfg = dict(self.config, probe_command=[str(script)])
        for reply, expected in [({'available': False, 'at': 2000}, False),
                                ({'available': True, 'at': 1800}, None),
                                ({'available': True, 'at': 2001}, None),
                                ({'available': 'false', 'at': 2000}, None)]:
            script.write_text("#!/usr/bin/env python3\nimport json,sys\nrequest=json.load(sys.stdin)\nassert request=={'at':2000}\nprint(json.dumps(" + repr(reply) + "))\n")
            script.chmod(0o700)
            self.assertIs(m.collect_probe(cfg, 2000)['available'], expected)
        script.write_text("#!/usr/bin/env python3\nprint('PRIVATE_SECRET')\n")
        self.assertNotIn('PRIVATE_SECRET', json.dumps(m.collect_probe(cfg, 2000)))


if __name__ == '__main__':
    unittest.main()
