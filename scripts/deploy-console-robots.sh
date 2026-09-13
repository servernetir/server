#!/usr/bin/env bash
#
# دیپلوی «کنسول robots.txtِ خودش را بگیرد» — کارِ سمتِ سرورِ ممیزیِ خزش.
#
# اجرا از ترمینالِ cPanel (اکانت servernetcloud):
#   DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-console-robots.sh)
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-console-robots.sh) [<SHA>]
#
# ═══ چرا ═══
#
# `console.servernet.cloud` همین `public_html` را سرو می‌کند، پس همان
# `robots.txt`ِ سایتِ اصلی را می‌داد — با «Allow: /». نتیجه در Crawl Stats
# (۹۰ روز): ۶۰۰ درخواستِ خزش، ۱۰٪ کلِ بودجه، خرجِ میزبانی که هر صفحه‌اش از
# قبل `noindex` است — در حالی که ۶۵۴ صفحهٔ بلاگ هرگز خزیده نشده بودند.
#
# دو تغییر: یک فایلِ تازه (`robots-console.txt`) و دو خط در `.htaccess`.
#
# 🔴 `.htaccess` **رونویسی نمی‌شود** — نسخهٔ سرور می‌تواند خط‌های تزریقیِ
# cPanel داشته باشد که در مخزن نیستند. جراحیِ نقطه‌ای + بکاپ + آزمونِ زندهٔ
# HTTP + بازگشتِ خودکار.
#
set -u

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-console-robots"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
DRY="${DRY:-0}"
SITE="https://servernet.cloud"
CONSOLE="https://console.servernet.cloud"

# ═══ اثباتِ مقصد پیش از هر نوشتنی ═══
# کاربرِ اشتباه ⇒ $HOME عوض می‌شود، فایل جایی می‌نشیند که سایت آن‌جا نیست، و
# گاردها **سبز** می‌شوند چون همان چیزی را می‌سنجند که خودشان ساخته‌اند.
if [ ! -f "$APP/artisan" ] || [ ! -d "$PUB" ]; then
  echo "🔴 مقصد ثابت نشد ($APP یا $PUB). کاربرِ درست: servernetcloud"
  exit 1
fi

mkdir -p "$WORK" "$BK"
cd "$WORK"
command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }

if [ -d repo/.git ]; then
  git -C repo fetch --depth 200 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 200 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

MINE="${1:-develop}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 \
  || MINE="origin/develop"
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
[ "$DRY" = "0" ] || echo "── حالتِ آزمایشی (DRY=1): هیچ فایلی نوشته نمی‌شود"

# ═══ وضعیتِ امروز، پیش از دست‌زدن ═══
echo
echo "═══ وضعیتِ فعلی ═══"
before_console=$(curl -s --max-time 20 "$CONSOLE/robots.txt" 2>/dev/null | grep -m1 -E '^(Allow|Disallow):' || echo '«خوانده نشد»')
before_main=$(curl -s --max-time 20 "$SITE/robots.txt" 2>/dev/null | grep -m1 -E '^(Allow|Disallow):' || echo '«خوانده نشد»')
echo "  کنسول:      $before_console"
echo "  سایتِ اصلی:  $before_main"

# ── ۱) فایلِ تازه ────────────────────────────────────────────────────────────
NEWFILE="$PUB/robots-console.txt"
git -C repo show "$MINE:website/public/robots-console.txt" > "$WORK/rc.raw" 2>/dev/null \
  || { echo "🔴 robots-console.txt در «$MINE» نیست — نسخهٔ هدف اشتباه است"; exit 1; }
tr -d '\r' < "$WORK/rc.raw" > "$WORK/rc.txt"

grep -qE '^\s*Disallow:\s*/\s*$' "$WORK/rc.txt" \
  || { echo "🔴 فایلِ مخزن «Disallow: /» ندارد — دیپلوی متوقف شد"; exit 1; }

echo
if [ -f "$NEWFILE" ] && cmp -s "$NEWFILE" "$WORK/rc.txt"; then
  echo "OK    robots-console.txt (از قبل درست است)"
else
  [ -f "$NEWFILE" ] && cp -p "$NEWFILE" "$BK/robots-console.txt.before"
  if [ "$DRY" = "0" ]; then
    cp "$WORK/rc.txt" "$NEWFILE"
    echo "NEW   robots-console.txt"
  else
    echo "PLAN  robots-console.txt نوشته می‌شود"
  fi
fi

# ── ۲) جراحیِ .htaccess ──────────────────────────────────────────────────────
HT="$PUB/.htaccess"
echo
echo "═══ .htaccess ═══"
if [ ! -f "$HT" ]; then
  echo "🔴 $HT نیست — بدونِ آن بازنویسی ممکن نیست."
  exit 1
elif grep -q 'robots-console.txt' "$HT"; then
  echo "OK    قاعده از قبل هست"
elif ! grep -q 'Redirect Trailing Slashes' "$HT"; then
  echo "⚠️ لنگرِ «Redirect Trailing Slashes» نیست — دست نخورد. دستی اضافه کن،"
  echo "   داخلِ <IfModule mod_rewrite.c> و پیش از فرانت‌کنترلر:"
  echo '     RewriteCond %{HTTP_HOST} ^console\. [NC]'
  echo '     RewriteRule ^robots\.txt$ robots-console.txt [L]'
elif [ "$DRY" != "0" ]; then
  echo "PLAN  دو خط پیش از «Redirect Trailing Slashes» درج می‌شود"
else
  cp -p "$HT" "$BK/htaccess.before"
  awk '
    /Redirect Trailing Slashes/ && !ins {
      print "    # کنسول robots.txt‌ِ خودش را می‌گیرد (همان public_html را سرو می‌کند)."
      print "    # بازنویسیِ داخلی، نه ۳۰۱: خزنده باید در همان آدرس پاسخ بگیرد."
      print "    RewriteCond %{HTTP_HOST} ^console\\. [NC]"
      print "    RewriteRule ^robots\\.txt$ robots-console.txt [L]"
      print ""
      ins = 1
    }
    { print }
  ' "$HT" > "$WORK/ht.new"

  # 🔴 «نوشتم» با «نشست» یکی نیست — اگر لنگر نگرفت، موفقیتِ دروغ چاپ نکن.
  if ! grep -q 'robots-console.txt' "$WORK/ht.new"; then
    echo "🔴 درج انجام نشد (لنگر نگرفت) — .htaccess دست‌نخورده مانْد."
  else
    cp "$WORK/ht.new" "$HT"
    echo "UP    .htaccess (دو خط اضافه شد)"
  fi
fi

if [ "$DRY" != "0" ]; then
  echo
  echo "═══ حالتِ آزمایشی — هیچ فایلی نوشته نشد ═══"
  exit 0
fi

# ── ۳) راستی‌آزماییِ زنده و بازگشتِ خودکار ────────────────────────────────────
#
# 🔴 دو ادعا، و **دومی مهم‌تر است**: بدترین حالتِ ممکن این است که شرطِ میزبان
#    اشتباه بگیرد و سایتِ اصلی هم `Disallow: /` بگیرد — یعنی کلِ سایت از گوگل
#    بیرون برود. آن حالت باید فوراً برگردانده شود، نه گزارش.
echo
echo "═══ راستی‌آزماییِ زنده ═══"
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE/" 2>/dev/null)
main_now=$(curl -s --max-time 20 "$SITE/robots.txt" 2>/dev/null)
console_now=$(curl -s --max-time 20 "$CONSOLE/robots.txt" 2>/dev/null)

bad=0
case "${code:-0}" in 2*|3*) ;; *) echo "🔴 سایت «$code» می‌دهد"; bad=1 ;; esac

if ! printf '%s' "$main_now" | grep -qE '^Allow:[[:space:]]*/[[:space:]]*$'; then
  echo "🔴 سایتِ اصلی دیگر «Allow: /» ندارد"; bad=1
fi
if printf '%s' "$main_now" | grep -qE '^Disallow:[[:space:]]*/[[:space:]]*$'; then
  echo "🔴🔴 سایتِ اصلی «Disallow: /» گرفته — کلِ سایت از گوگل بیرون می‌رفت"; bad=1
fi

if [ "$bad" -ne 0 ]; then
  [ -f "$BK/htaccess.before" ] && cp "$BK/htaccess.before" "$HT" && echo "   .htaccess برگشت."
  rm -f "$NEWFILE" && echo "   robots-console.txt حذف شد."
  echo "🔴 دیپلوی برگشت خورد. خروجیِ بالا را بفرست."
  exit 1
fi

echo "✅ سایتِ اصلی سالم: $(printf '%s' "$main_now" | grep -m1 -E '^(Allow|Disallow):')"

if printf '%s' "$console_now" | grep -qE '^Disallow:[[:space:]]*/[[:space:]]*$'; then
  echo "✅ کنسول حالا کاملاً Disallow است — ۱۰٪ بودجهٔ خزش آزاد شد"
else
  echo "⚠️ کنسول هنوز فایلِ تازه را نمی‌دهد:"
  printf '   %s\n' "$(printf '%s' "$console_now" | grep -m1 -E '^(Allow|Disallow):' || echo '«هیچ قاعده‌ای برنگشت»')"
  echo "   سایت سالم است و چیزی برنگشت. علتِ محتمل: کنسول docrootِ جدا دارد،"
  echo "   یا جلوی آپاچی یک لایهٔ کش/پروکسی نشسته. اول این را بزن:"
  echo "     curl -sI $CONSOLE/robots.txt | grep -i '^server:'"
  echo "   اگر «Apache» نبود، آن لایه فایل را سرو می‌کند نه ما."
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK"
echo
echo "⚠️ کارِ باقی‌مانده (بیرون از این سرور، در بخشِ ۰۵ گزارش):"
echo "  · bpms.servernet.cloud/robots.txt الان ۳۰۲ به /login می‌دهد ⇒ گوگل"
echo "    «robots.txt در دسترس نیست» ثبت می‌کند و خزشِ آن میزبان را محدود می‌کند."
echo "  · remote.servernet.cloud/robots.txt یک صفحهٔ HTML برمی‌گرداند."
echo "  · flow.servernet.cloud/robots.txt پاسخِ ۲۰۴ِ خالی می‌دهد."
echo "  هر سه روی openresty اند، نه این سرور — یک فایلِ استاتیکِ دوخطی لازم دارند."
