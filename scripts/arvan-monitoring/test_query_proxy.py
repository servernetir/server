"""Offline only: fake wire sockets/upstream. Never starts proxy, Prometheus, SSH or app."""
from __future__ import annotations
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest import mock
from urllib.parse import urlencode

HERE = Path(__file__).resolve().parent
NS = {'__name__': 'query_proxy_test'}
exec(compile((HERE/'prometheus-query-proxy.py').read_text(), str(HERE/'prometheus-query-proxy.py'), 'exec'), NS)
IDENTITY = {'service_id': 613, 'instance_id': 407, 'customer_id': 545,
            'provider_ref': 'ir-thr-ba1:00000000-0000-0000-0000-000000000001',
            'enrollment_id': 'a' * 32, 'device': 'ens3'}
QUERY = NS['canonical_query'](IDENTITY)
NOW = 1793700000
SUCCESS = b'{"status":"success","data":{"resultType":"vector","result":[]}}'


class Wire:
    def __init__(self, data=b'', chunks=None):
        self.data = data
        self.chunks = chunks
        self.sent = b''
        self.closed = False
        self.timeouts = []
        self.connections = []
    def recv(self, size):
        if self.chunks is not None:
            return self.chunks.pop(0) if self.chunks else b''
        value, self.data = self.data[:size], self.data[size:]
        return value
    def sendall(self, value): self.sent += value
    def settimeout(self, value): self.timeouts.append(value)
    def close(self): self.closed = True
    def connect(self, value): self.connections.append(value)


def request(query=QUERY, at=str(NOW), method='GET', path='/api/v1/query', extra=b'', params=None):
    encoded = urlencode(params if params is not None else {'query': query, 'time': at})
    return (method+' '+path+'?'+encoded+' HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n').encode()+extra+b'\r\n'


def response(body=SUCCESS, status='200 OK', extra=b'', length=True):
    return (b'HTTP/1.0 '+status.encode()+b'\r\nContent-Type: application/json\r\n'
            +(b'Content-Length: '+str(len(body)).encode()+b'\r\n' if length else b'')+extra+b'\r\n'+body)


class QueryProxyTest(unittest.TestCase):
    def call(self, data, upstream=None):
        wire = Wire(data)
        seen = []
        def source(params):
            seen.append(params)
            return SUCCESS if upstream is None else upstream(params)
        NS['handle_connection'](wire, frozenset([QUERY]), source, now=lambda: NOW)
        return wire.sent, seen

    def test_actual_php_adapter_generates_exact_same_canonical_query(self):
        root = HERE.parents[1]
        if not (root/'website/app/Services/Cloud/PrivatePrometheusTrafficSource.php').is_file():
            root = root.parent/'arvan-observation'
        php = os.environ.get('PHP_BINARY') or shutil.which('php')
        if php is None:
            fallback = Path('/workspace/scratch/669618ddf503/php-runtime/php')
            php = str(fallback) if fallback.is_file() else None
        self.assertIsNotNone(php, 'Set PHP_BINARY to an isolated PHP runtime for the real query contract check')
        code = r'''require $argv[1].'/website/app/Services/Cloud/ArvanTrafficObservation.php';
require $argv[1].'/website/app/Services/Cloud/PrivatePrometheusTrafficSource.php';
$i=json_decode($argv[2],true,32,JSON_THROW_ON_ERROR);
$s=new App\Services\Cloud\PrivatePrometheusTrafficSource('http://127.0.0.1:19090',function($url,$query){echo json_encode($query,JSON_THROW_ON_ERROR);return ['status'=>503,'body'=>''];});
$s->read($i,(int)$argv[3]);'''
        result = subprocess.run([php, '-r', code, str(root), json.dumps(IDENTITY), str(NOW)],
                                capture_output=True, text=True, timeout=5, check=True)
        self.assertEqual(result.stderr, '')
        self.assertEqual(json.loads(result.stdout), {'query': QUERY, 'time': str(NOW)})

    def test_exact_enrolled_query_is_forwarded_once(self):
        sent, seen = self.call(request())
        self.assertTrue(sent.startswith(b'HTTP/1.1 200 OK\r\n'))
        self.assertEqual(seen, [{'query': QUERY, 'time': str(NOW)}])
        self.assertTrue(sent.endswith(SUCCESS))

    def test_other_identity_is_not_authorized(self):
        other = dict(IDENTITY, service_id=614)
        self.assertEqual(self.call(request(NS['canonical_query'](other)))[1], [])

    def test_any_promql_change_is_refused(self):
        for query in ('up', QUERY+' or up', QUERY.replace('timestamp(', 'time('),
                      QUERY.replace('device="ens3"', 'device=~".*"'),
                      QUERY.replace('job="servernet-arvan"', 'job="other"'), QUERY+' ', QUERY.replace(' or ', ' OR ')):
            sent, seen = self.call(request(query))
            self.assertTrue(sent.startswith(b'HTTP/1.1 400 '))
            self.assertEqual(seen, [])

    def test_mutation_method_and_nonquery_paths_refused(self):
        for method, path in [('POST','/api/v1/query'), ('GET','/-/quit'), ('GET','/-/reload'),
                             ('POST','/api/v1/admin/tsdb/delete_series'), ('GET','/api/v1/targets'),
                             ('GET','http://127.0.0.1:9090/api/v1/query'), ('GET','/api/v1/%71uery')]:
            self.assertEqual(self.call(request(method=method, path=path))[1], [])

    def test_duplicate_extra_and_malformed_params_refused(self):
        for params in ([('query', QUERY),('time',str(NOW)),('query',QUERY)],
                       [('query',QUERY),('query',QUERY)], [('query',QUERY)],
                       [('query',QUERY),('time',str(NOW)),('timeout','99')]):
            self.assertEqual(self.call(request(params=params))[1], [])
        self.assertEqual(self.call(b'GET /api/v1/query?query=%ZZ&time=1 HTTP/1.1\r\nHost: localhost\r\n\r\n')[1], [])

    def test_time_bounds_no_ranges_fractions_or_old_query(self):
        for at in (str(NOW-31), str(NOW+31), '0', str(NOW)+'.0', '1e9', '-1', str(NOW)+'&start=1'):
            self.assertEqual(self.call(request(at=at))[1], [])
        self.assertEqual(len(self.call(request(at=str(NOW+30)))[1]), 1)

    def test_header_smuggling_body_and_pipelining_refused(self):
        for extra in (b'Host: localhost\r\n', b'Content-Length: 1\r\n', b'Transfer-Encoding: chunked\r\n',
                      b'Authorization: secret\r\n', b' Cookie: secret\r\n', b'X-Test: bad\x00value\r\n'):
            self.assertEqual(self.call(request(extra=extra))[1], [])
        self.assertEqual(self.call(request()+b'GET /-/quit HTTP/1.1\r\n\r\n')[1], [])

    def test_request_caps_refuse_before_upstream(self):
        for data in (b'GET /'+b'x'*17000+b' HTTP/1.1\r\n\r\n',
                     request(extra=b'User-Agent: '+b'x'*2049+b'\r\n'),
                     request(extra=b'X: v\r\n'*40)):
            self.assertEqual(self.call(data)[1], [])

    def test_error_is_generic_and_no_raw_identity_or_secret_logs(self):
        def failing(_): raise RuntimeError('TOP_SECRET_PROVIDER_REF_'+IDENTITY['provider_ref'])
        with mock.patch('builtins.print') as printer:
            sent, seen = self.call(request(), failing)
        self.assertTrue(sent.startswith(b'HTTP/1.1 502 '))
        self.assertNotIn(b'TOP_SECRET', sent)
        self.assertNotIn(IDENTITY['provider_ref'].encode(), sent)
        self.assertEqual(len(seen), 1)
        printer.assert_not_called()

    def upstream(self, data, clock=None):
        wire = Wire(data)
        factory = mock.Mock(return_value=wire)
        value = NS['upstream_query']({'query': QUERY, 'time': str(NOW)}, socket_factory=factory,
                                      clock=clock or (lambda: 1.0))
        return value, wire, factory

    def test_upstream_is_one_literal_ipv4_get_without_dns_or_proxy(self):
        with mock.patch('socket.getaddrinfo', side_effect=AssertionError('No DNS')):
            body, wire, factory = self.upstream(response())
        self.assertEqual(body, SUCCESS)
        self.assertEqual(factory.call_args.args, (NS['socket'].AF_INET, NS['socket'].SOCK_STREAM))
        self.assertEqual(wire.connections, [('127.0.0.1',9090)])
        self.assertTrue(wire.sent.startswith(b'GET /api/v1/query?'))
        self.assertIn(b' HTTP/1.0\r\n', wire.sent)
        self.assertNotIn(b'Authorization:', wire.sent)
        self.assertTrue(wire.closed)

    def test_upstream_http10_eof_body_works(self):
        self.assertEqual(self.upstream(response(length=False))[0], SUCCESS)

    def test_real_prometheus_repeatable_vary_response_is_accepted(self):
        value = response(extra=b'Vary: Accept-Encoding\r\nVary: Origin\r\n')
        self.assertEqual(self.upstream(value)[0], SUCCESS)
        wire = Wire(value)
        _, fields, _ = NS['headers'](wire, 5, 256, lambda: 1, response_vary=True)
        self.assertEqual(fields['vary'], 'Accept-Encoding, Origin')

    def test_duplicate_vary_is_never_accepted_on_requests(self):
        self.assertEqual(self.call(request(extra=b'Vary: Accept-Encoding\r\nVary: Origin\r\n'))[1], [])
        with self.assertRaises(NS['Refused']):
            NS['headers'](Wire(request(extra=b'Vary: a\r\nVary: b\r\n')), 5, 16384, lambda: 1)

    def test_vary_does_not_relax_other_duplicate_response_headers(self):
        for fields in (b'Content-Length: '+str(len(SUCCESS)).encode()+b'\r\n',
                       b'Content-Type: application/json\r\n',
                       b'Date: x\r\nDate: x\r\n',
                       b'Transfer-Encoding: chunked\r\n'):
            with self.assertRaises(NS['Refused']):
                self.upstream(response(extra=b'Vary: a\r\nVary: b\r\n'+fields))

    def test_response_vary_has_valid_token_list_and_combined_bound(self):
        for fields in (b'Vary: \r\n', b'Vary: a/b\r\n', b'Vary: a,,b\r\n',
                       b'Vary: a\x00b\r\n', b'Vary: '+b'a'*1500+b'\r\nVary: '+b'b'*1500+b'\r\n'):
            with self.assertRaises(NS['Refused']): self.upstream(response(extra=fields))

    def test_redirect_error_and_write_responses_never_forwarded(self):
        for value in (response(status='302 Found', extra=b'Location: http://example.com\r\n'),
                      response(status='500 Internal Server Error'), response(body=b'{"status":"error","error":"SECRET"}'),
                      response(body=b'{"status":"success","data":{"resultType":"matrix","result":[]}}'),
                      response(body=b'{"status":"success","warnings":["SECRET"],"data":{"resultType":"vector","result":[]}}')):
            with self.assertRaises(Exception): self.upstream(value)

    def test_upstream_oversize_truncated_duplicate_headers_and_chunking_refused(self):
        for value in (response(body=b'x'*262145), response(extra=b'Content-Length: 1\r\n'),
                      response(extra=b'Transfer-Encoding: chunked\r\n'), response(extra=b'Content-Encoding: gzip\r\n'),
                      response()[:-2], response(body=SUCCESS+b'NOT_JSON')):
            with self.assertRaises(Exception): self.upstream(value)

    def test_declared_length_does_not_hide_trailing_bytes_or_redirect_metadata(self):
        for value in (response()+b'TRAILING', response(extra=b'Location: http://example.test/\r\n')):
            with self.assertRaises(Exception): self.upstream(value)

    def test_absolute_deadline_applies_during_slow_header_reads(self):
        ticks = iter([0,0,0,0,1,2,3,4,5,6,7])
        wire = Wire(chunks=[b'H',b'T',b'T',b'P',b'/',b'1',b'.',b'0'])
        with self.assertRaises(NS['Refused']):
            NS['upstream_query']({'query':QUERY,'time':str(NOW)},socket_factory=lambda *_:wire,clock=lambda:next(ticks))
        self.assertTrue(wire.closed)
        self.assertTrue(all(value <= 5 for value in wire.timeouts))

    def test_bindings_exact_schema_pins_duplicates_and_types(self):
        data = json.dumps({'version':1,'identities':[IDENTITY]}).encode()
        self.assertEqual(NS['parse_bindings'](data), frozenset([QUERY]))
        for value in ({'version':True,'identities':[IDENTITY]}, {'version':1,'identities':[]},
                      {'version':1,'identities':[IDENTITY,IDENTITY]},
                      {'version':1,'identities':[dict(IDENTITY,service_id=True)]},
                      {'version':1,'identities':[dict(IDENTITY,device='lo')]},
                      {'version':1,'identities':[dict(IDENTITY,extra='secret')]}):
            with self.assertRaises(Exception): NS['parse_bindings'](json.dumps(value))
        with self.assertRaises(Exception): NS['parse_bindings']('{"version":1,"version":1,"identities":[]}')

    def test_binding_file_requires_exact_sha_owner_mode_and_regular_inode(self):
        data = json.dumps({'version':1,'identities':[IDENTITY]}).encode()
        digest = hashlib.sha256(data).hexdigest()
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder)/'bindings.json'; path.write_bytes(data); path.chmod(0o600)
            # Root-owner is synthetic when tests run unprivileged; file operations remain disposable.
            original_lstat, original_fstat = os.lstat, os.fstat
            def owned(info):
                values=list(info); values[4]=0; return os.stat_result(values)
            with mock.patch('os.lstat',side_effect=lambda p:owned(original_lstat(p))), mock.patch('os.fstat',side_effect=lambda fd:owned(original_fstat(fd))):
                self.assertEqual(NS['load_bindings'](path,digest),frozenset([QUERY]))
                with self.assertRaises(Exception): NS['load_bindings'](path,'0'*64)
                path.chmod(0o644)
                with self.assertRaises(Exception): NS['load_bindings'](path,digest)
            path.chmod(0o600)
            alias=Path(folder)/'alias.json'; alias.symlink_to(path)
            with self.assertRaises(Exception): NS['load_bindings'](alias,digest)


if __name__ == '__main__':
    unittest.main()

