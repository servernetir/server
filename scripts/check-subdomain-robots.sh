#!/usr/bin/env bash
#
# پایشِ robots.txt در **همهٔ** میزبان‌های propertyِ دامنه.
#
#   bash <(curl -fsSL https://raw.githubusercontent.com/servernetir/server/develop/scripts/check-subdomain-robots.sh)
#
# فقط می‌خوانَد — هیچ‌جا چیزی نمی‌نویسد. از هر ماشینی اجرا می‌شود.
#
# ═══ چرا لازم است ═══
#
# Search Console یک propertyِ **دامنه‌ای** است (`sc-domain:servernet.cloud`)،
# پس بودجهٔ خزش بینِ ۱۳ میزبان تقسیم می‌شود — نه فقط سایتِ اصلی. Crawl Stats
# (۹۰ روز، ۹ شهریور ۱۴۰۵): ۵٬۷۸۰ درخواست، که ۶۰۰تایش خرجِ کنسول می‌شد؛
# میزبانی که هر صفحه‌اش noindex است.
#
# 🔴 و این وضعیت **خاموش** خراب می‌شود: زیردامنهٔ تازه (ابزارِ داخلی، سایتِ
# مشتری، پنلِ آزمایشی) بی‌robots بالا می‌آید، گوگل شروع به خزیدنش می‌کند، و
# هیچ‌جای پروژه خبردار نمی‌شود. این اسکریپت همان لحظه نشانش می‌دهد.
#
set -u

APEX="servernet.cloud"

# میزبان‌هایی که Crawl Stats نشان داد. زیردامنهٔ تازه که اضافه شد، این‌جا هم
# اضافه شود — وگرنه پایش دربارهٔ چیزی که نمی‌بیند سکوت می‌کند.
HOSTS="
$APEX
console.$APEX
www.$APEX
pay.$APEX
bpms.$APEX
bpmn.$APEX
crm.$APEX
flow.$APEX
my.$APEX
meet.$APEX
remote.$APEX
"

UA='Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
fail=0

printf '%-26s %-5s %-11s %-9s %s\n' 'میزبان' 'کد' 'سرور' 'وضعیت' 'توضیح'
printf '%s\n' '────────────────────────────────────────────────────────────────────────────'

for h in $HOSTS; do
  tmp=$(mktemp 2>/dev/null || echo "/tmp/rb.$$")
  code=$(curl -s -o "$tmp" -w '%{http_code}' -A "$UA" --max-time 20 "https://$h/robots.txt" 2>/dev/null)
  srv=$(curl -sI -A "$UA" --max-time 20 "https://$h/robots.txt" 2>/dev/null | tr -d '\r' \
        | awk 'tolower($1)=="server:"{print $2; exit}')

  body=$(cat "$tmp" 2>/dev/null)
  rm -f "$tmp"

  # 🔴 «۲۰۰ گرفتم» یعنی هیچ. سه حالتِ متفاوت پشتِ یک کدِ ۲۰۰ پنهان می‌شود و
  #    فقط با نگاه‌کردن به **بدنه** از هم جدا می‌شوند.
  if printf '%s' "$body" | grep -qiE '^\s*<'; then
    state='HTML'; note='بدنه HTML است، نه robots — گوگل «مجاز» می‌خواندش'
  elif ! printf '%s' "$body" | grep -qiE '^\s*User-agent:'; then
    state='بی‌قاعده'; note='هیچ User-agent ی ندارد ⇒ عملاً «همه‌چیز مجاز»'
  elif printf '%s' "$body" | grep -qiE '^\s*Disallow:\s*/\s*$'; then
    state='بسته'; note='از خزش بیرون است ✅'
  else
    state='باز'; note='خزیده می‌شود'
  fi

  case "$code" in
    3*) state='ریدایرکت'; note='robots.txt نباید ۳۰۲ بدهد — گوگل «در دسترس نیست» ثبت می‌کند' ;;
    5*) state='۵xx'; note='🔴 بدترین حالت: گوگل خزشِ کلِ میزبان را متوقف می‌کند' ;;
  esac

  printf '%-26s %-5s %-11s %-9s %s\n' "$h" "$code" "${srv:0:10}" "$state" "$note"

  # ═══ دو ادعای سخت — بقیه گزارش‌اند، این دو گارد ═══
  #
  # ⚠️ جهتِ هرکدام برعکسِ دیگری است و همین نکته است: «همه را ببند» غلط است.
  # سایتِ اصلی باید باز بمانَد وگرنه کلِ کسب‌وکار از گوگل بیرون می‌رود.
  if [ "$h" = "$APEX" ] && [ "$state" != 'باز' ]; then
    echo "  🔴 سایتِ اصلی باید باز باشد — این یعنی کلِ سایت از گوگل بیرون می‌رود."
    fail=1
  fi
  if [ "$h" = "console.$APEX" ] && [ "$state" != 'بسته' ]; then
    echo "  🔴 کنسول دوباره باز شده — قاعدهٔ .htaccess برداشته شده یا کار نمی‌کند."
    fail=1
  fi
done

echo
echo "⚠️ «باز» به‌تنهایی خرابی نیست — برای سایتِ اصلی درست است. مسئله آن"
echo "   میزبان‌هایی‌اند که هیچ صفحه‌شان برای جست‌وجو نیست و باز مانده‌اند."
echo
echo "⚠️ www عمداً بسته نمی‌شود: هر مسیرش ۳۰۱ به دامنهٔ اصلی است و بستنش یعنی"
echo "   گوگل آن ۳۰۱ها را نمی‌بیند و اعتبارِ لینک‌ها یک‌جا نمی‌نشیند."
echo
[ "$fail" -eq 0 ] && echo "✅ هر دو گاردِ سخت سبزند." || echo "🔴 گاردِ سخت شکست — بالا را بخوان."
exit "$fail"
