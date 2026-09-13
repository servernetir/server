#!/bin/bash
# برداشتنِ کاملِ عامل و قواعدش. هیچ قاعدهٔ دیگری روی هاست لمس نمی‌شود.
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "باید با root اجرا شود" >&2; exit 2; }

systemctl disable --now servernet-guestpolicy.timer 2>/dev/null || true
rm -f /etc/systemd/system/servernet-guestpolicy.{service,timer}
systemctl daemon-reload

iptables -D FORWARD -j SNET-GUEST 2>/dev/null || true
iptables -F SNET-GUEST 2>/dev/null || true
iptables -X SNET-GUEST 2>/dev/null || true

rm -f /usr/local/sbin/servernet-guestpolicy-agent /etc/servernet/guestpolicy.env
echo "برداشته شد. هیچ قاعدهٔ دیگری تغییر نکرد."
