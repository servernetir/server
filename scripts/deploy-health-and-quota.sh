#!/usr/bin/env bash
#
# دیپلوی سه اصلاح — شهریور ۱۴۰۵، پیرو `deploy-menu-and-alerts.sh`.
#
# اجرا از ترمینال cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-health-and-quota.sh) [<SHA>]
#
# ═══ ۱) صفحهٔ سلامت مسیرِ **فعال** را می‌سنجد ═══
#
# `/system/health` همیشه رلهٔ قدیمیِ `servernet.ir` را می‌زد، چون
# `SMS_RELAY_URL` هنوز در .env هست. آن فایل مدت‌هاست بازنشسته شده و ۴۱۰
# می‌دهد، پس `relay.guard.ok` روی سرور **همیشه false** بود.
#
# دو خرابیِ هم‌زمان، و دومی خطرناک‌تر:
#
#   · آژیرِ همیشه‌روشن برای مسیری که هیچ‌کس استفاده نمی‌کند — و آژیری که
#     همیشه قرمز است، آژیرِ بعدی را هم می‌بلعد.
#   · مسیری که **واقعاً** پیامک را می‌برد (n8n) هیچ ناظری نداشت. صفحهٔ
#     سلامت دربارهٔ تنها چیزی که مهم بود ساکت بود، و سکوت شبیهِ «سالم» دیده
#     می‌شد.
#
# ⚠️ سنجشِ تازه پاکتی با امضای ۶۴ صفر می‌فرستد: ذاتاً نمی‌تواند پیامک
#    بفرستد. پاکتِ **امضاشده** عمداً فرستاده نمی‌شود.
#
# ═══ ۲) سهمیهٔ تقویمِ محتوا خودش را تنظیم می‌کند ═══
#
# سهمیهٔ روزانه یک عددِ سخت‌کد بود که با دست «کالیبره» شده بود. چیزی که
# کالیبراسیون را می‌شکست تغییرِ موضوع‌ها نبود — **گذرِ زمان** بود: پایانِ
# برنامه ثابت است (۲۹ اسفند ۱۴۰۵)، پس هر روز پنجره یک روز کوتاه‌تر و ظرفیت
# حدودِ ۳٫۵ کمتر می‌شود، در حالی که صف فقط به‌اندازهٔ تولیدِ واقعی آب می‌رود.
#
# امروز ظرفیت ۶۴۵ بود و صف ۶۸۸ — یعنی ۴۳ مطلب سرِ جایشان به سالِ بعد
# می‌افتادند، بی‌هیچ خطایی. حالا سهمیه از خودِ صف مشتق می‌شود و هر دو جهت
# را می‌بندد (کمبود ⇒ ته صف می‌افتد · زیادی ⇒ اسفند خالی می‌مانَد).
#
# ⚠️ باندِ ۲ تا ۵ در روز شکسته نمی‌شود، حتی اگر صف جا نشود. آن یک تصمیمِ
#    انسانی است و تست نشانش می‌دهد.
#
# ═══ ۳) `/system/tables` می‌گوید seeder کارش را کرده یا نه ═══
#
# «مهاجرت خورد» با «کاتالوگ پر شد» یکی نیست. seederها اول وجودِ جدول را چک
# می‌کنند و اگر رد شود **بی‌صدا** هیچ نمی‌کنند. بعد از دیپلویِ قبلی هیچ راهی
# نبود از بیرون فهمید ۲۲ اعلانِ مدیر ردیف گرفته‌اند یا نه.
#
# ═══ ⚠️ کارِ همکار در همین فایل ═══
#
# ۸ شهریور، همکار هم `ContentCalendar.php` را عوض کرد: همان ریشه را تشخیص
# داده بود (پنجره کوتاه می‌شود، صف نه) ولی توزیع را یک پله بالا برد.
#
# تشخیص دقیقاً درست بود؛ فقط پلهٔ بعدی هم چند هفته بعد کم می‌آورد. پس
# نتیجهٔ همان تحلیل ساختاری شد و پلهٔ ایشان هم **حفظ** شده — حالا فقط بافتِ
# توزیع را تعیین می‌کند و دیگر چیزی به کالیبراسیونش وابسته نیست.
#
# 🔴 پس پین `d41129d` است (کامیتِ merge)، نه کامیتِ اصلاحاتِ من. با پینِ
# قبلی، این دیپلوی کارِ تازه‌نشستهٔ همکار را بی‌صدا برمی‌گرداند.
#
# ═══ ⚠️ نسبت به دیپلویِ قبلی ═══
#
# `routes/web.php` روی سرور همان c3d0bd0 است و این کامیت فرزندِ اوست، پس
# `UP` می‌گیرد و مسیرهای منو از دست نمی‌روند.
#
# 🔴 و پینِ هیچ اسکریپتِ دیگری جلو کشیده نشد — قاعده‌ای که در سرصفحهٔ
# `deploy-menu-and-alerts.sh` نوشته شده: پین را فقط وقتی جلو بکش که آن
# اسکریپت همهٔ فایل‌های وابسته را هم بفرستد.
set -u

# ── حالتِ آزمایشی ──────────────────────────────────────────────────────────
#
#   DRY=1 bash <(curl -fsSL .../deploy-health-and-quota.sh)
#
# 🔴 اول این را بزن. هیچ عارضه‌ای ندارد — نه فایل، نه کش. فقط می‌گوید کدام
# فایل تداخل دارد.
DRY="${DRY:-0}"

APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-health-and-quota"
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
  git -C repo fetch --depth 500 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 500 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

# 🔴 پین به کامیتِ مشخص — نوکِ متحرکِ develop را دیپلوی نکن.
MINE="${1:-d41129d}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# 🔴 ترتیب معنادار است: اول نقشه و مدل‌ها، بعد سرویس‌ها، بعد provider،
#    بعد کنترلرها و config، بعد مهاجرت/seeder، بعد ویوها، و **آخر** routeها.
APP_FILES="
app/Services/ContentCalendar.php
routes/web.php
"

# فایل‌های وب‌روت — public_html جداست از servernet_app/public (کپی، نه symlink)
#
# ⚠️ خالی و باید خالی بماند. هرگز `public/index.php` این‌جا نیاید: نسخهٔ سرور
#    مسیرهای متفاوتی دارد چون اپ بیرونِ webroot است و آپلودش سایت را ۵۰۰ می‌کند.
PUB_FILES=""

CONFLICTS=""
UPD=0

# فایل‌هایی که این اجرا **ساخته** است (روی سرور نبودند).
#
# 🔴 بی‌این فهرست، بازگردانی ناقص است: بکاپ فقط فایل‌های موجود را دارد، پس
#    فایل‌های تازه بعد از rollback روی سرور **می‌مانند**. آن‌وقت پیام می‌گوید
#    «همه‌چیز برگشت» در حالی که سرور در حالتی است که نه قبلی است نه جدید —
#    و همان حالت است که بعداً کسی نمی‌فهمد از کجا آمده.
NEWFILES=""

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }

# ⚠️ خالی و باید خالی بمانَد.
FORCE_FILES=""

apply_one() {
  rel="$1"; dest="$2/$rel"
  mine_f="$WORK/mine.tmp"; base_f="$WORK/base.tmp"

  git -C repo show "$MINE:website/$rel" > "$WORK/mine.raw" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; return; }
  normalize "$WORK/mine.raw" "$mine_f"

  if [ -f "$dest" ] && [ "$DRY" = "0" ]; then
    mkdir -p "$BK/$(dirname "$rel")"
    cp -p "$dest" "$BK/$rel"
  fi

  if [ ! -f "$dest" ]; then
    [ "$DRY" = "0" ] && { mkdir -p "$(dirname "$dest")"; cp "$mine_f" "$dest"; }
    # 🔴 مسیرِ کامل نگه داشته می‌شود، وگرنه بازگردانی نمی‌تواند پاکش کند —
    #    بکاپ فقط فایل‌هایی را دارد که از **قبل** بودند.
    NEWFILES="$NEWFILES
$dest"
    echo "NEW   $rel   (فایلِ تازه — روی سرور نیست)"; UPD=$((UPD+1)); return
  fi

  dest_n="$WORK/dest.tmp"; normalize "$dest" "$dest_n"

  case " $(echo $FORCE_FILES) " in *" $rel "*)
    if cmp -s "$dest_n" "$mine_f"; then
      echo "OK    $rel"; return
    fi
    [ "$DRY" = "0" ] && cp "$mine_f" "$dest"
    echo "FR    $rel   (جایگزینیِ اجباری)"
    UPD=$((UPD+1)); return
  ;; esac

  if cmp -s "$dest_n" "$mine_f"; then
    cmp -s "$dest" "$mine_f" || { [ "$DRY" = "0" ] && cp "$mine_f" "$dest"; echo "EOL   $rel   (فقط پایانِ خط)"; UPD=$((UPD+1)); return; }
    echo "OK    $rel"; return
  fi

  best=""; bestd=999999999
  for sha in $(git -C repo log --format=%H -n "$HIST" "$MINE" -- "website/$rel"); do
    git -C repo show "$sha:website/$rel" > "$WORK/cand.raw" 2>/dev/null || continue
    normalize "$WORK/cand.raw" "$WORK/cand.tmp"
    if cmp -s "$dest_n" "$WORK/cand.tmp"; then best="$sha"; bestd=0; break; fi
    d=$(dist "$dest_n" "$WORK/cand.tmp")
    if [ "$d" -lt "$bestd" ]; then bestd=$d; best="$sha"; fi
  done

  if [ -z "$best" ]; then
    echo "CF    $rel   ← در تاریخچهٔ develop نیست؛ نسخهٔ ناشناخته روی سرور — دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"
    return
  fi

  if [ "$bestd" -eq 0 ]; then
    [ "$DRY" = "0" ] && cp "$mine_f" "$dest"
    echo "UP    $rel   (سرور = $(git -C repo rev-parse --short "$best") — جایگزینیِ بی‌ریسک)"; UPD=$((UPD+1)); return
  fi

  git -C repo show "$best:website/$rel" > "$WORK/base.raw"
  normalize "$WORK/base.raw" "$base_f"
  m="$WORK/merged.tmp"; cp "$dest_n" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    [ "$DRY" = "0" ] && cp "$m" "$dest"
    echo "MG    $rel   (پایه $(git -C repo rev-parse --short "$best")، فاصلهٔ سرور $bestd خط — تغییرِ دیگران حفظ شد)"
    UPD=$((UPD+1))
  else
    echo "CF    $rel   ← تداخل واقعی؛ دست نخورد (پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط)"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
    echo "──── سرور − پایه ($rel) — این تکه در مخزن نیست:"
    diff -u "$base_f" "$dest_n" | sed -n '1,140p'
    echo "──── پایانِ diff"
  fi
}

echo "── بکاپ در: $BK"
echo
echo "═══ اپ ($APP) ═══"
for f in $APP_FILES; do apply_one "$f" "$APP"; done

echo
echo "═══ وب‌روت ($PUB) — public_html جداست از public اپ ═══"
for f in $PUB_FILES; do
  apply_one "$f" "$APP"
  rel_pub="${f#public/}"
  if [ -f "$APP/$f" ] && [ "$DRY" = "0" ]; then
    mkdir -p "$BK/public_html/$(dirname "$rel_pub")" "$PUB/$(dirname "$rel_pub")"
    [ -f "$PUB/$rel_pub" ] && cp -p "$PUB/$rel_pub" "$BK/public_html/$rel_pub"
    cp "$APP/$f" "$PUB/$rel_pub" && echo "PUB   $rel_pub"
  fi
done

PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)

echo
union_ok=1
if [ -n "$PHPBIN" ]; then
  echo "═══ php -l روی فایل‌های نشسته ═══"
  for f in $APP_FILES $PUB_FILES; do
    case "$f" in *.php)
      [ -f "$APP/$f" ] || continue
      "$PHPBIN" -l "$APP/$f" >/dev/null 2>&1 || { echo "🔴 خطای نحو: $f"; union_ok=0; }
    ;; esac
  done
  [ "$union_ok" -eq 1 ] && echo "✅ نحو سالم"
fi

# ── پایانِ حالتِ آزمایشی ────────────────────────────────────────────────────
if [ "$DRY" != "0" ]; then
  echo
  echo "═══ حالتِ آزمایشی — هیچ فایلی روی سرور نوشته نشد ═══"
  echo "برنامه: $UPD فایل به‌روز یا تازه"
  if [ -n "$CONFLICTS" ]; then
    echo "🔴 تداخل:$CONFLICTS"
    echo "   پیش از دیپلویِ واقعی باید حل شود."
    exit 1
  fi
  echo "✅ هیچ تداخلی نیست — همین فرمان را بدونِ DRY=1 بزن."
  echo "⚠️ راستی‌آزمایی و پاکسازیِ کش فقط در اجرای واقعی می‌دوند."
  exit 0
fi

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────────
# 🔴 دیپلوی فایل‌به‌فایل است و «یک فایل جا ماند» فرضی نیست. این‌جا هر تکه‌ای که
#    نبودش یک خرابیِ **خاموش** می‌سازد جداگانه سنجیده می‌شود.
#
# ⚠️ هیچ گاردی «$» ندارد — و این عمدی است. گاردی که به نقل‌قول‌گذاریِ شل وابسته
#    باشد، روی سرور «ننشسته» گزارش می‌دهد در حالی که رشته در فایل هست، و کارِ
#    سالم را برمی‌گرداند. یک بار همین شد.
need_file() { [ -f "$1" ] || { echo "🔴 نیست: ${1#$APP/}"; union_ok=0; }; }

g() { grep -qF "$2" "$APP/$1" 2>/dev/null || { echo "🔴 $1: «$2» ننشسته"; union_ok=0; }; }

# ═══ ۱) سنجشِ سلامت روی مسیرِ فعال ═══
#
# 🔴 هر سه با هم معنا دارند: بی‌`active_path` نمی‌شود فهمید کدام مسیر سنجیده
#    شده، و بی‌`bad_signature` سنجش به کدِ ۲۰۰ بسنده می‌کند — که ورک‌فلو برای
#    ردشدن هم می‌دهد.
g routes/web.php "active_path"
g routes/web.php "n8n_relay"
g routes/web.php "bad_signature"

# ═══ ۲) تقویمِ خودتنظیم ═══
g app/Services/ContentCalendar.php "planQuota"
g app/Services/ContentCalendar.php "QUOTA_MAX"
g app/Services/ContentCalendar.php "baseQuotaFor"

# ═══ ۳) سنجهٔ کاتالوگ‌های seedشده ═══
g routes/web.php "admin_alerts"

# ⚠️ و هرچه در دیپلویِ قبلی نشست نباید بیفتد — `routes/web.php` مشترک است و
#    این فهرست تنها چیزی است که «یک فایلِ عقب‌مانده» را از خرابیِ خاموش
#    جدا می‌کند.
for r in "menus/save" "menus/add" "templates/" "AdminNotificationTemplateSeeder" \
         "name('gpu')" "name('go.pay')" "name('healthz')" "urmiaGone" \
         "ack-manual" "resolve-release" "total_all_time"; do
  g routes/web.php "$r"
done
if [ "$union_ok" -eq 0 ]; then
  echo "🔴 اتحادِ فایل‌ها کامل نیست — کلِ بکاپ برمی‌گردد تا سایت ۵۰۰ نشود."
  ( cd "$BK" && find . -type f | while read -r p; do
      rel="${p#./}"
      case "$rel" in
        public_html/*) cp "$p" "$PUB/${rel#public_html/}" ;;
        *)             cp "$p" "$APP/$rel" ;;
      esac
      echo "   بازگشت: $rel"
    done )

  # و فایل‌هایی که خودمان ساختیم — بکاپ نسخهٔ قبلی‌شان را ندارد چون نبودند
  printf '%s
' "$NEWFILES" | while read -r p; do
    [ -n "$p" ] && [ -f "$p" ] && rm -f "$p" && echo "   حذفِ فایلِ تازه: ${p#$APP/}"
  done

  echo "🔴 دیپلوی ناتمام. خروجیِ بالا را بفرست."
  exit 1
fi

# ── پاکسازی کش ────────────────────────────────────────────────────────────
#
# ⚠️ این دیپلوی مهاجرت و seeder **ندارد**.
if [ -n "$PHPBIN" ]; then
  cd "$APP"
  "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear

  # 🔴 عکسِ ۹۰ ثانیه‌ایِ صفحهٔ سلامت هم باید برود، وگرنه تا انقضایش همان
  #    گزارشِ قرمزِ قبلی را نشان می‌دهد و به‌نظر می‌رسد اصلاح نگرفته.
  "$PHPBIN" artisan tinker --execute='\Illuminate\Support\Facades\Cache::forget("system.health.snapshot"); echo "health snapshot cleared";' 2>/dev/null \
    || echo "⚠️ عکسِ سلامت دستی پاک نشد — حداکثر ۹۰ ثانیه صبر کن"
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
  echo "WARN: php پیدا نشد — کش‌ها دستی پاک شدند"
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK   · فایل‌های به‌روزشده: $UPD"
if [ -n "$CONFLICTS" ]; then
  echo "🔴 فایل‌های تداخل‌دار (دست‌نخورده، نیازمند merge دستی):$CONFLICTS"
  echo "   نسخه‌ها در $WORK/conflicts/ (پسوند .server / .base / .new)"
else
  echo "✅ هیچ تداخلی نبود"
fi
echo
echo "کارِ باقی‌مانده: ریستِ opcache از /system/opcache"
echo
echo "راستی‌آزمایی (بعد از ریستِ opcache):"
echo "  · /system/health  →  relay.active_path باید n8n_relay باشد و"
echo "    relay.guard.ok برابرِ true. اگر هنوز آدرسِ servernet.ir را دیدی،"
echo "    یعنی routes ننشسته یا opcache ریست نشده."
echo "  · /system/tables  →  seeded.admin_alerts.have باید برابرِ want باشد."
echo "    عددِ کمتر یعنی seeder دوید و کارش را تمام نکرد؛ نال یعنی ستون نیست."
echo "  · /admin/calendar →  تقویم باید تا اسفند پر باشد، نه اینکه ته سال"
echo "    خالی بماند."
