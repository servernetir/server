<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>فاکتور {{ $invoice->number }} — سرورنت</title>
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

.foot{ padding:16px 32px 26px; border-top:1px solid #eef1f6; font-size:11.5px; color:#8a93a6; display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px }
.foot .ltr{ direction:ltr }

/* A4 افقی (landscape) — به‌خواستِ کارفرما */
@page{ size:A4 landscape; margin:12mm }
@media print{
  body{ background:#fff }
  .bar{ display:none }
  .sheet{ box-shadow:none; margin:0; border-radius:0; max-width:100%; width:100% }
}
/* روی صفحه هم پهن‌تر تا حس A4 افقی بدهد */
@media screen and (min-width:900px){ .sheet, .bar{ max-width:1040px } }
</style>
</head>
<body>

<div class="bar">
  <button class="p" onclick="window.print()">چاپ / ذخیره به‌صورت PDF</button>
  <a class="b" href="{{ lroute('account.invoice', $invoice) }}">بازگشت</a>
</div>

<div class="sheet">
  <div class="head">
    <div class="brand">سرورنت<small>servernet.cloud · زیرساخت ابری</small></div>
    <div class="doc">
      <h1>{{ $invoice->kind === 'topup' ? 'رسید افزایش اعتبار' : ($paid ? 'فاکتور فروش' : 'پیش‌فاکتور') }}</h1>
      <div class="num">{{ $invoice->number }}</div>
    </div>
  </div>

  <div class="meta">
    <div class="party">
      <h3>فروشنده</h3>
      <b>{{ $legalName ?: 'سرورنت' }}</b>
      <div class="ltr">{{ $contact['phone'] ?? '' }}</div>
      <div class="ltr">{{ $contact['email'] ?? '' }}</div>
    </div>
    <div class="party">
      <h3>خریدار</h3>
      <b>{{ $invoice->customer?->displayName() ?? '—' }}</b>
      <div class="ltr">{{ $invoice->customer?->code }}</div>
      @if($invoice->customer?->phone)<div class="ltr">{{ $invoice->customer->phone }}</div>@endif
    </div>
    <div class="party">
      <h3>تاریخ صدور</h3>
      <b>{{ sdate($invoice->issued_at ?? $invoice->created_at) }}</b>
      @if($paid)<div>تاریخ پرداخت: {{ sdate($paid->paid_at) }}</div>@endif
    </div>
  </div>

  <table>
    <thead>
      <tr><th>شرح</th><th class="num">تعداد</th><th class="num">مبلغ واحد</th><th class="num">جمع (تومان)</th></tr>
    </thead>
    <tbody>
      @foreach($invoice->items as $item)
        <tr>
          <td>{{ $item->title }}@if($item->description)<div class="desc">{{ $item->description }}</div>@endif</td>
          <td class="num">{{ fa_num($item->quantity) }}</td>
          <td class="num">{{ fa_num(number_format($item->unit_price)) }}</td>
          <td class="num">{{ fa_num(number_format($item->line_total)) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <div class="totals">
    <div><span>جمع</span><span class="num">{{ fa_num(number_format($invoice->subtotal)) }}</span></div>
    @if($invoice->tax > 0)
      <div><span>مالیات بر ارزش افزوده</span><span class="num">{{ fa_num(number_format($invoice->tax)) }}</span></div>
    @endif
    <div class="grand"><span>مبلغ کل</span><span class="num">{{ fa_num(number_format($invoice->total)) }} تومان</span></div>
    @if($invoice->paid > 0)
      <div><span>پرداخت‌شده</span><span class="num">{{ fa_num(number_format($invoice->paid)) }}</span></div>
    @endif
    @if($invoice->due() > 0)
      <div><span>مانده</span><span class="num">{{ fa_num(number_format($invoice->due())) }}</span></div>
    @endif
  </div>

  @if($paid)
    <div class="pay ok">
      <h4><span class="stamp">پرداخت شد</span> رسید پرداخت</h4>
      <div class="grid">
        <span>روش پرداخت: <b>{{ ['zarinpal'=>'زرین‌پال','bale'=>'بله','bank_transfer'=>'واریز به حساب'][$paid->gateway] ?? $paid->gateway }}</b></span>
        <span>مبلغ: <b>{{ fa_num(number_format($paid->amount)) }} تومان</b></span>
        @if($paid->ref_id)<span>شمارهٔ پیگیری: <b class="ltr">{{ $paid->ref_id }}</b></span>@endif
        <span>زمان: <b>{{ stime($paid->paid_at) }}</b></span>
      </div>
    </div>
  @elseif($invoice->due() > 0)
    <div class="pay due">
      <h4>در انتظار پرداخت</h4>
      <div class="grid"><span>این فاکتور هنوز پرداخت نشده است. مبلغ قابل پرداخت: <b>{{ fa_num(number_format($invoice->due())) }} تومان</b></span></div>
    </div>
  @endif

  @if($stamp)
    <div style="padding:6px 32px 22px; text-align:left;">
      <div style="display:inline-block; text-align:center;">
        <img src="{{ $stamp }}" alt="مهر شرکت" style="max-width:150px; max-height:120px;">
        <div style="font-size:10.5px; color:#8a93a6; margin-top:2px;">مهر و امضای مجاز</div>
      </div>
    </div>
  @endif

  <div class="foot">
    <span>این فاکتور به‌صورت الکترونیکی صادر شده و بدون مهر و امضا معتبر است.</span>
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
