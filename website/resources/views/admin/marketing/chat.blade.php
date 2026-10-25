@extends('admin.layout')
@section('title', 'سرنخ‌های گفتگو')
@section('nav_marketing', 'on')
@section('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/marketing.css') }}">@endsection
@section('content')
<div class="mk">
  <div class="mk-head"><div><h2>سرنخ‌های گفتگو</h2><p>درخواست‌هایی که دستیار سایت ثبت کرده است؛ از بررسی اولیه تا نتیجهٔ پیگیری.</p></div><a class="mk-btn" href="/admin/marketing">بازاریابی هوشمند</a><a class="mk-btn" href="/admin/marketing?tab=email">درخواست‌های ایمیل تراکنشی</a></div>
  <div class="mk-tiles">
    @foreach(['new', 'reviewed', 'contacted', 'won'] as $key)
    <a class="mk-tile" style="color:inherit;text-decoration:none" href="/admin/marketing?tab=chat&amp;stage={{ $key }}"><div class="mk-tile-k">{{ $stages[$key] }}</div><div class="mk-tile-v">{{ number_format($counts[$key] ?? 0) }}</div></a>
    @endforeach
  </div>
  <form class="mk-form" method="get" action="/admin/marketing" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin:24px 0">
    <input type="hidden" name="tab" value="chat">
    <div style="flex:1;min-width:180px"><label for="chat-search">نام، شماره تماس، ایمیل، کد یا خلاصهٔ درخواست</label><input id="chat-search" name="q" value="{{ $q }}" maxlength="190"></div>
    <div><label for="chat-stage-filter">وضعیت</label><select id="chat-stage-filter" name="stage"><option value="">همه</option>@foreach($stages as $key => $label)<option value="{{ $key }}" @selected($stage === $key)>{{ $label }}</option>@endforeach</select></div>
    <button type="submit" class="btn btn-primary">جستجو</button><a class="mk-btn" href="/admin/marketing?tab=chat">پاک‌کردن فیلتر</a>
  </form>
  <p style="color:var(--muted)">{{ number_format($total) }} سرنخ مطابق فیلتر · ساعت‌ها به وقت تهران</p>
  @forelse($leads as $lead)
    @php
      $message = $lead->messages->first();
      $entry = $message ? (json_decode($message->body, true) ?: []) : [];
      $registeredAt = !empty($entry['occurred_at']) ? \Illuminate\Support\Carbon::parse($entry['occurred_at']) : $lead->created_at;
      $localDate = $registeredAt->copy()->timezone('Asia/Tehran');
    @endphp
    <article class="ad-panel" style="margin:16px 0;overflow-wrap:anywhere">
      <div class="ad-panel-h" style="display:flex;gap:12px;justify-content:space-between;flex-wrap:wrap"><h2>{{ $lead->contact_name ?: 'بازدیدکنندهٔ سایت' }}</h2><b class="mk-tag {{ $lead->stage === 'new' ? 'v' : 'c' }}">{{ $stages[$lead->stage] ?? $lead->stageLabel() }}</b></div>
      <div style="padding:18px;line-height:2"><p style="display:flex;gap:16px;flex-wrap:wrap"><span dir="ltr">{{ $lead->phone }}</span><span dir="ltr">{{ $lead->email }}</span><span dir="ltr">{{ $message?->subject }}</span></p>
      <p dir="auto">{{ \Illuminate\Support\Str::limit($entry['summary'] ?? '', 250) }}</p>
      <div style="display:flex;gap:15px;align-items:center;justify-content:space-between;flex-wrap:wrap"><small>{{ blog_date($localDate->toDateString()) }} · {{ $localDate->format('H:i') }} · {{ strtoupper($entry['locale'] ?? 'fa') }}</small><a class="mk-btn" href="{{ route('admin.marketing.lead', $lead) }}">جزئیات و ثبت پیگیری</a></div></div>
    </article>
  @empty<div class="mk-empty"><b>سرنخی مطابق فیلتر پیدا نشد</b><p>سرنخ‌های تازهٔ ثبت‌شده توسط دستیار سایت، اینجا نمایش داده می‌شوند.</p></div>@endforelse
  @if($leads->hasPages())<nav aria-label="صفحه‌بندی سرنخ‌های گفتگو" style="display:flex;gap:16px;align-items:center;margin-top:20px">@if($leads->previousPageUrl())<a class="mk-btn" href="{{ $leads->previousPageUrl() }}">قبلی</a>@endif<span>{{ $leads->currentPage() }} / {{ $leads->lastPage() }}</span>@if($leads->nextPageUrl())<a class="mk-btn" href="{{ $leads->nextPageUrl() }}">بعدی</a>@endif</nav>@endif
</div>
@endsection
