@extends('admin.layout')
@section('title', 'تاریخچهٔ سرویس — '.$service->name)
@section('nav_customers', 'on')
@section('content')

@php
  $actorTx  = ['customer' => 'مشتری', 'staff' => 'مدیر', 'system' => 'سیستم'];
  $actorClr = ['customer' => '#22d3ee', 'staff' => '#a78bfa', 'system' => '#6b7c96'];
@endphp

<div class="ad-panel">
  <div class="ad-panel-h" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
    <h3>تاریخچهٔ «{{ $service->name }}»</h3>
    <a href="/admin/customers/{{ $service->customer_id }}"
       style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--cyan);border:1px solid var(--line2);border-radius:9px;padding:6px 12px">
      <svg class="icon" style="width:15px;height:15px"><use href="#i-user"/></svg> بازگشت به پروندهٔ مشتری
    </a>
  </div>

  <div style="padding:4px 16px 18px">
    <p style="color:var(--muted);font-size:12.5px;line-height:2;margin:0 0 14px">
      مشتری: <b>{{ $service->customer?->displayName() ?? '—' }}</b>
      <span dir="ltr" style="color:var(--dim)">{{ $service->customer?->code }}</span>
      · وضعیتِ فعلی: <b>{{ $service->status }}</b>
      · هر رویداد با «کنندهٔ کار»، زمان و IP ثبت شده تا بشود دنبال کرد چه‌کسی
      کِی خرید، تمدید، تعلیق یا حذف کرده.
    </p>

    @forelse($logs as $log)
      <div style="display:flex;gap:12px;padding:11px 0;border-top:1px solid var(--line)">
        <span style="flex:none;width:30px;height:30px;border-radius:8px;display:grid;place-items:center;background:var(--surface);color:var(--cyan)">
          <svg class="icon" style="width:16px;height:16px"><use href="#{{ $log->icon() }}"/></svg>
        </span>
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;color:var(--text);line-height:1.7">{{ $log->description }}</div>
          <div style="font-size:11.5px;color:var(--dim);margin-top:2px;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
            <span style="color:{{ $actorClr[$log->actor] ?? '#6b7c96' }};font-weight:700">{{ $actorTx[$log->actor] ?? $log->actor }}</span>
            <span>{{ stime($log->created_at) }}</span>
            @if($log->ip)<span dir="ltr">{{ $log->ip }}</span>@endif
            @if($log->geoLabel())<span>{{ $log->geoLabel() }}</span>@endif
          </div>
        </div>
      </div>
    @empty
      <p style="color:var(--dim);padding:14px 0">هنوز رویدادی برای این سرویس ثبت نشده. رویدادها از این پس ثبت می‌شوند.</p>
    @endforelse
  </div>
</div>

@endsection
