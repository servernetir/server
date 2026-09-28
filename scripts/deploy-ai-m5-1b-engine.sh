#!/usr/bin/env bash
# انتشارِ M5.1b دروازهٔ AI — موتورِ پولی: رزروِ تومانی، تسویه از مصرفِ واقعی، سیاستِ شکست،
# آشتی‌دهنده و بازیابی، کلیدِ API در پنل. **فروش بسته می‌ماند** (`ai_sales_open` خالی ⇒ هر
# تماسِ /v1 پیش از هر رزروی ۵۰۳ `sales_closed`). از ترمینالِ cPanel با کاربرِ servernetcloud:
#
#   ۱) DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-ai-m5-1b-engine.sh) <SHA>
#   ۲) فقط اگر «DRY OK» بود، همان فرمان بدونِ DRY=1
#   ۳) ریستِ opcache، سپس دو مهاجرت (000110 بعد 000120) — خودتان؛ گام‌ها در پایانِ اجرا چاپ می‌شوند
#
# کد پیش از مهاجرت هم سالم است: درِ فروش پیش از هر دسترسی به `ai_usage` بسته است، فرمان‌های
# زمان‌بندی‌شده نبودِ جدول را رد می‌کنند و فرم‌های پنل پشتِ `Schema::hasColumn`‌اند.
set -u

DRY="${DRY:-0}"
MINE="${1:?SHA دقیق نسخهٔ هدف الزامی است}"
BRANCH="${BRANCH:-feature/ai-gateway-money}"
APP="$HOME/servernet_app"
WEB="$HOME/public_html"
WORK="$HOME/deploy-ai-m5"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$WORK/backup-m51b-$STAMP"
STAGE="$WORK/stage-m51b-$STAMP"
HIST=160
MIG1=database/migrations/2026_11_03_000110_ai_money_core.php
MIG2=database/migrations/2026_11_03_000120_deepinfra_driver_openai_compatible.php

# ── مقصد پیش از هر نوشتنی اثبات می‌شود ──
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ] || [ ! -f "$APP/app/Services/Ai/AiPricing.php" ] \
   || ! grep -q 'function createModel' "$APP/app/Http/Controllers/Admin/AiGatewayController.php" 2>/dev/null; then
  echo "FATAL: $APP اپِ دارای M5.1a و منوی درگاه نیست (کاربر: $(id -un)، HOME=$HOME)."
  exit 1
fi
if [ ! -d "$WEB/assets/css" ]; then
  echo "FATAL: $WEB/assets/css نیست — وب‌روتِ درست پیدا نشد."
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
# brick/math برای تبدیلِ دلار→میکرو در storeModel (BigDecimal)
if ! "$PHP_BIN" -r 'require $argv[1]."/vendor/autoload.php"; exit(class_exists("Brick\\Math\\BigDecimal") && defined("Brick\\Math\\RoundingMode::Up") ? 0 : 1);' "$APP"; then
  echo "FATAL: vendor/brick/math روی سرور کامل نیست."
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

# ابزارِ بلوک از خودِ کامیتِ هدف (درختِ کاریِ کلونِ قبلی کهنه می‌مانَد)
BLOCK_TOOL="$WORK/apply-marked-block.php"
git -C "$WORK/repo" cat-file blob "$MINE:scripts/apply-marked-block.php" > "$BLOCK_TOOL" 2>/dev/null || {
  echo "FATAL: scripts/apply-marked-block.php در نسخهٔ هدف نیست"; exit 2;
}
"$PHP_BIN" -l "$BLOCK_TOOL" >/dev/null || { echo "FATAL: ابزارِ بلوک سالم نیست"; exit 2; }

# ── فایل‌ها: کلاس‌های مستقل اول، مصرف‌کننده‌ها بعد، کنترلرها و ویوها آخر ──
APP_FILES="
app/Services/AiProviders/AiProviderCallException.php
app/Services/AiProviders/DriverCallResult.php
app/Services/AiProviders/AiProviderDriver.php
app/Services/AiProviders/OpenAiCompatibleDriver.php
app/Models/AiUsage.php
app/Models/AiReservation.php
app/Models/AiProvider.php
app/Models/AiModel.php
app/Services/Ai/AiRequestException.php
app/Services/Ai/AiPreparedRequest.php
app/Services/Ai/AiPayload.php
app/Services/Ai/AiUsageParser.php
app/Services/Ai/AiGateRefusal.php
app/Services/Ai/AiCallOutcome.php
app/Services/Ai/AiPricing.php
app/Services/Ai/AiSettlement.php
app/Services/Ai/AiAdmission.php
app/Services/Ai/AiCaller.php
$MIG1
$MIG2
app/Console/Commands/AiReconcile.php
app/Console/Commands/AiRecoverUsage.php
app/Console/Commands/AiExplain.php
app/Http/Controllers/Ai/V1ChatController.php
app/Http/Controllers/Admin/AiGatewayController.php
resources/views/admin/ai/_price-preview.blade.php
resources/views/admin/ai/provider-edit.blade.php
resources/views/admin/ai/model-edit.blade.php
"

# این‌ها باید از قبل روی سرور باشند (M4 و M5.1a). NEW روی هر کدام = سرورِ عقب‌افتاده یا
# مقصدِ اشتباه — ادامه یعنی نصفِ یک تغییر.
MUST_EXIST="
app/Services/AiProviders/AiProviderDriver.php
app/Services/AiProviders/OpenAiCompatibleDriver.php
app/Models/AiReservation.php
app/Models/AiProvider.php
app/Models/AiModel.php
app/Services/Ai/AiCallOutcome.php
app/Services/Ai/AiPricing.php
app/Services/Ai/AiAdmission.php
app/Services/Ai/AiCaller.php
app/Http/Controllers/Ai/V1ChatController.php
app/Http/Controllers/Admin/AiGatewayController.php
resources/views/admin/ai/_price-preview.blade.php
resources/views/admin/ai/provider-edit.blade.php
resources/views/admin/ai/model-edit.blade.php
"

# وابستگی‌هایی که فرستاده نمی‌شوند ولی موتور بی‌آن‌ها ۵۰۰ می‌دهد — «فایل|نشانه‌ای که باید داخلش باشد»
DEPENDS="
app/Services/Finance/Wallet.php|excludingReservationId
app/Services/Ai/AiReservations.php|function expirePending
app/Services/Ai/AiModelRegistry.php|function model
app/Services/Ai/PriceBook.php|function sellRates
app/Services/Ai/AiFx.php|function quote
app/Services/Ai/AiVat.php|function resolve
app/Services/Ai/AiPriceQuote.php|class AiPriceQuote
app/Services/Ai/AiFxQuote.php|class AiFxQuote
app/Services/Ai/AiPricingException.php|TOO_LARGE
app/Models/AiProject.php|function budgetWindowFor
app/Models/AiCall.php|class AiCall
app/Models/CustomerApiToken.php|daily_spend_cap_irt
app/Models/CreditEntry.php|class CreditEntry
app/Models/Setting.php|function putSecret
app/Support/ErrorTracker.php|function noteOnce
config/ai.php|hold_backstop_h
routes/web.php|ai.v1.chat.completions
routes/console.php|Display an inspiring quote
"

echo "── موجودیِ پیش‌نیازها روی سرور ──"
MISSING=""
for pair in $DEPENDS; do
  rel="${pair%%|*}"; needle="${pair#*|}"
  if [ ! -f "$APP/$rel" ]; then echo "MISS $rel"; MISSING="$MISSING $rel"
  elif ! grep -q -- "$needle" "$APP/$rel"; then echo "OLD  $rel (بی «$needle»)"; MISSING="$MISSING $rel"
  else echo "have $rel"; fi
done
if [ -n "$MISSING" ]; then
  echo "FATAL: پیش‌نیاز روی سرور نیست یا کهنه است:$MISSING — هیچ فایلی نوشته نشد."
  exit 2
fi

# MicroMath روی سرور نیست و عمداً نمی‌آید (قفلِ مسیرِ قدیمیِ B1)؛ هیچ فایلِ این انتشار نباید صدایش بزند
if [ ! -f "$APP/app/Support/MicroMath.php" ]; then
  for rel in $APP_FILES; do
    if git -C "$WORK/repo" cat-file blob "$MINE:website/$rel" 2>/dev/null | grep -q 'MicroMath'; then
      echo "FATAL: $rel به MicroMath نیاز دارد ولی MicroMath روی سرور نیست"; exit 2
    fi
  done
  echo "info MicroMath روی سرور نیست — هیچ فایلِ این انتشار صدایش نمی‌زند"
fi

SCHED_BEFORE="$(grep -c 'Schedule::command' "$APP/routes/console.php" || true)"
echo "Schedule::command پیش از انتشار: $SCHED_BEFORE"

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }
distance() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]' || true; }
CONFLICTS=""
UPD=0

# apply_one <rel> — ادغامِ سه‌طرفه با نزدیک‌ترین نسخهٔ تاریخی به‌عنوانِ پایه، فقط در stage
apply_one() {
  rel="$1"; dest="$APP/$rel"; src="website/$rel"
  mine="$WORK/mine.tmp"; dest_n="$WORK/dest.tmp"; base="$WORK/base.tmp"
  git -C "$WORK/repo" cat-file blob "$MINE:$src" > "$WORK/mine.raw" 2>/dev/null || {
    echo "FATAL: $rel در نسخهٔ هدف نیست"; CONFLICTS="$CONFLICTS $rel"; return;
  }
  normalize "$WORK/mine.raw" "$mine"

  if [ ! -f "$dest" ]; then
    case " $(echo $MUST_EXIST) " in *" $rel "*)
      echo "FATAL NEW $rel — باید روی سرور باشد"; CONFLICTS="$CONFLICTS $rel"; return ;;
    esac
    echo "NEW  $rel"
    mkdir -p "$STAGE/$(dirname "$rel")"; cp "$mine" "$STAGE/$rel"
    UPD=$((UPD+1)); return
  fi
  normalize "$dest" "$dest_n"
  if cmp -s "$dest_n" "$mine"; then echo "OK   $rel"; return; fi

  best=""; bestd=999999999
  for sha in $(git -C "$WORK/repo" log --full-history --format=%H -n "$HIST" "$MINE" -- "$src"); do
    git -C "$WORK/repo" cat-file blob "$sha:$src" > "$WORK/candidate.raw" 2>/dev/null || continue
    normalize "$WORK/candidate.raw" "$WORK/candidate.tmp"
    if cmp -s "$dest_n" "$WORK/candidate.tmp"; then best="$sha"; bestd=0; break; fi
    d="$(distance "$dest_n" "$WORK/candidate.tmp")"
    if [ "$d" -lt "$bestd" ]; then bestd="$d"; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "CF   $rel — پایهٔ تاریخی پیدا نشد"; CONFLICTS="$CONFLICTS $rel"; return
  fi
  git -C "$WORK/repo" cat-file blob "$best:$src" > "$WORK/base.raw" 2>/dev/null || true
  normalize "$WORK/base.raw" "$base"
  merged="$WORK/merged.tmp"
  if ! git merge-file -p "$dest_n" "$base" "$mine" > "$merged"; then
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine" "$keep.new"; cp "$merged" "$keep.merged"
    echo "CF   $rel — تداخل؛ فایلِ زنده دست نخورد"; CONFLICTS="$CONFLICTS $rel"; return
  fi
  echo "MG   $rel (base $(git -C "$WORK/repo" rev-parse --short "$best"), فاصله $bestd)"
  mkdir -p "$STAGE/$(dirname "$rel")"; cp "$merged" "$STAGE/$rel"
  UPD=$((UPD+1))
}

# apply_block <rel> <marker> <anchor> <--before|--after> <absent-regex>
# روی **کپیِ** فایلِ زنده در stage اجرا می‌شود؛ فایلِ زنده فقط در گامِ اعمال عوض می‌شود.
BLOCK_FILES=""
apply_block() {
  rel="$1"; marker="$2"; anchor="$3"; pos="$4"; absent="$5"
  srcfile="$WORK/block-src-$marker"
  git -C "$WORK/repo" cat-file blob "$MINE:website/$rel" > "$srcfile" 2>/dev/null || {
    echo "FATAL: $rel در نسخهٔ هدف نیست"; CONFLICTS="$CONFLICTS $rel"; return;
  }
  mkdir -p "$STAGE/$(dirname "$rel")"
  cp -p "$APP/$rel" "$STAGE/$rel"
  out="$("$PHP_BIN" "$BLOCK_TOOL" "$srcfile" "$STAGE/$rel" "$marker" "$anchor" "$pos" "--absent=$absent" 2>&1)"
  code=$?
  if [ $code -ne 0 ]; then
    echo "CF   $rel — $out"; CONFLICTS="$CONFLICTS $rel"; rm -f "$STAGE/$rel"; return
  fi
  case "$out" in
    SAME*) echo "OK   $rel [$marker]"; rm -f "$STAGE/$rel" ;;
    *)     echo "BLK  $rel [$marker] — $out"; BLOCK_FILES="$BLOCK_FILES $rel"; UPD=$((UPD+1)) ;;
  esac
}

# ═══ پیش‌پرواز: هیچ‌چیز روی فایلِ زنده نوشته نمی‌شود ═══
for rel in $APP_FILES; do apply_one "$rel"; done
apply_block routes/console.php ai-schedule \
  "})->purpose('Display an inspiring quote');" --after '/ai:reconcile/'

if [ -n "$CONFLICTS" ]; then
  echo "FATAL: تداخل/خطا:$CONFLICTS"
  echo "جزئیاتِ تداخل (اگر هست): $WORK/conflicts"
  exit 2
fi

for rel in $APP_FILES routes/console.php; do
  case "$rel" in *.php)
    [ -f "$STAGE/$rel" ] || continue
    "$PHP_BIN" -l "$STAGE/$rel" >/dev/null || { echo "FATAL lint (stage): $rel"; exit 3; }
  ;; esac
done

# 🔴 زمان‌بندی نباید کم شود (console.php ِ سرور پیش‌تر بازنویسی شده بود)
if [ -f "$STAGE/routes/console.php" ]; then
  SCHED_STAGE="$(grep -c 'Schedule::command' "$STAGE/routes/console.php" || true)"
  [ "$SCHED_STAGE" -eq $((SCHED_BEFORE + 3)) ] || { echo "FATAL: شمارِ Schedule::command از $SCHED_BEFORE به $SCHED_STAGE رسید (باید +۳)"; exit 3; }
  echo "Schedule::command پس از انتشار: $SCHED_STAGE (+۳)"
fi

if [ "$DRY" = "1" ]; then
  echo "DRY OK — $UPD فایل نیازمندِ تغییر است؛ هیچ فایلی نوشته نشد."
  exit 0
fi

# ═══ اعمال: کلاس‌ها و مدل‌ها، سپس کنترلرها و ویوها، زمان‌بندی آخر ═══
put() {
  rel="$1"; dest="$APP/$rel"
  mkdir -p "$BK/$(dirname "$rel")" "$(dirname "$dest")"
  if [ -f "$dest" ]; then cp -p "$dest" "$BK/$rel"; else echo "$rel" >> "$BK/.new-files"; fi
  cp "$STAGE/$rel" "$dest"
}
for rel in $APP_FILES; do [ -f "$STAGE/$rel" ] && put "$rel"; done
case " $BLOCK_FILES " in *" routes/console.php "*) put routes/console.php ;; esac

for rel in $APP_FILES routes/console.php; do
  case "$rel" in *.php) "$PHP_BIN" -l "$APP/$rel" >/dev/null || {
    echo "FATAL lint: $rel — بازگردانی از پشتیبان"
    [ -f "$BK/$rel" ] && cp -p "$BK/$rel" "$APP/$rel"
    exit 3
  } ;; esac
done

# مقدار را از خودِ مقصد بسنج، نه از چاپِ موفقیت
grep -q 'function salesOpenFor' "$APP/app/Services/Ai/AiCaller.php" || { echo "FATAL: AiCaller ِ M5 نیست"; exit 3; }
grep -q 'function settleFromUsage' "$APP/app/Services/Ai/AiSettlement.php" || { echo "FATAL: AiSettlement نیست"; exit 3; }
grep -q 'hold_backstop_h' "$APP/app/Models/AiReservation.php" || { echo "FATAL: پشتوانهٔ ۲۴ ساعته در AiReservation نیست"; exit 3; }
grep -q "handle(\$auth, \$payload" "$APP/app/Http/Controllers/Ai/V1ChatController.php" || { echo "FATAL: کنترلرِ /v1 به AiCaller ِ تازه وصل نیست"; exit 3; }
grep -q "ai:reconcile" "$APP/routes/console.php" || { echo "FATAL: زمان‌بندیِ آشتی‌دهنده نیست"; exit 3; }
SCHED_AFTER="$(grep -c 'Schedule::command' "$APP/routes/console.php" || true)"
[ "$SCHED_AFTER" -eq $((SCHED_BEFORE + 3)) ] || { echo "FATAL: Schedule::command $SCHED_BEFORE → $SCHED_AFTER"; exit 3; }

cd "$APP" || exit 1
"$PHP_BIN" artisan config:clear || exit 4
"$PHP_BIN" artisan route:clear || exit 4
"$PHP_BIN" artisan view:clear || exit 4
"$PHP_BIN" artisan schedule:list 2>/dev/null | grep -q 'ai:reconcile' \
  || echo "WARN: schedule:list آشتی‌دهنده را نشان نداد — پس از ریستِ opcache دوباره بسنجید"

echo
echo "FILES OK — backup: $BK"
echo
echo "🔴 هنوز زنده نیست (validate_timestamps=0). گام‌های باقی‌مانده، به همین ترتیب (خودتان):"
echo "  ۱) grep -c ' ERROR' $APP/storage/logs/laravel.log   ← عددِ پایه"
echo "  ۲) ریستِ opcache از /system/opcache"
echo "  ۳) با مرورگر: /admin/ai · /admin/ai/models · /admin/ai/pricing · ویرایشِ DeepInfra — هیچ ۵۰۰ی نه"
echo "     و: curl -s -o /dev/null -w '%{http_code}\\n' -X POST https://servernet.cloud/v1/chat/completions  ← باید 401"
echo "  ۴) cd $APP && $PHP_BIN artisan migrate --pretend --path=$MIG1   ← باید create table ai_usage و alter ها"
echo "  ۵) cd $APP && $PHP_BIN artisan migrate --force   --path=$MIG1"
echo "  ۶) cd $APP && $PHP_BIN artisan migrate --force   --path=$MIG2   ← درایورِ DeepInfra ⇒ OpenAI-Compatible"
echo "  ۷) cd $APP && $PHP_BIN artisan ai:reconcile && $PHP_BIN artisan ai:price-preview"
echo "  ۸) grep -c ' ERROR' $APP/storage/logs/laravel.log   ← نباید بالا رفته باشد"
echo
echo "فروش بسته می‌ماند تا خودتان ai_sales_open یا فهرستِ مشتریانِ آزمایشی را پر کنید."
echo
echo "Rollback: فایل‌های $BK را به $APP برگردانید، فایل‌های $BK/.new-files را پاک کنید،"
echo "          config/route/view:clear و **دوباره opcache را ریست کنید**. مهاجرت‌ها افزودنی‌اند و down ِ بی‌اثر دارند."
