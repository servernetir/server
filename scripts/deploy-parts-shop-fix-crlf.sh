#!/usr/bin/env bash
#
# رفعِ تداخلِ باقی‌مانده از deploy-parts-shop.sh — ریشه: پایانِ خطِ ویندوزی.
#
# اجرا از ترمینالِ cPanel:
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-parts-shop-fix-crlf.sh) [<SHA>]
#
# ═══ چه شد و چرا ═══
#
# 🔴 `app/Http/Controllers/Admin/TicketController.php` روی سرور با پایانِ خطِ
# **CRLF** ذخیره شده بود (یک نشستِ قبلی با روشی آپلودش کرده که خط‌ها را تبدیل
# می‌کند). گیت همه‌چیز را LF نگه می‌دارد.
#
# نتیجه: در اسکریپتِ اصلی، `cmp` با **هیچ** نسخهٔ تاریخی برابر نشد و `diff`
# هر خطِ فایل را «تغییرکرده» شمرد. پس پایهٔ خودکار عملاً یک حدسِ بی‌ربط شد و
# merge سه‌طرفه روی کلِ فایل تداخل داد — در حالی که محتوای واقعیِ سرور با یکی
# از نسخه‌های تاریخی **دقیقاً یکی** بود.
#
# ⚠️ پیامدش بی‌ضرر نبود: `routes/web.php` نشست و روتِ
# `POST /admin/tickets/{ticket}/polish` را ثبت کرد، ولی متدِ `polish` در
# کنترلرِ دست‌نخوردهٔ سرور نیست ⇒ دکمهٔ «تصحیح نگارش با AI» در پنلِ تیکت ۵۰۰
# می‌دهد. تا وقتی این فایل ننشیند، آن دکمه شکسته است.
#
# ═══ راه‌حل ═══
#
# همان منطقِ اسکریپتِ اصلی، ولی مقایسه **پس از یکسان‌سازیِ پایانِ خط**:
#   ۱) کپیِ سرور را به LF نرمال کن.
#   ۲) اگر نرمال‌شده دقیقاً برابرِ یکی از نسخه‌های تاریخی بود ⇒ کسی محتوا را
#      عوض نکرده، فقط پایانِ خط فرق داشته ⇒ نسخهٔ تازه امن است.
#   ۳) وگرنه merge سه‌طرفه با همان پایهٔ نرمال‌شده؛ تداخلِ واقعی ⇒ دست نزن.
#
# هیچ فایلی بدونِ اثباتِ بند ۲ یا موفقیتِ بند ۳ بازنویسی نمی‌شود.
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-parts-shop"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-crlf-$STAMP"
HIST=60

mkdir -p "$WORK" "$BK"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }
[ -d repo/.git ] || { echo "FATAL: $WORK/repo نیست — اول اسکریپتِ اصلی را بزن"; exit 1; }
git -C repo fetch --depth 400 origin develop || { echo "FATAL: fetch"; exit 1; }

MINE="${1:-aefcc0a}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
echo "── بکاپ در: $BK"
echo

FILES="app/Http/Controllers/Admin/TicketController.php"

to_lf() { tr -d '\r' < "$1" > "$2"; }
dist()  { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

FIXED=0; STILL=""

for rel in $FILES; do
  dest="$APP/$rel"
  mine_f="$WORK/f.mine"; srv_lf="$WORK/f.srv"; base_f="$WORK/f.base"; cand="$WORK/f.cand"

  git -C repo show "$MINE:website/$rel" > "$mine_f" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; continue; }
  [ -f "$dest" ] || { echo "SKIP  (روی سرور نیست)  $rel"; continue; }

  mkdir -p "$BK/$(dirname "$rel")"; cp -p "$dest" "$BK/$rel"

  crlf=$(grep -c $'\r' "$dest" || true)
  to_lf "$dest" "$srv_lf"
  echo "── $rel"
  echo "   خطوطِ CRLF روی سرور: $crlf"

  if cmp -s "$srv_lf" "$mine_f"; then
    cp "$mine_f" "$dest"; echo "   OK   محتوا از قبل یکی بود؛ فقط پایانِ خط اصلاح شد"; FIXED=$((FIXED+1)); continue
  fi

  # آیا نسخهٔ نرمال‌شدهٔ سرور دقیقاً یکی از نسخه‌های تاریخی است؟
  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$cand" 2>/dev/null || continue
    if cmp -s "$srv_lf" "$cand"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$srv_lf" "$cand")
    if [ "$d" -lt "$bestd" ]; then bestd=$d; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "   CF   هیچ نسخهٔ تاریخی‌ای پیدا نشد — دست نخورد"; STILL="$STILL $rel"; continue
  fi

  if [ "$bestd" -eq 0 ]; then
    cp "$mine_f" "$dest"
    echo "   UP   سرور دقیقاً = $(git -C repo rev-parse --short "$best") بود (فقط CRLF فرق داشت) ⇒ نسخهٔ تازه نشست"
    FIXED=$((FIXED+1)); continue
  fi

  git -C repo show "$best:website/$rel" > "$base_f"
  m="$WORK/f.merged"; cp "$srv_lf" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"
    echo "   MG   merge با پایهٔ $(git -C repo rev-parse --short "$best") (فاصله $bestd خط) — تغییرِ دیگران حفظ شد"
    FIXED=$((FIXED+1))
  else
    echo "   CF   تداخلِ واقعی حتی پس از نرمال‌سازی — دست نخورد"; STILL="$STILL $rel"
  fi
done

# ── ضمانتِ اتحاد: روت و متد باید با هم باشند ────────────────────────────
echo
ok=1
if grep -qF "'polish'" "$APP/routes/web.php" 2>/dev/null; then
  grep -q "function polish" "$APP/app/Http/Controllers/Admin/TicketController.php" 2>/dev/null \
    || { echo "🔴 routes روتِ polish دارد ولی کنترلر متدِ polish را ندارد — دکمهٔ «تصحیح نگارش» ۵۰۰ می‌دهد"; ok=0; }
fi
[ "$ok" -eq 1 ] && echo "✅ روتِ polish و متدش هر دو سرِ جایشان‌اند"

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
[ -n "$PHPBIN" ] && { cd "$APP" && "$PHPBIN" -l app/Http/Controllers/Admin/TicketController.php && "$PHPBIN" artisan config:clear >/dev/null && "$PHPBIN" artisan route:clear >/dev/null && "$PHPBIN" artisan view:clear >/dev/null && echo "کش‌ها پاک شد"; }

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · اصلاح‌شده: $FIXED"
[ -n "$STILL" ] && echo "🔴 هنوز تداخل‌دار:$STILL" || echo "✅ تداخلی نماند"
