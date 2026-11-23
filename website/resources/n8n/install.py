#!/usr/bin/env python3
"""Runs only on a newly allocated customer VM. No secrets go to stdout or argv."""
import fcntl
import json
import os
from pathlib import Path
import secrets
import subprocess
import time
import urllib.error
import urllib.request

ROOT = Path('/opt/servernet-n8n')


def write(name, content):
    path = ROOT / name
    path.write_text(content)
    path.chmod(0o600)


def run(args, timeout=600):
    for attempt in range(3):
        try:
            subprocess.run(args, check=True, timeout=timeout, stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL, env={**os.environ, 'DEBIAN_FRONTEND': 'noninteractive'})
            return
        except (subprocess.CalledProcessError, subprocess.TimeoutExpired):
            if attempt == 2:
                raise
            time.sleep(5)


def api(path, payload=None):
    data = None if payload is None else json.dumps(payload).encode()
    request = urllib.request.Request('http://127.0.0.1:5678' + path, data=data,
                                     headers={'Content-Type': 'application/json'})
    with urllib.request.urlopen(request, timeout=5) as response:
        return json.load(response)


def install_docker():
    run(['apt-get', 'update', '-qq'])
    run(['apt-get', 'install', '-y', '-qq', 'curl', 'ca-certificates'])
    release = dict(line.split('=', 1) for line in Path('/etc/os-release').read_text().splitlines() if '=' in line)
    distro = release['ID'].strip(chr(34))
    codename = release['VERSION_CODENAME'].strip(chr(34))
    if distro not in ['ubuntu', 'debian'] or not codename.isalnum():
        raise RuntimeError('unsupported_distribution')
    architecture = subprocess.check_output(['dpkg', '--print-architecture'], text=True).strip()
    if architecture not in ['amd64', 'arm64']:
        raise RuntimeError('unsupported_architecture')
    Path('/etc/apt/keyrings').mkdir(mode=0o755, exist_ok=True)
    key = Path('/etc/apt/keyrings/servernet-docker.asc')
    try:
        with urllib.request.urlopen('https://download.docker.com/linux/' + distro + '/gpg', timeout=30) as response:
            key.write_bytes(response.read())
    except urllib.error.HTTPError as error:
        # Docker's repository returns HTTP 403 in Iran. Ubuntu's signed
        # distribution packages provide Docker and Compose v2 without it.
        if error.code != 403 or distro != 'ubuntu':
            raise
        write('phase', 'distribution_docker_packages')
        run(['apt-get', 'install', '-y', '-qq', 'docker.io', 'docker-compose-v2'])
        run(['systemctl', 'enable', '--now', 'docker'])
        return
    key.chmod(0o644)
    Path('/etc/apt/sources.list.d/servernet-docker.list').write_text(
        f'deb [arch={architecture} signed-by={key}] https://download.docker.com/linux/{distro} {codename} stable\n')
    run(['apt-get', 'update', '-qq'])
    run(['apt-get', 'install', '-y', '-qq', 'docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-compose-plugin'])
    run(['systemctl', 'enable', '--now', 'docker'])


def install():
    spec = json.loads((ROOT / 'request.json').read_text())
    # Verify actual resources before announcing delivery. Allow normal kernel/filesystem overhead.
    memory = int(next(line.split()[1] for line in Path('/proc/meminfo').read_text().splitlines()
                      if line.startswith('MemTotal:'))) // 1024
    disk = os.statvfs('/')
    if os.cpu_count() < spec['vcpu'] or memory + 256 < spec['ram_mb'] \
            or disk.f_blocks * disk.f_frsize < spec['disk_gb'] * 10**9 * 0.85:
        raise RuntimeError('allocated_resources_mismatch')
    write('phase', 'docker_setup')
    install_docker()
    if not (ROOT / '.env').exists():
        write('.env', 'POSTGRES_PASSWORD=' + secrets.token_hex(32) + '\nN8N_ENCRYPTION_KEY='
              + secrets.token_hex(32) + '\n')
    host = spec['hostname']
    version = spec['version']
    compose = f'''services:
  postgres:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      POSTGRES_USER: n8n
      POSTGRES_DB: n8n
      POSTGRES_PASSWORD: ${{POSTGRES_PASSWORD}}
    volumes:
      - postgres_data:/var/lib/postgresql/data
    healthcheck:
      test: [CMD-SHELL, "pg_isready -U n8n -d n8n"]
      interval: 5s
      timeout: 5s
      retries: 30
  n8n:
    image: docker.n8n.io/n8nio/n8n:{version}
    restart: unless-stopped
    depends_on:
      postgres:
        condition: service_healthy
    ports:
      - "127.0.0.1:5678:5678"
    environment:
      DB_TYPE: postgresdb
      DB_POSTGRESDB_HOST: postgres
      DB_POSTGRESDB_DATABASE: n8n
      DB_POSTGRESDB_USER: n8n
      DB_POSTGRESDB_PASSWORD: ${{POSTGRES_PASSWORD}}
      N8N_ENCRYPTION_KEY: ${{N8N_ENCRYPTION_KEY}}
      N8N_HOST: {host}
      N8N_PROTOCOL: https
      N8N_PORT: 5678
      N8N_EDITOR_BASE_URL: https://{host}/
      N8N_WEBHOOK_URL: https://{host}/
      N8N_PROXY_HOPS: "{2 if spec['private'] else 1}"
      N8N_ENFORCE_SETTINGS_FILE_PERMISSIONS: "true"
      N8N_SECURE_COOKIE: "true"
      N8N_DIAGNOSTICS_ENABLED: "false"
      N8N_PERSONALIZATION_ENABLED: "false"
      N8N_DEFAULT_BINARY_DATA_MODE: filesystem
      EXECUTIONS_DATA_PRUNE: "true"
      EXECUTIONS_DATA_MAX_AGE: "168"
      GENERIC_TIMEZONE: Asia/Tehran
      TZ: Asia/Tehran
    volumes:
      - n8n_data:/home/node/.n8n
  caddy:
    image: caddy:2-alpine
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data
      - caddy_config:/config
volumes:
  postgres_data:
  n8n_data:
  caddy_data:
  caddy_config:
'''
    write('compose.yaml', compose)
    command = ['docker', 'compose', '--project-name', 'servernet-n8n', '--project-directory', str(ROOT)]
    # Public routing starts only AFTER the owner is initialized on loopback.
    write('phase', 'application_containers')
    run(command + ['up', '-d', 'postgres', 'n8n'], timeout=900)
    for _ in range(120):
        try:
            api('/healthz/readiness')
            break
        except (OSError, ValueError, urllib.error.HTTPError):
            time.sleep(3)
    else:
        raise RuntimeError('n8n_not_ready')
    write('phase', 'owner_setup')
    if not (ROOT / 'owner-initialized').exists():
        # Pinned n8n version: its OwnerSetupRequestDto accepts these fields.
        try:
            owner = api('/rest/owner/setup', {key: spec[key] for key in
                        ['email', 'firstName', 'lastName', 'password']})
        except urllib.error.HTTPError as error:
            if error.code != 400:
                raise
            # A previous response may have been lost after the owner was created.
            # Prove the same login works; never reset or replace an existing owner.
            owner = api('/rest/login', {'emailOrLdapLoginId': spec['email'], 'password': spec['password']})
        if owner.get('data', owner).get('email') != spec['email']:
            raise RuntimeError('owner_setup_unconfirmed')
        write('owner-initialized', str(spec['service_id']))
    receipt = {'service_id': spec['service_id'], 'nonce': spec['nonce'], 'version': version}
    marker = json.dumps(receipt, separators=(',', ':'))
    address = 'http://' + host if spec['private'] else host
    tls = '' if spec['private'] else 'tls ' + spec['email']
    write('Caddyfile', f'''{address} {{
    {tls}
    handle /.well-known/servernet-n8n {{
        header Content-Type application/json
        respond `{marker}` 200
    }}
    handle {{
        reverse_proxy n8n:5678
    }}
}}
''')
    # Caddy is the only public listener; PostgreSQL and n8n remain private.
    write('phase', 'public_listener')
    run(command + ['up', '-d', 'caddy'], timeout=300)
    write('ready.json', json.dumps(receipt))
    write('phase', 'ready')
    (ROOT / 'request.json').unlink(missing_ok=True)


if __name__ == '__main__':
    os.umask(0o077)
    ROOT.mkdir(mode=0o700, exist_ok=True)
    with (ROOT / 'install.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if not (ROOT / 'ready.json').exists():
            try:
                install()
            except Exception:
                write('failed', 'installation_failed')
                raise SystemExit(1)
