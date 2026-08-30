@extends('admin.layout')
@section('title', 'واریز به حساب')
@section('nav_bank', 'on')
@section('content')

@if($errors->any())<div class="ad-note" style="border-color:#ff6b6b;color:#ff6b6b">{{ $errors->first() }}</div>@endif

@php
  $btTab = fn ($st) => '/admin/bank-transfers?'.http_build_query(array_filter(['status' => $st, 'q' => $q ?? ''], fn ($v) => $v !== ''));
  $btInp = 'background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:7px 10px;font:inherit;font-size:12.5px';
@endphp
<div class="ad-toolbar" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
  <div class="ad-tabs">
    <a href="{{ $btTab('pending') }}"  class="{{ $filter === 'pending' ? 'on' : '' }}">در انتظار ({{ fa_num($counts['pending']) }})</a>
    <a href="{{ $btTab('approved') }}" class="{{ $filter === 'approved' ? 'on' : '' }}">تأییدشده ({{ fa_num($counts['approved']) }})</a>
    <a href="{{ $btTab('rejected') }}" class="{{ $filter === 'rejected' ? 'on' : '' }}">ردشده ({{ fa_num($counts['rejected']) }})</a>
  </div>
  <form method="get" action="/admin/bank-transfers" style="display:flex;gap:8px;align-items:center;margin-inline-start:auto;flex-wrap:wrap">
    <input type="hidden" name="status" value="{{ $filter }}">
    <input type="search" name="q" value="{{ $q ?? '' }}" placeholder="شناسهٔ پیگیری، کد/ایمیل/موبایل مشتری" style="{{ $btInp }};min-width:230px">
    <button type="submit" style="{{ $btInp }};cursor:pointer;color:var(--cyan);border-color:var(--cyan)">جستجو</button>
    @if(($q ?? '') !== '')<a href="{{ $btTab($filter) }}" style="font-size:12px;color:var(--dim)">پاک</a>@endif
  </form>
</div>

@if($notReady)
  <div class="ad-panel"><p style="padding:20px;color:#fbbf24">جدول واریزها روی این سرور هنوز ساخته نشده. پس از اجرای مهاجرت فعال می‌شود.</p></div>
@else
<div class="ad-panel">
  <div class="ad-panel-h"><h2>رسیدهای واریز</h2></div>
  @if($receipts->isEmpty())
    <p style="padding:20px;color:var(--muted)">رسیدی در این وضعیت نیست.</p>
  @else
    <table class="ad-table">
      {{-- ⚠️ ستونِ «مقصد» حیاتی است: با چند حسابِ ارزی و چند کیفِ رمزارز،
           رسیدی که مقصدش را نگوید یعنی مدیر باید صورت‌حسابِ همهٔ حساب‌ها را
           بگردد — و اگر پیدا نکرد نمی‌داند مشتری دروغ گفته یا جای اشتباه را
           نگاه می‌کند. «مبلغ فرستاده» هم جداست چون می‌تواند ارزِ دیگری باشد. --}}
      <thead><tr><th>مشتری</th><th>فاکتور</th><th>مبلغ</th><th>مقصد</th><th>مبلغ فرستاده</th><th>شناسهٔ پرداخت</th><th>مبدأ</th><th>زمان</th><th></th></tr></thead>
      <tbody>
        @foreach($receipts as $r)
        <tr>
          <td><a class="t" href="/admin/customers/{{ $r->customer_id }}">{{ $r->customer?->displayName() ?? '—' }}</a>
            <div style="font-size:12px;color:var(--dim)" dir="ltr">{{ $r->customer?->code }}</div></td>
          <td dir="ltr">{{ $r->invoice?->number ?? '—' }}</td>
          <td>{{ fa_num(number_format($r->amount)) }} ت</td>
          <td dir="ltr" style="color:var(--muted);font-size:12px">
            @if($r->payment_account_id && $r->account)
              {{ $r->account->displayLabel() }}
              @if($r->account->isCrypto())<br><small>{{ $r->account->network }}</small>@endif
            @else
              حساب ریالی
            @endif
          </td>
          <td dir="ltr" style="color:var(--muted)">
            {{ $r->sent_amount ? number_format($r->sent_amount / 100, 2).' '.strtoupper((string) $r->sent_currency) : '—' }}
          </td>
          <td dir="ltr" style="color:var(--text)">{{ $r->reference }}</td>
          <td dir="ltr" style="color:var(--muted)">{{ $r->paid_from ?: '—' }}</td>
          <td dir="ltr" style="color:var(--muted)">{{ stime($r->created_at) }}</td>
          <td class="ad-row-act" style="white-space:nowrap">
            @if($r->status === 'pending')
              <form method="post" action="/admin/bank-transfers/{{ $r->id }}/approve" style="display:inline"
                    data-confirm="تأیید واریز؟ فاکتور تسویه و سرویس فعال می‌شود." data-confirm-title="تأیید واریز">@csrf
                <button type="submit" style="background:#22d3ee;color:#04121f;border:0;border-radius:7px;padding:6px 12px;cursor:pointer;font:inherit;font-size:12px">تأیید</button>
              </form>
              <button type="button" class="del" onclick="document.getElementById('rej-{{ $r->id }}').style.display='flex'">رد</button>
              <form id="rej-{{ $r->id }}" method="post" action="/admin/bank-transfers/{{ $r->id }}/reject" style="display:none;gap:6px;margin-top:6px">@csrf
                <input type="text" name="reject_reason" placeholder="علت رد (اختیاری)" style="background:var(--surface2);border:1px solid var(--line);border-radius:7px;color:var(--text);padding:5px 8px;font:inherit;font-size:12px">
                <button type="submit" class="del">ثبت رد</button>
              </form>
            @elseif($r->status === 'approved')
              <span class="ad-badge pub">تأییدشده</span>
            @else
              <span class="ad-badge" style="background:rgba(255,107,107,.15);color:#ff6b6b">ردشده</span>
              @if($r->reject_reason)<div style="font-size:11px;color:var(--dim)">{{ $r->reject_reason }}</div>@endif
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
