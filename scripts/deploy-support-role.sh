#!/usr/bin/env bash
#
# دیپلویِ «نقشِ پشتیبان + جستجوی زندهٔ مشتری + جریانِ پاسخِ تیکت».
#
# اجرا از ترمینالِ cPanel:
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-support-role.sh) [<SHA>]
#
# ═══ ⚠️ نکتهٔ ویژهٔ این دیپلوی: bootstrap/app.php ═══
#
# نقشِ پشتیبان بدونِ اسمِ مستعارِ `staff` در `bootstrap/app.php` کار نمی‌کند، و
# آن فایل تا امروز هرگز دیپلوی نشده. اگر نسخهٔ سرور با تاریخچهٔ مخزن جور
# نباشد، اسکریپت آن را **دست نمی‌زند** و همه‌چیز را برمی‌گرداند — چون یک
# `bootstrap/app.php`ِ خراب یعنی کلِ سایت ۵۰۰، نه یک صفحهٔ خراب.
#
# هیچ مهاجرتی لازم نیست: نقشِ تازه فقط یک مقدارِ دیگر در ستونِ `role` است.
#
# مقایسه پس از نرمال‌سازیِ پایانِ خط (LF)؛ merge سه‌طرفه با پایهٔ تاریخی.
#
set -u

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-support-role"
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

MINE="${1:-f336d09}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
echo "── بکاپ در: $BK"
echo

# 🔴 ترتیب: مدل و میان‌افزار اول (تا وقتی روت به `staff` اشاره می‌کند،
#    اسمِ مستعار و کلاسش موجود باشند)، بعد کنترلرها و ویوها، **آخر** routes.
APP_FILES="
app/Models/User.php
app/Http/Middleware/EnsureStaff.php
app/Http/Controllers/Admin/CustomerController.php
app/Http/Controllers/Admin/TicketController.php
app/Http/Controllers/Admin/UserController.php
resources/views/admin/layout.blade.php
resources/views/admin/customers.blade.php
resources/views/admin/ticket.blade.php
resources/views/admin/users.blade.php
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

# ── bootstrap/app.php: محتاطانه، چون خرابی‌اش کلِ سایت را می‌خواباند ──
echo
BOOT="$APP/bootstrap/app.php"
if grep -q "EnsureStaff" "$BOOT" 2>/dev/null; then
  echo "OK    bootstrap/app.php (اسمِ مستعارِ staff از قبل هست)"
else
  mkdir -p "$BK/bootstrap"; cp -p "$BOOT" "$BK/bootstrap/app.php"
  git -C repo show "$MINE:website/bootstrap/app.php" > "$WORK/boot.new"
  tr -d '\r' < "$BOOT" > "$WORK/boot.srv"

  bb=""; bbd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/bootstrap/app.php"); do
    git -C repo show "$sha:website/bootstrap/app.php" > "$WORK/boot.cand" 2>/dev/null || continue
    if cmp -s "$WORK/boot.srv" "$WORK/boot.cand"; then bb="$sha"; bbd=0; break; fi
    d=$(dist "$WORK/boot.srv" "$WORK/boot.cand"); [ "$d" -lt "$bbd" ] && { bbd=$d; bb="$sha"; }
  done

  if [ -z "$bb" ] || [ "$bbd" -gt 40 ]; then
    echo "🔴 bootstrap/app.php روی سرور با تاریخچه جور نیست (فاصله $bbd) — دست نخورد."
    echo "   بدونِ اسمِ مستعارِ staff، صفحاتِ پشتیبانی برای همه ۵۰۰ می‌شوند."
    echo "   ⚠️ ادامه نده؛ خروجی را برای بررسی بفرست."
    CONFLICTS="$CONFLICTS bootstrap/app.php"
  elif [ "$bbd" -eq 0 ]; then
    cp "$WORK/boot.new" "$BOOT"; echo "UP    bootstrap/app.php  (سرور = $(git -C repo rev-parse --short "$bb"))"; UPD=$((UPD+1))
  else
    git -C repo show "$bb:website/bootstrap/app.php" > "$WORK/boot.base"
    cp "$WORK/boot.srv" "$WORK/boot.merged"
    if git merge-file -L server -L base -L new "$WORK/boot.merged" "$WORK/boot.base" "$WORK/boot.new" >/dev/null 2>&1; then
      cp "$WORK/boot.merged" "$BOOT"
      echo "MG    bootstrap/app.php  (پایه $(git -C repo rev-parse --short "$bb")، فاصله $bbd خط)"
      UPD=$((UPD+1))
    else
      echo "🔴 تداخلِ واقعی در bootstrap/app.php — دست نخورد."
      CONFLICTS="$CONFLICTS bootstrap/app.php"
    fi
  fi
fi

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────
echo
ok=1
grep -qF "function isStaff" "$APP/app/Models/User.php" || { echo "🔴 isStaff در مدل نیست"; ok=0; }
grep -qF "const ROLES" "$APP/app/Models/User.php" || { echo "🔴 فهرستِ نقش‌ها نیست"; ok=0; }
grep -qF "class EnsureStaff" "$APP/app/Http/Middleware/EnsureStaff.php" || { echo "🔴 میان‌افزارِ staff نیست"; ok=0; }
grep -qF "EnsureStaff" "$APP/bootstrap/app.php" || { echo "🔴 اسمِ مستعارِ staff ثبت نشده"; ok=0; }
grep -qF "middleware('staff')" "$APP/routes/web.php" || { echo "🔴 هیچ روتی به staff وصل نیست"; ok=0; }
grep -qF "customers/search" "$APP/routes/web.php" || { echo "🔴 روتِ جستجوی زنده نیست"; ok=0; }
grep -qF "function search" "$APP/app/Http/Controllers/Admin/CustomerController.php" || { echo "🔴 متدِ جستجو نیست"; ok=0; }
grep -qF "as_user" "$APP/app/Http/Controllers/Admin/TicketController.php" || { echo "🔴 امضای پاسخ‌دهنده نیست"; ok=0; }
grep -qF "id=\"cs-drop\"" "$APP/resources/views/admin/customers.blade.php" || { echo "🔴 فهرستِ زنده در ویو نیست"; ok=0; }
grep -qF ".cs-drop[hidden]" "$PUB/assets/css/admin.css" || { echo "🔴 گاردِ [hidden] فهرستِ زنده نیست"; ok=0; }
# اجزای دیپلوی‌های قبلی نباید در merge پریده باشند
grep -qF "ad-burger" "$APP/resources/views/admin/layout.blade.php" || { echo "🔴 همبرگرِ موبایل از layout پرید"; ok=0; }
grep -qF "nav_provisioning" "$APP/resources/views/admin/layout.blade.php" || { echo "🔴 آیتمِ تحویل‌ها از layout پرید"; ok=0; }
for r in "name('aup')" "name('cloud.index')" "name('domain.search')" "name('parts.index')" "tickets/bulk" "cancel-refund" "provision-override" "admin.provisioning"; do
  grep -qF "$r" "$APP/routes/web.php" || { echo "🔴 routes: $r گم شد"; ok=0; }
done
if [ "$ok" -eq 0 ]; then
  echo "🔴 اتحاد ناقص — routes، layout و bootstrap از بکاپ برمی‌گردند."
  for f in routes/web.php resources/views/admin/layout.blade.php bootstrap/app.php; do
    [ -f "$BK/$f" ] && cp "$BK/$f" "$APP/$f" && echo "   $f برگشت"
  done
else
  echo "✅ همهٔ اجزا با هم‌اند"
fi

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
if [ -n "$PHPBIN" ]; then
  syn=1
  for f in app/Models/User.php app/Http/Middleware/EnsureStaff.php \
           app/Http/Controllers/Admin/CustomerController.php \
           app/Http/Controllers/Admin/TicketController.php \
           app/Http/Controllers/Admin/UserController.php bootstrap/app.php; do
    "$PHPBIN" -l "$APP/$f" >/dev/null || { echo "🔴 خطای syntax در $f"; syn=0; }
  done

  if [ "$syn" -eq 0 ]; then
    echo "🔴 برگشت از بکاپ به‌خاطرِ خطای syntax"
    (cd "$BK" && find . -type f -print0 | while IFS= read -r -d '' p; do
       case "$p" in ./assets/*) cp "$p" "$PUB/${p#./}";; *) cp "$p" "$APP/${p#./}";; esac
     done)
  else
    echo "بدونِ خطای syntax"
    cd "$APP" && "$PHPBIN" artisan config:clear >/dev/null && "$PHPBIN" artisan route:clear >/dev/null \
      && "$PHPBIN" artisan view:clear >/dev/null && echo "کش‌ها پاک شد"
  fi
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · به‌روزشده: $UPD"
[ -n "$CONFLICTS" ] && echo "🔴 تداخل‌دار:$CONFLICTS  (نسخه‌ها در $WORK/conflicts/)" || echo "✅ تداخلی نبود"
echo
echo "هیچ مهاجرتی لازم نیست."
echo "امتحان:"
echo "  · /admin/customers → تایپ در کادرِ جستجو (بدونِ Enter) — نتایج از کلِ جدول"
echo "  · یک تیکت → «ارسال» یا «پاسخ و بستن» ⇒ برگشتِ خودکار به فهرست"
echo "  · همان فرم → «پاسخ به نامِ …» (فقط برای مدیر)"
echo "  · /admin/users → ساختِ کاربر با نقشِ «پشتیبان»، بعد ورود با آن حساب"
