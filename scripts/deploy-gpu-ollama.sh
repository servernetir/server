#!/usr/bin/env bash
#
# Ollama همیشه تازه‌ترین نسخه + رفعِ وعدهٔ رمزِ نداشته در ایمیلِ GPU — مهر ۱۴۰۵.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-gpu-ollama.sh)
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-gpu-ollama.sh) [<SHA>]
#
# 🔴 چرا لازم شد (تیکت‌های TK-260923-0856 و TK-260923-2867، یک مشتری، یک شب):
#
#   ۱ ایمیجِ «برنامهٔ آمادهٔ Ollama» روی recipeی از **2024-09-19** سفت شده بود.
#     مشتری RTX 3090 ساعتی خرید تا qwen3:8b اجرا کند و Ollama گفت «به نسخهٔ
#     جدیدتر نیاز است». این خط سرویس SSH ندارد، پس نه ارتقا ممکن بود نه
#     تعویضِ ایمیج. از دیدِ ما تحویل «موفق» بود و هیچ خطایی ثبت نشد.
#
#   ۲ ایمیلِ تحویلِ همان سرویس می‌گفت «رمز root یک بار در پنل نمایش داده
#     می‌شود» — این خط اصلاً رمزی ندارد.
#
# ⚠️ **این دیپلوی به‌تنهایی هیچ‌چیز را عوض نمی‌کند.** بعدش باید در /admin/cloud
#    دکمهٔ همگام‌سازی زده شود تا تگِ تازه حل و در cloud_images بنشیند. تا آن
#    لحظه کاتالوگ همان ایمیجِ کهنه را دارد.
#
# ⚠️ نمونه‌های **موجود** با ایمیج قبلی‌شان بالا مانده‌اند؛ این زیرساخت rebuild
#    ندارد. ارتقای یک مشتری یعنی نمونهٔ تازه.
#
# ⚠️ نه مهاجرت دارد نه فایلِ استاتیک. اگر روزی اضافه شد، این کامنت را عوض کن —
#    نه اینکه بی‌صدا اجرا نشود.
#
# منطق عیناً از scripts/deploy-hetzner-storage.sh: merge سه‌طرفه با پایهٔ
# خودکار به‌ازای هر فایل (UP/MG/CF) + بکاپ کامل + بازگشتِ خودکار.
set -u

DRY="${DRY:-0}"

APP="$HOME/servernet_app"
WORK="$HOME/deploy-gpu-ollama"

STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=80

# ═══ 🔴 اثباتِ مقصد — پیش از هر نوشتنی ═══
#
# اجرا با کاربرِ اشتباه یعنی $HOME عوض می‌شود، فایل‌ها جایی می‌نشینند که سایت
# آن‌جا نیست، و گاردِ اتحاد **سبز** می‌شود چون همان فایل‌هایی را می‌سنجد که
# خودش تازه ساخته.
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "🔴 «$APP» نصبِ لاراول نیست (artisan یا vendor نیست)."
  echo "   احتمالاً با کاربرِ اشتباه واردید. کاربرِ درست: servernetcloud"
  echo "   چاره:  su - servernetcloud   و بعد همین دستور را دوباره بزنید."
  exit 1
fi

FREE_MB=$(df -Pm "$HOME" | awk 'NR==2{print $4}')
if [ "${FREE_MB:-0}" -lt 500 ]; then
  echo "🔴 فضای آزاد کم است (${FREE_MB}MB). اول پاک‌سازی کنید:  rm -rf ~/deploy-*/repo"
  exit 1
fi

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }

if [ -d repo/.git ]; then
  git -C repo fetch --depth 500 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 500 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

# 🔴 پین به کامیتِ مشخص — نوکِ متحرکِ develop را دیپلوی نکن.
MINE="${1:-f0b4eba0}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# 🔴 ترتیب معنادار است: اول کلاس‌های مستقلِ تازه، بعد config، بعد مدل، بعد
#    رجیستریِ درایور، و آخر کنترلر و ویو. اگر اجرا وسطِ کار بمیرد، حالتِ
#    میانی باید «قابلیت هنوز نیست» باشد، نه «قابلیت هست ولی کلاسش نیست».
# 🔴 ترتیب معنادار است: اول کلاسِ مستقل، بعد مدلی که صدایش می‌زند، بعد
#    فرمان و مهاجرت. اگر اجرا وسطِ کار بمیرد، حالتِ میانی باید «محافظ هنوز
#    نیست» باشد، نه «مدل کلاسی را صدا می‌زند که روی سرور نیست» — که یعنی
#    **هر پاسخِ تیکت** با Class not found می‌ترکد.
# 🔴 ترتیب معنادار است: اول کلاسِ مستقلِ تازه، بعد چیزی که صدایش می‌زند.
#    اگر اجرا وسطِ کار بمیرد، حالتِ میانی باید «قابلیت هنوز نیست» باشد، نه
#    «کاتالوگ کلاسی را صدا می‌زند که روی سرور نیست» — که یعنی cloud:sync و
#    کلِ فروشِ GPU با Class not found می‌ترکد.
#
#    فایل‌های زبان **آخر**: کلیدِ نبود متنِ خام چاپ می‌کند، ولی قالبی که کلیدِ
#    تازه را بخواهد و فایلِ زبان هنوز کهنه باشد فقط یک جای خالی می‌دهد — نه ۵۰۰.
APP_FILES="
app/Services/Cloud/SaladOllamaImage.php
app/Services/Cloud/SaladOperations.php
app/Mail/ServiceReadyMail.php
resources/views/emails/service-ready.blade.php
app/Services/Cloud/CloudProvisioner.php
lang/fa/ui.php
lang/en/ui.php
lang/tr/ui.php
"

PUB_FILES=""

CONFLICTS=""
CREATED=""
UPD=0

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }

# ═══ شمارشِ کرونِ سرور، پیش از هر نوشتنی ═══
# این دیپلوی routes/console.php را دست نمی‌زند، پس عدد باید **دقیقاً** ثابت
# بماند. هر تغییری یعنی چیزی غیرمنتظره رخ داده.
CRON_BEFORE=0
if [ -f "$APP/routes/console.php" ]; then
  CRON_BEFORE=$(grep -c 'Schedule::command' "$APP/routes/console.php" 2>/dev/null || echo 0)
fi
echo "── کرونِ فعلیِ سرور: $CRON_BEFORE فرمان (این دیپلوی نباید عوضش کند)"

# این دیپلوی routes/web.php را دست نمی‌زند، پس عدد باید **دقیقاً** ثابت بماند.
ROUTES_BEFORE=0
if [ -f "$APP/routes/web.php" ]; then
  ROUTES_BEFORE=$(grep -c 'Route::' "$APP/routes/web.php" 2>/dev/null || echo 0)
fi
echo "── روت‌های فعلیِ سرور: $ROUTES_BEFORE"

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
    # فایلِ تازه بکاپ ندارد، پس حلقهٔ بازگشت نمی‌بیندش. این‌جا ثبت می‌شود تا
    # بازگشت واقعاً کامل باشد.
    [ "$DRY" = "0" ] && { mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"; CREATED="$CREATED $rel"; }
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
    echo "──── سرور − پایه ($rel) — این تکه در مخزن نیست:"
    diff -u "$base_f" "$dest_n" | sed -n '1,140p'
    echo "──── پایانِ diff"
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
  exit 0
fi

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────────
# 🔴 دیپلوی فایل‌به‌فایل است و «یک فایل جا ماند» فرضی نیست. هر خرابیِ ممکن
#    این‌جا **خاموش** است: کلاسِ نبود ⇒ سفارشِ مشتری با «Class not found»
#    شکست می‌خورد و فقط در لاگِ کرون دیده می‌شود.
need_file() { [ -f "$1" ] || { echo "🔴 نیست: ${1#$APP/}"; union_ok=0; }; }

need_file "$APP/app/Services/Cloud/SaladOllamaImage.php"

# `--` اجباری است: هر الگویی که با `-` شروع شود را grep گزینه می‌خواند.
# و stderr خفه نمی‌شود — گاردی که بی‌صدا شکست بخورد از نبودنش بدتر است.
g() {
  err=$(grep -qF -- "$2" "$APP/$1" 2>&1) && return 0
  [ -n "$err" ] && echo "   (grep گفت: $err)"
  echo "🔴 $1: «$2» ننشسته"
  union_ok=0
}

# ── زنجیرهٔ «تازه‌ترین نسخه» ──
g app/Services/Cloud/SaladOllamaImage.php "public const FLOOR"
g app/Services/Cloud/SaladOllamaImage.php "public static function isOurs"
g app/Services/Cloud/SaladOperations.php "SaladOllamaImage::REPO"
g app/Services/Cloud/SaladOperations.php "public function apps()"
# 🔴 مهم‌ترین گاردِ این فهرست: تطبیق باید روی **مخزن** باشد. اگر merge این را
#    به مقایسهٔ رشته‌ایِ قبلی برگرداند، اولین ارتقای نسخه سفارش‌های روی ردیفِ
#    قدیمی را می‌کشد — کانتینر بالا می‌آید، صورت‌حساب می‌خورد، و هیچ درخواستی
#    جواب نمی‌گیرد. بی‌هیچ خطایی.
g app/Services/Cloud/SaladOperations.php "SaladOllamaImage::repoOf"

# ── ایمیلِ تحویلِ GPU ──
g app/Mail/ServiceReadyMail.php "public bool \$gatewayAccess"
g resources/views/emails/service-ready.blade.php "email_service_gate_h"
g app/Services/Cloud/CloudProvisioner.php "gatewayAccess: true"
g app/Services/Cloud/CloudProvisioner.php "passwordInPanel: false"

# 🔴 گاردِ **وارونه**: ایمیجِ سپتامبر ۲۰۲۴ نباید برگردد. اگر merge پایهٔ اشتباه
#    بگیرد و آن خط را احیا کند، هیچ خطایی نمی‌دهد و فقط qwen3 دوباره pull
#    نمی‌شود — همان تیکت، از نو.
if grep -qF -- "ollama-llama3.1-recipe" "$APP/app/Services/Cloud/SaladOperations.php" 2>/dev/null; then
  echo "🔴 ایمیجِ کهنهٔ Ollama (سپتامبر ۲۰۲۴) دوباره در کاتالوگ است."
  union_ok=0
else
  echo "✅ ایمیجِ کهنهٔ Ollama برنگشته"
fi

# 🔴 سه فایلِ زبان باید **دقیقاً** هم‌اندازه باشند. کلیدِ جامانده در یکی یعنی
#    مشتریِ آن زبان متنِ خام می‌بیند — قاعدهٔ ثبت‌شدهٔ §۲.
if [ -n "$PHPBIN" ]; then
  LANGN=$("$PHPBIN" -r 'foreach(["fa","en","tr"] as $l){echo count(require "'"$APP"'/lang/$l/ui.php")." ";}' 2>/dev/null)
  echo "── شمارشِ کلیدِ زبان: $LANGN"
  if [ "$(echo $LANGN | tr ' ' '
' | sort -u | grep -c .)" != "1" ]; then
    echo "🔴 سه فایلِ زبان هم‌اندازه نیستند."
    union_ok=0
  else
    for L3 in fa en tr; do
      g lang/$L3/ui.php "email_service_gate_h"
    done
  fi
fi

# ── ⚠️ کارِ دیگران که این merge نباید برش دارد ──
#
# این فایل‌ها پرترددند. اگر پایه‌یاب پایهٔ اشتباه بگیرد، merge می‌تواند بی‌صدا
# عقبشان ببرد — و هیچ‌کدام خطا نمی‌دهند.
g app/Services/Cloud/SaladOperations.php "gpu-comfyui"
g app/Services/Cloud/SaladOperations.php "gpu-jupyter"
g app/Services/Cloud/SaladOperations.php "'is_interruptible'  => true"
g app/Services/Cloud/CloudProvisioner.php "CloudFraudGuard"
g app/Services/Cloud/CloudProvisioner.php "quarantineProvider"
g resources/views/emails/service-ready.blade.php "email_service_pass_panel_h"
g app/Mail/ServiceReadyMail.php "SSH_DOC_SLUG"

# ═══ کرون نباید عوض شده باشد ═══
ROUTES_AFTER=$(grep -c 'Route::' "$APP/routes/web.php" 2>/dev/null || echo 0)
echo "═══ شمارشِ روت: پیش $ROUTES_BEFORE → پس $ROUTES_AFTER ═══"
if [ "$ROUTES_AFTER" -ne "$ROUTES_BEFORE" ]; then
  echo "🔴 تعدادِ روت عوض شد — این دیپلوی routes/web.php را دست نمی‌زند."
  union_ok=0
else
  echo "✅ هیچ روتی گم نشد"
fi

CRON_AFTER=$(grep -c 'Schedule::command' "$APP/routes/console.php" 2>/dev/null || echo 0)
echo
echo "═══ شمارشِ کرون: پیش $CRON_BEFORE → پس $CRON_AFTER ═══"
if [ "$CRON_AFTER" -ne "$CRON_BEFORE" ]; then
  echo "🔴 تعدادِ کرون عوض شد — این دیپلوی routes/console.php را دست نمی‌زند."
  union_ok=0
else
  echo "✅ کرون دست‌نخورده"
fi

if [ "$union_ok" -eq 0 ]; then
  echo "🔴 اتحادِ فایل‌ها کامل نیست — کلِ بکاپ برمی‌گردد تا سایت ۵۰۰ نشود."
  ( cd "$BK" && find . -type f | while read -r p; do
      rel="${p#./}"
      cp "$p" "$APP/$rel"
      echo "   بازگشت: $rel"
    done )
  for rel in $CREATED; do
    rm -f "$APP/$rel" && echo "   حذفِ فایلِ تازه: $rel"
  done
  echo "🔴 دیپلوی ناتمام. خروجیِ بالا را بفرست."
  exit 1
fi

# ── پاکسازیِ کش ────────────────────────────────────────────────────────────
# ⚠️ مهاجرت این‌جا اجرا **نمی‌شود**. `artisan migrate` همهٔ مهاجرت‌های معلقِ
#    دیگران را هم می‌دواند و این اسکریپت مالکِ آن تصمیم نیست.
if [ -n "$PHPBIN" ]; then
  cd "$APP"
  "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
  echo "WARN: php پیدا نشد — کش‌ها دستی پاک شدند"
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
echo "کارِ باقی‌مانده — دو گام، و بی گامِ دوم هیچ‌چیز عوض نمی‌شود:"
echo
echo "  ۱) ریستِ opcache از /system/opcache"
echo "     (validate_timestamps=0 — بی‌ریست، کدِ تازه اجرا نمی‌شود)"
echo
echo "  ۲) در /admin/cloud دکمهٔ همگام‌سازیِ کاتالوگ را بزنید."
echo "     تازه آن‌جاست که تگِ جدیدِ Ollama از رجیستری حل و در cloud_images"
echo "     نوشته می‌شود. تا آن لحظه کاتالوگ همان ایمیجِ کهنه را دارد."
echo
echo "     نسخهٔ حل‌شده بعدش در تنظیمات، کلیدِ salad_ollama_tag، دیده می‌شود."
echo
echo "═══ چه چیزی این دیپلوی درست نمی‌کند ═══"
echo "  · نمونه‌های موجود با ایمیجِ قبلی‌شان بالا مانده‌اند. این زیرساخت"
echo "    rebuild ندارد، پس ارتقای یک مشتری یعنی ساختِ نمونهٔ تازه."
echo "  · پیش از وعده به مشتری، یک نمونهٔ آزمایشیِ واقعی بسازید و qwen3:8b را"
echo "    رویش pull کنید. قاعدهٔ ثبت‌شدهٔ این پروژه: تا یک سفارشِ واقعی تا"
echo "    انتها نرود، «درست شد» اثبات‌نشده است."
