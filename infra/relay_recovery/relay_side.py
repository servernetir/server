#!/usr/bin/env python3
"""Narrow SSH forced-command snapshot helper; no remote shell or path input.

Forced command (not a general SSH/SFTP account)::
  python3 /usr/local/lib/servernet-relay-recovery/relay_side.py \
      --config /etc/servernet/relay-side.json

stdin: <=16KiB JSON {op,request_id,generation?,config_hash?}.
ops inspect/status/restore-plan return JSON; export returns ONLY a completed
gzip tar stream on stdout (no banner). Nonzero exit invalidates any stream.
The first archive member, MANIFEST.json, binds request_id, local node/generation
and config_hash and lists every data member's SHA-256. Controller must pin SSH
host identity, capture to a private temporary file, require exit zero, validate
gzip/tar/member digests and request_id, and extract with its own path allowlist.
No SFTP arbitrary-file service is introduced.

Local root-owned 0600 config chooses ALL paths, services, images and limits.
Example groups are gateway (/opt/servernet-gateway plus explicit /etc units),
marzban (the actual Docker bind-mount DB/config), powerdns (/etc/powerdns plus
actual SQLite state) and nginx (/etc/nginx and explicit TLS/key paths). Paths
must be inventoried, never guessed from these examples. No source defaults
copy /etc wholesale. Mutable SQLite files must be explicitly registered and
are copied through sqlite3.Connection.backup, not tarred live. Every snapshot
passes quick_check. Other mutable database formats block export. Snapshot
consistency is PER DATABASE, not an assertion of cross-service atomicity or
zero data loss. Docker image export is restricted to configured sha256 digests.

Symlinks are recorded as metadata, never followed or added as tar links. Only
links resolving into included inventory get a possible restore-plan entry.
Special files block export unless explicitly excluded by local config.
Docker's writable layer is checked with read-only `docker diff`. Only an empty
diff or exact administrator-listed disposable paths proves that layer safe to
omit; no recursive/glob approvals or broad /var, /opt, /app approvals exist.
Unverified layers are explicit completeness issues. Per-database exports remain
available, with configured_inventory_complete:false and no restore proof.

fence is disabled by default. When explicitly enabled, it stops ONLY the
configured writer units, after identity checks and journaling intent. SSH,
network, Docker daemon and recovery/control units cannot be stopped. Unit
stopping is NOT persistent fencing; the receipt explicitly says so. The
controller must independently prove provider/lease fencing before promotion.
"""
from __future__ import annotations

import argparse
import contextlib
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import sqlite3
import stat
import subprocess
import sys
import tarfile
import tempfile
import time

try:
    from .protocol import canonical_json, decode_json, private_file, ProtocolError, IDENTIFIER, HEX64
except ImportError:
    from protocol import canonical_json, decode_json, private_file, ProtocolError, IDENTIFIER, HEX64

SCHEMA = "servernet.relay.side.v1"
MAX_REQUEST = 16384
PROTECTED_UNIT = re.compile(r"(?:^|[-_.])(ssh|sshd|recovery|network|networking|NetworkManager|systemd|dbus|docker|containerd|cloudflared)(?:[-_.]|$)", re.I)
UNIT = re.compile(r"\A[a-zA-Z0-9][a-zA-Z0-9_.@:-]{0,119}\.service\Z")
DIGEST = re.compile(r"\Asha256:[0-9a-f]{64}\Z")
SQLITE_HEADER = b"SQLite format 3\x00"
BROAD_OVERLAY_PATHS = {"/", "/var", "/opt", "/app", "/code", "/etc", "/usr", "/bin", "/sbin", "/lib", "/lib64", "/root", "/home", "/tmp", "/run", "/proc", "/sys", "/dev"}


def within(path: Path, parent: Path) -> bool:
    return path == parent or parent in path.parents


def absolute_path(value) -> Path:
    if not isinstance(value, str) or not value.startswith("/") or "\x00" in value:
        raise ProtocolError("configured_path")
    path = Path(value)
    if ".." in path.parts or path == Path("/"):
        raise ProtocolError("configured_path")
    return path


def no_symlink_parents(path: Path):
    for p in path.parents:
        info = p.lstat()
        if not stat.S_ISDIR(info.st_mode) or p.is_symlink():
            raise ProtocolError("source_parent_symlink")


@contextlib.contextmanager
def parent_fd(path: Path):
    """Pin each directory without following links, including during races."""
    fd = os.open("/", os.O_RDONLY | os.O_DIRECTORY)
    try:
        for part in path.parent.parts[1:]:
            nxt = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd); fd = nxt
        yield fd
    finally:
        os.close(fd)


def source_info(path: Path):
    with parent_fd(path) as fd:
        return os.stat(path.name, dir_fd=fd, follow_symlinks=False)


def source_open(path: Path, flags=os.O_RDONLY):
    with parent_fd(path) as fd:
        return os.open(path.name, flags | os.O_NOFOLLOW, dir_fd=fd)


def load_config(path: str | Path, *, root_owned=True) -> dict:
    config_path = private_file(path, root_owned=root_owned)
    if config_path.stat().st_size > 65536:
        raise ProtocolError("config_size")
    config = decode_json(config_path.read_bytes())
    validate_config(config, root_owned=root_owned)
    return config


def validate_config(config: dict, *, root_owned=True):
    if not isinstance(config, dict) or config.get("schema") != SCHEMA:
        raise ProtocolError("config_schema")
    if not isinstance(config.get("node_id"), str) or not IDENTIFIER.fullmatch(config["node_id"]):
        raise ProtocolError("node_id")
    if type(config.get("generation")) is not int or config["generation"] < 1:
        raise ProtocolError("generation")
    if not isinstance(config.get("config_hash"), str) or not HEX64.fullmatch(config["config_hash"]):
        raise ProtocolError("config_hash")
    work = absolute_path(config.get("work_dir"))
    no_symlink_parents(work)
    work.mkdir(mode=0o700, parents=False, exist_ok=True)
    info = work.lstat()
    if not stat.S_ISDIR(info.st_mode) or stat.S_IMODE(info.st_mode) & 0o077 or (root_owned and info.st_uid != 0):
        raise ProtocolError("work_dir_permissions")
    for parent in work.parents:
        info = parent.lstat()
        if root_owned and (info.st_uid != 0 or stat.S_IMODE(info.st_mode) & 0o022):
            raise ProtocolError("work_parent_permissions")
    groups = config.get("groups")
    if not isinstance(groups, dict) or not 1 <= len(groups) <= 32:
        raise ProtocolError("groups")
    includes = []
    for name, group in groups.items():
        if not isinstance(name, str) or not IDENTIFIER.fullmatch(name) or not isinstance(group, dict):
            raise ProtocolError("group")
        if group.get("consistency", "unverified") not in {"stateless", "sqlite", "external", "unverified"}:
            raise ProtocolError("group_consistency")
        paths = group.get("paths")
        if not isinstance(paths, list) or not 1 <= len(paths) <= 128:
            raise ProtocolError("group_paths")
        for value in paths:
            p = absolute_path(value)
            if within(p, work) or within(work, p):
                raise ProtocolError("recovery_directory_included")
            includes.append(p)
    if len(includes) != len(set(includes)):
        raise ProtocolError("duplicate_include")
    required = config.get("required_groups", list(groups))
    if not isinstance(required, list) or not all(isinstance(v, str) for v in required) or set(required) - set(groups):
        raise ProtocolError("required_groups")
    for field in ("exclude_paths", "units", "fence_units", "docker_images", "docker_containers"):
        if not isinstance(config.get(field, []), list):
            raise ProtocolError("config_list")
    for value in config.get("exclude_paths", []):
        absolute_path(value)
    databases = config.get("sqlite_databases", [])
    if not isinstance(databases, list):
        raise ProtocolError("sqlite_databases")
    registered = []
    for database in databases:
        if not isinstance(database, dict) or database.get("group") not in groups:
            raise ProtocolError("sqlite_database")
        p = absolute_path(database.get("path"))
        if not any(within(p, absolute_path(i)) for i in groups[database["group"]]["paths"]):
            raise ProtocolError("database_outside_group")
        registered.append(p)
    if len(registered) != len(set(registered)):
        raise ProtocolError("duplicate_database")
    for field in ("units", "fence_units", "docker_images", "docker_containers", "exclude_paths"):
        if len(config.get(field, [])) > 128:
            raise ProtocolError("config_list_limit")
    for unit in config.get("units", []) + config.get("fence_units", []):
        if not isinstance(unit, str) or not UNIT.fullmatch(unit):
            raise ProtocolError("unit")
    for unit in config.get("fence_units", []):
        if PROTECTED_UNIT.search(unit):
            raise ProtocolError("protected_unit")
    if type(config.get("fence_enabled", False)) is not bool:
        raise ProtocolError("fence_enabled")
    for image in config.get("docker_images", []):
        if not isinstance(image, str) or not DIGEST.fullmatch(image):
            raise ProtocolError("docker_image_digest")
    for name in config.get("docker_containers", []):
        if not isinstance(name, str) or not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}", name):
            raise ProtocolError("docker_container")
    if type(config.get("docker_include_config", False)) is not bool:
        raise ProtocolError("docker_include_config")
    overlay_policy = config.get("docker_disposable_overlay_paths", {})
    if not isinstance(overlay_policy, dict) or set(overlay_policy) - set(config.get("docker_containers", [])):
        raise ProtocolError("docker_overlay_policy")
    for paths in overlay_policy.values():
        if not isinstance(paths, list) or len(paths) > 512 or not all(isinstance(p, str) for p in paths) or len(set(paths)) != len(paths):
            raise ProtocolError("docker_overlay_policy")
        for path in paths:
            p = absolute_path(path)
            if str(p) != path or path in BROAD_OVERLAY_PATHS or len(path) > 1024 or any(c in path for c in "*?[]{}\r\n"):
                raise ProtocolError("docker_overlay_policy")
    for name, default, maximum in (("max_files", 20000, 100000), ("max_bytes", 2 << 30, 20 << 30),
                                    ("max_file_bytes", 512 << 20, 8 << 30), ("snapshot_timeout", 60, 300)):
        value = config.get(name, default)
        if type(value) is not int or not 1 <= value <= maximum:
            raise ProtocolError("limit")
        config[name] = value


def request_from_bytes(body: bytes, config: dict) -> dict:
    if len(body) > MAX_REQUEST:
        raise ProtocolError("request_size")
    request = decode_json(body)
    if set(request) - {"op", "request_id", "generation", "config_hash"}:
        raise ProtocolError("request_fields")
    if request.get("op") not in {"inspect", "export", "restore-plan", "fence", "status"}:
        raise ProtocolError("operation")
    if not isinstance(request.get("request_id"), str) or not IDENTIFIER.fullmatch(request["request_id"]):
        raise ProtocolError("request_id")
    if "generation" in request and (type(request["generation"]) is not int or request["generation"] != config["generation"]):
        raise ProtocolError("generation_mismatch")
    if "config_hash" in request and request["config_hash"] != config["config_hash"]:
        raise ProtocolError("config_mismatch")
    if request["op"] == "fence" and (request.get("generation") != config["generation"] or request.get("config_hash") != config["config_hash"]):
        raise ProtocolError("fence_identity_required")
    return request


class RelaySide:
    def __init__(self, config: dict, *, runner=subprocess.run, root_owned=True):
        self.config = config
        validate_config(config, root_owned=root_owned)
        self.runner = runner
        self.includes = [(name, absolute_path(p)) for name, group in config["groups"].items() for p in group["paths"]]
        self.excludes = [absolute_path(p) for p in config.get("exclude_paths", [])]
        self.databases = {absolute_path(d["path"]): d for d in config.get("sqlite_databases", [])}

    def identity(self, request_id):
        return {"schema": SCHEMA, "request_id": request_id, "node_id": self.config["node_id"],
                "generation": self.config["generation"], "config_hash": self.config["config_hash"],
                "observed_at": int(time.time()), "hostname": socket_hostname()}

    def _excluded(self, path):
        return any(within(path, p) for p in self.excludes)

    def inventory(self) -> tuple[list[dict], list[dict], list[dict]]:
        files = {}; links = []; issues = []; directories = {}; counts = {name: 0 for name in self.config["groups"]}
        total = 0
        sqlite_sidecars = {Path(str(p) + suffix) for p in self.databases for suffix in ("-wal", "-shm", "-journal")}
        def walk(group, path):
            nonlocal total
            if self._excluded(path) or path in sqlite_sidecars:
                return
            try:
                info = source_info(path)
            except FileNotFoundError:
                issues.append({"group": group, "path": str(path), "code": "include_missing"}); return
            if stat.S_ISLNK(info.st_mode):
                with parent_fd(path) as fd:
                    target = os.readlink(path.name, dir_fd=fd)
                resolved = Path(os.path.normpath(str(path.parent / target))) if not target.startswith("/") else Path(os.path.normpath(target))
                allowed = any(within(resolved, inc) for _, inc in self.includes) and not self._excluded(resolved)
                links.append({"group": group, "path": str(path), "target": target, "target_in_inventory_scope": allowed})
                if not allowed:
                    issues.append({"group": group, "path": str(path), "code": "symlink_target_outside_scope"})
                return
            if stat.S_ISDIR(info.st_mode):
                directories[path] = {"group": group, "path": str(path), "member": "data/" + path.as_posix().lstrip("/"),
                                     "mode": stat.S_IMODE(info.st_mode) & 0o777, "uid": info.st_uid, "gid": info.st_gid}
                if len(directories) + len(files) > self.config["max_files"]:
                    raise ProtocolError("inventory_limit")
                fd = source_open(path, os.O_RDONLY | os.O_DIRECTORY)
                try:
                    names = sorted(os.listdir(fd))
                finally:
                    os.close(fd)
                for name in names:
                    walk(group, path / name)
                return
            if not stat.S_ISREG(info.st_mode):
                issues.append({"group": group, "path": str(path), "code": "special_file"}); return
            if path in files:
                return
            if info.st_size > self.config["max_file_bytes"]:
                raise ProtocolError("source_file_size_limit")
            total += info.st_size
            if total > self.config["max_bytes"] or len(files) + len(directories) >= self.config["max_files"]:
                raise ProtocolError("inventory_limit")
            fd = source_open(path)
            with os.fdopen(fd, "rb") as f:
                header = f.read(16)
            sqlite_file = header == SQLITE_HEADER or path in self.databases
            if sqlite_file and path not in self.databases:
                issues.append({"group": group, "path": str(path), "code": "unregistered_sqlite"})
            elif not sqlite_file and (path.suffix.lower() in {".db", ".sqlite", ".sqlite3", ".ibd", ".myd", ".myi", ".rdb", ".aof", ".mdb"} or path.name in {"data.mdb", "dump.rdb"}):
                issues.append({"group": group, "path": str(path), "code": "unhandled_mutable_database"})
            files[path] = {"group": group, "path": str(path), "member": "data/" + path.as_posix().lstrip("/"),
                           "size": info.st_size, "mode": stat.S_IMODE(info.st_mode) & 0o777,
                           "uid": info.st_uid, "gid": info.st_gid, "mtime_ns": info.st_mtime_ns,
                           "sqlite": sqlite_file}
            if not sqlite_file:
                files[path]["source_sha256"] = regular_digest(path, self.config["max_file_bytes"])
            counts[group] += 1
        for group, path in self.includes:
            walk(group, path)
        for name in self.config.get("required_groups", self.config["groups"]):
            if counts[name] == 0:
                issues.append({"group": name, "code": "required_group_empty"})
            if self.config["groups"][name].get("consistency", "unverified") == "unverified":
                issues.append({"group": name, "code": "consistency_unverified"})
            if self.config["groups"][name].get("consistency") == "external":
                issues.append({"group": name, "code": "external_consistency_not_proven"})
        for path, database in self.databases.items():
            if path not in files:
                issues.append({"group": database["group"], "path": str(path), "code": "registered_database_missing"})
        for link in links:
            if link["target_in_inventory_scope"]:
                path = Path(link["path"])
                target = link["target"]
                resolved = Path(os.path.normpath(str(path.parent / target))) if not target.startswith("/") else Path(os.path.normpath(target))
                if resolved not in files and resolved not in directories:
                    issues.append({"group": link["group"], "path": link["path"], "code": "symlink_target_not_exported"})
        self.inventory_directories = sorted(directories.values(), key=lambda d: d["path"])
        return sorted(files.values(), key=lambda f: f["path"]), links, issues

    def service_status(self) -> dict:
        result = {}
        for unit in self.config.get("units", []):
            try:
                p = self.runner(["/usr/bin/systemctl", "show", unit, "--no-pager",
                                 "--property=Id,Names,LoadState,ActiveState,SubState,MainPID,FragmentPath"],
                                stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                timeout=8, check=False)
                if p.returncode or len(p.stdout) > 32768:
                    result[unit] = {"ok": False}; continue
                pairs = dict(line.split("=", 1) for line in p.stdout.decode("utf-8").splitlines() if "=" in line)
                result[unit] = {"ok": True, **{k: pairs.get(k, "") for k in ("Id", "Names", "LoadState", "ActiveState", "SubState", "MainPID", "FragmentPath")}}
            except (OSError, subprocess.TimeoutExpired, UnicodeError):
                result[unit] = {"ok": False}
        return result

    def docker_status(self) -> dict:
        result = {}
        for name in self.config.get("docker_containers", []):
            try:
                p = self.runner(["/usr/bin/docker", "container", "inspect", name],
                                stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                timeout=8, check=False)
                if p.returncode or len(p.stdout) > 512000:
                    result[name] = {"ok": False}; continue
                rows = json.loads(p.stdout)
                if not isinstance(rows, list) or len(rows) != 1:
                    result[name] = {"ok": False}; continue
                row = rows[0]
                result[name] = {"ok": True, "id": row.get("Id"), "image_digest": row.get("Image"),
                                "image_name": row.get("Config", {}).get("Image"),
                                "running": row.get("State", {}).get("Running") is True,
                                "mounts": [{k: m.get(k) for k in ("Type", "Source", "Destination", "RW")} for m in row.get("Mounts", [])]}
            except (OSError, subprocess.TimeoutExpired, ValueError, TypeError, KeyError):
                result[name] = {"ok": False}
        return result

    def docker_completeness(self, metadata: dict) -> list[dict]:
        issues = []
        for name, row in metadata.items():
            if not row.get("ok"):
                issues.append({"group": "marzban", "code": "docker_inspect_unavailable", "container": name}); continue
            if row.get("image_digest") not in self.config.get("docker_images", []):
                issues.append({"group": "marzban", "code": "docker_image_not_registered", "container": name})
            for mount in row.get("mounts", []):
                if mount.get("Type") in {"bind", "volume"}:
                    source = Path(mount.get("Source") or "/")
                    if not any(within(source, p) for _, p in self.includes) or self._excluded(source):
                        issues.append({"group": "marzban", "code": "docker_writable_mount_outside_inventory" if mount.get("RW") else "docker_readonly_mount_outside_inventory", "container": name})
            if self.config.get("docker_include_config") is not True:
                issues.append({"group": "marzban", "code": "docker_config_not_exported", "container": name})
        return issues

    def docker_overlay_status(self, metadata: dict) -> dict:
        """No docker exec/commit or filesystem mutation; exact diff policy only."""
        result = {}
        for name, identity in metadata.items():
            unknown = {"verified": False, "ok": False, "code": "docker_writable_layer_unverified"}
            if not identity.get("ok"):
                result[name] = unknown; continue
            try:
                p = self.runner(["/usr/bin/docker", "diff", name], stdin=subprocess.DEVNULL,
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=8, check=False)
                if p.returncode or len(p.stdout) > 256000:
                    result[name] = unknown; continue
                changes = []
                for line in p.stdout.decode("utf-8").splitlines():
                    if len(line) < 4 or line[:2] not in {"A ", "C ", "D "}:
                        raise ValueError()
                    path = line[2:]
                    parsed = absolute_path(path)
                    if str(parsed) != path or len(path) > 4096 or len(changes) >= 4096:
                        raise ValueError()
                    changes.append({"kind": line[0], "path": path})
                if len({c["path"] for c in changes}) != len(changes):
                    raise ValueError()
                approved = self.config.get("docker_disposable_overlay_paths", {}).get(name, [])
                unapproved = [c for c in changes if c["path"] not in approved]
                result[name] = {"ok": True, "verified": not unapproved, "change_count": len(changes),
                                "diff_sha256": hashlib.sha256(canonical_json(changes)).hexdigest(),
                                "unapproved_changes": unapproved[:128], "unapproved_count": len(unapproved),
                                "approved_disposable_count": len(changes) - len(unapproved)}
            except (OSError, subprocess.TimeoutExpired, UnicodeError, ValueError, ProtocolError):
                result[name] = unknown
        return result

    @staticmethod
    def overlay_issues(overlay: dict) -> list[dict]:
        return [{"group": "marzban", "code": "docker_writable_layer_unverified", "container": name}
                for name, data in overlay.items() if data.get("verified") is not True]

    def inspect(self, request_id: str) -> dict:
        files, links, issues = self.inventory()
        docker = self.docker_status()
        issues.extend(self.docker_completeness(docker))
        overlay = self.docker_overlay_status(docker)
        completeness_issues = self.overlay_issues(overlay)
        registry = {"files": files, "directories": self.inventory_directories, "symlinks": links, "groups": self.config["groups"],
                    "sqlite_databases": self.config.get("sqlite_databases", []),
                    "docker_images": self.config.get("docker_images", [])}
        return {**self.identity(request_id), "ok": not issues, "operation": "inspect",
                "registry_hash": hashlib.sha256(canonical_json(registry)).hexdigest(),
                "file_count": len(files), "source_bytes": sum(f["size"] for f in files),
                "issues": issues, "services": self.service_status(), "docker": docker,
                "docker_overlay": overlay, "completeness_issues": completeness_issues,
                "docker_writable_layer_verified": not completeness_issues,
                "configured_inventory_complete": not issues and not completeness_issues,
                "consistency_scope": "per_database_snapshot", "global_consistency_proven": False,
                "application_health_proven": False, "persistent_fence": False}

    def restore_plan(self, request_id: str) -> dict:
        files, links, issues = self.inventory()
        docker = self.docker_status()
        issues.extend(self.docker_completeness(docker))
        completeness_issues = self.overlay_issues(self.docker_overlay_status(docker))
        return {**self.identity(request_id), "ok": not issues, "operation": "restore-plan",
                "file_targets": [{"member": f["member"], "path": f["path"], "group": f["group"], "mode": f["mode"]} for f in files],
                "directory_targets": self.inventory_directories,
                "symlink_candidates": [l for l in links if l["target_in_inventory_scope"]],
                "units": self.config.get("units", []), "sqlite_databases": self.config.get("sqlite_databases", []),
                "docker_image_digests": self.config.get("docker_images", []), "issues": issues,
                "completeness_issues": completeness_issues, "configured_inventory_complete": not issues and not completeness_issues,
                "applied": False, "requires_target_identity_and_user_mapping": True,
                "global_consistency_proven": False}

    def _copy_regular(self, source: Path, target: Path):
        fd = source_open(source)
        try:
            before = os.fstat(fd)
            if not stat.S_ISREG(before.st_mode):
                raise ProtocolError("source_type_changed")
            total = 0
            with os.fdopen(fd, "rb", closefd=False) as f, target.open("xb") as out:
                while chunk := f.read(1024 * 1024):
                    total += len(chunk)
                    if total > self.config["max_file_bytes"]:
                        raise ProtocolError("source_file_size_limit")
                    out.write(chunk)
            after = os.fstat(fd)
            current = source_info(source)
            fields = ("st_dev", "st_ino", "st_size", "st_mtime_ns", "st_ctime_ns")
            if any(getattr(before, k) != getattr(after, k) or getattr(after, k) != getattr(current, k) for k in fields):
                raise ProtocolError("source_changed_during_export")
        finally:
            os.close(fd)

    def _backup_sqlite(self, source: Path, target: Path) -> dict:
        original = source_info(source)
        if not stat.S_ISREG(original.st_mode):
            raise ProtocolError("sqlite_source_type")
        started = time.monotonic()
        def progress(status, remaining, total):
            if time.monotonic() - started > self.config["snapshot_timeout"]:
                raise ProtocolError("sqlite_snapshot_timeout")
        try:
            fd = source_open(source)
            try:
                # Pin the opened source object rather than trusting a path
                # that might become a symlink between validation and open.
                source_uri = "file:/proc/self/fd/" + str(fd) + "?mode=ro"
                with sqlite3.connect(source_uri, uri=True, timeout=3) as src, sqlite3.connect(target) as dst:
                    src.backup(dst, pages=256, progress=progress, sleep=0.05)
                    dst.set_progress_handler(lambda: int(time.monotonic() - started > self.config["snapshot_timeout"]), 10000)
                    checks = dst.execute("PRAGMA quick_check").fetchall()
                    if checks != [("ok",)]:
                        raise ProtocolError("sqlite_snapshot_integrity")
                    meta = {"quick_check": "ok", "page_count": dst.execute("PRAGMA page_count").fetchone()[0],
                            "page_size": dst.execute("PRAGMA page_size").fetchone()[0], "snapshot_at": int(time.time())}
            finally:
                os.close(fd)
            current = source_info(source)
            if (original.st_dev, original.st_ino) != (current.st_dev, current.st_ino) or not stat.S_ISREG(current.st_mode):
                raise ProtocolError("sqlite_source_replaced")
            return meta
        except sqlite3.Error:
            raise ProtocolError("sqlite_snapshot_failed") from None

    def _save_image(self, digest: str, target: Path):
        """Installed Docker CLI only; image IDs are local, immutable sha256 IDs."""
        import selectors
        import signal
        process = subprocess.Popen(["/usr/bin/docker", "image", "save", digest], stdin=subprocess.DEVNULL,
                                   stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, start_new_session=True)
        selector = selectors.DefaultSelector(); total = 0; started = time.monotonic()
        try:
            selector.register(process.stdout, selectors.EVENT_READ)
            os.set_blocking(process.stdout.fileno(), False)
            with target.open("xb") as out:
                while selector.get_map():
                    remaining = 180 - (time.monotonic() - started)
                    if remaining <= 0:
                        raise ProtocolError("docker_image_export_timeout")
                    for key, _ in selector.select(remaining):
                        chunk = os.read(key.fileobj.fileno(), 65536)
                        if not chunk:
                            selector.unregister(key.fileobj); break
                        total += len(chunk)
                        if total > self.config["max_bytes"]:
                            raise ProtocolError("docker_image_export_size")
                        out.write(chunk)
            if process.wait(timeout=max(0.01, 180 - (time.monotonic() - started))) != 0:
                raise ProtocolError("docker_image_export_failed")
        finally:
            selector.close()
            if process.poll() is None:
                with contextlib.suppress(ProcessLookupError):
                    os.killpg(process.pid, signal.SIGKILL)
                process.wait(timeout=3)
            process.stdout.close()

    @contextlib.contextmanager
    def export_artifact(self, request_id: str):
        """Build privately before yielding (path, manifest). Artifact is ephemeral."""
        files, links, issues = self.inventory()
        docker = self.docker_status()
        issues.extend(self.docker_completeness(docker))
        overlay_before = self.docker_overlay_status(docker)
        if issues:
            raise ProtocolError("export_inventory_unsafe")
        with tempfile.TemporaryDirectory(prefix="snapshot-", dir=self.config["work_dir"]) as stage_name:
            stage = Path(stage_name)
            entries = []; sqlite_meta = []; total = 0
            for index, item in enumerate(files):
                source = Path(item["path"]); target = stage / str(index)
                if item["sqlite"]:
                    metadata = self._backup_sqlite(source, target)
                    sqlite_meta.append({"path": item["path"], **metadata})
                else:
                    self._copy_regular(source, target)
                size = target.stat().st_size; total += size
                if total > self.config["max_bytes"]:
                    raise ProtocolError("export_size_limit")
                digest = file_digest(target)
                if not item["sqlite"] and digest != item["source_sha256"]:
                    raise ProtocolError("source_changed_since_inventory")
                entries.append({**item, "size": size, "sha256": digest, "_staged": target})
            for digest in self.config.get("docker_images", []):
                target = stage / ("image-" + digest.split(":")[1])
                self._save_image(digest, target)
                size = target.stat().st_size; total += size
                if total > self.config["max_bytes"]:
                    raise ProtocolError("export_size_limit")
                entries.append({"group": "docker-image", "path": None, "member": "images/" + digest.split(":")[1] + ".tar",
                                "mode": 0o600, "uid": 0, "gid": 0, "size": size, "sha256": file_digest(target),
                                "docker_digest": digest, "_staged": target})
            for name in self.config.get("docker_containers", []):
                # Full container metadata may contain credentials. It is NEVER
                # included in inspect JSON; explicit local opt-in packages it
                # only inside the private, SSH-protected binary artifact.
                raw = self.runner(["/usr/bin/docker", "container", "inspect", name],
                                  stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                  timeout=8, check=False)
                if raw.returncode or len(raw.stdout) > 512000:
                    raise ProtocolError("docker_config_export_failed")
                parsed = json.loads(raw.stdout)
                if not isinstance(parsed, list) or len(parsed) != 1 or parsed[0].get("Id") != docker[name]["id"] or parsed[0].get("Image") != docker[name]["image_digest"]:
                    raise ProtocolError("docker_identity_changed")
                target = stage / ("container-" + name + ".json")
                target.write_bytes(canonical_json({"container": parsed[0]}))
                size = target.stat().st_size; total += size
                if total > self.config["max_bytes"]:
                    raise ProtocolError("export_size_limit")
                entries.append({"group": "docker-config", "path": None, "member": "private/docker/" + name + ".json",
                                "mode": 0o600, "uid": 0, "gid": 0, "size": size, "sha256": file_digest(target), "_staged": target})
            overlay = self.docker_overlay_status(docker)
            for name, data in overlay.items():
                if data.get("diff_sha256") != overlay_before[name].get("diff_sha256"):
                    data["verified"] = False
                    data["code"] = "docker_writable_layer_changed_during_export"
            completeness_issues = self.overlay_issues(overlay)
            manifest = {**self.identity(request_id), "operation": "export", "ok": True,
                        "files": [{k: v for k, v in entry.items() if k != "_staged"} for entry in entries],
                        "directories": self.inventory_directories, "symlinks": links, "sqlite_snapshots": sqlite_meta,
                        "groups": self.config["groups"], "services": self.service_status(), "docker": docker,
                        "docker_overlay": overlay, "completeness_issues": completeness_issues,
                        "docker_writable_layer_verified": not completeness_issues,
                        "configured_inventory_complete": not completeness_issues,
                        "consistency_scope": "per_database_snapshot", "global_consistency_proven": False,
                        "application_health_proven": False, "persistent_fence": False, "total_uncompressed_bytes": total}
            manifest["registry_hash"] = hashlib.sha256(canonical_json({"files": manifest["files"], "directories": manifest["directories"], "symlinks": links})).hexdigest()
            blob = canonical_json(manifest)
            if len(blob) > 8 * 1024 * 1024:
                raise ProtocolError("manifest_size")
            archive_path = stage / "snapshot.tar.gz"
            with tarfile.open(archive_path, "w:gz", format=tarfile.PAX_FORMAT) as archive:
                head = tarfile.TarInfo("MANIFEST.json"); head.size = len(blob); head.mode = 0o600
                archive.addfile(head, io.BytesIO(blob))
                for entry in self.inventory_directories:
                    info = tarfile.TarInfo(entry["member"]); info.type = tarfile.DIRTYPE
                    info.mode = entry["mode"]; info.uid = entry["uid"]; info.gid = entry["gid"]
                    archive.addfile(info)
                for entry in entries:
                    info = tarfile.TarInfo(entry["member"])
                    info.size = entry["size"]; info.mode = entry["mode"]; info.uid = entry["uid"]; info.gid = entry["gid"]
                    with entry["_staged"].open("rb") as f:
                        archive.addfile(info, f)
            os.chmod(archive_path, 0o600)
            yield archive_path, manifest

    def fence(self, request: dict) -> dict:
        if self.config.get("fence_enabled") is not True:
            raise ProtocolError("fence_disabled")
        request_from_bytes(canonical_json(request), self.config)
        units = self.config.get("fence_units", [])
        if not units:
            raise ProtocolError("fence_units_empty")
        # Inspect aliases first, so a harmless-looking alias cannot stop SSH.
        inspector = RelaySide({**self.config, "units": units}, runner=self.runner, root_owned=False)
        before = inspector.service_status()
        for unit in units:
            data = before[unit]
            if not data.get("ok") or data.get("LoadState") != "loaded" or any(PROTECTED_UNIT.search(name) for name in (data.get("Names", "") + " " + data.get("Id", "")).split()):
                raise ProtocolError("fence_unit_unverified")
        intent = {**self.identity(request["request_id"]), "operation": "fence", "units": units, "status": "intent"}
        journal = Path(self.config["work_dir"]) / "fence-intent.json"
        atomic_private_json(journal, intent)
        for unit in units:
            try:
                result = self.runner(["/usr/bin/systemctl", "stop", unit], stdin=subprocess.DEVNULL,
                                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30, check=False)
            except (OSError, subprocess.TimeoutExpired):
                raise ProtocolError("fence_outcome_unknown") from None
            if result.returncode:
                raise ProtocolError("fence_stop_failed")
        after = inspector.service_status()
        stopped = all(v.get("ok") and v.get("ActiveState") in {"inactive", "failed"} for v in after.values())
        receipt = {**intent, "ok": stopped, "status": "units_stopped" if stopped else "unverified",
                   "services": after, "units_stopped": stopped, "persistent_fence": False, "fenced": False}
        atomic_private_json(journal, receipt)
        return receipt


def socket_hostname():
    import socket
    return socket.gethostname()


def file_digest(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as f:
        while chunk := f.read(1024 * 1024): digest.update(chunk)
    return digest.hexdigest()


def regular_digest(path: Path, limit: int) -> str:
    fd = source_open(path)
    try:
        before = os.fstat(fd)
        if not stat.S_ISREG(before.st_mode): raise ProtocolError("source_type_changed")
        total = 0; digest = hashlib.sha256()
        with os.fdopen(fd, "rb", closefd=False) as source:
            while chunk := source.read(1024 * 1024):
                total += len(chunk)
                if total > limit: raise ProtocolError("source_file_size_limit")
                digest.update(chunk)
        after = os.fstat(fd)
        current = source_info(path)
        if any(getattr(before, key) != getattr(after, key) or getattr(after, key) != getattr(current, key)
               for key in ("st_dev", "st_ino", "st_size", "st_mtime_ns", "st_ctime_ns")):
            raise ProtocolError("source_changed_during_inventory")
        return digest.hexdigest()
    finally:
        os.close(fd)


def atomic_private_json(path: Path, value: dict):
    if path.is_symlink(): raise ProtocolError("state_symlink")
    temporary = path.with_suffix(".tmp")
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, "wb") as out:
        out.write(canonical_json(value)); out.flush(); os.fsync(out.fileno())
    os.replace(temporary, path)
    fd = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
    try: os.fsync(fd)
    finally: os.close(fd)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", required=True)
    args = parser.parse_args()
    os.umask(0o077)
    try:
        # Forced command deliberately ignores SSH_ORIGINAL_COMMAND. There is
        # no shell interpretation, arbitrary command or SFTP subsystem here.
        config = load_config(args.config)
        request = request_from_bytes(sys.stdin.buffer.read(MAX_REQUEST + 1), config)
        side = RelaySide(config)
        op = request["op"]
        if op == "export":
            with side.export_artifact(request["request_id"]) as (path, _):
                with path.open("rb") as source:
                    shutil.copyfileobj(source, sys.stdout.buffer, length=1024 * 1024)
                sys.stdout.buffer.flush()
            return 0
        result = side.restore_plan(request["request_id"]) if op == "restore-plan" else side.fence(request) if op == "fence" else side.inspect(request["request_id"])
        if op == "status":
            result["operation"] = "status"
        body = canonical_json(result)
        if len(body) > 1024 * 1024:
            raise ProtocolError("response_size")
        sys.stdout.buffer.write(body + b"\n")
        return 0 if result["ok"] else 2
    except (ProtocolError, OSError, sqlite3.Error, subprocess.SubprocessError, ValueError) as error:
        code = str(error) if isinstance(error, ProtocolError) else "local_operation_failed"
        # On an export failure stdout remains empty until a complete archive
        # exists. A later broken pipe still gives a nonzero SSH exit status.
        sys.stderr.write(json.dumps({"ok": False, "code": code}) + "\n")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
