"""Targeted OPcache invalidation through observed same-account FPM sockets only."""
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


def activate(directory, release):
    helper = directory/'fcgi.py'
    if helper.is_symlink() or hashlib.sha256(helper.read_bytes()).hexdigest() != FCGI_SHA:
        raise RuntimeError('fastcgi_helper_hash_mismatch')
    spec=importlib.util.spec_from_file_location('ollama_fastcgi',helper)
    fcgi=importlib.util.module_from_spec(spec); spec.loader.exec_module(fcgi)
    fcgi.RELEASE='ollama-official-20261122'
    if os.getuid()==0 or Path.home()!=Path('/home/servernetcloud'):
        raise RuntimeError('unexpected_activation_account')
    primary=directory/'activate.php'
    if primary.is_symlink() or stat.S_IMODE(primary.stat().st_mode)!=0o600:
        raise RuntimeError('activation_primary_must_be_private')
    results=[]
    for host in ['servernet.cloud','console.servernet.cloud']:
        config=Path('/opt/cpanel/ea-php84/root/etc/php-fpm.d')/(host+'.conf')
        if config.is_symlink() or not config.is_file(): raise RuntimeError('fpm_config_unavailable')
        source=config.read_text()
        def value(key):
            rows=re.findall(r'^\s*'+re.escape(key)+r'\s*=\s*([^;\r\n]+)',source,re.M)
            return rows[0].strip().strip('"\'') if len(rows)==1 else None
        if value('user')!='servernetcloud' or value('chroot'):
            raise RuntimeError('unverified_fpm_account_or_chroot')
        target=value('listen')
        if not target or not re.fullmatch(r'/opt/cpanel/ea-php84/root/usr/var/run/php-fpm/[a-f0-9]{20,80}\.sock',target):
            raise RuntimeError('unverified_fpm_socket')
        path=Path(target)
        observed=path.lstat()
        if path.is_symlink() or not stat.S_ISSOCK(observed.st_mode) or observed.st_uid!=os.getuid():
            raise RuntimeError('fpm_socket_owner_mismatch')
        params={'GATEWAY_INTERFACE':'CGI/1.1','SERVER_PROTOCOL':'HTTP/1.1','REQUEST_METHOD':'POST',
            'SCRIPT_FILENAME':str(primary),'SCRIPT_NAME':'/private-ollama-activation.php',
            'REQUEST_URI':'/private-ollama-activation.php','DOCUMENT_ROOT':'/home/servernetcloud/public_html',
            'SERVER_NAME':host,'HTTP_HOST':host,'SERVER_PORT':'443','HTTPS':'on','REMOTE_ADDR':'127.0.0.1',
            'CONTENT_LENGTH':'0','SERVERNET_LOCAL_RELEASE':fcgi.RELEASE,
            'SERVERNET_OLLAMA_PLAN':str(release/'plan.json')}
        with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as connection:
            connection.settimeout(20); connection.connect(target)
            connection.sendall(fcgi.record(1,struct.pack('!HB5s',1,0,b'\0'*5))
                +fcgi.record(4,fcgi.encode_params(params))+fcgi.record(4)+fcgi.record(5))
            result=fcgi.receive(connection,time.monotonic()+20)
        if result.get('version')!='0.40.17': raise RuntimeError('fpm_loaded_version_mismatch')
        results.append({'host':host,'version':result['version'],'invalidated':result['invalidated']})
    return results
