#!/usr/bin/env bash
# انتشار محافظت‌شدهٔ Analytics Growth v2 از ترمینال cPanel کاربر servernetcloud.
# ابتدا: DRY=1 bash <(curl -fsSL .../develop/scripts/deploy-analytics-growth-v2.sh) <SHA>
# سپس فقط اگر همهٔ فایل‌ها OK/UP/NEW بودند، همان فرمان بدون DRY اجرا شود.
set -u

DRY="${DRY:-0}"
MINE="${1:?SHA دقیق نسخهٔ هدف الزامی است}"
APP="$HOME/servernet_app"
WORK="$HOME/deploy-analytics-growth-v2"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$WORK/backup-$STAMP"
STAGE="$WORK/stage-$STAMP"
HIST=160

if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "FATAL: $APP نصب واقعی Laravel نیست؛ با کاربر servernetcloud اجرا کنید."
  exit 1
fi

FREE_MB="$(df -Pm "$HOME" | awk 'NR==2{print $4}')"
if [ "${FREE_MB:-0}" -lt 500 ]; then
  echo "FATAL: فضای آزاد کمتر از 500MB است."
  exit 1
fi

mkdir -p "$WORK" "$BK" "$STAGE" "$WORK/conflicts"
if [ -d "$WORK/repo/.git" ]; then
  git -C "$WORK/repo" fetch --depth 500 origin develop || exit 1
else
  git clone --depth 500 --branch develop https://github.com/servernetir/server.git "$WORK/repo" || exit 1
fi
git -C "$WORK/repo" rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || {
  echo "FATAL: کامیت $MINE در مخزن نیست."; exit 1;
}

APP_FILES="
app/Http/Middleware/SecurityHeaders.php
app/Services/Analytics/DataLayerService.php
config/services.php
resources/views/account/checkout.blade.php
resources/views/account/invoice.blade.php
resources/views/layouts/site.blade.php
resources/views/pages/cloud-location.blade.php
resources/views/pages/hosting.blade.php
resources/views/pages/order-summary.blade.php
resources/views/partials/analytics-consent.blade.php
resources/views/partials/gtm-body.blade.php
resources/views/partials/gtm-head.blade.php
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
  echo "MG   $rel (base $(git -C "$WORK/repo" rev-parse --short "$best"))"
  mkdir -p "$STAGE/$(dirname "$rel")"; cp "$merged" "$STAGE/$rel"
  UPD=$((UPD+1))
}

for rel in $APP_FILES; do apply_one "$rel"; done

if [ -n "$CONFLICTS" ]; then
  echo "FATAL: تداخل:$CONFLICTS"
  echo "جزئیات: $WORK/conflicts"
  exit 2
fi
if [ "$DRY" = "1" ]; then echo "DRY OK — $UPD فایل نیازمند تغییر است؛ هیچ فایلی نوشته نشد."; exit 0; fi

# بعد از موفقیت کل preflight، همهٔ فایل‌ها یک‌جا اعمال می‌شوند؛ بنابراین تداخل
# در فایل دوازدهم، یازده فایل اول را نیمه‌منتشر نمی‌کند.
for rel in $APP_FILES; do
  [ -f "$STAGE/$rel" ] || continue
  dest="$APP/$rel"
  mkdir -p "$BK/$(dirname "$rel")" "$(dirname "$dest")"
  if [ -f "$dest" ]; then cp -p "$dest" "$BK/$rel"; else echo "$rel" >> "$BK/.new-files"; fi
  cp "$STAGE/$rel" "$dest"
done

PHP_BIN="$(command -v php)"
for rel in app/Http/Middleware/SecurityHeaders.php app/Services/Analytics/DataLayerService.php config/services.php; do
  "$PHP_BIN" -l "$APP/$rel" >/dev/null || { echo "FATAL lint: $rel"; exit 3; }
done
cd "$APP" || exit 1
"$PHP_BIN" artisan optimize:clear || exit 4
"$PHP_BIN" artisan view:cache || exit 4
echo "DEPLOY OK — backup: $BK"
echo "Rollback: فایل‌های backup را به $APP برگردانید؛ مسیرهای .new-files را حذف و optimize:clear اجرا کنید."
