#!/usr/bin/env bash
# =============================================================================
# آزمونِ هم‌کاریِ نسخه‌ها — primaryِ آزمایشگاه (5.0.x) → secondaryِ Debian 13 (4.9.x)
#
# چرا: نیم‌سرورِ ایران روی Debian 13 بسته‌ی 4.9.17 را دارد، ولی همهٔ آزمون‌های
# آزمایشگاه روی 5.0.2 بودند. این اسکریپت همان بسته را در یک کانتینرِ debian:trixie
# (شبکهٔ host، پورتِ 5303) بالا می‌آورد، با همان پیکربندیِ secondaryِ تولید
# (catalog consumer + TSIG + pdns-tsig-reconcile) و چرخهٔ کامل را می‌سنجد:
# عضویت در catalog ← انتقالِ امضاشده ← پاسخِ AA با serialِ primary ← تغییرِ رکورد
# ← حذف از catalog ← REFUSED.
#
# پیش‌نیاز: lab-up.sh اجرا شده باشد (primary روی 127.0.0.1:5300). فقط آزمایشگاه.
# =============================================================================
set -euo pipefail
[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 1; }
case "$(hostname)" in *servernet*|ir|core|whm*) echo "refusing: looks like a server" >&2; exit 1;; esac

LAB=/root/pdns-lab
CATALOG=catalog.invalid
KIT=$(cd "$(dirname "$0")/.." && pwd)
TSIG=$(cat "$LAB/secrets/tsig-secret")
C=pdns49
ZONE="i49-$(head -c3 /dev/urandom | od -An -tx1 | tr -d ' \n').test"
ok() { echo "PASS  $*"; }
bad() { echo "FAIL  $*"; FAILS=$((FAILS + 1)); }
FAILS=0

docker rm -f "$C" >/dev/null 2>&1 || true
docker run -d --name "$C" --network host -v "$KIT:/kit:ro" debian:trixie sleep infinity >/dev/null
docker exec "$C" bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get -qq update && apt-get -y -qq install pdns-server pdns-backend-sqlite3 sqlite3 dnsutils >/dev/null'
V=$(docker exec "$C" dpkg-query -W -f='${Version}' pdns-server)
echo "secondary: pdns-server $V (Debian 13)"

docker exec -e TSIG="$TSIG" -e CATALOG="$CATALOG" "$C" bash -c '
set -euo pipefail
S=$(ls /usr/share/pdns-backend-sqlite3/schema/schema.sqlite3.sql 2>/dev/null || ls /usr/share/doc/pdns-backend-sqlite3/schema.sqlite3.sql)
install -d -o pdns -g pdns /var/lib/powerdns /run/pdns
rm -f /var/lib/powerdns/pdns.sqlite3; sqlite3 /var/lib/powerdns/pdns.sqlite3 < "$S"; chown pdns:pdns /var/lib/powerdns/pdns.sqlite3
cat > /etc/powerdns/pdns.conf <<CONF
launch=gsqlite3
gsqlite3-database=/var/lib/powerdns/pdns.sqlite3
gsqlite3-dnssec=yes
version-string=anonymous
local-address=127.0.0.1
local-port=5303
secondary=yes
xfr-cycle-interval=5
allow-notify-from=127.0.0.0/8
zone-metadata-cache-ttl=0
allow-axfr-ips=
disable-axfr=yes
api=no
webserver=no
setuid=pdns
setgid=pdns
CONF
pdnsutil import-tsig-key xfr-lab hmac-sha256 "$TSIG" >/dev/null
pdnsutil create-secondary-zone "$CATALOG" 127.0.0.1:5300 >/dev/null
pdnsutil set-kind "$CATALOG" consumer >/dev/null
pdnsutil set-meta "$CATALOG" AXFR-MASTER-TSIG xfr-lab >/dev/null
pdns_server --daemon=yes --guardian=no >/dev/null 2>&1
sleep 2
'

q() { dig +norec +time=2 +tries=1 @127.0.0.1 -p 5303 "$@"; }
reconcile() { docker exec -e PDNS_XFR_TSIG_KEY=xfr-lab "$C" bash /kit/secondary/pdns-tsig-reconcile.sh >/dev/null; }
retrieve() { docker exec "$C" pdns_control retrieve "$1" >/dev/null 2>&1 || true; }
wait_for() { # $1 = آزمونِ bash که باید درست شود، تا ۶۰ ثانیه
  for _ in $(seq 1 30); do retrieve "$CATALOG"; reconcile; eval "$1" && return 0; sleep 2; done; return 1; }

# ── ۱. zone روی primary با همان APIی که برنامه صدا می‌زند (pdnsutil کشِ zoneِ سرورِ در حالِ اجرا را به‌روز نمی‌کند) ──
API=http://127.0.0.1:8081/api/v1/servers/localhost
KEYH="X-API-Key: $(cat "$LAB/secrets/api-key")"
api() { curl -s -o /dev/null -w '%{http_code}' -H "$KEYH" -H 'Content-Type: application/json' -X "$1" "$API$2" ${3:+--data "$3"}; }
r=$(api POST /zones "{\"name\":\"$ZONE.\",\"kind\":\"Primary\",\"nameservers\":[\"derek.lab.test.\",\"linda.lab.test.\"],\"catalog\":\"$CATALOG.\",\"soa_edit_api\":\"DEFAULT\",\"rrsets\":[{\"name\":\"www.$ZONE.\",\"type\":\"A\",\"ttl\":300,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"185.10.20.30\",\"disabled\":false}]}]}")
[ "$r" = 201 ] && ok "zone created on primary via API" || bad "create zone HTTP $r"
r=$(api PUT "/zones/$ZONE./metadata/TSIG-ALLOW-AXFR" '{"metadata":["xfr-lab"]}')
[ "$r" = 200 ] && ok "TSIG-ALLOW-AXFR set" || bad "metadata HTTP $r"
PSERIAL=$(dig +short @127.0.0.1 -p 5300 "$ZONE" SOA | awk '{print $3}')

if wait_for "q $ZONE SOA | grep -q 'flags: qr aa'"; then ok "member zone transferred (catalog + TSIG) and answered with AA"; else bad "member zone never appeared on the 4.9 secondary"; docker exec "$C" pdnsutil list-all-zones 2>&1 | head; fi
SSERIAL=$(q +short "$ZONE" SOA | awk '{print $3}')
[ -n "$PSERIAL" ] && [ "$SSERIAL" = "$PSERIAL" ] && ok "serial matches primary ($SSERIAL)" || bad "serial '$SSERIAL' != primary '$PSERIAL'"
[ "$(q +short www."$ZONE" A)" = "185.10.20.30" ] && ok "record served" || bad "record not served"
q +tcp "$ZONE" SOA | grep -q 'flags: qr aa' && ok "TCP answer with AA" || bad "no TCP/AA answer"
q google.com A | grep -q 'status: REFUSED' && ok "no recursion (REFUSED)" || bad "answered a foreign name"
dig +time=2 +tries=1 @127.0.0.1 -p 5303 "$ZONE" AXFR | grep -q 'Transfer failed' && ok "AXFR from the public secondary refused" || bad "secondary hands out AXFR"

# ── ۲. PATCHِ رکورد (soa_edit_api serial را بالا می‌برد) → secondary به‌روز ──
r=$(api PATCH "/zones/$ZONE." "{\"rrsets\":[{\"name\":\"www.$ZONE.\",\"type\":\"A\",\"ttl\":300,\"changetype\":\"REPLACE\",\"records\":[{\"content\":\"185.10.20.31\",\"disabled\":false}]}]}")
[ "$r" = 204 ] || bad "patch HTTP $r"
retrieve "$ZONE"
if wait_for "[ \"\$(q +short www.$ZONE A)\" = 185.10.20.31 ]"; then ok "update propagated"; else bad "update not propagated"; fi

# ── ۳. حذف از primary → catalog → secondary دیگر authoritative نیست ──
r=$(api DELETE "/zones/$ZONE.")
[ "$r" = 204 ] || bad "delete HTTP $r"
if wait_for "q $ZONE SOA | grep -q 'status: REFUSED'"; then ok "deleted zone removed via catalog (REFUSED)"; else bad "deleted zone still served"; fi

docker rm -f "$C" >/dev/null 2>&1 || true
echo "result: $([ $FAILS -eq 0 ] && echo ALL PASS || echo "$FAILS FAIL") (primary $(pdns_server --version 2>&1 | grep -o 'Authoritative Server [0-9.]*' | head -1), secondary $V)"
[ $FAILS -eq 0 ]
