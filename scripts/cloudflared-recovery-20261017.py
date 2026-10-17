"""Guarded recovery on ir.servernet.cloud. No credentials are emitted."""
import datetime
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tarfile
import urllib.request

BASE = Path('/root/servernet-cloudflared-recovery-20261017')
VERSION = '2026.10.1'
SHA256 = '200573c872174ab449965655febadef8edfbb144ebcaa391b700b2dedbadb3cd'
DEB = BASE / 'cloudflared-linux-amd64.deb'


def clean(value):
    text = str(value)
    text = re.sub(r'(?i)(--token(?:=|\s+)|token:\s*)\S+', r'\1[REDACTED]', text)
    text = re.sub(r'eyJ[A-Za-z0-9_=-]{30,}', '[REDACTED]', text)
    return re.sub(r'[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}', '[ID]', text)


def run(args, timeout=35, check=True, emit=True, env=None):
    result = subprocess.run(args, capture_output=True, text=True, timeout=timeout, env=env)
    if emit:
        print(clean(result.stdout + result.stderr), flush=True)
    if check and result.returncode:
        raise RuntimeError('Command failed: ' + args[0] + ' rc=' + str(result.returncode))
    return result


def snapshot(label):
    commands = {
        'iptables': ['iptables-save'],
        'routes': ['ip', '-4', 'route', 'show', 'table', 'all'],
        'rules': ['ip', '-4', 'rule', 'show'],
        'guests': ['qm', 'list'],
        'containers': ['pct', 'list'],
        'cloudflared': ['systemctl', 'show', 'cloudflared', '-p', 'ActiveState', '-p', 'SubState', '-p', 'ExecMainStatus', '-p', 'NRestarts', '-p', 'MainPID'],
    }
    for name, args in commands.items():
        result = run(args, emit=False)
        (BASE / (label + '-' + name + '.txt')).write_text(result.stdout)


def prepare():
    assert not Path('/usr/bin/cloudflared').exists(), 'Binary now exists; reassess.'
    assert not Path('/etc/cloudflared').exists(), 'Config now exists; reassess.'
    assert Path('/root/.cloudflared/config.yml').is_file()
    BASE.mkdir(mode=0o700)  # Deliberately refuse a blind rerun.
    os.umask(0o077)
    with tarfile.open(BASE / 'before-config.tar.gz', 'w:gz') as archive:
        for path in ['/root/.cloudflared', '/etc/systemd/system/cloudflared.service', '/etc/systemd/system/cloudflared.service.d', '/etc/systemd/system/cloudflared-update.service', '/etc/systemd/system/cloudflared-update.timer']:
            archive.add(path, arcname=path.lstrip('/'))
    snapshot('before')
    req = urllib.request.Request('https://github.com/cloudflare/cloudflared/releases/download/' + VERSION + '/cloudflared-linux-amd64.deb', headers={'User-Agent': 'ServerNet-recovery'})
    with urllib.request.urlopen(req, timeout=45) as response, DEB.open('xb') as target:
        shutil.copyfileobj(response, target)
    actual = hashlib.sha256(DEB.read_bytes()).hexdigest()
    assert actual == SHA256, 'Package digest mismatch'
    print('PACKAGE_SHA256', actual, flush=True)
    run(['dpkg-deb', '-f', str(DEB), 'Package', 'Version', 'Architecture'])
    run(['dpkg-deb', '-e', str(DEB), str(BASE / 'control')])
    for script in ['preinst', 'postinst', 'prerm', 'postrm']:
        p = BASE / 'control' / script
        if p.exists():
            print('MAINTAINER_SCRIPT', script, clean(p.read_text()), flush=True)
    run(['dpkg-deb', '-x', str(DEB), str(BASE / 'stage')])
    run([str(BASE / 'stage/usr/bin/cloudflared'), '--version'])
    print('PREPARED', datetime.datetime.now(datetime.timezone.utc).isoformat(), flush=True)


def inventory(binary):
    result = run([str(binary), 'tunnel', '--origincert', '/root/.cloudflared/cert.pem', 'list', '--output', 'json'], emit=False)
    tunnels = json.loads(result.stdout)
    (BASE / 'tunnels-private.json').write_text(json.dumps(tunnels))
    for tunnel in tunnels:
        print('TUNNEL', json.dumps({key: tunnel.get(key) for key in ['name', 'created_at', 'deleted_at', 'status']}, ensure_ascii=False), 'connections', len(tunnel.get('connections') or []), flush=True)


def apply():
    assert BASE.is_dir() and (BASE / 'before-config.tar.gz').is_file()
    assert hashlib.sha256(DEB.read_bytes()).hexdigest() == SHA256
    assert not Path('/usr/bin/cloudflared').exists(), 'Binary now exists; reassess.'
    assert not Path('/etc/cloudflared').exists(), 'Config now exists; reassess.'
    # The old local config names a tunnel which no longer exists. The retained
    # credential actually belongs to the remotely managed pve-tunnel. Use its
    # existing cloud configuration instead of resurrecting that stale ingress.
    tunnels = json.loads((BASE / 'api-tunnels-private.json').read_text())['result']
    tunnel = next(t for t in tunnels if t['name'] == 'pve-tunnel')
    assert tunnel['config_src'] == 'cloudflare' and tunnel['status'] == 'down'
    staged = str(BASE / 'stage/usr/bin/cloudflared')
    result = run([staged, 'tunnel', '--origincert', '/root/.cloudflared/cert.pem', 'token', 'pve-tunnel'], emit=False)
    token = result.stdout.strip()
    payload = json.loads(base64.b64decode(token))
    assert payload.get('t') == tunnel['id'], 'Token identity mismatch'
    assert payload.get('a') and payload.get('s'), 'Incomplete token'
    override = Path('/etc/systemd/system/cloudflared.service.d/override.conf')
    original = override.read_text()
    expected = 'ExecStart=/usr/bin/cloudflared --no-autoupdate --config /etc/cloudflared/config.yml tunnel run'
    assert expected in original and '--token' not in original
    os.umask(0o077)
    run(['systemctl', 'stop', 'cloudflared'])
    Path('/etc/cloudflared').mkdir(mode=0o700)
    Path('/etc/cloudflared/config.yml').write_text('protocol: http2\nmetrics: 127.0.0.1:20241\n')
    os.chmod('/etc/cloudflared/config.yml', 0o600)
    Path('/etc/cloudflared/pve-tunnel.token').write_text(token + '\n')
    os.chmod('/etc/cloudflared/pve-tunnel.token', 0o600)
    run(['dpkg', '-i', str(DEB)], timeout=60)
    override.write_text(original.replace(expected, expected + ' --token-file /etc/cloudflared/pve-tunnel.token'))
    os.chmod(override, 0o644)
    run(['/usr/bin/cloudflared', '--version'])
    run(['systemd-analyze', 'verify', '/etc/systemd/system/cloudflared.service'])
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'enable', 'cloudflared'])
    run(['systemctl', 'restart', 'cloudflared'])
    snapshot('after-install')
    print('APPLIED', datetime.datetime.now(datetime.timezone.utc).isoformat(), flush=True)


def persist_remote():
    assert Path('/usr/bin/cloudflared').is_file()
    # The relay canary must prove readiness before committing its transport.
    with urllib.request.urlopen('http://127.0.0.1:20242/ready', timeout=5) as r:
        assert r.status == 200
    deployment = BASE / 'deployment'
    files = {
        'servernet-cloudflared-egress.py': '/usr/local/sbin/servernet-cloudflared-egress',
        'servernet-cloudflared-egress.service': '/etc/systemd/system/servernet-cloudflared-egress.service',
        'servernet-cloudflared-egress.timer': '/etc/systemd/system/servernet-cloudflared-egress.timer',
        'cloudflared@.service': '/etc/systemd/system/cloudflared@.service',
        'cloudflared-remote-override.conf': '/etc/systemd/system/cloudflared.service.d/override.conf',
    }
    for name, dest in files.items():
        assert (deployment / name).is_file()
        if name != 'cloudflared-remote-override.conf':
            assert not Path(dest).exists(), 'New deployment target already exists; reassess.'
    for name, dest in files.items():
        shutil.copyfile(deployment / name, dest)
        os.chmod(dest, 0o755 if name.endswith('.py') else 0o644)
    for name, port in [('config', 20241), ('oyaz', 20243), ('adak', 20244)]:
        token = Path('/etc/cloudflared') / (('pve-tunnel' if name == 'config' else name) + '.token')
        assert token.is_file() and token.stat().st_mode & 0o077 == 0
        config = Path('/etc/cloudflared') / (name + '.yml')
        config.write_text('protocol: http2\nedge-ip-version: "4"\nedge-bind-address: 10.10.10.1\nmetrics: 127.0.0.1:' + str(port) + '\n')
        os.chmod(config, 0o600)
    run(['systemd-analyze', 'verify', '/etc/systemd/system/servernet-cloudflared-egress.service', '/etc/systemd/system/servernet-cloudflared-egress.timer', '/etc/systemd/system/cloudflared.service', '/etc/systemd/system/cloudflared@.service'])
    run(['systemctl', 'daemon-reload'])
    run(['systemctl', 'enable', '--now', 'servernet-cloudflared-egress.service', 'servernet-cloudflared-egress.timer'])
    run(['systemctl', 'restart', 'cloudflared'], timeout=90)
    run(['systemctl', 'enable', '--now', 'cloudflared@oyaz.service', 'cloudflared@adak.service'])
    snapshot('after-persist')
    print('PERSISTED', datetime.datetime.now(datetime.timezone.utc).isoformat(), flush=True)


assert run(['hostname'], emit=False).stdout.strip() == 'ir.servernet.cloud'
action = sys.argv[1]
if action == 'prepare':
    prepare()
elif action == 'inventory-stage':
    inventory(BASE / 'stage/usr/bin/cloudflared')
elif action == 'apply':
    apply()
elif action == 'inventory':
    inventory(Path('/usr/bin/cloudflared'))
elif action == 'persist-remote':
    persist_remote()
else:
    raise ValueError(action)
