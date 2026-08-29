#!/usr/bin/env bash
#
# دیپلوی موتور محتوای ۱۴۰۵ — شهریور ۱۴۰۵.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-content-1405.sh)
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-content-1405.sh) [<SHA>]
#
# چه چیزی دیپلوی می‌شود:
#   · سه برنامهٔ محتوای تازه (۵۴۱ موضوع) + کرونی که مصرفشان می‌کند
#   · ContentCalendar — زمان‌بندیِ ۲ تا ۵ مطلب در روز تا ۲۹ اسفند ۱۴۰۵
#   · InternalLinks — لینکِ داخلیِ واقعی به‌جای آدرسِ حدسیِ مدل
#   · پرامپتِ بازنویسی‌شدهٔ نگارش + FAQPage JSON-LD روی بلاگ و مستندات
#   · چکِ سلامتِ «صف محتوا» تا ته‌کشیدنِ صف دیگر بی‌صدا نماند
#
# 🔴 چرا این دیپلوی لازم شد: بلاگ از ۲۵ مرداد هیچ مطلبی منتشر نکرده بود.
#    plan.php هر ۱۰۲ موضوعش مصرف شده بود و content:generate هر روز سرِ ساعت
#    می‌دوید، «همه ساخته شده‌اند ✓» می‌گفت و با کدِ خروجیِ **موفق** برمی‌گشت.
#
# منطق عیناً از scripts/deploy-gpu.sh: merge سه‌طرفه با پایهٔ خودکار به‌ازای
# هر فایل (UP/MG/CF) + بکاپ کامل + یکسان‌سازیِ پایانِ خط پیش از هر مقایسه.
# ⚠️ تلهٔ CRLF: بی‌normalize، پایه‌یاب روی سرور کور می‌شود.
set -u

DRY="${DRY:-0}"

APP="$HOME/servernet_app"
WORK="$HOME/deploy-content-1405"
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
MINE="${1:-ba1becd}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# 🔴 ترتیب معنادار است: اول سرویس‌های مستقل، بعد فرمان‌ها، بعد دادهٔ برنامه،
#    بعد ویو، و آخر routes/console.php (تنها فایلی که کرون در آن است).
APP_FILES="
app/Services/ContentCalendar.php
app/Services/InternalLinks.php
app/Services/AiContent.php
app/helpers.php
app/Console/Commands/GenerateContent.php
app/Console/Commands/CheckContentLinks.php
resources/content/blog-1405.php
resources/content/kb-1405.php
resources/content/docs-1405.php
resources/views/pages/blog-post.blade.php
resources/views/pages/docs-article.blade.php
app/Services/SystemHealth.php
routes/console.php
"

# ⚠️ این دیپلوی نه فایلِ استاتیک دارد نه مهاجرت: جدولِ posts و post_translations
#    از قبل هست و هیچ ستونی اضافه نشده. اگر روزی مهاجرت اضافه شد، این کامنت را
#    عوض کن — نه اینکه بی‌صدا اجرا نشود.
PUB_FILES=""

CONFLICTS=""
CREATED=""     # فایل‌های تازه‌ساخته‌شده — برای بازگشتِ کامل
UPD=0

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }

# ═══ شمارشِ کرونِ سرور، پیش از هر نوشتنی ═══
#
# 🔴 قاعدهٔ ثبت‌شدهٔ این پروژه: routes/php سرور drift می‌کند و رونویسی‌اش
#    **بی‌صدا کرون پاک می‌کند** — یک بار دقیقاً همین شد. merge سه‌طرفه باید
#    حفظشان کند، ولی «باید» کافی نیست: بعد از نوشتن دوباره می‌شماریم و اگر کم
#    شده باشد کلِ بکاپ برمی‌گردد.
CRON_BEFORE=0
if [ -f "$APP/routes/console.php" ]; then
  CRON_BEFORE=$(grep -c 'Schedule::command' "$APP/routes/console.php" 2>/dev/null || echo 0)
fi
echo "── کرونِ فعلیِ سرور: $CRON_BEFORE فرمان"

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
    # 🔴 فایلِ تازه بکاپ ندارد (چیزی نبوده که بکاپ شود)، پس حلقهٔ بازگشت
    #    نمی‌بیندش و روی سرور جا می‌مانَد. اجرای اول دقیقاً همین شد: ۸ فایل
    #    برگشت و ۵ فایلِ تازه ماندند — یعنی «بازگشتِ کامل» دروغ بود.
    #    این‌جا ثبتشان می‌کنیم تا بازگشت واقعاً کامل باشد.
    [ "$DRY" = "0" ] && { mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"; CREATED="$CREATED $rel"; }
    echo "NEW   $rel   (فایلِ تازه — روی سرور نیست)"; UPD=$((UPD+1)); return
  fi

  dest_n="$WORK/dest.tmp"; normalize "$dest" "$dest_n"

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

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)

echo
union_ok=1
if [ -n "$PHPBIN" ]; then
  echo "═══ php -l روی فایل‌های نشسته ═══"
  for f in $APP_FILES; do
    case "$f" in *.php)
      [ -f "$APP/$f" ] || continue
      "$PHPBIN" -l "$APP/$f" >/dev/null 2>&1 || { echo "🔴 خطای نحو: $f"; union_ok=0; }
    ;; esac
  done
  [ "$union_ok" -eq 1 ] && echo "✅ نحو سالم"
fi

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
  echo "⚠️ راستی‌آزماییِ اتحاد و پاکسازیِ کش فقط در اجرای واقعی می‌دوند."
  exit 0
fi

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────────
# 🔴 دیپلوی فایل‌به‌فایل است و «یک فایل جا ماند» فرضی نیست. این‌جا هر خرابیِ
#    ممکن **خاموش** است: برنامهٔ نبود ⇒ کرون هر روز «چیزی نیست» می‌گوید؛
#    ContentCalendarِ نبود ⇒ فرمان ۵۰۰ می‌دهد ولی فقط در لاگِ کرون.
need_file() { [ -f "$1" ] || { echo "🔴 نیست: ${1#$APP/}"; union_ok=0; }; }

need_file "$APP/app/Services/ContentCalendar.php"
need_file "$APP/app/Services/InternalLinks.php"
need_file "$APP/resources/content/blog-1405.php"
need_file "$APP/resources/content/kb-1405.php"
need_file "$APP/resources/content/docs-1405.php"

# 🔴 `--` اجباری است، و نبودش اجرای اول را برگرداند.
#
# الگوی این دیپلوی `--plan=blog-1405` است و grep هر آرگومانی که با `-` شروع
# شود را **گزینه** می‌خواند نه الگو:
#
#     grep -qF "--plan=blog-1405" file
#     → grep: unknown option -- plan=blog-1405     (خروجیِ ۲، یعنی «پیدا نشد»)
#
# و چون خطای استاندارد با `2>/dev/null` خفه شده بود، از بیرون دقیقاً شبیهِ
# «ننشسته» دیده می‌شد. نتیجه: دیپلویِ کاملاً سالم (شمارشِ کرون ۴۱→۴۳ درست بود)
# با یک گاردِ معیوب برگردانده شد.
#
# ⚠️ خفه‌کردنِ stderr همان چیزی بود که خطا را نامرئی کرد. حالا اگر grep خودش
# شکایتی داشته باشد، متنش چاپ می‌شود — گاردی که بی‌صدا شکست بخورد، از نبودنش
# بدتر است.
g() {
  err=$(grep -qF -- "$2" "$APP/$1" 2>&1) && return 0
  [ -n "$err" ] && echo "   (grep گفت: $err)"
  echo "🔴 $1: «$2» ننشسته"
  union_ok=0
}

# زنجیرهٔ تولید — هر حلقه بیفتد، تولید بی‌صدا می‌ایستد
g app/Services/ContentCalendar.php "PLAN_UNTIL_JYEAR"
g app/Services/ContentCalendar.php "nextSlot"
g app/Console/Commands/GenerateContent.php "ContentCalendar"
g app/Console/Commands/GenerateContent.php "InternalLinks"
g app/Console/Commands/GenerateContent.php "promptBlock"

# لینکِ داخلی — هر دو لایه باید بنشیند، وگرنه مدل دوباره آدرس می‌سازد
g app/Services/InternalLinks.php "public function sanitize"
g app/Services/InternalLinks.php "public function localize"
g app/Services/AiContent.php "internal_links"

# ⚠️ قاعدهٔ لینکِ محصولِ ممیزیِ سوم که develop اضافه کرده بود — این دیپلوی
#    نباید برش دارد. اگر merge بدش را انجام داده باشد، همین‌جا لو می‌رود.
g app/Services/AiContent.php "related_product"

# ⚠️ تابعِ site_social که develop به helpers اضافه کرده — نباید قربانیِ
#    افزودنِ article_faq_ld شود (هر دو ته همان فایل می‌نشینند).
g app/helpers.php "article_faq_ld"
g app/helpers.php "site_social"

# schemaِ پرسش روی هر دو نوعِ صفحه
g resources/views/pages/blog-post.blade.php "article_faq_ld"
g resources/views/pages/docs-article.blade.php "article_faq_ld"

# پایشِ صف — بی‌این، همان سکوتِ مرداد دوباره ممکن است
g app/Services/SystemHealth.php "contentQueue"

# 🔴 هر چهار برنامه در کرون. اگر یکی بیفتد، همان بخش از سایت ساکت می‌شود.
g routes/console.php "--plan=blog-1405"
g routes/console.php "--plan=kb-1405"
g routes/console.php "--plan=docs-1405"
g routes/console.php "--plan=docs-plan"
g routes/console.php "content:publish-due"

# ═══ کرون کم نشده باشد ═══
#
# 🔴 قاعدهٔ ثبت‌شده: رونویسیِ routes/console.php یک بار بی‌صدا کرون‌ها را پاک
#    کرد. این دیپلوی دو فرمانِ content:generate را با چهارتا عوض می‌کند، پس
#    انتظار +۲ است. هر عددِ کمتر از قبل یعنی چیزی گم شده.
CRON_AFTER=$(grep -c 'Schedule::command' "$APP/routes/console.php" 2>/dev/null || echo 0)
echo
echo "═══ شمارشِ کرون: پیش $CRON_BEFORE → پس $CRON_AFTER ═══"
if [ "$CRON_AFTER" -lt "$CRON_BEFORE" ]; then
  echo "🔴 تعدادِ کرون **کم شد** — رونویسی چیزی را پاک کرده."
  union_ok=0
else
  echo "✅ هیچ کرونی گم نشد"
fi

if [ "$union_ok" -eq 0 ]; then
  echo "🔴 اتحادِ فایل‌ها کامل نیست — کلِ بکاپ برمی‌گردد تا سایت ۵۰۰ نشود."
  ( cd "$BK" && find . -type f | while read -r p; do
      rel="${p#./}"
      cp "$p" "$APP/$rel"
      echo "   بازگشت: $rel"
    done )
  for rel in $CREATED; do
    rm -f "$APP/$rel" && echo "   حذفِ فایلِ تازه: $rel"
  done
  echo "🔴 دیپلوی ناتمام. خروجیِ بالا را بفرست."
  exit 1
fi

# ── پاکسازیِ کش (مهاجرتی در کار نیست) ──────────────────────────────────────
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
echo "کارِ باقی‌مانده: ریستِ opcache از /system/opcache (validate_timestamps=0 — بی‌ریست کدِ تازه اجرا نمی‌شود)"
echo
echo "═══ راستی‌آزمایی (بعد از ریستِ opcache) ═══"
echo "  $PHPBIN artisan content:generate --plan=blog-1405 --dry --limit=3"
echo "      ← باید «باقی‌مانده در برنامه: ۲۶۹» و ظرفیتِ تقویم را نشان دهد"
echo "  $PHPBIN artisan schedule:list | grep content"
echo "      ← باید ۵ خط باشد (publish-due + چهار برنامه) + translate-missing"
echo "  $PHPBIN artisan links:content"
echo "      ← تا امروز با «no such column: body» می‌ترکید؛ حالا باید گزارش بدهد"
echo
echo "═══ اولین تولید (اختیاری — بی‌این، کرونِ فردا خودش شروع می‌کند) ═══"
echo "  $PHPBIN artisan content:generate --plan=blog-1405 --limit=1"
echo "      ← یک مقاله می‌سازد؛ خروجی باید «لینکِ داخلی: N» با N>0 بدهد"
echo "  ⚠️ هر مقاله سه تماسِ هوش مصنوعی است (نگارشِ فارسی + دو ترجمه)."
echo
echo "═══ سلامت ═══"
echo "  /admin/errors ← چکِ «صف محتوا» باید بعد از اولین تولید سبز شود."
echo "     تا وقتی هیچ پیش‌نویسی زمان‌بندی نشده، عمداً قرمز است — همان سکوتی"
echo "     که ۱۲ روز کسی ندید."
