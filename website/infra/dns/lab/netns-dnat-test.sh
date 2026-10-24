#!/usr/bin/env bash
# آزمونِ ایزولهٔ servernet-dns-dnat.sh در network namespaceهای WSL — هیچ میزبانِ واقعی‌ای لمس نمی‌شود.
#   pub  (198.51.100.2)  ──  host (pubif 198.51.100.1 | lanif 10.10.10.1)  ──  vm (10.10.10.53, پاسخ‌گوی DNS)
set -uo pipefail
SCRIPT=${1:-/root/servernet-dns-dnat.sh}
cleanup(){ for n in pub host vm; do ip netns del "sn-$n" 2>/dev/null; done; }
cleanup
for n in pub host vm; do ip netns add "sn-$n"; ip -n "sn-$n" link set lo up; done
ip link add pubh type veth peer name pubc; ip link set pubh netns sn-host; ip link set pubc netns sn-pub
ip link add lanh type veth peer name lanv; ip link set lanh netns sn-host; ip link set lanv netns sn-vm
ip -n sn-host addr add 198.51.100.1/24 dev pubh; ip -n sn-host link set pubh up
ip -n sn-host addr add 10.10.10.1/24 dev lanh;  ip -n sn-host link set lanh up
ip -n sn-pub addr add 198.51.100.2/24 dev pubc;  ip -n sn-pub link set pubc up
ip -n sn-vm addr add 10.10.10.53/24 dev lanv;    ip -n sn-vm link set lanv up
ip -n sn-vm route add default via 10.10.10.1
ip netns exec sn-host sysctl -qw net.ipv4.ip_forward=1
# مثلِ میزبانِ واقعی: خروجیِ مهمان‌ها SNAT به IPِ عمومی
ip netns exec sn-host iptables -t nat -A POSTROUTING -s 10.10.10.0/24 -o pubh -j MASQUERADE

# پاسخ‌گوی کوچکِ DNS در vm: id را برمی‌گرداند و یک A ثابت می‌دهد (UDP و TCP)
cat > /tmp/sn-dns.py <<'PY'
import socket, struct, threading
def answer(q):
    i = q[:2]; rest = q[12:]; end = rest.index(b'\0') + 5
    hdr = i + b'\x84\x00' + struct.pack('>HHHH', 1, 1, 0, 0)
    return hdr + rest[:end] + b'\xc0\x0c' + struct.pack('>HHIH', 1, 1, 60, 4) + bytes([192,0,2,53])
def udp():
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.bind(('0.0.0.0', 53))
    while True:
        d, a = s.recvfrom(4096); s.sendto(answer(d), a)
def tcp():
    s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); s.bind(('0.0.0.0', 53)); s.listen(8)
    while True:
        c, _ = s.accept(); n = struct.unpack('>H', c.recv(2))[0]; r = answer(c.recv(n)); c.sendall(struct.pack('>H', len(r)) + r); c.close()
threading.Thread(target=tcp, daemon=True).start(); udp()
PY
ip netns exec sn-vm python3 /tmp/sn-dns.py & SRV=$!
sleep 1

pass=0; fail=0
check(){ if eval "$2"; then echo "PASS $1"; pass=$((pass+1)); else echo "FAIL $1"; fail=$((fail+1)); fi; }

check "before apply: no answer" '[ -z "$(ip netns exec sn-pub dig +short +time=1 +tries=1 @198.51.100.1 x.test A 2>/dev/null | grep -v ";;")" ]'

H(){ unshare --uts bash -c "hostname ir; ip netns exec sn-host env PUBLIC_IP=198.51.100.1 PUB_IF=pubh LAN_IF=lanh DNS_VM_IP=10.10.10.53 RATE=20/second BURST=20 $* $SCRIPT ${ARG:-}"; }
check "dry-run changes nothing" 'H APPLY=0 >/dev/null; ! ip netns exec sn-host iptables -t nat -S | grep -q SERVERNET-DNS'
check "apply succeeds" 'H APPLY=1 >/dev/null'
check "idempotent second apply" 'H APPLY=1 >/dev/null; [ "$(ip netns exec sn-host iptables -t nat -S PREROUTING | grep -c SERVERNET-DNS)" = 2 ]'
check "UDP answer via public IP" '[ "$(ip netns exec sn-pub dig +short +time=2 +tries=1 @198.51.100.1 x.test A)" = "192.0.2.53" ]'
check "TCP answer via public IP" '[ "$(ip netns exec sn-pub dig +tcp +short +time=2 +tries=1 @198.51.100.1 x.test A)" = "192.0.2.53" ]'
check "other port not forwarded" '! ip netns exec sn-pub timeout 2 bash -c "echo > /dev/tcp/198.51.100.1/5300" 2>/dev/null'
check "VM in split.state is refused" 'mkdir -p /etc/servernet; cp /etc/servernet/split.state /tmp/split.bak 2>/dev/null; echo "10.10.10.53 de" > /etc/servernet/split.state; ! H APPLY=1 >/dev/null 2>&1; rc=$?; rm -f /etc/servernet/split.state; [ -f /tmp/split.bak ] && mv /tmp/split.bak /etc/servernet/split.state; [ $rc = 0 ]'
# سیل: ۴۰۰ پرس‌وجوی پشتِ‌هم؛ با سقفِ ۲۰/ثانیه بیشترشان باید DROP شوند
ok=$(ip netns exec sn-pub bash -c 'for i in $(seq 1 400); do dig +short +time=1 +tries=1 @198.51.100.1 f$i.test A & done; wait' 2>/dev/null | grep -c 192.0.2.53)
check "flood is rate-limited ($ok/400 answered)" '[ "$ok" -lt 200 ] && [ "$ok" -gt 0 ]'
check "conntrack bounded (dropped before DNAT)" '[ "$(ip netns exec sn-host conntrack -C 2>/dev/null || echo 0)" -lt 300 ]'
check "remove cleans only own chains" 'ARG=remove H APPLY=1 >/dev/null; ! ip netns exec sn-host iptables-save | grep -q -i servernet-dns && ip netns exec sn-host iptables -t nat -S POSTROUTING | grep -q MASQUERADE'

kill $SRV 2>/dev/null; cleanup
echo "RESULT pass=$pass fail=$fail"
