#!/usr/bin/env python3
"""Verify a pinned local disk artifact and generate an inert PVE import plan."""
import argparse
import hashlib
import json
import re
import shlex
from pathlib import Path
from urllib.parse import urlsplit


def verify(manifest, artifact):
    for name in ("key", "version", "architecture", "family", "source_url", "sha256", "checksum_provenance"):
        if not isinstance(manifest.get(name), str) or not manifest[name].strip():
            raise ValueError(f"A pinned {name} is required")
    if not re.fullmatch(r"[a-z0-9][a-z0-9.-]{1,63}", manifest["key"]):
        raise ValueError("Invalid image key")
    if manifest["architecture"] != "amd64":
        raise ValueError("Only amd64 has a prepared deployment plan")
    url = urlsplit(manifest["source_url"])
    if url.scheme != "https" or not url.hostname or url.username or url.password or url.query or url.fragment:
        raise ValueError("Source must be a credential-free immutable HTTPS URL")
    if any(part.lower() in {"latest", "current", "daily"} for part in url.path.split("/")):
        raise ValueError("Moving image URLs cannot identify a template version")
    if not re.fullmatch(r"[0-9a-f]{64}", manifest["sha256"]):
        raise ValueError("A non-placeholder SHA256 is required")
    if len(set(manifest["sha256"])) < 4:
        raise ValueError("Placeholder SHA256 rejected")
    if manifest["family"] == "windows" and not manifest.get("license_evidence"):
        raise ValueError("Windows requires documented distribution/hosting license entitlement")
    artifact = Path(artifact)
    if not artifact.is_file() or artifact.stat().st_size == 0:
        raise ValueError("Artifact must be an existing nonempty file")
    with artifact.open("rb") as file:
        digest = hashlib.file_digest(file, "sha256").hexdigest()
    if digest != manifest["sha256"]:
        raise ValueError("Artifact SHA256 differs from the pinned trusted checksum")
    return digest


def import_plan(manifest, artifact, vmid, storage, bridge):
    digest = verify(manifest, artifact)
    if not isinstance(vmid, int) or not 100 <= vmid <= 999999999:
        raise ValueError("Invalid VMID")
    for value in (storage, bridge):
        if not re.fullmatch(r"[A-Za-z][A-Za-z0-9_-]{0,62}", value):
            raise ValueError("Invalid storage/bridge identifier")
    # A plan is emitted only. No qm/shell subprocess is executed by this tool.
    commands = [
        ["qm", "create", str(vmid), "--name", "template-" + manifest["key"], "--memory", "2048", "--cores", "2", "--net0", "virtio,bridge=" + bridge + ",firewall=1", "--agent", "enabled=1"],
        ["qm", "importdisk", str(vmid), str(Path(artifact).resolve()), storage],
    ]
    if manifest["family"] != "windows":
        commands.append(["qm", "set", str(vmid), "--ide2", storage + ":cloudinit", "--serial0", "socket", "--vga", "serial0"])
    return {"image_key": manifest["key"], "version": manifest["version"], "sha256": digest,
            "artifact_verified": True, "applied": False, "accepted_for_sales": False,
            "commands": [shlex.join(c) for c in commands],
            "remaining_steps": ["Inspect importdisk volume and attach exact returned disk as scsi0; never assume a volume name", "Configure scsihw/boot for the verified disk format and guest family", "Boot isolated initialization test VM and complete per-image acceptance matrix", "Stop and sanitize original image, remove machine-id/host keys as appropriate, then qm template", "Register exact node/template VMID only after independent acceptance; leave sales disabled until hardware gate"]}


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("manifest", type=Path)
    p.add_argument("artifact", type=Path)
    p.add_argument("--vmid", required=True, type=int)
    p.add_argument("--storage", required=True)
    p.add_argument("--bridge", required=True)
    p.add_argument("--output", required=True, type=Path)
    a = p.parse_args()
    try:
        result = import_plan(json.loads(a.manifest.read_text()), a.artifact, a.vmid, a.storage, a.bridge)
        with a.output.open("x") as out:
            json.dump(result, out, indent=2)
            out.write("\n")
    except (ValueError, OSError) as exc:
        p.exit(2, f"Image rejected: {exc}\n")
    print("Artifact verified; inert import plan written. No VM created and no image activated.")


if __name__ == "__main__":
    main()
