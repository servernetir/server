#!/bin/bash
# نصبِ یک‌بارهٔ عاملِ سیاستِ شبکهٔ داخلی. idempotent — اجرای دوباره بی‌خطر است.
#
# اجرا روی هاستِ ایران، به‌عنوانِ root:
#   bash install.sh <PANEL_URL> <AGENT_TOKEN>
#
# مثال:
#   bash install.sh https://servernet.cloud 'xxxxxxxx'
set -euo pipefail

PANEL_URL="${1:-}"
AGENT_TOKEN="${2:-}"

[[ -n "$PANEL_URL" && -n "$AGENT_TOKEN" ]] || {
  echo "usage: bash install.sh <PANEL_URL> <AGENT_TOKEN>" >&2; exit 2; }
[[ $EUID -eq 0 ]] || { echo "باید با root اجرا شود" >&2; exit 2; }

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── وابستگی ────────────────────────────────────────────────────────────────
if ! command -v jq >/dev/null; then
  echo "→ نصب jq"
  apt-get update -qq && apt-get install -y -qq jq
fi

# ── گاردِ توکنِ بدشکل ────────────────────────────────────────────────────────
#
# 🔴 رخدادِ واقعی: توکن با
#   grep -oP '(?<=X-PF-Token: )\S+'
# برداشته شده بود، و چون هدر در اسکریپت داخلِ گیومه است
# (`-H "X-PF-Token: abc123"`), آن `\S+` **گیومهٔ پایانی را هم بلعید**.
# نتیجه یک توکنِ ۱۵ نویسه‌ای با `"` در انتها بود.
#
# پس اگر توکن گیومه یا فاصله دارد، تقریباً قطعاً اشتباهِ استخراج است — نه
# توکنِ واقعی. صریح رد می‌کنیم تا کسی ساعت‌ها دنبالِ «چرا ۴۰۳ می‌گیرم» نگردد.
# 🔴 و این یکی از خودِ من درآمد: اسکریپتِ عاملِ نصب‌شده خطِ
#   -H "X-Agent-Token: ${AGENT_TOKEN}"
# دارد، پس دستورِ «توکن را از روی فایل‌های موجود پیدا کن» **خودِ همین فایل**
# را تطبیق می‌داد و رشتهٔ `${AGENT_TOKEN}` (دقیقاً ۱۴ نویسه) را به‌عنوان
# توکن برمی‌داشت. چون شبیهِ یک توکنِ کوتاه است، سه دور طول کشید تا دیده شود.
if [[ "$AGENT_TOKEN" == *'$'* || "$AGENT_TOKEN" == *'{'* || "$AGENT_TOKEN" == *'}'* ]]; then
  echo "🔴 توکن شبیهِ یک متغیرِ بسط‌نیافته است: [${AGENT_TOKEN}]" >&2
  echo "   احتمالاً دستورِ استخراج، خودِ اسکریپتِ عامل را تطبیق داده." >&2
  echo "   عاملِ واقعی را این‌طور پیدا کن:" >&2
  echo "   grep -rIl 'agent/countryroutes' /usr/local /opt /root /etc 2>/dev/null | grep -v guestpolicy" >&2
  exit 2
fi

# توکنِ کوتاه هم تقریباً همیشه یعنی استخراجِ غلط.
if (( ${#AGENT_TOKEN} < 12 )); then
  echo "🔴 توکن فقط ${#AGENT_TOKEN} نویسه است — تقریباً قطعاً تکهٔ اشتباهی برداشته شده." >&2
  exit 2
fi

if [[ "$AGENT_TOKEN" =~ [\"\'\ ] ]]; then
  echo "🔴 توکن گیومه یا فاصله دارد: [${AGENT_TOKEN}]" >&2
  echo "   تقریباً قطعاً هنگامِ استخراج، گیومهٔ پایانیِ هدر هم برداشته شده." >&2
  echo "   این را امتحان کن (تا اولین گیومه/فاصله می‌گیرد):" >&2
  echo "   TOKEN=\$(grep -rhoP 'X-(PF|Agent)-Token:\\s*\\K[^\"'\''[:space:]]+' \\" >&2
  echo "     /usr/local/sbin/ /etc/systemd/system/ /etc/servernet/ 2>/dev/null | head -1)" >&2
  exit 2
fi

# ── پیکربندی (۰۶۰۰ — توکن داخلش است) ───────────────────────────────────────
install -d -m 0755 /etc/servernet
umask 077

# 🔴 مقدارها **تک‌گیومه‌ای و فرارداده‌شده** نوشته می‌شوند. این فایل با `.`
# سورس می‌شود، پس مقدارِ بی‌گیومه‌ای که یک `"` یا فاصله یا `#` داشته باشد،
# کلِ فایل را می‌شکند — و پیامش (`unexpected EOF`) هیچ ربطی به توکن ندارد و
# آدم را گمراه می‌کند. دقیقاً یک بار همین رخ داد.
q(){ printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"; }

{
  printf 'PANEL_URL=%s\n'   "$(q "$PANEL_URL")"
  printf 'AGENT_TOKEN=%s\n' "$(q "$AGENT_TOKEN")"
} > /etc/servernet/guestpolicy.env

chmod 600 /etc/servernet/guestpolicy.env
umask 022

# و بلافاصله بسنج که واقعاً سورس می‌شود — نوشتنی که خوانده نشود بی‌فایده است.
( set -e; . /etc/servernet/guestpolicy.env; [[ -n "${AGENT_TOKEN:-}" ]] ) \
  || { echo "🔴 فایلِ پیکربندی نوشته شد ولی سورس نمی‌شود." >&2; exit 1; }

# ── اسکریپت و واحدها ───────────────────────────────────────────────────────
install -m 0755 "$HERE/servernet-guestpolicy-agent.sh" /usr/local/sbin/servernet-guestpolicy-agent
install -m 0644 "$HERE/servernet-guestpolicy.service"  /etc/systemd/system/
install -m 0644 "$HERE/servernet-guestpolicy.timer"    /etc/systemd/system/

systemctl daemon-reload
systemctl enable --now servernet-guestpolicy.timer

echo
echo "→ اجرای آزمایشی (verbose):"
/usr/local/sbin/servernet-guestpolicy-agent -v || true

echo
echo "→ زنجیره:"
iptables -S SNET-GUEST 2>/dev/null || echo "  (هنوز ساخته نشده)"
echo
echo "نصب تمام شد. وضعیت: systemctl status servernet-guestpolicy.timer"
echo "لاگ:            journalctl -t servernet-guestpolicy -n 30"
