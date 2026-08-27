#!/usr/bin/env bash
#
# دیپلوی زیرساختِ ۶ (GPU) + صفحهٔ /gpu — مهر ۱۴۰۵.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-gpu.sh) [<SHA>]
#
# چه چیزی دیپلوی می‌شود:
#   · درایورِ SaladCloud (زیرساختِ ۶) + ثبت در رجیستری + فرمِ تنظیمات
#   · ستون‌های GPU روی cloud_plans + مهاجرتش
#   · صفحهٔ فرودِ /gpu با پیکربندِ ساعتی (سه‌زبانه) + منو + sitemap + llms
#   · چکِ سلامتِ «کاتالوگِ بی‌قیمت» تا خطِ محصولِ صفرشده بی‌صدا غیب نشود
#
# منطق عیناً از scripts/deploy-audit7.sh: merge سه‌طرفه با پایهٔ خودکار به‌ازای
# هر فایل (UP/MG/CF) + بکاپ کامل + یکسان‌سازیِ پایانِ خط پیش از هر مقایسه.
# ⚠️ تلهٔ CRLF: بی‌normalize، پایه‌یاب روی سرور کور می‌شود (درسِ اجرای اول).
set -u

# ── حالتِ آزمایشی ──────────────────────────────────────────────────────────
#
#   DRY=1 bash <(curl -fsSL .../deploy-gpu.sh)
#
# 🔴 چرا لازم است: پیش از هر دیپلوی باید دید سرور با نسخهٔ ما تداخل دارد یا نه.
# قاعدهٔ ثبت‌شدهٔ این پروژه: «پروداکشن زیرمجموعهٔ develop است» و فایلی که با هیچ
# کامیتی نمی‌خواند اغلب کارِ **ازقبل‌دیپلوی‌شدهٔ** session دیگری است، نه خرابی.
# پس اول گزارش، بعد نوشتن.
#
# ⚠️ حالتِ آزمایشی باید **هیچ** عارضه‌ای نداشته باشد — نه فایل، نه مهاجرت، نه
# پاک‌کردنِ کش. (درسِ `--dry` در `servers:post-rent`: پیش‌نمایشی که گلوگاه را
# می‌سوزاند، تنها هشدارِ اجرای واقعی را خفه می‌کند.)
DRY="${DRY:-0}"

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-gpu"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=80

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }

if [ -d repo/.git ]; then
  git -C repo fetch --depth 500 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 500 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

# 🔴 پین به کامیتِ مشخص — نوکِ متحرکِ develop را دیپلوی نکن (قاعدهٔ ثبت‌شده).
#    آرگومانِ اول جایگزینش می‌کند.
#
# ⚠️ چرا پین از fa32a51 به کامیتِ ادغام رفت (و برنگردانش):
#    هم‌زمان یک دیپلویِ دیگر هست (scripts/deploy-ticket-workflow.sh، پین به
#    ac0d47a) و **هر دو `routes/web.php` را می‌برند**. آن کامیت روتِ /gpu را
#    ندارد و کامیتِ من روت‌های تیکت را؛ بدتر، هر دو افزوده در **همان ناحیهٔ**
#    فایل می‌نشینند. شبیه‌سازیِ merge سه‌طرفه با پینِ قبلی تداخلِ واقعی داد،
#    و رفتارِ این اسکریپت روی تداخل «دست نزن» است — یعنی /gpu بی‌صدا هرگز
#    روی سرور نمی‌آمد، با خروجیِ سبز و بی‌هیچ خطایی.
#    کامیتِ ادغام هر دو تغییر را دارد، پس هر دو اسکریپت هم‌گرا می‌شوند:
#    هرکدام زودتر بدود، تغییرِ آن‌یکی برای دیپلویِ بعدی یک تغییرِ سمتِ سرور
#    است و merge حفظش می‌کند. تنها فایلی که با این جابه‌جایی عوض می‌شود
#    همین `routes/web.php` است (۲۷ فایلِ دیگرِ فهرست بایت‌به‌بایت یکسان‌اند).
MINE="${1:-0a68cde}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# 🔴 ترتیب معنادار است: اول مدل و سرویس‌های مستقل، بعد درایور، بعد کنترلر،
#    بعد ویو و زبان، بعد config، و آخر routeها.
APP_FILES="
app/Models/CloudPlan.php
app/Models/CloudLocation.php
app/Services/Cloud/CloudProvisioner.php
app/Console/Commands/CloudMeterHourly.php
app/Services/Cloud/CloudCountry.php
app/Services/SiteMenu.php
app/Http/Controllers/Account/CloudStoreController.php
app/Http/Controllers/CloudCatalogController.php
app/Services/Cloud/CloudNaming.php
app/Services/Cloud/CloudProvider.php
app/Services/Cloud/SaladOperations.php
app/Services/Cloud/SaladClient.php
app/Services/Cloud/CloudManager.php
app/Services/Cloud/CloudCatalogSync.php
app/Services/SystemHealth.php
app/Http/Controllers/GpuController.php
app/Http/Controllers/SiteController.php
app/Http/Controllers/Admin/SettingsController.php
resources/views/pages/gpu.blade.php
resources/views/account/cloud-store.blade.php
resources/views/partials/cloud-locations-links.blade.php
resources/views/admin/settings/infra.blade.php
resources/views/admin/settings/pricing.blade.php
lang/fa/ui.php
lang/en/ui.php
lang/tr/ui.php
config/servernet.php
database/migrations/2026_10_03_000101_add_gpu_to_cloud_plans.php
routes/web.php
"

# ⚠️ این دیپلوی فایلِ استاتیکِ تازه ندارد؛ استایلِ صفحه درجاست (پیشوندِ gpu-).
PUB_FILES=""

CONFLICTS=""
UPD=0

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }

apply_one() {
  rel="$1"; dest="$2/$rel"
  mine_f="$WORK/mine.tmp"; base_f="$WORK/base.tmp"

  git -C repo show "$MINE:website/$rel" > "$WORK/mine.raw" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; return; }
  normalize "$WORK/mine.raw" "$mine_f"

  if [ -f "$dest" ] && [ "$DRY" = "0" ]; then
    mkdir -p "$BK/$(dirname "$rel")"
    cp -p "$dest" "$BK/$rel"
  fi

  if [ ! -f "$dest" ]; then
    [ "$DRY" = "0" ] && { mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"; }
    echo "NEW   $rel   (فایلِ تازه — روی سرور نیست)"; UPD=$((UPD+1)); return
  fi

  dest_n="$WORK/dest.tmp"; normalize "$dest" "$dest_n"

  if cmp -s "$dest_n" "$mine_f"; then
    cmp -s "$dest" "$mine_f" || { [ "$DRY" = "0" ] && cp "$mine_f" "$dest"; echo "EOL   $rel   (فقط پایانِ خط)"; UPD=$((UPD+1)); return; }
    echo "OK    $rel"; return
  fi

  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$WORK/cand.raw" 2>/dev/null || continue
    normalize "$WORK/cand.raw" "$WORK/cand.tmp"
    if cmp -s "$dest_n" "$WORK/cand.tmp"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$dest_n" "$WORK/cand.tmp")
    if [ "$d" -lt "$bestd" ]; then bestd=$d; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "CF    $rel   ← در تاریخچهٔ develop نیست؛ نسخهٔ ناشناخته روی سرور — دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"
    return
  fi

  if [ "$bestd" -eq 0 ]; then
    [ "$DRY" = "0" ] && cp "$mine_f" "$dest"
    echo "UP    $rel   (سرور = $(git -C repo rev-parse --short "$best") — جایگزینیِ بی‌ریسک)"; UPD=$((UPD+1)); return
  fi

  git -C repo show "$best:website/$rel" > "$WORK/base.raw"
  normalize "$WORK/base.raw" "$base_f"
  m="$WORK/merged.tmp"; cp "$dest_n" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    [ "$DRY" = "0" ] && cp "$m" "$dest"
    echo "MG    $rel   (پایه $(git -C repo rev-parse --short "$best")، فاصلهٔ سرور $bestd خط — تغییرِ دیگران حفظ شد)"
    UPD=$((UPD+1))
  else
    echo "CF    $rel   ← تداخل واقعی؛ دست نخورد (پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط)"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
}

echo "── بکاپ در: $BK"
echo
echo "═══ اپ ($APP) ═══"
for f in $APP_FILES; do apply_one "$f" "$APP"; done

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)

echo
union_ok=1
if [ -n "$PHPBIN" ]; then
  echo "═══ php -l روی فایل‌های نشسته ═══"
  for f in $APP_FILES; do
    case "$f" in *.php)
      [ -f "$APP/$f" ] || continue
      "$PHPBIN" -l "$APP/$f" >/dev/null 2>&1 || { echo "🔴 خطای نحو: $f"; union_ok=0; }
    ;; esac
  done
  [ "$union_ok" -eq 1 ] && echo "✅ نحو سالم"
fi

# ── پایانِ حالتِ آزمایشی ────────────────────────────────────────────────────
# 🔴 راستی‌آزماییِ اتحاد **نباید** در حالتِ آزمایشی بدود: هیچ‌چیز نوشته نشده،
#    پس هر `need_file`/`g` طبیعتاً «ننشسته» می‌دهد و گزارشی می‌سازد که شبیهِ
#    دیپلویِ شکست‌خورده است. بدتر، حلقهٔ بازگشتِ بکاپ هم صدا زده می‌شد در حالی
#    که در این حالت اصلاً بکاپی ساخته نشده — یعنی پیامِ «بکاپ برمی‌گردد» دربارهٔ
#    کاری حرف می‌زد که هرگز رخ نداده. تنها سؤالی که این حالت جواب می‌دهد این
#    است: «کدام فایل تداخل دارد؟»
if [ "$DRY" != "0" ]; then
  echo
  echo "═══ حالتِ آزمایشی — هیچ فایلی روی سرور نوشته نشد ═══"
  echo "برنامه: $UPD فایل به‌روز یا تازه"
  if [ -n "$CONFLICTS" ]; then
    echo "🔴 تداخل:$CONFLICTS"
    echo "   پیش از دیپلویِ واقعی باید حل شود."
    exit 1
  fi
  echo "✅ هیچ تداخلی نیست — همین فرمان را بدونِ DRY=1 بزن."
  echo "⚠️ راستی‌آزماییِ اتحاد، مهاجرت و پاکسازیِ کش فقط در اجرای واقعی می‌دوند."
  exit 0
fi

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────────
# 🔴 چرا لازم است: دیپلوی فایل‌به‌فایل است و «یک فایل جا ماند» فرضی نیست. اگر
#    درایور بنشیند و رجیستری نه، هر تماس ۵۰۰ می‌دهد؛ اگر ویو بنشیند و کلیدِ
#    زبان نه، کاربر «ui.gpu_h1» می‌بیند. هر دو با کدِ ۲۰۰.
need_file() { [ -f "$1" ] || { echo "🔴 نیست: ${1#$APP/}"; union_ok=0; }; }

need_file "$APP/app/Services/Cloud/SaladClient.php"
need_file "$APP/app/Services/Cloud/SaladOperations.php"
need_file "$APP/app/Http/Controllers/GpuController.php"
need_file "$APP/resources/views/pages/gpu.blade.php"
need_file "$APP/database/migrations/2026_10_03_000101_add_gpu_to_cloud_plans.php"

g() { grep -qF "$2" "$APP/$1" 2>/dev/null || { echo "🔴 $1: «$2» ننشسته"; union_ok=0; }; }

# روتِ تازه + روت‌هایی که نباید بیفتند (درسِ ۲۸ مرداد)
g routes/web.php "name('gpu')"
for r in "name('vps.hourly')" "name('cloud.index')" "name('healthz')" "name('go.pay')"; do
  g routes/web.php "$r"
done

# زنجیرهٔ صدازده‌شده — هر کدام بیفتد، خرابی **خاموش** است
g app/Services/Cloud/CloudManager.php "SaladClient::class"
g app/Services/Cloud/SaladClient.php "use SaladOperations"
g app/Models/CloudPlan.php "'gpu_model'"
g app/Services/Cloud/CloudNaming.php "gpuModel"
g app/Services/Cloud/CloudCatalogSync.php "\$gpuModel, \$gpuCount"
g app/Services/SystemHealth.php "unsellableCatalogue"
g app/Http/Controllers/SiteController.php "\$add('gpu')"
g app/Http/Controllers/Admin/SettingsController.php "salad_api_key"
g config/servernet.php "'gpu', []"

# مدلِ تحویل: برنامهٔ آماده + دروازه + توکن — هر کدام بیفتد خرابی خاموش است
g app/Services/Cloud/SaladOperations.php "public const APPS"
g app/Services/Cloud/SaladOperations.php "'networking'"
g app/Services/Cloud/CloudProvisioner.php "hostname"
g app/Models/CloudLocation.php "'XX'"

# جداییِ خطِ GPU از VPS — هر تکه بیفتد، دو خطِ محصول دوباره قاطی می‌شوند
g app/Models/CloudLocation.php "isGpuCode"
g app/Services/Cloud/SaladOperations.php "=> 'building'"
g app/Console/Commands/CloudMeterHourly.php "is_interruptible"
g app/Http/Controllers/Account/CloudStoreController.php "gpuMode"
g app/Http/Controllers/CloudCatalogController.php "isGpuCode"
g app/Services/SiteMenu.php "'XX'"
g resources/views/account/cloud-store.blade.php "startTab"
g resources/views/partials/cloud-locations-links.blade.php "isGpuCode"
g resources/views/pages/gpu.blade.php "location=global-gpu"
g resources/views/pages/gpu.blade.php "gpu_how_t"
g lang/fa/ui.php "gpu_how1_t"
g lang/en/ui.php "gpu_how1_t"
g lang/tr/ui.php "gpu_how1_t"

# کلیدهای زبان — هر سه فایل، وگرنه یک زبان متنِ خام نشان می‌دهد
for L in fa en tr; do
  g "lang/$L/ui.php" "gpu_h1"
  g "lang/$L/ui.php" "gpu_warn_t"
  g "lang/$L/ui.php" "gpu_units_d"
done

if [ "$union_ok" -eq 0 ]; then
  echo "🔴 اتحادِ فایل‌ها کامل نیست — کلِ بکاپ برمی‌گردد تا سایت ۵۰۰ نشود."
  ( cd "$BK" && find . -type f | while read -r p; do
      rel="${p#./}"
      cp "$p" "$APP/$rel"
      echo "   بازگشت: $rel"
    done )
  echo "🔴 دیپلوی ناتمام. خروجیِ بالا را بفرست."
  exit 1
fi

# ── مهاجرت (فقط --path خودش؛ idempotent و گارددار) + پاکسازی کش‌ها ─────────
if [ -n "$PHPBIN" ]; then
  cd "$APP"
  echo
  echo "═══ مهاجرتِ ستون‌های GPU ═══"
  # ⚠️ بی‌این مهاجرت، صفحهٔ /gpu **خالی** بالا می‌آید (کنترلر پشتِ hasColumn
  #    است، پس ۵۰۰ نمی‌دهد) و همگام‌ساز هم ستون‌ها را نمی‌نویسد.
  "$PHPBIN" artisan migrate --force \
    --path=database/migrations/2026_10_03_000101_add_gpu_to_cloud_plans.php \
    || { echo "🔴 مهاجرت نخورد — /gpu خالی می‌مانَد. خروجی را بفرست."; }

  "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
  "$PHPBIN" artisan tinker --execute='\App\Http\Middleware\PageCache::purge(); echo "pagecache purged";' 2>/dev/null \
    || echo "⚠️ purge کشِ صفحه دستی: از /admin یا صبر تا TTL"
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
  echo "WARN: php پیدا نشد — کش‌ها دستی پاک شدند و مهاجرت اجرا **نشد**"
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · فایل‌های به‌روزشده: $UPD"
if [ -n "$CONFLICTS" ]; then
  echo "🔴 فایل‌های تداخل‌دار (دست‌نخورده، نیازمند merge دستی):$CONFLICTS"
  echo "   نسخه‌ها در $WORK/conflicts/ (پسوند .server / .base / .new)"
else
  echo "✅ هیچ تداخلی نبود"
fi
echo
echo "کارِ باقی‌مانده: ریستِ opcache از /system/opcache (validate_timestamps=0 — بی‌ریست کدِ تازه اجرا نمی‌شود)"
echo
echo "═══ سپس در /admin/settings ═══"
echo "  تبِ زیرساخت  → «زیرساختِ ۶ — GPU»: کلیدِ API، نامِ سازمان، نامِ پروژه، ایمیجِ کانتینر"
echo "  تبِ قیمت‌گذاری → «نرخِ دستیِ دلار» (اگر نرخِ زنده نمی‌آید)"
echo "  و بعد یک بار:  $PHPBIN artisan cloud:sync"
echo
echo "راستی‌آزمایی (بعد از ریستِ opcache):"
echo "  curl -s -o /dev/null -w '%{http_code}\n' https://servernet.cloud/gpu        ← 200"
echo "  curl -s -o /dev/null -w '%{http_code}\n' https://servernet.cloud/en/gpu     ← 200"
echo "  curl -s https://servernet.cloud/gpu | grep -c 'ui\.gpu_'                    ← 0 (کلیدِ خام نباشد)"
echo "  curl -s https://servernet.cloud/sitemap.xml | grep -c '/gpu'                ← ۳ (سه زبان)"
echo "  و /admin/errors ← چکِ «قیمتِ کاتالوگ» باید سبز باشد؛ قرمزش یعنی نرخِ ارز نیست"
