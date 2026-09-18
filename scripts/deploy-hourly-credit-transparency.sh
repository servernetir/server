#!/usr/bin/env bash
# انتشارِ «پایانِ اعتبارِ ساعتی: شفافیت + نگهداریِ بی‌ضرر» از ترمینالِ cPanel
# با کاربرِ servernetcloud.
#
#   ۱) DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/feature/hourly-credit-transparency/scripts/deploy-hourly-credit-transparency.sh) <SHA>
#   ۲) فقط اگر همه OK/MG/NEW/DRY بودند، همان فرمان بدونِ DRY=1
#
# شاملِ یک مهاجرتِ هدفمند (`services.hold_rate_irt/hold_reserve_irt`).
# 🔴 مهاجرت با `--path` فقط همین یک فایل را اجرا می‌کند: مهاجرت‌های معلقِ AI روی
#    MariaDB با خطای کلیدِ خارجی می‌شکنند و `migrate`ِ کامل هرگز به این‌جا
#    نمی‌رسید. کد بدونِ این ستون‌ها هم سالم است (`HourlyHold::enabled()`).
set -u

DRY="${DRY:-0}"
MINE="${1:?SHA دقیق نسخهٔ هدف الزامی است}"
BRANCH="${BRANCH:-feature/hourly-credit-transparency}"
APP="$HOME/servernet_app"
WEB="$HOME/public_html"
WORK="$HOME/deploy-hourly-credit"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$WORK/backup-$STAMP"
STAGE="$WORK/stage-$STAMP"
HIST=160
MIGRATION=database/migrations/2026_11_03_000100_add_hourly_hold_to_services.php

if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "FATAL: $APP نصبِ واقعیِ Laravel نیست؛ با کاربرِ servernetcloud اجرا کنید."
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
# develop هم لازم است: پایهٔ کلیدهای ترجمه = نقطهٔ انشعاب
git -C "$WORK/repo" fetch --depth 500 origin develop:refs/remotes/origin/develop 2>/dev/null || true
git -C "$WORK/repo" rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || {
  echo "FATAL: کامیت $MINE در مخزن نیست."; exit 1;
}

# ── فایل‌های برنامه — کلاس و مهاجرت اول، مصرف‌کننده‌ها بعد ──
APP_FILES="
app/Services/Cloud/HourlyHold.php
$MIGRATION
app/Services/Finance/Wallet.php
app/Console/Commands/CloudMeterHourly.php
app/Models/Service.php
app/Support/PanelSections.php
app/Http/Controllers/GpuController.php
app/Http/Controllers/Account/CloudStoreController.php
app/Http/Controllers/Account/CloudServerController.php
routes/web.php
resources/views/partials/credit-lifecycle.blade.php
resources/views/account/partials/hourly-billing.blade.php
resources/views/account/partials/card-server.blade.php
resources/views/account/cloud-server.blade.php
resources/views/account/cloud-store.blade.php
resources/views/pages/vps-hourly.blade.php
resources/views/pages/gpu.blade.php
"

# ── ترجمه‌ها: **کلید به کلید**، نه ادغامِ کلِ فایل ──
#
# 🔴 چرا جدا: در نخستین DRY، `lang/tr/ui.php` تداخل کرد در حالی که fa و en تمیز
# ادغام شدند. سرور روی این فایل‌ها دریفت دارد و دو انتشارِ موازی که در یک ناحیه
# کلید افزوده باشند ادغامِ متنی را می‌شکنند. فایلِ ترجمه نقشهٔ کلید→مقدار است و
# جای کلید در فایل معنایی ندارد، پس فقط کلیدهای عوض‌شدهٔ همین نسخه می‌نشینند و
# دریفتِ سرور دست‌نخورده می‌مانَد. منطق: `scripts/lang-apply-keys.php`.
LANG_FILES="
lang/fa/ui.php
lang/en/ui.php
lang/tr/ui.php
"

# ── داراییِ عمومی — «مسیرِ مخزن:مسیرِ وب‌روت» ──
#
# 🔴 در مخزن `website/public/assets/...` است ولی روی سرور `public_html/assets/...`؛
# نخستین DRY با «assets/css/panel.css در نسخهٔ هدف نیست» شکست خورد چون هر دو را
# یکی گرفته بودم. جفتِ صریح این را برای همیشه می‌بندد.
# ⚠️ فقط داخلِ assets (قاعدهٔ ثبت‌شده: هرگز فایلِ ریشهٔ public).
WEB_FILES="
public/assets/css/panel.css:assets/css/panel.css
"

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }
distance() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]' || true; }
CONFLICTS=""
UPD=0

# apply_one <srcRel[:destRel]> <destRoot> <stageKey> — ادغامِ سه‌طرفه، فقط در stage
apply_one() {
  srcRel="${1%%:*}"; rel="${1##*:}"; dest="$2/$rel"; key="$3"; src="website/$srcRel"
  mine="$WORK/mine.tmp"; dest_n="$WORK/dest.tmp"; base="$WORK/base.tmp"
  git -C "$WORK/repo" show "$MINE:$src" > "$WORK/mine.raw" 2>/dev/null || {
    echo "FATAL: $rel در نسخهٔ هدف نیست"; CONFLICTS="$CONFLICTS $rel"; return;
  }
  normalize "$WORK/mine.raw" "$mine"

  if [ ! -f "$dest" ]; then
    echo "NEW  $rel"
    mkdir -p "$STAGE/$key/$(dirname "$rel")"; cp "$mine" "$STAGE/$key/$rel"
    UPD=$((UPD+1)); return
  fi
  normalize "$dest" "$dest_n"
  if cmp -s "$dest_n" "$mine"; then echo "OK   $rel"; return; fi

  best=""; bestd=999999999
  for sha in $(git -C "$WORK/repo" log --format=%H -n "$HIST" "$MINE" -- "$src"); do
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
  mkdir -p "$STAGE/$key/$(dirname "$rel")"; cp "$merged" "$STAGE/$key/$rel"
  UPD=$((UPD+1))
}

# apply_lang <rel> <dry 0|1> — کلیدهای عوض‌شده روی فایلِ زندهٔ ترجمه
apply_lang() {
  rel="$1"; dry="$2"
  git -C "$WORK/repo" show "$LANG_BASE:website/$rel" > "$WORK/lang-base.php" 2>/dev/null || {
    echo "FATAL: نسخهٔ پایهٔ $rel نیست"; CONFLICTS="$CONFLICTS $rel"; return;
  }
  git -C "$WORK/repo" show "$MINE:website/$rel" > "$WORK/lang-mine.php" 2>/dev/null || {
    echo "FATAL: نسخهٔ هدفِ $rel نیست"; CONFLICTS="$CONFLICTS $rel"; return;
  }
  if [ ! -f "$APP/$rel" ]; then
    echo "FATAL: $rel روی سرور نیست"; CONFLICTS="$CONFLICTS $rel"; return
  fi

  if [ "$dry" = "1" ]; then
    "$PHP_BIN" "$LANG_TOOL" \
      "$WORK/lang-base.php" "$WORK/lang-mine.php" "$APP/$rel" --dry \
      || CONFLICTS="$CONFLICTS $rel"
    return
  fi

  # پشتیبان پیش از نوشتن
  mkdir -p "$BK/app/$(dirname "$rel")"
  cp -p "$APP/$rel" "$BK/app/$rel"

  if ! "$PHP_BIN" "$LANG_TOOL" \
      "$WORK/lang-base.php" "$WORK/lang-mine.php" "$APP/$rel"; then
    echo "FATAL: اعمالِ کلیدهای $rel شکست خورد — پشتیبان: $BK/app/$rel"
    exit 3
  fi

  "$PHP_BIN" -l "$APP/$rel" >/dev/null || {
    echo "FATAL lint: $rel — بازگردانی از پشتیبان"; cp -p "$BK/app/$rel" "$APP/$rel"; exit 3;
  }
}

# 🔴 از کلونِ قبلی، درختِ کاری کهنه می‌مانَد (fetch فایلِ تازه را checkout نمی‌کند)
# و نخستین DRY با «Could not open input file: .../scripts/lang-apply-keys.php»
# شکست خورد. پس ابزار را از خودِ کامیتِ هدف بیرون می‌کشیم.
LANG_TOOL="$WORK/lang-apply-keys.php"
git -C "$WORK/repo" show "$MINE:scripts/lang-apply-keys.php" > "$LANG_TOOL" 2>/dev/null || {
  echo "FATAL: scripts/lang-apply-keys.php در نسخهٔ هدف نیست"; exit 2;
}
"$PHP_BIN" -l "$LANG_TOOL" >/dev/null || { echo "FATAL: ابزارِ ترجمه سالم نیست"; exit 2; }

LANG_BASE="${LANG_BASE:-$(git -C "$WORK/repo" merge-base "$MINE" origin/develop 2>/dev/null || true)}"

if [ -z "${LANG_BASE:-}" ]; then
  echo "FATAL: نقطهٔ انشعاب از develop پیدا نشد؛ LANG_BASE=<sha> را دستی بدهید."
  exit 2
fi
echo "LANG base: $(git -C "$WORK/repo" rev-parse --short "$LANG_BASE")"

# ═══ پیش‌پرواز: هیچ‌چیز روی فایلِ زنده نوشته نمی‌شود ═══
for rel in $APP_FILES; do apply_one "$rel" "$APP" app; done
for rel in $WEB_FILES; do apply_one "$rel" "$WEB" web; done
for rel in $LANG_FILES; do apply_lang "$rel" 1; done

if [ -n "$CONFLICTS" ]; then
  echo "FATAL: تداخل/خطا:$CONFLICTS"
  echo "جزئیات تداخل (اگر فایلی هست): $WORK/conflicts"
  exit 2
fi

# 🔴 جفتِ هم‌بسته: متر و کیفِ پول بدونِ کلاسِ HourlyHold ⇒ ۵۰۰ روی کلِ پنل
if [ ! -f "$STAGE/app/app/Services/Cloud/HourlyHold.php" ] && [ ! -f "$APP/app/Services/Cloud/HourlyHold.php" ]; then
  echo "FATAL: HourlyHold.php نه در stage است نه روی سرور"; exit 2
fi
for rel in $APP_FILES; do
  case "$rel" in *.php)
    [ -f "$STAGE/app/$rel" ] || continue
    "$PHP_BIN" -l "$STAGE/app/$rel" >/dev/null || { echo "FATAL lint (stage): $rel"; exit 3; }
  ;; esac
done

if [ "$DRY" = "1" ]; then
  echo "DRY OK — $UPD فایل نیازمندِ تغییر است (+ ترجمه‌ها بالا)؛ هیچ فایلی نوشته نشد."
  exit 0
fi

# ═══ اعمال ═══
for rel in $APP_FILES; do
  [ -f "$STAGE/app/$rel" ] || continue
  dest="$APP/$rel"
  mkdir -p "$BK/app/$(dirname "$rel")" "$(dirname "$dest")"
  if [ -f "$dest" ]; then cp -p "$dest" "$BK/app/$rel"; else echo "app/$rel" >> "$BK/.new-files"; fi
  cp "$STAGE/app/$rel" "$dest"
done
for rel in $WEB_FILES; do
  [ -f "$STAGE/web/$rel" ] || continue
  dest="$WEB/$rel"
  mkdir -p "$BK/web/$(dirname "$rel")" "$(dirname "$dest")"
  if [ -f "$dest" ]; then cp -p "$dest" "$BK/web/$rel"; else echo "web/$rel" >> "$BK/.new-files"; fi
  cp "$STAGE/web/$rel" "$dest"
done
# ترجمه‌ها پس از کد: کلیدِ تازه بی‌ویو بی‌اثر است، ولی ویوِ تازه بی‌کلید خام چاپ می‌کند
for rel in $LANG_FILES; do apply_lang "$rel" 0; done

for rel in $APP_FILES; do
  case "$rel" in *.php) "$PHP_BIN" -l "$APP/$rel" >/dev/null || { echo "FATAL lint: $rel"; exit 3; } ;; esac
done

# مقدار را از خودِ مقصد بسنج، نه از چاپِ موفقیت (درسِ «unverified success print»)
grep -q 'HourlyHold::heldOf' "$APP/app/Services/Finance/Wallet.php" || { echo "FATAL: Wallet ذخیرهٔ نگهداری را نمی‌شمارد"; exit 3; }
for l in fa en tr; do
  grep -q 'hb_susp_h' "$APP/lang/$l/ui.php" || { echo "FATAL: رشته‌های تازه در lang/$l نیستند"; exit 3; }
done
grep -q 'hb-alert' "$WEB/assets/css/panel.css" || { echo "FATAL: استایلِ پنل روی وب‌روت نرفت"; exit 3; }

cd "$APP" || exit 1
"$PHP_BIN" artisan migrate --force --path="$MIGRATION" || { echo "FATAL: مهاجرتِ ستون‌های نگهداری شکست خورد"; exit 4; }
"$PHP_BIN" artisan config:clear || exit 4
"$PHP_BIN" artisan route:clear || exit 4
"$PHP_BIN" artisan view:clear || exit 4

HOLD="$("$PHP_BIN" -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo \App\Services\Cloud\HourlyHold::enabled() ? "yes" : "no";')"
echo "ستون‌های نگهداری روی سرور: $HOLD"
[ "$HOLD" = "yes" ] || { echo "FATAL: ستون‌ها ساخته نشدند — کد بی‌اثر می‌مانَد"; exit 4; }

# ذخیرهٔ سرورهای ساعتیِ فعال در اولین تیکِ متر هم‌سو می‌شود؛ همین حالا یک تیک:
"$PHP_BIN" artisan cloud:meter || true

echo "DEPLOY OK — backup: $BK"
echo "Rollback: فایل‌های $BK/{app,web} را به مقصدشان برگردانید و config:clear بزنید (ستون‌ها بی‌ضررند)."
