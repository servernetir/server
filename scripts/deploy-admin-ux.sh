#!/usr/bin/env bash
#
# دیپلویِ «لغو+بازگشت وجه، جستجو/فیلترِ مشتری، موبایلِ پنل، ردیفِ اصلیِ دامنه».
#
# اجرا از ترمینالِ cPanel:
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-admin-ux.sh) [<SHA>]
#
# ═══ چرا این‌بار هدف نوکِ ادغام‌شده (a5ba41b) است، نه کامیتِ خودِ کار ═══
#
# ⚠️ برخلافِ دو دیپلویِ قبلی. نشستِ دیگری خطِ GPU را با پین‌های خودش مرتب
# دیپلوی کرده، پس فایل‌های مشترک (routes، CustomerController، …) روی سرور
# **جلوتر** از کامیتِ خامِ این کارند. اگر هدفْ کامیتِ خام بود، merge سه‌طرفه
# نبودِ کارِ GPU در «mine» را حذفِ عمدی می‌خواند و کارِ دیپلوی‌شدهٔ او را از
# سرور پاک می‌کرد — همان تلهٔ «پایهٔ اشتباه» که در سرآغازِ اسکریپتِ سئو مستند
# است، از سمتِ دیگر. نوکِ ادغام‌شده ابرمجموعهٔ هر دو کار است؛ merge فقط
# اضافه می‌کند.
#
# مقایسه، طبقِ درسِ ثابت‌شده، پس از نرمال‌سازیِ پایانِ خط (LF) انجام می‌شود.
#
set -u

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-admin-ux"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=80

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }
if [ -d repo/.git ]; then
  git -C repo fetch --depth 600 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 600 --branch develop https://github.com/servernetir/server.git repo || exit 1
fi

MINE="${1:-a5ba41b}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
echo "── بکاپ در: $BK"
echo

# 🔴 ترتیب: سرویس و کنترلر اول، بعد ویو و CSS، **آخر** routes.
APP_FILES="
app/Services/Domain/DomainSearch.php
app/Http/Controllers/DomainSearchController.php
app/Http/Controllers/Admin/CustomerController.php
app/Http/Controllers/Admin/ServiceController.php
resources/views/admin/customers.blade.php
resources/views/admin/customer.blade.php
resources/views/admin/layout.blade.php
resources/views/pages/domain-search.blade.php
routes/web.php
"
PUB_FILES="
assets/css/admin.css
"

to_lf() { tr -d '\r' < "$1" > "$2"; }
dist()  { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

CONFLICTS=""; UPD=0

apply_one() {                       # $1=مسیرِ مخزن (زیر website/)  $2=ریشه  $3=مسیرِ مقصد
  rel="$1"; root="$2"; drel="$3"
  dest="$root/$drel"
  mine_f="$WORK/a.mine"; srv_lf="$WORK/a.srv"; base_f="$WORK/a.base"; cand="$WORK/a.cand"

  git -C repo show "$MINE:website/$rel" > "$mine_f" 2>/dev/null || { echo "SKIP  $rel"; return; }

  if [ ! -f "$dest" ]; then
    mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"; echo "NEW   $drel"; UPD=$((UPD+1)); return
  fi

  mkdir -p "$BK/$(dirname "$drel")"; cp -p "$dest" "$BK/$drel"
  to_lf "$dest" "$srv_lf"

  if cmp -s "$srv_lf" "$mine_f"; then echo "OK    $drel"; return; fi

  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$cand" 2>/dev/null || continue
    if cmp -s "$srv_lf" "$cand"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$srv_lf" "$cand"); [ "$d" -lt "$bestd" ] && { bestd=$d; best="$sha"; }
  done

  if [ -z "$best" ]; then
    echo "CF    $drel  ← نسخهٔ ناشناخته روی سرور — دست نخورد"
    CONFLICTS="$CONFLICTS $drel"
    keep="$WORK/conflicts/$drel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"; return
  fi

  if [ "$bestd" -eq 0 ]; then
    cp "$mine_f" "$dest"; echo "UP    $drel  (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1)); return
  fi

  git -C repo show "$best:website/$rel" > "$base_f"
  m="$WORK/a.merged"; cp "$srv_lf" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"
    echo "MG    $drel  (پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط — تغییرِ دیگران حفظ شد)"
    UPD=$((UPD+1))
  else
    echo "CF    $drel  ← تداخلِ واقعی — دست نخورد"
    CONFLICTS="$CONFLICTS $drel"
    keep="$WORK/conflicts/$drel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
}

echo "═══ اپ ($APP) ═══"
for f in $APP_FILES; do apply_one "$f" "$APP" "$f"; done
echo
echo "═══ استاتیک ($PUB) ═══"
for f in $PUB_FILES; do apply_one "public/$f" "$PUB" "$f"; done

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────
echo
ok=1
grep -q "function cancelRefund" "$APP/app/Http/Controllers/Admin/ServiceController.php" || { echo "🔴 متدِ cancelRefund نیست"; ok=0; }
grep -q "cancel-refund" "$APP/routes/web.php" || { echo "🔴 روتِ cancel-refund ننشست"; ok=0; }
grep -q "function primaryFqdn" "$APP/app/Services/Domain/DomainSearch.php" || { echo "🔴 primaryFqdn نیست"; ok=0; }
grep -q "primaryFqdn" "$APP/app/Http/Controllers/DomainSearchController.php" || { echo "🔴 پرچمِ primary در کنترلرِ دامنه نیست"; ok=0; }
grep -q "ad-burger" "$APP/resources/views/admin/layout.blade.php" || { echo "🔴 همبرگرِ موبایل در لایوت نیست"; ok=0; }
grep -q "body.nav-open .ad-side" "$PUB/assets/css/admin.css" || { echo "🔴 CSSِ کشو نیست"; ok=0; }
grep -q "ad-scrim\[hidden\]" "$PUB/assets/css/admin.css" || { echo "🔴 گاردِ [hidden] رویه نیست — رویه همهٔ کلیک‌ها را می‌خورَد"; ok=0; }
grep -q "dsx-primary" "$APP/resources/views/pages/domain-search.blade.php" || { echo "🔴 سنجاقِ ردیفِ اصلیِ دامنه نیست"; ok=0; }
grep -q "name=\"service\"" "$APP/resources/views/admin/customers.blade.php" || { echo "🔴 فیلترهای مشتری در ویو نیستند"; ok=0; }
# روت و متدِ جفت: cancel-refund هست ⇒ متدش هم باید باشد (بالا چک شد)؛
# روت‌های حیاتیِ موجود گم نشده باشند
for r in "name('aup')" "name('cloud.index')" "name('domain.search')" "name('parts.index')" "name('contact')" "tickets/bulk"; do
  grep -qF "$r" "$APP/routes/web.php" || { echo "🔴 routes: $r گم شد"; ok=0; }
done
if [ "$ok" -eq 0 ]; then
  echo "🔴 اتحاد ناقص — routes از بکاپ برمی‌گردد تا سایت نشکند."
  [ -f "$BK/routes/web.php" ] && cp "$BK/routes/web.php" "$APP/routes/web.php" && echo "   routes/web.php برگشت"
else
  echo "✅ همهٔ اجزا با هم‌اند"
fi

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
if [ -n "$PHPBIN" ]; then
  "$PHPBIN" -l "$APP/app/Http/Controllers/Admin/ServiceController.php" \
    && "$PHPBIN" -l "$APP/app/Http/Controllers/Admin/CustomerController.php" \
    && "$PHPBIN" -l "$APP/app/Services/Domain/DomainSearch.php" \
    && { cd "$APP" && "$PHPBIN" artisan config:clear >/dev/null && "$PHPBIN" artisan route:clear >/dev/null && "$PHPBIN" artisan view:clear >/dev/null && echo "کش‌ها پاک شد"; }
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · به‌روزشده: $UPD"
[ -n "$CONFLICTS" ] && echo "🔴 تداخل‌دار:$CONFLICTS  (نسخه‌ها در $WORK/conflicts/)" || echo "✅ تداخلی نبود"
echo
echo "هیچ مهاجرتی لازم نیست."
echo "امتحان:"
echo "  · پروفایلِ یک مشتری → ردیفِ سرویس → «لغو + بازگشت وجه» (مبلغ پیش‌پر با سقفِ پرداختی)"
echo "  · /admin/customers → جستجوی «نام نام‌خانوادگی» و ردیفِ فیلترهای پیشرفته"
echo "  · پنل روی موبایل → همبرگر و کشوی کناری، جدول‌ها داخلِ قاب"
echo "  · /domains → جستجوی دامنهٔ گرفته‌شده → خودش سطرِ اول با قابِ متمایز، بعد پیشنهادها"
