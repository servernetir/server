"""Fixed Ubuntu/Debian first-boot wrapper. Importing this module does nothing.

The launcher supplies the exact embedded installer bytes. Runtime overrides are
private test seams, never command-line or customer configuration.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import sys
import time
import types

INSTALLER_SHA256 = "ba2d8ef3c2fea7534e89ca163364c63d0161c1809c7452e3923cfbb0f90f6ab8"
SAFE_PATH = "/usr/sbin:/usr/bin:/sbin:/bin"
PREREQUISITES = ("systemctl", "ip", "ss", "dpkg", "dpkg-deb", "apt-cache", "apt-get")
REFRESH_ARGS = ["apt-get", "-o", "Acquire::AllowInsecureRepositories=false",
                "-o", "Acquire::AllowDowngradeToInsecureRepositories=false",
                "-o", "APT::Get::AllowUnauthenticated=false", "-o", "Acquire::Retries=0",
                "-o", "Acquire::http::Timeout=15", "-o", "Acquire::https::Timeout=15",
                "-o", "APT::Update::Error-Mode=any", "update"]
MISSING_CANDIDATE = "apt_candidate_unavailable_refresh_metadata_separately"
SAFE_REFUSALS = frozenset((
    "symlink_path_requires_review", "managed_file_missing", "managed_file_ownership_or_mode_invalid",
    "parent_ownership_or_mode_invalid", "managed_installation_changed_requires_review",
    "ambiguous_default_interface_use_explicit_interface", "interface_must_be_an_existing_default_route_device",
    "interface_not_found", "only_debian_ubuntu_supported", "unsupported_architecture", MISSING_CANDIDATE,
    "candidate_index_transport_not_verified", "candidate_has_nonofficial_repository_requires_review",
    "official_apt_candidate_not_verified", "trusted_apt_origin_and_site_not_verified",
    "apt_package_metadata_invalid", "apt_package_size_invalid", "default_interface_changed_requires_review",
    "existing_exporter_requires_inventory_review", "existing_exporter_unit_requires_inventory_review",
    "exporter_port_in_use_requires_inventory_review", "managed_service_not_active", "loopback_listener_not_verified",
    "exporter_process_not_verified", "listener_not_owned_by_managed_exporter", "metrics_response_invalid",
    "netdev_collection_not_successful", "network_counter_invalid:receive", "network_counter_invalid:transmit",
    "inventory_digest_mismatch", "apply_requires_root", "inventory_changed", "downloaded_package_not_unique",
    "downloaded_package_hash_mismatch", "downloaded_package_identity_mismatch", "exporter_binary_not_executable",
    "failed_install_listener_still_present_requires_operator", "apply_requires_reviewed_inventory_hash",
))


class BootstrapRefused(RuntimeError):
    pass


def _result(status, reason=None, *, attempted=False, completed=False, **extra):
    result = {"status": status, "central_enrollment": "not_performed", "billing": "not_performed",
              "apt_metadata_refresh_attempted": attempted, "apt_metadata_refresh_completed": completed,
              "installer_sha256": INSTALLER_SHA256}
    if reason is not None:
        result["reason"] = reason
    result.update(extra)
    return result


def _preflight(root):
    if sys.platform != "linux":
        raise BootstrapRefused("linux_required")
    if sys.version_info[:2] < (3, 9):
        raise BootstrapRefused("python_3_9_required")
    if os.geteuid() != 0:
        raise BootstrapRefused("root_required")
    try:
        with (root / "etc/os-release").open("r", encoding="utf-8") as source:
            release = source.read(4097)
    except OSError:
        raise BootstrapRefused("os_release_unavailable") from None
    if len(release) > 4096:
        raise BootstrapRefused("os_release_invalid")
    ids = re.findall(r'^[ \t]*(?:export[ \t]+)?ID[ \t]*=.*$', release, re.M)
    if len(ids) != 1 or re.fullmatch(r'ID=(?:"(?:debian|ubuntu)"|debian|ubuntu)', ids[0]) is None:
        raise BootstrapRefused("only_debian_ubuntu_supported")
    if not (root / "run/systemd/system").is_dir():
        raise BootstrapRefused("systemd_required")
    try:
        with (root / "proc/1/comm").open("r", encoding="utf-8") as source:
            if source.read(65).strip() != "systemd":
                raise BootstrapRefused("systemd_required")
    except OSError:
        raise BootstrapRefused("systemd_required") from None
    for command in PREREQUISITES:
        if shutil.which(command, path=SAFE_PATH) is None:
            raise BootstrapRefused("required_command_missing:" + command)


def _metadata_stale(root, now):
    """A refresh decision only, never a claim about traffic or package freshness."""
    directory = root / "var/lib/apt/lists"
    for path in [directory, *directory.parents]:
        if path.is_symlink():
            raise BootstrapRefused("apt_metadata_path_requires_review")
        if path == root:
            break
    if not directory.exists():
        return True
    owner = root.stat().st_uid
    releases = []
    for count, entry in enumerate(directory.iterdir(), 1):
        if count > 4096:
            raise BootstrapRefused("apt_metadata_inventory_limit")
        if not entry.name.endswith(("_InRelease", "_Release")):
            continue
        attributes = entry.lstat()
        if not stat.S_ISREG(attributes.st_mode) or attributes.st_uid != owner or attributes.st_mode & 0o022:
            raise BootstrapRefused("apt_metadata_file_requires_review")
        releases.append(attributes.st_mtime)
    return not releases or any(timestamp < now - 86400 or timestamp > now + 300 for timestamp in releases)


def _installer_reason(error):
    reason = str(error)
    if reason in SAFE_REFUSALS:
        return reason
    # Command names may be absolute extracted paths. Never echo those values.
    if reason.startswith("required_command_missing:"):
        return "required_command_missing"
    if reason.startswith("command_failed:"):
        return "command_failed"
    return "installer_refused"


def run_embedded(installer_source: bytes, *, _root=None, _installer_factory=None, _now=None) -> dict:
    """Run one pinned install/verification; refresh at most once, apply at most once."""
    if not isinstance(installer_source, bytes) or len(installer_source) > 65536 \
            or hashlib.sha256(installer_source).hexdigest() != INSTALLER_SHA256:
        return _result("refused", "installer_source_pin_mismatch")
    root = Path("/") if _root is None else Path(_root)
    attempted = completed = False
    module = None
    try:
        _preflight(root)
        module = types.ModuleType("servernet_pinned_linux_exporter")
        module.__file__ = "<servernet-pinned-linux-exporter>"
        exec(compile(installer_source, module.__file__, "exec"), module.__dict__)
        installer = module.Installer(root) if _installer_factory is None else _installer_factory(module, root)
        try:
            inventory = installer.inventory()
            refresh = inventory["state"] == "new" and _metadata_stale(root, time.time() if _now is None else _now)
        except module.Refused as error:
            if str(error) != MISSING_CANDIDATE:
                raise
            refresh = True
        if refresh:
            # Authorized metadata refresh only. Existing sources/keys/configured
            # OS hooks are retained; no packages are installed or upgraded.
            attempted = True
            try:
                installer.runner.run(list(REFRESH_ARGS), timeout=120)
            except Exception:
                return _result("refused", "apt_metadata_refresh_failed", attempted=True)
            completed = True
            inventory = installer.inventory()  # All original origin/NIC/foreign-exporter guards run again.
        inventory_hash = module.digest(json.dumps(inventory, sort_keys=True, separators=(",", ":")).encode())
        verified = installer.apply(inventory, inventory_hash)
        if (verified.get("status") != "verified" or verified.get("central_enrollment") != "not_performed"
                or verified.get("billing") != "not_performed" or verified.get("listen") != "127.0.0.1:19100"
                or not isinstance(verified.get("changed"), bool)):
            return _result("refused", "local_verification_unconfirmed", attempted=attempted, completed=completed)
        return _result("verified", attempted=attempted, completed=completed, changed=verified["changed"],
                       local_verification="passed", listen="127.0.0.1:19100")
    except BootstrapRefused as error:
        return _result("refused", str(error), attempted=attempted, completed=completed)
    except Exception as error:
        refused_type = getattr(module, "Refused", None)
        reason = _installer_reason(error) if isinstance(refused_type, type) and isinstance(error, refused_type) else "bootstrap_runtime_failure"
        return _result("refused", reason, attempted=attempted, completed=completed)
