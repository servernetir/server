#!/usr/bin/env bash
#
# دیپلوی نهایی «سرور مجازی ساعتی» — ساخته‌شده بر اساسِ گزارشِ check-live-state (۲۵ اوت).
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-hourly-final.sh)
#
# ═══ چرا فقط همین ۷ فایل ═══
#
# گزارشِ فقط-خواندنیِ ۲۵ اوت گفت:
#   · علتِ ۵۰۰ فقط نبودِ HourlyVpsController است (لاگ: Target class does not exist)
#   · CloudPlan.php روی سرور نسخهٔ بسیار متفاوتی است (تداخلِ واقعی، فاصله ۵۰۴ خط)
#     ولی hourlyIrt و HOURLY_START_MIN_HOURS را **دارد** ⇒ به آن دست نمی‌زنیم.
#   · routes/web.php روی سرور روتِ vps.hourly را **دارد** (کسی دیپلوی دیگری برده)
#     ⇒ به آن هم دست نمی‌زنیم؛ merge بی‌دلیل = ریسکِ بی‌دلیل.
#   · بقیهٔ زنجیره (ویو، helpers، هر سه زبان، CloudCountry) = نوکِ develop (OK).
#   · چهار config و دو ویو BEHIND اند (نسخهٔ تاریخیِ سالم) ⇒ جایگزینیِ امن.
#
# قاعده‌ها همان همیشه: بکاپِ کامل، پایهٔ خودکارِ هر فایل، تداخل ⇒ دست‌نخورده +
# گزارش، ensure-union پیش از پاک‌کردنِ کش، و راستی‌آزماییِ HTTP در پایان.
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-check"            # همان کارگاهِ check-live-state — repo آماده است
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-hourly-final-$STAMP"
HIST=80

# ═══ 🔴 اثباتِ مقصد — پیش از هر نوشتنی ═══
#
# درسِ ثبت‌شدهٔ این پروژه، که خودِ همین اسکریپت‌ها قربانی‌اش شدند: اجرا با
# کاربرِ اشتباه (مثلاً root به‌جای servernetcloud) یعنی $HOME عوض می‌شود،
# فایل‌ها در مسیری ساخته می‌شوند که سایت آن‌جا نیست، و گاردِ اتحاد **سبز**
# می‌شود چون همان فایل‌هایی را می‌سنجد که خودش تازه ساخته.
#
# پس مقصد باید *قبل* از نوشتن ثابت شود: نصبِ واقعی artisan و vendor دارد.
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "🔴 «$APP» نصبِ لاراول نیست (artisan یا vendor نیست)."
  echo "   احتمالاً با کاربرِ اشتباه واردید. کاربرِ درست: servernetcloud"
  echo "   چاره:  su - servernetcloud   و بعد همین دستور را دوباره بزنید."
  exit 1
fi

# فضای آزاد: دیپلوی روی دیسکِ پر، نیمه‌کاره می‌مانَد
FREE_MB=$(df -Pm "$HOME" | awk 'NR==2{print $4}')
if [ "${FREE_MB:-0}" -lt 500 ]; then
  echo "🔴 فضای آزاد کم است (${FREE_MB}MB). اول پاک‌سازی کنید:"
  echo "   rm -rf ~/deploy-*/repo"
  exit 1
fi

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }

if [ -d repo/.git ]; then
  git -C repo fetch --depth 500 origin develop >/dev/null 2>&1 || { echo "FATAL: fetch"; exit 1; }
else
  git clone --quiet --depth 500 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

# پینِ محتوا: 6754f00 — همان نسخه‌ای که check-live-state با آن مقایسه کرد.
# (فایل‌های زنجیره در این کامیت با 67f93a0 و نوکِ فعلی یکسان‌اند؛ پین برای این
# است که کامیت‌های آیندهٔ develop بی‌بررسی سوار نشوند.)
MINE="${1:-6754f00}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# ── پیش‌شرط‌ها: چیزهایی که عمداً دیپلوی نمی‌کنیم باید از قبل روی سرور باشند ──
PRE_OK=1
grep -q "function hourlyIrt" "$APP/app/Models/CloudPlan.php" 2>/dev/null \
  || { echo "FATAL: CloudPlan::hourlyIrt روی سرور نیست — کنترلر بدونِ آن ۵۰۰ می‌دهد"; PRE_OK=0; }
grep -q "HOURLY_START_MIN_HOURS" "$APP/app/Models/CloudPlan.php" 2>/dev/null \
  || { echo "FATAL: HOURLY_START_MIN_HOURS روی سرور نیست"; PRE_OK=0; }
grep -q "name('vps.hourly')" "$APP/routes/web.php" 2>/dev/null \
  || { echo "FATAL: روتِ vps.hourly روی سرور نیست"; PRE_OK=0; }
[ -f "$APP/resources/views/pages/vps-hourly.blade.php" ] \
  || { echo "FATAL: ویوِ vps-hourly روی سرور نیست"; PRE_OK=0; }
for l in fa en tr; do
  grep -q "'hv_meta_t'" "$APP/lang/$l/ui.php" 2>/dev/null \
    || { echo "FATAL: lang/$l کلیدهای hv_* ندارد"; PRE_OK=0; }
done
[ "$PRE_OK" -eq 1 ] || { echo "پیش‌شرط‌ها برقرار نیست — هیچ کاری انجام نشد."; exit 1; }
echo "── پیش‌شرط‌ها ✓ (hourlyIrt، روت، ویو، زبان‌ها روی سرور هستند)"

# ── فقط همین‌ها. CloudPlan.php و routes/web.php عمداً نیستند. ──────────────
APP_FILES="
app/Http/Controllers/HourlyVpsController.php
config/catalog/vps.php
config/servernet.php
config/pagecache.php
resources/views/pages/cloud.blade.php
resources/views/account/cloud-store.blade.php
tests/Feature/HourlyVpsPageTest.php
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

# ── ضمانتِ اتحاد پیش از پاک‌کردنِ کش ─────────────────────────────────────
union_ok=1
[ -f "$APP/app/Http/Controllers/HourlyVpsController.php" ] || { echo "🔴 کنترلر ننشست"; union_ok=0; }
grep -q "class HourlyVpsController" "$APP/app/Http/Controllers/HourlyVpsController.php" 2>/dev/null || { echo "🔴 کنترلر ناقص است"; union_ok=0; }
grep -q "vps.hourly" "$APP/config/servernet.php" 2>/dev/null || echo "⚠️ منو لینکِ ساعتی نگرفت (بحرانی نیست)"
grep -q "'seo_t'" "$APP/config/catalog/vps.php" 2>/dev/null || echo "⚠️ عنوان‌های تراکنشیِ VPS ننشست (بحرانی نیست)"

if [ "$union_ok" -eq 0 ]; then
  echo "🔴 اتحاد کامل نیست — کنترلر از بکاپ/دیپلوی حذف می‌شود تا وضعیت بدتر نشود؛ گزارش را برگردان."
  exit 1
fi

# ── کش‌ها ────────────────────────────────────────────────────────────────
PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
if [ -n "$PHPBIN" ]; then
  cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan view:clear && "$PHPBIN" artisan cache:clear 2>/dev/null
else
  rm -f "$APP/bootstrap/cache/config.php"
  echo "WARN: php پیدا نشد — کشِ config دستی پاک شد"
fi

echo
echo "══════════ راستی‌آزمایی HTTP ══════════"
sleep 2
for u in /vps/hourly /en/vps/hourly /tr/vps/hourly /vps/iran /cloud /; do
  code=$(curl -s -o /dev/null -w "%{http_code}" "https://servernet.cloud$u")
  echo "  $code  $u"
done
echo "  عنوان ساعتی: $(curl -s https://servernet.cloud/vps/hourly | grep -o '<title>[^<]*' | head -1)"
echo "  عنوان ایران: $(curl -s https://servernet.cloud/vps/iran | grep -o '<title>[^<]*' | head -1)"
echo "  لینکِ منو در صفحهٔ اول: $(curl -s https://servernet.cloud/ | grep -c 'vps/hourly') بار"

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · فایل‌های به‌روزشده: $UPD"
if [ -n "$CONFLICTS" ]; then
  echo "🔴 تداخل‌ها (دست‌نخورده):$CONFLICTS — نسخه‌ها در $WORK/conflicts/"
else
  echo "✅ هیچ تداخلی نبود"
fi
echo "این خروجی را کامل کپی کن و برگردان."
