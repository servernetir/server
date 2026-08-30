#!/usr/bin/env bash
#
# دیپلویِ «دکمهٔ ثبت هزینه در منوی بات + معرفی تصحیح و هزینه در راهنما».
#
# اجرا از ترمینالِ cPanel:
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-bale-discoverability.sh) [<SHA>]
#
# ═══ چرا فقط دو فایل و چرا SHAِ جدا ═══
#
# ⚠️ نمی‌شود اسکریپتِ اصلیِ فروشگاه را با SHAِ تازه زد. آن SHA روی develop است
# و develop حالا کارِ دیپلوی‌نشدهٔ همکاران را دارد (سئوی ساعتی، اکسیت). اگر
# routes/web.phpِ آن نسخه بنشیند، به `HourlyVpsController` و `TunnelProfile`
# اشاره می‌کند که روی سرور نیستند.
#
# این دو فایل بررسی شده‌اند: بینِ نسخهٔ روی سرور و این نسخه **فقط** کامیتِ خودم
# هست (۱۵ خط اضافه)، پس هیچ کارِ دیگری در میان نیست.
#
# ═══ چه چیزی را درست می‌کند ═══
#
# 🔴 «ثبت هزینه» و «تصحیح نگارش با AI» ساخته شده بودند و روی سرور هم بودند،
# ولی نه دکمه‌ای در منو داشتند نه خطی در راهنما — یعنی تنها راهِ استفاده‌شان
# تایپِ اتفاقیِ کلمه بود. قابلیتی که راهِ کشف ندارد، برای کاربر وجود ندارد.
#
# منطقِ merge همان اسکریپتِ اصلی است: پایهٔ خودکار، و تداخلِ واقعی ⇒ دست نزن.
# مقایسه پس از یکسان‌سازیِ پایانِ خط انجام می‌شود (تلهٔ CRLF).
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-parts-shop"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-bale-$STAMP"
HIST=60

mkdir -p "$WORK" "$BK"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }
if [ -d repo/.git ]; then
  git -C repo fetch --depth 400 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 400 --branch develop https://github.com/servernetir/server.git repo || exit 1
fi

MINE="${1:-8d228dd}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
echo "── بکاپ در: $BK"
echo

FILES="
app/Services/Bale/Admin/AdminBaleCommands.php
app/Services/Bale/Admin/AdminBaleRouter.php
"

to_lf() { tr -d '\r' < "$1" > "$2"; }
dist()  { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

UPD=0; STILL=""

for rel in $FILES; do
  dest="$APP/$rel"
  mine_f="$WORK/b.mine"; srv_lf="$WORK/b.srv"; base_f="$WORK/b.base"; cand="$WORK/b.cand"

  git -C repo show "$MINE:website/$rel" > "$mine_f" 2>/dev/null || { echo "SKIP  $rel"; continue; }
  [ -f "$dest" ] || { mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"; echo "NEW   $rel"; UPD=$((UPD+1)); continue; }

  mkdir -p "$BK/$(dirname "$rel")"; cp -p "$dest" "$BK/$rel"
  to_lf "$dest" "$srv_lf"

  if cmp -s "$srv_lf" "$mine_f"; then echo "OK    $rel  (از قبل یکی بود)"; continue; fi

  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$cand" 2>/dev/null || continue
    if cmp -s "$srv_lf" "$cand"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$srv_lf" "$cand"); [ "$d" -lt "$bestd" ] && { bestd=$d; best="$sha"; }
  done

  [ -z "$best" ] && { echo "CF    $rel  ← نسخهٔ ناشناخته روی سرور — دست نخورد"; STILL="$STILL $rel"; continue; }

  if [ "$bestd" -eq 0 ]; then
    cp "$mine_f" "$dest"; echo "UP    $rel  (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1)); continue
  fi

  git -C repo show "$best:website/$rel" > "$base_f"
  m="$WORK/b.merged"; cp "$srv_lf" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"; echo "MG    $rel  (پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط)"; UPD=$((UPD+1))
  else
    echo "CF    $rel  ← تداخلِ واقعی — دست نخورد"; STILL="$STILL $rel"
  fi
done

# ── ضمانتِ اتحاد: دکمه و فعلش و متنِ راهنما باید هر سه باشند ────────────
echo
ok=1
grep -q "ثبت هزینه" "$APP/app/Services/Bale/Admin/AdminBaleRouter.php" || { echo "🔴 دکمهٔ «ثبت هزینه» در منو نیست"; ok=0; }
grep -q "'xp' =>" "$APP/app/Services/Bale/Admin/AdminBaleRouter.php" || { echo "🔴 روتر فعلِ xp را نمی‌شناسد — کلیک بی‌اثر می‌مانَد"; ok=0; }
grep -q "هزینه" "$APP/app/Services/Bale/Admin/AdminBaleCommands.php" || { echo "🔴 راهنما «هزینه» را معرفی نمی‌کند"; ok=0; }
grep -q "تصحیح" "$APP/app/Services/Bale/Admin/AdminBaleCommands.php" || { echo "🔴 راهنما «تصحیح» را معرفی نمی‌کند"; ok=0; }
[ "$ok" -eq 1 ] && echo "✅ دکمه، فعلش، و هر دو خطِ راهنما سرِ جایشان‌اند"

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
if [ -n "$PHPBIN" ]; then
  "$PHPBIN" -l "$APP/app/Services/Bale/Admin/AdminBaleRouter.php" \
    && "$PHPBIN" -l "$APP/app/Services/Bale/Admin/AdminBaleCommands.php" \
    && { cd "$APP" && "$PHPBIN" artisan config:clear >/dev/null && "$PHPBIN" artisan view:clear >/dev/null && echo "کش‌ها پاک شد"; }
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · به‌روزشده: $UPD"
[ -n "$STILL" ] && echo "🔴 تداخل‌دار:$STILL" || echo "✅ تداخلی نبود"
echo
echo "امتحان در بله: «منو» را بفرست — باید دکمهٔ «💸 ثبت هزینه» را ببینی."
echo "و «راهنما» — باید بخش‌های «✨ تصحیح نگارش» و «💸 هزینه» را داشته باشد."
