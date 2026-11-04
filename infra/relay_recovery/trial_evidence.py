"""Read existing authenticated isolated trial evidence, without advancing it.

This reader does not construct publishers, stores, verifiers or restore clients.
It never consumes a guest nonce, ingests an inbox, creates a file, or changes a
step. Its trust boundary is the owned collector journal written after gateway
authentication and the original private, immutable bootstrap public metadata.
Global hooks remain deliberately unwired.
"""
from __future__ import annotations

import contextlib
import copy
import hashlib
import os
from pathlib import Path
import re
import sqlite3
import stat
import time

from . import bootstrap, core, ct_stage, isolated_acceptance as isolated
from .backup_client import trusted_file
from .iran_effects import ISOLATED_ENROLLMENT, _isolated_receipt
from .isolated_trial import _pin
from .production_hooks import CONTEXT_FIELDS, _identity, _ipv4, _private_bytes, load_registry
from .protocol import HEX64, canonical_json, decode_json, manifest_hash, validate_manifest


OBSERVATION = 'servernet.relay.driver.observation.v1'
TTL = isolated.TTL


class TrialEvidenceError(core.RecoveryError):
    pass


def require(value):
    if not value:
        raise TrialEvidenceError('trial_evidence_unavailable')


def equal(left, right):
    return canonical_json(left) == canonical_json(right)


def decoded(raw):
    require(isinstance(raw, str) and len(raw.encode()) <= 65536)
    value = decode_json(raw.encode())
    require(raw.encode() == canonical_json(value))
    return value


class TrialEvidenceBridge:
    """A local typed reader; enrollment is the publisher's existing pinned ref.

    ``observe`` reads one exact driver claim. ``trial_network`` additionally
    binds the existing restore workflow context. Neither method publishes an
    accept job; the caller must first arrange the separately authorized stage
    and explicit read-only isolated acceptance using the existing publisher.
    """
    def __init__(self, config, enrollment, *, clock=lambda: int(time.time()), allow_test_paths=False):
        self.config = copy.deepcopy(config)
        self.reference = copy.deepcopy(enrollment)
        self.clock, self.last_now = clock, None
        self.allow_test_paths = allow_test_paths
        self.root = Path(config['state_dir'])

    def _now(self):
        value = self.clock()
        require(type(value) is int and 0 < value <= 2**53-1
                and (self.last_now is None or value >= self.last_now))
        self.last_now = value
        return value

    @contextlib.contextmanager
    def _database(self, name):
        # immutable=1 cannot create SQLite sidecars. Refuse existing sidecars
        # rather than silently ignoring a WAL or an incomplete transaction.
        path = trusted_file(str(self.root/name), uid=os.geteuid(), allow_test_paths=self.allow_test_paths)
        before = path.lstat()
        require(stat.S_ISREG(before.st_mode) and before.st_nlink == 1
                and before.st_size <= 128*1024*1024)
        sidecars = [Path(str(path)+suffix) for suffix in ('-wal', '-shm', '-journal')]
        require(not any(p.exists() or p.is_symlink() for p in sidecars))
        identity = lambda s: (s.st_dev, s.st_ino, s.st_size, s.st_mtime_ns, s.st_ctime_ns)
        db = sqlite3.connect(path.as_uri()+'?mode=ro&immutable=1', uri=True, timeout=0)
        db.row_factory = sqlite3.Row
        db.execute('PRAGMA query_only=ON')
        try:
            db.execute('BEGIN')
            yield db
            require(identity(path.lstat()) == identity(before)
                    and not any(p.exists() or p.is_symlink() for p in sidecars))
        finally:
            db.close()

    def _public(self, path, limit=8192):
        raw = _private_bytes(path, limit=limit, allow_test_paths=self.allow_test_paths)
        return decoded(raw.decode())

    def _enrollment(self, context):
        registry = load_registry(self.config, allow_test_paths=self.allow_test_paths)
        require(set(context) == CONTEXT_FIELDS and context['schema'] == 'servernet.relay.production-driver.v1'
                and context['registry_hash'] == self.config['production_driver']['registry']['sha256']
                and all(type(context[k]) is str and context[k] == registry[k]
                        for k in ('owner_id', 'service_id', 'project_id')))
        _identity(context['identity']); _identity(context['source_identity'])
        require(context['identity']['project_id'] == context['source_identity']['project_id'] == context['project_id']
                and context['restore_epoch'] is None and context['restore_hash'] is None)
        enrolled = _pin(self.reference, root_owned=not self.allow_test_paths)
        hashes = {'producer_config_sha256', 'verifier_sha256', 'expected_registry_hash',
                  'dependency_baseline_sha256', 'proxy_binding_sha256'}
        require(set(enrolled) == {'schema', 'enabled', 'agent_id', 'binding', *hashes}
                and enrolled['schema'] == ISOLATED_ENROLLMENT and enrolled['enabled'] is True
                and enrolled['agent_id'] == self.config['policy']['agent_id']
                and self.config.get('agents', {}).get(enrolled['agent_id'], {}).get('enabled') is True
                and equal(enrolled['binding'], {k: context[k] for k in
                    ('owner_id', 'service_id', 'project_id', 'registry_hash', 'config_hash')})
                and all(isinstance(enrolled[k], str) and HEX64.fullmatch(enrolled[k]) for k in hashes))
        return enrolled

    def _claim(self, db, permit, context):
        require(set(permit) == {'schema', 'intent_id', 'parent_intent_id', 'kind', 'context_hash', 'created_at', 'expires_at'}
                and permit['schema'] == 'servernet.relay.driver.permit.v1' and permit['kind'] == 'TRIAL_STAGE'
                and isinstance(permit['intent_id'], str) and re.fullmatch('[a-f0-9-]{36}', permit['intent_id'])
                and permit['parent_intent_id'] == context['parent_intent_id']
                and permit['context_hash'] == core.digest(context)
                and type(permit['created_at']) is int and type(permit['expires_at']) is int
                and 0 < permit['expires_at']-permit['created_at'] <= 30)
        step = db.execute('SELECT * FROM steps WHERE id=?', (permit['intent_id'],)).fetchone()
        claim = db.execute('SELECT * FROM hook_dispatches WHERE id=?', (permit['intent_id'],)).fetchone()
        body = dict(permit=permit, context=context)
        require(step is not None and claim is not None and step['kind'] == 'TRIAL_STAGE'
                and step['phase'] in ('DISPATCHED', 'VERIFIED') and step['parent'] == permit['parent_intent_id']
                and equal(decoded(step['body']), body) and equal(decoded(claim['body']), body)
                and type(claim['claimed_at']) is int
                and permit['created_at'] <= claim['claimed_at'] < permit['expires_at'])

    def _state(self, db, context, now):
        row = db.execute('SELECT body FROM state WHERE id=1').fetchone()
        require(row is not None)
        state = decoded(row['body']); candidate = state.get('candidate')
        require(state.get('phase') == 'VALIDATING' and state.get('pending_action') is None
                and state.get('clock_unsafe') is False and type(state.get('last_now')) is int
                and state['last_now'] <= now and isinstance(candidate, dict)
                and equal(core.identity(candidate), context['identity'])
                and equal(core.identity(state['active']), context['source_identity'])
                and equal({k: candidate.get(k) for k in ('config_hash', 'restore_epoch', 'restore_hash')},
                          {k: context[k] for k in ('config_hash', 'restore_epoch', 'restore_hash')})
                and state.get('incident_id') == context['incident_id'])
        _ipv4(candidate['ipv4'])
        parent = context['parent_intent_id']
        require(isinstance(parent, str) and re.fullmatch('[a-f0-9]{40}', parent))
        action = db.execute("SELECT body FROM actions WHERE id=? AND kind='CREATE'", (parent,)).fetchone()
        require(action is not None)
        action = decoded(action['body']); payload = action.get('payload', {}); option = payload.get('option', {})
        require(action.get('id') == parent and action.get('kind') == 'CREATE'
                and type(payload.get('next_generation')) is int and payload['next_generation'] == candidate['generation']
                and all(option.get(k) == context[k] for k in ('owner_id', 'service_id', 'project_id', 'config_hash'))
                and option.get('provider') == 'hetzner')
        return state, action

    def _ssh(self, context, state, action, stage):
        # Read existing public-only BootstrapStore products, never profile()
        # (which can generate/write), *.yaml, or the private host key.
        directory = self.root/'bootstrap'/context['parent_intent_id']
        scope = self._public(directory/'scope.json')
        trial = self._public(directory/'trial.json')
        binding = self._public(directory/'candidate.json')
        params = stage['manifest']['jobs'][0]['params']
        public = bootstrap.public_key(params['ssh_host_public_key'])
        require(params['ssh_host_public_key'] == public and params['ssh_host_key_sha256'] == ct_stage.fingerprint(public))
        digest = core.digest(scope)
        source = {**context['source_identity'], 'config_hash': state['active']['config_hash']}
        option = action['payload']['option']
        require(scope.get('schema') == 'servernet.relay.bootstrap.scope.v1'
                and scope.get('intent_id') == context['parent_intent_id'] and equal(scope.get('source_identity'), source)
                and all(scope.get(k) == context[k] for k in ('owner_id', 'service_id', 'config_hash'))
                and scope.get('generation') == context['identity']['generation']
                and scope.get('image') == str(option.get('image')) and scope.get('location') == option.get('location'))
        fields = ('owner_id', 'service_id', 'source_identity', 'generation', 'image', 'location', 'config_hash')
        require(trial.get('schema') == 'servernet.relay.bootstrap.artifact.v1'
                and trial.get('intent_id') == scope['intent_id'] and trial.get('profile') == 'trial'
                and trial.get('scope_hash') == digest and trial.get('host_key_origin') == 'trial'
                and trial.get('machine_id_receipt_required') is False and trial.get('machine_id_ready') is False
                and all(equal(trial.get(k), scope[k]) for k in fields)
                and trial.get('public_host_key') == public and trial.get('public_host_fingerprint') == params['ssh_host_key_sha256']
                and isinstance(trial.get('user_data_sha256'), str) and HEX64.fullmatch(trial['user_data_sha256']))
        address = state['candidate']['ipv4']; line = address+' '+public
        known = _private_bytes(directory/'known_hosts', limit=2048, allow_test_paths=self.allow_test_paths)
        public_raw = _private_bytes(directory/'host_ed25519.pub', limit=1024, allow_test_paths=self.allow_test_paths)
        require(binding.get('schema') == 'servernet.relay.bootstrap.binding.v1'
                and binding.get('intent_id') == scope['intent_id'] and binding.get('scope_hash') == digest
                and all(equal(binding.get(k), scope[k]) for k in fields)
                and equal(binding.get('identity'), context['identity']) and binding.get('ipv4') == address
                and binding.get('public_host_key') == public and binding.get('known_hosts_line') == line
                and binding.get('trial_user_data_sha256') == trial['user_data_sha256']
                and known == (line+'\n').encode() and public_raw.decode().strip() == public)
        values = {'scope.json': canonical_json(scope), 'trial.json': canonical_json(trial),
                  'candidate.json': canonical_json(binding), 'known_hosts': known, 'host_ed25519.pub': public_raw}
        return {directory/name: hashlib.sha256(raw).hexdigest() for name, raw in values.items()}

    def _publication(self, db, row, permit, context, operation, agent):
        require(row is not None and row['driver_intent'] == permit['intent_id'] and row['agent'] == agent
                and row['operation'] == operation and row['phase'] == 'SUCCEEDED'
                and row['exposure'] in ('CLAIMED', 'EXPOSED') and row['terminal_hash'] is not None)
        saved = decoded(row['body']); manifest = saved['manifest']
        require(equal(saved['permit'], permit) and equal(saved['context'], context)
                and type(manifest['issued_at']) is int and type(manifest['expires_at']) is int
                and 0 < manifest['expires_at']-manifest['issued_at'] <= 30)
        validate_manifest(manifest, agent, manifest['issued_at'])
        require(len(manifest['jobs']) == 1)
        job = manifest['jobs'][0]; params = job['params']
        if operation == 'stage':
            wire = dict(schema='servernet.relay.recovery.v1',
                **{k: manifest[k] for k in ('agent_id', 'incident_id', 'generation', 'revision', 'config_hash', 'jobs_hash')}, job=job)
            ct_stage.validate_context(wire, agent)
        history = db.execute('SELECT body FROM manifests WHERE agent=? AND revision=?', (agent, row['revision'])).fetchone()
        require(history is not None and equal(decoded(history['body']), manifest)
                and row['revision'] == manifest['revision'] and row['job_id'] == job['id'] and row['expires_at'] == manifest['expires_at']
                and manifest['incident_id'] == context['incident_id'] and manifest['config_hash'] == context['config_hash']
                and manifest['jobs_hash'] == manifest_hash([job]) and job['op'] == operation
                and equal(params['resource_identity'], context['identity']) and params['resource_config_hash'] == context['config_hash']
                and params['stage_profile'] == 'trial' and params['expires_at'] == manifest['expires_at']
                and job['target']['provider_id'] == context['identity']['provider_id'])
        terminal = db.execute('SELECT * FROM iran_outcomes WHERE agent=? AND hash=? AND publication=?',
                              (agent, row['terminal_hash'], row['id'])).fetchone()
        require(terminal is not None and terminal['classification'] == 'succeeded')
        record = decoded(terminal['body']); receipt = record['receipt']
        require(hashlib.sha256(canonical_json(receipt)).hexdigest() == row['terminal_hash']
                and record['agent_id'] == agent and record['publication_id'] == row['id']
                and equal(record['permit'], permit) and equal(record['context'], context)
                and equal(record['manifest'], manifest) and equal(record['job'], job)
                and type(record['received_at']) is int and record['received_at'] == terminal['received_at']
                and all(type(receipt.get(k)) is type(manifest[k]) and receipt[k] == manifest[k]
                        for k in ('incident_id', 'generation', 'revision', 'config_hash', 'jobs_hash'))
                and receipt.get('job_id') == job['id'] and receipt.get('op') == operation
                and receipt.get('code') == 'completed' and receipt.get('ok') is True
                and isinstance(receipt.get('checks'), dict))
        detail = receipt['checks'].get('adapter', {})
        require(isinstance(detail, dict) and detail.get('generation_verified') is True
                and detail.get('staged' if operation == 'stage' else 'accepted') is True)
        return saved, record, detail

    def _read(self, permit, context):
        now = self._now(); enrolled = self._enrollment(context); agent = enrolled['agent_id']
        with self._database('production-driver.sqlite3') as driver, self._database('controller.sqlite3') as controller, \
                self._database('controller-evidence.sqlite3') as evidence:
            self._claim(driver, permit, context)
            state, action = self._state(controller, context, now)
            stage_row = evidence.execute('SELECT * FROM iran_jobs WHERE id=?', (permit['intent_id'],)).fetchone()
            stage, _, _ = self._publication(evidence, stage_row, permit, context, 'stage', agent)
            require(set(stage) == {'permit', 'context', 'manifest'}
                    and stage['manifest']['issued_at'] >= permit['created_at']
                    and stage['manifest']['expires_at'] == permit['expires_at'])
            public_pins = self._ssh(context, state, action, stage)
            require(stage['manifest']['jobs'][0]['target']['host'] == state['candidate']['ipv4'])
            rows = evidence.execute("SELECT * FROM iran_jobs WHERE driver_intent=? AND operation='accept' ORDER BY revision DESC LIMIT 33",
                                    (permit['intent_id'],)).fetchall()
            require(0 < len(rows) <= 32 and all(r['phase'] != 'CONFLICT' for r in rows))
            # Never choose an older success over a newer pending/failed/conflict
            # or differently profiled request for this same driver claim.
            row = rows[0]
            saved, record, detail = self._publication(evidence, row, permit, context, 'accept', agent)
            require(set(saved) == {'permit', 'context', 'manifest', 'isolated_accept'})
            original = stage['manifest']; manifest = saved['manifest']; job = manifest['jobs'][0]; params = job['params']
            ssh = {k: original['jobs'][0]['params'][k] for k in ('ssh_host_public_key', 'ssh_host_key_sha256')}
            require(equal(saved['isolated_accept'], dict(enrollment=self.reference, stage_publication_id=stage_row['id'],
                stage_manifest_sha256=core.digest(original), ssh_binding_sha256=core.digest(ssh)))
                and manifest['revision'] > original['revision'] and manifest['generation'] >= original['generation']
                and equal(job['target'], original['jobs'][0]['target']))
            expected = dict(acceptance_profile=isolated.PROFILE, stage_profile='trial',
                resource_identity=context['identity'], resource_config_hash=context['config_hash'],
                expected_registry_hash=enrolled['expected_registry_hash'], dependency_baseline_sha256=enrolled['dependency_baseline_sha256'],
                proxy_binding_sha256=enrolled['proxy_binding_sha256'], isolated_trial_config_sha256=enrolled['producer_config_sha256'],
                isolated_verifier_sha256=enrolled['verifier_sha256'], expires_at=manifest['expires_at'], **ssh)
            require(equal(params, expected) and record.get('fresh_observation') is True
                    and _isolated_receipt(detail, manifest, job, record['receipt'].get('observed_at')))
            proof = detail['isolated_candidate']; received = record['received_at']; observed = record['receipt']['observed_at']
            clocks = [proof[k] for k in ('observed_at', 'ct_observed_at', 'guest_observed_at', 'registry_observed_at')]
            end = self._now()
            require(type(observed) is int and manifest['issued_at'] <= observed <= manifest['expires_at']
                    and observed <= received <= end and all(type(at) is int and 0 <= end-at < TTL for at in clocks)
                    and 0 <= end-received < TTL and 0 <= end-observed < TTL
                    and proof['observed_at'] == min(clocks[1:])
                    and detail.get('historical_outcome') is not True
                    and detail.get('network_observation_fresh') is not False)
            # Re-read pins at the end; a local enrollment/registry replacement
            # cannot turn an already loaded observation into current authority.
            require(equal(self._enrollment(context), enrolled))
            for path, digest in public_pins.items():
                _private_bytes(path, limit=8192, expected_sha256=digest, allow_test_paths=self.allow_test_paths)
            result = dict(schema=OBSERVATION, operation='stage_trial', state='complete', verified=True, read_only=True,
                intent_id=permit['intent_id'], context_hash=core.digest(context), observed_at=proof['observed_at'],
                received_at=received, proof_expires_at=min(clocks)+TTL, isolation_verified=True, network_verified=True,
                ct_egress_verified=True, live_dependencies_unchanged=True,
                acceptance_profile=isolated.PROFILE, path_profile=isolated.PATH_PROFILE,
                default_path_verified=False, global_cutover_performed=False,
                stage_publication_id=stage_row['id'], acceptance_publication_id=row['id'],
                proof_sha256=hashlib.sha256(canonical_json(proof)).hexdigest()), state, stage
        # Pin reads and snapshot teardown may take time. Original clocks must
        # still be eligible after every blocking boundary, at the actual return.
        self._fresh(result[0])
        return result

    def _fresh(self, proof):
        now = self._now()
        require(proof['observed_at'] <= now < proof['proof_expires_at']
                and 0 <= now-proof['received_at'] < TTL)

    def observe(self, permit, context):
        """Return a driver observation only for a fresh, exact durable chain."""
        try:
            return self._read(permit, context)[0]
        except Exception:
            raise TrialEvidenceError('trial_evidence_unavailable') from None

    def trial_network(self, context):
        """Read-only RestoreWorkflow trial_network callback; no restored proof."""
        try:
            parent = context['create_intent_id']
            with self._database('production-driver.sqlite3') as db:
                row = db.execute("SELECT body FROM steps WHERE parent=? AND kind='TRIAL_STAGE'", (parent,)).fetchone()
                require(row is not None)
                body = decoded(row['body'])
            proof, state, stage = self._read(body['permit'], body['context'])
            saved = body['context']; ssh = stage['manifest']['jobs'][0]['params']
            require(context.get('schema') == 'servernet.relay.restore.workflow.v1'
                    and isinstance(context.get('workflow_id'), str) and re.fullmatch('[a-f0-9-]{36}', context['workflow_id'])
                    and context['create_intent_id'] == saved['parent_intent_id']
                    and all(equal(context[k], saved[k]) for k in ('identity', 'source_identity', 'config_hash', 'incident_id', 'restore_epoch', 'restore_hash'))
                    and context.get('source_config_hash') == state['active']['config_hash']
                    and type(context.get('generation')) is int and context['generation'] == saved['identity']['generation']
                    and context.get('candidate_ipv4') == state['candidate']['ipv4']
                    and context.get('host_fingerprint') == ssh['ssh_host_key_sha256']
                    and type(context.get('now')) is int and 0 <= self._now()-context['now'] < TTL)
            result = dict(proof, workflow_id=context['workflow_id'], identity=saved['identity'], source_identity=saved['source_identity'],
                config_hash=saved['config_hash'], incident_id=saved['incident_id'], generation=saved['identity']['generation'],
                network_ok=True, isolated_egress_ok=True, staging_isolated=True)
            self._fresh(proof)
            return result
        except Exception:
            raise TrialEvidenceError('trial_evidence_unavailable') from None
