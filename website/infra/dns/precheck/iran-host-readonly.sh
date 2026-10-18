#!/usr/bin/env bash
# =============================================================================
# فقط‌خواندنی — پیش‌نیازِ تصمیم دربارهٔ نیم‌سرورِ ایران (VMِ داخلی + DNATِ پورتِ ۵۳)
#
# روی میزبانِ Proxmoxِ ایران (hostname: ir) به‌عنوانِ root اجرا شود و خروجی برای
# بازبینی فرستاده شود. هیچ چیزی را تغییر نمی‌دهد: فقط ss/ip/iptables-save/nft list/
# conntrack -S/pvesm/free/cat می‌خواند. خروجی راز ندارد (توکنِ عامل‌ها را نمی‌خوانَد).
# =============================================================================
set -u
[ "$(hostname -s)" = ir ] || { echo "not the Iran host ($(hostname -s)) — stopping"; exit 1; }
sec(){ printf '\n===== %s =====\n' "$1"; }

sec "time / kernel"; date -u; uname -r
sec "listeners on :53 (all addresses, TCP+UDP)"; ss -H -lntup 'sport = :53' 2>/dev/null || ss -lntup | grep -E ':53\b'
sec "anything bound to 85.9.108.118 or 0.0.0.0 that could shadow DNAT"; ss -H -lntu | awk '{print $1,$5}' | grep -E '85\.9\.108\.118:|0\.0\.0\.0:|\*:' | sort -u | head -60
sec "addresses"; ip -br addr
sec "routes (main)"; ip route
sec "policy rules"; ip rule
sec "table 50 (country split)"; ip route show table 50 2>/dev/null
sec "split.state (country-routed VMs)"; cat /etc/servernet/split.state 2>/dev/null || echo "(none)"
sec "iptables backend"; iptables --version; update-alternatives --display iptables 2>/dev/null | head -3
sec "nat table"; iptables-save -t nat 2>/dev/null
sec "filter FORWARD policy + chain"; iptables -S FORWARD 2>/dev/null | head -40
sec "raw table"; iptables-save -t raw 2>/dev/null
sec "nft ruleset (non-iptables tables only)"; nft list tables 2>/dev/null
sec "conntrack usage"; cat /proc/sys/net/netfilter/nf_conntrack_count /proc/sys/net/netfilter/nf_conntrack_max 2>/dev/null; sysctl net.netfilter.nf_conntrack_udp_timeout net.netfilter.nf_conntrack_udp_timeout_stream 2>/dev/null
sec "port-forward / country / egress agents"; systemctl list-units --all --no-pager 'servernet-*' 2>/dev/null | head -40; systemctl list-timers --all --no-pager 2>/dev/null | grep -i servernet
sec "firewall persistence mechanisms"; ls -la /etc/iptables /etc/network/interfaces.d 2>/dev/null; grep -n -E 'post-up|pre-up|iptables' /etc/network/interfaces 2>/dev/null; systemctl is-enabled netfilter-persistent pve-firewall 2>/dev/null
sec "pve firewall status"; pve-firewall status 2>/dev/null
sec "storage"; pvesm status 2>/dev/null; zpool list 2>/dev/null; zpool status -x 2>/dev/null
sec "capacity"; nproc; free -g; uptime
sec "vmbr1 used IPs (static config + neighbors)"; grep -h -E 'ipconfig0|net0' /etc/pve/qemu-server/*.conf /etc/pve/lxc/*.conf 2>/dev/null | grep -o '10\.10\.10\.[0-9]*' | sort -t. -k4 -n | uniq; ip neigh show dev vmbr1 2>/dev/null | awk '{print $1, $NF}' | sort -t. -k4 -n
sec "dnsmasq / dhcp on vmbr1"; pgrep -a dnsmasq 2>/dev/null || echo "(no dnsmasq)"
echo; echo "done (read-only)"
