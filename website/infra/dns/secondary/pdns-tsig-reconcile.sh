#!/usr/bin/env bash
# =============================================================================
# pdns-tsig-reconcile — روی هر نیم‌سرورِ secondary، هر دقیقه (systemd timer)
#
# 🔴 چرا وجود دارد (هر سه مورد روی PowerDNS 5.0.2 در آزمایشگاه دیده شد):
#
# 1. zoneهای عضوی که مصرف‌کنندهٔ catalog می‌سازد کلیدِ TSIG را به ارث **نمی‌برند**:
#    AXFR-MASTER-TSIG روی عضو خالی است، AXFRِ عضو بی‌امضا می‌رود و primary (که
#    فقط AXFRِ امضاشده می‌پذیرد) ردش می‌کند. zone هرگز روی نیم‌سرور ظاهر نمی‌شود.
# 2. zoneی که هنوز یک بار هم منتقل نشده (SOA ندارد) در `pdnsutil list-all-zones`
#    و `pdns_control list-zones` **دیده نمی‌شود** — یعنی دقیقاً همان zoneی که
#    باید درستش کنیم. پس فهرست از خودِ دیتابیسِ بک‌اند (gsqlite3) خوانده می‌شود.
# 3. `pdnsutil list-all-zones secondary` در این نسخه همیشه خالی است.
#
# این اسکریپت فقط یک کار می‌کند: هر zoneِ secondary بدونِ کلیدِ درست → کلید را
# می‌گذارد و انتقال را دوباره درخواست می‌کند. به برنامهٔ لاراول، APIِ primary یا
# دسترسیِ بیرونی نیاز ندارد. idempotent است و هیچ zoneی را نمی‌سازد/پاک نمی‌کند
# (عضویت و حذف کارِ catalog است).
# =============================================================================
set -euo pipefail

KEY="${PDNS_XFR_TSIG_KEY:-xfr-servernet}"            # نامِ کلید — راز نیست
DB="${PDNS_SQLITE_DB:-/var/lib/powerdns/pdns.sqlite3}"
PDNSUTIL=(pdnsutil ${PDNS_CONFIG_DIR:+--config-dir="$PDNS_CONFIG_DIR"} ${PDNS_CONFIG_NAME:+--config-name="$PDNS_CONFIG_NAME"})
PDNSCONTROL=(pdns_control ${PDNS_CONFIG_DIR:+--config-dir="$PDNS_CONFIG_DIR"} ${PDNS_CONFIG_NAME:+--config-name="$PDNS_CONFIG_NAME"})
[ -n "${PDNS_SOCKET_DIR:-}" ] && PDNSCONTROL+=(--socket-dir="$PDNS_SOCKET_DIR")

[ -r "$DB" ] || { echo "pdns-tsig-reconcile: database $DB not readable" >&2; exit 2; }
case "$KEY" in *[!A-Za-z0-9._-]*) echo "pdns-tsig-reconcile: bad key name" >&2; exit 2;; esac

# zoneهای secondary که کلیدِ درست را ندارند. نامِ کلید بالا اعتبارسنجی شد، پس
# درج در SQL امن است؛ نام‌های zone از دیتابیس می‌آیند و به‌عنوان آرگومان می‌روند.
mapfile -t zones < <(sqlite3 -readonly "$DB" "
  SELECT d.name FROM domains d
  WHERE upper(d.type) IN ('SLAVE','SECONDARY')
    AND NOT EXISTS (SELECT 1 FROM domainmetadata m
                    WHERE m.domain_id = d.id AND m.kind = 'AXFR-MASTER-TSIG' AND m.content = '$KEY');")

fixed=0
for zone in "${zones[@]}"; do
  [ -z "$zone" ] && continue
  "${PDNSUTIL[@]}" set-meta "$zone" AXFR-MASTER-TSIG "$KEY" >/dev/null
  "${PDNSCONTROL[@]}" retrieve "$zone" >/dev/null 2>&1 || true
  fixed=$((fixed + 1))
  logger -t pdns-tsig-reconcile "set AXFR-MASTER-TSIG on $zone and requested retrieve" 2>/dev/null || true
done

[ "$fixed" -gt 0 ] && echo "pdns-tsig-reconcile: fixed $fixed zone(s)"
exit 0
