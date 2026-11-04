"""Authenticated data-only bridge for the independent Iran agent.

The PHP front controller passes an HTTP envelope to this private CLI over stdin.
No shell, provider operation or incoming command is executed by this module.
The request and response protocol lives in protocol.py. SQLite is local to WHM.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import stat
import sys
import time

from .protocol import ReplayStore, sign_message, verify_message, canonical_json, decode_json


class GatewayError(Exception):
    pass


class InboxFull(GatewayError):
    """Backpressure: an unconsumed authenticated receipt must never be dropped."""


INBOX_MAX_ROWS = 50_000
INBOX_MAX_BYTES = 64 * 1024 * 1024


def private_file(path: Path) -> bytes:
    s = path.lstat()
    if (not stat.S_ISREG(s.st_mode) or s.st_uid != os.geteuid()
            or stat.S_IMODE(s.st_mode) & 0o077 or s.st_size > 131072):
        raise GatewayError('private_file_required')
    return path.read_bytes()


def load_config(path: Path) -> dict:
    config = json.loads(private_file(path))
    private_state_dir(Path(config['state_dir']))
    if not isinstance(config.get('agents'), dict) or not config['agents']:
        raise GatewayError('agents_missing')
    return config


def private_state_dir(root: Path) -> Path:
    s = root.lstat()
    if not stat.S_ISDIR(s.st_mode) or s.st_uid != os.geteuid() or stat.S_IMODE(s.st_mode) & 0o077:
        raise GatewayError('private_state_required')
    for name in ('worker.lock', 'status.json', 'controller.sqlite3', 'controller.sqlite3.lock',
                 'agent-reports.sqlite3', 'request-replay.sqlite3'):
        path = root/name
        if path.is_symlink():
            raise GatewayError('state_symlink_refused')
    return root


def _private_database(path: Path) -> None:
    """Create the database privately before SQLite writes the first report."""
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(fd)
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid()
                or stat.S_IMODE(info.st_mode) & 0o077):
            raise GatewayError('private_database_required')
    finally:
        os.close(fd)


def _inbox_limit(config: dict, name: str, ceiling: int) -> int:
    value = config.get(name, ceiling)
    if type(value) is not int or not 1 <= value <= ceiling:
        raise GatewayError('invalid_inbox_limit')
    return value


def _store_report(root: Path, config: dict, received: dict, report_hash: str) -> None:
    """Commit latest health and the immutable receipt inbox before an ACK.

    Consumers read ``inbox`` in received_at/rowid order and set consumed_at only
    after applying its job receipts durably to their own journal. The body is
    the same authenticated envelope as ``latest``, including raw-request hash.
    Health-only polls have no job receipts to lose and use ``latest`` alone.
    No inbox row, consumed or pending, is silently pruned here. Capacity blocks
    the entire transaction and withholds ACK, leaving the agent outbox intact.
    Explicit retention of already-consumed rows is a separate operation.
    """
    db_path = root/'agent-reports.sqlite3'
    _private_database(db_path)
    encoded = json.dumps(received, separators=(',', ':'), allow_nan=False)
    has_receipts = bool(received['report'].get('jobs'))
    rows_cap = _inbox_limit(config, 'report_inbox_max_rows', INBOX_MAX_ROWS)
    bytes_cap = _inbox_limit(config, 'report_inbox_max_bytes', INBOX_MAX_BYTES)
    db = sqlite3.connect(db_path, timeout=5, isolation_level=None)
    try:
        db.execute('PRAGMA synchronous=FULL')
        db.execute('CREATE TABLE IF NOT EXISTS latest(agent_id TEXT PRIMARY KEY, received_at INTEGER, body TEXT)')
        db.execute('''CREATE TABLE IF NOT EXISTS inbox (
            agent_id TEXT NOT NULL, report_hash TEXT NOT NULL,
            received_at INTEGER NOT NULL, body_sha256 TEXT NOT NULL,
            body TEXT NOT NULL, consumed_at INTEGER,
            PRIMARY KEY(agent_id, report_hash))''')
        db.execute('CREATE INDEX IF NOT EXISTS inbox_pending ON inbox(consumed_at, received_at)')
        db.execute('BEGIN IMMEDIATE')
        if has_receipts:
            existing = db.execute('SELECT 1 FROM inbox WHERE agent_id=? AND report_hash=?',
                                  (received['agent_id'], report_hash)).fetchone()
            if existing is None:
                size = db.execute('SELECT COUNT(*), COALESCE(SUM(LENGTH(CAST(body AS BLOB))),0) FROM inbox').fetchone()
                if size[0] >= rows_cap or size[1] + len(encoded.encode()) > bytes_cap:
                    raise InboxFull('receipt_inbox_full')
                db.execute('INSERT INTO inbox VALUES(?,?,?,?,?,NULL)',
                           (received['agent_id'], report_hash, received['received_at'],
                            received['body_sha256'], encoded))
        db.execute('INSERT INTO latest VALUES(?,?,?) ON CONFLICT(agent_id) DO UPDATE SET received_at=excluded.received_at,body=excluded.body',
                   (received['agent_id'], received['received_at'], encoded))
        db.commit()
    except Exception:
        if db.in_transaction:
            db.rollback()
        raise
    finally:
        db.close()


def handle(envelope: dict, config: dict, now: int) -> dict:
    """Return a sanitized HTTP envelope; failures never include raw exceptions."""
    try:
        path = config.get('agent_path', '/relay-recovery-agent.php')
        if envelope.get('method') != 'POST' or envelope.get('path') != path:
            return {'status': 404, 'headers': {}, 'body': '{"ok":false}'}
        body_text = envelope.get('body')
        if not isinstance(body_text, str) or len(body_text.encode()) > 65536:
            raise GatewayError('request_size')
        raw = body_text.encode('utf-8')
        headers = envelope.get('headers', {})
        header_map = {str(k).lower(): str(v) for k, v in headers.items()}
        agent_id = header_map.get('x-relay-agent', '')
        binding = config['agents'].get(agent_id)
        if not isinstance(binding, dict) or binding.get('enabled') is not True:
            raise GatewayError('unauthorized')
        key = private_file(Path(binding['key_file']))
        root = Path(config['state_dir'])
        verify_message(key, agent_id, 'POST', path, raw, headers, now,
                       ReplayStore(root/'request-replay.sqlite3'))
        request = decode_json(raw)
        if (not isinstance(request, dict) or request.get('op') != 'poll'
                or request.get('agent_id') != agent_id
                or not isinstance(request.get('report'), dict)):
            raise GatewayError('invalid_poll')
        request_nonce = header_map['x-relay-nonce']
        report = request['report']
        report_hash = hashlib.sha256(canonical_json(report)).hexdigest()
        # Authenticated does not mean the reported business check succeeded.
        # Preserve observed_at; received_at is assigned locally and cannot be forged.
        received = {'agent_id': agent_id, 'received_at': now,
                    'request_nonce': request_nonce, 'report': report,
                    'body_sha256': hashlib.sha256(raw).hexdigest()}
        _store_report(root, config, received, report_hash)
        manifest = None
        manifest_path = root/('manifest-'+agent_id+'.json')
        if manifest_path.exists():
            candidate = json.loads(private_file(manifest_path))
            if (isinstance(candidate, dict) and candidate.get('agent_id') == agent_id
                    and type(candidate.get('expires_at')) is int
                    and candidate['expires_at'] > now):
                manifest = candidate
        response = json.dumps({'schema': 'servernet.relay.recovery.v1',
                               'request_nonce': request_nonce,
                               'report_ack': report_hash,
                               'manifest': manifest},
                              separators=(',', ':'), ensure_ascii=True, allow_nan=False).encode()
        signed = sign_message(key, agent_id, 'RESPONSE', path, response, now)
        return {'status': 200, 'headers': signed, 'body': response.decode()}
    except InboxFull:
        return {'status': 503, 'headers': {}, 'body': '{"ok":false,"error":"receipt_inbox_full"}'}
    except Exception:
        return {'status': 403, 'headers': {}, 'body': '{"ok":false,"error":"unauthorized_or_invalid"}'}


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', type=Path, required=True)
    args = parser.parse_args()
    os.umask(0o077)
    try:
        data = sys.stdin.buffer.read(200001)
        if len(data) > 200000:
            raise GatewayError('envelope_size')
        envelope = json.loads(data)
        result = handle(envelope, load_config(args.config), int(time.time()))
    except Exception:
        result = {'status': 503, 'headers': {}, 'body': '{"ok":false,"error":"controller_unavailable"}'}
    print(json.dumps(result, separators=(',', ':'), ensure_ascii=True))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
