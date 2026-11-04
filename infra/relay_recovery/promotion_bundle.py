"""Create the private PHP promotion bundle from existing durable evidence.

This component does not enable global recovery, implement missing resource
handlers, or dispatch CRM. A concrete CRM hook may call it immediately before
the existing PHP transaction. Missing complete external readbacks, restore
acceptance, exact registry pins or a live driver claim always block publication.
No provider mutation, shell, remote command or supplied success callback exists.
"""
from __future__ import annotations

import copy
import contextlib
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import sqlite3
import time

from . import backup_client, core
from .production_hooks import (CONTEXT_FIELDS, ORIGIN_SCHEMA, ORIGIN_TTL, IntegrationError, _hash,
    _identity, _ipv4, _private_bytes, _require, load_registry)
from .protocol import canonical_json, decode_json


SCHEMA = 'servernet.relay.promotion-bundle.enrollment.v1'
OBSERVATION = 'servernet.relay.driver.observation.v1'
LOCATION = re.compile(r'\A[a-z0-9][a-z0-9-]{1,95}\Z')
PARENT = re.compile(r'\A[a-f0-9]{40}\Z')
GATE_FIELDS = ('candidate_health', 'critical_receipts', 'backup', 'fencing', 'restore_ready')


class PromotionBundleWriter:
    """Only writes ``promotion-<committed PROMOTE id>.json``, atomically0600.

    Enrollment is a pinned canonical private JSON at
    production_driver.promotion_bundle:{path,sha256}. Its exact fields are
    schema, owner_id, service_id, project_id, registry_hash and locations, where
    locations maps provider location references to actual application codes.
    It cannot select executable code, resource effects or filesystem targets.

    The existing EXTERNAL_CUTOVER reconciliation must carry complete actual
    resource_proofs (every catalogue resource except panel_binding), exact
    critical_receipts, no_old_references:true and
    all_external_resources_verified:true. A dispatch ACK is insufficient.
    """
    def __init__(self, config, *, provider, clock=lambda: int(time.time()), allow_test_paths=False):
        self.config = copy.deepcopy(config); self.provider = provider
        self.clock = clock; self.last_now = None; self.allow_test_paths = allow_test_paths
        self.root = backup_client._directory(config['state_dir'], uid=os.geteuid(), allow_test_paths=allow_test_paths)
        self.age = config.get('policy', {}).get('receipt_max_age', 90)
        _require(type(self.age) is int and 1 <= self.age <= 3600, 'promotion_bundle_receipt_age')

    def _now(self):
        now = self.clock()
        _require(type(now) is int and now >= 0 and (self.last_now is None or now >= self.last_now),
                 'promotion_bundle_clock')
        self.last_now = now; return now

    @contextlib.contextmanager
    def _db(self, name):
        path = self.root/name
        backup_client.trusted_file(str(path), uid=os.geteuid(), allow_test_paths=self.allow_test_paths)
        _require(path.stat().st_nlink == 1, 'promotion_bundle_private_database')
        db = sqlite3.connect(path.as_uri()+'?mode=ro', uri=True, timeout=2)
        db.row_factory = sqlite3.Row; db.execute('PRAGMA query_only=ON'); db.execute('BEGIN')
        try: yield db
        finally: db.close()

    def _fresh(self, receipt, *, age=None):
        now = self._now(); age = self.age if age is None else age
        _require(isinstance(receipt, dict) and receipt.get('verified') is True
            and receipt.get('read_only') is True and type(receipt.get('observed_at')) is int
            and type(receipt.get('received_at')) is int
            and 0 <= now-receipt['observed_at'] <= age and 0 <= now-receipt['received_at'] <= age
            and receipt['observed_at'] <= receipt['received_at']+5, 'promotion_bundle_stale_evidence')
        return min(receipt['observed_at'], receipt['received_at'])+age

    def _enrollment(self, registry, registry_hash):
        entry = self.config.get('production_driver', {}).get('promotion_bundle')
        _require(isinstance(entry, dict) and set(entry) == {'path', 'sha256'}, 'promotion_bundle_enrollment_required')
        raw = _private_bytes(entry['path'], limit=8192, expected_sha256=entry['sha256'], allow_test_paths=self.allow_test_paths)
        value = decode_json(raw)
        _require(raw == canonical_json(value) and set(value) == {'schema', 'owner_id', 'service_id',
            'project_id', 'registry_hash', 'locations'} and value['schema'] == SCHEMA
            and value['registry_hash'] == registry_hash
            and all(value[k] == registry[k] for k in ('owner_id', 'service_id', 'project_id')),
            'promotion_bundle_enrollment_scope')
        locations = value['locations']
        _require(isinstance(locations, dict) and 1 <= len(locations) <= 32
            and all(isinstance(k, str) and LOCATION.fullmatch(k) and isinstance(v, str)
                and LOCATION.fullmatch(v) for k, v in locations.items()), 'promotion_bundle_location_mapping')
        return value

    def _gate_fresh(self, receipt, context, *, age=None, restored=True):
        """Core receipts predate this writer and need not claim read_only."""
        age = self.age if age is None else age; now = self._now()
        _require(isinstance(receipt, dict) and receipt.get('verified') is True
            and type(receipt.get('received_at')) is int and 0 <= now-receipt['received_at'] <= age
            and receipt.get('incident_id') == context['incident_id']
            and receipt.get('generation') == context['identity']['generation']
            and receipt.get('config_hash') == context['config_hash'],
            'promotion_bundle_gate_evidence_stale_or_unscoped')
        if restored or {'restore_epoch', 'restore_hash'} & receipt.keys():
            _require(all(receipt.get(k) == context[k] for k in ('restore_epoch', 'restore_hash')),
                     'promotion_bundle_gate_evidence_stale_or_unscoped')
        at = receipt.get('observed_at', receipt['received_at'])
        _require(type(at) is int and 0 <= now-at <= age and at <= receipt['received_at']+5,
                 'promotion_bundle_gate_evidence_stale_or_unscoped')
        return min(at, receipt['received_at'])+age

    def _cloudflare_readback(self, resource, detail, context, target, deadlines):
        """Independently validate the existing CloudflareResources proof shape."""
        record = detail['record']; typ = record.get('type')
        expected = target.get('ipv4') if typ == 'A' else target.get('ipv6') if typ == 'AAAA' else None
        _require(typ in {'A', 'AAAA'} and expected is not None and record.get('content') == expected
            and record.get('id') == resource['record_id'] and type(record.get('proxied')) is bool,
            'promotion_bundle_cloudflare_record_mismatch')
        zones = [z for z in self.config.get('cloudflare', {}).get('zones', []) if z.get('zone_id') == resource['zone_id']]
        _require(len(zones) == 1 and resource['record_id'] in zones[0].get('record_ids', []),
                 'promotion_bundle_cloudflare_zone_missing')
        servers = zones[0].get('authoritative_ips', [])
        _require(isinstance(servers, list) and 1 <= len(servers) <= 8 and len(set(servers)) == len(servers),
                 'promotion_bundle_cloudflare_authority_missing')
        for server in servers: _ipv4(server)
        if not record['proxied']:
            answers = detail.get('authoritative')
            _require(isinstance(answers, list) and len(answers) == len(servers)
                and {a.get('server') for a in answers if isinstance(a, dict)} == set(servers),
                'promotion_bundle_cloudflare_authority_missing')
            for answer in answers:
                at = answer.get('observed_at'); actual = answer.get('answer', {})
                _require(answer.get('verified') is True and type(at) is int and 0 <= self._now()-at <= self.age
                    and isinstance(actual, dict) and actual.get('authoritative') is True
                    and actual.get('name') == resource['hostname'] and actual.get('type') == typ
                    and isinstance(actual.get('addresses'), list) and actual['addresses']
                    and set(actual['addresses']) == {expected}, 'promotion_bundle_cloudflare_authority_unverified')
                deadlines.append(at+self.age)
            return
        origin = detail['origin']; parts = origin.get('proofs', [])
        _require(origin.get('origin_ipv4') == target.get('ipv4')
            and isinstance(parts, list) and len(parts) == 2
            and {p.get('path') for p in parts if isinstance(p, dict)} == {'direct', 'edge'},
            'promotion_bundle_origin_address_or_routes')
        times = []
        for part in parts:
            at = part.get('observed_at'); peer = part.get('peer_ipv4')
            _hash(part.get('body_sha256')); _ipv4(peer)
            _require(type(at) is int and 0 <= self._now()-at < ORIGIN_TTL
                and ((part['path'] == 'direct' and peer == target['ipv4'])
                     or (part['path'] == 'edge' and peer != target['ipv4'])),
                'promotion_bundle_origin_route_unverified')
            times.append(at); deadlines.append(at+ORIGIN_TTL)
        _require(origin['observed_at'] == min(times), 'promotion_bundle_origin_clock')

    def _scope(self, permit, context, authorize):
        now = self._now()
        _require(self.config.get('production_driver', {}).get('enabled') is True
            and self.config.get('policy', {}).get('enabled') is True and callable(authorize)
            and isinstance(context, dict) and set(context) == CONTEXT_FIELDS | {'gate_hash', 'checkpoint'}
            and context.get('schema') == 'servernet.relay.production-driver.v1'
            and isinstance(context.get('parent_intent_id'), str) and PARENT.fullmatch(context['parent_intent_id'])
            and isinstance(context.get('checkpoint'), str) and 1 <= len(context['checkpoint']) <= 256,
            'promotion_bundle_context')
        old, new = _identity(context['source_identity']), _identity(context['identity'])
        _hash(context['config_hash']); _hash(context['gate_hash']); _hash(context['restore_hash'])
        _require(old['project_id'] == new['project_id'] == context['project_id']
            and old['provider_id'] != new['provider_id'] and new['generation'] == old['generation']+1
            and type(context['restore_epoch']) is int and context['restore_epoch'] > 0,
            'promotion_bundle_generation')
        _require(isinstance(permit, dict) and set(permit) == {'schema', 'intent_id', 'parent_intent_id', 'kind',
            'context_hash', 'created_at', 'expires_at'} and permit['schema'] == 'servernet.relay.driver.permit.v1'
            and permit['kind'] == 'CRM_PROMOTION' and permit['parent_intent_id'] == context['parent_intent_id']
            and permit['context_hash'] == core.digest(context)
            and type(permit['created_at']) is int and type(permit['expires_at']) is int
            and permit['created_at'] <= now < permit['expires_at']
            and 0 < permit['expires_at']-permit['created_at'] <= 30 and authorize(permit) is True,
            'promotion_bundle_live_driver_claim_required')

    def __call__(self, permit, context, authorize):
        self._scope(permit, context, authorize)
        registry = load_registry(self.config, allow_test_paths=self.allow_test_paths)
        registry_hash = self.config['production_driver']['registry']['sha256']
        _require(context['registry_hash'] == registry_hash and all(context[k] == registry[k]
            for k in ('owner_id', 'service_id', 'project_id')), 'promotion_bundle_registry_scope')
        enrollment = self._enrollment(registry, registry_hash)
        panel = [r for r in registry['resources'] if r['kind'] == 'panel_binding']
        _require(len(panel) == 1 and set(panel[0]) == {'id', 'kind', 'instance_id', 'exit_upstream_ids'}
            and type(panel[0]['instance_id']) is int and panel[0]['instance_id'] > 0
            and isinstance(panel[0]['exit_upstream_ids'], list)
            and len(panel[0]['exit_upstream_ids']) <= 32
            and all(type(i) is int and i > 0 for i in panel[0]['exit_upstream_ids'])
            and len(set(panel[0]['exit_upstream_ids'])) == len(panel[0]['exit_upstream_ids']), 'promotion_bundle_panel_pin')
        normal = {k: context[k] for k in CONTEXT_FIELDS}
        with self._db('production-driver.sqlite3') as db:
            crm = db.execute('SELECT * FROM steps WHERE id=?', (permit['intent_id'],)).fetchone()
            external = db.execute("SELECT * FROM steps WHERE parent=? AND kind='EXTERNAL_CUTOVER'",
                                  (context['parent_intent_id'],)).fetchone()
            gate = db.execute('SELECT body FROM promotion_gates WHERE parent=?', (context['parent_intent_id'],)).fetchone()
        _require(crm is not None and crm['parent'] == context['parent_intent_id'] and crm['kind'] == 'CRM_PROMOTION'
            and crm['phase'] == 'DISPATCHED' and json.loads(crm['body']) == {'permit': permit, 'context': context},
            'promotion_bundle_uncommitted_claim')
        _require(external is not None and external['phase'] == 'VERIFIED' and gate is not None,
                 'promotion_bundle_external_not_verified')
        saved = json.loads(external['body']); proof = json.loads(external['result'])
        _require(saved['context'] == normal and proof.get('schema') == OBSERVATION
            and proof.get('operation') == 'cutover_dispatch' and proof.get('context_hash') == core.digest(normal)
            and proof.get('intent_id') == saved['permit']['intent_id'] and proof.get('state') == 'complete'
            and proof.get('no_old_references') is True and proof.get('all_external_resources_verified') is True,
            'promotion_bundle_external_proof_incomplete')
        deadlines = [self._fresh(proof, age=30), permit['expires_at']]
        expected = {r['id']: r for r in registry['resources'] if r['kind'] != 'panel_binding'}
        resources = proof.get('resource_proofs')
        _require(expected and isinstance(resources, list) and len(resources) == len(expected)
            and {r.get('id') for r in resources if isinstance(r, dict)} == set(expected),
            'promotion_bundle_external_resource_coverage')
        for resource in resources:
            detail = resource.get('proof', {})
            _require(resource.get('verified') is True and isinstance(resource.get('verifier'), str)
                and resource['verifier'] and isinstance(detail, dict)
                and detail.get('resource') == expected[resource['id']]
                and type(detail.get('observed_at')) is int
                and 0 <= self._now()-detail['observed_at'] <= self.age
                and resource.get('proof_sha256') == core.digest(detail),
                'promotion_bundle_external_resource_invalid')
            deadlines.append(detail['observed_at']+self.age)
            origin = detail.get('origin')
            if expected[resource['id']]['kind'] == 'cloudflare_record':
                _require(isinstance(detail.get('record'), dict)
                    and detail['record'].get('name') == expected[resource['id']].get('hostname')
                    and detail.get('content_verified') is True, 'promotion_bundle_cloudflare_proof_invalid')
                _require(not detail['record'].get('proxied') or origin is not None,
                         'promotion_bundle_origin_required')
            else:
                _require(detail.get('read_only') is True and isinstance(detail.get('evidence'), dict)
                    and detail['evidence'], 'promotion_bundle_resource_evidence_required')
            if origin is not None:
                _require(isinstance(origin, dict) and origin.get('verified') is True
                    and origin.get('schema') == ORIGIN_SCHEMA and origin.get('origin_verified') is True
                    and origin.get('edge_route_verified') is True and origin.get('read_only') is True
                    and origin.get('identity') == context['identity']
                    and origin.get('config_hash') == context['config_hash']
                    and origin.get('hostname') == expected[resource['id']].get('hostname')
                    and type(origin.get('observed_at')) is int,
                    'promotion_bundle_origin_invalid')
                _hash(origin.get('machine_uuid_sha256'))
                _require(0 <= self._now()-origin['observed_at'] < ORIGIN_TTL, 'promotion_bundle_origin_expired')
                deadlines.append(origin['observed_at']+ORIGIN_TTL)
        receipts = proof.get('critical_receipts', [])
        _require(isinstance(receipts, list) and len(receipts) == len(registry['consumers'])
            and {r.get('consumer') for r in receipts if isinstance(r, dict)} == set(registry['consumers']),
            'promotion_bundle_critical_coverage')
        for receipt in receipts:
            deadlines.append(self._gate_fresh(receipt, context))
            _require(receipt.get('ok') is True and all(receipt.get(k) == context[k] for k in
                ('identity', 'config_hash', 'incident_id', 'restore_epoch', 'restore_hash'))
                and ('source_identity' not in receipt or receipt['source_identity'] == context['source_identity'])
                and receipt.get('read_only', True) is True
                and receipt.get('generation') == context['identity']['generation'], 'promotion_bundle_critical_scope')
        with self._db('controller.sqlite3') as db:
            row = db.execute("SELECT body FROM actions WHERE id=? AND kind='PROMOTE'", (context['parent_intent_id'],)).fetchone()
            state_row = db.execute('SELECT body FROM state WHERE id=1').fetchone()
        _require(row is not None and state_row is not None, 'promotion_bundle_parent_missing')
        action, state, evidence = json.loads(row['body']), json.loads(state_row['body']), json.loads(gate['body'])
        _require(state.get('phase') == 'CUTOVER' and state.get('pending_action') == context['parent_intent_id']
            and state.get('clock_unsafe') is False and state.get('incident_id') == context['incident_id']
            and core.identity(state['active']) == context['source_identity']
            and core.identity(state['candidate']) == context['identity']
            and all(state['candidate'].get(k) == context[k] for k in ('config_hash', 'restore_epoch', 'restore_hash'))
            and action.get('id') == context['parent_intent_id'] and action.get('kind') == 'PROMOTE'
            and action.get('incident_id') == context['incident_id']
            and action.get('generation') == context['identity']['generation']
            and action['payload'].get('identity') == context['identity']
            and action['payload'].get('old_identity') == context['source_identity']
            and action['payload'].get('gate_hash') == context['gate_hash']
            and action['payload'].get('checkpoint') == context['checkpoint']
            and action['created_at'] <= self._now() < action['expires_at']
            and permit['created_at'] >= action['created_at'] and permit['expires_at'] <= action['expires_at']
            and core.digest({k: evidence[k] for k in GATE_FIELDS}) == context['gate_hash'],
            'promotion_bundle_parent_scope')
        backup = evidence['backup']; fencing = evidence['fencing']; ready = evidence['restore_ready']
        data_age = self.config.get('policy', {}).get('data_max_age', 300)
        _require(type(data_age) is int and 1 <= data_age <= 86400, 'promotion_bundle_data_age')
        deadlines.extend((self._gate_fresh(backup, context, age=data_age, restored=False),
            self._gate_fresh(fencing, context, restored=False),
            self._gate_fresh(ready, context), self._gate_fresh(evidence['candidate_health'], context)))
        critical_gate = evidence['critical_receipts']
        _require(isinstance(critical_gate, list) and len(critical_gate) == len(registry['consumers'])
            and {r.get('consumer') for r in critical_gate if isinstance(r, dict)} == set(registry['consumers'])
            and all(r.get('ok') is True for r in critical_gate), 'promotion_bundle_gate_critical_incomplete')
        deadlines.extend(self._gate_fresh(r, context) for r in critical_gate)
        _require(backup.get('durable') is True and backup.get('restore_verified') is True
            and backup.get('checkpoint') == context['checkpoint'] and fencing.get('verified_fenced') is True
            and fencing.get('old_generation') == context['source_identity']['generation']
            and ready.get('pre_cutover_ready') is True and ready.get('source_identity') == context['source_identity']
            and all(ready.get(k) == context[k] for k in ('identity', 'config_hash', 'restore_epoch', 'restore_hash')),
            'promotion_bundle_restore_gate_incomplete')
        with self._db('restore-workflow.sqlite3') as db:
            rows = db.execute('SELECT body FROM workflows WHERE candidate_id=?', (context['identity']['provider_id'],)).fetchall()
        _require(len(rows) == 1, 'promotion_bundle_restore_workflow_missing'); workflow = json.loads(rows[0]['body'])
        fence = workflow.get('proofs', {}).get('fence', {})
        _require(workflow.get('phase') == 'PRE_CUTOVER_READY' and workflow.get('source_fenced') is True
            and workflow.get('id') == ready.get('workflow_id')
            and core.identity(workflow['source']) == context['source_identity']
            and core.identity(workflow['candidate']) == context['identity']
            and workflow['candidate'].get('config_hash') == context['config_hash']
            and all(workflow.get(k) == context[k] for k in ('restore_epoch', 'restore_hash'))
            and fence.get('service_id') == context['service_id']
            and all(fence.get(k) == workflow['source'][k] for k in ('provider_id', 'generation', 'config_hash'))
            and all(fence.get(k) is True for k in ('ok', 'physical_identity_verified', 'guard_installed',
                'identity_fenced', 'persistent_fence', 'all_units_inactive'))
            and fence.get('docker', {}).get('exact_inventory') is True
            and fence.get('docker', {}).get('daemon_inactive') is True
            and fence.get('docker', {}).get('no_shims') is True and fence.get('docker', {}).get('running_ids') == [],
            'promotion_bundle_restore_workflow_unverified')
        observed = self._now()
        source = self.provider.store.read_resource(context['source_identity']['provider_id'])
        _require(core.identity(self.provider._identity(source)) == context['source_identity'], 'promotion_bundle_source_identity')
        raw_source = self.provider._verified_server(self.provider._client(), source)
        _require(raw_source.get('status') == 'off', 'promotion_bundle_source_not_off')
        target = self.provider.probe_target(context['identity'])
        _require(target.get('identity') == context['identity'] and target.get('location_code') in enrollment['locations'],
                 'promotion_bundle_location_not_enrolled')
        for resource in resources:
            detail = resource['proof']
            if expected[resource['id']]['kind'] == 'cloudflare_record':
                self._cloudflare_readback(expected[resource['id']], detail, context, target, deadlines)
        target_record = self.provider.store.read_resource(context['identity']['provider_id'])
        raw_target = self.provider._verified_server(self.provider._client(), target_record)
        _require(core.identity(self.provider._identity(target_record)) == context['identity']
            and raw_target.get('status') == 'running', 'promotion_bundle_candidate_not_running')
        network = raw_target.get('public_net', {}).get('ipv6')
        ipv6 = None
        if network and network.get('ip'):
            try: prefix = ipaddress.IPv6Network(network['ip'], strict=True)
            except (ValueError, TypeError): raise IntegrationError('promotion_bundle_ipv6') from None
            try: confirmed = ipaddress.IPv6Address(target.get('ipv6'))
            except (ValueError, TypeError): raise IntegrationError('promotion_bundle_verified_ipv6_host_required') from None
            _require(prefix.is_global and prefix.prefixlen == 64 and confirmed.is_global and confirmed in prefix
                and str(confirmed) == target['ipv6'], 'promotion_bundle_ipv6')
            ipv6 = str(confirmed)
        verified = min(observed, proof['observed_at'])
        expires = min(deadlines+[verified+30])
        _require(self._now() < expires, 'promotion_bundle_proof_expired')
        bundle = dict(intent_id=context['parent_intent_id'], incident_id=context['incident_id'],
            old_identity=context['source_identity'], new_identity=context['identity'], config_hash=context['config_hash'],
            gate_hash=context['gate_hash'], checkpoint=context['checkpoint'], ipv4=_ipv4(target.get('ipv4')), ipv6=ipv6,
            location_code=enrollment['locations'][target['location_code']], all_checks_passed=True,
            verified_at=verified, proof_expires_at=expires,
            checks={**{r['consumer']: r['ok'] for r in receipts}, 'backup_durable': True,
                'restore_verified': True, 'old_fenced': True, 'dns_cutover_verified': True},
            evidence_sha256=core.digest(dict(external=proof, restore=ready, fence=fence, provider_off_at=observed)))
        self._scope(permit, context, authorize)
        _require(self._now() < expires, 'promotion_bundle_proof_expired')
        path = self.root/('promotion-'+context['parent_intent_id']+'.json')
        if path.exists() or path.is_symlink():
            previous = decode_json(_private_bytes(path, limit=65536, allow_test_paths=self.allow_test_paths))
            _require(all(previous.get(k) == bundle[k] for k in ('intent_id', 'incident_id', 'old_identity',
                'new_identity', 'config_hash', 'gate_hash', 'checkpoint')), 'promotion_bundle_existing_scope_changed')
        temporary = self.root/('promotion-bundle-'+secrets.token_hex(16)+'.tmp')
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            with os.fdopen(fd, 'wb') as stream:
                stream.write(canonical_json(bundle)); stream.flush(); os.fsync(stream.fileno())
            self._scope(permit, context, authorize)
            _require(self._now() < expires, 'promotion_bundle_proof_expired')
            os.replace(temporary, path)
            directory = os.open(self.root, os.O_DIRECTORY)
            try: os.fsync(directory)
            finally: os.close(directory)
        finally:
            if temporary.exists(): temporary.unlink()
        return bundle
