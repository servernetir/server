#!/usr/bin/env bash
#
# دیپلوی «سئوی فروش: صفحهٔ سرور مجازی ساعتی + عنوان‌های تراکنشی» روی سرور cPanel.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-seo-hourly.sh) [<SHA>]
#   ← SHA همان کامیتِ develop است که می‌خواهی بنشیند؛ پیش‌فرض 76ec369 = فقط همین
#     کارِ سئو. ⚠️ عمداً نوکِ develop نیست: روی develop کارِ دیپلوی‌نشدهٔ
#     همکاران (فروشگاه قطعات، اکسیت) هست و routes/web.phpِ نوک به کنترلرهایی
#     اشاره می‌کند که روی سرور نیستند ⇒ ۵۰۰ سراسری. آن‌ها دیپلوی خودشان را دارند.
#
# ═══ چرا با اسکریپتِ ممیزی ۳ فرق دارد: پایهٔ **خودکار به ازای هر فایل** ═══
#
# درسِ ۲۸ مرداد: merge سه‌طرفه با پایهٔ اشتباه، «نداشتنِ» کدِ جدید در کپیِ سرور
# را «حذفِ عمدی» تفسیر کرد و روتِ aup افتاد ⇒ ۵۰۰ سراسری. پایهٔ ثابتِ دست‌نویس
# دقیقاً همان ریسک است: اگر سرور هنوز نسخهٔ قدیمی‌تری از فایل را دارد، یک
# BASEِ تازه‌تر یعنی همهٔ تغییراتِ بینِ آن دو، «حذف‌شده توسط سرور» خوانده می‌شود.
#
# پس این‌جا برای هر فایل:
#   ۱) اگر فایلِ سرور **دقیقاً برابرِ یکی از نسخه‌های تاریخیِ همان فایل در
#      develop** است (۶۰ کامیتِ آخرِ آن فایل) ⇒ هیچ‌کس دست نزده ⇒ نسخهٔ جدید
#      مستقیم می‌نشیند (UP). پایه همان نسخهٔ تاریخی است، نه یک حدس.
#   ۲) اگر با هیچ نسخه‌ای برابر نیست ⇒ کسِ دیگری تغییرش داده ⇒ merge سه‌طرفه
#      با **نزدیک‌ترین** نسخهٔ تاریخی (کم‌ترین diff با فایلِ سرور) به‌عنوانِ پایه
#      (MG)؛ تداخلِ واقعی ⇒ فایل دست‌نخورده، فقط گزارش (CF). هرگز مارکرِ تداخل
#      روی سایتِ زنده نمی‌نشیند.
#   ۳) پیش از هر تغییری بکاپِ کامل در ~/deploy-seo-hourly/backup-<stamp>/.
#
# هیچ فایلِ استاتیکی (public_html) در این دیپلوی نیست — همه‌چیز در اپ است.
# bootstrap/app.php دست نمی‌خورد.
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-seo-hourly"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=60

# ═══ 🔴 اثباتِ مقصد — پیش از هر نوشتنی ═══
#
# درسِ ثبت‌شدهٔ این پروژه، که خودِ همین اسکریپت‌ها قربانی‌اش شدند: اجرا با
# کاربرِ اشتباه (مثلاً root به‌جای servernetcloud) یعنی $HOME عوض می‌شود،
# فایل‌ها در مسیری ساخته می‌شوند که سایت آن‌جا نیست، و گاردِ اتحاد **سبز**
# می‌شود چون همان فایل‌هایی را می‌سنجد که خودش تازه ساخته.
#
# پس مقصد باید *قبل* از نوشتن ثابت شود: نصبِ واقعی artisan و vendor دارد.
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "🔴 «$APP» نصبِ لاراول نیست (artisan یا vendor نیست)."
  echo "   احتمالاً با کاربرِ اشتباه واردید. کاربرِ درست: servernetcloud"
  echo "   چاره:  su - servernetcloud   و بعد همین دستور را دوباره بزنید."
  exit 1
fi

# فضای آزاد: دیپلوی روی دیسکِ پر، نیمه‌کاره می‌مانَد
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
  git -C repo fetch --depth 400 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 400 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

MINE="${1:-76ec369}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# ── فهرست فایل‌ها (نسبت به website/ در مخزن) ─────────────────────────────
# ترتیب مهم است: اول فایل‌های **مستقل** (کنترلر و ویوِ تازه، رشته‌ها، config)،
# بعد routes/web.php که به کنترلرِ تازه اشاره می‌کند. اگر روت اول بنشیند و
# کنترلر هنوز نرسیده باشد، تا رسیدنش هر درخواست ۵۰۰ می‌گیرد.
APP_FILES="
app/Http/Controllers/HourlyVpsController.php
resources/views/pages/vps-hourly.blade.php
lang/fa/ui.php
lang/en/ui.php
lang/tr/ui.php
config/catalog/vps.php
config/catalog/domain.php
config/servernet.php
config/pagecache.php
app/Http/Controllers/CloudCatalogController.php
app/Http/Controllers/SiteController.php
resources/views/pages/hosting.blade.php
resources/views/pages/cloud.blade.php
resources/views/account/cloud-store.blade.php
tests/Feature/HourlyVpsPageTest.php
CLAUDE.md
routes/web.php
"

CONFLICTS=""
UPD=0

# کم‌ترین فاصله: تعدادِ خطوطِ diff بینِ دو فایل
dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

apply_one() {                       # $1 = مسیر نسبی
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

  # ── پایهٔ خودکار: نزدیک‌ترین نسخهٔ تاریخیِ همین فایل به آنچه روی سرور است ──
  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$WORK/cand.tmp" 2>/dev/null || continue
    if cmp -s "$dest" "$WORK/cand.tmp"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$dest" "$WORK/cand.tmp")
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
    cp "$mine_f" "$dest"; echo "UP    $rel   (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1)); return
  fi

  # کس دیگری دست برده — merge سه‌طرفه روی کپی با نزدیک‌ترین پایه
  git -C repo show "$best:website/$rel" > "$base_f"
  m="$WORK/merged.tmp"; cp "$dest" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"; echo "MG    $rel   (پایه $(git -C repo rev-parse --short "$best")، فاصلهٔ سرور $bestd خط — تغییرِ دیگران حفظ شد)"; UPD=$((UPD+1))
  else
    echo "CF    $rel   ← تداخل واقعی؛ دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
}

echo "── بکاپ در: $BK"
for f in $APP_FILES; do apply_one "$f"; done

# ── ضمانتِ اتحاد: چیزهایی که اگر نباشند، سایت می‌شکند ───────────────────
# routes/web.php به HourlyVpsController اشاره می‌کند؛ اگر روت نشست ولی کنترلر
# نه (یا برعکس)، باید همین‌جا بفهمیم نه از ۵۰۰ِ مشتری.
union_ok=1
grep -q "HourlyVpsController" "$APP/routes/web.php" 2>/dev/null || { echo "🔴 routes/web.php روتِ vps.hourly را ندارد"; union_ok=0; }
[ -f "$APP/app/Http/Controllers/HourlyVpsController.php" ] || { echo "🔴 HourlyVpsController روی سرور نیست"; union_ok=0; }
[ -f "$APP/resources/views/pages/vps-hourly.blade.php" ] || { echo "🔴 ویوِ vps-hourly روی سرور نیست"; union_ok=0; }
for l in fa en tr; do
  grep -q "'hv_meta_t'" "$APP/lang/$l/ui.php" 2>/dev/null || { echo "🔴 lang/$l/ui.php کلیدهای hv_* را ندارد"; union_ok=0; }
done
# روتی که فوتر/هدر می‌زنند باید بمانند (درسِ ۲۸ مرداد)
for r in "name('aup')" "name('vps.hourly')" "name('cloud.index')" "name('domain.search')"; do
  grep -qF "$r" "$APP/routes/web.php" || { echo "🔴 routes/web.php: $r گم شده"; union_ok=0; }
done

if [ "$union_ok" -eq 0 ]; then
  echo "🔴 اتحادِ فایل‌ها کامل نیست — روتِ تازه را از روی سرور برمی‌دارم تا سایت ۵۰۰ نشود؛ بقیه را دستی بررسی کن."
  # برگرداندنِ routes/web.php از بکاپ، اگر بکاپی هست
  [ -f "$BK/routes/web.php" ] && cp "$BK/routes/web.php" "$APP/routes/web.php" && echo "   routes/web.php از بکاپ برگشت"
fi

# ── کش‌ها: تا پاک نشوند config/روت/ویوِ تازه دیده نمی‌شود ──────────────────
PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
if [ -n "$PHPBIN" ]; then
  cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
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
echo "راستی‌آزمایی:"
echo "  curl -sI https://servernet.cloud/vps/hourly | head -1          ← باید 200 باشد"
echo "  curl -s  https://servernet.cloud/vps/hourly | grep -o '<title>[^<]*'"
echo "  curl -s  https://servernet.cloud/vps/iran   | grep -o '<title>[^<]*'"
echo "  curl -s  https://servernet.cloud/sitemap.xml | grep -c vps/hourly  ← باید 3 باشد"
