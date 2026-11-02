"""Scoped Cloudflare DNS migration with a durable per-record write journal.

Only content is PATCHed, after comparing a fresh full record with the saved
plan. Cloudflare does not document an atomic conditional DNS edit/If-Match
contract: this is a local serialized compare-before-write, NOT a cross-writer
atomic CAS. Post-write metadata drift is a conflict and is never overwritten.
Unknown writes are reconciled with GET only and are never retried blindly.

The private ``cloudflare`` config contains ``zones`` [{zone_id, record_ids,
authoritative_ips}], ``old_ipv4`` and optional ``old_ipv6``. The existing pinned
PHP CredentialBridge provides cloudflare_token via private stdin/stdout.
An explicit core PROMOTE intent is required before every write. A trusted local
cutover coordinator may inject an authorizer callback; JSON approval flags are
never accepted. No I/O occurs on import. All tests inject transport/clock/DNS.

TTL sources (checked 2026-11-02):
https://developers.cloudflare.com/dns/manage-dns-records/reference/ttl/
https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/edit/
Auto (API ttl=1) is 300s. It is not a guarantee of every client's cache expiry.
Proxied DNS answers expose Cloudflare anycast, not the origin. Such answers can
never prove origin migration; this module keeps that acceptance gate false.
"""
from __future__ import annotations

import contextlib
import fcntl
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import socket
import sqlite3
import ssl
import stat
import struct
import time
from typing import Callable
import urllib.error
import urllib.parse
import urllib.request

from . import core
from .provider_adapter import CredentialBridge
from .protocol import canonical_json

BASE = 'https://api.cloudflare.com/client/v4'
ID = re.compile(r'\A[0-9a-f]{32}\Z')
MAX_RESPONSE = 4*1024*1024


class CloudflareError(core.RecoveryError):
    pass


class UnknownWrite(CloudflareError):
    pass


def _id(value) -> str:
    if not isinstance(value, str) or not ID.fullmatch(value):
        raise CloudflareError('invalid_cloudflare_id')
    return value


def _ip(value, version: int) -> str:
    try: address = ipaddress.ip_address(value)
    except (ValueError, TypeError): raise CloudflareError('invalid_public_address') from None
    if address.version != version or not address.is_global:
        raise CloudflareError('invalid_public_address')
    return str(address)


def _hash(value) -> str:
    return hashlib.sha256(canonical_json(value)).hexdigest()


def _stable(record: dict) -> dict:
    # modified_on is a provider-managed consequence of editing content. All
    # other returned metadata, including settings/tags/comment/meta, is guarded.
    return {k: v for k, v in record.items() if k != 'modified_on'}


def _changed(old: dict, content: str) -> dict:
    return {**_stable(old), 'content': content}


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise CloudflareError('cloudflare_redirect_refused')


class HttpTransport:
    def __init__(self, token: str, timeout: float = 5):
        if not isinstance(token, str) or not token or any(c.isspace() for c in token):
            raise CloudflareError('cloudflare_token_unavailable')
        self.token, self.timeout = token, timeout
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPSHandler(context=ssl.create_default_context()), _NoRedirect())

    def __call__(self, method: str, path: str, payload: dict | None):
        if method not in ('GET', 'PATCH') or not path.startswith('/zones/') or '..' in path:
            raise CloudflareError('cloudflare_request_scope')
        parsed = urllib.parse.urlsplit(path)
        if parsed.scheme or parsed.netloc or parsed.fragment:
            raise CloudflareError('cloudflare_request_origin')
        raw = None if payload is None else canonical_json(payload)
        req = urllib.request.Request(BASE+path, raw, method=method, headers={
            'Authorization': 'Bearer '+self.token, 'Content-Type': 'application/json',
            'Accept': 'application/json', 'User-Agent': 'ServerNet-relay-recovery/1'})
        try:
            with self.opener.open(req, timeout=self.timeout) as response:
                status, body = response.status, response.read(MAX_RESPONSE+1)
        except urllib.error.HTTPError as exc:
            status, body = exc.code, exc.read(MAX_RESPONSE+1)
        except CloudflareError:
            raise
        except (OSError, TimeoutError, urllib.error.URLError):
            raise UnknownWrite('cloudflare_transport_unknown') from None
        if len(body) > MAX_RESPONSE: raise CloudflareError('cloudflare_response_size')
        try: result = json.loads(body)
        except (ValueError, UnicodeError): raise CloudflareError('cloudflare_response_json') from None
        if not isinstance(result, dict): raise CloudflareError('cloudflare_response_shape')
        return status, result


def _name(data: bytes, offset: int, visited=None):
    visited = set() if visited is None else visited
    if len(visited) > 16 or offset in visited: raise CloudflareError('dns_compression')
    visited.add(offset)
    labels, cursor, end = [], offset, None
    while True:
        if cursor >= len(data): raise CloudflareError('dns_truncated')
        length = data[cursor]; cursor += 1
        if length == 0: return '.'.join(labels).lower(), end or cursor
        if length & 0xc0 == 0xc0:
            if cursor >= len(data): raise CloudflareError('dns_truncated')
            pointer = ((length & 0x3f) << 8) | data[cursor]
            suffix, _ = _name(data, pointer, visited)
            labels.append(suffix)
            return '.'.join(labels).lower(), end or cursor+1
        if length > 63 or cursor+length > len(data): raise CloudflareError('dns_label')
        try: labels.append(data[cursor:cursor+length].decode('ascii'))
        except UnicodeError: raise CloudflareError('dns_label') from None
        cursor += length


def authoritative_query(server: str, name: str, record_type: str, timeout=3) -> dict:
    """Query one explicitly configured authoritative public IP via DNS/TCP."""
    try:
        address = ipaddress.ip_address(server)
        if not address.is_global: raise ValueError()
        encoded = name.rstrip('.').encode('idna').decode('ascii').lower()
        labels = encoded.split('.')
        if len(encoded) > 253 or any(not 1 <= len(x) <= 63 for x in labels): raise ValueError()
        qtype = {'A': 1, 'AAAA': 28}[record_type]
        ident = secrets.randbelow(65536)
        qname = b''.join(bytes([len(x)])+x.encode() for x in labels)+b'\0'
        request = struct.pack('!6H', ident, 0, 1, 0, 0, 0)+qname+struct.pack('!2H', qtype, 1)
        with socket.create_connection((str(address), 53), timeout=timeout) as connection:
            connection.settimeout(timeout)
            connection.sendall(struct.pack('!H', len(request))+request)
            def read(count):
                chunks = bytearray()
                while len(chunks) < count:
                    part = connection.recv(count-len(chunks))
                    if not part: raise CloudflareError('dns_truncated')
                    chunks.extend(part)
                return bytes(chunks)
            size = struct.unpack('!H', read(2))[0]
            if not 12 <= size <= 16384: raise CloudflareError('dns_size')
            response = read(size)
        rid, flags, qd, an, _, _ = struct.unpack('!6H', response[:12])
        if rid != ident or not flags & 0x8000 or not flags & 0x0400 or flags & 0x020f or qd != 1:
            raise CloudflareError('dns_not_authoritative_success')
        question, offset = _name(response, 12)
        if question != encoded or response[offset:offset+4] != struct.pack('!2H', qtype, 1):
            raise CloudflareError('dns_question_mismatch')
        offset += 4
        answers, ttls = [], []
        for _ in range(an):
            hostname, offset = _name(response, offset)
            if offset+10 > len(response): raise CloudflareError('dns_truncated')
            typ, cls, ttl, length = struct.unpack('!2HIH', response[offset:offset+10]); offset += 10
            value = response[offset:offset+length]; offset += length
            if len(value) != length: raise CloudflareError('dns_truncated')
            if hostname == encoded and typ == qtype and cls == 1:
                if len(value) != (4 if typ == 1 else 16): raise CloudflareError('dns_address')
                answers.append(str(ipaddress.ip_address(value))); ttls.append(ttl)
        return {'authoritative': True, 'name': encoded, 'type': record_type,
                'addresses': sorted(set(answers)), 'ttl': min(ttls) if ttls else None}
    except Exception:
        return {'authoritative': False, 'addresses': [], 'ttl': None}


class Store:
    def __init__(self, root: Path):
        info = root.lstat()
        if (not stat.S_ISDIR(info.st_mode) or root.is_symlink()
                or info.st_uid != os.geteuid() or info.st_mode & 0o077):
            raise CloudflareError('private_cloudflare_state')
        self.path, self.lock = root/'cloudflare-effects.sqlite3', root/'cloudflare-effects.lock'
        with self.transaction() as db:
            db.executescript('''
              CREATE TABLE IF NOT EXISTS plans(id TEXT PRIMARY KEY, body TEXT NOT NULL);
              CREATE TABLE IF NOT EXISTS changes(id TEXT PRIMARY KEY, plan_id TEXT NOT NULL,
                zone_id TEXT NOT NULL, record_id TEXT NOT NULL, direction TEXT NOT NULL,
                permit_id TEXT NOT NULL, phase TEXT NOT NULL, body TEXT NOT NULL, receipt TEXT,
                UNIQUE(plan_id,zone_id,record_id,direction));
            ''')

    @contextlib.contextmanager
    def transaction(self):
        fd = os.open(self.lock, os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW, 0o600)
        db = None
        try:
            info = os.fstat(fd)
            if info.st_uid != os.geteuid() or info.st_mode & 0o077: raise CloudflareError('private_cloudflare_lock')
            fcntl.flock(fd, fcntl.LOCK_EX|fcntl.LOCK_NB)
            if self.path.exists() or self.path.is_symlink():
                info = self.path.lstat()
                if (not stat.S_ISREG(info.st_mode) or self.path.is_symlink()
                        or info.st_uid != os.geteuid() or info.st_mode & 0o077):
                    raise CloudflareError('private_cloudflare_database')
            old = os.umask(0o077)
            try: db = sqlite3.connect(self.path, timeout=0, isolation_level=None)
            finally: os.umask(old)
            db.row_factory = sqlite3.Row
            db.execute('PRAGMA synchronous=FULL'); db.execute('BEGIN IMMEDIATE')
            yield db
            if db.in_transaction: db.commit()
        except Exception:
            if db and db.in_transaction: db.rollback()
            raise
        finally:
            if db: db.close()
            os.close(fd)


class CloudflareEffects:
    def __init__(self, config: dict, *, bridge=None, transport=None,
                 now: Callable = time.time, authorizer: Callable | None = None,
                 dns: Callable = authoritative_query, provider=None):
        self.config, self.now, self.dns = config, now, dns
        self.entry = config.get('cloudflare', {})
        self.bridge, self.transport = bridge or CredentialBridge(config), transport
        self.authorizer = authorizer
        self.provider, self.address_proof = provider, None
        zones = self.entry.get('zones')
        if not isinstance(zones, list) or not zones or len(zones) > 100:
            raise CloudflareError('explicit_cloudflare_zones_required')
        self.zones = {}
        for zone in zones:
            zid = _id(zone.get('zone_id'))
            if zid in self.zones: raise CloudflareError('duplicate_cloudflare_zone')
            records = zone.get('record_ids')
            if not isinstance(records, list) or not records or len(records) > 10000:
                raise CloudflareError('explicit_cloudflare_records_required')
            if len(set(_id(r) for r in records)) != len(records): raise CloudflareError('duplicate_cloudflare_record')
            servers = zone.get('authoritative_ips', [])
            if not isinstance(servers, list) or len(servers) > 8: raise CloudflareError('authoritative_servers')
            for server in servers:
                try: valid = ipaddress.ip_address(server).is_global
                except ValueError: valid = False
                if not valid: raise CloudflareError('authoritative_servers')
            self.zones[zid] = {'record_ids': records, 'authoritative_ips': servers}
        self.old = {'A': _ip(self.entry.get('old_ipv4'), 4)}
        if self.entry.get('old_ipv6'): self.old['AAAA'] = _ip(self.entry['old_ipv6'], 6)
        self.store = Store(Path(config['state_dir']))

    def _request(self, method: str, path: str, payload=None):
        if self.transport is None:
            credentials = self.bridge.request('credentials')
            self.transport = HttpTransport(credentials.get('cloudflare_token'))
        status, body = self.transport(method, path, payload)
        if status != 200 or not isinstance(body, dict) or body.get('success') is not True or body.get('errors'):
            raise CloudflareError('cloudflare_api_failed')
        return body

    def _record(self, zone: str, record: str) -> dict:
        value = self._request('GET', f'/zones/{_id(zone)}/dns_records/{_id(record)}').get('result')
        if not isinstance(value, dict) or value.get('id') != record:
            raise CloudflareError('cloudflare_record_identity')
        if value.get('type') not in ('A', 'AAAA') or not isinstance(value.get('name'), str):
            raise CloudflareError('cloudflare_record_type')
        _ip(value.get('content'), 4 if value['type'] == 'A' else 6)
        if type(value.get('proxied')) is not bool or type(value.get('ttl')) is not int:
            raise CloudflareError('cloudflare_record_metadata')
        return value

    def discover(self) -> dict:
        records, matches = {}, []
        for zone in self.zones:
            seen, page, total = set(), 1, None
            while True:
                body = self._request('GET', f'/zones/{zone}/dns_records?page={page}&per_page=100')
                info, values = body.get('result_info'), body.get('result')
                if (not isinstance(info, dict) or not isinstance(values, list)
                        or type(info.get('total_pages')) is not int or not 1 <= info['total_pages'] <= 500
                        or type(info.get('page')) is not int or info['page'] != page or len(values) > 100):
                    raise CloudflareError('cloudflare_pagination_incomplete')
                if total is not None and total != info['total_pages']:
                    raise CloudflareError('cloudflare_pagination_changed')
                total = info['total_pages']
                for record in values:
                    if not isinstance(record, dict): raise CloudflareError('cloudflare_record_shape')
                    rid = _id(record.get('id'))
                    if rid in seen: raise CloudflareError('cloudflare_duplicate_record')
                    seen.add(rid); records[(zone,rid)] = record
                    if record.get('content') in self.old.values():
                        matches.append({'zone_id': zone, 'record_id': rid,
                                        'type': record.get('type'), 'name': record.get('name')})
                if page >= total: break
                if not values: raise CloudflareError('cloudflare_empty_page')
                page += 1
        return {'configured_scope_complete': True, 'records': records, 'old_references': matches}

    def plan(self, old_identity: dict, new_identity: dict, new_ipv4: str,
             new_ipv6: str | None = None) -> dict:
        old, new = core.identity(old_identity), core.identity(new_identity)
        if old == new or old['provider'] != 'hetzner' or new['provider'] != 'hetzner' or old['project_id'] != new['project_id']:
            raise CloudflareError('cloudflare_plan_identity')
        replacements = {'A': _ip(new_ipv4, 4)}
        if new_ipv6 is not None: replacements['AAAA'] = _ip(new_ipv6, 6)
        if any(replacements.get(k) == v for k,v in self.old.items()): raise CloudflareError('unchanged_relay_address')
        discovery = self.discover()
        entries, blockers = [], []
        for match in discovery['old_references']:
            zid, rid = match['zone_id'], match['record_id']
            if rid not in self.zones[zid]['record_ids']:
                blockers.append({'code': 'unregistered_old_reference', 'zone_id': zid, 'record_id': rid})
        for zid, zone in self.zones.items():
            for rid in zone['record_ids']:
                if (zid,rid) not in discovery['records']:
                    blockers.append({'code': 'registered_record_missing', 'zone_id': zid, 'record_id': rid}); continue
                record = self._record(zid, rid)
                typ = record['type']
                if record.get('content') != self.old.get(typ):
                    blockers.append({'code': 'registered_record_not_old_relay', 'zone_id': zid, 'record_id': rid}); continue
                if typ not in replacements:
                    blockers.append({'code': 'ipv6_replacement_missing', 'zone_id': zid, 'record_id': rid}); continue
                entries.append({'zone_id': zid, 'record_id': rid, 'before': record,
                                'new_content': replacements[typ]})
        plan = {'schema': 'servernet.relay.cloudflare.plan.v1', 'created_at': int(self.now()),
                'registry_hash': _hash(self.entry), 'old_identity': old, 'new_identity': new,
                'old_addresses': self.old, 'new_addresses': replacements, 'entries': entries,
                'blockers': blockers, 'configured_scope_complete': True,
                'ready': bool(entries) and not blockers, 'cas_atomic': False}
        digest = _hash(plan)
        with self.store.transaction() as db:
            db.execute('INSERT OR IGNORE INTO plans VALUES(?,?)', (digest, canonical_json(plan).decode()))
        return {'plan_id': digest, **plan}

    def _load(self, plan_id: str) -> dict:
        if not isinstance(plan_id, str) or not re.fullmatch('[0-9a-f]{64}', plan_id):
            raise CloudflareError('cloudflare_plan_digest')
        with self.store.transaction() as db:
            row = db.execute('SELECT body FROM plans WHERE id=?', (plan_id,)).fetchone()
        if row is None: raise CloudflareError('cloudflare_plan_missing')
        plan = json.loads(row['body'])
        if _hash(plan) != plan_id or plan['registry_hash'] != _hash(self.entry):
            raise CloudflareError('cloudflare_plan_changed')
        return plan

    def _authorize(self, plan: dict, permit_id: str, direction: str):
        if self.authorizer is not None:
            # A callable is injected by trusted installed Python code, not by a
            # network request/config approval flag. It must raise on rejection.
            if self.authorizer(plan, permit_id, direction) is not True:
                raise CloudflareError('cloudflare_permit_rejected')
            return
        if self.config.get('policy', {}).get('enabled') is not True:
            raise CloudflareError('cloudflare_effects_disabled')
        path = Path(self.config['state_dir'])/'controller.sqlite3'
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or path.is_symlink() or info.st_uid != os.geteuid() or info.st_mode & 0o077:
            raise CloudflareError('cloudflare_core_journal_private')
        db = sqlite3.connect(path.as_uri()+'?mode=ro', uri=True)
        try:
            action = db.execute('SELECT body,receipt FROM actions WHERE id=?', (permit_id,)).fetchone()
            row = db.execute('SELECT body FROM state WHERE id=1').fetchone()
        finally: db.close()
        if action is None or row is None or action[1] is not None:
            raise CloudflareError('cloudflare_committed_promotion_required')
        intent, state = json.loads(action[0]), json.loads(row[0])
        if (intent.get('kind') != 'PROMOTE' or state.get('phase') != 'CUTOVER'
                or state.get('pending_action') != permit_id
                or state.get('clock_unsafe') is not False
                or not intent['created_at'] <= int(self.now()) <= intent['expires_at']
                or not core.same_identity(intent['payload'].get('old_identity', {}), plan['old_identity'])
                or not core.same_identity(intent['payload'].get('identity', {}), plan['new_identity'])
                or not core.same_identity(state.get('active', {}), plan['old_identity'])
                or not core.same_identity(state.get('candidate', {}), plan['new_identity'])):
            raise CloudflareError('cloudflare_promotion_scope_or_deadline')
        proof_key = _hash([plan['old_identity'],plan['new_identity'],plan['old_addresses'],plan['new_addresses']])
        if not (self.address_proof and self.address_proof['key'] == proof_key
                and 0 <= int(self.now())-self.address_proof['at'] <= 15):
            if self.provider is None:
                from .provider_adapter import ProviderEffects
                self.provider = ProviderEffects(self.config, bridge=self.bridge)
            for ident, addresses in ((plan['old_identity'],plan['old_addresses']),
                                     (plan['new_identity'],plan['new_addresses'])):
                resolved = self.provider.probe_target(ident)
                if (not isinstance(resolved, dict) or not core.same_identity(resolved.get('identity', {}),ident)
                        or resolved.get('ipv4') != addresses.get('A')
                        or ('AAAA' in addresses and resolved.get('ipv6') != addresses['AAAA'])):
                    raise CloudflareError('cloudflare_provider_address_mismatch')
            self.address_proof = {'key': proof_key, 'at': int(self.now())}
        # Address reads can consume the entire short permit lifetime.
        if int(self.now()) > intent['expires_at']:
            raise CloudflareError('cloudflare_promotion_scope_or_deadline')

    def _receipt(self, change: dict, current: dict, api_verified: bool) -> dict:
        typ = current['type']; target = change['target']
        before = change['before']; old = change['source']
        ttl = before.get('ttl')
        effective = 300 if ttl == 1 else ttl if type(ttl) is int and 30 <= ttl <= 86400 else None
        results, observed_ttls = [], []
        for server in self.zones[change['zone_id']]['authoritative_ips']:
            answer = self.dns(server, current['name'], typ)
            verified = bool(isinstance(answer, dict) and answer.get('authoritative') is True
                            and answer.get('name') == current['name'].rstrip('.').lower()
                            and answer.get('type') == typ and target in answer.get('addresses', [])
                            and old not in answer.get('addresses', []))
            results.append(verified)
            if (isinstance(answer, dict) and answer.get('authoritative') is True
                    and type(answer.get('ttl')) is int and answer['ttl'] > 0):
                observed_ttls.append(answer['ttl'])
        if effective is not None and observed_ttls:
            effective = max(effective,max(observed_ttls))
        # Proxied anycast records require a separate HTTP/origin challenge.
        authoritative = not current['proxied'] and bool(results) and all(results)
        verified_at = int(self.now())
        return {'schema': 'servernet.relay.cloudflare.record.v1', 'zone_id': change['zone_id'],
                'record_id': change['record_id'], 'old_content': old, 'new_content': target,
                'api_verified': api_verified, 'authoritative_verified': authoritative,
                'origin_verification_required': current['proxied'], 'observed_at': verified_at,
                'ttl_known': effective is not None, 'effective_ttl': effective,
                'expires_at': verified_at+effective if effective is not None else None,
                'client_cache_expiry_guaranteed': False, 'cas_atomic': False,
                'record': current}

    def _change(self, plan_id: str, plan: dict, entry: dict, permit_id: str, direction: str) -> dict:
        zid, rid = entry['zone_id'], entry['record_id']
        with self.store.transaction() as db:
            prior = db.execute('SELECT body,phase,receipt FROM changes WHERE plan_id=? AND zone_id=? AND record_id=? AND direction=?',
                               (plan_id,zid,rid,direction)).fetchone()
        if prior is not None:
            # Reconciliation only, even when GET still shows the old value.
            return self._reconcile_change(json.loads(prior['body']))
        source, target, before = entry['before']['content'], entry['new_content'], entry['before']
        if direction == 'rollback':
            with self.store.transaction() as db:
                own = db.execute('SELECT phase,receipt,permit_id FROM changes WHERE plan_id=? AND zone_id=? AND record_id=? AND direction=?',
                                 (plan_id,zid,rid,'forward')).fetchone()
            if own is None or own['phase'] != 'applied' or own['receipt'] is None:
                raise CloudflareError('cloudflare_rollback_not_owned')
            if own['permit_id'] == permit_id:
                raise CloudflareError('cloudflare_rollback_fresh_permit_required')
            proof = json.loads(own['receipt'])
            if proof.get('api_verified') is not True: raise CloudflareError('cloudflare_rollback_unverified')
            source, target, before = target, source, proof['record']
        self._authorize(plan, permit_id, direction)
        current = self._record(zid,rid)
        if _stable(current) != _stable(before): raise CloudflareError('cloudflare_compare_conflict')
        self._authorize(plan, permit_id, direction)
        change = {'id': _hash([plan_id,zid,rid,direction]), 'plan_id': plan_id, 'zone_id': zid,
                  'record_id': rid, 'direction': direction, 'permit_id': permit_id,
                  'source': source, 'target': target, 'before': current, 'started_at': int(self.now())}
        with self.store.transaction() as db:
            db.execute('INSERT INTO changes VALUES(?,?,?,?,?,?,?,?,NULL)',
                       (change['id'],plan_id,zid,rid,direction,permit_id,'pending',canonical_json(change).decode()))
        # No write or token can escape before the durable intent above commits.
        self._authorize(plan, permit_id, direction)
        try:
            self._request('PATCH', f'/zones/{zid}/dns_records/{rid}', {'content': target})
        except Exception:
            with self.store.transaction() as db:
                db.execute('UPDATE changes SET phase=? WHERE id=?', ('unknown',change['id']))
            return self._reconcile_change(change)
        return self._reconcile_change(change)

    def _reconcile_change(self, change: dict) -> dict:
        current = self._record(change['zone_id'],change['record_id'])
        expected = _changed(change['before'],change['target'])
        matches = _stable(current) == expected
        if matches:
            receipt = self._receipt(change,current,True)
            with self.store.transaction() as db:
                row = db.execute('SELECT receipt FROM changes WHERE id=?', (change['id'],)).fetchone()
                # Preserve the earliest confirmed migration/TTL baseline. DNS
                # can be re-verified separately without extending the deadline.
                if row and row[0] is not None:
                    previous = json.loads(row[0])
                    receipt['expires_at'] = previous.get('expires_at')
                db.execute('UPDATE changes SET phase=?,receipt=? WHERE id=?',
                           ('applied',canonical_json(receipt).decode(),change['id']))
            return {'outcome': 'applied', 'receipt': receipt}
        phase = 'unchanged' if _stable(current) == _stable(change['before']) else 'conflict'
        with self.store.transaction() as db:
            db.execute('UPDATE changes SET phase=? WHERE id=?', (phase,change['id']))
        return {'outcome': phase, 'zone_id': change['zone_id'], 'record_id': change['record_id'],
                'retry_authorized': False, 'api_verified': False}

    def _result(self, plan_id: str, plan: dict, results: list[dict], direction: str) -> dict:
        success = len(results) == len(plan['entries']) and all(r['outcome'] == 'applied' for r in results)
        receipts = [r['receipt'] for r in results if r.get('receipt')]
        remaining = self.discover()['old_references'] if success and direction == 'forward' else []
        return {'schema': 'servernet.relay.cloudflare.receipt.v1', 'plan_id': plan_id,
                'old_identity': plan['old_identity'], 'new_identity': plan['new_identity'],
                'direction': direction, 'configured_scope_complete': True,
                'api_verified': success and (not remaining if direction == 'forward' else True),
                'authoritative_verified': success and bool(receipts) and all(r['authoritative_verified'] for r in receipts),
                'remaining_old_references': remaining, 'records': results,
                'observed_at': int(self.now()), 'cas_atomic': False,
                'ttl_known': success and bool(receipts) and all(r['ttl_known'] for r in receipts),
                'expires_at': max((r['expires_at'] for r in receipts if r['expires_at'] is not None), default=None)}

    def apply(self, plan_id: str, permit_id: str) -> dict:
        plan = self._load(plan_id)
        if not plan['ready'] or not 0 <= int(self.now())-plan['created_at'] <= 300:
            raise CloudflareError('cloudflare_plan_blocked_or_expired')
        self._authorize(plan,permit_id,'forward')
        results = [self._change(plan_id,plan,entry,permit_id,'forward') for entry in plan['entries']]
        return self._result(plan_id,plan,results,'forward')

    def reconcile(self, plan_id: str) -> dict:
        plan = self._load(plan_id)
        with self.store.transaction() as db:
            rows = db.execute('SELECT body FROM changes WHERE plan_id=? AND direction=? ORDER BY zone_id,record_id',
                              (plan_id,'forward')).fetchall()
        return self._result(plan_id,plan,[self._reconcile_change(json.loads(r[0])) for r in rows],'forward')

    def rollback(self, plan_id: str, fresh_permit_id: str) -> dict:
        plan = self._load(plan_id)
        self._authorize(plan,fresh_permit_id,'rollback')
        results = [self._change(plan_id,plan,entry,fresh_permit_id,'rollback') for entry in reversed(plan['entries'])]
        return self._result(plan_id,plan,results,'rollback')
