#!/usr/bin/env python3
"""Private WHM evidence collector and data-only Iran probe publisher.

The gateway has already authenticated agent envelopes before committing its
``latest`` table. This adapter additionally binds every job receipt to the exact
locally published manifest. It preserves both receiver and remote observation
times: polling an old successful job never makes that job fresh again.
After a committed local restore epoch, only a newly published exact epoch/hash
job and observations received after that epoch may supply health/critical
evidence. Network probes attest fresh connectivity, not snapshot/disk identity;
that provenance is verified separately by the committed local restore workflow.

The ordinary collector publishes only ``probe`` jobs. The separate finite
``iran_effects`` API binds stage/apply/readback jobs to durable driver claims;
pending effects retain their manifest slot and their own outcome ledger.
Promotion is unavailable unless an installed,
hash-pinned cutover recipe implements the entire transaction and reconciliation.
Local recipes are executable paths from private controller configuration, never
commands from agent reports. Missing integrations remain false preflight checks.
"""
from __future__ import annotations

import argparse
import contextlib
import copy
import fcntl
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import sqlite3
import stat
import sys
import time

from .agent import _bounded_process
from .core import Config, Journal, RecoveryError, canonical, identity, same_identity
from .gateway import private_file, private_state_dir
from .protocol import (HEX64, IDENTIFIER, NONCE, canonical_json, decode_json,
                       manifest_hash, private_file as pinned_file,
                       validate_manifest)


class CollectorError(RecoveryError):
    pass


def _private_db(path: Path) -> None:
    s = path.lstat()
    if (not stat.S_ISREG(s.st_mode) or s.st_uid != os.geteuid()
            or s.st_mode & 0o077 or path.is_symlink() or s.st_size > 128 * 1024 * 1024):
        raise CollectorError('private_database_required')


def _fresh(observed, received, now: int, age: int) -> bool:
    return (type(observed) is int and type(received) is int
            and 0 <= now - observed <= age and 0 <= now - received <= age
            and observed <= received + 5)


def _stored_envelope(raw: str) -> dict:
    # Gateway wraps an already authenticated <=64 KiB wire body with receiver
    # metadata. Applying the smaller wire limit to that wrapper would strand a
    # legitimately ACKed large report forever in the durable inbox.
    if not isinstance(raw, str) or len(raw.encode()) > 131072:
        raise CollectorError('stored_report_size')
    value = json.loads(raw)
    if not isinstance(value, dict): raise CollectorError('stored_report_shape')
    return value


def _atomic(path: Path, value: dict) -> None:
    if path.is_symlink():
        raise CollectorError('manifest_symlink')
    temp = path.with_name(path.name + '.tmp-' + str(os.getpid()))
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(canonical_json(value)); stream.flush(); os.fsync(stream.fileno())
        os.replace(temp, path)
        directory = os.open(path.parent, os.O_DIRECTORY)
        try: os.fsync(directory)
        finally: os.close(directory)
    finally:
        if temp.exists(): temp.unlink()


class Adapter:
    def __init__(self, config: dict, now: int | None = None, *, provider=None,
                 runner=_bounded_process, allow_test_paths=False):
        self.config = config
        self.now = int(time.time()) if now is None else now
        started = time.monotonic()
        self.clock = time.time if now is None else lambda: now + (time.monotonic()-started)
        if type(self.now) is not int or self.now < 0:
            raise CollectorError('invalid_clock')
        self.root = private_state_dir(Path(config['state_dir']))
        values = dict(config.get('policy', {}))
        if 'critical_consumers' in values:
            values['critical_consumers'] = tuple(values['critical_consumers'])
        self.policy = Config(**values)
        self.policy.validate()
        self.options = config.get('collector', {})
        if not isinstance(self.options, dict):
            raise CollectorError('collector_config')
        if (type(self.options.get('dns_ownership_refresh', False)) is not bool
                or type(allow_test_paths) is not bool):
            raise CollectorError('dns_ownership_refresh_policy')
        self.allow_test_paths = allow_test_paths
        self.runner, self.provider = runner, provider
        self.path = self.root/'controller-evidence.sqlite3'
        if self.path.exists(): _private_db(self.path)
        if self.path.is_symlink(): raise CollectorError('state_symlink')
        with self._db() as db:
            db.executescript('''
              CREATE TABLE IF NOT EXISTS sequence(agent TEXT PRIMARY KEY, revision INTEGER NOT NULL);
              CREATE TABLE IF NOT EXISTS wire_generation(agent TEXT PRIMARY KEY, generation INTEGER NOT NULL,
                config_hash TEXT NOT NULL);
              CREATE TABLE IF NOT EXISTS manifests(agent TEXT NOT NULL, revision INTEGER NOT NULL,
                issued_at INTEGER NOT NULL, body TEXT NOT NULL, PRIMARY KEY(agent,revision));
              CREATE TABLE IF NOT EXISTS receipts(hash TEXT PRIMARY KEY, received_at INTEGER NOT NULL,
                body TEXT NOT NULL);
              CREATE TABLE IF NOT EXISTS ingested_reports(agent TEXT NOT NULL, report_hash TEXT NOT NULL,
                received_at INTEGER NOT NULL, body_sha256 TEXT NOT NULL, status TEXT NOT NULL,
                PRIMARY KEY(agent,report_hash));
              CREATE TABLE IF NOT EXISTS iran_jobs(id TEXT PRIMARY KEY,driver_intent TEXT NOT NULL,
                agent TEXT NOT NULL,operation TEXT NOT NULL,revision INTEGER NOT NULL,job_id TEXT NOT NULL,
                expires_at INTEGER NOT NULL,body TEXT NOT NULL,phase TEXT NOT NULL,
                exposure TEXT NOT NULL,terminal_hash TEXT,UNIQUE(agent,revision,job_id));
              CREATE UNIQUE INDEX IF NOT EXISTS iran_mutation_once ON iran_jobs(driver_intent)
                WHERE operation IN ('stage','apply');
              CREATE TABLE IF NOT EXISTS iran_outcomes(agent TEXT NOT NULL,hash TEXT NOT NULL,
                publication TEXT NOT NULL,received_at INTEGER NOT NULL,classification TEXT NOT NULL,
                body TEXT NOT NULL,PRIMARY KEY(agent,hash));
            ''')
        os.chmod(self.path, 0o600)

    def _db(self):
        db = sqlite3.connect(self.path, timeout=5)
        db.row_factory = sqlite3.Row
        db.execute('PRAGMA synchronous=FULL')
        return db

    @contextlib.contextmanager
    def publication_lock(self):
        """Serialize durable manifest history and its single exposed file.

        Callers must not invoke the driver's authorizer under this lock: its
        fresh gate collection can recursively call ``publish``.
        """
        path = self.root/'controller-publish.lock'
        fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        try:
            info = os.fstat(fd)
            if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid()
                    or info.st_mode & 0o077 or info.st_nlink != 1):
                raise CollectorError('private_publication_lock_required')
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            yield
        finally:
            os.close(fd)

    def _pending_iran_manifest(self, db, agent):
        row = db.execute("SELECT body FROM iran_jobs WHERE agent=? AND phase IN ('PENDING','CONFLICT') "
            "AND (operation IN ('stage','apply') OR expires_at>?) ORDER BY revision LIMIT 1",
            (agent, self.now)).fetchone()
        return json.loads(row['body'])['manifest'] if row else None

    def _next_manifest_scope(self, db, agent, resource):
        row = db.execute('SELECT revision FROM sequence WHERE agent=?', (agent,)).fetchone()
        revision = (row[0] if row else 0) + 1
        prior = db.execute('SELECT generation,config_hash FROM wire_generation WHERE agent=?', (agent,)).fetchone()
        generation = resource['generation']
        if prior:
            generation = max(generation, prior['generation'])
            if resource['config_hash'] != prior['config_hash']:
                generation = max(generation, prior['generation']+1)
        return generation, revision

    @staticmethod
    def _save_manifest(db, manifest):
        agent, revision = manifest['agent_id'], manifest['revision']
        db.execute('INSERT OR REPLACE INTO sequence VALUES(?,?)', (agent, revision))
        db.execute('INSERT OR REPLACE INTO wire_generation VALUES(?,?,?)',
                   (agent, manifest['generation'], manifest['config_hash']))
        db.execute('INSERT INTO manifests VALUES(?,?,?,?)',
                   (agent, revision, manifest['issued_at'], canonical_json(manifest).decode()))

    def _provider(self):
        if self.provider is None:
            from .provider_adapter import ProviderEffects
            # Provider-side destructive gates must advance across HTTP calls.
            # Tests inject their fake provider instead of freezing this clock.
            self.provider = ProviderEffects(self.config)
        return self.provider

    def _refresh(self):
        self.now = int(self.clock())

    def recipe(self, name: str, state: dict, *, action: str | None = None,
               payload: dict | None = None) -> dict | None:
        self._refresh()
        entry = self.options.get('recipes', {}).get(name)
        if not isinstance(entry, dict): return None
        try:
            path = pinned_file(entry['path'], root_owned=False, executable=True)
            if path.stat().st_uid != os.geteuid():
                raise CollectorError('recipe_owner')
            if hashlib.sha256(path.read_bytes()).hexdigest() != entry.get('sha256'):
                raise CollectorError('recipe_hash')
            body = {'schema': 'servernet.relay.collector.request.v1', 'kind': name,
                    'now': self.now, 'state': state, 'binding': self.config.get('binding', {})}
            if payload is not None: body['payload'] = payload
            code, raw = self.runner([str(path), action or name], canonical_json(body), timeout=2)
            self._refresh()
            result = decode_json(raw)
            if (code != 0 or result.get('schema') != 'servernet.relay.collector.v1'
                    or result.get('kind') != name or type(result.get('ok')) is not bool
                    or not _fresh(result.get('observed_at'), self.now, self.now,
                                  self.policy.receipt_max_age)):
                return None
            return result
        except Exception:
            return None

    def _latest(self, agent: str) -> dict | None:
        path = self.root/'agent-reports.sqlite3'
        if not path.exists(): return None
        _private_db(path)
        db = sqlite3.connect(path.as_uri()+'?mode=ro', uri=True, timeout=3)
        try:
            row = db.execute('SELECT received_at,body FROM latest WHERE agent_id=?', (agent,)).fetchone()
        finally: db.close()
        if row is None: return None
        received = _stored_envelope(row[1])
        report = received.get('report', {})
        if (received.get('agent_id') != agent or received.get('received_at') != row[0]
                or not isinstance(report, dict) or report.get('agent_id') != agent
                or not isinstance(received.get('body_sha256'), str)
                or not HEX64.fullmatch(received['body_sha256'])
                or not isinstance(received.get('request_nonce'), str)
                or not NONCE.fullmatch(received['request_nonce'])
                or not _fresh(report.get('observed_at'), row[0], self.now,
                              self.policy.receipt_max_age)):
            return None
        return received

    def _ingest_report(self, agent: str, report_hash: str, received_at: int,
                       body_sha256: str, raw: str) -> None:
        """Durably accept/reject one authenticated inbox envelope before ACKing
        consumption. Old observations remain in the audit store but cannot pass
        ``_resource_receipt`` freshness gates.
        """
        envelope = _stored_envelope(raw)
        report = envelope.get('report', {})
        if (envelope.get('agent_id') != agent or envelope.get('received_at') != received_at
                or type(received_at) is not int or received_at > self.now+5
                or envelope.get('body_sha256') != body_sha256
                or not isinstance(body_sha256, str) or not HEX64.fullmatch(body_sha256)
                or not isinstance(envelope.get('request_nonce'), str)
                or not NONCE.fullmatch(envelope['request_nonce'])
                or not isinstance(report, dict) or report.get('agent_id') != agent
                or hashlib.sha256(canonical_json(report)).hexdigest() != report_hash):
            raise CollectorError('inbox_envelope_invalid')
        jobs = report.get('jobs')
        if not isinstance(jobs, list) or not 1 <= len(jobs) <= 6:
            raise CollectorError('inbox_jobs_invalid')
        with self._db() as db:
            # Routing reads the prior effect disposition before inserting its
            # receipt. Serialize that read/decision/write across collectors so
            # contradictory terminal reports cannot both replace PENDING.
            db.execute('BEGIN IMMEDIATE')
            prior = db.execute('SELECT received_at,body_sha256 FROM ingested_reports WHERE agent=? AND report_hash=?',
                               (agent, report_hash)).fetchone()
            if prior:
                if prior['received_at'] != received_at or prior['body_sha256'] != body_sha256:
                    raise CollectorError('inbox_immutable_conflict')
                return
            accepted = 0
            for receipt in jobs:
                if not isinstance(receipt, dict): continue
                revision = receipt.get('revision')
                if type(revision) is not int: continue
                row = db.execute('SELECT body FROM manifests WHERE agent=? AND revision=?',
                                 (agent, revision)).fetchone()
                if row is None: continue
                manifest = json.loads(row['body'])
                if any(type(receipt.get(k)) is not type(manifest[k]) or receipt.get(k) != manifest[k]
                       for k in ('generation', 'revision', 'config_hash', 'jobs_hash', 'incident_id')):
                    continue
                selected = [j for j in manifest['jobs'] if j['id'] == receipt.get('job_id')]
                if (len(selected) == 1 and selected[0]['op'] in {'stage', 'apply', 'accept'}
                        and receipt.get('op') == selected[0]['op']):
                    from .iran_effects import route_receipt
                    if route_receipt(db, agent, manifest, selected[0], receipt, received_at):
                        accepted += 1
                    continue
                observed = receipt.get('observed_at')
                if (len(selected) != 1 or selected[0]['op'] != 'probe' or receipt.get('op') != 'probe'
                        or type(receipt.get('ok')) is not bool
                        or not isinstance(receipt.get('checks'), dict)
                        or type(observed) is not int or observed > received_at+5
                        or not manifest['issued_at'] <= observed <= manifest['expires_at']):
                    continue
                record = {'agent_id': agent, 'received_at': received_at,
                          'receipt': receipt, 'job': selected[0],
                          'manifest_issued_at': manifest['issued_at'],
                          'incident_id': manifest['incident_id']}
                # Immutable original receiver time: a duplicate cannot refresh it.
                digest = hashlib.sha256(canonical_json(receipt)).hexdigest()
                db.execute('INSERT OR IGNORE INTO receipts VALUES(?,?,?)',
                           (digest, received_at, canonical_json(record).decode()))
                accepted += 1
            db.execute('INSERT INTO ingested_reports VALUES(?,?,?,?,?)',
                       (agent, report_hash, received_at, body_sha256,
                        'accepted' if accepted == len(jobs) else 'partially_rejected' if accepted else 'rejected'))
        # Leaving this context commits synchronous=FULL before the gateway's
        # consumed_at is updated. A crash between the commits is idempotent.

    def _mark_consumed(self, agent: str, report_hash: str) -> None:
        path = self.root/'agent-reports.sqlite3'
        _private_db(path)
        db = sqlite3.connect(path, timeout=3)
        try:
            db.execute('PRAGMA synchronous=FULL')
            db.execute('UPDATE inbox SET consumed_at=? WHERE agent_id=? AND report_hash=? AND consumed_at IS NULL',
                       (self.now, agent, report_hash))
            db.commit()
        finally: db.close()

    def _ingest(self, agent: str) -> dict | None:
        self._refresh()
        path = self.root/'agent-reports.sqlite3'
        if path.exists():
            _private_db(path)
            db = sqlite3.connect(path.as_uri()+'?mode=ro', uri=True, timeout=3)
            try:
                rows = db.execute('SELECT report_hash,received_at,body_sha256,body FROM inbox '
                                  'WHERE agent_id=? AND consumed_at IS NULL ORDER BY received_at,rowid LIMIT 64',
                                  (agent,)).fetchall()
            finally: db.close()
            for report_hash, received_at, body_sha256, raw in rows:
                self._ingest_report(agent, report_hash, received_at, body_sha256, raw)
                self._mark_consumed(agent, report_hash)
        # Only the current latest envelope describes direct-control health;
        # historical inbox reports are job evidence, never a current heartbeat.
        return self._latest(agent)

    def _resource_receipt(self, resource: dict, state: dict, agent: str) -> dict | None:
        with self._db() as db:
            rows = db.execute('SELECT body FROM receipts ORDER BY received_at DESC,rowid DESC LIMIT 256').fetchall()
        found = []
        for row in rows:
            r = json.loads(row['body'])
            job = r['job']; source = r['receipt']
            params = job.get('params', {})
            if (r['agent_id'] != agent or not same_identity(params.get('resource_identity', {}), resource)
                    or params.get('resource_config_hash') != resource.get('config_hash')
                    or not _fresh(source.get('observed_at'), r['received_at'], self.now,
                                  self.policy.receipt_max_age)):
                continue
            restore = self._restore_params(resource)
            if restore and (type(params.get('restore_epoch')) is not int
                    or params['restore_epoch'] != restore['restore_epoch']
                    or params.get('restore_hash') != restore['restore_hash']
                    or type(r.get('manifest_issued_at')) is not int
                    or r['manifest_issued_at'] < resource['restore_recorded_at']
                    or source['observed_at'] < resource['restore_recorded_at']
                    or r['received_at'] < resource['restore_recorded_at']):
                continue
            found.append(r)
        if not found: return None
        # Remote observation time is primary; a re-delivery of an older success
        # must not mask a more recently observed failure.
        return max(found, key=lambda r: (r['receipt']['observed_at'], r['received_at']))

    @staticmethod
    def _restore_params(resource: dict) -> dict:
        """Only locally committed resource state chooses an epoch. No report can
        introduce one, and malformed local restore metadata fails closed.
        """
        if 'restore_epoch' not in resource:
            return {}
        epoch, proof, recorded = (resource.get('restore_epoch'), resource.get('restore_hash'),
                                   resource.get('restore_recorded_at'))
        if (type(epoch) is not int or not 1 <= epoch <= 2**53 - 1
                or not isinstance(proof, str) or not HEX64.fullmatch(proof)
                or type(recorded) is not int or recorded < 0):
            raise CollectorError('invalid_local_restore_epoch')
        return {'restore_epoch': epoch, 'restore_hash': proof}

    def _base(self, resource: dict, state: dict) -> dict:
        return {'verified': True, 'received_at': self.now, 'observed_at': self.now,
                'incident_id': state.get('incident_id'), 'generation': resource['generation'],
                'config_hash': resource['config_hash'], 'identity': identity(resource)}

    def _scoped_recipe(self, name: str, state: dict, resource: dict) -> dict | None:
        result = self.recipe(name, state)
        if (not result or result['ok'] is not True
                or not same_identity(result.get('identity', {}), resource)
                or result.get('config_hash') != resource['config_hash']
                or result.get('incident_id') != state.get('incident_id')):
            return None
        return {**result, **self._base(resource, state), 'observed_at': result['observed_at']}

    def publish(self, state: dict, agent: str) -> dict:
        ownership = None
        if (self.options.get('dns_ownership_refresh') is True and agent == self.policy.agent_id
                and self.config.get('agents', {}).get(agent, {}).get('enabled') is True):
            # A pending effect wins before any slow protected metadata read.
            with self.publication_lock():
                self._refresh()
                with self._db() as db:
                    pending = self._pending_iran_manifest(db, agent)
                if pending is not None: return pending
            try:
                from .dns_ownership import DnsOwnershipBridge
                bridge = DnsOwnershipBridge(self.config, runner=self.runner,
                    clock=lambda: int(self.clock()), allow_test_paths=self.allow_test_paths)
                ownership = (bridge, bridge.prepare_handoff(state))
            except Exception:
                # An enrolled dynamic guest has no static fallback. Missing
                # data remains a failed DNS check, never a renewed old source.
                ownership = None
        with self.publication_lock():
            self._refresh()
            with self._db() as db:
                pending = self._pending_iran_manifest(db, agent)
            if pending is not None:
                # Do not expose/re-expose a mutation here, even after a crash
                # before its original file write. Only its authorized publisher
                # owns that write; an expired/unknown effect still blocks probes.
                return pending
            return self._publish_probes(state, agent, ownership=ownership)

    def _publish_probes(self, state: dict, agent: str, *, ownership=None) -> dict:
        self._refresh()
        resources = [state['active']]
        if state.get('candidate'): resources.append(state['candidate'])
        resource = max(resources, key=lambda r: r['generation'])
        if not isinstance(resource.get('config_hash'), str) or not HEX64.fullmatch(resource['config_hash']):
            raise CollectorError('bundle_hash_required')
        port, ttl = self.options.get('probe_port', 443), self.options.get('manifest_ttl', 180)
        if type(port) is not int or port not in (22, 443): raise CollectorError('probe_port')
        if type(ttl) is not int or not 30 <= ttl <= 300: raise CollectorError('manifest_ttl')
        with self._db() as db:
            db.execute('BEGIN IMMEDIATE')
            generation, revision = self._next_manifest_scope(db, agent, resource)
            jobs = []
            for target in resources:
                address = target.get('ipv4')
                if address is None and same_identity(target, self.config.get('active', {})):
                    address = self.config.get('binding', {}).get('ipv4')
                if address is None:
                    try:
                        resolved = self._provider().probe_target(identity(target))
                        if (isinstance(resolved, dict)
                                and same_identity(resolved.get('identity', {}), target)):
                            address = resolved.get('ipv4')
                    except Exception:
                        pass
                try: ip = ipaddress.ip_address(address or '')
                except ValueError: continue
                if ip.version != 4 or not ip.is_global: continue
                provider_id = target['provider_id']
                if not isinstance(provider_id, str) or not provider_id.isdecimal(): continue
                restore = self._restore_params(target)
                if restore and self.now < target['restore_recorded_at']:
                    raise CollectorError('restore_epoch_is_future')
                jobs.append({'id': f'probe-{provider_id}-{revision}', 'op': 'probe',
                             'target': {'host': str(ip), 'port': port, 'provider_id': provider_id},
                             'params': {'resource_identity': identity(target),
                                        'resource_config_hash': target['config_hash'],
                                        'critical_consumers': list(self.policy.critical_consumers), **restore}})
                if ownership is not None and target is resources[0]:
                    source = ownership[1]['source']
                    if (canonical_json(source['identity']) == canonical_json(identity(target))
                            and source['binding']['config_hash'] == target['config_hash']
                            and source['ipv4'] == str(ip) and source['agent_id'] == agent):
                        jobs[-1]['params']['dns_ownership'] = copy.deepcopy(ownership[1])
            if ownership is not None:
                try:
                    ownership[0].guard_handoff(ownership[1], state)
                except Exception:
                    ownership = None
                    for job in jobs: job['params'].pop('dns_ownership', None)
            manifest = {'agent_id': agent, 'incident_id': state.get('incident_id') or f'observe-{resource["generation"]}',
                        'generation': generation, 'revision': revision,
                        'config_hash': resource['config_hash'], 'jobs_hash': manifest_hash(jobs),
                        'issued_at': self.now, 'expires_at': self.now+ttl, 'jobs': jobs}
            validate_manifest(manifest, agent, self.now)
            self._save_manifest(db, manifest)
            # Commit history BEFORE exposure: no accepted receipt can refer to
            # a manifest that was not durably authorized locally.
        if ownership is not None and any('dns_ownership' in job['params'] for job in jobs):
            try:
                ownership[0].guard_handoff(ownership[1], state)
            except Exception:
                raise CollectorError('dns_ownership_changed_before_exposure') from None
        _atomic(self.root/f'manifest-{agent}.json', manifest)
        return manifest

    def collect(self, payload: dict) -> dict:
        self._refresh()
        state = payload['state']
        active = state['active']; resource = state.get('candidate') or active
        identity(active); identity(resource)
        agent = self.policy.agent_id
        binding = self.config.get('agents', {}).get(agent, {})
        enabled = binding.get('enabled') is True and IDENTIFIER.fullmatch(agent)
        evidence: dict = {'options': [], 'critical_receipts': []}
        report = None
        if enabled:
            try: report = self._ingest(agent)
            except Exception: report = None
        independent = self.recipe('independent_control', state)
        control_ok = bool(independent and independent['ok'] is True
                          and independent.get('relay_independent') is True)
        controls = report['report'].get('direct_control_tcp') if report else None
        direct = control_ok and isinstance(controls, list) and bool(controls) and all(v is True for v in controls)
        for key, target in [('health', active), ('candidate_health', state.get('candidate'))]:
            if target is None: continue
            source = self._resource_receipt(target, state, agent)
            if source is None: continue
            raw = source['receipt']; checks = raw['checks']; detail = checks.get('adapter', {})
            if not isinstance(detail, dict): continue
            base = {**self._base(target, state), 'received_at': source['received_at'],
                    'observed_at': raw['observed_at'], 'agent_id': agent,
                    'incident_id': None if source['incident_id'].startswith('observe-') else source['incident_id']}
            if 'restore_epoch' in target:
                # _resource_receipt already matched exact locally persisted
                # manifest params and post-restore issue/observe/receive times.
                # Never derive these fields from a cached unrelated report.
                params = source['job']['params']
                base.update(restore_epoch=params['restore_epoch'], restore_hash=params['restore_hash'])
            # Network path health is distinct from guest/business acceptance.
            # An unavailable VM109/QGA must block promotion, never turn a
            # functioning CT113 path into an outage and trigger another buy.
            path_ok, tcp_ok = detail.get('path_probe_ok'), checks.get('candidate_tcp')
            network_probe = (path_ok and tcp_ok
                             if type(path_ok) is bool and type(tcp_ok) is bool else None)
            evidence[key] = {**base, 'direct_control': direct,
                             'host_healthy': detail.get('host_healthy'),
                             'internet_healthy': detail.get('internet_healthy'),
                             'tunnel_ok': detail.get('tunnel_ok') if type(path_ok) is bool else None,
                             'probe_ok': network_probe}
            if same_identity(target, resource):
                critical = detail.get('critical_consumers', {})
                if not isinstance(critical, dict): critical = {}
                evidence['critical_receipts'] = [{**base, 'consumer': name,
                    'ok': raw['ok'] is True and critical.get(name) is True} for name in self.policy.critical_consumers]
        for name in ('backup', 'fencing', 'registry', 'cleanup'):
            result = self._scoped_recipe(name, state, resource)
            if result is not None:
                if name == 'registry':
                    for output in ('ttl', 'active_refs'):
                        if isinstance(result.get(output), dict):
                            evidence[output] = {**result[output], **self._base(resource, state),
                                                'observed_at': result['observed_at']}
                else: evidence[name] = result
        # CREATE needs proof that the exact active generation *can* be fenced,
        # not proof it has already been stopped. Requiring actual fencing here
        # would either deadlock candidate creation or power off the only relay
        # prematurely. This read-only capability is never a fencing receipt.
        capability = self._scoped_recipe('fencing_capability', state, active)
        if capability is not None:
            evidence['fencing_capability'] = capability
        owner = self.recipe('owner_binding', state)
        owner_ok = bool(owner and owner['ok'] and all(str(owner.get(k, '')) == str(getattr(self.policy, k))
                                                   for k in ('owner_id', 'service_id', 'project_id')))
        budget = self.recipe('budget_authorization', state)
        budget_ok = bool(budget and budget['ok'] and budget.get('currency') == self.policy.budget_currency
                         and budget.get('policy_sha256') == hashlib.sha256(canonical_json(self.config.get('policy', {}))).hexdigest())
        image = self.recipe('image_compatibility', state)
        provider_ok = False
        try:
            provider = self._provider()
            quotes = provider.quotes()
            inventory = provider.inventory(identity(active))
            if not isinstance(inventory, dict): raise CollectorError('inventory_shape')
            evidence['inventory'] = inventory
            provider_ok = (inventory.get('provider_access') is True
                           and inventory.get('complete') is True
                           and inventory.get('project_id') == self.policy.project_id
                           and inventory.get('active_identity') == identity(active))
            evidence['inventory'] = {**inventory, 'verified': provider_ok,
                                     'received_at': self.now, 'observed_at': self.now}
            live_image = bool(image and image['ok'] and
                              provider.image_compatible(str(image.get('image_id', ''))))
            for quote in quotes:
                compatible = bool(live_image and image.get('architecture') == 'x86'
                                  and str(image.get('image_id', '')).isdecimal()
                                  and quote.architecture == 'x86')
                evidence['options'].append({'verified': True, 'received_at': self.now,
                    'observed_at': quote.observed_at, 'provider': 'hetzner',
                    'project_id': self.policy.project_id, 'owner_id': self.policy.owner_id,
                    'service_id': self.policy.service_id, 'country': quote.country,
                    'location': quote.location, 'server_type': quote.server_type,
                    'currency': quote.currency, 'available': True, 'compatible': compatible,
                    'architecture': quote.architecture, 'config_hash': active['config_hash'],
                    'monthly_gross_micro': quote.predicted_monthly_micro,
                    'trial_max_gross_micro': quote.hourly_micro*((self.policy.candidate_deadline+3599)//3600),
                    'image': str(image.get('image_id', '')) if image else ''})
        except Exception:
            evidence['options'] = []
            evidence.pop('inventory', None)
        checks = {'owner_bound': owner_ok, 'independent_control': control_ok,
                  'restore_current': bool(evidence.get('backup', {}).get('durable') is True
                                          and evidence.get('backup', {}).get('restore_verified') is True),
                  'fencing_enforced': bool(capability
                      and capability.get('enforcement_ready') is True
                      and capability.get('capability_verified') is True),
                  'registry_complete': evidence.get('active_refs', {}).get('complete') is True,
                  'provider_access': provider_ok, 'budget_authorized': budget_ok}
        # Provisioning is blocked too if no real cutover implementation exists.
        # A hash-pinned but untested configuration entry is not readiness proof.
        readiness = self.recipe('cutover_readiness', state)
        cutover = self.options.get('recipes', {}).get('cutover', {})
        cutover_pinned = False
        try:
            path = pinned_file(cutover['path'], root_owned=False, executable=True)
            cutover_pinned = (path.stat().st_uid == os.geteuid()
                              and hashlib.sha256(path.read_bytes()).hexdigest() == cutover.get('sha256'))
        except Exception:
            pass
        checks['cutover_ready'] = bool(readiness and readiness['ok'] and cutover_pinned
                                      and readiness.get('full_promotion_verified') is True)
        evidence['preflight'] = {'verified': True, 'received_at': self.now, 'observed_at': self.now,
            'owner_id': self.policy.owner_id, 'service_id': self.policy.service_id,
            'project_id': self.policy.project_id, 'checks': checks,
            'ready': all(checks.values())}
        clock = self.recipe('clock', state)
        if clock and clock['ok'] and clock.get('local_clock_verified') is True:
            evidence['clock'] = {'verified': True, 'received_at': self.now,
                                 'observed_at': clock['observed_at'], 'local_clock_verified': True}
        published = self.publish(state, agent) if enabled else None
        return {'schema': 'servernet.relay.evidence.v1', 'evidence': evidence,
                'probe_revision': published['revision'] if published else None}

    def effect(self, action: str, payload: dict) -> dict:
        self._refresh()
        if action not in ('PROMOTE', 'RECONCILE_PROMOTION'):
            return self._provider().execute(action, payload)
        intent = payload.get('intent', {})
        journal = Journal(self.root/'controller.sqlite3')
        with journal.transaction() as db:
            row = db.execute('SELECT body,receipt FROM actions WHERE id=?', (intent.get('id'),)).fetchone()
            if row is None or row['body'] != canonical(intent) or row['receipt'] is not None:
                raise CollectorError('uncommitted_promotion')
            state = journal._read(db)
        if (action != intent.get('kind') or (action.startswith('RECONCILE_')
                and intent.get('payload', {}).get('read_only') is not True)):
            raise CollectorError('promotion_action_mismatch')
        result = self.recipe('cutover', state, action=action, payload=payload)
        if not result or not isinstance(result.get('effect'), dict):
            raise CollectorError('cutover_not_implemented')
        envelope = result['effect']; receipt = envelope.get('receipt', {})
        if (envelope.get('schema') != 'servernet.relay.effect.v1'
                or receipt.get('intent_id') != intent['id'] or receipt.get('kind') != action
                or receipt.get('verified') is not True or receipt.get('received_at') != self.now):
            raise CollectorError('cutover_receipt_invalid')
        return envelope


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', type=Path, required=True)
    parser.add_argument('action')
    args = parser.parse_args()
    os.umask(0o077)
    try:
        config = decode_json(private_file(args.config))
        raw = sys.stdin.buffer.read(131073)
        if len(raw) > 131072: raise CollectorError('request_size')
        # Worker input can exceed protocol's 64 KiB due to bounded evidence.
        payload = json.loads(raw)
        adapter = Adapter(config)
        result = adapter.collect(payload) if args.action == 'collect' else adapter.effect(args.action, payload)
        print(json.dumps(result, separators=(',', ':'), allow_nan=False))
        return 0
    except Exception:
        print('{"ok":false,"error":"controller_integration_unavailable"}')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
