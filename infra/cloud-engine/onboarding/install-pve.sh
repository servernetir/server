#!/usr/bin/env bash
# Operator-run package stage only. Default is a signed APT simulation.
set -euo pipefail
mode=${1:---plan}
if [[ "$mode" != --plan && "$mode" != --apply ]]; then
  echo 'Usage: install-pve.sh [--plan|--apply]' >&2; exit 2
fi
. /etc/os-release
[[ "${ID:-}" == debian && "${VERSION_ID:-}" == 13 ]] || { echo 'Requires dedicated Debian 13 node; stop on any other host.' >&2; exit 1; }
[[ -e /dev/kvm ]] || { echo 'KVM unavailable; hardware acceptance required.' >&2; exit 1; }
# The administrator configures the official PVE 9 repository with its signed-by
# keyring following the linked official guide. This script does not invent keys,
# disable signatures, install an untrusted key or replace repository sources.
apt-cache policy proxmox-ve | grep -q 'Candidate: [^ (]' || { echo 'Configure verified official PVE 9 signed repository first.' >&2; exit 1; }
if [[ "$mode" == --plan ]]; then
  apt-get --simulate --no-install-recommends install proxmox-ve postfix open-iscsi chrony
  exit
fi
[[ $EUID == 0 ]] || { echo 'Application requires node administrator privileges.' >&2; exit 1; }
[[ "${SERVERNET_NODE_CHANGE_APPROVAL:-}" == "$(hostname -s)" ]] || { echo 'Explicit node-specific operator approval is required.' >&2; exit 1; }
[[ "${SERVERNET_BACKUP_AND_CONSOLE_VERIFIED:-}" == yes ]] || { echo 'Verify console and recovery backup before installation.' >&2; exit 1; }
apt-get update
apt-get --no-install-recommends install proxmox-ve postfix open-iscsi chrony
echo 'Package stage finished. No reboot, disk formatting, network replacement, guest creation or sales activation performed.'
