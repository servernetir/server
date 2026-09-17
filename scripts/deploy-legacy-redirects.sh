#!/usr/bin/env bash
# انتشارِ ریدایرکتِ آدرس‌های قدیمیِ وردپرس (LegacyUrlResolver) از ترمینالِ cPanel
# با کاربرِ servernetcloud.
#
#   ۱) DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/fix/legacy-redirects/scripts/deploy-legacy-redirects.sh) <SHA>
#   ۲) فقط اگر همه OK/MG/NEW بودند، همان فرمان بدون DRY=1
#
# فقط سه فایلِ برنامه؛ نه مهاجرت، نه کرون، نه public_html.
set -u

DRY="${DRY:-0}"
MINE="${1:?SHA دقیق نسخهٔ هدف الزامی است}"
BRANCH="${BRANCH:-fix/legacy-redirects}"
APP="$HOME/servernet_app"
WORK="$HOME/deploy-legacy-redirects"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$WORK/backup-$STAMP"
STAGE="$WORK/stage-$STAMP"
HIST=160

# مقصد را پیش از نوشتن ثابت کن — نصبِ واقعی artisan و vendor دارد
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "FATAL: $APP نصب واقعی Laravel نیست؛ با کاربر servernetcloud اجرا کنید."
  exit 1
fi

if [ -x /opt/cpanel/ea-php84/root/usr/bin/php ]; then
  PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php
else
  PHP_BIN="$(command -v php 2>/dev/null || true)"
fi
if [ -z "$PHP_BIN" ] || ! "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then
  echo "FATAL: PHP 8.4 در دسترس نیست؛ هیچ فایلی نوشته نشد."
  exit 1
fi

FREE_MB="$(df -Pm "$HOME" | awk 'NR==2{print $4}')"
if [ "${FREE_MB:-0}" -lt 300 ]; then
  echo "FATAL: فضای آزاد کمتر از 300MB است."
  exit 1
fi

mkdir -p "$WORK" "$BK" "$STAGE" "$WORK/conflicts"
if [ -d "$WORK/repo/.git" ]; then
  git -C "$WORK/repo" fetch --depth 500 origin "$BRANCH" || exit 1
else
  git clone --depth 500 --branch "$BRANCH" https://github.com/servernetir/server.git "$WORK/repo" || exit 1
fi
git -C "$WORK/repo" rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || {
  echo "FATAL: کامیت $MINE در مخزن نیست."; exit 1;
}

APP_FILES="
config/legacy_urls.php
app/Support/LegacyUrlResolver.php
app/Http/Middleware/TrackNotFound.php
"

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }
distance() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]' || true; }
CONFLICTS=""
UPD=0

apply_one() {
  rel="$1"; dest="$APP/$rel"
  mine="$WORK/mine.tmp"; dest_n="$WORK/dest.tmp"; base="$WORK/base.tmp"
  git -C "$WORK/repo" show "$MINE:website/$rel" > "$WORK/mine.raw" 2>/dev/null || {
    echo "FATAL: $rel در نسخه هدف نیست"; CONFLICTS="$CONFLICTS $rel"; return;
  }
  normalize "$WORK/mine.raw" "$mine"

  if [ ! -f "$dest" ]; then
    echo "NEW  $rel"
    mkdir -p "$STAGE/$(dirname "$rel")"; cp "$mine" "$STAGE/$rel"
    UPD=$((UPD+1)); return
  fi
  normalize "$dest" "$dest_n"
  if cmp -s "$dest_n" "$mine"; then echo "OK   $rel"; return; fi

  best=""; bestd=999999999
  for sha in $(git -C "$WORK/repo" log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C "$WORK/repo" show "$sha:website/$rel" > "$WORK/candidate.raw" 2>/dev/null || continue
    normalize "$WORK/candidate.raw" "$WORK/candidate.tmp"
    if cmp -s "$dest_n" "$WORK/candidate.tmp"; then best="$sha"; bestd=0; break; fi
    d="$(distance "$dest_n" "$WORK/candidate.tmp")"
    if [ "$d" -lt "$bestd" ]; then bestd="$d"; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "CF   $rel — پایه تاریخی پیدا نشد"; CONFLICTS="$CONFLICTS $rel"; return
  fi
  git -C "$WORK/repo" show "$best:website/$rel" > "$WORK/base.raw" 2>/dev/null || true
  normalize "$WORK/base.raw" "$base"
  merged="$WORK/merged.tmp"
  if ! git merge-file -p "$dest_n" "$base" "$mine" > "$merged"; then
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine" "$keep.new"; cp "$merged" "$keep.merged"
    echo "CF   $rel — تداخل؛ فایل زنده دست نخورد"; CONFLICTS="$CONFLICTS $rel"; return
  fi
  echo "MG   $rel (base $(git -C "$WORK/repo" rev-parse --short "$best"), فاصله $bestd)"
  mkdir -p "$STAGE/$(dirname "$rel")"; cp "$merged" "$STAGE/$rel"
  UPD=$((UPD+1))
}

for rel in $APP_FILES; do apply_one "$rel"; done

if [ -n "$CONFLICTS" ]; then
  echo "FATAL: تداخل:$CONFLICTS"
  echo "جزئیات: $WORK/conflicts"
  exit 2
fi

# 🔴 میان‌افزار بدونِ کلاس = کلِ سایت ۵۰۰. هر دو باید در stage یا مقصد باشند.
for rel in app/Support/LegacyUrlResolver.php config/legacy_urls.php; do
  if [ ! -f "$STAGE/$rel" ] && [ ! -f "$APP/$rel" ]; then
    echo "FATAL: $rel نه در stage است نه روی سرور"; exit 2
  fi
done
for rel in $APP_FILES; do
  [ -f "$STAGE/$rel" ] || continue
  "$PHP_BIN" -l "$STAGE/$rel" >/dev/null || { echo "FATAL lint (stage): $rel"; exit 3; }
done
grep -q 'LegacyUrlResolver::resolve' "$STAGE/app/Http/Middleware/TrackNotFound.php" "$APP/app/Http/Middleware/TrackNotFound.php" 2>/dev/null \
  || { echo "FATAL: TrackNotFound نسخهٔ ادغام‌شده resolver را صدا نمی‌زند"; exit 3; }

if [ "$DRY" = "1" ]; then echo "DRY OK — $UPD فایل نیازمند تغییر است؛ هیچ فایلی نوشته نشد."; exit 0; fi

# اول کلاس و config، آخر میان‌افزار — تا هیچ لحظه‌ای میان‌افزار کلاسِ ناموجود را صدا نزند
for rel in config/legacy_urls.php app/Support/LegacyUrlResolver.php app/Http/Middleware/TrackNotFound.php; do
  [ -f "$STAGE/$rel" ] || continue
  dest="$APP/$rel"
  mkdir -p "$BK/$(dirname "$rel")" "$(dirname "$dest")"
  if [ -f "$dest" ]; then cp -p "$dest" "$BK/$rel"; else echo "$rel" >> "$BK/.new-files"; fi
  cp "$STAGE/$rel" "$dest"
done

for rel in $APP_FILES; do
  "$PHP_BIN" -l "$APP/$rel" >/dev/null || { echo "FATAL lint: $rel"; exit 3; }
done
# مقدار را از خودِ مقصد بسنج، نه از چاپِ موفقیت
grep -q 'LegacyUrlResolver::resolve' "$APP/app/Http/Middleware/TrackNotFound.php" || { echo "FATAL: میان‌افزار روی سرور resolver ندارد"; exit 3; }

cd "$APP" || exit 1
"$PHP_BIN" artisan config:clear || exit 4
"$PHP_BIN" artisan route:clear || exit 4

N="$("$PHP_BIN" -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo count((array) config("legacy_urls.exact"));')"
echo "نقشهٔ دقیق روی سرور: $N کلید"

echo "DEPLOY OK — backup: $BK"
echo "Rollback: فایل‌های $BK را به $APP برگردانید؛ مسیرهای .new-files را حذف و config:clear اجرا کنید."
