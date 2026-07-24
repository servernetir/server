@extends('admin.layout')
@section('title', 'واریز به حساب')
@section('nav_bank', 'on')
@section('content')

@if(session('ok'))<div class="ad-note ok">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="ad-note" style="border-color:#ff6b6b;color:#ff6b6b">{{ $errors->first() }}</div>@endif

<div class="ad-toolbar">
  <div class="ad-tabs">
    <a href="/admin/bank-transfers?status=pending"  class="{{ $filter === 'pending' ? 'on' : '' }}">در انتظار ({{ fa_num($counts['pending']) }})</a>
    <a href="/admin/bank-transfers?status=approved" class="{{ $filter === 'approved' ? 'on' : '' }}">تأییدشده ({{ fa_num($counts['approved']) }})</a>
    <a href="/admin/bank-transfers?status=rejected" class="{{ $filter === 'rejected' ? 'on' : '' }}">ردشده ({{ fa_num($counts['rejected']) }})</a>
  </div>
</div>

@if($notReady)
  <div class="ad-panel"><p style="padding:20px;color:#fbbf24">جدول واریزها روی این سرور هنوز ساخته نشده. پس از اجرای مهاجرت فعال می‌شود.</p></div>
@else
<div class="ad-panel">
  <div class="ad-panel-h"><h2>رسیدهای واریز</h2></div>
  @if($receipts->isEmpty())
    <p style="padding:20px;color:#96a3ba">رسیدی در این وضعیت نیست.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>مشتری</th><th>فاکتور</th><th>مبلغ</th><th>شناسهٔ پرداخت</th><th>مبدأ</th><th>زمان</th><th></th></tr></thead>
      <tbody>
        @foreach($receipts as $r)
        <tr>
          <td><a class="t" href="/admin/customers/{{ $r->customer_id }}">{{ $r->customer?->displayName() ?? '—' }}</a>
            <div style="font-size:12px;color:#5f6c82" dir="ltr">{{ $r->customer?->code }}</div></td>
          <td dir="ltr">{{ $r->invoice?->number ?? '—' }}</td>
          <td>{{ fa_num(number_format($r->amount)) }} ت</td>
          <td dir="ltr" style="color:#e7edf7">{{ $r->reference }}</td>
          <td dir="ltr" style="color:#96a3ba">{{ $r->paid_from ?: '—' }}</td>
          <td dir="ltr" style="color:#96a3ba">{{ stime($r->created_at) }}</td>
          <td class="ad-row-act" style="white-space:nowrap">
            @if($r->status === 'pending')
              <form method="post" action="/admin/bank-transfers/{{ $r->id }}/approve" style="display:inline"
                    onsubmit="return confirm('تأیید واریز؟ فاکتور تسویه و سرویس فعال می‌شود.')">@csrf
                <button type="submit" style="background:#22d3ee;color:#04121f;border:0;border-radius:7px;padding:6px 12px;cursor:pointer;font:inherit;font-size:12px">تأیید</button>
              </form>
              <button type="button" class="del" onclick="document.getElementById('rej-{{ $r->id }}').style.display='flex'">رد</button>
              <form id="rej-{{ $r->id }}" method="post" action="/admin/bank-transfers/{{ $r->id }}/reject" style="display:none;gap:6px;margin-top:6px">@csrf
                <input type="text" name="reject_reason" placeholder="علت رد (اختیاری)" style="background:#0f1522;border:1px solid #1e2637;border-radius:7px;color:#e7edf7;padding:5px 8px;font:inherit;font-size:12px">
                <button type="submit" class="del">ثبت رد</button>
              </form>
            @elseif($r->status === 'approved')
              <span class="ad-badge pub">تأییدشده</span>
            @else
              <span class="ad-badge" style="background:rgba(255,107,107,.15);color:#ff6b6b">ردشده</span>
              @if($r->reject_reason)<div style="font-size:11px;color:#5f6c82">{{ $r->reject_reason }}</div>@endif
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>
{{ $receipts->links() }}
@endif

@endsection
