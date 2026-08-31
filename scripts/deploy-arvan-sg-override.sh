#!/usr/bin/env bash
#
# 🔴 گروهِ فایروالِ زیرساختِ ایرانی — راهِ فرارِ مدیر + قالب‌های Proxmox
#
# روی رفعِ قبلی (04c14f8) می‌نشیند و **جایگزینش نمی‌شود**. اگر آن دیپلوی از
# قبل اجرا شده باشد، این اسکریپت فقط دلتا را می‌گذارد؛ اگر نشده باشد، هر دو
# را با هم می‌آورد. در هر دو حالت درست کار می‌کند، چون محتوای نهاییِ فایل‌ها
# را با merge سه‌طرفه می‌نشاند نه یک patch را.
#
# اجرا از ترمینالِ cPanel:
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-arvan-sg-override.sh) [<SHA>]
#
# ═══ چه چیزهایی را می‌بندد ═══
#
#  ۱) **راهِ فرارِ مدیر.** کشفِ خودکارِ گروهِ فایروال روی مسیرهای حدسی تکیه
#     دارد. اگر حساب هیچ‌کدام را نشناسد، تحویل برای همیشه بسته می‌مانَد و
#     پیام می‌گوید «در پنل یک firewall بساز» — مدیر می‌سازد و باز هم کار
#     نمی‌کند، چون مسئله ساختنِ گروه نبود، خواندنِ فهرست بود. حالا شناسه را
#     می‌شود از «تنظیمات ← زیرساخت ← گروهِ فایروال» دستی گذاشت.
#  ۲) دو مسیرِ کاندیدِ دیگر برای کشف (هزینه‌اش یک ۴۰۴ است).
#  ۳) قالب‌های Proxmox دیگر «سرور» شمرده نمی‌شوند. در تطبیقِ موجودی «بی‌صاحب»
#     می‌افتادند و کنشِ حذف رویشان فعال بود — حذفِ یک قالب یعنی از فردا هیچ
#     سرورِ تازه‌ای ساخته نمی‌شود.
#
# ⚠️ **هیچ مهاجرتی ندارد.** پنج فایلِ موجود، بی‌هیچ تغییرِ ساختارِ دیتابیس.
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-arvan-sg"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=80

# ── اثباتِ محل، پیش از هر نوشتن ─────────────────────────────────────────
# گاردِ محتوا روی دایرکتوریِ اشتباه همیشه سبز می‌شود، چون همان فایلی را
# می‌سنجد که خودش تازه ساخته. پس اول باید ثابت شود این‌جا واقعاً اپ است.
[ -f "$APP/artisan" ] || { echo "FATAL: $APP/artisan نیست — مسیرِ اپ اشتباه است"; exit 1; }
[ -f "$APP/app/Services/Cloud/ArvanClient.php" ] || { echo "FATAL: ArvanClient روی سرور نیست — این‌جا اپ نیست"; exit 1; }

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }
if [ -d repo/.git ]; then
  git -C repo fetch --depth 600 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 600 --branch develop https://github.com/servernetir/server.git repo || exit 1
fi

MINE="${1:-b386f01}"

# اگر هنوز به develop مرج نشده، همان کامیت از شاخهٔ خودش کشیده می‌شود.
if ! git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1; then
  git -C repo fetch --depth 600 origin fix/arvan-sg-override-and-proxmox-templates >/dev/null 2>&1 || true
fi
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }

echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
echo "── بکاپ در: $BK"
echo

# ⚠️ هر پنج فایل از قبل روی سرور هستند. NEW به‌جای UP/MG یعنی مسیر اشتباه
# است، نه قابلیتِ تازه.
#
# CloudProvisioner عمداً در فهرست است با اینکه کارِ همکار است: اگر دیپلویِ
# آنها اجرا نشده باشد، بی‌این فایل قرنطینهٔ خطای فایروال نمی‌نشیند و همان
# «مشتریِ بعدی هم می‌خرد» برمی‌گردد.
APP_FILES="
app/Services/Cloud/ArvanClient.php
app/Services/Cloud/ProxmoxClient.php
app/Services/Cloud/CloudProvisioner.php
app/Http/Controllers/Admin/SettingsController.php
resources/views/admin/settings/infra.blade.php
"

to_lf() { tr -d '\r' < "$1" > "$2"; }
dist()  { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

CONFLICTS=""; UPD=0; MISPLACED=""

apply_one() {
  rel="$1"; dest="$APP/$rel"
  mine_f="$WORK/a.mine"; srv_lf="$WORK/a.srv"; base_f="$WORK/a.base"; cand="$WORK/a.cand"

  git -C repo show "$MINE:website/$rel" > "$mine_f" 2>/dev/null || { echo "SKIP  $rel"; return; }

  if [ ! -f "$dest" ]; then
    echo "🔴 $rel روی سرور نیست — مسیر اشتباه است، دست نمی‌زنم"
    MISPLACED="$MISPLACED $rel"; return
  fi

  mkdir -p "$BK/$(dirname "$rel")"; cp -p "$dest" "$BK/$rel"
  to_lf "$dest" "$srv_lf"

  if cmp -s "$srv_lf" "$mine_f"; then echo "OK    $rel"; return; fi

  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$cand" 2>/dev/null || continue
    if cmp -s "$srv_lf" "$cand"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$srv_lf" "$cand"); [ "$d" -lt "$bestd" ] && { bestd=$d; best="$sha"; }
  done

  if [ -z "$best" ]; then
    echo "CF    $rel  ← نسخهٔ ناشناخته روی سرور — دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$(basename "$rel")"
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"; return
  fi

  if [ "$bestd" -eq 0 ]; then
    cp "$mine_f" "$dest"; echo "UP    $rel  (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1)); return
  fi

  git -C repo show "$best:website/$rel" > "$base_f"
  m="$WORK/a.merged"; cp "$srv_lf" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"
    echo "MG    $rel  (پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط — تغییرِ دیگران حفظ شد)"
    UPD=$((UPD+1))
  else
    echo "CF    $rel  ← تداخلِ واقعی — دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$(basename "$rel")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
}

echo "═══ اپ ($APP) ═══"
for f in $APP_FILES; do apply_one "$f"; done

if [ -n "$MISPLACED" ]; then
  echo
  echo "🔴 فایل روی سرور پیدا نشد:$MISPLACED — هیچ ادعایی از این اجرا معتبر نیست."
  exit 1
fi

# ── ضمانتِ اتحاد ──────────────────────────────────────────────────────
#
# 🔴 تک‌تک، چون درسِ این پروژه: وقتی یک فایل «CF» می‌خورَد بقیه می‌نشینند و
# اسکریپت با موفقیت تمام می‌شود — و قابلیت **بی‌صدا** منتشر نمی‌شود.
echo
ok=1

AC="$APP/app/Services/Cloud/ArvanClient.php"
grep -qF "function securityGroupIds" "$AC" || { echo "🔴 کشفِ گروهِ امنیتی ننشست"; ok=0; }
grep -qF "'security_groups' =>" "$AC"      || { echo "🔴 پیلودِ ساخت گروه را حمل نمی‌کند — همان باگ برمی‌گردد"; ok=0; }
grep -qF "arvan_security_group" "$AC"      || { echo "🔴 راهِ فرارِ دستیِ مدیر ننشست"; ok=0; }
grep -qF "'/securitygroups'" "$AC"         || { echo "🔴 مسیرهای کاندیدِ تازه ننشستند"; ok=0; }
grep -qF "md5(\$wanted)" "$AC"             || { echo "🔴 کلیدِ کش انتخابِ مدیر را ندارد — تغییرِ تنظیمات تا یک ساعت بی‌اثر می‌مانَد"; ok=0; }
# ارکانِ قبلیِ همین فایل نباید در merge پریده باشند
grep -qF "function createServer" "$AC"     || { echo "🔴 createServer از فایل پرید"; ok=0; }
grep -qF "function fetchCatalog" "$AC"     || { echo "🔴 کاتالوگ از فایل پرید"; ok=0; }
grep -qF "function publicNetworkId" "$AC"  || { echo "🔴 کشفِ شبکه از فایل پرید"; ok=0; }

PX="$APP/app/Services/Cloud/ProxmoxClient.php"
grep -qF "\$vm['template']" "$PX"      || { echo "🔴 فیلترِ قالب ننشست — قالب‌ها باز هم «بی‌صاحب» می‌شوند"; ok=0; }
grep -qF "function listServers" "$PX"  || { echo "🔴 listServers از فایل پرید"; ok=0; }
grep -qF "function createServer" "$PX" || { echo "🔴 createServer پراکسموکس پرید"; ok=0; }

CP="$APP/app/Services/Cloud/CloudProvisioner.php"
grep -qF "'firewall'," "$CP"                 || { echo "🔴 قرنطینهٔ خطای فایروال ننشست — فروش پس از شکست باز می‌مانَد"; ok=0; }
grep -qF "function quarantineProvider" "$CP" || { echo "🔴 قرنطینه از فایل پرید"; ok=0; }
grep -qF "QUARANTINE_PREFIX" "$CP"           || { echo "🔴 پیشوندِ قرنطینه پرید"; ok=0; }

# دو نقطه لازم است: اعتبارسنجیِ ورودی، و نوشتنِ مقدار. یکی بدونِ دیگری یعنی
# فیلد روی صفحه هست ولی ذخیره نمی‌شود — بی‌صداترین شکلِ خرابی.
SC="$APP/app/Http/Controllers/Admin/SettingsController.php"
SC_HITS=$(grep -c "arvan_security_group" "$SC" || echo 0)
[ "$SC_HITS" -ge 2 ] || { echo "🔴 تنظیمِ گروهِ فایروال ناقص است (اعتبارسنجی یا ذخیره جا مانده)"; ok=0; }
grep -qF "arvan_api_token" "$SC" || { echo "🔴 توکنِ زیرساختِ ۳ از تنظیمات پرید"; ok=0; }

IB="$APP/resources/views/admin/settings/infra.blade.php"
grep -qF "arvan_security_group" "$IB" || { echo "🔴 فیلدِ گروهِ فایروال در صفحهٔ تنظیمات نیست — تنظیمی که رابط ندارد وجود ندارد"; ok=0; }
grep -qF "arvan_api_token" "$IB"      || { echo "🔴 فیلدِ توکن از صفحهٔ تنظیمات پرید"; ok=0; }

if [ "$ok" -eq 0 ]; then
  echo
  echo "🔴 اتحاد ناقص — فایل‌ها از بکاپ برمی‌گردند."
  for f in $APP_FILES; do [ -f "$BK/$f" ] && cp "$BK/$f" "$APP/$f"; done
  echo "   برگشت انجام شد. خروجی را بفرست."
  exit 1
fi
echo "✅ همهٔ اجزا با هم‌اند"

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)

if [ -n "$PHPBIN" ]; then
  syn=1
  for f in $APP_FILES; do
    case "$f" in *.php) "$PHPBIN" -l "$APP/$f" >/dev/null || { echo "🔴 خطای syntax در $f"; syn=0; } ;; esac
  done

  if [ "$syn" -eq 0 ]; then
    for f in $APP_FILES; do [ -f "$BK/$f" ] && cp "$BK/$f" "$APP/$f"; done
    echo "🔴 برگشت از بکاپ به‌خاطرِ خطای syntax"; exit 1
  fi
  echo "بدونِ خطای syntax"

  cd "$APP"
  "$PHPBIN" artisan config:clear >/dev/null
  "$PHPBIN" artisan view:clear   >/dev/null
  "$PHPBIN" artisan route:clear  >/dev/null
  echo "کش‌ها پاک شد"
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
  echo "WARN: php پیدا نشد — کش‌ها دستی پاک شدند"
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · به‌روزشده: $UPD"
[ -n "$CONFLICTS" ] && echo "🔴 تداخل‌دار:$CONFLICTS" || echo "✅ تداخلی نبود"
echo
echo "🔎 قدمِ بعد:"
echo "   ۱) اگر پلن‌های زیرساختِ ایرانی قرنطینه شده‌اند، بازشان کنید:"
echo "        cd ~/servernet_app && php artisan cloud:reopen"
echo "   ۲) سرویسِ #۹۳ را باز کنید و «تلاش دوباره» بزنید."
echo
echo "   ⚠️ اگر باز هم «firewall» گفت، یعنی فهرستِ گروه‌ها از این حساب خوانده"
echo "      نمی‌شود. شناسهٔ Security Group را از پنلِ خودِ زیرساخت بردارید و در"
echo "      «تنظیمات ← زیرساخت ← زیرساختِ ۳ — گروهِ فایروال» بگذارید، بعد دوباره"
echo "      «تلاش دوباره». همین راهِ فرار، کارِ اصلیِ این دیپلوی است."
echo
