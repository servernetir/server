#!/usr/bin/env bash
#
# بررسیِ «فقط-گزارش» وضعیتِ سرور در برابرِ گیت — هیچ فایلی از اپ نوشته/عوض نمی‌شود.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/check-live-state.sh)
#
# چه می‌گوید:
#   OK       فایلِ سرور = نوکِ develop — کاری لازم نیست
#   BEHIND   فایلِ سرور = یک نسخهٔ تاریخیِ سالم — دیپلوی، بدونِ ریسک جایگزینش می‌کند
#   EDITED   کسی روی سرور دستش برده — می‌گوید merge سه‌طرفه تمیز درمی‌آید یا تداخل دارد
#   MISSING  فایل روی سرور اصلاً نیست (برای فایلِ تازه طبیعی است)
#   UNKNOWN  با هیچ نسخه‌ای از تاریخچه نمی‌خواند — بررسیِ دستی
#
# به‌علاوه: زنجیرهٔ /vps/hourly (کنترلر/متد/کلیدها/روت) و آخرین خطاهای واقعیِ
# لاگ برای همان صفحه، تا علتِ ۵۰۰ حدس نباشد.
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-check"
HIST=80

mkdir -p "$WORK"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }

if [ -d repo/.git ]; then
  git -C repo fetch --depth 500 origin develop >/dev/null 2>&1 || { echo "FATAL: fetch"; exit 1; }
else
  git clone --quiet --depth 500 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

TIP=$(git -C repo rev-parse origin/develop)
echo "═══ هدفِ مقایسه: $(git -C repo log -1 --format='%h %s' "$TIP")"
echo

FILES="
app/Models/CloudPlan.php
app/Services/Cloud/CloudCountry.php
app/Http/Controllers/HourlyVpsController.php
app/Http/Controllers/CloudCatalogController.php
app/Http/Controllers/SiteController.php
app/helpers.php
lang/fa/ui.php
lang/en/ui.php
lang/tr/ui.php
config/catalog/vps.php
config/catalog/domain.php
config/servernet.php
config/pagecache.php
resources/views/pages/vps-hourly.blade.php
resources/views/pages/hosting.blade.php
resources/views/pages/cloud.blade.php
resources/views/pages/cloud-location.blade.php
resources/views/pages/server-detail.blade.php
resources/views/account/cloud-store.blade.php
routes/web.php
database/migrations/2026_10_02_000101_deactivate_legacy_cloud_locations.php
"

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

for rel in $FILES; do
  dest="$APP/$rel"
  tip_f="$WORK/tip.tmp"

  if ! git -C repo show "$TIP:website/$rel" > "$tip_f" 2>/dev/null; then
    echo "SKIP     $rel (در نوکِ develop نیست)"; continue
  fi

  if [ ! -f "$dest" ]; then
    echo "MISSING  $rel"; continue
  fi

  if cmp -s "$dest" "$tip_f"; then
    echo "OK       $rel"; continue
  fi

  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$TIP" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$WORK/cand.tmp" 2>/dev/null || continue
    if cmp -s "$dest" "$WORK/cand.tmp"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$dest" "$WORK/cand.tmp")
    if [ "$d" -lt "$bestd" ]; then bestd=$d; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "UNKNOWN  $rel"; continue
  fi

  if [ "$bestd" -eq 0 ]; then
    echo "BEHIND   $rel   (سرور = $(git -C repo log -1 --format='%h %ad' --date=short "$best"))"
    continue
  fi

  # کسی دست برده — آزمایشِ merge روی فایلِ موقت، نه روی سرور
  git -C repo show "$best:website/$rel" > "$WORK/base.tmp"
  cp "$dest" "$WORK/try.tmp"
  if git merge-file -L server -L base -L new "$WORK/try.tmp" "$WORK/base.tmp" "$tip_f" >/dev/null 2>&1; then
    echo "EDITED   $rel   (نزدیک‌ترین پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط) → merge تمیز درمی‌آید"
  else
    echo "EDITED🔴 $rel   (نزدیک‌ترین پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط) → تداخلِ واقعی — بررسیِ دستی"
  fi
done

echo
echo "═══ زنجیرهٔ /vps/hourly روی سرور"
[ -f "$APP/app/Http/Controllers/HourlyVpsController.php" ] && echo "✓ HourlyVpsController هست" || echo "✗ HourlyVpsController نیست"
grep -q "function hourlyIrt" "$APP/app/Models/CloudPlan.php" 2>/dev/null && echo "✓ CloudPlan::hourlyIrt هست" || echo "✗ CloudPlan::hourlyIrt نیست"
grep -q "HOURLY_START_MIN_HOURS" "$APP/app/Models/CloudPlan.php" 2>/dev/null && echo "✓ HOURLY_START_MIN_HOURS هست" || echo "✗ HOURLY_START_MIN_HOURS نیست"
[ -f "$APP/resources/views/pages/vps-hourly.blade.php" ] && echo "✓ ویوِ vps-hourly هست" || echo "✗ ویوِ vps-hourly نیست"
for l in fa en tr; do
  grep -q "'hv_meta_t'" "$APP/lang/$l/ui.php" 2>/dev/null && echo "✓ lang/$l کلیدهای hv_* دارد" || echo "✗ lang/$l کلیدهای hv_* ندارد"
done
grep -q "name('vps.hourly')" "$APP/routes/web.php" 2>/dev/null && echo "✓ روتِ vps.hourly هست" || echo "✗ روتِ vps.hourly نیست"
grep -q "schema_offer_extras" "$APP/app/helpers.php" 2>/dev/null && echo "✓ schema_offer_extras در helpers هست" || echo "✗ schema_offer_extras در helpers نیست"
grep -c "schema_offer_extras" "$APP/resources/views/pages/hosting.blade.php" 2>/dev/null | sed 's/^/   (ارجاع در hosting.blade: /;s/$/)/'

echo
echo "═══ آخرین خطاهای واقعیِ vps-hourly در لاگ (فقط خواندن)"
LOG=$(ls -t "$APP"/storage/logs/laravel*.log 2>/dev/null | head -1)
if [ -n "${LOG:-}" ]; then
  grep -a "HourlyVps\|vps-hourly\|vps/hourly\|hourlyIrt" "$LOG" | tail -4 | cut -c1-400
else
  echo "لاگی پیدا نشد"
fi
for T in "$APP"/storage/logs/tracker*.jsonl; do
  [ -f "$T" ] || continue
  grep -a "vps.hourly\|HourlyVps\|hourlyIrt" "$T" | tail -3 | cut -c1-400
done

echo
echo "═══ تمام — هیچ فایلی تغییر نکرد. این خروجی را کامل کپی کن و برگردان."
