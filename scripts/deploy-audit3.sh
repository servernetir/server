#!/usr/bin/env bash
#
# دیپلوی اصلاحات ممیزی ۳ (کامیت d53c820) روی سرور — با احترام به کار دیگران.
#
# چرا این‌قدر محتاط: توسعه‌دهنده‌های دیگر هم‌زمان روی سرور کار می‌کنند، پس
# هیچ فایلی کورکورانه replace نمی‌شود:
#   · اگر فایل سرور == نسخهٔ پایه (8e3fa6d) → نسخهٔ جدید می‌نشیند (UP)
#   · اگر فایل سرور == نسخهٔ جدید → کاری لازم نیست (OK)
#   · اگر کس دیگری تغییرش داده → merge سه‌طرفه (MG)؛ تداخل واقعی → فایل
#     دست‌نخورده می‌مانَد و فقط گزارش می‌شود (CF) — هرگز مارکر تداخل روی
#     سایتِ زنده نمی‌نشیند.
#   · قبل از هر تغییری از همهٔ فایل‌های هدف بکاپ کامل گرفته می‌شود.
#
# bootstrap/app.php عمداً آخر از همه اعمال می‌شود (میدل‌ور جدید را ثبت
# می‌کند؛ اگر اول برود، تا رسیدن فایل میدل‌ور کل سایت ۵۰۰ می‌شود).
#
set -u

MINE=7e79110        # نسخهٔ جدید = unionِ ممیزی ۴ + شاخهٔ cloud-phone همکار
FBASE=6911175       # نوکِ شاخهٔ همکار — پایهٔ دومِ پذیرفته (سرور کپی‌اش را دارد)
BASE=d53c820        # نسخهٔ پایه = آخرین دیپلویِ راستی‌آزمایی‌شده (ممیزی ۳)
APP="$HOME/servernet_app"
PUB="$HOME/public_html"
WORK="$HOME/deploy-audit3"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"

mkdir -p "$WORK" "$BK"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }

if [ -d repo/.git ]; then
  git -C repo fetch --depth 200 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 200 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

git -C repo rev-parse --verify "$MINE" >/dev/null || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
git -C repo rev-parse --verify "$BASE" >/dev/null || { echo "FATAL: $BASE در مخزن نیست"; exit 1; }

# شاخهٔ همکار (پایهٔ دوم) — کلون تک‌شاخه است، جدا fetch می‌شود. شکستش کشنده
# نیست: فقط مسیرِ «سرور کپیِ شاخهٔ همکار است» تشخیص داده نمی‌شود.
git -C repo fetch --depth 100 origin feature/cloud-phone 2>/dev/null || true
git -C repo rev-parse --verify "$FBASE" >/dev/null 2>&1 || FBASE=""

# ── فهرست فایل‌ها (نسبت به website/ در مخزن) ─────────────────────────────
# bootstrap/app.php این‌جا نیست — جدا و در انتها اعمال می‌شود.
APP_FILES="
app/Console/Commands/CheckSiteLinks.php
app/Http/Middleware/PageCache.php
app/Http/Controllers/OrderSummaryController.php
app/Http/Controllers/AbuseController.php
resources/views/pages/order-summary.blade.php
resources/views/pages/abuse.blade.php
config/pagecache.php
resources/views/partials/product-guides.blade.php
app/Console/Commands/CheckContentLinks.php
app/Http/Controllers/SiteController.php
app/Http/Controllers/CatalogController.php
app/Providers/AppServiceProvider.php
app/Services/AiContent.php
app/helpers.php
config/blog.php
config/hosting.php
config/pages.php
lang/en/ui.php
lang/fa/ui.php
lang/tr/ui.php
resources/views/pages/blog-post.blade.php
resources/views/pages/cloud-location.blade.php
resources/views/pages/cloud.blade.php
resources/views/pages/content.blade.php
resources/views/pages/hosting.blade.php
resources/views/pages/server-detail.blade.php
resources/views/pages/sla.blade.php
resources/views/pages/solution.blade.php
resources/views/partials/footer.blade.php
resources/views/partials/sig-ai-builder.blade.php
routes/console.php
routes/web.php
app/Http/Controllers/Admin/CustomerController.php
app/Http/Controllers/Admin/PhoneCallController.php
app/Http/Controllers/Admin/UserController.php
app/Http/Controllers/CloudPhoneWebhookController.php
app/Models/PhoneCall.php
app/Models/PhoneCallEvent.php
app/Models/User.php
app/Services/CloudPhone/CallIngestor.php
app/Services/CloudPhone/CustomerMatcher.php
app/Services/CloudPhone/OutgoingCallService.php
app/Services/CloudPhone/WebhookPayload.php
app/Support/IranianPhone.php
config/services.php
resources/views/admin/calls.blade.php
resources/views/admin/customer.blade.php
resources/views/admin/customers.blade.php
resources/views/admin/layout.blade.php
resources/views/admin/users.blade.php
"
# ↑ بلوکِ دوم، فایل‌های خطِ cloud-phone همکار است — از این کامیتِ merge دیگر
#   بخشی از develop اند. سرور نسخهٔ آپلودِ دستیِ خودِ او را دارد؛ اگر با
#   شاخه‌اش یکی باشد UP می‌شود و اگر تغییرِ محلیِ ثبت‌نشده داشته باشد CF
#   می‌ماند و گزارش می‌شود — کارش پایمال نمی‌شود.

CONFLICTS=""
MERGED=""

apply_one() {                       # $1 = مسیر نسبی، $2 = ریشهٔ مقصد
  rel="$1"; destroot="$2"
  dest="$destroot/$rel"
  mine_f="$WORK/mine.tmp"; base_f="$WORK/base.tmp"

  git -C repo show "$MINE:website/$rel" > "$mine_f" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; return; }

  # بکاپ از وضع فعلی سرور (اگر هست)
  if [ -f "$dest" ]; then
    mkdir -p "$BK/$(dirname "$destroot/$rel" | sed "s|^$HOME/||")" 2>/dev/null
    cp -p "$dest" "$BK/$(echo "$destroot/$rel" | sed "s|^$HOME/||")"
  fi

  if [ ! -f "$dest" ]; then
    mkdir -p "$(dirname "$dest")"
    cp "$mine_f" "$dest"; echo "NEW   $rel"; return
  fi

  if cmp -s "$dest" "$mine_f"; then echo "OK    $rel"; return; fi

  # محتوای یکسان، فقط پایان‌خطِ ویندوزی (CRLF از آپلود/ادیتورِ قدیمی — دورِ
  # اول همین AiContent را «تداخل» جا زد در حالی که هیچ کدِ واقعی‌ای فرق
  # نداشت). تفاوتِ واقعی نیست؛ نسخهٔ LFِ مخزن می‌نشیند تا پایدار شود.
  if tr -d '\r' < "$dest" | cmp -s - "$mine_f"; then
    cp "$mine_f" "$dest"; echo "EOL   $rel   (فقط پایان‌خط)"; return
  fi

  # نسخهٔ پایه لازم است؛ اگر فایل در BASE نبوده ولی روی سرور نسخهٔ متفاوتی
  # هست، یعنی کس دیگری هم‌نامش را ساخته — سه‌طرفه ممکن نیست، دست نمی‌زنیم.
  if ! git -C repo show "$BASE:website/$rel" > "$base_f" 2>/dev/null; then
    echo "CF    $rel   ← روی سرور نسخهٔ ناشناخته دارد؛ دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$mine_f" "$keep.new"
    return
  fi

  if cmp -s "$dest" "$base_f"; then
    cp "$mine_f" "$dest"; echo "UP    $rel"; return
  fi

  # همان پایه ولی با CRLF — باز هم «بدونِ تغییرِ دیگران» شمرده می‌شود
  if tr -d '\r' < "$dest" | cmp -s - "$base_f"; then
    cp "$mine_f" "$dest"; echo "UP    $rel   (پایان‌خطِ کهنه هم تمیز شد)"; return
  fi

  # پایهٔ دوم: نسخهٔ شاخهٔ همکار (cloud-phone). سرور کپیِ آن را دارد و
  # MINE حالا **merge** همان شاخه است، پس جایگزینی هیچ کاری از او را پاک
  # نمی‌کند — این دقیقاً همان حفره‌ای بود که دیپلوی قبلی را به حذفِ ناخواستهٔ
  # روتِ aup و ۵۰۰ سراسری رساند: merge-file با پایهٔ اشتباه، «نداشتنِ» کدِ
  # جدید در کپیِ شاخهٔ همکار را «حذفِ عمدی» تفسیر می‌کرد.
  if [ -n "$FBASE" ] && git -C repo show "$FBASE:website/$rel" > "$WORK/fbase.tmp" 2>/dev/null; then
    if cmp -s "$dest" "$WORK/fbase.tmp" || tr -d '\r' < "$dest" | cmp -s - "$WORK/fbase.tmp"; then
      cp "$mine_f" "$dest"; echo "UP    $rel   (پایه: شاخهٔ همکار — کارش داخل union هست)"; return
    fi
  fi

  # کس دیگری دست برده — merge سه‌طرفه روی کپی، نه روی فایل زنده.
  # پایان‌خط پیش از merge نرمال می‌شود وگرنه CRLF هر خط را «تغییر» جا می‌زند.
  m="$WORK/merged.tmp"; tr -d '\r' < "$dest" > "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"; echo "MG    $rel   (تغییر دیگران حفظ شد)"; MERGED="$MERGED $rel"
  else
    echo "CF    $rel   ← تداخل واقعی؛ دست نخورد"
    CONFLICTS="$CONFLICTS $rel"
    # نسخه‌ها برای بررسی دستی می‌مانند
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
}

echo "── بکاپ در: $BK"
for f in $APP_FILES; do apply_one "$f" "$APP"; done
# ⚠️ به کپیِ وستیجیالِ servernet_app/public دست نمی‌زنیم — DocumentRoot واقعی
#    public_html است (قاعدهٔ CLAUDE.md) و آن پوشه عمداً کهنه می‌مانَد.

# دارایی استاتیک واقعی → public_html (DocumentRoot جداست — قاعدهٔ CLAUDE.md)
rel="assets/js/builder.js"
dest="$PUB/$rel"
git -C repo show "$MINE:website/public/$rel" > "$WORK/mine.tmp"
if [ -f "$dest" ]; then
  mkdir -p "$BK/public_html/assets/js"; cp -p "$dest" "$BK/public_html/$rel"
  if cmp -s "$dest" "$WORK/mine.tmp"; then echo "OK    public_html/$rel"
  else
    git -C repo show "$BASE:website/public/$rel" > "$WORK/base.tmp" 2>/dev/null
    if cmp -s "$dest" "$WORK/base.tmp"; then cp "$WORK/mine.tmp" "$dest"; echo "UP    public_html/$rel"
    else
      m="$WORK/merged.tmp"; cp "$dest" "$m"
      if git merge-file -L server -L base -L new "$m" "$WORK/base.tmp" "$WORK/mine.tmp" >/dev/null 2>&1; then
        cp "$m" "$dest"; echo "MG    public_html/$rel"
      else
        echo "CF    public_html/$rel ← دست نخورد"; CONFLICTS="$CONFLICTS public_html/$rel"
      fi
    fi
  fi
else
  mkdir -p "$(dirname "$dest")"; cp "$WORK/mine.tmp" "$dest"; echo "NEW   public_html/$rel"
fi

# ── bootstrap/app.php — آخر از همه، و فقط اگر خودش گیر نکند ──────────────
apply_one "bootstrap/app.php" "$APP"

# ── رفعِ رانشِ دیپلویِ قدیمی (کشفِ ۲۶ مرداد ۱۴۰۵) ────────────────────────
#
# دیپلویِ کامیت 860fd9b («قیمتِ پسوندها از OpenProvider، نه WHMCS») روی سرور
# ناقص مانده بود: SiteController با tlds()ِ قدیمیِ WHMCSخوان و TldPriceBookِ
# بدونِ cachedForTlds. نتیجهٔ دیده‌شده روی سایتِ زنده: چیپِ «.ir» با قیمت
# تبلیغ می‌شد در حالی که در UNSOLD_TLDS است و سبد قبولش نمی‌کند.
#
# 🔴 این «تغییرِ همکار» نیست که باید حفظ شود — کدِ کهنهٔ جامانده است (با
# دیفِ دستی اثبات شد: دقیقاً نسخهٔ پیش از 860fd9b). ولی چون این اسکریپت
# قرار است به کارِ دیگران احترام بگذارد، جایگزینی فقط با «اثرانگشتِ کهنگی»
# انجام می‌شود: اگر فایل نشانهٔ قطعیِ نسخهٔ کهنه را نداشت، دست نمی‌خورد.
drift_fix() {                       # $1 = مسیر نسبی، $2 = grep اثرانگشت، $3 = نوع (has|lacks)
  rel="$1"; fp="$2"; mode="$3"
  dest="$APP/$rel"

  [ -f "$dest" ] || { echo "SKIP  $rel (نیست)"; return; }

  git --git-dir="$WORK/repo/.git" show "$MINE:website/$rel" > "$WORK/mine.tmp" 2>/dev/null || return

  if cmp -s "$dest" "$WORK/mine.tmp"; then echo "OK    $rel (drift قبلاً رفع شده)"; return; fi

  stale=0
  if [ "$mode" = has ] && grep -q "$fp" "$dest"; then stale=1; fi
  if [ "$mode" = lacks ] && ! grep -q "$fp" "$dest"; then stale=1; fi

  if [ "$stale" = 1 ]; then
    mkdir -p "$BK/servernet_app/$(dirname "$rel")"
    cp -p "$dest" "$BK/servernet_app/$rel"
    cp "$WORK/mine.tmp" "$dest"
    echo "DRIFT $rel   ← نسخهٔ کهنهٔ جامانده با نسخهٔ مخزن جایگزین شد"
  else
    echo "⚠️  $rel با مخزن فرق دارد ولی اثرانگشتِ کهنگی ندارد — دستی بررسی شود"
  fi
}

drift_fix "app/Services/Domain/TldPriceBook.php" "function cachedForTlds" lacks
drift_fix "app/Http/Controllers/SiteController.php" "Whmcs::forLocale()->tldPricing()" has

# ── تضمینِ union — هیچ نشانگرِ حیاتیِ هیچ‌کدام از دو خطِ کار نباید گم بماند ──
#
# درسِ ۵۰۰ِ سراسریِ ۲۸ مرداد: فوترِ همهٔ صفحات lroute('aup') می‌زند؛ روتی که
# از merge بیفتد یعنی exception روی هر صفحه. MINE اکنون unionِ هر دو خطِ کار
# است، پس اگر بعد از همهٔ مراحل نشانگری غایب بود، جایگزینیِ کامل با MINE
# **هیچ‌چیزِ شناخته‌شده‌ای را حذف نمی‌کند** — و بکاپِ همین اجرا برای هر چیزِ
# ناشناخته هست.
ensure_union() {                    # $1 = مسیر نسبی، $2.. = نشانگرهای اجباری
  rel="$1"; shift
  dest="$APP/$rel"; missing=0

  [ -f "$dest" ] || missing=1
  if [ "$missing" = 0 ]; then
    for probe in "$@"; do
      grep -qF "$probe" "$dest" || { missing=1; break; }
    done
  fi

  [ "$missing" = 0 ] && return

  git --git-dir="$WORK/repo/.git" show "$MINE:website/$rel" > "$WORK/union.tmp" 2>/dev/null || return
  mkdir -p "$BK/servernet_app/$(dirname "$rel")"
  [ -f "$dest" ] && cp -p "$dest" "$BK/servernet_app/$rel.pre-union"
  cp "$WORK/union.tmp" "$dest"
  echo "FIX   $rel   ← نشانگرِ حیاتی «$probe» غایب بود؛ نسخهٔ union نشست"
}

ensure_union "routes/web.php"     "name('aup')" "name('order.summary')" "cloud-phone/webhook"
ensure_union "app/helpers.php"    "function blog_related_product" "function sdate_full"
ensure_union "bootstrap/app.php"  "PageCache::class" "cloud-phone/webhook"

# ── کش‌ها: تا پاک نشوند config/روت تازه دیده نمی‌شود ─────────────────────
PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)
if [ -n "$PHPBIN" ]; then
  cd "$APP" && "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
  echo "WARN: php پیدا نشد — کش‌ها دستی پاک شدند"
fi

if [ -n "$MERGED" ]; then
  echo
  echo "── تغییراتِ سرور-فقط که در merge حفظ شد (باید در گیت هم ثبت شود):"
  for f in $MERGED; do
    echo "---- $f"
    # 🔴 مسیرِ مطلق، نه `-C repo`: بلوکِ پاک‌سازیِ کش بالاتر cd کرده و مسیرِ
    #    نسبی این‌جا بی‌صدا می‌شکست (git شکست می‌خورد، && دیف را می‌پراند، و
    #    گزارش خالی چاپ می‌شد — دقیقاً همان چیزی که در اجرای دوم دیده شد).
    git --git-dir="$WORK/repo/.git" show "$MINE:website/$f" > "$WORK/mine.tmp" 2>/dev/null \
      && diff -u "$WORK/mine.tmp" "$APP/$f" | sed -n '1,60p'
  done
fi

echo
echo "══════════ تمام ══════════"
echo "بکاپ: $BK"
if [ -n "$CONFLICTS" ]; then
  echo "🔴 فایل‌های تداخل‌دار (دست‌نخورده، نیازمند merge دستی):$CONFLICTS"
  echo "   نسخه‌ها در $WORK/conflicts/ (پسوند .server / .base / .new)"
else
  echo "✅ هیچ تداخلی نبود"
fi
