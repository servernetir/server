# Dedicated-node candidates

These tools generate local candidates and inspect a node. They do not buy a
server, activate Robot rescue, repartition a disk or apply network changes.
Use an existing isolated cloud checkout; do not create a Git worktree unless
explicitly requested. Never point the existing Iran Ansible inventory at a new
German server.

```sh
python3 infra/cloud-engine/onboarding/render.py \
  infra/cloud-engine/onboarding/node.example.json --output /tmp/engine-node-candidate
python3 infra/cloud-engine/onboarding/preflight.py
```

The example is simulation-only and uses RFC 5737 addresses. For a purchased node,
copy the JSON to a private operator directory, enter its assigned network and
set `simulation=false`. The renderer validates the bounded subnet, isolated
bridge, gateway, restricted management sources and identifier syntax; it refuses
to overwrite different existing candidates. Review `manifest.json` hashes before
installation. It deliberately cannot apply artifacts.

`install-pve.sh --plan` runs only APT simulation on an actual Debian 13/KVM node
with an administrator-configured, verified official Proxmox VE 9 repository.
`--apply` also requires root, a hostname-specific approval and verified recovery
backup/console. Do not run the apply stage during software-only development.
It installs packages only; partitioning, reboot and networking remain explicit
steps in the [runbook](../../../docs/project/cloud-engine/NODE-RUNBOOK.md).

`firewall-requirements.json` is an input to the firewall review, not a complete
policy. `host.fw` is an actual PVE host firewall candidate with default incoming
DROP and restricted management TCP ingress. The global PVE firewall must also be
enabled through its supported configuration; compile/inspect the rules on the
accepted PVE version before installation. Confirm management reachability via a separate console and
test tenant source-spoofing and isolation before enabling sales. The routed
subnet bridge consumes one usable address as the guest gateway. Reserve it in
IPAM; the host's primary IP remains a separate management/routing address.

To generate a concrete per-VM PVE firewall candidate from the same node config,
pass the exact reserved pair: `--vmid 1000 --guest-ip 203.0.113.2`. This additionally
renders `1000.fw`: MAC/IP filtering, one `ipfilter-net0` IP, denied tenant subnet
and management/private destinations, IPv6 denied, default incoming DROP. Public
ingress stays closed until explicitly listed as TCP/UDP ports in `public_ingress`.
There are no open peer rules. Do not apply this against a guessed VMID or address;
the application IPAM journal is authoritative. Review resulting rule ordering,
compile and packet-test the candidate on the actual PVE version before enabling
sales. A generated file does not establish that firewall rules are installed.
