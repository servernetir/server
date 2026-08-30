#!/usr/bin/env bash
#
# 🔴 رفعِ فوری: سفارش‌های آروان تحویل نمی‌شدند.
#
# آروان روی ساختِ سرور دستِ‌کم یک گروهِ امنیتی می‌خواهد و پیلودِ ما هرگز
# نمی‌فرستاد ⇒ «At least one firewall should be selected» ⇒ هر سفارش شکست.
#
# سه چیز با هم:
#   ۱) نرخِ ارز پشتوانهٔ پایدار می‌گیرد ⇒ کشِ سرد دیگر درگاه را خاموش نمی‌کند
#   ۲) هر پرداخت مبلغِ یکتا می‌گیرد ⇒ دو فاکتورِ هم‌مبلغ جابه‌جا نمی‌شوند
#   ۳) واریزیِ با مبلغِ بیگانه به بازبینیِ دستی می‌رود، نه تسویه
#
# اجرا از ترمینالِ cPanel:
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-crypto-hardening.sh) [<SHA>]
#
# ═══ چه چیزی را می‌بندد ═══
#
# آدرس‌های ترون از استخر بازاستفاده می‌شوند و `TronWatcher` ۵۰ تراکنشِ آخرِ
# آدرس را **بی‌فیلترِ زمانی** برمی‌گرداند. هر واریزیِ قدیمیِ آن آدرس که در
# جدولِ ما ثبت نشده بود، روی اولین پرداختِ بازِ بعدی می‌نشست و فاکتورش را
# «پرداخت‌شده» می‌کرد: سرویس فعال، پول نیامده.
#
# بعد از این دیپلوی، `php artisan crypto:audit` را بزنید تا پرونده‌های
# گذشته پیدا شوند (فقط می‌خواند؛ چیزی را عوض نمی‌کند).
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-crypto-hardening"
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

MINE="${1:-04c14f8}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
echo "── بکاپ در: $BK"
echo

APP_FILES="
app/Services/Cloud/ArvanClient.php
app/Services/Cloud/CloudProvisioner.php
app/Services/ExchangeRate.php
app/Services/Payment/CryptoIssuer.php
app/Services/Payment/CryptoReconciler.php
app/Console/Commands/CryptoAudit.php
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
    keep="$WORK/conflicts/$(basename "$rel")"
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
    keep="$WORK/conflicts/$(basename "$rel")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
}

echo "═══ اپ ($APP) ═══"
for f in $APP_FILES; do apply_one "$f"; done

# ── ضمانتِ اتحاد ──────────────────────────────────────────────────────
echo
ok=1
grep -qF "function securityGroupIds" "$APP/app/Services/Cloud/ArvanClient.php" || { echo "🔴 کشفِ گروهِ امنیتی ننشست"; ok=0; }
grep -qF "'security_groups' =>" "$APP/app/Services/Cloud/ArvanClient.php" || { echo "🔴 پیلودِ ساخت گروه را حمل نمی‌کند"; ok=0; }
grep -qF "function createServer" "$APP/app/Services/Cloud/ArvanClient.php" || { echo "🔴 createServer از فایل پرید"; ok=0; }
grep -qF "function fetchCatalog" "$APP/app/Services/Cloud/ArvanClient.php" || { echo "🔴 کاتالوگ از فایل پرید"; ok=0; }
grep -qF "'firewall'," "$APP/app/Services/Cloud/CloudProvisioner.php" || { echo "🔴 قرنطینهٔ خطای فایروال ننشست"; ok=0; }
grep -qF "CLOCK_SKEW_SECONDS" "$APP/app/Services/Cloud/CloudProvisioner.php" || { echo "🔴 گاردِ زمانیِ رمزارز پرید"; ok=0; }
grep -qF "function settle" "$APP/app/Services/Cloud/CloudProvisioner.php" || { echo "🔴 تسویه از فایل پرید"; ok=0; }
grep -qF "freeOrphanedWallets" "$APP/app/Services/Cloud/CloudProvisioner.php" || { echo "🔴 آزادسازیِ ولتِ یتیم پرید"; ok=0; }

if [ "$ok" -eq 0 ]; then
  echo "🔴 اتحاد ناقص — فایل از بکاپ برمی‌گردد."
  for f in $APP_FILES; do [ -f "$BK/$f" ] && cp "$BK/$f" "$APP/$f" && echo "   $f برگشت"; done
  exit 1
fi
echo "✅ همهٔ اجزا با هم‌اند"

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
if [ -n "$PHPBIN" ]; then
  syn=1
  for f in $APP_FILES; do
    "$PHPBIN" -l "$APP/$f" >/dev/null || { echo "🔴 خطای syntax در $f"; syn=0; }
  done

  if [ "$syn" -eq 0 ]; then
    for f in $APP_FILES; do [ -f "$BK/$f" ] && cp "$BK/$f" "$APP/$f"; done
    echo "🔴 برگشت از بکاپ به‌خاطرِ خطای syntax"; exit 1
  fi

  echo "بدونِ خطای syntax"
  cd "$APP" && "$PHPBIN" artisan config:clear >/dev/null && "$PHPBIN" artisan view:clear >/dev/null && echo "کش‌ها پاک شد"
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · به‌روزشده: $UPD"
[ -n "$CONFLICTS" ] && echo "🔴 تداخل‌دار:$CONFLICTS" || echo "✅ تداخلی نبود"
echo
echo "🔎 قدمِ بعد:"
echo
echo "  ۱) در پنل: زیرساختِ ابری ← پلن‌های قرنطینه‌شدهٔ arvan را باز کنید"
echo "  ۲) یک خریدِ تستیِ کوچک از ایران/تهران — این‌بار باید تحویل شود"
echo
