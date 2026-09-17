#!/usr/bin/env bash
#
# دیپلوی «returnMethod در سیاستِ بازگشت» — ۱۶ سپتامبر ۲۰۲۶.
#
#   DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/feature/return-method/scripts/deploy-return-method.sh)
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/feature/return-method/scripts/deploy-return-method.sh) [<SHA>]
#
# چه چیزی (۲ فایل، بی‌مهاجرت): Search Console روی هر ۶۹ آیتمِ Merchant listings
# «Missing field returnMethod» می‌داد. helpers.php یک منبعِ واحد
# (schema_return_policy) می‌سازد و OrderSummaryController هم از همان می‌خوانَد.
#
# منطقِ merge عیناً از scripts/deploy-seo-growth-sep.sh (UP/MG/CF + بکاپ +
# بازگشتِ خودکار). ⚠️ تلهٔ CRLF: پیش از هر مقایسه normalize.
set -u

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-return-method"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=80
DRY="${DRY:-0}"
BRANCH="feature/return-method"

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

MINE="${1:-8ce174a6}"
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
  echo "      🔴 خطای نحوی بعد از نوشتن — از بکاپ برگردانده شد: $1"
  if [ -f "$BK/$1" ]; then cp -p "$BK/$1" "$APP/$1"; else rm -f "$APP/$1"; fi
  LINT_FAIL="$LINT_FAIL $1"
  return 1
}

norm() { tr -d '\r' < "$1" > "$2"; }
same() { norm "$1" "$WORK/n1.tmp"; norm "$2" "$WORK/n2.tmp"; cmp -s "$WORK/n1.tmp" "$WORK/n2.tmp"; }
dist() { norm "$1" "$WORK/d1.tmp"; norm "$2" "$WORK/d2.tmp"; diff "$WORK/d1.tmp" "$WORK/d2.tmp" 2>/dev/null | grep -c '^[<>]'; }

MERGE_FILES="
app/helpers.php
app/Http/Controllers/OrderSummaryController.php
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
# بی‌purge، صفحاتِ کش‌شده schemaِ قدیمی را سرو می‌کنند.
(cd "$APP" && "$PHPBIN" artisan tinker --execute='\App\Http\Middleware\PageCache::purge(); echo "pagecache purged\n";') \
  || echo "  ⚠️ purgeِ کشِ صفحه انجام نشد — صبر تا TTL"

echo
echo "═══ ۳) ضمانتِ اتحاد ═══"
union_ok=1
need_grep() { grep -qF -- "$2" "$APP/$1" 2>/dev/null || { echo "🔴 «$2» در $1 نیست"; union_ok=0; }; }

need_grep app/helpers.php                                 'function schema_return_policy'
need_grep app/helpers.php                                 'https://schema.org/ReturnByMail'
need_grep app/helpers.php                                 "'hasMerchantReturnPolicy' => schema_return_policy()"
need_grep app/Http/Controllers/OrderSummaryController.php "= schema_return_policy();"
# ⚠️ کارِ قبلی که merge نباید بخورد
need_grep app/helpers.php                                 'function schema_offer_extras'
need_grep app/helpers.php                                 'function price_factor'
need_grep app/Http/Controllers/OrderSummaryController.php 'function retiredPlan'
need_grep app/Http/Controllers/OrderSummaryController.php 'function pay'

[ "$union_ok" -eq 0 ] && echo "🔴 اتحاد ناقص — گزارشِ بالا را بفرست."

echo
echo "═══ ۴) راستی‌آزماییِ زنده ═══"
BAD=0
check() {
  c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$1?qa=$STAMP")
  case " $2 " in *" $c "*) echo "  ✅ $c  $1" ;; *) echo "  🔴 $c  $1  (انتظار: $2)"; BAD=1 ;; esac
}
check "https://servernet.cloud/"                 "200"
check "https://servernet.cloud/vps/hourly"       "200"
check "https://servernet.cloud/hosting/linux"    "200 301"
check "https://servernet.cloud/gpu"              "200"
check "https://servernet.cloud/blog"             "200"
check "https://console.servernet.cloud/login"    "200"

if [ "$BAD" -eq 1 ] || [ "$union_ok" -eq 0 ]; then
  echo
  echo "🔴🔴 سایت سالم برنگشت — کلِ بکاپ برگردانده می‌شود …"
  (cd "$BK" && find . -type f | while read -r f; do cp -p "$f" "$APP/${f#./}"; done)
  (cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan view:clear)
  echo "↩️ برگشت انجام شد. گزارشِ بالا را بفرست."
  exit 1
fi

echo
echo "═══ ۵) بررسیِ محتوا (فقط گزارشی — پس از ریستِ opcache دوباره نگاه کنید) ═══"
curl -s --max-time 25 "https://servernet.cloud/vps/hourly?qa=$STAMP" | grep -q 'ReturnByMail' \
  && echo "  ✅ /vps/hourly: returnMethod در schema" || echo "  ⚠️ /vps/hourly: returnMethod هنوز نیست (opcache؟)"

echo
echo "═══ خلاصه ═══"
echo "  به‌روزشده: $UPD فایل"
[ -n "$CONFLICTS" ] && echo "  🔴 تداخل:$CONFLICTS   (نسخه‌ها در $WORK/conflicts)"
[ -n "$LINT_FAIL" ] && echo "  🔴 خطای نحوی:$LINT_FAIL"
echo "  بکاپ: $BK"
echo
echo "  کارِ باقی‌مانده: ریستِ opcache از /system/opcache."
