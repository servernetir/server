"""Actual gateway/agent/adapter journals, fake scoped recipes and provider GETs.

No network, subprocess, VM lifecycle, key generation or production enrollment.
"""
import base64
import copy
import hashlib
import io
import json
from pathlib import Path
import sqlite3
import struct
import tempfile
import threading
from types import SimpleNamespace
import unittest
import uuid
from unittest.mock import patch

from infra.relay_recovery import agent, controller_adapter as ca, core, ct_stage
from infra.relay_recovery import gateway, iran_adapter, iran_effects as effects, protocol
from infra.relay_recovery.production_driver import DriverStore


NOW = 1_800_000_000
SOURCE = dict(provider='hetzner', project_id='p', provider_id='40', generation=1)
TARGET = dict(provider='hetzner', project_id='p', provider_id='51', generation=2)
OLD, NEW = '8.8.8.8', '8.8.4.4'
PARENT = 'a'*40


class Provider:
    def __init__(self): self.addresses = {'40': OLD, '51': NEW}; self.calls = []
    def probe_target(self, scoped):
        self.calls.append(copy.deepcopy(scoped))
        return dict(identity=copy.deepcopy(scoped), ipv4=self.addresses[scoped['provider_id']])
    def quotes(self): return []
    def inventory(self, scoped):
        return dict(complete=True, provider_access=True, project_id='p', active_identity=scoped, managed_vm_count=2)


class BridgeTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name); self.base.chmod(0o700)
        self.root = self.base/'whm'; self.root.mkdir(mode=0o700)
        self.agent_root = self.base/'pve'; self.agent_root.mkdir(mode=0o700)
        self.bundle_root = self.base/'bundles'; self.bundle_root.mkdir(mode=0o700)
        self.now = NOW; self.key = b'k'*32; self.provider = Provider()
        keyfile = self.private(self.root/'key', self.key)
        self.recipe_specs = {}
        for action in ('stage', 'apply', 'accept', 'reconcile', 'host', 'ct113', 'vm109'):
            executable = self.private(self.base/('recipe-'+action), b'fixture never executed')
            executable.chmod(0o700)
            self.recipe_specs[action] = dict(action=action, argv=[str(executable)],
                executable_sha256=hashlib.sha256(executable.read_bytes()).hexdigest(), inputs=[])
        bundle = dict(schema=iran_adapter.BUNDLE_SCHEMA, agent_id='ir-agent', candidate_ports=[22, 443],
            recipes={k: k for k in ('stage', 'apply', 'accept', 'reconcile')})
        raw = protocol.canonical_json(bundle); self.config_hash = hashlib.sha256(raw).hexdigest()
        self.private(self.bundle_root/(self.config_hash+'.json'), raw)
        registry = dict(schema='servernet.relay.dependency.registry.v1', owner_id='1', service_id='7001',
            project_id='p', revision=1, consumers=['vm109'], resources=[dict(id='ct-endpoint', kind='ct_endpoint')])
        raw = protocol.canonical_json(registry)
        registry_path = self.private(self.root/'registry', raw)
        self.cfg = dict(state_dir=str(self.root), agents={'ir-agent': dict(enabled=True, key_file=str(keyfile))},
            policy=dict(enabled=True, owner_id='1', service_id='7001', project_id='p', critical_consumers=['vm109']),
            binding=dict(owner_id='1', service_id='7001', ipv4=OLD),
            production_driver=dict(enabled=True, registry=dict(path=str(registry_path), sha256=hashlib.sha256(raw).hexdigest())),
            collector=dict(recipes={}))
        self.active = {**SOURCE, 'config_hash': 'c'*64}
        self.candidate = {**TARGET, 'config_hash': self.config_hash}
        self.cfg['active'] = self.active
        self.context = dict(schema='servernet.relay.production-driver.v1', owner_id='1', service_id='7001',
            project_id='p', source_identity=SOURCE, identity=TARGET, config_hash=self.config_hash,
            registry_hash=self.cfg['production_driver']['registry']['sha256'], parent_intent_id=PARENT,
            incident_id='incident-1', restore_epoch=None, restore_hash=None)
        blob = struct.pack('!I', 11)+b'ssh-ed25519'+struct.pack('!I', 32)+b'x'*32
        public = 'ssh-ed25519 '+base64.b64encode(blob).decode()
        self.params = dict(stage_profile='trial', ssh_host_public_key=public,
                          ssh_host_key_sha256=ct_stage.fingerprint(public))
        self.permit = dict(schema='servernet.relay.driver.permit.v1', intent_id=str(uuid.uuid4()),
            parent_intent_id=PARENT, kind='TRIAL_STAGE', context_hash=core.digest(self.context), created_at=NOW, expires_at=NOW+30)
        self.state = dict(active=self.active, candidate=self.candidate, retiring=None,
            incident_id='incident-1', phase='VALIDATING', pending_action=None, clock_unsafe=False, last_now=NOW)
        with sqlite3.connect(self.root/'controller.sqlite3') as db:
            db.execute('CREATE TABLE state(id INTEGER PRIMARY KEY,body TEXT)')
            db.execute('CREATE TABLE actions(id TEXT PRIMARY KEY,kind TEXT,body TEXT)')
        (self.root/'controller.sqlite3').chmod(0o600)
        self.driver = DriverStore(self.root); self.save_claim()
        self.backend_calls = []; self.backend_fates = {}; self.timeout_after = False; self.endpoint = OLD
        ir_config = dict(agent_id='ir-agent', root_owned=False, manifest_dir=str(self.bundle_root),
            candidate_ports=[22, 443], vm109_source_ip='192.168.10.109',
            recipes={k: self.recipe_specs[k] for k in ('stage', 'apply', 'accept', 'reconcile')},
            probes={k: self.recipe_specs[k] for k in ('host', 'ct113', 'vm109')}, consumer_probes={})
        self.iran = iran_adapter.IranAdapter(ir_config, runner=self.recipe_runner,
            tcp=lambda *a: True, icmp=lambda *a: None, now=lambda: self.now)
        self.transport_headers = []; self.poll_receipts = []
        self.agent_config = dict(agent_id='ir-agent', key=self.key,
            controller_url='https://controller.invalid/relay-recovery-agent.php', controller_path='/relay-recovery-agent.php',
            control_targets=[dict(host='control.invalid', port=443)], candidate_ports=[22, 443],
            state_dir=str(self.agent_root))
        self.remote = self.make_agent()
        self.adapter = ca.Adapter(self.cfg, NOW, provider=self.provider)
        self.publisher = effects.IranEffectPublisher(self.cfg, adapter=self.adapter, provider=self.provider,
            clock=lambda: self.now, allow_test_paths=True)

    @staticmethod
    def private(path, raw): path.write_bytes(raw); path.chmod(0o600); return path

    def save_claim(self, phase='DISPATCHED', audit=True):
        body = core.canonical(dict(permit=self.permit, context=self.context))
        with self.driver.transaction() as db:
            db.execute('DELETE FROM steps'); db.execute('DELETE FROM hook_dispatches')
            db.execute('INSERT INTO steps VALUES(?,?,?,?,?,NULL)',
                (self.permit['intent_id'], PARENT, self.permit['kind'], body, phase))
            if audit: db.execute('INSERT INTO hook_dispatches VALUES(?,?,?)', (self.permit['intent_id'], body, NOW))
        with sqlite3.connect(self.root/'controller.sqlite3') as db:
            db.execute('INSERT OR REPLACE INTO state VALUES(1,?)', (core.canonical(self.state),))
            if self.permit['kind'] == 'EXTERNAL_CUTOVER':
                action = dict(id=PARENT, kind='PROMOTE', created_at=NOW, expires_at=NOW+30,
                    payload=dict(identity=TARGET, old_identity=SOURCE))
                db.execute('INSERT OR REPLACE INTO actions VALUES(?,?,?)', (PARENT, 'PROMOTE', core.canonical(action)))

    def recipe_runner(self, argv, raw, timeout):
        action = Path(argv[0]).name.removeprefix('recipe-'); context = json.loads(raw)
        self.backend_calls.append(action)
        common = {**{k: context[k] for k in iran_adapter.SCOPE}, 'target': context['job']['target'], 'ok': True}
        if action in ('host', 'ct113', 'vm109'):
            checks = (dict(host_healthy=True, direct_control=True, internet_healthy=True) if action == 'host'
                else dict(execution_origin='ct113' if action == 'ct113' else 'guest',
                    tunnel_ok=True, probe_ok=True, egress_ip=self.endpoint, source_ip='192.168.10.109'))
            return 0, protocol.canonical_json(dict(schema=iran_adapter.PROBE_SCHEMA, probe_kind=action,
                read_only=True, checks=checks, **common))
        key = ct_stage.context_key(context); original = context['job']['op']
        if action in ('stage', 'apply'):
            ct_stage.validate_context(context, 'ir-agent')
            self.assertLess(self.now, context['job']['params']['expires_at'])
            self.backend_fates[key] = True
            if action == 'apply': self.endpoint = context['job']['target']['host']
            if self.timeout_after: raise protocol.ProtocolError('adapter_timeout')
        good = self.backend_fates.get(key, False) if action == 'reconcile' else True
        checks = dict(generation_verified=good, **{{'stage': 'staged', 'apply': 'applied', 'accept': 'accepted'}[original]: good})
        if action == 'reconcile': checks['historical_outcome'] = True
        return 0, protocol.canonical_json(dict(schema=iran_adapter.RECIPE_SCHEMA, action=action,
            read_only=action in ('accept', 'reconcile'), reconciled=action == 'reconcile',
            checks=checks, **{**common, 'ok': good}))

    def transport(self, url, raw, headers):
        self.transport_headers.append(copy.deepcopy(headers)); self.poll_receipts.extend(json.loads(raw)['report']['jobs'])
        reply = gateway.handle(dict(method='POST', path='/relay-recovery-agent.php', body=raw.decode(), headers=headers), self.cfg, self.now)
        return reply['status'], reply['headers'], reply['body'].encode()

    def make_agent(self):
        return agent.Agent(self.agent_config, transport=self.transport, adapter=self.iran.run,
            tcp=lambda *a: True, icmp=lambda *a: None, monotonic=lambda: 0)

    def publish(self, authorize=lambda p: True):
        return self.publisher.publish(self.permit, self.context,
            'stage' if self.permit['kind'] == 'TRIAL_STAGE' else 'apply', self.params, authorize)

    def deliver(self):
        self.remote.run_once(self.now); self.now += 1; self.remote.run_once(self.now)
        return self.publisher.reconcile(self.permit['intent_id'])

    def send_receipt(self, receipt):
        report = dict(agent_id='ir-agent', jobs=[receipt], observed_at=self.now, direct_control_tcp=[True])
        raw = protocol.canonical_json(dict(agent_id='ir-agent', op='poll', report=report))
        headers = protocol.sign_message(self.key, 'ir-agent', 'POST', '/relay-recovery-agent.php', raw, self.now)
        self.assertEqual(self.transport(self.agent_config['controller_url'], raw, headers)[0], 200)

    def isolated_enrollment(self):
        value=dict(schema=effects.ISOLATED_ENROLLMENT,enabled=True,agent_id='ir-agent',
            binding={k:self.context[k] for k in ('owner_id','service_id','project_id','registry_hash','config_hash')},
            producer_config_sha256='a'*64,verifier_sha256='b'*64,expected_registry_hash='c'*64,
            dependency_baseline_sha256='d'*64,proxy_binding_sha256='e'*64)
        path=self.private(self.root/'isolated-publication.json',protocol.canonical_json(value))
        return dict(path=str(path),sha256=hashlib.sha256(path.read_bytes()).hexdigest())

    def test_isolated_accept_retains_original_stage_ssh_binding_and_uses_separate_fresh_read_lease(self):
        original=self.publish()['manifest'];self.deliver();self.now+=40
        reference=self.isolated_enrollment()
        result=self.publisher.publish_isolated_accept(self.permit['intent_id'],reference)
        manifest=result['manifest'];params=manifest['jobs'][0]['params']
        self.assertEqual(manifest['expires_at'],self.now+25)
        self.assertGreater(manifest['expires_at'],self.permit['expires_at'])
        self.assertEqual(params['expires_at'],manifest['expires_at'])
        for key in ('ssh_host_public_key','ssh_host_key_sha256'):
            self.assertEqual(params[key],original['jobs'][0]['params'][key])
        self.assertEqual(params['acceptance_profile'],effects.ISOLATED_PROFILE)
        self.assertNotIn('critical_consumers',params)
        row=self.publisher._row(result['publication_id']);saved=json.loads(row['body'])
        ssh={k:params[k] for k in ('ssh_host_public_key','ssh_host_key_sha256')}
        self.assertEqual(saved['isolated_accept']['ssh_binding_sha256'],core.digest(ssh))
        self.assertEqual(saved['permit'],self.permit)
        self.assertEqual(saved['isolated_accept']['stage_manifest_sha256'],core.digest(original))
        self.now+=1
        self.assertEqual(result,self.publisher.publish_isolated_accept(self.permit['intent_id'],reference))
        self.assertEqual(self.publisher.reconcile(self.permit['intent_id'])['phase'],'SUCCEEDED')

    def test_isolated_accept_requires_actual_terminal_stage_outcome_and_exact_private_binding(self):
        self.publish();reference=self.isolated_enrollment()
        with self.assertRaisesRegex(ca.CollectorError,'stage_not_succeeded'):
            self.publisher.publish_isolated_accept(self.permit['intent_id'],reference)
        self.deliver()
        value=protocol.decode_json(Path(reference['path']).read_bytes());value['binding']['owner_id']='2'
        raw=protocol.canonical_json(value);Path(reference['path']).write_bytes(raw)
        reference['sha256']=hashlib.sha256(raw).hexdigest()
        with self.assertRaisesRegex(ca.CollectorError,'enrollment_scope'):
            self.publisher.publish_isolated_accept(self.permit['intent_id'],reference)
        with self.adapter._db() as db:
            db.execute("DELETE FROM iran_outcomes")
        reference=self.isolated_enrollment()
        with self.assertRaisesRegex(ca.CollectorError,'stage_outcome_missing'):
            self.publisher.publish_isolated_accept(self.permit['intent_id'],reference)

    def test_default_and_isolated_accepts_never_coalesce_or_overwrite_pending_profile(self):
        self.publish();self.deliver();reference=self.isolated_enrollment()
        isolated=self.publisher.publish_isolated_accept(self.permit['intent_id'],reference)
        raw=(self.root/'manifest-ir-agent.json').read_bytes()
        with self.assertRaisesRegex(ca.CollectorError,'profile_pending'):
            self.publisher.publish_accept(self.permit['intent_id'])
        self.assertEqual(raw,(self.root/'manifest-ir-agent.json').read_bytes())
        self.assertEqual(isolated['phase'],'PENDING')

    def test_isolated_api_cannot_relabel_pending_default_accept(self):
        self.publish();self.deliver();self.publisher.publish_accept(self.permit['intent_id'])
        raw=(self.root/'manifest-ir-agent.json').read_bytes()
        with self.assertRaisesRegex(ca.CollectorError,'profile_pending'):
            self.publisher.publish_isolated_accept(self.permit['intent_id'],self.isolated_enrollment())
        self.assertEqual(raw,(self.root/'manifest-ir-agent.json').read_bytes())

    def test_isolated_enrollment_or_current_scope_drift_during_provider_read_prevents_exposure(self):
        self.publish();self.deliver();reference=self.isolated_enrollment();before=self.provider.probe_target
        path=self.root/'manifest-ir-agent.json';old=path.read_bytes();calls=[]
        def changed(identity):
            result=before(identity);calls.append(identity)
            if len(calls)==2:
                self.state['incident_id']='new-incident'
                with sqlite3.connect(self.root/'controller.sqlite3') as db:
                    db.execute('UPDATE state SET body=? WHERE id=1',(core.canonical(self.state),))
            return result
        with patch.object(self.provider,'probe_target',side_effect=changed):
            with self.assertRaisesRegex(ca.CollectorError,'core_scope_changed'):
                self.publisher.publish_isolated_accept(self.permit['intent_id'],reference)
        self.assertEqual(path.read_bytes(),old)
        with self.adapter._db() as db:
            row=db.execute("SELECT phase,exposure FROM iran_jobs WHERE operation='accept'").fetchone()
        self.assertEqual(tuple(row),('PENDING','PREPARED'))

    def test_expired_pending_isolated_job_cannot_receive_a_new_read_revision(self):
        self.publish();self.deliver();reference=self.isolated_enrollment()
        pending=self.publisher.publish_isolated_accept(self.permit['intent_id'],reference)
        self.now+=25
        with self.assertRaisesRegex(ca.CollectorError,'pending_expired'):
            self.publisher.publish_isolated_accept(self.permit['intent_id'],reference)
        self.assertEqual(pending['manifest']['revision'],self.publisher._row(pending['publication_id'])['revision'])

    def test_default_boolean_accept_response_cannot_succeed_for_isolated_publication(self):
        self.publish();self.deliver()
        isolated=self.publisher.publish_isolated_accept(self.permit['intent_id'],self.isolated_enrollment())
        manifest=isolated['manifest'];job=manifest['jobs'][0]
        receipt=dict(schema=protocol.SCHEMA,agent_id='ir-agent',
            **{k:manifest[k] for k in ('incident_id','generation','revision','config_hash','jobs_hash')},
            job_id=job['id'],op='accept',target=job['target'],ok=True,code='completed',observed_at=self.now,
            checks={'adapter':{'accepted':True,'generation_verified':True}})
        self.send_receipt(receipt)
        result=self.publisher.reconcile(isolated['publication_id'])
        self.assertEqual(result['phase'],'PENDING');self.assertFalse(result['fresh_observation'])
        with self.adapter._db() as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM receipts').fetchone()[0],0)

    def native_isolated(self):
        """Real pinned producer/CLI/backend/client/verifier; only OS/wire fake."""
        from infra.relay_recovery.tests import test_isolated_trial as trial_tests
        from infra.relay_recovery.tests import test_windows_candidate_probe_client as guest_tests
        from infra.relay_recovery import isolated_trial as trial
        fixture=trial_tests.IsolatedTrialTests();fixture.setUp();self.addCleanup(fixture.doCleanups)
        fixture.now=self.now;fixture.ct.now=self.now
        fixture.ct.cfg['agent_id']='ir-agent';fixture.ct.process_host=NEW;fixture.ct.egress=NEW
        fixture.node_fixture.raw['agent_id']='ir-agent';fixture.node_fixture.cfg['agent_id']='ir-agent'
        fixture.refs['node_stage']=fixture.pin('node.json',fixture.node_fixture.raw)
        fixture.proxy['agent_id']='ir-agent'
        fixture.proxy['proxy_binding_sha256']=trial.candidate.proxy_binding('ir-agent',
            fixture.proxy['proxy_host'],fixture.proxy['proxy_port'])
        fixture.refs['candidate_proxy']=fixture.pin('proxy.json',fixture.proxy)
        for key in ('windows','verifier','dependencies'):
            value=protocol.decode_json(Path(fixture.refs[key]['path']).read_bytes());value['agent_id']='ir-agent'
            if key=='verifier':value['proxy_binding_sha256']=fixture.proxy['proxy_binding_sha256']
            fixture.refs[key]=fixture.pin(key+'.json',value)
        fixture.config.update(agent_id='ir-agent',**fixture.refs)
        fixture.reference=fixture.pin('producer.json',fixture.config)
        fixture.peer=guest_tests.CandidateGuest(fixture.proxy,trial.windows.SOURCE_HMAC_POLICY)
        fixture.peer.response_timestamp=self.now
        guest_clock=patch.object(guest_tests,'NOW',self.now);guest_clock.start();self.addCleanup(guest_clock.stop)
        source=json.loads(self.publisher._row(self.permit['intent_id'])['body'])['manifest']
        wire=dict(schema=protocol.SCHEMA,**{k:source[k] for k in
            ('agent_id','incident_id','generation','revision','config_hash','jobs_hash')},job=source['jobs'][0])
        fixture.ct.ctx=wire
        self.assertTrue(fixture.ct.helper().run('stage',wire)['ok'])
        fixture.ct.calls.clear();fixture.node_fixture.calls.clear();fixture.node_fixture.remote.clear()
        baseline=fixture.producer().inspect_dependencies()
        enrolled=dict(schema=effects.ISOLATED_ENROLLMENT,enabled=True,agent_id='ir-agent',
            binding={k:self.context[k] for k in ('owner_id','service_id','project_id','registry_hash','config_hash')},
            producer_config_sha256=fixture.reference['sha256'],verifier_sha256=fixture.refs['verifier']['sha256'],
            expected_registry_hash=fixture.refs['dependencies']['sha256'],
            dependency_baseline_sha256=baseline['state_sha256'],proxy_binding_sha256=fixture.proxy['proxy_binding_sha256'])
        path=self.private(self.root/'actual-isolated-enrollment.json',protocol.canonical_json(enrolled))
        reference=dict(path=str(path),sha256=hashlib.sha256(path.read_bytes()).hexdigest())
        config=self.iran.config;config['isolated_trial']=fixture.reference;config['isolated_verifier']=fixture.refs['verifier']
        spec=copy.deepcopy(config['recipes']['accept'])
        spec.update(acceptance_profile=effects.ISOLATED_PROFILE,timeout=20,
            inputs=[fixture.reference],argv=[spec['argv'][0],'--config',fixture.reference['path'],
                '--config-sha256',fixture.reference['sha256'],'accept'])
        config['recipes']['accept']=spec
        real_producer=trial.IsolatedTrialProducer;self.cli_calls=[]
        def runner(argv,raw,timeout):
            self.cli_calls.append((copy.deepcopy(argv),raw,timeout))
            output=io.BytesIO()
            def factory(ref):return real_producer(ref,root_owned=False,clock=lambda:fixture.now,monotonic=lambda:fixture.mono)
            with patch.object(trial,'IsolatedTrialProducer',side_effect=factory), \
                 patch.object(trial.os,'geteuid',return_value=0), \
                 patch.object(trial.signal,'signal'),patch.object(trial.signal,'alarm'), \
                 patch.object(trial.sys,'stdin',SimpleNamespace(buffer=io.BytesIO(raw))), \
                 patch.object(trial.sys,'stdout',SimpleNamespace(buffer=output)):
                status=trial.main(argv[1:])
            return status,output.getvalue()
        def dispatch(action,context):
            return iran_adapter.build_adapter(config,action=action,context=context,runner=runner,
                now=lambda:self.now,tcp=lambda *a:True,icmp=lambda *a:None).run(action,context)
        self.iran=SimpleNamespace(config=config,run=dispatch);self.remote=self.make_agent()
        return fixture,reference

    def test_actual_signed_pull_cli_producer_native_ct_guest_and_single_final_verifier(self):
        self.publish();self.deliver();fixture,reference=self.native_isolated()
        isolated=self.publisher.publish_isolated_accept(self.permit['intent_id'],reference)
        self.remote.run_once(self.now);self.now+=1;self.remote.run_once(self.now)
        result=self.publisher.reconcile(isolated['publication_id'])
        self.assertEqual(result['phase'],'SUCCEEDED');self.assertTrue(result['authenticated'])
        self.assertTrue(result['fresh_observation']);self.assertEqual(len(self.cli_calls),1)
        self.assertEqual(self.cli_calls[0][2],20)
        detail=result['receipt']['checks']['adapter'];proof=detail['isolated_candidate']
        self.assertEqual(detail['acceptance_profile'],effects.ISOLATED_PROFILE)
        self.assertTrue(proof['verified']);self.assertFalse(proof['default_path_verified'])
        self.assertFalse(proof['global_cutover_performed']);self.assertEqual(len(fixture.peer.calls),1)
        verifier=fixture.root/'verifier-state'/'isolated-guest-responses.sqlite3'
        with sqlite3.connect(verifier) as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM replay').fetchone()[0],1)
        encoded=protocol.canonical_json(result).decode()
        for forbidden in ('body_base64','X-VM-Signature','fixture never used'):
            self.assertNotIn(forbidden,encoded)
        with self.adapter._db() as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM receipts').fetchone()[0],0)
        # A structurally matching JSON boolean must not alias numeric identity1.
        manifest=copy.deepcopy(isolated['manifest']);job=manifest['jobs'][0]
        candidate_detail=copy.deepcopy(detail)
        job['params']['resource_identity']['generation']=1
        manifest['jobs_hash']=protocol.manifest_hash([job])
        candidate_detail['isolated_candidate']['scope']['jobs_hash']=manifest['jobs_hash']
        candidate_detail['isolated_candidate']['resource_identity']['generation']=1
        self.assertTrue(effects._isolated_receipt(candidate_detail,manifest,job,result['observed_at']))
        candidate_detail['isolated_candidate']['resource_identity']['generation']=True
        self.assertFalse(effects._isolated_receipt(candidate_detail,manifest,job,result['observed_at']))

    def test_signed_isolated_accept_can_use_full_guest_budget_inside_twenty_second_recipe(self):
        from infra.relay_recovery import windows_probe_client
        self.publish();self.deliver();fixture,reference=self.native_isolated()
        transport=fixture.guest_transport
        def full_budget(*args):
            result=transport(*args)
            fixture.mono+=windows_probe_client.DEADLINE
            fixture.now+=windows_probe_client.DEADLINE;self.now=fixture.now
            return result
        fixture.guest_transport=full_budget;self.remote.monotonic=lambda:fixture.mono
        isolated=self.publisher.publish_isolated_accept(self.permit['intent_id'],reference)
        self.remote.run_once(self.now);self.remote.run_once(self.now)
        result=self.publisher.reconcile(isolated['publication_id'])
        self.assertEqual(result['phase'],'SUCCEEDED');self.assertTrue(result['fresh_observation'])
        self.assertEqual(fixture.mono,windows_probe_client.DEADLINE)
        self.assertLess(fixture.mono,20);self.assertEqual(len(self.cli_calls),1)

    def test_expiry_after_native_recipe_before_final_verifier_never_consumes_nonce_or_accepts(self):
        self.publish();self.deliver();fixture,reference=self.native_isolated()
        isolated=self.publisher.publish_isolated_accept(self.permit['intent_id'],reference)
        original=fixture.guest_transport
        def revoke(*args):
            result=original(*args)
            # The producer's own local clock remains inside its lease, but the
            # enclosing adapter sees authority expire before final verification.
            self.now=isolated['manifest']['expires_at']
            return result
        fixture.guest_transport=revoke
        self.remote.run_once(isolated['manifest']['issued_at']);self.remote.run_once(self.now)
        result=self.publisher.reconcile(isolated['publication_id'])
        self.assertNotEqual(result['phase'],'SUCCEEDED')
        with sqlite3.connect(fixture.root/'verifier-state'/'isolated-guest-responses.sqlite3') as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM replay').fetchone()[0],0)

    def test_combined_request_with_different_local_verifier_fails_before_recipe_or_guest(self):
        self.publish();self.deliver();fixture,reference=self.native_isolated()
        self.publisher.publish_isolated_accept(self.permit['intent_id'],reference)
        self.iran.config['isolated_verifier']={**fixture.refs['verifier'],'sha256':'f'*64}
        self.remote.run_once(self.now)
        self.assertFalse(self.cli_calls);self.assertFalse(fixture.peer.calls)

    def test_actual_signed_gateway_agent_adapter_path_executes_stage_once(self):
        publication = self.publish(); manifest = publication['manifest']
        self.assertEqual(manifest['expires_at'], self.permit['expires_at'])
        self.assertEqual(manifest['jobs'][0]['params']['expires_at'], self.permit['expires_at'])
        result = self.deliver()
        self.assertEqual(result['phase'], 'SUCCEEDED'); self.assertTrue(result['authenticated'])
        self.assertFalse(result['fresh_observation']); self.assertNotEqual(result['schema'], 'servernet.relay.driver.observation.v1')
        self.assertEqual(self.backend_calls.count('stage'), 1)
        self.assertNotEqual(self.transport_headers[0]['X-Relay-Nonce'], self.transport_headers[1]['X-Relay-Nonce'])

    def test_always_true_callback_cannot_replace_committed_exact_driver_claim(self):
        for phase, audit in (('COMMITTED', True), ('DISPATCHED', False)):
            self.save_claim(phase=phase, audit=audit)
            with self.subTest(phase=phase, audit=audit), self.assertRaisesRegex(ca.CollectorError, 'uncommitted_claim'):
                self.publish()
        self.assertFalse((self.root/'manifest-ir-agent.json').exists())

    def test_expired_driver_claim_does_not_publish_or_allocate_new_revision(self):
        self.now += 30
        with self.assertRaisesRegex(ca.CollectorError, 'permit_expired'): self.publish()
        with self.adapter._db() as db: self.assertEqual(db.execute('SELECT COUNT(*) FROM manifests').fetchone()[0], 0)

    def test_one_second_remaining_claim_does_not_receive_probe_ttl_or_new_lease(self):
        self.now += 29
        manifest = self.publish()['manifest']
        self.assertEqual(manifest['expires_at']-manifest['issued_at'], 1)
        self.assertEqual(manifest['jobs'][0]['params']['expires_at'], NOW+30)

    def test_expiry_during_authorizer_cannot_expose_prepared_job(self):
        calls = []
        def authorize(permit):
            calls.append(permit)
            if len(calls) == 2: self.now = permit['expires_at']
            return True
        with self.assertRaisesRegex(ca.CollectorError, 'permit_expired'): self.publish(authorize)
        self.assertFalse((self.root/'manifest-ir-agent.json').exists())
        self.assertEqual(self.publisher.reconcile(self.permit['intent_id'])['phase'], 'PENDING')

    def test_always_true_authorizer_cannot_expose_after_actual_core_scope_changes(self):
        calls = []
        def authorize(permit):
            calls.append(permit)
            if len(calls) == 2:
                self.state['clock_unsafe'] = True
                with sqlite3.connect(self.root/'controller.sqlite3') as db:
                    db.execute('UPDATE state SET body=? WHERE id=1', (core.canonical(self.state),))
            return True
        with self.assertRaisesRegex(ca.CollectorError, 'core_scope'): self.publish(authorize)
        self.assertFalse((self.root/'manifest-ir-agent.json').exists())

    def test_pending_effect_manifest_survives_probe_collection_even_after_expiry(self):
        publication = self.publish(); path = self.root/'manifest-ir-agent.json'; raw = path.read_bytes()
        self.now += 100
        self.assertEqual(self.adapter.publish(self.state, 'ir-agent'), publication['manifest'])
        self.assertEqual(path.read_bytes(), raw)
        with self.adapter._db() as db: self.assertEqual(db.execute('SELECT COUNT(*) FROM manifests').fetchone()[0], 1)
        with self.assertRaisesRegex(ca.CollectorError, 'original_not_succeeded'):
            self.publisher.publish_accept(self.permit['intent_id'])

    def test_recursive_authorizer_collects_without_deadlock_or_probe_overwrite(self):
        calls = []
        def authorize(permit):
            calls.append(self.adapter.publish(self.state, 'ir-agent'))
            return True
        publication = self.publish(authorize)
        self.assertEqual(len(calls), 3); self.assertEqual(calls[0]['jobs'][0]['op'], 'probe')
        self.assertEqual(calls[1], publication['manifest'])
        self.assertEqual(calls[2], publication['manifest'])
        self.assertEqual(json.loads((self.root/'manifest-ir-agent.json').read_text()), publication['manifest'])

    def test_provider_drift_during_authorizer_prevents_exposure(self):
        calls = []
        def authorize(permit):
            calls.append(permit)
            if len(calls) == 2: self.provider.addresses['51'] = '1.1.1.1'
            return True
        with self.assertRaisesRegex(ca.CollectorError, 'provider_target_changed'): self.publish(authorize)
        self.assertFalse((self.root/'manifest-ir-agent.json').exists())

    def test_gate_revocation_during_final_provider_read_prevents_exposure(self):
        gate = [True]; authorizations = []; original = self.provider.probe_target
        def authorize(permit):
            authorizations.append(gate[0])
            return gate[0]
        def provider_read(scoped):
            resolved = original(scoped)
            if len(authorizations) >= 2:
                gate[0] = False
            return resolved
        with patch.object(self.provider, 'probe_target', side_effect=provider_read):
            with self.assertRaisesRegex(ca.CollectorError, 'authorizer_rejected'):
                self.publish(authorize)
        self.assertEqual(authorizations, [True, True, False])
        self.assertFalse((self.root/'manifest-ir-agent.json').exists())
        with self.adapter._db() as db:
            row = db.execute('SELECT phase,exposure FROM iran_jobs').fetchone()
            self.assertEqual(tuple(row), ('PENDING', 'PREPARED'))

    def test_no_publication_retry_after_crash_before_or_after_atomic_exposure(self):
        original = ca._atomic
        def crash(path, value):
            original(path, value); raise OSError('crash after replace')
        with patch.object(ca, '_atomic', side_effect=crash), self.assertRaises(OSError): self.publish()
        raw = (self.root/'manifest-ir-agent.json').read_bytes()
        with patch.object(ca, '_atomic', side_effect=AssertionError('must not expose again')):
            repeated = self.publish(); self.publisher.reconcile(self.permit['intent_id'])
            self.adapter.publish(self.state, 'ir-agent')
        self.assertEqual(repeated['exposure'], 'CLAIMED')
        self.assertEqual((self.root/'manifest-ir-agent.json').read_bytes(), raw)
        self.assertEqual(self.deliver()['phase'], 'SUCCEEDED')
        self.assertEqual(self.backend_calls.count('stage'), 1)

    def test_crash_before_exposure_cannot_be_republished_by_probes_or_reconcile(self):
        with patch.object(ca, '_atomic', side_effect=OSError('before replace')), self.assertRaises(OSError): self.publish()
        with patch.object(ca, '_atomic', side_effect=AssertionError('must not retry')):
            self.publish(); self.publisher.reconcile(self.permit['intent_id'])
            self.adapter.publish(self.state, 'ir-agent')
        self.assertFalse((self.root/'manifest-ir-agent.json').exists())

    def test_unknown_then_expired_historical_reconcile_preserves_no_observation(self):
        self.publish(); self.timeout_after = True
        self.remote.run_once(self.now); self.timeout_after = False; self.now += 40
        self.remote = self.make_agent(); self.remote.run_once(self.now)
        self.assertEqual(self.publisher.reconcile(self.permit['intent_id'])['phase'], 'PENDING')
        self.now += 1; self.remote.run_once(self.now)
        result = self.publisher.reconcile(self.permit['intent_id'])
        self.assertEqual(result['phase'], 'SUCCEEDED'); self.assertIsNone(result['observed_at'])
        self.assertFalse(result['fresh_observation']); self.assertEqual(self.backend_calls.count('stage'), 1)
        self.assertEqual(self.backend_calls.count('reconcile'), 1)
        with self.adapter._db() as db: self.assertEqual(db.execute('SELECT COUNT(*) FROM receipts').fetchone()[0], 0)

    def test_outcome_commit_precedes_inbox_consumption_and_redelivery_keeps_first_time(self):
        self.publish(); self.remote.run_once(self.now); self.now += 1; self.remote.run_once(self.now)
        with patch.object(self.adapter, '_mark_consumed', side_effect=OSError('after commit')), self.assertRaises(OSError):
            self.publisher.reconcile(self.permit['intent_id'])
        with self.adapter._db() as db: self.assertEqual(db.execute('SELECT COUNT(*) FROM iran_outcomes').fetchone()[0], 1)
        with sqlite3.connect(self.root/'agent-reports.sqlite3') as db:
            self.assertIsNone(db.execute('SELECT consumed_at FROM inbox').fetchone()[0])
        result = self.publisher.reconcile(self.permit['intent_id']); first = result['received_at']
        self.now += 10; self.send_receipt(self.poll_receipts[0])
        self.assertEqual(self.publisher.reconcile(self.permit['intent_id'])['received_at'], first)
        with self.adapter._db() as db: self.assertEqual(db.execute('SELECT COUNT(*) FROM iran_outcomes').fetchone()[0], 1)

    def test_tampered_report_scope_is_not_delivered_to_driver_mapping(self):
        publication = self.publish(); manifest = publication['manifest']
        receipt = {k: manifest[k] for k in ('incident_id', 'generation', 'revision', 'config_hash', 'jobs_hash')}
        receipt.update(job_id=manifest['jobs'][0]['id'], op='stage', ok=True, code='completed', observed_at=NOW,
            checks={'adapter': dict(generation_verified=True, staged=True)})
        for change in ({'jobs_hash': 'f'*64}, {'job_id': 'different'}, {'op': 'apply'}, {'op': 'probe'}, {'generation': True}):
            self.send_receipt({**receipt, **change})
        self.assertEqual(self.publisher.reconcile(self.permit['intent_id'])['phase'], 'PENDING')
        with self.adapter._db() as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM iran_outcomes').fetchone()[0], 0)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM receipts').fetchone()[0], 0)

    def test_conflicting_authenticated_terminal_fates_hold_and_preserve_both(self):
        self.publish(); self.deliver()
        contrary = copy.deepcopy(self.poll_receipts[0])
        contrary.update(ok=False, code='check_failed')
        contrary['checks']['adapter']['staged'] = False
        self.now += 1; self.send_receipt(contrary)
        result = self.publisher.reconcile(self.permit['intent_id'])
        self.assertEqual(result['phase'], 'CONFLICT'); self.assertFalse(result['fresh_observation'])
        with self.adapter._db() as db: self.assertEqual(db.execute('SELECT COUNT(*) FROM iran_outcomes').fetchone()[0], 2)
        manifest = json.loads((self.root/'manifest-ir-agent.json').read_text())
        self.assertEqual(self.adapter.publish(self.state, 'ir-agent'), manifest)

    def test_two_sqlite_ingesters_cannot_overwrite_contradictory_terminal_outcomes(self):
        manifest = self.publish()['manifest']
        common = {k: manifest[k] for k in ('incident_id', 'generation', 'revision', 'config_hash', 'jobs_hash')}
        common.update(job_id=manifest['jobs'][0]['id'], op='stage', observed_at=NOW)
        self.send_receipt(dict(common, ok=True, code='completed',
            checks={'adapter': dict(generation_verified=True, staged=True)}))
        self.send_receipt(dict(common, ok=False, code='check_failed',
            checks={'adapter': dict(generation_verified=True, staged=False)}))
        with sqlite3.connect(self.root/'agent-reports.sqlite3') as db:
            rows = db.execute('SELECT agent_id,report_hash,received_at,body_sha256,body FROM inbox ORDER BY rowid').fetchall()
        first_read = threading.Event(); release_first = threading.Event()
        second_started = threading.Event(); second_done = threading.Event(); failures = []
        original = self.adapter._db

        class PausedCursor:
            def __init__(self, cursor): self.cursor = cursor
            def fetchone(self):
                value = self.cursor.fetchone()
                first_read.set()
                if not release_first.wait(2): raise AssertionError('interleave release missing')
                return value

        class Connection:
            def __init__(self): self.db = original()
            def __enter__(self): self.db.__enter__(); return self
            def __exit__(self, *args):
                try: return self.db.__exit__(*args)
                finally: self.db.close()
            def execute(self, sql, params=()):
                cursor = self.db.execute(sql, params)
                if threading.current_thread().name == 'first-ingester' and sql.startswith('SELECT * FROM iran_jobs WHERE agent='):
                    return PausedCursor(cursor)
                return cursor

        def ingest(index):
            try:
                if index == 1: second_started.set()
                self.adapter._ingest_report(*rows[index])
            except BaseException as error: failures.append(error)
            finally:
                if index == 1: second_done.set()

        with patch.object(self.adapter, '_db', side_effect=Connection):
            first = threading.Thread(target=ingest, args=(0,), name='first-ingester')
            second = threading.Thread(target=ingest, args=(1,), name='second-ingester')
            first.start()
            try:
                self.assertTrue(first_read.wait(2))
                second.start(); self.assertTrue(second_started.wait(2))
                # Before the fix the second connection could commit failure
                # while the first retained PENDING, then falsely win success.
                second_done.wait(.2)
            finally:
                release_first.set(); first.join(3)
                if second.ident is not None: second.join(3)
        self.assertFalse(first.is_alive()); self.assertFalse(second.is_alive()); self.assertEqual(failures, [])
        with original() as db:
            outcome = db.execute('SELECT phase,terminal_hash FROM iran_jobs').fetchone()
            self.assertEqual(outcome[0], 'CONFLICT')
            first_receipt = json.loads(rows[0][-1])['report']['jobs'][0]
            self.assertEqual(outcome[1], hashlib.sha256(protocol.canonical_json(first_receipt)).hexdigest())
            self.assertEqual(db.execute('SELECT COUNT(*) FROM iran_outcomes').fetchone()[0], 2)

    def test_fresh_accept_is_readonly_separate_from_stage_and_never_global_readiness(self):
        self.publish(); self.deliver(); self.now += 40
        readback = self.publisher.publish_accept(self.permit['intent_id'])
        self.assertGreater(readback['manifest']['expires_at'], self.permit['expires_at'])
        self.assertEqual(readback['manifest']['jobs'][0]['op'], 'accept')
        self.remote.run_once(self.now); self.now += 1; self.remote.run_once(self.now)
        result = self.publisher.reconcile(readback['publication_id'])
        self.assertEqual(result['phase'], 'FAILED')  # isolated stage did not change actual default path
        self.assertTrue(result['fresh_observation']); self.assertFalse(result['receipt']['ok'])
        self.assertNotIn('global_cutover_complete', result); self.assertNotIn('isolation_verified', result)
        self.assertEqual(self.backend_calls.count('stage'), 1)

    def test_apply_uses_exact_source_scope_and_original_promote_deadline(self):
        self.context.update(restore_epoch=1, restore_hash='e'*64)
        self.candidate.update(restore_epoch=1, restore_hash='e'*64)
        self.state.update(phase='CUTOVER', pending_action=PARENT)
        self.permit.update(kind='EXTERNAL_CUTOVER', context_hash=core.digest(self.context))
        self.params.update(stage_profile='restored', expected_registry_hash='f'*64)
        self.save_claim(); publication = self.publish()
        params = publication['manifest']['jobs'][0]['params']
        self.assertEqual(params['old_identity'], SOURCE); self.assertEqual(params['old_ipv4'], OLD)
        self.assertEqual(params['old_config_hash'], self.active['config_hash'])
        self.assertEqual(self.deliver()['phase'], 'SUCCEEDED'); self.assertEqual(self.endpoint, NEW)

    def test_nonprobe_receipts_cannot_become_collector_health(self):
        self.publish(); self.deliver()
        evidence = self.adapter.collect({'state': self.state})['evidence']
        self.assertNotIn('health', evidence); self.assertNotIn('candidate_health', evidence)
        self.assertEqual(evidence['critical_receipts'], []); self.assertFalse(evidence['preflight']['ready'])

    def test_input_cannot_change_resource_deadline_or_select_a_command(self):
        for changes in ({'expires_at': NOW+31}, {'resource_identity': SOURCE}, {'command': 'reboot'}):
            params = {**self.params, **changes}
            with self.subTest(changes=changes), self.assertRaises((ca.CollectorError, protocol.ProtocolError)):
                self.publisher.publish(self.permit, self.context, 'stage', params, lambda p: True)
        self.assertFalse((self.root/'manifest-ir-agent.json').exists())


if __name__ == '__main__': unittest.main()
