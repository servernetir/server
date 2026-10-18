#!/usr/bin/env bash
# آزمونِ پذیرشِ آزمایشگاه — روی PowerDNSِ واقعی (lab-up.sh) و reconcilerِ واقعی:
# انتشارِ zoneِ تازه با catalog+TSIG، AA/UDP/TCP، به‌روزرسانی، قطع و برگشتِ یک نود،
# حذف از طریقِ catalog. پیش‌نیاز: /root/pdns-tsig-reconcile.sh = infra/dns/secondary/pdns-tsig-reconcile.sh
set -u
K=$(cat /root/pdns-lab/secrets/api-key); A=http://127.0.0.1:8081/api/v1/servers/localhost; H="X-API-Key: $K"
fix(){ for n in 1 2; do PDNS_SQLITE_DB=/root/pdns-lab/db/sec$n.sqlite3 PDNS_XFR_TSIG_KEY=xfr-lab PDNS_CONFIG_DIR=/root/pdns-lab/conf PDNS_CONFIG_NAME=sec$n PDNS_SOCKET_DIR=/root/pdns-lab/run/sec$n /root/pdns-tsig-reconcile.sh; done; }
q(){ dig +short +norec @127.0.0.1 -p $1 $2 $3 | sort | tr '\n' ' '; }
curl -s -o /dev/null -w "create %{http_code}\n" -X POST -H "$H" -H 'Content-Type: application/json' $A/zones -d '{"name":"probe2.test.","kind":"Primary","catalog":"catalog.invalid.","nameservers":["derek.lab.test.","linda.lab.test."],"soa_edit_api":"DEFAULT","rrsets":[{"name":"www.probe2.test.","type":"A","ttl":300,"changetype":"REPLACE","records":[{"content":"192.0.2.10","disabled":false}]}]}'
curl -s -o /dev/null -w "meta %{http_code}\n" -X POST -H "$H" -H 'Content-Type: application/json' $A/zones/probe2.test./metadata -d '{"kind":"TSIG-ALLOW-AXFR","metadata":["xfr-lab"]}'
t0=$(date +%s)
for i in $(seq 1 60); do fix >/dev/null; a=$(q 5301 www.probe2.test A); b=$(q 5302 www.probe2.test A); [ -n "$a" ] && [ -n "$b" ] && break; sleep 1; done
echo "propagated to both secondaries in $(( $(date +%s)-t0 ))s: s1=[$a] s2=[$b]"
echo "AA flag: $(dig +norec @127.0.0.1 -p 5301 www.probe2.test A | grep -o 'flags: [a-z ]*')"
echo "TCP: $(dig +tcp +short +norec @127.0.0.1 -p 5302 www.probe2.test A)"
curl -s -o /dev/null -w "patch %{http_code}\n" -X PATCH -H "$H" -H 'Content-Type: application/json' $A/zones/probe2.test. -d '{"rrsets":[{"name":"www.probe2.test.","type":"A","ttl":300,"changetype":"REPLACE","records":[{"content":"192.0.2.10","disabled":false},{"content":"192.0.2.11","disabled":false}]}]}'
t0=$(date +%s); for i in $(seq 1 60); do a=$(q 5301 www.probe2.test A); b=$(q 5302 www.probe2.test A); [ "$a" = "192.0.2.10 192.0.2.11 " ] && [ "$a" = "$b" ] && break; sleep 1; done
echo "update on both in $(( $(date +%s)-t0 ))s: [$a] serials $(q 5300 probe2.test SOA | awk '{print $3}')/$(q 5301 probe2.test SOA | awk '{print $3}')/$(q 5302 probe2.test SOA | awk '{print $3}')"
echo "== outage: stop sec2, change, restart"
kill $(cat /root/pdns-lab/run/sec2.pid); sleep 1
curl -s -o /dev/null -w "patch %{http_code}\n" -X PATCH -H "$H" -H 'Content-Type: application/json' $A/zones/probe2.test. -d '{"rrsets":[{"name":"new.probe2.test.","type":"TXT","ttl":300,"changetype":"REPLACE","records":[{"content":"\"after-outage\"","disabled":false}]}]}'
sleep 3; echo "sec1 during sec2 outage: $(q 5301 new.probe2.test TXT)"
pdns_server --config-dir=/root/pdns-lab/conf --config-name=sec2 --daemon=yes --guardian=no --write-pid=yes --socket-dir=/root/pdns-lab/run/sec2 >/dev/null 2>&1; sleep 1; cp /root/pdns-lab/run/sec2/pdns-sec2.pid /root/pdns-lab/run/sec2.pid 2>/dev/null
t0=$(date +%s); for i in $(seq 1 90); do b=$(q 5302 new.probe2.test TXT); [ -n "$b" ] && break; sleep 1; done
echo "sec2 caught up after restart in $(( $(date +%s)-t0 ))s: $b"
echo "== delete via API"
curl -s -o /dev/null -w "delete %{http_code}\n" -X DELETE -H "$H" $A/zones/probe2.test.
t0=$(date +%s); for i in $(seq 1 90); do s1=$(dig +norec @127.0.0.1 -p 5301 www.probe2.test A | grep -o 'status: [A-Z]*'); s2=$(dig +norec @127.0.0.1 -p 5302 www.probe2.test A | grep -o 'status: [A-Z]*'); [ "$s1" = "status: REFUSED" ] && [ "$s2" = "status: REFUSED" ] && break; sleep 1; done
echo "removed from both in $(( $(date +%s)-t0 ))s ($s1 / $s2); sec1 zones: $(pdnsutil --config-dir=/root/pdns-lab/conf --config-name=sec1 list-all-zones 2>/dev/null | tr '\n' ' ')"
