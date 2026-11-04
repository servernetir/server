"""Finite driver-bound Iran jobs over the existing authenticated pull channel.

No RPC service, remote command, recipe installation or global recovery hook is
created here. A stage/apply acknowledgement is only an operation disposition;
it cannot satisfy isolated consumer acceptance or global cutover. Reconciliation
only ingests authenticated reports and reads the durable original mapping.
"""
from __future__ import annotations

import copy
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import uuid

from . import core, controller_adapter as collector, ct_stage
from .agent import validate_job
from .production_hooks import CONTEXT_FIELDS, _identity, _ipv4, load_registry
from .protocol import HEX64, IDENTIFIER, canonical_json, manifest_hash, validate_manifest


PUBLICATION = 'servernet.relay.iran-publication.v1'
OUTCOME = 'servernet.relay.iran-outcome.v1'
MUTATIONS = {'stage': 'TRIAL_STAGE', 'apply': 'EXTERNAL_CUTOVER'}
FLAGS = {'stage': 'staged', 'apply': 'applied', 'accept': 'accepted'}
ISOLATED_PROFILE = 'isolated_candidate_v1'
ISOLATED_ENROLLMENT = 'servernet.relay.isolated-publication.v1'


def _require(condition, code):
    if not condition:
        raise collector.CollectorError(code)


def _isolated_receipt(detail, manifest, job, observed):
    """Recognize only the exact isolated proof from the authenticated adapter."""
    from .isolated_acceptance import SCHEMA, PATH_PROFILE, SCOPE
    p = job['params']; proof = detail.get('isolated_candidate')
    if (detail.get('acceptance_profile') != ISOLATED_PROFILE or detail.get('path_profile') != PATH_PROFILE
            or detail.get('default_path_verified') is not False or detail.get('global_cutover_performed') is not False
            or not isinstance(proof, dict) or proof.get('schema') != SCHEMA or proof.get('verified') is not True
            or proof.get('acceptance_profile') != ISOLATED_PROFILE or proof.get('path_profile') != PATH_PROFILE
            or proof.get('default_path_verified') is not False or proof.get('global_cutover_performed') is not False):
        return False
    expected = dict(scope={k: manifest[k] for k in SCOPE}, target=job['target'],
        stage_profile=p['stage_profile'], resource_identity=p['resource_identity'],
        resource_config_hash=p['resource_config_hash'], restore_epoch=p.get('restore_epoch'),
        restore_hash=p.get('restore_hash'), proxy_binding_sha256=p['proxy_binding_sha256'],
        registry_sha256=p['expected_registry_hash'], dependency_baseline_sha256=p['dependency_baseline_sha256'])
    clocks = [proof.get(k) for k in ('observed_at', 'ct_observed_at', 'guest_observed_at', 'registry_observed_at')]
    return (canonical_json({k: proof.get(k) for k in expected}) == canonical_json(expected)
        and type(observed) is int and all(type(at) is int and manifest['issued_at'] <= at <= observed+5 for at in clocks)
        and all(isinstance(proof.get(k), str) and HEX64.fullmatch(proof[k])
                for k in ('guest_body_sha256', 'ct_proof_sha256', 'registry_proof_sha256')))


def route_receipt(db, agent, manifest, job, receipt, received_at):
    """Run inside the collector's FULL transaction before inbox consumption.

    All clocks and original receipt bytes survive redelivery unchanged. Late
    read-only reconciliation may establish an old effect's fate, never fresh
    health. Uncertain adapter/transport failures remain pending.
    """
    row = db.execute('SELECT * FROM iran_jobs WHERE agent=? AND revision=? AND job_id=?',
                     (agent, manifest['revision'], job['id'])).fetchone()
    if row is None or row['operation'] != job['op'] or row['exposure'] == 'PREPARED':
        return False
    saved = json.loads(row['body'])
    if saved['manifest'] != manifest or manifest['jobs'] != [job]:
        return False
    if (type(receipt.get('ok')) is not bool or not isinstance(receipt.get('checks'), dict)
            or len(canonical_json(receipt['checks'])) > 4096):
        return False
    detail = receipt['checks'].get('adapter', {})
    if not isinstance(detail, dict):
        return False
    observed = receipt.get('observed_at')
    historical = (detail.get('historical_outcome') is True
        and receipt.get('observation_kind') == 'historical_outcome'
        and receipt.get('fresh_observation') is False and observed is None)
    readback = (detail.get('read_only') is True and detail.get('network_observation_fresh') is False)
    if historical:
        at = receipt.get('outcome_reconciled_at')
        if not readback or type(at) is not int or not manifest['issued_at'] <= at <= received_at+5:
            return False
    else:
        if type(observed) is not int or not manifest['issued_at'] <= observed <= received_at+5:
            return False
        if observed > manifest['expires_at'] and not readback:
            return False
    flag = FLAGS[job['op']]
    classification = 'unknown'
    if receipt.get('code') == 'completed' and receipt['ok'] is True:
        if detail.get('generation_verified') is True and detail.get(flag) is True:
            classification = 'succeeded'
            if (job['op'] == 'accept' and job.get('params', {}).get('acceptance_profile') == ISOLATED_PROFILE
                    and (historical or readback or not _isolated_receipt(detail, manifest, job, observed))):
                classification = 'unknown'
    elif receipt.get('code') == 'check_failed' and receipt['ok'] is False:
        if job['op'] == 'accept' or readback or (detail.get('generation_verified') is True and detail.get(flag) is False):
            classification = 'failed'
    fresh = (job['op'] == 'accept' and not historical and not readback
        and type(observed) is int and observed <= manifest['expires_at']
        and classification != 'unknown')
    encoded = canonical_json(dict(agent_id=agent, publication_id=row['id'],
        permit=saved['permit'], context=saved['context'], manifest=manifest, job=job,
        receipt=receipt, received_at=received_at, fresh_observation=fresh)).decode()
    digest = hashlib.sha256(canonical_json(receipt)).hexdigest()
    prior = db.execute('SELECT publication FROM iran_outcomes WHERE agent=? AND hash=?', (agent, digest)).fetchone()
    if prior:
        _require(prior['publication'] == row['id'], 'iran_receipt_immutable_conflict')
        return True
    db.execute('INSERT INTO iran_outcomes VALUES(?,?,?,?,?,?)',
               (agent, digest, row['id'], received_at, classification, encoded))
    if classification != 'unknown':
        phase = classification.upper()
        if row['phase'] in {'SUCCEEDED', 'FAILED'} and row['phase'] != phase:
            db.execute("UPDATE iran_jobs SET phase='CONFLICT' WHERE id=?", (row['id'],))
        elif row['phase'] != 'CONFLICT' and row['terminal_hash'] is None:
            db.execute('UPDATE iran_jobs SET phase=?,terminal_hash=? WHERE id=?', (phase, digest, row['id']))
    return True


class IranEffectPublisher:
    """Private in-process API; never constructed from network-supplied code.

    ``publish`` requires both the exact durable DISPATCHED driver claim and a
    live authorizer. Public target addresses come only from ProviderEffects.
    ``publish_accept`` is a separately journaled read-only request for the same
    immutable resource after a successful disposition. Neither returns driver
    readiness observations or installs missing private production recipes.
    """
    def __init__(self, config, *, adapter=None, provider=None, clock=None, allow_test_paths=False):
        self.config = config
        self.adapter = adapter or collector.Adapter(config, provider=provider)
        self.provider = provider or self.adapter._provider()
        self.root = self.adapter.root; self.allow_test_paths = allow_test_paths
        self.clock = clock or self.adapter.clock; self.last_now = None
        if clock is not None:
            self.adapter.clock = clock
        self.agent = self.adapter.policy.agent_id

    def _now(self):
        value = self.clock()
        _require(type(value) in (int, float), 'iran_publication_clock')
        now = int(value)
        _require(now >= 0
            and (self.last_now is None or now >= self.last_now), 'iran_publication_clock')
        self.last_now = now; self.adapter.now = now
        return now

    def _database(self, name):
        path = self.root/name; collector._private_db(path)
        _require(path.stat().st_nlink == 1, 'iran_private_journal_required')
        db = sqlite3.connect(path.as_uri()+'?mode=ro', uri=True, timeout=2)
        db.row_factory = sqlite3.Row; db.execute('PRAGMA query_only=ON')
        return db

    def _claim(self, permit, context, operation, *, fresh=True):
        now = self._now()
        _require(operation in MUTATIONS and isinstance(context, dict) and set(context) == CONTEXT_FIELDS
            and context.get('schema') == 'servernet.relay.production-driver.v1', 'iran_effect_context')
        _require(isinstance(permit, dict) and set(permit) == {'schema', 'intent_id', 'parent_intent_id',
            'kind', 'context_hash', 'created_at', 'expires_at'}
            and permit.get('schema') == 'servernet.relay.driver.permit.v1'
            and permit.get('kind') == MUTATIONS[operation]
            and isinstance(permit.get('intent_id'), str)
            and re.fullmatch(r'[a-f0-9-]{36}', permit['intent_id'])
            and permit.get('parent_intent_id') == context['parent_intent_id']
            and permit.get('context_hash') == core.digest(context)
            and type(permit.get('created_at')) is int and type(permit.get('expires_at')) is int
            and 0 < permit['expires_at']-permit['created_at'] <= 30, 'iran_effect_permit')
        if fresh:
            _require(self.config.get('policy', {}).get('enabled') is True
                and self.config.get('production_driver', {}).get('enabled') is True
                and permit['created_at'] <= now < permit['expires_at'], 'iran_effect_permit_expired')
        db = self._database('production-driver.sqlite3')
        try:
            row = db.execute('SELECT * FROM steps WHERE id=?', (permit['intent_id'],)).fetchone()
            dispatched = db.execute('SELECT body,claimed_at FROM hook_dispatches WHERE id=?', (permit['intent_id'],)).fetchone()
        finally:
            db.close()
        expected = core.canonical({'permit': permit, 'context': context})
        _require(row is not None and dispatched is not None and row['body'] == expected
            and dispatched['body'] == expected and row['parent'] == permit['parent_intent_id']
            and row['kind'] == permit['kind'] and row['phase'] in ({'DISPATCHED'} if fresh else {'DISPATCHED', 'VERIFIED'})
            and type(dispatched['claimed_at']) is int
            and permit['created_at'] <= dispatched['claimed_at'] < permit['expires_at'], 'iran_effect_uncommitted_claim')
        if not fresh:
            return
        registry = load_registry(self.config, allow_test_paths=self.allow_test_paths)
        _require(context['registry_hash'] == self.config['production_driver']['registry']['sha256']
            and all(context[k] == registry[k] for k in ('owner_id', 'service_id', 'project_id')),
            'iran_effect_registry_scope')
        _require(_identity(context['source_identity'])['project_id'] == context['project_id']
            and _identity(context['identity'])['project_id'] == context['project_id'], 'iran_effect_project_scope')
        db = self._database('controller.sqlite3')
        try:
            row = db.execute('SELECT body FROM state WHERE id=1').fetchone()
            state = json.loads(row['body']) if row else {}
            parent = db.execute('SELECT body FROM actions WHERE id=?', (permit['parent_intent_id'],)).fetchone()
        finally:
            db.close()
        resource = state.get('candidate')
        _require(state.get('clock_unsafe') is False and resource is not None
            and type(state.get('last_now')) is int and state['last_now'] <= now
            and core.identity(state['active']) == context['source_identity']
            and core.identity(resource) == context['identity']
            and all(resource.get(k) == context[k] for k in ('config_hash', 'restore_epoch', 'restore_hash'))
            and state.get('incident_id') == context['incident_id'], 'iran_effect_core_scope')
        if operation == 'stage':
            _require(state.get('phase') == 'VALIDATING' and state.get('pending_action') is None,
                     'iran_effect_stage_scope')
        else:
            action = json.loads(parent['body']) if parent else {}
            _require(state.get('phase') == 'CUTOVER' and state.get('pending_action') == permit['parent_intent_id']
                and action.get('id') == permit['parent_intent_id'] and action.get('kind') == 'PROMOTE'
                and action.get('payload', {}).get('identity') == context['identity']
                and action.get('payload', {}).get('old_identity') == context['source_identity']
                and type(action.get('created_at')) is int and type(action.get('expires_at')) is int
                and action['created_at'] <= permit['created_at'] and permit['expires_at'] <= action['expires_at']
                and action['created_at'] <= now < action['expires_at'], 'iran_effect_parent_expired_or_changed')

    def _enabled_agent(self):
        _require(isinstance(self.agent, str) and IDENTIFIER.fullmatch(self.agent)
            and self.config.get('agents', {}).get(self.agent, {}).get('enabled') is True, 'iran_effect_agent_disabled')

    def _row(self, publication):
        with self.adapter._db() as db:
            row = db.execute('SELECT * FROM iran_jobs WHERE id=? AND agent=?', (publication, self.agent)).fetchone()
        return dict(row) if row else None

    @staticmethod
    def _description(row):
        saved = json.loads(row['body'])
        return dict(schema=PUBLICATION, publication_id=row['id'], driver_intent_id=row['driver_intent'],
            operation=row['operation'], phase=row['phase'], exposure=row['exposure'],
            context_hash=core.digest(saved['context']), manifest=copy.deepcopy(saved['manifest']))

    def _prepare(self, publication, permit, context, operation, params, *, port, expiry, isolated_binding=None):
        self._enabled_agent(); now = self._now()
        _require(port in (22, 443) and type(port) is int, 'iran_effect_ssh_port')
        resolved = self.provider.probe_target(context['identity'])
        _require(resolved.get('identity') == context['identity'], 'iran_effect_provider_scope')
        address = _ipv4(resolved.get('ipv4')); values = copy.deepcopy(params)
        _require(isinstance(values, dict), 'iran_effect_params')
        isolated = operation == 'accept' and values.get('acceptance_profile') == ISOLATED_PROFILE
        _require(isolated == (isolated_binding is not None), 'iran_isolated_publication_scope')
        bound = dict(resource_identity=context['identity'], resource_config_hash=context['config_hash'], expires_at=expiry)
        if not isolated:
            bound['critical_consumers'] = list(self.adapter.policy.critical_consumers)
        if values.get('stage_profile') == 'restored':
            bound.update(restore_epoch=context['restore_epoch'], restore_hash=context['restore_hash'])
        else:
            _require(context['restore_epoch'] is None and context['restore_hash'] is None, 'iran_effect_trial_epoch')
        if operation == 'apply':
            source = self.provider.probe_target(context['source_identity'])
            _require(source.get('identity') == context['source_identity'], 'iran_effect_source_scope')
            db = self._database('controller.sqlite3')
            try: current = json.loads(db.execute('SELECT body FROM state WHERE id=1').fetchone()['body'])
            finally: db.close()
            bound.update(old_identity=context['source_identity'], old_ipv4=_ipv4(source.get('ipv4')),
                         old_config_hash=current['active']['config_hash'])
        for key, value in bound.items():
            _require(key not in values or values[key] == value, 'iran_effect_param_scope')
            values[key] = value
        job = dict(id=('read-' if operation == 'accept' else 'effect-')+publication.replace('-', ''),
            op=operation, target=dict(host=address, port=port, provider_id=context['identity']['provider_id']), params=values)
        validate_job(job, {'candidate_ports': [22, 443]})
        with self.adapter.publication_lock():
            now = self._now()
            with self.adapter._db() as db:
                db.execute('BEGIN IMMEDIATE')
                _require(self.adapter._pending_iran_manifest(db, self.agent) is None, 'iran_effect_other_pending')
                generation, revision = self.adapter._next_manifest_scope(db, self.agent,
                    {**context['identity'], 'config_hash': context['config_hash']})
                manifest = dict(agent_id=self.agent, incident_id=context['incident_id'], generation=generation,
                    revision=revision, config_hash=context['config_hash'], issued_at=now, expires_at=expiry,
                    jobs=[job], jobs_hash=manifest_hash([job]))
                validate_manifest(manifest, self.agent, now)
                wire = dict(schema='servernet.relay.recovery.v1', agent_id=self.agent,
                    **{k: manifest[k] for k in ('incident_id', 'generation', 'revision', 'config_hash', 'jobs_hash')}, job=job)
                if isolated:
                    from .isolated_trial import project_context
                    project_context(wire)
                else:
                    ct_stage.validate_context(wire, self.agent)
                self.adapter._save_manifest(db, manifest)
                saved = dict(permit=permit, context=context, manifest=manifest)
                if isolated: saved['isolated_accept'] = isolated_binding
                saved = core.canonical(saved)
                db.execute('INSERT INTO iran_jobs VALUES(?,?,?,?,?,?,?,?,?,?,NULL)',
                    (publication, permit['intent_id'], self.agent, operation, revision, job['id'], expiry,
                     saved, 'PENDING', 'PREPARED'))
        return manifest

    def _expose(self, publication, *, claim=None):
        with self.adapter.publication_lock():
            now = self._now()
            with self.adapter._db() as db:
                db.execute('BEGIN IMMEDIATE')
                row = db.execute('SELECT * FROM iran_jobs WHERE id=?', (publication,)).fetchone()
                _require(row is not None and row['phase'] == 'PENDING' and row['exposure'] == 'PREPARED'
                    and now < row['expires_at'], 'iran_effect_exposure_not_authorized')
                saved = json.loads(row['body'])
                if 'isolated_accept' in saved:
                    self._check_isolated_publication(saved, row=row)
                if claim is not None:
                    self._claim(saved['permit'], saved['context'], row['operation'])
                db.execute("UPDATE iran_jobs SET exposure='CLAIMED' WHERE id=?", (publication,))
            # Claim is committed before the atomic exposure. A crash from this
            # point is ambiguous; no code path retries this write.
            _require(self._now() < row['expires_at'], 'iran_effect_exposure_expired')
            collector._atomic(self.root/('manifest-'+self.agent+'.json'), saved['manifest'])
            with self.adapter._db() as db:
                db.execute("UPDATE iran_jobs SET exposure='EXPOSED' WHERE id=?", (publication,))

    def _verify_addresses(self, publication):
        saved = json.loads(self._row(publication)['body']); job = saved['manifest']['jobs'][0]
        target = self.provider.probe_target(saved['context']['identity'])
        _require(target.get('identity') == saved['context']['identity']
            and target.get('ipv4') == job['target']['host'], 'iran_effect_provider_target_changed')
        if job['op'] == 'apply':
            source = self.provider.probe_target(saved['context']['source_identity'])
            _require(source.get('identity') == saved['context']['source_identity']
                and source.get('ipv4') == job['params']['old_ipv4'], 'iran_effect_source_changed')

    def publish(self, permit, context, operation, params, authorize, *, port=22):
        _require(operation in MUTATIONS, 'iran_effect_operation')
        prior = self._row(permit.get('intent_id') if isinstance(permit, dict) else None)
        if prior:
            saved = json.loads(prior['body'])
            _require(saved['permit'] == permit and saved['context'] == context and prior['operation'] == operation,
                     'iran_effect_immutable_scope_changed')
            job = saved['manifest']['jobs'][0]
            _require(isinstance(params, dict) and port == job['target']['port']
                and all(key in job['params'] and job['params'][key] == value for key, value in params.items()),
                'iran_effect_immutable_params_changed')
            return self._description(prior)  # never re-expose, renew or reissue
        self._claim(permit, context, operation)
        _require(callable(authorize) and authorize(permit) is True, 'iran_effect_authorizer_rejected')
        self._prepare(permit['intent_id'], permit, context, operation, params, port=port, expiry=permit['expires_at'])
        # The authorizer may collect/publish probes. Our pending record already
        # protects the slot; do not call it while holding the publication lock.
        _require(authorize(permit) is True, 'iran_effect_authorizer_rejected')
        self._verify_addresses(permit['intent_id'])
        # Provider reads can consume the lease or invalidate the live gate.
        # Reauthorize after that I/O, still outside the publication lock because
        # the authorizer may recursively collect/publish observation jobs.
        _require(authorize(permit) is True, 'iran_effect_authorizer_rejected')
        self._claim(permit, context, operation)
        self._expose(permit['intent_id'], claim=True)
        return self._description(self._row(permit['intent_id']))

    def publish_accept(self, effect_id, *, ttl=25):
        """Explicit fresh read-only job; never a mutation retry or readiness ACK."""
        original = self._row(effect_id)
        _require(original is not None and original['operation'] in MUTATIONS
            and original['phase'] == 'SUCCEEDED', 'iran_accept_original_not_succeeded')
        saved = json.loads(original['body']); permit, context = saved['permit'], saved['context']
        self._claim(permit, context, original['operation'], fresh=False)
        _require(type(ttl) is int and 1 <= ttl <= 30, 'iran_accept_ttl')
        self._enabled_agent(); now = self._now()
        with self.adapter._db() as db:
            row = db.execute("SELECT * FROM iran_jobs WHERE driver_intent=? AND operation='accept' "
                "AND phase='PENDING' AND expires_at>? ORDER BY revision DESC LIMIT 1", (effect_id, now)).fetchone()
        if row:
            pending = json.loads(row['body'])['manifest']['jobs'][0]
            _require('acceptance_profile' not in pending.get('params', {}), 'iran_accept_profile_pending')
            return self._description(row)
        publication = str(uuid.uuid4()); params = copy.deepcopy(saved['manifest']['jobs'][0]['params'])
        params['expires_at'] = now+ttl
        self._prepare(publication, permit, context, 'accept', params,
            port=saved['manifest']['jobs'][0]['target']['port'], expiry=now+ttl)
        self._expose(publication)
        return self._description(self._row(publication))

    def _isolated_source(self, effect_id, reference):
        """Current exact stage scope plus private hashes, not a readiness proof."""
        from .isolated_trial import _pin
        original = self._row(effect_id)
        _require(original is not None and original['operation'] == 'stage'
            and original['phase'] == 'SUCCEEDED' and original['terminal_hash'] is not None,
            'iran_isolated_stage_not_succeeded')
        saved = json.loads(original['body']); context = saved['context']
        with self.adapter._db() as db:
            terminal = db.execute('SELECT classification,body FROM iran_outcomes WHERE agent=? AND hash=? AND publication=?',
                (self.agent, original['terminal_hash'], effect_id)).fetchone()
        record = json.loads(terminal['body']) if terminal else {}
        _require(terminal is not None and terminal['classification'] == 'succeeded'
            and canonical_json(record.get('manifest')) == canonical_json(saved['manifest'])
            and canonical_json(record.get('context')) == canonical_json(context)
            and canonical_json(record.get('permit')) == canonical_json(saved['permit'])
            and record.get('publication_id') == effect_id,
            'iran_isolated_stage_outcome_missing')
        self._claim(saved['permit'], context, 'stage', fresh=False)
        self._enabled_agent(); now = self._now()
        enrollment = _pin(reference, root_owned=not self.allow_test_paths)
        hashes = {'producer_config_sha256', 'verifier_sha256', 'expected_registry_hash',
                  'dependency_baseline_sha256', 'proxy_binding_sha256'}
        binding = {k: context[k] for k in ('owner_id', 'service_id', 'project_id', 'registry_hash', 'config_hash')}
        _require(set(enrollment) == {'schema', 'enabled', 'agent_id', 'binding', *hashes}
            and enrollment['schema'] == ISOLATED_ENROLLMENT and enrollment['enabled'] is True
            and enrollment['agent_id'] == self.agent and enrollment['binding'] == binding
            and all(isinstance(enrollment[k], str) and HEX64.fullmatch(enrollment[k]) for k in hashes),
            'iran_isolated_enrollment_scope')
        registry = load_registry(self.config, allow_test_paths=self.allow_test_paths)
        _require(context['registry_hash'] == self.config['production_driver']['registry']['sha256']
            and all(context[k] == registry[k] for k in ('owner_id', 'service_id', 'project_id')),
            'iran_isolated_registry_changed')
        db = self._database('controller.sqlite3')
        try:
            row = db.execute('SELECT body FROM state WHERE id=1').fetchone()
            state = json.loads(row['body']) if row else {}
        finally: db.close()
        resource = state.get('candidate')
        _require(state.get('phase') == 'VALIDATING' and state.get('pending_action') is None
            and state.get('clock_unsafe') is False and type(state.get('last_now')) is int
            and state['last_now'] <= now and resource is not None
            and isinstance(state.get('active'), dict)
            and state.get('incident_id') == context['incident_id']
            and canonical_json(core.identity(state.get('active'))) == canonical_json(context['source_identity'])
            and canonical_json(core.identity(resource)) == canonical_json(context['identity'])
            and canonical_json({k: resource.get(k) for k in ('config_hash', 'restore_epoch', 'restore_hash')})
                == canonical_json({k: context[k] for k in ('config_hash', 'restore_epoch', 'restore_hash')}),
            'iran_isolated_core_scope_changed')
        manifest = saved['manifest']; job = manifest['jobs'][0]
        stage_wire = dict(schema='servernet.relay.recovery.v1', agent_id=self.agent,
            **{k: manifest[k] for k in ('incident_id', 'generation', 'revision', 'config_hash', 'jobs_hash')}, job=job)
        ct_stage.validate_context(stage_wire, self.agent)
        ssh = {k: job['params'][k] for k in ('ssh_host_public_key', 'ssh_host_key_sha256')}
        metadata = dict(enrollment=copy.deepcopy(reference), stage_publication_id=effect_id,
            stage_manifest_sha256=core.digest(manifest), ssh_binding_sha256=core.digest(ssh))
        return original, saved, enrollment, metadata, ssh

    def _check_isolated_publication(self, saved, *, row=None):
        metadata = saved['isolated_accept']
        _require(isinstance(metadata, dict) and set(metadata) == {'enrollment', 'stage_publication_id',
            'stage_manifest_sha256', 'ssh_binding_sha256'}, 'iran_isolated_metadata')
        _, source, enrollment, expected, ssh = self._isolated_source(
            metadata['stage_publication_id'], metadata['enrollment'])
        _require(metadata == expected and saved['permit'] == source['permit']
            and saved['context'] == source['context'], 'iran_isolated_original_changed')
        manifest = saved['manifest']; job = manifest['jobs'][0]; params = job['params']
        values = self._isolated_params(source, enrollment, ssh, params['expires_at'])
        validate_manifest(manifest, self.agent, self._now())
        _require(canonical_json(params) == canonical_json(values) and job['op'] == 'accept'
            and job['target'] == source['manifest']['jobs'][0]['target']
            and manifest['config_hash'] == source['context']['config_hash']
            and manifest['incident_id'] == source['context']['incident_id']
            and manifest['generation'] >= source['manifest']['generation']
            and manifest['revision'] > source['manifest']['revision']
            and 0 < manifest['expires_at']-manifest['issued_at'] <= 30
            and params['expires_at'] == manifest['expires_at'], 'iran_isolated_publication_changed')
        if row is not None:
            _require(row['agent'] == manifest['agent_id'] and row['operation'] == 'accept'
                and row['driver_intent'] == source['permit']['intent_id']
                and row['revision'] == manifest['revision'] and row['job_id'] == job['id']
                and row['expires_at'] == manifest['expires_at'], 'iran_isolated_publication_changed')

    @staticmethod
    def _isolated_params(source, enrollment, ssh, expiry):
        original = source['manifest']['jobs'][0]['params']; context = source['context']
        values = dict(acceptance_profile=ISOLATED_PROFILE, stage_profile=original['stage_profile'],
            resource_identity=context['identity'], resource_config_hash=context['config_hash'],
            expected_registry_hash=enrollment['expected_registry_hash'],
            dependency_baseline_sha256=enrollment['dependency_baseline_sha256'],
            proxy_binding_sha256=enrollment['proxy_binding_sha256'],
            isolated_trial_config_sha256=enrollment['producer_config_sha256'],
            isolated_verifier_sha256=enrollment['verifier_sha256'], expires_at=expiry, **ssh)
        if original['stage_profile'] == 'restored':
            values.update(restore_epoch=context['restore_epoch'], restore_hash=context['restore_hash'])
        return values

    def publish_isolated_accept(self, effect_id, enrollment, *, ttl=25):
        """Explicit signed read-only trial. Never renews an original mutation."""
        _require(type(ttl) is int and 1 <= ttl <= 30, 'iran_accept_ttl')
        original, source, enrolled, metadata, ssh = self._isolated_source(effect_id, enrollment)
        now = self._now(); expiry = now+ttl
        with self.adapter._db() as db:
            row = db.execute("SELECT * FROM iran_jobs WHERE driver_intent=? AND operation='accept' "
                "AND phase='PENDING' ORDER BY revision DESC LIMIT 1", (effect_id,)).fetchone()
        if row:
            pending = json.loads(row['body'])
            _require('isolated_accept' in pending and pending['isolated_accept'] == metadata,
                'iran_accept_profile_pending')
            _require(now < row['expires_at'], 'iran_isolated_accept_pending_expired')
            self._check_isolated_publication(pending)
            return self._description(row)
        params = self._isolated_params(source, enrolled, ssh, expiry)
        publication = str(uuid.uuid4())
        self._prepare(publication, source['permit'], source['context'], 'accept', params,
            port=source['manifest']['jobs'][0]['target']['port'], expiry=expiry, isolated_binding=metadata)
        self._verify_addresses(publication)
        self._check_isolated_publication(json.loads(self._row(publication)['body']))
        self._expose(publication)
        return self._description(self._row(publication))

    def reconcile(self, publication):
        """No exposure, new revision, remote write, acceptance or clock refresh."""
        self._now(); self.adapter._ingest(self.agent); now = self._now()
        row = self._row(publication)
        _require(row is not None, 'iran_publication_missing')
        saved = json.loads(row['body'])
        with self.adapter._db() as db:
            result = db.execute('SELECT * FROM iran_outcomes WHERE agent=? AND hash=?',
                (self.agent, row['terminal_hash'])).fetchone() if row['terminal_hash'] else None
            if result is None:
                result = db.execute('SELECT * FROM iran_outcomes WHERE publication=? ORDER BY received_at DESC,rowid DESC LIMIT 1',
                                    (publication,)).fetchone()
        record = json.loads(result['body']) if result else None
        observed = record['receipt'].get('observed_at') if record else None
        received = result['received_at'] if result else None
        fresh = bool(record and record['fresh_observation'] and type(observed) is int
            and 0 <= now-observed <= self.adapter.policy.receipt_max_age
            and 0 <= now-received <= self.adapter.policy.receipt_max_age)
        return dict(schema=OUTCOME, publication_id=publication, driver_intent_id=row['driver_intent'],
            operation=row['operation'], phase=row['phase'], context_hash=core.digest(saved['context']),
            read_only=True, authenticated=record is not None, fresh_observation=fresh,
            observed_at=observed, received_at=received, receipt=copy.deepcopy(record['receipt']) if record else None)
