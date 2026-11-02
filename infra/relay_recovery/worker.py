"""Independent WHM scheduler entry point with a private, fixed local adapter.

This entry point never interprets network reports as shell commands. All effects
require a committed core intent and the locally installed integration adapter.
Missing integration/backup/agent evidence is a blocker, not an empty success.
"""
from __future__ import annotations

import argparse
import dataclasses
import fcntl
import hashlib
import json
import math
import os
from pathlib import Path
import subprocess
import sys
import time

from .core import Config, Engine, Journal, RecoveryError
from .gateway import GatewayError, private_file, private_state_dir
from .protocol import private_file as executable_file


def atomic_json(path: Path, value: dict) -> None:
    raw = json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False).encode()
    temp = path.with_name(path.name+'.tmp-'+str(os.getpid()))
    fd = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    try:
        with os.fdopen(fd, 'wb') as out:
            out.write(raw); out.flush(); os.fsync(out.fileno())
        os.replace(temp, path)
        directory = os.open(path.parent, os.O_DIRECTORY)
        try: os.fsync(directory)
        finally: os.close(directory)
    finally:
        if temp.exists(): temp.unlink()


def invoke_adapter(config: dict, action: str, payload: dict) -> dict:
    """Only trusted, pinned executable paths from a private local config."""
    if action not in {'collect', 'CREATE', 'RECONCILE_CREATE', 'PROMOTE',
                      'RECONCILE_PROMOTION', 'DELETE_CANDIDATE',
                      'RECONCILE_DELETE_CANDIDATE', 'RETIRE', 'RECONCILE_RETIRE'}:
        raise RecoveryError('Invalid adapter action')
    entry = config.get('adapter')
    if not isinstance(entry, dict):
        raise RecoveryError('Integration adapter not configured')
    path = executable_file(entry['path'], root_owned=False, executable=True)
    if hashlib.sha256(path.read_bytes()).hexdigest() != entry.get('sha256'):
        raise RecoveryError('Integration adapter hash mismatch')
    if action.startswith('RECONCILE_') and payload.get('intent', {}).get('payload', {}).get('read_only') is not True:
        raise RecoveryError('Reconciliation must be read only')
    # Use the same bounded runner as the Iran agent: no shell, process group
    # timeout, capped output. The local adapter owns all credential handling.
    from .agent import _bounded_process
    code, output = _bounded_process([str(path), action],
                                    json.dumps(payload, separators=(',', ':'), allow_nan=False).encode(),
                                    timeout=60 if action == 'collect' else 30)
    if code != 0:
        raise RecoveryError('Integration adapter failed')
    response = json.loads(output)
    if not isinstance(response, dict):
        raise RecoveryError('Invalid adapter result')
    return response


def tick(config: dict, now: int, adapter=invoke_adapter, *, clock=None) -> dict:
    """Advance the observation/claim clock across blocking integration I/O.

    Production anchors UTC once at invocation and advances using monotonic
    elapsed time. The integer UTC anchor has a subsecond uncertainty: a 0.2s
    operation can cross a wall-clock second. Resolve only that one-second
    interval using wall time, clamped between the monotonic floor/ceiling;
    never stamp a fast effect's intent one second in its actual future and
    never follow an arbitrary wall-clock jump. Tests may inject an integer
    UTC clock without consulting the real host.
    """
    if type(now) is not int or now < 0:
        raise RecoveryError('Invalid worker clock')
    started = time.monotonic()
    last_clock = now
    last_elapsed = 0.0

    def current_time() -> int:
        nonlocal last_clock, last_elapsed
        if clock is None:
            elapsed = time.monotonic() - started
            if not math.isfinite(elapsed) or elapsed < last_elapsed or elapsed < 0:
                raise RecoveryError('Worker monotonic clock regressed')
            last_elapsed = elapsed
            value = min(now + math.ceil(elapsed),
                        max(now + math.floor(elapsed), int(time.time())))
        else:
            value = clock()
        if type(value) is not int or value < last_clock:
            raise RecoveryError('Worker clock regressed')
        last_clock = value
        return value

    try:
        state_dir = private_state_dir(Path(config['state_dir']))
    except GatewayError:
        raise RecoveryError('Private state required') from None
    policy_values = dict(config.get('policy', {}))
    if 'critical_consumers' in policy_values:
        policy_values['critical_consumers'] = tuple(policy_values['critical_consumers'])
    policy = Config(**policy_values)
    policy.validate()
    binding = config.get('binding', {})
    if (str(binding.get('owner_id', '')) != policy.owner_id
            or str(binding.get('service_id', '')) != policy.service_id):
        raise RecoveryError('Controller owner binding mismatch')
    if not config.get('active'):
        result = {'schema': 'servernet.relay.controller.v1', 'at': now,
                  'mode': 'blocked', 'reason': 'ACTIVE_ADOPTION_NOT_COMPLETE',
                  'effects_enabled': False, 'effect_performed': False}
        atomic_json(state_dir/'status.json', result)
        return result
    journal = Journal(state_dir/'controller.sqlite3')
    # The immutable startup seed is not the current generation after promotion.
    # Initialize only an empty journal; never overwrite/re-adopt on every tick.
    with journal.transaction() as db:
        initialized = db.execute('SELECT 1 FROM state WHERE id=1').fetchone() is not None
    if not initialized:
        journal.initialize(config['active'], now)
    current = journal.snapshot()['active']
    if current.get('project_id') != policy.project_id or current.get('provider') != 'hetzner':
        raise RecoveryError('Journal provider scope mismatch')
    engine = Engine(policy, journal)
    try:
        collected = adapter(config, 'collect', {'now': current_time(), 'state': journal.snapshot(),
                                               'binding': binding})
        if collected.get('schema') != 'servernet.relay.evidence.v1' or not isinstance(collected.get('evidence'), dict):
            raise RecoveryError('Invalid evidence envelope')
        evidence = collected['evidence']
    except Exception:
        evidence = {}
    decision_at = current_time()
    state = engine.observe(evidence, decision_at)
    intent = engine.plan(state, evidence, decision_at)
    performed = False
    if intent.kind != 'HOLD':
        # No call to an external adapter happens before durable journal commit.
        claimed_at = current_time()
        engine.commit_intent(intent, evidence, claimed_at)
        try:
            envelope = adapter(config, intent.kind, {'now': claimed_at, 'intent': intent.to_dict(),
                                                    'evidence': evidence})
            if envelope.get('schema') != 'servernet.relay.effect.v1':
                raise RecoveryError('Invalid effect envelope')
            engine.record_effect(intent.id, envelope['receipt'], current_time())
            performed = True
        except Exception:
            # Preserve ambiguous operation. Next tick can only reconcile it.
            # Exception text may include secrets; it never enters status output.
            pass
    result = {'schema': 'servernet.relay.controller.v1', 'at': current_time(),
              'mode': 'enabled' if policy.enabled else 'observe_only',
              'phase': journal.snapshot()['phase'], 'intent': intent.kind,
              'reason': intent.reason, 'retry_at': intent.retry_at,
              'effects_enabled': policy.enabled, 'effect_performed': performed}
    atomic_json(state_dir/'status.json', result)
    return result


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument('--config', type=Path, required=True)
    p.add_argument('--status', action='store_true')
    args = p.parse_args()
    os.umask(0o077)
    try:
        config = json.loads(private_file(args.config))
        state_dir = private_state_dir(Path(config['state_dir']))
        if args.status:
            print(private_file(state_dir/'status.json').decode())
            return 0
        lock = os.open(state_dir/'worker.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            os.close(lock)
            return 0
        try:
            print(json.dumps(tick(config, int(time.time())), separators=(',', ':')))
        finally:
            os.close(lock)
        return 0
    except Exception:
        print('{"ok":false,"error":"controller_precondition_failed","effects_enabled":false}')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
