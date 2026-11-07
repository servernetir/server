#!/usr/bin/env python3
"""Use only an explicitly observed, same-account PHP-FPM Unix socket.

No discovery, network host connection, deployment or file creation occurs here.
The fixed primary is outside the observed document root in the private release
stage. Host labels are receipt slots; they do not select or identify an FPM pool.
"""
import argparse
import hashlib
import json
import os
import pwd
import re
from pathlib import Path
import socket
import stat
import struct
import sys
import time

sys.dont_write_bytecode = True
RELEASE = 'arvan-delivery-20261103-v3q4m7dz'
HOME = Path('/home/servernetcloud')
DOCROOT = HOME / 'public_html'
DIRECTORY = HOME / 'servernet_app/storage/app/private' / RELEASE
PRIMARY = DIRECTORY / 'private-fpm-activation.php'
CALLER = DIRECTORY / 'private-fpm-activation.py'
SCRIPT_NAME = '/__servernet_private_arvan_release_20261103_v3q4m7dz.php'
TRANSPORT = 'private-stage-fcgi-v1'
PRIMARY_SHA256 = '316f3beab6ee50df8749c2016c0669d72d9b16d61685dce9526e88f893a15bb0'
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


def checked_path(path, mode, directory=False):
    before = path.lstat()
    if path.is_symlink() or path.resolve(strict=True) != path or before.st_uid != os.getuid():
        raise RuntimeError('private_primary_identity_invalid')
    if stat.S_IMODE(before.st_mode) != mode or (directory and not stat.S_ISDIR(before.st_mode)):
        raise RuntimeError('private_primary_permissions_invalid')
    if not directory and (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1):
        raise RuntimeError('private_primary_file_invalid')
    data = None if directory else path.read_bytes()
    after = path.lstat()
    fields = ('st_dev', 'st_ino', 'st_uid', 'st_gid', 'st_mode', 'st_nlink', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
    if any(getattr(before, key) != getattr(after, key) for key in fields):
        raise RuntimeError('private_primary_changed_during_read')
    return data


def safety(socket_path):
    if (os.getuid() == 0 or os.getuid() != os.geteuid() or Path.home() != HOME
            or pwd.getpwuid(os.getuid()).pw_name != 'servernetcloud'):
        raise RuntimeError('unexpected_account')
    if (not socket_path.is_absolute() or socket_path.is_symlink()
            or socket_path.resolve(strict=True) != socket_path):
        raise RuntimeError('invalid_socket_path')
    observed = socket_path.lstat()
    if not stat.S_ISSOCK(observed.st_mode) or observed.st_uid != os.getuid():
        raise RuntimeError('socket_not_owned_by_account')
    if (DOCROOT.resolve(strict=True) != DOCROOT or not DOCROOT.is_dir()
            or DIRECTORY == DOCROOT or DOCROOT in DIRECTORY.parents or PRIMARY != DIRECTORY / 'private-fpm-activation.php'
            or CALLER != DIRECTORY / 'private-fpm-activation.py' or Path(__file__) != CALLER):
        raise RuntimeError('fixed_private_primary_bounds_invalid')
    checked_path(DIRECTORY, 0o700, directory=True)
    checked_path(CALLER, 0o600)
    primary = checked_path(PRIMARY, 0o600)
    if hashlib.sha256(primary).hexdigest() != PRIMARY_SHA256:
        raise RuntimeError('private_primary_hash_drift')


def validate_response(result, action, host, plan_sha256):
    expected = {'release': RELEASE, 'action': action, 'host': host, 'plan_sha256': plan_sha256,
                'transport_variant': TRANSPORT, 'primary_path': str(PRIMARY), 'primary_sha256': PRIMARY_SHA256}
    if any(result.get(key) != value for key, value in expected.items()):
        raise RuntimeError('fastcgi_response_binding_mismatch')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--socket', required=True, type=Path)
    parser.add_argument('--action', required=True, choices=['observe', 'activate', 'verify'])
    parser.add_argument('--host', default='console.servernet.cloud', choices=['console.servernet.cloud', 'servernet.cloud'])
    parser.add_argument('--plan-sha256', required=True)
    args = parser.parse_args()
    if not re.fullmatch(r'[a-f0-9]{64}', args.plan_sha256):
        raise RuntimeError('reviewed_plan_hash_required')
    safety(args.socket)
    params = {'GATEWAY_INTERFACE': 'CGI/1.1', 'SERVER_PROTOCOL': 'HTTP/1.1',
              'REQUEST_METHOD': 'POST', 'REQUEST_URI': SCRIPT_NAME, 'SCRIPT_NAME': SCRIPT_NAME,
              'SCRIPT_FILENAME': str(PRIMARY), 'DOCUMENT_ROOT': str(DOCROOT),
              'SERVER_NAME': args.host, 'HTTP_HOST': args.host, 'SERVER_PORT': '443', 'HTTPS': 'on',
              'REMOTE_ADDR': '127.0.0.1', 'CONTENT_TYPE': 'application/octet-stream', 'CONTENT_LENGTH': '0',
              'SERVERNET_LOCAL_RELEASE': RELEASE, 'SERVERNET_RELEASE_ACTION': args.action,
              'SERVERNET_EXPECTED_PLAN_SHA256': args.plan_sha256}
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
    validate_response(result, args.action, args.host, args.plan_sha256)
    print(json.dumps(result, sort_keys=True, ensure_ascii=False))
    return 0 if result.get('status') == 'ok' else 1


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception as error:
        reason = str(error) if isinstance(error, RuntimeError) else 'inspect_private_activation_status'
        print(json.dumps({'release': RELEASE, 'status': 'stopped', 'reason': reason}, sort_keys=True))
        sys.exit(1)
