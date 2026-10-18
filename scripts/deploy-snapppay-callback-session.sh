#!/usr/bin/env bash
# رفعِ «بیرون‌افتادن از حساب بعد از بازگشت از اسنپ‌پی».
# از ترمینالِ cPanel با کاربرِ servernetcloud:
#
#   ۱) DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-snapppay-callback-session.sh) <SHA>
#   ۲) فقط اگر «DRY OK» بود، همان فرمان بدونِ DRY=1
#   ۳) ریستِ opcache از /system/opcache (validate_timestamps=0)
#
# POST ِ بازگشت از اسنپ‌پی بی‌نشست می‌شود و فقط ۳۰۳ به همان آدرس با GET می‌دهد.
# مهاجرت ندارد، کرون ندارد، ترجمه ندارد.
#
#  DELTA PaymentController — ادغامِ سه‌طرفه با پایهٔ پین‌شده (فقط callbackPost).
#  BLOCK routes/web.php — بلوکِ نشان‌دارِ `snapppay-callback` جایگزین می‌شود؛
#        بیرون از بلوک بایت‌به‌بایت دست نمی‌خورد.
set -u

DRY="${DRY:-0}"
MINE="${1:?SHA دقیق نسخهٔ هدف الزامی است}"
# نوکِ develop درست پیش از این کار. دلتا = MINE − BASE = فقط همین کار.
BASE="${BASE:-eafc36229078f48f6492ba57f43b4fbd6bd615d9}"
BRANCH="${BRANCH:-develop}"
APP="$HOME/servernet_app"
WORK="$HOME/deploy-snapppay-cb"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$WORK/backup-$STAMP"
STAGE="$WORK/stage-$STAMP"

if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "FATAL: $APP نصبِ لاراول نیست (کاربر: $(id -un)، HOME=$HOME). کاربرِ درست: servernetcloud"
  exit 1
fi

if [ -x /opt/cpanel/ea-php84/root/usr/bin/php ]; then
  PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php
else
  PHP_BIN="$(command -v php 2>/dev/null || true)"
fi
if [ -z "$PHP_BIN" ] || ! "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'; then
  echo "FATAL: PHP 8.3+ در دسترس نیست؛ هیچ فایلی نوشته نشد."
  exit 1
fi

mkdir -p "$WORK" "$BK" "$STAGE" "$WORK/conflicts"
if [ -d "$WORK/repo/.git" ]; then
  git -C "$WORK/repo" fetch --depth 500 origin "$BRANCH" || exit 1
else
  git clone --depth 500 --branch "$BRANCH" https://github.com/servernetir/server.git "$WORK/repo" || exit 1
fi
for c in "$MINE" "$BASE"; do
  git -C "$WORK/repo" rev-parse --verify "$c^{commit}" >/dev/null 2>&1 || {
    echo "FATAL: کامیت $c در مخزن نیست (شاخه: $BRANCH)."; exit 1;
  }
done
# 🔴 وجودِ شیء در کلونِ قبلی کافی نیست؛ هدف باید واقعاً روی همین شاخه باشد
#    (سرور زیرمجموعهٔ develop است — یک بار کامیتِ ادغام‌نشده از همین راه رفت).
git -C "$WORK/repo" merge-base --is-ancestor "$MINE" FETCH_HEAD || {
  echo "FATAL: $MINE روی $BRANCH نیست — اول ادغام، بعد انتشار."; exit 1;
}
echo "── هدف: $(git -C "$WORK/repo" log -1 --format='%h %s' "$MINE")"
echo "── پایه: $(git -C "$WORK/repo" log -1 --format='%h %s' "$BASE")"

BLOCK_TOOL="$WORK/apply-marked-block.php"
git -C "$WORK/repo" cat-file blob "$MINE:scripts/apply-marked-block.php" > "$BLOCK_TOOL" 2>/dev/null || {
  echo "FATAL: scripts/apply-marked-block.php در نسخهٔ هدف نیست"; exit 2; }
"$PHP_BIN" -l "$BLOCK_TOOL" >/dev/null || { echo "FATAL: ابزارِ بلوک سالم نیست"; exit 2; }

DELTA_FILES="
app/Http/Controllers/Account/PaymentController.php
"

echo "── پیش‌نیازها روی سرور ──"
MISSING=""
for rel in $DELTA_FILES routes/web.php; do [ -f "$APP/$rel" ] || MISSING="$MISSING $rel"; done
grep -qF '[snapppay-callback:start]' "$APP/routes/web.php" 2>/dev/null || MISSING="$MISSING block:snapppay-callback"
grep -qF 'function callback(' "$APP/app/Http/Controllers/Account/PaymentController.php" 2>/dev/null || MISSING="$MISSING PaymentController::callback"
if [ -n "$MISSING" ]; then
  echo "FATAL: پیش‌نیاز روی سرور نیست:$MISSING — هیچ فایلی نوشته نشد."
  exit 2
fi
echo "همهٔ پیش‌نیازها هستند."

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }
CONFLICTS=""
UPD=0
STAGED=""
stage_put() { mkdir -p "$STAGE/$(dirname "$1")"; cp "$2" "$STAGE/$1"; STAGED="$STAGED $1"; UPD=$((UPD+1)); }

apply_delta() {
  rel="$1"; dest="$APP/$rel"; src="website/$rel"
  git -C "$WORK/repo" cat-file blob "$MINE:$src" > "$WORK/mine.raw" 2>/dev/null || {
    echo "FATAL: $rel در نسخهٔ هدف نیست"; CONFLICTS="$CONFLICTS $rel"; return; }
  git -C "$WORK/repo" cat-file blob "$BASE:$src" > "$WORK/base.raw" 2>/dev/null || {
    echo "FATAL: $rel در پایه نیست"; CONFLICTS="$CONFLICTS $rel"; return; }
  normalize "$WORK/mine.raw" "$WORK/mine.tmp"
  normalize "$WORK/base.raw" "$WORK/base.tmp"
  normalize "$dest" "$WORK/dest.tmp"
  if cmp -s "$WORK/dest.tmp" "$WORK/mine.tmp"; then echo "OK   $rel"; return; fi
  if cmp -s "$WORK/base.tmp" "$WORK/mine.tmp"; then echo "SKIP $rel — در این کار تغییری ندارد"; return; fi
  merged="$WORK/merged.tmp"
  if ! git merge-file -p -L server -L base -L new "$WORK/dest.tmp" "$WORK/base.tmp" "$WORK/mine.tmp" > "$merged" 2>/dev/null; then
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$WORK/base.tmp" "$keep.base"; cp "$WORK/mine.tmp" "$keep.new"; cp "$merged" "$keep.merged"
    echo "CF   $rel — متنِ اطرافِ تغییر روی سرور فرق دارد؛ فایلِ زنده دست نخورد"
    CONFLICTS="$CONFLICTS $rel"; return
  fi
  if cmp -s "$merged" "$WORK/dest.tmp"; then echo "OK   $rel (تغییر از قبل روی سرور هست)"; return; fi
  if cmp -s "$WORK/dest.tmp" "$WORK/base.tmp"; then how="سرور = پایه"; else how="سرور ≠ پایه؛ فقط خط‌های همین کار افزوده شد"; fi
  echo "MG   $rel ($how)"; stage_put "$rel" "$merged"
}

# بلوکِ routes روی یک کپیِ stage اعمال می‌شود؛ فایلِ زنده تا مرحلهٔ اعمال دست نمی‌خورد
BLOCK_CHANGED=0
ROUTES_STAGE="$WORK/blockstage-$STAMP/routes/web.php"
apply_routes_block() {
  src="$WORK/block-src-web.php"
  git -C "$WORK/repo" cat-file blob "$MINE:website/routes/web.php" > "$src" 2>/dev/null || {
    echo "FATAL: routes/web.php در نسخهٔ هدف نیست"; CONFLICTS="$CONFLICTS routes/web.php"; return; }
  mkdir -p "$(dirname "$ROUTES_STAGE")"; cp -p "$APP/routes/web.php" "$ROUTES_STAGE"
  out="$("$PHP_BIN" "$BLOCK_TOOL" "$src" "$ROUTES_STAGE" snapppay-callback \
        "Route::get('/payment/callback/{gateway}'" --before '--absent=/payment\.callback\.post/' 2>&1)"
  code=$?
  if [ $code -ne 0 ]; then echo "CF   routes/web.php [snapppay-callback] — $out"; CONFLICTS="$CONFLICTS routes/web.php"; return; fi
  case "$out" in
    SAME*) echo "OK   routes/web.php [snapppay-callback]" ;;
    *)     echo "BLK  routes/web.php [snapppay-callback] — $out"; BLOCK_CHANGED=1; UPD=$((UPD+1)) ;;
  esac
}

put() {
  rel="$1"; from="$2"; dest="$APP/$rel"
  mkdir -p "$BK/$(dirname "$rel")"
  cp -p "$dest" "$BK/$rel"
  cp "$from" "$dest"
}
rollback() {
  echo "🔴 بازگردانیِ کامل از $BK"
  ( cd "$BK" && find . -type f | while read -r p; do
      r="${p#./}"; cp -p "$p" "$APP/$r"; echo "   برگشت: $r"; done )
  ( cd "$APP" && "$PHP_BIN" artisan route:clear >/dev/null 2>&1 )
}

# ═══ پیش‌پرواز: هیچ‌چیز روی فایلِ زنده نوشته نمی‌شود ═══
echo; echo "═══ فایل‌های تغییریافته (فقط دلتای همین کار) ═══"
for rel in $DELTA_FILES; do apply_delta "$rel"; done
echo; echo "═══ بلوکِ نشان‌دار ═══"
apply_routes_block

if [ -n "$CONFLICTS" ]; then
  echo; echo "FATAL: تداخل/خطا:$CONFLICTS"
  echo "جزئیات (اگر هست): $WORK/conflicts — پسوندهای .server / .base / .new / .merged"
  echo "هیچ فایلی روی سرور نوشته نشد."
  exit 2
fi
for rel in $STAGED; do
  "$PHP_BIN" -l "$STAGE/$rel" >/dev/null || { echo "FATAL lint (stage): $rel"; exit 3; }
done
if [ "$BLOCK_CHANGED" = "1" ]; then
  "$PHP_BIN" -l "$ROUTES_STAGE" >/dev/null || { echo "FATAL lint (block): routes/web.php"; exit 3; }
fi

if [ "$DRY" = "1" ]; then
  echo; echo "DRY OK — $UPD فایل نیازمندِ تغییر است؛ هیچ فایلی نوشته نشد."
  exit 0
fi

# ═══ اعمال — کنترلر پیش از روتی که صدایش می‌زند ═══
for rel in $DELTA_FILES; do
  case " $STAGED " in *" $rel "*) put "$rel" "$STAGE/$rel"; echo "UP   $rel" ;; esac
done
if [ "$BLOCK_CHANGED" = "1" ]; then put routes/web.php "$ROUTES_STAGE"; echo "UP   routes/web.php"; fi

for rel in $DELTA_FILES routes/web.php; do
  "$PHP_BIN" -l "$APP/$rel" >/dev/null || { echo "FATAL lint: $rel"; rollback; exit 3; }
done

# ── مقدار را از خودِ مقصد بسنج، نه از چاپِ موفقیت ──
ok=1
g() { grep -qF -- "$2" "$APP/$1" || { echo "🔴 $1: «$2» ننشسته"; ok=0; }; }
one() { n="$(grep -cF -- "$2" "$APP/$1" 2>/dev/null || echo 0)"; [ "$n" = "1" ] || { echo "🔴 $1: «$2» $n بار (باید ۱)"; ok=0; }; }
g app/Http/Controllers/Account/PaymentController.php 'function callbackPost'
g app/Http/Controllers/Account/PaymentController.php 'function callback('
g routes/web.php "'callbackPost'"
g routes/web.php 'PreventRequestForgery::class'
one routes/web.php '[snapppay-callback:start]'
one routes/web.php '[snapppay-callback:end]'
one routes/web.php "->name('payment.callback.post')"
one routes/web.php "->name('payment.callback')"
if [ "$ok" -ne 1 ]; then rollback; echo "🔴 انتشار ناتمام — خروجیِ بالا را بفرستید."; exit 3; fi

cd "$APP" || exit 1
"$PHP_BIN" artisan route:clear || exit 4

echo
echo "FILES OK — backup: $BK"
echo "callbackPost=$(grep -c 'function callbackPost' app/Http/Controllers/Account/PaymentController.php) route=$(grep -c "'callbackPost'" routes/web.php)"
echo
echo "🔴 گامِ باقی‌مانده: ریستِ opcache از /system/opcache."
