#!/usr/bin/env python3
"""Use only an explicitly observed, same-account PHP-FPM Unix socket.

No discovery, network host connection, deployment or file creation occurs here.
The temporary primary must first be installed with HTTP access denied and checked
on both domains by the release coordinator. No HTTP token or auth bypass exists.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import socket
import stat
import struct
import sys
import time

sys.dont_write_bytecode = True
RELEASE = 'bale-month-comparison-20261031'
HOME = Path('/home/servernetcloud')
DOCROOT = HOME / 'public_html'
DIRECTORY = DOCROOT / '_bale_month_comparison_20261031'
PRIMARY = DIRECTORY / 'activate.php'
SCRIPT_NAME = '/_bale_month_comparison_20261031/activate.php'
MAX_OUTPUT = 1048576


def record(kind, content=b''):
    if len(content) > 65535:
        raise RuntimeError('fastcgi_record_too_large')
    padding = (-len(content)) % 8
    return struct.pack('!BBHHBB', 1, kind, 1, len(content), padding, 0) + content + b'\0' * padding


def encode_length(size):
    if not 0 <= size < 0x80000000:
        raise RuntimeError('fastcgi_parameter_too_large')
    return bytes([size]) if size < 128 else struct.pack('!I', size | 0x80000000)


def encode_params(params):
    result = b''
    for key, value in params.items():
        key, value = key.encode('utf-8'), value.encode('utf-8')
        result += encode_length(len(key)) + encode_length(len(value)) + key + value
    return result


def read_exact(connection, length, deadline):
    data = bytearray()
    while len(data) < length:
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise RuntimeError('fastcgi_timeout')
        connection.settimeout(remaining)
        chunk = connection.recv(length - len(data))
        if not chunk:
            raise RuntimeError('fastcgi_incomplete_response')
        data.extend(chunk)
    return bytes(data)


def receive(connection, deadline):
    stdout, stderr = bytearray(), bytearray()
    while True:
        header = read_exact(connection, 8, deadline)
        version, kind, request, size, padding, reserved = struct.unpack('!BBHHBB', header)
        if version != 1 or request != 1 or reserved != 0:
            raise RuntimeError('fastcgi_invalid_record')
        content = read_exact(connection, size, deadline)
        if padding:
            read_exact(connection, padding, deadline)
        if kind == 6:
            stdout.extend(content)
        elif kind == 7:
            stderr.extend(content)
        elif kind == 3:
            if len(content) != 8 or struct.unpack('!I', content[:4])[0] != 0 or content[4] != 0:
                raise RuntimeError('fastcgi_application_failed')
            break
        else:
            raise RuntimeError('fastcgi_unexpected_record')
        if len(stdout) + len(stderr) > MAX_OUTPUT:
            raise RuntimeError('fastcgi_output_too_large')
    if stderr:
        raise RuntimeError('fastcgi_stderr_present')
    header, separator, body = bytes(stdout).partition(b'\r\n\r\n')
    if not separator:
        header, separator, body = bytes(stdout).partition(b'\n\n')
    if not separator or b'content-type: application/json' not in header.lower():
        raise RuntimeError('fastcgi_invalid_response_headers')
    result = json.loads(body.decode('utf-8'))
    if not isinstance(result, dict) or result.get('release') != RELEASE:
        raise RuntimeError('fastcgi_invalid_response_body')
    return result


def safety(socket_path):
    if os.getuid() == 0 or Path.home() != HOME:
        raise RuntimeError('unexpected_account')
    if not socket_path.is_absolute() or socket_path.is_symlink():
        raise RuntimeError('invalid_socket_path')
    observed = socket_path.stat()
    if not stat.S_ISSOCK(observed.st_mode) or observed.st_uid != os.getuid():
        raise RuntimeError('socket_not_owned_by_account')
    for path, mode in [(DIRECTORY, 0o700), (PRIMARY, 0o600), (DIRECTORY / '.htaccess', 0o600)]:
        observed = path.lstat()
        if path.is_symlink() or observed.st_uid != os.getuid() or stat.S_IMODE(observed.st_mode) != mode:
            raise RuntimeError('private_primary_permissions_invalid')
    if not DIRECTORY.is_dir() or not PRIMARY.is_file() or (DIRECTORY / '.htaccess').read_bytes() != b'Require all denied\n':
        raise RuntimeError('http_denial_missing')
    reviewed = Path(__file__).with_name('fpm-activation.php')
    if hashlib.sha256(PRIMARY.read_bytes()).digest() != hashlib.sha256(reviewed.read_bytes()).digest():
        raise RuntimeError('private_primary_hash_drift')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--socket', required=True, type=Path)
    parser.add_argument('--action', required=True, choices=['observe', 'activate', 'verify'])
    parser.add_argument('--host', default='console.servernet.cloud', choices=['console.servernet.cloud', 'servernet.cloud'])
    args = parser.parse_args()
    safety(args.socket)
    params = {'GATEWAY_INTERFACE': 'CGI/1.1', 'SERVER_PROTOCOL': 'HTTP/1.1',
              'REQUEST_METHOD': 'POST', 'REQUEST_URI': SCRIPT_NAME, 'SCRIPT_NAME': SCRIPT_NAME,
              'SCRIPT_FILENAME': str(PRIMARY), 'DOCUMENT_ROOT': str(DOCROOT),
              'SERVER_NAME': args.host, 'HTTP_HOST': args.host, 'SERVER_PORT': '443', 'HTTPS': 'on',
              'REMOTE_ADDR': '127.0.0.1', 'CONTENT_TYPE': 'application/octet-stream', 'CONTENT_LENGTH': '0',
              'SERVERNET_LOCAL_RELEASE': RELEASE, 'SERVERNET_RELEASE_ACTION': args.action}
    deadline = time.monotonic() + 20
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
        connection.settimeout(20)
        connection.connect(str(args.socket))
        payload = (record(1, struct.pack('!HB5s', 1, 0, b'\0' * 5))
                   + record(4, encode_params(params)) + record(4) + record(5))
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            raise RuntimeError('fastcgi_timeout')
        connection.settimeout(remaining)
        connection.sendall(payload)
        result = receive(connection, deadline)
    if result.get('action') != args.action:
        raise RuntimeError('fastcgi_action_mismatch')
    print(json.dumps(result, sort_keys=True, ensure_ascii=False))
    return 0 if result.get('status') == 'ok' else 1


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception as error:
        reason = str(error) if isinstance(error, RuntimeError) else 'inspect_private_activation_status'
        print(json.dumps({'release': RELEASE, 'status': 'stopped', 'reason': reason}, sort_keys=True))
        sys.exit(1)
