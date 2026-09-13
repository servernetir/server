#!/usr/bin/env bash
#
# تکمیلِ صفحهٔ «سرور مجازی ساعتی» — قطعاتِ گمشدهٔ کارِ ۲۲ اوت روی سرور.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA-این-اسکریپت>/scripts/deploy-vps-hourly-complete.sh) [<SHA>]
#   ← SHA پیش‌فرض 67f93a0 — همان نسخه‌ای که deploy-gsc-fixes نشاند تا همه‌چیز
#     هم‌نسخه بماند.
#
# ═══ چرا این اسکریپت ═══
#
# راستی‌آزماییِ بعد از deploy-gsc-fixes (۲۵ اوت) نشان داد /vps/hourly در هر
# سه زبان ۵۰۰ می‌دهد: روتش و ویویش روی سرور نشسته ولی
# HourlyVpsController، متدِ CloudPlan::hourlyIrt و کلیدهای hv_* زبان هرگز
# دیپلوی نشده بودند (کارِ سئوی 76ec369 فقط تا نیمه روی سرور رفته بود).
# بقیهٔ سایت ۲۰۰ است؛ این اسکریپت فقط زنجیرهٔ وابستگیِ همان یک صفحه را
# کامل می‌کند. همان مدلِ اثبات‌شده: پایهٔ خودکارِ هر فایل، merge سه‌طرفه،
# تداخل دست‌نخورده + گزارش، بکاپ، ensure-union.
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-gsc-fixes"          # همان کارگاهِ دیپلویِ قبلی — repo آماده است
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-hourly-$STAMP"
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
  git -C repo fetch --depth 400 origin develop seo/gsc-fixes-aug24 || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 400 --branch seo/gsc-fixes-aug24 https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

MINE="${1:-67f93a0}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# ── زنجیرهٔ وابستگیِ /vps/hourly (نسبت به website/) ──────────────────────
# ترتیب: اول مدل/سرویس، بعد کنترلر، بعد زبان و config، آخر ویوهای لینک‌دهنده.
APP_FILES="
app/Models/CloudPlan.php
app/Services/Cloud/CloudCountry.php
app/Http/Controllers/HourlyVpsController.php
lang/fa/ui.php
lang/en/ui.php
lang/tr/ui.php
config/catalog/vps.php
config/catalog/domain.php
config/servernet.php
config/pagecache.php
resources/views/pages/cloud.blade.php
resources/views/account/cloud-store.blade.php
tests/Feature/HourlyVpsPageTest.php
"

CONFLICTS=""
UPD=0

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
    cp "$mine_f" "$dest"; echo "UP    $rel   (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1)); return
  fi

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

# ── ضمانتِ اتحاد: هر حلقهٔ گمشده = ۵۰۰ ماندنِ /vps/hourly ────────────────
union_ok=1
[ -f "$APP/app/Http/Controllers/HourlyVpsController.php" ] || { echo "🔴 HourlyVpsController روی سرور نیست"; union_ok=0; }
[ -f "$APP/app/Services/Cloud/CloudCountry.php" ]          || { echo "🔴 CloudCountry روی سرور نیست"; union_ok=0; }
[ -f "$APP/resources/views/pages/vps-hourly.blade.php" ]   || { echo "🔴 ویوِ vps-hourly روی سرور نیست (deploy-gsc-fixes را اول بزن)"; union_ok=0; }
grep -q "function hourlyIrt" "$APP/app/Models/CloudPlan.php" 2>/dev/null \
  || { echo "🔴 CloudPlan::hourlyIrt روی سرور نیست"; union_ok=0; }
grep -q "HOURLY_START_MIN_HOURS" "$APP/app/Models/CloudPlan.php" 2>/dev/null \
  || { echo "🔴 CloudPlan::HOURLY_START_MIN_HOURS روی سرور نیست"; union_ok=0; }
for l in fa en tr; do
  grep -q "'hv_meta_t'" "$APP/lang/$l/ui.php" 2>/dev/null || { echo "🔴 lang/$l/ui.php کلیدهای hv_* را ندارد"; union_ok=0; }
done
# روت‌های حیاتیِ فوتر/هدر (درسِ ۲۸ مرداد) — این اسکریپت routes را دست نمی‌زند، فقط چک
for r in "name('aup')" "name('cloud.index')" "name('vps.hourly')" "name('domain.search')"; do
  grep -qF "$r" "$APP/routes/web.php" || { echo "🔴 routes/web.php: $r گم شده"; union_ok=0; }
done

[ "$union_ok" -eq 0 ] && echo "🔴 اتحاد ناقص — /vps/hourly احتمالاً هنوز ۵۰۰ است؛ گزارشِ بالا را بفرست."

# ── کش‌ها ────────────────────────────────────────────────────────────────
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
echo "  curl -s -o /dev/null -w '%{http_code}\\n' https://servernet.cloud/vps/hourly      ← باید 200 باشد"
echo "  curl -s -o /dev/null -w '%{http_code}\\n' https://servernet.cloud/en/vps/hourly   ← باید 200 باشد"
echo "  curl -s https://servernet.cloud/vps/hourly | grep -o '<title>[^<]*'"
echo "  curl -s -o /dev/null -w '%{http_code}\\n' https://servernet.cloud/cloud           ← باید 200 بماند"
