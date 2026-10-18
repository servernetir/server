#!/usr/bin/env bash
# =============================================================================
# آزمایشگاهِ محلیِ PowerDNS — فقط برای آزمونِ واقعی، هرگز روی تولید اجرا نشود.
#
# سه نمونهٔ مستقل روی یک ماشین (WSL/Debian/Ubuntu):
#   primary  127.0.0.1:5300  + API 8081   (gsqlite3، تولیدکنندهٔ catalog)
#   sec1     0.0.0.0:5301    (مصرف‌کنندهٔ catalog، AXFR با TSIG)
#   sec2     0.0.0.0:5302    (همان)
#
# همان معماریِ تولید: پنل فقط با APIِ primary حرف می‌زند؛ نیم‌سرورهای عمومی
# secondary هستند و zoneها را با catalog + NOTIFY + AXFRِ امضاشده می‌گیرند.
#
# راز‌ها (کلیدِ API و TSIG) فقط در /root/pdns-lab/secrets می‌مانند؛ این اسکریپت
# آن‌ها را چاپ نمی‌کند و در مخزن هم نیست.
# =============================================================================
set -euo pipefail

[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 1; }
case "$(hostname)" in *servernet*|ir|core|whm*) echo "refusing: looks like a server ($(hostname))" >&2; exit 1;; esac

LAB=/root/pdns-lab
CATALOG=catalog.invalid
mkdir -p "$LAB"/{run,db,conf,secrets}
chmod 700 "$LAB/secrets"

# ── راز‌ها: یک بار ساخته می‌شوند و در اجراهای بعدی دوباره استفاده می‌شوند ──
[ -s "$LAB/secrets/api-key" ] || head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$LAB/secrets/api-key"
API_KEY=$(cat "$LAB/secrets/api-key")

stop_all() {
  for n in primary sec1 sec2; do
    if [ -f "$LAB/run/$n.pid" ]; then kill "$(cat "$LAB/run/$n.pid")" 2>/dev/null || true; rm -f "$LAB/run/$n.pid"; fi
  done
  sleep 1
}

[ "${1:-}" = "down" ] && { stop_all; echo "lab stopped"; exit 0; }
[ "${1:-}" = "reset" ] && { stop_all; rm -rf "$LAB/db" "$LAB/conf"; mkdir -p "$LAB/db" "$LAB/conf"; }

stop_all

SCHEMA=$(ls /usr/share/pdns-backend-sqlite3/schema/schema.sqlite3.sql 2>/dev/null || ls /usr/share/doc/pdns-backend-sqlite3/schema.sqlite3.sql)

for n in primary sec1 sec2; do
  [ -s "$LAB/db/$n.sqlite3" ] || sqlite3 "$LAB/db/$n.sqlite3" < "$SCHEMA"
done

common() {
  cat <<EOF
launch=gsqlite3
gsqlite3-database=$LAB/db/$1.sqlite3
gsqlite3-dnssec=yes
socket-dir=$LAB/run/$1
version-string=anonymous
loglevel=4
log-dns-queries=no
# فقط authoritative — هیچ recursion یا forwardی وجود ندارد
EOF
}

mkdir -p "$LAB/run/primary" "$LAB/run/sec1" "$LAB/run/sec2"

{ common primary; cat <<EOF
local-address=127.0.0.1:5300
primary=yes
# آزمایشگاه: چرخهٔ سریع تا افزایشِ serialِ catalog منتظرِ ۶۰ ثانیهٔ پیش‌فرض نماند
xfr-cycle-interval=5
api=yes
api-key=$API_KEY
webserver=yes
webserver-address=127.0.0.1
webserver-port=8081
webserver-allow-from=127.0.0.0/8
# AXFR فقط با TSIG (متادیتای TSIG-ALLOW-AXFR روی هر zone)؛ هیچ IPی بی‌امضا مجاز نیست
allow-axfr-ips=
also-notify=127.0.0.1:5301,127.0.0.1:5302
only-notify=
default-soa-content=derek.lab.test. hostmaster.lab.test. 0 3600 600 2419200 300
EOF
} > "$LAB/conf/pdns-primary.conf"

for i in 1 2; do
  { common "sec$i"; cat <<EOF
local-address=0.0.0.0:530$i
secondary=yes
xfr-cycle-interval=5
# 🔴 بدونِ این، کلیدِ TSIGی که pdns-tsig-reconcile روی zoneِ تازه می‌گذارد تا ۶۰ ثانیه
# از کش دیده نمی‌شود و انتقال‌های بعدی هم بی‌امضا می‌روند (آزموده روی 5.0.2)
zone-metadata-cache-ttl=0
allow-notify-from=127.0.0.0/8
webserver=yes
webserver-address=127.0.0.1
webserver-port=808$((i+1))
webserver-allow-from=127.0.0.0/8
api=yes
api-key=$API_KEY
EOF
  } > "$LAB/conf/pdns-sec$i.conf"
done

start() {
  pdns_server --config-dir="$LAB/conf" --config-name="$1" --daemon=yes --guardian=no --write-pid=yes \
    --socket-dir="$LAB/run/$1" >/dev/null 2>&1
  for _ in $(seq 1 30); do [ -f "$LAB/run/$1/pdns-$1.pid" ] && break; sleep 0.2; done
  cp "$LAB/run/$1/pdns-$1.pid" "$LAB/run/$1.pid" 2>/dev/null || pgrep -f "config-name=$1" | head -1 > "$LAB/run/$1.pid"
}

pu() { pdnsutil --config-dir="$LAB/conf" --config-name="$1" "${@:2}"; }

# ── TSIG: یک کلید برای انتقال؛ روی هر سه نصب می‌شود ──
if [ ! -s "$LAB/secrets/tsig-secret" ]; then
  head -c 32 /dev/urandom | base64 > "$LAB/secrets/tsig-secret"
fi
TSIG=$(cat "$LAB/secrets/tsig-secret")
for n in primary sec1 sec2; do
  pu "$n" import-tsig-key xfr-lab hmac-sha256 "$TSIG" >/dev/null 2>&1 || true
done

# ── catalog: تولیدکننده روی primary، مصرف‌کننده روی دو secondary ──
if ! pu primary list-zone "$CATALOG" >/dev/null 2>&1; then
  pu primary create-zone "$CATALOG" >/dev/null
  pu primary set-kind "$CATALOG" producer >/dev/null
  pu primary set-meta "$CATALOG" TSIG-ALLOW-AXFR xfr-lab >/dev/null
fi
for n in sec1 sec2; do
  if ! pu "$n" list-zone "$CATALOG" >/dev/null 2>&1; then
    pu "$n" create-secondary-zone "$CATALOG" 127.0.0.1:5300 >/dev/null
    pu "$n" set-kind "$CATALOG" consumer >/dev/null
    pu "$n" set-meta "$CATALOG" AXFR-MASTER-TSIG xfr-lab >/dev/null
  fi
done

start primary
start sec1
start sec2
sleep 1

for n in primary:5300 sec1:5301 sec2:5302; do
  port=${n#*:}
  if dig +time=2 +tries=1 @127.0.0.1 -p "$port" "$CATALOG" SOA +short >/dev/null 2>&1; then echo "up   ${n%%:*} :$port"; else echo "DOWN ${n%%:*} :$port"; fi
done
curl -s -o /dev/null -w "api  primary :8081 HTTP %{http_code}\n" -H "X-API-Key: $API_KEY" http://127.0.0.1:8081/api/v1/servers/localhost
