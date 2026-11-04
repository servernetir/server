#!/usr/bin/env python3
"""Native, read-only DNS101 consumer acceptance; no enrollment or fallback.

Invoke only from a pinned Iran-adapter consumer_probes.dns SPEC on DNS101:
  /usr/bin/python3 -B /.../dns_consumer_probe.py --config /.../dns-consumer.json \
      --agent-id <installed-agent-id>
The fixed wrapper/transport must run on the enrolled secondary. Every socket
binds its literal secondary IPv4; PVE cannot substitute a host-side observation.
The interpreter, this script, protocol.py, config and ownership artifact require
explicit local deployment pins. No network job selects a command, zone or port.

Private config (root0600) has exactly schema, agent_id, binding, primary,
secondary, ownership_evidence. Binding contains owner_id, service_id, project_id
and config_hash. Primary contains ipv4, port, provider_id and generation;
secondary contains ipv4 and port. The primary IPv4/provider/generation and
binding config_hash must equal the authenticated job scope before any socket.
DNS ports are explicitly enrolled independently of the job's SSH port.

ownership_evidence is a fixed path/SHA256 to another root0600 artifact. Its
schema is servernet.relay.dns-ownership.v1, with agent_id, exact binding,
source_receipt_sha256, observed_at, expires_at, catalog and zones (1..3 objects
containing positive id and canonical name). Catalog is one canonical DNS name.
Its maximum lifetime is 900 seconds; an actual authorized metadata refresher is
required. This helper neither discovers ownership nor extends that lifetime.

For every enrolled zone/catalog, concurrently query the exact primary and
secondary over UDP and TCP, NORECURSE. Require a fresh, authoritative NOERROR
SOA with exact question/answer owner and compare the entire SOA RDATA across
all four responses. No TSIG/AXFR, cache, database, service or network writes.
DNSSEC validation, public delegation and publisher migration remain separate
acceptance requirements. This component supports default_path_v1 only.
"""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import hashlib
import hmac
import ipaddress
import math
import os
from pathlib import Path
import re
import secrets
import socket
import stat
import struct
import sys
import time
from typing import Callable

try:
    from .protocol import (HEX64, IDENTIFIER, SCHEMA as CONTEXT_SCHEMA,
                           ProtocolError, canonical_json, decode_json, private_file)
except ImportError:
    from protocol import (HEX64, IDENTIFIER, SCHEMA as CONTEXT_SCHEMA,
                          ProtocolError, canonical_json, decode_json, private_file)

CONFIG_SCHEMA = 'servernet.relay.dns-consumer-config.v1'
OWNERSHIP_SCHEMA = 'servernet.relay.dns-ownership.v1'
HANDOFF_SCHEMA = 'servernet.relay.dns-ownership-handoff.v1'
HANDOFF_CONFIG_SCHEMA = 'servernet.relay.dns-handoff-config.v1'
PROBE_SCHEMA = 'servernet.relay.iran-probe.v1'
SCOPE = ('incident_id', 'generation', 'revision', 'config_hash')
MAX_CONFIG = 8192
MAX_PACKET = 4096
MAX_ENROLLMENT_TTL = 900
NETWORK_BUDGET = 6.0
EXCHANGE_BUDGET = 2.0
DOMAIN = re.compile(r'\A(?=.{2,254}\Z)(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)+\Z')
PRIVATE_NETWORKS = tuple(ipaddress.ip_network(value) for value in
                         ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'))


def _integer(value: object, minimum: int, maximum: int, code: str) -> int:
    if type(value) is not int or not minimum <= value <= maximum:
        raise ProtocolError(code)
    return value


def _hash(value: object, code: str) -> str:
    if not isinstance(value, str) or not HEX64.fullmatch(value):
        raise ProtocolError(code)
    return value


def _name(value: object) -> str:
    if not isinstance(value, str) or not DOMAIN.fullmatch(value):
        raise ProtocolError('dns_zone_name')
    return value


def _provider(value: object) -> str:
    if (not isinstance(value, str) or not value.isascii() or not value.isdecimal()
            or not 1 <= len(value) <= 20 or int(value) < 1):
        raise ProtocolError('dns_provider_id')
    return value


def _ipv4(value: object, *, primary: bool) -> str:
    try:
        address = ipaddress.ip_address(value)
    except (TypeError, ValueError):
        raise ProtocolError('dns_address') from None
    if (not isinstance(value, str) or address.version != 4 or str(address) != value
            or address.is_multicast or address.is_unspecified or address.is_loopback
            or address.is_link_local
            or (not address.is_global if primary else not any(address in network for network in PRIVATE_NETWORKS))):
        raise ProtocolError('dns_address')
    return value


def _binding(value: object) -> dict:
    if not isinstance(value, dict) or set(value) != {'owner_id', 'service_id', 'project_id', 'config_hash'}:
        raise ProtocolError('dns_binding')
    for key in ('owner_id', 'service_id'):
        _integer(value[key], 1, 2**53 - 1, 'dns_binding')
    if not isinstance(value['project_id'], str) or not IDENTIFIER.fullmatch(value['project_id']):
        raise ProtocolError('dns_binding')
    _hash(value['config_hash'], 'dns_binding')
    return dict(value)


def _identity(info):
    return (info.st_dev, info.st_ino, info.st_uid, info.st_gid, info.st_mode,
            info.st_nlink, info.st_size, info.st_mtime_ns, info.st_ctime_ns)


def _read_private(path: str | Path, *, root_owned: bool) -> bytes:
    if '..' in Path(path).parts:
        raise ProtocolError('dns_private_path')
    path = private_file(path, root_owned=root_owned)
    before = path.lstat()
    if stat.S_IMODE(before.st_mode) != 0o600 or before.st_nlink != 1 or not 1 <= before.st_size <= MAX_CONFIG:
        raise ProtocolError('dns_private_file')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        if _identity(os.fstat(fd)) != _identity(before):
            raise ProtocolError('dns_private_changed')
        raw = os.read(fd, MAX_CONFIG + 1)
        after = os.fstat(fd)
        if (len(raw) != before.st_size or _identity(after) != _identity(before)
                or _identity(path.lstat()) != _identity(before)):
            raise ProtocolError('dns_private_changed')
        return raw
    finally:
        os.close(fd)


def load_config(path: str | Path, *, agent_id: str, root_owned: bool = True) -> dict:
    """Validate two existing local pins; never create/refresh an enrollment."""
    cfg = decode_json(_read_private(path, root_owned=root_owned))
    if (set(cfg) != {'schema', 'agent_id', 'binding', 'primary', 'secondary', 'ownership_evidence'}
            or cfg.get('schema') != CONFIG_SCHEMA or cfg.get('agent_id') != agent_id
            or not isinstance(agent_id, str) or not IDENTIFIER.fullmatch(agent_id)):
        raise ProtocolError('dns_config')
    _binding(cfg['binding'])
    primary, secondary, evidence = cfg['primary'], cfg['secondary'], cfg['ownership_evidence']
    if not isinstance(primary, dict) or set(primary) != {'ipv4', 'port', 'provider_id', 'generation'}:
        raise ProtocolError('dns_primary')
    if not isinstance(secondary, dict) or set(secondary) != {'ipv4', 'port'}:
        raise ProtocolError('dns_secondary')
    _ipv4(primary['ipv4'], primary=True)
    _provider(primary['provider_id'])
    _integer(primary['generation'], 1, 2**53 - 1, 'dns_primary_generation')
    _ipv4(secondary['ipv4'], primary=False)
    for endpoint in (primary, secondary):
        _integer(endpoint['port'], 1, 65535, 'dns_port')
    if not isinstance(evidence, dict) or set(evidence) != {'path', 'sha256'} or not isinstance(evidence['path'], str):
        raise ProtocolError('dns_ownership_pin')
    _hash(evidence['sha256'], 'dns_ownership_pin')
    raw = _read_private(evidence['path'], root_owned=root_owned)
    if not hmac.compare_digest(hashlib.sha256(raw).hexdigest(), evidence['sha256']):
        raise ProtocolError('dns_ownership_pin')
    owned = decode_json(raw)
    if (set(owned) != {'schema', 'agent_id', 'binding', 'source_receipt_sha256',
                       'observed_at', 'expires_at', 'catalog', 'zones'}
            or owned.get('schema') != OWNERSHIP_SCHEMA or owned.get('agent_id') != agent_id
            or _binding(owned.get('binding')) != cfg['binding']):
        raise ProtocolError('dns_ownership_scope')
    _hash(owned['source_receipt_sha256'], 'dns_ownership_source')
    observed = _integer(owned['observed_at'], 1, 2**53 - 1, 'dns_ownership_clock')
    expiry = _integer(owned['expires_at'], observed + 1, observed + MAX_ENROLLMENT_TTL, 'dns_ownership_clock')
    _name(owned['catalog'])
    zones = owned['zones']
    if not isinstance(zones, list) or not 1 <= len(zones) <= 3:
        raise ProtocolError('dns_owned_zones')
    names, ids = {owned['catalog']}, set()
    for zone in zones:
        if not isinstance(zone, dict) or set(zone) != {'id', 'name'}:
            raise ProtocolError('dns_owned_zone')
        ident = _integer(zone['id'], 1, 2**53 - 1, 'dns_owned_zone')
        name = _name(zone['name'])
        if ident in ids or name in names:
            raise ProtocolError('dns_owned_zone_duplicate')
        ids.add(ident); names.add(name)
    return dict(cfg, ownership=owned)


def validate_context(value: object, *, agent_id: str) -> dict:
    """Verify only typed, scope-relevant data from an already-authenticated job."""
    if (not isinstance(value, dict) or len(canonical_json(value)) > 16384
            or set(value) != {'schema', 'agent_id', 'incident_id', 'generation', 'revision',
                              'config_hash', 'jobs_hash', 'job'}
            or value.get('schema') != CONTEXT_SCHEMA or value.get('agent_id') != agent_id
            or not isinstance(agent_id, str) or not IDENTIFIER.fullmatch(agent_id)):
        raise ProtocolError('dns_context')
    if not isinstance(value['incident_id'], str) or not IDENTIFIER.fullmatch(value['incident_id']):
        raise ProtocolError('dns_context_incident')
    for key in ('generation', 'revision'):
        _integer(value[key], 1, 2**53 - 1, 'dns_context_scope')
    for key in ('config_hash', 'jobs_hash'):
        _hash(value[key], 'dns_context_scope')
    job = value['job']
    if (not isinstance(job, dict) or set(job) - {'id', 'op', 'target', 'params'}
            or not {'id', 'op', 'target'} <= job.keys()
            or not isinstance(job['id'], str) or not IDENTIFIER.fullmatch(job['id'])
            or job['op'] not in ('probe', 'accept')):
        raise ProtocolError('dns_context_job')
    target = job['target']
    if not isinstance(target, dict) or set(target) != {'host', 'port', 'provider_id'}:
        raise ProtocolError('dns_context_target')
    _ipv4(target['host'], primary=True)
    _provider(target['provider_id'])
    if type(target['port']) is not int or target['port'] not in (22, 443):
        raise ProtocolError('dns_context_target')
    params = job.get('params', {})
    if not isinstance(params, dict):
        raise ProtocolError('dns_context_params')
    # This component cannot turn isolated candidate observations into default
    # consumer acceptance. All other params are uninterpreted data, not inputs.
    if (set(params) & {'acceptance_profile', 'dependency_baseline_sha256', 'proxy_binding_sha256'}
            or params.get('path_profile', 'default_path_v1') != 'default_path_v1'):
        raise ProtocolError('dns_default_path_only')
    if 'expires_at' in params:
        _integer(params['expires_at'], 1, 2**53 - 1, 'dns_job_deadline')
    return value


def _resource(value: object) -> dict:
    if (not isinstance(value, dict) or set(value) != {'provider', 'project_id', 'provider_id', 'generation'}
            or value.get('provider') != 'hetzner' or not isinstance(value.get('project_id'), str)
            or not IDENTIFIER.fullmatch(value['project_id'])):
        raise ProtocolError('dns_resource_identity')
    _provider(value['provider_id'])
    _integer(value['generation'], 1, 2**53-1, 'dns_resource_identity')
    return dict(value)


def load_handoff_config(path: str | Path, expected_sha256: str, *, agent_id: str,
                        root_owned: bool = True) -> dict:
    _hash(expected_sha256, 'dns_handoff_config_pin')
    raw = _read_private(path, root_owned=root_owned)
    if not hmac.compare_digest(hashlib.sha256(raw).hexdigest(), expected_sha256):
        raise ProtocolError('dns_handoff_config_pin')
    cfg = decode_json(raw)
    if (raw != canonical_json(cfg) or set(cfg) != {'schema', 'agent_id', 'binding', 'source_identity',
                                                'primary', 'secondary', 'ownership_enrollment_sha256'}
            or cfg.get('schema') != HANDOFF_CONFIG_SCHEMA or cfg.get('agent_id') != agent_id):
        raise ProtocolError('dns_handoff_config')
    _binding(cfg['binding']); resource = _resource(cfg['source_identity'])
    _hash(cfg['ownership_enrollment_sha256'], 'dns_source_enrollment')
    if cfg['binding']['project_id'] != resource['project_id']:
        raise ProtocolError('dns_handoff_config')
    primary, secondary = cfg['primary'], cfg['secondary']
    if (not isinstance(primary, dict) or set(primary) != {'ipv4', 'port', 'provider_id', 'generation'}
            or not isinstance(secondary, dict) or set(secondary) != {'ipv4', 'port'}):
        raise ProtocolError('dns_handoff_endpoints')
    _ipv4(primary['ipv4'], primary=True); _ipv4(secondary['ipv4'], primary=False)
    _provider(primary['provider_id']); _integer(primary['generation'], 1, 2**53-1, 'dns_handoff_generation')
    for item in (primary, secondary):
        _integer(item['port'], 1, 65535, 'dns_port')
    if primary['provider_id'] != resource['provider_id'] or primary['generation'] != resource['generation']:
        raise ProtocolError('dns_handoff_endpoints')
    return cfg


def validate_handoff(context: dict, *, agent_id: str, enrollment: dict | None = None,
                     now: float | None = None) -> dict:
    """One finite verifier for signed data at both transport/guest boundaries.

    The agent authenticates the envelope before this call. This function does
    not create a source, read application metadata, consume another nonce, or
    derive ownership from a boolean. The guest is the sole SOA observer.
    """
    context = validate_context(context, agent_id=agent_id)
    params = context['job'].get('params', {}); value = params.get('dns_ownership')
    if (not isinstance(value, dict) or set(value) != {'schema', 'source', 'source_receipt_sha256',
                                                   'artifact', 'artifact_sha256'}
            or value.get('schema') != HANDOFF_SCHEMA or len(canonical_json(value)) > 8192):
        raise ProtocolError('dns_handoff')
    resource = _resource(params.get('resource_identity'))
    resource_hash = _hash(params.get('resource_config_hash'), 'dns_resource_hash')
    target = context['job']['target']
    if (resource['provider_id'] != target['provider_id'] or resource['generation'] > context['generation']):
        raise ProtocolError('dns_handoff_resource')
    source, artifact = value['source'], value['artifact']
    fields = {'schema','ok','read_only','internal_protected','agent_id','binding','enrollment_sha256',
              'identity','ipv4','observed_at','received_at','catalog','zones'}
    if (not isinstance(source, dict) or set(source) != fields
            or source.get('schema') != 'servernet.relay.dns-ownership-source.v1'
            or source.get('ok') is not True or source.get('read_only') is not True
            or source.get('internal_protected') is not True or source.get('agent_id') != agent_id
            or _resource(source.get('identity')) != resource
            or _ipv4(source.get('ipv4'), primary=True) != target['host']):
        raise ProtocolError('dns_handoff_source')
    binding = _binding(source['binding'])
    if binding['project_id'] != resource['project_id'] or binding['config_hash'] != resource_hash:
        raise ProtocolError('dns_handoff_source')
    _hash(source['enrollment_sha256'], 'dns_source_enrollment')
    observed = _integer(source['observed_at'], 1, 2**53-1-MAX_ENROLLMENT_TTL, 'dns_source_clock')
    received = _integer(source['received_at'], observed, observed+20, 'dns_source_clock')
    _name(source['catalog'])
    zones = source['zones']
    if not isinstance(zones, list) or not 1 <= len(zones) <= 3:
        raise ProtocolError('dns_handoff_zones')
    ids, names = [], {source['catalog']}
    for zone in zones:
        if not isinstance(zone, dict) or set(zone) != {'id', 'name'}:
            raise ProtocolError('dns_handoff_zone')
        ident = _integer(zone['id'], 1, 2**53-1, 'dns_handoff_zone'); name = _name(zone['name'])
        if ident in ids or name in names:
            raise ProtocolError('dns_handoff_zone')
        ids.append(ident); names.add(name)
    if ids != sorted(ids):
        raise ProtocolError('dns_handoff_zone')
    source_hash = _hash(value['source_receipt_sha256'], 'dns_source_hash')
    artifact_hash = _hash(value['artifact_sha256'], 'dns_artifact_hash')
    expected = dict(schema=OWNERSHIP_SCHEMA, agent_id=agent_id, binding=binding,
                    source_receipt_sha256=source_hash, observed_at=observed,
                    expires_at=observed+MAX_ENROLLMENT_TTL, catalog=source['catalog'], zones=zones)
    if (not isinstance(artifact, dict) or canonical_json(artifact) != canonical_json(expected)
            or hashlib.sha256(canonical_json(source)).hexdigest() != source_hash
            or hashlib.sha256(canonical_json(artifact)).hexdigest() != artifact_hash):
        raise ProtocolError('dns_handoff_hash')
    if enrollment is not None:
        if (canonical_json(enrollment['binding']) != canonical_json(binding)
                or enrollment['source_identity'] != resource
                or enrollment['primary']['ipv4'] != source['ipv4']
                or enrollment['ownership_enrollment_sha256'] != source['enrollment_sha256']):
            raise ProtocolError('dns_handoff_enrollment')
    if now is not None:
        if (type(now) not in (int,float) or not math.isfinite(now)
                or not received <= now < expected['expires_at']):
            raise ProtocolError('dns_handoff_expired')
        expiry = params.get('expires_at')
        if expiry is not None and now >= expiry:
            raise ProtocolError('dns_job_expired')
    return decode_json(canonical_json(value))


def _wire_name(name: str) -> bytes:
    return b''.join(bytes((len(part),)) + part.encode('ascii') for part in _name(name)[:-1].split('.')) + b'\0'


def _read_name(packet: bytes, offset: int) -> tuple[tuple[bytes, ...], int]:
    labels, end, visited, total = [], None, set(), 1
    while True:
        if not 12 <= offset < len(packet) or offset in visited or len(visited) > 128:
            raise ProtocolError('dns_wire_name')
        visited.add(offset)
        count = packet[offset]
        if count & 0xC0 == 0xC0:
            if offset + 1 >= len(packet):
                raise ProtocolError('dns_wire_name')
            pointer = ((count & 0x3F) << 8) | packet[offset + 1]
            if not 12 <= pointer < offset:
                raise ProtocolError('dns_wire_pointer')
            if end is None:
                end = offset + 2
            offset = pointer
            continue
        if count & 0xC0 or count > 63 or offset + count + 1 > len(packet):
            raise ProtocolError('dns_wire_name')
        offset += 1
        if count == 0:
            return tuple(labels), end if end is not None else offset
        label = packet[offset:offset + count]
        total += count + 1
        if total > 255:
            raise ProtocolError('dns_wire_name')
        labels.append(label.lower()); offset += count


def parse_soa(packet: bytes, *, query_id: int, zone: str) -> tuple:
    """A strict authoritative answer; no resolver fallback or alias following."""
    if not isinstance(packet, bytes) or not 12 <= len(packet) <= MAX_PACKET:
        raise ProtocolError('dns_wire_size')
    ident, flags, questions, answers, authority, additional = struct.unpack('!6H', packet[:12])
    if (ident != query_id or not flags & 0x8000 or not flags & 0x0400
            or flags & (0x7800 | 0x0200 | 0x0100 | 0x0040 | 0x000F)
            or questions != 1 or answers != 1 or authority + additional > 32):
        raise ProtocolError('dns_wire_header')
    expected = tuple(part.encode('ascii') for part in _name(zone)[:-1].split('.'))
    question, offset = _read_name(packet, 12)
    if question != expected or offset + 4 > len(packet) or packet[offset:offset + 4] != struct.pack('!HH', 6, 1):
        raise ProtocolError('dns_wire_question')
    offset += 4; soa = None
    for index in range(answers + authority + additional):
        owner, offset = _read_name(packet, offset)
        if offset + 10 > len(packet):
            raise ProtocolError('dns_wire_record')
        kind, cls, ttl, length = struct.unpack('!HHIH', packet[offset:offset + 10])
        offset += 10; end = offset + length
        if end > len(packet):
            raise ProtocolError('dns_wire_record')
        if index == 0:
            if owner != expected or kind != 6 or cls != 1:
                raise ProtocolError('dns_wire_answer')
            mname, cursor = _read_name(packet, offset)
            rname, cursor = _read_name(packet, cursor)
            if cursor + 20 != end:
                raise ProtocolError('dns_wire_soa')
            soa = (mname, rname, *struct.unpack('!5I', packet[cursor:end]))
        offset = end
    if offset != len(packet) or soa is None:
        raise ProtocolError('dns_wire_trailing')
    return soa


def exchange(endpoint: dict, zone: str, transport: str, source_ipv4: str,
             deadline: float, *, monotonic: Callable = time.monotonic,
             socket_factory: Callable = socket.socket) -> tuple:
    """One bounded literal-IP exchange from the actual enrolled source."""
    query_id = secrets.randbits(16)
    query = struct.pack('!6H', query_id, 0, 1, 0, 0, 0) + _wire_name(zone) + struct.pack('!HH', 6, 1)
    limit = min(deadline, monotonic() + EXCHANGE_BUDGET)
    if transport not in ('udp', 'tcp'):
        raise ProtocolError('dns_transport')
    kind = socket.SOCK_DGRAM if transport == 'udp' else socket.SOCK_STREAM
    def remaining(sock):
        left = limit - monotonic()
        if left <= 0:
            raise ProtocolError('dns_exchange_timeout')
        sock.settimeout(left)
    with socket_factory(socket.AF_INET, kind) as sock:
        remaining(sock)
        sock.bind((source_ipv4, 0))
        sock.connect((endpoint['ipv4'], endpoint['port']))
        remaining(sock)
        if transport == 'udp':
            sock.send(query)
            remaining(sock)
            packet = sock.recv(MAX_PACKET + 1)
        else:
            sock.sendall(struct.pack('!H', len(query)) + query)
            def receive(size):
                output = bytearray()
                while len(output) < size:
                    remaining(sock)
                    chunk = sock.recv(size - len(output))
                    if not chunk:
                        raise ProtocolError('dns_tcp_closed')
                    output.extend(chunk)
                return bytes(output)
            size = struct.unpack('!H', receive(2))[0]
            if not 12 <= size <= MAX_PACKET:
                raise ProtocolError('dns_wire_size')
            packet = receive(size)
    return parse_soa(packet, query_id=query_id, zone=zone)


def _fresh(cfg: dict, context: dict, now: float, *, started: float | None = None) -> float:
    if type(now) not in (int, float) or not math.isfinite(now) or started is not None and now < started:
        raise ProtocolError('dns_clock')
    owned = cfg['ownership']
    if not owned['observed_at'] <= now < owned['expires_at']:
        raise ProtocolError('dns_ownership_expired')
    expiry = owned['expires_at']
    job_expiry = context['job'].get('params', {}).get('expires_at')
    if job_expiry is not None:
        if now >= job_expiry:
            raise ProtocolError('dns_job_expired')
        expiry = min(expiry, job_expiry)
    return expiry - now


def _soa_value(value: object) -> bool:
    return (isinstance(value, tuple) and len(value) == 7
            and all(isinstance(name, tuple) and 1 <= len(name) <= 127
                    and all(isinstance(label, bytes) and 1 <= len(label) <= 63 for label in name)
                    for name in value[:2])
            and all(type(number) is int and 0 <= number <= 2**32 - 1 for number in value[2:]))


class DnsConsumerProbe:
    def __init__(self, config_path: str | Path, *, agent_id: str,
                 root_owned: bool = True, now: Callable = time.time,
                 monotonic: Callable = time.monotonic, query: Callable = exchange):
        self.config_path, self.agent_id, self.root_owned = config_path, agent_id, root_owned
        self.now, self.monotonic, self.query = now, monotonic, query

    def _load(self, context):
        if 'dns_ownership' in context['job'].get('params', {}):
            raise ProtocolError('dns_handoff_mode_required')
        return load_config(self.config_path, agent_id=self.agent_id, root_owned=self.root_owned)

    def _resource_scope(self, context):
        return context['generation'], context['config_hash']

    def run(self, context: dict) -> dict:
        context = validate_context(context, agent_id=self.agent_id)
        result = dict(schema=PROBE_SCHEMA, probe_kind='dns', read_only=True, ok=False,
                      target=dict(context['job']['target']), checks=dict(consumer='dns', probe_ok=False,
                      path_profile='default_path_v1'), **{key: context[key] for key in SCOPE})
        checks = result['checks']
        try:
            cfg = self._load(context)
            primary, secondary, target, owned = cfg['primary'], cfg['secondary'], context['job']['target'], cfg['ownership']
            generation, config_hash = self._resource_scope(context)
            if (primary['ipv4'] != target['host'] or primary['provider_id'] != target['provider_id']
                    or primary['generation'] != generation or cfg['binding']['config_hash'] != config_hash):
                raise ProtocolError('dns_target_binding')
            started = self.now()
            budget = min(NETWORK_BUDGET, _fresh(cfg, context, started))
            deadline = self.monotonic() + budget
            names = [owned['catalog'], *[row['name'] for row in owned['zones']]]
            checks.update(ownership_observed_at=owned['observed_at'], ownership_expires_at=owned['expires_at'])
            if 'source_receipt_sha256' in cfg:
                checks.update(source_receipt_sha256=cfg['source_receipt_sha256'],
                              ownership_sha256=cfg['ownership_sha256'])
            if 'expires_at' in context['job'].get('params', {}):
                checks['expires_at'] = context['job']['params']['expires_at']
            tasks = [(name, role, endpoint, transport) for name in names
                     for role, endpoint in (('primary', primary), ('secondary', secondary))
                     for transport in ('udp', 'tcp')]
            answers = {}
            with ThreadPoolExecutor(max_workers=len(tasks)) as pool:
                futures = {pool.submit(self.query, endpoint, name, transport, secondary['ipv4'], deadline):
                           (name, role, transport) for name, role, endpoint, transport in tasks}
                for future in as_completed(futures):
                    answer = future.result()
                    if not _soa_value(answer):
                        raise ProtocolError('dns_soa_value')
                    answers[futures[future]] = answer
            # A concurrent local re-enrollment must not turn observations under
            # the previous private pins into acceptance of the new enrollment.
            if canonical_json(self._load(context)) != canonical_json(cfg):
                raise ProtocolError('dns_enrollment_changed')
            _fresh(cfg, context, self.now(), started=started)
            if self.monotonic() >= deadline:
                raise ProtocolError('dns_network_deadline')
            matching = all(all(answers[(name, role, transport)] == answers[(name, 'primary', 'udp')]
                               for role in ('primary', 'secondary') for transport in ('udp', 'tcp'))
                           for name in names)
            checks.update(authoritative_soa_ok=True, soa_identity_matches=matching,
                          udp_and_tcp_verified=True, source_bound=True, zone_count=len(names),
                          execution_origin='dns101', network_observation_fresh=True, probe_ok=matching)
            result['ok'] = matching
        except (ProtocolError, OSError, TimeoutError):
            checks['failure'] = 'dns_acceptance_unproven'
        return result


class DnsHandoffProbe(DnsConsumerProbe):
    def __init__(self, config_path, *, config_sha256, **kwargs):
        super().__init__(config_path, **kwargs)
        self.config_sha256 = config_sha256

    def _load(self, context):
        cfg = load_handoff_config(self.config_path, self.config_sha256,
                                  agent_id=self.agent_id, root_owned=self.root_owned)
        handoff = validate_handoff(context, agent_id=self.agent_id, enrollment=cfg, now=self.now())
        return dict(cfg, ownership=handoff['artifact'], source_receipt_sha256=handoff['source_receipt_sha256'],
                    ownership_sha256=handoff['artifact_sha256'])

    def _resource_scope(self, context):
        params = context['job']['params']
        return _resource(params['resource_identity'])['generation'], params['resource_config_hash']


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    choices = parser.add_mutually_exclusive_group(required=True)
    choices.add_argument('--config')
    choices.add_argument('--handoff-config')
    parser.add_argument('--config-sha256')
    parser.add_argument('--agent-id', required=True)
    args = parser.parse_args(argv)
    try:
        raw = sys.stdin.buffer.read(16385)
        if len(raw) > 16384:
            raise ProtocolError('dns_context_size')
        context = decode_json(raw)
        if args.handoff_config:
            result = DnsHandoffProbe(args.handoff_config, config_sha256=args.config_sha256,
                                    agent_id=args.agent_id).run(context)
        else:
            if args.config_sha256 is not None:
                raise ProtocolError('dns_static_config_selector')
            result = DnsConsumerProbe(args.config, agent_id=args.agent_id).run(context)
    except (ProtocolError, OSError, TimeoutError):
        result = dict(schema=PROBE_SCHEMA, probe_kind='dns', read_only=True, ok=False,
                      checks=dict(consumer='dns', probe_ok=False, failure='dns_acceptance_unproven'))
    sys.stdout.buffer.write(canonical_json(result) + b'\n')
    return 0 if result['ok'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
