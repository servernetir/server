@extends('admin.layout')
@section('title', 'پیگیری سرنخ گفتگو')
@section('nav_marketing', 'on')
@section('head')<link rel="stylesheet" href="{{ asset_ver('assets/css/marketing.css') }}">@endsection
@section('content')
<div class="mk" style="overflow-wrap:anywhere">
  <div class="mk-head"><div><h2>{{ $lead->contact_name ?: 'بازدیدکنندهٔ سایت' }}</h2><p>پروندهٔ سرنخ گفتگو · <span dir="ltr">CHAT-{{ $lead->id }}</span></p></div><a class="mk-btn" href="/admin/marketing?tab=chat">بازگشت به سرنخ‌های گفتگو</a></div>
  <div class="ad-panel"><div class="ad-panel-h"><h2>اطلاعات تماس</h2></div><div style="padding:18px;display:flex;gap:20px;flex-wrap:wrap;line-height:2"><span dir="ltr">{{ $lead->phone ?: '—' }}</span><span dir="ltr">{{ $lead->email ?: '—' }}</span><b>{{ $stages[$lead->stage] ?? $lead->stageLabel() }}</b></div></div>
  <div class="ad-panel" style="margin-top:18px"><div class="ad-panel-h"><h2>وضعیت و یادداشت پیگیری</h2></div>
    <form class="mk-form" method="post" action="/admin/marketing/{{ $lead->id }}/stage" style="padding:18px;display:flex;gap:14px;flex-wrap:wrap;align-items:end">
      @csrf<input type="hidden" name="version" value="{{ $messages->max('id') ?? 0 }}">
      <div><label for="lead-status">وضعیت</label><select id="lead-status" name="stage">@foreach($stages as $key => $label)<option value="{{ $key }}" @selected(old('stage', $lead->stage) === $key)>{{ $label }}</option>@endforeach</select></div>
      <div style="flex:1;min-width:180px"><label for="lead-reason">دلیل بسته‌شدن، در صورت نیاز</label><input id="lead-reason" name="reason" maxlength="120" value="{{ old('reason', $lead->lost_reason) }}"></div>
      <div style="width:100%"><label for="lead-notes">یادداشت داخلی پیگیری</label><textarea id="lead-notes" name="notes" rows="4" maxlength="5000">{{ old('notes', $lead->notes) }}</textarea></div>
      <button class="btn btn-primary" type="submit">ذخیرهٔ پیگیری</button>
    </form>
  </div>
  <div class="ad-panel" style="margin-top:18px"><div class="ad-panel-h"><h2>درخواست دستیار و تاریخچهٔ پیگیری</h2></div><div style="padding:18px">
    @foreach($messages as $message)
      @php
        $entry = json_decode($message->body, true) ?: [];
        $registeredAt = $message->channel === 'chat' && !empty($entry['occurred_at']) ? \Illuminate\Support\Carbon::parse($entry['occurred_at']) : $message->created_at;
        $localDate = $registeredAt->copy()->timezone('Asia/Tehran');
      @endphp
      <article class="mk-msg {{ $message->direction === 'in' ? 'in' : '' }}"><div class="mk-msg-h"><b>{{ $message->subject }}</b><span>{{ blog_date($localDate->toDateString()) }} · {{ $localDate->format('H:i') }} تهران</span></div>
        @if($message->channel === 'chat')<p>زبان گفتگو: <span dir="ltr">{{ strtoupper($entry['locale'] ?? 'fa') }}</span></p><pre dir="auto" style="white-space:pre-wrap;font:inherit;line-height:2;overflow-wrap:anywhere">{{ $entry['summary'] ?? $message->body }}</pre>
        @else<p>{{ $entry['admin_name'] ?? 'مدیر' }} · {{ $entry['from'] ?? '' }} ← {{ $entry['to'] ?? '' }}</p>@if(filled($entry['notes'] ?? null))<pre dir="auto" style="white-space:pre-wrap;font:inherit;line-height:2;overflow-wrap:anywhere">{{ $entry['notes'] }}</pre>@endif @if(filled($entry['reason'] ?? null))<p>دلیل: {{ $entry['reason'] }}</p>@endif
        @endif
      </article>
    @endforeach
  </div></div>
</div>
@endsection
