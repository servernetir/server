@extends('panel.layout')
@section('title', 'فاکتورها — سرورنت')

@section('panel')

<div class="pnl-head">
  <div>
    <h1>فاکتورها</h1>
    <p>موجودی اعتبار: <b class="pnl-num">{{ fa_num(number_format($balance)) }}</b> تومان</p>
  </div>
  <div class="pnl-acts">
    <a class="pnl-btn primary" href="{{ lroute('account.topup') }}">
      <svg class="icon"><use href="#i-plus"/></svg>افزایش اعتبار
    </a>
  </div>
</div>

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>همهٔ فاکتورها</h2></div>

  @if($invoices->isEmpty())
    <div class="pnl-sec-b">
      <p style="font-size:13.5px;color:var(--muted);line-height:2;margin:0 0 14px">
        هنوز فاکتوری ندارید. با افزایش اعتبار یا سفارش سرویس، اولین فاکتور شما اینجا می‌آید.
      </p>
      <a class="pnl-btn" href="{{ lroute('account.topup') }}">
        <svg class="icon"><use href="#i-coins"/></svg>افزایش اعتبار
      </a>
    </div>
  @else
    <div class="pnl-sec-b flush">
      <div class="pnl-tw">
        <table class="pnl-table">
          <thead>
            <tr><th>شماره</th><th>بابت</th><th>تاریخ</th><th>وضعیت</th><th class="num">مبلغ</th><th></th></tr>
          </thead>
          <tbody>
            @foreach($invoices as $inv)
              <tr>
                <td dir="ltr">{{ $inv->number }}</td>
                <td>{{ $inv->kind === 'topup' ? 'افزایش اعتبار' : 'سرویس' }}</td>
                <td>{{ sdate($inv->issued_at ?? $inv->created_at) }}</td>
                <td>
                  @if($inv->status === 'paid')
                    <span class="pnl-pill ok">پرداخت شد</span>
                  @elseif($inv->status === 'void' || $inv->status === 'canceled')
                    <span class="pnl-pill mute">{{ $inv->status === 'canceled' ? 'لغو شده' : 'باطل' }}</span>
                  @else
                    <span class="pnl-pill warn">در انتظار پرداخت</span>
                  @endif
                </td>
                <td class="num pnl-num">{{ fa_num(number_format($inv->total)) }}</td>
                <td style="white-space:nowrap">
                  <a class="pnl-btn" href="{{ lroute('account.invoice', $inv) }}">مشاهده</a>
                  @if($inv->status !== 'paid' && $inv->status !== 'canceled' && $inv->status !== 'void' && $inv->paid == 0)
                    <form method="POST" action="{{ lroute('account.invoice.cancel', $inv) }}" style="display:inline"
                          onsubmit="return confirm('این فاکتورِ در انتظار پرداخت لغو شود؟ اگر مربوط به سرویس باشد، آن سرویس هم غیرفعال می‌شود.')">
                      @csrf
                      <button type="submit" class="pnl-btn" style="color:var(--danger);border-color:var(--danger-line)">لغو</button>
                    </form>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @endif
</section>

{{ $invoices->links() }}

@endsection
