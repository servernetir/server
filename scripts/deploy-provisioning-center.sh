#!/usr/bin/env bash
#
# دیپلویِ «مرکزِ تحویل‌ها» — /admin/provisioning + نشانِ منو.
#
# اجرا از ترمینالِ cPanel:
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-provisioning-center.sh) [<SHA>]
#
# چهار فایل: کنترلر و ویو **تازه‌اند** (فقط کپی)، layout و routes از دیپلویِ
# چند ساعت پیش (a5ba41b) روی سرورند و این‌جا فقط جلو می‌روند — پس MG واقعی
# فقط وقتی پیش می‌آید که هم‌زمان دیپلویِ دیگری نشسته باشد.
#
# پین به نوکِ ادغام‌شده، به همان دلیلِ ثبت‌شده در سرآغازِ deploy-admin-ux.sh.
# مقایسه پس از نرمال‌سازیِ پایانِ خط (LF).
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-provisioning-center"
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

MINE="${1:-0a0276e}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
echo "── بکاپ در: $BK"
echo

# 🔴 ترتیب: کنترلر و ویو اول، **آخر** routes — تا هیچ لحظه‌ای روتِ بی‌کنترلر نباشد.
APP_FILES="
app/Http/Controllers/Admin/ProvisioningController.php
resources/views/admin/provisioning.blade.php
resources/views/admin/layout.blade.php
routes/web.php
"

to_lf() { tr -d '\r' < "$1" > "$2"; }
dist()  { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

CONFLICTS=""; UPD=0

apply_one() {
  rel="$1"; dest="$APP/$rel"
  mine_f="$WORK/a.mine"; srv_lf="$WORK/a.srv"; base_f="$WORK/a.base"; cand="$WORK/a.cand"

  git -C repo show "$MINE:website/$rel" > "$mine_f" 2>/dev/null || { echo "SKIP  $rel"; return; }

  if [ ! -f "$dest" ]; then
    mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"; echo "NEW   $rel"; UPD=$((UPD+1)); return
  fi

  mkdir -p "$BK/$(dirname "$rel")"; cp -p "$dest" "$BK/$rel"
  to_lf "$dest" "$srv_lf"

  if cmp -s "$srv_lf" "$mine_f"; then echo "OK    $rel"; return; fi

  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$cand" 2>/dev/null || continue
    if cmp -s "$srv_lf" "$cand"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$srv_lf" "$cand"); [ "$d" -lt "$bestd" ] && { bestd=$d; best="$sha"; }
  done

  if [ -z "$best" ]; then
    echo "CF    $rel  ← نسخهٔ ناشناخته روی سرور — دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"; return
  fi

  if [ "$bestd" -eq 0 ]; then
    cp "$mine_f" "$dest"; echo "UP    $rel  (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1)); return
  fi

  git -C repo show "$best:website/$rel" > "$base_f"
  m="$WORK/a.merged"; cp "$srv_lf" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"
    echo "MG    $rel  (پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط — تغییرِ دیگران حفظ شد)"
    UPD=$((UPD+1))
  else
    echo "CF    $rel  ← تداخلِ واقعی — دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
}

echo "═══ اپ ($APP) ═══"
for f in $APP_FILES; do apply_one "$f"; done

# ── ضمانتِ اتحاد: روت ↔ کنترلر ↔ ویو ↔ آیتمِ منو با هم‌اند ──────────────
echo
ok=1
grep -q "class ProvisioningController" "$APP/app/Http/Controllers/Admin/ProvisioningController.php" 2>/dev/null || { echo "🔴 کنترلر نیست"; ok=0; }
# ⚠️ درسِ اجرای اول: الگوی قبلی «admin/provisioning» بود که در فایل وجود
# ندارد (نامِ روت با نقطه است: admin.provisioning) — گارد همیشه می‌شکست و
# دیپلویِ سالم را رول‌بک می‌کرد. الگو باید عینِ متنِ فایل باشد، پس هر دو
# تکهٔ واقعی را می‌گیریم:
grep -qF "Route::get('/provisioning'" "$APP/routes/web.php" || { echo "🔴 روتِ /provisioning ننشست"; ok=0; }
grep -qF "name('admin.provisioning')" "$APP/routes/web.php" || { echo "🔴 نامِ روتِ admin.provisioning ننشست"; ok=0; }
grep -q "ProvisioningController" "$APP/routes/web.php" || { echo "🔴 routes به کنترلر اشاره نمی‌کند"; ok=0; }
[ -f "$APP/resources/views/admin/provisioning.blade.php" ] || { echo "🔴 ویوی مرکز نیست"; ok=0; }
grep -q "nav_provisioning" "$APP/resources/views/admin/layout.blade.php" || { echo "🔴 آیتمِ منو نیست — صفحهٔ کشف‌نشدنی"; ok=0; }
grep -q "admin.nav.prov-stuck" "$APP/resources/views/admin/layout.blade.php" || { echo "🔴 نشانِ شمارش نیست"; ok=0; }
# لایوت هنوز اجزای کشوی موبایلِ دیپلویِ قبل را دارد؟ (merge نباید خورده باشدش)
grep -q "ad-burger" "$APP/resources/views/admin/layout.blade.php" || { echo "🔴 همبرگرِ موبایل از layout پرید"; ok=0; }
# روت‌های حیاتیِ موجود گم نشده باشند
for r in "name('aup')" "name('cloud.index')" "name('domain.search')" "name('parts.index')" "name('contact')" "tickets/bulk" "cancel-refund" "provision-override"; do
  grep -qF "$r" "$APP/routes/web.php" || { echo "🔴 routes: $r گم شد"; ok=0; }
done
if [ "$ok" -eq 0 ]; then
  echo "🔴 اتحاد ناقص — routes و layout از بکاپ برمی‌گردند تا پنل نشکند."
  [ -f "$BK/routes/web.php" ] && cp "$BK/routes/web.php" "$APP/routes/web.php" && echo "   routes/web.php برگشت"
  [ -f "$BK/resources/views/admin/layout.blade.php" ] && cp "$BK/resources/views/admin/layout.blade.php" "$APP/resources/views/admin/layout.blade.php" && echo "   layout برگشت"
else
  echo "✅ همهٔ اجزا با هم‌اند"
fi

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
if [ -n "$PHPBIN" ]; then
  "$PHPBIN" -l "$APP/app/Http/Controllers/Admin/ProvisioningController.php" \
    && { cd "$APP" && "$PHPBIN" artisan config:clear >/dev/null && "$PHPBIN" artisan route:clear >/dev/null && "$PHPBIN" artisan view:clear >/dev/null && echo "کش‌ها پاک شد"; }
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · به‌روزشده: $UPD"
[ -n "$CONFLICTS" ] && echo "🔴 تداخل‌دار:$CONFLICTS  (نسخه‌ها در $WORK/conflicts/)" || echo "✅ تداخلی نبود"
echo
echo "هیچ مهاجرتی لازم نیست."
echo "امتحان:"
echo "  · منوی پنل → «تحویل‌ها» (نشانِ قرمز = تعدادِ سفارش‌های نیازمندِ شما)"
echo "  · سرویسِ SN-604534 آن‌جاست: تشخیصِ علت + متنِ خطا + دکمهٔ اقدامِ درست"
echo "  · پایینِ صفحه «سلامتِ کاتالوگ»: پلنِ قرنطینه/پرخطا، مکانِ بی‌ایمیج، ظرفیتِ WHM"
