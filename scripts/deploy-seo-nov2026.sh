#!/usr/bin/env bash
#
# دیپلوی کارِ سئوی ممیزی نوامبر ۲۰۲۶ روی سرور cPanel.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/seo/nov2026-gsc/scripts/deploy-seo-nov2026.sh)
#
# ⚠️ آدرسِ بالا به **شاخه** است نه SHA، و عمدی است: اسکریپت نمی‌تواند به
#    کامیتی اشاره کند که خودش در آن است (هر اصلاحِ هدر، SHA را عوض می‌کند).
#    پینِ ایمنی آن چیزی است که اهمیت دارد و پایین‌تر است: `MINE` که **محتوای
#    نشستنی** را تعیین می‌کند و به یک SHA تغییرناپذیر بسته شده. شاخهٔ
#    seo/nov2026-gsc برای همین کار است و جلو نمی‌رود؛ اگر روزی رفت، SHA را
#    صریح پاس بدهید:  … /scripts/deploy-seo-nov2026.sh) 7f293eaa
#
# ⚠️ نوکِ develop دیپلوی نمی‌شود: روی develop ~۳۵۰ فایلِ کامیت‌نشده هست و
#    routes/web.phpِ نوک به کنترلرهایی اشاره می‌کند که روی سرور نیستند
#    ⇒ ۵۰۰ سراسری.
#
# ═══ چه چیزی می‌نشیند ═══
#   • صفحهٔ /vps/discord (هاست بات دیسکورد) در هر سه زبان + ثبت در مگامنو
#   • ادغام /vps/gpu در /gpu — ۳۰۱، حذف از sitemap و llms.txt و نوار «مرتبط»
#   • عنوان و توضیح تراکنشی برای ۱۲ صفحهٔ سرور اختصاصی و ۳ صفحهٔ نمایندگی
#   • کانونیکال‌سازی هر زیردامنهٔ غیرمجاز (رفعِ ۳۱۳ نشانیِ mail.servernet.cloud)
#   • ۸ ریدایرکت ۳۰۱ برای اسلاگ‌های فارسیِ وردپرسِ قدیمی
#   • rel="nofollow" روی لینک‌های /account/* در صفحات /vps/* و /dedicated/*
#
# ═══ پایهٔ خودکار به ازای هر فایل (درسِ ۲۸ مرداد) ═══
#
# merge سه‌طرفه با پایهٔ اشتباه، «نداشتنِ» کدِ جدید در کپیِ سرور را «حذفِ عمدی»
# می‌خوانَد و روت را می‌انداخت ⇒ ۵۰۰ سراسری. پس برای هر فایل:
#   ۱) اگر فایلِ سرور دقیقاً برابرِ یکی از نسخه‌های تاریخیِ همان فایل است
#      ⇒ کسی دست نزده ⇒ نسخهٔ تازه مستقیم می‌نشیند (UP).
#   ۲) وگرنه ⇒ merge سه‌طرفه با **نزدیک‌ترین** نسخهٔ تاریخی به‌عنوان پایه (MG).
#   ۳) تداخلِ واقعی ⇒ فایل دست‌نخورده، فقط گزارش (CF).
#
# 🔴 این‌جا بندِ ۲ حتماً اتفاق می‌افتد و باید بیفتد: سرور در config/hosting.php
#    از مخزن **جلوتر** است (هاست ویندوز، نمایندگی ویندوز و seo_tِ هاست پایتون
#    روی سرور هستند و در مخزن نیستند). merge با پایهٔ درست، هر سهِ آن‌ها را نگه
#    می‌دارد و فقط ۶ بلوکِ تازه را اضافه می‌کند. اگر این فایل CF خورد، **دستی
#    هم اضافه نکن** — گزارش را بخوان.
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-seo-nov2026"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=60
BRANCH="seo/nov2026-gsc"

# ═══ 🔴 اثباتِ مقصد — پیش از هر نوشتنی ═══
#
# اجرا با کاربرِ اشتباه (root به‌جای servernetcloud) یعنی $HOME عوض می‌شود،
# فایل‌ها جایی می‌نشینند که سایت آن‌جا نیست، و گاردِ اتحاد **سبز** می‌شود چون
# همان فایل‌هایی را می‌سنجد که خودش تازه ساخته.
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "🔴 «$APP» نصبِ لاراول نیست (artisan یا vendor نیست)."
  echo "   احتمالاً با کاربرِ اشتباه واردید. کاربرِ درست: servernetcloud"
  echo "   چاره:  su - servernetcloud   و بعد همین دستور را دوباره بزنید."
  exit 1
fi

FREE_MB=$(df -Pm "$HOME" | awk 'NR==2{print $4}')
if [ "${FREE_MB:-0}" -lt 500 ]; then
  echo "🔴 فضای آزاد کم است (${FREE_MB}MB). اول پاک‌سازی کنید:"
  echo "   rm -rf ~/deploy-*/repo"
  exit 1
fi

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }

if [ -d repo/.git ]; then
  git -C repo fetch --depth 400 origin "$BRANCH" || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 400 --branch "$BRANCH" https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

MINE="${1:-7f293eaa}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 \
  || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# ── فهرست فایل‌ها (نسبت به website/ در مخزن) ─────────────────────────────
#
# 🔴 ترتیب مهم است و دو وابستگیِ واقعی دارد:
#
#   • SiteController متدِ استاتیکِ CatalogController::mergedToGpu() را صدا
#     می‌زند. اگر SiteController بنشیند و CatalogController نه، sitemap.xml و
#     llms.txt هر دو Fatal می‌دهند. پس کنترلرِ تعریف‌کننده **اول**.
#   • config/servernet.php در مگامنو به اسلاگِ `discord` لینک می‌دهد؛ اگر منو
#     بنشیند و کاتالوگ نه، آن آیتم ۴۰۴ می‌شود. پس کاتالوگ **اول**.
#   • routes/web.php آخر، مثل همیشه.
APP_FILES="
config/catalog/vps.php
config/catalog/dedicated.php
config/hosting.php
config/servernet.php
app/Http/Middleware/ConsoleHost.php
app/Http/Controllers/CatalogController.php
app/Http/Controllers/SiteController.php
resources/views/pages/hosting.blade.php
tests/Feature/ConsoleHostTest.php
tests/Feature/LegacyPersianRedirectTest.php
tests/Feature/AccountLinksAreNofollowTest.php
tests/Feature/DiscordAndGpuMergeTest.php
routes/web.php
"

CONFLICTS=""
UPD=0

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

apply_one() {
  rel="$1"
  dest="$APP/$rel"
  mine_f="$WORK/mine.tmp"; base_f="$WORK/base.tmp"

  git -C repo show "$MINE:website/$rel" > "$mine_f" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; return; }

  if [ -f "$dest" ]; then
    mkdir -p "$BK/$(dirname "$rel")"
    cp -p "$dest" "$BK/$rel"
  fi

  if [ ! -f "$dest" ]; then
    mkdir -p "$(dirname "$dest")"
    cp "$mine_f" "$dest"; echo "NEW   $rel"; UPD=$((UPD+1)); return
  fi

  if cmp -s "$dest" "$mine_f"; then echo "OK    $rel"; return; fi

  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$WORK/cand.tmp" 2>/dev/null || continue
    if cmp -s "$dest" "$WORK/cand.tmp"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$dest" "$WORK/cand.tmp")
    if [ "$d" -lt "$bestd" ]; then bestd=$d; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "CF    $rel   ← در تاریخچه نیست؛ نسخهٔ ناشناخته روی سرور — دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"
    return
  fi

  if [ "$bestd" -eq 0 ]; then
    cp "$mine_f" "$dest"
    echo "UP    $rel   (سرور = $(git -C repo rev-parse --short "$best"))"
    UPD=$((UPD+1)); return
  fi

  git -C repo show "$best:website/$rel" > "$base_f"
  m="$WORK/merged.tmp"; cp "$dest" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"
    echo "MG    $rel   (پایه $(git -C repo rev-parse --short "$best")، فاصلهٔ سرور $bestd خط — تغییرِ دیگران حفظ شد)"
    UPD=$((UPD+1))
  else
    echo "CF    $rel   ← تداخل واقعی؛ دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
}

echo "── بکاپ در: $BK"
for f in $APP_FILES; do apply_one "$f"; done

# ═══ ضمانتِ اتحاد — چیزهایی که نبودشان سایت را می‌شکند ═══
union_ok=1
g() { grep -qF -- "$2" "$APP/$1" 2>/dev/null || { echo "🔴 $1 :: $2"; union_ok=0; }; }

# 🔴 وابستگیِ متقابل: اگر یکی بنشیند و دیگری نه، sitemap.xml و llms.txt Fatal
#    می‌دهند — و sitemap.xml دقیقاً چیزی است که گوگل هر روز می‌خوانَد.
g app/Http/Controllers/CatalogController.php "MERGED_TO_GPU"
g app/Http/Controllers/CatalogController.php "public static function mergedToGpu"
g app/Http/Controllers/SiteController.php    "CatalogController::mergedToGpu"

# صفحهٔ دیسکورد و لینکِ منویش — یکی بی‌دیگری یعنی ۴۰۴ در مگامنو
g config/catalog/vps.php "'discord' => ["
g config/servernet.php   "'slug' => 'discord'"
# آیتمِ GPU منو باید به روت رفته باشد، نه اسلاگِ ۳۰۱خور
g config/servernet.php   "['route' => ['gpu', []]"

# کانونیکال‌سازی میزبان
g app/Http/Middleware/ConsoleHost.php "SERVING_HOSTS"
g app/Http/Middleware/ConsoleHost.php "isStrayHost"

# nofollow شرطی + لینکِ لنگردارِ GPU
g resources/views/pages/hosting.blade.php '$storeRel'
g resources/views/pages/hosting.blade.php "ui.gpu_h1"

# 🔴 روتی که فوتر/هدر می‌زنند؛ افتادنش = ۵۰۰ سراسری (درسِ ۲۸ مرداد)
for r in "name('aup')" "name('vps.hourly')" "name('cloud.index')" "name('domain.search')" "name('gpu')"; do
  grep -qF -- "$r" "$APP/routes/web.php" || { echo "🔴 routes/web.php: $r گم شده"; union_ok=0; }
done

# ⚠️ کلیدِ زبانِ لنگرِ GPU: نبودش ۵۰۰ نمی‌دهد ولی متنِ خام «ui.gpu_h1» روی
#    صفحه چاپ می‌شود. هشدار، نه بلاکر — چون این دیپلوی lang/ را دست نمی‌زند.
for l in fa en tr; do
  grep -q "'gpu_h1'" "$APP/lang/$l/ui.php" 2>/dev/null \
    || echo "⚠️  lang/$l/ui.php کلیدِ gpu_h1 را ندارد — لنگرِ GPU متنِ خام نشان می‌دهد"
done

if [ "$union_ok" -eq 0 ]; then
  echo "🔴 اتحادِ فایل‌ها کامل نیست — routes/web.php را از بکاپ برمی‌گردانم تا سایت ۵۰۰ نشود."
  [ -f "$BK/routes/web.php" ] && cp "$BK/routes/web.php" "$APP/routes/web.php" \
    && echo "   routes/web.php از بکاپ برگشت"
  echo "   بقیه را دستی بررسی کن؛ بکاپِ کامل: $BK"
fi

# ── کش‌ها: تا پاک نشوند config و روت و ویوِ تازه دیده نمی‌شوند ─────────────
PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
if [ -n "${PHPBIN:-}" ]; then
  cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
  echo "WARN: php پیدا نشد — کش‌ها دستی پاک شدند"
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · فایل‌های به‌روزشده: $UPD"
if [ -n "$CONFLICTS" ]; then
  echo "🔴 تداخل (دست‌نخورده ماندند):$CONFLICTS"
  echo "   سه نسخهٔ هرکدام در $WORK/conflicts/"
else
  echo "✅ بدون تداخل"
fi
echo
echo "── راستی‌آزمایی بعدی (از مرورگر یا همین‌جا با curl) ──"
echo "  /vps/discord  /en/vps/discord  /tr/vps/discord   → باید ۲۰۰ باشند"
echo "  /vps/gpu                                          → باید ۳۰۱ به /gpu باشد"
echo "  /dedicated/managed                                → عنوانش باید «سرور اختصاصی مدیریت‌شده | …» باشد"
echo "  /host                                             → باید ۳۰۱ به /hosting/linux باشد"
echo "  mail.servernet.cloud/privacy                      → باید ۳۰۱ به servernet.cloud/privacy باشد"
echo "  /sitemap.xml                                      → باید /vps/discord داشته باشد و /vps/gpu نداشته باشد"
