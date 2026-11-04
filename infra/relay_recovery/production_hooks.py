"""Concrete, default-off resource components for production integration.

This module does not advertise a completed global failover.  It implements
typed Cloudflare discovery/migration, authenticated direct AND edge origin
checks, and durable exact-generation lifecycle enrollment.  Iran consumers,
authoritative DNS, publishers and restore acceptance still require their real
adapters. ``build_integrations`` deliberately refuses to fabricate those hooks.

No I/O occurs on import. Private registry/keys are never returned in proofs.
No config value selects executable code, a URL, an HTTP path or a shell.
"""
from __future__ import annotations

import copy
import hashlib
import hmac
import http.client
import ipaddress
import os
from pathlib import Path
import re
import secrets
import socket
import sqlite3
import ssl
import stat
import time

from . import backup_client, cloudflare_adapter as cf, core
from .protocol import HEX64, IDENTIFIER, NONCE, canonical_json, decode_json


REGISTRY_SCHEMA = 'servernet.relay.dependency.registry.v1'
ORIGIN_SCHEMA = 'servernet.relay.origin-challenge.v1'
ORIGIN_PATH = '/.well-known/servernet-relay-generation'
ORIGIN_TTL = 15
ORIGIN_DEADLINE = 8
ORIGIN_TOTAL_DEADLINE = 20
ORIGIN_MAX_BODY = 8192
HOSTNAME = re.compile(r'\A(?=.{1,253}\Z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\Z')
CONTEXT_FIELDS = {'schema', 'owner_id', 'service_id', 'project_id', 'source_identity', 'identity',
                  'config_hash', 'registry_hash', 'parent_intent_id', 'incident_id', 'restore_epoch', 'restore_hash'}


class IntegrationError(core.RecoveryError):
    """Fixed, non-secret failure code; never raw transport output."""


def _require(condition, code):
    if not condition:
        raise IntegrationError(code)


def _hash(value):
    _require(isinstance(value, str) and HEX64.fullmatch(value), 'integration_hash')
    return value


def _registry_hash(registry):
    return hashlib.sha256(canonical_json(registry)).hexdigest()


def _host(value):
    _require(isinstance(value, str) and HOSTNAME.fullmatch(value), 'origin_hostname')
    return value


def _ipv4(value):
    try:
        address = ipaddress.IPv4Address(value)
    except (TypeError, ValueError):
        raise IntegrationError('origin_public_ipv4') from None
    _require(isinstance(value, str) and str(address) == value and address.is_global, 'origin_public_ipv4')
    return value


def _identity(value):
    _require(isinstance(value, dict) and set(value) == {'provider', 'project_id', 'provider_id', 'generation'},
             'integration_identity')
    result = core.identity(value)
    _require(result['provider'] == 'hetzner' and IDENTIFIER.fullmatch(result['project_id'])
             and result['provider_id'].isascii() and result['provider_id'].isdecimal()
             and 0 < int(result['provider_id']) and len(result['provider_id']) <= 20,
             'integration_identity')
    return result


def _private_bytes(path, *, limit, expected_sha256=None, allow_test_paths=False):
    """Read one stable owned file descriptor, refusing symlinks/hardlinks."""
    path = backup_client.trusted_file(str(path), uid=os.geteuid(), allow_test_paths=allow_test_paths)
    before = path.lstat()
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as source:
        current = os.fstat(source.fileno())
        _require(stat.S_ISREG(current.st_mode) and current.st_nlink == 1
                 and current.st_uid == os.geteuid() and not current.st_mode & 0o077
                 and (current.st_dev, current.st_ino) == (before.st_dev, before.st_ino)
                 and current.st_size <= limit, 'integration_private_file')
        raw = source.read(limit + 1)
    _require(len(raw) <= limit, 'integration_file_size')
    if expected_sha256 is not None:
        _require(hmac.compare_digest(hashlib.sha256(raw).hexdigest(), _hash(expected_sha256)),
                 'integration_pin_mismatch')
    return raw


def load_registry(config, *, allow_test_paths=False):
    """Load the same immutable catalogue used by the driver, without I/O effects.

    Unknown resource kinds are preserved and are explicit integration blockers;
    they are never silently dropped from the completeness calculation.
    """
    entry = config.get('production_driver', {}).get('registry', {})
    _require(isinstance(entry, dict) and set(entry) == {'path', 'sha256'}, 'registry_pin_required')
    raw = _private_bytes(entry['path'], limit=65536, expected_sha256=entry['sha256'],
                         allow_test_paths=allow_test_paths)
    registry = decode_json(raw)
    _require(raw == canonical_json(registry), 'registry_canonical')
    _require(set(registry) == {'schema', 'owner_id', 'service_id', 'project_id', 'revision', 'consumers', 'resources'}
             and registry['schema'] == REGISTRY_SCHEMA, 'registry_schema')
    policy = config.get('policy', {})
    _require(all(isinstance(registry[k], str) and registry[k] == str(policy.get(k))
                 for k in ('owner_id', 'service_id', 'project_id'))
             and type(registry['revision']) is int and registry['revision'] > 0, 'registry_scope')
    names = registry['consumers']
    _require(isinstance(names, list) and 1 <= len(names) <= 32 and len(set(names)) == len(names)
             and all(isinstance(n, str) and IDENTIFIER.fullmatch(n) for n in names)
             and set(names) == set(policy.get('critical_consumers', ())), 'registry_consumers')
    resources = registry['resources']
    _require(isinstance(resources, list) and 1 <= len(resources) <= 256, 'registry_resources')
    ids = set()
    for resource in resources:
        _require(isinstance(resource, dict) and isinstance(resource.get('id'), str)
                 and IDENTIFIER.fullmatch(resource['id']) and resource['id'] not in ids
                 and isinstance(resource.get('kind'), str) and IDENTIFIER.fullmatch(resource['kind']),
                 'registry_resource_identity')
        ids.add(resource['id'])
        if resource['kind'] == 'cloudflare_record':
            _require(set(resource) == {'id', 'kind', 'zone_id', 'record_id', 'hostname'}, 'registry_cloudflare_fields')
            cf._id(resource['zone_id']); cf._id(resource['record_id']); _host(resource['hostname'])
    return registry


def _headers(value, wanted):
    """HTTP header names are case insensitive; ambiguity is always rejected."""
    _require(isinstance(value, (dict, list, tuple)), 'origin_headers')
    pairs = list(value.items() if isinstance(value, dict) else value)
    _require(len(pairs) <= 64, 'origin_headers')
    normalized = {}
    for name, item in pairs:
        _require(isinstance(name, str) and isinstance(item, str) and 1 <= len(name) <= 128
                 and len(item) <= 8192 and '\r' not in item and '\n' not in item, 'origin_headers')
        lower = name.lower()
        _require(lower not in normalized, 'origin_duplicate_header')
        normalized[lower] = item
    _require(set(wanted) <= normalized.keys(), 'origin_headers_missing')
    return {k: normalized[k] for k in wanted}


def _key(value):
    _require(isinstance(value, bytes) and len(value) == 32, 'origin_key_length')
    return value


def _request_frame(hostname, address, nonce, timestamp):
    _host(hostname); _ipv4(address)
    _require(isinstance(nonce, str) and NONCE.fullmatch(nonce)
             and type(timestamp) is int and 0 <= timestamp < 10**12, 'origin_request_scope')
    return f'SN-RELAY-ORIGIN/1\nREQUEST\nGET\n{ORIGIN_PATH}\n{hostname}\n{address}\n{nonce}\n{timestamp}\n'.encode('ascii')


def origin_request_headers(key, hostname, address, nonce, timestamp):
    signature = hmac.new(_key(key), _request_frame(hostname, address, nonce, timestamp), hashlib.sha256).hexdigest()
    return {'Host': hostname, 'Accept': 'application/json', 'Cache-Control': 'no-cache, no-store',
            'X-SN-Origin-Nonce': nonce, 'X-SN-Origin-At': str(timestamp), 'X-SN-Origin-Signature': signature}


def _response_signature(key, raw):
    return hmac.new(_key(key), b'SN-RELAY-ORIGIN/1\nRESPONSE\n' + raw, hashlib.sha256).hexdigest()


class _TLSConnection(http.client.HTTPSConnection):
    """Fixed socket destination, independent normal SNI/certificate validation."""
    def __init__(self, hostname, address, timeout):
        super().__init__(hostname, 443, timeout=timeout, context=ssl.create_default_context())
        self.address = address

    def connect(self):
        raw = socket.create_connection((self.address, 443), self.timeout)
        try:
            self.sock = self._context.wrap_socket(raw, server_hostname=self.host)
        except BaseException:
            raw.close()
            raise


def origin_transport(hostname, address, headers, timeout, *, edge=False, edge_ipv4=None):
    """GET only, port443, no redirects or environment proxy. No caller URL."""
    _host(hostname); _ipv4(address)
    # The edge socket is selected from independently queried authoritative DNS,
    # not the local hostname resolver or hosts file. A direct-origin answer can
    # therefore never be mistaken for a Cloudflare edge route.
    if edge:
        _ipv4(edge_ipv4)
        _require(edge_ipv4 != address, 'origin_edge_equals_candidate')
    connection = _TLSConnection(hostname, edge_ipv4 if edge else address, timeout)
    try:
        connection.request('GET', ORIGIN_PATH, headers=headers)
        response = connection.getresponse()
        return response.status, response.getheaders(), response.read(ORIGIN_MAX_BODY + 1)
    finally:
        connection.close()


class OriginProbeClient:
    """Fresh candidate-origin and public edge proofs, using a dedicated key.

    ``provider`` is the installed independently scoped ProviderEffects, never
    an address supplied by a report. ``expected_machine`` must come from the
    already authenticated lifecycle proof; self-reported DMI is insufficient.
    """
    def __init__(self, key, *, provider, service_id, clock=time.time, monotonic=time.monotonic,
                 transport=origin_transport, nonce=lambda: secrets.token_hex(16),
                 authoritative_servers=None, dns=cf.authoritative_query):
        self.key, self.provider, self.service_id = _key(key), provider, service_id
        self.clock, self.monotonic, self.transport, self.nonce = clock, monotonic, transport, nonce
        self.authoritative_servers, self.dns = copy.deepcopy(authoritative_servers or {}), dns
        _require(isinstance(self.authoritative_servers, dict), 'origin_authoritative_servers')
        for zone_id, servers in self.authoritative_servers.items():
            cf._id(zone_id)
            _require(isinstance(servers, (list, tuple)) and 1 <= len(servers) <= 8
                     and len(set(servers)) == len(servers), 'origin_authoritative_servers')
            for server in servers: _ipv4(server)
        _require(isinstance(service_id, str) and service_id.isascii() and service_id.isdecimal(), 'origin_service')

    def _address(self, identity):
        result = self.provider.probe_target(identity)
        _require(isinstance(result, dict) and result.get('identity') == identity, 'origin_provider_scope')
        return _ipv4(result.get('ipv4'))

    def _edge_addresses(self, resource, address):
        servers = self.authoritative_servers.get(resource.get('zone_id'), ())
        _require(bool(servers), 'origin_authoritative_servers_missing')
        observed = []
        for server in servers:
            answer = self.dns(server, resource['hostname'], 'A')
            _require(isinstance(answer, dict) and answer.get('authoritative') is True
                     and answer.get('name') == resource['hostname'] and answer.get('type') == 'A'
                     and isinstance(answer.get('addresses'), list) and bool(answer['addresses']),
                     'origin_edge_dns_unverified')
            values = sorted({_ipv4(value) for value in answer['addresses']})
            _require(address not in values, 'origin_edge_equals_candidate')
            observed.append(values)
        _require(all(values == observed[0] for values in observed), 'origin_edge_dns_disagrees')
        return observed[0]

    def probe(self, resource, identity, config_hash, expected_machine):
        total_began = self.monotonic()
        _require(isinstance(resource, dict) and resource.get('kind') == 'cloudflare_record', 'origin_resource')
        hostname = _host(resource.get('hostname'))
        identity = _identity(identity); _hash(config_hash); _hash(expected_machine)
        address = self._address(identity)
        edge_addresses = self._edge_addresses(resource, address)
        proofs = []
        for edge in (False, True):
            began, started, nonce = self.monotonic(), int(self.clock()), self.nonce()
            headers = origin_request_headers(self.key, hostname, address, nonce, started)
            try:
                status, response_headers, raw = self.transport(hostname, address, headers, ORIGIN_DEADLINE,
                    edge=edge, edge_ipv4=edge_addresses[0] if edge else None)
            except Exception:
                raise IntegrationError('origin_transport_failed') from None
            now = int(self.clock())
            _require(0 <= self.monotonic() - began <= ORIGIN_DEADLINE and status == 200
                     and isinstance(raw, bytes) and len(raw) <= ORIGIN_MAX_BODY, 'origin_response_unavailable')
            signed = _headers(response_headers, ('x-sn-origin-signature', 'cache-control'))
            _require('no-store' in [v.strip().lower() for v in signed['cache-control'].split(',')], 'origin_cache_policy')
            signature = signed['x-sn-origin-signature']
            _require(isinstance(signature, str) and HEX64.fullmatch(signature)
                     and hmac.compare_digest(signature, _response_signature(self.key, raw)), 'origin_response_signature')
            body = decode_json(raw)
            _require(raw == canonical_json(body), 'origin_response_canonical')
            expected = dict(schema=ORIGIN_SCHEMA, purpose='relay_origin', service_id=self.service_id,
                identity=identity, config_hash=config_hash, hostname=hostname, origin_ipv4=address,
                machine_uuid_sha256=expected_machine, nonce=nonce, read_only=True)
            _require(set(body) == set(expected) | {'observed_at'}
                     and canonical_json({k: body.get(k) for k in expected}) == canonical_json(expected),
                     'origin_response_binding')
            at = body['observed_at']
            _require(type(at) is int and started - 2 <= at <= now and 0 <= now - at <= ORIGIN_TTL,
                     'origin_response_stale')
            proofs.append(dict(path='edge' if edge else 'direct', observed_at=at,
                               peer_ipv4=edge_addresses[0] if edge else address,
                               body_sha256=hashlib.sha256(raw).hexdigest()))
        _require(self._address(identity) == address, 'origin_provider_changed')
        _require(0 <= int(self.clock()) - min(p['observed_at'] for p in proofs) <= ORIGIN_TTL,
                 'origin_response_stale')
        _require(self._edge_addresses(resource, address) == edge_addresses, 'origin_edge_dns_changed')
        _require(0 <= self.monotonic() - total_began <= ORIGIN_TOTAL_DEADLINE
                 and 0 <= int(self.clock()) - min(p['observed_at'] for p in proofs) <= ORIGIN_TTL,
                 'origin_response_stale')
        return dict(schema=ORIGIN_SCHEMA, verified=True, read_only=True, identity=identity,
            config_hash=config_hash, hostname=hostname, origin_ipv4=address,
            machine_uuid_sha256=expected_machine, origin_verified=True, edge_route_verified=True,
            observed_at=min(p['observed_at'] for p in proofs), received_at=int(self.clock()), proofs=proofs)


class OriginChallengeResponder:
    """Pure responder for an explicitly installed local HTTPS route.

    No listener or Nginx change occurs here. Enrollment is selected privately by
    the operator and must be updated by the generation-adoption transaction.
    Replay storage is durable. Authentication precedes the fixed DMI read.
    """
    def __init__(self, enrollment, key, replay, *, clock=time.time, machine_reader=None):
        required = {'schema', 'service_id', 'identity', 'config_hash', 'origin_ipv4', 'hostnames'}
        _require(isinstance(enrollment, dict) and set(enrollment) == required
                 and enrollment['schema'] == ORIGIN_SCHEMA, 'origin_enrollment')
        self.enrollment = copy.deepcopy(enrollment); self.key = _key(key)
        self.replay, self.clock = replay, clock
        _require(callable(getattr(replay, 'claim', None)), 'origin_replay_store')
        _identity(enrollment['identity']); _hash(enrollment['config_hash']); _ipv4(enrollment['origin_ipv4'])
        names = enrollment['hostnames']
        _require(isinstance(names, list) and 1 <= len(names) <= 128 and len(set(names)) == len(names), 'origin_enrollment_hosts')
        for name in names: _host(name)
        _require(isinstance(enrollment['service_id'], str) and enrollment['service_id'].isascii()
                 and enrollment['service_id'].isdecimal(), 'origin_service')
        self.machine_reader = machine_reader or self._machine

    @staticmethod
    def _machine():
        from .generation_guard import machine_uuid_hash
        return machine_uuid_hash()

    def respond(self, method, path, request_headers):
        _require(method == 'GET' and path == ORIGIN_PATH, 'origin_request_path')
        headers = _headers(request_headers, ('host', 'x-sn-origin-nonce', 'x-sn-origin-at', 'x-sn-origin-signature'))
        host, nonce, stamp = headers['host'], headers['x-sn-origin-nonce'], headers['x-sn-origin-at']
        _require(host in self.enrollment['hostnames'] and re.fullmatch(r'0|[1-9][0-9]{0,11}', stamp), 'origin_request_scope')
        at, now = int(stamp), int(self.clock())
        _require(abs(now - at) <= ORIGIN_TTL, 'origin_request_stale')
        expected = hmac.new(self.key, _request_frame(host, self.enrollment['origin_ipv4'], nonce, at), hashlib.sha256).hexdigest()
        _require(bool(HEX64.fullmatch(headers['x-sn-origin-signature']))
                 and hmac.compare_digest(headers['x-sn-origin-signature'], expected), 'origin_request_signature')
        _require(self.replay.claim('origin-' + self.enrollment['service_id'], nonce, now + ORIGIN_TTL, now), 'origin_request_replay')
        machine = _hash(self.machine_reader())
        body = dict(schema=ORIGIN_SCHEMA, purpose='relay_origin', service_id=self.enrollment['service_id'],
            identity=self.enrollment['identity'], config_hash=self.enrollment['config_hash'], hostname=host,
            origin_ipv4=self.enrollment['origin_ipv4'], machine_uuid_sha256=machine,
            observed_at=now, nonce=nonce, read_only=True)
        raw = canonical_json(body)
        return 200, {'Content-Type': 'application/json', 'Cache-Control': 'no-store',
                     'X-SN-Origin-Signature': _response_signature(self.key, raw)}, raw


class CloudflareResources:
    """Real CF resource reader and journaled content-only migration component.

    This scope never implies all relay dependencies were discovered. It rejects
    uncovered records and hostname drift, retains the provider adapter's unknown
    write/reconciliation semantics, and exposes CAS as non-atomic explicitly.
    """
    def __init__(self, config, registry, *, provider, origin_probe=None, transport=None,
                 dns=cf.authoritative_query, clock=time.time):
        self.config, self.registry, self.provider = copy.deepcopy(config), copy.deepcopy(registry), provider
        self.origin_probe, self.transport, self.dns, self.clock = origin_probe, transport, dns, clock
        self.resources = [r for r in registry['resources'] if r['kind'] == 'cloudflare_record']
        _require(_registry_hash(registry) == config.get('production_driver', {}).get('registry', {}).get('sha256'),
                 'cloudflare_registry_pin')
        _require(bool(self.resources), 'cloudflare_resources_missing')
        actual = {(cf._id(z['zone_id']), cf._id(r)) for z in config.get('cloudflare', {}).get('zones', []) for r in z['record_ids']}
        wanted = {(cf._id(r['zone_id']), cf._id(r['record_id'])) for r in self.resources}
        _require(actual == wanted and len(wanted) == len(self.resources), 'cloudflare_registry_coverage')
        for resource in self.resources: _host(resource['hostname'])

    def _effects(self, context, *, authorizer=None):
        _require(isinstance(context, dict) and set(context) == CONTEXT_FIELDS, 'integration_context')
        _require(context.get('registry_hash') == _registry_hash(self.registry), 'integration_registry_scope')
        old, new = _identity(context['source_identity']), _identity(context['identity'])
        _require(old['project_id'] == new['project_id'] == self.registry['project_id']
                 and all(str(context[k]) == str(self.registry[k]) for k in ('owner_id', 'service_id', 'project_id')),
                 'integration_context_scope')
        source, target = self.provider.probe_target(old), self.provider.probe_target(new)
        _require(source.get('identity') == old and target.get('identity') == new, 'integration_provider_scope')
        config = copy.deepcopy(self.config)
        config['cloudflare']['old_ipv4'] = _ipv4(source.get('ipv4'))
        if source.get('ipv6'):
            config['cloudflare']['old_ipv6'] = source['ipv6']
        else:
            config['cloudflare'].pop('old_ipv6', None)
        effects = cf.CloudflareEffects(config, provider=self.provider, transport=self.transport,
            dns=self.dns, now=self.clock, authorizer=authorizer)
        return effects, source, target

    def inspect(self, context, *, expect_candidate=False, expected_machine=None, expected_config_hash=None):
        discovery_at = int(self.clock())
        effects, source, target = self._effects(context)
        discovered = effects.discover()
        allowed = {(r['zone_id'], r['record_id']) for r in self.resources}
        uncovered = [r for r in discovered['old_references'] if (r['zone_id'], r['record_id']) not in allowed]
        _require(not uncovered, 'cloudflare_unregistered_old_reference')
        identity = context['identity'] if expect_candidate else context['source_identity']
        if expected_config_hash is None:
            _require(expect_candidate or context['identity'] == context['source_identity'], 'origin_source_config_required')
            expected_config_hash = context['config_hash']
        _hash(expected_config_hash)
        address = target['ipv4'] if expect_candidate else source['ipv4']
        proofs = []
        for resource in self.resources:
            record_at = int(self.clock())
            record = effects._record(resource['zone_id'], resource['record_id'])
            _require(record['name'] == resource['hostname'], 'cloudflare_hostname_drift')
            expected = address if record['type'] == 'A' else (target if expect_candidate else source).get('ipv6')
            matched = bool(expected and record['content'] == expected)
            origin = None
            authoritative = []
            observation_times = [record_at]
            if record['proxied'] and matched:
                _require(self.origin_probe is not None and expected_machine is not None, 'cloudflare_origin_probe_missing')
                origin = self.origin_probe.probe(resource, identity, expected_config_hash, expected_machine)
                at = origin.get('observed_at')
                matched = (origin.get('verified') is True and origin.get('edge_route_verified') is True
                           and type(at) is int and 0 <= int(self.clock()) - at <= ORIGIN_TTL)
                if type(at) is int: observation_times.append(at)
            elif matched:
                for server in effects.zones[resource['zone_id']]['authoritative_ips']:
                    dns_at = int(self.clock())
                    answer = self.dns(server, resource['hostname'], record['type'])
                    good = bool(isinstance(answer, dict) and answer.get('authoritative') is True
                        and answer.get('name') == resource['hostname'] and answer.get('type') == record['type']
                        and isinstance(answer.get('addresses'), list) and answer['addresses']
                        and set(answer['addresses']) == {expected})
                    authoritative.append(dict(server=server, verified=good, answer=answer, observed_at=dns_at))
                    observation_times.append(dns_at)
                matched = bool(authoritative) and all(answer['verified'] for answer in authoritative)
            proof = dict(resource=resource, record=cf._stable(record), origin=origin,
                         authoritative=authoritative, observed_at=min(observation_times), content_verified=record['content'] == expected)
            proofs.append(dict(id=resource['id'], verified=matched, verifier='cloudflare-api-and-authenticated-origin-v1'
                if record['proxied'] else 'cloudflare-api-and-authoritative-dns-v1', proof_sha256=core.digest(proof), proof=proof))
        finished = int(self.clock())
        age = self.config.get('policy', {}).get('receipt_max_age', 90)
        _require(type(age) is int and 1 <= age <= 3600, 'integration_receipt_age')
        for entry in proofs:
            proof = entry['proof']
            fresh = 0 <= finished - proof['observed_at'] <= age
            if proof['origin'] is not None:
                at = proof['origin'].get('observed_at')
                fresh = fresh and type(at) is int and 0 <= finished - at <= ORIGIN_TTL
            proof['fresh_at_completion'] = fresh
            proof['completed_at'] = finished
            entry['verified'] = entry['verified'] and fresh
            entry['proof_sha256'] = core.digest(proof)
        discovery_fresh = 0 <= finished - discovery_at <= age
        return dict(configured_scope_complete=True, all_resources_verified=discovery_fresh and all(p['verified'] for p in proofs),
                    no_old_references=discovery_fresh and not discovered['old_references'], resource_proofs=proofs,
                    observed_at=min([discovery_at] + [p['proof']['observed_at'] for p in proofs]),
                    received_at=finished, cas_atomic=False)

    def plan(self, context):
        effects, _, target = self._effects(context)
        # Hostname drift must be detected before a plan can be published.
        for resource in self.resources:
            _require(effects._record(resource['zone_id'], resource['record_id'])['name'] == resource['hostname'],
                     'cloudflare_hostname_drift')
        return effects.plan(context['source_identity'], context['identity'], target['ipv4'], target.get('ipv6'))

    def apply(self, context, plan_id, permit, authorize):
        _require(self.config.get('policy', {}).get('enabled') is True
                 and self.config.get('production_driver', {}).get('enabled') is True, 'production_effects_disabled')
        _require(callable(authorize) and isinstance(permit, dict), 'cloudflare_driver_permit')
        created, expiry, now = permit.get('created_at'), permit.get('expires_at'), int(self.clock())
        _require(permit.get('schema') == 'servernet.relay.driver.permit.v1'
                 and permit.get('kind') == 'EXTERNAL_CUTOVER'
                 and permit.get('context_hash') == core.digest(context)
                 and permit.get('parent_intent_id') == context['parent_intent_id']
                 and type(created) is int and type(expiry) is int and created <= now < expiry
                 and 0 < expiry - created <= 30, 'cloudflare_driver_permit')
        def bound(plan, permit_id, direction):
            if (direction != 'forward' or permit_id != permit.get('intent_id')
                    or plan['old_identity'] != context['source_identity'] or plan['new_identity'] != context['identity']
                    or not created <= int(self.clock()) < expiry or authorize(permit) is not True):
                return False
            source, target = self.provider.probe_target(plan['old_identity']), self.provider.probe_target(plan['new_identity'])
            return (source.get('identity') == plan['old_identity'] and target.get('identity') == plan['new_identity']
                    and source.get('ipv4') == plan['old_addresses']['A'] and target.get('ipv4') == plan['new_addresses']['A']
                    and ('AAAA' not in plan['old_addresses'] or source.get('ipv6') == plan['old_addresses']['AAAA'])
                    and ('AAAA' not in plan['new_addresses'] or target.get('ipv6') == plan['new_addresses']['AAAA'])
                    and created <= int(self.clock()) < expiry and authorize(permit) is True)
        effects, _, _ = self._effects(context, authorizer=bound)
        return effects.apply(plan_id, permit['intent_id'])

    def reconcile(self, context, plan_id):
        effects, _, _ = self._effects(context)
        plan = effects._load(plan_id)
        _require(plan['old_identity'] == context['source_identity'] and plan['new_identity'] == context['identity'],
                 'cloudflare_reconcile_scope')
        return effects.reconcile(plan_id)


class LifecycleFactory:
    """Persist restored candidate enrollment for its later active generation.

    The source configuration must match the current core active identity and
    exact config hash. A provider-id-only startup seed cannot stand in for a
    different generation. Candidates require the independently source-pinned
    restored bootstrap artifact; their configuration is validated and recorded
    durably before it can later be selected as an active source.

    Existing ``restore_workflow.lifecycle.source_configs`` entries are explicit
    ``{path,sha256}`` pins, keyed by provider ID. Candidate template accepts only
    SSH/controller key paths and existing timeout/port limits. No remote command
    or host-key fallback is accepted.
    """
    def __init__(self, config, *, provider, client_factory=None, clock=time.time, allow_test_paths=False):
        from .lifecycle_client import LifecycleClient
        self.config, self.provider, self.clock = copy.deepcopy(config), provider, clock
        self.options = config.get('restore_workflow', {}).get('lifecycle', {})
        _require(isinstance(self.options, dict), 'lifecycle_enrollment_config')
        self.client_factory = client_factory or LifecycleClient
        self.allow_test_paths = allow_test_paths
        self.root = backup_client._directory(str(config['state_dir']), uid=os.geteuid(), allow_test_paths=allow_test_paths)
        self.path = self.root / 'lifecycle-enrollments.sqlite3'
        fd = os.open(self.path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        os.close(fd)
        backup_client.trusted_file(str(self.path), uid=os.geteuid(), allow_test_paths=allow_test_paths)
        with self._db() as db:
            db.execute('CREATE TABLE IF NOT EXISTS generations(scope TEXT PRIMARY KEY, body TEXT NOT NULL, sha256 TEXT NOT NULL)')

    def _db(self):
        db = sqlite3.connect(self.path, timeout=2)
        db.execute('PRAGMA synchronous=FULL')
        return db

    def _state(self):
        path = self.root / 'controller.sqlite3'
        trusted = backup_client.trusted_file(str(path), uid=os.geteuid(), allow_test_paths=self.allow_test_paths)
        _require(trusted.stat().st_nlink == 1, 'lifecycle_core_journal_private')
        with sqlite3.connect(path.as_uri() + '?mode=ro', uri=True, timeout=2) as db:
            row = db.execute('SELECT body FROM state WHERE id=1').fetchone()
        _require(row is not None, 'lifecycle_core_state_missing')
        return decode_json(row[0].encode('utf-8'))

    def __call__(self, target, artifact, authorize):
        from . import lifecycle_client as lifecycle
        identity = _identity(core.identity(target)); config_hash = _hash(target.get('config_hash'))
        _require(identity['project_id'] == self.config.get('policy', {}).get('project_id')
                 and callable(authorize), 'lifecycle_scope')
        state = self._state()
        role = 'active' if artifact is None else 'candidate'
        current = state.get(role)
        _require(isinstance(current, dict) and core.identity(current) == identity
                 and current.get('config_hash') == config_hash, 'lifecycle_core_generation_mismatch')
        resolved = self.provider.probe_target(identity)
        _require(isinstance(resolved, dict) and resolved.get('identity') == identity, 'lifecycle_provider_scope')
        address = _ipv4(resolved.get('ipv4'))
        scope = core.digest({'identity': identity, 'config_hash': config_hash})
        with self._db() as db:
            saved = db.execute('SELECT body,sha256 FROM generations WHERE scope=?', (scope,)).fetchone()
        if saved:
            raw = saved[0].encode('utf-8')
            _require(hashlib.sha256(raw).hexdigest() == saved[1], 'lifecycle_enrollment_corrupt')
            cfg = decode_json(raw)
        elif artifact is None:
            entry = self.options.get('source_configs', {}).get(identity['provider_id'])
            _require(isinstance(entry, dict) and set(entry) == {'path', 'sha256'}, 'source_lifecycle_pin_missing')
            cfg = decode_json(_private_bytes(entry['path'], limit=65536, expected_sha256=entry['sha256'],
                allow_test_paths=self.allow_test_paths))
        else:
            _require(getattr(artifact, 'profile', None) == 'restored'
                     and getattr(artifact, 'host_key_origin', None) == 'source', 'lifecycle_source_host_pin_required')
            template = self.options.get('candidate_template', {})
            _require(isinstance(template, dict) and set(template) <= {'ssh_key_file', 'controller_key_file', 'ssh_port', 'timeout_seconds'}
                     and {'ssh_key_file', 'controller_key_file'} <= template.keys(), 'lifecycle_candidate_template')
            cfg = dict(template, schema=lifecycle.SCHEMA, service_id=str(self.config['policy']['service_id']),
                provider_id=identity['provider_id'], generation=identity['generation'], config_hash=config_hash,
                source_host=address, known_hosts_file=artifact.known_hosts_path,
                expected_host_key_sha256=artifact.public_host_fingerprint,
                state_dir=str(self.root / ('lifecycle-' + scope[:32])))
        _require({k: cfg.get(k) for k in ('provider_id', 'generation', 'config_hash')} == {
            'provider_id': identity['provider_id'], 'generation': identity['generation'], 'config_hash': config_hash}
            and cfg.get('service_id') == str(self.config['policy']['service_id']) and cfg.get('source_host') == address,
            'lifecycle_enrollment_scope_mismatch')
        if artifact is not None:
            _require(cfg.get('known_hosts_file') == artifact.known_hosts_path
                     and cfg.get('expected_host_key_sha256') == artifact.public_host_fingerprint,
                     'lifecycle_restored_pin_changed')
        lifecycle.validate_config(copy.deepcopy(cfg), allow_test_paths=self.allow_test_paths)
        client = self.client_factory(cfg, authorize=authorize, clock=self.clock, allow_test_paths=self.allow_test_paths)
        raw = canonical_json(cfg)
        with self._db() as db:
            db.execute('BEGIN IMMEDIATE')
            existing = db.execute('SELECT body FROM generations WHERE scope=?', (scope,)).fetchone()
            _require(existing is None or existing[0].encode('utf-8') == raw, 'lifecycle_enrollment_changed')
            db.execute('INSERT OR IGNORE INTO generations VALUES(?,?,?)',
                (scope, raw.decode('utf-8'), hashlib.sha256(raw).hexdigest()))
        return client


def integration_blockers(registry):
    """Explicit engineering gaps, not operator flags that can forge readiness."""
    _require(isinstance(registry, dict) and isinstance(registry.get('resources'), list), 'registry_schema')
    unsupported = sorted({r['kind'] for r in registry['resources'] if r.get('kind') != 'cloudflare_record'})
    return dict(unsupported_resource_kinds=unsupported, blockers=[
        'iran_trial_transport_and_resource_observation_not_integrated',
        'restore_acceptance_callbacks_not_integrated',
        'global_cutover_and_retirement_not_integrated'], effects_enabled=False)


def build_integrations(config):
    """Fail closed until the complete typed production integration is present.

    Enabling policy alone is never interpreted as reviewed hooks. The concrete
    components above can be used by the final coordinator after actual private
    registry, origin endpoint and consumer adapter enrollment.
    """
    if (config.get('production_driver', {}).get('enabled') is not True
            or config.get('policy', {}).get('enabled') is not True):
        return {}
    load_registry(config)
    raise IntegrationError('production_hooks_global_integration_incomplete')
