"""Targeted activation through observed PHP-FPM sockets owned by this account."""
import hashlib
import importlib.util
import os
from pathlib import Path
import re
import socket
import stat
import struct
import time

FCGI_SHA = '57b07c6c014104b2fb5c2e3e232defb8b5283a39df6bdf0ffdbc45f76b3e5b62'
FPM_DIRECTORY = Path('/opt/cpanel/ea-php84/root/usr/var/run/php-fpm')


def owned_socket(path, uid):
    """A pathname in proc is evidence only after filesystem ownership checks."""
    path = Path(path)
    if path.parent != FPM_DIRECTORY or not re.fullmatch(r'[a-f0-9]{20,80}\.sock', path.name):
        return False
    try:
        observed = path.lstat()
        return not path.is_symlink() and stat.S_ISSOCK(observed.st_mode) and observed.st_uid == uid
    except OSError:
        return False


def observe_pools():
    paths = set()
    for row in Path('/proc/net/unix').read_text().splitlines():
        parts = row.split()
        if parts and owned_socket(parts[-1], os.getuid()):
            paths.add(Path(parts[-1]))
    if not paths:
        raise RuntimeError('no_observed_same_account_fpm_socket')
    return sorted(paths)


def activate(directory, release):
    helper = directory/'fcgi.py'
    if helper.is_symlink() or hashlib.sha256(helper.read_bytes()).hexdigest() != FCGI_SHA:
        raise RuntimeError('fastcgi_helper_hash_mismatch')
    spec = importlib.util.spec_from_file_location('mcp_auto_fastcgi', helper)
    fcgi = importlib.util.module_from_spec(spec); spec.loader.exec_module(fcgi)
    fcgi.RELEASE = 'mcp-auto-approval-20261122'
    if os.getuid() == 0 or Path.home() != Path('/home/servernetcloud'):
        raise RuntimeError('unexpected_activation_account')
    primary = directory/'activate.php'
    if primary.is_symlink() or stat.S_IMODE(primary.stat().st_mode) != 0o600:
        raise RuntimeError('activation_primary_must_be_private')
    results = []
    # Every observed pool belongs to the already-authorized Unix account. The
    # primary remains private outside webroot; no privileged pool config is read.
    for target in observe_pools():
        if not owned_socket(target, os.getuid()):
            raise RuntimeError('fpm_socket_owner_changed')
        params = {'GATEWAY_INTERFACE':'CGI/1.1', 'SERVER_PROTOCOL':'HTTP/1.1', 'REQUEST_METHOD':'POST',
            'SCRIPT_FILENAME':str(primary), 'SCRIPT_NAME':'/private-mcp-auto-activation.php',
            'REQUEST_URI':'/private-mcp-auto-activation.php', 'DOCUMENT_ROOT':'/home/servernetcloud/public_html',
            'SERVER_NAME':'console.servernet.cloud', 'HTTP_HOST':'console.servernet.cloud',
            'SERVER_PORT':'443', 'HTTPS':'on', 'REMOTE_ADDR':'127.0.0.1', 'CONTENT_LENGTH':'0',
            'SERVERNET_LOCAL_RELEASE':fcgi.RELEASE, 'SERVERNET_MCP_PLAN':str(release/'prepared.json')}
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
            connection.settimeout(20); connection.connect(str(target))
            connection.sendall(fcgi.record(1,struct.pack('!HB5s',1,0,b'\0'*5))
                + fcgi.record(4,fcgi.encode_params(params)) + fcgi.record(4) + fcgi.record(5))
            result = fcgi.receive(connection,time.monotonic()+20)
        if result.get('version') != '1.2.0' or result.get('automatic_approval') is not True:
            raise RuntimeError('fpm_loaded_version_mismatch')
        results.append({'pool':hashlib.sha256(str(target).encode()).hexdigest()[:12],
            'version':result['version'], 'automatic_approval':result['automatic_approval'],
            'invalidated':result['invalidated']})
    return results
