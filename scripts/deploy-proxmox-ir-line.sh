#!/usr/bin/env bash
#
# دیپلوی «خطِ VPSِ ایران روی Proxmox» — ۱۱ شهریور ۱۴۰۵.
#
# اجرا از ترمینالِ cPanel (اکانت servernetcloud):
#   DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-proxmox-ir-line.sh)
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-proxmox-ir-line.sh) [<SHA>]
#
# چه چیزی و چرا:
#   مشتری SN-978603 «سرور ایران ۱ گیگ / ۱ هسته» خرید و پولش را داد. ماشین روی
#   Proxmox ساخته شد ولی به پرتالِ مشتری وصل نشد: فرمِ «اتصالِ سرورِ موجود» یک
#   cloud_plan_id می‌خواهد و هیچ پلنِ ایرانی نبود — و پلن از هیچ صفحه‌ای دستی
#   ساخته نمی‌شود، تنها منبعش fetchCatalog() است.
#     • مکان همان ir-tehranِ آروان است تا اسلاگ مشترک بماند
#     • اندازه‌ها از proxmox_ir_plans و شهر از proxmox_ir_city می‌آیند
#
# بدونِ مهاجرت. تنها یک فایلِ اپ عوض می‌شود.
#
# بعد از این اسکریپت دو کارِ دستی مانده و ترتیبشان مهم است:
#   ۱) ریستِ opcache، بعد /admin/cloud → «همگام‌سازیِ کاتالوگ»
#      (وگرنه پلن ساخته نمی‌شود و فرمِ اتصال خالی می‌مانَد)
#   ۲) زیرساختِ ۵ عمداً خاموش می‌مانَد تا قیمت‌گذاری نشده کسی نخرد؛
#      scopeSellable زیرساختِ خاموش را کنار می‌گذارد ولی درایور و فرمِ
#      اتصال همچنان کار می‌کنند.
#
# منطقِ merge از scripts/deploy-gsc-crawl.sh
# تلهٔ CRLF: بی‌normalize، پایه‌یاب روی سرور کور می‌شود.
#
set -u

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-proxmox-ir"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=60
DRY="${DRY:-0}"
SITE="https://servernet.cloud"

# ═══ 🔴 اثباتِ مقصد — پیش از هر نوشتنی ═══
# اجرا با کاربرِ اشتباه یعنی $HOME عوض می‌شود، فایل‌ها جایی می‌نشینند که سایت
# آن‌جا نیست، و گاردِ اتحاد **سبز** می‌شود چون همان فایلی را می‌سنجد که خودش
# تازه ساخته.
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "🔴 «$APP» نصبِ لاراول نیست (artisan یا vendor نیست)."
  echo "   احتمالاً با کاربرِ اشتباه واردید. کاربرِ درست: servernetcloud"
  exit 1
fi
if [ ! -d "$PUB" ]; then
  echo "🔴 «$PUB» نیست — مقصدِ استاتیک پیدا نشد."
  exit 1
fi

FREE_MB=$(df -Pm "$HOME" | awk 'NR==2{print $4}')
if [ "${FREE_MB:-0}" -lt 500 ]; then
  echo "🔴 فضای آزاد کم است (${FREE_MB}MB). اول:  rm -rf ~/deploy-*/repo"
  exit 1
fi

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }

if [ -d repo/.git ]; then
  git -C repo fetch --depth 400 origin develop feature/proxmox-iran-vps 2>/dev/null \
    || git -C repo fetch --depth 400 origin develop \
    || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 400 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
  git -C repo fetch --depth 400 origin feature/proxmox-iran-vps 2>/dev/null || true
fi

MINE="${1:-8479dd30}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
[ "$DRY" = "0" ] || echo "── حالتِ آزمایشی (DRY=1): هیچ فایلی نوشته نمی‌شود"

# ⚠️ هر ۹ فایل با هم می‌روند. سه فایلِ زبان **جفتِ هم‌بستهٔ** کنترلرند: کلیدِ
#    `cvb_e_hourly_unsupported` تازه است و اگر کنترلر برود و lang نه، پیامِ
#    خطا خامِ کلید چاپ می‌شود. و blade جفتِ CSS است.
APP_FILES="
app/Services/Cloud/ProxmoxClient.php
app/Services/Cloud/CloudProvisioner.php
"

# ⚠️ فقط زیرِ assets/. هرگز `public/index.php` یا `public/.htaccess` — نسخهٔ
#    سرور فرق دارد و رونویسی‌شان یک‌بار کلِ سایت را ۵۰۰ کرد.
# هیچ فایلِ استاتیکی عوض نشده.
PUB_FILES="
"

CONFLICTS=""
CREATED=""
UPD=0
LINT_FAIL=""

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }
normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)

# ── گاردِ نحوی ────────────────────────────────────────────────────────────
#
# 🔴 `php -l` روی `.blade.php` **بی‌اثر است**: دایرکتیوها بیرونِ تگِ `<?php`اند،
# پس یک `@if` بی‌`@endif` — که صفحه را ۵۰۰ می‌کند — «No syntax errors» می‌گیرد.
# پس Blade **کامپایل** می‌شود و خروجیِ کامپایل‌شده lint می‌شود.
cat > "$WORK/bladecheck.php" <<'PHPCHK'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$tmp = sys_get_temp_dir().'/bladechk_'.getmypid().'.php';
file_put_contents($tmp, Illuminate\Support\Facades\Blade::compileString(file_get_contents($argv[2])));
exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1', $o, $rc);
unlink($tmp);
exit($rc);
PHPCHK

# $1 = مسیرِ نسبی زیرِ website/ (فقط برای فایل‌های اپ)
lint_or_restore() {
  [ -n "$PHPBIN" ] || return 0
  case "$1" in
    *.blade.php) "$PHPBIN" "$WORK/bladecheck.php" "$APP" "$APP/$1" >/dev/null 2>&1 && return 0 ;;
    *.php)       "$PHPBIN" -l "$APP/$1" >/dev/null 2>&1 && return 0 ;;
    *)           return 0 ;;
  esac
  echo "      🔴 خطای نحوی بعد از نوشتن — از بکاپ برگردانده شد: $1"
  if [ -f "$BK/$1" ]; then cp -p "$BK/$1" "$APP/$1"; else rm -f "$APP/$1"; fi
  LINT_FAIL="$LINT_FAIL $1"
  return 1
}

# $1 = مسیرِ نسبی زیر website/   $2 = ریشهٔ مقصد   $3 = مسیرِ نسبیِ مقصد
#
# ⚠️ بکاپ زیرِ «ریشهٔ مقصد» نگه داشته می‌شود ($BK/app/… در برابر $BK/__pub__/…)
#    وگرنه حلقهٔ بازگشت نمی‌داند هر فایل به کدام ریشه برمی‌گردد.
apply_one() {
  rel="$1"; root="$2"; drel="$3"; dest="$root/$drel"
  if [ "$root" = "$PUB" ]; then bkrel="__pub__/$drel"; else bkrel="$drel"; fi
  mine_f="$WORK/mine.tmp"; base_f="$WORK/base.tmp"

  git -C repo show "$MINE:website/$rel" > "$WORK/mine.raw" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; return; }
  normalize "$WORK/mine.raw" "$mine_f"

  if [ -f "$dest" ] && [ "$DRY" = "0" ]; then
    mkdir -p "$BK/$(dirname "$bkrel")"
    cp -p "$dest" "$BK/$bkrel"
  fi

  # 🔴 «NEW» روی این دیپلوی پرچمِ قرمز است: هر ۱۰ فایل باید از قبل روی سرور
  #    باشند. NEW یعنی یا مسیر غلط است یا با کاربرِ اشتباه واردیم.
  if [ ! -f "$dest" ]; then
    echo "NEW   $drel   🔴 روی سرور نبود — مقصد را بررسی کن"
    [ "$DRY" = "0" ] && { mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"; CREATED="$CREATED $root|$drel"; }
    UPD=$((UPD+1)); [ "$root" = "$APP" ] && lint_or_restore "$drel"; return
  fi

  dest_n="$WORK/dest.tmp"; normalize "$dest" "$dest_n"

  if cmp -s "$dest_n" "$mine_f"; then
    cmp -s "$dest" "$mine_f" || { [ "$DRY" = "0" ] && cp "$mine_f" "$dest"; echo "EOL   $drel   (فقط پایانِ خط)"; UPD=$((UPD+1)); return; }
    echo "OK    $drel"; return
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
    echo "CF    $drel   ← در تاریخچهٔ develop نیست؛ نسخهٔ ناشناخته روی سرور — دست نخورد"
    CONFLICTS="$CONFLICTS $drel"
    keep="$WORK/conflicts/$drel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"
    return
  fi

  if [ "$bestd" -eq 0 ]; then
    [ "$DRY" = "0" ] && { cp "$mine_f" "$dest"; [ "$root" = "$APP" ] && lint_or_restore "$drel"; }
    echo "UP    $drel   (سرور = $(git -C repo rev-parse --short "$best") — جایگزینیِ بی‌ریسک)"; UPD=$((UPD+1)); return
  fi

  git -C repo show "$best:website/$rel" > "$WORK/base.raw"
  normalize "$WORK/base.raw" "$base_f"
  m="$WORK/merged.tmp"; cp "$dest_n" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    [ "$DRY" = "0" ] && { cp "$m" "$dest"; [ "$root" = "$APP" ] && lint_or_restore "$drel"; }
    echo "MG    $drel   (پایه $(git -C repo rev-parse --short "$best")، فاصلهٔ سرور $bestd خط — تغییرِ دیگران حفظ شد)"
    UPD=$((UPD+1))
  else
    echo "CF    $drel   ← تداخل واقعی؛ دست نخورد (پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط)"
    CONFLICTS="$CONFLICTS $drel"
    keep="$WORK/conflicts/$drel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
    echo "──── سرور − پایه ($drel):"
    diff -u "$base_f" "$dest_n" | sed -n '1,140p'
    echo "──── پایانِ diff"
  fi
}

echo "── بکاپ در: $BK"
echo
echo "═══ اپ ($APP) ═══"
for f in $APP_FILES; do apply_one "$f" "$APP" "$f"; done
echo
echo "═══ استاتیک ($PUB) ═══"
for f in $PUB_FILES; do apply_one "public/$f" "$PUB" "$f"; done

union_ok=1

if [ "$DRY" != "0" ]; then
  echo
  echo "═══ حالتِ آزمایشی — هیچ فایلی نوشته نشد ═══"
  echo "برنامه: $UPD فایل به‌روز"
  if [ -n "$CONFLICTS" ]; then
    echo "🔴 تداخل:$CONFLICTS — پیش از دیپلویِ واقعی باید حل شود."
    exit 1
  fi
  echo "✅ هیچ تداخلی نیست — همین فرمان را بدونِ DRY=1 بزن."
  exit 0
fi

if [ -n "$LINT_FAIL" ]; then
  echo
  echo "🔴 خطای نحوی:$LINT_FAIL — همان فایل‌ها از بکاپ برگشتند."
  union_ok=0
fi

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────────
# 🔴 `--` اجباری است: هر الگویی که با `-` شروع شود را grep **گزینه** می‌خواند،
#    و با stderrِ خفه، شکستِ ابزار عیناً شبیهِ شکستِ ادعا دیده می‌شود — یک بار
#    دقیقاً همین یک دیپلویِ سالم را برگرداند.
g() {
  err=$(grep -qF -- "$2" "$1" 2>&1) && return 0
  [ -n "$err" ] && echo "   (grep گفت: $err)"
  echo "🔴 ${1#$HOME/}: «$2» ننشسته"
  union_ok=0
}

echo
echo "═══ ضمانتِ اتحاد ═══"

# ── تعمیرِ اصلی ──
g "$APP/app/Services/Cloud/ProxmoxClient.php" "irPlans"
g "$APP/app/Services/Cloud/ProxmoxClient.php" "irCity"
g "$APP/app/Services/Cloud/ProxmoxClient.php" "irCostCents"
g "$APP/app/Services/Cloud/ProxmoxClient.php" "IR_PLANS_DEFAULT"

# ── و آنچه نباید قربانیِ merge شود ──
# خطِ اکسیت و کلِ مسیرِ ساخت در همین یک فایل‌اند؛ یک merge بد می‌تواند بی‌صدا
# برشان دارد و آن‌وقت تحویلِ اکسیت‌ها می‌میرد بی‌آنکه چیزی خطا بدهد.
g "$APP/app/Services/Cloud/ProxmoxClient.php" "exitCountries"
g "$APP/app/Services/Cloud/ProxmoxClient.php" "exit-vps-"
g "$APP/app/Services/Cloud/ProxmoxClient.php" "cipassword"
g "$APP/app/Services/Cloud/ProxmoxClient.php" "waitForTask"

# 🔴 تعمیرِ IP: بی‌این، «اتصالِ سرورِ موجود» سرویسی می‌سازد که نه آدرس دارد
#    نه پورتِ عمومی می‌گیرد — و هیچ خطایی هم ثبت نمی‌شود.
g "$APP/app/Services/Cloud/ProxmoxClient.php" "vmIp(\$node, \$ref)"

# 🔴 صفِ همگام‌سازی باید نمونهٔ بی‌IP را هم بردارد، وگرنه تعمیرِ بالا هرگز
#    فرصتِ اجرا پیدا نمی‌کند.
g "$APP/app/Services/Cloud/CloudProvisioner.php" "orWhereNull('"'"'ipv4'"'"')"
g "$APP/app/Services/Cloud/CloudProvisioner.php" "syncInstances"
g "$APP/app/Services/Cloud/CloudProvisioner.php" "deliverOwedNotices"

# 🔴 آزمونِ زندهٔ کاتالوگ — تنها چیزی که واقعاً ثابت می‌کند کار می‌کند.
#    گاردهای بالا فقط می‌گویند «رشته در فایل هست»؛ این می‌گوید «پلن ساخته شد».
if [ -n "$PHPBIN" ]; then
  echo
  echo "── آزمونِ زندهٔ کاتالوگ ──"
  ( cd "$APP" && "$PHPBIN" artisan tinker --execute='
$c = app(\App\Services\Cloud\ProxmoxClient::class)->fetchCatalog();
$ir = array_values(array_filter($c["plans"], fn($p) => str_starts_with((string) $p["location_code"], "ir-")));
echo "IR_PLANS=".count($ir)."
";
foreach ($ir as $p) { echo "   ".$p["location_code"]." ".$p["vcpu"]."c/".intdiv($p["ram_mb"],1024)."g/".$p["disk_gb"]."d
"; }
' ) 2>&1 | sed -n "1,12p"
fi

# ── بازگشتِ کامل اگر اتحاد ناقص است ─────────────────────────────────────────
if [ "$union_ok" -eq 0 ]; then
  echo
  echo "🔴 اتحادِ فایل‌ها کامل نیست — کلِ بکاپ برمی‌گردد تا سایت ۵۰۰ نشود."
  ( cd "$BK" && find . -type f | while read -r p; do
      rel="${p#./}"
      case "$rel" in
        __pub__/*) cp "$p" "$PUB/${rel#__pub__/}"; echo "   بازگشت (public_html): ${rel#__pub__/}" ;;
        *)         cp "$p" "$APP/$rel";            echo "   بازگشت (app): $rel" ;;
      esac
    done )
  for item in $CREATED; do
    root="${item%%|*}"; rel="${item#*|}"
    rm -f "$root/$rel" && echo "   حذفِ فایلِ تازه: $rel"
  done
  echo "🔴 دیپلوی ناتمام. خروجیِ بالا را بفرست."
  exit 1
fi

# ── کش‌ها (هیچ مهاجرتی در کار نیست) ─────────────────────────────────────────
if [ -n "$PHPBIN" ]; then
  cd "$APP"
  "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
  # صفحهٔ فروشگاه پشتِ لاگین است و کشِ صفحه نمی‌گیرد، ولی purge بی‌ضرر است.
  "$PHPBIN" artisan tinker --execute='\App\Http\Middleware\PageCache::purge(); echo "pagecache purged";' 2>/dev/null \
    || echo "⚠️ purge کشِ صفحه انجام نشد (مهم نیست — فروشگاه کش نمی‌شود)"
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
  echo "WARN: php پیدا نشد — کش‌ها دستی پاک شدند"
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · فایل‌های به‌روزشده: $UPD"
if [ -n "$CONFLICTS" ]; then
  echo "🔴 تداخل (دست‌نخورده):$CONFLICTS   — نسخه‌ها در $WORK/conflicts/"
else
  echo "✅ هیچ تداخلی نبود"
fi
echo
echo "🔴 کارِ باقی‌مانده — به همین ترتیب:"
echo "  ۱) ریستِ opcache:  /system/opcache"
echo "  ۲) /admin/cloud → دکمهٔ «همگام‌سازیِ کاتالوگ»"
echo "     ← بی‌این، پلنِ ایران ساخته نمی‌شود و فرمِ اتصال خالی می‌مانَد."
echo "  ۳) زیرساختِ ۵ را روشن نکن تا قیمت‌گذاری نکرده‌ای — خاموش‌بودنش فقط"
echo "     جلوی فروشِ عمومی را می‌گیرد، نه اتصال و نه تحویل."
echo
echo "═══ راستی‌آزمایی (بعد از همگام‌سازی) ═══"
echo "  /admin/cloud?provider=proxmox   ← باید ردیف‌های «ایران — تهران» بیاید"
echo "  /admin/cloud/attach             ← پلنِ ایران باید در فهرست باشد"
