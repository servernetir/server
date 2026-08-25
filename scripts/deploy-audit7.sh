#!/usr/bin/env bash
#
# دیپلوی رفع‌های ممیزی ۶+۷ (شهریور ۱۴۰۵) — merge سه‌طرفه با پایهٔ خودکار به‌ازای هر فایل.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-audit7.sh) [<SHA>]
#
# پیش از اجرا (اختیاری ولی توصیه‌شده): گزارشِ فقط-خواندنیِ وضعیت سرور:
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/check-live-state.sh)
#
# چه چیزی دیپلوی می‌شود (یک‌جا، چون کار ممیزی ۶ هرگز جدا دیپلوی نشد):
#   · ممیزی ۶: /order v2 با تحویل امضاشده + اسکیما، کش denylist، /official-channels،
#     ۴۱۰ برای /share، قیف /api/funnel، دروازهٔ انتشار site:gate، متن‌های حقوقی
#   · ممیزی ۷: /healthz، /go/pay، ۳۰۱ کدهای legacy ابر + پاک‌سازی sitemap،
#     همهٔ صفحات سفارش در sitemap، دروازهٔ محتوابین + سقف صفحه، بند terms
#   · باقی‌ماندهٔ GSC ۲۴ اوت و کار تمدید دامنه (اگر قبلاً کامل ننشسته باشد، بی‌ضرر merge می‌شود)
#
# منطق عیناً از scripts/deploy-domain-audit.sh (خانوادهٔ deploy-seo-hourly):
# merge سه‌طرفه با پایهٔ خودکار به‌ازای هر فایل — UP/MG/CF + بکاپ کامل +
# یکسان‌سازی پایانِ خط پیش از هر مقایسه (درسِ اجرای اول: CRLF سرور پایه‌یاب را کور می‌کرد).
set -u

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-audit7"
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

# 🔴 پین به کامیتِ ممیزی ۷ — نوکِ متحرکِ develop را دیپلوی نکن (قاعدهٔ ثبت‌شده)
MINE="${1:-822d092d3469960789935748df874a2fa5f06949}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# 🔴 ترتیب معنادار است: اول config و مدل‌ها و سرویس‌های مستقل، بعد فرمان‌ها و
#    میدل‌ور، بعد کنترلرها، بعد ویو و زبان، بعد مهاجرت، و آخر routeها.
APP_FILES="
config/billing.php
config/hosting.php
config/pagecache.php
config/pages.php
config/seo.php
app/Models/CloudLocation.php
app/Models/Product.php
app/Support/Funnel.php
app/Support/OrderHandoff.php
app/helpers.php
app/Services/Cloud/CloudCatalogSync.php
app/Services/Cloud/CloudCountry.php
app/Services/Domain/DomainRenewalInvoicer.php
app/Console/Commands/CheckContentLinks.php
app/Console/Commands/CheckSiteLinks.php
app/Console/Commands/ReleaseGate.php
app/Http/Middleware/PageCache.php
app/Http/Middleware/SecurityHeaders.php
app/Providers/AppServiceProvider.php
app/Http/Controllers/FunnelController.php
app/Http/Controllers/OrderSummaryController.php
app/Http/Controllers/CloudCatalogController.php
app/Http/Controllers/SiteController.php
app/Http/Controllers/Account/CloudStoreController.php
app/Http/Controllers/Account/StoreController.php
app/Http/Controllers/Account/DomainController.php
resources/views/account/checkout.blade.php
resources/views/account/domain-show.blade.php
resources/views/pages/cloud-location.blade.php
resources/views/pages/cloud.blade.php
resources/views/pages/hosting.blade.php
resources/views/pages/order-summary.blade.php
resources/views/pages/server-detail.blade.php
resources/views/pages/vps-hourly.blade.php
resources/views/partials/cloud-locations-links.blade.php
resources/views/partials/footer.blade.php
resources/views/partials/product-guides.blade.php
lang/en/ui.php
lang/fa/ui.php
lang/tr/ui.php
database/migrations/2026_10_02_000101_deactivate_legacy_cloud_locations.php
routes/console.php
routes/web.php
"

# فایل‌های وب‌روت — public_html جداست از servernet_app/public (کپی، نه symlink)
PUB_FILES="
public/robots.txt
public/assets/css/site.css
"

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

  if [ -f "$dest" ]; then
    mkdir -p "$BK/$(dirname "$rel")"
    cp -p "$dest" "$BK/$rel"
  fi

  if [ ! -f "$dest" ]; then
    mkdir -p "$(dirname "$dest")"
    cp "$mine_f" "$dest"; echo "NEW   $rel"; UPD=$((UPD+1)); return
  fi

  dest_n="$WORK/dest.tmp"; normalize "$dest" "$dest_n"

  if cmp -s "$dest_n" "$mine_f"; then
    cmp -s "$dest" "$mine_f" || { cp "$mine_f" "$dest"; echo "EOL   $rel   (فقط پایانِ خط درمان شد)"; UPD=$((UPD+1)); return; }
    echo "OK    $rel"; return
  fi

  # پایهٔ خودکار: نزدیک‌ترین نسخهٔ تاریخیِ همین فایل به آنچه روی سرور است
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
    cp "$mine_f" "$dest"; echo "UP    $rel   (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1)); return
  fi

  git -C repo show "$best:website/$rel" > "$WORK/base.raw"
  normalize "$WORK/base.raw" "$base_f"
  m="$WORK/merged.tmp"; cp "$dest_n" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"
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

echo
echo "═══ وب‌روت ($PUB) — public_html جداست از public اپ ═══"
for f in $PUB_FILES; do
  apply_one "$f" "$APP"
  rel_pub="${f#public/}"
  if [ -f "$APP/$f" ]; then
    mkdir -p "$BK/public_html/$(dirname "$rel_pub")" "$PUB/$(dirname "$rel_pub")"
    [ -f "$PUB/$rel_pub" ] && cp -p "$PUB/$rel_pub" "$BK/public_html/$rel_pub"
    cp "$APP/$f" "$PUB/$rel_pub" && echo "PUB   $rel_pub"
  fi
done

# ── نحو: هر PHPِ نشسته باید php -l پاس کند (short_open_tag سرور روشن است) ──
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

# ── ضمانتِ اتحاد — کلاسی که صدا زده می‌شود باید نشسته باشد ─────────────────
need_file() { [ -f "$1" ] || { echo "🔴 نیست: ${1#$APP/}"; union_ok=0; }; }

need_file "$APP/app/Support/OrderHandoff.php"
need_file "$APP/app/Support/Funnel.php"
need_file "$APP/app/Http/Controllers/FunnelController.php"
need_file "$APP/app/Console/Commands/ReleaseGate.php"
need_file "$APP/config/seo.php"
need_file "$APP/resources/views/partials/cloud-locations-links.blade.php"

g() { grep -qF "$2" "$APP/$1" 2>/dev/null || { echo "🔴 $1: «$2» ننشسته"; union_ok=0; }; }

# روت‌های تازهٔ ممیزی ۶+۷
g routes/web.php "name('healthz')"
g routes/web.php "name('go.pay')"
g routes/web.php "name('official')"
g routes/web.php "name('share.gone')"
g routes/web.php "name('api.funnel')"
# روت‌های موجود نباید بیفتند (درسِ ۲۸ مرداد)
for r in "name('aup')" "name('cloud.index')" "name('order.summary')" "name('vps.hourly')" "name('domain.search')" "name('contact')" "name('blog')"; do
  g routes/web.php "$r"
done
# زنجیره‌های صدازده‌شده
g app/Http/Controllers/OrderSummaryController.php "function pay("
g app/Models/Product.php "function orderableSlugs("
g app/Models/CloudLocation.php "function isLegacyCode("
g app/Http/Controllers/CloudCatalogController.php "isLegacyCode"
g app/Http/Controllers/SiteController.php "orderableSlugs"
g app/Support/Funnel.php "pay_redirect"
g app/Console/Commands/ReleaseGate.php "RG-DUP-PATH-11"
g config/pagecache.php "'/go', '/healthz'"
g config/pages.php "official-channels"
g routes/console.php "site:gate"
for L in fa en tr; do g "lang/$L/ui.php" "os_tax_neutral"; done

if [ "$union_ok" -eq 0 ]; then
  echo "🔴 اتحادِ فایل‌ها کامل نیست — کلِ بکاپ برمی‌گردد تا سایت ۵۰۰ نشود."
  ( cd "$BK" && find . -type f | while read -r p; do
      rel="${p#./}"
      case "$rel" in
        public_html/*) cp "$p" "$PUB/${rel#public_html/}" ;;
        *)             cp "$p" "$APP/$rel" ;;
      esac
      echo "   بازگشت: $rel"
    done )
  echo "🔴 دیپلوی ناتمام. خروجیِ بالا را بفرست."
  exit 1
fi

# ── مهاجرت (فقط --path خودش؛ idempotent و گارددار) + پاکسازی کش‌ها ─────────
if [ -n "$PHPBIN" ]; then
  cd "$APP"
  echo
  echo "═══ مهاجرتِ غیرفعال‌سازی لوکیشن‌های میراثی (اگر قبلاً نخورده) ═══"
  "$PHPBIN" artisan migrate --force \
    --path=database/migrations/2026_10_02_000101_deactivate_legacy_cloud_locations.php \
    || echo "⚠️ مهاجرت نخورد — کد بدونِ آن هم امن است (۳۰۱ الگویی است، نه دیتابیسی)"

  "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
  # نسلِ کشِ صفحه عوض شود تا HITهای ساختارِ قدیمی نمانند
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
echo "راستی‌آزمایی (بعد از ریستِ opcache):"
echo "  curl -s  -o /dev/null -w '%{http_code}\n' https://servernet.cloud/healthz                      ← 200"
echo "  curl -sI https://servernet.cloud/go/pay?sku=wordpress-3\&cycle=yearly | head -3               ← 302 به console با sig"
echo "  curl -s  -o /dev/null -w '%{http_code} %{redirect_url}\n' https://servernet.cloud/cloud/de-de-dedicated   ← 301 به /vps/germany"
echo "  curl -s  -o /dev/null -w '%{http_code}\n' https://servernet.cloud/official-channels            ← 200"
echo "  curl -s  -o /dev/null -w '%{http_code}\n' https://servernet.cloud/share/url                    ← 410"
echo "  curl -s https://servernet.cloud/sitemap.xml | grep -cE '/order/'                               ← ~64"
echo "  curl -s https://servernet.cloud/sitemap.xml | grep -cE '/([a-z]{2})-\\1-'                      ← 0"
echo "  curl -sI 'https://servernet.cloud/?qa=1' | grep -i 'x-cache\|server-timing'                    ← MISS + app;dur"
echo "  و بعد: $PHPBIN artisan site:gate --limit=60   ← دروازهٔ انتشار"
