"""Explicit private enrollment for the adapter's isolated guest verifier.

This module authenticates supplied evidence only. It creates no listener,
transport, endpoint change or candidate enrollment, and has no success fallback.
"""
from __future__ import annotations

import base64
import hashlib
import hmac
import os
from pathlib import Path
import sqlite3
import stat
import time

try:
    from .isolated_acceptance import IsolatedAcceptanceVerifier, parameters
    from . import windows_probe_client as windows
    from .protocol import HEX64, IDENTIFIER, ProtocolError, ReplayStore, canonical_json, decode_json, private_file
except ImportError:
    from isolated_acceptance import IsolatedAcceptanceVerifier, parameters
    import windows_probe_client as windows
    from protocol import HEX64, IDENTIFIER, ProtocolError, ReplayStore, canonical_json, decode_json, private_file


SCHEMA = 'servernet.relay.isolated-verifier.v1'
REPLAY_NAME = 'isolated-guest-responses.sqlite3'
BASE_FIELDS = {'schema', 'enabled', 'agent_id'}
ACTIVE_FIELDS = BASE_FIELDS | {'guest_key_file', 'guest_key_sha256', 'state_dir',
                              'proxy_binding_sha256', 'access_policy'}


def _hash(value):
    if not isinstance(value, str) or not HEX64.fullmatch(value):
        raise ProtocolError('isolated_enrollment_pin')
    return value


def _path(value):
    if (not isinstance(value, str) or not value or '\x00' in value
            or not Path(value).is_absolute() or '..' in Path(value).parts):
        raise ProtocolError('isolated_enrollment_path')
    return Path(value)


def _read_pinned(path, digest, limit, root_owned):
    """Validate the actual opened inode as well as the pinned bytes."""
    digest = _hash(digest)
    path = private_file(_path(path), root_owned=root_owned)
    before = path.lstat()
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, 'rb') as source:
        opened = os.fstat(source.fileno())
        if (not stat.S_ISREG(opened.st_mode) or opened.st_nlink != 1
                or (opened.st_dev, opened.st_ino) != (before.st_dev, before.st_ino)
                or opened.st_mode & 0o077 or root_owned and opened.st_uid != 0):
            raise ProtocolError('isolated_enrollment_file_changed')
        raw = source.read(limit + 1)
        after = os.fstat(source.fileno())
    current = private_file(path, root_owned=root_owned).lstat()
    if ((after.st_size, after.st_mtime_ns, after.st_ctime_ns) !=
            (opened.st_size, opened.st_mtime_ns, opened.st_ctime_ns)
            or (current.st_dev, current.st_ino) != (opened.st_dev, opened.st_ino)):
        raise ProtocolError('isolated_enrollment_file_changed')
    if len(raw) > limit or not hmac.compare_digest(hashlib.sha256(raw).hexdigest(), digest):
        raise ProtocolError('isolated_enrollment_pin')
    return raw


def _state_directory(value, root_owned):
    path = _path(value)
    # Enrollment never creates a missing directory or discovers a state path.
    for current in (path, *path.parents):
        info = current.lstat()
        if (current.is_symlink() or not stat.S_ISDIR(info.st_mode)
                or root_owned and (info.st_uid != 0 or info.st_mode & 0o022)
                or current == path and info.st_mode & 0o077):
            raise ProtocolError('isolated_enrollment_state_permissions')
    return path


def _check_replay(path, root_owned, *, required=False):
    if required and not path.exists():
        raise ProtocolError('isolated_enrollment_replay_unavailable')
    if path.exists() or path.is_symlink():
        checked = private_file(path, root_owned=root_owned)
        if checked.lstat().st_nlink != 1:
            raise ProtocolError('isolated_enrollment_replay_alias')


class EnrolledVerifier(IsolatedAcceptanceVerifier):
    def __init__(self, key, replay, *, enrollment, guard, now):
        super().__init__(key, replay, now=now)
        self.enrollment, self.guard = enrollment, guard

    def verify(self, context, recipe):
        self.guard()  # Detect pin/key/replay drift immediately before acceptance.
        p = parameters(context)
        if (context['agent_id'] != self.enrollment['agent_id']
                or not hmac.compare_digest(p['proxy_binding_sha256'], self.enrollment['proxy_binding_sha256'])):
            raise ProtocolError('isolated_enrollment_scope')
        try:
            encoded = recipe['isolated_evidence']['guest']['body_base64']
            if not isinstance(encoded, str) or len(encoded) > windows.MAX_BODY * 2:
                raise ValueError()
            body = decode_json(base64.b64decode(encoded, validate=True))
        except (KeyError, TypeError, ValueError):
            raise ProtocolError('isolated_enrollment_guest_policy') from None
        policy = self.enrollment['access_policy']
        if (policy == windows.SOURCE_HMAC_POLICY and body.get('access_policy') != policy
                or policy == windows.DEFAULT_ACCESS_POLICY and 'access_policy' in body):
            raise ProtocolError('isolated_enrollment_guest_policy')
        try:
            return super().verify(context, recipe)
        except sqlite3.Error:
            raise ProtocolError('isolated_enrollment_replay_unavailable') from None


def load_verifier(reference, *, agent_id, root_owned=True, now=lambda: int(time.time())):
    """Load one pinned enrollment; absent or explicitly disabled means no verifier."""
    if reference is None:
        return None
    if (not isinstance(reference, dict) or set(reference) != {'path', 'sha256'}
            or not isinstance(agent_id, str) or not IDENTIFIER.fullmatch(agent_id)):
        raise ProtocolError('isolated_enrollment_reference')
    reference = decode_json(canonical_json(reference))
    raw = _read_pinned(reference['path'], reference['sha256'], 4096, root_owned)
    enrollment = decode_json(raw)
    if raw.strip() != canonical_json(enrollment):
        raise ProtocolError('isolated_enrollment_canonical')
    if (enrollment.get('schema') != SCHEMA or enrollment.get('agent_id') != agent_id
            or type(enrollment.get('enabled')) is not bool):
        raise ProtocolError('isolated_enrollment_identity')
    if enrollment['enabled'] is False:
        if set(enrollment) != BASE_FIELDS:
            raise ProtocolError('isolated_enrollment_disabled_fields')
        return None
    if (set(enrollment) != ACTIVE_FIELDS
            or enrollment['access_policy'] not in (windows.DEFAULT_ACCESS_POLICY, windows.SOURCE_HMAC_POLICY)):
        raise ProtocolError('isolated_enrollment_fields')
    _hash(enrollment['proxy_binding_sha256'])
    key = _read_pinned(enrollment['guest_key_file'], enrollment['guest_key_sha256'], 32, root_owned)
    if len(key) != 32:
        raise ProtocolError('isolated_enrollment_guest_key')
    root = _state_directory(enrollment['state_dir'], root_owned)
    replay_path = root / REPLAY_NAME
    if replay_path in (_path(reference['path']), _path(enrollment['guest_key_file'])):
        raise ProtocolError('isolated_enrollment_replay_alias')
    _check_replay(replay_path, root_owned)
    try:
        replay = ReplayStore(replay_path)
    except sqlite3.Error:
        raise ProtocolError('isolated_enrollment_replay_unavailable') from None
    _check_replay(replay_path, root_owned, required=True)

    def guard():
        _read_pinned(reference['path'], reference['sha256'], 4096, root_owned)
        _read_pinned(enrollment['guest_key_file'], enrollment['guest_key_sha256'], 32, root_owned)
        _state_directory(enrollment['state_dir'], root_owned)
        _check_replay(replay_path, root_owned, required=True)

    return EnrolledVerifier(key, replay, enrollment=enrollment, guard=guard, now=now)
