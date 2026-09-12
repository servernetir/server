#!/bin/bash
# ─────────────────────────────────────────────────────────────────────────────
# servernet-guestpolicy-agent
#
# «حالتِ مطلوبِ» دسترسیِ مهمان‌ها به شبکهٔ داخلی را از پنل می‌کشد و روی هاست
# اعمال می‌کند، بعد نتیجه را تأیید می‌کند.
#
# ═══ سه قاعده‌ای که این اسکریپت را امن نگه می‌دارد ═══
#
# 🔴 ۱ — سرور «داده» می‌فرستد، نه «دستور».
#        هیچ چیزی از پاسخ مستقیم به iptables نمی‌رود. هر IP با regex سنجیده
#        می‌شود و دستور را خودِ اسکریپت می‌سازد. بدترین کارِ یک پاسخِ جعلی،
#        بستنِ دسترسیِ داخلیِ یک IP در همان ساب‌نت است.
#
# 🔴 ۲ — فقط زنجیرهٔ خودش.  همهٔ قواعد داخلِ SNET-GUEST است. هیچ قاعدهٔ
#        دیگری روی این هاست — VLESS، SSH:20000، NPM، country-routing —
#        هرگز خوانده یا پاک نمی‌شود. «یک مالک برای هر دامنهٔ قاعده».
#
# 🔴 ۳ — پاسخِ نامعتبر هیچ‌چیز را پاک نمی‌کند.  خطای شبکه، صفحهٔ Cloudflare با
#        کدِ ۲۰۰، JSONِ ناقص ⇒ خروج بی‌تغییر. سکوت بهتر از بازکردنِ ناخواستهٔ
#        دسترسی است.
#
# اجرا با systemd timer هر ۳۰ ثانیه. دستی هم می‌شود: bash این‌فایل --once -v
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

CONF=/etc/servernet/guestpolicy.env
CHAIN=SNET-GUEST
LOCK=/run/servernet-guestpolicy.lock
VERBOSE=0

[[ "${1:-}" == "-v" || "${2:-}" == "-v" ]] && VERBOSE=1

log()  { logger -t servernet-guestpolicy -- "$*"; [[ $VERBOSE -eq 1 ]] && echo "$*" >&2 || true; }
die()  { log "ABORT: $*"; exit 1; }

# ── پیکربندی ────────────────────────────────────────────────────────────────
[[ -r "$CONF" ]] || die "پیکربندی خوانده نشد: $CONF"
# shellcheck disable=SC1090
. "$CONF"

: "${PANEL_URL:?PANEL_URL تنظیم نشده}"
: "${AGENT_TOKEN:?AGENT_TOKEN تنظیم نشده}"

command -v jq >/dev/null || die "jq نصب نیست"
command -v iptables >/dev/null || die "iptables پیدا نشد"

# ── قفل: دو اجرای هم‌زمان نداشته باشیم ──────────────────────────────────────
exec 9>"$LOCK"
flock -n 9 || { log "اجرای قبلی هنوز تمام نشده؛ رد می‌شوم"; exit 0; }

# ── کشیدنِ حالتِ مطلوب ───────────────────────────────────────────────────────
BODY="$(curl -fsS --max-time 20 \
          -H "X-Agent-Token: ${AGENT_TOKEN}" \
          "${PANEL_URL%/}/agent/guestpolicy" 2>/dev/null)" || {
  log "پنل پاسخ نداد — قواعد دست‌نخورده ماند"
  exit 0
}

# 🔴 نشانهٔ صریح. صفحهٔ خطای Cloudflare هم کدِ ۲۰۰ می‌دهد؛ بی‌این بررسی،
# آن HTML «پاسخِ معتبرِ خالی» خوانده می‌شد و همهٔ قواعد پاک می‌شدند.
SCHEMA="$(jq -r '.schema // empty' <<<"$BODY" 2>/dev/null || true)"
[[ "$SCHEMA" == "servernet.guestpolicy.v1" ]] || {
  log "پاسخِ ناشناخته (schema='$SCHEMA') — قواعد دست‌نخورده ماند"
  exit 0
}

REVISION="$(jq -r '.revision // empty' <<<"$BODY")"
LAN_CIDR="$(jq -r '.lan_cidr // "10.10.10.0/24"' <<<"$BODY")"

[[ "$REVISION" =~ ^[0-9a-f]{6,64}$ ]] || { log "نسخهٔ نامعتبر — رد"; exit 0; }
[[ "$LAN_CIDR" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}/[0-9]{1,2}$ ]] || { log "CIDR نامعتبر — رد"; exit 0; }

# ── ساختِ فهرستِ مطلوب (فقط از مقادیرِ اعتبارسنجی‌شده) ───────────────────────
BLOCKED=()
while IFS= read -r ip; do
  [[ -z "$ip" ]] && continue
  # 🔴 فهرستِ سفید: فقط IPv4ِ نقطه‌ای. هر چیز دیگری بی‌صدا دور ریخته می‌شود.
  [[ "$ip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || { log "IPِ نامعتبر رد شد: $ip"; continue; }
  BLOCKED+=("$ip")
done < <(jq -r '.policies[]? | select(.lan == false) | .ip' <<<"$BODY")

# خروجیِ `iptables -S` مورد انتظار
build_want() {
  printf -- "-N %s\n" "$CHAIN"
  printf -- "-A %s -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN\n" "$CHAIN"
  local ip
  for ip in "${BLOCKED[@]:-}"; do
    [[ -z "$ip" ]] && continue
    printf -- "-A %s -s %s/32 -d %s -j DROP\n" "$CHAIN" "$ip" "$LAN_CIDR"
  done
}

WANT="$(build_want)"

# ── وضعیتِ فعلی ─────────────────────────────────────────────────────────────
if ! HAVE="$(iptables -S "$CHAIN" 2>/dev/null)"; then
  iptables -N "$CHAIN"
  HAVE="$(iptables -S "$CHAIN" 2>/dev/null || true)"
fi

# قلّابِ FORWARD — اگر نبود، بگذار (idempotent)
iptables -C FORWARD -j "$CHAIN" 2>/dev/null || iptables -I FORWARD 1 -j "$CHAIN"

# 🔴 تلهٔ jq: `{a: empty}` کلِ آبجکت را نابود می‌کند، نه فقط آن فیلد را.
# نسخهٔ اول `error:($e|select(.!=""))` بود؛ در حالتِ موفق (بی‌خطا) خروجیِ jq
# **کاملاً خالی** می‌شد و curl بدنهٔ تهی می‌فرستاد — یعنی تأیید هرگز ثبت
# نمی‌شد و پنل تا ابد «در انتظار» می‌مانْد. با هیچ خطایی هم معلوم نمی‌شد.
ack() {
  local ok="$1" err="${2:-}"
  curl -fsS --max-time 15 -X POST \
    -H "X-Agent-Token: ${AGENT_TOKEN}" \
    -H 'Content-Type: application/json' \
    -d "$(jq -cn --arg r "$REVISION" --argjson ok "$ok" --arg e "$err" \
            '{revision:$r, ok:$ok} + (if $e == "" then {} else {error:$e} end)')" \
    "${PANEL_URL%/}/agent/guestpolicy/ack" >/dev/null 2>&1 || \
      log "تأیید فرستاده نشد (پنل در دسترس نبود)"
}

# ── اگر چیزی عوض نشده، دست نزن ──────────────────────────────────────────────
if [[ "$HAVE" == "$WANT" ]]; then
  [[ $VERBOSE -eq 1 ]] && log "بدون تغییر (rev $REVISION, ${#BLOCKED[@]} بسته)"
  ack true
  exit 0
fi

# ── اعمال ───────────────────────────────────────────────────────────────────
# ⚠️ فقط همین زنجیره flush می‌شود. هیچ قاعدهٔ دیگری روی هاست لمس نمی‌شود.
if ! {
      iptables -F "$CHAIN"
      iptables -A "$CHAIN" -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN
      for ip in "${BLOCKED[@]:-}"; do
        [[ -z "$ip" ]] && continue
        iptables -A "$CHAIN" -s "$ip/32" -d "$LAN_CIDR" -j DROP
      done
    }; then
  log "اعمال شکست خورد"
  ack false "اعمالِ iptables شکست خورد"
  exit 1
fi

log "اعمال شد: rev $REVISION — ${#BLOCKED[@]} مهمانِ بسته"
ack true
