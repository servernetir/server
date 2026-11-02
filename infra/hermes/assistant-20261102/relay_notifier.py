#!/usr/bin/env python3
"""Notification-only observer of the independent relay controller.

Never imports the recovery Journal or invokes a recovery adapter. All source
reads are read-only. Delivery uses a private, fixed local adapter and existing
application owner/Bale settings; this module accepts no bot tokens or keys.
"""
from __future__ import annotations

import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import selectors
import signal
import sqlite3
import stat
import subprocess
import time
from urllib.parse import quote

SCHEMA = 'servernet.hermes.relay-monitor.v1'
MAX_JSON = 1024 * 1024
MAX_AGE = 120


def integer(value):
    return type(value) is int


def encoded(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False, allow_nan=False)


def checked_file(path: Path, limit=MAX_JSON):
    if not path.is_absolute() or any(p.is_symlink() for p in (path, *path.parents)):
        raise ValueError('private_source_required')
    info = path.stat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077 or info.st_size > limit:
        raise ValueError('private_source_required')
    return path


def private_file(path: Path, limit=MAX_JSON):
    return checked_file(path, limit).read_bytes()


def private_directory(path: Path, create=False):
    if not path.is_absolute() or any(p.is_symlink() for p in (path, *path.parents)):
        raise ValueError('private_state_required')
    if create:
        path.mkdir(mode=0o700, exist_ok=True)
    info = path.stat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.geteuid() or info.st_mode & 0o077:
        raise ValueError('private_state_required')
    return path


def fresh(at, now, age=MAX_AGE):
    return integer(at) and 0 <= now - at <= age


def read_source(config, now):
    """Read the controller's own classification; TCP heartbeats are not health."""
    try:
        controller = {}
        if config.get('controller_config'):
            controller = json.loads(private_file(Path(config['controller_config'])))
        source = config.get('controller_state_dir') or controller.get('state_dir')
        if not source:
            return {'quality': 'unknown', 'reason': 'controller_not_configured'}
        root = private_directory(Path(source))
        status = json.loads(private_file(root / 'status.json'))
        if status.get('schema') != 'servernet.relay.controller.v1':
            raise ValueError('controller_schema')
        if status.get('mode') not in ('enabled', 'observe_only'):
            return {'quality': 'unknown', 'reason': 'controller_not_active'}
        if not fresh(status.get('at'), now):
            return {'quality': 'unknown', 'reason': 'controller_stale'}
        database = root / 'controller.sqlite3'
        checked_file(database, limit=64 * 1024 * 1024)
        db = sqlite3.connect('file:' + quote(str(database), safe='/') + '?mode=ro', uri=True, timeout=1)
        try:
            db.execute('PRAGMA query_only=ON')
            row = db.execute('SELECT body FROM state WHERE id=1').fetchone()
        finally:
            db.close()
        if not row or not isinstance(row[0], str) or len(row[0].encode()) > MAX_JSON:
            raise ValueError('controller_state')
        state = json.loads(row[0])
        if state.get('clock_unsafe') is not False:
            return {'quality': 'unknown', 'reason': 'controller_clock_unverified'}
        health = state.get('health', {})
        if not isinstance(health, dict) or not fresh(health.get('last_at'), now):
            return {'quality': 'unknown', 'reason': 'health_stale_or_missing'}
        quality = health.get('quality')
        if quality not in ('ok', 'bad'):
            return {'quality': 'unknown', 'reason': 'health_unavailable'}
        active = state.get('active', {})
        generation = active.get('generation') if isinstance(active, dict) else None
        if (not integer(generation) or generation < 1
                or not isinstance(active.get('config_hash'), str)
                or not 1 <= len(active['config_hash']) <= 256 or not active['config_hash'].strip()):
            raise ValueError('resource_binding')
        policy = controller.get('policy', {})
        suspect = policy.get('suspect_seconds', 600)
        recover = policy.get('recover_seconds', 600)
        for threshold in (suspect, recover):
            if not integer(threshold) or not 1 <= threshold <= 86400:
                raise ValueError('policy')
        result = {'quality': quality, 'at': health['last_at'], 'generation': generation,
                  'source_key': hashlib.sha256(encoded([generation, active['config_hash'], state.get('incident_id')]).encode()).hexdigest()}
        if quality == 'bad':
            started = health.get('bad_run_since')
            result.update(state='down' if integer(started) and 0 <= now - started and now - started >= suspect else 'suspect',
                          reason='confirmed_relay_failure' if integer(started) and 0 <= now - started and now - started >= suspect else 'failure_observation_in_progress')
        else:
            started = health.get('recovery_since')
            # Promotion explicitly clears health; phase=HEALTHY is never sufficient.
            confirmed = integer(started) and 0 <= now - started and now - started >= recover
            result.update(state='healthy' if confirmed else 'recovering',
                          reason='verified_relay_recovery' if confirmed else 'recovery_observation_in_progress')
        return result
    except Exception:
        # Paths, SQL details and raw exception text must never enter owner output.
        return {'quality': 'unknown', 'reason': 'controller_source_unavailable'}


def classify(source, previous):
    """Return next durable state and at most one event for this observation."""
    current = source.get('state', 'unknown') if source.get('quality') in ('ok', 'bad') else 'unknown'
    prior = dict(previous)
    next_state = dict(prior, monitor=current)
    kind = None
    if current == 'unknown':
        if prior.get('monitor') != 'unknown':
            kind = 'relay_monitor_unavailable'
    elif current == 'down':
        if not prior.get('outage_open') or prior.get('outage_key') != source.get('source_key'):
            kind = 'relay_outage'
        next_state.update(outage_open=True, outage_key=source.get('source_key'))
    elif current == 'healthy':
        if prior.get('outage_open'):
            kind = 'relay_recovered'
            next_state.update(outage_open=False)
        elif prior.get('monitor') == 'unknown':
            kind = 'relay_monitor_restored'
    elif prior.get('monitor') == 'unknown':
        kind = 'relay_monitor_restored'
    return next_state, kind


def event_payload(kind, source, now, event_id):
    return {'schema': SCHEMA, 'event_id': event_id, 'type': kind, 'at': now,
            'state': source.get('state', 'unknown') if source.get('quality') in ('ok', 'bad') else 'unknown',
            'reason': source.get('reason', 'health_unavailable'),
            'observation_at': source.get('at')}


def validate_command(config, command_key='delivery_command', hash_key='adapter_sha256'):
    argv = config.get(command_key)
    if not isinstance(argv, list) or not 1 <= len(argv) <= 4 or any(not isinstance(x, str) for x in argv):
        raise ValueError('delivery_unconfigured')
    resolved = []
    for value in argv:
        path = Path(value)
        if not path.is_absolute():
            raise ValueError('invalid_delivery_adapter')
        path = path.resolve(strict=True)
        info = path.stat()
        if not stat.S_ISREG(info.st_mode) or info.st_uid not in (0, os.geteuid()) or info.st_mode & 0o022:
            raise ValueError('invalid_delivery_adapter')
        resolved.append(str(path))
    if not os.access(resolved[0], os.X_OK):
        raise ValueError('invalid_delivery_adapter')
    if config.get(hash_key) and hashlib.sha256(Path(resolved[-1]).read_bytes()).hexdigest() != config[hash_key]:
        raise ValueError('delivery_adapter_changed')
    return resolved


def invoke_command(config, payload, command_key='delivery_command', hash_key='adapter_sha256'):
    """Bounded no-shell command; never returns raw process output."""
    child = None
    try:
        argv = validate_command(config, command_key, hash_key)
        child = subprocess.Popen(argv, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                 start_new_session=True, env={'PATH': '/usr/local/bin:/usr/bin:/bin', 'LANG': 'C.UTF-8'})
        child.stdin.write((encoded(payload) + '\n').encode())
        child.stdin.close()
        output = bytearray()
        total = 0
        end = time.monotonic() + 20
        with selectors.DefaultSelector() as selector:
            selector.register(child.stdout, selectors.EVENT_READ, True)
            selector.register(child.stderr, selectors.EVENT_READ, False)
            while selector.get_map():
                if time.monotonic() >= end:
                    raise ValueError('delivery_timeout')
                for key, _ in selector.select(min(0.25, max(0, end - time.monotonic()))):
                    data = os.read(key.fd, 4096)
                    if not data:
                        selector.unregister(key.fileobj)
                    else:
                        total += len(data)
                        if total > 16384:
                            raise ValueError('delivery_output_limit')
                        if key.data:
                            output.extend(data)
        code = child.wait(timeout=max(0.01, end - time.monotonic()))
        reply = json.loads(output)
        return reply if code == 0 and isinstance(reply, dict) else None
    except Exception:
        return None
    finally:
        if child is not None:
            if child.poll() is None:
                try:
                    os.killpg(child.pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
                child.wait()
            for stream in (child.stdin, child.stdout, child.stderr):
                if stream and not stream.closed:
                    stream.close()


def deliver(config, payload):
    """An ambiguous delivery is never retried by this observer."""
    reply = invoke_command(config, payload)
    return 'sent' if isinstance(reply, dict) and reply.get('sent') is True else 'uncertain'


def collect_probe(config, now):
    """Optional fixed read-only access path probe, independent of tunnel health."""
    if not config.get('probe_command'):
        return {'configured': False, 'available': None, 'at': None}
    reply = invoke_command(config, {'at': now}, 'probe_command', 'probe_sha256')
    if (not isinstance(reply, dict) or type(reply.get('available')) is not bool
            or not fresh(reply.get('at'), now)):
        return {'configured': True, 'available': None, 'at': None}
    return {'configured': True, 'available': reply['available'], 'at': reply['at']}


def classify_access(probe, previous, now):
    """Two distinct fresh samples; outages refer only to this access path."""
    state = dict(previous)
    observation = probe.get('at')
    value = probe.get('available')
    if not probe.get('configured'):
        return state, None, None
    if type(value) is not bool or not fresh(observation, now):
        state.update(streak=0, sample_at=None, sample_value=None)
        return state, {'state': 'unknown', 'reason': 'access_probe_unavailable', 'at': None}, None
    before = state.get('sample_at')
    if integer(before) and observation <= before:
        confirmed = state.get('confirmed')
        shown = confirmed if observation == before and state.get('streak', 0) >= 2 else 'unknown'
        return state, {'state': shown or 'unknown', 'reason': 'access_observation_unchanged', 'at': observation}, None
    continuous = integer(before) and 0 < observation - before <= MAX_AGE
    streak = state.get('streak', 0) + 1 if continuous and state.get('sample_value') is value else 1
    state.update(sample_at=observation, sample_value=value, streak=min(2, streak))
    kind = None
    shown = 'unknown'
    if streak >= 2:
        shown = 'available' if value else 'unavailable'
        prior = state.get('confirmed')
        if value is False and prior != 'unavailable':
            kind = 'relay_access_unavailable'
        elif value is True and prior == 'unavailable':
            kind = 'relay_access_restored'
        state['confirmed'] = shown
    reason = ('access_path_available' if value else 'access_path_unavailable') if streak >= 2 else 'access_observation_pending'
    return state, {'state': shown, 'reason': reason, 'at': observation}, kind


def atomic_status(root, snapshot):
    target = root / 'status.json'
    if target.is_symlink():
        raise ValueError('state_symlink')
    temp = root / ('status.tmp-' + str(os.getpid()))
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, 'w') as stream:
            stream.write(encoded(snapshot)); stream.flush(); os.fsync(stream.fileno())
        os.replace(temp, target)
    finally:
        if temp.exists():
            temp.unlink()


def run(config, now, sender=deliver, reader=read_source, prober=collect_probe):
    root = private_directory(Path(config['state_dir']), create=True)
    binding = config.get('owner_binding')
    if not isinstance(binding, str) or not 1 <= len(binding) <= 200:
        raise ValueError('owner_binding_required')
    owner_hash = hashlib.sha256(binding.encode()).hexdigest()
    lock = os.open(root / 'notifier.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return {'schema': SCHEMA, 'at': now, 'state': 'unknown', 'reason': 'observer_busy'}
        database = root / 'notifications.sqlite3'
        if database.is_symlink():
            raise ValueError('state_symlink')
        fd = os.open(database, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        os.close(fd)
        checked_file(database, 64 * 1024 * 1024)
        db = sqlite3.connect(database, timeout=1)
        try:
            db.execute('PRAGMA synchronous=FULL')
            db.execute('CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY,value TEXT NOT NULL)')
            db.execute('CREATE TABLE IF NOT EXISTS events (id TEXT PRIMARY KEY,owner TEXT NOT NULL,type TEXT NOT NULL,at INTEGER NOT NULL,payload TEXT NOT NULL,status TEXT NOT NULL)')
            # A previous process may have died after sending; never replay it.
            db.execute("UPDATE events SET status='uncertain' WHERE status='dispatching'")
            row = db.execute("SELECT value FROM metadata WHERE key='state'").fetchone()
            previous = json.loads(row[0]) if row else {}
            sequence_row = db.execute("SELECT value FROM metadata WHERE key='sequence'").fetchone()
            # Seed from total retained events when upgrading the former per-owner
            # counter. This exceeds every prior owner's local sequence.
            sequence = max(int(sequence_row[0]) if sequence_row else 0,
                           int(previous.get('sequence', 0)), db.execute('SELECT COUNT(*) FROM events').fetchone()[0])
            if previous.get('owner_hash') != owner_hash:
                previous = {'owner_hash': owner_hash, 'sequence': sequence}
            source = reader(config, now)
            next_state, kind = classify(source, previous)
            probe = prober(config, now)
            access_state, access, access_kind = classify_access(probe, previous.get('access', {}), now)
            next_state['access'] = access_state
            pending = []
            transitions = [(kind, source)] if kind else []
            if access_kind:
                transitions.append((access_kind, {'quality': 'ok' if probe['available'] else 'bad',
                    'state': access['state'], 'reason': access['reason'], 'at': access['at'], 'source_key': 'independent-access-path'}))
            # Separate durable claims ensure simultaneous controller/path changes
            # are neither merged nor silently lost.
            for event_kind, observation in transitions:
                sequence += 1
                event_id = hashlib.sha256(encoded([owner_hash, sequence, event_kind, observation.get('source_key')]).encode()).hexdigest()
                payload = event_payload(event_kind, observation, now, event_id)
                db.execute('INSERT INTO events VALUES(?,?,?,?,?,?)', (event_id, owner_hash, event_kind, now, encoded(payload), 'dispatching'))
                pending.append(payload)
            next_state.update(owner_hash=owner_hash, sequence=sequence)
            db.execute("INSERT INTO metadata VALUES('state',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", (encoded(next_state),))
            db.execute("INSERT INTO metadata VALUES('sequence',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", (str(sequence),))
            db.commit()
            for payload in pending:
                try:
                    outcome = sender(config, payload)
                except Exception:
                    outcome = 'uncertain'
                if outcome not in ('sent', 'uncertain'):
                    outcome = 'uncertain'
                db.execute('UPDATE events SET status=? WHERE id=?', (outcome, payload['event_id']))
                db.commit()
            last = db.execute('SELECT type,at,status FROM events WHERE owner=? ORDER BY rowid DESC LIMIT 1', (owner_hash,)).fetchone()
            snapshot = {'schema': SCHEMA, 'at': now, 'state': next_state['monitor'],
                        'reason': source.get('reason', 'health_unavailable'),
                        'observation_at': source.get('at'), 'outage_open': bool(next_state.get('outage_open')),
                        'access_path': access,
                        'notification': {'type': last[0], 'at': last[1], 'status': last[2]} if last else None}
            atomic_status(root, snapshot)
            return snapshot
        finally:
            db.close()
    finally:
        os.close(lock)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', type=Path, required=True)
    args = parser.parse_args()
    os.umask(0o077)
    try:
        config = json.loads(private_file(args.config))
        result = run(config, int(time.time()))
        print(encoded(result))
        return 0
    except Exception:
        print(encoded({'schema': SCHEMA, 'state': 'unknown', 'reason': 'observer_precondition_failed'}))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
