#!/usr/bin/env bash
#
# خطِ GPU: هیچ‌جا SSH/root/IP + صفحهٔ خرید وعدهٔ تغییرِ سیستم‌عامل نمی‌دهد — مهر ۱۴۰۵.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-gpu-no-ssh.sh)
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-gpu-no-ssh.sh) [<SHA>]
#
# 🔴 چرا لازم شد: سه مشتری در سه روز تیکت زدند «رمز SSH/root کجاست؟». خطِ GPU
#    نه SSH دارد نه root نه رمز؛ ولی کارتِ داشبورد «ssh root@<IP>» چاپ می‌کرد،
#    سربرگِ صفحهٔ مدیریت و پیامکِ «آماده شد» IPِ زیرساخت را می‌دادند، ردیفِ
#    سرویس username=root می‌گرفت، و صفحهٔ خرید وعدهٔ «تغییرِ سیستم‌عامل از پنل»
#    می‌داد (تیکتِ TK-260924-8595 عیناً همان جمله را نقل کرد).
#
# ⚠️ Workerِ دروازهٔ GPU در این دیپلوی نیست. آن از داشبورد Cloudflare مدیریت
#    می‌شود و اصلاحِ کوکی‌اش (b140ba1a) باید آن‌جا دستی اعمال شود.
#
# ⚠️ نه مهاجرت دارد نه فایلِ استاتیک.
#
# منطق عیناً از scripts/deploy-gpu-ollama.sh.
set -u

DRY="${DRY:-0}"

APP="$HOME/servernet_app"
WORK="$HOME/deploy-gpu-no-ssh"
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
MINE="${1:-885c1433}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# 🔴 ترتیب معنادار است: اول مدل (isGpuApp/gatewayUrl)، چون قالب‌ها و
#    CloudProvisioner صدایش می‌زنند — قالبی که متدِ نبود را صدا بزند ۵۰۰ است.
#    فایل‌های زبان آخر: کلیدِ جامانده فقط متنِ خام چاپ می‌کند، نه ۵۰۰.
APP_FILES="
app/Models/CloudInstance.php
app/Services/Cloud/CloudProvisioner.php
resources/views/account/partials/card-server.blade.php
resources/views/account/cloud-server.blade.php
resources/views/account/cloud-store.blade.php
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

need_file "$APP/app/Models/CloudInstance.php"

# `--` اجباری است: هر الگویی که با `-` شروع شود را grep گزینه می‌خواند.
g() {
  err=$(grep -qF -- "$2" "$APP/$1" 2>&1) && return 0
  [ -n "$err" ] && echo "   (grep گفت: $err)"
  echo "🔴 $1: «$2» ننشسته"
  union_ok=0
}

# ── یک تعریف ──
g app/Models/CloudInstance.php "public function isGpuApp()"
g app/Models/CloudInstance.php "public function gatewayUrl()"

# ── سطح‌ها ──
# 🔴 مهم‌ترین گارد: کارتِ داشبورد. اگر merge این شرط را بخورد، «ssh root@…» بی‌صدا
#    برمی‌گردد و موجِ تیکتِ «رمزِ SSH کجاست» هم.
g resources/views/account/partials/card-server.blade.php '($ready && ! $gpuApp) ? $ci->sshCommand()'
g resources/views/account/partials/card-server.blade.php "svc_gpu_card_note"
g resources/views/account/cloud-server.blade.php '$inst?->isGpuApp()'
g app/Services/Cloud/CloudProvisioner.php "isGpuApp() ? null : 'root'"
g app/Services/Cloud/CloudProvisioner.php "gatewayUrl()"
g resources/views/account/cloud-store.blade.php "ui.cvb_gpu_access_note"

# 🔴 سه فایلِ زبان باید دقیقاً هم‌اندازه باشند.
if [ -n "$PHPBIN" ]; then
  LANGN=$("$PHPBIN" -r 'foreach(["fa","en","tr"] as $l){echo count(require "'"$APP"'/lang/$l/ui.php")." ";}' 2>/dev/null)
  echo "── شمارشِ کلیدِ زبان: $LANGN"
  if [ "$(echo $LANGN | tr ' ' '\n' | sort -u | grep -c .)" != "1" ]; then
    echo "🔴 سه فایلِ زبان هم‌اندازه نیستند."
    union_ok=0
  fi
fi
g lang/fa/ui.php "svc_gpu_card_note"
g lang/en/ui.php "svc_gpu_card_note"
g lang/tr/ui.php "svc_gpu_card_note"
g lang/fa/ui.php "cvb_gpu_access_note"
g lang/en/ui.php "cvb_gpu_access_note"
g lang/tr/ui.php "cvb_gpu_access_note"

# ── ⚠️ کارِ دیگران که این merge نباید برش دارد ──
g app/Services/Cloud/CloudProvisioner.php "CloudFraudGuard"
g app/Services/Cloud/CloudProvisioner.php "quarantineProvider"
g app/Services/Cloud/CloudProvisioner.php "public function deliverOwedNotices"
g app/Services/Cloud/CloudProvisioner.php "gatewayAccess: true"
g app/Models/CloudInstance.php "public function accessToken()"
g app/Models/CloudInstance.php "public function isDelivered()"
g resources/views/account/partials/card-server.blade.php "PanelSections::hoursLeft"
g resources/views/account/cloud-server.blade.php "str_starts_with((string) (\$inst->image_key"
g resources/views/account/cloud-store.blade.php "ui.cvb_os_note"

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
echo "کارِ باقی‌مانده: ریستِ opcache از /system/opcache"
echo "   (validate_timestamps=0 — بی‌ریست، کدِ تازه اجرا نمی‌شود)"
echo
echo "═══ چه چیزی این دیپلوی درست نمی‌کند ═══"
echo "  · ردیف‌های سرویسِ GPUِ **قبلی** هنوز username=root دارند؛ فقط تحویل‌های"
echo "    تازه درست ذخیره می‌شوند."
echo "  · Workerِ دروازه (کوکی‌های پنل) — از داشبورد Cloudflare، جدا."
