#!/usr/bin/env bash
#
# دیپلوی «فروشگاه قطعات سرور + ده مورد بهبودی» روی سرور cPanel.
#
# اجرا از ترمینالِ cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-parts-shop.sh) [<SHA>]
#   ← SHA پیش‌فرض aefcc0a = «فروشگاه قطعات + ده مورد بهبودی» و نه بیشتر.
#
# ⚠️ عمداً نوکِ develop نیست. روی develop کارِ دیپلوی‌نشدهٔ همکاران هست
#    (اکسیت/WireGuard، سئوی ساعتی) و routes/web.phpِ نوک به کنترلرهایی اشاره
#    می‌کند که روی سرور نیستند ⇒ ۵۰۰ سراسری. هرکس دیپلویِ خودش را دارد.
#
# ═══ منطق و ساختار از scripts/deploy-seo-hourly.sh (جعفر) گرفته شده ═══
#
# همان درسِ ۲۸ مرداد: merge سه‌طرفه با پایهٔ **ثابتِ دست‌نویس** ⇒ «نداشتنِ» کدِ
# جدید در کپیِ سرور به‌عنوان «حذفِ عمدی» تفسیر می‌شود و روت می‌افتد ⇒ ۵۰۰.
# پس پایه برای هر فایل **خودکار** پیدا می‌شود:
#
#   ۱) فایلِ سرور دقیقاً برابرِ یکی از نسخه‌های تاریخیِ همان فایل ⇒ کسی دست
#      نزده ⇒ نسخهٔ تازه مستقیم می‌نشیند (UP).
#   ۲) برابرِ هیچ‌کدام نیست ⇒ کسِ دیگری تغییرش داده ⇒ merge سه‌طرفه با
#      **نزدیک‌ترین** نسخهٔ تاریخی به‌عنوان پایه (MG) تا کارِ او حفظ شود.
#   ۳) تداخلِ واقعی ⇒ فایل دست‌نخورده می‌مانَد و فقط گزارش می‌شود (CF).
#      هرگز مارکرِ تداخل روی سایتِ زنده نمی‌نشیند.
#   ۴) پیش از هر تغییری بکاپِ کامل.
#
# ⚠️ تفاوت با اسکریپتِ سئو: این دیپلوی **سه فایلِ استاتیک** هم دارد که به
#    public_html می‌روند، نه به servernet_app. بخشِ جدا در انتها.
#
set -u

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-parts-shop"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=60

# ═══ 🔴 اثباتِ مقصد — پیش از هر نوشتنی ═══
#
# درسِ ثبت‌شدهٔ این پروژه، که خودِ همین اسکریپت‌ها قربانی‌اش شدند: اجرا با
# کاربرِ اشتباه (مثلاً root به‌جای servernetcloud) یعنی $HOME عوض می‌شود،
# فایل‌ها در مسیری ساخته می‌شوند که سایت آن‌جا نیست، و گاردِ اتحاد **سبز**
# می‌شود چون همان فایل‌هایی را می‌سنجد که خودش تازه ساخته.
#
# پس مقصد باید *قبل* از نوشتن ثابت شود: نصبِ واقعی artisan و vendor دارد.
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "🔴 «$APP» نصبِ لاراول نیست (artisan یا vendor نیست)."
  echo "   احتمالاً با کاربرِ اشتباه واردید. کاربرِ درست: servernetcloud"
  echo "   چاره:  su - servernetcloud   و بعد همین دستور را دوباره بزنید."
  exit 1
fi

# فضای آزاد: دیپلوی روی دیسکِ پر، نیمه‌کاره می‌مانَد
FREE_MB=$(df -Pm "$HOME" | awk 'NR==2{print $4}')
if [ "${FREE_MB:-0}" -lt 500 ]; then
  echo "🔴 فضای آزاد کم است (${FREE_MB}MB). اول پاک‌سازی کنید:"
  echo "   rm -rf ~/deploy-*/repo"
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

MINE="${1:-aefcc0a}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# ── فایل‌های اپ ─────────────────────────────────────────────────────────
#
# 🔴 ترتیب معنادار است:
#   ۱) اول کدِ **مستقل**: مدل، سرویس، کنترلر، config، زبان، helpers.
#   ۲) بعد ویوها — و به‌ویژه layouts/site.blade.php که `social_profiles()` را
#      صدا می‌زند؛ اگر پیش از helpers.php بنشیند، **هر صفحهٔ سایت** با
#      «Call to undefined function» می‌خوابد.
#   ۳) آخر از همه routes/web.php که به کنترلرهای تازه اشاره می‌کند.
APP_FILES="
app/helpers.php
app/Models/ServerPart.php
app/Models/ActivityLog.php
app/Services/Shop/PartsCatalog.php
app/Services/Ticket/ReplyPolisher.php
app/Services/AiContent.php
app/Services/SiteMenu.php
app/Services/Finance/BusinessLedger.php
app/Services/Notify/Notifier.php
app/Services/Notify/AdminNotifier.php
app/Services/Bale/BaleNotifier.php
app/Services/Bale/Admin/AdminBaleRouter.php
config/hp_generations.php
config/parts_content.php
config/servernet.php
lang/fa/ui.php
lang/en/ui.php
lang/tr/ui.php
app/Http/Controllers/PartsShopController.php
app/Http/Controllers/Admin/ServerPartController.php
app/Http/Controllers/Admin/DashboardController.php
app/Http/Controllers/Admin/TicketController.php
app/Http/Controllers/Account/AccountController.php
app/Http/Controllers/Account/TicketController.php
app/Http/Controllers/SiteController.php
app/Providers/AppServiceProvider.php
database/migrations/2026_08_21_100000_create_server_parts_table.php
database/seeders/ServerPartSeeder.php
resources/views/partials/part-card.blade.php
resources/views/partials/parts-sidebar.blade.php
resources/views/partials/parts-compare-bar.blade.php
resources/views/pages/parts-index.blade.php
resources/views/pages/parts-category.blade.php
resources/views/pages/part-detail.blade.php
resources/views/pages/parts-generation.blade.php
resources/views/pages/parts-compare.blade.php
resources/views/admin/parts.blade.php
resources/views/admin/part-edit.blade.php
resources/views/admin/dashboard.blade.php
resources/views/admin/ticket.blade.php
resources/views/admin/finance.blade.php
resources/views/admin/layout.blade.php
resources/views/admin/cloud-inventory.blade.php
resources/views/layouts/site.blade.php
routes/web.php
"

# ── فایل‌های استاتیک (به public_html می‌روند) ───────────────────────────
PUB_FILES="
assets/css/site.css
assets/css/admin.css
assets/css/panel.css
"

CONFLICTS=""
UPD=0

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

# $1 = مسیرِ نسبی در مخزن (زیرِ website/)   $2 = ریشهٔ مقصد   $3 = مسیرِ نسبی مقصد
apply_one() {
  rel="$1"; root="$2"; drel="$3"
  dest="$root/$drel"
  mine_f="$WORK/mine.tmp"; base_f="$WORK/base.tmp"

  git -C repo show "$MINE:website/$rel" > "$mine_f" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; return; }

  if [ -f "$dest" ]; then
    mkdir -p "$BK/$drel"; rmdir "$BK/$drel" 2>/dev/null
    mkdir -p "$BK/$(dirname "$drel")"
    cp -p "$dest" "$BK/$drel"
  fi

  if [ ! -f "$dest" ]; then
    mkdir -p "$(dirname "$dest")"
    cp "$mine_f" "$dest"; echo "NEW   $drel"; UPD=$((UPD+1)); return
  fi

  if cmp -s "$dest" "$mine_f"; then echo "OK    $drel"; return; fi

  # پایهٔ خودکار: نزدیک‌ترین نسخهٔ تاریخیِ همین فایل به آنچه روی سرور است
  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$WORK/cand.tmp" 2>/dev/null || continue
    if cmp -s "$dest" "$WORK/cand.tmp"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$dest" "$WORK/cand.tmp")
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
    cp "$mine_f" "$dest"; echo "UP    $drel   (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1)); return
  fi

  git -C repo show "$best:website/$rel" > "$base_f"
  m="$WORK/merged.tmp"; cp "$dest" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"
    echo "MG    $drel   (پایه $(git -C repo rev-parse --short "$best")، فاصلهٔ سرور $bestd خط — تغییرِ دیگران حفظ شد)"
    UPD=$((UPD+1))
  else
    echo "CF    $drel   ← تداخل واقعی؛ دست نخورد"
    CONFLICTS="$CONFLICTS $drel"
    keep="$WORK/conflicts/$drel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
}

echo "── بکاپ در: $BK"
echo
echo "═══ اپ ($APP) ═══"
for f in $APP_FILES; do apply_one "$f" "$APP" "$f"; done

echo
echo "═══ استاتیک ($PUB) ═══"
for f in $PUB_FILES; do apply_one "public/$f" "$PUB" "$f"; done

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────
# اگر روتِ فروشگاه نشست ولی کنترلر/ویو/زبانش نه (یا برعکس)، باید همین‌جا
# بفهمیم، نه از ۵۰۰ِ مشتری.
echo
union_ok=1
need_file() { [ -f "$1" ] || { echo "🔴 نیست: ${1#$APP/}"; union_ok=0; }; }

need_file "$APP/app/Http/Controllers/PartsShopController.php"
need_file "$APP/app/Models/ServerPart.php"
need_file "$APP/app/Services/Shop/PartsCatalog.php"
need_file "$APP/config/parts_content.php"
need_file "$APP/config/hp_generations.php"
for v in parts-index parts-category part-detail parts-generation parts-compare; do
  need_file "$APP/resources/views/pages/$v.blade.php"
done
for v in part-card parts-sidebar parts-compare-bar; do
  need_file "$APP/resources/views/partials/$v.blade.php"
done

# 🔴 layouts/site.blade.php این را صدا می‌زند؛ نبودنش = هر صفحهٔ سایت ۵۰۰
grep -q "function social_profiles" "$APP/app/helpers.php" 2>/dev/null \
  || { echo "🔴 helpers.php تابع social_profiles را ندارد — site.blade.php می‌شکند"; union_ok=0; }
grep -q "function part_price" "$APP/app/helpers.php" 2>/dev/null \
  || { echo "🔴 helpers.php تابع part_price را ندارد"; union_ok=0; }

for l in fa en tr; do
  grep -q "'parts_title'" "$APP/lang/$l/ui.php" 2>/dev/null \
    || { echo "🔴 lang/$l/ui.php کلیدهای parts_* را ندارد"; union_ok=0; }
done

for r in "name('parts.index')" "name('parts.category')" "name('parts.show')" "name('parts.compare')" "name('servers.generation')"; do
  grep -qF "$r" "$APP/routes/web.php" || { echo "🔴 routes/web.php: $r گم شده"; union_ok=0; }
done

# روت‌هایی که هدر/فوتر می‌زنند باید زنده بمانند (درسِ ۲۸ مرداد)
for r in "name('aup')" "name('cloud.index')" "name('domain.search')" "name('servers.index')" "name('contact')"; do
  grep -qF "$r" "$APP/routes/web.php" || { echo "🔴 routes/web.php: روتِ موجودِ $r گم شد"; union_ok=0; }
done

if [ "$union_ok" -eq 0 ]; then
  echo "🔴 اتحادِ فایل‌ها کامل نیست — routes/web.php را از بکاپ برمی‌گردانم تا سایت ۵۰۰ نشود."
  [ -f "$BK/routes/web.php" ] && cp "$BK/routes/web.php" "$APP/routes/web.php" && echo "   routes/web.php از بکاپ برگشت"
fi

# ── کش‌ها ───────────────────────────────────────────────────────────────
PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
if [ -n "$PHPBIN" ]; then
  cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
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
echo "دو کارِ باقی‌مانده — با ترتیب:"
echo "  ۱) ریستِ opcache  (کدِ PHP تا ریست نشود اجرا نمی‌شود: validate_timestamps=0)"
echo "  ۲) مهاجرت + سیدرِ جدولِ server_parts  (وگرنه /parts صفحهٔ «به‌زودی» نشان می‌دهد، نه ۵۰۰)"
echo
echo "راستی‌آزمایی:"
echo "  curl -sI https://servernet.cloud/            | head -1   ← 200"
echo "  curl -sI https://servernet.cloud/parts       | head -1   ← 200"
echo "  curl -sI https://servernet.cloud/parts/cpu   | head -1   ← 200"
echo "  curl -sI https://servernet.cloud/servers/hp/gen9 | head -1 ← 200"
