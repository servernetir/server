#!/usr/bin/env python3
"""Render isolated node configuration. Never connects to or modifies a node."""
import argparse
import hashlib
import ipaddress
import json
import re
from pathlib import Path


def safe(value, pattern, label):
    if not isinstance(value, str) or not re.fullmatch(pattern, value):
        raise ValueError(f"Invalid {label}")
    return value


def validate(c):
    c = dict(c)
    simulation = c.get("simulation") is True
    for key in ("name", "pve_node", "storage", "backup_storage"):
        safe(c.get(key), r"[A-Za-z][A-Za-z0-9_-]{0,62}", key)
    safe(c.get("location_code"), r"[a-z][a-z0-9-]{1,30}", "location_code")
    for key in ("uplink", "guest_bridge"):
        safe(c.get(key), r"[A-Za-z][A-Za-z0-9_.-]{0,14}", key)
    if c["uplink"] == c["guest_bridge"]:
        raise ValueError("Guest bridge must not be the management uplink")
    network = ipaddress.IPv4Network(c["routed_subnet"], strict=True)
    if not 24 <= network.prefixlen <= 29:
        raise ValueError("Use a bounded /24 through /29 routed subnet")
    primary = ipaddress.IPv4Address(c["primary_ipv4"])
    gateway = ipaddress.IPv4Address(c["guest_gateway"])
    if gateway not in network or gateway in (network.network_address, network.broadcast_address):
        raise ValueError("Guest gateway must be a usable routed-subnet address")
    if primary in network:
        raise ValueError("Provider must route the guest subnet to a separate primary host IP")
    if not simulation and (not primary.is_global or not network.network_address.is_global):
        raise ValueError("Production nodes require assigned global IPv4 addresses")
    allow = c.get("management_cidrs", [])
    if not allow:
        raise ValueError("At least one restricted management source is required")
    for cidr in allow:
        n = ipaddress.ip_network(cidr, strict=True)
        if n.prefixlen == 0:
            raise ValueError("Unrestricted management access is forbidden")
    if not isinstance(c.get("app_ipv4"), str) or not ipaddress.ip_address(c["app_ipv4"]).version == 4:
        raise ValueError("app_ipv4 must identify the application egress address")
    if not isinstance(c.get("dns_servers"), list) or not 1 <= len(c["dns_servers"]) <= 3:
        raise ValueError("Specify one to three DNS resolver addresses")
    for address in c["dns_servers"]:
        ipaddress.ip_address(address)
    if not isinstance(c.get("template_vmid_start"), int) or not 100 <= c["template_vmid_start"] <= 999999999:
        raise ValueError("Invalid template VMID range")
    if c.get("network_model") != "routed-bridge":
        raise ValueError("Only the documented routed-bridge model is implemented")
    for resource in ("vcpu", "ram_mb", "disk_gb"):
        capacity, reserve = c.get(resource + "_capacity"), c.get(resource + "_reserve")
        if not isinstance(capacity, int) or isinstance(capacity, bool) or not isinstance(reserve, int) or isinstance(reserve, bool) or not 0 <= reserve < capacity:
            raise ValueError(f"Specify positive {resource} capacity and a smaller nonnegative reserve")
    if not isinstance(c.get("public_ingress", []), list):
        raise ValueError("public_ingress must be a list of explicit port rules")
    for rule in c.get("public_ingress", []):
        if not isinstance(rule, dict) or rule.get("protocol") not in {"tcp", "udp"}:
            raise ValueError("Public ingress must use explicit TCP/UDP ports")
        if not isinstance(rule.get("port"), int) or isinstance(rule["port"], bool) or not 1 <= rule["port"] <= 65535:
            raise ValueError("Invalid public ingress port")
    return c, network


def host_firewall(c):
    rules = ["# Candidate for /etc/pve/nodes/NODE/host.fw; verify independent console before applying.",
             "[OPTIONS]", "enable: 1", "policy_in: DROP", "policy_out: ACCEPT", "", "[RULES]"]
    for cidr in c["management_cidrs"]:
        rules += [f"IN ACCEPT -source {cidr} -p tcp -dport 22", f"IN ACCEPT -source {cidr} -p tcp -dport 8006"]
    rules += [f"IN ACCEPT -source {c['app_ipv4']}/32 -p tcp -dport 8006", "IN ACCEPT -p icmp"]
    return "\n".join(rules) + "\n"


def guest_firewall(config, address):
    c, network = validate(config)
    ip = ipaddress.IPv4Address(address)
    if ip not in network or ip in (network.network_address, network.broadcast_address, ipaddress.IPv4Address(c["guest_gateway"])):
        raise ValueError("Guest IP must be its usable non-gateway locked allocation")
    rules = ["# Per-VM candidate; fixed allocation must match the existing QEMU net0 MAC/config.",
             "[OPTIONS]", "enable: 1", "ipfilter: 1", "macfilter: 1", "dhcp: 0", "ndp: 0", "radv: 0",
             "policy_in: DROP", "policy_out: ACCEPT", "", "[IPSET ipfilter-net0]", str(ip), "", "[RULES]",
             "# IPv6 remains unavailable until its isolation and address contract are implemented.",
             "IN DROP -source ::/0", "OUT DROP -dest ::/0",
             f"OUT DROP -dest {network}", f"IN DROP -source {network}",
             f"OUT DROP -dest {c['primary_ipv4']}/32", f"OUT DROP -dest {c['app_ipv4']}/32",
             "OUT DROP -dest 10.0.0.0/8", "OUT DROP -dest 172.16.0.0/12", "OUT DROP -dest 192.168.0.0/16",
             "OUT DROP -dest 169.254.0.0/16", "OUT DROP -dest 100.64.0.0/10"]
    rules += [f"IN ACCEPT -p {r['protocol']} -dport {r['port']}" for r in c.get("public_ingress", [])]
    return "\n".join(rules) + "\n"


def render(config, output):
    c, subnet = validate(config)
    output = Path(output)
    output.mkdir(parents=True, exist_ok=True)
    bridge = c["guest_bridge"]
    files = {
        "interfaces.fragment": f"""# Generated candidate; preserve existing management uplink configuration.
# Provider routes {subnet} to {c['primary_ipv4']}; verify in Robot before applying.
auto {bridge}
iface {bridge} inet static
    address {c['guest_gateway']}/{subnet.prefixlen}
    bridge-ports none
    bridge-stp off
    bridge-fd 0
""",
        "99-cloud-engine.conf": "net.ipv4.ip_forward=1\nnet.ipv4.conf.all.rp_filter=2\nnet.ipv4.conf.default.rp_filter=2\n",
        "host.fw": host_firewall(c),
        "firewall-requirements.json": json.dumps({
            "mode": "candidate-do-not-apply-without-console",
            "management_sources": c["management_cidrs"],
            "app_source": c["app_ipv4"],
            "host_allow": {"tcp_22": c["management_cidrs"], "tcp_8006": c["management_cidrs"] + [c["app_ipv4"] + "/32"]},
            "guest_bridge": bridge, "routed_subnet": str(subnet),
            "guest_rules": ["firewall=1 on each VM NIC", "ipfilter=1 and ipfilter-net0 contains only assigned guest IPv4", "deny spoofed source MAC/IP", "deny tenant-to-management and tenant-to-tenant traffic by default", "allow established/related and DHCP/cloud-init only where needed", "explicitly allow supported public ingress; do not globally drop customer VPN protocols"],
        }, indent=2) + "\n",
        "cloud-init.network.yaml": f"""# Per-VM renderer substitutes ADDRESS from locked IPAM, never by scanning guests.
version: 2
ethernets:
  eth0:
    match:
      name: "en*"
    addresses: ["ADDRESS/{subnet.prefixlen}"]
    routes:
      - to: default
        via: {c['guest_gateway']}
    nameservers:
      addresses: [{', '.join(c['dns_servers'])}]
""",
        "node-registration.json": json.dumps({
            "name": c["name"], "location_code": c["location_code"], "pve_node": c["pve_node"], "api_url": "https://" + c["primary_ipv4"] + ":8006/api2/json",
            "storage": c["storage"],
            "bridge": bridge, "gateway": c["guest_gateway"],
            "sales_enabled": False, "draining": False, "health": "unknown", "token_secret": None,
            **{key: c[key] for key in ("vcpu_capacity", "vcpu_reserve", "ram_mb_capacity", "ram_mb_reserve", "disk_gb_capacity", "disk_gb_reserve")},
            "metadata": {"simulation": c.get("simulation", False), "backup_storage": c["backup_storage"], "routed_subnet": str(subnet)},
        }, indent=2) + "\n",
    }
    hashes = {}
    for name, content in files.items():
        target = output / name
        if target.exists() and target.read_text() != content:
            raise ValueError(f"Refusing to overwrite different existing candidate: {name}")
        target.write_text(content)
        hashes[name] = hashlib.sha256(content.encode()).hexdigest()
    (output / "manifest.json").write_text(json.dumps({"config_sha256": hashlib.sha256(json.dumps(c, sort_keys=True).encode()).hexdigest(), "files": hashes, "simulation": c.get("simulation", False), "applied": False}, indent=2) + "\n")
    return hashes


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("config", type=Path)
    p.add_argument("--output", type=Path, required=True)
    p.add_argument("--guest-ip", help="Optional exact locked allocation; renders an additional VM firewall candidate")
    p.add_argument("--vmid", type=int, help="Reserved VMID belonging to --guest-ip; no VM is contacted")
    args = p.parse_args()
    try:
        config = json.loads(args.config.read_text())
        if bool(args.guest_ip) != bool(args.vmid) or (args.vmid is not None and not 100 <= args.vmid <= 999999999):
            raise ValueError("Supply a valid reserved --vmid and --guest-ip together")
        files = render(config, args.output)
        if args.guest_ip:
            content = guest_firewall(config, args.guest_ip)
            target = args.output / (str(args.vmid) + ".fw")
            if target.exists() and target.read_text() != content:
                raise ValueError("Refusing to overwrite different existing VM firewall")
            target.write_text(content)
            files[target.name] = hashlib.sha256(content.encode()).hexdigest()
            manifest_path = args.output / "manifest.json"
            manifest = json.loads(manifest_path.read_text())
            manifest["files"] = files
            manifest_path.write_text(json.dumps(manifest, indent=2) + "\n")
    except (ValueError, KeyError, OSError) as exc:
        p.exit(2, f"Configuration rejected: {exc}\n")
    print(json.dumps({"rendered": sorted(files), "applied": False}, indent=2))


if __name__ == "__main__":
    main()
