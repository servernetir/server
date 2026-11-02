#!/usr/bin/env python3
"""Install only the owner's notification timer, preserving all existing cron lines."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess
import time

HOME = Path('/home/servernetcloud')
ROOT = HOME/'.hermes-assistant-20261102'
TAG = '# nava-relay-observer-v1'
LINE = '* * * * * /usr/bin/python3 /home/servernetcloud/.hermes-assistant-20261102/relay_notifier.py --config /home/servernetcloud/.hermes-assistant-20261102/relay-notifier.json > /home/servernetcloud/.hermes-assistant-20261102/relay-last-run.json 2>&1 '+TAG


def current():
    p = subprocess.run(['/usr/bin/crontab', '-l'], capture_output=True, timeout=15)
    if p.returncode: raise RuntimeError('existing_cron_unavailable')
    return p.stdout


def install():
    if os.geteuid() == 0 or Path.home() != HOME:
        raise RuntimeError('unexpected_account')
    os.umask(0o077)
    config = ROOT/'relay-notifier.json'
    if config.is_symlink() or stat.S_IMODE(config.stat().st_mode) != 0o600:
        raise RuntimeError('private_configuration_required')
    cfg = json.loads(config.read_text())
    for key, hash_key in [('delivery_command', 'adapter_sha256'), ('probe_command', 'probe_sha256')]:
        path = Path(cfg[key][-1])
        if path.is_symlink() or hashlib.sha256(path.read_bytes()).hexdigest() != cfg[hash_key]:
            raise RuntimeError('adapter_hash_drift')
    snapshot = json.loads((Path(cfg['state_dir'])/'status.json').read_text())
    if snapshot.get('schema') != 'servernet.hermes.relay-monitor.v1' or not isinstance(snapshot.get('at'), int) or not 0 <= time.time()-snapshot['at'] <= 120:
        raise RuntimeError('fresh_acceptance_required')
    notification = snapshot.get('notification')
    if isinstance(notification, dict) and notification.get('status') not in ('sent', None):
        raise RuntimeError('delivery_reconciliation_required')
    handles = []
    try:
        for name in ['.servernet-web-release.lock', 'servernet-release.lock']:
            fd = os.open(HOME/name, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB); handles.append(fd)
        before = current()
        matches = [s for s in before.decode().splitlines() if TAG in s]
        if matches and matches != [LINE]: raise RuntimeError('existing_observer_differs')
        if not matches:
            backup = ROOT/'crontab-before-observer.txt'
            with backup.open('xb') as out: out.write(before)
            after = before + (b'' if before.endswith(b'\n') else b'\n') + LINE.encode() + b'\n'
            if current() != before: raise RuntimeError('cron_changed_during_preflight')
            subprocess.run(['/usr/bin/crontab', '-'], input=after, check=True, capture_output=True, timeout=15)
            if current() != after: raise RuntimeError('cron_acceptance_failed')
        result = {'release': 'nava-relay-observer-v1', 'at': int(time.time()), 'installed': True,
                  'notification_only': True, 'existing_cron_lines_preserved': True, 'matching_entries': 1}
        (ROOT/'observer-installed.json').write_text(json.dumps(result)+'\n')
        return result
    finally:
        for fd in reversed(handles): os.close(fd)


if __name__ == '__main__':
    print(json.dumps(install(), sort_keys=True))
