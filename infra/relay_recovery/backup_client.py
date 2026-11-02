#!/usr/bin/env python3
"""Pull and validate a private relay snapshot over a pinned, forced SSH account.

CLI: python3 -m relay_recovery.backup_client --config PRIVATE_JSON --op backup
or --op inspect. No remote command, path, host, generation or key comes from
stdin/CLI. The only remote command string is the fixed marker below; sshd's
authorized_keys forced command must invoke relay_side.py independently.

Config schema servernet.relay.backup-client.v1: node_id, generation,
config_hash, source_host (literal public active IP), provider_id (digits),
ssh_key_file, known_hosts_file, expected_host_key_sha256 (SHA256:base64),
state_dir; optional ssh_port=22, max_artifact_bytes<=512MiB,
max_uncompressed_bytes<=2GiB, timeout_seconds<=180, expected_groups=[names].
Config/key/knownhosts are private files owned by the effective UID (WHM's
unprivileged cPanel UID is supported). Ancestors must be root/current UID and
not group/world writable. Test fixtures alone may allow sticky /tmp.

Downloads are bounded, exclusive/private, fsynced, validated WITHOUT extracting
members, then published by a single directory rename containing archive and
metadata. A separate atomic latest.json is only a pointer to a completed set.
SSH/gzip/tar/identity/member hashes and per-database snapshot metadata must all
validate. This proves an artifact exists; it never proves a current restore,
cross-database consistency, persistent fencing, or permission to delete a VM.
The stdout summary always says ready:false and restore_current:false.
Storage admission defaults: <=3 completed backups, <=2GiB private state bytes,
and >=512MiB free filesystem reserve. These budgets include temporary validation
files. A full budget causes a bounded hold; no backup or unknown file is deleted.
"""
from __future__ import annotations

import argparse
import base64
import contextlib
import fcntl
import gzip
import hashlib
import ipaddress
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import selectors
import shutil
import signal
import stat
import subprocess
import sys
import tarfile
import tempfile
import time

try:
    from .protocol import canonical_json, decode_json, HEX64, IDENTIFIER, ProtocolError
except ImportError:
    from protocol import canonical_json, decode_json, HEX64, IDENTIFIER, ProtocolError

SCHEMA = "servernet.relay.backup-client.v1"
SIDE_SCHEMA = "servernet.relay.side.v1"
REMOTE_MARKER = "servernet-relay-side"
MAX_ARTIFACT = 512 << 20
MAX_UNCOMPRESSED = 2 << 30
MAX_MANIFEST = 8 << 20
MAX_MEMBERS = 100000
SAFE_ENV = {"PATH": "/usr/bin:/bin", "LANG": "C", "LC_ALL": "C"}


def _absolute(value) -> Path:
    if not isinstance(value, str) or not value.startswith("/") or "\x00" in value or ".." in Path(value).parts or value == "/":
        raise ProtocolError("configured_path")
    return Path(value)


def _parents(path: Path, uid: int, allow_test_paths: bool):
    for parent in path.parents:
        info = parent.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in {0, uid}:
            raise ProtocolError("private_parent")
        if info.st_mode & 0o022:
            if not (allow_test_paths and parent == Path("/tmp") and info.st_mode & stat.S_ISVTX):
                raise ProtocolError("private_parent_mode")


def trusted_file(value, *, uid=None, allow_test_paths=False) -> Path:
    uid = os.geteuid() if uid is None else uid
    path = _absolute(value)
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != uid or info.st_mode & 0o077:
        raise ProtocolError("private_file")
    _parents(path, uid, allow_test_paths)
    return path


def _directory(value, *, uid, allow_test_paths) -> Path:
    path = _absolute(value)
    _parents(path, uid, allow_test_paths)
    path.mkdir(mode=0o700, exist_ok=True)
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != uid or info.st_mode & 0o077:
        raise ProtocolError("private_directory")
    return path


def _read_private(path: Path, limit: int) -> bytes:
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(fd, "rb") as source:
        body = source.read(limit + 1)
    if len(body) > limit:
        raise ProtocolError("private_file_size")
    return body


def _host_key(config: dict, *, uid, allow_test_paths):
    path = trusted_file(config.get("known_hosts_file"), uid=uid, allow_test_paths=allow_test_paths)
    rows = [line.strip() for line in _read_private(path, 65536).splitlines() if line.strip() and not line.lstrip().startswith(b"#")]
    if len(rows) != 1:
        raise ProtocolError("host_key_count")
    fields = rows[0].split()
    host = config["source_host"]
    expected = host if config["ssh_port"] == 22 else f"[{host}]:{config['ssh_port']}"
    if len(fields) not in {3, 4} or fields[0] != expected.encode() or fields[1] not in {b"ssh-ed25519", b"ecdsa-sha2-nistp256", b"ssh-rsa"}:
        raise ProtocolError("host_key_entry")
    try:
        key = base64.b64decode(fields[2], validate=True)
    except ValueError:
        raise ProtocolError("host_key_encoding") from None
    if not 32 <= len(key) <= 16384:
        raise ProtocolError("host_key_encoding")
    length = int.from_bytes(key[:4], "big")
    if not 1 <= length <= 64 or key[4:4 + length] != fields[1]:
        raise ProtocolError("host_key_encoding")
    fingerprint = "SHA256:" + base64.b64encode(hashlib.sha256(key).digest()).decode().rstrip("=")
    if not secrets.compare_digest(fingerprint, str(config.get("expected_host_key_sha256", ""))):
        raise ProtocolError("host_key_pin")


def validate_config(config: dict, *, uid=None, allow_test_paths=False):
    uid = os.geteuid() if uid is None else uid
    if not isinstance(config, dict) or config.get("schema") != SCHEMA:
        raise ProtocolError("config_schema")
    if not isinstance(config.get("node_id"), str) or not IDENTIFIER.fullmatch(config["node_id"]):
        raise ProtocolError("node_id")
    if type(config.get("generation")) is not int or config["generation"] < 1:
        raise ProtocolError("generation")
    if not isinstance(config.get("config_hash"), str) or not HEX64.fullmatch(config["config_hash"]):
        raise ProtocolError("config_hash")
    try:
        host = ipaddress.ip_address(config.get("source_host"))
    except ValueError:
        raise ProtocolError("source_host") from None
    if not host.is_global or host.compressed != config["source_host"]:
        raise ProtocolError("source_host")
    if not isinstance(config.get("provider_id"), str) or not re.fullmatch(r"[1-9][0-9]{0,19}", config["provider_id"]):
        raise ProtocolError("provider_id")
    if "ssh_user" in config and config["ssh_user"] != "root":
        raise ProtocolError("ssh_user")
    for field, default, maximum in (("ssh_port", 22, 65535), ("max_artifact_bytes", MAX_ARTIFACT, MAX_ARTIFACT),
                                  ("max_uncompressed_bytes", MAX_UNCOMPRESSED, MAX_UNCOMPRESSED), ("timeout_seconds", 180, 180),
                                  ("max_backup_count", 3, 100), ("max_backup_bytes", 2 << 30, 20 << 30),
                                  ("min_free_bytes", 512 << 20, 20 << 30)):
        value = config.get(field, default)
        if type(value) is not int or not 1 <= value <= maximum:
            raise ProtocolError("config_limit")
        config[field] = value
    if "expected_groups" in config and (not isinstance(config["expected_groups"], list) or not config["expected_groups"]
            or not all(isinstance(n, str) and IDENTIFIER.fullmatch(n) for n in config["expected_groups"])):
        raise ProtocolError("expected_groups")
    trusted_file(config.get("ssh_key_file"), uid=uid, allow_test_paths=allow_test_paths)
    _host_key(config, uid=uid, allow_test_paths=allow_test_paths)
    _directory(config.get("state_dir"), uid=uid, allow_test_paths=allow_test_paths)


def load_config(path, *, allow_test_paths=False) -> dict:
    path = trusted_file(str(path), allow_test_paths=allow_test_paths)
    config = decode_json(_read_private(path, 65536))
    validate_config(config, allow_test_paths=allow_test_paths)
    return config


def ssh_argv(config: dict) -> list[str]:
    return ["/usr/bin/ssh", "-F", "/dev/null", "-T", "-a", "-x",
            "-oBatchMode=yes", "-oIdentitiesOnly=yes", "-oPreferredAuthentications=publickey",
            "-oPasswordAuthentication=no", "-oKbdInteractiveAuthentication=no", "-oHostbasedAuthentication=no",
            "-oGSSAPIAuthentication=no", "-oStrictHostKeyChecking=yes",
            "-oUserKnownHostsFile=" + config["known_hosts_file"], "-oGlobalKnownHostsFile=/dev/null",
            "-oVerifyHostKeyDNS=no", "-oCheckHostIP=yes", "-oUpdateHostKeys=no", "-oAddKeysToAgent=no",
            "-oClearAllForwardings=yes", "-oPermitLocalCommand=no", "-oProxyCommand=none", "-oProxyJump=none",
            "-oControlMaster=no", "-oControlPath=none", "-oConnectTimeout=10", "-oConnectionAttempts=1",
            "-oServerAliveInterval=15", "-oServerAliveCountMax=2", "-i", config["ssh_key_file"],
            "-p", str(config["ssh_port"]), "--", "root@" + config["source_host"], REMOTE_MARKER]


def ssh_stream(argv: list[str], request: bytes, output, max_bytes: int, timeout: int) -> int:
    """Stream with fixed SSH options, bounded stdout/time, and no shell."""
    process = subprocess.Popen(argv, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                               env=SAFE_ENV, start_new_session=True)
    selector = selectors.DefaultSelector(); started = time.monotonic(); total = 0
    try:
        # This fixed request is below 1KiB, less than the POSIX minimum pipe
        # capacity; it cannot deadlock behind the potentially large response.
        if len(request) > 1024:
            raise ProtocolError("request_size")
        process.stdin.write(request); process.stdin.close()
        os.set_blocking(process.stdout.fileno(), False)
        selector.register(process.stdout, selectors.EVENT_READ)
        while selector.get_map():
            remaining = timeout - (time.monotonic() - started)
            if remaining <= 0:
                raise ProtocolError("ssh_timeout")
            for key, _ in selector.select(remaining):
                chunk = os.read(key.fileobj.fileno(), 65536)
                if not chunk:
                    selector.unregister(key.fileobj); break
                total += len(chunk)
                if total > max_bytes:
                    raise ProtocolError("artifact_size_limit")
                output.write(chunk)
        return process.wait(timeout=max(0.01, timeout - (time.monotonic() - started)))
    except subprocess.TimeoutExpired:
        raise ProtocolError("ssh_timeout") from None
    finally:
        selector.close()
        if process.poll() is None:
            with contextlib.suppress(ProcessLookupError):
                os.killpg(process.pid, signal.SIGKILL)
            process.wait(timeout=3)
        for stream in (process.stdin, process.stdout):
            if stream is not None and not stream.closed:
                stream.close()


def member_name(value: str) -> str:
    if not isinstance(value, str) or not value or len(value) > 4096 or value.startswith("/") or "\\" in value or "\x00" in value:
        raise ProtocolError("archive_path")
    name = value.rstrip("/")
    if any(part in {"", ".", ".."} for part in name.split("/")):
        raise ProtocolError("archive_path")
    return name


def _source_path(value) -> str:
    if not isinstance(value, str) or not value.startswith("/") or "\x00" in value or "\\" in value or any(p in {"", ".", ".."} for p in value.split("/")[1:]):
        raise ProtocolError("manifest_source_path")
    return value


def _inside(path: str, parent: str) -> bool:
    return path == parent or path.startswith(parent.rstrip("/") + "/")


def _fsync_directory(path: Path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try: os.fsync(fd)
    finally: os.close(fd)


def _atomic_json(path: Path, value: dict):
    temporary = path.parent / ("." + path.name + "." + secrets.token_hex(8))
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        with os.fdopen(fd, "wb") as out:
            out.write(canonical_json(value)); out.flush(); os.fsync(out.fileno())
        os.replace(temporary, path); _fsync_directory(path.parent)
    finally:
        with contextlib.suppress(FileNotFoundError): temporary.unlink()


def _octal(field: bytes) -> int:
    field = field.strip(b" \x00")
    if not field: return 0
    if len(field) > 12 or any(c not in b"01234567" for c in field):
        raise ProtocolError("archive_numeric_header")
    return int(field, 8)


def _pax(body: bytes):
    """Only tiny producer path/uid/gid extensions, never sparse/size overrides."""
    offset = 0; seen = set()
    while offset < len(body):
        end = body.find(b" ", offset, min(len(body), offset + 8))
        if end < 0 or not body[offset:end].isdigit(): raise ProtocolError("archive_pax")
        length = int(body[offset:end])
        if not 5 <= length <= 8192 or offset + length > len(body): raise ProtocolError("archive_pax")
        record = body[end + 1:offset + length]
        if not record.endswith(b"\n") or b"=" not in record: raise ProtocolError("archive_pax")
        key, value = record[:-1].split(b"=", 1)
        if key not in {b"path", b"uid", b"gid"} or key in seen: raise ProtocolError("archive_pax")
        seen.add(key)
        if key == b"path":
            try: member_name(value.decode("utf-8"))
            except UnicodeError: raise ProtocolError("archive_pax") from None
        elif not value.isdigit() or len(value) > 10 or int(value) >= 1 << 31:
            raise ProtocolError("archive_pax")
        offset += length


def preflight_tar(path: Path, total_bytes: int, max_file_bytes: int):
    """Bound parser metadata BEFORE tarfile can allocate a PAX/sparse body."""
    offset = 0; count = 0; pending_pax = False
    with path.open("rb") as source:
        while offset + 512 <= total_bytes:
            source.seek(offset); block = source.read(512)
            if block == b"\x00" * 512:
                remaining = total_bytes - offset
                if pending_pax or not 1024 <= remaining <= 10240 or remaining % 512:
                    raise ProtocolError("archive_end")
                if any(source.read()): raise ProtocolError("archive_trailing_data")
                return
            count += 1
            if count > MAX_MEMBERS * 2 + 1: raise ProtocolError("archive_member_count")
            if _octal(block[148:156]) != sum(block[:148]) + 8 * 32 + sum(block[156:]):
                raise ProtocolError("archive_header_checksum")
            kind = block[156:157]; size = _octal(block[124:136])
            if kind not in {b"0", b"\x00", b"5", b"x"}: raise ProtocolError("archive_member_type")
            if count == 1 and (block[:100].split(b"\x00", 1)[0] != b"MANIFEST.json" or kind not in {b"0", b"\x00"} or not 0 < size <= MAX_MANIFEST):
                raise ProtocolError("manifest_first")
            if kind == b"x":
                if pending_pax or size > 65536: raise ProtocolError("archive_pax_size")
                _pax(source.read(size)); pending_pax = True
            else:
                pending_pax = False
                if (kind == b"5" and size) or (count > 1 and size > max_file_bytes):
                    raise ProtocolError("archive_member_size")
            offset += 512 + (size + 511) // 512 * 512
            if offset > total_bytes - 1024: raise ProtocolError("archive_end")
    raise ProtocolError("archive_end")


class ReservedWriter:
    def __init__(self, output, guard):
        self.output = output; self.guard = guard

    def write(self, chunk):
        self.guard(len(chunk))
        return self.output.write(chunk)


class BackupClient:
    """Transport injection is for fake tests; production always uses ssh_stream."""
    def __init__(self, config: dict, *, transport=ssh_stream, clock=time.time,
                 nonce=lambda: secrets.token_hex(16), allow_test_paths=False,
                 disk_free=lambda path: shutil.disk_usage(path).free):
        validate_config(config, allow_test_paths=allow_test_paths)
        self.config = config; self.transport = transport; self.clock = clock; self.nonce = nonce
        self.state = Path(config["state_dir"])
        self.disk_free = disk_free

    def _usage(self):
        count = 0; total = 0; entries = 0
        for parent, directories, files in os.walk(self.state, followlinks=False):
            if Path(parent) == self.state:
                count = sum(bool(re.fullmatch(r"snapshot-[0-9a-f]{32}", name)) for name in directories)
            for name in directories + files:
                entry = Path(parent) / name; info = entry.lstat(); entries += 1
                if entries > MAX_MEMBERS or info.st_uid != os.geteuid() or info.st_mode & 0o077 or not (stat.S_ISREG(info.st_mode) or stat.S_ISDIR(info.st_mode)):
                    raise ProtocolError("backup_state_unverified")
                if stat.S_ISREG(info.st_mode): total += info.st_size
        return count, total

    def _space(self, additional=0):
        _, size = self._usage()
        if size + additional > self.config["max_backup_bytes"]:
            raise ProtocolError("backup_storage_budget_hold")
        if self.disk_free(self.state) - additional < self.config["min_free_bytes"]:
            raise ProtocolError("backup_free_space_hold")

    @contextlib.contextmanager
    def _lock(self):
        fd = os.open(self.state / ".backup.lock", os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        try:
            info = os.fstat(fd)
            if info.st_uid != os.geteuid() or info.st_mode & 0o077 or not stat.S_ISREG(info.st_mode):
                raise ProtocolError("backup_lock_permissions")
            try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError: raise ProtocolError("backup_in_progress_hold") from None
            yield
        finally: os.close(fd)

    def _request(self, op, request_id):
        return canonical_json(dict(op=op, request_id=request_id, generation=self.config["generation"], config_hash=self.config["config_hash"]))

    def _identity(self, response: dict, request_id: str, op: str):
        if not isinstance(response, dict) or response.get("schema") != SIDE_SCHEMA or response.get("operation") != op:
            raise ProtocolError("response_schema")
        if response.get("request_id") != request_id or response.get("node_id") != self.config["node_id"]:
            raise ProtocolError("response_identity")
        if type(response.get("generation")) is not int or response["generation"] != self.config["generation"] or response.get("config_hash") != self.config["config_hash"]:
            raise ProtocolError("response_generation")
        observed = response.get("observed_at")
        if type(observed) is not int or not self.clock() - 300 <= observed <= self.clock() + 30:
            raise ProtocolError("response_stale")
        if type(response.get("ok")) is not bool:
            raise ProtocolError("response_ok")

    def _download(self, op: str, request_id: str, target: Path):
        limit = self.config["max_artifact_bytes"] if op == "export" else 1 << 20
        fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "wb") as output:
            code = self.transport(ssh_argv(self.config), self._request(op, request_id), ReservedWriter(output, self._space), limit, self.config["timeout_seconds"])
            output.flush(); os.fsync(output.fileno())
            if code != 0:
                raise ProtocolError("ssh_exit_failed")
            if os.fstat(output.fileno()).st_size > limit:
                raise ProtocolError("artifact_size_limit")

    def _base_metadata(self, request_id):
        return dict(schema=SCHEMA, request_id=request_id, node_id=self.config["node_id"], generation=self.config["generation"],
                    config_hash=self.config["config_hash"], source_host=self.config["source_host"], provider_id=self.config["provider_id"],
                    verified_at=int(self.clock()), ready=False, restore_current=False, persistent_fence=False)

    def inspect(self) -> dict:
        request_id = self.nonce()
        if not isinstance(request_id, str) or not re.fullmatch(r"[0-9a-f]{32}", request_id):
            raise ProtocolError("request_id")
        with tempfile.TemporaryDirectory(prefix=".inspect-", dir=self.state) as name:
            target = Path(name) / "response.json"
            self._download("inspect", request_id, target)
            response = decode_json(target.read_bytes())
            self._identity(response, request_id, "inspect")
            registry = response.get("registry_hash")
            if not isinstance(registry, str) or not HEX64.fullmatch(registry):
                raise ProtocolError("registry_hash")
            summary = {**self._base_metadata(request_id), "operation": "inspect", "ok": True,
                       "independent_control_reachable": True, "inventory_safe": response["ok"],
                       "configured_inventory_complete": response.get("configured_inventory_complete") is True,
                       "docker_writable_layer_verified": response.get("docker_writable_layer_verified") is True,
                       "registry_hash": registry, "application_health_proven": False,
                       "issue_codes": sorted({i.get("code", "unknown") for i in response.get("issues", []) + response.get("completeness_issues", []) if isinstance(i, dict) and isinstance(i.get("code"), str)})}
            _atomic_json(self.state / "inspect-latest.json", summary)
            return summary

    def _manifest(self, manifest: dict, request_id: str) -> tuple[dict, dict]:
        self._identity(manifest, request_id, "export")
        if manifest["ok"] is not True or manifest.get("consistency_scope") != "per_database_snapshot":
            raise ProtocolError("snapshot_not_safe")
        for field in ("global_consistency_proven", "persistent_fence", "application_health_proven"):
            if manifest.get(field) is not False:
                raise ProtocolError("unproven_snapshot_claim")
        groups = manifest.get("groups")
        if not isinstance(groups, dict) or not groups or len(groups) > 32:
            raise ProtocolError("manifest_groups")
        if "expected_groups" in self.config and set(groups) != set(self.config["expected_groups"]):
            raise ProtocolError("manifest_groups")
        for name, group in groups.items():
            if not isinstance(name, str) or not IDENTIFIER.fullmatch(name) or not isinstance(group, dict) or group.get("consistency") not in {"stateless", "sqlite"}:
                raise ProtocolError("manifest_groups")
            if not isinstance(group.get("paths"), list) or not group["paths"]:
                raise ProtocolError("manifest_groups")
            for p in group["paths"]: _source_path(p)
        files = manifest.get("files"); directories = manifest.get("directories"); links = manifest.get("symlinks")
        if not isinstance(files, list) or not files or not isinstance(directories, list) or not isinstance(links, list) or len(files) + len(directories) + len(links) > MAX_MEMBERS:
            raise ProtocolError("manifest_entries")
        expected_files = {}; expected_dirs = {}; exported_paths = set(); sqlite_paths = {}
        total = 0
        for entry, is_directory in [(e, False) for e in files] + [(e, True) for e in directories]:
            if not isinstance(entry, dict): raise ProtocolError("manifest_entry")
            member = member_name(entry.get("member"))
            if member in expected_files or member in expected_dirs or member == "MANIFEST.json":
                raise ProtocolError("manifest_duplicate")
            if type(entry.get("mode")) is not int or not 0 <= entry["mode"] <= 0o777 or any(type(entry.get(k)) is not int or not 0 <= entry[k] < 1 << 31 for k in ("uid", "gid")):
                raise ProtocolError("manifest_permissions")
            path = entry.get("path"); group = entry.get("group")
            if path is not None:
                _source_path(path)
                if group not in groups or not any(_inside(path, p) for p in groups[group]["paths"]) or member != "data/" + path.lstrip("/") or path in exported_paths:
                    raise ProtocolError("manifest_scope")
                exported_paths.add(path)
            elif is_directory:
                raise ProtocolError("manifest_scope")
            elif group == "docker-image":
                digest = entry.get("docker_digest")
                if not isinstance(digest, str) or not re.fullmatch(r"sha256:[0-9a-f]{64}", digest) or member != "images/" + digest[7:] + ".tar":
                    raise ProtocolError("manifest_image")
            elif group == "docker-config":
                if not re.fullmatch(r"private/docker/[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}\.json", member):
                    raise ProtocolError("manifest_docker_config")
            else:
                raise ProtocolError("manifest_scope")
            if is_directory:
                expected_dirs[member] = entry
            else:
                if type(entry.get("size")) is not int or not 0 <= entry["size"] <= self.config["max_uncompressed_bytes"] or not isinstance(entry.get("sha256"), str) or not HEX64.fullmatch(entry["sha256"]):
                    raise ProtocolError("manifest_file")
                total += entry["size"]
                if total > self.config["max_uncompressed_bytes"]:
                    raise ProtocolError("uncompressed_size_limit")
                expected_files[member] = entry
                if entry.get("sqlite") is True:
                    if path is None: raise ProtocolError("manifest_sqlite")
                    sqlite_paths[path] = entry
        seen_links = set()
        for link in links:
            if not isinstance(link, dict) or link.get("target_in_inventory_scope") is not True:
                raise ProtocolError("manifest_symlink")
            path = _source_path(link.get("path")); target = link.get("target"); group = link.get("group")
            if path in exported_paths or path in seen_links or group not in groups or not any(_inside(path, p) for p in groups[group]["paths"]) or not isinstance(target, str) or "\x00" in target or "\\" in target:
                raise ProtocolError("manifest_symlink")
            seen_links.add(path)
            resolved = os.path.normpath(target if target.startswith("/") else str(PurePosixPath(path).parent / target))
            if resolved not in exported_paths:
                raise ProtocolError("manifest_symlink_target")
        snapshots = manifest.get("sqlite_snapshots")
        if not isinstance(snapshots, list) or len(snapshots) != len(sqlite_paths):
            raise ProtocolError("manifest_sqlite")
        seen_sqlite = set()
        for snap in snapshots:
            if not isinstance(snap, dict) or snap.get("path") not in sqlite_paths or snap["path"] in seen_sqlite or snap.get("quick_check") != "ok":
                raise ProtocolError("manifest_sqlite")
            count = snap.get("page_count"); size = snap.get("page_size")
            if type(count) is not int or count < 1 or type(size) is not int or not 512 <= size <= 65536 or size & (size - 1) or count * size != sqlite_paths[snap["path"]]["size"]:
                raise ProtocolError("manifest_sqlite")
            if type(snap.get("snapshot_at")) is not int or not self.clock() - 300 <= snap["snapshot_at"] <= self.clock() + 30:
                raise ProtocolError("manifest_sqlite_stale")
            seen_sqlite.add(snap["path"])
        if type(manifest.get("total_uncompressed_bytes")) is not int or manifest["total_uncompressed_bytes"] != total:
            raise ProtocolError("manifest_total")
        registry = hashlib.sha256(canonical_json({"files": files, "directories": directories, "symlinks": links})).hexdigest()
        if manifest.get("registry_hash") != registry:
            raise ProtocolError("manifest_registry_hash")
        return expected_files, expected_dirs

    def validate_archive(self, path: Path, request_id: str, temporary_dir: Path) -> dict:
        """Validate the gzip stream and tar records; never extract a member."""
        plain = temporary_dir / "validation.tar"
        total = 0
        try:
            fd = os.open(plain, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
            with os.fdopen(fd, "wb") as out, gzip.open(path, "rb") as compressed:
                while chunk := compressed.read(1024 * 1024):
                    total += len(chunk)
                    if total > self.config["max_uncompressed_bytes"] + MAX_MANIFEST + MAX_MEMBERS * 1024 + 10240:
                        raise ProtocolError("uncompressed_size_limit")
                    self._space(len(chunk))
                    out.write(chunk)
            with plain.open("rb") as source:
                first_header = source.read(512)
            if len(first_header) != 512 or first_header[:100].split(b"\x00", 1)[0] != b"MANIFEST.json":
                raise ProtocolError("manifest_first")
            preflight_tar(plain, total, self.config["max_uncompressed_bytes"])
            with tarfile.open(plain, "r:") as archive:
                first = archive.next()
                if first is None or first.name != "MANIFEST.json" or not first.isreg() or first.offset != 0 or first.offset_data != 512 or not 0 < first.size <= MAX_MANIFEST or first.pax_headers:
                    raise ProtocolError("manifest_first")
                manifest_bytes = archive.extractfile(first).read(MAX_MANIFEST + 1)
                manifest = decode_large_json(manifest_bytes)
                expected_files, expected_dirs = self._manifest(manifest, request_id)
                seen = {"MANIFEST.json"}; last_end = first.offset_data + (first.size + 511) // 512 * 512
                for member in archive:
                    if member.name == first.name and member.offset == first.offset:
                        continue  # tarfile iteration includes the first cached member.
                    name = member_name(member.name)
                    if name in seen: raise ProtocolError("archive_duplicate")
                    seen.add(name)
                    if len(seen) > MAX_MEMBERS or member.sparse is not None or not (member.isreg() or member.isdir()):
                        raise ProtocolError("archive_member_type")
                    expected = expected_dirs.get(name) if member.isdir() else expected_files.get(name)
                    if expected is None or member.mode != expected["mode"] or member.uid != expected["uid"] or member.gid != expected["gid"]:
                        raise ProtocolError("archive_unlisted_member")
                    if member.isdir():
                        if member.size != 0: raise ProtocolError("archive_directory_size")
                    else:
                        if member.size != expected["size"]: raise ProtocolError("archive_member_size")
                        digest = hashlib.sha256(); size = 0
                        with archive.extractfile(member) as data:
                            while chunk := data.read(1024 * 1024):
                                size += len(chunk); digest.update(chunk)
                                if size > expected["size"]: raise ProtocolError("archive_member_size")
                        if size != expected["size"] or digest.hexdigest() != expected["sha256"]:
                            raise ProtocolError("archive_member_hash")
                    last_end = member.offset_data + (member.size + 511) // 512 * 512
                if seen != {"MANIFEST.json", *expected_files, *expected_dirs}:
                    raise ProtocolError("archive_missing_member")
            if total % 512 or not 1024 <= total - last_end <= 10240:
                raise ProtocolError("archive_end")
            with plain.open("rb") as source:
                source.seek(last_end)
                if any(source.read()): raise ProtocolError("archive_trailing_data")
            return manifest
        except (gzip.BadGzipFile, EOFError, tarfile.TarError):
            raise ProtocolError("archive_invalid") from None
        finally:
            with contextlib.suppress(FileNotFoundError): plain.unlink()

    def backup(self) -> dict:
        with self._lock():
            count, _ = self._usage()
            if count >= self.config["max_backup_count"]:
                raise ProtocolError("backup_count_budget_hold")
            self._space(self.config["max_artifact_bytes"])
            return self._backup_locked()

    def _backup_locked(self) -> dict:
        request_id = self.nonce()
        if not isinstance(request_id, str) or not re.fullmatch(r"[0-9a-f]{32}", request_id):
            raise ProtocolError("request_id")
        temporary = Path(tempfile.mkdtemp(prefix=".pending-", dir=self.state))
        final = self.state / ("snapshot-" + request_id)
        published = False
        try:
            path = temporary / "snapshot.tar.gz"
            self._download("export", request_id, path)
            manifest = self.validate_archive(path, request_id, temporary)
            digest = hashlib.sha256()
            with path.open("rb") as source:
                while chunk := source.read(1024 * 1024): digest.update(chunk)
            metadata = {**self._base_metadata(request_id), "operation": "backup", "ok": True,
                        "artifact_id": final.name, "artifact_sha256": digest.hexdigest(), "artifact_bytes": path.stat().st_size,
                        "manifest_sha256": hashlib.sha256(canonical_json(manifest)).hexdigest(),
                        "registry_hash": manifest["registry_hash"], "artifact_verified": True,
                        "per_database_ready": bool(manifest["sqlite_snapshots"]), "global_consistency_proven": False,
                        "docker_writable_layer_verified": manifest.get("docker_writable_layer_verified") is True,
                        "configured_inventory_complete": manifest.get("configured_inventory_complete") is True,
                        "file_count": len(manifest["files"]), "sqlite_count": len(manifest["sqlite_snapshots"]),
                        "requires_restore_test_and_fence_checkpoint": True}
            _atomic_json(temporary / "metadata.json", metadata)
            _fsync_directory(temporary)
            if final.exists(): raise ProtocolError("artifact_exists")
            os.rename(temporary, final); published = True; _fsync_directory(self.state)
            _atomic_json(self.state / "latest.json", metadata)
            return metadata
        finally:
            if not published: shutil.rmtree(temporary)


def decode_large_json(body: bytes) -> dict:
    # Share duplicate/nonfinite rejection with protocol.decode_json while
    # allowing the deliberately larger manifest size than an agent message.
    if len(body) > MAX_MANIFEST: raise ProtocolError("manifest_size")
    def pairs(values):
        out = {}
        for key, value in values:
            if key in out: raise ProtocolError("json_duplicate_key")
            out[key] = value
        return out
    try:
        result = json.loads(body, object_pairs_hook=pairs, parse_constant=lambda value: (_ for _ in ()).throw(ValueError()))
    except (ValueError, UnicodeError):
        raise ProtocolError("json_invalid") from None
    if not isinstance(result, dict): raise ProtocolError("json_object")
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", required=True)
    parser.add_argument("--op", choices=("inspect", "backup"), required=True)
    args = parser.parse_args(); os.umask(0o077)
    try:
        client = BackupClient(load_config(args.config))
        result = client.inspect() if args.op == "inspect" else client.backup()
        sys.stdout.buffer.write(canonical_json(result) + b"\n")
        return 0
    except (ProtocolError, OSError, ValueError, subprocess.SubprocessError) as error:
        code = str(error) if isinstance(error, ProtocolError) else "local_operation_failed"
        sys.stderr.write(json.dumps({"ok": False, "code": code}) + "\n")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
