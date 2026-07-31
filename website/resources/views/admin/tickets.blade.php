@extends('admin.layout')
@section('title', 'تیکت‌ها')
@section('nav_tickets', 'on')
@section('content')

@php
  // لینکِ تب‌ها فیلترهای جستجو/اولویت/بخش را نگه می‌دارد تا با عوض‌کردنِ وضعیت
  // جستجو دور نریزد.
  $tab = fn ($st) => '/admin/tickets?'.http_build_query(array_filter(
      ['status' => $st, 'q' => $q, 'priority' => $priority, 'department' => $dept],
      fn ($v) => $v !== ''
  ));
  $inp = 'background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:7px 10px;font:inherit;font-size:12.5px';
@endphp

<div class="ad-toolbar" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
  <div class="ad-tabs">
    <a href="{{ $tab('open') }}"     class="{{ $filter === 'open' ? 'on' : '' }}">در انتظار پاسخ ({{ $counts['open'] }})</a>
    <a href="{{ $tab('answered') }}" class="{{ $filter === 'answered' ? 'on' : '' }}">پاسخ داده‌شده ({{ $counts['answered'] }})</a>
    <a href="{{ $tab('closed') }}"   class="{{ $filter === 'closed' ? 'on' : '' }}">بسته ({{ $counts['closed'] }})</a>
    <a href="{{ $tab('all') }}"      class="{{ $filter === 'all' ? 'on' : '' }}">همه</a>
  </div>

  <form method="get" action="/admin/tickets" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-inline-start:auto">
    <input type="hidden" name="status" value="{{ $filter }}">
    <input type="search" name="q" value="{{ $q }}" placeholder="شماره، موضوع، کد/ایمیل/موبایل مشتری" style="{{ $inp }};min-width:230px">
    <select name="priority" style="{{ $inp }}">
      <option value="">همهٔ اولویت‌ها</option>
      <option value="urgent" @selected($priority === 'urgent')>فوری</option>
      <option value="high"   @selected($priority === 'high')>زیاد</option>
      <option value="normal" @selected($priority === 'normal')>عادی</option>
      <option value="low"    @selected($priority === 'low')>کم</option>
    </select>
    <select name="department" style="{{ $inp }}">
      <option value="">همهٔ بخش‌ها</option>
      <option value="technical" @selected($dept === 'technical')>فنی</option>
      <option value="billing"   @selected($dept === 'billing')>مالی</option>
      <option value="sales"     @selected($dept === 'sales')>فروش</option>
    </select>
    <button type="submit" style="{{ $inp }};cursor:pointer;color:var(--cyan);border-color:var(--cyan)">جستجو</button>
    @if($q !== '' || $priority !== '' || $dept !== '')
      <a href="/admin/tickets?status={{ $filter }}" style="font-size:12px;color:var(--dim)">پاک کردن</a>
    @endif
  </form>
</div>


<div class="ad-panel">
  <div class="ad-panel-h"><h2>تیکت‌ها</h2></div>
  @if($tickets->isEmpty())
    <p style="padding:20px;color:var(--muted)">تیکتی در این وضعیت نیست.</p>
  @else
    <table class="ad-table">
      <thead>
        <tr><th>شماره</th><th>موضوع</th><th>مشتری</th><th>بخش</th><th>اولویت</th><th>وضعیت</th><th>آخرین پاسخ</th></tr>
      </thead>
      <tbody>
        @foreach($tickets as $t)
          <tr onclick="location='/admin/tickets/{{ $t->id }}'" style="cursor:pointer">
            <td dir="ltr">{{ $t->number }}</td>
            <td>{{ $t->subject }}</td>
            <td>{{ $t->customer?->displayName() }} <small style="color:var(--dim)" dir="ltr">{{ $t->customer?->code }}</small></td>
            <td>{{ ['technical'=>'فنی','billing'=>'مالی','sales'=>'فروش'][$t->department] ?? $t->department }}</td>
            <td>
              @php $pr = ['low'=>['کم','var(--dim)'],'normal'=>['عادی','var(--muted)'],'high'=>['زیاد','#fbbf24'],'urgent'=>['فوری','#ff6b6b']][$t->priority] ?? ['—','var(--dim)']; @endphp
              <span style="color:{{ $pr[1] }}">{{ $pr[0] }}</span>
            </td>
            <td>
              @if($t->status === 'open')<span class="ad-badge" style="background:rgba(251,191,36,.15);color:#fbbf24">در انتظار</span>
              @elseif($t->status === 'answered')<span class="ad-badge" style="background:rgba(52,211,153,.15);color:#34d399">پاسخ‌داده</span>
              @else<span class="ad-badge" style="background:rgba(95,108,130,.15);color:var(--muted)">بسته</span>@endif
            </td>
            <td dir="ltr">{{ stime($t->last_reply_at) }} <small style="color:var(--dim)">{{ $t->last_reply_role === 'staff' ? '(ما)' : '(مشتری)' }}</small></td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

{{ $tickets->links() }}

@endsection
