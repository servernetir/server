#!/usr/bin/env python3
"""Owner-authorized Proxmox root-key enrollment and guarded MCP target promotion."""
import argparse
import base64
import fcntl
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import pwd
import re
import socket
import stat
import subprocess
import tempfile
import uuid


def sha(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None


def safe_path(path):
    path = Path(path).absolute()
    if any(p.is_symlink() for p in (path, *path.parents)):
        raise ValueError("symlink_path")
    return path


def read(path, private=False):
    path = safe_path(path)
    if not path.exists():
        return None
    info = path.stat()
    if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid()
            or info.st_nlink != 1 or info.st_mode & (0o077 if private else 0o022)):
        raise ValueError("unsafe_file")
    return path.read_bytes()


def private_dir(path):
    path = safe_path(path)
    path.mkdir(mode=0o700, parents=True, exist_ok=True)
    info = path.stat()
    if info.st_uid != os.geteuid() or info.st_mode & 0o077:
        raise ValueError("unsafe_directory")
    return path


def replace(path, data, expected):
    path = safe_path(path)
    fd, tmp = tempfile.mkstemp(prefix=".mcp-access-", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        if read(path) != expected:
            raise ValueError("concurrent_change")
        os.replace(tmp, path)
        parent = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(parent)
        finally:
            os.close(parent)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def commit(path, before, after, state):
    """Private receipt and backup precede replacement; no credential output."""
    state = private_dir(state)
    lock = os.open(state / "lock", os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if read(path) != before:
            raise ValueError("concurrent_change")
        receipt_dir = private_dir(state / uuid.uuid4().hex)
        if before is not None:
            replace(receipt_dir / "before", before, None)
        receipt = {"target": str(Path(path).absolute()), "before_sha256": sha(before),
                   "after_sha256": sha(after)}
        receipt_path = receipt_dir / "receipt.json"
        replace(receipt_path, (json.dumps(receipt) + "\n").encode(), None)
        replace(path, after, before)
        return {"changed": True, "receipt": str(receipt_path), "sha256": sha(after)}
    finally:
        os.close(lock)


def rollback(receipt_path):
    receipt_path = safe_path(receipt_path)
    receipt = json.loads(read(receipt_path, private=True))
    path = safe_path(receipt["target"])
    before = read(receipt_path.parent / "before", private=True)
    if sha(before) != receipt["before_sha256"]:
        raise ValueError("backup_changed")
    state = private_dir(receipt_path.parent.parent)
    lock = os.open(state / "lock", os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(lock, fcntl.LOCK_EX)
        current = read(path)
        if sha(current) != receipt["after_sha256"]:
            raise ValueError("concurrent_change")
        if before is None:
            path.unlink()
        else:
            replace(path, before, current)
        return {"restored": True, "sha256": sha(before)}
    finally:
        os.close(lock)


def key_line(source, fingerprint, address):
    ipaddress.ip_address(address)
    matches = []
    for line in source.decode("utf-8").splitlines():
        m = re.search(r"(?<!\S)(ssh-ed25519) ([A-Za-z0-9+/=]+)(?:\s|$)", line)
        if not m:
            continue
        raw = base64.b64decode(m[2], validate=True)
        actual = "SHA256:" + base64.b64encode(hashlib.sha256(raw).digest()).decode().rstrip("=")
        if actual == fingerprint:
            if line[:m.start()] != 'restrict,from="' + address + '" ':
                raise ValueError("unexpected_key_restrictions")
            matches.append(line[:m.end()].rstrip() + " servernet-mcp-admin")
    if len(matches) != 1:
        raise ValueError("expected_one_matching_key")
    return matches[0]


def grant_bytes(current, line):
    current = current or b""
    key = line.split(" ")[2]
    matches = [row for row in current.decode("utf-8").splitlines()
               if re.search(r"(?<!\S)" + re.escape(key) + r"(?:\s|$)", row)]
    if matches:
        if matches == [line]:
            return current
        raise ValueError("existing_key_requires_review")
    return current + (b"\n" if current and not current.endswith(b"\n") else b"") + line.encode() + b"\n"


def enrollment_target(root_home, policy):
    paths = set(policy.get("authorizedkeysfile", "").split())
    if (policy.get("pubkeyauthentication") != "yes"
            or policy.get("permitrootlogin") not in ("yes", "prohibit-password", "without-password")
            or policy.get("authenticationmethods", "any") not in ("any", "publickey")):
        raise ValueError("existing_sshd_policy_does_not_allow_this_key_only_login")
    primary = root_home / ".ssh/authorized_keys"
    if primary.is_symlink():
        # Proxmox owns this cluster-wide symlink. Enroll only on the local node.
        if primary.resolve() != Path("/etc/pve/priv/authorized_keys"):
            raise ValueError("unexpected_authorized_keys_symlink")
        name = "authorized_keys2"
    else:
        name = "authorized_keys"
    target = root_home / ".ssh" / name
    if not paths.intersection((".ssh/" + name, "%h/.ssh/" + name, str(target))):
        raise ValueError("local_authorized_keys_path_not_configured")
    return safe_path(target)


def host_grant(fingerprint, address):
    if os.geteuid() != 0:
        raise ValueError("run_on_proxmox_as_root")
    operator = pwd.getpwnam("mcp-ops")
    source_path = safe_path(Path(operator.pw_dir) / ".ssh/authorized_keys")
    info = source_path.stat()
    if (not stat.S_ISREG(info.st_mode) or info.st_uid != operator.pw_uid
            or info.st_nlink != 1 or info.st_mode & 0o022):
        raise ValueError("unsafe_operator_key_file")
    line = key_line(source_path.read_bytes(), fingerprint, address)
    result = subprocess.run(
        ["/usr/sbin/sshd", "-T", "-C", f"user=root,host={socket.gethostname()},addr={address}"],
        capture_output=True, text=True, timeout=10, check=True)
    policy = dict(row.split(" ", 1) for row in result.stdout.splitlines() if " " in row)
    root_home = Path(pwd.getpwnam("root").pw_dir)
    target = enrollment_target(root_home, policy)
    private_dir(target.parent)
    before = read(target)
    after = grant_bytes(before, line)
    if after == before:
        return {"changed": False, "already_enrolled": True, "fingerprint": fingerprint}
    result = commit(target, before, after, root_home / ".local/state/servernet/mcp-admin")
    return result | {"fingerprint": fingerprint, "source_address": address,
                     "sshd_config_changed": False, "target_path": str(target)}


def verify_root(target, registry_dir):
    for name in ("identity_file", "known_hosts_file"):
        path = safe_path(target[name])
        if not path.is_relative_to(safe_path(registry_dir)):
            raise ValueError("ssh_file_outside_registry")
        info = path.stat()
        if (not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid()
                or info.st_nlink != 1 or info.st_mode & 0o077):
            raise ValueError("unsafe_ssh_identity_or_host_file")
    if (not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9.:-]{0,252}", target["host"])
            or not 1 <= int(target["port"]) <= 65535):
        raise ValueError("invalid_target")
    command = ["/usr/bin/ssh", "-F", "/dev/null", "-T"]
    for option in ("BatchMode=yes", "IdentitiesOnly=yes", "StrictHostKeyChecking=yes",
                   "PasswordAuthentication=no", "KbdInteractiveAuthentication=no",
                   "ConnectTimeout=5", "ConnectionAttempts=1", "ForwardAgent=no",
                   "ClearAllForwardings=yes", "UserKnownHostsFile=" + target["known_hosts_file"]):
        command += ["-o", option]
    command += ["-i", target["identity_file"], "-p", str(target["port"]),
                "root@" + target["host"], "id -u && /usr/sbin/qm status 127"]
    result = subprocess.run(command, capture_output=True, timeout=15, check=False)
    if result.returncode != 0 or not re.fullmatch(
            rb"0\r?\nstatus: (running|stopped|paused)\r?\n", result.stdout):
        raise ValueError("root_ssh_verification_failed_registry_unchanged")


def gateway_promote(registry, verify=verify_root):
    registry = safe_path(registry)
    private_dir(registry.parent)
    before = read(registry, private=True)
    targets = json.loads(before)
    target = targets["proxmox-ir"]
    if target["user"] not in ("mcp-ops", "root"):
        raise ValueError("unexpected_existing_user")
    verify(target, registry.parent)
    if target["user"] == "root":
        return {"changed": False, "root_verified": True}
    targets["proxmox-ir"] = target | {"user": "root", "label": "Proxmox Iran administrator"}
    after = (json.dumps(targets, ensure_ascii=False, indent=2) + "\n").encode()
    result = commit(registry, before, after, registry.parent / "admin-access-receipts")
    return result | {"target": "proxmox-ir", "root_verified": True,
                     "other_targets_preserved": True}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="mode", required=True)
    host = sub.add_parser("host-grant")
    host.add_argument("--fingerprint", required=True)
    host.add_argument("--source-address", required=True)
    gateway = sub.add_parser("gateway-promote")
    gateway.add_argument("--registry", type=Path, required=True)
    undo = sub.add_parser("rollback")
    undo.add_argument("receipt", type=Path)
    args = parser.parse_args()
    try:
        if args.mode == "host-grant":
            result = host_grant(args.fingerprint, args.source_address)
        elif args.mode == "gateway-promote":
            result = gateway_promote(args.registry)
        else:
            result = rollback(args.receipt)
        print(json.dumps(result))
    except (OSError, ValueError, KeyError, subprocess.SubprocessError) as exc:
        # Paths/credentials/upstream output are never reflected in error messages.
        code = str(exc) if type(exc) is ValueError and re.fullmatch(r"[a-z_]{1,90}", str(exc)) else "access_setup_stopped_no_automatic_retry"
        print(json.dumps({"ok": False, "code": code}))
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
