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

# ── پیکربندی (۰۶۰۰ — توکن داخلش است) ───────────────────────────────────────
install -d -m 0755 /etc/servernet
umask 077
cat > /etc/servernet/guestpolicy.env <<EOF
PANEL_URL=${PANEL_URL}
AGENT_TOKEN=${AGENT_TOKEN}
EOF
chmod 600 /etc/servernet/guestpolicy.env
umask 022

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
