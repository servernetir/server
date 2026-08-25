#!/usr/bin/env bash
# rg-core.sh — سه تستِ هسته‌ی ممیزی ۷ + هشت عددِ هفتگی، روی سایتِ زنده.
#
#   اجرا:            bash scripts/rg-core.sh ; echo $?
#   میزبانِ دیگر:    BASE=https://staging.example bash scripts/rg-core.sh
#
# معیارِ دودوییِ دورِ هشتم (مدیر تست): «آیا rg-core.sh اجرا شد و خروجیِ خام
# ثبت و ارائه شد؟ بله/خیر. حتی اگر خروجی FAIL باشد، معیار "بله" است؛ شکستِ
# فرایند "اجرا نشدن" است، نه "نتیجه‌ی بد".»
#
# T1  RG-DUP-PATH-11   هیچ URLِ sitemap با کدِ کشورِ دوبل /xx-xx-…      (حد: ۰)
# T2  RG-META-UNIQ-13  هیچ عنوانِ تکراری میانِ صفحاتِ فارسی             (حد: ۰)
# T3  RG-H1-15         هر صفحه دقیقاً یک <h1>                            (حد: ۰ تخلف)
#
# سپس «هشت عدد» — بدونِ تفسیر، بدونِ گزارش (جایگزینِ ممیزیِ کامل تا ۳۰ روز):
#   ۱ صفحاتِ فارسیِ sitemap   ۲ تفاوت با اجرای قبلی   ۳ صفحاتِ /order در sitemap
#   ۴ عنوانِ تکراری           ۵ صفحاتِ چند-H1          ۶ نسبتِ imgِ بدونِ alt
#   ۷ کش: HIT/BYPASS چهار مسیر                        ۸ TTFB سه مسیر + /healthz
#
# خزش با همزمانیِ ۱ و مکثِ ۰.۳ ثانیه — تا ۴۰۳/بارِ مصنوعی نسازیم (قاعده‌ی ممیزی).

set -u
BASE="${BASE:-https://servernet.cloud}"
UA="servernet-qa/1.0 (rg-core)"
O="${RG_OUT:-/tmp/rg}"
mkdir -p "$O"
FAIL=0

say() { printf '%s\n' "$*"; }

# ── sitemap ← فقط مسیرهای فارسی ────────────────────────────────────────────
curl -s --max-time 30 -A "$UA" "$BASE/sitemap.xml" \
  | grep -oE '<loc>[^<]+' | sed 's|<loc>||' \
  | grep -vE "^$BASE/(en|tr)(/|$)" | sort -u > "$O/urls.txt"

N_PAGES=$(wc -l < "$O/urls.txt" | tr -d ' ')

if [ "$N_PAGES" -lt 50 ]; then
  say "FATAL: sitemap فقط $N_PAGES مسیر داد — یا سایت پایین است یا sitemap شکسته"
  exit 2
fi

# ── خزش: عنوان + شمارِ h1 + شمارِ img و imgِ بدونِ alt ─────────────────────
: > "$O/meta.tsv"
while IFS= read -r u; do
  b=$(curl -s --max-time 20 -A "$UA" "$u" | tr '\n' ' ')
  t=$(printf '%s' "$b" | sed -n 's|.*<title>\(.*\)</title>.*|\1|p' | head -1)
  h=$(printf '%s' "$b" | grep -oiE '<h1[ >]' | wc -l | tr -d ' ')
  gi=$(printf '%s' "$b" | grep -oiE '<img[^>]*>' | wc -l | tr -d ' ')
  ga=$(printf '%s' "$b" | grep -oiE '<img[^>]*>' | grep -civE 'alt=' || true)
  printf '%s\t%s\t%s\t%s\t%s\n' "$u" "${t:-NO_TITLE}" "${h:-0}" "${gi:-0}" "${ga:-0}" >> "$O/meta.tsv"
  sleep 0.3
done < "$O/urls.txt"

say "── سه تستِ هسته ──"

# T1 — کدِ کشورِ دوبل در sitemap
grep -E '/([a-z]{2})-\1-' "$O/urls.txt" > "$O/f_dup.txt" || true
d=$(wc -l < "$O/f_dup.txt" | tr -d ' ')
say "T1 dup-path: $d (حد: 0)"
[ "$d" -gt 0 ] && FAIL=1

# T2 — یکتاییِ عنوان (فارسی)
cut -f2 "$O/meta.tsv" | grep -v '^NO_TITLE$' | sort | uniq -d > "$O/f_title.txt" || true
n=$(wc -l < "$O/f_title.txt" | tr -d ' ')
say "T2 dup-title groups: $n (حد: 0)"
[ "$n" -gt 0 ] && FAIL=1

# T3 — دقیقاً یک H1
awk -F'\t' '$3 != 1 {print $1 "\t" $3}' "$O/meta.tsv" > "$O/f_h1.txt" || true
n=$(wc -l < "$O/f_h1.txt" | tr -d ' ')
say "T3 h1!=1: $n (حد: 0)"
[ "$n" -gt 0 ] && FAIL=1

say ""
say "── هشت عدد (بدونِ تفسیر) ──"

# ۱ و ۲ — شمارِ صفحات و تفاوت با اجرای قبل
PREV="$O/urls.prev.txt"
if [ -f "$PREV" ]; then
  DIFF_NEW=$(comm -13 "$PREV" "$O/urls.txt" | wc -l | tr -d ' ')
  DIFF_GONE=$(comm -23 "$PREV" "$O/urls.txt" | wc -l | tr -d ' ')
else
  DIFF_NEW=- ; DIFF_GONE=-
fi
cp "$O/urls.txt" "$PREV"
say "1) صفحات فارسی sitemap: $N_PAGES"
say "2) نسبت به اجرای قبل: +$DIFF_NEW / -$DIFF_GONE"

# ۳ — صفحاتِ /order در sitemap
say "3) /order در sitemap: $(grep -cE "^$BASE/order/" "$O/urls.txt")"

# ۴ و ۵ — از تست‌های بالا
say "4) گروه عنوان تکراری: $(wc -l < "$O/f_title.txt" | tr -d ' ')"
say "5) صفحات چند/بدون H1: $(wc -l < "$O/f_h1.txt" | tr -d ' ')"

# ۶ — نسبتِ imgِ بدونِ صفتِ alt
awk -F'\t' '{gi+=$4; ga+=$5} END{ if (gi>0) printf "6) img بدون alt: %d از %d (%.0f%%)\n", ga, gi, 100*ga/gi; else print "6) img بدون alt: 0 از 0" }' "$O/meta.tsv"

# ۷ — کش: دو بارِ پیاپی، هدرِ X-Cache (دامپِ هدر — header_json به curlِ نو نیاز داشت)
printf '7) کش (بار دوم):'
for p in / /cloud /parts /urmia; do
  curl -s -o /dev/null --max-time 20 -A "$UA" "$BASE$p"
  x=$(curl -s -o /dev/null -D - --max-time 20 -A "$UA" "$BASE$p" \
      | tr -d '\r' | awk -F': ' 'tolower($1)=="x-cache"{print $2; exit}')
  printf ' %s=%s' "$p" "${x:-?}"
done
printf '\n'

# ۸ — TTFB سه مسیر + /healthz (سه نمونه، میانه)
printf '8) TTFB(ms):'
for p in / /cloud /order/wordpress-3 /healthz; do
  m=$(for i in 1 2 3; do
        curl -s -o /dev/null --max-time 20 -A "$UA" -w '%{time_starttransfer}\n' "$BASE$p"
        sleep 1
      done | sort -n | sed -n '2p')
  printf ' %s=%s' "$p" "$(awk -v v="$m" 'BEGIN{printf "%d", v*1000}')"
done
printf '\n'

say ""
say "نتیجه: $([ $FAIL -eq 0 ] && echo PASS || echo FAIL) — جزئیات: $O/f_dup.txt $O/f_title.txt $O/f_h1.txt"
exit $FAIL
