#!/usr/bin/env bash
#
# دیپلوی «منطقهٔ حساب OVH» — شهریور ۱۴۰۵.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-ovh-region.sh)
#
# چه چیزی دیپلوی می‌شود (۳ فایل، بدونِ مهاجرت، بدونِ تغییرِ روت):
#   · OvhClient  — نشانیِ API از تنظیمِ `ovh_region` ساخته می‌شود (eu/ca/us)
#   · SettingsController — اعتبارسنجی + ذخیره + نمایشِ منطقه
#   · settings/infra.blade — انتخابگرِ منطقه و نشانیِ createTokenِ همان منطقه
#
# 🔴 چرا لازم شد: حسابِ OVH ما روی نهادِ **US** است ولی کد نشانیِ اروپا را
#    سخت‌کد داشت. `ovh-eu`/`ovh-ca`/`ovh-us` سه شرکتِ حقوقیِ جدا با پایگاهِ
#    کاربریِ جدا هستند — کلیدِ US روی eu.api.ovh.com اصلاً **وجود ندارد** و
#    پاسخش همان ۴۰۳ِ بی‌توضیحِ امضاست. یعنی سه کلیدِ کاملاً درست هم بی‌اثر بود
#    و پیامِ خطا آدم را دنبالِ کلید می‌فرستاد، نه دنبالِ منطقه.
#
# منطق عیناً از scripts/deploy-two-factor-app.sh: merge سه‌طرفه با پایهٔ
# خودکار به‌ازای هر فایل (UP/MG/CF) + بکاپ کامل + یکسان‌سازیِ پایانِ خط پیش
# از هر مقایسه.  ⚠️ تلهٔ CRLF: بی‌normalize، پایه‌یاب روی سرور کور می‌شود.
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-ovh-region"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=80

# ═══ 🔴 اثباتِ مقصد — پیش از هر نوشتنی ═══
# اجرا با کاربرِ اشتباه یعنی $HOME عوض می‌شود، فایل‌ها جایی نوشته می‌شوند که
# سایت آن‌جا نیست، و گاردِ اتحاد **سبز** می‌شود چون همان فایلی را می‌سنجد که
# خودش تازه ساخته.
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

# 🔴 پین به کامیتِ مشخص — نوکِ متحرکِ develop را دیپلوی نکن.
MINE="${1:-043441c2}"

if ! git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1; then
  echo "── $MINE در develop نیست؛ شاخهٔ feature/ovh-region هم آورده می‌شود"
  git -C repo fetch --depth 400 origin feature/ovh-region >/dev/null 2>&1 || true
fi

git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 \
  || { echo "FATAL: $MINE در مخزن نیست (نه develop، نه feature/ovh-region)"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
echo "── بکاپ در: $BK"
echo

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
[ -n "$PHPBIN" ] || { echo "FATAL: php پیدا نشد"; exit 1; }

CONFLICTS=""; UPD=0; LINT_FAIL=""

backup_of() {
  [ -f "$APP/$1" ] || return 0
  mkdir -p "$BK/$(dirname "$1")"
  cp -p "$APP/$1" "$BK/$1"
}

# 🔴 `php -l` روی `.blade.php` بی‌اثر است: دایرکتیوهای Blade بیرونِ تگِ php
#    هستند و lint آنها را HTMLِ خام می‌بیند. یک `@if` بدونِ `@endif` — که صفحه
#    را ۵۰۰ می‌کند — «No syntax errors» می‌گیرد. پس Blade باید کامپایل شود.
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

# ═══ 🔴 نرمال‌سازیِ CRLF — پیش از هر مقایسه ═══
norm() { tr -d '\r' < "$1" > "$2"; }
same() { norm "$1" "$WORK/n1.tmp"; norm "$2" "$WORK/n2.tmp"; cmp -s "$WORK/n1.tmp" "$WORK/n2.tmp"; }
dist() { norm "$1" "$WORK/d1.tmp"; norm "$2" "$WORK/d2.tmp"; diff "$WORK/d1.tmp" "$WORK/d2.tmp" 2>/dev/null | grep -c '^[<>]'; }

# هر سه فایل از قبل روی سرور هستند ⇒ همه از مسیرِ merge می‌روند.
MERGE_FILES="
app/Services/Cloud/OvhClient.php
app/Http/Controllers/Admin/SettingsController.php
resources/views/admin/settings/infra.blade.php
"

echo "═══ ۱) فایل‌ها (merge سه‌طرفه) ═══"
for rel in $MERGE_FILES; do
  dest="$APP/$rel"
  mine_f="$WORK/mine.tmp"; base_f="$WORK/base.tmp"

  git -C repo show "$MINE:website/$rel" > "$mine_f" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; continue; }

  if [ ! -f "$dest" ]; then
    backup_of "$rel"; mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"
    lint_or_restore "$rel" && { echo "NEW   $rel"; UPD=$((UPD+1)); }
    continue
  fi

  backup_of "$rel"
  if same "$dest" "$mine_f"; then echo "OK    $rel"; continue; fi

  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$WORK/cand.tmp" 2>/dev/null || continue
    if same "$dest" "$WORK/cand.tmp"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$dest" "$WORK/cand.tmp")
    if [ "$d" -lt "$bestd" ]; then bestd=$d; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "CF    $rel   ← در تاریخچه نیست؛ نسخهٔ ناشناخته روی سرور — دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"
    continue
  fi

  if [ "$bestd" -eq 0 ]; then
    cp "$mine_f" "$dest"
    lint_or_restore "$rel" && { echo "UP    $rel   (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1)); }
    continue
  fi

  git -C repo show "$best:website/$rel" > "$base_f"
  m="$WORK/merged.tmp"; norm "$dest" "$m"; norm "$base_f" "$WORK/base_n.tmp"
  if git merge-file -L server -L base -L new "$m" "$WORK/base_n.tmp" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"
    lint_or_restore "$rel" && { echo "MG    $rel   (پایه $(git -C repo rev-parse --short "$best")، فاصلهٔ سرور $bestd خط — تغییرِ دیگران حفظ شد)"; UPD=$((UPD+1)); }
  else
    echo "CF    $rel   ← تداخل واقعی؛ دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
done

# ═══ ۲) کش‌ها ══════════════════════════════════════════════════════════
# مهاجرت لازم نیست: `ovh_region` روی جدولِ موجودِ `settings` می‌نشیند.
echo
(cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan view:clear)

# ═══ ۳) ضمانتِ اتحاد ═════════════════════════════════════════════════════
echo
echo "═══ ۳) ضمانتِ اتحاد ═══"
union_ok=1
need_grep() { grep -qF "$2" "$APP/$1" 2>/dev/null || { echo "🔴 «$2» در $1 نیست"; union_ok=0; }; }

# ⚠️ نشانه‌ها عمداً روی **هر سه** حلقه‌اند: یک فایلِ جامانده یعنی یا انتخابگر
#    هست و ذخیره نمی‌شود، یا ذخیره می‌شود و کلاینت هنوز به اروپا می‌زند —
#    هر دو حالت دقیقاً شبیهِ «کلیدِ غلط» دیده می‌شوند.
need_grep app/Services/Cloud/OvhClient.php                  "api.us.ovhcloud.com"
need_grep app/Services/Cloud/OvhClient.php                  "ENDPOINTS"
need_grep app/Http/Controllers/Admin/SettingsController.php "in:eu,ca,us"
need_grep app/Http/Controllers/Admin/SettingsController.php "ovh_region"
need_grep resources/views/admin/settings/infra.blade.php    "ovh_region"
need_grep resources/views/admin/settings/infra.blade.php    "ovhTokenUrl"

# ⚠️ نبودِ رگرسیون: کارِ نشستِ دیگر (Hetzner Robot) در همان فایل‌هاست و merge
#    نباید خورده باشدش. اگر این خط نیست، merge چیزی را پاک کرده.
need_grep app/Http/Controllers/Admin/SettingsController.php "hetzner_robot_user"

[ "$union_ok" -eq 0 ] && echo "🔴 اتحاد ناقص — گزارشِ بالا را بفرست."

# ═══ ۴) راستی‌آزماییِ زنده + بازگردانیِ خودکار ═══════════════════════════
echo
echo "═══ ۴) راستی‌آزماییِ زنده ═══"
BAD=0
check() {
  c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$1")
  case " $2 " in *" $c "*) echo "  ✅ $c  $1" ;; *) echo "  🔴 $c  $1  (انتظار: $2)"; BAD=1 ;; esac
}
check "https://servernet.cloud/"                        "200"
check "https://console.servernet.cloud/admin/login"     "200"
check "https://console.servernet.cloud/admin/settings"  "302 301"

if [ "$BAD" -eq 1 ]; then
  echo
  echo "🔴🔴 سایت سالم برنگشت — کلِ بکاپ برگردانده می‌شود …"
  (cd "$BK" && find . -type f | while read -r f; do cp -p "$f" "$APP/${f#./}"; done)
  (cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan view:clear)
  echo "   ↩️ برگشت انجام شد."
  exit 1
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · فایل‌های به‌روزشده: $UPD"
[ -n "$LINT_FAIL" ] && echo "🔴 خطای نحوی (برگردانده شد):$LINT_FAIL"
if [ -n "$CONFLICTS" ]; then
  echo "🔴 تداخل (دست‌نخورده):$CONFLICTS"
  echo "   نسخه‌ها در $WORK/conflicts/ (پسوند .server / .base / .new)"
else
  echo "✅ هیچ تداخلی نبود"
fi
echo
echo "حالا در پنل:"
echo "  ۱) console.servernet.cloud/admin/settings?tab=infra"
echo "  ۲) «زیرساختِ ۴ — OVHcloud» → منطقهٔ حساب = ovh-us → ذخیره"
echo "  ۳) /admin/cloud → تست اتصالِ OVH"
echo "     انتظار: «اتصال برقرار است — حسابِ ‹نیک‌هندل› · ۰ سرورِ مجازی»"
echo "     (صفر طبیعی است؛ حساب هنوز سرویسی ندارد.)"
