#!/usr/bin/env bash
# =============================================================================
# وصل‌کردنِ نیم‌سرورِ ایران (linda — VMِ 10.10.10.200) به primary روی Gateway و
# بازکردنِ پورتِ ۵۳ِ 85.9.108.118 به آن. روی **میزبانِ Proxmoxِ ایران (ir)** با root:
#
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/website/infra/dns/iran/connect-linda.sh)
#
# (با `bash <(…)`، نه `curl | bash` — رازِ TSIG از ورودیِ ترمینال خوانده می‌شود.)
#
# ۱) رازِ TSIG را پنهان می‌پرسد (نمایش داده نمی‌شود، در history نمی‌نشیند) و از راهِ stdinِ
#    SSH به VM می‌دهد — روی هیچ خطِ فرمانی نمی‌آید.
# ۲) VM: allow-notify-from = IPِ primary، کلیدِ TSIG، catalogِ مصرف‌کننده، راه‌اندازیِ دوباره،
#    و صبر تا catalog با AA برسد.
# ۳) میزبان: servernet-dns-dnat (زنجیره‌های اختصاصی، فقط مقصدِ دقیق و پورتِ ۵۳، سقفِ نرخِ raw)
#    + env + واحد و timerِ ماندگار. هیچ flush/restoreِ سراسری و هیچ restartِ شبکه‌ای نیست.
#
# idempotent است. rollback در پایانِ خروجی چاپ می‌شود.
# =============================================================================
set -euo pipefail

VM_IP="${SN_VM_IP:-10.10.10.200}"
PRIMARY_IP="${SN_PRIMARY_IP:-91.107.173.226}"
PUBLIC_IP="${SN_PUBLIC_IP:-85.9.108.118}"
KEYFILE=/root/.ssh/sn-dns
CATALOG="catalog.servernet.invalid"
TSIG="xfr-servernet"
REF="${SN_REF:-develop}"
KIT_URL="https://raw.githubusercontent.com/servernetir/server/$REF/website/infra/dns"

die() { echo "FATAL: $*" >&2; exit 1; }
say() { printf '\n== %s\n' "$*"; }

[ "$(id -u)" = 0 ] || die "با root اجرا کنید"
[ "$(hostname -s)" = ir ] || die "این میزبانِ ایران (ir) نیست: $(hostname -s)"
[ -f "$KEYFILE" ] || die "کلیدِ SSHِ $KEYFILE نیست (VM با بلوکِ ساخت ساخته شده بود؟)"
VMSSH=(ssh -o BatchMode=yes -o ConnectTimeout=5 -i "$KEYFILE" "root@$VM_IP")
"${VMSSH[@]}" 'systemctl is-active --quiet pdns' || die "PowerDNS روی VMِ $VM_IP فعال نیست"
if [ -f /etc/servernet/split.state ] && grep -q "^$VM_IP " /etc/servernet/split.state; then
  die "$VM_IP در مسیریابیِ کشوری است — پاسخ‌ها از .118 برنمی‌گردند"
fi
say "مقصد: میزبانِ ir، VMِ $VM_IP، primary $PRIMARY_IP، عمومی $PUBLIC_IP"

# ── ۱. راز ──
echo
echo "رازِ TSIG را از Gateway بیاورید. روی Gateway بزنید:"
echo "    pdnsutil --config-name=primary list-tsig-keys | awk '\$1==\"$TSIG.\" {print \$3}'"
echo "خروجی (یک رشتهٔ طولانی) را کپی کنید و این‌جا paste کنید (نمایش داده نمی‌شود)، سپس Enter:"
IFS= read -rs SECRET < /dev/tty
echo
SECRET="$(printf '%s' "$SECRET" | tr -d '[:space:]')"
[[ "$SECRET" =~ ^[A-Za-z0-9+/]{40,}={0,2}$ ]] || die "این شبیهِ رازِ TSIG نیست (base64، دست‌کم ۴۰ نویسه)"

# ── ۲. VM ──
say "VM: اتصال به primary"
{ printf '%s\n' "$SECRET"; cat <<VMEOF
set -euo pipefail
C=/etc/powerdns/pdns.conf
sed -i "s#^allow-notify-from=.*#allow-notify-from=$PRIMARY_IP/32#" "\$C"
grep -qx "allow-notify-from=$PRIMARY_IP/32" "\$C" || { echo "FATAL: allow-notify-from نشست"; exit 1; }
if pdnsutil list-tsig-keys 2>/dev/null | awk -v k="$TSIG." -v s="\$S" '\$1==k && \$3==s {f=1} END {exit !f}'; then
  echo "TSIG: از قبل همین کلید"
else
  pdnsutil import-tsig-key "$TSIG" hmac-sha256 "\$S" >/dev/null && echo "TSIG: وارد شد"
fi
unset S
if ! sqlite3 /var/lib/powerdns/pdns.sqlite3 "select 1 from domains where name='$CATALOG'" | grep -q 1; then
  pdnsutil create-secondary-zone "$CATALOG" "$PRIMARY_IP:5300" >/dev/null
fi
pdnsutil set-kind "$CATALOG" consumer >/dev/null
pdnsutil set-meta "$CATALOG" AXFR-MASTER-TSIG "$TSIG" >/dev/null
chown pdns:pdns /var/lib/powerdns/pdns.sqlite3
systemctl restart pdns
sleep 2
systemctl is-active --quiet pdns || { journalctl -u pdns -n 20 --no-pager; exit 1; }
for i in \$(seq 1 45); do
  pdns_control retrieve "$CATALOG" >/dev/null 2>&1 || true
  /usr/local/sbin/pdns-tsig-reconcile >/dev/null 2>&1 || true
  if dig +norec +time=2 +tries=1 @$VM_IP "$CATALOG" SOA | grep -q 'flags: qr aa'; then
    echo "PASS  catalog از primary رسید: serial \$(dig +short +norec @$VM_IP "$CATALOG" SOA | awk '{print \$3}')"
    exit 0
  fi
  sleep 2
done
echo "FAIL  catalog نرسید — آخرین لاگ:"; journalctl -u pdns -n 15 --no-pager; exit 1
VMEOF
} | "${VMSSH[@]}" 'IFS= read -r S && export S && exec bash -s'
unset SECRET

# ── ۳. DNATِ میزبان ──
say "میزبان: هدایتِ $PUBLIC_IP:53 → $VM_IP:53"
curl -fsSL "$KIT_URL/iran/servernet-dns-dnat.sh" -o /usr/local/sbin/servernet-dns-dnat.sh
curl -fsSL "$KIT_URL/iran/servernet-dns-dnat.service" -o /etc/systemd/system/servernet-dns-dnat.service
curl -fsSL "$KIT_URL/iran/servernet-dns-dnat.timer" -o /etc/systemd/system/servernet-dns-dnat.timer
sed -i 's/\r$//' /usr/local/sbin/servernet-dns-dnat.sh /etc/systemd/system/servernet-dns-dnat.service /etc/systemd/system/servernet-dns-dnat.timer
chmod 755 /usr/local/sbin/servernet-dns-dnat.sh
install -d -m 755 /etc/servernet
printf 'DNS_VM_IP=%s\nPUBLIC_IP=%s\n' "$VM_IP" "$PUBLIC_IP" > /etc/servernet/dns-dnat.env
chmod 600 /etc/servernet/dns-dnat.env

echo "-- پیش‌نمایش (DRY-RUN):"
APPLY=0 DNS_VM_IP="$VM_IP" PUBLIC_IP="$PUBLIC_IP" /usr/local/sbin/servernet-dns-dnat.sh | tail -3
systemctl daemon-reload
systemctl enable --now servernet-dns-dnat.timer >/dev/null 2>&1
systemctl start servernet-dns-dnat.service
systemctl is-active --quiet servernet-dns-dnat.timer || die "timer فعال نشد"

FAIL=0
chk() { if eval "$2"; then echo "PASS  $1"; else echo "FAIL  $1"; FAIL=$((FAIL+1)); fi; }
chk "nat: PREROUTING → SERVERNET-DNS برای $PUBLIC_IP:53 (udp+tcp)" \
  "[ \$(iptables -t nat -S PREROUTING | grep -c -- '-d $PUBLIC_IP/32 .*--dport 53 .*-j SERVERNET-DNS') = 2 ]"
chk "nat: مقصد $VM_IP:53" "iptables -t nat -S SERVERNET-DNS | grep -q -- '--to-destination $VM_IP:53'"
chk "raw: سقفِ نرخِ UDP (حفاظتِ conntrack)" "iptables -t raw -S SERVERNET-DNS-RAW | grep -q hashlimit"
chk "FORWARD: فقط ۵۳ به $VM_IP" "iptables -S SERVERNET-DNS-FWD | grep -q -- '-d $VM_IP/32'"
chk "ماندگاری: timer هر دقیقه" "systemctl is-enabled --quiet servernet-dns-dnat.timer"
chk "قواعدِ دیگران سر جایشان (SERVERNET-PF و egress-snat)" \
  "iptables -t nat -S | grep -q 'SERVERNET-PF' && iptables -t nat -S POSTROUTING | grep -q servernet-egress-snat"

echo
if [ $FAIL -eq 0 ]; then echo "CONNECT OK"; else echo "CONNECT WITH $FAIL FAIL(S) — خروجی را بفرستید"; fi
cat <<EOF

آزمونِ بیرونی را من از خارجِ شبکه انجام می‌دهم (از خودِ میزبان، هدایتِ .118 دیده نمی‌شود).
rollback: systemctl disable --now servernet-dns-dnat.timer; /usr/local/sbin/servernet-dns-dnat.sh remove
          (فقط زنجیره‌های خودش؛ VM و PowerDNS دست نمی‌خورند)
EOF
[ $FAIL -eq 0 ]
