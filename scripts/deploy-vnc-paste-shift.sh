#!/usr/bin/env bash
#
# دیپلوی «چسباندن در کنسول: Shift را خودمان بفرستیم» — ۱۱ شهریور ۱۴۰۵.
#
# اجرا از ترمینالِ cPanel (اکانت servernetcloud):
#   DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-vnc-paste-shift.sh)
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-vnc-paste-shift.sh) [<SHA>]
#
# ⚠️ اسکریپت‌های دیپلوی در `scripts/`ِ **ریشهٔ مخزن**اند، نه `website/scripts/`.
#
# چه چیزی و چرا:
#   🔴 مشتری گزارش داد که متنِ چسبانده‌شده در کنسولِ تحتِ وب خراب وارد می‌شود:
#          $→4   _→-   :→;   }→]   G→g
#      یعنی هر کاراکترِ شیفت‌دار به کلیدِ پایه‌اش می‌افتاد و رمز، کلیدِ SSH و
#      دستورِ داکر همیشه غلط تایپ می‌شد.
#   • ریشه در کدِ خودمان بود، نه اِمولاتور و نه انکودینگ: سرورِ VNC (QEMU) در
#     key_event حروفِ A–Z را عمداً کوچک می‌کند و انتظار دارد **کلاینت** مثلِ یک
#     صفحه‌کلیدِ واقعی Shift_L را خودش نگه دارد. تایپِ دستی کار می‌کرد چون
#     مرورگر رویدادِ فیزیکیِ Shift را جداگانه می‌فرستد؛ ولی حلقهٔ چسباندنِ ما
#     با rfb.sendKey(keysym, null) هیچ مدیفایری نمی‌فرستاد.
#   • حالا Shift برای یک **رشتهٔ پیوسته** از کاراکترهای شیفت‌دار نگه داشته و
#     پیش از Enter/Tab و در پایان رها می‌شود (رها نکردنش = Shiftِ گیرکرده در
#     مهمان)، و گاردِ `pasting` جلوی دو حلقهٔ موازی را می‌گیرد.
#
# ✅ یک فایل. بدونِ مهاجرت، بدونِ کلیدِ زبان، بدونِ CSS.
#
# منطقِ merge و گاردِ Blade از scripts/deploy-hourly-term-guard.sh
# ⚠️ تلهٔ CRLF: بی‌normalize، پایه‌یاب روی سرور کور می‌شود.
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-vnc-paste"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=60
DRY="${DRY:-0}"

# ═══ 🔴 اثباتِ مقصد — پیش از هر نوشتنی ═══
# اجرا با کاربرِ اشتباه یعنی $HOME عوض می‌شود، فایل جایی می‌نشیند که سایت آن‌جا
# نیست، و گاردِ اتحاد **سبز** می‌شود چون همان فایلی را می‌سنجد که خودش ساخته.
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "🔴 «$APP» نصبِ لاراول نیست (artisan یا vendor نیست)."
  echo "   احتمالاً با کاربرِ اشتباه واردید. کاربرِ درست: servernetcloud"
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
  git -C repo fetch --depth 400 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 400 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

MINE="${1:-f4d82427}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
[ "$DRY" = "0" ] || echo "── حالتِ آزمایشی (DRY=1): هیچ فایلی نوشته نمی‌شود"

# ⚠️ تنها فایلِ این دیپلوی. جفتِ هم‌بسته‌ای ندارد: نه کلیدِ زبانِ تازه‌ای اضافه
#    شده، نه کلاسِ CSSای. اگر روزی این فهرست بلند شد، یادت باشد که فوتر یک‌بار
#    چون بیرونِ فهرست ماند کلِ en/tr را ۵۰۰ کرد.
APP_FILES="
resources/views/account/cloud-console.blade.php
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
# 🔴 `php -l` روی `.blade.php` **بی‌اثر است**: دایرکتیوها بیرونِ تگِ <?php اند،
# پس یک @if بی‌@endif — که صفحه را ۵۰۰ می‌کند — «No syntax errors» می‌گیرد.
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

apply_one() {
  rel="$1"; dest="$APP/$rel"
  mine_f="$WORK/mine.tmp"; base_f="$WORK/base.tmp"

  git -C repo show "$MINE:website/$rel" > "$WORK/mine.raw" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; return; }
  normalize "$WORK/mine.raw" "$mine_f"

  if [ -f "$dest" ] && [ "$DRY" = "0" ]; then
    mkdir -p "$BK/$(dirname "$rel")"
    cp -p "$dest" "$BK/$rel"
  fi

  # 🔴 «NEW» پرچمِ قرمز است: این فایل از قبل روی سرور هست. NEW یعنی یا مسیر
  #    غلط است یا با کاربرِ اشتباه واردیم.
  if [ ! -f "$dest" ]; then
    echo "NEW   $rel   🔴 روی سرور نبود — مقصد را بررسی کن"
    [ "$DRY" = "0" ] && { mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"; CREATED="$CREATED $rel"; }
    UPD=$((UPD+1)); lint_or_restore "$rel"; return
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
    [ "$DRY" = "0" ] && { cp "$mine_f" "$dest"; lint_or_restore "$rel"; }
    echo "UP    $rel   (سرور = $(git -C repo rev-parse --short "$best") — جایگزینیِ بی‌ریسک)"; UPD=$((UPD+1)); return
  fi

  git -C repo show "$best:website/$rel" > "$WORK/base.raw"
  normalize "$WORK/base.raw" "$base_f"
  m="$WORK/merged.tmp"; cp "$dest_n" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    [ "$DRY" = "0" ] && { cp "$m" "$dest"; lint_or_restore "$rel"; }
    echo "MG    $rel   (پایه $(git -C repo rev-parse --short "$best")، فاصلهٔ سرور $bestd خط — تغییرِ دیگران حفظ شد)"
    UPD=$((UPD+1))
  else
    echo "CF    $rel   ← تداخل واقعی؛ دست نخورد (پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط)"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
    echo "──── سرور − پایه ($rel):"
    diff -u "$base_f" "$dest_n" | sed -n '1,140p'
    echo "──── پایانِ diff"
  fi
}

echo "── بکاپ در: $BK"
echo
echo "═══ اپ ($APP) ═══"
for f in $APP_FILES; do apply_one "$f"; done

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
  echo "🔴 خطای نحوی:$LINT_FAIL — همان فایل از بکاپ برگشت."
  union_ok=0
fi

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────────
# 🔴 `--` اجباری است: هر الگویی که با `-` شروع شود را grep **گزینه** می‌خواند،
#    و با stderrِ خفه، شکستِ ابزار عیناً شبیهِ شکستِ ادعا دیده می‌شود.
g() {
  err=$(grep -qF -- "$2" "$1" 2>&1) && return 0
  [ -n "$err" ] && echo "   (grep گفت: $err)"
  echo "🔴 ${1#$HOME/}: «$2» ننشسته"
  union_ok=0
}

V="$APP/resources/views/account/cloud-console.blade.php"

echo
echo "═══ ضمانتِ اتحاد ═══"

# ── تعمیرِ اصلی: هر شش تکه لازم است ──
# نبودِ هرکدام یعنی یا اصلاً چیزی عوض نشده، یا merge نصفه‌اش کرده — و نصفهٔ
# این تغییر بدتر از نبودنش است: Shift زده می‌شود ولی رها نمی‌شود.
g "$V" "var SHIFT_L = 0xFFE1"
g "$V" "function needsShift(ch)"
g "$V" "rfb.sendKey(SHIFT_L, null, on)"
g "$V" "var pasting = false"
g "$V" "shift(needsShift(ch))"
g "$V" "function finish()"

# ── و آنچه **نباید** قربانیِ merge شود ──
# هر کدام قاعده‌ای است که پیش‌تر با خونِ دل نوشته شده و merge می‌تواند بی‌صدا
# برش دارد.
g "$V" "Array.from(text)"                     # نه split — حروفِ خارج از BMP
g "$V" "0x01000000 + cp"                      # آفستِ یونیکدِ X11
g "$V" "setTimeout(step, 12)"                 # صفِ ورودیِ محدودِ کنسول
g "$V" "rfb.sendKey(0xFF0D, 'Enter')"         # Enter با scancode
g "$V" "#vnc-wrap:-webkit-full-screen"        # قاعدهٔ جدا، نه با کاما
g "$V" "beforeunload"                         # بستنِ تمیزِ نشست
g "$V" "lroute('account.servers')"            # نه route — مشتریِ /en و /tr
g "$V" "scaleViewport"

[ "$union_ok" -eq 1 ] && echo "✅ همهٔ ضمانت‌ها نشستند"

# ── بازگشتِ کامل اگر اتحاد ناقص است ─────────────────────────────────────────
if [ "$union_ok" -eq 0 ]; then
  echo
  echo "🔴 اتحادِ فایل کامل نیست — بکاپ برمی‌گردد تا صفحه ۵۰۰ نشود."
  ( cd "$BK" && find . -type f | while read -r p; do
      rel="${p#./}"; cp "$p" "$APP/$rel"; echo "   بازگشت: $rel"
    done )
  for rel in $CREATED; do rm -f "$APP/$rel" && echo "   حذفِ فایلِ تازه: $rel"; done
  echo "🔴 دیپلوی ناتمام. خروجیِ بالا را بفرست."
  exit 1
fi

# ── کش‌ها ───────────────────────────────────────────────────────────────────
# 🔴 view:clear این‌جا **اجباری** است، نه تشریفات: تغییر فقط داخلِ یک Blade
#    است و نسخهٔ کامپایل‌شدهٔ قدیمی تا پاک نشود سرو می‌شود. و چون نامِ فایلِ
#    کامپایل‌شده از **مسیرِ** ویو ساخته می‌شود نه محتوایش، همان نام دوباره
#    نوشته می‌شود ⇒ opcache هم باید ریست شود وگرنه بایت‌کدِ قدیمی می‌مانَد.
if [ -n "$PHPBIN" ]; then
  cd "$APP"
  "$PHPBIN" artisan view:clear && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
  rm -f "$APP/storage/framework/views/"*.php
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

# متنِ راستی‌آزمایی در هیردوکِ کوته‌نشان است تا $ و کوتِ داخلِ نمونه‌دستور
# دست‌نخورده چاپ شود — همان کاراکترهایی که اصلِ ماجرایند.
cat <<'VERIFY'

کارِ باقی‌مانده: ریستِ opcache از /system/opcache  ← بدونِ این، صفحه قدیمی می‌مانَد

═══ راستی‌آزمایی (بعد از ریستِ opcache) ═══
  ۱) پنل → سرویس‌ها → یک سرورِ Hetzner → «کنسول»
     https://console.servernet.cloud/account/cloud/<id>/console
     ⚠️ فقط زیرساختِ Hetzner کنسول می‌دهد؛ بقیه دکمه را نشان نمی‌دهند.

  ۲) در کنسول، دکمهٔ «چسباندن» → این خط را بچسبان و بفرست:
        sudo docker run -e PASSWORD='Test_$123:G}'
     ← باید بایت‌به‌بایت همین دربیاید. پیش از این تعمیر می‌شد:
        sudo docker run -e password='test-4123;g]'

  ۳) یک کلیدِ SSH کامل هم بچسبان (AAAAB3Nza… با حروفِ بزرگ و + / =)
     ← حروفِ بزرگ باید بزرگ بمانند.

  ۴) بعدش یک حرفِ کوچک تایپ کن (مثلاً g) ← باید کوچک بماند.
     اگر بزرگ شد یعنی Shift رها نشده — که همان چیزی است که finish() می‌بندد.

ℹ️ کاراکترِ غیرلاتین (فارسی) با keymapِ QEMU نگاشت نمی‌شود و بی‌صدا می‌افتد —
   این رفتار از قبل هم بود و در این دیپلوی تغییر نکرده.
VERIFY
