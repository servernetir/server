"""Protected application ownership reader and canonical private artifact builder.

The only source is the fixed pinned PHP ``dns_ownership`` operation. No caller
receipt, zone, catalog, clock, SQL, URL or command can replace that read. The
enrollment selects existing owner canary IDs under the protected relay service's
authority; it does not assert CdnZone.service_id equals that relay service.

This component does NOT refresh a DNS guest, change static pins, publish jobs,
install SSH transports or establish full catalogue/consumer/global readiness.
Both the sanitized canonical source receipt and artifact are retained immutable
with mode0600, outside the web root. Future signed data transport remains separate.
"""
from __future__ import annotations

import copy
import hashlib
import ipaddress
import os
from pathlib import Path
import sqlite3
import time

from . import core, dns_consumer_probe as dns
from .agent import _bounded_process
from .production_hooks import _identity, _private_bytes
from .provider_adapter import CredentialBridge, ProviderEffectError
from .protocol import HEX64, IDENTIFIER, canonical_json, decode_json
from .backup_client import _directory, trusted_file


ENROLLMENT = 'servernet.relay.dns-ownership.enrollment.v1'
SOURCE = 'servernet.relay.dns-ownership-source.v1'
PUBLICATION = 'servernet.relay.dns-ownership-publication.v1'
SOURCE_FIELDS = {'schema', 'ok', 'read_only', 'internal_protected', 'agent_id', 'binding',
                 'enrollment_sha256', 'identity', 'ipv4', 'observed_at', 'received_at', 'catalog', 'zones'}


class DnsOwnershipError(ProviderEffectError):
    pass


def require(value):
    if not value:
        raise DnsOwnershipError('dns_ownership_unavailable')


def load_enrollment(config, *, allow_test_paths=False):
    reference = config.get('dns_ownership')
    require(isinstance(reference, dict) and set(reference) == {'path', 'sha256'}
            and isinstance(reference['sha256'], str) and HEX64.fullmatch(reference['sha256']))
    raw = _private_bytes(reference['path'], limit=8192, expected_sha256=reference['sha256'],
                         allow_test_paths=allow_test_paths)
    value = decode_json(raw)
    require(raw == canonical_json(value) and isinstance(value, dict)
            and set(value) == {'schema', 'agent_id', 'binding', 'zone_ids'}
            and value['schema'] == ENROLLMENT and isinstance(value['agent_id'], str)
            and IDENTIFIER.fullmatch(value['agent_id'])
            and value['agent_id'] == config.get('policy', {}).get('agent_id'))
    binding = dns._binding(value['binding'])
    require(all(str(binding[k]) == str(config.get('policy', {}).get(k))
                for k in ('owner_id', 'service_id', 'project_id'))
            and all(type(config.get('binding', {}).get(k)) is int
                    and binding[k] == config['binding'][k] for k in ('owner_id', 'service_id')))
    ids = value['zone_ids']
    require(isinstance(ids, list) and 1 <= len(ids) <= 3
            and all(type(item) is int and 0 < item <= 2**53-1 for item in ids)
            and ids == sorted(set(ids)))
    return value


class DnsOwnershipBridge:
    """Actual fixed metadata pipe; no raw/source-observation injection API."""
    def __init__(self, config, *, runner=_bounded_process, clock=lambda: int(time.time()),
                 allow_test_paths=False):
        self.config = copy.deepcopy(config)
        self.clock, self.last_now = clock, None
        self.allow_test_paths = allow_test_paths
        self.enrollment = load_enrollment(self.config, allow_test_paths=allow_test_paths)
        self.pipe = CredentialBridge(self.config, runner=runner)
        self.root = _directory(self.config['state_dir'], uid=os.geteuid(), allow_test_paths=allow_test_paths)
        self.artifacts = _directory(str(self.root/'dns-ownership'), uid=os.geteuid(),
                                    allow_test_paths=allow_test_paths)

    def _now(self):
        now = self.clock()
        require(type(now) is int and 0 < now <= 2**53-1-dns.MAX_ENROLLMENT_TTL
                and (self.last_now is None or now >= self.last_now))
        self.last_now = now
        return now

    def _active(self):
        path = trusted_file(str(self.root/'controller.sqlite3'), uid=os.geteuid(),
                            allow_test_paths=self.allow_test_paths)
        require(path.stat().st_nlink == 1)
        with sqlite3.connect(path.as_uri()+'?mode=ro', uri=True, timeout=2) as db:
            row = db.execute('SELECT body FROM state WHERE id=1').fetchone()
        require(row is not None)
        state = decode_json(row[0].encode())
        require(state.get('clock_unsafe') is False and type(state.get('last_now')) is int
                and state['last_now'] <= self._now()
                and state.get('active', {}).get('config_hash') == self.enrollment['binding']['config_hash'])
        identity = _identity(core.identity(state['active']))
        require(identity['project_id'] == self.enrollment['binding']['project_id'])
        return identity

    def _guard(self, identity):
        require(load_enrollment(self.config, allow_test_paths=self.allow_test_paths) == self.enrollment
                and self._active() == identity)

    def _source(self, value, identity, started):
        now = self._now()
        require(isinstance(value, dict) and set(value) == SOURCE_FIELDS and value['schema'] == SOURCE
                and value['ok'] is True and value['read_only'] is True and value['internal_protected'] is True
                and value['agent_id'] == self.enrollment['agent_id']
                and canonical_json(value['binding']) == canonical_json(self.enrollment['binding'])
                and value['enrollment_sha256'] == self.config['dns_ownership']['sha256']
                and canonical_json(value['identity']) == canonical_json(identity))
        address = ipaddress.IPv4Address(value['ipv4'])
        require(isinstance(value['ipv4'], str) and str(address) == value['ipv4'] and address.is_global)
        observed, received = value['observed_at'], value['received_at']
        require(type(observed) is int and type(received) is int
                and started <= observed <= received <= now and received-observed <= 20
                and now < observed + dns.MAX_ENROLLMENT_TTL)
        dns._name(value['catalog'])
        rows = value['zones']; ids = self.enrollment['zone_ids']
        require(isinstance(rows, list) and len(rows) == len(ids))
        names = {value['catalog']}
        for row, ident in zip(rows, ids):
            require(isinstance(row, dict) and set(row) == {'id', 'name'}
                    and type(row['id']) is int and row['id'] == ident)
            dns._name(row['name'])
            require(row['name'] not in names)
            names.add(row['name'])
        return copy.deepcopy(value)

    def _save(self, name, raw):
        path = self.artifacts/name
        if path.exists() or path.is_symlink():
            require(_private_bytes(path, limit=8192, allow_test_paths=self.allow_test_paths) == raw)
            return path
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, 'wb') as out:
            require(out.write(raw) == len(raw)); out.flush(); os.fsync(out.fileno())
        directory = os.open(self.artifacts, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
        return path

    def build(self):
        """Read current actual ownership and retain its original bounded clocks."""
        try:
            identity = self._active(); self._guard(identity); started = self._now()
            source = self._source(self.pipe._exchange({'op': 'dns_ownership'}), identity, started)
            self._guard(identity)
            raw = canonical_json(source); digest = hashlib.sha256(raw).hexdigest()
            artifact = dict(schema=dns.OWNERSHIP_SCHEMA, agent_id=source['agent_id'], binding=source['binding'],
                source_receipt_sha256=digest, observed_at=source['observed_at'],
                expires_at=source['observed_at']+dns.MAX_ENROLLMENT_TTL,
                catalog=source['catalog'], zones=source['zones'])
            require(self._now() < artifact['expires_at'])
            source_path = self._save('source-'+digest+'.json', raw)
            encoded = canonical_json(artifact); artifact_hash = hashlib.sha256(encoded).hexdigest()
            self._guard(identity); require(self._now() < artifact['expires_at'])
            path = self._save('ownership-'+artifact_hash+'.json', encoded)
            self._guard(identity); require(self._now() < artifact['expires_at'])
            return dict(schema=PUBLICATION, read_only=True, authenticated_source=True,
                observed_at=artifact['observed_at'], expires_at=artifact['expires_at'],
                artifact=artifact, path=str(path), sha256=artifact_hash,
                source_path=str(source_path), source_receipt_sha256=digest)
        except Exception:
            raise DnsOwnershipError('dns_ownership_unavailable') from None
