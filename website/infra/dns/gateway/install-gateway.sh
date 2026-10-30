#!/usr/bin/env bash
# =============================================================================
# نصبِ primaryِ پنهان + نیم‌سرورِ عمومیِ derek روی سرورِ Gateway ترکیه (155.103.69.128)
#
#   curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/website/infra/dns/gateway/install-gateway.sh | bash
#
# idempotent: هر بار اجرا همان حالت را برقرار می‌کند و رازِ موجود را عوض نمی‌کند.
# اجرای دوباره پس از ساختِ رکوردِ DNSِ dns-api.servernet.cloud بخشِ HTTPSِ API را هم می‌سازد.
#
#   primary (پنهان)  pdns@primary   127.0.0.1:5300 و IP:5300 (فایروال: فقط lo و IPِ ایران)
#                                    API روی 127.0.0.1:8081 — بیرون فقط از nginx/TLS و فقط از IPِ WHM
#   derek (عمومی)    pdns           IP:53 و 127.0.0.1:53 (systemd-resolved روی 127.0.0.53/54 دست نمی‌خورد)
#
# هیچ رازی چاپ نمی‌شود. کلیدِ API در /etc/powerdns/pdns-primary.conf (640 root:pdns) و کلیدِ
# TSIG در پایگاه‌های PowerDNS می‌مانند؛ فرمانِ دیدنشان در پایانِ خروجی آمده است.
# هیچ سرویسِ دیگری (nginx سایت‌های موجود، Marzban، بکاپ، systemd-resolved) تغییر نمی‌کند.
# =============================================================================
set -euo pipefail

PUBIP="${SN_EXPECT_IP:-155.103.69.128}"     # فقط برای تمرین عوض می‌شود
IRAN_IP="${SN_IRAN_IP:-85.9.108.118}"       # SNAT/DNATِ میزبانِ ایران
WHM_IP="${SN_WHM_IP:-65.109.176.14}"        # تنها مبدأِ مجاز برای API (برنامهٔ لاراول)
API_HOST="${SN_API_HOST:-dns-api.servernet.cloud}"
NS_A="derek.servernet.cloud"
CATALOG="catalog.servernet.invalid"
TSIG="xfr-servernet"
REF="${SN_REF:-develop}"
KIT_URL="https://raw.githubusercontent.com/servernetir/server/$REF/website/infra/dns"
KIT_DIR="${SN_KIT_DIR:-}"                    # تمرین: مسیرِ محلیِ کیت به‌جای GitHub
ETC=/etc/powerdns
LIB=/var/lib/powerdns
PCONF="$ETC/pdns-primary.conf"
DCONF="$ETC/pdns.conf"
PDB="$LIB/primary.sqlite3"
DDB="$LIB/pdns.sqlite3"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="/root/servernet-dns-backup-$STAMP"

say() { printf '\n== %s\n' "$*"; }
die() { echo "FATAL: $*" >&2; exit 1; }

# ── مقصد پیش از هر نوشتنی اثبات می‌شود ──
[ "$(id -u)" = 0 ] || die "با root اجرا کنید"
H="$(hostname -f 2>/dev/null || hostname)"
OS="$(grep -E '^PRETTY_NAME=' /etc/os-release | cut -d= -f2 | tr -d '"')"
case "$H $OS" in *core.servernet.cloud*|*AlmaLinux*|*Proxmox*) die "این سرورِ WHM/Proxmox است ($H). روی Gateway اجرا کنید.";; esac
ip -4 -o addr show | grep -qF " $PUBIP/" || die "آدرسِ $PUBIP روی این ماشین نیست — سرورِ اشتباه"
case "$OS" in *Ubuntu*|*Debian*) ;; *) die "فقط Ubuntu/Debian ($OS)";; esac

# پورت‌ها: یا آزاد، یا مالِ PowerDNSِ خودمان
busy() { ss -H -lntup "sport = :$1" 2>/dev/null | grep -v -E 'pdns_server|systemd-resolve' | grep -E "($2|0\.0\.0\.0|\*|\[::\]):$1 " || true; }
for p in 53 5300 8081; do
  b="$(busy $p "$PUBIP|127\.0\.0\.1")"
  [ -z "$b" ] || die "پورتِ $p را برنامهٔ دیگری گرفته: $b"
done
say "مقصد: $H ($OS) — $PUBIP؛ پورت‌های ۵۳/۵۳۰۰/۸۰۸۱ آزاد یا مالِ PowerDNS"

kitfile() { # kitfile <rel> <dest> <mode>
  if [ -n "$KIT_DIR" ]; then cp "$KIT_DIR/$1" "$2"; else curl -fsSL "$KIT_URL/$1" -o "$2"; fi
  chmod "$3" "$2"; sed -i 's/\r$//' "$2"
}

# ── ۱. بسته‌ها، بی‌آنکه نصب سرویسی را با پیکربندیِ پیش‌فرض بالا بیاورد ──
if ! dpkg -s pdns-server pdns-backend-sqlite3 sqlite3 >/dev/null 2>&1; then
  say "نصبِ PowerDNS"
  printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d; chmod 755 /usr/sbin/policy-rc.d
  trap 'rm -f /usr/sbin/policy-rc.d' EXIT
  export DEBIAN_FRONTEND=noninteractive
  apt-get -qq update
  apt-get -y -qq install pdns-server pdns-backend-sqlite3 sqlite3 bind9-dnsutils >/dev/null
  rm -f /usr/sbin/policy-rc.d; trap - EXIT
fi
V="$(dpkg-query -W -f='${Version}' pdns-server)"
dpkg --compare-versions "$V" ge 4.7 || die "PowerDNS $V بدونِ catalog zone"
echo "pdns-server $V"

mkdir -p "$BK"
for f in "$DCONF" "$PCONF"; do [ -f "$f" ] && cp -p "$f" "$BK/" || true; done

# ── ۲. پایگاه‌ها ──
SCHEMA=$(ls /usr/share/pdns-backend-sqlite3/schema/schema.sqlite3.sql 2>/dev/null || ls /usr/share/doc/pdns-backend-sqlite3/schema.sqlite3.sql)
install -d -o pdns -g pdns -m 750 "$LIB"
for db in "$PDB" "$DDB"; do
  if [ ! -s "$db" ] || ! sqlite3 "$db" "select 1 from domains limit 1" >/dev/null 2>&1; then
    [ -s "$db" ] && cp -p "$db" "$BK/"
    rm -f "$db"; sqlite3 "$db" < "$SCHEMA"
  fi
  chown pdns:pdns "$db"; chmod 640 "$db"
done

# ── ۳. کلیدِ API: موجود می‌مانَد، فقط بارِ اول ساخته می‌شود ──
KEY="$(grep -E '^api-key=' "$PCONF" 2>/dev/null | head -1 | cut -d= -f2- || true)"
[ -n "$KEY" ] || KEY="$(openssl rand -hex 32)"

# ── ۴. پیکربندیِ primary ──
umask 027
cat > "$PCONF.new" <<CONF
# ServerNet Managed DNS — HIDDEN PRIMARY (website/infra/dns/gateway). در NSِ هیچ zoneی نیست.
launch=gsqlite3
gsqlite3-database=$PDB
gsqlite3-dnssec=yes
socket-dir=/run/pdns-primary
version-string=anonymous
local-address=127.0.0.1:5300, $PUBIP:5300
primary=yes
xfr-cycle-interval=60
send-signed-notify=yes
# AXFR فقط با TSIG (متادیتای TSIG-ALLOW-AXFR روی هر zone)؛ فایروال هم 5300 را فقط برای lo و ایران باز می‌کند
allow-axfr-ips=
disable-axfr=no
also-notify=127.0.0.1:53, $IRAN_IP:53
only-notify=
default-soa-content=$NS_A. hostmaster.servernet.cloud. 0 3600 600 2419200 300
default-catalog-zone=$CATALOG.
api=yes
api-key=$KEY
webserver=yes
webserver-address=127.0.0.1
webserver-port=8081
webserver-allow-from=127.0.0.1/32
loglevel=4
CONF
install -o root -g pdns -m 640 "$PCONF.new" "$PCONF"; rm -f "$PCONF.new"

# ── ۵. پیکربندیِ derek (نیم‌سرورِ عمومی؛ include-dirِ پیش‌فرض و bind کنار می‌روند) ──
[ -f "$DCONF.dist" ] || { [ -f "$DCONF" ] && cp -p "$DCONF" "$DCONF.dist" || true; }
cat > "$DCONF.new" <<CONF
# ServerNet Managed DNS — PUBLIC SECONDARY derek (website/infra/dns/gateway)
launch=gsqlite3
gsqlite3-database=$DDB
gsqlite3-dnssec=yes
version-string=anonymous
# systemd-resolved روی 127.0.0.53/54 می‌مانَد؛ پس wildcard نه، دو آدرسِ مشخص
local-address=$PUBIP, 127.0.0.1
local-port=53
secondary=yes
xfr-cycle-interval=60
allow-notify-from=127.0.0.1/32, $PUBIP/32
# لازم برای pdns-tsig-reconcile: کلیدِ تازهٔ zoneِ عضو بی‌درنگ دیده شود
zone-metadata-cache-ttl=0
allow-axfr-ips=
disable-axfr=yes
api=no
webserver=no
max-tcp-connections=200
max-queue-length=5000
loglevel=4
CONF
install -o root -g pdns -m 640 "$DCONF.new" "$DCONF"; rm -f "$DCONF.new"
umask 022

PU() { pdnsutil --config-name=primary "$@"; }
DU() { pdnsutil "$@"; }

# ── ۶. TSIG: یک بار روی primary؛ derek همان کلید را محلی می‌گیرد (راز از ماشین بیرون نمی‌رود) ──
if ! PU list-tsig-keys 2>/dev/null | grep -q "^$TSIG\. "; then
  PU generate-tsig-key "$TSIG" hmac-sha256 >/dev/null
fi
SECRET="$(PU list-tsig-keys | awk -v k="$TSIG." '$1==k {print $3}')"
[ -n "$SECRET" ] || die "کلیدِ TSIG خوانده نشد"
if ! DU list-tsig-keys 2>/dev/null | awk -v k="$TSIG." -v s="$SECRET" '$1==k && $3==s {f=1} END {exit !f}'; then
  DU import-tsig-key "$TSIG" hmac-sha256 "$SECRET" >/dev/null
fi
unset SECRET

# ── ۷. catalog: تولیدکننده روی primary، مصرف‌کننده روی derek ──
if ! PU list-zone "$CATALOG" >/dev/null 2>&1; then
  PU create-zone "$CATALOG" >/dev/null
  PU set-kind "$CATALOG" producer >/dev/null
fi
PU set-meta "$CATALOG" TSIG-ALLOW-AXFR "$TSIG" >/dev/null
if ! DU list-all-zones 2>/dev/null | grep -qx "$CATALOG" && ! sqlite3 "$DDB" "select 1 from domains where name='$CATALOG'" | grep -q 1; then
  DU create-secondary-zone "$CATALOG" "127.0.0.1:5300" >/dev/null
fi
DU set-kind "$CATALOG" consumer >/dev/null
DU set-meta "$CATALOG" AXFR-MASTER-TSIG "$TSIG" >/dev/null
chown pdns:pdns "$PDB" "$DDB"

# ── ۸. اصلاحِ TSIGِ zoneهای عضو روی derek (الزامی؛ README «یافته‌های آزمایشگاه») ──
kitfile secondary/pdns-tsig-reconcile.sh /usr/local/sbin/pdns-tsig-reconcile 755
kitfile secondary/pdns-tsig-reconcile.service /etc/systemd/system/pdns-tsig-reconcile.service 644
kitfile secondary/pdns-tsig-reconcile.timer /etc/systemd/system/pdns-tsig-reconcile.timer 644

# ── ۹. فایروالِ 5300: فقط lo و میزبانِ ایران؛ زنجیرهٔ اختصاصی، بی flush ──
cat > /usr/local/sbin/servernet-dns-fw <<FW
#!/usr/bin/env bash
# فقط زنجیرهٔ SERVERNET-DNS-XFR را می‌سازد/به‌روز می‌کند؛ بقیهٔ قواعد دست نمی‌خورد. idempotent.
set -eu
C=SERVERNET-DNS-XFR
iptables -w 10 -nL \$C >/dev/null 2>&1 || iptables -w 10 -N \$C
iptables -w 10 -F \$C
iptables -w 10 -A \$C -i lo -j RETURN
iptables -w 10 -A \$C -s $IRAN_IP/32 -j RETURN
iptables -w 10 -A \$C -j DROP
for p in tcp udp; do
  iptables -w 10 -C INPUT -p \$p --dport 5300 -j \$C 2>/dev/null || iptables -w 10 -I INPUT 1 -p \$p --dport 5300 -j \$C
done
FW
chmod 755 /usr/local/sbin/servernet-dns-fw
cat > /etc/systemd/system/servernet-dns-fw.service <<'UNIT'
[Unit]
Description=ServerNet DNS: allow primary XFR port 5300 only from loopback and the Iran host
After=network-online.target
Before=pdns@primary.service
[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/local/sbin/servernet-dns-fw
[Install]
WantedBy=multi-user.target
UNIT

# ── ۱۰. راه‌اندازی ──
say "راه‌اندازی"
systemctl daemon-reload
systemctl enable --now servernet-dns-fw.service >/dev/null
systemctl enable pdns@primary >/dev/null 2>&1; systemctl restart pdns@primary
systemctl enable pdns >/dev/null 2>&1; systemctl restart pdns
systemctl enable --now pdns-tsig-reconcile.timer >/dev/null
sleep 3
for u in pdns@primary pdns servernet-dns-fw pdns-tsig-reconcile.timer; do
  st="$(systemctl is-active "$u" || true)"; echo "$u: $st"
  [ "$st" = active ] || { journalctl -u "$u" -n 20 --no-pager; die "$u بالا نیامد"; }
done

# ── ۱۱. خودآزمایی: catalog → derek، یک zoneِ آزمایشی از راهِ API تا پاسخِ AA، سپس حذف ──
say "خودآزمایی"
FAIL=0
ok() { echo "PASS  $*"; }
bad() { echo "FAIL  $*"; FAIL=$((FAIL+1)); }
API=http://127.0.0.1:8081/api/v1/servers/localhost
api() { curl -s -o /dev/null -w '%{http_code}' -H "X-API-Key: $KEY" -H 'Content-Type: application/json' -X "$1" "$API$2" ${3:+--data "$3"}; }
[ "$(api GET '')" = 200 ] && ok "API روی loopback با کلید" || bad "API پاسخ نداد"
[ "$(curl -s -o /dev/null -w '%{http_code}' "$API")" = 401 ] && ok "API بی‌کلید ۴۰۱" || bad "API بی‌کلید باز است"
pdns_control retrieve "$CATALOG" >/dev/null 2>&1 || true
T="selftest-$(head -c3 /dev/urandom | od -An -tx1 | tr -d ' \n').invalid"
r=$(api POST /zones "{\"name\":\"$T.\",\"kind\":\"Primary\",\"nameservers\":[\"$NS_A.\"],\"catalog\":\"$CATALOG.\",\"soa_edit_api\":\"DEFAULT\",\"rrsets\":[{\"name\":\"www.$T.\",\"type\":\"A\",\"ttl\":60,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"192.0.2.1\",\"disabled\":false}]}]}")
[ "$r" = 201 ] && ok "zoneِ آزمایشی روی primary" || bad "ساختِ zoneِ آزمایشی HTTP $r"
api PUT "/zones/$T./metadata/TSIG-ALLOW-AXFR" "{\"metadata\":[\"$TSIG\"]}" >/dev/null
seen=0
for _ in $(seq 1 45); do
  pdns_control retrieve "$CATALOG" >/dev/null 2>&1 || true
  /usr/local/sbin/pdns-tsig-reconcile >/dev/null 2>&1 || true
  if dig +norec +time=2 +tries=1 @"$PUBIP" "$T" SOA | grep -q 'flags: qr aa'; then seen=1; break; fi
  sleep 2
done
[ $seen = 1 ] && ok "derek روی $PUBIP:53 با AA پاسخ داد (catalog + TSIG)" || bad "zone به derek نرسید"
[ "$(dig +short +norec @"$PUBIP" "www.$T" A)" = 192.0.2.1 ] && ok "رکورد سرو شد" || bad "رکورد سرو نشد"
dig +norec +tcp @"$PUBIP" "$T" SOA | grep -q 'flags: qr aa' && ok "TCP با AA" || bad "TCP/AA نه"
dig +time=2 +tries=1 @"$PUBIP" google.com A | grep -q 'status: REFUSED' && ok "recursion نیست (REFUSED)" || bad "به نامِ بیگانه پاسخ داد"
dig +time=2 +tries=1 @"$PUBIP" "$T" AXFR | grep -q 'Transfer failed' && ok "AXFR از نیم‌سرورِ عمومی رد شد" || bad "AXFR باز است"
api DELETE "/zones/$T." >/dev/null
gone=0
for _ in $(seq 1 45); do
  pdns_control retrieve "$CATALOG" >/dev/null 2>&1 || true
  if dig +norec +time=2 +tries=1 @"$PUBIP" "$T" SOA | grep -q 'status: REFUSED'; then gone=1; break; fi
  sleep 2
done
[ $gone = 1 ] && ok "حذف از راهِ catalog (REFUSED)" || bad "zoneِ حذف‌شده هنوز سرو می‌شود"
iptables -S INPUT | grep -q 'SERVERNET-DNS-XFR' && ok "فایروالِ 5300 (lo + $IRAN_IP)" || bad "فایروالِ 5300 نیست"
if systemctl is-active --quiet systemd-resolved; then ok "systemd-resolved دست‌نخورده"; fi

# ── ۱۲. HTTPSِ API برای برنامه (فقط وقتی رکوردِ DNSِ $API_HOST به همین IP اشاره کند) ──
say "API روی HTTPS"
RES="$(dig +short @1.1.1.1 "$API_HOST" A 2>/dev/null | tail -1)"
if [ "$RES" != "$PUBIP" ]; then
  echo "SKIP  $API_HOST هنوز به $PUBIP اشاره نمی‌کند (الان: «${RES:-هیچ}»)."
  echo "      پس از ساختِ رکوردِ A ِ خاکستری در Cloudflare، همین فرمان را دوباره اجرا کنید."
elif command -v nginx >/dev/null && command -v certbot >/dev/null; then
  SITE=/etc/nginx/sites-available/servernet-dns-api
  LIVE=/etc/letsencrypt/live/$API_HOST
  if [ ! -f "$LIVE/fullchain.pem" ]; then
    mkdir -p /var/www/servernet-dns-acme
    cat > "$SITE" <<NGX
# ServerNet Managed DNS — API proxy (website/infra/dns/gateway). فقط ACME روی 80.
server {
    listen 80;
    server_name $API_HOST;
    location /.well-known/acme-challenge/ { root /var/www/servernet-dns-acme; }
    location / { return 404; }
}
NGX
    ln -sf "$SITE" /etc/nginx/sites-enabled/servernet-dns-api
    nginx -t >/dev/null 2>&1 || { rm -f /etc/nginx/sites-enabled/servernet-dns-api; die "nginx -t ناموفق؛ سایت برداشته شد"; }
    systemctl reload nginx
    certbot certonly --webroot -w /var/www/servernet-dns-acme -d "$API_HOST" -n --agree-tos --register-unsafely-without-email >/dev/null \
      || die "certbot برای $API_HOST ناموفق"
  fi
  cat > "$SITE" <<NGX
# ServerNet Managed DNS — API proxy (website/infra/dns/gateway).
# فقط مسیرِ /api/v1/ و فقط از IPِ برنامه ($WHM_IP). کلیدِ API همچنان لازم است.
# قالبِ پیش‌فرضِ لاگِ nginx هدرِ X-API-Key را نمی‌نویسد.
server {
    listen 80;
    server_name $API_HOST;
    location /.well-known/acme-challenge/ { root /var/www/servernet-dns-acme; }
    location / { return 404; }
}
server {
    listen 443 ssl;
    server_name $API_HOST;
    ssl_certificate     $LIVE/fullchain.pem;
    ssl_certificate_key $LIVE/privkey.pem;
    location /api/v1/ {
        allow $WHM_IP;
        deny all;
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host \$host;
        proxy_read_timeout 30s;
    }
    location / { return 404; }
}
NGX
  ln -sf "$SITE" /etc/nginx/sites-enabled/servernet-dns-api
  nginx -t >/dev/null 2>&1 || { rm -f /etc/nginx/sites-enabled/servernet-dns-api; systemctl reload nginx; die "nginx -t ناموفق؛ سایتِ API برداشته شد"; }
  systemctl reload nginx
  c="$(curl -s -o /dev/null -w '%{http_code}' --resolve "$API_HOST:443:127.0.0.1" "https://$API_HOST/api/v1/servers/localhost")"
  [ "$c" = 403 ] && ok "https://$API_HOST برای مبدأِ غیرِ WHM بسته است (403)" || bad "API از مبدأِ دیگر: HTTP $c (انتظار 403)"
else
  bad "nginx یا certbot نیست"
fi

echo
if [ $FAIL -eq 0 ]; then echo "INSTALL OK — پشتیبانِ پیکربندی‌های قبلی: $BK"; else echo "INSTALL WITH $FAIL FAIL(S) — خروجی را بفرستید"; fi
cat <<EOF

برای پنل (/admin/dns) — این‌ها را **روی همین سرور** ببینید، در چت نفرستید:
  کلیدِ API:      grep '^api-key=' $PCONF | cut -d= -f2
  رازِ TSIG (فقط برای نیم‌سرورِ ایران):  pdnsutil --config-name=primary list-tsig-keys
نشانیِ API در پنل: https://$API_HOST    نامِ کلیدِ TSIG: $TSIG    catalog: $CATALOG
rollback: systemctl disable --now pdns@primary pdns pdns-tsig-reconcile.timer servernet-dns-fw;
          iptables -D INPUT -p tcp --dport 5300 -j SERVERNET-DNS-XFR; iptables -D INPUT -p udp --dport 5300 -j SERVERNET-DNS-XFR;
          rm -f /etc/nginx/sites-enabled/servernet-dns-api && systemctl reload nginx;  پیکربندی‌های قبلی در $BK
EOF
[ $FAIL -eq 0 ]
