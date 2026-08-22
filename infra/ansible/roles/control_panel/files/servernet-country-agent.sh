#!/bin/bash
# ServerNet country-routing agent. Pulls desired {ip,cc} from the panel and applies
# per-VM split-routing via servernet-vm-country. Reconciles removals. Idempotent.
#
# HARDENED (audit C2): a non-array / malformed / truncated response, or a missing
# python3, must NEVER be treated as "empty desired state" — otherwise the reconcile
# loop would delete every customer's split-routing and revert them all to the Iran
# IP. We only proceed past parsing when we got a VALID JSON ARRAY.
# PERSIST (audit C1): the validated desired state is written to /etc/servernet/split.state
# so the host boot unit (servernet-split-boot) can restore per-VM marks before VMs boot.
set -uo pipefail
ENVFILE=/etc/servernet/pf-agent.env
[ -f "$ENVFILE" ] && . "$ENVFILE"
# پیش‌فرض = پنلِ اصلی روی آلمان. (پیش‌تر 10.10.10.30 بود — پنلِ interimِ قدیمی —
# و چون env هم توکنِ قدیمی داشت، ایجنت ۳٫۵ روز ۴۰۳ می‌گرفت و هیچ مسیری اعمال
# نمی‌شد؛ باگِ ۲۰۲۶-۰۸-۲۱.) با CR_API در pf-agent.env قابلِ override است.
API="${CR_API:-https://servernet.cloud/agent/countryroutes}"
TOKEN="${PF_AGENT_TOKEN:-}"
STATE=/etc/servernet/split.state

JSON=$(curl -fsS -m 8 -H "X-PF-Token: $TOKEN" "$API" 2>/dev/null || true)
[ -z "$JSON" ] && exit 0          # network error / empty / HTTP>=400 -> do nothing (safe)

# Parse. Emit a sentinel ONLY on a valid JSON array; otherwise emit nothing.
PARSED=$(printf '%s' "$JSON" | python3 -c 'import sys,json
try:
    d = json.load(sys.stdin)
    assert isinstance(d, list)
    print("__SN_OK__")
    for r in d:
        print(r["ip"], r["cc"])
except Exception:
    pass' 2>/dev/null || true)

case "$PARSED" in
  __SN_OK__*) : ;;                 # valid array (possibly empty) -> proceed
  *) exit 0 ;;                     # C2: invalid/malformed/python-missing -> touch NOTHING
esac
DESIRED=$(printf '%s\n' "$PARSED" | sed '1d')   # strip the __SN_OK__ marker

# C1: persist the validated desired state for the boot unit.
install -d /etc/servernet 2>/dev/null || true
printf '%s\n' "$DESIRED" | sed '/^[[:space:]]*$/d' > "$STATE"

# apply desired
printf '%s\n' "$DESIRED" | while read -r ip cc; do
  [ -n "${ip:-}" ] && [ -n "${cc:-}" ] && /usr/local/sbin/servernet-vm-country "$ip" "$cc" >/dev/null 2>&1 || true
done

# reconcile: any IP currently split-routed (our fwmark rule) but not desired -> remove
CUR=$(ip rule show 2>/dev/null | grep -oE 'from 10\.10\.10\.[0-9]+ fwmark 0xc0' | awk '{print $2}' | sort -u)
WANT=$(printf '%s\n' "$DESIRED" | awk '{print $1}' | sort -u)
for ip in $CUR; do
  printf '%s\n' "$WANT" | grep -qx "$ip" || /usr/local/sbin/servernet-vm-country-del "$ip" >/dev/null 2>&1 || true
done
