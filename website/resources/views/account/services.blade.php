@extends('panel.layout')
@section('title', 'سرویس‌ها — سرورنت')

@section('panel')

<div class="pnl-head">
  <div>
    <h1>سرویس‌های من</h1>
    <p>سرویس‌ها و خدماتی که تهیه کرده‌اید.</p>
  </div>
</div>

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>سرویس‌ها</h2></div>

  @if($services->isEmpty())
    <div class="pnl-sec-b">
      <p style="font-size:13.5px;color:var(--muted);line-height:2;margin:0">
        هنوز سرویسی ندارید. وقتی سرویسی برایتان صادر شود، اینجا نمایش داده می‌شود.
      </p>
    </div>
  @else
    <div class="pnl-sec-b flush">
      <div class="pnl-tw">
        <table class="pnl-table">
          <thead>
            <tr><th>سرویس</th><th>دوره</th><th>وضعیت</th><th>سررسید بعدی</th><th class="num">مبلغ دوره</th><th></th></tr>
          </thead>
          <tbody>
            @foreach($services as $s)
              @php
                $badge = $s->statusBadge();
                $unpaid = $s->invoices->firstWhere('status', 'unpaid');
              @endphp
              <tr>
                <td>
                  <b>{{ $s->name }}</b>
                  @if($s->description)<div style="font-size:12px;color:var(--muted);margin-top:3px">{{ \Illuminate\Support\Str::limit($s->description, 70) }}</div>@endif
                </td>
                <td>{{ $s->cycleLabel() }}</td>
                <td><span class="pnl-pill" style="background:{{ $badge[1] }}22;color:{{ $badge[1] }}">{{ $badge[0] }}</span></td>
                <td>{{ sdate($s->next_due_at) }}</td>
                <td class="num pnl-num">{{ fa_num(number_format($s->total())) }}</td>
                <td>
                  @if($unpaid)
                    <a class="pnl-btn primary" href="{{ lroute('account.invoice', $unpaid) }}">پرداخت</a>
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

@endsection
