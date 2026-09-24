#!/usr/bin/env bash
#
# دیپلوی «عنوان و توضیحِ جست‌وجو برای ۷ صفحهٔ پرنمایشِ کم‌کلیک» — ۲۴ سپتامبر ۲۰۲۶.
#
#   DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/feature/seo-meta-lift/scripts/deploy-seo-meta-lift.sh)
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/feature/seo-meta-lift/scripts/deploy-seo-meta-lift.sh) [<SHA>]
#
# چه چیزی (۴ فایلِ config، بی‌مهاجرت، بی‌فایلِ تازه):
#   · config/hosting.php          — windows · linux · download · reseller-linux
#   · config/catalog/vps.php      — russia
#   · config/catalog/cloud.php    — cdn
#   · config/catalog/dedicated.php— france
#
# دادهٔ GSC (سه ماه): /hosting/windows ۶۷۶ نمایش/۰ کلیک · /vps/linux ۶۴۸/۰ ·
# /dedicated/france ۴۳۵/۰ · /hosting/download ۸۴۴/۱ · /cloud/cdn ۴۰۸/۰.
#
# ⚠️ فقط `seo_t`/`seo_d` اضافه می‌شود؛ هیچ قیمتی، پلنی و ادعایی عوض نمی‌شود.
#    گاردهای پایین ثابت می‌کنند کارِ قبلیِ روی سرور (عنوانِ هاست پایتون) هم مانده.
set -u

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-seo-meta-lift"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=80
DRY="${DRY:-0}"
BRANCH="feature/seo-meta-lift"

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

MINE="${1:-d5d390d1}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 \
  || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
[ "$DRY" = "0" ] || echo "── حالتِ آزمایشی (DRY=1): هیچ فایلی نوشته نمی‌شود"
echo "── بکاپ در: $BK"
echo

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
[ -n "$PHPBIN" ] || { echo "FATAL: php پیدا نشد"; exit 1; }

CONFLICTS=""; UPD=0; LINT_FAIL=""

backup_of() {
  [ -f "$APP/$1" ] || return 0
  mkdir -p "$BK/$(dirname "$1")"
  cp -p "$APP/$1" "$BK/$1"
}

lint_or_restore() {
  "$PHPBIN" -l "$APP/$1" >/dev/null 2>&1 && return 0
  echo "      🔴 خطای نحوی بعد از نوشتن — برگردانده شد: $1"
  if [ -f "$BK/$1" ]; then cp -p "$BK/$1" "$APP/$1"; else rm -f "$APP/$1"; fi
  LINT_FAIL="$LINT_FAIL $1"
  return 1
}

norm() { tr -d '\r' < "$1" > "$2"; }
same() { norm "$1" "$WORK/n1.tmp"; norm "$2" "$WORK/n2.tmp"; cmp -s "$WORK/n1.tmp" "$WORK/n2.tmp"; }
dist() { norm "$1" "$WORK/d1.tmp"; norm "$2" "$WORK/d2.tmp"; diff "$WORK/d1.tmp" "$WORK/d2.tmp" 2>/dev/null | grep -c '^[<>]'; }

MERGE_FILES="
config/hosting.php
config/catalog/vps.php
config/catalog/cloud.php
config/catalog/dedicated.php
"

echo "═══ ۱) فایل‌ها (merge سه‌طرفه) ═══"
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
echo "═══ ۲) کش‌ها ═══"
(cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan view:clear) || true
# بی‌purge، عنوانِ قدیمی تا TTL سرو می‌شود (و امروز کشِ صفحه خاموش است — بخشِ ۵)
(cd "$APP" && "$PHPBIN" artisan tinker --execute='\App\Http\Middleware\PageCache::purge(); echo "pagecache purged\n";') \
  || echo "  ⚠️ purgeِ کشِ صفحه انجام نشد"

echo
echo "═══ ۳) ضمانتِ اتحاد ═══"
union_ok=1
need_grep() { grep -qF -- "$2" "$APP/$1" 2>/dev/null || { echo "🔴 «$2» در $1 نیست"; union_ok=0; }; }

need_grep config/hosting.php           'خرید هاست ویندوز'
need_grep config/hosting.php           'خرید هاست لینوکس'
need_grep config/hosting.php           'خرید نمایندگی هاست لینوکس'
need_grep config/hosting.php           'هاست دانلود و میزبانی فایل حجیم'
need_grep config/catalog/vps.php       'خرید سرور مجازی روسیه'
need_grep config/catalog/cloud.php     'CDN ایران و جهانی'
need_grep config/catalog/dedicated.php 'خرید سرور اختصاصی فرانسه'
# ⚠️ کارِ قبلیِ روی سرور که merge نباید برش دارد
need_grep config/hosting.php           'خرید هاست پایتون با SSH'
need_grep config/catalog/vps.php       'خرید سرور مجازی خارج'
need_grep config/catalog/dedicated.php 'خرید سرور برمتال فنلاند'

[ "$union_ok" -eq 0 ] && echo "🔴 اتحاد ناقص — گزارشِ بالا را بفرست."

echo
echo "═══ ۴) راستی‌آزماییِ زنده ═══"
BAD=0
check() {
  c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$1?qa=$STAMP")
  case " $2 " in *" $c "*) echo "  ✅ $c  $1" ;; *) echo "  🔴 $c  $1  (انتظار: $2)"; BAD=1 ;; esac
}
check "https://servernet.cloud/"                    "200"
check "https://servernet.cloud/hosting/windows"     "200"
check "https://servernet.cloud/hosting/linux"       "200"
check "https://servernet.cloud/vps/russia"          "200"
check "https://servernet.cloud/cloud/cdn"           "200"
check "https://servernet.cloud/dedicated/france"    "200"
check "https://servernet.cloud/sitemap.xml"         "200"

if [ "$BAD" -eq 1 ] || [ "$union_ok" -eq 0 ]; then
  echo
  echo "🔴🔴 سایت سالم برنگشت — کلِ بکاپ برگردانده می‌شود …"
  (cd "$BK" && find . -type f | while read -r f; do cp -p "$f" "$APP/${f#./}"; done)
  (cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan view:clear)
  echo "↩️ برگشت انجام شد. گزارشِ بالا را بفرست."
  exit 1
fi

echo
echo "═══ ۵) عنوان‌ها روی سایتِ زنده (گزارشی — تا ریستِ opcache ممکن است قدیمی باشد) ═══"
t() {
  got=$(curl -s --max-time 25 "$1?qa=$STAMP" | grep -o '<title>[^<]*</title>' | head -1)
  case "$got" in *"$2"*) echo "  ✅ $1" ;; *) echo "  ⚠️ $1 — هنوز: $got" ;; esac
}
t "https://servernet.cloud/hosting/windows"  'خرید هاست ویندوز'
t "https://servernet.cloud/hosting/linux"    'خرید هاست لینوکس'
t "https://servernet.cloud/vps/russia"       'خرید سرور مجازی روسیه'
t "https://servernet.cloud/dedicated/france" 'خرید سرور اختصاصی فرانسه'

echo
echo "═══ ۶) کشِ صفحه (تشخیص — ربطی به این دیپلوی ندارد) ═══"
XC=$(curl -sI --max-time 25 "https://servernet.cloud/" | grep -i '^x-cache' | tr -d '\r')
echo "  $XC"
case "$XC" in
  *BYPASS*)
    echo "  ⚠️ کشِ کاملِ صفحه خاموش است. در .env دنبالِ PAGE_CACHE بگرد:"
    echo "       grep -n '^PAGE_CACHE' ~/servernet_app/.env"
    echo "       grep -oP '^[A-Z0-9_]+(?==)' ~/servernet_app/.env | sort | uniq -d   # کلیدِ تکراری"
    ;;
esac

echo
echo "═══ خلاصه ═══"
echo "  به‌روزشده: $UPD فایل"
[ -n "$CONFLICTS" ] && echo "  🔴 تداخل:$CONFLICTS   (نسخه‌ها در $WORK/conflicts)"
[ -n "$LINT_FAIL" ] && echo "  🔴 خطای نحوی:$LINT_FAIL"
echo "  بکاپ: $BK"
echo
echo "  کارِ باقی‌مانده: ریستِ opcache از /system/opcache، و بعد بخشِ ۵ را دوباره نگاه کنید."
