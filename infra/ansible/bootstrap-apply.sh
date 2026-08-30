#!/usr/bin/env bash
# =============================================================================
#  ServerNet IaC — turnkey applier.
#  Run as root ON the Proxmox host, from the repo root:
#        bash bootstrap-apply.sh
#
#  It installs ansible, auto-fills the node name + a local Module-2 secret,
#  syntax-checks on the real ansible, then applies ONLY the safe/idempotent
#  parts (host bootstrap + snippet + templates — existing objects are
#  reconciled/skipped) and deploys Module 2 (new, additive alongside sing-box).
#
#  It does NOT touch the working control panel, the sing-box exit, or the
#  existing pf-agent. The VM 108 red line and the 100-114 guests are protected
#  by the playbooks no matter what.
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")"
say(){ printf '\n\033[1;36m=== %s ===\033[0m\n' "$*"; }

[ "$(id -u)" = 0 ] || { echo "Run as root on the Proxmox host."; exit 1; }
command -v pvesh >/dev/null 2>&1 || { echo "pvesh not found — this is not a Proxmox host."; exit 1; }

# 1) ansible-core (apt first, then pip; the host has real internet)
if ! command -v ansible-playbook >/dev/null 2>&1; then
  say "Installing ansible-core"
  { apt-get update -qq && apt-get install -y ansible-core; } \
    || apt-get install -y ansible \
    || pip3 install --break-system-packages ansible-core \
    || { echo "FATAL: could not install ansible by any method."; exit 1; }
fi
ansible-playbook --version | head -1

# 2) real node name -> extra var
NODE="$(pvesh get /nodes --output-format json 2>/dev/null \
        | python3 -c 'import sys,json;print(json.load(sys.stdin)[0]["node"])' 2>/dev/null || hostname)"
say "Proxmox node: ${NODE}"
EXTRA=(-e "pve_node=${NODE}")

# 3) minimal vault — only Module 2 needs a secret; generate a local clash secret.
VDIR="inventory/group_vars/all"
VF="${VDIR}/vault.yml"
mkdir -p "$VDIR"
if [ ! -f "$VF" ]; then
  say "Creating ${VF} (local Module-2 secret auto-generated; other secrets stay placeholders)"
  SECRET="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 32)"
  cp inventory/group_vars/vault.example.yml "$VF"
  sed -i "s|^vault_singbox_clash_secret:.*|vault_singbox_clash_secret: \"${SECRET}\"|" "$VF"
  chmod 600 "$VF"
fi

# 4) validate on the REAL ansible (hard gate), then an informational dry-run
say "Syntax check (hard gate)"
ansible-playbook site.yml --syntax-check "${EXTRA[@]}"
say "Dry-run (informational) -> /root/.servernet/apply-check.log"
mkdir -p /root/.servernet
ansible-playbook site.yml --check "${EXTRA[@]}" > /root/.servernet/apply-check.log 2>&1 || true
grep -aE 'PLAY RECAP|failed=' /root/.servernet/apply-check.log | tail -3 || true

# 5) SAFE idempotent applies
say "Apply: host bootstrap (pool / least-priv token / role / ACL / snippet storage)"
ansible-playbook site.yml --tags bootstrap "${EXTRA[@]}"
say "Apply: snippet + templates (existing templates skipped)"
ansible-playbook site.yml --tags templates "${EXTRA[@]}"

# 6) Module 2 — new, additive alongside sing-box in LXC 113
say "Apply: Module 2 multi-country pool (mihomo)"
ansible-playbook playbooks/45-exit-multicountry.yml -e mc_apply=true "${EXTRA[@]}"

# 7) verify Module 2
say "Verify Module 2"
pct exec 113 -- systemctl is-active mihomo sn-subs-httpd 2>/dev/null || true
pct exec 113 -- bash -lc 'ls -la /var/lib/sn-subs 2>/dev/null || true'

say "DONE"
echo "Applied: host bootstrap + templates + Module 2."
echo "Left untouched: control panel, sing-box exit, existing pf-agent (all still running)."
echo "New least-priv token secret (if created): /root/.servernet/provisioner.token"
echo "To (re)apply panel/exit/pf later — after exporting their live state — see README section 7."
