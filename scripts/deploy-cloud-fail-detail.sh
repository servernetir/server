#!/usr/bin/env bash
#
# دیپلویِ تک‌فایلی: علتِ واقعیِ شکستِ تحویلِ ابری (raw.detail) دیگر گم نمی‌شود.
#
# اجرا از ترمینالِ cPanel:
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-cloud-fail-detail.sh) [<SHA>]
#
# چرا: سرویس #74 با «ساختِ ماشین از قالب انجام نشد» شکست خورد و متنِ خامِ
# Proxmox (علتِ واقعی) در همان لحظه دور ریخته شد. بعد از این دیپلوی، یک
# «تلاش دوباره» علتِ دقیق را در provision_error می‌نشاند و قرنطینهٔ خودکار هم
# آن را می‌بیند. مقایسه با نرمال‌سازیِ LF؛ merge سه‌طرفه با پایهٔ تاریخی.
#
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-cloud-fail-detail"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
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
  git -C repo fetch --depth 600 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 600 --branch develop https://github.com/servernetir/server.git repo || exit 1
fi

MINE="${1:-66c675e}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
echo "── بکاپ در: $BK"
echo

rel="app/Services/Cloud/CloudProvisioner.php"
dest="$APP/$rel"
mine_f="$WORK/a.mine"; srv_lf="$WORK/a.srv"; base_f="$WORK/a.base"; cand="$WORK/a.cand"

git -C repo show "$MINE:website/$rel" > "$mine_f" || { echo "FATAL: فایل در $MINE نیست"; exit 1; }
[ -f "$dest" ] || { echo "FATAL: فایل روی سرور نیست — نصب ناقص است"; exit 1; }

mkdir -p "$BK/$(dirname "$rel")"; cp -p "$dest" "$BK/$rel"
tr -d '\r' < "$dest" > "$srv_lf"

if cmp -s "$srv_lf" "$mine_f"; then
  echo "OK    $rel  (سرور از قبل همین است)"
else
  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$cand" 2>/dev/null || continue
    if cmp -s "$srv_lf" "$cand"; then best="$sha"; bestd=0; break; fi
    d=$(diff "$srv_lf" "$cand" 2>/dev/null | grep -c '^[<>]'); [ "$d" -lt "$bestd" ] && { bestd=$d; best="$sha"; }
  done

  if [ -z "$best" ]; then
    echo "CF    $rel  ← نسخهٔ ناشناخته روی سرور — دست نخورد"
    cp "$dest" "$WORK/conflicts/CloudProvisioner.php.server"; cp "$mine_f" "$WORK/conflicts/CloudProvisioner.php.new"
    exit 1
  elif [ "$bestd" -eq 0 ]; then
    cp "$mine_f" "$dest"; echo "UP    $rel  (سرور = $(git -C repo rev-parse --short "$best"))"
  else
    git -C repo show "$best:website/$rel" > "$base_f"
    m="$WORK/a.merged"; cp "$srv_lf" "$m"
    if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
      cp "$m" "$dest"
      echo "MG    $rel  (پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط — تغییرِ دیگران حفظ شد)"
    else
      echo "CF    $rel  ← تداخلِ واقعی — دست نخورد"
      cp "$dest" "$WORK/conflicts/CloudProvisioner.php.server"; cp "$base_f" "$WORK/conflicts/CloudProvisioner.php.base"; cp "$mine_f" "$WORK/conflicts/CloudProvisioner.php.new"
      exit 1
    fi
  fi
fi

# ── ضمانت: هم تکهٔ تازه هست، هم ارکانِ قبلیِ فایل سرِ جایشان‌اند ──
ok=1
grep -qF "raw']['detail'" "$dest" || { echo "🔴 حفظِ raw.detail ننشست"; ok=0; }
grep -qF "QUARANTINE_PREFIX" "$dest" || { echo "🔴 قرنطینه از فایل پرید"; ok=0; }
grep -qF "function provision" "$dest" || { echo "🔴 provision از فایل پرید"; ok=0; }
grep -qF "refundIfPrepaid" "$dest" || { echo "🔴 بازگشتِ ساعتی از فایل پرید"; ok=0; }
if [ "$ok" -eq 0 ]; then
  cp "$BK/$rel" "$dest"; echo "🔴 برگشت از بکاپ — فایل دست‌نخورده ماند"; exit 1
fi

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
if [ -n "$PHPBIN" ]; then
  if ! "$PHPBIN" -l "$dest"; then
    cp "$BK/$rel" "$dest"; echo "🔴 خطای syntax — برگشت از بکاپ"; exit 1
  fi
  cd "$APP" && "$PHPBIN" artisan config:clear >/dev/null && "$PHPBIN" artisan view:clear >/dev/null && echo "کش‌ها پاک شد"
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK"
echo
echo "قدمِ بعد: در «تحویل‌ها» روی سرویس #74 «تلاش دوباره» بزنید —"
echo "این‌بار provision_error علتِ دقیقِ Proxmox را نشان می‌دهد."
