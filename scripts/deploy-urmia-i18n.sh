#!/usr/bin/env bash
#
# دیپلوی «سه‌زبانه‌شدن بخش ارومیه + بستن نشتی‌های فارسی روی en/tr» روی cPanel.
#
# اجرا از ترمینالِ cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-urmia-i18n.sh) [<SHA>]
#   ← SHA پیش‌فرض = کامیتِ mergeِ همین کار روی develop (پایین، MINE).
#
# ⚠️ عمداً «نوکِ develop در آیندهٔ نامعلوم» نیست؛ به SHA پین است. روی develop
#    کارِ دیپلوی‌نشدهٔ همکاران می‌نشیند و routes/webِ نوک ممکن است به
#    کنترلری اشاره کند که روی سرور نیست ⇒ ۵۰۰ سراسری. هرکس دیپلویِ خودش را دارد.
#
# منطق عیناً از scripts/deploy-parts-shop.sh (که خودش از deploy-seo-hourly است):
# merge سه‌طرفه با پایهٔ خودکار به‌ازای هر فایل — UP/MG/CF + بکاپ کامل.
# این دیپلوی هیچ فایل استاتیک و هیچ مهاجرتی ندارد؛ فقط PHP/Blade/config.
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-urmia-i18n"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=60

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }

if [ -d repo/.git ]; then
  git -C repo fetch --depth 400 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 400 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

MINE="${1:-4378fa64eca202fe50ba3adb6296825dea2a39b4}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# 🔴 ترتیب معنادار است: اول config و سرویس‌های مستقل، بعد کنترلرها، بعد
#    ویوها، و آخر از همه routes/web.php (که به همهٔ قبلی‌ها اشاره می‌کند).
APP_FILES="
config/urmia.php
config/urmia_i18n.php
config/catalog/services.php
app/Services/BlogRepository.php
app/Http/Controllers/UrmiaController.php
app/Http/Controllers/SiteController.php
resources/views/pages/urmia/hub.blade.php
resources/views/pages/urmia/page.blade.php
resources/views/pages/urmia/city.blade.php
resources/views/partials/footer.blade.php
resources/views/layouts/site.blade.php
resources/views/pages/domain-search.blade.php
routes/web.php
"

CONFLICTS=""
UPD=0

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

apply_one() {
  rel="$1"; dest="$APP/$rel"
  mine_f="$WORK/mine.tmp"; base_f="$WORK/base.tmp"

  git -C repo show "$MINE:website/$rel" > "$mine_f" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; return; }

  if [ -f "$dest" ]; then
    mkdir -p "$BK/$(dirname "$rel")"
    cp -p "$dest" "$BK/$rel"
  fi

  if [ ! -f "$dest" ]; then
    mkdir -p "$(dirname "$dest")"
    cp "$mine_f" "$dest"; echo "NEW   $rel"; UPD=$((UPD+1)); return
  fi

  if cmp -s "$dest" "$mine_f"; then echo "OK    $rel"; return; fi

  # پایهٔ خودکار: نزدیک‌ترین نسخهٔ تاریخیِ همین فایل به آنچه روی سرور است
  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$WORK/cand.tmp" 2>/dev/null || continue
    if cmp -s "$dest" "$WORK/cand.tmp"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$dest" "$WORK/cand.tmp")
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

  git -C repo show "$best:website/$rel" > "$base_f"
  m="$WORK/merged.tmp"; cp "$dest" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"
    echo "MG    $rel   (پایه $(git -C repo rev-parse --short "$best")، فاصلهٔ سرور $bestd خط — تغییرِ دیگران حفظ شد)"
    UPD=$((UPD+1))
  else
    echo "CF    $rel   ← تداخل واقعی؛ دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
}

echo "── بکاپ در: $BK"
echo
echo "═══ اپ ($APP) ═══"
for f in $APP_FILES; do apply_one "$f"; done

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────
# اگر روتِ سه‌زبانه نشست ولی config ترجمه یا کنترلر نه (یا برعکس)، همین‌جا
# بفهمیم، نه از ۵۰۰ِ بازدیدکننده.
echo
union_ok=1
need_file() { [ -f "$1" ] || { echo "🔴 نیست: ${1#$APP/}"; union_ok=0; }; }

need_file "$APP/config/urmia_i18n.php"
need_file "$APP/app/Http/Controllers/UrmiaController.php"
for v in hub page city; do need_file "$APP/resources/views/pages/urmia/$v.blade.php"; done

grep -q "urmia_i18n" "$APP/app/Http/Controllers/UrmiaController.php" 2>/dev/null \
  || { echo "🔴 UrmiaController هنوز overlay ترجمه ندارد"; union_ok=0; }

# سه ثبتِ گروهِ urmia (fa + en. + tr.)
n=$(grep -c 'group($urmia)' "$APP/routes/web.php" 2>/dev/null || echo 0)
[ "$n" -eq 3 ] || { echo "🔴 routes/web.php: گروه urmia $n بار ثبت شده (باید ۳ بار)"; union_ok=0; }

grep -q "step === 'translate'" "$APP/routes/web.php" \
  || { echo "🔴 routes/web.php: stepِ translate نیست"; union_ok=0; }

# روت‌های موجودی که هدر/فوتر می‌زنند نباید بیفتند (درسِ ۲۸ مرداد)
for r in "name('aup')" "name('cloud.index')" "name('domain.search')" "name('contact')" "name('parts.index')" "name('urmia.hub')" "name('blog')" ; do
  grep -qF "$r" "$APP/routes/web.php" || { echo "🔴 routes/web.php: روتِ $r گم شده"; union_ok=0; }
done

grep -q "lroute('urmia.hub')" "$APP/resources/views/partials/footer.blade.php" 2>/dev/null \
  || { echo "🔴 footer هنوز لینکِ سه‌زبانهٔ ارومیه را ندارد"; union_ok=0; }

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
echo "کارِ باقی‌مانده: ریستِ opcache (validate_timestamps=0 — بی‌ریست کدِ تازه اجرا نمی‌شود)"
echo
echo "راستی‌آزمایی:"
echo "  curl -sI https://servernet.cloud/?qa=1               | head -1   ← 200"
echo "  curl -sI https://servernet.cloud/en/urmia?qa=1        | head -1   ← 200"
echo "  curl -sI https://servernet.cloud/tr/urmia/web-design?qa=1 | head -1 ← 200"
echo "  curl -sI https://servernet.cloud/en/urmia/cities/khoy?qa=1 | head -1 ← 200"
echo "  curl -s  https://servernet.cloud/sitemap.xml | grep -c '/en/urmia'   ← ۲۹"
