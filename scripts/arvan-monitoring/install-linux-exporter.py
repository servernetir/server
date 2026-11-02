#!/usr/bin/env python3
"""Prepare/verify one Linux guest exporter. Never bills, enrolls or contacts Arvan.

Default is a read-only inventory. --apply additionally requires its exact digest.
Only an authenticated, official Debian/Ubuntu apt package is downloaded; package
maintainer scripts are never executed. Existing installations require review.
"""
from __future__ import annotations

import argparse
import datetime
import decimal
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import time
import urllib.request

PACKAGE = "prometheus-node-exporter"
UNIT = "servernet-arvan-node-exporter.service"
PORT = 19100
SAFE_PATH = "/usr/sbin:/usr/bin:/sbin:/bin"


class Refused(RuntimeError):
    pass


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


class Runner:
    def run(self, args, *, cwd=None, timeout=15):
        binary = shutil.which(args[0], path=SAFE_PATH)
        if not binary:
            raise Refused("required_command_missing:" + args[0])
        result = subprocess.run([binary, *args[1:]], cwd=cwd, timeout=timeout,
                                capture_output=True, text=True, check=False,
                                env={"PATH": SAFE_PATH, "LANG": "C", "LC_ALL": "C"})
        if result.returncode:
            # apt/proc output may contain private mirror addresses. Do not echo it.
            raise Refused("command_failed:" + args[0])
        return result.stdout


def local_metrics():
    # Explicitly disable environment proxies for this loopback-only request.
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    with opener.open(f"http://127.0.0.1:{PORT}/metrics", timeout=4) as response:
        body = response.read(2 * 1024 * 1024 + 1)
        if response.status != 200 or len(body) > 2 * 1024 * 1024:
            raise Refused("metrics_response_invalid")
        return body.decode("utf-8", errors="strict")


class Installer:
    def __init__(self, root=Path("/"), runner=None, metrics_reader=local_metrics):
        # root injection exists for isolated tests; the CLI never exposes it.
        self.root = Path(root)
        self.owner = self.root.stat().st_uid
        self.runner = runner or Runner()
        self.metrics_reader = metrics_reader
        self.home = self.root / "opt/servernet-node-exporter"
        self.unit = self.root / "etc/systemd/system" / UNIT
        self.binary = self.home / "node_exporter"
        self.manifest = self.home / "manifest.json"

    def safe_file(self, path):
        for node in [path, *path.parents]:
            if node.is_symlink():
                raise Refused("symlink_path_requires_review")
            if node == self.root:
                break
        if not path.is_file():
            raise Refused("managed_file_missing")
        st = path.stat()
        if st.st_uid != self.owner or st.st_mode & 0o022:
            raise Refused("managed_file_ownership_or_mode_invalid")

    def safe_parent(self, path):
        for parent in path.parents:
            if parent.is_symlink():
                raise Refused("symlink_path_requires_review")
            if parent.exists():
                st = parent.stat()
                if st.st_uid != self.owner or st.st_mode & 0o022:
                    raise Refused("parent_ownership_or_mode_invalid")
            if parent == self.root:
                break

    def owned(self):
        self.safe_parent(self.home)
        self.safe_parent(self.unit)
        if not self.home.exists() and not self.home.is_symlink() and not self.unit.exists() and not self.unit.is_symlink():
            return None
        for path in [self.manifest, self.binary, self.unit]:
            self.safe_file(path)
        try:
            state = json.loads(self.manifest.read_text())
            valid = (state["schema"] == 1 and state["package"] == PACKAGE
                     and state["port"] == PORT
                     and digest(self.binary.read_bytes()) == state["binary_sha256"]
                     and digest(self.unit.read_bytes()) == state["unit_sha256"])
        except (KeyError, ValueError):
            valid = False
        if not valid:
            raise Refused("managed_installation_changed_requires_review")
        return state

    def interface(self, explicit=None):
        devices = set()
        for family in ["-4", "-6"]:
            routes = json.loads(self.runner.run(["ip", "-j", family, "route", "show", "default"]))
            for route in routes:
                if isinstance(route.get("dev"), str) and route["dev"] != "lo":
                    devices.add(route["dev"])
        if explicit is None and len(devices) != 1:
            raise Refused("ambiguous_default_interface_use_explicit_interface")
        interface = explicit or next(iter(devices))
        if not re.fullmatch(r"[A-Za-z0-9_.:-]{1,15}", interface) or interface not in devices:
            raise Refused("interface_must_be_an_existing_default_route_device")
        links = json.loads(self.runner.run(["ip", "-j", "link", "show", "dev", interface]))
        if len(links) != 1 or links[0].get("ifname") != interface:
            raise Refused("interface_not_found")
        return interface

    def listeners(self):
        output = self.runner.run(["ss", "-H", "-ltn"])
        return [line.split()[3] for line in output.splitlines() if len(line.split()) >= 4]

    def candidate(self):
        os_release = (self.root / "etc/os-release").read_text()
        if not re.search(r'^ID=(?:"?)(debian|ubuntu)(?:"?)$', os_release, re.M):
            raise Refused("only_debian_ubuntu_supported")
        architecture = self.runner.run(["dpkg", "--print-architecture"]).strip()
        if architecture not in {"amd64", "arm64"}:
            raise Refused("unsupported_architecture")
        policy = self.runner.run(["apt-cache", "policy", PACKAGE])
        match = re.search(r"^\s*Candidate:\s*(\S+)", policy, re.M)
        version = match.group(1) if match else ""
        if not re.fullmatch(r"[0-9][A-Za-z0-9.+:~_-]*", version):
            raise Refused("apt_candidate_unavailable_refresh_metadata_separately")
        # Bind repository evidence to the candidate's version-table stanza.
        in_candidate = False
        candidate_sources = []
        for line in policy.splitlines():
            heading = re.match(r"^\s+(?:\*\*\*\s+)?(\S+)\s+\d+\s*$", line)
            if heading:
                in_candidate = heading.group(1) == version
            elif in_candidate:
                url = re.search(r"https?://([^/\s]+)(/[^\s]*)?", line)
                if url:
                    host = url.group(1).lower()
                    allowed = host in {"deb.debian.org", "security.debian.org", "archive.ubuntu.com", "security.ubuntu.com", "ports.ubuntu.com"} or bool(re.fullmatch(r"[a-z]{2}\.archive\.ubuntu\.com", host))
                    if not allowed:
                        raise Refused("candidate_has_nonofficial_repository_requires_review")
                    source = re.match(r"^\s*\d+\s+(https?://\S+\s+.+\s+Packages)\s*$", line)
                    if source:
                        candidate_sources.append((source.group(1), host))
        if not candidate_sources:
            raise Refused("official_apt_candidate_not_verified")
        # Package policy alone omits Release Origin. Bind each selected source
        # to apt's release metadata and its independently reported Site.
        all_policy = self.runner.run(["apt-cache", "policy"])
        origins = {}
        current = None
        for line in all_policy.splitlines():
            source = re.match(r"^\s*\d+\s+(https?://\S+\s+.+\s+Packages)\s*$", line)
            if source:
                current = source.group(1)
                origins[current] = {}
            elif current and line.strip().startswith("release "):
                fields_release = dict(re.findall(r"(?:^|,)([a-z])=([^,]+)", line.strip()[8:]))
                origins[current]["origin"] = fields_release.get("o")
            elif current and line.strip().startswith("origin "):
                origins[current]["site"] = line.strip()[7:]
        allowed_origins = {"Ubuntu"} if re.search(r'^ID="?ubuntu"?$', os_release, re.M) else {"Debian", "Debian-Security"}
        for source, host in candidate_sources:
            release = origins.get(source, {})
            if release.get("origin") not in allowed_origins or release.get("site") != host:
                raise Refused("trusted_apt_origin_and_site_not_verified")
        metadata = self.runner.run(["apt-cache", "show", "--no-all-versions", f"{PACKAGE}={version}"])
        fields = dict(re.findall(r"^([A-Za-z0-9-]+): (.*)$", metadata, re.M))
        if (fields.get("Package") != PACKAGE or fields.get("Version") != version
                or fields.get("Architecture") != architecture
                or not re.fullmatch(r"[a-f0-9]{64}", fields.get("SHA256", ""))):
            raise Refused("apt_package_metadata_invalid")
        size = fields.get("Size", "")
        if not size.isdigit() or not 0 < int(size) <= 64 * 1024 * 1024:
            raise Refused("apt_package_size_invalid")
        return {"version": version, "architecture": architecture, "sha256": fields["SHA256"], "size": int(size)}

    def inventory(self, explicit=None):
        owned = self.owned()
        interface = self.interface(explicit)
        if owned:
            if owned["interface"] != interface:
                raise Refused("default_interface_changed_requires_review")
            return {"schema": 1, "state": "managed", "interface": interface,
                    "manifest_sha256": digest(self.manifest.read_bytes()), "port": PORT}
        for relative in ["usr/bin/prometheus-node-exporter", "usr/local/bin/node_exporter",
                         "etc/default/prometheus-node-exporter"]:
            p = self.root / relative
            if p.exists() or p.is_symlink():
                raise Refused("existing_exporter_requires_inventory_review")
        for name in ["prometheus-node-exporter.service", "node_exporter.service", "servernet-node-exporter.service"]:
            state = self.runner.run(["systemctl", "show", name, "--property=LoadState", "--value"]).strip()
            if state != "not-found":
                raise Refused("existing_exporter_unit_requires_inventory_review")
        for address in self.listeners():
            if address.rsplit(":", 1)[-1] in {str(PORT), "9100"}:
                raise Refused("exporter_port_in_use_requires_inventory_review")
        return {"schema": 1, "state": "new", "interface": interface, "port": PORT,
                "candidate": self.candidate(),
                "scope": "default_route_interface_not_verified_billable_public"}

    def unit_text(self, interface):
        # Avoid backslashes interpreted by systemd's ExecStart parser.
        include = interface.replace(".", "[.]")
        return ("[Unit]\nDescription=ServerNet Arvan guest network counters\nAfter=network.target\n\n"
                "[Service]\nType=simple\nDynamicUser=yes\n"
                f"ExecStart=/opt/servernet-node-exporter/node_exporter --web.listen-address=127.0.0.1:{PORT} "
                f"--collector.disable-defaults --collector.netdev --collector.netclass --collector.stat --collector.netdev.device-include=^{include}$\n"
                "Restart=on-failure\nRestartSec=5\nNoNewPrivileges=yes\nProtectSystem=strict\nProtectHome=yes\n"
                "PrivateTmp=yes\nPrivateDevices=yes\nProtectKernelTunables=yes\nProtectKernelModules=yes\n"
                "ProtectControlGroups=yes\nRestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX\n\n[Install]\nWantedBy=multi-user.target\n")

    def verify(self, interface):
        if self.runner.run(["systemctl", "is-active", UNIT]).strip() != "active":
            raise Refused("managed_service_not_active")
        addresses = [a for a in self.listeners() if a.rsplit(":", 1)[-1] == str(PORT)]
        if addresses != [f"127.0.0.1:{PORT}"]:
            raise Refused("loopback_listener_not_verified")
        pid = self.runner.run(["systemctl", "show", UNIT, "--property=MainPID", "--value"]).strip()
        if not re.fullmatch(r"[1-9]\d*", pid):
            raise Refused("exporter_process_not_verified")
        process_listeners = self.runner.run(["ss", "-H", "-ltnp"])
        matched = [line for line in process_listeners.splitlines()
                   if len(line.split()) >= 4 and line.split()[3] == f"127.0.0.1:{PORT}"
                   and re.search(r"\bpid=" + re.escape(pid) + r"(?:,|\))", line)]
        if len(matched) != 1:
            raise Refused("listener_not_owned_by_managed_exporter")
        metrics = self.metrics_reader()
        if not isinstance(metrics, str) or len(metrics) > 2 * 1024 * 1024:
            raise Refused("metrics_response_invalid")
        if not re.search(r'^node_scrape_collector_success\{collector="netdev"\}\s+1(?:\.0)?$', metrics, re.M):
            raise Refused("netdev_collection_not_successful")
        values = {}
        for key in ["receive", "transmit"]:
            name = f"node_network_{key}_bytes_total"
            rows = re.findall(r"^" + name + r'\{device="' + re.escape(interface) + r'"\}\s+(\S+)\s*$', metrics, re.M)
            try:
                value = decimal.Decimal(rows[0]) if len(rows) == 1 else decimal.Decimal("NaN")
            except decimal.InvalidOperation:
                value = decimal.Decimal("NaN")
            if not value.is_finite() or value < 0 or value != value.to_integral_value():
                raise Refused("network_counter_invalid:" + key)
            values[key + "_bytes"] = str(value.to_integral_value())
        return {"status": "verified", "interface": interface, "listen": f"127.0.0.1:{PORT}",
                "counters": values, "central_enrollment": "not_performed", "billing": "not_performed"}

    def apply(self, inventory, expected):
        if digest(json.dumps(inventory, sort_keys=True, separators=(",", ":")).encode()) != expected:
            raise Refused("inventory_digest_mismatch")
        if os.geteuid() != 0:
            raise Refused("apply_requires_root")
        # Serializes only our installer. Existing unit/package configuration is untouched.
        lock = self.root / "run/lock/servernet-arvan-monitoring.lock"
        fd = os.open(lock, os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "w") as handle:
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            if self.inventory(inventory["interface"]) != inventory:
                raise Refused("inventory_changed")
            if inventory["state"] == "managed":
                return self.verify(inventory["interface"]) | {"changed": False}
            return self.install(inventory)

    def install(self, inventory):
        candidate = inventory["candidate"]
        created_home = created_unit = False
        try:
            with tempfile.TemporaryDirectory(prefix=".servernet-exporter-", dir=self.root / "opt") as directory:
                stage = Path(directory)
                self.runner.run(["apt-get", "-o", "Acquire::AllowInsecureRepositories=false", "-o",
                                 "APT::Get::AllowUnauthenticated=false", "download",
                                 f"{PACKAGE}={candidate['version']}"], cwd=stage, timeout=180)
                archives = list(stage.glob("*.deb"))
                if len(archives) != 1 or archives[0].is_symlink() or not archives[0].is_file():
                    raise Refused("downloaded_package_not_unique")
                archive = archives[0]
                if archive.stat().st_size != candidate["size"] or digest(archive.read_bytes()) != candidate["sha256"]:
                    raise Refused("downloaded_package_hash_mismatch")
                for field, expected in [("Package", PACKAGE), ("Version", candidate["version"]), ("Architecture", candidate["architecture"])]:
                    if self.runner.run(["dpkg-deb", "--field", str(archive), field]).strip() != expected:
                        raise Refused("downloaded_package_identity_mismatch")
                unpacked = stage / "unpacked"
                self.runner.run(["dpkg-deb", "--extract", str(archive), str(unpacked)], timeout=30)
                source = unpacked / "usr/bin/prometheus-node-exporter"
                self.safe_file(source)
                version = self.runner.run([str(source), "--version"], timeout=5)
                if "node_exporter" not in version:
                    raise Refused("exporter_binary_not_executable")
                self.home.mkdir(mode=0o755)  # no exist_ok: concurrent/unreviewed files fail closed
                created_home = True
                with self.binary.open("xb") as output:
                    output.write(source.read_bytes())
                self.binary.chmod(0o755)
                unit = self.unit_text(inventory["interface"]).encode()
                with self.unit.open("xb") as output:
                    output.write(unit)
                created_unit = True
                self.unit.chmod(0o644)
                self.runner.run(["systemctl", "daemon-reload"])
                self.runner.run(["systemctl", "enable", "--now", UNIT], timeout=30)
                # systemctl's Type=simple start can return before the first bind.
                # Retry verification only, never installation/download/power.
                for attempt in range(5):
                    try:
                        result = self.verify(inventory["interface"])
                        break
                    except (Refused, OSError, urllib.error.URLError):
                        if attempt == 4:
                            raise
                        time.sleep(0.5)
                state = {"schema": 1, "package": PACKAGE, "package_version": candidate["version"],
                         "package_sha256": candidate["sha256"], "binary_sha256": digest(self.binary.read_bytes()),
                         "unit_sha256": digest(unit), "interface": inventory["interface"], "port": PORT,
                         "created_utc": datetime.datetime.now(datetime.timezone.utc).isoformat()}
                with self.manifest.open("x") as output:
                    json.dump(state, output, sort_keys=True)
                self.manifest.chmod(0o600)
                return result | {"changed": True}
        except Exception:
            # Only stop/remove files created by this invocation. Never uninstall a
            # package or touch an existing service, firewall, SSH or credentials.
            if created_unit:
                self.runner.run(["systemctl", "disable", "--now", UNIT], timeout=30)
                if any(a.rsplit(":", 1)[-1] == str(PORT) for a in self.listeners()):
                    raise Refused("failed_install_listener_still_present_requires_operator")
                self.unit.unlink()
                self.runner.run(["systemctl", "daemon-reload"])
            if created_home:
                shutil.rmtree(self.home)
            raise


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--inventory-hash")
    parser.add_argument("--interface")
    args = parser.parse_args()
    try:
        installer = Installer()
        inventory = installer.inventory(args.interface)
        inventory_hash = digest(json.dumps(inventory, sort_keys=True, separators=(",", ":")).encode())
        if args.apply:
            if not args.inventory_hash:
                raise Refused("apply_requires_reviewed_inventory_hash")
            result = installer.apply(inventory, args.inventory_hash)
        else:
            result = {"status": "plan", "inventory": inventory, "inventory_sha256": inventory_hash,
                      "changed": False, "central_enrollment": "not_performed", "billing": "not_performed"}
        print(json.dumps(result, sort_keys=True))
        return 0
    except (Refused, OSError, ValueError, subprocess.TimeoutExpired, urllib.error.URLError) as error:
        # No raw command output, IPs, credentials or package URLs in error output.
        code = str(error) if isinstance(error, Refused) else type(error).__name__
        print(json.dumps({"status": "refused", "reason": code, "success": False}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
