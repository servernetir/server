#!/usr/bin/env bash
#
# دیپلوی «جور کردن صفحهٔ هاست پایتون با آنچه سرور واقعاً می‌دهد» روی cPanel.
#
# اجرا از ترمینالِ cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-python-page-truth.sh) [<SHA>]
#
# ⚠️ به SHA پین است، نه نوکِ develop — روی develop کارِ دیپلوی‌نشدهٔ همکاران
#    می‌نشیند و «هرچه فرق دارد را بفرست» کرون را می‌شکند.
#
# فقط **یک فایل config** است: نه مهاجرت، نه روت، نه فایل استاتیک، نه blade.
# منطق merge سه‌طرفه عیناً از scripts/deploy-urmia-i18n.sh.
#
# چه چیزی را درست می‌کند (بررسی مستقیم WHM سرور core، ۷ شهریور ۱۴۰۵):
#   PostgreSQL/Redis نصب نیستند · ورکر دائم اجرا نمی‌شود ·
#   اپ با Passenger بالا می‌آید نه Gunicorn/Uvicorn · فقط python3.11/3.12
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-python-page-truth"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=60

# 🔴 گاردِ محل — قبل از ساختنِ هر چیزی.
#
# ۷ شهریور ۱۴۰۵ این اسکریپت با کاربر root روی سرورِ WHM ایران اجرا شد. آنجا
# $HOME=/root بود، پس APP شد /root/servernet_app که وجود نداشت و اسکریپت
# با خوش‌رویی «NEW config/hosting.php» را از صفر ساخت، گاردِ محتوا هم روی
# همان فایلِ تازه‌ساخته سبز شد، و سایتِ زنده اصلاً دست نخورد.
#
# درسش: گاردی که فقط محتوا را می‌سنجد، «سرورِ اشتباه» را نمی‌بیند — چون
# محتوا دقیقاً همانی است که خودت نوشتی. مقصد باید *قبلش* اثبات شود.
if [ ! -f "$APP/artisan" ] || [ ! -f "$APP/config/hosting.php" ]; then
  echo "🔴 FATAL: در \"$APP\" اپِ لاراول پیدا نشد (artisan و config/hosting.php لازم است)."
  echo
  echo "   کاربر فعلی: $(id -un)   ·   HOME: $HOME"
  echo
  echo "   این اسکریپت باید با کاربرِ cPanelِ اکانتِ سایت اجرا شود، نه با root و"
  echo "   نه روی سرورِ WHM. از ترمینالِ خودِ cPanel اجرایش کن، یا اگر root هستی:"
  echo "       su - servernetcloud -c 'bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-python-page-truth.sh)'"
  echo
  echo "   هیچ فایلی ساخته یا تغییر داده نشد."
  exit 1
fi

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }

if [ -d repo/.git ]; then
  git -C repo fetch --depth 400 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 400 --branch develop https://github.com/servernetir/server.git repo     || { echo "FATAL: clone"; exit 1; }
fi

MINE="${1:-a08ce966b3303ee872cac95ee650e0bf7b3dded7}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

APP_FILES="
config/hosting.php
"

CONFLICTS=""
UPD=0

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

apply_one() {
  rel="$1"; dest="$APP/$rel"
  mine_f="$WORK/mine.tmp"; base_f="$WORK/base.tmp"

  git -C repo show "$MINE:website/$rel" > "$mine_f" 2>/dev/null     || { echo "SKIP  (در $MINE نیست)  $rel"; return; }

  if [ -f "$dest" ]; then
    mkdir -p "$BK/$(dirname "$rel")"
    cp -p "$dest" "$BK/$rel"
  fi

  if [ ! -f "$dest" ]; then
    mkdir -p "$(dirname "$dest")"
    cp "$mine_f" "$dest"; echo "NEW   $rel"; UPD=$((UPD+1)); return
  fi

  if cmp -s "$dest" "$mine_f"; then echo "OK    $rel"; return; fi

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

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)

# ── گاردِ سلامت ─────────────────────────────────────────────────────────
# 🔴 config/hosting.php کلِ کاتالوگ سایت است؛ اگر خرابش کنیم هر ۱۳۴ صفحه
#    ۵۰۰ می‌شود. پس قبل از پاک کردنِ کش، هم سینتکس و هم محتوا را می‌سنجیم
#    و در صورت شک از بکاپ برمی‌گردانیم.
ok=1
H="$APP/config/hosting.php"

if [ -n "$PHPBIN" ]; then
  "$PHPBIN" -l "$H" >/dev/null 2>&1 || { echo "🔴 سینتکس PHP خراب است"; ok=0; }
fi
grep -q "MySQL / MariaDB"            "$H" || { echo "🔴 تغییرِ MySQL/MariaDB ننشسته"; ok=0; }
grep -q "a2wsgi"                     "$H" || { echo "🔴 FAQی ASGI ننشسته"; ok=0; }
grep -q "'python' =>"                "$H" || { echo "🔴 بلاکِ python گم شده"; ok=0; }
grep -q "'linux' =>"                 "$H" || { echo "🔴 بلاکِ linux گم شده — فایل ناقص است"; ok=0; }
grep -qi "PostgreSQL + MySQL"        "$H" && { echo "🔴 ادعای PostgreSQL هنوز هست"; ok=0; }
grep -qi "gunicorn"                  "$H" && { echo "🔴 ادعای gunicorn هنوز هست"; ok=0; }

if [ "$ok" -eq 0 ]; then
  echo "🔴 گارد رد شد — config/hosting.php از بکاپ برمی‌گردد تا سایت نیفتد."
  [ -f "$BK/config/hosting.php" ] && cp "$BK/config/hosting.php" "$H" && echo "   برگشت انجام شد"
  exit 1
fi
echo "✅ گارد پاس شد"

# ── کش‌ها ───────────────────────────────────────────────────────────────
if [ -n "$PHPBIN" ]; then
  cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan view:clear
else
  rm -f "$APP/bootstrap/cache/config.php"
  echo "WARN: php پیدا نشد — کشِ config دستی پاک شد"
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · فایل‌های به‌روزشده: $UPD"
if [ -n "$CONFLICTS" ]; then
  echo "🔴 تداخل (دست‌نخورده):$CONFLICTS — نسخه‌ها در $WORK/conflicts/"
else
  echo "✅ هیچ تداخلی نبود"
fi
echo
echo "کارِ باقی‌مانده: ریستِ opcache (validate_timestamps=0 — بی‌ریست کدِ تازه اجرا نمی‌شود)"
echo
echo "راستی‌آزمایی (۲۰۰ کافی نیست — محتوا باید عوض شده باشد):"
echo "  curl -sI https://servernet.cloud/hosting/python?qa=1 | head -1                      ← 200"
echo "  curl -s  'https://servernet.cloud/hosting/python?qa=1' | grep -c PostgreSQL         ← 0"
echo "  curl -s  'https://servernet.cloud/hosting/python?qa=1' | grep -c 'MySQL / MariaDB'  ← بزرگ‌تر از ۰"
echo "  curl -s  'https://servernet.cloud/hosting/python?qa=1' | grep -o 'a2wsgi'           ← a2wsgi"
echo "  curl -sI https://servernet.cloud/hosting/linux?qa=1  | head -1                      ← 200 (بقیهٔ کاتالوگ سالم)"
