#!/usr/bin/env python3
"""TLS-only, identity-bound Proxmox console gateway. No arbitrary target URLs.

The gateway terminates upstream VNC password authentication before presenting
RFB security type None inside the authenticated, expiring browser WebSocket.
Neither PVE API credentials nor the VNC ticket are sent to the browser.
"""
import base64
import hashlib
import hmac
import json
import os
import re
import select
import socket
import ssl
import struct
import time
import threading
from datetime import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, quote, urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, HTTPSHandler, Request, build_opener


MAX_FRAME = 1024 * 1024


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        raise RuntimeError('redirect_refused')


def json_post(url, body, headers, ca_file=None):
    parsed = urlsplit(url)
    if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password or parsed.fragment:
        raise ValueError('https_target_required')
    opener = build_opener(NoRedirect(), HTTPSHandler(context=ssl.create_default_context(cafile=ca_file)))
    request = Request(url, data=body, method='POST', headers=headers)
    with opener.open(request, timeout=5) as response:
        raw = response.read(65537)
        if len(raw) > 65536:
            raise ValueError('response_too_large')
        return json.loads(raw)


def expiry(value):
    parsed = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if parsed.tzinfo is None:
        raise ValueError('expiry_timezone_required')
    stamp = parsed.timestamp()
    if not time.time() < stamp <= time.time() + 91:
        raise ValueError('expiry_invalid')
    return stamp


def send_frame(connection, payload, opcode=2):
    if len(payload) > MAX_FRAME:
        raise ValueError('frame_too_large')
    length = len(payload)
    header = bytes([0x80 | opcode, length]) if length < 126 else bytes([0x80 | opcode, 126]) + struct.pack('!H', length) if length <= 65535 else bytes([0x80 | opcode, 127]) + struct.pack('!Q', length)
    connection.sendall(header + payload)


def read_exact(connection, count, deadline=None):
    result = b''
    while len(result) < count:
        if deadline is not None and time.time() >= deadline:
            raise TimeoutError('console_expired')
        chunk = connection.recv(count - len(result))
        if not chunk:
            raise EOFError('socket_closed')
        result += chunk
    return result


def read_frame(connection, deadline=None):
    first, second = read_exact(connection, 2, deadline)
    if first & 0x70 or not second & 0x80:
        raise ValueError('unmasked_or_reserved_frame')
    opcode, final = first & 15, bool(first & 0x80)
    length = second & 127
    if length == 126:
        length = struct.unpack('!H', read_exact(connection, 2, deadline))[0]
        if length < 126:
            raise ValueError('noncanonical_frame')
    elif length == 127:
        length = struct.unpack('!Q', read_exact(connection, 8, deadline))[0]
        if length < 65536:
            raise ValueError('noncanonical_frame')
    if length > MAX_FRAME or opcode not in (0, 2, 8, 9, 10) or (opcode >= 8 and (not final or length > 125)):
        raise ValueError('frame_invalid')
    mask = read_exact(connection, 4, deadline)
    payload = read_exact(connection, length, deadline)
    return opcode, final, bytes(value ^ mask[index % 4] for index, value in enumerate(payload))


class BrowserStream:
    def __init__(self, connection, deadline=None):
        self.connection, self.buffer, self.fragmented = connection, b'', False
        self.deadline = deadline

    def chunk(self):
        while True:
            opcode, final, data = read_frame(self.connection, self.deadline)
            if opcode == 8:
                raise EOFError('browser_closed')
            if opcode == 9:
                send_frame(self.connection, data, 10)
                continue
            if opcode == 10:
                continue
            if opcode == 0:
                if not self.fragmented:
                    raise ValueError('continuation_without_start')
            elif self.fragmented:
                raise ValueError('nested_fragment')
            self.fragmented = not final
            return data

    def exact(self, count):
        while len(self.buffer) < count:
            self.buffer += self.chunk()
            if len(self.buffer) > MAX_FRAME:
                raise ValueError('buffer_limit')
        result, self.buffer = self.buffer[:count], self.buffer[count:]
        return result


class UpstreamStream:
    def __init__(self, websocket, deadline=None):
        self.websocket, self.buffer = websocket, b''
        self.deadline = deadline

    def exact(self, count):
        while len(self.buffer) < count:
            if self.deadline is not None and time.time() >= self.deadline:
                raise TimeoutError('console_expired')
            data = self.websocket.recv()
            if not isinstance(data, bytes) or not data:
                raise ValueError('upstream_binary_required')
            self.buffer += data
            if len(self.buffer) > MAX_FRAME:
                raise ValueError('upstream_buffer_limit')
        result, self.buffer = self.buffer[:count], self.buffer[count:]
        return result


def vnc_response(password, challenge):
    from Crypto.Cipher import DES
    # VNC reverses the bits in each byte of the first eight password bytes.
    key = password.encode('latin-1')[:8].ljust(8, b'\0')
    reversed_key = bytes(int(f'{byte:08b}'[::-1], 2) for byte in key)
    return DES.new(reversed_key, DES.MODE_ECB).encrypt(challenge)


def authenticate_rfb(upstream, ticket, deadline=None):
    stream = UpstreamStream(upstream, deadline)
    if stream.exact(12) != b'RFB 003.008\n':
        raise ValueError('rfb38_required')
    upstream.send_binary(b'RFB 003.008\n')
    count = stream.exact(1)[0]
    if not 1 <= count <= 32:
        raise ValueError('upstream_auth_unavailable')
    options = stream.exact(count)
    if 2 in options:
        upstream.send_binary(b'\x02')
        upstream.send_binary(vnc_response(ticket, stream.exact(16)))
    elif 1 in options:
        upstream.send_binary(b'\x01')
    else:
        raise ValueError('upstream_auth_unsupported')
    if stream.exact(4) != b'\0\0\0\0':
        raise ValueError('upstream_auth_failed')
    return stream


def browser_rfb(connection, upstream, stream, deadline=None):
    browser = BrowserStream(connection, deadline)
    send_frame(connection, b'RFB 003.008\n')
    if browser.exact(12) != b'RFB 003.008\n':
        raise ValueError('browser_rfb38_required')
    send_frame(connection, b'\x01\x01')
    if browser.exact(1) != b'\x01':
        raise ValueError('browser_auth_invalid')
    send_frame(connection, b'\0\0\0\0')
    upstream.send_binary(browser.exact(1))  # shared session flag
    # Existing buffered bytes belong to the normal RFB stream and remain intact.
    return browser


class Config:
    def __init__(self):
        self.backchannel = os.environ['ENGINE_CONSOLE_BACKCHANNEL_URL']
        self.secret = os.environ['ENGINE_CONSOLE_GATEWAY_SECRET'].encode()
        if len(self.secret) < 32:
            raise ValueError('gateway_secret_too_short')
        self.origins = {value.strip() for value in os.environ['ENGINE_CONSOLE_ALLOWED_ORIGINS'].split(',') if value.strip()}
        self.hosts = {value.strip() for value in os.environ['ENGINE_CONSOLE_PVE_HOSTS'].split(',') if value.strip()}
        if not self.origins or not self.hosts or any(urlsplit(origin).scheme != 'https' or origin != 'https://' + urlsplit(origin).netloc for origin in self.origins):
            raise ValueError('https_origins_and_pve_hosts_required')
        self.novnc = Path(os.environ['ENGINE_NOVNC_DIR']).resolve(strict=True)
        self.app_ca = os.environ.get('ENGINE_CONSOLE_APP_CA_FILE')
        self.ca_root = Path(os.environ.get('ENGINE_CONSOLE_CA_DIRECTORY', '/etc/servernet-engine/ca')).resolve()

    def exchange(self, token, phase):
        body = json.dumps({'token': token, 'phase': phase}, separators=(',', ':')).encode()
        stamp = str(int(time.time()))
        signature = hmac.new(self.secret, stamp.encode() + b'\n' + body, hashlib.sha256).hexdigest()
        return json_post(self.backchannel, body, {'Content-Type': 'application/json',
                         'X-Engine-Time': stamp, 'X-Engine-Signature': signature}, self.app_ca)

    def upstream(self, target, deadline=None):
        import websocket
        endpoint = urlsplit(target['endpoint'])
        if endpoint.scheme != 'https' or endpoint.netloc not in self.hosts or endpoint.path not in ('', '/') or endpoint.query or endpoint.fragment or endpoint.username or endpoint.password:
            raise ValueError('target_not_allowlisted')
        node, vmid = target['node'], target['vmid']
        if not re.fullmatch(r'[A-Za-z0-9_-]{1,64}', node) or type(vmid) is not int or not 1 <= vmid <= 999999999:
            raise ValueError('target_identity_invalid')
        token_id, secret = target['token_id'], target['token_secret']
        if not re.fullmatch(r'[A-Za-z0-9_.@!_-]{1,200}', token_id) or not isinstance(secret, str) or any(ord(char) < 32 or ord(char) == 127 for char in secret):
            raise ValueError('target_token_invalid')
        ca_file = target.get('tls_ca_file')
        if ca_file:
            candidate = Path(ca_file).resolve(strict=True)
            if not candidate.is_relative_to(self.ca_root):
                raise ValueError('target_ca_outside_trust_directory')
            ca_file = str(candidate)
        authorization = 'PVEAPIToken=' + token_id + '=' + secret
        prefix = target['endpoint'].rstrip('/') + '/api2/json/nodes/' + quote(node, safe='') + '/qemu/' + str(vmid)
        result = json_post(prefix + '/vncproxy', urlencode({'websocket': 1}).encode(),
                           {'Authorization': authorization, 'Content-Type': 'application/x-www-form-urlencoded'}, ca_file)
        data = result.get('data', {})
        ticket, port = data.get('ticket'), data.get('port')
        if not isinstance(ticket, str) or not 1 <= len(ticket) <= 2048 or type(port) is not int or not 1 <= port <= 65535:
            raise ValueError('pve_console_response_invalid')
        url = 'wss://' + endpoint.netloc + '/api2/json/nodes/' + quote(node, safe='') + '/qemu/' + str(vmid) + '/vncwebsocket?' + urlencode({'port': port, 'vncticket': ticket})
        options = {'cert_reqs': ssl.CERT_REQUIRED, 'check_hostname': True}
        if ca_file:
            options['ca_certs'] = ca_file
        upstream = websocket.create_connection(url, timeout=2, sslopt=options,
                                               header={'Authorization': authorization}, suppress_origin=True)
        try:
            return upstream, authenticate_rfb(upstream, ticket, deadline)
        except Exception:
            upstream.close()
            raise


HTML = rb'''<!doctype html><html lang="en"><meta charset="utf-8"><title>Server console</title>
<style>html,body,#screen{height:100%;margin:0;background:#111;color:white}#error{padding:1rem}</style>
<div id="error" role="alert"></div><div id="screen"></div><script type="module">
import RFB from '/novnc/core/rfb.js';
const language=(navigator.language||'en').slice(0,2);
const text={fa:{title:'\u06a9\u0646\u0633\u0648\u0644 \u0633\u0631\u0648\u0631',missing:'\u0645\u062c\u0648\u0632 \u06a9\u0646\u0633\u0648\u0644 \u0648\u062c\u0648\u062f \u0646\u062f\u0627\u0631\u062f \u06cc\u0627 \u0645\u0646\u0642\u0636\u06cc \u0634\u062f\u0647 \u0627\u0633\u062a.',ended:'\u0646\u0634\u0633\u062a \u067e\u0627\u06cc\u0627\u0646 \u06cc\u0627\u0641\u062a. \u0627\u0632 \u062d\u0633\u0627\u0628 \u062e\u0648\u062f \u06a9\u0646\u0633\u0648\u0644 \u062a\u0627\u0632\u0647 \u0628\u0627\u0632 \u06a9\u0646\u06cc\u062f.'},tr:{title:'Sunucu konsolu',missing:'Konsol izni eksik veya s\u00fcresi doldu.',ended:'Konsol oturumu sona erdi. Hesab\u0131n\u0131zdan yeni bir konsol a\u00e7\u0131n.'},en:{title:'Server console',missing:'Console grant is missing or expired',ended:'Console session ended. Open a new console from your account.'}}[language]||{title:'Server console',missing:'Console grant is missing or expired',ended:'Console session ended. Open a new console from your account.'};
document.title=text.title;document.documentElement.lang=['fa','en','tr'].includes(language)?language:'en';document.documentElement.dir=language==='fa'?'rtl':'ltr';
const token=location.hash.slice(1);history.replaceState(null,'',location.pathname);
if(!/^[a-f0-9]{64}$/.test(token)){document.querySelector('#error').textContent=text.missing;}
else{const rfb=new RFB(document.querySelector('#screen'),'wss://'+location.host+'/socket?token='+token);
rfb.scaleViewport=true;rfb.resizeSession=false;rfb.addEventListener('disconnect',()=>{document.querySelector('#error').textContent=text.ended;});}
</script></html>'''


class Handler(BaseHTTPRequestHandler):
    rbufsize = 0
    protocol_version = 'HTTP/1.1'

    def setup(self):
        super().setup()
        self.connection.settimeout(5)

    def log_message(self, *args):
        pass  # Never log URLs, tokens, headers, VNC payloads or remote exceptions.

    def reply(self, status, body=b'', content_type='text/plain'):
        self.send_response(status)
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(len(body)))
        self.send_header('Cache-Control', 'no-store, private')
        self.send_header('Referrer-Policy', 'no-referrer')
        self.send_header('X-Content-Type-Options', 'nosniff')
        self.send_header('Content-Security-Policy', "default-src 'none'; script-src 'self' 'unsafe-inline'; style-src 'unsafe-inline'; connect-src 'self'; frame-ancestors 'none'")
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        parsed = urlsplit(self.path)
        if parsed.path in ('/', '/console', '/console/') and not parsed.query:
            return self.reply(200, HTML, 'text/html; charset=utf-8')
        if parsed.path.startswith('/novnc/') and not parsed.query:
            candidate = (self.server.config.novnc / parsed.path.removeprefix('/novnc/')).resolve()
            if candidate.is_relative_to(self.server.config.novnc) and candidate.is_file() and candidate.suffix == '.js':
                return self.reply(200, candidate.read_bytes(), 'text/javascript')
            return self.reply(404)
        if parsed.path != '/socket':
            return self.reply(404)
        config = self.server.config
        try:
            query = parse_qs(parsed.query, strict_parsing=True, max_num_fields=1)
        except ValueError:
            return self.reply(400)
        token = query.get('token', [''])[0]
        key = self.headers.get('Sec-WebSocket-Key', '')
        try:
            decoded_key = base64.b64decode(key, validate=True)
        except ValueError:
            decoded_key = b''
        if self.headers.get('Origin') not in config.origins or self.headers.get('Upgrade', '').lower() != 'websocket' or 'upgrade' not in [word.strip() for word in self.headers.get('Connection', '').lower().split(',')] or self.headers.get('Sec-WebSocket-Version') != '13' or len(decoded_key) != 16 or set(query) != {'token'} or len(query['token']) != 1 or not re.fullmatch('[a-f0-9]{64}', token):
            return self.reply(403)
        upstream = None
        upgraded = False
        try:
            grant = config.exchange(token, 'redeem')
            session = grant['session']
            deadline = expiry(grant['expires_at'])
            claim = config.exchange(session, 'connect')
            deadline = min(deadline, expiry(claim['expires_at']))
            upstream, stream = config.upstream(claim['target'], deadline)
            validation = config.exchange(session, 'validate')
            if validation.get('valid') is not True:
                raise ValueError('console_session_invalidated')
            deadline = min(deadline, expiry(validation['expires_at']))
            self.send_response(101)
            self.send_header('Upgrade', 'websocket')
            self.send_header('Connection', 'Upgrade')
            if 'binary' in [word.strip() for word in self.headers.get('Sec-WebSocket-Protocol', '').split(',')]:
                self.send_header('Sec-WebSocket-Protocol', 'binary')
            self.send_header('Sec-WebSocket-Accept', base64.b64encode(hashlib.sha1((key + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').encode()).digest()).decode())
            self.end_headers()
            upgraded = True
            self.connection.settimeout(2)
            browser = browser_rfb(self.connection, upstream, stream, deadline)
            last_check = 0
            while time.time() < deadline:
                if time.time() - last_check >= 10:
                    validation = config.exchange(session, 'validate')
                    if validation.get('valid') is not True:
                        break
                    deadline = min(deadline, expiry(validation['expires_at']))
                    last_check = time.time()
                if browser.buffer:
                    upstream.send_binary(browser.buffer)
                    browser.buffer = b''
                if stream.buffer:
                    send_frame(self.connection, stream.buffer)
                    stream.buffer = b''
                readable, _, _ = select.select([self.connection, upstream.sock], [], [], min(1, max(0, deadline-time.time())))
                if self.connection in readable:
                    upstream.send_binary(browser.chunk())
                if upstream.sock in readable or (hasattr(upstream.sock, 'pending') and upstream.sock.pending()):
                    data = upstream.recv()
                    if not isinstance(data, bytes) or not data:
                        break
                    send_frame(self.connection, data)
        except Exception:
            if not upgraded:
                self.reply(403, b'Console unavailable or expired')
        finally:
            if upstream is not None:
                upstream.close()
            self.close_connection = True
            if upgraded:
                try:
                    send_frame(self.connection, struct.pack('!H', 1000), 8)
                except OSError:
                    pass


class BoundedServer(ThreadingHTTPServer):
    request_queue_size = 128

    def __init__(self, *args, max_connections=64, **kwargs):
        self.slots = threading.BoundedSemaphore(max_connections)
        super().__init__(*args, **kwargs)

    def process_request(self, request, address):
        if not self.slots.acquire(blocking=False):
            self.shutdown_request(request)
            return
        try:
            super().process_request(request, address)
        except Exception:
            self.slots.release()
            raise

    def process_request_thread(self, request, address):
        try:
            super().process_request_thread(request, address)
        finally:
            self.slots.release()


def main():
    config = Config()
    bind = os.environ.get('ENGINE_CONSOLE_BIND', '127.0.0.1')
    port = int(os.environ.get('ENGINE_CONSOLE_PORT', '8443'))
    server = BoundedServer((bind, port), Handler, max_connections=max(1, min(256, int(os.environ.get('ENGINE_CONSOLE_MAX_CONNECTIONS', '64')))))
    server.daemon_threads = True
    server.config = config
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(os.environ['ENGINE_CONSOLE_TLS_CERT'], os.environ['ENGINE_CONSOLE_TLS_KEY'])
    server.socket = context.wrap_socket(server.socket, server_side=True)
    server.serve_forever()


if __name__ == '__main__':
    main()
