#!/usr/bin/env bash
# انتشارِ بازطراحیِ پنلِ مشتریِ DNS (رابط کاربری فقط) — ۳ نوامبر ۲۰۲۶
# از ترمینالِ cPanel با کاربرِ servernetcloud:
#
#   ۱) DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-dns-panel-v2.sh) <SHA>
#   ۲) فقط اگر «DRY OK» بود، همان فرمان بدونِ DRY=1
#   ۳) بازگشت: ROLLBACK=<پوشهٔ پشتیبان> bash <(curl …) <SHA>
#
# دامنه: ۱۰ فایلِ مشخص — پنج view در resources/views/account/dns، سه lang/*/dns.php،
# و دو asset (dns.css و dns.js در public_html و کپیِ داخلیِ servernet_app/public).
# هیچ controller، route، config، migration، کرون یا cache flushی در کار نیست.
#
# محافظ‌ها:
#  • هر مقصد پیش از نوشتن باید **دقیقاً** نسخهٔ BASE (پیش از بازطراحی) یا نسخهٔ هدف باشد؛
#    هر اختلافی ⇒ توقف بی‌هیچ نوشتنی (کارِ همکار بازنویسی نمی‌شود).
#  • هدف باید روی develop باشد؛ تغییری جز همین ده فایل بینِ BASE و هدف منتشر نمی‌شود.
#  • lint روی فایل‌های lang و روی خروجیِ کامپایل‌شدهٔ هر view با کامپایلرِ خودِ اپ.
#  • پشتیبانِ خصوصی بیرونِ webroot، نصبِ اتمی هر فایل (mv در همان دیرکتوری)، کنترلِ هشِ پس از نصب.
set -u

DRY="${DRY:-0}"
ROLLBACK="${ROLLBACK:-}"
MINE="${1:?SHA دقیق نسخهٔ هدف الزامی است}"
BASE="da62f84f7b73adf3ebb58e0aa117be6d9e50d0ed"
BRANCH="develop"
APP="$HOME/servernet_app"
WEB="$HOME/public_html"
WORK="$HOME/deploy-dns-panel-v2"
STAMP="$(date +%Y%m%d-%H%M%S)"

APP_FILES=(
  lang/fa/dns.php lang/en/dns.php lang/tr/dns.php
  resources/views/account/dns/index.blade.php
  resources/views/account/dns/zone.blade.php
  resources/views/account/dns/claim.blade.php
  resources/views/account/dns/import.blade.php
  resources/views/account/dns/record-form.blade.php
)
ASSETS=(assets/css/dns.css assets/js/dns.js)

sha() { [ -f "$1" ] && sha256sum "$1" | cut -d' ' -f1 || echo absent; }

if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ] || [ ! -d "$WEB/assets" ]; then
  echo "FATAL: نصبِ لاراول یا وب‌روت پیدا نشد (کاربر: $(id -un)، HOME=$HOME). کاربرِ درست: servernetcloud"; exit 1
fi
if [ -x /opt/cpanel/ea-php84/root/usr/bin/php ]; then PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php; else PHP_BIN="$(command -v php || true)"; fi
[ -n "$PHP_BIN" ] && "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || { echo "FATAL: PHP 8.3+ نیست"; exit 1; }

# ── مقصدها: (مسیرِ مقصد ← مسیرِ منبع در مخزن) ──
DEST=(); SRC=()
for f in "${APP_FILES[@]}"; do DEST+=("$APP/$f"); SRC+=("website/$f"); done
for a in "${ASSETS[@]}"; do
  DEST+=("$WEB/$a"); SRC+=("website/public/$a")
  # کپیِ داخلیِ اپ فقط اگر وجود دارد همگام می‌شود (سرو نمی‌شود؛ CLAUDE.md §public_html)
  if [ -f "$APP/public/$a" ]; then DEST+=("$APP/public/$a"); SRC+=("website/public/$a"); fi
done

# ══ بازگشت ══
if [ -n "$ROLLBACK" ]; then
  [ -f "$ROLLBACK/manifest.tsv" ] || { echo "FATAL: $ROLLBACK/manifest.tsv نیست"; exit 1; }
  bad=0
  while IFS=$'\t' read -r dest before after; do
    cur="$(sha "$dest")"
    if [ "$cur" != "$after" ] && [ "$cur" != "$before" ]; then echo "DRIFT (پس از انتشار عوض شده) $dest"; bad=1; fi
  done < "$ROLLBACK/manifest.tsv"
  [ "$bad" = 0 ] || { echo "FATAL: بازگشت متوقف شد؛ هیچ چیزی نوشته نشد."; exit 3; }
  while IFS=$'\t' read -r dest before after; do
    rel="${dest#"$HOME"/}"
    if [ "$before" = absent ]; then echo "SKIP $rel (پیش از انتشار نبود؛ دست نخورد)"; continue; fi
    tmp="$dest.dnsv2rb.$$"; cp -p "$ROLLBACK/files/$rel" "$tmp" && mv -f "$tmp" "$dest" || { echo "FATAL: $rel"; exit 4; }
    [ "$(sha "$dest")" = "$before" ] && echo "RESTORED $rel" || { echo "FATAL: هشِ بازگشت $rel"; exit 4; }
  done < "$ROLLBACK/manifest.tsv"
  echo "ROLLBACK OK"; exit 0
fi

mkdir -p "$WORK" && chmod 700 "$WORK"
if [ -d "$WORK/repo/.git" ]; then
  git -C "$WORK/repo" fetch -q --depth 200 origin "$BRANCH" || exit 1
else
  git clone -q --depth 200 --branch "$BRANCH" https://github.com/servernetir/server.git "$WORK/repo" || exit 1
fi
R="$WORK/repo"
git -C "$R" rev-parse -q --verify "$MINE^{commit}" >/dev/null || { echo "FATAL: کامیت $MINE نیست"; exit 1; }
git -C "$R" rev-parse -q --verify "$BASE^{commit}" >/dev/null || { echo "FATAL: پایهٔ $BASE در عمقِ کلون نیست"; exit 1; }
TIP="$(git -C "$R" rev-parse -q --verify "refs/remotes/origin/$BRANCH")"
git -C "$R" merge-base --is-ancestor "$MINE" "$TIP" || { echo "FATAL: $MINE روی $BRANCH نیست"; exit 1; }
git -C "$R" merge-base --is-ancestor "$BASE" "$MINE" || { echo "FATAL: $BASE نیای $MINE نیست"; exit 1; }
echo "── هدف: $(git -C "$R" log -1 --format='%h %s' "$MINE")"

STAGE="$WORK/stage-$STAMP"; mkdir -p "$STAGE/new" "$STAGE/base"
drift=0; todo=0; n=${#DEST[@]}
: > "$STAGE/plan.tsv"
for ((i=0;i<n;i++)); do
  d="${DEST[$i]}"; s="${SRC[$i]}"; rel="${d#"$HOME"/}"
  mkdir -p "$STAGE/new/$(dirname "$rel")" "$STAGE/base/$(dirname "$rel")"
  git -C "$R" show "$MINE:$s" > "$STAGE/new/$rel" || { echo "FATAL: $s در هدف نیست"; exit 2; }
  if git -C "$R" cat-file -e "$BASE:$s" 2>/dev/null; then git -C "$R" show "$BASE:$s" > "$STAGE/base/$rel"; bsha="$(sha "$STAGE/base/$rel")"; else bsha=absent; fi
  nsha="$(sha "$STAGE/new/$rel")"; cur="$(sha "$d")"
  if [ "$cur" = "$nsha" ]; then echo "SAME   $rel"
  elif [ "$cur" = "$bsha" ]; then echo "UPDATE $rel"; todo=$((todo+1)); printf '%s\t%s\t%s\n' "$d" "$cur" "$nsha" >> "$STAGE/plan.tsv"
  else echo "DRIFT  $rel  (روی سرور: ${cur:0:12}، پایه: ${bsha:0:12})"; drift=1; fi
done
[ "$drift" = 0 ] || { echo "FATAL: فایلِ تولید با پایه نمی‌خوانَد؛ هیچ چیزی نوشته نشد. اول تطبیق دهید."; exit 3; }

# ── lint: ترجمه‌ها + خروجیِ کامپایل‌شدهٔ viewها با کامپایلرِ خودِ اپ (بی‌نوشتن در cache) ──
for f in "${APP_FILES[@]}"; do
  case "$f" in
    *.blade.php) continue ;;
    *.php) "$PHP_BIN" -l "$STAGE/new/servernet_app/$f" >/dev/null || { echo "FATAL: lint $f"; exit 5; } ;;
  esac
done
cat > "$STAGE/blade-lint.php" <<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';   // مثلِ artisan: autoload پیش از bootstrap
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = app('blade.compiler'); $bad = 0;
foreach (array_slice($argv, 3) as $f) {
    $out = $argv[2].'/'.md5($f).'.php';
    file_put_contents($out, $c->compileString(file_get_contents($f)));
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($out).' 2>&1', $o, $rc);
    if ($rc !== 0 || str_contains(file_get_contents($f), '<'.'?')) { echo "BAD ".basename($f)."\n"; $bad = 1; }
    $o = [];
}
foreach (['fa', 'en', 'tr'] as $l) {
    $a = require $argv[2].'/../new/servernet_app/lang/'.$l.'/dns.php';
    if (! isset($a['ux']['type_help']['A'], $a['ux']['eyebrow'])) { echo "BAD lang $l\n"; $bad = 1; }
}
exit($bad);
PHP
mkdir -p "$STAGE/compiled"
blades=(); for f in "${APP_FILES[@]}"; do case "$f" in *.blade.php) blades+=("$STAGE/new/servernet_app/$f");; esac; done
"$PHP_BIN" "$STAGE/blade-lint.php" "$APP" "$STAGE/compiled" "${blades[@]}" || { echo "FATAL: lintِ Blade/ترجمه"; exit 5; }
grep -l '<?' "$STAGE"/new/public_html/assets/js/dns.js >/dev/null 2>&1 && { echo "FATAL: <? در dns.js"; exit 5; }
echo "LINT OK"

if [ "$todo" = 0 ]; then echo "NOTHING TO DO — همه از قبل نصب‌اند."; exit 0; fi
if [ "$DRY" = 1 ]; then echo "DRY OK — $todo فایل آمادهٔ نصب است. همان فرمان را بدونِ DRY=1 اجرا کنید."; exit 0; fi

# ══ نصب ══
BK="$WORK/backup-$STAMP"; mkdir -p "$BK/files"; chmod 700 "$BK"
cp "$STAGE/plan.tsv" "$BK/manifest.tsv"
while IFS=$'\t' read -r d before after; do
  rel="${d#"$HOME"/}"; [ "$before" = absent ] && continue
  mkdir -p "$BK/files/$(dirname "$rel")"; cp -p "$d" "$BK/files/$rel" || { echo "FATAL: پشتیبانِ $rel"; exit 6; }
done < "$STAGE/plan.tsv"
echo "── پشتیبان: $BK"

while IFS=$'\t' read -r d before after; do
  rel="${d#"$HOME"/}"
  [ "$(sha "$d")" = "$before" ] || { echo "FATAL: $rel همین حالا عوض شد — متوقف. بازگشت: ROLLBACK=$BK"; exit 7; }
  if [ "$before" = absent ]; then mode=644; mkdir -p "$(dirname "$d")"; else mode="$(stat -c %a "$d")"; fi; tmp="$d.dnsv2.$$"
  cp "$STAGE/new/$rel" "$tmp" && chmod "$mode" "$tmp" && mv -f "$tmp" "$d" || { echo "FATAL: نصبِ $rel — بازگشت: ROLLBACK=$BK"; exit 7; }
  [ "$(sha "$d")" = "$after" ] && echo "INSTALLED $rel" || { echo "FATAL: هشِ $rel — بازگشت: ROLLBACK=$BK"; exit 7; }
done < "$STAGE/plan.tsv"

echo "FILES OK — $todo فایل نصب شد. پشتیبان و manifest: $BK"
echo "بازگشت در صورت نیاز: ROLLBACK=$BK bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/$MINE/scripts/deploy-dns-panel-v2.sh) $MINE"
