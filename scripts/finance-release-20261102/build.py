"""Build a pinned source candidate; no production access or deployment."""
import argparse
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import zipfile

SCOPE = Path(__file__).with_name("scope.json")
ARCHIVE = "finance-funding-20261102-source.zip"


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def git(root, *args):
    return subprocess.check_output(["git", "-C", str(root), *args])


def read_source(root, source):
    path = PurePosixPath(source)
    if (path.is_absolute() or path.as_posix() != source
            or any(part in (".", "..", "") for part in path.parts)
            or not source.startswith("website/")):
        raise ValueError("Invalid source path")
    current = root
    for part in path.parts:
        current = current / part
        if current.is_symlink():
            raise ValueError("Symlink source rejected")
    if not current.is_file() or not current.resolve().is_relative_to(root):
        raise ValueError("Source outside checkout or missing")
    data = current.read_bytes()
    if data != git(root, "show", "HEAD:" + source):
        raise ValueError("Source differs from the pinned commit: " + source)
    return data


def destinations(source):
    relative = source.removeprefix("website/")
    result = ["servernet_app/" + relative]
    if relative.startswith("public/"):
        result.append("public_html/" + relative.removeprefix("public/"))
    return result


def build(source_root, output):
    scope = json.loads(SCOPE.read_text(encoding="utf-8"))
    root, target = source_root.resolve(strict=True), output.resolve()
    if target.is_relative_to(root) or root.is_relative_to(target):
        raise ValueError("Output must be separate from the source checkout")
    commit = scope["source_commit"]
    if not re.fullmatch(r"[0-9a-f]{40}", commit):
        raise ValueError("Full source commit required")
    if git(root, "rev-parse", "HEAD").decode().strip() != commit:
        raise ValueError("Checkout is not the pinned source commit")
    if Path(git(root, "rev-parse", "--show-toplevel").decode().strip()).resolve() != root:
        raise ValueError("Source root must be the repository root")
    files = scope["files"]
    if len(files) != 26 or len({row["source"] for row in files}) != 26:
        raise ValueError("Expected exactly 26 distinct source files")
    all_destinations, manifest_files, payload = [], [], {}
    allowed = ("website/app/", "website/lang/", "website/database/migrations/",
               "website/resources/views/admin/", "website/public/assets/css/")
    for row in files:
        source = row["source"]
        if not source.startswith(allowed) or not source.endswith((".php", ".css")):
            raise ValueError("Source outside the runtime scope")
        if row["destinations"] != destinations(source):
            raise ValueError("Unexpected production destination")
        data = read_source(root, source)
        payload[source] = data
        all_destinations.extend(row["destinations"])
        manifest_files.append({**row, "size_bytes": len(data), "sha256": sha256(data),
                               "expected_before_sha256": None})
    if len(all_destinations) != 28 or len(set(all_destinations)) != 28:
        raise ValueError("Expected exactly 28 distinct production destinations")
    dependencies = []
    for row in scope["required_shared_dependencies"]:
        if row["destinations"] != destinations(row["source"]):
            raise ValueError("Unexpected shared dependency destination")
        dependencies.append({**row, "sha256": sha256(read_source(root, row["source"])),
                             "included_in_archive": False})
    manifest = {**scope, "files": manifest_files,
                "required_shared_dependencies": dependencies,
                "hash_algorithm": "SHA-256", "production_state": "not_observed",
                "before_hashes_and_backups_required": True}
    manifest_bytes = (json.dumps(manifest, ensure_ascii=False,
                                 indent=2, sort_keys=True) + "\n").encode("utf-8")
    payload["candidate-manifest.json"] = manifest_bytes
    target.mkdir(parents=True, exist_ok=True)
    if any(target.iterdir()):
        raise ValueError("Output directory must be empty")
    archive = target / ARCHIVE
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED) as package:
        for name, data in sorted(payload.items()):
            info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            package.writestr(info, data, compresslevel=9)
    with zipfile.ZipFile(archive) as package:
        if len(package.infolist()) != len(payload) or set(package.namelist()) != set(payload):
            raise ValueError("Archive contains duplicate or unexpected files")
        for name, data in payload.items():
            if package.read(name) != data:
                raise ValueError("Archive content mismatch")
    (target / "candidate-manifest.json").write_bytes(manifest_bytes)
    checksums = {ARCHIVE: sha256(archive.read_bytes()),
                 "candidate-manifest.json": sha256(manifest_bytes)}
    (target / "SHA256SUMS").write_text(
        "".join(digest + "  " + name + "\n" for name, digest in checksums.items()),
        encoding="ascii")
    print(json.dumps({"source_commit": commit, "runtime_files": len(files),
                      "production_destinations": len(all_destinations),
                      "archive_sha256": checksums[ARCHIVE],
                      "production_state": "not_observed"}, sort_keys=True))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source-root", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    build(args.source_root, args.output)
