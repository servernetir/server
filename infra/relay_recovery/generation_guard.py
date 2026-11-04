#!/usr/bin/env python3
"""Fail-closed local writer gate for a full-disk relay snapshot (stdlib only).

Install this as an ExecCondition on every reviewed business writer, including
the Docker daemon when its exact container inventory has been reviewed. A clone
inherits the identity file but has a different provider DMI UUID, so it cannot
start those writers. The lifecycle helper owns explicit generation activation
and persistent ``fenced`` transitions. This guard never activates an identity,
runs a command, contacts a provider, or changes a service.

The only CLI is ``--config /etc/servernet/relay-generation.json``. A systemd
drop-in must run this root-only read with the '+' ExecCondition command prefix
and must not treat DENY_EXIT_CODE as success through SuccessExitStatus. The private
root-owned identity contains exactly the schema fields below; it cannot supply
paths, commands or a different source of machine identity. ``root_owned=False``
is a Python API fixture option, never available through the CLI. Syntactic unit
checks cannot discover systemd aliases: installation must also inspect every
resolved Id/Names and prove the complete writer inventory before arming.
"""
from __future__ import annotations

import contextlib
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import secrets
import stat
import sys

SCHEMA = "servernet.relay.generation.v1"
RESULT_SCHEMA = "servernet.relay.generation.result.v1"
DEFAULT_CONFIG = "/etc/servernet/relay-generation.json"
DENY_EXIT_CODE = 1
DMI_UUID_PATH = "/sys/class/dmi/id/product_uuid"
MAX_IDENTITY_BYTES = 65536
FIELDS = frozenset({"schema", "service_id", "provider_id", "generation",
                    "config_hash", "machine_uuid_sha256", "fenced", "units"})
_NUMBER = re.compile(r"\A[1-9][0-9]{0,19}\Z")
_HASH = re.compile(r"\A[0-9a-f]{64}\Z")
_UUID = re.compile(r"\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\Z")
_UNIT = re.compile(r"\A[a-zA-Z0-9][a-zA-Z0-9_.@:-]{0,119}\.service\Z")
# Docker/nginx/PowerDNS/gateway/VPN can be business writers. The recovery and
# administrative path must remain available even when the writer gate denies.
# Include @/: delimiters so obvious template-instance spellings cannot bypass
# this check. Unknown aliases are resolved and checked by the installer.
_PROTECTED = re.compile(
    r"(?:^|[-_.@:])(?:ssh|sshd|dropbear|recovery|control|agent|monitor|"
    r"network|networking|networkmanager|networkd|resolved|systemd|dbus|"
    r"cloudflared|containerd|dhclient|dhcpcd|wicked|connman|iwd|wpa_supplicant|"
    r"firewalld|ufw|iptables|ip6tables|nftables|fail2ban|cron|crond|atd|"
    r"getty|logind|journald|udev|polkit|auditd|rescue|emergency|"
    r"reboot|poweroff|halt|shutdown|init|mount|umount|fsck|cryptsetup|"
    r"lvm|lvm2|zfs|mdadm|multipath|swap)(?:[-_.@:]|$)", re.IGNORECASE)


class GuardError(ValueError):
    """Fixed safe error code; never contains configuration values or paths."""


def validate_units(units: list) -> list:
    """Return the ordered, distinct reviewed service names or fail closed.

    This is intentionally limited to service units. A lifecycle installer must
    additionally reject protected resolved Id/Names, socket/timer resurrection
    paths and incomplete inventories; the guard does not invoke systemctl.
    """
    if type(units) is not list or not 1 <= len(units) <= 128:
        raise GuardError("units")
    seen = set()
    for unit in units:
        if type(unit) is not str or not _UNIT.fullmatch(unit):
            raise GuardError("unit")
        if _PROTECTED.search(unit):
            raise GuardError("protected_unit")
        if unit in seen:
            raise GuardError("duplicate_unit")
        seen.add(unit)
    return list(units)


def validate_identity(identity: dict) -> dict:
    """Validate the complete identity and return an independent normalized copy."""
    if type(identity) is not dict or set(identity) != FIELDS:
        raise GuardError("identity_fields")
    if identity.get("schema") != SCHEMA:
        raise GuardError("identity_schema")
    for field in ("service_id", "provider_id"):
        if type(identity[field]) is not str or not _NUMBER.fullmatch(identity[field]):
            raise GuardError("identity_id")
    if type(identity["generation"]) is not int or identity["generation"] < 1:
        raise GuardError("identity_generation")
    for field in ("config_hash", "machine_uuid_sha256"):
        if type(identity[field]) is not str or not _HASH.fullmatch(identity[field]):
            raise GuardError("identity_hash")
    if type(identity["fenced"]) is not bool:
        raise GuardError("identity_fenced")
    result = dict(identity)
    result["units"] = validate_units(identity["units"])
    return result


def _identity_path(path: str | Path) -> Path:
    try:
        value = os.fspath(path)
    except TypeError:
        raise GuardError("identity_path") from None
    if (type(value) is not str or not value.startswith("/") or len(value) > 4096
            or any(ord(char) < 32 or ord(char) == 127 for char in value)):
        raise GuardError("identity_path")
    result = Path(value)
    if (result == Path("/") or str(result) != value or ".." in result.parts):
        raise GuardError("identity_path")
    return result


def _directory_info(info, root_owned: bool):
    if not stat.S_ISDIR(info.st_mode):
        raise GuardError("identity_parent")
    if root_owned and (info.st_uid != 0 or stat.S_IMODE(info.st_mode) & 0o022):
        raise GuardError("identity_parent_permissions")


@contextlib.contextmanager
def _parent_fd(path: Path, root_owned: bool):
    """Pin and inspect every path component without ever following a symlink."""
    fd = os.open("/", os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC)
    try:
        _directory_info(os.fstat(fd), root_owned)
        for part in path.parent.parts[1:]:
            next_fd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
                              | os.O_CLOEXEC, dir_fd=fd)
            os.close(fd)
            fd = next_fd
            _directory_info(os.fstat(fd), root_owned)
        yield fd
    finally:
        os.close(fd)


def _file_info(info, root_owned: bool):
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise GuardError("identity_file")
    if stat.S_IMODE(info.st_mode) != 0o600 or (root_owned and info.st_uid != 0):
        raise GuardError("identity_permissions")
    if info.st_size > MAX_IDENTITY_BYTES:
        raise GuardError("identity_size")


def _decode(body: bytes) -> dict:
    def pairs(items):
        result = {}
        for key, value in items:
            if key in result:
                raise GuardError("identity_duplicate_key")
            result[key] = value
        return result

    def constant(_):
        raise GuardError("identity_json")

    try:
        return validate_identity(json.loads(body.decode("utf-8"),
                                 object_pairs_hook=pairs, parse_constant=constant))
    except GuardError:
        raise
    except (ValueError, RecursionError, OverflowError):
        raise GuardError("identity_json") from None


def load_identity(path: str | Path, *, root_owned: bool = True) -> dict:
    """Read bounded private JSON using pinned directory and file descriptors.

    Production checks require root ownership and no writable parent directories,
    as well as exact 0600 file mode. The explicit fixture option only relaxes
    ownership/parent permissions; symlinks, hardlinks and unsafe files still fail.
    """
    config_path = _identity_path(path)
    try:
        with _parent_fd(config_path, root_owned) as parent:
            fd = os.open(config_path.name, os.O_RDONLY | os.O_NOFOLLOW
                         | os.O_NONBLOCK | os.O_CLOEXEC, dir_fd=parent)
            try:
                _file_info(os.fstat(fd), root_owned)
                body = bytearray()
                while len(body) <= MAX_IDENTITY_BYTES:
                    chunk = os.read(fd, min(8192, MAX_IDENTITY_BYTES + 1 - len(body)))
                    if not chunk:
                        break
                    body.extend(chunk)
                if len(body) > MAX_IDENTITY_BYTES:
                    raise GuardError("identity_size")
            finally:
                os.close(fd)
        return _decode(bytes(body))
    except OSError:
        raise GuardError("identity_unavailable") from None


def _read_machine_uuid() -> bytes:
    # The source is fixed kernel sysfs, never a configurable file or metadata URL.
    with open(DMI_UUID_PATH, "rb") as stream:
        info = os.fstat(stream.fileno())
        if info.st_uid != 0 or not stat.S_ISREG(info.st_mode):
            raise GuardError("machine_identity_unavailable")
        return stream.read(129)


def machine_uuid_hash(read=None) -> str:
    """Hash the fixed root DMI UUID, stripped and lowercased before SHA-256.

    ``read`` is an optional zero-argument fixture callable returning str/bytes;
    no such override exists in the executable interface.
    """
    try:
        raw = _read_machine_uuid() if read is None else read()
        if type(raw) is bytes:
            raw = raw.decode("ascii")
        if type(raw) is not str or len(raw) > 128:
            raise GuardError("machine_identity_unavailable")
        value = raw.strip().lower()
        if (not _UUID.fullmatch(value) or set(value.replace("-", "")) in ({"0"}, {"f"})):
            raise GuardError("machine_identity_unavailable")
        return hashlib.sha256(value.encode("ascii")).hexdigest()
    except (OSError, UnicodeError, TypeError, ValueError):
        raise GuardError("machine_identity_unavailable") from None


def check_guard(identity: dict, machine_hash: str) -> bool:
    """Deny malformed, unavailable, cloned or persistently fenced identities."""
    try:
        valid = validate_identity(identity)
        return (valid["fenced"] is False and type(machine_hash) is str
                and _HASH.fullmatch(machine_hash) is not None
                and hmac.compare_digest(valid["machine_uuid_sha256"], machine_hash))
    except (GuardError, TypeError, ValueError):
        return False


def _existing_file(parent: int, name: str, root_owned: bool):
    try:
        info = os.stat(name, dir_fd=parent, follow_symlinks=False)
    except FileNotFoundError:
        return
    _file_info(info, root_owned)


def atomic_identity(path: str | Path, identity: dict, *, root_owned: bool = True):
    """Validate and durably replace one identity, retaining private mode 0600.

    Existing unsafe files are rejected. The parent must already exist; writing
    never creates arbitrary directories. Lifecycle operations must hold their
    own interprocess lock across identity comparison and this replacement.
    """
    valid = validate_identity(identity)
    config_path = _identity_path(path)
    try:
        body = (json.dumps(valid, sort_keys=True, separators=(",", ":"),
                           ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")
    except (TypeError, ValueError, OverflowError):
        raise GuardError("identity_json") from None
    if len(body) > MAX_IDENTITY_BYTES:
        raise GuardError("identity_size")
    try:
        with _parent_fd(config_path, root_owned) as parent:
            _existing_file(parent, config_path.name, root_owned)
            temporary = "." + config_path.name + "." + secrets.token_hex(16) + ".tmp"
            fd = None
            created = False
            try:
                fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL
                             | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600, dir_fd=parent)
                created = True
                os.fchmod(fd, 0o600)
                _file_info(os.fstat(fd), root_owned)
                pending = memoryview(body)
                while pending:
                    written = os.write(fd, pending)
                    if written <= 0:
                        raise GuardError("identity_write")
                    pending = pending[written:]
                os.fsync(fd)
                os.close(fd)
                fd = None
                _existing_file(parent, config_path.name, root_owned)
                os.replace(temporary, config_path.name, src_dir_fd=parent, dst_dir_fd=parent)
                os.fsync(parent)
            finally:
                if fd is not None:
                    os.close(fd)
                if created:
                    with contextlib.suppress(FileNotFoundError):
                        os.unlink(temporary, dir_fd=parent)
    except OSError:
        raise GuardError("identity_write") from None


def main(argv=None) -> int:
    """ExecCondition: 0 permits writers; every other outcome is safe JSON + 1."""
    argv = sys.argv[1:] if argv is None else argv
    allowed = False
    try:
        if argv != ["--config", DEFAULT_CONFIG]:
            raise GuardError("arguments")
        identity = load_identity(DEFAULT_CONFIG)
        allowed = check_guard(identity, machine_uuid_hash())
    except Exception:
        # Service startup must remain denied on I/O failure and unexpected errors.
        # Never put an exception, identity value, UUID or path into service logs.
        allowed = False
    print(json.dumps({"schema": RESULT_SCHEMA, "allowed": allowed},
                     sort_keys=True, separators=(",", ":")))
    return 0 if allowed else DENY_EXIT_CODE


if __name__ == "__main__":
    raise SystemExit(main())
