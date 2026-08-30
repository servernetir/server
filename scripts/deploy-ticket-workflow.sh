#!/usr/bin/env bash
#
# دیپلویِ «وضعیتِ نگه‌داشته‌شده + عملیاتِ گروهی + پیشنهادِ پاسخ با AI» (تیکت).
#
# اجرا از ترمینالِ cPanel:
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-ticket-workflow.sh) [<SHA>]
#
# ═══ چرا هدفِ پیش‌فرض ac0d47a است و نه نوکِ develop ═══
#
# ⚠️ ac0d47a = کامیتِ همین کار، **پیش از** ادغامِ ۵۷ کامیتِ بعدیِ develop.
# routes/web.phpِ نوک به کنترلرهای GPU/ساعتی/ارومیه اشاره می‌کند که شاید
# روی سرور نباشند — هر کاری دیپلویِ پینِ خودش را دارد. در ac0d47a تنها
# افزوده‌های routes همین دو روتِ تیکت است.
#
# ═══ منطق: همان merge سه‌طرفه با پایهٔ خودکار + درسِ CRLF از روزِ اول ═══
#
# دیپلویِ قبلی یک تداخلِ قلابی خورد چون فایلِ سرور CRLF بود و cmp با هیچ
# نسخهٔ تاریخیِ LF برابر نمی‌شد. این‌بار مقایسه از اول **پس از نرمال‌سازی به
# LF** انجام می‌شود؛ merge هم روی نسخهٔ نرمال‌شده.
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-ticket-workflow"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=60

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }
if [ -d repo/.git ]; then
  git -C repo fetch --depth 500 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 500 --branch develop https://github.com/servernetir/server.git repo || exit 1
fi

MINE="${1:-ac0d47a}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
echo "── بکاپ در: $BK"
echo

# 🔴 ترتیب معنادار: اول مدل و کنترلر و ویو، **آخر از همه** routes —
#    روتی که به متدِ هنوز-نرسیده اشاره کند یعنی ۵۰۰.
FILES="
app/Models/Ticket.php
app/Http/Controllers/Admin/TicketController.php
app/Services/Bale/Admin/AdminBaleCommands.php
resources/views/admin/tickets.blade.php
resources/views/admin/ticket.blade.php
routes/web.php
"

to_lf() { tr -d '\r' < "$1" > "$2"; }
dist()  { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

CONFLICTS=""; UPD=0

for rel in $FILES; do
  dest="$APP/$rel"
  mine_f="$WORK/t.mine"; srv_lf="$WORK/t.srv"; base_f="$WORK/t.base"; cand="$WORK/t.cand"

  git -C repo show "$MINE:website/$rel" > "$mine_f" 2>/dev/null || { echo "SKIP  $rel"; continue; }

  if [ ! -f "$dest" ]; then
    mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"; echo "NEW   $rel"; UPD=$((UPD+1)); continue
  fi

  mkdir -p "$BK/$(dirname "$rel")"; cp -p "$dest" "$BK/$rel"
  to_lf "$dest" "$srv_lf"

  if cmp -s "$srv_lf" "$mine_f"; then echo "OK    $rel"; continue; fi

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
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"; continue
  fi

  if [ "$bestd" -eq 0 ]; then
    cp "$mine_f" "$dest"; echo "UP    $rel  (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1)); continue
  fi

  git -C repo show "$best:website/$rel" > "$base_f"
  m="$WORK/t.merged"; cp "$srv_lf" "$m"
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
done

# ── ضمانتِ اتحاد: روت و متد و ویو باید با هم برسند ──────────────────────
echo
ok=1
TC="$APP/app/Http/Controllers/Admin/TicketController.php"
grep -q "function bulk"  "$TC" || { echo "🔴 کنترلر متدِ bulk را ندارد"; ok=0; }
grep -q "function draft" "$TC" || { echo "🔴 کنترلر متدِ draft را ندارد"; ok=0; }
grep -q "STATUSES" "$APP/app/Models/Ticket.php" || { echo "🔴 مدل STATUSES را ندارد"; ok=0; }
grep -q "transitionTo" "$APP/app/Models/Ticket.php" || { echo "🔴 مدل transitionTo را ندارد"; ok=0; }
grep -q "tk-bulkbar" "$APP/resources/views/admin/tickets.blade.php" || { echo "🔴 نوارِ گروهی در فهرست نیست"; ok=0; }
grep -q "tk-draft" "$APP/resources/views/admin/ticket.blade.php" || { echo "🔴 دکمهٔ پیشنهاد در صفحهٔ تیکت نیست"; ok=0; }
if grep -q "tickets/bulk" "$APP/routes/web.php"; then
  [ "$ok" -eq 1 ] || {
    echo "🔴 روت هست ولی اجزایش نه — routes از بکاپ برمی‌گردد تا پنل ۵۰۰ نشود."
    [ -f "$BK/routes/web.php" ] && cp "$BK/routes/web.php" "$APP/routes/web.php" && echo "   routes/web.php برگشت"
  }
else
  echo "🔴 روتِ bulk در routes ننشست"; ok=0
fi
# روت‌های حیاتیِ موجود نباید گم شده باشند (درسِ ۲۸ مرداد)
for r in "name('aup')" "name('cloud.index')" "name('domain.search')" "name('parts.index')" "name('contact')"; do
  grep -qF "$r" "$APP/routes/web.php" || { echo "🔴 routes: روتِ موجودِ $r گم شد"; ok=0; }
done
[ "$ok" -eq 1 ] && echo "✅ روت‌ها، متدها و ویوها همه با هم‌اند"

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
if [ -n "$PHPBIN" ]; then
  "$PHPBIN" -l "$TC" && "$PHPBIN" -l "$APP/app/Models/Ticket.php" \
    && { cd "$APP" && "$PHPBIN" artisan config:clear >/dev/null && "$PHPBIN" artisan route:clear >/dev/null && "$PHPBIN" artisan view:clear >/dev/null && echo "کش‌ها پاک شد"; }
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · به‌روزشده: $UPD"
[ -n "$CONFLICTS" ] && echo "🔴 تداخل‌دار:$CONFLICTS  (نسخه‌ها در $WORK/conflicts/)" || echo "✅ تداخلی نبود"
echo
echo "هیچ مهاجرتی لازم نیست — ستونِ status از قبل جا داشت."
echo "امتحان: /admin/tickets → تبِ «نگه‌داشته‌شده»، چک‌باکس‌ها و نوارِ اعمال؛"
echo "         صفحهٔ یک تیکت → دکمهٔ «پیشنهاد پاسخ» با انتخابِ لحن."
