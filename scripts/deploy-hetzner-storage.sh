#!/usr/bin/env bash
#
# دیپلوی فضای بکاپ/دانلود روی Storage Boxِ هتزنر — شهریور ۱۴۰۵.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-hetzner-storage.sh)
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-hetzner-storage.sh) [<SHA>]
#
# چه چیزی دیپلوی می‌شود:
#   · HetznerStorageClient + HetznerStorageProvisioner — تحویلِ خودکارِ Storage Box
#   · فرمانِ hetzner:storage-catalog — نوع‌ها با بهای تمام‌شده و قیمتِ فروشِ ما
#   · نوعِ تازهٔ سرور در Server::TYPES و در فرمِ /admin/servers
#   · شاخهٔ درایور در ProvisioningService و آزمونِ اتصال در ServerController
#
# 🔴 چرا لازم شد: هاست بکاپ روی چهار پلن چیپِ «S3-Compatible» می‌فروخت و هیچ
#    پیاده‌سازیِ S3 در مخزن نبود؛ تحویلِ واقعی یک حسابِ cPanel بود. مشتری
#    BK-500 خرید و شش دقیقه بعد اطلاعاتِ اتصالِ S3 خواست. وجه برگشت و هر ده
#    پکیجِ بکاپ و دانلود بسته شد. این دیپلوی مسیرِ تحویلِ واقعی را می‌سازد.
#
# ⚠️ این دیپلوی **چیزی را روشن نمی‌کند**. بعد از آن هنوز باید: سرورِ نوعِ
#    hetzner_storage ساخته شود، کاتالوگ گرفته شود، نگاشتِ پلن پر شود، و
#    پکیج‌ها دوباره فعال شوند. تا آن لحظه رفتارِ سایت دقیقاً مثلِ امروز است.
#
# ⚠️ نه مهاجرت دارد نه فایلِ استاتیک. اگر روزی اضافه شد، این کامنت را عوض کن —
#    نه اینکه بی‌صدا اجرا نشود.
#
# منطق عیناً از scripts/deploy-content-1405.sh: merge سه‌طرفه با پایهٔ خودکار
# به‌ازای هر فایل (UP/MG/CF) + بکاپ کامل + یکسان‌سازیِ پایانِ خط پیش از مقایسه.
set -u

DRY="${DRY:-0}"

APP="$HOME/servernet_app"
WORK="$HOME/deploy-hetzner-storage"
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
MINE="${1:-2f4fe2ca}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# 🔴 ترتیب معنادار است: اول کلاس‌های مستقلِ تازه، بعد config، بعد مدل، بعد
#    رجیستریِ درایور، و آخر کنترلر و ویو. اگر اجرا وسطِ کار بمیرد، حالتِ
#    میانی باید «قابلیت هنوز نیست» باشد، نه «قابلیت هست ولی کلاسش نیست».
APP_FILES="
app/Services/Provisioning/HetznerStorageClient.php
app/Services/Provisioning/HetznerStorageProvisioner.php
app/Console/Commands/HetznerStorageCatalog.php
config/provisioning.php
app/Models/Server.php
app/Services/Provisioning/ProvisioningService.php
app/Http/Controllers/Admin/ServerController.php
resources/views/admin/partials/server-form.blade.php
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

need_file "$APP/app/Services/Provisioning/HetznerStorageClient.php"
need_file "$APP/app/Services/Provisioning/HetznerStorageProvisioner.php"
need_file "$APP/app/Console/Commands/HetznerStorageCatalog.php"
# ⚠️ گاردِ رشته‌ای فقط می‌گوید نامِ کلاس در فایل هست، نه اینکه **کلاسش** روی
#    سرور وجود دارد. این فایل را نسخهٔ تازهٔ ProvisioningService صدا می‌زند.
need_file "$APP/app/Services/Provisioning/BuilderSitePublisher.php"

# `--` اجباری است: هر الگویی که با `-` شروع شود را grep گزینه می‌خواند.
# و stderr خفه نمی‌شود — گاردی که بی‌صدا شکست بخورد از نبودنش بدتر است.
g() {
  err=$(grep -qF -- "$2" "$APP/$1" 2>&1) && return 0
  [ -n "$err" ] && echo "   (grep گفت: $err)"
  echo "🔴 $1: «$2» ننشسته"
  union_ok=0
}

# ── زنجیرهٔ تحویل — هر حلقه بیفتد، سفارش بی‌صدا به جای اشتباه می‌رود ──
#
# 🔴 مهم‌ترینِ این فهرست همین سطرِ اول است: driverFor یک
#    `default => new WhmProvisioner()` دارد. اگر merge شاخهٔ تازه را بخورد،
#    سفارشِ Storage Box **بی‌هیچ خطایی** به WHM فرستاده می‌شود.
g app/Services/Provisioning/ProvisioningService.php "'hetzner_storage' => new HetznerStorageProvisioner()"
g app/Models/Server.php "'hetzner_storage'"
g config/provisioning.php "hetzner_storage"
g resources/views/admin/partials/server-form.blade.php "hetzner_storage"
g app/Http/Controllers/Admin/ServerController.php "HetznerStorageClient"

# میزبانِ درست — api.hetzner.com نه api.hetzner.cloud. اشتباهش ۴۰۴ِ JSON
# می‌دهد که شبیهِ «توکنِ غلط» است نه «آدرسِ غلط».
g app/Services/Provisioning/HetznerStorageClient.php "https://api.hetzner.com/v1"
# پشتیبانِ توکن از تنظیماتِ سرورِ ابری
g app/Services/Provisioning/HetznerStorageClient.php "hetzner_api_token"
# محافظِ «دو بار نخر»
g app/Services/Provisioning/HetznerStorageProvisioner.php "sn-svc-"

# ── ⚠️ کارِ دیگران که این merge نباید برش دارد ──
#
# این فایل‌ها روی develop بینِ ۱ تا ۷ کامیت جلو رفته‌اند. اگر پایه‌یاب پایهٔ
# اشتباه بگیرد، merge می‌تواند بی‌صدا عقبشان ببرد — و هیچ‌کدام خطا نمی‌دهند.
g app/Services/Provisioning/ProvisioningService.php "BuilderSitePublisher"
g app/Http/Controllers/Admin/ServerController.php "monthly_cost"
g resources/views/admin/partials/server-form.blade.php "costReady"

# ═══ کرون نباید عوض شده باشد ═══
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

# ── پاکسازیِ کش (مهاجرتی در کار نیست) ──────────────────────────────────────
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
echo "═══ گامِ بعدی: کاتالوگ ═══"
echo "  cd $APP && $PHPBIN artisan hetzner:storage-catalog"
echo
echo "  ⚠️ اگر گفت «سرورِ نوعِ hetzner_storage پیدا نشد» یعنی هنوز ردیفِ سرور"
echo "     ساخته نشده. در /admin/servers یک سرور با نوعِ «فضای بکاپ — Hetzner"
echo "     Storage Box» بسازید و **فیلدِ توکن را خالی بگذارید**: توکن از همان"
echo "     hetzner_api_token در تنظیمات خوانده می‌شود (یک توکنِ پروژه‌ای برای"
echo "     هر دو API؛ فقط میزبانشان فرق دارد)."
echo
echo "  ⚠️ توکن باید Read & Write باشد. توکنِ فقط‌خواندنی کاتالوگ را می‌دهد ولی"
echo "     ساختِ باکس را رد می‌کند — یعنی فروش انجام می‌شود و تحویل نه."
echo
echo "  خروجیِ کاتالوگ (نامِ نوع‌ها) را بفرستید تا نگاشتِ plans پر شود."
echo "  تا پر نشدنِ آن نگاشت، سفارش به صفِ **دستی** می‌رود — نه به نوعِ حدسی."
echo
echo "═══ چیزی روشن نشد ═══"
echo "  پکیج‌های BK/DL هنوز غیرفعال‌اند و رفتارِ سایت مثلِ امروز است."
