@php
    $loc = app()->getLocale();
    $rtl = $loc === 'fa';
@endphp
<!doctype html>
<html lang="{{ $loc }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ __('ui.invp_title_prefix') }} {{ $invoice->number }} — {{ __('ui.invp_brand') }}</title>
<style>
@font-face{font-family:'IRANSans';src:url('/assets/font/woff2/IRANSans-web.woff2') format('woff2'),url('/assets/font/woff/IRANSans-web.woff') format('woff');font-weight:400;font-display:swap}
@font-face{font-family:'IRANSans';src:url('/assets/font/woff2/IRANSans-Medium-web.woff2') format('woff2'),url('/assets/font/woff/IRANSans-Medium-web.woff') format('woff');font-weight:500;font-display:swap}
@font-face{font-family:'IRANSans';src:url('/assets/font/woff2/IRANSans-Bold-web.woff2') format('woff2'),url('/assets/font/woff/IRANSans-Bold-web.woff') format('woff');font-weight:700;font-display:swap}

*{ box-sizing:border-box; margin:0; padding:0 }
body{ font-family:'IRANSans',Tahoma,sans-serif; background:#eef1f6; color:#1a2233; line-height:1.9;
  -webkit-print-color-adjust:exact; print-color-adjust:exact; }
.bar{ max-width:800px; margin:16px auto; display:flex; gap:10px; justify-content:flex-end; }
.bar button,.bar a{ font:inherit; font-size:14px; border:0; border-radius:10px; padding:10px 18px; cursor:pointer; text-decoration:none; }
.bar .p{ background:#0ea5b7; color:#fff; }
.bar .b{ background:#fff; color:#334; border:1px solid #d5dbe6; }

.sheet{ max-width:800px; margin:0 auto 40px; background:#fff; border-radius:14px; overflow:hidden;
  box-shadow:0 8px 30px rgba(20,30,50,.08); }
.head{ display:flex; justify-content:space-between; align-items:flex-start; padding:26px 32px;
  background:linear-gradient(120deg,#0b1220,#12233b); color:#fff; }
.brand{ font-size:22px; font-weight:700; letter-spacing:.5px }
.brand small{ display:block; font-size:11.5px; font-weight:400; color:#9fb4cf; letter-spacing:0; margin-top:3px }
.doc{ text-align:left }
.doc h1{ font-size:17px; font-weight:700 }
.doc .num{ direction:ltr; font-size:13px; color:#9fb4cf; margin-top:2px }

.meta{ display:flex; gap:22px; flex-wrap:wrap; padding:20px 32px; border-bottom:1px solid #eef1f6; }
.party{ flex:1; min-width:220px }
.party h3{ font-size:11px; color:#8a93a6; font-weight:500; margin-bottom:6px }
.party b{ font-size:14px; color:#1a2233 }
.party div{ font-size:12.5px; color:#5b6577; margin-top:2px }
.party .ltr{ direction:ltr; text-align:right }

table{ width:100%; border-collapse:collapse; }
thead th{ background:#f6f8fb; font-size:12px; color:#6b7488; font-weight:500; padding:11px 32px; text-align:right; }
thead th.num,tbody td.num{ text-align:left; direction:ltr; font-variant-numeric:tabular-nums }
tbody td{ padding:13px 32px; font-size:13px; border-bottom:1px solid #f1f3f8; }
tbody tr:last-child td{ border-bottom:0 }
.desc{ font-size:11.5px; color:#8a93a6; margin-top:2px }

.totals{ padding:14px 32px 6px; }
.totals div{ display:flex; justify-content:space-between; font-size:13px; padding:6px 0; color:#5b6577 }
.totals .grand{ border-top:2px solid #eef1f6; margin-top:6px; padding-top:12px; font-size:15px; color:#1a2233; font-weight:700 }
.totals .num{ direction:ltr; font-variant-numeric:tabular-nums }

.pay{ margin:8px 32px 24px; border-radius:12px; padding:16px 18px; }
.pay.ok{ background:#eaf7ef; border:1px solid #bfe6cd; }
.pay.due{ background:#fff6e9; border:1px solid #f2ddb4; }
.pay h4{ font-size:13.5px; display:flex; align-items:center; gap:8px; margin-bottom:8px }
.pay.ok h4{ color:#1a8a4a } .pay.due h4{ color:#b5791a }
.pay .grid{ display:grid; grid-template-columns:1fr 1fr; gap:6px 20px; font-size:12.5px; color:#5b6577 }
.pay .grid b{ color:#1a2233 } .pay .grid .ltr{ direction:ltr }
.stamp{ display:inline-block; border:2px solid #1a8a4a; color:#1a8a4a; border-radius:8px; padding:2px 10px; font-size:12px; font-weight:700; transform:rotate(-3deg) }

/* مهرِ شرکت داخلِ همان جعبهٔ سبزِ رسید.
   ⚠️ `align-items:flex-start` عمدی است: مهر نباید ارتفاعِ جعبه را بکشد و
   ردیف‌های پرداخت را عمودی وسط‌چین کند. */
.pay-receipt{ display:flex; align-items:flex-start; gap:18px }
.pay-receipt .pay-body{ flex:1; min-width:0 }
.pay-seal{ flex:0 0 auto; text-align:center; padding-top:2px }
.pay-seal img{ max-width:120px; max-height:96px; display:block; margin:0 auto }
.pay-seal div{ font-size:10px; color:#6d7a88; margin-top:2px }

.foot{ padding:16px 32px 26px; border-top:1px solid #eef1f6; font-size:11.5px; color:#8a93a6; display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px }
.foot .ltr{ direction:ltr }

/*
| ── چاپ: یک برگهٔ A4، و جا برای چند ردیف ──
|
| 🔴 عمودی، نه افقی. قبلاً `landscape` بود و همان ریشهٔ «دو برگه شدن» است:
| A4ِ افقی فقط ۲۱۰ میلی‌متر ارتفاع دارد که با حاشیه ~۱۸۶ می‌مانَد، در حالی که
| عمودی ۲۹۷ است و ~۲۷۷ می‌مانَد — نزدیک **۵۰٪ فضای عمودیِ بیشتر**. با یک ردیف
| محصول هم افقی سرریز می‌کرد.
|
| ⚠️ فشرده‌سازی فقط زیرِ `@media print` است و نسخهٔ روی صفحه دست‌نخورده
| می‌مانَد: سندی که روی مانیتور خوانا باشد و روی کاغذ جا شود، دو نیازِ متفاوت
| است و یکی‌کردنشان یکی را خراب می‌کند.
|
| ⚠️ `line-height` سراسری ۱٫۹ است که برای متنِ فارسیِ وب درست است ولی روی سند
| ارتفاع را باد می‌کند؛ در چاپ ۱٫۵ می‌شود.
*/
@page{ size:A4 portrait; margin:10mm }

@media print{
  body{ background:#fff; line-height:1.5; font-size:12px }
  .bar{ display:none }
  .sheet{ box-shadow:none; margin:0; border-radius:0; max-width:100%; width:100% }

  .head{ padding:14px 18px }
  .brand{ font-size:17px } .brand small{ font-size:10px; margin-top:1px }
  .doc h1{ font-size:14px } .doc .num{ font-size:11px }

  .meta{ padding:10px 18px; gap:14px }
  .party h3{ font-size:9.5px; margin-bottom:3px }
  .party b{ font-size:12px }
  .party div{ font-size:10.5px; margin-top:0 }

  thead th{ padding:6px 18px; font-size:10.5px }
  tbody td{ padding:7px 18px; font-size:11.5px }
  .desc{ font-size:10px }

  .totals{ padding:8px 18px 2px }
  .totals div{ font-size:11.5px; padding:3px 0 }
  .totals .grand{ font-size:13px; margin-top:3px; padding-top:7px }

  .pay{ margin:6px 18px 10px; padding:10px 12px; border-radius:8px }
  .pay h4{ font-size:12px; margin-bottom:5px }
  .pay .grid{ font-size:11px; gap:3px 16px }
  .pay-seal img{ max-width:86px; max-height:68px }
  .pay-seal div{ font-size:9px }

  .foot{ padding:8px 18px 10px; font-size:10px }

  /*
  | 🔴 بلوکِ پایانی نباید وسطش بشکند.
  |
  | بدترین حالتِ ممکن این نیست که سند دو برگه شود؛ این است که «جمعِ کل» یا
  | مهرِ تأیید تنها روی برگهٔ دوم بیفتد و برگهٔ اول سندی ناقص به‌نظر برسد.
  | پس اگر روزی ردیف‌ها آن‌قدر زیاد شدند که سرریز شد، شکست از **وسطِ جدول**
  | می‌افتد نه از وسطِ رسید.
  */
  .totals, .pay, .foot{ break-inside:avoid; page-break-inside:avoid }
  tbody tr{ break-inside:avoid; page-break-inside:avoid }
  thead{ display:table-header-group }
}
</style>
</head>
<body>

<div class="bar">
  <button class="p" onclick="window.print()">{{ __('ui.invp_print_pdf') }}</button>
  <a class="b" href="{{ lroute('account.invoice', $invoice) }}">{{ __('ui.invp_back') }}</a>
</div>

<div class="sheet">
  <div class="head">
    <div class="brand">{{ __('ui.invp_brand') }}<small>servernet.cloud · {{ __('ui.invp_brand_tagline') }}</small></div>
    <div class="doc">
      <h1>{{ $invoice->kind === 'topup' ? __('ui.invp_doc_topup') : ($paid ? __('ui.invp_doc_sales') : __('ui.invp_doc_proforma')) }}</h1>
      <div class="num">{{ $invoice->number }}</div>
    </div>
  </div>

  <div class="meta">
    <div class="party">
      <h3>{{ __('ui.invp_seller') }}</h3>
      <b>{{ $legalName ?: __('ui.invp_brand') }}</b>
      {{-- 🔴 شناسه‌های ثبتی روی فاکتورِ رسمی لازم‌اند، ولی فقط آن‌هایی که
           **واقعاً** پر شده‌اند. `company_identity()` خالی‌ها را برنمی‌گرداند،
           پس این حلقه هیچ‌وقت «شماره ثبت: —» چاپ نمی‌کند.

           ⚠️ نامِ ثبتی بالا آمده و **کد اقتصادی** به‌خواستِ کارفرما اصلاً روی
           فاکتور نمی‌آید؛ هر دو این‌جا رد می‌شوند. حذفشان از خودِ
           `company_identity()` غلط بود: صفحهٔ تماس همچنان به کد اقتصادی نیاز
           دارد و آن تابع منبعِ مشترکِ هر دو است. --}}
      @foreach($sellerIdentity as $row)
        @continue(in_array($row['label'], ['ui.trust_legal_name', 'ui.trust_economic'], true))
        <div>{{ __($row['label']) }}: <b>{{ fa_num($row['value']) }}</b></div>
      @endforeach
      @if($sellerAddress)<div>{{ fa_num($sellerAddress) }}</div>@endif
      <div class="ltr">{{ $contact['phone'] ?? '' }}</div>
      {{-- 🔴 نشانیِ **حسابداری**، نه پشتیبانیِ فنی: کسی که دربارهٔ فاکتور
           می‌نویسد نباید در صفِ تیکتِ فنی بیفتد. --}}
      <div class="ltr">{{ $contact['billing_email'] ?? $contact['email'] ?? '' }}</div>
    </div>
    <div class="party">
      <h3>{{ __('ui.invp_buyer') }}</h3>
      <b>{{ $invoice->customer?->displayName() ?? '—' }}</b>
      <div class="ltr">{{ $invoice->customer?->code }}</div>
      @if($invoice->customer?->phone)<div class="ltr">{{ $invoice->customer->phone }}</div>@endif
    </div>
    <div class="party">
      <h3>{{ __('ui.invp_issue_date') }}</h3>
      <b>{{ sdate($invoice->issued_at ?? $invoice->created_at) }}</b>
      @if($paid)<div>{{ __('ui.invp_paid_date') }} {{ sdate($paid->paid_at) }}</div>@endif
    </div>
  </div>

  <table>
    <thead>
      <tr><th>{{ __('ui.invp_col_desc') }}</th><th class="num">{{ __('ui.invp_col_qty') }}</th><th class="num">{{ __('ui.invp_col_unit') }}</th><th class="num">{{ __('ui.invp_col_total') }}</th></tr>
    </thead>
    <tbody>
      @foreach($invoice->items as $item)
        <tr>
          <td>{{ $item->title }}@if($item->description)<div class="desc">{{ $item->description }}</div>@endif</td>
          <td class="num">{{ fa_num($item->quantity) }}</td>
          <td class="num">{{ invoice_money($item->unit_price, $invoice->currency_code) }}</td>
          <td class="num">{{ invoice_money($item->line_total, $invoice->currency_code) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <div class="totals">
    <div><span>{{ __('ui.invp_subtotal') }}</span><span class="num">{{ invoice_money($invoice->subtotal, $invoice->currency_code) }}</span></div>
    @if($invoice->tax > 0)
      <div><span>{{ __('ui.invp_vat') }}</span><span class="num">{{ invoice_money($invoice->tax, $invoice->currency_code) }}</span></div>
    @endif
    <div class="grand"><span>{{ __('ui.invp_grand_total') }}</span><span class="num">{{ invoice_money($invoice->total, $invoice->currency_code) }}</span></div>
    @if($invoice->paid > 0)
      <div><span>{{ __('ui.invp_paid') }}</span><span class="num">{{ invoice_money($invoice->paid, $invoice->currency_code) }}</span></div>
    @endif
    @if($invoice->due() > 0)
      <div><span>{{ __('ui.invp_due') }}</span><span class="num">{{ invoice_money($invoice->due(), $invoice->currency_code) }}</span></div>
    @endif
  </div>

  @if($paid)
    {{-- 🔴 مهرِ شرکت **داخلِ** همین جعبهٔ سبز است، نه زیرِ آن.
         مهر معنایش «این پرداخت را تأیید می‌کنیم» است، پس باید کنارِ همان
         چیزی بنشیند که تأییدش می‌کند. جدا افتادنش پایینِ صفحه، در چاپ گاهی
         به صفحهٔ بعد می‌رفت و از رسید جدا می‌شد. --}}
    <div class="pay ok pay-receipt">
      <div class="pay-body">
        <h4><span class="stamp">{{ __('ui.invp_stamp_paid') }}</span> {{ __('ui.invp_receipt_title') }}</h4>
        <div class="grid">
          <span>{{ __('ui.invp_pay_method') }} <b>{{ ['zarinpal'=>__('ui.invp_gw_zarinpal'),'bale'=>__('ui.invp_gw_bale'),'bank_transfer'=>__('ui.invp_gw_bank_transfer')][$paid->gateway] ?? $paid->gateway }}</b></span>
          <span>{{ __('ui.invp_amount') }} <b>{{ invoice_money($paid->amount, $invoice->currency_code) }}</b></span>
          @if($paid->ref_id)<span>{{ __('ui.invp_ref_no') }} <b class="ltr">{{ $paid->ref_id }}</b></span>@endif
          <span>{{ __('ui.invp_time') }} <b>{{ stime($paid->paid_at) }}</b></span>
        </div>
      </div>
      @if($stamp)
        <div class="pay-seal">
          <img src="{{ $stamp }}" alt="{{ __('ui.invp_company_seal_alt') }}">
          <div>{{ __('ui.invp_authorized_seal') }}</div>
        </div>
      @endif
    </div>
  @elseif($invoice->due() > 0)
    <div class="pay due">
      <h4>{{ __('ui.invp_awaiting') }}</h4>
      <div class="grid"><span>{{ __('ui.invp_unpaid_notice') }} <b>{{ invoice_money($invoice->due(), $invoice->currency_code) }}</b></span></div>
    </div>
  @endif

  <div class="foot">
    <span>{{ __('ui.invp_foot_note') }}</span>
    <span class="ltr">servernet.cloud</span>
  </div>
</div>

<script>
  // این صفحه خودش عملِ «دانلود PDF» است: پنجرهٔ چاپ باز می‌شود تا کاربر
  // «ذخیره به‌صورت PDF» را بزند. با ?noprint در URL می‌شود فقط دید.
  if (location.search.indexOf('noprint') === -1) {
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 500); });
  }
</script>
</body>
</html>
