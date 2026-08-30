#!/usr/bin/env bash
#
# 🔴 ناوگانِ زیرساخت — /admin/fleet
#
# «کدام ماشین‌ها را داریم، کدامشان به مشتری وصل‌اند، و بابتِ کدام‌ها بی‌درآمد
# پول می‌دهیم.» با جست‌وجو روی آی‌پی/مشتری/سرویس، سنِ رهاشدگی، و ضررِ انباشته.
#
# اجرا از ترمینالِ cPanel:
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-infra-fleet.sh) [<SHA>]
#
# ═══ چه چیزی را می‌بندد ═══
#
# مشتری سرویسش را می‌بندد، حذفِ سمتِ زیرساخت انجام نمی‌شود، و ماشین ماه‌ها
# اجاره می‌بَرد. گزارشِ زندهٔ /admin/cloud/inventory این را می‌بیند ولی حافظه
# ندارد: نمی‌گوید از کِی، و نمی‌گوید چقدر. این دیپلوی یک دفترِ ماندگار
# (`infra_assets`) اضافه می‌کند که هر اسکن رویش می‌نشیند.
#
# ═══ بعد از این اسکریپت ═══
#
#   ۱) /admin/fleet را باز کنید و دکمهٔ «اسکن زنده» را بزنید (اولین عکس).
#   ۲) هر ماشینِ «بی‌صاحب»ی که واقعاً مالِ خودمان است را با دکمهٔ «مدیریت»
#      نقشِ «داخلی» بدهید — وگرنه هر روز در فهرستِ هشدار می‌مانَد.
#
# ⚠️ کرونِ روزانهٔ `fleet:scan` هم اضافه می‌شود. سنِ رهاشدگی از فاصلهٔ بینِ دو
#    اسکن می‌آید؛ بی‌کرون، عددِ ضرر همیشه صفر می‌مانَد.
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-infra-fleet"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=80

# ── اثباتِ محل، پیش از هر نوشتن ─────────────────────────────────────────
# 🔴 گاردِ محتوا روی دایرکتوریِ اشتباه همیشه سبز می‌شود، چون همان فایلی را
# می‌سنجد که خودش تازه ساخته. پس اول باید ثابت شود این‌جا واقعاً اپ است.
[ -f "$APP/artisan" ] || { echo "FATAL: $APP/artisan نیست — مسیرِ اپ اشتباه است"; exit 1; }
[ -f "$APP/routes/web.php" ] || { echo "FATAL: routes/web.php نیست — این‌جا اپ نیست"; exit 1; }
[ -f "$APP/app/Services/Cloud/CloudInventory.php" ] || { echo "FATAL: CloudInventory نیست — نسخهٔ اپ خیلی قدیمی است"; exit 1; }

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }
if [ -d repo/.git ]; then
  git -C repo fetch --depth 600 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 600 --branch develop https://github.com/servernetir/server.git repo || exit 1
fi

MINE="${1:-PLACEHOLDER_SHA}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
echo "── بکاپ در: $BK"
echo

# ⚠️ شمارشِ کرون‌ها **پیش** از دست‌زدن به console.php.
# درسِ گذشته: فایلِ مشترکِ سرور drift دارد و یک‌بار بازنویسی، کرون‌های دیگری
# را بی‌صدا پاک کرد. اگر عدد بعد از دیپلوی کمتر شود، برمی‌گردانیم.
CRON_BEFORE=$(grep -c "Schedule::command" "$APP/routes/console.php" 2>/dev/null || echo 0)
echo "── کرون‌های فعلی روی سرور: $CRON_BEFORE"
echo

# فایل‌های تازه (بی‌ریسک) و فایل‌های مشترک (نیازمندِ ادغامِ سه‌طرفه) با هم
# می‌روند: بی routes/web.php صفحه وجود ندارد، بی layout هیچ‌کس پیدایش نمی‌کند،
# و بی console.php سنِ رهاشدگی هرگز ساخته نمی‌شود.
APP_FILES="
app/Models/InfraAsset.php
app/Services/Cloud/FleetScanner.php
app/Http/Controllers/Admin/FleetController.php
app/Console/Commands/FleetScan.php
resources/views/admin/fleet.blade.php
resources/views/admin/layout.blade.php
routes/web.php
routes/console.php
database/migrations/2026_10_09_000101_create_infra_assets_table.php
"

# فایل‌هایی که **باید** از قبل روی سرور باشند. اگر یکی‌شان NEW گزارش شود،
# یعنی داریم در جای اشتباه می‌نویسیم — نه اینکه قابلیتِ تازه‌ای اضافه می‌کنیم.
SHARED_FILES="routes/web.php routes/console.php resources/views/admin/layout.blade.php"

to_lf() { tr -d '\r' < "$1" > "$2"; }
dist()  { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

CONFLICTS=""; UPD=0; MISPLACED=""

apply_one() {
  rel="$1"; dest="$APP/$rel"
  mine_f="$WORK/a.mine"; srv_lf="$WORK/a.srv"; base_f="$WORK/a.base"; cand="$WORK/a.cand"

  git -C repo show "$MINE:website/$rel" > "$mine_f" 2>/dev/null || { echo "SKIP  $rel"; return; }

  if [ ! -f "$dest" ]; then
    case " $SHARED_FILES " in
      *" $rel "*) echo "🔴 $rel روی سرور نیست — مسیر اشتباه است، دست نمی‌زنم"; MISPLACED="$MISPLACED $rel"; return ;;
    esac
    mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"; echo "NEW   $rel"; UPD=$((UPD+1)); return
  fi

  mkdir -p "$BK/$(dirname "$rel")"; cp -p "$dest" "$BK/$rel"
  to_lf "$dest" "$srv_lf"

  if cmp -s "$srv_lf" "$mine_f"; then echo "OK    $rel"; return; fi

  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$cand" 2>/dev/null || continue
    if cmp -s "$srv_lf" "$cand"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$srv_lf" "$cand"); [ "$d" -lt "$bestd" ] && { bestd=$d; best="$sha"; }
  done

  if [ -z "$best" ]; then
    echo "CF    $rel  ← نسخهٔ ناشناخته روی سرور — دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$(basename "$rel")"
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"; return
  fi

  if [ "$bestd" -eq 0 ]; then
    cp "$mine_f" "$dest"; echo "UP    $rel  (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1)); return
  fi

  git -C repo show "$best:website/$rel" > "$base_f"
  m="$WORK/a.merged"; cp "$srv_lf" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"
    echo "MG    $rel  (پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط — تغییرِ دیگران حفظ شد)"
    UPD=$((UPD+1))
  else
    echo "CF    $rel  ← تداخلِ واقعی — دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$(basename "$rel")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
}

echo "═══ اپ ($APP) ═══"
for f in $APP_FILES; do apply_one "$f"; done

if [ -n "$MISPLACED" ]; then
  echo
  echo "🔴 فایلِ مشترک روی سرور پیدا نشد:$MISPLACED — هیچ ادعایی از این اجرا معتبر نیست."
  exit 1
fi

# ── ضمانتِ اتحاد ──────────────────────────────────────────────────────
#
# 🔴 چرا تک‌تک: تجربهٔ تلخِ همین پروژه این است که وقتی یکی از فایل‌های مشترک
# «CF» می‌خورَد، بقیه می‌نشینند و اسکریپت با موفقیت تمام می‌شود — و قابلیت
# **بی‌صدا** منتشر نمی‌شود. پس هر جزء جدا سنجیده می‌شود و نبودِ هرکدام قرمز است.
echo
ok=1

grep -qF "class InfraAsset" "$APP/app/Models/InfraAsset.php" 2>/dev/null || { echo "🔴 مدلِ InfraAsset ننشست"; ok=0; }
grep -qF "class FleetScanner" "$APP/app/Services/Cloud/FleetScanner.php" 2>/dev/null || { echo "🔴 FleetScanner ننشست"; ok=0; }
grep -qF "class FleetController" "$APP/app/Http/Controllers/Admin/FleetController.php" 2>/dev/null || { echo "🔴 FleetController ننشست"; ok=0; }
grep -qF "fleet:scan" "$APP/app/Console/Commands/FleetScan.php" 2>/dev/null || { echo "🔴 فرمانِ fleet:scan ننشست"; ok=0; }
grep -qF "ناوگان زیرساخت" "$APP/resources/views/admin/fleet.blade.php" 2>/dev/null || { echo "🔴 ویوی ناوگان ننشست"; ok=0; }
grep -qF "infra_assets" "$APP/database/migrations/2026_10_09_000101_create_infra_assets_table.php" 2>/dev/null || { echo "🔴 مهاجرت ننشست"; ok=0; }

# مسیرها — بی‌این‌ها صفحه اصلاً وجود ندارد
grep -qF "FleetController" "$APP/routes/web.php" || { echo "🔴 مسیرِ /admin/fleet در routes/web.php ننشست (تداخلِ هم‌زمانی؟)"; ok=0; }
grep -qF "fleet/scan" "$APP/routes/web.php" || { echo "🔴 مسیرِ اسکن ننشست"; ok=0; }
grep -qF "fleet/{asset}/release" "$APP/routes/web.php" || { echo "🔴 مسیرِ حذف ننشست"; ok=0; }

# ⚠️ ارکانِ قبلیِ routes/web.php نباید پریده باشند
grep -qF "CloudAttachController" "$APP/routes/web.php" || { echo "🔴 مسیرهای cloud/attach از فایل پرید"; ok=0; }
grep -qF "admin.domains" "$APP/routes/web.php" || { echo "🔴 مسیرهای دامنه از فایل پرید"; ok=0; }

# منو — بی‌این، صفحه هست ولی هیچ‌کس پیدایش نمی‌کند
grep -qF "/admin/fleet" "$APP/resources/views/admin/layout.blade.php" || { echo "🔴 لینکِ منو ننشست"; ok=0; }
grep -qF "/admin/cloud" "$APP/resources/views/admin/layout.blade.php" || { echo "🔴 منوی زیرساختِ ابری از layout پرید"; ok=0; }

# کرون — و مهم‌تر: هیچ کرونی نباید کم شده باشد
grep -qF "fleet:scan" "$APP/routes/console.php" || { echo "🔴 کرونِ fleet:scan ننشست — سنِ رهاشدگی هرگز ساخته نمی‌شود"; ok=0; }
CRON_AFTER=$(grep -c "Schedule::command" "$APP/routes/console.php" 2>/dev/null || echo 0)
if [ "$CRON_AFTER" -lt "$CRON_BEFORE" ]; then
  echo "🔴 تعدادِ کرون‌ها کم شد ($CRON_BEFORE → $CRON_AFTER) — console.php برمی‌گردد"
  cp "$BK/routes/console.php" "$APP/routes/console.php"
  ok=0
else
  echo "کرون‌ها: $CRON_BEFORE → $CRON_AFTER"
fi

if [ "$ok" -eq 0 ]; then
  echo
  echo "🔴 اتحاد ناقص — فایل‌ها از بکاپ برمی‌گردند."
  for f in $APP_FILES; do [ -f "$BK/$f" ] && cp "$BK/$f" "$APP/$f"; done
  echo "   برگشت انجام شد. خروجی را بفرست."
  exit 1
fi
echo "✅ همهٔ اجزا با هم‌اند"

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)

if [ -n "$PHPBIN" ]; then
  syn=1
  for f in $APP_FILES; do
    case "$f" in *.php) "$PHPBIN" -l "$APP/$f" >/dev/null || { echo "🔴 خطای syntax در $f"; syn=0; } ;; esac
  done

  if [ "$syn" -eq 0 ]; then
    for f in $APP_FILES; do [ -f "$BK/$f" ] && cp "$BK/$f" "$APP/$f"; done
    echo "🔴 برگشت از بکاپ به‌خاطرِ خطای syntax"; exit 1
  fi
  echo "بدونِ خطای syntax"

  cd "$APP"

  echo
  echo "═══ مهاجرتِ جدولِ ناوگان ═══"
  # ⚠️ بی‌این مهاجرت، صفحه ۵۰۰ نمی‌دهد (پشتِ hasTable است) ولی برای همیشه
  #    خالی می‌مانَد و «اسکن زنده» هم چیزی ذخیره نمی‌کند.
  "$PHPBIN" artisan migrate --force \
    --path=database/migrations/2026_10_09_000101_create_infra_assets_table.php \
    || { echo "🔴 مهاجرت نخورد — صفحه خالی می‌مانَد. خروجی را بفرست."; }

  "$PHPBIN" artisan config:clear >/dev/null
  "$PHPBIN" artisan route:clear >/dev/null
  "$PHPBIN" artisan view:clear >/dev/null
  echo "کش‌ها پاک شد"

  # اثباتِ نهایی: جدول واقعاً روی همین دیتابیس هست؟
  "$PHPBIN" artisan tinker --execute='echo \Illuminate\Support\Facades\Schema::hasTable("infra_assets") ? "TABLE OK" : "TABLE MISSING";' 2>/dev/null || true
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
  echo "WARN: php پیدا نشد — کش‌ها دستی پاک شدند و مهاجرت اجرا **نشد**"
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · به‌روزشده: $UPD"
[ -n "$CONFLICTS" ] && echo "🔴 تداخل‌دار:$CONFLICTS" || echo "✅ تداخلی نبود"
echo
echo "🔎 قدمِ بعد:"
echo "   ۱) /admin/fleet را باز کنید و «اسکن زنده» را بزنید — اولین عکسِ ناوگان."
echo "   ۲) ماشین‌های «بی‌صاحب»ی که مالِ خودمان‌اند را نقشِ «داخلی» بدهید،"
echo "      وگرنه هر روز در فهرستِ «نیازمندِ تصمیم» می‌مانند."
echo
echo "   اسکنِ دستی از ترمینال هم ممکن است (چیزی حذف نمی‌کند):"
echo "   cd ~/servernet_app && php artisan fleet:scan"
echo
