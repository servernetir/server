#!/usr/bin/env bash
#
# دیپلویِ «نقشِ پشتیبان + جستجوی زندهٔ مشتری + جریانِ پاسخِ تیکت».
#
# اجرا از ترمینالِ cPanel:
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-remove-enamad-footer.sh) [<SHA>]
#
# حذفِ نمادِ اعتماد (enamad) از فوتر — یک ویو، بدونِ مهاجرت.
#
set -u

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-remove-enamad-footer"
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

MINE="${1:-51b79ed1}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"
echo "── بکاپ در: $BK"
echo

# 🔴 ترتیب: مدل و میان‌افزار اول (تا وقتی روت به `staff` اشاره می‌کند،
#    اسمِ مستعار و کلاسش موجود باشند)، بعد کنترلرها و ویوها، **آخر** routes.
APP_FILES="
resources/views/partials/footer.blade.php
"
PUB_FILES=""

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

echo
ok=1
grep -qF "f-seals" "$APP/resources/views/partials/footer.blade.php" || { echo "🔴 بلوکِ f-seals گم شد"; ok=0; }
grep -qF "'enamad'" "$APP/resources/views/partials/footer.blade.php" || { echo "🔴 فیلترِ enamad نیست"; ok=0; }
if [ "$ok" -eq 0 ]; then
  [ -f "$BK/resources/views/partials/footer.blade.php" ] && cp "$BK/resources/views/partials/footer.blade.php" "$APP/resources/views/partials/footer.blade.php" && echo "   فوتر از بکاپ برگشت"
fi
PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
[ -n "$PHPBIN" ] && cd "$APP" && "$PHPBIN" artisan view:clear >/dev/null && echo "کشِ ویو پاک شد"
echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · به‌روزشده: $UPD"
[ -n "$CONFLICTS" ] && echo "🔴 تداخل‌دار:$CONFLICTS" || echo "✅ تداخلی نبود"
echo "امتحان: curl -s https://servernet.cloud/ | grep -c trustseal.enamad.ir   ← باید 0 باشد"
