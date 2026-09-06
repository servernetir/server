#!/usr/bin/env bash
#
# پوشاندنِ شمارهٔ کاملِ کارت در متنِ تیکت‌ها — شهریور ۱۴۰۵.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-redact-cards.sh)
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-redact-cards.sh) [<SHA>]
#
# چه چیزی دیپلوی می‌شود:
#   · CardRedactor — تشخیص و پوشاندنِ PAN در هر متنِ آزاد
#   · mutatorِ TicketMessage::body — هیچ شمارهٔ کارتی دیگر ذخیره نمی‌شود
#   · فرمانِ security:redact-cards — بازبینی و پاک‌سازیِ ردیف‌های کهنه
#   · مهاجرتِ داده‌ایِ 2026_09_28_000101 — همان پاک‌سازی از راهِ /system/migrate
#
# 🔴 چرا لازم شد: مشتری برای دریافتِ عودتِ وجه شمارهٔ کاملِ ۱۶ رقمیِ کارتش را
#    در متنِ تیکت نوشت. پول برگشت، تیکت بسته شد، و آن شماره دست‌نخورده در
#    `ticket_messages` ماند. قاعدهٔ «PAN کامل ذخیره نمی‌شود» در مسیرِ احرازِ
#    بانکی پیاده شده بود — آن‌جا کارت یک فیلدِ فرم است. متنِ تیکت هیچ فیلدی
#    ندارد، پس آن محافظ اصلاً سرِ راه نبود.
#
# 🔴 این دیپلوی **دو نیمه** دارد و نیمهٔ دوم دستی است:
#      نیمهٔ ۱ (همین اسکریپت) کد را می‌نشانَد ⇒ از این لحظه چیزی ذخیره نمی‌شود.
#      نیمهٔ ۲ ردیف‌های **کهنه** را پاک می‌کند و برگشت‌ناپذیر است، پس اسکریپت
#      فقط گزارشِ خشک می‌دهد و فرمانش را چاپ می‌کند. خودش نمی‌نویسد.
#
# ⚠️ فایلِ استاتیک ندارد. مهاجرت **دارد** ولی اسکریپت `migrate` نمی‌زند: آن
#    فرمان همهٔ مهاجرت‌های معلقِ دیگران را هم می‌دواند. پاک‌سازی از راهِ فرمانِ
#    اختصاصی انجام می‌شود که دقیقاً همین یک کار را می‌کند.
#
# منطق عیناً از scripts/deploy-hetzner-storage.sh: merge سه‌طرفه با پایهٔ
# خودکار به‌ازای هر فایل (UP/MG/CF) + بکاپ کامل + یکسان‌سازیِ پایانِ خط.
set -u

DRY="${DRY:-0}"

APP="$HOME/servernet_app"
WORK="$HOME/deploy-redact-cards"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=80

# ═══ 🔴 اثباتِ مقصد — پیش از هر نوشتنی ═══
#
# اجرا با کاربرِ اشتباه یعنی $HOME عوض می‌شود، فایل‌ها جایی می‌نشینند که سایت
# آن‌جا نیست، و گاردِ اتحاد **سبز** می‌شود چون همان فایل‌هایی را می‌سنجد که
# خودش تازه ساخته.
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
MINE="${1:-635aa70e}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# 🔴 ترتیب معنادار است: اول کلاس‌های مستقلِ تازه، بعد config، بعد مدل، بعد
#    رجیستریِ درایور، و آخر کنترلر و ویو. اگر اجرا وسطِ کار بمیرد، حالتِ
#    میانی باید «قابلیت هنوز نیست» باشد، نه «قابلیت هست ولی کلاسش نیست».
# 🔴 ترتیب معنادار است: اول کلاسِ مستقل، بعد مدلی که صدایش می‌زند، بعد
#    فرمان و مهاجرت. اگر اجرا وسطِ کار بمیرد، حالتِ میانی باید «محافظ هنوز
#    نیست» باشد، نه «مدل کلاسی را صدا می‌زند که روی سرور نیست» — که یعنی
#    **هر پاسخِ تیکت** با Class not found می‌ترکد.
APP_FILES="
app/Support/CardRedactor.php
app/Models/TicketMessage.php
app/Models/Ticket.php
app/Console/Commands/RedactStoredCards.php
database/migrations/2026_09_28_000101_redact_stored_card_numbers.php
"

PUB_FILES=""

CONFLICTS=""
CREATED=""
UPD=0

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }

# ═══ شمارشِ کرونِ سرور، پیش از هر نوشتنی ═══
# این دیپلوی routes/console.php را دست نمی‌زند، پس عدد باید **دقیقاً** ثابت
# بماند. هر تغییری یعنی چیزی غیرمنتظره رخ داده.
CRON_BEFORE=0
if [ -f "$APP/routes/console.php" ]; then
  CRON_BEFORE=$(grep -c 'Schedule::command' "$APP/routes/console.php" 2>/dev/null || echo 0)
fi
echo "── کرونِ فعلیِ سرور: $CRON_BEFORE فرمان (این دیپلوی نباید عوضش کند)"

# این دیپلوی routes/web.php را دست نمی‌زند، پس عدد باید **دقیقاً** ثابت بماند.
ROUTES_BEFORE=0
if [ -f "$APP/routes/web.php" ]; then
  ROUTES_BEFORE=$(grep -c 'Route::' "$APP/routes/web.php" 2>/dev/null || echo 0)
fi
echo "── روت‌های فعلیِ سرور: $ROUTES_BEFORE"

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
    # فایلِ تازه بکاپ ندارد، پس حلقهٔ بازگشت نمی‌بیندش. این‌جا ثبت می‌شود تا
    # بازگشت واقعاً کامل باشد.
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
# 🔴 دیپلوی فایل‌به‌فایل است و «یک فایل جا ماند» فرضی نیست. هر خرابیِ ممکن
#    این‌جا **خاموش** است: کلاسِ نبود ⇒ سفارشِ مشتری با «Class not found»
#    شکست می‌خورد و فقط در لاگِ کرون دیده می‌شود.
need_file() { [ -f "$1" ] || { echo "🔴 نیست: ${1#$APP/}"; union_ok=0; }; }

need_file "$APP/app/Support/CardRedactor.php"
need_file "$APP/app/Console/Commands/RedactStoredCards.php"
need_file "$APP/database/migrations/2026_09_28_000101_redact_stored_card_numbers.php"

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
# CardRedactor روی سرور بنشیند ولی mutator ننشیند = دقیقاً وضعیتِ امروز، با
# یک کلاسِ بلااستفاده. هیچ خطایی هم نمی‌دهد، پس دیپلوی «موفق» به‌نظر می‌رسد.
g app/Models/TicketMessage.php "protected function body(): Attribute"
g app/Models/TicketMessage.php "CardRedactor::mask"
g app/Models/TicketMessage.php "use App\Support\CardRedactor;"
g app/Support/CardRedactor.php "public static function mask"

# موضوعِ تیکت هم متنِ مشتری است و همان محافظ را لازم دارد. جا انداختنش یعنی
# پاک‌سازیِ یک‌بارهٔ ردیف‌های کهنه و پُر شدنِ دوبارهٔ ستون فردا.
g app/Models/Ticket.php "protected function subject(): Attribute"
g app/Models/Ticket.php "CardRedactor::mask"

# ── ⚠️ کارِ دیگران که این merge نباید برش دارد ──
#
# TicketMessage.php کوچک است ولی دست‌خورده: اگر پایه‌یاب پایهٔ اشتباه بگیرد،
# merge می‌تواند بی‌صدا این‌ها را بردارد و هیچ‌کدام خطا نمی‌دهند.
g app/Models/TicketMessage.php "public function attachments()"
g app/Models/TicketMessage.php "public function fromStaff()"
g app/Models/TicketMessage.php "'is_internal' => 'boolean'"
# 🔴 این یکی از یک اشتباهِ واقعیِ همین کار آمده: فایل در checkoutِ کهنه
#    ویرایش و روی نسخهٔ تازهٔ develop کپی شد و staffDisplayName() یک همکار را
#    بی‌صدا پاک کرد. تست‌ها گرفتندش؛ فهرستِ گارد نگرفت، چون خودِ فهرست از
#    همان فایلِ کهنه نوشته شده بود.
g app/Models/TicketMessage.php "public function staffDisplayName()"
g app/Models/Ticket.php "public function addMessage("
g app/Models/Ticket.php "public function scopeQueue("

# ═══ کرون نباید عوض شده باشد ═══
ROUTES_AFTER=$(grep -c 'Route::' "$APP/routes/web.php" 2>/dev/null || echo 0)
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
# ⚠️ مهاجرت این‌جا اجرا **نمی‌شود**. `artisan migrate` همهٔ مهاجرت‌های معلقِ
#    دیگران را هم می‌دواند و این اسکریپت مالکِ آن تصمیم نیست.
if [ -n "$PHPBIN" ]; then
  cd "$APP"
  "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
  echo "WARN: php پیدا نشد — کش‌ها دستی پاک شدند"
fi

echo
echo "══════════ نیمهٔ ۱ تمام ══════════"
echo "بکاپ: $BK   · فایل‌های به‌روزشده: $UPD"
if [ -n "$CONFLICTS" ]; then
  echo "🔴 فایل‌های تداخل‌دار (دست‌نخورده، نیازمند merge دستی):$CONFLICTS"
  echo "   نسخه‌ها در $WORK/conflicts/ (پسوند .server / .base / .new)"
else
  echo "✅ هیچ تداخلی نبود"
fi
echo
echo "از این لحظه هیچ شمارهٔ کارتِ تازه‌ای ذخیره نمی‌شود."
echo "⚠️ اول opcache را از /system/opcache ریست کنید — بی‌ریست، کدِ تازه اجرا"
echo "   نمی‌شود و گزارشِ زیر روی کدِ **قدیمی** گرفته می‌شود."
echo

# ═══ نیمهٔ ۲ — ردیف‌های کهنه ═══
#
# 🔴 عمداً فقط گزارشِ خشک. بازنویسیِ متنِ تیکتِ مشتری برگشت‌ناپذیر است و
#    اسکریپتی که آدم با کنجکاوی اجرایش می‌کند نباید خودش انجامش دهد. همان
#    قاعده‌ای که خودِ فرمان هم دارد: نوشتن فقط با --force.
if [ -n "$PHPBIN" ] && [ -f "$APP/app/Console/Commands/RedactStoredCards.php" ]; then
  echo "═══ گزارشِ خشک: چه چیزی روی دیتابیسِ زنده هست ═══"
  ( cd "$APP" && "$PHPBIN" artisan security:redact-cards ) || true
  echo
  echo "برای پاک‌کردنِ واقعی (برگشت‌ناپذیر):"
  echo
  echo "  cd $APP && $PHPBIN artisan security:redact-cards --force"
  echo
  echo "⚠️ خروجی فقط تکهٔ ماسک‌شده را چاپ می‌کند، نه متنِ خصوصیِ مشتری."
  echo "   ولی همان تکه هم BIN و چهار رقمِ آخر را دارد؛ ترمینال را جایی باز"
  echo "   نکنید که اسکرین‌شات می‌شود."
fi

echo
echo "═══ چه چیزی این دیپلوی درست نمی‌کند ═══"
echo "  · ایمیل و پیامکی که آن شماره را قبلاً برده‌اند برگشتنی نیستند."
echo "  · فقط متنِ تیکت و موضوعِ تیکت پوشانده می‌شود. اگر روزی جای دیگری"
echo "    متنِ آزادِ مشتری ذخیره شد، همان محافظ را آن‌جا هم لازم دارید —"
echo "    قاعدهٔ «PAN کامل ذخیره نمی‌شود» یک بار در مسیرِ بانکی پیاده شده بود"
echo "    و همین‌جا از کنارش رد شد."