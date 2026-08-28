#!/usr/bin/env bash
#
# دیپلوی ممیزیِ دور نهم — شهریور ۱۴۰۵.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-audit-r9.sh) [<SHA>]
#
# چه چیزی دیپلوی می‌شود:
#   · نامِ محصول به انگلیسی و ترکی (ستون‌های name_en/name_tr + displayName)
#     ⇒ ۱۳۴ صفحهٔ /en/order/* و /tr/order/* دیگر نامِ فارسی نشان نمی‌دهند
#   · مالیاتِ ارز-آگاه: تومان مشمول ارزش افزوده، یورو نه (effectiveTaxPercent)
#   · بندِ صریحِ ارزش افزوده در /terms، هر سه زبان
#   · «Buy» از عنوانِ صفحاتِ en/tr برداشته شد (درگاهِ یورو نداریم)
#   · MailboxReplier دیگر از MAIL_MAILER رد نمی‌شود
#
# منطق عیناً از scripts/deploy-gpu.sh: merge سه‌طرفه با پایهٔ خودکار به‌ازای
# هر فایل (UP/MG/CF) + بکاپ کامل + یکسان‌سازیِ پایانِ خط پیش از هر مقایسه.
# ⚠️ تلهٔ CRLF: بی‌normalize، پایه‌یاب روی سرور کور می‌شود.
set -u

# ── حالتِ آزمایشی ──────────────────────────────────────────────────────────
#
#   DRY=1 bash <(curl -fsSL .../deploy-audit-r9.sh)
#
# 🔴 اول این را بزن. قاعدهٔ ثبت‌شدهٔ این پروژه: «پروداکشن زیرمجموعهٔ develop
# است» و فایلی که با هیچ کامیتی نمی‌خواند اغلب کارِ **ازقبل‌دیپلوی‌شدهٔ**
# session دیگری است، نه خرابی. پس اول گزارش، بعد نوشتن.
#
# ⚠️ حالتِ آزمایشی هیچ عارضه‌ای ندارد — نه فایل، نه مهاجرت، نه پاک‌کردنِ کش،
# نه seeder.
DRY="${DRY:-0}"

APP="$HOME/servernet_app"
WORK="$HOME/deploy-audit-r9"
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
#
# ⚠️ چرا این کامیت: کامیتِ ادغامِ audit-r9 در develop. هیچ‌کدام از ۱۰ فایلِ
#    این دیپلوی با کارِ session‌های دیگر (خطِ ابری/GPU/حواله) مشترک نیست —
#    با merge-tree بررسی شد و همپوشانیِ فایلی **صفر** بود. پس این دیپلوی و
#    دیپلویِ ابری می‌توانند به هر ترتیبی بدوند.
MINE="${1:-6633743}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# 🔴 ترتیب معنادار است: اول مدل (منبعِ displayName و مالیات)، بعد سرویس و
#    فرمان، بعد کنترلر، بعد ویو و زبان، بعد config، و آخر مهاجرت.
APP_FILES="
app/Models/Product.php
app/Services/Mail/MailboxReplier.php
app/Console/Commands/SeedHostingProducts.php
app/Http/Controllers/OrderSummaryController.php
resources/views/pages/order-summary.blade.php
lang/fa/ui.php
lang/en/ui.php
lang/tr/ui.php
config/pages.php
database/migrations/2026_10_01_000101_add_localized_name_to_products.php
"

# ⚠️ این دیپلوی فایلِ استاتیکِ تازه ندارد (هیچ CSS/JSی عوض نشده).
PUB_FILES=""

CONFLICTS=""
UPD=0

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }

# ═══ جایگزینیِ اجباری ═══
# ⚠️ خالی و باید خالی بمانَد: هیچ فایلی در این دیپلوی «ادغامِ ازپیش‌انجام‌شدهٔ
# کارِ سرور» نیست.
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
  echo "⚠️ راستی‌آزمایی، مهاجرت، seeder و پاکسازیِ کش فقط در اجرای واقعی می‌دوند."
  exit 0
fi

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────────
# 🔴 چرا لازم است: دیپلوی فایل‌به‌فایل است. اگر مدل بنشیند و ویو نه، صفحه
#    همان نامِ فارسی را نشان می‌دهد؛ اگر کلیدِ زبان نه، کاربر «ui.os_tax_none»
#    می‌بیند. هر دو با کدِ ۲۰۰.
need_file() { [ -f "$1" ] || { echo "🔴 نیست: ${1#$APP/}"; union_ok=0; }; }

need_file "$APP/database/migrations/2026_10_01_000101_add_localized_name_to_products.php"

g() { grep -qF "$2" "$APP/$1" 2>/dev/null || { echo "🔴 $1: «$2» ننشسته"; union_ok=0; }; }

# نامِ سه‌زبانه — زنجیرهٔ کامل: ستون → مدل → seeder → کنترلر → ویو
g app/Models/Product.php "public function displayName"
g app/Models/Product.php "name_en"
g app/Console/Commands/SeedHostingProducts.php "name_en"
g app/Http/Controllers/OrderSummaryController.php "displayName"
g resources/views/pages/order-summary.blade.php "displayName"

# مالیاتِ ارز-آگاه — تنها دروازه، و هر پنج فراخوان از همان می‌پرسند
g app/Models/Product.php "effectiveTaxPercent"
g app/Http/Controllers/OrderSummaryController.php "sellsInCurrentLocale"

# بندِ ارزش افزوده در /terms، هر سه زبان
g config/pages.php "مالیات بر ارزش افزوده: سفارش"
g config/pages.php "Value added tax: orders"
g config/pages.php "Katma değer vergisi"

# MailboxReplier دیگر از MAIL_MAILER رد نمی‌شود
g app/Services/Mail/MailboxReplier.php "mail.default"

# کلیدهای زبان — هر سه فایل، وگرنه یک زبان متنِ خام نشان می‌دهد
for L in fa en tr; do
  g "lang/$L/ui.php" "os_tax_none"
  g "lang/$L/ui.php" "os_cta_quote"
  g "lang/$L/ui.php" "eu_data_title"
done

if [ "$union_ok" -eq 0 ]; then
  echo "🔴 اتحادِ فایل‌ها کامل نیست — کلِ بکاپ برمی‌گردد تا سایت ۵۰۰ نشود."
  ( cd "$BK" && find . -type f | while read -r p; do
      rel="${p#./}"
      cp "$p" "$APP/$rel"
      echo "   بازگشت: $rel"
    done )
  echo "🔴 دیپلوی ناتمام. خروجیِ بالا را بفرست."
  exit 1
fi

# ── مهاجرت + پرکردنِ ترجمهٔ محصولاتِ موجود + پاکسازی کش ────────────────────
if [ -n "$PHPBIN" ]; then
  cd "$APP"
  echo
  echo "═══ مهاجرتِ ستون‌های name_en / name_tr ═══"
  # ⚠️ بی‌این مهاجرت، هر صفحهٔ سفارش ۵۰۰ می‌دهد (مدل ستونِ نبود را می‌خوانَد).
  "$PHPBIN" artisan migrate --force \
    --path=database/migrations/2026_10_01_000101_add_localized_name_to_products.php \
    || { echo "🔴 مهاجرت نخورد — صفحاتِ سفارش ۵۰۰ می‌دهند. خروجی را بفرست."; }

  echo
  echo "═══ پرکردنِ ترجمهٔ نامِ محصولاتِ موجود ═══"
  # 🔴 بی‌این گام، مهاجرت و کدِ تازه **هیچ اثری روی سایت نمی‌گذارند**:
  #    ستون‌ها null می‌مانند، displayName به فارسی برمی‌گردد، و همان ۱۳۴ صفحه
  #    دقیقاً مثلِ دیروز نامِ فارسی نشان می‌دهند — با کدِ ۲۰۰ و بی‌هیچ خطایی.
  # ⚠️ عمداً **بدونِ** --force: قیمت و مشخصاتِ ویرایش‌شدهٔ مدیر دست نمی‌خورد.
  #    این اجرا فقط ستونِ خالیِ نام را پر می‌کند.
  "$PHPBIN" artisan products:seed-hosting \
    || { echo "🔴 seeder نخورد — نامِ محصولات بی‌ترجمه می‌مانَد. خروجی را بفرست."; }

  "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
  "$PHPBIN" artisan tinker --execute='\App\Http\Middleware\PageCache::purge(); echo "pagecache purged";' 2>/dev/null \
    || echo "⚠️ purge کشِ صفحه دستی: از /admin یا صبر تا TTL"
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
  echo "WARN: php پیدا نشد — کش‌ها دستی پاک شدند و مهاجرت اجرا **نشد**"
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
echo "راستی‌آزمایی (بعد از ریستِ opcache) — پیش از دیپلوی این‌ها فارسی بودند:"
echo "  curl -s https://servernet.cloud/en/order/backup-1 | grep -o '<title>[^<]*</title>'"
echo "     ← باید انگلیسی باشد و «Buy» نداشته باشد"
echo "  curl -s https://servernet.cloud/tr/order/backup-1 | grep -o '<title>[^<]*</title>'"
echo "     ← باید ترکی باشد"
echo "  curl -s https://servernet.cloud/order/backup-1 | grep -o '<title>[^<]*</title>'"
echo "     ← فارسی، دست‌نخورده"
echo "  curl -s https://servernet.cloud/en/terms | grep -c 'Value added tax'    ← 1"
