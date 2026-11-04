"""Authenticated, bounded relay-recovery wire messages (stdlib only).

The endpoint is a single POST at /relay-recovery-agent.php. Requests contain
{agent_id, op: "poll", report: {...}}. Responses contain
{schema: "servernet.relay.recovery.v1", request_nonce, report_ack,
 manifest: {...}|null}. report_ack is SHA-256 of canonical_json(request.report),
emitted only after durable acceptance of that report.
Sign responses with method RESPONSE, the *same path*, and a new nonce.

Wire canonical form, UTF-8, with no final newline::

    SN-RELAY/1\nagent_id\nMETHOD\n/path\ntimestamp\nnonce\nsha256(raw_body)

ReplayStore must be private and persistent. Authenticate before consuming a
nonce; BEGIN IMMEDIATE makes two concurrent copies unable to both succeed.
No credentials or raw request bodies are included in errors.
"""
from __future__ import annotations

from dataclasses import dataclass
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import secrets
import sqlite3
import stat
from typing import Mapping

SCHEMA = "servernet.relay.recovery.v1"
MAX_BODY = 65536
MESSAGE_TTL = 90
MAX_MANIFEST_TTL = 300
IDENTIFIER = re.compile(r"\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,95}\Z")
HEX64 = re.compile(r"\A[0-9a-f]{64}\Z")
NONCE = re.compile(r"\A[0-9a-f]{32}\Z")
PATH = re.compile(r"\A/[a-zA-Z0-9/_.-]{1,180}\Z")


class ProtocolError(ValueError):
    """A safe machine-readable code; never include untrusted input in it."""


@dataclass(frozen=True)
class VerifiedMessage:
    agent_id: str
    timestamp: int
    nonce: str
    body_hash: str


def canonical_json(value: object) -> bytes:
    """The manifest jobs hash uses sorted, compact UTF-8 JSON."""
    try:
        return json.dumps(value, ensure_ascii=False, allow_nan=False,
                          sort_keys=True, separators=(",", ":")).encode("utf-8")
    except (TypeError, ValueError):
        raise ProtocolError("invalid_json_value") from None


def decode_json(body: bytes) -> dict:
    """Reject duplicate JSON keys and non-finite numbers instead of guessing."""
    if not isinstance(body, bytes) or len(body) > MAX_BODY:
        raise ProtocolError("body_size")

    def pairs(items):
        result = {}
        for key, value in items:
            if key in result:
                raise ProtocolError("duplicate_json_key")
            result[key] = value
        return result

    def constant(_):
        raise ProtocolError("nonfinite_json")

    try:
        obj = json.loads(body.decode("utf-8"), object_pairs_hook=pairs,
                         parse_constant=constant)
    except (UnicodeError, json.JSONDecodeError, RecursionError):
        raise ProtocolError("invalid_json") from None
    if not isinstance(obj, dict):
        raise ProtocolError("json_object_required")
    return obj


def _integer(value, code: str, minimum: int = 0) -> int:
    if type(value) is not int or value < minimum:
        raise ProtocolError(code)
    return value


def _key(key: bytes) -> None:
    if not isinstance(key, bytes) or not 32 <= len(key) <= 256:
        raise ProtocolError("key_length")


def _canonical(agent: str, method: str, path: str, timestamp: int,
               nonce: str, body: bytes) -> bytes:
    if not isinstance(agent, str) or not IDENTIFIER.fullmatch(agent):
        raise ProtocolError("agent_id")
    if method not in {"POST", "RESPONSE"}:
        raise ProtocolError("method")
    if not isinstance(path, str) or not PATH.fullmatch(path) or ".." in path:
        raise ProtocolError("path")
    _integer(timestamp, "timestamp")
    if not isinstance(nonce, str) or not NONCE.fullmatch(nonce):
        raise ProtocolError("nonce")
    if not isinstance(body, bytes) or len(body) > MAX_BODY:
        raise ProtocolError("body_size")
    return (f"SN-RELAY/1\n{agent}\n{method}\n{path}\n{timestamp}\n{nonce}\n"
            f"{hashlib.sha256(body).hexdigest()}").encode("utf-8")


def sign_message(key: bytes, agent_id: str, method: str, path: str,
                 body: bytes, now: int, nonce: str | None = None) -> dict[str, str]:
    _key(key)
    nonce = nonce or secrets.token_hex(16)
    data = _canonical(agent_id, method, path, now, nonce, body)
    return {"X-Relay-Agent": agent_id, "X-Relay-Timestamp": str(now),
            "X-Relay-Nonce": nonce,
            "X-Relay-Signature": hmac.new(key, data, hashlib.sha256).hexdigest()}


def verify_message(key: bytes, expected_agent: str, method: str, path: str,
                   body: bytes, headers: Mapping[str, str], now: int,
                   replay_store: "ReplayStore", ttl: int = MESSAGE_TTL) -> VerifiedMessage:
    _key(key)
    _integer(now, "timestamp")
    if type(ttl) is not int or not 1 <= ttl <= MESSAGE_TTL:
        raise ProtocolError("ttl")
    normalized = {}
    for name, value in headers.items():
        lower = name.lower()
        if lower in normalized:
            raise ProtocolError("duplicate_header")
        normalized[lower] = value
    agent = normalized.get("x-relay-agent", "")
    ts = normalized.get("x-relay-timestamp", "")
    nonce = normalized.get("x-relay-nonce", "")
    signature = normalized.get("x-relay-signature", "")
    if agent != expected_agent:
        raise ProtocolError("agent_mismatch")
    if not isinstance(ts, str) or not re.fullmatch(r"0|[1-9][0-9]{0,11}", ts):
        raise ProtocolError("timestamp")
    timestamp = int(ts)
    if abs(now - timestamp) > ttl:
        raise ProtocolError("message_expired")
    data = _canonical(agent, method, path, timestamp, nonce, body)
    if not isinstance(signature, str) or not HEX64.fullmatch(signature):
        raise ProtocolError("signature")
    if not hmac.compare_digest(hmac.new(key, data, hashlib.sha256).hexdigest(), signature):
        raise ProtocolError("authentication_failed")
    if not replay_store.claim(agent, nonce, timestamp + ttl, now):
        raise ProtocolError("replay")
    return VerifiedMessage(agent, timestamp, nonce, hashlib.sha256(body).hexdigest())


def private_file(path: str | Path, *, root_owned: bool = True,
                 executable: bool = False) -> Path:
    """Validate a local trusted file, refusing symlinks and unsafe permissions."""
    path = Path(path)
    if not path.is_absolute():
        raise ProtocolError("absolute_path_required")
    try:
        info = path.lstat()
    except OSError:
        raise ProtocolError("private_file_unavailable") from None
    if not stat.S_ISREG(info.st_mode) or path.is_symlink():
        raise ProtocolError("private_file_type")
    if root_owned and info.st_uid != 0:
        raise ProtocolError("private_file_owner")
    forbidden = 0o022 if executable else 0o077
    if stat.S_IMODE(info.st_mode) & forbidden:
        raise ProtocolError("private_file_mode")
    if executable and not os.access(path, os.X_OK):
        raise ProtocolError("adapter_not_executable")
    # A writable ancestor can replace an otherwise safe file between calls.
    for parent in path.parents:
        pinf = parent.lstat()
        if parent.is_symlink() or not stat.S_ISDIR(pinf.st_mode):
            raise ProtocolError("private_parent_type")
        if root_owned and pinf.st_uid != 0:
            raise ProtocolError("private_parent_owner")
        if stat.S_IMODE(pinf.st_mode) & 0o022:
            # Sticky /tmp is a valid ancestor of isolated test directories but
            # not a valid production secret/adapter location.
            if root_owned:
                raise ProtocolError("private_parent_mode")
    return path


class ReplayStore:
    def __init__(self, path: str | Path):
        self.path = Path(path)
        self.path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        if self.path.is_symlink():
            raise ProtocolError("state_symlink")
        with self._connect() as db:
            db.execute("CREATE TABLE IF NOT EXISTS replay (agent TEXT NOT NULL, nonce TEXT NOT NULL, expires INTEGER NOT NULL, PRIMARY KEY(agent, nonce))")
            db.execute("CREATE TABLE IF NOT EXISTS replay_clock (id INTEGER PRIMARY KEY CHECK(id=1), last_wall INTEGER NOT NULL)")
        os.chmod(self.path, 0o600)

    def _connect(self):
        db = sqlite3.connect(self.path, timeout=5, isolation_level=None)
        db.execute("PRAGMA busy_timeout=5000")
        db.execute("PRAGMA synchronous=FULL")
        return db

    def claim(self, agent: str, nonce: str, expires: int, now: int) -> bool:
        with self._connect() as db:
            db.execute("BEGIN IMMEDIATE")
            previous = db.execute("SELECT last_wall FROM replay_clock WHERE id=1").fetchone()
            if previous and now < previous[0] - 5:
                db.rollback()
                raise ProtocolError("clock_regression")
            db.execute("DELETE FROM replay WHERE expires < ?", (now,))
            try:
                db.execute("INSERT INTO replay VALUES(?,?,?)", (agent, nonce, expires))
            except sqlite3.IntegrityError:
                db.rollback()
                return False
            db.execute("INSERT OR REPLACE INTO replay_clock VALUES(1,?)", (max(now, previous[0] if previous else now),))
            db.commit()
            return True


def manifest_hash(jobs: list) -> str:
    return hashlib.sha256(canonical_json(jobs)).hexdigest()


def validate_manifest(manifest: dict, expected_agent: str, now: int) -> dict:
    """Validate shape and signed jobs hash; agent.py enforces local execution policy."""
    if not isinstance(manifest, dict):
        raise ProtocolError("manifest_object")
    if manifest.get("agent_id") != expected_agent:
        raise ProtocolError("manifest_agent")
    if not isinstance(manifest.get("incident_id"), str) or not IDENTIFIER.fullmatch(manifest["incident_id"]):
        raise ProtocolError("manifest_incident")
    for name in ("generation", "revision"):
        _integer(manifest.get(name), "manifest_" + name, 1)
    issued = _integer(manifest.get("issued_at"), "manifest_issued")
    expiry = _integer(manifest.get("expires_at"), "manifest_expiry")
    if issued > now + 30 or expiry <= now or expiry <= issued or expiry - issued > MAX_MANIFEST_TTL:
        raise ProtocolError("manifest_expired")
    jobs = manifest.get("jobs")
    if not isinstance(jobs, list) or len(jobs) > 16:
        raise ProtocolError("manifest_jobs")
    if not isinstance(manifest.get("config_hash"), str) or not HEX64.fullmatch(manifest["config_hash"]):
        raise ProtocolError("manifest_hash")
    if not isinstance(manifest.get("jobs_hash"), str) or not HEX64.fullmatch(manifest["jobs_hash"]):
        raise ProtocolError("manifest_jobs_hash")
    if not hmac.compare_digest(manifest_hash(jobs), manifest["jobs_hash"]):
        raise ProtocolError("manifest_jobs_hash")
    if len(canonical_json(manifest)) > MAX_BODY:
        raise ProtocolError("body_size")
    return manifest
