#!/usr/bin/env bash
#
# دیپلوی جمع‌بندیِ ممیزی نهم + رفعِ قرمزهای develop — شهریور ۱۴۰۵.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-audit-r9-final.sh) [<SHA>]
#
# چه چیزی دیپلوی می‌شود:
#
#   تصمیم‌های کارفرما
#     · ارومیه فقط فارسی — /en/urmia* و /tr/urmia* حالا ۴۱۰ می‌دهند،
#       نقشهٔ سایت فقط فارسی، و صفحهٔ فارسی دیگر alternate خارجی نمی‌دهد
#     · شناسه‌های ثبتی از فوتر برداشته شد (جایشان /contact می‌مانَد)
#
#   خرابی‌های زندهٔ سایت
#     · /assets/flags/xx.svg — ۴۰۴ بود؛ انتخابِ مکانِ GPU اسلاتِ خالی داشت
#     · robots.txt — کامنت‌های استدلالیِ داخلی از فایلِ عمومی برداشته شدند
#     · /developers صفحهٔ en/tr دو ردیفِ اسکوپِ تونل را فارسی نشان می‌داد
#
#   ردِ خرابی برای سه آژیرِ بی‌صدا
#     · آژیرِ «سرورِ ساعتیِ زیرِ بها»، خبرِ تغییرِ نرخ به مشتری، و اعلامِ
#       نتیجهٔ احراز هویت — هر سه شکستشان را می‌بلعیدند
#
# منطق عیناً از scripts/deploy-audit7.sh و deploy-gpu.sh: merge سه‌طرفه با
# پایهٔ خودکار به‌ازای هر فایل (UP/MG/CF) + بکاپ کامل + یکسان‌سازیِ پایانِ خط.
# ⚠️ تلهٔ CRLF: بی‌normalize، پایه‌یاب روی سرور کور می‌شود.
set -u

# ── حالتِ آزمایشی ──────────────────────────────────────────────────────────
#
#   DRY=1 bash <(curl -fsSL .../deploy-audit-r9-final.sh)
#
# 🔴 اول این را بزن. هیچ عارضه‌ای ندارد — نه فایل، نه کش. فقط می‌گوید کدام
# فایل تداخل دارد.
DRY="${DRY:-0}"

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-r9-final"
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

# 🔴 پین به کامیتِ مشخص — نوکِ متحرکِ develop را دیپلوی نکن.
MINE="${1:-5caf49c}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# 🔴 ترتیب معنادار است: اول محتوا و partial (که ویو صدایشان می‌زند)، بعد
#    ویوها، بعد کنترلر، بعد فرمان‌ها، و **آخر** routeها.
#
# ⚠️ `routes/web.php` را `scripts/deploy-gpu.sh` هم می‌برد — تلهٔ ثبت‌شدهٔ
#    «دو دیپلوی روی یک فایل». پینِ آن اسکریپت به همین کامیت برده شد تا هر دو
#    هم‌گرا شوند و ترتیبِ اجرا مهم نباشد.
APP_FILES="
resources/content/developers-tunnel.php
resources/content/developers.php
resources/views/partials/dev-copy.blade.php
resources/views/pages/developers.blade.php
resources/views/layouts/site.blade.php
resources/views/partials/footer.blade.php
resources/views/pages/urmia/hub.blade.php
resources/views/pages/urmia/page.blade.php
resources/views/pages/urmia/city.blade.php
app/Http/Controllers/SiteController.php
app/Console/Commands/CloudHourlyAudit.php
app/Console/Commands/CloudHourlyReprice.php
app/Services/Customer/KycReview.php
routes/web.php
"

# فایل‌های وب‌روت — public_html جداست از servernet_app/public (کپی، نه symlink)
#
# ⚠️ فقط همین دو. هرگز `public/index.php` را این‌جا نگذارید: نسخهٔ سرور مسیرهای
#    متفاوتی دارد چون اپ بیرونِ webroot است و آپلودش کلِ سایت را ۵۰۰ می‌کند.
PUB_FILES="
public/robots.txt
public/assets/flags/xx.svg
"

CONFLICTS=""
UPD=0

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }

# ⚠️ خالی و باید خالی بمانَد.
FORCE_FILES=""

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
    [ "$DRY" = "0" ] && { mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"; }
    echo "NEW   $rel   (فایلِ تازه — روی سرور نیست)"; UPD=$((UPD+1)); return
  fi

  dest_n="$WORK/dest.tmp"; normalize "$dest" "$dest_n"

  case " $(echo $FORCE_FILES) " in *" $rel "*)
    if cmp -s "$dest_n" "$mine_f"; then
      echo "OK    $rel"; return
    fi
    [ "$DRY" = "0" ] && cp "$mine_f" "$dest"
    echo "FR    $rel   (جایگزینیِ اجباری)"
    UPD=$((UPD+1)); return
  ;; esac

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

echo
echo "═══ وب‌روت ($PUB) — public_html جداست از public اپ ═══"
for f in $PUB_FILES; do
  apply_one "$f" "$APP"
  rel_pub="${f#public/}"
  if [ -f "$APP/$f" ] && [ "$DRY" = "0" ]; then
    mkdir -p "$BK/public_html/$(dirname "$rel_pub")" "$PUB/$(dirname "$rel_pub")"
    [ -f "$PUB/$rel_pub" ] && cp -p "$PUB/$rel_pub" "$BK/public_html/$rel_pub"
    cp "$APP/$f" "$PUB/$rel_pub" && echo "PUB   $rel_pub"
  fi
done

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)

echo
union_ok=1
if [ -n "$PHPBIN" ]; then
  echo "═══ php -l روی فایل‌های نشسته ═══"
  for f in $APP_FILES $PUB_FILES; do
    case "$f" in *.php)
      [ -f "$APP/$f" ] || continue
      "$PHPBIN" -l "$APP/$f" >/dev/null 2>&1 || { echo "🔴 خطای نحو: $f"; union_ok=0; }
    ;; esac
  done
  [ "$union_ok" -eq 1 ] && echo "✅ نحو سالم"
fi

# ── پایانِ حالتِ آزمایشی ────────────────────────────────────────────────────
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
  echo "⚠️ راستی‌آزمایی و پاکسازیِ کش فقط در اجرای واقعی می‌دوند."
  exit 0
fi

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────────
# 🔴 دیپلوی فایل‌به‌فایل است و «یک فایل جا ماند» فرضی نیست. این‌جا هر تکه‌ای که
#    نبودش یک خرابیِ **خاموش** می‌سازد جداگانه سنجیده می‌شود.
need_file() { [ -f "$1" ] || { echo "🔴 نیست: ${1#$APP/}"; union_ok=0; }; }

need_file "$APP/resources/views/partials/dev-copy.blade.php"
need_file "$APP/resources/content/developers-tunnel.php"
need_file "$PUB/assets/flags/xx.svg"

g() { grep -qF "$2" "$APP/$1" 2>/dev/null || { echo "🔴 $1: «$2» ننشسته"; union_ok=0; }; }

# ── ارومیه: هر سه تکه با هم، وگرنه وضع از قبل بدتر است ──
#    (مسیر رفته ولی در نقشه مانده = گوگل هر بار ۴۱۰ می‌گیرد)
g routes/web.php "urmiaGone"
g app/Http/Controllers/SiteController.php "addFa"
for v in hub page city; do
  g "resources/views/pages/urmia/$v.blade.php" "faOnly"
done
# و روتِ فارسی نباید افتاده باشد
g routes/web.php "name('urmia.hub')"

# ── فوتر: بلوکِ شناسهٔ ثبتی رفته، ولی مهرِ نماد و خودِ فوتر سرِ جا ──
grep -qF 'class="f-legal"' "$APP/resources/views/partials/footer.blade.php" \
  && { echo "🔴 بلوکِ f-legal هنوز در فوتر است"; union_ok=0; }
g resources/views/partials/footer.blade.php "f-bottom"

# ── مستنداتِ توسعه‌دهنده ──
g resources/views/pages/developers.blade.php "partials.dev-copy"
g resources/content/developers.php "tunnel:read"
g resources/content/developers.php "tunnel:write"

# ── ردِ خرابیِ سه آژیر ──
g app/Console/Commands/CloudHourlyAudit.php "آژیرِ «سرورِ ساعتیِ زیرِ بها» فرستاده نشد"
g app/Console/Commands/CloudHourlyReprice.php "خبرِ تغییرِ نرخِ ساعتی به مشتریِ سرویسِ"
g app/Services/Customer/KycReview.php "نتیجهٔ احراز هویت به مشتریِ"

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

# ── پاکسازی کش ────────────────────────────────────────────────────────────
# ⚠️ این دیپلوی مهاجرت و seeder **ندارد** — هیچ ستون و هیچ ردیفی عوض نمی‌شود.
if [ -n "$PHPBIN" ]; then
  cd "$APP"
  "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
  "$PHPBIN" artisan tinker --execute='\App\Http\Middleware\PageCache::purge(); echo "pagecache purged";' 2>/dev/null \
    || echo "⚠️ purge کشِ صفحه دستی: از /admin یا صبر تا TTL"
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
echo "کارِ باقی‌مانده: ریستِ opcache از /system/opcache (validate_timestamps=0)"
echo
echo "راستی‌آزمایی (بعد از ریستِ opcache):"
echo "  curl -s -o /dev/null -w '%{http_code}\n' https://servernet.cloud/en/urmia         ← 410"
echo "  curl -s -o /dev/null -w '%{http_code}\n' https://servernet.cloud/tr/urmia/khoy    ← 410"
echo "  curl -s -o /dev/null -w '%{http_code}\n' https://servernet.cloud/urmia            ← 200 (دست‌نخورده)"
echo "  curl -s https://servernet.cloud/sitemap.xml | grep -c '/en/urmia'                 ← 0"
echo "  curl -s https://servernet.cloud/urmia | grep -c 'hreflang=\"en\"'                   ← 0"
echo "  curl -s -o /dev/null -w '%{http_code}\n' https://servernet.cloud/assets/flags/xx.svg ← 200"
echo "  curl -s https://servernet.cloud/robots.txt | grep -c '#'                          ← 0"
echo "  curl -s https://servernet.cloud/robots.txt | grep -c 'Disallow'                   ← 13 (هیچ قاعده‌ای نیفتاده)"
echo "  curl -s https://servernet.cloud/ | grep -c 'f-legal'                              ← 0"
echo "  curl -s https://servernet.cloud/contact | grep -c 'ct-legal'                      ← 1 (افشا سرِ جایش)"
echo "  curl -s -o /dev/null -w '%{http_code}\n' https://servernet.cloud/developers/tunnel  ← 200"
