#!/usr/bin/env bash
#
# پاکسازیِ فضای دیسکِ حسابِ هاست — و مهم‌تر، بستنِ نشتی‌ای که خودمان ساختیم.
#
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/housekeeping-disk.sh)          # فقط گزارش
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/housekeeping-disk.sh) --apply  # واقعاً پاک کن
#
# ═══ 🔴 چرا این فایل وجود دارد ═══
#
# ۳۱ اوت ۲۰۲۶ دیسکِ سرور به ۱۰۰٪ رسید و **کلِ سایت ۵۰۰ شد**. مقصرِ اصلی جای
# دیگری بود، ولی ۲.۷ گیگش تقصیرِ خودِ ما بود: هر اسکریپتِ دیپلوی یک پوشهٔ
# `~/deploy-<نام>/` می‌ساخت و داخلش یک کلونِ کاملِ مخزن (~۱۱۰ مگ). ۲۶ دیپلوی،
# ۲۶ کپیِ یکسان از یک مخزن.
#
# این اسکریپت هم آن انباشت را پاک می‌کند و هم — با ساختنِ کلونِ مشترک — کاری
# می‌کند که دیپلوی‌های بعدی دیگر تکرارش نکنند.
#
# ⚠️ پیش‌فرض **فقط گزارش** است. روی سروری که تازه از ۱۰۰٪ برگشته، اسکریپتی که
# بی‌پرسش پاک می‌کند خودش خطرِ بعدی است. اول ببین، بعد `--apply`.
#
set -u

APPLY=0
[ "${1:-}" = "--apply" ] && APPLY=1

# ── مالکِ درست، نه «هرکه اجرا می‌کند» ────────────────────────────────────
#
# 🔴 درسِ همان روز: دستورها با کاربرِ حسابِ هاست نوشته شده بودند و بعد با
# `root` اجرا شدند. `~` شد `/root`، هر پنج دستور «No such file or directory»
# داد، و چون خروجیِ `df` تغییر نکرده بود لحظه‌ای به‌نظر رسید که پاکسازی
# بی‌اثر بوده — در حالی که اصلاً انجام نشده بود.
#
# پس هوم **صریح** است و اگر پیدا نشد اسکریپت می‌ایستد.
HOME_DIR="${SN_HOME:-/home/servernetcloud}"

if [ ! -d "$HOME_DIR" ]; then
  echo "FATAL: هومِ حساب پیدا نشد: $HOME_DIR"
  echo "       اگر نامِ حساب فرق دارد:  SN_HOME=/home/<user> bash این‌اسکریپت"
  exit 1
fi

APP="$HOME_DIR/servernet_app"
[ -f "$APP/artisan" ] || { echo "FATAL: $APP/artisan نیست — این هومِ اپ نیست"; exit 1; }

SHARED="$HOME_DIR/deploy-repo"
BACKUP_KEEP_DAYS=21

human() { numfmt --to=iec --suffix=B "${1:-0}" 2>/dev/null || echo "${1:-0}B"; }
size_of() { du -sb "$@" 2>/dev/null | awk '{s+=$1} END {print s+0}'; }

echo "══════════ پاکسازیِ فضا ══════════"
echo "هوم: $HOME_DIR"
[ "$APPLY" -eq 1 ] && echo "حالت: 🔴 اجرای واقعی" || echo "حالت: فقط گزارش (برای اجرا --apply بده)"
echo
df -h "$HOME_DIR" | tail -1
echo

FREED=0

step() { echo; echo "── $1"; }

# ── ۱) کلون‌های تکراریِ مخزن ────────────────────────────────────────────
step "کلون‌های تکراریِ مخزن در پوشه‌های دیپلوی"

REPOS=$(find "$HOME_DIR" -maxdepth 2 -type d -name repo -path "$HOME_DIR/deploy-*" 2>/dev/null | grep -v "^$SHARED" || true)

if [ -z "$REPOS" ]; then
  echo "   چیزی نیست."
else
  n=$(echo "$REPOS" | wc -l)
  sz=$(size_of $REPOS)
  echo "   $n کلون، جمعاً $(human "$sz")"
  echo "$REPOS" | sed 's/^/     /'
  # بی‌خطر: هر کدام یک clone است و اسکریپتِ دیپلوی در صورتِ نبودش دوباره می‌سازد.
  if [ "$APPLY" -eq 1 ]; then
    rm -rf $REPOS && FREED=$((FREED+sz)) && echo "   ✅ پاک شد"
  fi
fi

# ── ۲) فایل‌های موقتِ آپلودِ نیمه‌کاره ───────────────────────────────────
step "آپلودهای رهاشدهٔ File Manager در tmp"

# ⚠️ فقط همین الگو. سشن‌ها (`sess_*`) و صفِ ایمیل در همان پوشه‌اند و
# پاک‌کردنشان یعنی بیرون‌انداختنِ همهٔ کاربران از حساب.
UPLOADS=$(find "$HOME_DIR/tmp" -maxdepth 1 -type f -name 'Cpanel_Form_file.upload.*' -mtime +1 2>/dev/null || true)

if [ -z "$UPLOADS" ]; then
  echo "   چیزی نیست."
else
  sz=$(size_of $UPLOADS)
  echo "   $(echo "$UPLOADS" | wc -l) فایل، جمعاً $(human "$sz")"
  echo "$UPLOADS" | sed 's/^/     /'
  if [ "$APPLY" -eq 1 ]; then
    rm -f $UPLOADS && FREED=$((FREED+sz)) && echo "   ✅ پاک شد"
  fi
fi

# ── ۳) بکاپ‌های کهنهٔ دیپلوی ────────────────────────────────────────────
step "بکاپ‌های دیپلویِ قدیمی‌تر از $BACKUP_KEEP_DAYS روز"

# اینها تورِ نجاتِ rollback‌اند، پس فقط کهنه‌ها. دیپلویِ دو هفته پیش را
# دیگر کسی برنمی‌گرداند؛ دیپلویِ دیروز را شاید همین امروز.
OLDBK=$(find "$HOME_DIR" -maxdepth 2 -type d -name 'backup-*' -path "$HOME_DIR/deploy-*" -mtime +$BACKUP_KEEP_DAYS 2>/dev/null || true)

if [ -z "$OLDBK" ]; then
  echo "   چیزی نیست."
else
  sz=$(size_of $OLDBK)
  echo "   $(echo "$OLDBK" | wc -l) پوشه، جمعاً $(human "$sz")"
  if [ "$APPLY" -eq 1 ]; then
    rm -rf $OLDBK && FREED=$((FREED+sz)) && echo "   ✅ پاک شد"
  fi
fi

# ── ۴) پوشه‌های دیپلویِ خالی‌شده ────────────────────────────────────────
step "پوشه‌های دیپلویِ بی‌محتوا"

if [ "$APPLY" -eq 1 ]; then
  find "$HOME_DIR" -maxdepth 1 -type d -name 'deploy-*' -empty -delete 2>/dev/null
  echo "   ✅ پاک شد (اگر بود)"
else
  find "$HOME_DIR" -maxdepth 1 -type d -name 'deploy-*' -empty 2>/dev/null | sed 's/^/     /' || true
  echo "   (فقط پوشه‌های کاملاً خالی)"
fi

# ── ۵) لاگِ لاراول ─────────────────────────────────────────────────────
step "لاگِ لاراول"

LOG="$APP/storage/logs/laravel.log"
if [ -f "$LOG" ]; then
  sz=$(size_of "$LOG")
  echo "   $(human "$sz")"
  # ⚠️ truncate و نه rm: پروسهٔ php ممکن است همین حالا فایل را باز داشته
  # باشد؛ حذفش inode را زنده نگه می‌دارد و فضا آزاد **نمی‌شود**.
  if [ "$APPLY" -eq 1 ] && [ "$sz" -gt 20000000 ]; then
    : > "$LOG" && FREED=$((FREED+sz)) && echo "   ✅ خالی شد (بیش از ۲۰ مگ بود)"
  elif [ "$APPLY" -eq 1 ]; then
    echo "   کوچک است — دست نخورد"
  fi
fi

# ── ۶) کلونِ مشترک: تا دیپلویِ بعدی دوباره ۱۱۰ مگ نگیرد ────────────────
step "کلونِ مشترکِ دیپلوی ($SHARED)"

if [ -d "$SHARED/.git" ]; then
  echo "   هست — $(human "$(size_of "$SHARED")")"
  if [ "$APPLY" -eq 1 ]; then
    git -C "$SHARED" gc --prune=now --quiet 2>/dev/null || true
    echo "   ✅ فشرده شد → $(human "$(size_of "$SHARED")")"
  fi
else
  echo "   هنوز ساخته نشده. اولین اسکریپتِ دیپلویِ تازه خودش می‌سازدش،"
  echo "   و از آن به بعد همهٔ دیپلوی‌ها از همین یکی استفاده می‌کنند."
fi

echo
echo "══════════ تمام ══════════"
if [ "$APPLY" -eq 1 ]; then
  echo "آزادشده: $(human "$FREED")"
  df -h "$HOME_DIR" | tail -1
else
  echo "چیزی پاک نشد. برای اجرای واقعی، همان دستور را با --apply بزنید."
fi
echo
echo "⚠️ اگر دیسک هنوز پر است، مقصر بیرونِ این حساب است. با root:"
echo "     du -xh --max-depth=2 / 2>/dev/null | sort -h | tail -25"
echo
