{{--
  استایلِ داشبوردهای مالی — **یک تعریف، دو صفحه**.

  🔴 چرا partial و نه کپی در هر ویو: این بلوک تا امروز داخلِ خودِ
  `finance.blade.php` بود، و وقتی صفحهٔ «گزارشِ کسب‌وکار» همان کلاس‌ها را به کار
  برد، **کاملاً بی‌استایل رندر شد** — بی‌هیچ خطایی، با کدِ ۲۰۰. همان تلهٔ
  ثبت‌شدهٔ پروژه: کلاسِ CSSِ نبود صدا نمی‌کند.

  🔴 چرا در `admin.css` نرفت: آن فایل append-only است و هم‌زمان دستِ چند نفر؛
  این بلوک مالِ دو صفحهٔ مشخص است و کنارِ خودشان می‌مانَد. اگر روزی صفحهٔ سومی
  هم لازمش داشت، همین را include کند.
--}}
<style>
.fin-kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:16px }
@media(max-width:900px){ .fin-kpis{ grid-template-columns:1fr 1fr } }
.fin-kpi{ background:#111725; border:1px solid var(--line); border-radius:14px; padding:16px 18px; display:flex; flex-direction:column; gap:5px }
.fin-kpi-l{ font-size:12.5px; color:var(--muted) }
.fin-kpi-v{ font-size:26px; font-weight:800; font-variant-numeric:tabular-nums; letter-spacing:-.5px; line-height:1.2 }
.fin-kpi small{ font-size:11px; color:var(--dim) }

.fin-cols{ display:grid; grid-template-columns:1fr 380px; gap:16px; align-items:start }
@media(max-width:1000px){ .fin-cols{ grid-template-columns:1fr } }

.fin-pl{ width:100%; border-collapse:collapse }
.fin-pl td{ padding:12px 16px; border-bottom:1px solid var(--line); font-size:13.5px }
.fin-pl tr:last-child td{ border-bottom:0 }
.fin-pl .fin-total td{ font-weight:800; background:var(--surface2) }
.fin-num{ text-align:end; font-variant-numeric:tabular-nums; white-space:nowrap }
.fin-src{ text-align:end; font-size:11px; color:var(--dim); width:1%; white-space:nowrap }

.fin-cat{ padding:14px 16px; border-top:1px solid var(--line) }
.fin-cat-h{ font-size:12px; color:var(--muted); margin-bottom:10px }
.fin-cat-row{ display:grid; grid-template-columns:130px 1fr auto; align-items:center; gap:10px; margin-bottom:8px; font-size:12.5px }
.fin-cat-bar{ height:7px; background:var(--line); border-radius:99px; overflow:hidden }
.fin-cat-bar i{ display:block; height:100%; background:#ff6b6b; border-radius:99px }

.fin-chart{ display:flex; align-items:flex-end; gap:10px; height:170px; padding:16px 16px 8px }
.fin-bar-group{ flex:1; display:flex; flex-direction:column; align-items:center; gap:6px; height:100% }
.fin-bars{ flex:1; display:flex; align-items:flex-end; gap:3px; width:100% }
.fin-bar{ flex:1; border-radius:4px 4px 0 0; min-height:2px; transition:height .3s }
.fin-bar.rev{ background:#34d399 }
.fin-bar.exp{ background:#ff6b6b }
.fin-bar-x{ font-size:10px; color:var(--dim) }
.fin-legend{ display:flex; gap:16px; justify-content:center; padding:0 0 14px; font-size:12px; color:var(--muted) }
.fin-legend i{ display:inline-block; width:10px; height:10px; border-radius:3px; vertical-align:middle; margin-inline-end:5px }

/* برچسبِ دلیلِ حذف یک جملهٔ کامل است، پس ستونِ ۱۳۰پیکسلیِ دسته‌های هزینه
   جوابش نمی‌دهد — نامِ گزینه اول می‌آید و میله باریک‌تر می‌شود. */
.fin-churn .fin-cat-row{ grid-template-columns:1fr 110px auto }
.fin-churn .fin-cat-row > span:first-child{ overflow-wrap:anywhere }

.fin-form{ padding:16px; display:flex; flex-direction:column; gap:8px }
.fin-form label{ font-size:12px; color:var(--muted); margin-top:6px }
.fin-submit{ margin-top:14px; background:#22d3ee; color:#04121f; border:0; border-radius:10px; padding:11px; font:inherit; font-weight:700; cursor:pointer }
</style>
