#!/usr/bin/env bash
# انتشارِ «/gpu به پرسش‌های پیش از خرید جواب می‌دهد» + بازگرداندنِ کلیدهای جاماندهٔ
# /gpu و /vps/hourly — از ترمینالِ cPanel با کاربرِ servernetcloud.
#
#   ۱) DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-gpu-page-faq.sh) <SHA>
#   ۲) فقط اگر همه OK/MG/UP و ترجمه‌ها بی‌خطا بودند، همان فرمان بدونِ DRY=1
#
# 🔴 چرا (۴ مهر ۱۴۰۵): ویوهای شاخهٔ hourly-credit-transparency زنده‌اند ولی
#    کلیدهایشان نه — ۸۸ کلیدِ خامِ `ui.*` روی /gpu (حتی داخلِ FAQPage) و ۴۰ روی
#    /vps/hourly. این انتشار همان کلیدها + ۶ پرسشِ تازه و متنِ درستِ ComfyUI را
#    **کلید به کلید** می‌نشانَد (scripts/lang-apply-keys.php) و فقط یک فایلِ کد
#    دارد: pages/gpu.blade.php (کنترلر و پارشال از قبل روی سرورند).
# بی‌مهاجرت، بی‌روت، بی‌دارایی.
set -u

DRY="${DRY:-0}"
MINE="${1:?SHA دقیق نسخهٔ هدف الزامی است}"
BRANCH="${BRANCH:-feature/gpu-page-faq}"
APP="$HOME/servernet_app"
WORK="$HOME/deploy-gpu-page-faq"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$WORK/backup-$STAMP"
STAGE="$WORK/stage-$STAMP"
HIST=160
SITE="${SITE:-https://servernet.cloud}"

# ── اثباتِ مقصد پیش از هر نوشتنی (درسِ «گاردی که روی سرورِ اشتباه سبز شد») ──
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ] || [ ! -f "$APP/resources/views/pages/gpu.blade.php" ]; then
  echo "FATAL: $APP نصبِ واقعیِ Laravel نیست (user=$(id -un) HOME=$HOME)؛ با کاربرِ servernetcloud اجرا کنید."
  exit 1
fi
# ویوِ زنده باید همان نسخهٔ hourly-credit باشد که به پارشالِ پایانِ اعتبار تکیه دارد
if [ ! -f "$APP/resources/views/partials/credit-lifecycle.blade.php" ] || [ ! -f "$APP/app/Services/Cloud/HourlyHold.php" ]; then
  echo "FATAL: پارشالِ credit-lifecycle یا HourlyHold روی سرور نیست — پیش‌فرضِ این انتشار غلط است؛ هیچ فایلی نوشته نشد."
  exit 1
fi

if [ -x /opt/cpanel/ea-php84/root/usr/bin/php ]; then
  PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php
else
  PHP_BIN="$(command -v php 2>/dev/null || true)"
fi
if [ -z "$PHP_BIN" ] || ! "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then
  echo "FATAL: PHP 8.4 در دسترس نیست؛ هیچ فایلی نوشته نشد."
  exit 1
fi

FREE_MB="$(df -Pm "$HOME" | awk 'NR==2{print $4}')"
if [ "${FREE_MB:-0}" -lt 200 ]; then
  echo "FATAL: فضای آزاد کمتر از 200MB است."
  exit 1
fi

mkdir -p "$WORK" "$BK" "$STAGE" "$WORK/conflicts"
if [ -d "$WORK/repo/.git" ]; then
  git -C "$WORK/repo" fetch --depth 500 origin "$BRANCH" || exit 1
else
  git clone --depth 500 --branch "$BRANCH" https://github.com/servernetir/server.git "$WORK/repo" || exit 1
fi
git -C "$WORK/repo" fetch --depth 500 origin develop:refs/remotes/origin/develop 2>/dev/null || true
git -C "$WORK/repo" rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || {
  echo "FATAL: کامیت $MINE در مخزن نیست."; exit 1;
}

APP_FILES="
resources/views/pages/gpu.blade.php
"
LANG_FILES="
lang/fa/ui.php
lang/en/ui.php
lang/tr/ui.php
"

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }
distance() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]' || true; }
CONFLICTS=""
UPD=0

# apply_one <rel> — ادغامِ سه‌طرفه، فقط در stage
apply_one() {
  rel="$1"; dest="$APP/$rel"; src="website/$rel"
  mine="$WORK/mine.tmp"; dest_n="$WORK/dest.tmp"; base="$WORK/base.tmp"
  git -C "$WORK/repo" show "$MINE:$src" > "$WORK/mine.raw" 2>/dev/null || {
    echo "FATAL: $rel در نسخهٔ هدف نیست"; CONFLICTS="$CONFLICTS $rel"; return;
  }
  normalize "$WORK/mine.raw" "$mine"

  if [ ! -f "$dest" ]; then
    # این فایل باید از قبل باشد؛ NEW یعنی مقصدِ اشتباه
    echo "FATAL: $rel روی سرور نیست (NEW = پرچمِ قرمز)"; CONFLICTS="$CONFLICTS $rel"; return
  fi
  normalize "$dest" "$dest_n"
  if cmp -s "$dest_n" "$mine"; then echo "OK   $rel"; return; fi

  # `--full-history` واجب است: نسخهٔ زنده از والدِ دومِ یک مرج می‌آید
  best=""; bestd=999999999
  for sha in $(git -C "$WORK/repo" log --full-history --format=%H -n "$HIST" "$MINE" -- "$src"); do
    git -C "$WORK/repo" show "$sha:$src" > "$WORK/candidate.raw" 2>/dev/null || continue
    normalize "$WORK/candidate.raw" "$WORK/candidate.tmp"
    if cmp -s "$dest_n" "$WORK/candidate.tmp"; then best="$sha"; bestd=0; break; fi
    d="$(distance "$dest_n" "$WORK/candidate.tmp")"
    if [ "$d" -lt "$bestd" ]; then bestd="$d"; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "CF   $rel — پایهٔ تاریخی پیدا نشد"; CONFLICTS="$CONFLICTS $rel"; return
  fi
  if [ "$bestd" -eq 0 ]; then
    echo "UP   $rel (سرور = $(git -C "$WORK/repo" rev-parse --short "$best") — جایگزینیِ بی‌ریسک)"
    mkdir -p "$STAGE/$(dirname "$rel")"; cp "$mine" "$STAGE/$rel"
    UPD=$((UPD+1)); return
  fi
  git -C "$WORK/repo" show "$best:$src" > "$WORK/base.raw" 2>/dev/null || true
  normalize "$WORK/base.raw" "$base"
  merged="$WORK/merged.tmp"
  if ! git merge-file -p "$dest_n" "$base" "$mine" > "$merged"; then
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine" "$keep.new"; cp "$merged" "$keep.merged"
    echo "CF   $rel — تداخل؛ فایلِ زنده دست نخورد"; CONFLICTS="$CONFLICTS $rel"; return
  fi
  echo "MG   $rel (base $(git -C "$WORK/repo" rev-parse --short "$best"), فاصله $bestd)"
  mkdir -p "$STAGE/$(dirname "$rel")"; cp "$merged" "$STAGE/$rel"
  UPD=$((UPD+1))
}

# apply_lang <rel> <dry 0|1> — کلیدهای عوض‌شده روی فایلِ زندهٔ ترجمه
apply_lang() {
  rel="$1"; dry="$2"
  git -C "$WORK/repo" show "$LANG_BASE:website/$rel" > "$WORK/lang-base.php" 2>/dev/null || {
    echo "FATAL: نسخهٔ پایهٔ $rel نیست"; CONFLICTS="$CONFLICTS $rel"; return;
  }
  git -C "$WORK/repo" show "$MINE:website/$rel" > "$WORK/lang-mine.php" 2>/dev/null || {
    echo "FATAL: نسخهٔ هدفِ $rel نیست"; CONFLICTS="$CONFLICTS $rel"; return;
  }
  if [ ! -f "$APP/$rel" ]; then
    echo "FATAL: $rel روی سرور نیست"; CONFLICTS="$CONFLICTS $rel"; return
  fi

  if [ "$dry" = "1" ]; then
    echo "── $rel"
    "$PHP_BIN" "$LANG_TOOL" "$WORK/lang-base.php" "$WORK/lang-mine.php" "$APP/$rel" --dry \
      || CONFLICTS="$CONFLICTS $rel"
    return
  fi

  mkdir -p "$BK/$(dirname "$rel")"
  cp -p "$APP/$rel" "$BK/$rel"

  if ! "$PHP_BIN" "$LANG_TOOL" "$WORK/lang-base.php" "$WORK/lang-mine.php" "$APP/$rel"; then
    echo "FATAL: اعمالِ کلیدهای $rel شکست خورد — بازگردانی از پشتیبان"
    cp -p "$BK/$rel" "$APP/$rel"; exit 3
  fi
  "$PHP_BIN" -l "$APP/$rel" >/dev/null || {
    echo "FATAL lint: $rel — بازگردانی از پشتیبان"; cp -p "$BK/$rel" "$APP/$rel"; exit 3;
  }
}

# ابزار را از خودِ کامیتِ هدف بیرون بکش (درختِ کاریِ کلونِ قبلی کهنه می‌مانَد)
LANG_TOOL="$WORK/lang-apply-keys.php"
git -C "$WORK/repo" show "$MINE:scripts/lang-apply-keys.php" > "$LANG_TOOL" 2>/dev/null || {
  echo "FATAL: scripts/lang-apply-keys.php در نسخهٔ هدف نیست"; exit 2;
}
"$PHP_BIN" -l "$LANG_TOOL" >/dev/null || { echo "FATAL: ابزارِ ترجمه سالم نیست"; exit 2; }

# پایه = نقطهٔ انشعاب از develop ⇒ «عوض‌شده» = همهٔ کلیدهای hourly-credit + این انتشار
#
# 🔴 پین‌شده، نه `merge-base` در لحظه. تمرین روی HOMEِ ساختگی: با developِ کهنهٔ
#    کلون، پایه cdf31dfb شد و ترکی ۷۴ کلیدِ بی‌ربط (wt_*، lk_*) را «جایگزین»
#    می‌کرد — یعنی برگرداندنِ مقدارِ تازه‌ترِ سرور. پایهٔ درست ba710796 است
#    (merge-base با origin/develop در ۴ مهر)؛ هیچ کلیدی از ۱۳۸/۱۳۸/۲۱۸ را
#    develop یا شاخه‌های اخیر بعد از آن عوض نکرده‌اند (بررسی‌شده).
LANG_BASE="${LANG_BASE:-ba710796}"
git -C "$WORK/repo" rev-parse --verify "$LANG_BASE^{commit}" >/dev/null 2>&1 || LANG_BASE=""
if [ -z "${LANG_BASE:-}" ]; then
  echo "FATAL: نقطهٔ انشعاب از develop پیدا نشد؛ LANG_BASE=<sha> را دستی بدهید."
  exit 2
fi
echo "target $(git -C "$WORK/repo" rev-parse --short "$MINE") · LANG base $(git -C "$WORK/repo" rev-parse --short "$LANG_BASE")"

RAW_BEFORE="$(curl -fsSL "$SITE/gpu" 2>/dev/null | grep -o '>ui\.[a-z0-9_]*<\|"ui\.[a-z0-9_]*"' | sort -u | wc -l)"
echo "کلیدِ خام روی /gpu پیش از انتشار: $RAW_BEFORE"

# ═══ پیش‌پرواز: هیچ‌چیز روی فایلِ زنده نوشته نمی‌شود ═══
for rel in $APP_FILES; do apply_one "$rel"; done
for rel in $LANG_FILES; do apply_lang "$rel" 1; done

if [ -n "$CONFLICTS" ]; then
  echo "FATAL: تداخل/خطا:$CONFLICTS"
  echo "جزئیات تداخل (اگر فایلی هست): $WORK/conflicts"
  exit 2
fi
for rel in $APP_FILES; do
  [ -f "$STAGE/$rel" ] || continue
  "$PHP_BIN" -l "$STAGE/$rel" >/dev/null 2>&1 || true   # blade است، lint فقط برای ردِ خرابیِ آشکار
done

if [ "$DRY" = "1" ]; then
  echo "DRY OK — $UPD فایلِ کد + ترجمه‌های بالا؛ هیچ فایلی نوشته نشد."
  exit 0
fi

# ═══ اعمال — ترجمه‌ها اول: کلیدِ تازه بی‌ویو بی‌اثر است، ولی ویوِ تازه بی‌کلید خام چاپ می‌کند ═══
for rel in $LANG_FILES; do apply_lang "$rel" 0; done

for rel in $APP_FILES; do
  [ -f "$STAGE/$rel" ] || continue
  mkdir -p "$BK/$(dirname "$rel")"
  cp -p "$APP/$rel" "$BK/$rel"
  cp "$STAGE/$rel" "$APP/$rel"
done

# مقدار را از خودِ مقصد بسنج، نه از چاپِ موفقیت
for l in fa en tr; do
  "$PHP_BIN" -r '$a = require $argv[1]; foreach (["gpu_faq1_q","gpu_faq13_q","gpu_faq18_a","gpu_guide_t","cl_t"] as $k) { if (! isset($a[$k])) { fwrite(STDERR, "missing $k\n"); exit(1); } } if (str_contains($a["gpu_app_img_d"] ?? "", "web UI and API") || str_contains($a["gpu_app_img_d"] ?? "", "رابط وب و API")) { fwrite(STDERR, "comfyui text still old\n"); exit(1); }' "$APP/lang/$l/ui.php" \
    || { echo "FATAL: کلیدها در lang/$l ننشستند — پشتیبان: $BK"; exit 3; }
done
grep -q "13, 14, 15, 16, 17, 18" "$APP/resources/views/pages/gpu.blade.php" || { echo "FATAL: ویوِ /gpu به‌روز نشد"; exit 3; }

cd "$APP" || exit 1
"$PHP_BIN" artisan view:clear || exit 4

# ⚠️ opcache_reset از CLI روی PHP-FPM اثری ندارد؛ فایلِ ترجمه با revalidate
#    چند ثانیه بعد تازه می‌شود. اگر شمارِ پایین صفر نشد، یک دقیقه بعد دوباره بسنجید.
sleep 5
for p in gpu en/gpu tr/gpu vps/hourly en/vps/hourly; do
  n="$(curl -fsSL "$SITE/$p?nocache=$STAMP" 2>/dev/null | grep -o '>ui\.[a-z0-9_]*<\|"ui\.[a-z0-9_]*"' | sort -u | wc -l)"
  echo "کلیدِ خام روی /$p: $n"
done

echo "DEPLOY OK — backup: $BK"
echo "Rollback: فایل‌های $BK را به $APP برگردانید و artisan view:clear بزنید."
