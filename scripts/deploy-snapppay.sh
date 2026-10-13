#!/usr/bin/env bash
# انتشارِ درگاهِ اقساطیِ اسنپ‌پی + ستونِ تخفیفِ فاکتور + درآمدِ مصرفِ اعتبار در دفتر.
# از ترمینالِ cPanel با کاربرِ servernetcloud:
#
#   ۱) DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-snapppay.sh) <SHA>
#   ۲) فقط اگر «DRY OK» بود، همان فرمان بدونِ DRY=1
#   ۳) دو مهاجرت (فرمانِ دقیق را خودِ اسکریپت در پایان چاپ می‌کند)
#   ۴) ریستِ opcache از /system/opcache (validate_timestamps=0)
#
# ═══ درگاه پس از انتشار **خاموش** است ═══
#
# `SNAPPPAY_ENABLED` پیش‌فرض false است. تا وقتی صریحاً روشن نشود: کارتِ پرداخت
# رندر نمی‌شود، سرویسِ eligible صدا زده نمی‌شود، و کرون بی‌هیچ تماسی برمی‌گردد.
# پس این انتشار برای مشتریِ واقعی هیچ اثرِ دیدنی‌ای ندارد.
#
# ═══ سه سازوکارِ انتشار، هرکدام برای یک جنس فایل ═══
#
#  NEW   فایلِ تازه — کپی. اگر روی سرور باشد و فرق کند ⇒ خطا، نه بازنویسی.
#
#  DELTA فایلِ تغییریافته — ادغامِ سه‌طرفه با پایهٔ **پین‌شده** (`BASE`، نوکِ
#        develop درست پیش از این کار)، نه «نزدیک‌ترین نسخهٔ تاریخی به سرور».
#        🔴 تفاوتش حیاتی است: با پایهٔ «نزدیک‌ترین»، هر تغییرِ دیگرِ develop میانِ
#        آن پایه و نسخهٔ ما هم همراهِ فایل می‌رود — و سرور زیرمجموعهٔ develop
#        است. با پایهٔ پین‌شده فقط خط‌های **همین کار** اعمال می‌شوند؛ اگر متنِ
#        اطرافشان روی سرور فرق داشته باشد، تداخل می‌دهد و فایلِ زنده دست نمی‌خورد.
#
#  BLOCK `layout.blade.php` و `routes/web.php` — بلوکِ نشان‌دار در یک لنگر
#        (`apply-marked-block.php`)؛ بیرون از بلوک بایت‌به‌بایت دست نمی‌خورد.
#
#  LANG  ترجمه‌ها — کلید-به-کلید (`lang-apply-keys.php`)، نه ادغامِ کلِ فایل.
set -u

DRY="${DRY:-0}"
MINE="${1:?SHA دقیق نسخهٔ هدف الزامی است}"
# نوکِ develop درست پیش از این کار. دلتا = MINE − BASE = فقط همین کار.
BASE="${BASE:-a5f62a550b0fb87b1977769541f07a385ea76ba1}"
BRANCH="${BRANCH:-develop}"
APP="$HOME/servernet_app"
WEB="$HOME/public_html"
WORK="$HOME/deploy-snapppay"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$WORK/backup-$STAMP"
STAGE="$WORK/stage-$STAMP"

# ── مقصد پیش از هر نوشتنی اثبات می‌شود ──
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "FATAL: $APP نصبِ لاراول نیست (کاربر: $(id -un)، HOME=$HOME). کاربرِ درست: servernetcloud"
  exit 1
fi
if [ ! -d "$WEB/assets" ]; then
  echo "FATAL: $WEB/assets نیست — وب‌روتِ درست پیدا نشد."
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
for c in "$MINE" "$BASE"; do
  git -C "$WORK/repo" rev-parse --verify "$c^{commit}" >/dev/null 2>&1 || {
    echo "FATAL: کامیت $c در مخزن نیست."; exit 1;
  }
done
echo "── هدف: $(git -C "$WORK/repo" log -1 --format='%h %s' "$MINE")"
echo "── پایه: $(git -C "$WORK/repo" log -1 --format='%h %s' "$BASE")"

# ابزارها از خودِ کامیتِ هدف (درختِ کاریِ کلونِ قبلی کهنه می‌مانَد)
BLOCK_TOOL="$WORK/apply-marked-block.php"
LANG_TOOL="$WORK/lang-apply-keys.php"
for t in apply-marked-block lang-apply-keys; do
  git -C "$WORK/repo" cat-file blob "$MINE:scripts/$t.php" > "$WORK/$t.php" 2>/dev/null || {
    echo "FATAL: scripts/$t.php در نسخهٔ هدف نیست"; exit 2;
  }
  "$PHP_BIN" -l "$WORK/$t.php" >/dev/null || { echo "FATAL: ابزارِ $t سالم نیست"; exit 2; }
done

# ── ترتیب معنادار است: کلاس پیش از صداکننده‌اش، ویو پیش از لینکش، روت آخر ──
NEW_FILES="
config/snapppay.php
database/migrations/2026_09_29_000101_add_discount_to_invoices.php
database/migrations/2026_09_29_000102_create_snapppay_orders_table.php
app/Models/SnappPayOrder.php
app/Models/SnappPayOrderEvent.php
app/Services/Payment/SnappPay/SnappPayRejected.php
app/Services/Payment/SnappPay/SnappPayClient.php
app/Services/Payment/SnappPay/SnappPayCart.php
app/Services/Payment/SnappPay/SnappPayAvailability.php
app/Services/Payment/SnappPay/SnappPayGateway.php
app/Services/Payment/SnappPay/SnappPayAdmin.php
app/Console/Commands/SnappPayReconcile.php
app/Http/Controllers/Admin/SnappPayController.php
resources/views/admin/snapppay.blade.php
resources/views/admin/snapppay-order.blade.php
"
DELTA_FILES="
app/Models/Invoice.php
app/Models/InvoiceItem.php
app/Services/Finance/BusinessLedger.php
app/Services/Finance/Wallet.php
app/Services/Reports/BusinessReport.php
app/Http/Controllers/Account/PaymentController.php
app/Providers/AppServiceProvider.php
bootstrap/app.php
resources/views/account/invoice-print.blade.php
resources/views/account/invoice.blade.php
resources/views/account/payment-result.blade.php
routes/console.php
"
LANG_FILES="
lang/fa/ui.php
lang/en/ui.php
lang/tr/ui.php
"
ASSETS="
assets/img/snapppay.svg
assets/img/snapppay-logotype.svg
"
# چیزهایی که کدِ تازه به آن‌ها تکیه دارد و **این انتشار نمی‌آوردشان**
DEPENDS="
app/Services/Payment/PaymentService.php
app/Services/Payment/PaymentGateway.php
app/Services/Payment/GatewayRegistry.php
app/Support/ErrorTracker.php
app/Models/BusinessEntry.php
resources/views/admin/partials/finance-styles.blade.php
resources/views/admin/layout.blade.php
routes/web.php
"

echo "── موجودی روی سرور ──"
MISSING=""
for rel in $DEPENDS $DELTA_FILES $LANG_FILES; do
  [ -f "$APP/$rel" ] || { echo "MISS $rel"; MISSING="$MISSING $rel"; }
done
# دفترِ مالی باید نسخهٔ دارای بازگشتِ وجهِ فاکتوری باشد؛ کدِ تازه رویش بنا شده
grep -q 'function recordInvoiceRefund' "$APP/app/Services/Finance/BusinessLedger.php" 2>/dev/null \
  || MISSING="$MISSING BusinessLedger::recordInvoiceRefund"
grep -q 'function entry' "$APP/app/Services/Finance/Wallet.php" 2>/dev/null \
  || MISSING="$MISSING Wallet::entry"
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

# apply_new <rel>
apply_new() {
  rel="$1"; dest="$APP/$rel"
  git -C "$WORK/repo" cat-file blob "$MINE:website/$rel" > "$WORK/mine.raw" 2>/dev/null || {
    echo "FATAL: $rel در نسخهٔ هدف نیست"; CONFLICTS="$CONFLICTS $rel"; return;
  }
  normalize "$WORK/mine.raw" "$WORK/mine.tmp"
  if [ -f "$dest" ]; then
    normalize "$dest" "$WORK/dest.tmp"
    if cmp -s "$WORK/dest.tmp" "$WORK/mine.tmp"; then echo "OK   $rel"; return; fi
    # 🔴 فایلِ «تازه» که روی سرور هست و فرق دارد = کسی نسخهٔ دیگری گذاشته. بازنویسی نمی‌کنیم.
    echo "CF   $rel — روی سرور هست و با نسخهٔ هدف فرق دارد"; CONFLICTS="$CONFLICTS $rel"; return
  fi
  echo "NEW  $rel"; stage_put "$rel" "$WORK/mine.tmp"
}

# apply_delta <rel> — فقط (MINE − BASE) روی فایلِ زنده
apply_delta() {
  rel="$1"; dest="$APP/$rel"; src="website/$rel"
  git -C "$WORK/repo" cat-file blob "$MINE:$src" > "$WORK/mine.raw" 2>/dev/null || {
    echo "FATAL: $rel در نسخهٔ هدف نیست"; CONFLICTS="$CONFLICTS $rel"; return;
  }
  git -C "$WORK/repo" cat-file blob "$BASE:$src" > "$WORK/base.raw" 2>/dev/null || {
    echo "FATAL: $rel در پایه نیست"; CONFLICTS="$CONFLICTS $rel"; return;
  }
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

# apply_block <rel> <marker> <anchor> <--before|--after> <absent-regex>
# 🔴 برخلافِ اسکریپتِ مرجع، کپیِ stage فقط **بارِ اول** از فایلِ زنده ساخته می‌شود:
#    این انتشار دو بلوک روی routes/web.php دارد و کپیِ دوباره، بلوکِ اول را پاک می‌کرد.
BLOCK_CHANGED=""
apply_block() {
  rel="$1"; marker="$2"; anchor="$3"; pos="$4"; absent="$5"
  srcfile="$WORK/block-src-$marker"
  git -C "$WORK/repo" cat-file blob "$MINE:website/$rel" > "$srcfile" 2>/dev/null || {
    echo "FATAL: $rel در نسخهٔ هدف نیست"; CONFLICTS="$CONFLICTS $rel"; return;
  }
  bstage="$WORK/blockstage-$STAMP/$rel"
  if [ ! -f "$bstage" ]; then mkdir -p "$(dirname "$bstage")"; cp -p "$APP/$rel" "$bstage"; fi
  out="$("$PHP_BIN" "$BLOCK_TOOL" "$srcfile" "$bstage" "$marker" "$anchor" "$pos" "--absent=$absent" 2>&1)"
  code=$?
  if [ $code -ne 0 ]; then echo "CF   $rel [$marker] — $out"; CONFLICTS="$CONFLICTS $rel"; return; fi
  case "$out" in
    SAME*) echo "OK   $rel [$marker]" ;;
    *)     echo "BLK  $rel [$marker] — $out"
           case " $BLOCK_CHANGED " in *" $rel "*) ;; *) BLOCK_CHANGED="$BLOCK_CHANGED $rel"; UPD=$((UPD+1)) ;; esac ;;
  esac
}

# apply_lang <rel> <dry 0|1>
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
    echo "FATAL: اعمالِ کلیدهای $rel شکست خورد — پشتیبان: $BK/$rel"; exit 3
  fi
  "$PHP_BIN" -l "$APP/$rel" >/dev/null || { echo "FATAL lint: $rel — بازگردانی"; cp -p "$BK/$rel" "$APP/$rel"; exit 3; }
}

CRON_BEFORE="$(grep -c 'Schedule::command' "$APP/routes/console.php" 2>/dev/null || echo 0)"

# ═══ پیش‌پرواز: هیچ‌چیز روی فایلِ زنده نوشته نمی‌شود ═══
echo; echo "═══ فایل‌های تازه ═══"
for rel in $NEW_FILES; do apply_new "$rel"; done
echo; echo "═══ فایل‌های تغییریافته (فقط دلتای همین کار) ═══"
for rel in $DELTA_FILES; do apply_delta "$rel"; done
echo; echo "═══ بلوک‌های نشان‌دار ═══"
apply_block resources/views/admin/layout.blade.php snapppay-admin-nav \
  'href="/admin/transactions"' --after '/nav_snapppay/'
apply_block routes/web.php snapppay-callback \
  "Route::get('/payment/callback/{gateway}'" --before '/payment\.callback\.post/'
apply_block routes/web.php snapppay-admin-routes \
  "->name('admin.transactions')" --after '/admin\.snapppay/'
echo; echo "═══ ترجمه‌ها (کلید-به-کلید) ═══"
for rel in $LANG_FILES; do apply_lang "$rel" 1; done

if [ -n "$CONFLICTS" ]; then
  echo; echo "FATAL: تداخل/خطا:$CONFLICTS"
  echo "جزئیات (اگر هست): $WORK/conflicts — پسوندهای .server / .base / .new / .merged"
  echo "هیچ فایلی روی سرور نوشته نشد."
  exit 2
fi

# ── lint روی stage، پیش از هر نوشتنی ──
for rel in $STAGED; do
  case "$rel" in *.blade.php) ;; *.php)
    "$PHP_BIN" -l "$STAGE/$rel" >/dev/null || { echo "FATAL lint (stage): $rel"; exit 3; } ;;
  esac
done
for rel in $BLOCK_CHANGED; do
  case "$rel" in *.blade.php) ;; *.php)
    "$PHP_BIN" -l "$WORK/blockstage-$STAMP/$rel" >/dev/null || { echo "FATAL lint (block): $rel"; exit 3; } ;;
  esac
done

# 🔴 جفت‌های هم‌بسته: روت بی کنترلر، یا ثبتِ درگاه بی کلاسش ⇒ ۵۰۰ ِ سراسری
have() { [ -f "$STAGE/$1" ] || [ -f "$APP/$1" ]; }
for rel in app/Http/Controllers/Admin/SnappPayController.php \
           app/Services/Payment/SnappPay/SnappPayGateway.php \
           app/Services/Payment/SnappPay/SnappPayClient.php \
           app/Services/Payment/SnappPay/SnappPayCart.php \
           app/Console/Commands/SnappPayReconcile.php \
           resources/views/admin/snapppay.blade.php \
           resources/views/admin/snapppay-order.blade.php \
           config/snapppay.php; do
  have "$rel" || { echo "FATAL: $rel نه در stage است نه روی سرور، ولی به آن ارجاع داده می‌شود"; exit 2; }
done

if [ "$DRY" = "1" ]; then
  echo; echo "DRY OK — $UPD فایل نیازمندِ تغییر است؛ هیچ فایلی نوشته نشد."
  exit 0
fi

# ═══ اعمال ═══
put() {  # put <rel> <from>
  rel="$1"; from="$2"; dest="$APP/$rel"
  mkdir -p "$BK/$(dirname "$rel")" "$(dirname "$dest")"
  if [ -f "$dest" ]; then cp -p "$dest" "$BK/$rel"; else echo "$rel" >> "$BK/.new-files"; fi
  cp "$from" "$dest"
}
rollback() {
  echo "🔴 بازگردانیِ کامل از $BK"
  ( cd "$BK" && find . -type f ! -name '.new-files' ! -path './web/*' | while read -r p; do
      r="${p#./}"; cp -p "$p" "$APP/$r"; echo "   برگشت: $r"; done )
  [ -f "$BK/.new-files" ] && while read -r r; do rm -f "$APP/$r"; echo "   حذف: $r"; done < "$BK/.new-files"
  ( cd "$APP" && "$PHP_BIN" artisan config:clear >/dev/null 2>&1; "$PHP_BIN" artisan route:clear >/dev/null 2>&1; "$PHP_BIN" artisan view:clear >/dev/null 2>&1 )
}

for rel in $NEW_FILES $DELTA_FILES; do
  case " $STAGED " in *" $rel "*) put "$rel" "$STAGE/$rel" ;; esac
done
for rel in $LANG_FILES; do apply_lang "$rel" 0; done
for a in $ASSETS; do
  git -C "$WORK/repo" cat-file blob "$MINE:website/public/$a" > "$WORK/asset.tmp" 2>/dev/null || { echo "FATAL: $a در نسخهٔ هدف نیست"; rollback; exit 3; }
  if [ -f "$WEB/$a" ] && cmp -s "$WEB/$a" "$WORK/asset.tmp"; then echo "OK   public/$a"; continue; fi
  mkdir -p "$BK/web/$(dirname "$a")" "$WEB/$(dirname "$a")"
  [ -f "$WEB/$a" ] && cp -p "$WEB/$a" "$BK/web/$a"
  cp "$WORK/asset.tmp" "$WEB/$a"; echo "PUT  public/$a"
done
# layout پیش از routes؛ routes آخر از همه
for rel in resources/views/admin/layout.blade.php routes/web.php; do
  case " $BLOCK_CHANGED " in *" $rel "*) put "$rel" "$WORK/blockstage-$STAMP/$rel" ;; esac
done

# ── lint روی مقصد ──
for rel in $NEW_FILES $DELTA_FILES routes/web.php; do
  case "$rel" in *.blade.php) ;; *.php)
    "$PHP_BIN" -l "$APP/$rel" >/dev/null || { echo "FATAL lint: $rel"; rollback; exit 3; } ;;
  esac
done

# ── مقدار را از خودِ مقصد بسنج، نه از چاپِ موفقیت ──
ok=1
g() { grep -qF -- "$2" "$APP/$1" || { echo "🔴 $1: «$2» ننشسته"; ok=0; }; }
one() { n="$(grep -cF -- "$2" "$APP/$1" 2>/dev/null || echo 0)"; [ "$n" = "1" ] || { echo "🔴 $1: «$2» $n بار (باید ۱)"; ok=0; }; }

g app/Services/Finance/Wallet.php 'recordCreditSpend($entry)'
g app/Services/Finance/BusinessLedger.php 'function recordCreditSpend'
g app/Services/Finance/BusinessLedger.php 'function recordRefundFor'
g app/Models/Invoice.php 'function recalculateTotals'
g app/Models/InvoiceItem.php 'function netTotal'
g app/Http/Controllers/Account/PaymentController.php 'function findCallbackPayment'
g app/Http/Controllers/Account/PaymentController.php 'SnappPayAvailability'
g app/Providers/AppServiceProvider.php 'SnappPayGateway'
g bootstrap/app.php "'payment/callback/*'"
g resources/views/account/invoice.blade.php "snapppay['show']"
g routes/console.php 'snapppay:reconcile'
one routes/web.php '[snapppay-callback:start]'
one routes/web.php '[snapppay-admin-routes:start]'
one resources/views/admin/layout.blade.php '[snapppay-admin-nav:start]'
for l in fa en tr; do g "lang/$l/ui.php" 'pres_ref_snapppay'; g "lang/$l/ui.php" 'invp_discount'; done

# ── کارِ دیگران که نباید برداشته شده باشد ──
g app/Services/Finance/BusinessLedger.php 'function recordInvoiceRefund'
g app/Services/Finance/BusinessLedger.php 'function recordCreditSale'
g app/Services/Finance/Wallet.php 'function debit'
g app/Http/Controllers/Account/PaymentController.php 'function payCredit'
g app/Http/Controllers/Account/PaymentController.php 'function printInvoice'

CRON_AFTER="$(grep -c 'Schedule::command' "$APP/routes/console.php" 2>/dev/null || echo 0)"
echo "کرون: $CRON_BEFORE → $CRON_AFTER"
if [ "$CRON_AFTER" -lt "$CRON_BEFORE" ] || [ "$CRON_AFTER" -gt $((CRON_BEFORE+1)) ]; then
  echo "🔴 شمارشِ کرون غیرمنتظره است (این انتشار دقیقاً یک فرمان می‌افزاید)"; ok=0
fi

if [ "$ok" -ne 1 ]; then rollback; echo "🔴 انتشار ناتمام — خروجیِ بالا را بفرستید."; exit 3; fi

cd "$APP" || exit 1
"$PHP_BIN" artisan config:clear || exit 4
"$PHP_BIN" artisan route:clear || exit 4
"$PHP_BIN" artisan view:clear || exit 4

echo
echo "FILES OK — backup: $BK"
echo
echo "🔴 هنوز زنده نیست. گام‌های باقی‌مانده، به همین ترتیب:"
echo
echo "  ۱) عددِ پایهٔ خطا:"
echo "       grep -c ' ERROR' $APP/storage/logs/laravel.log"
echo
echo "  ۲) دو مهاجرت — **با --path**، تا مهاجرت‌های معلقِ دیگران اجرا نشوند:"
echo "       cd $APP"
echo "       $PHP_BIN artisan migrate --force --path=database/migrations/2026_09_29_000101_add_discount_to_invoices.php"
echo "       $PHP_BIN artisan migrate --force --path=database/migrations/2026_09_29_000102_create_snapppay_orders_table.php"
echo
echo "  ۳) ریستِ opcache از /system/opcache"
echo
echo "  ۴) بسنجید: /admin/snapppay باز شود (فهرستِ خالی، بدونِ ۵۰۰)؛ یک فاکتور را باز و"
echo "     چاپ کنید؛ و دوباره:  grep -c ' ERROR' $APP/storage/logs/laravel.log   ← نباید بالا رفته باشد"
echo
echo "  ۵) درگاه هنوز **خاموش** است. وقتی اسنپ‌پی آی‌پی را وایت کرد، در .env بگذارید:"
echo "       SNAPPPAY_BASE_URL=   SNAPPPAY_CLIENT_ID=   SNAPPPAY_CLIENT_SECRET="
echo "       SNAPPPAY_USERNAME=   SNAPPPAY_PASSWORD="
echo "       SNAPPPAY_TEST_EMAILS=<ایمیلِ حسابِ تست>     ← تا فقط همان حساب درگاه را ببیند"
echo "       SNAPPPAY_ENABLED=true"
echo "     و بعد: $PHP_BIN artisan config:clear  + ریستِ opcache"
echo
echo "Rollback: فایل‌های $BK را به $APP برگردانید ($BK/web → $WEB)، فایل‌های"
echo "          $BK/.new-files را پاک کنید، سه clear، و **دوباره opcache را ریست کنید**."
echo "          مهاجرت‌ها فقط ستون/جدول می‌افزایند و ماندنشان بی‌خطر است."
