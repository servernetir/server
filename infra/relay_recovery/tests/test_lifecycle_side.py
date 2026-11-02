"""Full-snapshot lifecycle tests. Fake systemd/Docker/metadata; no live effects."""
import copy
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import sys
import tempfile
import unittest
from unittest import mock

INFRA = Path(__file__).resolve().parents[2]
if str(INFRA) not in sys.path:
    sys.path.insert(0, str(INFRA))
from relay_recovery import generation_guard as g, lifecycle_side as life


class World:
    def __init__(self, path):
        self.path = path; self.lifecycle = None
        self.machine = 'a'*64; self.provider = '40'
        self.container = 'c'*64
        self.disk = [self.container]; self.api_ids = [self.container]
        self.running = {self.container}; self.shims = True
        self.states = {'nginx.service': 'active', 'docker.service': 'active'}
        self.calls = []; self.mutations = []
        self.guard_installed = True; self.success_status = ''; self.alias = None
        self.needs_reload = 'no'
        self.loaded_flags = 'privileged'
        self.pending_job=False
        self.fail_stop = False; self.guard_changed_during_start = False

    def __call__(self, argv, data, timeout):
        self.calls.append(argv)
        if argv[:2] == ['/usr/bin/systemctl', 'show']:
            rows = []
            for name in argv[4:]:
                exists = name in self.states
                rows.append('\n'.join([
                    'Id='+name, 'Names='+name+(' ssh.service' if self.alias == name else ''),
                    'LoadState='+('loaded' if exists else 'not-found'),
                    'ActiveState='+self.states.get(name, 'inactive'),
                    'Job='+('123 /org/freedesktop/systemd1/job/123' if self.pending_job else '0'),
                    'ExecCondition={ path=/usr/bin/python3 ; argv[]=/usr/bin/python3 '+life.GUARD_PROGRAM+' --config '+g.DEFAULT_CONFIG+' ; ignore_errors=no ; start_time=[n/a] ; }',
                    'ExecConditionEx={ path=/usr/bin/python3 ; argv[]=/usr/bin/python3 '+life.GUARD_PROGRAM+' --config '+g.DEFAULT_CONFIG+' ; flags='+self.loaded_flags+' ; start_time=[n/a] ; }',
                    'DropInPaths=/etc/systemd/system/'+name+'.d/'+life.DROPIN_NAME,
                    'NeedDaemonReload='+self.needs_reload,
                    'SuccessExitStatus='+self.success_status]))
            return 0, ('\n\n'.join(rows)+'\n').encode()
        if argv[:3] == ['/usr/bin/docker', 'container', 'ls']:
            return 0, ('\n'.join(self.api_ids)+'\n').encode()
        if argv[:3] == ['/usr/bin/docker', 'container', 'inspect']:
            return 0, ('\n'.join(cid+' '+str(cid in self.running).lower() for cid in argv[5:])+'\n').encode()
        if argv[:2] == ['/usr/bin/pgrep', '-f']:
            return (0, b'2398\n') if self.shims else (1, b'')
        if argv[:3] == ['/usr/bin/docker', 'container', 'stop'] or argv[:2] == ['/usr/bin/systemctl', 'stop']:
            self.mutations.append(argv)
            current = g.load_identity(self.path, root_owned=False)
            if not current['fenced']: raise AssertionError('flag must persist BEFORE stopping')
            with sqlite3.connect(self.lifecycle.path) as db:
                if db.execute('SELECT COUNT(*) FROM intents').fetchone()[0] == 0:
                    raise AssertionError('intent must persist BEFORE stopping')
                if db.execute('SELECT COUNT(*) FROM retired').fetchone()[0] == 0:
                    raise AssertionError('retirement must persist BEFORE stopping')
            if self.fail_stop: raise TimeoutError('fake stop timeout')
            if argv[0] == '/usr/bin/docker':
                self.running.clear(); self.shims = False
            else:
                for name in argv[3:]:
                    if name in self.states: self.states[name] = 'inactive'
            return 0, b''
        if argv[:2] == ['/usr/bin/systemctl', 'start'] or argv[:3] == ['/usr/bin/docker', 'container', 'start']:
            self.mutations.append(argv)
            current = g.load_identity(self.path, root_owned=False)
            if current['fenced']: raise AssertionError('adopted flag must clear BEFORE starting')
            if current['machine_uuid_sha256'] != self.machine:
                raise AssertionError('must match physical clone before any start')
            if argv[0] == '/usr/bin/docker':
                self.running.update(argv[3:]); self.shims = bool(self.running)
            else:
                for name in argv[3:]: self.states[name] = 'active'
            if self.guard_changed_during_start: self.guard_installed = False
            return 0, b''
        raise AssertionError(('unexpected command', argv))


class LifecycleTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(); self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name); self.root.chmod(0o700)
        self.path = self.root/'identity.json'
        self.now = 1_800_000_000; self.counter = 0; self.key = b'only-a-fake-controller-key-32-byte!'
        self.original = {'schema': g.SCHEMA, 'service_id': '711', 'provider_id': '40',
            'generation': 1, 'config_hash': 'd'*64, 'machine_uuid_sha256': 'a'*64,
            'fenced': False, 'units': ['nginx.service', 'docker.service']}
        g.atomic_identity(self.path, self.original, root_owned=False)
        self.world = World(self.path)
        self.config = {'schema': life.SCHEMA, 'identity_file': g.DEFAULT_CONFIG,
            'controller_key_file': '/etc/servernet/relay-lifecycle.key',
            'work_dir': '/var/lib/servernet-relay-lifecycle', 'guard_sha256': 'b'*64,
            'docker_containers': [self.world.container]}
        self.side = life.Lifecycle(self.config, runner=self.world, metadata=lambda: self.world.provider,
            machine=lambda: self.world.machine, clock=lambda: self.now, root_owned=False,
            identity_path=self.path, work_dir=self.root, key=self.key,
            guard_check=lambda name, values: self.world.guard_installed,
            docker_disk=lambda: list(self.world.disk))
        self.world.lifecycle = self.side

    def request(self, op, *, intent=None, **changes):
        self.counter += 1
        identity = g.load_identity(self.path, root_owned=False)
        request = {'schema': life.REQUEST_SCHEMA, 'op': op, 'request_id': f'{self.counter:032x}',
            'nonce': f'{self.counter+100:032x}', 'service_id': identity['service_id'],
            'provider_id': identity['provider_id'], 'generation': identity['generation'],
            'config_hash': identity['config_hash'], 'issued_at': self.now, 'expires_at': self.now+60}
        if op != 'inspect': request['intent_id'] = intent or f'{self.counter:040x}'
        if op in {'adopt_clone', 'quarantine_inspect'}:
            request.update(provider_id='51', generation=2, config_hash='e'*64,
                old_provider_id='40', old_generation=1, old_config_hash='d'*64,
                old_machine_uuid_sha256='a'*64)
        if op == 'activate':
            request.update(old_provider_id='40', old_generation=1,
                old_fence_proof_digest='f'*64, old_fence_proof_at=self.now)
        request.update(changes)
        return life.sign_request(request, self.key)

    def clone(self):
        self.world.machine = 'b'*64; self.world.provider = '51'
        self.world.states = {name: 'inactive' for name in self.world.states}
        self.world.running.clear(); self.world.shims = False
        return self.side.run(self.request('adopt_clone'))

    def test_inspect_and_source_guard_installation_have_no_business_effect(self):
        result = self.side.run(self.request('inspect'))
        self.assertTrue(result['guard_installed'])
        self.assertTrue(result['active_generation_verified'])
        self.assertFalse(result['persistent_fence'])
        self.assertEqual([], self.world.mutations)
        self.assertEqual(self.original, g.load_identity(self.path, root_owned=False))

    def test_fence_is_durable_before_exact_container_and_unit_stop(self):
        result = self.side.run(self.request('fence'))
        self.assertTrue(result['persistent_fence'], result)
        self.assertTrue(result['all_units_inactive'])
        self.assertTrue(result['identity_fenced'])
        self.assertFalse(result['global_consistency_proven'])
        self.assertFalse(result['migration_complete'])
        self.assertTrue(self.world.mutations[0][:3] == ['/usr/bin/docker', 'container', 'stop'])
        self.assertFalse(any('ssh.service' in call or 'containerd.service' in call for call in self.world.mutations))

    def test_guard_or_resolved_ssh_alias_bypass_prevents_fence(self):
        self.world.guard_installed = False
        with self.assertRaisesRegex(life.LifecycleError, 'guard_not_installed'):
            self.side.run(self.request('fence'))
        self.assertFalse(g.load_identity(self.path, root_owned=False)['fenced'])
        self.world.guard_installed = True; self.world.alias = 'nginx.service'
        with self.assertRaises(g.GuardError): self.side.run(self.request('fence'))
        self.assertEqual([], self.world.mutations)

    def test_success_exit_status_accepting_guard_deny_is_not_installed(self):
        self.world.success_status = '1'
        with self.assertRaisesRegex(life.LifecycleError, 'guard_not_installed'):
            self.side.run(self.request('fence'))
        self.assertEqual([], self.world.mutations)

    def test_unloaded_disk_dropin_is_never_a_guard_installation_proof(self):
        self.world.needs_reload = 'yes'
        with self.assertRaisesRegex(life.LifecycleError, 'guard_not_installed'):
            self.side.run(self.request('fence'))
        self.assertEqual([], self.world.mutations)

    def test_effective_guard_must_have_loaded_privileged_flag_without_ignore_failure(self):
        self.side.guard_check = None
        program = b'fake-pinned-guard-for-this-offline-test'
        self.side.config['guard_sha256'] = hashlib.sha256(program).hexdigest()
        def read(path, limit=65536):
            return program if str(path) == life.GUARD_PROGRAM else life.DROPIN.encode()
        with mock.patch.object(life, '_private', side_effect=lambda p, **kwargs: p), mock.patch.object(life, '_read', side_effect=read):
            for flags in ('', 'ignore-failure', 'privileged ignore-failure', 'full-privileges'):
                self.world.loaded_flags = flags
                _, installed = self.side._units(self.original)
                self.assertFalse(installed, flags)
            self.world.loaded_flags = 'privileged'
            _, installed = self.side._units(self.original)
            self.assertTrue(installed)

    def test_extra_docker_container_is_never_stopped(self):
        self.world.api_ids.append('f'*64)
        with self.assertRaisesRegex(life.LifecycleError, 'docker_api_inventory_mismatch'):
            self.side.run(self.request('fence'))
        self.assertEqual([], self.world.mutations)
        self.assertFalse(g.load_identity(self.path, root_owned=False)['fenced'])

    def expire_after_preflight(self):
        runner = self.side.runner
        def delayed(argv, data, timeout):
            result = runner(argv, data, timeout)
            if argv[:2] == ['/usr/bin/pgrep', '-f']:
                self.now += 91
            return result
        self.side.runner = delayed

    def test_request_expiring_during_fence_preflight_never_sets_flag(self):
        request = self.request('fence'); self.expire_after_preflight()
        with self.assertRaisesRegex(life.LifecycleError, 'request_expired'):
            self.side.run(request)
        self.assertEqual(self.original, g.load_identity(self.path, root_owned=False))
        self.assertEqual([], self.world.mutations)
        with sqlite3.connect(self.side.path) as db:
            self.assertEqual(0, db.execute('SELECT COUNT(*) FROM intents').fetchone()[0])

    def test_request_expiring_during_adoption_preflight_never_changes_identity(self):
        self.world.machine = 'b'*64; self.world.provider = '51'
        self.world.states = {name: 'inactive' for name in self.world.states}
        self.world.running.clear(); self.world.shims = False
        request = self.request('adopt_clone'); self.expire_after_preflight()
        with self.assertRaisesRegex(life.LifecycleError, 'request_expired'):
            self.side.run(request)
        self.assertEqual(self.original, g.load_identity(self.path, root_owned=False))
        self.assertEqual([], self.world.mutations)

    def test_request_expiring_during_activation_preflight_never_clears_fence(self):
        self.clone(); request = self.request('activate'); self.expire_after_preflight()
        with self.assertRaisesRegex(life.LifecycleError, 'request_expired'):
            self.side.run(request)
        self.assertTrue(g.load_identity(self.path, root_owned=False)['fenced'])
        self.assertEqual([], self.world.mutations)

    def test_request_expiring_after_first_start_never_starts_following_units(self):
        self.clone(); request = self.request('activate')
        runner = self.side.runner
        def delayed(argv, data, timeout):
            result = runner(argv, data, timeout)
            if argv[:2] == ['/usr/bin/systemctl', 'start']:
                self.now += 91
            return result
        self.side.runner = delayed
        with self.assertRaisesRegex(life.LifecycleError, 'request_expired'):
            self.side.run(request)
        self.assertEqual(1, len(self.world.mutations))
        self.assertEqual('inactive', self.world.states['docker.service'])

    def test_stop_timeout_stays_fenced_and_reconcile_never_repeats_stop(self):
        request = self.request('fence'); self.world.fail_stop = True
        with self.assertRaises(life.LifecycleError): self.side.run(request)
        self.assertTrue(g.load_identity(self.path, root_owned=False)['fenced'])
        before = list(self.world.mutations)
        result = self.side.run(self.request('reconcile', intent=request['intent_id']))
        self.assertTrue(result['read_only'])
        self.assertFalse(result['persistent_fence'])
        self.assertFalse(result['recorded_completion'])
        self.assertEqual(before, self.world.mutations)
        self.world.fail_stop = False
        result = self.side.run(self.request('fence', intent=request['intent_id']))
        self.assertTrue(result['persistent_fence'])

    def test_actual_clone_must_adopt_exact_metadata_and_stays_fenced(self):
        result = self.clone()
        current = g.load_identity(self.path, root_owned=False)
        self.assertEqual(('51', 2, 'b'*64, True),
                         (current['provider_id'], current['generation'], current['machine_uuid_sha256'], current['fenced']))
        self.assertTrue(result['persistent_fence'])
        self.assertEqual([], self.world.mutations)

    def quarantine_source(self):
        self.side.run(self.request('fence'))
        self.world.machine='b'*64; self.world.provider='51'

    def bootstrap_files(self):
        guest={'schema':'servernet.relay.bootstrap.guest.v1','profile':'restored','service_id':'711',
            'intent_id':'9'*40,'candidate_provider_id':'51','generation':2,'config_hash':'e'*64,
            'source_identity':{'provider_id':'40','generation':1,'config_hash':'d'*64}}
        marker={'schema':'servernet.relay.machine-id.marker.v1','scope':hashlib.sha256(life.canonical(guest)).hexdigest(),
            **{k:guest[k] for k in ('service_id','intent_id','candidate_provider_id','generation','config_hash')},
            'phase':'complete','boot_id':'11111111-1111-1111-1111-111111111111','old_machine_id':'a'*32,
            'new_boot_id':'22222222-2222-2222-2222-222222222222','new_machine_id':'b'*32}
        files={'/etc/servernet/relay-bootstrap.json':life.canonical(guest),
            '/var/lib/servernet-relay-bootstrap/'+'9'*40+'/machine-id.json':life.canonical(marker),
            '/etc/machine-id':('b'*32+'\n').encode(),
            '/proc/sys/kernel/random/boot_id':b'22222222-2222-2222-2222-222222222222\n'}
        self.side.bootstrap_read=lambda path,private=True:files[path]
        return files,marker

    def test_quarantine_is_readonly_and_requires_exact_new_physical_old_fenced_identity(self):
        self.quarantine_source(); before=list(self.world.mutations); identity=self.path.read_bytes()
        result=self.side.run(self.request('quarantine_inspect'))
        self.assertTrue(result['quarantine_verified']); self.assertTrue(result['read_only'])
        self.assertFalse(result['physical_identity_verified']); self.assertFalse(result['persistent_fence'])
        self.assertTrue(result['guard_denies_current_machine']); self.assertTrue(result['docker_socket_inactive'])
        self.assertTrue(result['journal_adoption_absent']); self.assertFalse(result['bootstrap']['machine_id_ready'])
        self.assertEqual('51',result['provider_id']); self.assertEqual('40',result['inherited_identity']['provider_id'])
        self.assertEqual(before,self.world.mutations); self.assertEqual(identity,self.path.read_bytes())

    def test_quarantine_never_starts_docker_and_active_socket_or_shim_prevents_proof(self):
        self.quarantine_source(); before=list(self.world.mutations)
        self.world.states['docker.socket']='active'
        result=self.side.run(self.request('quarantine_inspect'))
        self.assertFalse(result['quarantine_verified'])
        self.world.states['docker.socket']='inactive'; self.world.shims=True
        result=self.side.run(self.request('quarantine_inspect'))
        self.assertFalse(result['quarantine_verified']); self.assertEqual(before,self.world.mutations)

    def test_quarantine_rejects_unfenced_source_or_wrong_new_metadata(self):
        self.world.machine='b'*64; self.world.provider='51'
        with self.assertRaisesRegex(life.LifecycleError,'clone_quarantine_identity_unverified'):
            self.side.run(self.request('quarantine_inspect'))
        self.world.machine='a'*64; self.world.provider='40'; self.side.run(self.request('fence'))
        self.world.machine='b'*64; self.world.provider='52'
        with self.assertRaisesRegex(life.LifecycleError,'clone_quarantine_identity_unverified'):
            self.side.run(self.request('quarantine_inspect'))

    def test_quarantine_bootstrap_proof_binds_current_machine_current_boot_and_guest_scope(self):
        self.quarantine_source(); files,marker=self.bootstrap_files()
        result=self.side.run(self.request('quarantine_inspect'))
        bootstrap=result['bootstrap']; self.assertTrue(bootstrap['machine_id_ready'])
        self.assertNotIn('new_machine_id',bootstrap); self.assertNotIn('boot_id',bootstrap)
        self.assertEqual(hashlib.sha256(('b'*32).encode()).hexdigest(),bootstrap['machine_id_sha256'])
        files['/proc/sys/kernel/random/boot_id']=b'33333333-3333-3333-3333-333333333333'
        self.assertFalse(self.side.run(self.request('quarantine_inspect'))['bootstrap']['machine_id_ready'])
        files['/proc/sys/kernel/random/boot_id']=marker['new_boot_id'].encode()
        marker['scope']='0'*64
        files['/var/lib/servernet-relay-bootstrap/'+'9'*40+'/machine-id.json']=life.canonical(marker)
        self.assertFalse(self.side.run(self.request('quarantine_inspect'))['bootstrap']['machine_id_ready'])

    def test_quarantine_existing_adoption_intent_never_reports_absence(self):
        self.quarantine_source(); adopt=self.request('adopt_clone')
        self.side._begin(adopt)
        result=self.side.run(self.request('quarantine_inspect',intent=adopt['intent_id']))
        self.assertFalse(result['journal_adoption_absent'])
        self.assertEqual('adopt_clone',result['recorded_operation']); self.assertFalse(result['recorded_completion'])

    def test_quarantine_machine_identity_proof_rejects_zero_original_machine_id(self):
        self.quarantine_source();files,marker=self.bootstrap_files()
        marker['old_machine_id']='0'*32
        files['/var/lib/servernet-relay-bootstrap/'+'9'*40+'/machine-id.json']=life.canonical(marker)
        self.assertFalse(self.side.run(self.request('quarantine_inspect'))['bootstrap']['machine_id_ready'])

    def test_original_or_wrong_provider_cannot_be_adopted_as_clone(self):
        with self.assertRaisesRegex(life.LifecycleError, 'clone_identity_unverified'):
            self.side.run(self.request('adopt_clone'))
        self.world.machine = 'b'*64; self.world.provider = '52'
        with self.assertRaisesRegex(life.LifecycleError, 'clone_identity_unverified'):
            self.side.run(self.request('adopt_clone'))
        self.assertEqual(self.original, g.load_identity(self.path, root_owned=False))

    def test_clone_with_any_running_writer_cannot_adopt(self):
        self.world.machine = 'b'*64; self.world.provider = '51'
        with self.assertRaisesRegex(life.LifecycleError, 'clone_writers_not_quiet'):
            self.side.run(self.request('adopt_clone'))

    def test_activation_requires_adoption_and_fresh_old_fence_attestation(self):
        with self.assertRaisesRegex(life.LifecycleError, 'clone_adoption_missing'):
            self.side.run(self.request('activate'))
        self.clone()
        with self.assertRaisesRegex(life.LifecycleError, 'old_fence_proof_stale'):
            self.side.run(self.request('activate', old_fence_proof_at=self.now-91))
        self.assertTrue(g.load_identity(self.path, root_owned=False)['fenced'])

    def test_activation_starts_only_adopted_reviewed_generation(self):
        self.clone()
        result = self.side.run(self.request('activate'))
        self.assertTrue(result['active_generation_verified'], result)
        self.assertEqual('activated', result['outcome'])
        self.assertFalse(result['persistent_fence'])
        self.assertFalse(result['migration_complete'])
        self.assertFalse(g.load_identity(self.path, root_owned=False)['fenced'])

    def test_activation_waits_readonly_for_docker_before_exact_manual_container_start(self):
        self.clone(); runner=self.side.runner; unavailable=[True]; waits=[]
        def starting(argv,data,timeout):
            if argv[:3] == ['/usr/bin/docker','container','ls'] and self.world.states['docker.service']=='active' and unavailable[0]:
                unavailable[0]=False
                return 1,b''
            return runner(argv,data,timeout)
        self.side.runner=starting; self.side.wait=lambda seconds:waits.append(seconds)
        result=self.side.run(self.request('activate'))
        self.assertTrue(result['active_generation_verified']); self.assertEqual([0.1],waits)
        self.assertEqual(['/usr/bin/docker','container','start',self.world.container],self.world.mutations[-1])

    def test_retired_adopted_generation_cannot_reactivate_with_old_lineage(self):
        self.clone(); self.side.run(self.request('activate')); self.side.run(self.request('fence'))
        before = list(self.world.mutations)
        with self.assertRaisesRegex(life.LifecycleError, 'retired_generation_cannot_activate'):
            self.side.run(self.request('activate'))
        self.assertTrue(g.load_identity(self.path, root_owned=False)['fenced'])
        self.assertEqual(before, self.world.mutations)

    def test_activation_does_not_claim_success_if_guard_changes_during_start(self):
        self.clone(); self.world.guard_changed_during_start = True
        result = self.side.run(self.request('activate'))
        self.assertFalse(result['ok'])
        self.assertFalse(result['active_generation_verified'])

    def test_reconcile_is_read_only_even_when_activation_was_completed(self):
        self.clone(); request = self.request('activate'); self.side.run(request)
        before, identity = list(self.world.mutations), self.path.read_bytes()
        result = self.side.run(self.request('reconcile', intent=request['intent_id']))
        self.assertTrue(result['read_only'])
        self.assertTrue(result['recorded_completion'])
        self.assertTrue(result['active_generation_verified'])
        self.assertEqual(before, self.world.mutations)
        self.assertEqual(identity, self.path.read_bytes())

    def test_reconcile_proves_fence_never_started_only_on_exact_active_unguarded_writer_history(self):
        request=self.request('fence')
        result=self.side.run(self.request('reconcile',intent=request['intent_id']))
        self.assertEqual(['fence'],result['not_started_for'])
        self.assertTrue(result['systemd_jobs_clear']);self.assertFalse(result['current_generation_retired'])
        self.assertRegex(result['writer_inventory_sha256'],r'^[a-f0-9]{64}$')
        self.assertEqual([],self.world.mutations)
        self.side._begin(request)  # Intent alone removes definitive absence.
        result=self.side.run(self.request('reconcile',intent=request['intent_id']))
        self.assertEqual([],result['not_started_for']);self.assertEqual('fence',result['recorded_operation'])
        other=self.side.run(self.request('reconcile'))
        self.assertEqual([],other['not_started_for'])  # New UUID cannot bypass it.

    def test_reconcile_never_started_blocks_tombstone_or_pending_systemd_jobs(self):
        self.world.pending_job=True
        self.assertEqual([],self.side.run(self.request('reconcile'))['not_started_for'])
        self.world.pending_job=False
        with self.side._db()as db:
            db.execute('INSERT INTO retired VALUES(?,?,?,?,?)',(1,'40','a'*64,'9'*40,self.now))
        self.assertEqual([],self.side.run(self.request('reconcile'))['not_started_for'])

    def test_reconcile_proves_activation_absent_only_for_exact_unretired_adopted_fenced_clone(self):
        self.clone();request=self.request('activate')
        result=self.side.run(self.request('reconcile',intent=request['intent_id']))
        self.assertEqual(['activate'],result['not_started_for'])
        self.assertTrue(result['activation_adoption_verified']);self.assertEqual('40',result['adopted_from']['provider_id'])
        self.side._begin(request)
        result=self.side.run(self.request('reconcile',intent=request['intent_id']))
        self.assertEqual([],result['not_started_for']);self.assertEqual('activate',result['recorded_operation'])

    def test_bad_signature_replay_expiry_and_command_field_never_mutate(self):
        request = self.request('inspect'); bad = {**request, 'signature': '0'*64}
        with self.assertRaisesRegex(life.LifecycleError, 'request_authentication'): self.side.run(bad)
        self.side.run(request)
        with self.assertRaisesRegex(life.LifecycleError, 'replay'): self.side.run(request)
        with self.assertRaisesRegex(life.LifecycleError, 'request_expired'):
            self.side.run(self.request('fence', expires_at=self.now))
        with self.assertRaisesRegex(life.LifecycleError, 'request_fields'):
            self.side.run(self.request('fence', command='anything'))
        self.assertEqual([], self.world.mutations)

    def test_wrong_service_generation_or_config_never_fences(self):
        for patch in ({'service_id': '712'}, {'generation': 2}, {'config_hash': '0'*64}):
            with self.assertRaisesRegex(life.LifecycleError, 'identity_scope'):
                self.side.run(self.request('fence', **patch))
        self.assertEqual([], self.world.mutations)

    def test_metadata_unavailable_allows_original_dmi_fallback_but_not_adopt(self):
        def absent(): raise life.LifecycleError('unavailable')
        self.side.metadata = absent
        result = self.side.run(self.request('fence'))
        self.assertTrue(result['persistent_fence'])
        self.world.machine = 'b'*64
        with self.assertRaises(life.LifecycleError): self.side.run(self.request('adopt_clone'))

    def test_durable_identity_without_adoption_intent_is_not_resumable(self):
        self.world.provider = '51'; self.world.machine = 'b'*64
        self.world.states = {k: 'inactive' for k in self.world.states}
        self.world.running.clear(); self.world.shims = False
        g.atomic_identity(self.path, {**self.original, 'provider_id': '51', 'generation': 2,
            'config_hash': 'e'*64, 'machine_uuid_sha256': 'b'*64, 'fenced': True}, root_owned=False)
        with self.assertRaisesRegex(life.LifecycleError, 'clone_adoption_intent_missing'):
            self.side.run(self.request('adopt_clone'))

    def test_pending_adoption_can_resume_after_identity_write_before_adoption_receipt(self):
        self.world.provider = '51'; self.world.machine = 'b'*64
        self.world.states = {k: 'inactive' for k in self.world.states}; self.world.running.clear(); self.world.shims = False
        request = self.request('adopt_clone'); self.side.run(request)
        with sqlite3.connect(self.side.path) as db:
            db.execute('DELETE FROM adoption'); db.execute('UPDATE intents SET receipt=NULL WHERE id=?', (request['intent_id'],))
        result = self.side.run(self.request('adopt_clone', intent=request['intent_id']))
        self.assertEqual('adopted_fenced', result['outcome'])

    def test_renewed_fence_proof_can_resume_same_activation_target(self):
        self.clone(); request = self.request('activate'); self.side.run(request)
        self.now += 10
        result = self.side.run(self.request('activate', intent=request['intent_id'],
                                         old_fence_proof_digest='a'*64, old_fence_proof_at=self.now))
        self.assertTrue(result['active_generation_verified'])


if __name__ == '__main__':
    unittest.main()
