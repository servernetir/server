#!/usr/bin/env bash
# =============================================================================
# فقط‌خواندنی — آیا روی سرورِ WHMِ آلمان (core.servernet.cloud) می‌شود یک PowerDNSِ
# مستقل کنارِ PowerDNSِ cPanel اجرا کرد، بی‌آنکه DNSِ cPanel بازپیکربندی شود؟
#
# به‌عنوانِ root اجرا و خروجی فرستاده شود. هیچ چیزی را تغییر نمی‌دهد و محتوای
# pdns.conf را چاپ نمی‌کند (ممکن است کلید داشته باشد) — فقط کلیدهای مرتبط با bind.
# =============================================================================
set -u
case "$(hostname -f 2>/dev/null || hostname)" in *servernet.cloud*) ;; *) echo "not core.servernet.cloud — stopping"; exit 1;; esac
sec(){ printf '\n===== %s =====\n' "$1"; }

sec "time / OS"; date -u; cat /etc/redhat-release 2>/dev/null; uname -r
sec "listeners on :53 (does cPanel DNS bind 0.0.0.0 / :: or specific IPs?)"; ss -H -lntup 'sport = :53' 2>/dev/null
sec "other listeners on 5300/8081/8443 (candidate ports for a hidden primary)"; ss -H -lntu | grep -E ':(5300|8081|8443)\b' || echo "(free)"
sec "cPanel nameserver software"; grep -i -E '^(local|nameserver)' /var/cpanel/cpanel.config 2>/dev/null | head; ls /etc/pdns 2>/dev/null
sec "cPanel pdns bind-related settings only"; grep -E '^(local-address|local-ipv6|local-port|launch|api|webserver)' /etc/pdns/pdns.conf 2>/dev/null | sed 's/\(api-key\s*=\).*/\1***redacted***/'
sec "IP addresses on this server"; ip -br addr
sec "firewalld"; firewall-cmd --state 2>/dev/null; firewall-cmd --list-all 2>/dev/null | head -30
sec "capacity"; nproc; free -g; df -h / | tail -1
echo; echo "done (read-only)"
