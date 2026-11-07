#!/usr/bin/env python3
"""Fixed read-only Arvan observation query proxy. No SSH, app, guest or provider calls."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import socket
import socketserver
import stat
import threading
import time
from urllib.parse import parse_qsl, urlencode

LISTEN = ('127.0.0.1', 19101)
UPSTREAM = ('127.0.0.1', 9090)
MAX_BODY = 262144
MAX_LINE = 16384
MAX_HEADERS = 8192
MAX_QUERY = 8192
REQUEST_SECONDS = 2
UPSTREAM_SECONDS = 5
CLOCK_SKEW_SECONDS = 30
METRICS = ('up', 'node_scrape_collector_success', 'node_network_receive_bytes_total',
           'node_network_transmit_bytes_total', 'node_boot_time_seconds')
LABELS = (('service_id', 'snet_service_id'), ('instance_id', 'snet_instance_id'),
          ('customer_id', 'snet_customer_id'), ('provider_ref', 'snet_provider_ref'),
          ('enrollment_id', 'snet_enrollment_id'))
IDENTITY_KEYS = {key for key, _ in LABELS} | {'device'}


class Refused(Exception):
    """A stable internal class; exception messages and input are never logged."""


def identity_valid(value):
    if not isinstance(value, dict) or set(value) != IDENTITY_KEYS:
        return False
    if any(type(value[key]) is not int or not 0 < value[key] <= 9223372036854775807
           for key in ('service_id', 'instance_id', 'customer_id')):
        return False
    return (isinstance(value['provider_ref'], str)
            and re.fullmatch(r'ir-[a-z0-9]+-[a-z0-9]+:[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}', value['provider_ref']) is not None
            and isinstance(value['enrollment_id'], str) and re.fullmatch(r'[a-f0-9]{32}', value['enrollment_id']) is not None
            and isinstance(value['device'], str) and value['device'] != 'lo'
            and re.fullmatch(r'[A-Za-z0-9_.:-]{1,15}', value['device']) is not None)


def canonical_query(identity):
    """Byte-for-byte contract with PrivatePrometheusTrafficSource::read()."""
    if not identity_valid(identity):
        raise Refused()
    labels = 'job="servernet-arvan"'
    for key, label in LABELS:
        labels += ',' + label + '=' + json.dumps(str(identity[key]), separators=(',', ':'))
    expressions = []
    for metric in METRICS:
        metric_labels = labels
        if metric == 'node_scrape_collector_success':
            metric_labels += ',collector="netdev"'
        elif metric.startswith('node_network_'):
            metric_labels += ',device=' + json.dumps(identity['device'], separators=(',', ':'))
        selector = metric + '{' + metric_labels + '}'
        expressions.append(selector)
        expressions.append('label_replace(label_replace(timestamp(' + selector + '), "snet_observed_metric", "'
                           + metric + '", "", ""), "snet_observation_kind", "scrape_timestamp", "", "")')
    query = ' or '.join(expressions)
    if len(query) > MAX_QUERY:
        raise Refused()
    return query


def unique_object(pairs):
    out = {}
    for key, value in pairs:
        if key in out:
            raise Refused()
        out[key] = value
    return out


def parse_bindings(data):
    value = json.loads(data, object_pairs_hook=unique_object)
    if (not isinstance(value, dict) or set(value) != {'version', 'identities'}
            or type(value['version']) is not int or value['version'] != 1
            or not isinstance(value['identities'], list) or not 1 <= len(value['identities']) <= 512):
        raise Refused()
    queries = set()
    seen = {key: set() for key in ('service_id', 'instance_id', 'provider_ref', 'enrollment_id')}
    for identity in value['identities']:
        if not identity_valid(identity):
            raise Refused()
        for key, values in seen.items():
            if identity[key] in values:
                raise Refused()
            values.add(identity[key])
        queries.add(canonical_query(identity))
    return frozenset(queries)


def load_bindings(path, expected_sha):
    if not re.fullmatch(r'[a-f0-9]{64}', expected_sha):
        raise Refused()
    path = os.fspath(path)
    if not os.path.isabs(path) or os.path.realpath(path) != path:
        raise Refused()
    before = os.lstat(path)
    if (not stat.S_ISREG(before.st_mode) or before.st_uid != 0 or before.st_nlink != 1
            or stat.S_IMODE(before.st_mode) not in (0o600, 0o640) or before.st_size > MAX_BODY):
        raise Refused()
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        opened = os.fstat(fd)
        with os.fdopen(fd, 'rb', closefd=False) as source:
            data = source.read(MAX_BODY + 1)
        after = os.fstat(fd)
    finally:
        os.close(fd)
    named = os.lstat(path)
    fields = ('st_dev', 'st_ino', 'st_uid', 'st_gid', 'st_mode', 'st_nlink', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
    if (len(data) > MAX_BODY or len(data) != before.st_size
            or any(getattr(before, key) != getattr(opened, key) or getattr(before, key) != getattr(after, key)
                   or getattr(before, key) != getattr(named, key) for key in fields)
            or hashlib.sha256(data).hexdigest() != expected_sha):
        raise Refused()
    return parse_bindings(data)


def receive(sock, deadline, maximum, clock=time.monotonic):
    remaining = deadline - clock()
    if remaining <= 0:
        raise Refused()
    sock.settimeout(remaining)
    return sock.recv(maximum)


def headers(sock, deadline, first_line_limit, clock=time.monotonic, *, response_vary=False):
    data = bytearray()
    while b'\r\n\r\n' not in data:
        chunk = receive(sock, deadline, min(4096, first_line_limit + MAX_HEADERS + 5 - len(data)), clock)
        if not chunk:
            raise Refused()
        data.extend(chunk)
        line_end = data.find(b'\r\n')
        if (line_end < 0 and len(data) > first_line_limit) or line_end > first_line_limit:
            raise Refused()
        if len(data) > first_line_limit + MAX_HEADERS + 4:
            raise Refused()
    head, rest = bytes(data).split(b'\r\n\r\n', 1)
    lines = head.split(b'\r\n')
    if len(lines[0]) > first_line_limit or len(head) - len(lines[0]) > MAX_HEADERS or len(lines) > 33:
        raise Refused()
    out = {}
    for line in lines[1:]:
        if len(line) > 2048 or b':' not in line:
            raise Refused()
        key, value = line.split(b':', 1)
        if not re.fullmatch(rb'[!#$%&\'*+.^_`|~0-9A-Za-z-]+', key):
            raise Refused()
        value = value.strip(b' \t')
        if any(byte < 32 or byte == 127 for byte in value):
            raise Refused()
        key = key.decode('ascii').lower()
        if response_vary and key == 'vary':
            # RFC 9110: Vary is a response list field. It never affects framing
            # and is not forwarded or cached by this query-only proxy.
            if not re.fullmatch(rb"[!#$%&'*+.^_`|~0-9A-Za-z-]+(?: *, *[!#$%&'*+.^_`|~0-9A-Za-z-]+)*", value):
                raise Refused()
            combined = (out[key] + ', ' if key in out else '') + value.decode('ascii')
            if len(combined) > 2048:
                raise Refused()
            out[key] = combined
        else:
            if key in out:
                raise Refused()
            out[key] = value.decode('ascii')
    return lines[0].decode('ascii'), out, rest


def request_query(sock, allowed_queries, now, clock=time.monotonic):
    line, values, rest = headers(sock, clock() + REQUEST_SECONDS, MAX_LINE, clock)
    if rest or set(values) - {'host', 'accept', 'user-agent', 'connection', 'content-length'}:
        raise Refused()
    if values.get('host') not in ('localhost', '127.0.0.1', 'localhost:19101', '127.0.0.1:19101'):
        raise Refused()
    if values.get('content-length', '0') != '0':
        raise Refused()
    pieces = line.split(' ')
    if len(pieces) != 3 or pieces[0] != 'GET' or pieces[2] not in ('HTTP/1.0', 'HTTP/1.1'):
        raise Refused()
    path, separator, encoded = pieces[1].partition('?')
    if path != '/api/v1/query' or not separator or '#' in encoded or re.search(r'%(?![a-fA-F0-9]{2})', encoded):
        raise Refused()
    pairs = parse_qsl(encoded, strict_parsing=True, max_num_fields=2, encoding='ascii', errors='strict')
    if len(pairs) != 2 or {key for key, _ in pairs} != {'query', 'time'}:
        raise Refused()
    params = dict(pairs)
    if (len(params['query']) > MAX_QUERY or params['query'] not in allowed_queries
            or not re.fullmatch(r'[1-9]\d{9}', params['time']) or abs(int(params['time']) - now) > CLOCK_SKEW_SECONDS):
        raise Refused()
    return params


def upstream_query(params, socket_factory=socket.socket, clock=time.monotonic):
    """Literal AF_INET socket, one HTTP1.0 GET, absolute deadline, no redirect/DNS/proxy."""
    deadline = clock() + UPSTREAM_SECONDS
    sock = socket_factory(socket.AF_INET, socket.SOCK_STREAM)
    try:
        sock.settimeout(max(0.001, deadline - clock()))
        sock.connect(UPSTREAM)
        target = '/api/v1/query?' + urlencode(params)
        wire = ('GET ' + target + ' HTTP/1.0\r\nHost: 127.0.0.1:9090\r\n'
                'Accept: application/json\r\nAccept-Encoding: identity\r\nConnection: close\r\n\r\n').encode('ascii')
        remaining = deadline - clock()
        if remaining <= 0:
            raise Refused()
        sock.settimeout(remaining)
        sock.sendall(wire)
        line, values, rest = headers(sock, deadline, 256, clock, response_vary=True)
        if (re.fullmatch(r'HTTP/1\.[01] 200(?: [\x20-\x7e]*)?', line) is None
                or 'transfer-encoding' in values or values.get('content-encoding', 'identity') != 'identity'
                or 'location' in values
                or values.get('content-type', '').split(';', 1)[0].strip().lower() != 'application/json'):
            raise Refused()
        size = values.get('content-length')
        if size is not None and (not re.fullmatch(r'0|[1-9]\d{0,6}', size) or int(size) > MAX_BODY):
            raise Refused()
        body = bytearray(rest)
        while True:
            if len(body) > MAX_BODY or (size is not None and len(body) > int(size)):
                raise Refused()
            chunk = receive(sock, deadline, min(8192, MAX_BODY + 1 - len(body)), clock)
            if not chunk:
                break
            body.extend(chunk)
        if len(body) > MAX_BODY or (size is not None and len(body) != int(size)):
            raise Refused()
        result = json.loads(body, object_pairs_hook=unique_object)
        if (not isinstance(result, dict) or result.get('status') != 'success'
                or not isinstance(result.get('data'), dict) or result['data'].get('resultType') != 'vector'
                or not isinstance(result['data'].get('result'), list) or len(result['data']['result']) > 20
                or result.get('warnings') or result.get('infos')):
            raise Refused()
        return bytes(body)
    finally:
        sock.close()


def respond(sock, status, body):
    reason = {200: 'OK', 400: 'Bad Request', 502: 'Bad Gateway', 503: 'Service Unavailable'}[status]
    wire = ('HTTP/1.1 ' + str(status) + ' ' + reason + '\r\nContent-Type: application/json\r\n'
            'Content-Length: ' + str(len(body)) + '\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n').encode() + body
    sock.settimeout(1)
    sock.sendall(wire)


ERROR = b'{"status":"error","errorType":"private_query_unavailable","error":"Observation unavailable"}\n'


def handle_connection(sock, allowed_queries, upstream=upstream_query, now=time.time, clock=time.monotonic):
    try:
        params = request_query(sock, allowed_queries, int(now()), clock)
    except Exception:
        try: respond(sock, 400, ERROR)
        except Exception: pass
        return
    try:
        body = upstream(params)
        if not isinstance(body, bytes) or len(body) > MAX_BODY:
            raise Refused()
    except Exception:
        try: respond(sock, 502, ERROR)
        except Exception: pass
        return
    try: respond(sock, 200, body)
    except Exception: pass


class ProxyHandler(socketserver.BaseRequestHandler):
    def handle(self):
        handle_connection(self.request, self.server.allowed_queries)


class ProxyServer(socketserver.ThreadingMixIn, socketserver.TCPServer):
    daemon_threads = True
    block_on_close = False
    request_queue_size = 8
    allow_reuse_address = True

    def __init__(self, allowed_queries):
        self.allowed_queries = allowed_queries
        self.slots = threading.BoundedSemaphore(4)
        super().__init__(LISTEN, ProxyHandler)
        self.timeout = 0.5

    def process_request(self, request, client_address):
        if not self.slots.acquire(blocking=False):
            try: respond(request, 503, ERROR)
            except Exception: pass
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, client_address)
        except BaseException:
            self.slots.release()
            raise

    def process_request_thread(self, request, client_address):
        try:
            super().process_request_thread(request, client_address)
        finally:
            self.slots.release()

    def handle_error(self, request, client_address):
        pass  # No raw URL, query, response, identity or exception logging.


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--bindings', type=Path, required=True)
    parser.add_argument('--bindings-sha256', required=True)
    args = parser.parse_args()
    allowed = load_bindings(args.bindings, args.bindings_sha256)
    stopped = threading.Event()
    for signum in (signal.SIGTERM, signal.SIGINT):
        signal.signal(signum, lambda _sig, _frame: stopped.set())
    with ProxyServer(allowed) as server:
        while not stopped.is_set():
            server.handle_request()


if __name__ == '__main__':
    try:
        main()
    except Exception:
        raise SystemExit('private query proxy refused startup')
