@extends('admin.layout')
@section('title', 'درخواست‌های ایمیل تراکنشی')
@section('nav_marketing', 'on')
@section('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/marketing.css') }}">@endsection
@section('content')
<div class="mk">
  <div class="mk-head"><div><h2>درخواست‌های ایمیل تراکنشی</h2><p>درخواست‌های ثبت‌شده در فرم سایت؛ هر ردیف یک درخواست مستقل است. این صفحه فقط گزارش می‌دهد و هیچ پیامی ارسال نمی‌کند.</p></div><a class="mk-btn" href="/admin/marketing">بازگشت به بازاریابی</a></div>
  <div class="mk-tiles"><div class="mk-tile"><div class="mk-tile-k">درخواست مطابق فیلتر</div><div class="mk-tile-v">{{ number_format($requestCount) }}</div></div><div class="mk-tile"><div class="mk-tile-k">متقاضی یکتا مطابق فیلتر</div><div class="mk-tile-v">{{ number_format($contactCount) }}</div></div></div>
  <form method="get" action="/admin/marketing" class="mk-form" style="margin:24px 0;display:flex;gap:12px;align-items:end;flex-wrap:wrap">
    <input type="hidden" name="tab" value="email">
    <div style="flex:1;min-width:200px"><label for="email-inbox-q">جستجوی شرکت، ایمیل، شماره تماس یا متن درخواست</label><input id="email-inbox-q" name="q" maxlength="190" value="{{ $q }}"></div>
    <div><label for="email-inbox-stage">وضعیت متقاضی در CRM</label><select id="email-inbox-stage" name="stage"><option value="">همهٔ وضعیت‌ها</option>@foreach(\App\Models\CrmLead::STAGES as $key => $label)<option value="{{ $key }}" @selected($stage === $key)>{{ $label }}</option>@endforeach</select></div>
    <button class="btn btn-primary" type="submit">اعمال فیلتر</button><a class="mk-btn" href="/admin/marketing?tab=email">پاک‌کردن فیلتر</a>
  </form>
  <p style="color:var(--muted);font-size:12px">تاریخ‌ها میلادی و ساعت‌ها به وقت تهران هستند. وضعیت، مربوط به پروندهٔ متقاضی است و تأیید پرداخت یا تحویل سرویس نیست.</p>
  @forelse($requests as $message)
  <article class="ad-panel" style="margin-bottom:16px">
    <div class="ad-panel-h" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap"><h2>{{ $message->lead->company }}</h2><span dir="ltr">TE-{{ $message->id }} · {{ $message->created_at->timezone('Asia/Tehran')->format('Y-m-d H:i') }}</span></div>
    <div style="padding:18px">
      <p style="display:flex;gap:15px;flex-wrap:wrap"><span dir="ltr">{{ $message->lead->email }}</span><span dir="ltr">{{ $message->lead->phone }}</span><b>{{ $message->lead->stageLabel() }}</b></p>
      <details><summary style="cursor:pointer">متن و مشخصات این درخواست</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere;font:inherit;font-size:13px;line-height:2;max-width:100%" dir="auto">{{ $message->body }}</pre></details>
      <a class="mk-btn" style="margin-top:15px" href="{{ route('admin.marketing.lead', $message->lead_id) }}">پرونده و پیگیری متقاضی</a>
    </div>
  </article>
  @empty<div class="mk-empty"><b>درخواستی مطابق فیلتر نیست</b><p>درخواست‌های جدید پس از ثبت فرم ایمیل تراکنشی اینجا دیده می‌شوند.</p></div>@endforelse
  @if($requests->hasPages())<nav aria-label="صفحه‌بندی درخواست‌های ایمیل" style="display:flex;gap:16px;align-items:center;margin:20px 0">@if($requests->previousPageUrl())<a class="mk-btn" href="{{ $requests->previousPageUrl() }}">صفحهٔ قبل</a>@endif<span>صفحهٔ {{ $requests->currentPage() }} از {{ $requests->lastPage() }}</span>@if($requests->nextPageUrl())<a class="mk-btn" href="{{ $requests->nextPageUrl() }}">صفحهٔ بعد</a>@endif</nav>@endif
</div>
@endsection
