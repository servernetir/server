#!/usr/bin/env python3
"""Root-owned Proxmox relay-recovery pull agent, Python 3.12 stdlib only.

This component does not provision/delete cloud resources. It authenticates one
fixed HTTPS controller, validates bounded manifests, journals job intent before
effects, probes only an allowlisted port on a public literal IP, and invokes a
root-owned *local* adapter executable with fixed action argv and JSON stdin.
No command, script, URL, executable or shell text is accepted from the network.

Local config (0600 root-owned; parents not group/world writable)::
  {"agent_id":"pve-ir", "controller_url":"https://HOST/relay-recovery-agent.php",
   "shared_key_file":"/etc/servernet/relay-recovery.key",
   "state_dir":"/var/lib/servernet-relay-recovery",
   "adapter":"/usr/local/lib/servernet-relay-recovery/adapter",
   "candidate_ports":[22,443],
   "control_targets":[{"host":"TRUSTED_CONTROL_HOST","port":443}]}

Key is 32..256 *raw* bytes (not implicit hex decoding). Endpoint request/response
signatures are specified in protocol.py. config_hash is the deployment/bundle
digest; jobs_hash separately hashes the exact jobs list for this revision.
The controller must return report_ack = sha256(canonical_json(request.report))
only after durable acceptance. Completed effects and receipt outbox entries
commit in one SQLite transaction, so a crash cannot lose an effect's receipt.
No-op polls return manifest:null. An adapter must return JSON schema
servernet.relay.adapter.v1, ok:boolean, checks:object and the same incident_id,
generation, revision, config_hash. Exit zero alone is never a successful job.
After a crash, a journaled unfinished mutating job invokes the fixed local
adapter action 'reconcile' with its original context; it never blindly repeats
the mutation. Every adapter must implement this action before auto is enabled.

CLI: python3 agent.py --config /etc/servernet/relay-recovery.json --once
Installation, approved adapter implementation and scheduling are separate.
"""
from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor
import fcntl
import hashlib
import hmac
import ipaddress
import json
import os
from pathlib import Path
import socket
import sqlite3
import ssl
import stat
import subprocess
import selectors
import signal
import time
from typing import Callable
from urllib.parse import urlsplit
import urllib.error
import urllib.request

try:
    from .protocol import (SCHEMA, MAX_BODY, IDENTIFIER, ProtocolError, ReplayStore,
                           canonical_json, decode_json, private_file, sign_message,
                           validate_manifest, verify_message)
except ImportError:
    from protocol import (SCHEMA, MAX_BODY, IDENTIFIER, ProtocolError, ReplayStore,
                          canonical_json, decode_json, private_file, sign_message,
                          validate_manifest, verify_message)

ACTIONS = frozenset({"probe", "stage", "apply", "accept", "rollback"})
MUTATING = frozenset({"stage", "apply", "rollback"})
ADAPTER_SCHEMA = "servernet.relay.adapter.v1"


def _int(value, low: int, high: int, code: str) -> int:
    if type(value) is not int or not low <= value <= high:
        raise ProtocolError(code)
    return value


def _local_name(host: object) -> str:
    if not isinstance(host, str) or not host or len(host) > 253 or any(c not in "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-:" for c in host):
        raise ProtocolError("control_host")
    return host


def load_config(path: str | Path, *, root_owned: bool = True) -> dict:
    p = private_file(path, root_owned=root_owned)
    if p.stat().st_size > MAX_BODY:
        raise ProtocolError("config_size")
    config = decode_json(p.read_bytes())
    if not isinstance(config.get("agent_id"), str) or not IDENTIFIER.fullmatch(config["agent_id"]):
        raise ProtocolError("agent_id")
    url = urlsplit(config.get("controller_url", ""))
    if url.scheme != "https" or not url.hostname or url.username or url.password or url.query or url.fragment or url.path != "/relay-recovery-agent.php" or url.port not in (None, 443):
        raise ProtocolError("controller_url")
    config["controller_path"] = url.path
    key = private_file(config.get("shared_key_file", ""), root_owned=root_owned).read_bytes()
    if not 32 <= len(key) <= 256:
        raise ProtocolError("key_length")
    config["key"] = key
    adapter = private_file(config.get("adapter", ""), root_owned=root_owned, executable=True)
    config["adapter"] = str(adapter)
    ports = config.get("candidate_ports", [22, 443])
    if not isinstance(ports, list) or not ports or len(ports) > 16:
        raise ProtocolError("candidate_ports")
    config["candidate_ports"] = [_int(v, 1, 65535, "candidate_port") for v in ports]
    targets = config.get("control_targets")
    if not isinstance(targets, list) or not 1 <= len(targets) <= 4:
        raise ProtocolError("control_targets")
    for target in targets:
        if not isinstance(target, dict):
            raise ProtocolError("control_target")
        _local_name(target.get("host"))
        _int(target.get("port"), 1, 65535, "control_port")
    state = Path(config.get("state_dir", ""))
    if not state.is_absolute() or state.is_symlink():
        raise ProtocolError("state_dir")
    state.mkdir(mode=0o700, parents=True, exist_ok=True)
    info = state.stat()
    if not stat.S_ISDIR(info.st_mode) or stat.S_IMODE(info.st_mode) & 0o077 or (root_owned and info.st_uid != 0):
        raise ProtocolError("state_dir_permissions")
    for parent in state.parents:
        info = parent.lstat()
        if parent.is_symlink() or not stat.S_ISDIR(info.st_mode):
            raise ProtocolError("private_parent_type")
        if root_owned and (info.st_uid != 0 or stat.S_IMODE(info.st_mode) & 0o022):
            raise ProtocolError("private_parent_mode")
    config["state_dir"] = str(state)
    config["root_owned"] = root_owned
    return config


def validate_job(job: dict, config: dict) -> dict:
    if not isinstance(job, dict) or not isinstance(job.get("id"), str) or not IDENTIFIER.fullmatch(job["id"]):
        raise ProtocolError("job_id")
    if job.get("op") not in ACTIONS:
        raise ProtocolError("job_action")
    if set(job) - {"id", "op", "target", "params"}:
        raise ProtocolError("job_fields")
    if "params" in job and not isinstance(job["params"], dict):
        raise ProtocolError("job_params")
    target = job.get("target")
    if target is not None:
        if not isinstance(target, dict) or set(target) - {"host", "port", "provider_id"}:
            raise ProtocolError("job_target")
        try:
            address = ipaddress.ip_address(target.get("host", ""))
        except ValueError:
            raise ProtocolError("candidate_ip") from None
        if not address.is_global:
            raise ProtocolError("candidate_ip")
        if _int(target.get("port"), 1, 65535, "candidate_port") not in config["candidate_ports"]:
            raise ProtocolError("candidate_port")
        provider_id = target.get("provider_id")
        if not isinstance(provider_id, str) or not provider_id.isascii() or not provider_id.isdigit() or int(provider_id) <= 0 or len(provider_id) > 20:
            raise ProtocolError("provider_id")
    if job["op"] == "probe" and target is None:
        raise ProtocolError("probe_target_required")
    # Recursive strings never become argv. Reject known command fields anyway.
    def scan(value, depth=0):
        if depth > 12:
            raise ProtocolError("job_depth")
        if isinstance(value, dict):
            if any(str(k).lower() in {"command", "shell", "argv", "script", "executable", "url"} for k in value):
                raise ProtocolError("job_command_forbidden")
            for v in value.values():
                scan(v, depth + 1)
        elif isinstance(value, list):
            for v in value:
                scan(v, depth + 1)
    scan(job)
    if len(canonical_json(job)) > 8192:
        raise ProtocolError("job_size")
    return job


class AgentJournal:
    def __init__(self, path: str | Path):
        self.path = Path(path)
        if self.path.is_symlink():
            raise ProtocolError("state_symlink")
        with self.connect() as db:
            db.executescript("""
                CREATE TABLE IF NOT EXISTS cursor(agent TEXT PRIMARY KEY,
                  generation INTEGER NOT NULL, revision INTEGER NOT NULL,
                  config_hash TEXT NOT NULL, jobs_hash TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS jobs(agent TEXT NOT NULL,
                  generation INTEGER NOT NULL, revision INTEGER NOT NULL,
                  job_id TEXT NOT NULL, context TEXT NOT NULL,
                  status TEXT NOT NULL, result TEXT,
                  PRIMARY KEY(agent,generation,revision,job_id));
                CREATE TABLE IF NOT EXISTS clock(id INTEGER PRIMARY KEY CHECK(id=1),
                  last_wall INTEGER NOT NULL);
                CREATE TABLE IF NOT EXISTS outbox(seq INTEGER PRIMARY KEY AUTOINCREMENT,
                  receipt_hash TEXT NOT NULL UNIQUE, receipt TEXT NOT NULL);
            """)
        os.chmod(self.path, 0o600)

    def connect(self):
        db = sqlite3.connect(self.path, timeout=5, isolation_level=None)
        db.row_factory = sqlite3.Row
        db.execute("PRAGMA synchronous=FULL")
        return db

    def accept_manifest(self, agent: str, manifest: dict, now: int) -> None:
        with self.connect() as db:
            db.execute("BEGIN IMMEDIATE")
            clock = db.execute("SELECT last_wall FROM clock WHERE id=1").fetchone()
            if clock and now < clock[0] - 5:
                db.rollback()
                raise ProtocolError("clock_regression")
            row = db.execute("SELECT generation,revision,config_hash,jobs_hash FROM cursor WHERE agent=?", (agent,)).fetchone()
            new = (manifest["generation"], manifest["revision"])
            if row and (new < (row[0], row[1]) or (new == (row[0], row[1]) and
                       (manifest["config_hash"] != row[2] or manifest["jobs_hash"] != row[3]))):
                db.rollback()
                raise ProtocolError("manifest_rollback")
            if row and new[0] == row[0] and manifest["config_hash"] != row[2]:
                db.rollback()
                raise ProtocolError("config_generation_conflict")
            db.execute("INSERT OR REPLACE INTO cursor VALUES(?,?,?,?,?)", (agent, *new, manifest["config_hash"], manifest["jobs_hash"]))
            db.execute("INSERT OR REPLACE INTO clock VALUES(1,?)", (max(now, clock[0] if clock else now),))
            db.commit()

    def begin(self, context: dict) -> tuple[str, dict | None]:
        key = (context["agent_id"], context["generation"], context["revision"], context["job"]["id"])
        encoded = canonical_json(context).decode()
        with self.connect() as db:
            db.execute("BEGIN IMMEDIATE")
            row = db.execute("SELECT context,status,result FROM jobs WHERE agent=? AND generation=? AND revision=? AND job_id=?", key).fetchone()
            if row:
                if row["context"] != encoded:
                    db.rollback()
                    raise ProtocolError("job_identity_conflict")
                db.commit()
                return row["status"], json.loads(row["result"]) if row["result"] else None
            if context["job"]["op"] in MUTATING:
                pending = db.execute("SELECT context FROM jobs WHERE agent=? AND status='started'", (context["agent_id"],)).fetchall()
                if any(json.loads(p[0])["job"]["op"] in MUTATING for p in pending):
                    db.rollback()
                    raise ProtocolError("mutation_pending_reconciliation")
            db.execute("INSERT INTO jobs VALUES(?,?,?,?,?,'started',NULL)", (*key, encoded))
            db.commit()
            return "new", None

    def pending_mutations(self, agent_id: str) -> list[dict]:
        with self.connect() as db:
            rows = db.execute("SELECT context FROM jobs WHERE agent=? AND status='started' ORDER BY generation,revision,job_id", (agent_id,)).fetchall()
        return [json.loads(row[0]) for row in rows if json.loads(row[0])["job"]["op"] in MUTATING]

    def complete(self, context: dict, result: dict) -> None:
        key = (context["agent_id"], context["generation"], context["revision"], context["job"]["id"])
        with self.connect() as db:
            db.execute("BEGIN IMMEDIATE")
            encoded = canonical_json(result).decode()
            row = db.execute("SELECT status,result FROM jobs WHERE agent=? AND generation=? AND revision=? AND job_id=?", key).fetchone()
            if not row:
                raise ProtocolError("job_not_started")
            if row["status"] == "done" and row["result"] != encoded:
                raise ProtocolError("job_receipt_immutable")
            db.execute("UPDATE jobs SET status='done',result=? WHERE agent=? AND generation=? AND revision=? AND job_id=?", (encoded, *key))
            self._queue(db, encoded)
            db.commit()

    @staticmethod
    def _queue(db, encoded: str):
        if len(encoded.encode()) > 8192:
            raise ProtocolError("receipt_size")
        digest = hashlib.sha256(encoded.encode()).hexdigest()
        db.execute("INSERT OR IGNORE INTO outbox(receipt_hash,receipt) VALUES(?,?)", (digest, encoded))

    def queue_receipt(self, receipt: dict):
        with self.connect() as db:
            db.execute("BEGIN IMMEDIATE")
            self._queue(db, canonical_json(receipt).decode())
            db.commit()

    def queued(self) -> list[dict]:
        with self.connect() as db:
            rows = db.execute("SELECT receipt FROM outbox ORDER BY seq LIMIT 6").fetchall()
        return [json.loads(r[0]) for r in rows]

    def acknowledge(self, receipts: list[dict]):
        with self.connect() as db:
            db.execute("BEGIN IMMEDIATE")
            for receipt in receipts:
                digest = hashlib.sha256(canonical_json(receipt)).hexdigest()
                db.execute("DELETE FROM outbox WHERE receipt_hash=?", (digest,))
            db.commit()

    def backlog(self) -> int:
        with self.connect() as db:
            return db.execute("SELECT count(*) FROM outbox").fetchone()[0]


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def https_transport(url: str, body: bytes, headers: dict) -> tuple[int, dict, bytes]:
    """Only this fixed URL is used. Redirects never receive our authenticated body."""
    opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=ssl.create_default_context()), _NoRedirect())
    req = urllib.request.Request(url, body, headers={**headers, "Content-Type": "application/json", "Accept": "application/json"}, method="POST")
    try:
        with opener.open(req, timeout=12) as response:
            data = response.read(MAX_BODY + 1)
            if len(data) > MAX_BODY:
                raise ProtocolError("body_size")
            return response.status, dict(response.headers.items()), data
    except (urllib.error.URLError, TimeoutError, OSError):
        raise ProtocolError("controller_transport") from None


def tcp_probe(host: str, port: int, timeout: float = 3) -> bool:
    try:
        with socket.create_connection((host, port), timeout=timeout):
            return True
    except (OSError, TimeoutError):
        return False


def icmp_probe(host: str) -> bool | None:
    try:
        result = subprocess.run(["/usr/bin/ping", "-n", "-c", "1", "-W", "2", host],
                                stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, timeout=3, check=False)
        return result.returncode == 0
    except (OSError, subprocess.TimeoutExpired):
        return None


def _bounded_process(argv: list[str], data: bytes, timeout: float = 30) -> tuple[int, bytes]:
    """Drain bounded output while writing input without pipe/timeout deadlocks."""
    process = subprocess.Popen(argv, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                               stderr=subprocess.DEVNULL, start_new_session=True,
                               env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8"})
    selector = selectors.DefaultSelector()
    output = bytearray()
    position = 0
    deadline = time.monotonic() + timeout
    try:
        os.set_blocking(process.stdin.fileno(), False)
        os.set_blocking(process.stdout.fileno(), False)
        selector.register(process.stdin, selectors.EVENT_WRITE, "stdin")
        selector.register(process.stdout, selectors.EVENT_READ, "stdout")
        while selector.get_map():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise ProtocolError("adapter_timeout")
            for key, _ in selector.select(remaining):
                if key.data == "stdin":
                    try:
                        position += os.write(key.fileobj.fileno(), data[position:position + 8192])
                    except BrokenPipeError:
                        position = len(data)
                    if position >= len(data):
                        selector.unregister(key.fileobj)
                        key.fileobj.close()
                else:
                    chunk = os.read(key.fileobj.fileno(), min(8192, MAX_BODY + 1 - len(output)))
                    if not chunk:
                        selector.unregister(key.fileobj)
                    output.extend(chunk)
                    if len(output) > MAX_BODY:
                        raise ProtocolError("adapter_output_size")
        try:
            return process.wait(timeout=max(0.001, deadline - time.monotonic())), bytes(output)
        except subprocess.TimeoutExpired:
            raise ProtocolError("adapter_timeout") from None
    finally:
        selector.close()
        if process.poll() is None:
            # The mutation's outcome is unknown and remains journaled for
            # reconciliation. Do not leave an unbounded background subprocess.
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            process.wait(timeout=3)
        process.stdin.close()
        process.stdout.close()


def local_adapter(path: str, action: str, context: dict, *, root_owned: bool = True) -> dict:
    if action not in ACTIONS | {"reconcile"}:
        raise ProtocolError("adapter_action")
    private_file(path, root_owned=root_owned, executable=True)
    data = canonical_json(context)
    try:
        returncode, output = _bounded_process([path, action], data)
    except OSError:
        raise ProtocolError("adapter_unavailable") from None
    result = decode_json(output)
    if result.get("schema") != ADAPTER_SCHEMA or type(result.get("ok")) is not bool or not isinstance(result.get("checks"), dict):
        raise ProtocolError("adapter_receipt")
    if len(canonical_json(result["checks"])) > 4096:
        raise ProtocolError("adapter_checks_size")
    for name in ("incident_id", "generation", "revision", "config_hash"):
        if type(result.get(name)) is not type(context[name]) or result.get(name) != context[name]:
            raise ProtocolError("adapter_receipt_identity")
    if returncode != 0 and result["ok"]:
        raise ProtocolError("adapter_exit_mismatch")
    return result


class Agent:
    def __init__(self, config: dict, *, transport=https_transport,
                 adapter: Callable | None = None, tcp=tcp_probe, icmp=icmp_probe,
                 monotonic=time.monotonic):
        self.config = config
        self.transport = transport
        self.adapter = adapter or (lambda action, context: local_adapter(config["adapter"], action, context, root_owned=config.get("root_owned", True)))
        self.tcp = tcp
        self.icmp = icmp
        self.monotonic = monotonic
        base = Path(config["state_dir"])
        self.journal = AgentJournal(base / "agent.sqlite3")
        self.replay = ReplayStore(base / "replay.sqlite3")
        self.report_path = base / "pending-report.json"
        self.lock_path = base / "agent.lock"

    def _write_report(self, report: dict):
        path = self.report_path.with_suffix(".tmp")
        if path.is_symlink() or self.report_path.is_symlink():
            raise ProtocolError("state_symlink")
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "wb") as f:
            f.write(canonical_json(report)); f.flush(); os.fsync(f.fileno())
        os.replace(path, self.report_path)

    def _pending_report(self, now: int) -> dict:
        # SQLite outbox, committed atomically with each completed effect, is
        # authoritative. The JSON file is a local inspection cache only.
        report = {"agent_id": self.config["agent_id"], "jobs": self.journal.queued()}
        targets = self.config["control_targets"]
        with ThreadPoolExecutor(max_workers=4) as pool:
            controls = list(pool.map(lambda t: self.tcp(t["host"], t["port"]), targets))
        # TCP controls are evidence only, never an assertion of tunnel health.
        report["observed_at"] = now
        report["direct_control_tcp"] = controls
        return report

    @staticmethod
    def _observation_fields(receipt: dict, now: int) -> dict:
        if receipt['checks'].get('historical_outcome') is True:
            # A terminal remote journal row proves the mutation's old outcome,
            # not a new tunnel/consumer measurement. Older CT records carry no
            # original measurement clock, so keep it explicitly unknown.
            return {'observed_at':None,'outcome_reconciled_at':now,
                    'observation_kind':'historical_outcome','fresh_observation':False}
        return {'observed_at':now}

    def run_once(self, now: int | None = None) -> dict:
        now = int(time.time()) if now is None else now
        fd = os.open(self.lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "w") as lock:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                return {"ok": False, "code": "already_running"}
            return self._run(now)

    def _run(self, now: int) -> dict:
        cfg = self.config
        started = self.monotonic()
        def current_time():
            elapsed = self.monotonic() - started
            if elapsed < 0:
                raise ProtocolError("clock_regression")
            return now + int(elapsed)
        report = self._pending_report(now)
        body = canonical_json({"agent_id": cfg["agent_id"], "op": "poll", "report": report})
        headers = sign_message(cfg["key"], cfg["agent_id"], "POST", cfg["controller_path"], body, now)
        status, response_headers, response_body = self.transport(cfg["controller_url"], body, headers)
        if status != 200:
            raise ProtocolError("controller_status")
        verify_message(cfg["key"], cfg["agent_id"], "RESPONSE", cfg["controller_path"], response_body, response_headers, current_time(), self.replay)
        envelope = decode_json(response_body)
        if envelope.get("schema") != SCHEMA or envelope.get("request_nonce") != headers["X-Relay-Nonce"]:
            raise ProtocolError("response_binding")
        expected_ack = hashlib.sha256(canonical_json(report)).hexdigest()
        if not isinstance(envelope.get("report_ack"), str) or not hmac.compare_digest(envelope["report_ack"], expected_ack):
            raise ProtocolError("report_ack_missing")
        self.journal.acknowledge(report["jobs"])
        if "manifest" not in envelope:
            raise ProtocolError("manifest_missing")
        reconciled = self._reconcile_pending(current_time, headers["X-Relay-Nonce"])
        if reconciled:
            pending = bool(self.journal.pending_mutations(cfg["agent_id"]))
            # Deliver the original intent's fate on the next poll before any
            # later manifest can overwrite its queued receipt.
            return {"ok": not pending and all(r["ok"] for r in reconciled),
                    "code": "mutation_pending_reconciliation" if pending else "reconciled",
                    "job_count": len(reconciled)}
        manifest = envelope["manifest"]
        if manifest is None:
            return {"ok": True, "code": "reconciled" if reconciled else "no_job", "job_count": len(reconciled)}
        manifest = validate_manifest(manifest, cfg["agent_id"], current_time())
        if self.journal.backlog() >= 48:
            return {"ok": False, "code": "receipt_backlog", "job_count": 0}
        jobs = manifest["jobs"]
        seen = set()
        for job in jobs:
            validate_job(job, cfg)
            if job["id"] in seen:
                raise ProtocolError("job_id_duplicate")
            seen.add(job["id"])
        self.journal.accept_manifest(cfg["agent_id"], manifest, current_time())
        results = []
        halt_reason = None
        for job in jobs:
            if current_time() >= manifest["expires_at"]:
                halt_reason = "manifest_expired"
                break
            context = {"schema": SCHEMA, "agent_id": cfg["agent_id"],
                       **{k: manifest[k] for k in ("incident_id", "generation", "revision", "config_hash")},
                       "jobs_hash": manifest["jobs_hash"],
                       "job": job}
            state, prior = self.journal.begin(context)
            if state == "done":
                results.append(prior)
                continue
            action = "reconcile" if state == "started" and job["op"] in MUTATING else job["op"]
            checks = {}
            try:
                if job["op"] == "probe":
                    target = job["target"]
                    checks = {"candidate_tcp": bool(self.tcp(target["host"], target["port"])),
                              "candidate_icmp": self.icmp(target["host"])}
                adapter_result = self.adapter(action, context)
                # Injected adapters are subject to the same semantic validation.
                if not isinstance(adapter_result, dict) or adapter_result.get("schema") != ADAPTER_SCHEMA or type(adapter_result.get("ok")) is not bool or not isinstance(adapter_result.get("checks"), dict):
                    raise ProtocolError("adapter_receipt")
                if len(canonical_json(adapter_result["checks"])) > 4096:
                    raise ProtocolError("adapter_checks_size")
                if any(type(adapter_result.get(k)) is not type(context[k]) or adapter_result.get(k) != context[k] for k in ("incident_id", "generation", "revision", "config_hash")):
                    raise ProtocolError("adapter_receipt_identity")
                checks["adapter"] = adapter_result["checks"]
                ok = adapter_result["ok"] and (checks.get("candidate_tcp", True))
                result = {"job_id": job["id"], "op": job["op"], "ok": bool(ok),
                          "code": "completed" if ok else "check_failed", "checks": checks,
                          **self._observation_fields(adapter_result,current_time()), "incident_id": manifest["incident_id"],
                          "generation": manifest["generation"], "revision": manifest["revision"],
                          "config_hash": manifest["config_hash"], "jobs_hash": manifest["jobs_hash"],
                          "request_nonce": headers["X-Relay-Nonce"]}
                # A reconciliation must explicitly establish the mutation's fate.
                if action == "reconcile" and adapter_result.get("reconciled") is not True:
                    raise ProtocolError("reconciliation_pending")
                self.journal.complete(context, result)
            except ProtocolError as error:
                result = {"job_id": job["id"], "op": job["op"], "ok": False,
                          "code": str(error), "checks": checks, "observed_at": current_time(),
                          "incident_id": manifest["incident_id"], "generation": manifest["generation"],
                          "revision": manifest["revision"], "config_hash": manifest["config_hash"],
                          "jobs_hash": manifest["jobs_hash"],
                          "request_nonce": headers["X-Relay-Nonce"]}
                # Leave unfinished mutations started for future reconciliation.
                # Non-mutating probes can be safely retried after transport errors.
            results.append(result)
            self.journal.queue_receipt(result)
            self._write_report({"jobs": results, "agent_id": cfg["agent_id"],
                                "generation": manifest["generation"], "revision": manifest["revision"],
                                "config_hash": manifest["config_hash"], "jobs_hash": manifest["jobs_hash"],
                                "incident_id": manifest["incident_id"]})
            if current_time() >= manifest["expires_at"]:
                halt_reason = "manifest_expired"
                break
            if not result["ok"] and job["op"] in MUTATING:
                break
        return {"ok": not halt_reason and all(r["ok"] for r in results), "code": halt_reason or "processed", "job_count": len(results)}

    def _reconcile_pending(self, current_time: Callable, request_nonce: str) -> list[dict]:
        """Resolve original mutation intents before accepting any newer work.

        'reconcile' is a local read-only inspection operation, authorized by the
        persisted original intent. It may run after that manifest expires. It
        must never apply or roll back a mutation itself.
        """
        results = []
        for context in self.journal.pending_mutations(self.config["agent_id"]):
            identity = {k: context[k] for k in ("incident_id", "generation", "revision", "config_hash", "jobs_hash")}
            result = {**identity, "job_id": context["job"]["id"], "op": context["job"]["op"],
                      "ok": False, "code": "reconciliation_pending", "checks": {},
                      "observed_at": current_time(), "request_nonce": request_nonce}
            try:
                receipt = self.adapter("reconcile", context)
                if not isinstance(receipt, dict) or receipt.get("schema") != ADAPTER_SCHEMA or type(receipt.get("ok")) is not bool or not isinstance(receipt.get("checks"), dict):
                    raise ProtocolError("adapter_receipt")
                if len(canonical_json(receipt["checks"])) > 4096:
                    raise ProtocolError("adapter_checks_size")
                if any(type(receipt.get(k)) is not type(context[k]) or receipt.get(k) != context[k] for k in ("incident_id", "generation", "revision", "config_hash")):
                    raise ProtocolError("adapter_receipt_identity")
                if receipt.get("reconciled") is not True:
                    raise ProtocolError("reconciliation_pending")
                result.update(ok=receipt["ok"], code="completed" if receipt["ok"] else "check_failed",
                              checks={"adapter": receipt["checks"]},
                              **self._observation_fields(receipt,current_time()))
                self.journal.complete(context, result)
            except ProtocolError as error:
                result.update(code=str(error), observed_at=current_time())
            results.append(result)
            self.journal.queue_receipt(result)
            self._write_report({**identity, "agent_id": self.config["agent_id"], "jobs": results})
            if self.journal.pending_mutations(self.config["agent_id"]):
                break
        return results


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", required=True)
    parser.add_argument("--once", action="store_true", required=True)
    args = parser.parse_args()
    os.umask(0o077)
    try:
        result = Agent(load_config(args.config)).run_once()
    except (ProtocolError, OSError, sqlite3.Error) as error:
        # OSError/sqlite messages can contain paths; only fixed classes escape.
        result = {"ok": False, "code": str(error) if isinstance(error, ProtocolError) else "local_state_unavailable"}
    print(json.dumps(result, separators=(",", ":")))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
