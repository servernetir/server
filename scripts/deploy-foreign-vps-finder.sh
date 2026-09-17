#!/usr/bin/env bash
#
# دیپلوی «کدام کشور برای سرور خارج؟» + خوشهٔ ۱۲ مقالهٔ سرور خارج — ۱۷ سپتامبر ۲۰۲۶.
#
#   DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/feature/foreign-vps-finder/scripts/deploy-foreign-vps-finder.sh)
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/feature/foreign-vps-finder/scripts/deploy-foreign-vps-finder.sh) [<SHA>]
#
# چه چیزی (بی‌مهاجرت):
#   فایل‌های تازه (فقط اگر روی سرور نباشند ساخته می‌شوند):
#     · app/Http/Controllers/ForeignVpsFinderController.php
#     · config/foreign_vps.php
#     · resources/views/pages/vps-compare.blade.php
#   فایل‌های موجود (merge سه‌طرفه):
#     · routes/web.php                    — روتِ /vps/compare-locations
#     · app/Http/Controllers/SiteController.php — sitemap + llms.txt
#     · config/servernet.php              — لینکِ منو
#     · resources/content/blog-1405.php   — خوشهٔ ۳۲ (۱۲ موضوعِ تاریخ‌دار)
#
# ⚠️ ترتیب مهم است: فایل‌های تازه **پیش از** routes نوشته می‌شوند؛ روتی که به
#    کنترلرِ نبود اشاره کند فقط همان صفحه را ۵۰۰ می‌کند، ولی ترتیبِ درست یعنی
#    حتی آن لحظه هم وجود ندارد.
set -u

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-foreign-vps-finder"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=80
DRY="${DRY:-0}"
BRANCH="feature/foreign-vps-finder"

if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ] || [ ! -f "$PUB/index.php" ]; then
  echo "🔴 مقصد ثابت نشد ($APP یا $PUB). کاربرِ درست: servernetcloud"
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

if [ -d repo/.git ]; then
  git -C repo fetch --depth 400 origin develop "$BRANCH" || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 400 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
  git -C repo fetch --depth 400 origin "$BRANCH" || { echo "FATAL: fetch $BRANCH"; exit 1; }
fi

MINE="${1:-368c203c}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 \
  || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
[ "$DRY" = "0" ] || echo "── حالتِ آزمایشی (DRY=1): هیچ فایلی نوشته نمی‌شود"
echo "── بکاپ در: $BK"
echo

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
[ -n "$PHPBIN" ] || { echo "FATAL: php پیدا نشد"; exit 1; }

CONFLICTS=""; UPD=0; LINT_FAIL=""; CREATED=""

backup_of() {
  [ -f "$APP/$1" ] || return 0
  mkdir -p "$BK/$(dirname "$1")"
  cp -p "$APP/$1" "$BK/$1"
}

# 🔴 `php -l` روی Blade بی‌اثر است — Blade باید کامپایل شود.
cat > "$WORK/bladecheck.php" <<'PHPCHK'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$tmp = sys_get_temp_dir().'/bladechk_'.getmypid().'.php';
file_put_contents($tmp, Illuminate\Support\Facades\Blade::compileString(file_get_contents($argv[2])));
exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1', $o, $rc);
unlink($tmp);
exit($rc);
PHPCHK

lint_or_restore() {
  case "$1" in
    *.blade.php) "$PHPBIN" "$WORK/bladecheck.php" "$APP" "$APP/$1" >/dev/null 2>&1 && return 0 ;;
    *.php)       "$PHPBIN" -l "$APP/$1" >/dev/null 2>&1 && return 0 ;;
    *)           return 0 ;;
  esac
  echo "      🔴 خطای نحوی بعد از نوشتن — برگردانده شد: $1"
  if [ -f "$BK/$1" ]; then cp -p "$BK/$1" "$APP/$1"; else rm -f "$APP/$1"; fi
  LINT_FAIL="$LINT_FAIL $1"
  return 1
}

norm() { tr -d '\r' < "$1" > "$2"; }
same() { norm "$1" "$WORK/n1.tmp"; norm "$2" "$WORK/n2.tmp"; cmp -s "$WORK/n1.tmp" "$WORK/n2.tmp"; }
dist() { norm "$1" "$WORK/d1.tmp"; norm "$2" "$WORK/d2.tmp"; diff "$WORK/d1.tmp" "$WORK/d2.tmp" 2>/dev/null | grep -c '^[<>]'; }

NEW_FILES="
app/Http/Controllers/ForeignVpsFinderController.php
config/foreign_vps.php
resources/views/pages/vps-compare.blade.php
"

MERGE_FILES="
app/Http/Controllers/SiteController.php
config/servernet.php
resources/content/blog-1405.php
routes/web.php
"

echo "═══ ۱) فایل‌های تازه ═══"
for rel in $NEW_FILES; do
  dest="$APP/$rel"
  git -C repo show "$MINE:website/$rel" > "$WORK/mine.raw" 2>/dev/null \
    || { echo "🔴 در $MINE نیست: $rel"; CONFLICTS="$CONFLICTS $rel"; continue; }
  norm "$WORK/mine.raw" "$WORK/mine.tmp"

  if [ -f "$dest" ]; then
    if same "$dest" "$WORK/mine.tmp"; then echo "OK    $rel"; continue; fi
    # 🔴 فایلِ «تازه» که از قبل با محتوای دیگری هست = کارِ کسِ دیگر؛ رونویسی نمی‌شود
    echo "CF    $rel   ← از قبل روی سرور هست و متفاوت است — دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$WORK/mine.tmp" "$keep.new"
    continue
  fi

  if [ "$DRY" = "0" ]; then
    mkdir -p "$(dirname "$dest")"
    cp "$WORK/mine.tmp" "$dest"
    lint_or_restore "$rel" && { echo "NEW   $rel"; UPD=$((UPD+1)); CREATED="$CREATED $rel"; }
  else
    echo "PLAN  NEW $rel"; UPD=$((UPD+1))
  fi
done

echo
echo "═══ ۲) فایل‌های موجود (merge سه‌طرفه) ═══"
for rel in $MERGE_FILES; do
  dest="$APP/$rel"
  mine_f="$WORK/mine.tmp"; base_f="$WORK/base.tmp"

  git -C repo show "$MINE:website/$rel" > "$WORK/mine.raw" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; continue; }
  norm "$WORK/mine.raw" "$mine_f"

  if [ ! -f "$dest" ]; then
    echo "CF    $rel   ← روی سرور نیست — دست نخورد"
    CONFLICTS="$CONFLICTS $rel"; continue
  fi

  [ "$DRY" = "0" ] && backup_of "$rel"
  if same "$dest" "$mine_f"; then echo "OK    $rel"; continue; fi

  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$WORK/cand.tmp" 2>/dev/null || continue
    if same "$dest" "$WORK/cand.tmp"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$dest" "$WORK/cand.tmp")
    if [ "$d" -lt "$bestd" ]; then bestd=$d; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "CF    $rel   ← در تاریخچه نیست؛ نسخهٔ ناشناخته روی سرور — دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"
    continue
  fi

  if [ "$bestd" -eq 0 ]; then
    if [ "$DRY" = "0" ]; then
      cp "$mine_f" "$dest"
      lint_or_restore "$rel" && { echo "UP    $rel   (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1)); }
    else
      echo "PLAN  UP $rel   (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1))
    fi
    continue
  fi

  git -C repo show "$best:website/$rel" > "$base_f"
  m="$WORK/merged.tmp"; norm "$dest" "$m"; norm "$base_f" "$WORK/base_n.tmp"
  if git merge-file -L server -L base -L new "$m" "$WORK/base_n.tmp" "$mine_f" >/dev/null 2>&1; then
    if [ "$DRY" = "0" ]; then
      cp "$m" "$dest"
      lint_or_restore "$rel" && { echo "MG    $rel   (پایه $(git -C repo rev-parse --short "$best")، فاصلهٔ سرور $bestd خط — تغییرِ دیگران حفظ شد)"; UPD=$((UPD+1)); }
    else
      echo "PLAN  MG $rel   (پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط)"; UPD=$((UPD+1))
    fi
  else
    echo "CF    $rel   ← تداخل واقعی؛ دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
done

if [ "$DRY" != "0" ]; then
  echo
  echo "═══ حالتِ آزمایشی — هیچ فایلی نوشته نشد ═══"
  echo "  برنامه: $UPD فایل"
  [ -n "$CONFLICTS" ] && { echo "  🔴 تداخل:$CONFLICTS — پیش از دیپلویِ واقعی حل شود"; exit 1; }
  echo "  ✅ هیچ تداخلی نیست — همین فرمان را بدونِ DRY=1 بزنید."
  exit 0
fi

echo
echo "═══ ۳) کش‌ها ═══"
(cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan view:clear) || true
(cd "$APP" && "$PHPBIN" artisan tinker --execute='\App\Http\Middleware\PageCache::purge(); echo "pagecache purged\n";') \
  || echo "  ⚠️ purgeِ کشِ صفحه انجام نشد — صبر تا TTL (منوی تازه تا آن موقع دیده نمی‌شود)"

echo
echo "═══ ۴) ضمانتِ اتحاد ═══"
union_ok=1
need_grep() { grep -qF -- "$2" "$APP/$1" 2>/dev/null || { echo "🔴 «$2» در $1 نیست"; union_ok=0; }; }

need_grep routes/web.php                                  "->name('vps.compare')"
need_grep app/Http/Controllers/SiteController.php         "\$add('vps.compare');"
need_grep app/Http/Controllers/SiteController.php         "'/vps/compare-locations' =>"
need_grep config/servernet.php                            "['vps.compare', []]"
need_grep resources/content/blog-1405.php                 "'foreign-vps-location-guide'"
need_grep resources/content/blog-1405.php                 "'migrate-site-to-foreign-vps'"
need_grep app/Http/Controllers/ForeignVpsFinderController.php 'class ForeignVpsFinderController'
need_grep config/foreign_vps.php                          "'countries' =>"
need_grep resources/views/pages/vps-compare.blade.php     'id="vc-quiz"'
# ⚠️ کارِ قبلی که merge نباید بخورد
need_grep routes/web.php                                  "->name('vps.hourly')"
need_grep routes/web.php                                  "->name('gpu')"
need_grep app/Http/Controllers/SiteController.php         "\$add('gpu');"
need_grep config/servernet.php                            "['vps.hourly', []]"
need_grep resources/content/blog-1405.php                 "'post-holiday-restart-checklist'"

[ "$union_ok" -eq 0 ] && echo "🔴 اتحاد ناقص — گزارشِ بالا را بفرست."

echo
echo "═══ ۵) راستی‌آزماییِ زنده ═══"
BAD=0
check() {
  c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$1?qa=$STAMP")
  case " $2 " in *" $c "*) echo "  ✅ $c  $1" ;; *) echo "  🔴 $c  $1  (انتظار: $2)"; BAD=1 ;; esac
}
check "https://servernet.cloud/"                     "200"
check "https://servernet.cloud/vps/international"    "200"
check "https://servernet.cloud/vps/hourly"           "200"
check "https://servernet.cloud/blog"                 "200"
check "https://servernet.cloud/sitemap.xml"          "200"
check "https://console.servernet.cloud/login"        "200"

if [ "$BAD" -eq 1 ] || [ "$union_ok" -eq 0 ]; then
  echo
  echo "🔴🔴 سایت سالم برنگشت — کلِ بکاپ برگردانده و فایل‌های تازه حذف می‌شوند …"
  (cd "$BK" && find . -type f | while read -r f; do cp -p "$f" "$APP/${f#./}"; done)
  for rel in $CREATED; do rm -f "$APP/$rel"; done
  (cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan view:clear)
  echo "↩️ برگشت انجام شد. گزارشِ بالا را بفرست."
  exit 1
fi

echo
echo "═══ ۶) صفحهٔ تازه (فقط گزارشی — تا ریستِ opcache ممکن است ۴۰۴ بدهد) ═══"
for p in /vps/compare-locations /en/vps/compare-locations /tr/vps/compare-locations; do
  c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "https://servernet.cloud$p?qa=$STAMP")
  echo "  $([ "$c" = 200 ] && echo ✅ || echo ⚠️) $c  $p"
done

echo
echo "═══ خلاصه ═══"
echo "  به‌روز/ساخته‌شده: $UPD فایل"
[ -n "$CONFLICTS" ] && echo "  🔴 تداخل:$CONFLICTS   (نسخه‌ها در $WORK/conflicts)"
[ -n "$LINT_FAIL" ] && echo "  🔴 خطای نحوی:$LINT_FAIL"
echo "  بکاپ: $BK"
echo
echo "  کارِ باقی‌مانده: ریستِ opcache از /system/opcache، و بعد بخشِ ۶ را دوباره نگاه کنید."
