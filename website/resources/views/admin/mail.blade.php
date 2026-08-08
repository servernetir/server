@extends('admin.layout')
@section('title', 'صندوق‌های ایمیل')
@section('nav_mail', 'on')
@section('content')

@if($notReady)
  <div class="ad-panel">
    <div class="ad-panel-h"><h2>صندوق‌های ایمیل</h2></div>
    <p style="padding:18px;color:#fbbf24">
      جدولِ صندوق‌ها روی این سرور ساخته نشده. پس از اجرای <code dir="ltr">php artisan migrate</code> این‌جا فعال می‌شود.
    </p>
  </div>
@elseif(! $configured)
  <div class="ad-panel">
    <div class="ad-panel-h"><h2>صندوق‌های ایمیل</h2></div>
    <p style="padding:18px;color:#fbbf24;line-height:2">
      هیچ صندوقی پیکربندی نشده. رمزِ هر صندوق را در <code dir="ltr">.env</code> بگذار
      (<code dir="ltr">MAILBOX_CEO_PASS</code>، <code dir="ltr">MAILBOX_SUPPORT_PASS</code>،
      <code dir="ltr">MAILBOX_INFO_PASS</code>) و بعد <code dir="ltr">php artisan config:clear</code>.
      <br>
      رمزها عمداً در دیتابیس ذخیره نمی‌شوند — رمزِ ایمیل در دیتابیس یعنی رمزِ ایمیل در هر بکاپ.
    </p>
  </div>
@else

{{-- ══ صندوق‌ها ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>صندوق‌ها</h2></div>
  <table class="ad-table">
    <thead><tr><th>صندوق</th><th>باز</th><th>نیازمندِ جواب</th><th>آخرین نامه</th><th></th></tr></thead>
    <tbody>
      @foreach($boxes as $b)
      <tr>
        <td><b>{{ $b['label'] }}</b><small style="display:block;color:var(--dim)" dir="ltr">{{ $b['user'] }}</small></td>
        <td>{{ $b['open'] }}</td>
        <td>
          @if($b['reply'])
            <span class="ad-pill" style="background:rgba(239,68,68,.18);color:#ef4444">{{ $b['reply'] }}</span>
          @else
            <span style="color:var(--dim)">—</span>
          @endif
        </td>
        <td dir="ltr" style="text-align:left">{{ $b['last'] ? \Illuminate\Support\Carbon::parse($b['last'])->format('Y-m-d H:i') : '—' }}</td>
        <td class="ad-row-act"><a href="/admin/mail?box={{ $b['key'] }}">دیدن</a></td>
      </tr>
      @endforeach
    </tbody>
  </table>
  <p style="padding:0 18px 16px;color:var(--muted);font-size:13px;line-height:1.9">
    {{ $systemSeen }} نامهٔ سیستمیِ خودمان شناسایی و کنار گذاشته شده — این‌ها همان‌هایی هستند که
    یک‌بار در بله گفته شده‌اند و در گزارش تکرار نمی‌شوند.
    @if($pending) · {{ $pending }} نامه هنوز دسته‌بندی نشده. @endif
  </p>
</div>

{{-- ══ فیلتر ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>نامه‌ها</h2></div>
  <div style="display:flex;flex-wrap:wrap;gap:8px;padding:14px 18px">
    @php $base = '/admin/mail?'.($account !== '' ? 'box='.$account.'&' : ''); @endphp
    @foreach(['open' => 'باز', 'reply' => 'نیازمندِ جواب', 'all' => 'همه', 'system' => 'سیستمی'] as $key => $label)
      <a href="{{ $base }}show={{ $key }}" class="ad-pill"
         style="text-decoration:none;background:{{ $filter === $key ? 'rgba(34,211,238,.18)' : 'rgba(148,163,184,.14)' }};color:{{ $filter === $key ? '#22d3ee' : 'var(--muted)' }}">{{ $label }}</a>
    @endforeach
    @if($account !== '')
      <a href="/admin/mail?show={{ $filter }}" class="ad-pill" style="text-decoration:none;background:rgba(148,163,184,.14);color:var(--muted)">همهٔ صندوق‌ها</a>
    @endif
  </div>

  <table class="ad-table">
    <thead><tr><th>فرستنده</th><th>موضوع</th><th>دسته</th><th>زمان</th><th></th></tr></thead>
    <tbody>
      @forelse($messages as $m)
      <tr>
        <td>
          <b>{{ $m->from_name ?: $m->from_email }}</b>
          <small style="display:block;color:var(--dim)" dir="ltr">{{ $m->from_email }}</small>
          <small style="color:var(--dim)">‹{{ $m->accountLabel() }}›</small>
        </td>
        <td>
          {{ $m->subject ?: '(بدون موضوع)' }}
          @if(filled($m->summary))<small style="display:block;color:var(--muted)">↳ {{ $m->summary }}</small>@endif
          @if($m->needs_reply)<span class="ad-pill" style="background:rgba(239,68,68,.18);color:#ef4444">جواب می‌خواهد</span>@endif
          @if($m->is_system)<span class="ad-pill" style="background:rgba(148,163,184,.18);color:#94a3b8">سیستمی</span>@endif
        </td>
        <td>{{ $m->category ? $m->categoryLabel() : '—' }}</td>
        <td dir="ltr" style="text-align:left">{{ $m->received_at?->format('Y-m-d H:i') }}</td>
        <td class="ad-row-act">
          @if($m->handled_at)
            <form method="post" action="/admin/mail/{{ $m->id }}/reopen" style="display:inline">@csrf<button type="submit">باز کن</button></form>
          @else
            <form method="post" action="/admin/mail/{{ $m->id }}/handled" style="display:inline">@csrf<button type="submit">رسیدگی شد</button></form>
          @endif
        </td>
      </tr>
      @empty
      <tr><td colspan="5" style="color:var(--muted)">چیزی نیست.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

{{-- ══ بستنِ گروهی ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>بستنِ گروهی</h2></div>
  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    روزِ اول، صندوقی که سال‌ها پر شده صدها نامهٔ «باز» می‌سازد که هیچ‌کدام کاری ندارند.
    یک‌بار همه را ببند تا از فردا فقط چیزهای تازه بمانند. چیزی پاک نمی‌شود، فقط از فهرستِ باز بیرون می‌رود.
  </p>
  <form method="post" action="/admin/mail/clear" data-confirm="همهٔ این نامه‌ها «رسیدگی‌شده» علامت می‌خورند." style="padding:14px 18px;display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(190px,1fr))">
    @csrf
    <select name="box">
      <option value="">همهٔ صندوق‌ها</option>
      @foreach($boxes as $b)<option value="{{ $b['key'] }}">{{ $b['label'] }}</option>@endforeach
    </select>
    <input type="date" name="before" dir="ltr" value="{{ now()->toDateString() }}">
    <button class="btn" type="submit">ببند</button>
  </form>
</div>

@endif
@endsection
