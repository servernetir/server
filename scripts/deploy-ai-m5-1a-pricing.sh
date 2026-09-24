#!/usr/bin/env bash
# انتشارِ M5.1a دروازهٔ AI — سرویس‌های قیمت‌گذاری/نرخ ارز/مالیات + تنظیمات و
# پیش‌نمایشِ قیمت در پنلِ مدیر. **هیچ تغییری در مسیرِ /v1 نیست**؛ هیچ پولی جابه‌جا
# نمی‌شود. از ترمینالِ cPanel با کاربرِ servernetcloud:
#
#   ۱) DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-ai-m5-1a-pricing.sh) <SHA>
#   ۲) فقط اگر همه OK/MG/NEW و «DRY OK» بود، همان فرمان بدونِ DRY=1
#   ۳) مهاجرت را خودتان بزنید (اسکریپت نمی‌زند): اول --pretend، بعد --force --path
#
# کد بدونِ مهاجرت هم سالم است: ستون‌های `fx_fee_bp`/`margin_bp` پشتِ
# `Schema::hasColumn` خوانده می‌شوند و نبودشان یعنی «فروختنی نیست».
set -u

DRY="${DRY:-0}"
MINE="${1:?SHA دقیق نسخهٔ هدف الزامی است}"
BRANCH="${BRANCH:-feature/ai-gateway-money}"
APP="$HOME/servernet_app"
WEB="$HOME/public_html"
WORK="$HOME/deploy-ai-m5"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$WORK/backup-m51a-$STAMP"
STAGE="$WORK/stage-m51a-$STAMP"
HIST=160
MIGRATION=database/migrations/2026_11_03_000050_ai_pricing_inputs.php

# ── مقصد پیش از هر نوشتنی اثبات می‌شود (درسِ «گاردِ محتوا روی سرورِ اشتباه سبز شد») ──
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ] || [ ! -f "$APP/app/Services/Ai/PriceBook.php" ]; then
  echo "FATAL: $APP نصبِ واقعیِ اپ با دروازهٔ AI نیست (کاربر: $(id -un)، HOME=$HOME)."
  echo "با کاربرِ servernetcloud اجرا کنید. هیچ فایلی نوشته نشد."
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

# 🔴 همهٔ حسابِ پول با brick/math است و vendor ِ سرور مالِ این جعبه نیست (m5-spec
# M5.0 گام j). کد `RoundingMode::Up` (PascalCase) را صدا می‌زند که فقط از 0.14.2
# هست؛ 0.12–0.14.1 هم enum دارند ولی با `UP`، پس grep ِ «enum» سبز می‌شد و صفحهٔ
# قیمت بعداً با Error ِ «Undefined constant» ۵۰۰ می‌داد. خودِ همان نماد سنجیده می‌شود.
if ! "$PHP_BIN" -r 'require $argv[1]."/vendor/autoload.php"; exit(class_exists("Brick\\Math\\BigInteger") && defined("Brick\\Math\\RoundingMode::Up") ? 0 : 1);' "$APP"; then
  echo "FATAL: vendor/brick/math روی سرور RoundingMode::Up ندارد (نیاز: ≥ 0.14.2) — AiPricing بارگذاری نمی‌شود."
  exit 1
fi
echo "brick/math: $(grep -A3 '"name": "brick/math"' "$APP/vendor/composer/installed.json" 2>/dev/null | grep -m1 '"version"' | tr -d ' ,')"

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

# ── فایل‌ها: کلاس‌های تازهٔ مستقل اول، مصرف‌کننده‌ها بعد، ویوها آخر ──
APP_FILES="
config/ai.php
app/Services/Ai/AiFxQuote.php
app/Services/Ai/AiPriceQuote.php
app/Services/Ai/AiPricingException.php
app/Services/Ai/AiFx.php
app/Services/Ai/AiVat.php
app/Services/Ai/PriceBook.php
app/Services/Ai/AiPricing.php
$MIGRATION
app/Models/AiProvider.php
app/Models/AiModel.php
app/Console/Commands/AiPricePreview.php
app/Http/Controllers/Admin/AiGatewayController.php
app/Http/Controllers/Admin/SettingsController.php
resources/views/admin/ai/_price-preview.blade.php
resources/views/admin/ai/providers.blade.php
resources/views/admin/ai/models.blade.php
resources/views/admin/ai/pricing.blade.php
resources/views/admin/ai/provider-edit.blade.php
resources/views/admin/ai/model-edit.blade.php
resources/views/admin/settings/pricing.blade.php
"

# فایل‌هایی که **باید** از قبل روی سرور باشند. NEW روی این‌ها = مقصدِ اشتباه یا
# سرورِ عقب‌افتاده؛ ادامه دادن یعنی نصفِ یک تغییر (درسِ «NEW به‌جای UP»).
#
# 🔴 پنج ویوی admin/ai عمداً این‌جا نیستند. نخستین DRY (c4ca5bdb) سه‌تایشان را
# NEW دید در حالی که کنترلرشان روی سرور دقیقاً نسخهٔ ba71079 بود و روت‌هایشان
# زنده‌اند (`/admin/ai/pricing` → ۳۰۲ به لاگین، مسیرِ ساختگی → ۴۰۴). یعنی
# انتشارِ M1 نیمه‌کاره رفته بود و این صفحه‌ها برای مدیر ۵۰۰ می‌دادند. هر پنج
# ویو مالِ انحصاریِ همین workstream‌اند و با کنترلر یک واحدند، پس NEW مجاز است.
MUST_EXIST="
app/Services/Ai/PriceBook.php
app/Models/AiProvider.php
app/Models/AiModel.php
app/Http/Controllers/Admin/AiGatewayController.php
app/Http/Controllers/Admin/SettingsController.php
resources/views/admin/settings/pricing.blade.php
"

# وابستگی‌هایی که فرستاده **نمی‌شوند** ولی کدِ فرستاده‌شده بی‌آن‌ها ۵۰۰ می‌دهد —
# فقط وجودشان سنجیده می‌شود. هیچ‌کدام بعد از ba71079 عوض نشده‌اند، پس هر نسخه‌ای
# از develop که روی سرور باشد کافی است.
DEPENDS="
app/Models/AiModelUnitPrice.php
app/Services/Ai/AiModelRegistry.php
app/Support/MicroMath.php
app/Services/ExchangeRate.php
app/Support/ErrorTracker.php
app/Models/TaxRate.php
app/Models/Setting.php
app/Models/Customer.php
app/Models/ActivityLog.php
app/Models/User.php
resources/views/admin/layout.blade.php
resources/views/admin/settings/_save.blade.php
"

echo "── موجودیِ سطحِ AI روی سرور ──"
MISSING=""
for rel in $DEPENDS; do
  if [ -f "$APP/$rel" ]; then echo "have $rel"; else echo "MISS $rel"; MISSING="$MISSING $rel"; fi
done
# روت‌ها فرستاده نمی‌شوند (routes/web.php مشترک است)؛ باید از قبل زنده باشند
for name in admin.ai.providers admin.ai.providers.edit admin.ai.providers.update admin.ai.models admin.ai.models.edit admin.ai.models.update admin.ai.pricing admin.ai.pricing.supersede; do
  if grep -q "name('$name')" "$APP/routes/web.php"; then echo "have route $name"; else echo "MISS route $name"; MISSING="$MISSING route:$name"; fi
done
if [ -n "$MISSING" ]; then
  echo "FATAL: وابستگی روی سرور نیست:$MISSING — هیچ فایلی نوشته نشد."
  exit 2
fi

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }
distance() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]' || true; }
CONFLICTS=""
UPD=0

# apply_one <rel> — ادغامِ سه‌طرفه با نزدیک‌ترین نسخهٔ تاریخی به‌عنوانِ پایه، فقط در stage
apply_one() {
  rel="$1"; dest="$APP/$rel"; src="website/$rel"
  mine="$WORK/mine.tmp"; dest_n="$WORK/dest.tmp"; base="$WORK/base.tmp"
  git -C "$WORK/repo" show "$MINE:$src" > "$WORK/mine.raw" 2>/dev/null || {
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

  # --full-history واجب: بی‌آن، نسخهٔ زندهٔ آمده از والدِ دومِ یک مرج پیدا نمی‌شود
  best=""; bestd=999999999
  for sha in $(git -C "$WORK/repo" log --full-history --format=%H -n "$HIST" "$MINE" -- "$src"); do
    git -C "$WORK/repo" show "$sha:$src" > "$WORK/candidate.raw" 2>/dev/null || continue
    normalize "$WORK/candidate.raw" "$WORK/candidate.tmp"
    if cmp -s "$dest_n" "$WORK/candidate.tmp"; then best="$sha"; bestd=0; break; fi
    d="$(distance "$dest_n" "$WORK/candidate.tmp")"
    if [ "$d" -lt "$bestd" ]; then bestd="$d"; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "CF   $rel — پایهٔ تاریخی پیدا نشد"; CONFLICTS="$CONFLICTS $rel"; return
  fi
  git -C "$WORK/repo" show "$best:$src" > "$WORK/base.raw" 2>/dev/null || true
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

# ═══ پیش‌پرواز: هیچ‌چیز روی فایلِ زنده نوشته نمی‌شود ═══
for rel in $APP_FILES; do apply_one "$rel"; done

if [ -n "$CONFLICTS" ]; then
  echo "FATAL: تداخل/خطا:$CONFLICTS"
  echo "جزئیاتِ تداخل (اگر هست): $WORK/conflicts"
  exit 2
fi

for rel in $APP_FILES; do
  case "$rel" in *.php)
    [ -f "$STAGE/$rel" ] || continue
    "$PHP_BIN" -l "$STAGE/$rel" >/dev/null || { echo "FATAL lint (stage): $rel"; exit 3; }
  ;; esac
done

# 🔴 جفتِ هم‌بسته: کنترلر و ویوی پیش‌نمایش بی‌کلاسِ AiPricing ⇒ ۵۰۰ روی /admin/ai/pricing
for need in app/Services/Ai/AiPricing.php app/Services/Ai/AiFx.php app/Services/Ai/AiVat.php config/ai.php; do
  [ -f "$STAGE/$need" ] || [ -f "$APP/$need" ] || { echo "FATAL: $need نه در stage است نه روی سرور"; exit 2; }
done

# منوی AI در layout مشترک است و فرستاده نمی‌شود؛ فقط گزارش (صفحه‌ها بی‌آن هم باز می‌شوند)
if grep -q 'nav_ai_providers' "$APP/resources/views/admin/layout.blade.php"; then
  echo "have nav_ai (منوی AI در پنلِ مدیر هست)"
else
  echo "WARN: layout منوی AI ندارد — /admin/ai فقط با نشانی باز می‌شود؛ جداگانه merge شود."
fi

if [ "$DRY" = "1" ]; then
  echo "DRY OK — $UPD فایل نیازمندِ تغییر است؛ هیچ فایلی نوشته نشد."
  exit 0
fi

# ═══ اعمال ═══
for rel in $APP_FILES; do
  [ -f "$STAGE/$rel" ] || continue
  dest="$APP/$rel"
  mkdir -p "$BK/$(dirname "$rel")" "$(dirname "$dest")"
  if [ -f "$dest" ]; then cp -p "$dest" "$BK/$rel"; else echo "$rel" >> "$BK/.new-files"; fi
  cp "$STAGE/$rel" "$dest"
done

for rel in $APP_FILES; do
  case "$rel" in *.php) "$PHP_BIN" -l "$APP/$rel" >/dev/null || { echo "FATAL lint: $rel"; exit 3; } ;; esac
done

# مقدار را از خودِ مقصد بسنج، نه از چاپِ موفقیت
grep -q 'function sellRates' "$APP/app/Services/Ai/PriceBook.php" || { echo "FATAL: PriceBook بی sellRates"; exit 3; }
grep -q "'ai_margin_pct'" "$APP/app/Http/Controllers/Admin/SettingsController.php" || { echo "FATAL: SettingsController حاشیهٔ AI را نمی‌شناسد"; exit 3; }
grep -q "_price-preview" "$APP/resources/views/admin/ai/pricing.blade.php" || { echo "FATAL: صفحهٔ قیمت پیش‌نمایش ندارد"; exit 3; }
grep -q 'name="ai_margin_pct"' "$APP/resources/views/admin/settings/pricing.blade.php" || { echo "FATAL: فرمِ تنظیمات فیلدِ حاشیه ندارد"; exit 3; }
grep -q 'active_prices' "$APP/resources/views/admin/ai/models.blade.php" || { echo "FATAL: models.blade.php هنوز active_models می‌خواند (۵۰۰)"; exit 3; }
grep -q "'v' => 2" "$APP/app/Services/Ai/AiFx.php" || { echo "FATAL: AiFx نسخهٔ پنجرهٔ ۲۴ساعته نیست"; exit 3; }
# 🔴 مسیرِ /v1 نباید عوض شده باشد — این انتشار حقِ لمسش را ندارد
if grep -q 'AiPricing' "$APP/app/Services/Ai/AiCaller.php"; then
  echo "WARN: AiCaller.php روی سرور AiPricing را صدا می‌زند — این انتشار آن را نفرستاده؛ بررسی کنید."
fi

cd "$APP" || exit 1
"$PHP_BIN" artisan config:clear || exit 4
"$PHP_BIN" artisan view:clear || exit 4

echo
echo "── پیش‌نمایش (بی‌تماسِ شبکه؛ سطلِ ساعتیِ ضامنِ نرخ را ثبت می‌کند) ──"
"$PHP_BIN" artisan ai:price-preview || echo "WARN: ai:price-preview شکست خورد — laravel.log را ببینید"

echo
echo "FILES OK — backup: $BK"
echo
echo "🔴 هنوز زنده نیست. سرور با validate_timestamps=0 کار می‌کند: تا opcache ریست نشود،"
echo "   FPM کنترلرهای قدیمی را با ویوهای تازه اجرا می‌کند و /admin/settings?tab=pricing و"
echo "   /admin/ai/pricing ۵۰۰ می‌دهند (view:clear ِ CLI جای این را نمی‌گیرد)."
echo
echo "گام‌های باقی‌مانده، به همین ترتیب (خودتان):"
echo "  ۱) ریستِ opcache از /system/opcache"
echo "  ۲) در مرورگر، با ورودِ مدیر: /admin/ai · /admin/ai/models · /admin/ai/pricing · /admin/settings?tab=pricing — هیچ‌کدام نباید ۵۰۰ بدهد"
echo "     و: grep -c ' ERROR' $APP/storage/logs/laravel.log  (پیش و پس از باز کردنِ صفحه‌ها)"
echo "  ۳) cd $APP && $PHP_BIN artisan migrate --pretend --path=$MIGRATION"
echo "     باید دو alter table (fx_fee_bp و margin_bp) نشان دهد؛ اگر چیزی نشان نداد یعنی ستون‌ها از قبل هستند."
echo "  ۴) cd $APP && $PHP_BIN artisan migrate --force   --path=$MIGRATION"
echo "  ۵) cd $APP && $PHP_BIN artisan ai:price-preview"
echo
echo "Rollback: فایل‌های $BK را به $APP برگردانید، فایل‌های $BK/.new-files را پاک کنید،"
echo "          config:clear و view:clear، و **دوباره opcache را ریست کنید** (بی‌ریست، بازگردانی هم زنده نمی‌شود)."
