#!/usr/bin/env bash
#
# دیپلوی «بازسازیِ دامنه — ممیزیِ شهریور ۱۴۰۵» روی cPanel (۱۱ کامیت).
#
# اجرا از ترمینالِ cPanel (اکانت servernetcloud):
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/deploy-domain-audit.sh) [<SHA>]
#   ← SHA پیش‌فرض = نوکِ همین کار (پایین، MINE).
#
# ⚠️ عمداً به SHA پین است، نه «نوکِ develop»: روی develop کارِ دیپلوی‌نشدهٔ
#    همکاران می‌نشیند (مثلاً ارومیه) و routes نوک ممکن است به کنترلری اشاره
#    کند که روی سرور نیست ⇒ ۵۰۰ سراسری. هرکس دیپلویِ خودش را دارد.
#    پینِ این اسکریپت **پیش از** mergeِ ارومیه است؛ اگر ارومیه قبلاً دیپلوی
#    شده باشد، merge سه‌طرفهٔ routes آن را حفظ می‌کند.
#
# محتوا: تمدیدِ دستی مشتری + رفاندِ تمدید/انتقالِ ناموفق + فرم EPP +
# registrationBlocker + صداقتِ ثبت + مدارشکنِ ۱۹۶ + کفِ ارزی تمدید +
# دفترِ مالی + مالکیتِ استعلام‌ها + سلامت + کاتالوگِ صادق.
#
# 🔴 این دیپلوی **دو مهاجرت** دارد (cost_renew_amount و domain_quotes.customer_id)
#    که در انتها فقط با --path خودشان اجرا می‌شوند — نه migrate کور که
#    مهاجرت‌های دیپلوی‌نشدهٔ دیگران را هم بدواند.
#
# منطق عیناً از scripts/deploy-urmia-i18n.sh (خانوادهٔ deploy-seo-hourly):
# merge سه‌طرفه با پایهٔ خودکار به‌ازای هر فایل — UP/MG/CF + بکاپ کامل.
set -u

APP="$HOME/servernet_app"
WORK="$HOME/deploy-domain-audit"
STAMP=$(date +%Y%m%d-%H%M%S)
BK="$WORK/backup-$STAMP"
HIST=60

mkdir -p "$WORK" "$BK" "$WORK/conflicts"
cd "$WORK"

command -v git >/dev/null || { echo "FATAL: git روی سرور نیست"; exit 1; }

if [ -d repo/.git ]; then
  git -C repo fetch --depth 400 origin develop || { echo "FATAL: fetch"; exit 1; }
else
  git clone --depth 400 --branch develop https://github.com/servernetir/server.git repo \
    || { echo "FATAL: clone"; exit 1; }
fi

MINE="${1:-f4800c6df7d85778c9e67fc5a99bbed098e9cbc8}"
git -C repo rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: $MINE در مخزن نیست"; exit 1; }
echo "── نسخهٔ هدف: $(git -C repo log -1 --format='%h %s' "$MINE")"

# 🔴 ترتیب معنادار است: اول config و مدل‌ها و سرویس‌های مستقل، بعد فرمان‌ها و
#    کنترلرها، بعد ویو و زبان، بعد مهاجرت‌ها، و آخر از همه routeها (که به
#    همهٔ قبلی‌ها اشاره می‌کنند).
APP_FILES="
config/catalog/domain.php
app/Models/Domain.php
app/Models/DomainQuote.php
app/Services/Domain/DomainCostFloor.php
app/Services/Domain/DomainRenewalInvoicer.php
app/Services/Domain/OpenProviderClient.php
app/Services/Domain/DomainSearch.php
app/Services/Domain/DomainRegistrar.php
app/Services/Domain/DomainTransfer.php
app/Services/Domain/Reseller/ResellerOrderService.php
app/Services/Billing/InvoiceCanceller.php
app/Services/Finance/BusinessLedger.php
app/Services/SystemHealth.php
app/Support/PanelSections.php
app/Console/Commands/RunDomainLifecycle.php
app/Console/Commands/ResolveStuckDomains.php
app/Console/Commands/ExpireOrderInvoices.php
app/Console/Commands/PruneDomainQuotes.php
app/Http/Controllers/Account/DomainController.php
app/Http/Controllers/Account/BuilderCheckoutController.php
app/Http/Controllers/CatalogController.php
app/Http/Controllers/DomainCheckController.php
resources/views/account/domain-show.blade.php
lang/fa/ui.php
lang/en/ui.php
lang/tr/ui.php
database/migrations/2026_08_24_000100_add_cost_renew_amount_to_domain_tables.php
database/migrations/2026_08_24_000200_add_customer_id_to_domain_quotes.php
routes/console.php
routes/web.php
"

CONFLICTS=""
UPD=0

dist() { diff "$1" "$2" 2>/dev/null | grep -c '^[<>]'; }

# ── یکسان‌سازیِ پایانِ خط پیش از هر مقایسه/merge ─────────────────────────
#
# 🔴 درسِ اجرای اول (۲ شهریور): OpenProviderClient روی سرور CRLF بود (و
#    بی‌newlineِ پایانی). diff هر ۴۷۹ خط را «متفاوت» می‌دید، پس هیچ نسخهٔ
#    تاریخی exact match نمی‌شد و پایه‌یاب «کوچک‌ترین فایلِ تاریخچه» (نسخهٔ
#    اولیهٔ ۱۴۹خطی) را نزدیک‌ترین می‌گرفت ⇒ merge با پایهٔ غلط ⇒ تداخلِ
#    قلابی ⇒ کلِ دیپلوی برمی‌گشت. همان بیماریِ ثبت‌شدهٔ TicketController
#    («مقایسه پس از یکسان‌سازیِ پایانِ خط»)، این بار در لایهٔ دیپلوی.
#
#    همهٔ مقایسه‌ها و mergeها روی نسخهٔ نرمال‌شده (LF + newlineِ پایانی)
#    انجام می‌شوند و خروجی هم LF می‌نشیند — فایلِ CRLFِ سرور همین‌جا
#    درمان می‌شود، برای همهٔ فایل‌ها نه فقط آن یکی.
normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }

apply_one() {
  rel="$1"; dest="$APP/$rel"
  mine_f="$WORK/mine.tmp"; base_f="$WORK/base.tmp"

  git -C repo show "$MINE:website/$rel" > "$WORK/mine.raw" 2>/dev/null \
    || { echo "SKIP  (در $MINE نیست)  $rel"; return; }
  normalize "$WORK/mine.raw" "$mine_f"

  if [ -f "$dest" ]; then
    mkdir -p "$BK/$(dirname "$rel")"
    cp -p "$dest" "$BK/$rel"
  fi

  if [ ! -f "$dest" ]; then
    mkdir -p "$(dirname "$dest")"
    cp "$mine_f" "$dest"; echo "NEW   $rel"; UPD=$((UPD+1)); return
  fi

  dest_n="$WORK/dest.tmp"; normalize "$dest" "$dest_n"

  if cmp -s "$dest_n" "$mine_f"; then
    # محتوا یکی است؛ اگر بایت‌ها فرق دارند (CRLF)، نسخهٔ سالم بنشیند
    cmp -s "$dest" "$mine_f" || { cp "$mine_f" "$dest"; echo "EOL   $rel   (فقط پایانِ خط درمان شد)"; UPD=$((UPD+1)); return; }
    echo "OK    $rel"; return
  fi

  # پایهٔ خودکار: نزدیک‌ترین نسخهٔ تاریخیِ همین فایل به آنچه روی سرور است
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
    cp "$mine_f" "$dest"; echo "UP    $rel   (سرور = $(git -C repo rev-parse --short "$best"))"; UPD=$((UPD+1)); return
  fi

  git -C repo show "$best:website/$rel" > "$WORK/base.raw"
  normalize "$WORK/base.raw" "$base_f"
  m="$WORK/merged.tmp"; cp "$dest_n" "$m"
  if git merge-file -L server -L base -L new "$m" "$base_f" "$mine_f" >/dev/null 2>&1; then
    cp "$m" "$dest"
    echo "MG    $rel   (پایه $(git -C repo rev-parse --short "$best")، فاصلهٔ سرور $bestd خط — تغییرِ دیگران حفظ شد)"
    UPD=$((UPD+1))
  else
    echo "CF    $rel   ← تداخل واقعی؛ دست نخورد (پایه $(git -C repo rev-parse --short "$best")، فاصله $bestd خط)"
    CONFLICTS="$CONFLICTS $rel"
    keep="$WORK/conflicts/$rel"; mkdir -p "$(dirname "$keep")"
    cp "$dest" "$keep.server"; cp "$base_f" "$keep.base"; cp "$mine_f" "$keep.new"
  fi
}

echo "── بکاپ در: $BK"
echo
echo "═══ اپ ($APP) ═══"
for f in $APP_FILES; do apply_one "$f"; done

# ── ضمانتِ اتحاد ────────────────────────────────────────────────────────
# کلاسِ تازه‌ای که کنترلر/کرون صدایش می‌زند اگر ننشسته باشد، ۵۰۰ می‌گیریم —
# همین‌جا بفهمیم، نه از پنلِ مشتری.
echo
union_ok=1
need_file() { [ -f "$1" ] || { echo "🔴 نیست: ${1#$APP/}"; union_ok=0; }; }

need_file "$APP/app/Services/Domain/DomainCostFloor.php"
need_file "$APP/app/Services/Domain/DomainRenewalInvoicer.php"
need_file "$APP/app/Console/Commands/PruneDomainQuotes.php"
need_file "$APP/database/migrations/2026_08_24_000100_add_cost_renew_amount_to_domain_tables.php"
need_file "$APP/database/migrations/2026_08_24_000200_add_customer_id_to_domain_quotes.php"

grep -q "function renew(" "$APP/app/Http/Controllers/Account/DomainController.php" 2>/dev/null \
  || { echo "🔴 DomainController هنوز renew() ندارد"; union_ok=0; }
grep -q "registrationBlocker" "$APP/app/Services/Domain/DomainRegistrar.php" 2>/dev/null \
  || { echo "🔴 DomainRegistrar هنوز registrationBlocker ندارد"; union_ok=0; }
grep -q "registrationBlocker" "$APP/app/Console/Commands/ResolveStuckDomains.php" 2>/dev/null \
  || { echo "🔴 resolve-stuck هنوز سؤالِ درست را نمی‌پرسد"; union_ok=0; }
grep -q "claimFor" "$APP/app/Models/DomainQuote.php" 2>/dev/null \
  || { echo "🔴 DomainQuote هنوز claimFor ندارد"; union_ok=0; }
grep -q "AUTH_DOWN_KEY" "$APP/app/Services/Domain/OpenProviderClient.php" 2>/dev/null \
  || { echo "🔴 مدارشکنِ ۱۹۶ ننشسته"; union_ok=0; }
grep -q "recordCreditSale" "$APP/app/Services/Finance/BusinessLedger.php" 2>/dev/null \
  || { echo "🔴 BusinessLedger هنوز recordCreditSale ندارد"; union_ok=0; }
grep -q "DomainRenewalInvoicer" "$APP/app/Console/Commands/RunDomainLifecycle.php" 2>/dev/null \
  || { echo "🔴 چرخهٔ عمر هنوز به سرویسِ فاکتور وصل نیست"; union_ok=0; }
grep -qF "name('domain.renew')" "$APP/routes/web.php" 2>/dev/null \
  || { echo "🔴 routes/web.php: روتِ تمدید نیست"; union_ok=0; }
grep -qF "domains:prune-quotes" "$APP/routes/console.php" 2>/dev/null \
  || { echo "🔴 routes/console.php: کرونِ هرس ثبت نشده"; union_ok=0; }
grep -qF "account.domain.renew" "$APP/resources/views/account/domain-show.blade.php" 2>/dev/null \
  || { echo "🔴 صفحهٔ دامنه هنوز دکمهٔ تمدید ندارد"; union_ok=0; }

# روت‌های موجود نباید بیفتند (درسِ ۲۸ مرداد)
for r in "name('aup')" "name('cloud.index')" "name('domain.search')" "name('contact')" "name('blog')" "name('domain.transfer.submit')"; do
  grep -qF "$r" "$APP/routes/web.php" || { echo "🔴 routes/web.php: روتِ $r گم شده"; union_ok=0; }
done

# کلیدهای سه‌زبانه (کشنده نیست ولی «نامشخص» برمی‌گردد)
for L in fa en tr; do
  grep -q "dmn_state_transfer_epp" "$APP/lang/$L/ui.php" 2>/dev/null \
    || echo "⚠️ lang/$L/ui.php کلیدهای انتقال را ندارد (برچسبِ وضعیت ناقص می‌مانَد)"
done

if [ "$union_ok" -eq 0 ]; then
  echo "🔴 اتحادِ فایل‌ها کامل نیست — کلِ بکاپ برمی‌گردد تا سایت ۵۰۰ نشود."
  ( cd "$BK" && find . -type f | while read -r p; do
      rel="${p#./}"; cp "$p" "$APP/$rel" && echo "   بازگشت: $rel"
    done )
  echo "🔴 دیپلوی ناتمام؛ مهاجرت‌ها هم اجرا نشدند. خروجیِ بالا را بفرست."
  exit 1
fi

# ── مهاجرت‌ها — فقط دو مهاجرتِ همین کار ─────────────────────────────────
PHPBIN=/opt/cpanel/ea-php84/root/usr/bin/php
[ -x "$PHPBIN" ] || PHPBIN=$(command -v php)

if [ -n "$PHPBIN" ]; then
  cd "$APP"
  echo
  echo "═══ مهاجرت‌ها (فقط --path همین دو فایل) ═══"
  "$PHPBIN" artisan migrate --force \
    --path=database/migrations/2026_08_24_000100_add_cost_renew_amount_to_domain_tables.php \
    --path=database/migrations/2026_08_24_000200_add_customer_id_to_domain_quotes.php \
    || echo "🔴 مهاجرت شکست خورد — کد با گاردِ hasColumn امن است ولی کفِ ارزی و مالکیتِ استعلام تا اجرای دستی خاموش‌اند."

  "$PHPBIN" artisan config:clear && "$PHPBIN" artisan route:clear && "$PHPBIN" artisan view:clear
else
  rm -f "$APP/bootstrap/cache/config.php" "$APP/bootstrap/cache/routes-v7.php"
  echo "WARN: php پیدا نشد — کش‌ها دستی پاک شدند و مهاجرت‌ها اجرا **نشدند**"
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
echo "کارِ باقی‌مانده: ریستِ opcache (validate_timestamps=0 — بی‌ریست کدِ تازه اجرا نمی‌شود)"
echo
echo "راستی‌آزمایی:"
echo "  curl -sI https://servernet.cloud/?qa=1                    | head -1   ← 200"
echo "  curl -sI https://servernet.cloud/domains?qa=1             | head -1   ← 200"
echo "  curl -sI https://servernet.cloud/domain/popular-tlds?qa=1 | head -1   ← 200"
echo "  curl -s  https://servernet.cloud/domain/popular-tlds?qa=1 | grep -c 'DNSSEC'    ← 0 (وعدهٔ دروغ حذف شده)"
echo "  و از پنلِ یک مشتریِ دامنه‌دار: بخشِ «تمدید دامنه» باید دیده شود."
echo "  پنلِ مدیر → سلامت: چکِ «حاشیهٔ سودِ دامنه» — اگر warn است، domain_margin_pct را در تنظیمات بگذارید."