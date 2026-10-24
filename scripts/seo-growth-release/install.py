#!/usr/bin/env python3
"""Install a reviewed SEO bundle outside webroot. Default: verify only.
No CMS, environment, database, cache, cron, payment or infrastructure operations.
"""
import argparse
from contextlib import contextmanager
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import stat
import tempfile
from datetime import datetime, timezone

PREFIXES = ("servernet_app/app/Console/Commands/", "servernet_app/app/Http/Controllers/",
            "servernet_app/config/", "servernet_app/lang/", "servernet_app/resources/")
ASSETS = {"servernet_app/public/assets/js/growth-cta.js", "public_html/assets/js/growth-cta.js"}

def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() else "absent"

def safe(root, relative):
    p = PurePosixPath(relative)
    if (p.is_absolute() or ".." in p.parts or p.as_posix() != relative
        or any(x.startswith(".") for x in p.parts)
        or not (relative.startswith(PREFIXES) or relative in ASSETS)):
        raise ValueError("Unapproved path: " + relative)
    target = root / relative
    for current in (target, *target.parents):
        if current.is_symlink():
            raise ValueError("Symlink refused: " + relative)
    if target.exists() and not target.is_file():
        raise ValueError("Non-file target: " + relative)
    return target

def plan(root, bundle):
    manifest = json.loads((bundle / "manifest.json").read_text())
    if manifest.get("schema") != 1 or not manifest.get("files"):
        raise ValueError("Invalid manifest")
    seen = set()
    for row in manifest["files"]:
        name = row["path"]
        if name in seen:
            raise ValueError("Duplicate target: " + name)
        seen.add(name)
        source = safe(bundle / "stage", name)
        target = safe(root, name)
        if not source.is_file() or digest(source) != row["after_sha256"]:
            raise ValueError("Stage hash mismatch: " + name)
        if digest(target) != row["before_sha256"]:
            raise ValueError("Live drift; refresh and reconcile: " + name)
    return manifest

@contextmanager
def lock(root):
    path = root / ".servernet-seo-growth.lock"
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield
    finally:
        os.close(fd)

def replace(target, data, mode):
    target.parent.mkdir(parents=True, exist_ok=True)
    fd, name = tempfile.mkstemp(prefix="seo-stage-", dir=target.parent)
    try:
        with os.fdopen(fd, "wb") as out:
            os.fchmod(out.fileno(), mode)
            out.write(data)
            out.flush()
            os.fsync(out.fileno())
        os.replace(name, target)
    finally:
        if os.path.exists(name):
            os.unlink(name)

def install(root, bundle):
    with lock(root):
        manifest = plan(root, bundle)
        backup = bundle / "backups" / datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
        backup.mkdir(parents=True, mode=0o700)
        modes = {}
        for row in manifest["files"]:
            target = safe(root, row["path"])
            modes[row["path"]] = stat.S_IMODE(target.stat().st_mode) if target.exists() else 0o644
            if target.exists():
                dest = backup / row["path"]
                dest.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(target, dest)
                if digest(dest) != row["before_sha256"]:
                    raise ValueError("Backup verification failed: " + row["path"])
        (backup / "manifest.json").write_text(json.dumps(manifest, indent=2))
        plan(root, bundle)  # Refuse drift during backup, before the first write.
        installed = []
        try:
            for row in manifest["files"]:
                target = safe(root, row["path"])
                if digest(target) != row["before_sha256"]:
                    raise ValueError("Concurrent change: " + row["path"])
                data = safe(bundle / "stage", row["path"]).read_bytes()
                if hashlib.sha256(data).hexdigest() != row["after_sha256"]:
                    raise ValueError("Stage changed during install: " + row["path"])
                replace(target, data, modes[row["path"]])
                installed.append(row)
            for row in installed:
                if digest(safe(root, row["path"])) != row["after_sha256"]:
                    raise ValueError("Post-install mismatch: " + row["path"])
        except Exception:
            # Restore only our own bytes; preserve and report any concurrent edits.
            blocked = []
            for row in reversed(installed):
                target = safe(root, row["path"])
                if digest(target) != row["after_sha256"]:
                    blocked.append(row["path"])
                    continue
                prior = backup / row["path"]
                if prior.exists():
                    replace(target, prior.read_bytes(), modes[row["path"]])
                else:
                    saved = backup / "recovered-new" / row["path"]
                    saved.parent.mkdir(parents=True, exist_ok=True)
                    os.replace(target, saved)
            if blocked:
                raise RuntimeError("Rollback preserved concurrent edits: " + ", ".join(blocked))
            raise
        receipt = {"utc": datetime.now(timezone.utc).isoformat(), "files": len(installed),
                   "backup": str(backup), "activation": "not performed", "cms": "not performed"}
        (backup / "receipt.json").write_text(json.dumps(receipt, indent=2))
        return receipt

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path("/home/servernetcloud"))
    parser.add_argument("--bundle", type=Path, default=Path(__file__).resolve().parent)
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    if args.root.is_symlink() or args.bundle.is_symlink():
        raise ValueError("Symlink roots refused")
    if args.apply:
        print(json.dumps(install(args.root.resolve(), args.bundle.resolve()), indent=2))
    else:
        result = plan(args.root.resolve(), args.bundle.resolve())
        print(json.dumps({"verified": len(result["files"]), "mode": "preview; no writes"}))

if __name__ == "__main__":
    main()
