#!/usr/bin/env bash
# انتشارِ M5.1a-2 دروازهٔ AI — منوی «درگاه هوش مصنوعی» در پنلِ مدیر + صفحهٔ «افزودنِ مدل».
# هیچ تغییری در مسیرِ /v1 و هیچ مهاجرتی نیست. از ترمینالِ cPanel با کاربرِ servernetcloud:
#
#   ۱) DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-ai-m5-1a2-admin.sh) <SHA>
#   ۲) فقط اگر «DRY OK» بود، همان فرمان بدونِ DRY=1
#   ۳) ریستِ opcache از /system/opcache (validate_timestamps=0)
#
# ═══ چرا layout و routes با ادغامِ سه‌طرفه نمی‌روند ═══
#
# هر دو مشترکِ همهٔ شاخه‌ها‌اند و سرور زیرمجموعهٔ develop است. ادغامِ کلِ فایل هر
# تغییرِ develop میانِ پایه و نسخهٔ ما را هم می‌آورد؛ در routes یعنی روتی به کنترلری
# که روی سرور نیست ⇒ ۵۰۰ ِ سراسری. پس فقط دو **بلوکِ نشان‌دار** جابه‌جا می‌شوند
# (`scripts/apply-marked-block.php`): بقیهٔ فایلِ زنده بایت‌به‌بایت دست نمی‌خورد.
set -u

DRY="${DRY:-0}"
MINE="${1:?SHA دقیق نسخهٔ هدف الزامی است}"
BRANCH="${BRANCH:-feature/ai-gateway-money}"
APP="$HOME/servernet_app"
WEB="$HOME/public_html"
WORK="$HOME/deploy-ai-m5"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$WORK/backup-m51a2-$STAMP"
STAGE="$WORK/stage-m51a2-$STAMP"
HIST=160

# ── مقصد پیش از هر نوشتنی اثبات می‌شود ──
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ] || [ ! -f "$APP/app/Services/Ai/AiPricing.php" ]; then
  echo "FATAL: $APP اپِ دارای M5.1a نیست (کاربر: $(id -un)، HOME=$HOME). اول deploy-ai-m5-1a-pricing.sh."
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

# ── فایل‌هایی که با ادغامِ سه‌طرفه می‌روند: کنترلر اول، ویوها بعد ──
APP_FILES="
app/Http/Controllers/Admin/AiGatewayController.php
resources/views/admin/ai/model-create.blade.php
resources/views/admin/ai/models.blade.php
resources/views/admin/settings/pricing.blade.php
"
# این‌ها با انتشارِ قبلی (M5.1a) روی سرور نشستند؛ NEW یعنی سرورِ عقب‌افتاده
MUST_EXIST="
app/Http/Controllers/Admin/AiGatewayController.php
resources/views/admin/ai/models.blade.php
resources/views/admin/settings/pricing.blade.php
"
# کنترلرِ تازه این‌ها را صدا می‌زند؛ همه با M5.1a آمده‌اند یا پیش از آن بوده‌اند
DEPENDS="
app/Services/Ai/AiPricing.php
app/Services/Ai/AiFx.php
app/Services/Ai/AiVat.php
app/Services/Ai/PriceBook.php
app/Models/AiModel.php
app/Models/AiProvider.php
app/Models/AiModelUnitPrice.php
app/Models/ActivityLog.php
config/ai.php
resources/views/admin/ai/providers.blade.php
resources/views/admin/ai/pricing.blade.php
resources/views/admin/ai/_price-preview.blade.php
resources/views/admin/layout.blade.php
routes/web.php
"

echo "── موجودی روی سرور ──"
MISSING=""
for rel in $DEPENDS; do
  if [ -f "$APP/$rel" ]; then echo "have $rel"; else echo "MISS $rel"; MISSING="$MISSING $rel"; fi
done
grep -q 'function sellRates' "$APP/app/Services/Ai/PriceBook.php" || MISSING="$MISSING PriceBook::sellRates"
grep -q "name('admin.ai.models.status')" "$APP/routes/web.php" || MISSING="$MISSING route:admin.ai.models.status"
if [ -n "$MISSING" ]; then
  echo "FATAL: پیش‌نیاز روی سرور نیست:$MISSING — هیچ فایلی نوشته نشد."
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
apply_block resources/views/admin/layout.blade.php ai-admin-nav \
  '<div class="ad-nav-sep">دامنه</div>' --before '/nav_ai_providers/'
apply_block routes/web.php ai-admin-routes-create \
  "->name('admin.ai.models.status')" --after '/admin\.ai\.models\.create/'

if [ -n "$CONFLICTS" ]; then
  echo "FATAL: تداخل/خطا:$CONFLICTS"
  echo "جزئیاتِ تداخل (اگر هست): $WORK/conflicts"
  exit 2
fi

for rel in $APP_FILES routes/web.php; do
  case "$rel" in *.php)
    [ -f "$STAGE/$rel" ] || continue
    "$PHP_BIN" -l "$STAGE/$rel" >/dev/null || { echo "FATAL lint (stage): $rel"; exit 3; }
  ;; esac
done

# 🔴 جفتِ هم‌بسته: روتِ تازه بی متدِ کنترلر ⇒ ۵۰۰ (درسِ «روت به کنترلرِ غایب»)
CTRL="$STAGE/app/Http/Controllers/Admin/AiGatewayController.php"
[ -f "$CTRL" ] || CTRL="$APP/app/Http/Controllers/Admin/AiGatewayController.php"
for m in createModel storeModel; do
  grep -q "function $m" "$CTRL" || { echo "FATAL: روتِ $m می‌رود ولی کنترلر آن را ندارد"; exit 2; }
done
MC="$STAGE/resources/views/admin/ai/model-create.blade.php"
[ -f "$MC" ] || MC="$APP/resources/views/admin/ai/model-create.blade.php"
[ -f "$MC" ] || { echo "FATAL: ویوی model-create نه در stage است نه روی سرور"; exit 2; }

if [ "$DRY" = "1" ]; then
  echo "DRY OK — $UPD فایل نیازمندِ تغییر است؛ هیچ فایلی نوشته نشد."
  exit 0
fi

# ═══ اعمال: کنترلر و ویوها اول، layout بعد، routes آخر ═══
put() {
  rel="$1"; dest="$APP/$rel"
  mkdir -p "$BK/$(dirname "$rel")" "$(dirname "$dest")"
  if [ -f "$dest" ]; then cp -p "$dest" "$BK/$rel"; else echo "$rel" >> "$BK/.new-files"; fi
  cp "$STAGE/$rel" "$dest"
}
for rel in $APP_FILES; do [ -f "$STAGE/$rel" ] && put "$rel"; done
for rel in resources/views/admin/layout.blade.php routes/web.php; do
  case " $BLOCK_FILES " in *" $rel "*) put "$rel" ;; esac
done

for rel in $APP_FILES routes/web.php; do
  case "$rel" in *.php) "$PHP_BIN" -l "$APP/$rel" >/dev/null || {
    echo "FATAL lint: $rel — بازگردانی از پشتیبان"
    [ -f "$BK/$rel" ] && cp -p "$BK/$rel" "$APP/$rel"
    exit 3
  } ;; esac
done

# مقدار را از خودِ مقصد بسنج، نه از چاپِ موفقیت
grep -q 'function storeModel' "$APP/app/Http/Controllers/Admin/AiGatewayController.php" || { echo "FATAL: کنترلر storeModel ندارد"; exit 3; }
grep -q "name('admin.ai.models.create')" "$APP/routes/web.php" || { echo "FATAL: روتِ ساختِ مدل در routes نیست"; exit 3; }
grep -q 'درگاه هوش مصنوعی' "$APP/resources/views/admin/layout.blade.php" || { echo "FATAL: منوی درگاه در layout نیست"; exit 3; }
[ "$(grep -c 'ai-admin-nav:start' "$APP/resources/views/admin/layout.blade.php")" = "1" ] || { echo "FATAL: بلوکِ منو تکراری/غایب است"; exit 3; }
grep -q 'id="ai-sales"' "$APP/resources/views/admin/settings/pricing.blade.php" || { echo "FATAL: لنگرِ ai-sales در تنظیمات نیست"; exit 3; }

cd "$APP" || exit 1
"$PHP_BIN" artisan config:clear || exit 4
"$PHP_BIN" artisan route:clear || exit 4
"$PHP_BIN" artisan view:clear || exit 4
# فقط هشدار: فایل‌ها نوشته شده‌اند و route:list برای هر روتِ سرور کنترلر را بازتاب می‌کند؛
# خطای یک روتِ بی‌ربط نباید این‌جا «FATAL» ِ گمراه‌کننده بدهد. نگهبانِ اصلی grep ِ بالاست.
"$PHP_BIN" artisan route:list --name=admin.ai.models 2>/dev/null | grep -q 'admin.ai.models.create' \
  || echo "WARN: artisan route:list روتِ admin.ai.models.create را نشان نداد — پس از ریستِ opcache با مرورگر بسنجید"


echo
echo "FILES OK — backup: $BK"
echo
echo "🔴 هنوز زنده نیست (validate_timestamps=0). گام‌های باقی‌مانده، به همین ترتیب:"
echo "  ۱) grep -c ' ERROR' $APP/storage/logs/laravel.log   ← عددِ پایه"
echo "  ۲) ریستِ opcache از /system/opcache"
echo "  ۳) با ورودِ مدیر: منوی کناری باید بخشِ «درگاه هوش مصنوعی» با چهار آیتم داشته باشد؛"
echo "     /admin/ai/models دکمهٔ «افزودنِ مدل» دارد؛ /admin/ai/models/create باز می‌شود — هیچ ۵۰۰ی نه"
echo "  ۴) grep -c ' ERROR' $APP/storage/logs/laravel.log   ← نباید بالا رفته باشد"
echo
echo "Rollback: فایل‌های $BK را به $APP برگردانید، فایل‌های $BK/.new-files را پاک کنید،"
echo "          config:clear و route:clear و view:clear، و **دوباره opcache را ریست کنید**."
