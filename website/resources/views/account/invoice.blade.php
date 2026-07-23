@extends('panel.layout')
@section('title', 'فاکتور '.$invoice->number.' — سرورنت')

@section('panel')

<div class="pnl-head">
  <div>
    <h1>فاکتور <span dir="ltr">{{ $invoice->number }}</span></h1>
    <p>{{ fa_num(optional($invoice->issued_at ?? $invoice->created_at)->format('Y/m/d')) }}</p>
  </div>
  <div class="pnl-acts">
    <a class="pnl-btn" href="{{ lroute('account.invoices') }}">
      <svg class="icon"><use href="#i-arrow"/></svg>بازگشت
    </a>
  </div>
</div>

@if($errors->any())
  <div class="pnl-sec" style="border-color:var(--danger-line)">
    <div class="pnl-sec-b" style="color:var(--danger);font-size:13.5px;line-height:2">
      @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
  </div>
@endif

<section class="pnl-sec">
  <div class="pnl-sec-h">
    <h2>ردیف‌ها</h2>
    @if($invoice->status === 'paid')
      <span class="pnl-pill ok">پرداخت شد</span>
    @else
      <span class="pnl-pill warn">در انتظار پرداخت</span>
    @endif
  </div>
  <div class="pnl-sec-b flush">
    <div class="pnl-tw">
      <table class="pnl-table">
        <thead>
          <tr><th>شرح</th><th class="num">تعداد</th><th class="num">مبلغ واحد</th><th class="num">جمع</th></tr>
        </thead>
        <tbody>
          @foreach($invoice->items as $item)
            <tr>
              <td>{{ $item->title }}</td>
              <td class="num pnl-num">{{ fa_num($item->quantity) }}</td>
              <td class="num pnl-num">{{ fa_num(number_format($item->unit_price)) }}</td>
              <td class="num pnl-num">{{ fa_num(number_format($item->line_total)) }}</td>
            </tr>
          @endforeach
          <tr><td colspan="3" style="color:var(--muted)">جمع</td>
              <td class="num pnl-num">{{ fa_num(number_format($invoice->subtotal)) }}</td></tr>
          @if($invoice->tax > 0)
            <tr><td colspan="3" style="color:var(--muted)">مالیات بر ارزش افزوده</td>
                <td class="num pnl-num">{{ fa_num(number_format($invoice->tax)) }}</td></tr>
          @endif
          <tr><td colspan="3"><b>قابل پرداخت</b></td>
              <td class="num pnl-num"><b>{{ fa_num(number_format($invoice->total)) }}</b> تومان</td></tr>
          @if($invoice->paid > 0 && $invoice->due() > 0)
            <tr><td colspan="3" style="color:var(--muted)">پرداخت‌شده تا کنون</td>
                <td class="num pnl-num">{{ fa_num(number_format($invoice->paid)) }}</td></tr>
            <tr><td colspan="3"><b>مانده</b></td>
                <td class="num pnl-num"><b>{{ fa_num(number_format($invoice->due())) }}</b></td></tr>
          @endif
        </tbody>
      </table>
    </div>
  </div>
</section>

@if($invoice->isPayable())
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>پرداخت</h2></div>
  <div class="pnl-sec-b">
    @if(count($gateways) === 0)
      <p style="font-size:13.5px;color:var(--warn);line-height:2;margin:0">
        هیچ درگاه پرداختی هنوز فعال نیست.
      </p>
    @else
      <form method="POST" action="{{ lroute('account.invoice.pay', $invoice) }}"
            style="display:flex;flex-direction:column;gap:14px;max-width:420px">
        @csrf
        @foreach($gateways as $key => $g)
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;
                        border:1px solid var(--line);border-radius:12px;padding:13px 15px;
                        background:var(--surface-2)">
            <input type="radio" name="gateway" value="{{ $key }}" {{ $loop->first ? 'checked' : '' }}
                   style="accent-color:#22D3EE">
            <b style="font-size:13px">{{ $key === 'zarinpal' ? 'زرین‌پال — کارت بانکی' : $key }}</b>
          </label>
        @endforeach
        <button type="submit" class="pnl-btn primary" style="justify-content:center">
          پرداخت {{ fa_num(number_format($invoice->due())) }} تومان
        </button>
      </form>
    @endif
  </div>
</section>
@endif

@if($invoice->payments->isNotEmpty())
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>تلاش‌های پرداخت</h2></div>
  <div class="pnl-sec-b flush">
    <div class="pnl-tw">
      <table class="pnl-table">
        <thead><tr><th>تاریخ</th><th>درگاه</th><th>وضعیت</th><th>پیگیری</th><th class="num">مبلغ</th></tr></thead>
        <tbody>
          @foreach($invoice->payments->sortByDesc('id') as $p)
            <tr>
              <td>{{ fa_num($p->created_at->format('Y/m/d H:i')) }}</td>
              <td>{{ $p->gateway }}</td>
              <td>
                @if($p->status === 'paid')<span class="pnl-pill ok">موفق</span>
                @elseif($p->status === 'canceled')<span class="pnl-pill mute">لغو شد</span>
                @elseif($p->status === 'failed')<span class="pnl-pill danger">ناموفق</span>
                @else<span class="pnl-pill warn">ناتمام</span>@endif
              </td>
              <td dir="ltr" style="font-size:12px">{{ $p->ref_id ?: '—' }}</td>
              <td class="num pnl-num">{{ fa_num(number_format($p->amount)) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</section>
@endif

@endsection
