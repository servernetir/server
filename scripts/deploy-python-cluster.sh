#!/usr/bin/env bash
set -u

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-python-cluster"
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

MINE="${1:-8a53e246}"
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


MERGE_FILES="
config/blog.php
resources/content/blog-1405.php
"
echo
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
(cd "$APP" && "$PHPBIN" artisan tinker --execute='\App\Http\Middleware\PageCache::purge(); echo "pagecache purged\n";') \
  || echo "  ⚠️ purgeِ کشِ صفحه انجام نشد"

echo
echo "═══ ۳) ضمانتِ اتحاد ═══"
union_ok=1
need_grep() { grep -qF -- "$2" "$APP/$1" 2>/dev/null || { echo "🔴 «$2» در $1 نیست"; union_ok=0; }; }

# ── کارِ این دیپلوی
need_grep config/blog.php "'post_products' =>"
need_grep config/blog.php 'deploy-django-cpanel-passenger'
need_grep config/blog.php 'python-hosting-vs-vps-when-to-move'
for sl in deploy-django-cpanel-passenger python-app-not-starting-passenger \
          fastapi-on-wsgi-with-a2wsgi flask-deploy-shared-hosting \
          python-venv-pip-shared-hosting django-static-files-cpanel \
          django-mysql-instead-of-postgres python-scheduled-tasks-cpanel \
          django-secrets-env-shared-hosting python-hosting-vs-vps-when-to-move; do
  need_grep resources/content/blog-1405.php "'$sl'"
done

# 🔴 ⚠️ خوشهٔ «سرور خارج» فقط روی سرور است و در این شاخه **نیست** (شاخه از
# develop آمده). اگر merge به‌جای ادغام، فایل را رونویسی کند، ۱۲ مقالهٔ
# صف‌شده بی‌صدا پاک می‌شوند — بی‌هیچ خطایی، و فقط ماه‌ها بعد از روی
# lastmodِ ثابتِ نقشهٔ سایت دیده می‌شد. پس همه‌شان اسم‌به‌اسم سنجیده می‌شوند.
for sl in foreign-vps-location-guide dev-environment-on-foreign-vps \
          first-hour-new-vps-security buying-foreign-server-with-rial \
          forex-vps-broker-proximity windows-vps-remote-desktop-secure \
          vps-traffic-bandwidth-explained check-new-server-ip-reputation \
          cdn-for-site-hosted-abroad mtr-traceroute-network-path \
          dedicated-vs-shared-vcpu migrate-site-to-foreign-vps; do
  need_grep resources/content/blog-1405.php "'$sl'"
done

# ⚠️ کارِ دیپلوی‌های قبلی که merge نباید برش دارد
need_grep config/blog.php 'iranian-discord-servers'
need_grep config/blog.php "'category_products' =>"
need_grep config/blog.php "'category_products_fallback' =>"

# شمارشِ کل: اتحادِ دو خوشه باید از هر دو طرف بیشتر باشد، نه برابرِ یکی از آنها.
TOPICS=$(grep -c "^\['" "$APP/resources/content/blog-1405.php" 2>/dev/null || echo 0)
echo "  ── موضوعاتِ صفِ بلاگ روی سرور: $TOPICS"
if [ "${TOPICS:-0}" -lt 335 ]; then
  echo "  🔴 کمتر از انتظار (۳۲۵ شاخه + ۱۲ سرور - همپوشانی). چیزی از merge افتاده."
  union_ok=0
fi
[ "$union_ok" -eq 0 ] && echo "🔴 اتحاد ناقص — گزارشِ بالا را بفرست."

echo
echo "═══ ۴) راستی‌آزماییِ زنده ═══"
BAD=0
check() {
  c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$1?qa=$STAMP")
  case " $2 " in *" $c "*) echo "  ✅ $c  $1" ;; *) echo "  🔴 $c  $1  (انتظار: $2)"; BAD=1 ;; esac
}
check "https://servernet.cloud/"                              "200"
check "https://servernet.cloud/hosting/python"                "200"
check "https://servernet.cloud/blog/iranian-discord-servers"  "200"
check "https://servernet.cloud/blog"                          "200"
check "https://servernet.cloud/sitemap.xml"                   "200"
check "https://console.servernet.cloud/login"                 "200"

if [ "$BAD" -eq 1 ] || [ "$union_ok" -eq 0 ]; then
  echo
  echo "🔴🔴 سایت سالم برنگشت — کلِ بکاپ برگردانده می‌شود …"
  (cd "$BK" && find . -type f | while read -r f; do cp -p "$f" "$APP/${f#./}"; done)
  (cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan view:clear)
  echo "↩️ برگشت انجام شد. گزارشِ بالا را بفرست."
  exit 1
fi

echo
echo "═══ ۵) صف (گزارشی) ═══"
# ⚠️ این دیپلوی هیچ صفحهٔ تازه‌ای نمی‌سازد: ده مقاله در **صف** می‌نشینند و
# content:generate روزی ۲ تا از آن‌ها را می‌سازد. پس انتظارِ درست این است که
# /blog/deploy-django-cpanel-passenger همین امروز ۴۰۴ بدهد.
echo "  موضوعاتِ پایتون در صف: $(grep "^\['" "$APP/resources/content/blog-1405.php" 2>/dev/null | grep -cE "django|flask|fastapi|python")"
echo "  اولین انتشار: ۲۰۲۶-۱۰-۱۸ (۲ مطلب در روز، تا ۲۰۲۶-۱۱-۲۲)"
echo "  پایشِ واقعی: چند روز بعد /blog را ببینید و صفِ پیش‌نویس را در /admin/calendar."
cho "═══ خلاصه ═══"
echo "  به‌روزشده: $UPD فایل"
[ -n "$CONFLICTS" ] && echo "  🔴 تداخل:$CONFLICTS   (نسخه‌ها در $WORK/conflicts)"
[ -n "$LINT_FAIL" ] && echo "  🔴 خطای نحوی:$LINT_FAIL"
echo "  بکاپ: $BK"
echo
echo "  کارِ باقی‌مانده: ریستِ opcache از /system/opcache، و بعد بخشِ ۵ را دوباره نگاه کنید."
