#!/usr/bin/env bash
#
# فاکتورِ مشتریِ حقوقی به نامِ شرکت — شهریور ۱۴۰۵.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-invoice-legal-buyer.sh)
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-invoice-legal-buyer.sh) [<SHA>]
#
# چه چیزی دیپلوی می‌شود:
#   · Customer::billingProfile() — انتخابِ پروفایلِ حقوقی برای فاکتور
#   · CustomerProfile::invoiceIdentity()/invoiceAddress() — شناسه‌ها و نشانی
#   · PaymentController::printInvoice() — پاس‌دادنِ بلوکِ خریدار به ویو
#   · invoice-print.blade.php — چاپِ نامِ شرکت به‌جای نامِ شخص
#   · سه فایلِ زبان — کلیدِ تازهٔ invp_postal_code
#
# 🔴 چرا لازم شد: بلوکِ خریدار همیشه نامِ شخصِ حقیقیِ صاحبِ حساب را می‌نشاند.
#    مشتری‌ای که پروفایلِ حقوقی‌اش را پر کرده بود، هم روی پیش‌فاکتور و هم روی
#    فاکتورِ فروش نامِ خودش را می‌دید — سندی که برای دفاترِ آن شرکت بی‌مصرف
#    است و ارزش افزوده‌اش هم قابلِ استفاده نیست.
#
# ⚠️ مهاجرت ندارد. فایلِ استاتیک ندارد. routes را دست نمی‌زند.
#
# 🔴 ترتیب حیاتی است و ویو **آخر** است. ویو به متغیرِ buyerName تکیه دارد که
#    کنترلر می‌سازد؛ اگر ویو زودتر بنشیند، هر چاپِ فاکتور با «متغیرِ
#    تعریف‌نشده» ۵۰۰ می‌دهد — برای همهٔ مشتری‌ها، نه فقط حقوقی‌ها.
#
# منطق عیناً از scripts/deploy-finance-phase1.sh.
set -u

DRY="${DRY:-0}"

APP="$HOME/servernet_app"
WORK="$HOME/deploy-invoice-legal-buyer"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=80

# ═══ 🔴 اثباتِ مقصد — پیش از هر نوشتنی ═══
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "🔴 «$APP» نصبِ لاراول نیست (artisan یا vendor نیست)."
  echo "   احتمالاً با کاربرِ اشتباه واردید. کاربرِ درست: servernetcloud"
  echo "   چاره:  su - servernetcloud   و بعد همین دستور را دوباره بزنید."
  exit 1
fi

FREE_MB=$(df -Pm "$HOME" | awk 'NR==2{print $4}')
if [ "${FREE_MB:-0}" -lt 500 ]; then
  echo "🔴 فضای آزاد کم است (${FREE_MB}MB). اول پاک‌سازی کنید:  rm -rf ~/deploy-*/repo"
  exit 1
fi

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
MINE="${1:-27427702}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# ترتیب: زبان → مدل‌ها → کنترلر → ویو. ویو آخر است چون به متغیرهای کنترلر
# تکیه دارد؛ کنترلر بعد از مدل‌هاست چون متدهایشان را صدا می‌زند.
APP_FILES="
lang/fa/ui.php
lang/en/ui.php
lang/tr/ui.php
app/Models/Customer.php
app/Models/CustomerProfile.php
app/Http/Controllers/Account/PaymentController.php
resources/views/account/invoice-print.blade.php
"

PUB_FILES=""

CONFLICTS=""
CREATED=""
UPD=0

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }

# ═══ شمارشِ کرون و روت، پیش از هر نوشتنی ═══
CRON_BEFORE=0
if [ -f "$APP/routes/console.php" ]; then
  CRON_BEFORE=$(grep -c 'Schedule::command' "$APP/routes/console.php" 2>/dev/null || echo 0)
fi
echo "── کرونِ فعلیِ سرور: $CRON_BEFORE فرمان (این دیپلوی نباید عوضش کند)"

ROUTES_BEFORE=0
if [ -f "$APP/routes/web.php" ]; then
  ROUTES_BEFORE=$(grep -c 'Route::' "$APP/routes/web.php" 2>/dev/null || echo 0)
fi
echo "── روت‌های فعلیِ سرور: $ROUTES_BEFORE"

# ═══ 🔴 شمارشِ کلیدِ فایل‌های زبان ═══
#
# سه فایلِ زبان هرکدام هزاران کلید دارند و merge سه‌طرفه می‌تواند بی‌صدا یک
# بلوکِ کاملِ کلیدها را بردارد. کلیدِ گم‌شده خطا نمی‌دهد — لاراول خودِ نامِ
# کلید را چاپ می‌کند، و آن را فقط وقتی می‌بینیم که مشتری صفحه را باز کند.
# این دیپلوی به هر فایل **دقیقاً یک** کلید اضافه می‌کند.
LANG_BEFORE=""
for lf in fa en tr; do
  n=$(grep -cF "' =>" "$APP/lang/$lf/ui.php" 2>/dev/null || echo 0)
  LANG_BEFORE="$LANG_BEFORE $lf:$n"
done
echo "── کلیدهای زبانِ فعلی:$LANG_BEFORE (هرکدام باید دقیقاً +۱ شود)"

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
  exit 0
fi

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────────
need_file() { [ -f "$1" ] || { echo "🔴 نیست: ${1#$APP/}"; union_ok=0; }; }

need_file "$APP/app/Models/Customer.php"
need_file "$APP/app/Models/CustomerProfile.php"
need_file "$APP/app/Http/Controllers/Account/PaymentController.php"
need_file "$APP/resources/views/account/invoice-print.blade.php"

# `--` اجباری است: هر الگویی که با `-` شروع شود را grep گزینه می‌خواند.
# و stderr خفه نمی‌شود — گاردی که بی‌صدا شکست بخورد از نبودنش بدتر است.
g() {
  err=$(grep -qF -- "$2" "$APP/$1" 2>&1) && return 0
  [ -n "$err" ] && echo "   (grep گفت: $err)"
  echo "🔴 $1: «$2» ننشسته"
  union_ok=0
}

# ── 🔴 مهم‌ترین گاردِ این فهرست ──
#
# ویو بدونِ کنترلر = «متغیرِ تعریف‌نشده buyerName» روی **هر** چاپِ فاکتور،
# برای همهٔ مشتری‌ها. این بدترین حالتِ ممکنِ این دیپلوی است.
g resources/views/account/invoice-print.blade.php "buyerName"
g app/Http/Controllers/Account/PaymentController.php "buyerName"
g app/Http/Controllers/Account/PaymentController.php "buyerIdentity"
g app/Http/Controllers/Account/PaymentController.php "billingProfile()"

# و کنترلر بدونِ مدل‌ها = «متدِ ناموجود» در همان مسیر.
g app/Models/Customer.php "public function billingProfile"
g app/Models/CustomerProfile.php "public function invoiceIdentity"
g app/Models/CustomerProfile.php "public function invoiceAddress"
g lang/fa/ui.php "invp_postal_code"

# ── ⚠️ کارِ دیگران که این merge نباید برش دارد ──
#
# هر سه فایل پرکارند و هیچ‌کدامِ این متدها اگر بی‌صدا برداشته شوند خطای
# فوری نمی‌دهند — فقط مسیرهای دیگری خراب می‌شوند.
g app/Models/Customer.php "public function defaultProfile"
g app/Models/Customer.php "public function displayName"
g app/Models/Customer.php "public function creditBalance"
g app/Models/Customer.php "public function isNameLocked"

g app/Models/CustomerProfile.php "public function setSecure"
g app/Models/CustomerProfile.php "public function getSecure"
g app/Models/CustomerProfile.php "public static function findBySecure"
g app/Models/CustomerProfile.php "public function displayName"

g app/Http/Controllers/Account/PaymentController.php "public function payCredit"
g app/Http/Controllers/Account/PaymentController.php "public function printInvoice"

# مهرِ شرکت و چیدمانِ چاپ روی همین ویو نشسته‌اند.
g resources/views/account/invoice-print.blade.php "pay-seal"
g resources/views/account/invoice-print.blade.php "size:A4 portrait"

# ═══ 🔴 کلیدهای زبان: دقیقاً یکی اضافه، نه یکی کم ═══
LANG_AFTER=""
lang_ok=1
for lf in fa en tr; do
  before=$(echo "$LANG_BEFORE" | tr ' ' '\n' | grep "^$lf:" | cut -d: -f2)
  after=$(grep -cF "' =>" "$APP/lang/$lf/ui.php" 2>/dev/null || echo 0)
  LANG_AFTER="$LANG_AFTER $lf:$after"
  expected=$((before+1))
  if [ "$after" -ne "$expected" ]; then
    echo "🔴 lang/$lf/ui.php: $before → $after (انتظار $expected). merge کلید انداخته یا برداشته."
    lang_ok=0
  fi
done
echo "═══ کلیدهای زبان:$LANG_BEFORE →$LANG_AFTER ═══"
if [ "$lang_ok" -eq 1 ]; then
  echo "✅ هر سه فایل دقیقاً یک کلید گرفتند"
else
  union_ok=0
fi

# ═══ روت و کرون نباید عوض شده باشند ═══
ROUTES_AFTER=$(grep -c 'Route::' "$APP/routes/web.php" 2>/dev/null || echo 0)
echo
echo "═══ شمارشِ روت: پیش $ROUTES_BEFORE → پس $ROUTES_AFTER ═══"
if [ "$ROUTES_AFTER" -ne "$ROUTES_BEFORE" ]; then
  echo "🔴 تعدادِ روت عوض شد — این دیپلوی routes/web.php را دست نمی‌زند."
  union_ok=0
else
  echo "✅ هیچ روتی گم نشد"
fi

CRON_AFTER=$(grep -c 'Schedule::command' "$APP/routes/console.php" 2>/dev/null || echo 0)
echo
echo "═══ شمارشِ کرون: پیش $CRON_BEFORE → پس $CRON_AFTER ═══"
if [ "$CRON_AFTER" -ne "$CRON_BEFORE" ]; then
  echo "🔴 تعدادِ کرون عوض شد — این دیپلوی routes/console.php را دست نمی‌زند."
  union_ok=0
else
  echo "✅ کرون دست‌نخورده"
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

# ── پاکسازیِ کش ────────────────────────────────────────────────────────────
# ⚠️ view:clear این‌جا واقعاً لازم است — تنها تغییرِ ویویِ این دیپلوی وگرنه
#    از کشِ کامپایل‌شده سرو می‌شود و «دیپلوی شد ولی اثر ندارد» می‌دهد.
if [ -n "$PHPBIN" ]; then
  cd "$APP"
  "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
  echo "WARN: php پیدا نشد — کش‌ها دستی پاک شدند"
fi

echo
echo "══════════ دیپلوی تمام ══════════"
echo "بکاپ: $BK   · فایل‌های به‌روزشده: $UPD"
if [ -n "$CONFLICTS" ]; then
  echo "🔴 فایل‌های تداخل‌دار (دست‌نخورده، نیازمند merge دستی):$CONFLICTS"
  echo "   نسخه‌ها در $WORK/conflicts/ (پسوند .server / .base / .new)"
else
  echo "✅ هیچ تداخلی نبود"
fi
echo
echo "⚠️ حالا opcache را از /system/opcache ریست کنید — بی‌ریست، بایت‌کدِ کهنه"
echo "   اجرا می‌شود و تغییر اثر ندارد."
echo
echo "═══ چطور بفهمید کار کرد ═══"
echo "  ۱. مشتری‌ای را که پروفایلِ حقوقی دارد پیدا کنید."
echo "  ۲. یکی از فاکتورهایش را باز کنید: /account/invoices/<id>/print"
echo "  ۳. بخشِ «خریدار» باید نامِ شرکت، شناسهٔ ملی و نشانیِ شرکت را بدهد،"
echo "     نه نامِ شخصِ صاحبِ حساب."
echo
echo "═══ چه چیزی این دیپلوی درست نمی‌کند ═══"
echo "  · هویت **زنده** خوانده می‌شود، نه منجمد روی فاکتور. اگر مشتری فردا"
echo "    نامِ شرکتش را عوض کند، فاکتورهای قدیمی‌اش هم عوض می‌شوند."
echo "  · مشتریِ حقیقی هنوز کد ملی روی فاکتورش نمی‌آید — خارج از این تغییر."
