#!/usr/bin/env python3
"""Pinned six-file release; no inference, schema, dependency or financial changes."""
import fcntl
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import socket
import struct
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.request

HOME = Path('/home/servernetcloud')
ROOT = HOME / 'servernet_app'
STUDIO = HOME / 'studio-stage.sOsXeb/servernet'
RELEASE = 'studio-catalogue-union-20261124'
PREFIX = 'scripts/' + RELEASE + '/'
PHP = '/opt/cpanel/ea-php84/root/usr/bin/php'

def sha(data):
    return hashlib.sha256(data).hexdigest()

def fetch(commit, path):
    with urllib.request.urlopen('https://raw.githubusercontent.com/servernetir/server/'+commit+'/'+path, timeout=40) as r:
        return r.read()

def write(path, data, mode=0o600):
    if path.is_symlink(): raise RuntimeError('symlink_destination')
    fd, temporary = tempfile.mkstemp(prefix='.catalogue-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as f:
            f.write(data); f.flush(); os.fsync(f.fileno())
        os.chmod(temporary, mode); os.replace(temporary, path)
    finally:
        if os.path.exists(temporary): os.unlink(temporary)

def current(row):
    path = HOME / row['destination']
    if path.is_symlink(): raise RuntimeError('symlink_destination')
    return sha(path.read_bytes()) if path.exists() else None

def matches(row, phase):
    observed = current(row)
    return observed == row[phase] or (phase == 'before' and row['before'] is None and observed == row['after'])

def activate(stage, manifest, commit, phase):
    for name, row in manifest['helpers'].items():
        data = fetch(commit, row['path'])
        assert sha(data) == row['sha256'], 'helper_drift'
        write(stage / name, data)
    write(stage/'activate.php', fetch(commit, PREFIX+'activate.php'))
    modules = {}
    for name in ['activation_common', 'fcgi']:
        spec = importlib.util.spec_from_file_location(name, stage/(name+'.py'))
        module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module); modules[name] = module
    common, fcgi = modules['activation_common'], modules['fcgi']; fcgi.RELEASE = RELEASE
    results = []
    with common.temporary_primary(stage) as (primary, script_name):
        for target in common.observe_pools():
            assert common.owned_socket(target, os.getuid()), 'pool_owner_changed'
            params = {'GATEWAY_INTERFACE':'CGI/1.1','SERVER_PROTOCOL':'HTTP/1.1','REQUEST_METHOD':'POST',
                'SCRIPT_FILENAME':str(primary),'SCRIPT_NAME':script_name,'REQUEST_URI':script_name,
                'DOCUMENT_ROOT':str(HOME/'public_html'),'SERVER_NAME':'console.servernet.cloud',
                'HTTP_HOST':'console.servernet.cloud','SERVER_PORT':'443','HTTPS':'on','REMOTE_ADDR':'127.0.0.1',
                'CONTENT_LENGTH':'0','SERVERNET_LOCAL_RELEASE':RELEASE,
                'SERVERNET_STUDIO_PLAN':str(stage/'prepared.json'),'SERVERNET_STUDIO_PHASE':phase}
            with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as connection:
                connection.settimeout(30); connection.connect(str(target))
                connection.sendall(fcgi.record(1,struct.pack('!HB5s',1,0,b'\0'*5))
                    +fcgi.record(4,fcgi.encode_params(params))+fcgi.record(4)+fcgi.record(5))
                result = fcgi.receive(connection,time.monotonic()+30)
            assert result['invalidated'] == 2 and result['phase'] == phase, 'activation_failed'
            if phase == 'after':
                assert result['input_only_priced'] == result['models'].get('embedding',0)+result['models'].get('rerank',0)
            results.append(result)
    return results

def run(mode, commit, stage):
    manifest = json.loads(fetch(commit, PREFIX+'manifest.json'))
    config = STUDIO/'runtime/config.json'
    if mode == 'prepare':
        assert not (stage/'prepared.json').exists(), 'already_prepared'
        appdata = json.loads(subprocess.check_output(['/usr/local/cpanel/bin/uapi','--output=json','PassengerApps','list_applications']))
        registered = appdata['result']['data']['ServerNetStudio']
        assert registered['path'] == str(STUDIO) and registered['domain'] == 'studio.servernet.cloud' and str(registered['enabled']) == '1'
        assert not config.is_symlink() and config.stat().st_uid == os.getuid() and config.stat().st_nlink == 1
        assert config.stat().st_mode & 0o777 in [0o400,0o600], 'config_not_private'
        configuration = json.loads(config.read_text())
        assert configuration['SERVERNET_STUDIO_SOURCE_URL'] == manifest['source_before'], 'source_link_changed'
        payload = fetch(commit, PREFIX+'studio-payload.tar.gz')
        assert sha(payload) == manifest['payload_sha256'], 'payload_drift'
        with tarfile.open(fileobj=io.BytesIO(payload), mode='r:gz') as archive:
            expected = {row['payload'] for row in manifest['files'] if 'payload' in row}
            assert {m.name for m in archive.getmembers()} == expected and all(m.isfile() for m in archive.getmembers())
            for i,row in enumerate(manifest['files']):
                assert '..' not in Path(row['destination']).parts and matches(row, 'before'), 'live_hash_changed'
                data = archive.extractfile(row['payload']).read() if 'payload' in row else fetch(commit,row['source'])
                assert sha(data) == row['after'], 'source_hash_mismatch'
                write(stage/('new-'+str(i)),data)
                path = HOME/row['destination']
                if path.exists(): write(stage/('old-'+str(i)),path.read_bytes())
                row['mode'] = path.stat().st_mode & 0o777 if path.exists() else 0o644
                if row['destination'].endswith('.php'):
                    subprocess.run([PHP,'-l',str(stage/('new-'+str(i)))],check=True,stdout=subprocess.DEVNULL)
        write(stage/'config-before.bin',config.read_bytes())
        configuration['SERVERNET_STUDIO_SOURCE_URL'] = 'https://github.com/servernetir/servernet-studio/tree/'+manifest['studio_commit']
        after = (json.dumps(configuration,indent=2)+'\n').encode(); write(stage/'config-after.bin',after)
        plan = {'files':manifest['files'],'config_before':sha(config.read_bytes()),'config_after':sha(after),
            'config_mode':config.stat().st_mode & 0o777}
        write(stage/'prepared.json',json.dumps(plan).encode())
        print(json.dumps({'status':'prepared','files':len(plan['files']),'commit':commit})); return
    plan = json.loads((stage/'prepared.json').read_text()); rows = plan['files']
    phase = 'after' if mode in ['verify','rollback'] else 'before'
    assert all(matches(row, phase) for row in rows), 'live_hash_changed'
    assert sha(config.read_bytes()) == plan['config_'+phase], 'configuration_changed'
    if mode == 'verify':
        print(json.dumps({'status':'verified','files':len(rows),'commit':commit})); return
    if mode == 'rollback':
        for i,row in reversed(list(enumerate(rows))):
            if row['before'] is not None: write(HOME/row['destination'],(stage/('old-'+str(i))).read_bytes(),row['mode'])
        write(config,(stage/'config-before.bin').read_bytes(),plan['config_mode'])
        activate(stage,manifest,commit,'before'); (STUDIO/'tmp/restart.txt').touch()
        print(json.dumps({'status':'rolled_back','new_hashed_assets_retained':True})); return
    assert mode == 'apply'
    changed = []
    try:
        for i,row in enumerate(rows):
            assert matches(row, 'before'), 'concurrent_file_change'
            if row['before'] is None and current(row) == row['after']:
                continue  # A prior failed activation/rollback retained these exact immutable assets.
            write(HOME/row['destination'],(stage/('new-'+str(i))).read_bytes(),row['mode']); changed.append((i,row))
        assert sha(config.read_bytes()) == plan['config_before'], 'concurrent_config_change'
        write(config,(stage/'config-after.bin').read_bytes(),plan['config_mode'])
        results = activate(stage,manifest,commit,'after'); (STUDIO/'tmp/restart.txt').touch()
    except Exception:
        for i,row in reversed(changed):
            assert current(row) == row['after'], 'rollback_refused_concurrent_change'
            if row['before'] is not None: write(HOME/row['destination'],(stage/('old-'+str(i))).read_bytes(),row['mode'])
        if sha(config.read_bytes()) == plan['config_after']:
            write(config,(stage/'config-before.bin').read_bytes(),plan['config_mode'])
        if changed: activate(stage,manifest,commit,'before'); (STUDIO/'tmp/restart.txt').touch()
        raise
    receipt = {'status':'installed','files':len(rows),'core_commit':commit,'studio_commit':manifest['studio_commit'],'activation':results}
    write(stage/'receipt.json',json.dumps(receipt).encode()); print(json.dumps(receipt))

if __name__ == '__main__':
    mode,commit = sys.argv[1:3]
    assert mode in ['prepare','apply','verify','rollback'] and re.fullmatch('[a-f0-9]{40}',commit)
    assert os.getuid()!=0 and Path.home()==HOME, 'wrong_account'
    stage = HOME/'deploy-backups'/(RELEASE+'-'+commit[:12]); stage.mkdir(parents=True,exist_ok=True,mode=0o700)
    os.chmod(stage,0o700)
    with (ROOT/'storage/framework/servernet-release.lock').open('a') as core_lock, (STUDIO/'runtime/catalogue-release.lock').open('a') as studio_lock:
        fcntl.flock(core_lock,fcntl.LOCK_EX|fcntl.LOCK_NB); fcntl.flock(studio_lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        run(mode,commit,stage)
