#!/usr/bin/env bash
#
# دیپلوی صفحهٔ «نشان سرورنت» (/badge) — موتور لینک‌سازی مشتری‌ها.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-badge.sh)
#
# همان الگوی اثبات‌شده: بکاپ کامل، پایهٔ خودکار هر فایل (نزدیک‌ترین نسخهٔ
# تاریخی)، merge سه‌طرفه برای فایل دست‌خورده، تداخل ⇒ دست‌نخورده + گزارش،
# ensure-union پیش از پاک‌کردن کش، راستی‌آزمایی HTTP در پایان.
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-check"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-badge-$STAMP"
HIST=80

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }

if [ -d repo/.git ]; then
  git -C repo fetch --depth 500 origin develop >/dev/null 2>&1 || { echo "FATAL: fetch"; exit 1; }
else
  git clone --quiet --depth 500 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

# پین به کامیتِ خودِ این کار؛ نوکِ develop ممکن است کارِ دیپلوی‌نشدهٔ دیگران را داشته باشد.
MINE="${1:-2562539}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# ترتیب: اول ویو و زبان‌ها، بعد فوتر/سایت‌مپ، آخر routes (که به ویو اشاره دارد).
APP_FILES="
resources/views/pages/badge.blade.php
lang/fa/ui.php
lang/en/ui.php
lang/tr/ui.php
resources/views/partials/footer.blade.php
app/Http/Controllers/SiteController.php
routes/web.php
"

CONFLICTS=""
UPD=0

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

apply_one() {
  rel="$1"
  dest="$APP/$rel"
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

  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$WORK/cand.tmp" 2>/dev/null || continue
    if cmp -s "$dest" "$WORK/cand.tmp"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$dest" "$WORK/cand.tmp")
    if [ "$d" -lt "$bestd" ]; then bestd=$d; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "CF    $rel   ← در تاریخچه نیست؛ نسخهٔ ناشناخته روی سرور — دست نخورد"
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
    cp "$m" "$dest"; echo "MG    $rel   (پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط — تغییرِ دیگران حفظ شد)"; UPD=$((UPD+1))
  else
    echo "CF    $rel   ← تداخل واقعی؛ دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
}

echo "── بکاپ در: $BK"
for f in $APP_FILES; do apply_one "$f"; done

# ── ضمانتِ اتحاد ─────────────────────────────────────────────────────────
union_ok=1
grep -q "name('badge')" "$APP/routes/web.php" 2>/dev/null || { echo "🔴 روتِ badge ننشست"; union_ok=0; }
[ -f "$APP/resources/views/pages/badge.blade.php" ] || { echo "🔴 ویوِ badge نیست"; union_ok=0; }
for l in fa en tr; do
  grep -q "'bdg_meta_t'" "$APP/lang/$l/ui.php" 2>/dev/null || { echo "🔴 lang/$l کلیدهای bdg_* را ندارد"; union_ok=0; }
done
grep -q "lroute('badge')" "$APP/resources/views/partials/footer.blade.php" 2>/dev/null || echo "⚠️ لینکِ فوتر ننشست (بحرانی نیست)"
# روت‌های حیاتی فوتر/هدر نباید بیفتند (درسِ ۲۸ مرداد)
for r in "name('aup')" "name('vps.hourly')" "name('official')" "name('urmia.hub')" "name('speed')" "name('status')" "name('sla')"; do
  grep -qF "$r" "$APP/routes/web.php" || { echo "🔴 routes/web.php: $r گم شده"; union_ok=0; }
done

if [ "$union_ok" -eq 0 ]; then
  echo "🔴 اتحاد کامل نیست — routes از بکاپ برمی‌گردد تا سایت ۵۰۰ نشود."
  [ -f "$BK/routes/web.php" ] && cp "$BK/routes/web.php" "$APP/routes/web.php" && echo "   routes/web.php از بکاپ برگشت"
  [ -f "$BK/resources/views/partials/footer.blade.php" ] && cp "$BK/resources/views/partials/footer.blade.php" "$APP/resources/views/partials/footer.blade.php" && echo "   footer از بکاپ برگشت"
  exit 1
fi

# ── کش‌ها ────────────────────────────────────────────────────────────────
PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
if [ -n "$PHPBIN" ]; then
  cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear && "$PHPBIN" artisan cache:clear 2>/dev/null
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
fi

echo
echo "══════════ راستی‌آزمایی HTTP ══════════"
sleep 2
for u in /badge /en/badge /tr/badge / /vps/hourly; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "https://servernet.cloud$u")
  echo "  $code  $u"
done
echo "  عنوان: $(curl -s https://servernet.cloud/badge | grep -o '<title>[^<]*' | head -1)"
echo "  لینکِ فوتر: $(curl -s https://servernet.cloud/ | grep -c '/badge') بار"

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · فایل‌های به‌روزشده: $UPD"
if [ -n "$CONFLICTS" ]; then
  echo "🔴 تداخل‌ها (دست‌نخورده):$CONFLICTS — نسخه‌ها در $WORK/conflicts/"
else
  echo "✅ هیچ تداخلی نبود"
fi
echo "این خروجی را کامل کپی کن و برگردان."
