#!/usr/bin/env bash
#
# دیپلوی «مدیریتِ اعلان‌های بله + منوی هدر و فوتر» — شهریور ۱۴۰۵.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-menu-and-alerts.sh) [<SHA>]
#
# ═══ چه چیزی دیپلوی می‌شود ═══
#
# ۱) اعلان‌هایی که به **خودِ مدیر** می‌روند، مدیریت‌پذیر شدند.
#
#    ۲۵ فراخوانِ `AdminNotifier::event()` عنوان و متنِ سخت‌کد داشتند و هیچ
#    کلیدِ خاموشی. مدیر نمی‌توانست بگوید «این یکی را دیگر نفرست» — و اعلانِ
#    پرتکرارِ کم‌ارزش دقیقاً همان چیزی است که باعث می‌شود اعلانِ **مهم** هم
#    دیده نشود.
#
#    حالا در `/admin/settings?tab=messages` فهرستِ «اعلان‌های من» هست: هر
#    رخداد یک سوییچِ روشن/خاموش دارد و متنش با تگ‌هایی مثل {مشتری}، {مبلغ}،
#    {IP} قابلِ بازنویسی است. تگ‌ها از خودِ همان فراخوان‌ها می‌آیند، پس هیچ‌کدام
#    از آن ۲۵ نقطه عوض نشد.
#
# ۲) منوی هدر و فوتر از پنل مدیریت می‌شود.
#
#    لینک‌های فوتر تا امروز در Blade سخت‌کد بودند. حالا داده‌اند
#    (`config('servernet.footer_menu')`) و `/admin/settings?tab=menus` روی
#    ۱۲۷ گرهِ منو (مگامنو، خدمات، ابزارها، دانش، فوتر) کنترلِ کامل می‌دهد:
#    متنِ هر سه زبان، ترتیب، روشن/خاموش، و لینکِ تازه.
#
# ═══ 🔴 دو تلهٔ همین دیپلوی ═══
#
# · **فوتر روی هر صفحهٔ سایت است.** `partials/footer.blade.php` بدونِ
#   `config/servernet.php` (که `footer_menu` در آن است) و بدونِ
#   `app/Services/MenuManager.php` معنا ندارد. هر سه با هم می‌روند و گاردِ
#   پایین جداگانه هر سه را می‌سنجد — چون «فایلی که جا ماند» در این پروژه
#   فرضی نیست.
#
# · **مهاجرت لازم است ولی شرطِ دیپلوی نیست.** هر سه نقطهٔ مصرف
#   (`MenuManager`، `menusData`، `messagesData`) وجودِ جدول/ستون را چک
#   می‌کنند، پس تا وقتی مهاجرت نخورده سایت **دقیقاً مثلِ امروز** کار می‌کند
#   و فقط دو صفحهٔ تنظیمات می‌گویند «هنوز ساخته نشده».
#
# ═══ 🔴 چرا این بار پینِ اسکریپت‌های دیگر هم‌گرا **نشد** ═══
#
# `routes/web.php`، `config/servernet.php`، `footer.blade.php` و
# `SettingsController.php` را `deploy-gpu.sh` (پین c3508a0) و
# `deploy-licence-lifecycle.sh` (پین 6132a36) هم می‌برند. هر دو جدِ این
# کامیت‌اند، پس عادتِ جلسه‌های قبل این بود که پینشان جلو کشیده شود تا
# ترتیبِ اجرا مهم نباشد.
#
# این بار آن کار **خطرناک** بود و انجام نشد.
#
# `deploy-gpu.sh` فوتر را می‌برد ولی `MenuManager` را نه. با پینِ جلوکشیده،
# اجرایش ویوِ تازهٔ فوتر را می‌نشاند که کلاسی را صدا می‌زند که آن اسکریپت
# نمی‌فرستد ⇒ **هر صفحهٔ سایت ۵۰۰**. همان اسکریپت با پینِ خودش بی‌خطر است:
# نسخهٔ سرور را با merge سه‌طرفه نگه می‌دارد.
#
# قاعده‌ای که از این بیرون آمد:
#
#   پینِ اسکریپتِ دیگری را فقط وقتی جلو بکش که آن اسکریپت **همهٔ** فایل‌های
#   وابسته به نسخهٔ تازه را هم بفرستد. وگرنه نیمی از یک تغییرِ به‌هم‌پیوسته
#   را دیپلوی می‌کند — و merge سه‌طرفه، که برای همین ساخته شده، از
#   جلوکشیدنِ پین امن‌تر است.
#
# منطق عیناً از scripts/deploy-licence-lifecycle.sh.
set -u

# ── حالتِ آزمایشی ──────────────────────────────────────────────────────────
#
#   DRY=1 bash <(curl -fsSL .../deploy-menu-and-alerts.sh)
#
# 🔴 اول این را بزن. هیچ عارضه‌ای ندارد — نه فایل، نه کش. فقط می‌گوید کدام
# فایل تداخل دارد.
DRY="${DRY:-0}"

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-menu-and-alerts"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=80

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }

if [ -d repo/.git ]; then
  git -C repo fetch --depth 500 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 500 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

# 🔴 پین به کامیتِ مشخص — نوکِ متحرکِ develop را دیپلوی نکن.
MINE="${1:-c3d0bd0}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# 🔴 ترتیب معنادار است: اول نقشه و مدل‌ها، بعد سرویس‌ها، بعد provider،
#    بعد کنترلرها و config، بعد مهاجرت/seeder، بعد ویوها، و **آخر** routeها.
APP_FILES="
app/Support/AdminAlerts.php
app/Models/NotificationTemplate.php
app/Models/MenuOverride.php
app/Services/Notify/AdminNotifier.php
app/Services/Cloud/CloudProvisioner.php
app/Services/Provisioning/ManualLifecycleNotice.php
app/Services/MenuManager.php
app/Services/MenuTree.php
app/Providers/AppServiceProvider.php
app/Http/Controllers/Admin/NotificationTemplateController.php
app/Http/Controllers/Admin/MenuController.php
app/Http/Controllers/Admin/SettingsController.php
config/servernet.php
database/migrations/2026_10_07_000101_add_audience_to_notification_templates.php
database/migrations/2026_10_08_000100_create_menu_overrides_table.php
database/seeders/AdminNotificationTemplateSeeder.php
resources/views/admin/settings/messages.blade.php
resources/views/admin/settings/menus.blade.php
resources/views/partials/footer.blade.php
resources/views/partials/header.blade.php
routes/web.php
"

# فایل‌های وب‌روت — public_html جداست از servernet_app/public (کپی، نه symlink)
#
# ⚠️ خالی و باید خالی بماند. هرگز `public/index.php` این‌جا نیاید: نسخهٔ سرور
#    مسیرهای متفاوتی دارد چون اپ بیرونِ webroot است و آپلودش سایت را ۵۰۰ می‌کند.
PUB_FILES=""

CONFLICTS=""
UPD=0

# فایل‌هایی که این اجرا **ساخته** است (روی سرور نبودند).
#
# 🔴 بی‌این فهرست، بازگردانی ناقص است: بکاپ فقط فایل‌های موجود را دارد، پس
#    فایل‌های تازه بعد از rollback روی سرور **می‌مانند**. آن‌وقت پیام می‌گوید
#    «همه‌چیز برگشت» در حالی که سرور در حالتی است که نه قبلی است نه جدید —
#    و همان حالت است که بعداً کسی نمی‌فهمد از کجا آمده.
NEWFILES=""

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }

# ⚠️ خالی و باید خالی بمانَد.
FORCE_FILES=""

apply_one() {
  rel="$1"; dest="$2/$rel"
  mine_f="$WORK/mine.tmp"; base_f="$WORK/base.tmp"

  git -C repo show "$MINE:website/$rel" > "$WORK/mine.raw" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; return; }
  normalize "$WORK/mine.raw" "$mine_f"

  if [ -f "$dest" ] && [ "$DRY" = "0" ]; then
    mkdir -p "$BK/$(dirname "$rel")"
    cp -p "$dest" "$BK/$rel"
  fi

  if [ ! -f "$dest" ]; then
    [ "$DRY" = "0" ] && { mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"; }
    # 🔴 مسیرِ کامل نگه داشته می‌شود، وگرنه بازگردانی نمی‌تواند پاکش کند —
    #    بکاپ فقط فایل‌هایی را دارد که از **قبل** بودند.
    NEWFILES="$NEWFILES
$dest"
    echo "NEW   $rel   (فایلِ تازه — روی سرور نیست)"; UPD=$((UPD+1)); return
  fi

  dest_n="$WORK/dest.tmp"; normalize "$dest" "$dest_n"

  case " $(echo $FORCE_FILES) " in *" $rel "*)
    if cmp -s "$dest_n" "$mine_f"; then
      echo "OK    $rel"; return
    fi
    [ "$DRY" = "0" ] && cp "$mine_f" "$dest"
    echo "FR    $rel   (جایگزینیِ اجباری)"
    UPD=$((UPD+1)); return
  ;; esac

  if cmp -s "$dest_n" "$mine_f"; then
    cmp -s "$dest" "$mine_f" || { [ "$DRY" = "0" ] && cp "$mine_f" "$dest"; echo "EOL   $rel   (فقط پایانِ خط)"; UPD=$((UPD+1)); return; }
    echo "OK    $rel"; return
  fi

  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$WORK/cand.raw" 2>/dev/null || continue
    normalize "$WORK/cand.raw" "$WORK/cand.tmp"
    if cmp -s "$dest_n" "$WORK/cand.tmp"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$dest_n" "$WORK/cand.tmp")
    if [ "$d" -lt "$bestd" ]; then bestd=$d; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "CF    $rel   ← در تاریخچهٔ develop نیست؛ نسخهٔ ناشناخته روی سرور — دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"
    return
  fi

  if [ "$bestd" -eq 0 ]; then
    [ "$DRY" = "0" ] && cp "$mine_f" "$dest"
    echo "UP    $rel   (سرور = $(git -C repo rev-parse --short "$best") — جایگزینیِ بی‌ریسک)"; UPD=$((UPD+1)); return
  fi

  git -C repo show "$best:website/$rel" > "$WORK/base.raw"
  normalize "$WORK/base.raw" "$base_f"
  m="$WORK/merged.tmp"; cp "$dest_n" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    [ "$DRY" = "0" ] && cp "$m" "$dest"
    echo "MG    $rel   (پایه $(git -C repo rev-parse --short "$best")، فاصلهٔ سرور $bestd خط — تغییرِ دیگران حفظ شد)"
    UPD=$((UPD+1))
  else
    echo "CF    $rel   ← تداخل واقعی؛ دست نخورد (پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط)"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
    echo "──── سرور − پایه ($rel) — این تکه در مخزن نیست:"
    diff -u "$base_f" "$dest_n" | sed -n '1,140p'
    echo "──── پایانِ diff"
  fi
}

echo "── بکاپ در: $BK"
echo
echo "═══ اپ ($APP) ═══"
for f in $APP_FILES; do apply_one "$f" "$APP"; done

echo
echo "═══ وب‌روت ($PUB) — public_html جداست از public اپ ═══"
for f in $PUB_FILES; do
  apply_one "$f" "$APP"
  rel_pub="${f#public/}"
  if [ -f "$APP/$f" ] && [ "$DRY" = "0" ]; then
    mkdir -p "$BK/public_html/$(dirname "$rel_pub")" "$PUB/$(dirname "$rel_pub")"
    [ -f "$PUB/$rel_pub" ] && cp -p "$PUB/$rel_pub" "$BK/public_html/$rel_pub"
    cp "$APP/$f" "$PUB/$rel_pub" && echo "PUB   $rel_pub"
  fi
done

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)

echo
union_ok=1
if [ -n "$PHPBIN" ]; then
  echo "═══ php -l روی فایل‌های نشسته ═══"
  for f in $APP_FILES $PUB_FILES; do
    case "$f" in *.php)
      [ -f "$APP/$f" ] || continue
      "$PHPBIN" -l "$APP/$f" >/dev/null 2>&1 || { echo "🔴 خطای نحو: $f"; union_ok=0; }
    ;; esac
  done
  [ "$union_ok" -eq 1 ] && echo "✅ نحو سالم"
fi

# ── پایانِ حالتِ آزمایشی ────────────────────────────────────────────────────
if [ "$DRY" != "0" ]; then
  echo
  echo "═══ حالتِ آزمایشی — هیچ فایلی روی سرور نوشته نشد ═══"
  echo "برنامه: $UPD فایل به‌روز یا تازه"
  if [ -n "$CONFLICTS" ]; then
    echo "🔴 تداخل:$CONFLICTS"
    echo "   پیش از دیپلویِ واقعی باید حل شود."
    exit 1
  fi
  echo "✅ هیچ تداخلی نیست — همین فرمان را بدونِ DRY=1 بزن."
  echo "⚠️ راستی‌آزمایی و پاکسازیِ کش فقط در اجرای واقعی می‌دوند."
  exit 0
fi

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────────
# 🔴 دیپلوی فایل‌به‌فایل است و «یک فایل جا ماند» فرضی نیست. این‌جا هر تکه‌ای که
#    نبودش یک خرابیِ **خاموش** می‌سازد جداگانه سنجیده می‌شود.
#
# ⚠️ هیچ گاردی «$» ندارد — و این عمدی است. گاردی که به نقل‌قول‌گذاریِ شل وابسته
#    باشد، روی سرور «ننشسته» گزارش می‌دهد در حالی که رشته در فایل هست، و کارِ
#    سالم را برمی‌گرداند. یک بار همین شد.
need_file() { [ -f "$1" ] || { echo "🔴 نیست: ${1#$APP/}"; union_ok=0; }; }

need_file "$APP/app/Support/AdminAlerts.php"
need_file "$APP/app/Models/MenuOverride.php"
need_file "$APP/app/Services/MenuManager.php"
need_file "$APP/app/Services/MenuTree.php"
need_file "$APP/app/Http/Controllers/Admin/MenuController.php"
need_file "$APP/app/Http/Controllers/Admin/SettingsController.php"
need_file "$APP/resources/views/admin/settings/menus.blade.php"
need_file "$APP/database/migrations/2026_10_07_000101_add_audience_to_notification_templates.php"
need_file "$APP/database/migrations/2026_10_08_000100_create_menu_overrides_table.php"
need_file "$APP/database/seeders/AdminNotificationTemplateSeeder.php"

g() { grep -qF "$2" "$APP/$1" 2>/dev/null || { echo "🔴 $1: «$2» ننشسته"; union_ok=0; }; }

# ═══ زنجیرهٔ اعلانِ مدیر ═══
#
# 🔴 هر تکه‌ای که جا بماند، خرابیِ **خاموش** است:
#    نقشه بی‌گیت = سوییچ کار نمی‌کند · گیت بی‌نقشه = هیچ رخدادی کلید نمی‌گیرد
#    · جدول بی‌seeder = صفحه خالی است و مدیر فکر می‌کند قابلیت نیامده
g app/Support/AdminAlerts.php "admin.payment_ok"
g app/Support/AdminAlerts.php "admin.cloud_lingering"
g app/Services/Notify/AdminNotifier.php "tagsFrom"
g app/Services/Notify/AdminNotifier.php "wanted("
g app/Models/NotificationTemplate.php "audience"
g app/Http/Controllers/Admin/NotificationTemplateController.php "toggle"
g app/Http/Controllers/Admin/SettingsController.php "adminGroups"
g resources/views/admin/settings/messages.blade.php "toggle"
g routes/web.php "templates/"
g routes/web.php "AdminNotificationTemplateSeeder"

# 🔴 دو فراخوانی که عنوانشان **ساخته** می‌شود و بی‌کلیدِ صریح از مدیریت بیرون
#    می‌افتند: می‌روند، ولی مدیر نه می‌بیندشان نه می‌تواند خاموششان کند.
g app/Services/Cloud/CloudProvisioner.php "admin.cloud_lingering"
g app/Services/Provisioning/ManualLifecycleNotice.php "admin.manual_lifecycle"

# ═══ زنجیرهٔ منو ═══
#
# 🔴 این سه یک واحدند و جداجدا معنا ندارند. فوتر روی **هر** صفحهٔ سایت است:
#    ویوِ تازه بدونِ `footer_menu` هیچ لینکی نشان نمی‌دهد و بدونِ `MenuManager`
#    اصلاً رندر نمی‌شود.
g config/servernet.php "footer_menu"
g app/Services/MenuManager.php "customHref"
g resources/views/partials/footer.blade.php "MenuManager"

g app/Services/MenuTree.php "withCustom"
g app/Http/Controllers/Admin/MenuController.php "RouteFacade"
g app/Http/Controllers/Admin/SettingsController.php "menusData"
g resources/views/admin/settings/menus.blade.php "menusNotReady"
g resources/views/partials/header.blade.php "MenuManager"
g app/Providers/AppServiceProvider.php "MenuManager"
g routes/web.php "menus/save"
g routes/web.php "menus/add"

# ⚠️ و روت‌هایی که نباید بیفتند (routes/web.php مشترک است)
for r in "name('gpu')" "name('go.pay')" "name('healthz')" "urmiaGone" "ack-manual" "resolve-release" "total_all_time"; do
  g routes/web.php "$r"
done

if [ "$union_ok" -eq 0 ]; then
  echo "🔴 اتحادِ فایل‌ها کامل نیست — کلِ بکاپ برمی‌گردد تا سایت ۵۰۰ نشود."
  ( cd "$BK" && find . -type f | while read -r p; do
      rel="${p#./}"
      case "$rel" in
        public_html/*) cp "$p" "$PUB/${rel#public_html/}" ;;
        *)             cp "$p" "$APP/$rel" ;;
      esac
      echo "   بازگشت: $rel"
    done )

  # و فایل‌هایی که خودمان ساختیم — بکاپ نسخهٔ قبلی‌شان را ندارد چون نبودند
  printf '%s
' "$NEWFILES" | while read -r p; do
    [ -n "$p" ] && [ -f "$p" ] && rm -f "$p" && echo "   حذفِ فایلِ تازه: ${p#$APP/}"
  done

  echo "🔴 دیپلوی ناتمام. خروجیِ بالا را بفرست."
  exit 1
fi

# ── پاکسازی کش ────────────────────────────────────────────────────────────
#
# ⚠️ کشِ صفحه این بار **حتماً** باید پاک شود: فوتر روی هر صفحه است، پس هر
#    صفحهٔ کش‌شده هنوز فوترِ قدیمی را نگه داشته.
if [ -n "$PHPBIN" ]; then
  cd "$APP"
  "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
  "$PHPBIN" artisan tinker --execute='\App\Http\Middleware\PageCache::purge(); echo "pagecache purged";' 2>/dev/null \
    || echo "⚠️ purge کشِ صفحه دستی: از /admin یا صبر تا TTL"
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
  echo "WARN: php پیدا نشد — کش‌ها دستی پاک شدند"
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · فایل‌های به‌روزشده: $UPD"
if [ -n "$CONFLICTS" ]; then
  echo "🔴 فایل‌های تداخل‌دار (دست‌نخورده، نیازمند merge دستی):$CONFLICTS"
  echo "   نسخه‌ها در $WORK/conflicts/ (پسوند .server / .base / .new)"
else
  echo "✅ هیچ تداخلی نبود"
fi
echo
echo "کارِ باقی‌مانده — به همین ترتیب:"
echo
echo "  ۱) ریستِ opcache از /system/opcache  (validate_timestamps=0)"
echo
echo "  ۲) مهاجرت + seeder از /system/migrate"
echo "     دو جدول/ستونِ تازه می‌آید و ۲۰ اعلانِ مدیر ردیف می‌گیرند."
echo "     ⚠️ تا این کار نکنی سایت **سالم** است ولی دو صفحهٔ تنظیمات"
echo "        می‌گویند «هنوز ساخته نشده» — این عمدی است، نه خرابی."
echo
echo "راستی‌آزمایی:"
echo "  · https://servernet.cloud/         ← فوتر باید همان ۲۷ لینکِ قبلی را"
echo "    داشته باشد. اگر ستونی خالی شد یعنی config ننشسته."
echo "  · https://servernet.cloud/en  و  /tr  ← باید ۲۰۰ بدهند."
echo "    🔴 «خدمات ما در ارومیه» فقط در فارسی است؛ اگر در en/tr دیده شد یا"
echo "       آن دو ۵۰۰ دادند، همان تلهٔ مرداد ۱۴۰۵ برگشته."
echo "  · /admin/settings?tab=menus      ← ۱۲۷ گره در پنج بخش"
echo "  · /admin/settings?tab=messages   ← بخشِ «اعلان‌های من» با ۲۰ رخداد"
