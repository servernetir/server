#!/usr/bin/env bash
# اسنپ‌پی: صفحهٔ پرداخت در CSP form-action — بی‌این، هدایت به اسنپ‌پی بی‌صدا بلاک می‌شد.
# از ترمینالِ cPanel با کاربرِ servernetcloud:
#
#   ۱) DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-snapppay-csp.sh) <SHA>
#   ۲) فقط اگر «DRY OK» بود، همان فرمان بدونِ DRY=1
#   ۳) ریستِ opcache از /system/opcache (validate_timestamps=0)
#
# مهاجرت ندارد. پایه = همان کامیتی که الان روی سرور است (52ad836f).
# سازوکار همان DELTA ِ deploy-snapppay.sh است: ادغامِ سه‌طرفه با
# پایهٔ پین‌شده (نوکِ develop درست پیش از این کار)، تا فقط خط‌های همین کار بروند.
set -u

DRY="${DRY:-0}"
MINE="${1:?SHA دقیق نسخهٔ هدف الزامی است}"
BASE="${BASE:-52ad836f}"
BRANCH="${BRANCH:-fix/snapppay-start-500}"
APP="$HOME/servernet_app"
WORK="$HOME/deploy-snapppay"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$WORK/backup-csp-$STAMP"
STAGE="$WORK/stage-csp-$STAMP"

if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "FATAL: $APP نصبِ لاراول نیست (کاربر: $(id -un)، HOME=$HOME). کاربرِ درست: servernetcloud"
  exit 1
fi
if [ -x /opt/cpanel/ea-php84/root/usr/bin/php ]; then
  PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php
else
  PHP_BIN="$(command -v php 2>/dev/null || true)"
fi
[ -n "$PHP_BIN" ] || { echo "FATAL: PHP پیدا نشد"; exit 1; }

mkdir -p "$WORK" "$BK" "$STAGE" "$WORK/conflicts"
if [ -d "$WORK/repo/.git" ]; then
  git -C "$WORK/repo" fetch --depth 500 origin "$BRANCH" || exit 1
else
  git clone --depth 500 --branch "$BRANCH" https://github.com/servernetir/server.git "$WORK/repo" || exit 1
fi
for c in "$MINE" "$BASE"; do
  git -C "$WORK/repo" rev-parse --verify "$c^{commit}" >/dev/null 2>&1 || {
    echo "FATAL: کامیت $c در مخزن نیست."; exit 1;
  }
done
echo "── هدف: $(git -C "$WORK/repo" log -1 --format='%h %s' "$MINE")"
echo "── پایه: $(git -C "$WORK/repo" log -1 --format='%h %s' "$BASE")"

DELTA_FILES="
config/snapppay.php
app/Http/Middleware/SecurityHeaders.php
app/Services/Payment/SnappPay/SnappPayGateway.php
"

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }
CONFLICTS=""
STAGED=""
UPD=0

for rel in $DELTA_FILES; do
  dest="$APP/$rel"; src="website/$rel"
  [ -f "$dest" ] || { echo "MISS $rel"; CONFLICTS="$CONFLICTS $rel"; continue; }
  git -C "$WORK/repo" cat-file blob "$MINE:$src" > "$WORK/mine.raw" 2>/dev/null || { echo "FATAL: $rel در هدف نیست"; CONFLICTS="$CONFLICTS $rel"; continue; }
  git -C "$WORK/repo" cat-file blob "$BASE:$src" > "$WORK/base.raw" 2>/dev/null || { echo "FATAL: $rel در پایه نیست"; CONFLICTS="$CONFLICTS $rel"; continue; }
  normalize "$WORK/mine.raw" "$WORK/mine.tmp"
  normalize "$WORK/base.raw" "$WORK/base.tmp"
  normalize "$dest" "$WORK/dest.tmp"

  if cmp -s "$WORK/dest.tmp" "$WORK/mine.tmp"; then echo "OK   $rel"; continue; fi

  merged="$WORK/merged.tmp"
  if ! git merge-file -p -L server -L base -L new "$WORK/dest.tmp" "$WORK/base.tmp" "$WORK/mine.tmp" > "$merged" 2>/dev/null; then
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$WORK/base.tmp" "$keep.base"; cp "$WORK/mine.tmp" "$keep.new"; cp "$merged" "$keep.merged"
    echo "CF   $rel — متنِ اطرافِ تغییر روی سرور فرق دارد؛ فایلِ زنده دست نخورد"
    CONFLICTS="$CONFLICTS $rel"; continue
  fi
  if cmp -s "$merged" "$WORK/dest.tmp"; then echo "OK   $rel (تغییر از قبل روی سرور هست)"; continue; fi
  if cmp -s "$WORK/dest.tmp" "$WORK/base.tmp"; then how="سرور = پایه"; else how="سرور ≠ پایه؛ فقط خط‌های همین کار افزوده شد"; fi
  mkdir -p "$STAGE/$(dirname "$rel")"; cp "$merged" "$STAGE/$rel"
  "$PHP_BIN" -l "$STAGE/$rel" >/dev/null || { echo "FATAL lint (stage): $rel"; exit 3; }
  # نشانهٔ اینکه ادغام واقعاً تغییرِ این کار را دارد
  case "$rel" in
    *snapppay.php) grep -q "'page_hosts'" "$STAGE/$rel" || { echo "FATAL: page_hosts در نتیجه نیست"; exit 3; } ;;
    *SecurityHeaders.php) grep -q 'function snappPayHosts' "$STAGE/$rel" || { echo "FATAL: snappPayHosts در نتیجه نیست"; exit 3; } ;;
  esac
  echo "MG   $rel ($how)"; STAGED="$STAGED $rel"; UPD=$((UPD+1))
done

if [ -n "$CONFLICTS" ]; then
  echo; echo "FATAL: تداخل/خطا:$CONFLICTS — جزئیات: $WORK/conflicts"
  echo "هیچ فایلی روی سرور نوشته نشد."
  exit 2
fi

if [ "$DRY" = "1" ]; then
  echo; echo "DRY OK — $UPD فایل نیازمندِ تغییر است؛ هیچ فایلی نوشته نشد."
  exit 0
fi

# ترتیب: کانفیگ پیش از میدل‌ور (میدل‌ور با پیش‌فرضِ [] بی‌آن هم کار می‌کند)
for rel in $DELTA_FILES; do
  case " $STAGED " in *" $rel "*)
    mkdir -p "$BK/$(dirname "$rel")"; cp -p "$APP/$rel" "$BK/$rel"; cp "$STAGE/$rel" "$APP/$rel"
    echo "UP   $rel" ;;
  esac
done

for rel in $DELTA_FILES; do
  "$PHP_BIN" -l "$APP/$rel" >/dev/null || {
    echo "🔴 lint روی سرور شکست خورد — بازگردانی"
    ( cd "$BK" && find . -type f | while read -r p; do cp -p "$p" "$APP/${p#./}"; echo "   برگشت: ${p#./}"; done )
    exit 3
  }
done

# اثبات از مقصد، نه از چاپِ موفقیت
grep -c "snappPayHosts()" "$APP/app/Http/Middleware/SecurityHeaders.php" | sed 's/^/csp-hook (باید 2): /'
grep -c "'page_hosts'" "$APP/config/snapppay.php" | sed 's/^/page-hosts (باید 1): /'
( cd "$APP" && "$PHP_BIN" artisan config:clear >/dev/null 2>&1 && echo "config:clear OK" )

echo; echo "DONE — $UPD فایل. پشتیبان: $BK"
echo "حالا opcache را از /system/opcache ریست کنید."
