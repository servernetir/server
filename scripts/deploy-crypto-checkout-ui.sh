#!/usr/bin/env bash
# انتشارِ ظاهرِ تازهٔ پرداختِ رمزارزی (یک کارت، انتخابِ ارز، QR، تایمرِ حلقه‌ای).
# از ترمینالِ cPanel با کاربرِ servernetcloud:
#
#   ۱) DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-crypto-checkout-ui.sh) <SHA>
#   ۲) فقط اگر «DRY OK» بود، همان فرمان بدونِ DRY=1
#   ۳) ریستِ opcache از /system/opcache (validate_timestamps=0)
#
# مهاجرت ندارد، کرون ندارد، روت ندارد. منطقِ مالی (صدور، اثرِ انگشت، تطبیق) دست
# نمی‌خورد؛ CryptoIssuer فقط یک «≈ تخمین» نمایشی به خروجیِ checkout() می‌افزاید.
#
#  NEW   دو partial تازه — کپی؛ اگر روی سرور باشد و فرق کند ⇒ خطا.
#  DELTA CryptoIssuer + invoice.blade — ادغامِ سه‌طرفه با پایهٔ پین‌شده.
#  LANG  ۱۲ کلیدِ تازه در fa/en/tr — کلید-به-کلید.
set -u

DRY="${DRY:-0}"
MINE="${1:?SHA دقیق نسخهٔ هدف الزامی است}"
# نوکِ develop درست پیش از این کار. دلتا = MINE − BASE = فقط همین کار.
BASE="${BASE:-b009ad495af795146cfd253e52ea279977ef33c6}"
BRANCH="${BRANCH:-develop}"
APP="$HOME/servernet_app"
WORK="$HOME/deploy-crypto-ui"
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
echo "── هدف: $(git -C "$WORK/repo" log -1 --format='%h %s' "$MINE")"
echo "── پایه: $(git -C "$WORK/repo" log -1 --format='%h %s' "$BASE")"

LANG_TOOL="$WORK/lang-apply-keys.php"
git -C "$WORK/repo" cat-file blob "$MINE:scripts/lang-apply-keys.php" > "$LANG_TOOL" 2>/dev/null || {
  echo "FATAL: scripts/lang-apply-keys.php در نسخهٔ هدف نیست"; exit 2; }
"$PHP_BIN" -l "$LANG_TOOL" >/dev/null || { echo "FATAL: ابزارِ lang سالم نیست"; exit 2; }

# partialها پیش از ویوی صداکننده
NEW_FILES="
resources/views/account/partials/crypto-coin.blade.php
resources/views/account/partials/crypto-pane.blade.php
"
DELTA_FILES="
app/Services/Payment/CryptoIssuer.php
resources/views/account/invoice.blade.php
"
LANG_FILES="
lang/fa/ui.php
lang/en/ui.php
lang/tr/ui.php
"

echo "── پیش‌نیازها روی سرور ──"
MISSING=""
for rel in $DELTA_FILES $LANG_FILES app/Services/Security/QrCode.php app/Models/CryptoPayment.php resources/views/partials/icons.blade.php; do
  [ -f "$APP/$rel" ] || MISSING="$MISSING $rel"
done
for ic in i-copy i-chev i-info i-coins; do
  grep -q "id=\"$ic\"" "$APP/resources/views/partials/icons.blade.php" 2>/dev/null || MISSING="$MISSING icon:$ic"
done
grep -q "account.invoice.crypto.status" "$APP/routes/web.php" 2>/dev/null || MISSING="$MISSING route:account.invoice.crypto.status"
grep -q 'function svg' "$APP/app/Services/Security/QrCode.php" 2>/dev/null || MISSING="$MISSING QrCode::svg"
grep -q 'function secondsLeft' "$APP/app/Models/CryptoPayment.php" 2>/dev/null || MISSING="$MISSING CryptoPayment::secondsLeft"
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

apply_new() {
  rel="$1"; dest="$APP/$rel"
  git -C "$WORK/repo" cat-file blob "$MINE:website/$rel" > "$WORK/mine.raw" 2>/dev/null || {
    echo "FATAL: $rel در نسخهٔ هدف نیست"; CONFLICTS="$CONFLICTS $rel"; return; }
  normalize "$WORK/mine.raw" "$WORK/mine.tmp"
  if [ -f "$dest" ]; then
    normalize "$dest" "$WORK/dest.tmp"
    if cmp -s "$WORK/dest.tmp" "$WORK/mine.tmp"; then echo "OK   $rel"; return; fi
    echo "CF   $rel — روی سرور هست و با نسخهٔ هدف فرق دارد"; CONFLICTS="$CONFLICTS $rel"; return
  fi
  echo "NEW  $rel"; stage_put "$rel" "$WORK/mine.tmp"
}

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

apply_lang() {
  rel="$1"; dry="$2"
  git -C "$WORK/repo" cat-file blob "$BASE:website/$rel" > "$WORK/lang-base.php" 2>/dev/null || {
    echo "FATAL: پایهٔ $rel نیست"; CONFLICTS="$CONFLICTS $rel"; return; }
  git -C "$WORK/repo" cat-file blob "$MINE:website/$rel" > "$WORK/lang-mine.php" 2>/dev/null || {
    echo "FATAL: هدفِ $rel نیست"; CONFLICTS="$CONFLICTS $rel"; return; }
  if [ "$dry" = "1" ]; then
    "$PHP_BIN" "$LANG_TOOL" "$WORK/lang-base.php" "$WORK/lang-mine.php" "$APP/$rel" --dry || CONFLICTS="$CONFLICTS $rel"
    return
  fi
  mkdir -p "$BK/$(dirname "$rel")"; cp -p "$APP/$rel" "$BK/$rel"
  if ! "$PHP_BIN" "$LANG_TOOL" "$WORK/lang-base.php" "$WORK/lang-mine.php" "$APP/$rel"; then
    echo "FATAL: اعمالِ کلیدهای $rel شکست خورد — پشتیبان: $BK/$rel"; rollback; exit 3
  fi
  "$PHP_BIN" -l "$APP/$rel" >/dev/null || { echo "FATAL lint: $rel"; rollback; exit 3; }
}

put() {
  rel="$1"; from="$2"; dest="$APP/$rel"
  mkdir -p "$BK/$(dirname "$rel")" "$(dirname "$dest")"
  if [ -f "$dest" ]; then cp -p "$dest" "$BK/$rel"; else echo "$rel" >> "$BK/.new-files"; fi
  cp "$from" "$dest"
}
rollback() {
  echo "🔴 بازگردانیِ کامل از $BK"
  ( cd "$BK" && find . -type f ! -name '.new-files' | while read -r p; do
      r="${p#./}"; cp -p "$p" "$APP/$r"; echo "   برگشت: $r"; done )
  [ -f "$BK/.new-files" ] && while read -r r; do rm -f "$APP/$r"; echo "   حذف: $r"; done < "$BK/.new-files"
  ( cd "$APP" && "$PHP_BIN" artisan view:clear >/dev/null 2>&1 )
}

# ═══ پیش‌پرواز: هیچ‌چیز روی فایلِ زنده نوشته نمی‌شود ═══
echo; echo "═══ فایل‌های تازه ═══"
for rel in $NEW_FILES; do apply_new "$rel"; done
echo; echo "═══ فایل‌های تغییریافته (فقط دلتای همین کار) ═══"
for rel in $DELTA_FILES; do apply_delta "$rel"; done
echo; echo "═══ ترجمه‌ها (کلید-به-کلید) ═══"
for rel in $LANG_FILES; do apply_lang "$rel" 1; done

if [ -n "$CONFLICTS" ]; then
  echo; echo "FATAL: تداخل/خطا:$CONFLICTS"
  echo "جزئیات (اگر هست): $WORK/conflicts — پسوندهای .server / .base / .new / .merged"
  echo "هیچ فایلی روی سرور نوشته نشد."
  exit 2
fi
for rel in $STAGED; do
  case "$rel" in *.blade.php) ;; *.php)
    "$PHP_BIN" -l "$STAGE/$rel" >/dev/null || { echo "FATAL lint (stage): $rel"; exit 3; } ;;
  esac
done

if [ "$DRY" = "1" ]; then
  echo; echo "DRY OK — $UPD فایل + ترجمه‌ها نیازمندِ تغییر است؛ هیچ فایلی نوشته نشد."
  exit 0
fi

# ═══ اعمال — partialها پیش از invoice.blade ═══
for rel in $NEW_FILES $DELTA_FILES; do
  case " $STAGED " in *" $rel "*) put "$rel" "$STAGE/$rel"; echo "UP   $rel" ;; esac
done
for rel in $LANG_FILES; do apply_lang "$rel" 0; done

"$PHP_BIN" -l "$APP/app/Services/Payment/CryptoIssuer.php" >/dev/null || { echo "FATAL lint: CryptoIssuer"; rollback; exit 3; }

# ── مقدار را از خودِ مقصد بسنج، نه از چاپِ موفقیت ──
ok=1
g() { grep -qF -- "$2" "$APP/$1" || { echo "🔴 $1: «$2» ننشسته"; ok=0; }; }
g app/Services/Payment/CryptoIssuer.php 'function estimate'
g resources/views/account/invoice.blade.php "account.partials.crypto-pane"
g resources/views/account/invoice.blade.php '.pm-hint[hidden]'
g resources/views/account/partials/crypto-pane.blade.php 'id="cy-ring"'
g resources/views/account/partials/crypto-coin.blade.php '#26A17B'
for l in fa en tr; do g "lang/$l/ui.php" "'cy_choose_coin'"; g "lang/$l/ui.php" "'cy_new_address'"; done
# کارِ قبلیِ درگاه که نباید برداشته شده باشد
g app/Services/Payment/CryptoIssuer.php 'function issue'
g app/Services/Payment/CryptoIssuer.php 'function offers'
if [ "$ok" -ne 1 ]; then rollback; echo "🔴 انتشار ناتمام — خروجیِ بالا را بفرستید."; exit 3; fi

cd "$APP" || exit 1
"$PHP_BIN" artisan view:clear || exit 4

echo
echo "FILES OK — backup: $BK"
echo "estimate=$(grep -c 'function estimate' app/Services/Payment/CryptoIssuer.php) pane-include=$(grep -c 'account.partials.crypto-pane' resources/views/account/invoice.blade.php) cy-keys-fa=$(grep -c "'cy_" lang/fa/ui.php)"
echo
echo "🔴 گامِ باقی‌مانده: ریستِ opcache از /system/opcache، بعد یک فاکتورِ پرداخت‌نشده را باز کنید."
