#!/usr/bin/env bash
#
# دیپلوی «رفعِ یافته‌های Search Console — ممیزی ۲۴ اوت ۲۰۲۶» روی سرور cPanel.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA-این-اسکریپت>/scripts/deploy-gsc-fixes.sh) [<SHA>]
#   ← SHA پیش‌فرض 67f93a0 = فقط همین کارِ سئو/GSC روی شاخهٔ seo/gsc-fixes-aug24.
#     ⚠️ عمداً نوکِ develop نیست (درسِ ۲۲ اوت: روی develop کارِ دیپلوی‌نشدهٔ
#     همکاران هست و routes نوک به کنترلرهای غایب اشاره می‌کند ⇒ ۵۰۰ سراسری).
#
# چه می‌نشیند:
#   • schema_offer_extras در helpers + image/extras در ۴ نقطهٔ Product LD
#     (رفع ۶۷ آیتمِ invalid و ۳×۶۷ هشدارِ Merchant listings + ۱ خطای
#     Product snippets)
#   • noindex + حذف از sitemap برای مکان‌های ابریِ بی‌پلن + مهاجرتِ
#     غیرفعال‌سازیِ ردیف‌های میراثی (۲۱ صفحهٔ Duplicate-canonical)
#   • ۳۰۱ برای /privacy-policy /home /cart /services /marketing /servernet
#   • site.css: خنثی‌شدنِ .reveal در موبایل (LCP > 4s روی هر ۷۴ URL)
#
# روش: همان مدلِ اثبات‌شدهٔ deploy-seo-hourly — پایهٔ خودکار به‌ازای هر فایل
# (نزدیک‌ترین نسخهٔ تاریخی به فایلِ سرور)، merge سه‌طرفه، تداخلِ واقعی
# دست‌نخورده + گزارش، بکاپِ کامل، ensure-union، و مهاجرت فقط با --path خودش.
#
set -u

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-gsc-fixes"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=60

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }

if [ -d repo/.git ]; then
  git -C repo fetch --depth 400 origin develop seo/gsc-fixes-aug24 || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 400 --branch seo/gsc-fixes-aug24 https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
  git -C repo fetch --depth 400 origin develop || true
fi

MINE="${1:-67f93a0}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# ── فایل‌های اپ (نسبت به website/) ───────────────────────────────────────
# ترتیب مهم است: اول helpers (تابعِ تازه)، بعد مصرف‌کننده‌ها، آخر routes.
# اگر مصرف‌کننده بنشیند ولی هلپر نه ⇒ ۵۰۰؛ ensure-union پایین همین را می‌پاید.
APP_FILES="
app/helpers.php
app/Http/Controllers/CloudCatalogController.php
app/Http/Controllers/SiteController.php
resources/views/pages/hosting.blade.php
resources/views/pages/server-detail.blade.php
resources/views/pages/vps-hourly.blade.php
resources/views/pages/cloud-location.blade.php
database/migrations/2026_10_02_000101_deactivate_legacy_cloud_locations.php
routes/web.php
"

# ── فایل‌های استاتیک (به public_html می‌روند) ────────────────────────────
PUB_FILES="
assets/css/site.css
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
    mkdir -p "$BK/$(dirname "$drel")"
    cp -p "$dest" "$BK/$drel"
  fi

  if [ ! -f "$dest" ]; then
    mkdir -p "$(dirname "$dest")"
    cp "$mine_f" "$dest"; echo "NEW   $drel"; UPD=$((UPD+1)); return
  fi

  if cmp -s "$dest" "$mine_f"; then echo "OK    $drel"; return; fi

  # ── پایهٔ خودکار: نزدیک‌ترین نسخهٔ تاریخیِ همین فایل به آنچه روی سرور است ──
  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$WORK/cand.tmp" 2>/dev/null || continue
    if cmp -s "$dest" "$WORK/cand.tmp"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$dest" "$WORK/cand.tmp")
    if [ "$d" -lt "$bestd" ]; then bestd=$d; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "CF    $drel   ← در تاریخچه نیست؛ نسخهٔ ناشناخته روی سرور — دست نخورد"
    CONFLICTS="$CONFLICTS $drel"
    keep="$WORK/conflicts/$drel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"
    return
  fi

  if [ "$bestd" -eq 0 ]; then
    cp "$mine_f" "$dest"; echo "UP    $drel   (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1)); return
  fi

  # کس دیگری دست برده — merge سه‌طرفه روی کپی با نزدیک‌ترین پایه
  git -C repo show "$best:website/$rel" > "$base_f"
  m="$WORK/merged.tmp"; cp "$dest" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"; echo "MG    $drel   (پایه $(git -C repo rev-parse --short "$best")، فاصلهٔ سرور $bestd خط — تغییرِ دیگران حفظ شد)"; UPD=$((UPD+1))
  else
    echo "CF    $drel   ← تداخل واقعی؛ دست نخورد"
    CONFLICTS="$CONFLICTS $drel"
    keep="$WORK/conflicts/$drel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
}

echo "── بکاپ در: $BK"
for f in $APP_FILES; do apply_one "$f" "$APP" "$f"; done
for f in $PUB_FILES; do apply_one "public/$f" "$PUB" "$f"; done

# ── ضمانتِ اتحاد: چیزهایی که اگر ناقص بنشینند، سایت می‌شکند ────────────────
union_ok=1

# ۱) هلپر پیش‌نیازِ مصرف‌کننده‌هاست
if ! grep -q "function schema_offer_extras" "$APP/app/helpers.php" 2>/dev/null; then
  for c in \
    "app/Http/Controllers/CloudCatalogController.php" \
    "resources/views/pages/hosting.blade.php" \
    "resources/views/pages/server-detail.blade.php" \
    "resources/views/pages/vps-hourly.blade.php"; do
    if grep -q "schema_offer_extras" "$APP/$c" 2>/dev/null; then
      echo "🔴 $c هلپرِ غایب را صدا می‌زند — از بکاپ برمی‌گردد"
      [ -f "$BK/$c" ] && cp "$BK/$c" "$APP/$c" && echo "   $c از بکاپ برگشت"
      union_ok=0
    fi
  done
fi

# ۲) روت‌هایی که فوتر/هدر می‌زنند باید بمانند (درسِ ۲۸ مرداد) + ریدایرکت‌های تازه
for r in "name('aup')" "name('cloud.index')" "name('domain.search')" "name('privacy')" "name('privacy.legacy')"; do
  grep -qF "$r" "$APP/routes/web.php" || { echo "🔴 routes/web.php: $r گم شده"; union_ok=0; }
done

# ۳) noindexِ ویوِ مکان به لایوت وابسته است که از قبل روی سرور هست — فقط چک
grep -q "hasSection('noindex')" "$APP/resources/views/layouts/site.blade.php" 2>/dev/null \
  || echo "⚠️  لایوتِ سرور hasSection('noindex') ندارد — noindexِ مکان‌های خالی اثر نمی‌کند (سایت نمی‌شکند)"

if [ "$union_ok" -eq 0 ]; then
  echo "🔴 اتحاد کامل نیست — routes/web.php از بکاپ برمی‌گردد تا ۵۰۰ نخوریم؛ بقیه دستی."
  [ -f "$BK/routes/web.php" ] && cp "$BK/routes/web.php" "$APP/routes/web.php" && echo "   routes/web.php از بکاپ برگشت"
fi

# ── مهاجرت — فقط همین یک فایل، نه migrate کور ─────────────────────────────
PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)

if [ -n "$PHPBIN" ]; then
  cd "$APP"
  echo
  echo "═══ مهاجرت (فقط --path همین فایل) ═══"
  "$PHPBIN" artisan migrate --force \
    --path=database/migrations/2026_10_02_000101_deactivate_legacy_cloud_locations.php \
    || echo "🔴 مهاجرت شکست خورد — بقیهٔ دیپلوی سالم است؛ ردیف‌های میراثی تا اجرای دستی فعال می‌مانند (فقط noindex/سایت‌مپ اثر می‌کند)"

  "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
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
echo "راستی‌آزمایی:"
echo "  curl -sI https://servernet.cloud/privacy-policy | head -2                          ← 301 → /privacy"
echo "  curl -sI https://servernet.cloud/cart | head -2                                    ← 301 → /cloud"
echo "  curl -s  https://servernet.cloud/hosting/linux | grep -o '\"image\":\\[[^]]*\\]' | head -1   ← باید og.png باشد"
echo "  curl -s  https://servernet.cloud/hosting/linux | grep -c hasMerchantReturnPolicy   ← باید ≥1 باشد"
echo "  curl -s  https://servernet.cloud/servers/hpe-proliant-dl380-gen9 | grep -c '\"@type\":\"Product\"'  ← باید 0 باشد (بی‌قیمت)"
echo "  curl -s  https://servernet.cloud/sitemap.xml | grep -c 'cloud/ru-intel'            ← باید 0 باشد"
echo "  curl -s  https://servernet.cloud/cloud/ru-intel -o /dev/null -w '%{http_code}\\n'   ← باید 404 باشد (بعد از مهاجرت)"
echo "  دو بار: curl -sI https://servernet.cloud/ | grep -i x-cache                        ← دومی HIT"
