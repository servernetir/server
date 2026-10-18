#!/usr/bin/env bash
# =============================================================================
# servernet-dns-dnat — هدایتِ TCP/UDP 53 از IPِ عمومیِ میزبانِ ایران به VMِ DNS
#
# ⚠️ فقط پس از مجوزِ صریحِ مالک و پس از اجرای iran-host-readonly.sh و بررسیِ
#    خروجی‌اش اجرا شود. پیش‌فرض DRY-RUN است و فقط فرمان‌ها را چاپ می‌کند.
#    اجرای واقعی: APPLY=1 DNS_VM_IP=10.10.10.N ./servernet-dns-dnat.sh
#
# طراحی، هم‌شکلِ عامل‌های موجودِ همین میزبان (SERVERNET-PF و egressهای cloudflared):
#  • زنجیرهٔ اختصاصی SERVERNET-DNS در nat/PREROUTING و SERVERNET-DNS-FWD در filter؛
#    فقط همین دو زنجیره دست می‌خورند — هیچ flush/restoreِ سراسری، هیچ restartِ شبکه.
#  • قاعدهٔ PREROUTING به مقصدِ دقیقِ PUBLIC_IP و پورتِ ۵۳ محدود است؛ SERVERNET-PF
#    مقصد را محدود نمی‌کند و فقط بازهٔ فروش (۲۰۰۰۰–۲۰۹۹۹) را می‌گیرد، پس تداخل ندارند.
#  • idempotent: هر قاعده با -C سنجیده و فقط در نبودش -A/-I می‌شود؛ timer هر دقیقه
#    همین را اجرا می‌کند تا پس از reboot یا هر بازسازیِ دیگری، حالت برگردد.
#  • محافظتِ conntrack: هر پرس‌وجوی UDP یک ردیفِ conntrack می‌سازد و همین جدول NATِ
#    همهٔ مهمان‌ها را نگه می‌دارد. سیلِ DNS نباید بتواند NATِ ۳۰ VM را بخواباند؛ پس
#    پیش از DNAT در جدولِ raw سقفِ نرخ به ازای هر /24 گذاشته می‌شود و مازاد DROP.
#  • مسیرِ برگشت: پاسخِ VM از gateway 10.10.10.1 برمی‌گردد و conntrack آن را به
#    PUBLIC_IP بازمی‌نویسد. VMِ DNS نباید در /etc/servernet/split.state (مسیریابیِ
#    کشوری) باشد — اسکریپت این را می‌سنجد و در صورتِ وجود متوقف می‌شود.
#
# بازگشت: ./servernet-dns-dnat.sh remove   (فقط زنجیره‌های خودش را برمی‌دارد)
# =============================================================================
set -euo pipefail

PUBLIC_IP="${PUBLIC_IP:-85.9.108.118}"
PUB_IF="${PUB_IF:-vmbr0}"
LAN_IF="${LAN_IF:-vmbr1}"
DNS_VM_IP="${DNS_VM_IP:-}"
RATE="${RATE:-60/second}"      # به ازای هر /24 مبدأ — پرس‌وجوی مشروعِ resolverها خیلی کمتر است
BURST="${BURST:-120}"
APPLY="${APPLY:-0}"
NAT=SERVERNET-DNS
FWD=SERVERNET-DNS-FWD
RAW=SERVERNET-DNS-RAW
TAG="servernet-dns"

run() { if [ "$APPLY" = 1 ]; then "$@"; else printf 'DRY-RUN: %q ' "$@"; echo; fi; }
ensure() { local t=$1; shift; iptables -w 10 -t "$t" -C "$@" 2>/dev/null || run iptables -w 10 -t "$t" -A "$@"; }
ensure_first() { local t=$1 c=$2; shift 2; iptables -w 10 -t "$t" -C "$c" "$@" 2>/dev/null || run iptables -w 10 -t "$t" -I "$c" 1 "$@"; }
chain() { iptables -w 10 -t "$1" -nL "$2" >/dev/null 2>&1 || run iptables -w 10 -t "$1" -N "$2"; }

[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 1; }
[ "$(hostname -s)" = "ir" ] || { echo "refusing: expected the Iran Proxmox host (hostname ir), got $(hostname -s)" >&2; exit 1; }

if [ "${1:-}" = "remove" ]; then
  iptables -w 10 -t nat -D PREROUTING -i "$PUB_IF" -d "$PUBLIC_IP"/32 -p udp --dport 53 -m comment --comment "$TAG" -j "$NAT" 2>/dev/null && echo "removed nat hook udp" || true
  iptables -w 10 -t nat -D PREROUTING -i "$PUB_IF" -d "$PUBLIC_IP"/32 -p tcp --dport 53 -m comment --comment "$TAG" -j "$NAT" 2>/dev/null && echo "removed nat hook tcp" || true
  iptables -w 10 -t raw -D PREROUTING -i "$PUB_IF" -d "$PUBLIC_IP"/32 -p udp --dport 53 -m comment --comment "$TAG" -j "$RAW" 2>/dev/null || true
  iptables -w 10 -D FORWARD -o "$LAN_IF" -m comment --comment "$TAG" -j "$FWD" 2>/dev/null || true
  for spec in "nat $NAT" "raw $RAW" "filter $FWD"; do set -- $spec; iptables -w 10 -t "$1" -F "$2" 2>/dev/null || true; iptables -w 10 -t "$1" -X "$2" 2>/dev/null || true; done
  echo "servernet-dns-dnat: removed"; exit 0
fi

[[ "$DNS_VM_IP" =~ ^10\.10\.10\.([0-9]{1,3})$ ]] && [ "${BASH_REMATCH[1]}" -ge 2 ] && [ "${BASH_REMATCH[1]}" -le 254 ] \
  || { echo "DNS_VM_IP must be a 10.10.10.x address (got '$DNS_VM_IP')" >&2; exit 1; }

# مسیریابیِ کشوری پاسخ‌ها را به تونل می‌فرستاد — VMِ DNS نباید در آن باشد
if [ -f /etc/servernet/split.state ] && grep -q "^$DNS_VM_IP " /etc/servernet/split.state; then
  echo "refusing: $DNS_VM_IP is country-routed (/etc/servernet/split.state)" >&2; exit 1
fi
if ip rule show | grep -q "from $DNS_VM_IP "; then
  echo "refusing: policy rule exists for $DNS_VM_IP — replies would not return via $PUBLIC_IP" >&2; exit 1
fi

chain raw "$RAW"
chain nat "$NAT"
chain filter "$FWD"

# raw: سقفِ نرخ پیش از ساختِ ردیفِ conntrack (فقط UDP؛ TCP خودش handshake دارد)
ensure raw "$RAW" -p udp -m hashlimit --hashlimit-upto "$RATE" --hashlimit-burst "$BURST" \
  --hashlimit-mode srcip --hashlimit-srcmask 24 --hashlimit-name sn_dns -m comment --comment "$TAG" -j RETURN
ensure raw "$RAW" -p udp -m comment --comment "$TAG" -j DROP
ensure_first raw PREROUTING -i "$PUB_IF" -d "$PUBLIC_IP"/32 -p udp --dport 53 -m comment --comment "$TAG" -j "$RAW"

# nat: فقط مقصدِ دقیق + پورتِ ۵۳
ensure nat "$NAT" -p udp --dport 53 -m comment --comment "$TAG" -j DNAT --to-destination "$DNS_VM_IP:53"
ensure nat "$NAT" -p tcp --dport 53 -m comment --comment "$TAG" -j DNAT --to-destination "$DNS_VM_IP:53"
ensure_first nat PREROUTING -i "$PUB_IF" -d "$PUBLIC_IP"/32 -p udp --dport 53 -m comment --comment "$TAG" -j "$NAT"
ensure_first nat PREROUTING -i "$PUB_IF" -d "$PUBLIC_IP"/32 -p tcp --dport 53 -m comment --comment "$TAG" -j "$NAT"

# filter: اگر سیاستِ FORWARD سخت‌گیر باشد، فقط همین جریان باز می‌شود
ensure filter "$FWD" -i "$PUB_IF" -d "$DNS_VM_IP"/32 -p udp --dport 53 -m conntrack --ctstate NEW,ESTABLISHED -m comment --comment "$TAG" -j ACCEPT
ensure filter "$FWD" -i "$PUB_IF" -d "$DNS_VM_IP"/32 -p tcp --dport 53 -m conntrack --ctstate NEW,ESTABLISHED -m comment --comment "$TAG" -j ACCEPT
ensure_first filter FORWARD -o "$LAN_IF" -m comment --comment "$TAG" -j "$FWD"

echo "servernet-dns-dnat: ${PUBLIC_IP}:53 -> ${DNS_VM_IP}:53 ($([ "$APPLY" = 1 ] && echo applied || echo dry-run))"
