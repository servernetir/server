#!/usr/bin/env bash
#
# دیپلوی «تعمیرِ خزش و ایندکس» — یافته‌های Search Console، ۹ شهریور ۱۴۰۵.
#
# اجرا از ترمینالِ cPanel (اکانت servernetcloud).
#
# ⚠️ تا وقتی این کار در develop مرج نشده، آدرسِ خام باید به همان کامیت اشاره
#    کند نه به شاخه — نامِ شاخه اسلش دارد و در مسیرِ raw مبهم می‌شود.
#   DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/PIN/scripts/deploy-gsc-crawl.sh)
#         (PIN را با کامیتِ کاملِ همین کار عوض کن)
#
# بعد از مرج:
#   DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-gsc-crawl.sh)
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-gsc-crawl.sh) [<SHA>]
#
# چه چیزی و چرا:
#   ۱) لایوت canonical را از `url()->current()` می‌ساخت و آن متد رشتهٔ پرس‌وجو
#      را دور می‌ریزد ⇒ هر ۱۵ صفحهٔ فهرستِ بلاگ خودشان را `/blog` اعلام
#      می‌کردند. گوگل صفحهٔ صفحه‌بندی‌شده‌ای که خودش را صفحهٔ اول می‌خوانَد
#      «تکراری» می‌گیرد و خیلی کمتر می‌خزدش — و پست‌های ۱۰ به بعد **فقط** از
#      همان صفحه‌ها لینک دارند. گزارشِ ایندکس: ۶۵۴ نشانی
#      «Discovered – currently not indexed» با «آخرین خزش: N/A».
#   ۲) `.htaccess`: `SetEnvIf Query_String …` هرگز شلیک نمی‌کرد (نه کلیدواژهٔ
#      آپاچی است نه نامِ هدر) ⇒ کشِ یک‌سالهٔ CSS/JS هیچ‌وقت اعمال نشد.
#      سنجیدهٔ سرور: `site.css?v=1787915697` هم `max-age=604800` می‌گرفت.
#   ۳) robots: `/payment/` و `/sb/`
#
# منطقِ merge عیناً از scripts/deploy-content-1405.sh.
# ⚠️ تلهٔ CRLF: بی‌normalize، پایه‌یاب روی سرور کور می‌شود.
#
set -u

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-gsc-crawl"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=60
DRY="${DRY:-0}"
SITE="https://servernet.cloud"

# ═══ 🔴 اثباتِ مقصد — پیش از هر نوشتنی ═══
# اجرا با کاربرِ اشتباه یعنی $HOME عوض می‌شود، فایل‌ها جایی می‌نشینند که سایت
# آن‌جا نیست، و گاردِ اتحاد **سبز** می‌شود چون همان فایل‌هایی را می‌سنجد که
# خودش تازه ساخته.
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "🔴 «$APP» نصبِ لاراول نیست (artisan یا vendor نیست)."
  echo "   احتمالاً با کاربرِ اشتباه واردید. کاربرِ درست: servernetcloud"
  exit 1
fi
if [ ! -d "$PUB" ]; then
  echo "🔴 «$PUB» نیست — مقصدِ استاتیک پیدا نشد."
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

# ⚠️ شاخهٔ کار هم fetch می‌شود: تا وقتی این کار در develop مرج نشده، پین فقط
#    روی همان شاخه است. بعد از مرج، این خط بی‌اثر ولی بی‌ضرر می‌مانَد.
if [ -d repo/.git ]; then
  git -C repo fetch --depth 400 origin develop feature/gsc-crawl-index 2>/dev/null \
    || git -C repo fetch --depth 400 origin develop \
    || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 400 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
  git -C repo fetch --depth 400 origin feature/gsc-crawl-index 2>/dev/null || true
fi

MINE="${1:-97ecc7ea}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
[ "$DRY" = "0" ] || echo "── حالتِ آزمایشی (DRY=1): هیچ فایلی نوشته نمی‌شود"

APP_FILES="
app/Http/Controllers/BlogController.php
app/Http/Controllers/SiteController.php
resources/views/layouts/site.blade.php
resources/views/pages/blog.blade.php
"

# ⚠️ فقط robots.txt. `public/index.php` و `public/.htaccess` **کپی نمی‌شوند**:
#    نسخهٔ سرور با نسخهٔ مخزن فرق دارد (مسیرهای نسبی، خط‌های تزریقیِ cPanel) و
#    رونویسی‌شان یک‌بار کلِ سایت را ۵۰۰ کرد. `.htaccess` پایین‌تر **جراحی**
#    می‌شود، نه رونویسی.
PUB_FILES="
robots.txt
"

CONFLICTS=""
CREATED=""
UPD=0

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }
normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }

# $1 = مسیرِ نسبی زیر website/   $2 = ریشهٔ مقصد   $3 = مسیرِ نسبیِ مقصد
#
# ⚠️ بکاپ زیرِ «ریشهٔ مقصد» نگه داشته می‌شود ($BK/app/… در برابر $BK/__pub__/…)
#    وگرنه حلقهٔ بازگشت نمی‌داند هر فایل به کدام ریشه برمی‌گردد و robots.txt را
#    می‌برد داخلِ اپ — بازگشتی که خودش خرابی می‌سازد.
apply_one() {
  rel="$1"; root="$2"; drel="$3"; dest="$root/$drel"
  if [ "$root" = "$PUB" ]; then bkrel="__pub__/$drel"; else bkrel="$drel"; fi
  mine_f="$WORK/mine.tmp"; base_f="$WORK/base.tmp"

  git -C repo show "$MINE:website/$rel" > "$WORK/mine.raw" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; return; }
  normalize "$WORK/mine.raw" "$mine_f"

  if [ -f "$dest" ] && [ "$DRY" = "0" ]; then
    mkdir -p "$BK/$(dirname "$bkrel")"
    cp -p "$dest" "$BK/$bkrel"
  fi

  if [ ! -f "$dest" ]; then
    # فایلِ تازه بکاپ ندارد، پس حلقهٔ بازگشت نمی‌بیندش — این‌جا ثبت می‌شود
    # تا «بازگشتِ کامل» واقعاً کامل باشد.
    [ "$DRY" = "0" ] && { mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"; CREATED="$CREATED $root|$drel"; }
    echo "NEW   $drel   (روی سرور نبود)"; UPD=$((UPD+1)); return
  fi

  dest_n="$WORK/dest.tmp"; normalize "$dest" "$dest_n"

  if cmp -s "$dest_n" "$mine_f"; then
    cmp -s "$dest" "$mine_f" || { [ "$DRY" = "0" ] && cp "$mine_f" "$dest"; echo "EOL   $drel   (فقط پایانِ خط)"; UPD=$((UPD+1)); return; }
    echo "OK    $drel"; return
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
    echo "CF    $drel   ← در تاریخچهٔ develop نیست؛ نسخهٔ ناشناخته روی سرور — دست نخورد"
    CONFLICTS="$CONFLICTS $drel"
    keep="$WORK/conflicts/$drel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"
    return
  fi

  if [ "$bestd" -eq 0 ]; then
    [ "$DRY" = "0" ] && cp "$mine_f" "$dest"
    echo "UP    $drel   (سرور = $(git -C repo rev-parse --short "$best") — جایگزینیِ بی‌ریسک)"; UPD=$((UPD+1)); return
  fi

  git -C repo show "$best:website/$rel" > "$WORK/base.raw"
  normalize "$WORK/base.raw" "$base_f"
  m="$WORK/merged.tmp"; cp "$dest_n" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    [ "$DRY" = "0" ] && cp "$m" "$dest"
    echo "MG    $drel   (پایه $(git -C repo rev-parse --short "$best")، فاصلهٔ سرور $bestd خط — تغییرِ دیگران حفظ شد)"
    UPD=$((UPD+1))
  else
    echo "CF    $drel   ← تداخل واقعی؛ دست نخورد (پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط)"
    CONFLICTS="$CONFLICTS $drel"
    keep="$WORK/conflicts/$drel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
    echo "──── سرور − پایه ($drel):"
    diff -u "$base_f" "$dest_n" | sed -n '1,140p'
    echo "──── پایانِ diff"
  fi
}

echo "── بکاپ در: $BK"
echo
echo "═══ اپ ($APP) ═══"
for f in $APP_FILES; do apply_one "$f" "$APP" "$f"; done
echo
echo "═══ استاتیک ($PUB) ═══"
for f in $PUB_FILES; do apply_one "public/$f" "$PUB" "$f"; done

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)

union_ok=1
echo
if [ -n "$PHPBIN" ]; then
  echo "═══ php -l ═══"
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
  echo "═══ حالتِ آزمایشی — هیچ فایلی نوشته نشد ═══"
  echo "برنامه: $UPD فایل به‌روز یا تازه (+ جراحیِ .htaccess)"
  if [ -n "$CONFLICTS" ]; then
    echo "🔴 تداخل:$CONFLICTS — پیش از دیپلویِ واقعی باید حل شود."
    exit 1
  fi
  echo "✅ هیچ تداخلی نیست — همین فرمان را بدونِ DRY=1 بزن."
  exit 0
fi

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────────
# 🔴 `--` اجباری است: هر الگویی که با `-` شروع شود را grep **گزینه** می‌خواند،
#    و با stderrِ خفه، شکستِ ابزار عیناً شبیهِ شکستِ ادعا دیده می‌شود — یک بار
#    دقیقاً همین یک دیپلویِ سالم را برگرداند.
g() {
  err=$(grep -qF -- "$2" "$1" 2>&1) && return 0
  [ -n "$err" ] && echo "   (grep گفت: $err)"
  echo "🔴 ${1#$HOME/}: «$2» ننشسته"
  union_ok=0
}

# تعمیرِ اصلی — هر سه تکه لازم است؛ نبودِ هرکدام یعنی canonical دوباره دروغ می‌گوید
g "$APP/app/Http/Controllers/BlogController.php" "listingSeo"
g "$APP/app/Http/Controllers/BlogController.php" "listingNoindex"
g "$APP/resources/views/pages/blog.blade.php"    "listingNoindex"
g "$APP/resources/views/layouts/site.blade.php"  "hrefLangUrls"

# ⚠️ و آنچه **نباید** قربانی شود: هر سه، قاعده‌هایی‌اند که پیش‌تر با خونِ دل
#    نوشته شده‌اند و merge می‌تواند بی‌صدا برشان دارد.
g "$APP/resources/views/layouts/site.blade.php"  'rel="canonical"'
g "$APP/resources/views/layouts/site.blade.php"  'hreflang="x-default"'
g "$APP/resources/views/layouts/site.blade.php"  "faOnly"
g "$APP/app/Http/Controllers/SiteController.php" "orderableSlugs"
g "$APP/app/Http/Controllers/SiteController.php" "isLegacyCode"
g "$APP/app/Http/Controllers/SiteController.php" "llms.txt"
g "$APP/app/Http/Controllers/SiteController.php" "urmia.hub"
g "$PUB/robots.txt" "Disallow: /account/"
g "$PUB/robots.txt" "Sitemap: https://servernet.cloud/sitemap.xml"
g "$PUB/robots.txt" "Disallow: /sb/"

# ── جراحیِ .htaccess ────────────────────────────────────────────────────────
#
# 🔴 رونویسی ممنوع. نسخهٔ سرور می‌تواند خط‌های تزریقیِ cPanel داشته باشد که در
#    مخزن نیستند؛ کپیِ کور آنها را پاک می‌کند و سایت را می‌خواباند. پس فقط دو
#    تغییرِ نقطه‌ای، با بکاپ و آزمونِ زندهٔ HTTP و بازگشتِ خودکار.
HT="$PUB/.htaccess"
echo
echo "═══ .htaccess ═══"
if [ ! -f "$HT" ]; then
  echo "⚠️ $HT نیست — رد شد (کشِ یک‌ساله اعمال نمی‌شود، ولی چیزی هم خراب نمی‌شود)"
elif grep -q 'SN_VERSIONED:1' "$HT"; then
  echo "OK    پرچمِ نسخه از قبل هست"
elif ! grep -q 'Redirect Trailing Slashes' "$HT"; then
  echo "⚠️ لنگرِ «Redirect Trailing Slashes» در .htaccessِ سرور نیست — دست نخورد."
  echo "   دستی اضافه کن (داخلِ بلوکِ <IfModule mod_rewrite.c>، پیش از فرانت‌کنترلر):"
  echo '     RewriteCond %{QUERY_STRING} (^|&)v=[0-9]{6,}(&|$)'
  echo '     RewriteRule \.(css|js|mjs)$ - [E=SN_VERSIONED:1]'
else
  cp -p "$HT" "$BK/htaccess.before"
  awk '
    /Redirect Trailing Slashes/ && !ins {
      print "    # پرچمِ نسخهٔ واقعی — mod_headers پایین‌تر می‌خواندش."
      print "    # SetEnvIf Query_String هرگز شلیک نمی‌کرد (نه کلیدواژهٔ آپاچی، نه نامِ هدر)."
      print "    RewriteCond %{QUERY_STRING} (^|&)v=[0-9]{6,}(&|$)"
      print "    RewriteRule \\.(css|js|mjs)$ - [E=SN_VERSIONED:1]"
      print ""
      ins = 1
    }
    /SetEnvIf Query_String/ { next }
    { print }
  ' "$HT" > "$WORK/ht.new"

  # 🔴 «نوشتم» با «نشست» یکی نیست. اگر awk لنگر را نگرفته باشد، فایل بی‌تغییر
  #    است و چاپِ موفقیت دروغ می‌شود — همان درسِ «unverified success print».
  if ! grep -q 'SN_VERSIONED:1' "$WORK/ht.new"; then
    echo "🔴 درج انجام نشد (لنگر نگرفت) — .htaccess دست‌نخورده مانْد."
  else
    cp "$WORK/ht.new" "$HT"
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE/" 2>/dev/null)
    case "${code:-0}" in
      2*|3*)
        echo "✅ .htaccess به‌روز شد و سایت هنوز $code می‌دهد"
        V=$(curl -s --max-time 20 "$SITE/" 2>/dev/null | grep -oE 'assets/css/site\.css\?v=[0-9]+' | head -1)
        if [ -n "$V" ]; then
          CC=$(curl -sI --max-time 20 "$SITE/$V" 2>/dev/null | tr -d '\r' | grep -i '^cache-control' | head -1)
          echo "   $V  →  ${CC:-«هدری برنگشت»}"
          case "$CC" in
            *immutable*) echo "   ✅ کشِ یک‌ساله اعمال شد" ;;
            *)           echo "   ⚠️ هنوز immutable نیست — mod_headers یا ترتیبِ قواعد را ببین (سایت سالم است)" ;;
          esac
        else
          echo "   ⚠️ مهرِ عددیِ نسخه در HTML پیدا نشد (asset_ver به هشِ fallback افتاده؟)"
        fi
        ;;
      *)
        cp "$BK/htaccess.before" "$HT"
        echo "🔴 سایت بعد از تغییر «$code» داد — .htaccess از بکاپ برگشت."
        echo "   بقیهٔ دیپلوی دست‌نخورده است."
        ;;
    esac
  fi
fi

# ── بازگشتِ کامل اگر اتحاد ناقص است ─────────────────────────────────────────
if [ "$union_ok" -eq 0 ]; then
  echo
  echo "🔴 اتحادِ فایل‌ها کامل نیست — کلِ بکاپ برمی‌گردد تا سایت ۵۰۰ نشود."
  ( cd "$BK" && find . -type f ! -name 'htaccess.before' | while read -r p; do
      rel="${p#./}"
      case "$rel" in
        __pub__/*) cp "$p" "$PUB/${rel#__pub__/}"; echo "   بازگشت (public_html): ${rel#__pub__/}" ;;
        *)         cp "$p" "$APP/$rel";            echo "   بازگشت (app): $rel" ;;
      esac
    done )
  for item in $CREATED; do
    root="${item%%|*}"; rel="${item#*|}"
    rm -f "$root/$rel" && echo "   حذفِ فایلِ تازه: $rel"
  done
  [ -f "$BK/htaccess.before" ] && cp "$BK/htaccess.before" "$HT" && echo "   بازگشت: .htaccess"
  echo "🔴 دیپلوی ناتمام. خروجیِ بالا را بفرست."
  exit 1
fi

# ── کش‌ها (هیچ مهاجرتی در کار نیست) ─────────────────────────────────────────
if [ -n "$PHPBIN" ]; then
  cd "$APP"
  "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
  # 🔴 بی‌این، صفحه‌های کش‌شده تا ۲۴ ساعت canonicalِ قدیمی را سرو می‌کنند —
  #    یعنی دقیقاً همان چیزی که تعمیر شد، به گوگل نشان داده نمی‌شود.
  "$PHPBIN" artisan tinker --execute='\App\Http\Middleware\PageCache::purge(); echo "pagecache purged";' 2>/dev/null \
    || echo "⚠️ purge کشِ صفحه انجام نشد — از /admin یا صبر تا TTL"
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
  echo "WARN: php پیدا نشد — کش‌ها دستی پاک شدند"
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · فایل‌های به‌روزشده: $UPD"
if [ -n "$CONFLICTS" ]; then
  echo "🔴 تداخل (دست‌نخورده):$CONFLICTS   — نسخه‌ها در $WORK/conflicts/"
else
  echo "✅ هیچ تداخلی نبود"
fi
echo
echo "کارِ باقی‌مانده: ریستِ opcache از /system/opcache"
echo
echo "═══ راستی‌آزمایی (بعد از ریستِ opcache) ═══"
echo "  curl -s '$SITE/blog?page=2' | grep -o '<link rel=\"canonical\"[^>]*>'"
echo "      ← باید .../blog?page=2 باشد، نه .../blog"
echo "  curl -s '$SITE/blog?cat=seo' | grep -o '<link rel=\"canonical\"[^>]*>'"
echo "      ← باید .../blog?cat=seo باشد"
echo "  curl -s '$SITE/blog?tag=%D8%B3%D8%A6%D9%88' | grep -o '<meta name=\"robots\"[^>]*>'"
echo "      ← باید noindex,follow باشد و هیچ canonicalی نباشد"
echo "  curl -s '$SITE/sitemap.xml' | grep -c 'cat='"
echo "      ← باید > ۰ باشد (فهرستِ دسته‌ها)"
echo "  curl -s '$SITE/sitemap.xml' | grep -c 'page='"
echo "      ← باید ۰ باشد"
echo "  curl -s '$SITE/robots.txt' | grep -E 'sb/|payment/'"
echo
echo "═══ در Search Console (کارِ کارفرما، بعد از راستی‌آزمایی) ═══"
echo "  Pages → «Duplicate, Google chose different canonical» → VALIDATE FIX"
echo "  Pages → «Not found (404)» و «Server error (5xx)» → VALIDATE FIX"
echo "  (هر دو از قبل روی سرور درست شده‌اند؛ فقط اعتبارسنجی مانده)"
