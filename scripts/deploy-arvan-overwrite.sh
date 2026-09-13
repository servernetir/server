#!/usr/bin/env bash
#
# 🔴 آروان — جایگزینیِ **مستقیمِ** ArvanClient.php، نه merge.
#
# ═══ چرا این‌بار overwrite و نه merge سه‌طرفه ═══
#
# دو نشست هم‌زمان همین فایل را رفع کردند. کامیتِ همکار روی شاخهٔ خودش است و
# جدِ پینِ ما نیست، پس الگوریتمِ «نزدیک‌ترین پایهٔ تاریخی» هیچ پایه‌ای پیدا
# نمی‌کند و هر بار CF می‌دهد — درست، ولی بن‌بست.
#
# جایگزینی این‌جا امن است چون نسخهٔ هدف **همان نسخهٔ همکار است** به‌علاوهٔ سه
# اصلاح (نام به‌جای شناسه، آرایهٔ آبجکتِ name، غنی‌سازیِ پیامِ خطا). و برای
# اینکه این ادعا حرف نماند، گاردِ پایین صریح می‌سنجد که ارکانِ کارِ او —
# راهِ فرارِ دستی، کشفِ چندمسیره، لاگِ ردشدن — سرِ جایشان‌اند.
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-arvan-final"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"

# ═══ اثباتِ مقصد — پیش از هر نوشتنی ═══
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "🔴 «$APP» نصبِ لاراول نیست (artisan یا vendor نیست)."
  echo "   کاربرِ درست: servernetcloud  ·  چاره:  su - servernetcloud"
  exit 1
fi

FREE_MB=$(df -Pm "$HOME" | awk 'NR==2{print $4}')
if [ "${FREE_MB:-0}" -lt 500 ]; then
  echo "🔴 فضای آزاد کم است (${FREE_MB}MB):  rm -rf ~/deploy-*/repo"
  exit 1
fi

mkdir -p "$WORK" "$BK"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }
if [ -d repo/.git ]; then
  git -C repo fetch --depth 600 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 600 --branch develop https://github.com/servernetir/server.git repo || exit 1
fi

MINE="${1:-b8306a6}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
echo "── بکاپ در: $BK"
echo

rel="app/Services/Cloud/ArvanClient.php"
dest="$APP/$rel"
new="$WORK/a.new"

git -C repo show "$MINE:website/$rel" > "$new" || { echo "FATAL: فایل در $MINE نیست"; exit 1; }
[ -f "$dest" ] || { echo "FATAL: فایل روی سرور نیست — نصب ناقص"; exit 1; }

mkdir -p "$BK/$(dirname "$rel")"
cp -p "$dest" "$BK/$rel"
echo "── نسخهٔ فعلیِ سرور در بکاپ: $BK/$rel"

# تفاوت را نشان بده تا جایگزینی کور نباشد
BEFORE=$(wc -l < "$dest"); AFTER=$(wc -l < "$new")
echo "── خطوط: سرور $BEFORE → هدف $AFTER"

cp "$new" "$dest"
echo "OW    $rel  (جایگزینیِ مستقیم)"
echo

# ── ضمانتِ اتحاد: هم رفعِ تازه، هم کارِ همکار ──
ok=1
grep -qF "'security_groups' => array_map" "$dest" || { echo "🔴 شکلِ درستِ security_groups نیست"; ok=0; }
grep -qF "abrak.securityGroupName" "$dest" || { echo "🔴 توضیحِ شکلِ آروان نیست"; ok=0; }
grep -qF "قابلِ اقدام" "$dest" || { echo "🔴 غنی‌سازیِ پیامِ خطا نیست"; ok=0; }
# ارکانِ کارِ همکار (طبقِ نسخهٔ b386f01 که پایه شد)
grep -qF "arvan_security_group" "$dest" || { echo "🔴 راهِ فرارِ دستیِ همکار پرید"; ok=0; }
grep -qF "securitygroups" "$dest" || { echo "🔴 کشفِ چندمسیرهٔ همکار پرید"; ok=0; }
# ارکانِ خودِ درایور
for f in "function createServer" "function fetchCatalog" "function listServers" "function deleteServer" "function testConnection"; do
  grep -qF "$f" "$dest" || { echo "🔴 $f از فایل پرید"; ok=0; }
done

if [ "$ok" -eq 0 ]; then
  cp "$BK/$rel" "$dest"
  echo "🔴 اتحاد ناقص — نسخهٔ سرور برگشت، هیچ‌چیز عوض نشد."
  exit 1
fi
echo "✅ رفعِ تازه و کارِ همکار، هر دو سرِ جایشان"

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
if [ -n "$PHPBIN" ]; then
  if ! "$PHPBIN" -l "$dest"; then
    cp "$BK/$rel" "$dest"; echo "🔴 خطای syntax — برگشت از بکاپ"; exit 1
  fi
  echo "بدونِ خطای syntax"
  cd "$APP" && "$PHPBIN" artisan config:clear >/dev/null && "$PHPBIN" artisan view:clear >/dev/null && echo "کش‌ها پاک شد"
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK/$rel"
echo
echo "قدمِ بعد:"
echo "  ۱) php artisan cloud:reopen arvan --force"
echo "  ۲) تلاشِ دوباره روی سرویسِ #93 (دستورش در گفتگو)"
