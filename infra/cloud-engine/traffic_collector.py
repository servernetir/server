#!/usr/bin/env python3
"""One host tap observation per VM. Never reads guest-controlled counters or charges money."""
import argparse
import fcntl
import hashlib
import hmac
import json
import os
import re
import secrets
import ssl
import time
from datetime import datetime, timezone
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener, HTTPSHandler


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        raise RuntimeError('redirect_refused')


def atomic_json(path, data):
    path = Path(path)
    temporary = path.with_name(path.name + '.tmp')
    with open(temporary, 'w', opener=lambda p, flags: os.open(p, flags, 0o600)) as out:
        json.dump(data, out, separators=(',', ':'))
        out.flush()
        os.fsync(out.fileno())
    os.replace(temporary, path)
    descriptor = os.open(path.parent, os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def observation(assignment, previous, boot_id, sysfs=Path('/sys/class/net'), now=None):
    vmid = assignment['vmid']
    if type(vmid) is not int or not 1 <= vmid <= 999999999:
        raise ValueError('vmid_invalid')
    interface = 'tap' + str(vmid) + 'i0'
    interface_root = sysfs / interface
    ifindex = int((interface_root / 'ifindex').read_text())
    if ifindex < 1:
        raise ValueError('interface_generation_invalid')
    root = interface_root / 'statistics'
    # On a host tap, RX is transmitted BY the guest; TX is delivered TO it.
    inbound = int((root / 'tx_bytes').read_text())
    outbound = int((root / 'rx_bytes').read_text())
    if int((interface_root / 'ifindex').read_text()) != ifindex:
        raise ValueError('interface_changed_during_read')
    if min(inbound, outbound) < 0 or max(inbound, outbound) > 9223372036854775807:
        raise ValueError('counter_invalid')
    reset = not previous or previous.get('boot_id') != boot_id or previous.get('ifindex') != ifindex or previous.get('missing', False)
    if previous and (inbound < previous['in_bytes'] or outbound < previous['out_bytes']):
        reset = True
    epoch = secrets.token_hex(16) if reset else previous['epoch']
    sequence = 1 if reset else previous['sequence'] + 1
    sampled = datetime.fromtimestamp(time.time() if now is None else now, timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
    payload = {'vm_uuid': assignment['vm_uuid'], 'interface': interface, 'epoch': epoch,
               'sequence': sequence, 'in_bytes': inbound, 'out_bytes': outbound, 'sampled_at': sampled}
    return {'boot_id': boot_id, 'ifindex': ifindex, 'epoch': epoch, 'sequence': sequence,
            'in_bytes': inbound, 'out_bytes': outbound, 'pending': payload}


def transmit(endpoint, secret, payload, ca_file=None):
    target = urlsplit(endpoint)
    if target.scheme != 'https' or not target.hostname or target.username or target.password or target.fragment or target.query:
        raise ValueError('trusted_https_ingest_endpoint_required')
    body = json.dumps(payload, separators=(',', ':'), sort_keys=True).encode()
    stamp = str(int(time.time()))
    signature = hmac.new(secret, stamp.encode() + b'\n' + body, hashlib.sha256).hexdigest()
    context = ssl.create_default_context(cafile=ca_file)
    opener = build_opener(NoRedirect(), HTTPSHandler(context=context))
    request = Request(endpoint, data=body, method='POST', headers={'Content-Type': 'application/json',
                      'X-Engine-Time': stamp, 'X-Engine-Signature': signature})
    try:
        with opener.open(request, timeout=10) as response:
            result = json.loads(response.read(4097))
            if result.get('status') not in ('accepted', 'duplicate'):
                raise RuntimeError('ingest_receipt_invalid')
            return result
    except HTTPError as error:
        # Only this explicit timestamp rejection permits a new baseline. All
        # other errors retain the exact outbox for reconciliation/retry.
        if error.code == 422:
            detail = json.loads(error.read(4097))
            if detail.get('error') == 'traffic_timestamp_stale_or_future':
                return {'status': 'rebaseline_required'}
        raise RuntimeError('ingest_rejected') from None


def collect_once(config, state, secret, state_file, sysfs=Path('/sys/class/net'), boot_id=None, sender=transmit):
    boot_id = boot_id or Path('/proc/sys/kernel/random/boot_id').read_text().strip()
    assignments = config['assignments']
    if len(assignments) > 10000 or len({a['vm_uuid'] for a in assignments}) != len(assignments) or len({a['vmid'] for a in assignments}) != len(assignments):
        raise ValueError('assignments_duplicate_or_excessive')
    for assignment in assignments:
        identity = assignment['vm_uuid']
        previous = state.get(identity)
        if previous and previous.get('pending'):
            payload = previous['pending']
        else:
            try:
                state[identity] = observation(assignment, previous, boot_id, sysfs)
            except FileNotFoundError:
                if previous:
                    previous['missing'] = True
                    atomic_json(state_file, state)
                continue
            payload = state[identity]['pending']
            atomic_json(state_file, state)  # durable outbox BEFORE network submission
        receipt = sender(config['endpoint'], secret, payload, config.get('ca_file'))
        if receipt['status'] == 'rebaseline_required':
            state[identity]['missing'] = True
            state[identity]['pending'] = None
            state[identity]['discarded_stale_outbox'] = True
        else:
            state[identity]['pending'] = None
        atomic_json(state_file, state)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', required=True)
    parser.add_argument('--once', action='store_true')
    args = parser.parse_args()
    config = json.loads(Path(args.config).read_text())
    secret_path = Path(config['secret_file'])
    if secret_path.stat().st_mode & 0o077:
        raise RuntimeError('agent_secret_permissions_must_be_0600')
    secret = secret_path.read_bytes().strip()
    if len(secret) < 32:
        raise RuntimeError('agent_secret_too_short')
    state_file = Path(config['state_file'])
    state_file.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    with state_file.with_suffix('.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        state = json.loads(state_file.read_text()) if state_file.exists() else {}
        while True:
            collect_once(config, state, secret, state_file)
            if args.once:
                return
            time.sleep(max(2, min(60, int(config.get('interval_seconds', 10)))))


if __name__ == '__main__':
    main()
