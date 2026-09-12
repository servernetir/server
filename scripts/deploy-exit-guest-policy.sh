#!/usr/bin/env bash
#
# دیپلویِ «سیاستِ شبکهٔ داخلی + تخصیصِ پورت + رفعِ اسکنِ Proxmox».
#
# اجرا از ترمینالِ cPanel (کاربرِ servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-exit-guest-policy.sh) [<SHA-یا-BRANCH>]
#
# ═══ چه چیزی این دیپلوی ندارد ═══
#   • هیچ مهاجرتِ دیتابیسی — صفر
#   • هیچ فایلی در public/ ⇒ public_html اصلاً باز نمی‌شود
#   • هیچ رونویسیِ کاملِ routes/web.php — فقط درجِ idempotent
#   • .env خوانده و نوشته نمی‌شود
#
# ═══ قانونِ ایمنی ═══
#
# 🔴 پروداکشنِ این پروژه لزوماً چکِ‌اوتِ یک کامیت نیست — ده‌ها اسکریپتِ دیپلویِ
# جداگانه فایل‌ها را تکه‌تکه نشانده‌اند. پس برای هر فایل **پایه را از روی
# محتوای واقعیِ سرور کشف می‌کنیم** (`git hash-object` → جست‌وجوی همان blob در
# تاریخچه) و بعد merge سه‌طرفه می‌زنیم. تداخل یا خطای نحوی ⇒ آن فایل
# **دست‌نخورده** می‌مانَد و گزارش می‌شود. اجرای دوباره بی‌خطر است.
#
set -uo pipefail

REF="${1:-develop}"
APP="$HOME/servernet_app"
WORK="$HOME/deploy-guestpolicy"
REPO="${DEPLOY_REPO:-https://github.com/servernetir/server.git}"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$WORK/backup-$STAMP"

ok(){   printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn(){ printf '  \033[33m!\033[0m %s\n' "$*"; }
bad(){  printf '  \033[31m✗\033[0m %s\n' "$*"; }
die(){  bad "$*"; exit 1; }

FILES=(
  app/Http/Controllers/Admin/CloudAttachController.php
  app/Http/Controllers/Admin/ExitInfraController.php
  app/Http/Controllers/Agent/PullController.php
  app/Models/CloudInstance.php
  app/Services/Cloud/ProxmoxClient.php
  app/Services/Cloud/PublicPortAllocator.php
  app/Support/GuestPolicySnapshot.php
  resources/views/admin/exit-infra-import.blade.php
  resources/views/admin/exit-infra.blade.php
)

echo "═══ ۱) اثباتِ مقصد ═══"
# 🔴 پیش از هر نوشتنی. درسِ ثبت‌شده: اجرا با کاربرِ اشتباه یعنی $HOME عوض
# می‌شود، فایل‌ها جایی می‌نشینند که سایت آن‌جا نیست، و گاردِ اتحاد سبز می‌شود
# چون همان فایل‌هایی را می‌سنجد که خودش تازه ساخته.
[[ -d "$APP" ]]         || die "پیدا نشد: $APP — کاربرِ درست servernetcloud است."
[[ -f "$APP/artisan" ]] || die "$APP اپِ لاراول نیست (artisan نیست)."
[[ -d "$APP/vendor" ]]  || die "$APP/vendor نیست — این نصبِ واقعی نیست."
[[ -f "$APP/app/Http/Controllers/Admin/ExitInfraController.php" ]] \
  || die "ExitInfraController روی سرور نیست — صفحهٔ «زیرساختِ اکسیت» هرگز دیپلوی نشده."

FREE_MB=$(df -Pm "$HOME" | awk 'NR==2{print $4}')
[[ "${FREE_MB:-0}" -ge 500 ]] || die "فضای آزاد کم است (${FREE_MB}MB). اول: rm -rf ~/deploy-*/repo"

PHP="$(command -v /opt/cpanel/ea-php84/root/usr/bin/php 2>/dev/null || command -v php)"
[[ -x "$PHP" ]] || die "php پیدا نشد"
command -v git >/dev/null 2>&1 || die "git روی سرور نیست"
ok "اپ : $APP"
ok "php: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"

echo
echo "═══ ۲) مخزن ═══"
mkdir -p "$WORK" "$BK" || die "ساختِ کارگاه نشد"
if [[ -d "$WORK/repo/.git" ]]; then
  git -C "$WORK/repo" fetch --all --prune -q || die "fetch نشد"
else
  git clone -q "$REPO" "$WORK/repo" || die "clone نشد"
fi
cd "$WORK/repo" || die "cd نشد"
SHA="$(git rev-parse --verify "origin/$REF" 2>/dev/null || git rev-parse --verify "$REF" 2>/dev/null)"
[[ -n "$SHA" ]] || die "ref پیدا نشد: $REF — آیا شاخه push شده؟"
ok "نسخهٔ هدف: $(git log -1 --format='%h %s' "$SHA")"
ok "بکاپ در : $BK"

echo
echo "═══ ۳) نصب فایل‌به‌فایل ═══"
APPLIED=(); SAME=(); HELD=()

for rel in "${FILES[@]}"; do
  f="website/$rel"; name="$(basename "$rel")"; live="$APP/$rel"

  git cat-file -e "$SHA:$f" 2>/dev/null || { bad "$name — در این نسخه نیست"; HELD+=("$name (نبود)"); continue; }

  if [[ ! -f "$live" ]]; then
    mkdir -p "$(dirname "$live")"
    git show "$SHA:$f" > "$live" || die "نوشتن نشد: $rel"
    ok "$name — تازه، نصب شد"; APPLIED+=("$name"); continue
  fi

  mkdir -p "$BK/$(dirname "$rel")"; cp -p "$live" "$BK/$rel"

  LIVEHASH="$(git hash-object "$live")"
  if [[ "$LIVEHASH" == "$(git rev-parse "$SHA:$f")" ]]; then
    ok "$name — از قبل به‌روز"; SAME+=("$name"); continue
  fi

  # 🔴 پایه را از روی محتوای واقعیِ سرور پیدا کن، نه از فرضِ «سرور = develop».
  BASE=""
  while read -r c; do
    [[ "$(git rev-parse "$c:$f" 2>/dev/null || true)" == "$LIVEHASH" ]] && { BASE="$c"; break; }
  done < <(git rev-list --all -- "$f" | head -400)

  if [[ -z "$BASE" ]]; then
    warn "$name — نسخهٔ روی سرور در هیچ کامیتی نیست (ویرایشِ دستی). دست‌نخورده ماند."
    HELD+=("$name (پایهٔ ناشناخته)"); continue
  fi

  git show "$BASE:$f" > "$WORK/.base"; git show "$SHA:$f" > "$WORK/.mine"; cp "$live" "$WORK/.try"
  if ! git merge-file -q "$WORK/.try" "$WORK/.base" "$WORK/.mine" || grep -q '^<<<<<<<' "$WORK/.try"; then
    warn "$name — تداخل با تغییرِ روی سرور. دست‌نخورده ماند."
    HELD+=("$name (تداخل)"); continue
  fi
  if [[ "$rel" == *.php ]] && ! "$PHP" -l "$WORK/.try" >/dev/null 2>&1; then
    bad "$name — نتیجهٔ merge از نظرِ نحوی خراب است. دست‌نخورده ماند."
    HELD+=("$name (php -l)"); continue
  fi
  cp "$WORK/.try" "$live"; ok "$name — merge شد (پایه ${BASE:0:7})"; APPLIED+=("$name")
done

echo
echo "═══ ۴) روت‌ها — درج، نه رونویسی ═══"
# 🔴 routes/web.php هرگز کامل آپلود نمی‌شود. دو بار در این پروژه آپلودِ کاملش
# کارِ توسعه‌دهندهٔ دیگر را پاک کرده.
RF="$APP/routes/web.php"
[[ -f "$RF" ]] || die "routes/web.php نیست"
cp -p "$RF" "$BK/routes-web.php.bak"

BLK="$WORK/blocks"; rm -rf "$BLK"; mkdir -p "$BLK"
for n in anchor-admin block-admin marker-admin anchor-agent block-agent marker-agent; do
  git show "$SHA:scripts/exit-guest-policy/routes/$n.txt" > "$BLK/$n.txt" \
    || die "فایلِ بلوکِ $n در این نسخه نیست"
done

"$PHP" -r '
// متنِ بلوک‌ها از فایل خوانده می‌شود، نه از رشتهٔ داخلِ دستور: فرارِ متنِ
// فارسی داخلِ -r کامنت‌ها را می‌خورَد و نتیجهٔ روی سرور با نسخهٔ گیت یکی
// نمی‌شود — یعنی دیپلویِ بعدی دیگر «پایه» را پیدا نمی‌کند.
$f = $argv[1]; $d = $argv[2];
$s = $orig = file_get_contents($f); $add = 0;

$ins = function (&$s, $name) use (&$add, $d) {
    $anchor = file_get_contents("$d/anchor-$name.txt");
    $block  = file_get_contents("$d/block-$name.txt");
    $marker = file_get_contents("$d/marker-$name.txt");
    if (str_contains($s, $marker)) { echo "  = $name از قبل هست\n"; return; }
    $n = substr_count($s, $anchor);
    if ($n === 0) { fwrite(STDERR, "  ! لنگرِ $name پیدا نشد\n"); return; }
    if ($n > 1)  { fwrite(STDERR, "  ! لنگرِ $name چند بار هست ($n) — درج نشد\n"); return; }
    $s = str_replace($anchor, $anchor.$block, $s); $add++;
    echo "  + $name درج شد\n";
};
$ins($s, "admin"); $ins($s, "agent");

if ($s === $orig) { echo "  هیچ درجی لازم نبود.\n"; exit(0); }
$tmp = tempnam(sys_get_temp_dir(), "rt"); file_put_contents($tmp, $s);
exec(escapeshellarg(PHP_BINARY)." -l ".escapeshellarg($tmp)." 2>&1", $o, $rc); unlink($tmp);
if ($rc !== 0) { fwrite(STDERR, "  x نتیجه از نظرِ نحوی خراب است — نوشته نشد\n".implode("\n",$o)."\n"); exit(1); }
file_put_contents($f, $s); echo "  \u{2713} $add بلوک درج شد\n";
' "$RF" "$BLK" || die "درجِ روت‌ها ناموفق — routes/web.php دست‌نخورده ماند (بکاپ: $BK/routes-web.php.bak)"

echo
echo "═══ ۵) پاک‌سازیِ کش ═══"
cd "$APP" || die "cd نشد"
for c in view:clear config:clear route:clear cache:clear; do
  "$PHP" artisan $c >/dev/null 2>&1 && ok "$c" || warn "$c نشد"
done

echo
echo "═══ ۶) راستی‌آزمایی ═══"
RL="$("$PHP" artisan route:list 2>/dev/null)"
echo "$RL" | grep -q 'agent/guestpolicy'            && ok "روتِ guestpolicy عامل" || bad "روتِ guestpolicy دیده نشد"
echo "$RL" | grep -q 'guestpolicy/ack'              && ok "روتِ تأییدِ اعمال"      || bad "روتِ ack دیده نشد"
echo "$RL" | grep -q 'admin.exit-infra.lan'         && ok "روتِ شبکهٔ داخلیِ پنل"  || bad "روتِ lan دیده نشد"
echo "$RL" | grep -q 'admin.exit-infra.sync-ports'  && ok "روتِ تخصیصِ پورت"       || bad "روتِ sync-ports دیده نشد"

echo
echo "═══ خلاصه ═══"
echo "  نصب/به‌روز : ${#APPLIED[@]}  ${APPLIED[*]:-—}"
echo "  بدون‌تغییر : ${#SAME[@]}  ${SAME[*]:-—}"
echo "  دست‌نخورده : ${#HELD[@]}  ${HELD[*]:-—}"
echo "  بکاپ       : $BK"
echo
if (( ${#HELD[@]} > 0 )); then
  warn "فایل‌های بالا دست‌نخورده ماندند — هیچ‌چیزی پاک نشد. همین خروجی را بفرست."
  exit 2
fi
ok "تمام."
echo
echo "گامِ بعد: در /admin/exit-infra دکمهٔ «تخصیصِ پورت به این ماشین‌ها» را یک بار بزن."
echo "تا نصبِ عامل روی هاستِ ایران، ستونِ «شبکهٔ داخلی» عمداً «اعمال‌نشدنی» می‌مانَد."
exit 0
