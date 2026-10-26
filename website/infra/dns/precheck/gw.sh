#!/usr/bin/env bash
# =============================================================================
# فقط‌خواندنی — پیش‌بررسیِ سرورِ Gateway در آلمان (91.107.173.226) برای primaryِ پنهان
# و نیم‌سرورِ derek. هیچ چیزی را تغییر نمی‌دهد و هیچ راز/کلیدی چاپ نمی‌کند.
#
# اجرا (با root، روی خودِ Gateway — نه WHM):
#   curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/website/infra/dns/precheck/gw.sh | bash
# =============================================================================
set -u
H="$(hostname -f 2>/dev/null || hostname)"
OS="$(grep -E '^PRETTY_NAME=' /etc/os-release 2>/dev/null | cut -d= -f2 | tr -d '"')"
case "$H $OS" in
  *core.servernet.cloud*|*AlmaLinux*) echo "STOP: this is the WHM server ($H, $OS), not Gateway. Log in to 91.107.173.226 first."; exit 1;;
esac
sec(){ printf '\n===== %s =====\n' "$1"; }
sec "host"; echo "$H"; date -u; echo "$OS"; uname -r; id -un
sec "public IPv4 seen by the internet"; curl -4 -s --max-time 8 https://ifconfig.me 2>/dev/null || echo "(unknown)"; echo
sec "listeners on :53"; ss -H -lntup 'sport = :53' 2>/dev/null || echo "(none)"
sec "5300/8081"; ss -H -lntu | grep -E ':(5300|8081)\b' || echo "(free)"
sec "all public listeners"; ss -H -lntu | awk '{print $1, $5}' | grep -v -E '127\.0\.0\.|\[::1\]' | sort -u | head -40
sec "addresses"; ip -br addr
sec "resolved stub"; systemctl is-active systemd-resolved 2>/dev/null; grep -E '^DNSStubListener' /etc/systemd/resolved.conf 2>/dev/null
sec "firewall"; ufw status 2>/dev/null | head -5; iptables -S INPUT 2>/dev/null | head -20; nft list tables 2>/dev/null
sec "nginx"; nginx -v 2>&1; ls /etc/nginx/sites-enabled 2>/dev/null
sec "certbot"; certbot certificates 2>/dev/null | grep -E 'Certificate Name|Domains|Expiry' | head -12
sec "pdns package"; apt-cache policy pdns-server 2>/dev/null | head -4
sec "docker containers"; docker ps --format '{{.Names}} {{.Ports}}' 2>/dev/null | head -10
sec "capacity"; nproc; free -m; df -h / | tail -1; uptime
echo; echo "done (read-only)"
