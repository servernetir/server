#!/usr/bin/env bash
# انتشارِ DNSِ مدیریت‌شده (v1 + v2) روی سایتِ اصلی — با پرچمِ **خاموش**.
# از ترمینالِ cPanel با کاربرِ servernetcloud:
#
#   ۱) DRY=1 bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/<SHA>/scripts/deploy-managed-dns.sh) <SHA>
#   ۲) فقط اگر «DRY OK» بود، همان فرمان بدونِ DRY=1
#   ۳) دو مهاجرت (فرمانِ دقیق را خودِ اسکریپت در پایان چاپ می‌کند)
#   ۴) ریستِ opcache از /system/opcache (validate_timestamps=0)
#
# ═══ پس از انتشار هیچ چیزی برای مشتری دیده نمی‌شود ═══
#
# پرچمِ `dns_managed_enabled` پیش‌فرض خاموش است: همهٔ مسیرهای مشتریِ /account/dns
# ۴۰۴ می‌دهند و آیتمِ منو فقط وقتی ظاهر می‌شود که پرچم روشن، کلیدِ PowerDNS
# ثبت و دو نیم‌سرور تنظیم شده باشند. پیش از مهاجرت هم همه‌چیز بی‌خطاست: هر
# صفحه و فرمان نبودِ جدول را می‌سنجد و موفق برمی‌گردد.
#
# ═══ دو سازوکار ═══
#
#  NEW   ۵۲ فایلِ تازه + ۲ asset (v1 هرگز روی سرور نرفت) — کپی. اگر روی سرور باشد و فرق کند
#        ⇒ خطا، نه بازنویسی.
#  BLOCK پنج فایلِ مشترک — بلوکِ نشان‌دارِ `[managed-dns…]` در یک لنگر
#        (`apply-marked-block.php`)؛ بیرون از بلوک بایت‌به‌بایت دست نمی‌خورد، پس
#        هیچ تغییرِ دیگرِ develop همراهِ این انتشار نمی‌رود (سرور زیرمجموعهٔ develop است).
#        هیچ فایلِ ترجمهٔ مشترکی عوض نمی‌شود: `lang/*/dns.php` فایلِ تازه است.
set -u

DRY="${DRY:-0}"
MINE="${1:?SHA دقیق نسخهٔ هدف الزامی است}"
BRANCH="${BRANCH:-develop}"
APP="$HOME/servernet_app"
WEB="$HOME/public_html"
WORK="$HOME/deploy-managed-dns"
STAMP="$(date +%Y%m%d-%H%M%S)"
BK="$WORK/backup-$STAMP"
STAGE="$WORK/stage-$STAMP"

# ── مقصد پیش از هر نوشتنی اثبات می‌شود ──
if [ ! -f "$APP/artisan" ] || [ ! -d "$APP/vendor" ]; then
  echo "FATAL: $APP نصبِ لاراول نیست (کاربر: $(id -un)، HOME=$HOME). کاربرِ درست: servernetcloud"
  exit 1
fi
if [ ! -d "$WEB/assets" ]; then
  echo "FATAL: $WEB/assets نیست — وب‌روتِ درست پیدا نشد."
  exit 1
fi

if [ -x /opt/cpanel/ea-php84/root/usr/bin/php ]; then
  PHP_BIN=/opt/cpanel/ea-php84/root/usr/bin/php
else
  PHP_BIN="$(command -v php 2>/dev/null || true)"
fi
if [ -z "$PHP_BIN" ] || ! "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'; then
  echo "FATAL: PHP 8.3+ در دسترس نیست؛ هیچ فایلی نوشته نشد."
  exit 1
fi

FREE_MB="$(df -Pm "$HOME" | awk 'NR==2{print $4}')"
if [ "${FREE_MB:-0}" -lt 300 ]; then
  echo "FATAL: فضای آزاد کمتر از 300MB است (${FREE_MB}MB)."
  exit 1
fi

mkdir -p "$WORK" "$BK" "$STAGE"
if [ -d "$WORK/repo/.git" ]; then
  git -C "$WORK/repo" fetch --depth 500 origin "$BRANCH" || exit 1
else
  git clone --depth 500 --branch "$BRANCH" https://github.com/servernetir/server.git "$WORK/repo" || exit 1
fi
git -C "$WORK/repo" rev-parse --verify "$MINE^{commit}" >/dev/null 2>&1 || { echo "FATAL: کامیت $MINE در مخزن نیست."; exit 1; }
# 🔴 وجودِ شیء کافی نیست: کامیت باید روی شاخه باشد (سرور زیرمجموعهٔ develop می‌مانَد)
git -C "$WORK/repo" merge-base --is-ancestor "$MINE" FETCH_HEAD 2>/dev/null || {
  echo "FATAL: $MINE روی $BRANCH نیست — انتشارِ کارِ ادغام‌نشده ممنوع است."; exit 1; }
echo "── هدف: $(git -C "$WORK/repo" log -1 --format='%h %s' "$MINE")"

BLOCK_TOOL="$WORK/apply-marked-block.php"
git -C "$WORK/repo" cat-file blob "$MINE:scripts/apply-marked-block.php" > "$BLOCK_TOOL" 2>/dev/null || {
  echo "FATAL: scripts/apply-marked-block.php در نسخهٔ هدف نیست"; exit 2; }
"$PHP_BIN" -l "$BLOCK_TOOL" >/dev/null || { echo "FATAL: ابزارِ بلوک سالم نیست"; exit 2; }

# ── ترتیب معنادار است: پشتیبان‌ها پیش از صداکننده، ویو پیش از روت؛ روت‌ها آخر ──
NEW_FILES="
config/edge.php
app/Support/Rfc1982.php
app/Services/Dns/Wire/DnsFailure.php
app/Services/Dns/Wire/DnsMessage.php
app/Services/Dns/Wire/DnsResponse.php
app/Services/Dns/Wire/DnsWireClient.php
app/Services/Dns/DomainName.php
app/Services/Dns/TxtCodec.php
app/Services/Dns/DnsConfig.php
app/Services/Dns/AdoptRefused.php
app/Services/Dns/PublishResult.php
app/Services/Dns/RecordValidator.php
app/Services/Dns/RecordSetIndex.php
app/Services/Dns/ZoneFile/ParsedZone.php
app/Services/Dns/ZoneFile/ZoneFileParser.php
app/Services/Dns/ZoneFile/ZoneFileWriter.php
app/Services/Dns/DelegationInspector.php
app/Services/Dns/PowerDnsClient.php
app/Services/Dns/NodeVerifier.php
app/Services/Dns/ZonePublisher.php
app/Services/Dns/ZoneImporter.php
app/Services/Dns/ZoneService.php
app/Services/Dns/OwnershipService.php
app/Models/CdnZone.php
app/Models/CdnRecord.php
app/Models/CdnEvent.php
app/Models/CdnOrigin.php
app/Models/CdnCacheRule.php
app/Models/CdnWafRule.php
app/Models/CdnCertificate.php
app/Models/CdnUsage.php
app/Models/CdnNode.php
app/Models/DnsOwnershipClaim.php
app/Models/DnsZoneNode.php
app/Console/Commands/DnsPublish.php
app/Console/Commands/DnsCheckNodes.php
app/Console/Commands/DnsCheckDelegation.php
app/Console/Commands/DnsReconcile.php
app/Console/Commands/DnsInspect.php
database/migrations/2026_11_03_000100_create_cdn_tables.php
database/migrations/2026_11_03_000200_managed_dns_v2.php
lang/fa/dns.php
lang/en/dns.php
lang/tr/dns.php
resources/views/account/dns/record-form.blade.php
resources/views/account/dns/index.blade.php
resources/views/account/dns/claim.blade.php
resources/views/account/dns/zone.blade.php
resources/views/account/dns/import.blade.php
resources/views/admin/dns.blade.php
app/Http/Controllers/Account/DnsController.php
app/Http/Controllers/Admin/DnsController.php
"
ASSETS="
assets/css/dns.css
assets/js/dns.js
"
BLOCK_FILES="
app/Providers/AppServiceProvider.php
app/Http/Controllers/Account/AccountController.php
resources/views/admin/layout.blade.php
routes/console.php
routes/web.php
"
# چیزهایی که کدِ تازه به آن‌ها تکیه دارد و این انتشار نمی‌آوردشان
DEPENDS="
app/helpers.php
app/Models/Setting.php
app/Models/Customer.php
app/Models/Domain.php
app/Models/Service.php
app/Services/NetworkTools.php
resources/views/panel/layout.blade.php
"

echo "── موجودی روی سرور ──"
MISSING=""
for rel in $DEPENDS $BLOCK_FILES; do
  [ -f "$APP/$rel" ] || { echo "MISS $rel"; MISSING="$MISSING $rel"; }
done
need() { grep -q -- "$2" "$APP/$1" 2>/dev/null || MISSING="$MISSING $1::$2"; }
need app/Models/Setting.php 'function putSecret'
need app/Models/Setting.php 'function getSecret'
need app/Services/NetworkTools.php 'function dns('
need app/helpers.php 'function lroute'
need app/helpers.php 'function asset_ver'
need app/helpers.php 'function sdate'
if [ -n "$MISSING" ]; then
  echo "FATAL: پیش‌نیاز روی سرور نیست:$MISSING — هیچ فایلی نوشته نشد."
  exit 2
fi
echo "همهٔ پیش‌نیازها هستند."

normalize() { tr -d '\r' < "$1" | sed -e '$a\' > "$2"; }
CONFLICTS=""
UPD=0
STAGED=""
stage_put() { mkdir -p "$STAGE/$(dirname "$1")"; cp "$2" "$STAGE/$1"; STAGED="$STAGED $1"; UPD=$((UPD+1)); }

apply_new() {
  rel="$1"; dest="$APP/$rel"
  git -C "$WORK/repo" cat-file blob "$MINE:website/$rel" > "$WORK/mine.raw" 2>/dev/null || {
    echo "FATAL: $rel در نسخهٔ هدف نیست"; CONFLICTS="$CONFLICTS $rel"; return; }
  normalize "$WORK/mine.raw" "$WORK/mine.tmp"
  if [ -f "$dest" ]; then
    normalize "$dest" "$WORK/dest.tmp"
    if cmp -s "$WORK/dest.tmp" "$WORK/mine.tmp"; then echo "OK   $rel"; return; fi
    echo "CF   $rel — روی سرور هست و با نسخهٔ هدف فرق دارد"; CONFLICTS="$CONFLICTS $rel"; return
  fi
  echo "NEW  $rel"; stage_put "$rel" "$WORK/mine.tmp"
}

# apply_block <rel> <marker> <anchor> <--before|--after> <absent-regex>
# کپیِ stage فقط بارِ اول از فایلِ زنده ساخته می‌شود (routes/web.php دو بلوک دارد)
BLOCK_CHANGED=""
apply_block() {
  rel="$1"; marker="$2"; anchor="$3"; pos="$4"; absent="$5"
  srcfile="$WORK/block-src-$marker-$(echo "$rel" | tr '/' '_')"
  git -C "$WORK/repo" cat-file blob "$MINE:website/$rel" > "$srcfile" 2>/dev/null || {
    echo "FATAL: $rel در نسخهٔ هدف نیست"; CONFLICTS="$CONFLICTS $rel"; return; }
  bstage="$WORK/blockstage-$STAMP/$rel"
  if [ ! -f "$bstage" ]; then mkdir -p "$(dirname "$bstage")"; cp -p "$APP/$rel" "$bstage"; fi
  out="$("$PHP_BIN" "$BLOCK_TOOL" "$srcfile" "$bstage" "$marker" "$anchor" "$pos" "--absent=$absent" 2>&1)"
  code=$?
  if [ $code -ne 0 ]; then echo "CF   $rel [$marker] — $out"; CONFLICTS="$CONFLICTS $rel"; return; fi
  case "$out" in
    SAME*) echo "OK   $rel [$marker]" ;;
    *)     echo "BLK  $rel [$marker] — $out"
           case " $BLOCK_CHANGED " in *" $rel "*) ;; *) BLOCK_CHANGED="$BLOCK_CHANGED $rel"; UPD=$((UPD+1)) ;; esac ;;
  esac
}

# ⚠️ `grep -c … || echo 0` روی صفر تطبیق «0» را دو بار چاپ می‌کند (grep کد ۱ می‌دهد) —
#    در تمرینِ روی HOMEِ ساختگی همین شمارش را خراب کرد. پس فقط پیش‌فرضِ خالی.
count() { n="$(grep -cF -- "$2" "$1" 2>/dev/null)"; echo "${n:-0}"; }
CRON_BEFORE="$(count "$APP/routes/console.php" 'Schedule::command')"
HAD_CRON_BLOCK="$(count "$APP/routes/console.php" '[managed-dns:start]')"

# ═══ پیش‌پرواز: هیچ‌چیز روی فایلِ زنده نوشته نمی‌شود ═══
echo; echo "═══ فایل‌های تازه ═══"
for rel in $NEW_FILES; do apply_new "$rel"; done
echo; echo "═══ بلوک‌های نشان‌دار ═══"
apply_block app/Providers/AppServiceProvider.php managed-dns \
  "'beacon' => [120, 1]," --after '/dns-lookup/'
apply_block app/Http/Controllers/Account/AccountController.php managed-dns \
  "['key' => 'other', 'icon' => 'wrench'" --before "/'key' => 'dns'/"
apply_block resources/views/admin/layout.blade.php managed-dns \
  '<a href="/admin/domains" ' --after '/href="\/admin\/dns"/'
apply_block routes/console.php managed-dns \
  "Schedule::command('site:gate')" --before '/dns:publish/'
apply_block routes/web.php managed-dns \
  "SectionController::class, 'other'])->name('other');" --after '/dns\/claims/'
apply_block routes/web.php managed-dns-admin \
  "FleetController::class, 'index'" --before '/dns\/zones\/\{zone\}\/status/'

if [ -n "$CONFLICTS" ]; then
  echo; echo "FATAL: تداخل/خطا:$CONFLICTS"
  echo "هیچ فایلی روی سرور نوشته نشد."
  exit 2
fi

# ── lint روی stage، پیش از هر نوشتنی ──
for rel in $STAGED; do
  case "$rel" in *.blade.php) ;; *.php)
    "$PHP_BIN" -l "$STAGE/$rel" >/dev/null || { echo "FATAL lint (stage): $rel"; exit 3; } ;;
  esac
done
for rel in $BLOCK_CHANGED; do
  case "$rel" in *.blade.php) ;; *.php)
    "$PHP_BIN" -l "$WORK/blockstage-$STAMP/$rel" >/dev/null || { echo "FATAL lint (block): $rel"; exit 3; } ;;
  esac
done

# 🔴 جفت‌های هم‌بسته: روت بی کنترلر ⇒ ۵۰۰ ِ سراسری
have() { [ -f "$STAGE/$1" ] || [ -f "$APP/$1" ]; }
for rel in app/Http/Controllers/Account/DnsController.php app/Http/Controllers/Admin/DnsController.php \
           app/Services/Dns/DnsConfig.php app/Services/Dns/ZoneService.php app/Services/Dns/OwnershipService.php \
           app/Console/Commands/DnsPublish.php resources/views/account/dns/index.blade.php \
           resources/views/admin/dns.blade.php config/edge.php lang/fa/dns.php; do
  have "$rel" || { echo "FATAL: $rel نه در stage است نه روی سرور، ولی به آن ارجاع داده می‌شود"; exit 2; }
done

if [ "$DRY" = "1" ]; then
  echo; echo "DRY OK — $UPD فایل نیازمندِ تغییر است (+ $(echo $ASSETS | wc -w) asset)؛ هیچ فایلی نوشته نشد."
  exit 0
fi

# ═══ اعمال ═══
put() {  # put <rel> <from>
  rel="$1"; from="$2"; dest="$APP/$rel"
  mkdir -p "$BK/$(dirname "$rel")" "$(dirname "$dest")"
  if [ -f "$dest" ]; then cp -p "$dest" "$BK/$rel"; else echo "$rel" >> "$BK/.new-files"; fi
  cp "$from" "$dest"
}
rollback() {
  echo "🔴 بازگردانیِ کامل از $BK"
  ( cd "$BK" && find . -type f ! -name '.new-files' ! -name '.new-assets' ! -path './web/*' | while read -r p; do
      r="${p#./}"; cp -p "$p" "$APP/$r"; echo "   برگشت: $r"; done )
  [ -f "$BK/.new-files" ] && while read -r r; do rm -f "$APP/$r"; echo "   حذف: $r"; done < "$BK/.new-files"
  [ -f "$BK/.new-assets" ] && while read -r a; do rm -f "$WEB/$a"; echo "   حذف: public/$a"; done < "$BK/.new-assets"
  ( cd "$APP" && "$PHP_BIN" artisan config:clear >/dev/null 2>&1; "$PHP_BIN" artisan route:clear >/dev/null 2>&1; "$PHP_BIN" artisan view:clear >/dev/null 2>&1 )
}

for rel in $NEW_FILES; do
  case " $STAGED " in *" $rel "*) put "$rel" "$STAGE/$rel" ;; esac
done
for a in $ASSETS; do
  git -C "$WORK/repo" cat-file blob "$MINE:website/public/$a" > "$WORK/asset.tmp" 2>/dev/null || { echo "FATAL: $a در نسخهٔ هدف نیست"; rollback; exit 3; }
  if [ -f "$WEB/$a" ] && cmp -s "$WEB/$a" "$WORK/asset.tmp"; then echo "OK   public/$a"; continue; fi
  mkdir -p "$BK/web/$(dirname "$a")" "$WEB/$(dirname "$a")"
  if [ -f "$WEB/$a" ]; then cp -p "$WEB/$a" "$BK/web/$a"; else echo "$a" >> "$BK/.new-assets"; fi
  cp "$WORK/asset.tmp" "$WEB/$a"; echo "PUT  public/$a"
done
# بلوک‌ها: سرویس و منو پیش از روت‌ها؛ routes/web.php آخر از همه
for rel in app/Providers/AppServiceProvider.php app/Http/Controllers/Account/AccountController.php \
           resources/views/admin/layout.blade.php routes/console.php routes/web.php; do
  case " $BLOCK_CHANGED " in *" $rel "*) put "$rel" "$WORK/blockstage-$STAMP/$rel" ;; esac
done

# ── lint روی مقصد ──
for rel in $NEW_FILES $BLOCK_FILES; do
  case "$rel" in *.blade.php) ;; *.php)
    "$PHP_BIN" -l "$APP/$rel" >/dev/null || { echo "FATAL lint: $rel"; rollback; exit 3; } ;;
  esac
done

# ── مقدار را از خودِ مقصد بسنج، نه از چاپِ موفقیت ──
ok=1
g() { grep -qF -- "$2" "$APP/$1" || { echo "🔴 $1: «$2» ننشسته"; ok=0; }; }
one() { n="$(count "$APP/$1" "$2")"; [ "$n" = "1" ] || { echo "🔴 $1: «$2» $n بار (باید ۱)"; ok=0; }; }

one app/Providers/AppServiceProvider.php '[managed-dns:start]'
one app/Http/Controllers/Account/AccountController.php '[managed-dns:start]'
one resources/views/admin/layout.blade.php '[managed-dns:start]'
one routes/console.php '[managed-dns:start]'
one routes/web.php '[managed-dns:start]'
one routes/web.php '[managed-dns-admin:start]'
g app/Providers/AppServiceProvider.php "'dns-write'"
g routes/console.php "Schedule::command('dns:publish')"
g app/Services/Dns/ZonePublisher.php 'array_merge($patch, $replace)'
g app/Http/Controllers/Account/DnsController.php 'function suspended'
for l in fa en tr; do g "lang/$l/dns.php" "'zone_suspended'"; done
for a in $ASSETS; do [ -f "$WEB/$a" ] || { echo "🔴 public/$a نیست"; ok=0; }; done

# ── کارِ دیگران که نباید برداشته شده باشد ──
g routes/console.php "Schedule::command('site:gate')"
g routes/web.php "FleetController::class, 'index'"
g app/Providers/AppServiceProvider.php "'beacon' => [120, 1],"
g app/Http/Controllers/Account/AccountController.php "'key' => 'other'"

CRON_AFTER="$(count "$APP/routes/console.php" 'Schedule::command')"
echo "کرون: $CRON_BEFORE → $CRON_AFTER"
if [ "$HAD_CRON_BLOCK" = "0" ]; then want=$((CRON_BEFORE+4)); else want=$CRON_BEFORE; fi
if [ "$CRON_AFTER" != "$want" ]; then
  echo "🔴 شمارشِ کرون غیرمنتظره است (این انتشار دقیقاً چهار فرمان می‌افزاید؛ انتظار $want)"; ok=0
fi

if [ "$ok" -ne 1 ]; then rollback; echo "🔴 انتشار ناتمام — خروجیِ بالا را بفرستید."; exit 3; fi

cd "$APP" || exit 1
"$PHP_BIN" artisan config:clear || { rollback; exit 4; }
"$PHP_BIN" artisan route:clear || { rollback; exit 4; }
"$PHP_BIN" artisan view:clear || { rollback; exit 4; }

# ── سیم‌کشیِ واقعی: روت‌ها کنترلر را پیدا می‌کنند و فرمان‌ها بی‌جدول موفق‌اند ──
ROUTES="$("$PHP_BIN" artisan route:list --path=dns --json 2>&1)"
# فقط مسیرهای خودِ این کار (کنترلرِ DnsController)؛ ابزارهای عمومیِ dns-lookup هم با --path=dns می‌آیند.
# ۱۷ مسیرِ مشتری × سه زبان (fa/en/tr) + ۷ مسیرِ مدیر = ۵۸ — تمرینِ HOMEِ ساختگی همین را داد.
NR="$(printf '%s' "$ROUTES" | grep -o 'DnsController@' | wc -l | tr -d ' ')"
if [ "$NR" != "58" ]; then
  echo "🔴 route:list برای DnsController $NR مسیر داد (انتظار ۵۸):"; printf '%s' "$ROUTES" | head -c 600; echo
  rollback; exit 4
fi
echo "روت‌ها: $NR مسیرِ DnsController (۱۷×۳ زبان + ۷ مدیر)"
"$PHP_BIN" artisan dns:publish >/dev/null 2>&1 || { echo "🔴 dns:publish روی سرورِ بی‌جدول شکست خورد"; rollback; exit 4; }
echo "فرمان‌ها: dns:publish بی‌جدول بی‌صدا موفق"

echo
echo "FILES OK — backup: $BK"
echo
echo "🔴 گام‌های باقی‌مانده، به همین ترتیب:"
echo
echo "  ۱) عددِ پایهٔ خطا:"
echo "       grep -c ' ERROR' $APP/storage/logs/laravel.log"
echo
echo "  ۲) دو مهاجرت — **با --path**، تا مهاجرت‌های معلقِ دیگران اجرا نشوند:"
echo "       cd $APP"
echo "       $PHP_BIN artisan migrate --force --path=database/migrations/2026_11_03_000100_create_cdn_tables.php"
echo "       $PHP_BIN artisan migrate --force --path=database/migrations/2026_11_03_000200_managed_dns_v2.php"
echo
echo "  ۳) ریستِ opcache از /system/opcache"
echo
echo "  ۴) دوباره:  grep -c ' ERROR' $APP/storage/logs/laravel.log   ← نباید بالا رفته باشد"
echo
echo "  پرچم خاموش است؛ /account/dns برای مشتری ۴۰۴ و منو پنهان می‌مانَد تا در /admin/dns"
echo "  کلیدِ PowerDNS و دو نیم‌سرور ثبت و پرچم روشن شود."
echo
echo "Rollback: فایل‌های $BK را به $APP برگردانید، فایل‌های $BK/.new-files و"
echo "          $BK/.new-assets را پاک کنید، سه clear، و **دوباره opcache را ریست کنید**."
echo "          مهاجرت‌ها فقط جدول/ستون می‌افزایند و ماندنشان بی‌خطر است."
