@extends('admin.layout')
@section('title', 'پرونده — '.$lead->company)
@section('nav_crm', 'on')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h">
    <h2>{{ $lead->company }}</h2>
    <a href="/admin/crm" style="color:var(--muted)">بازگشت به قیف</a>
  </div>

  <table class="ad-table">
    <tbody>
      <tr><th style="width:180px">سایت</th><td dir="ltr" style="text-align:left"><a href="{{ $lead->website }}" target="_blank" rel="noopener nofollow">{{ $lead->website }}</a></td></tr>
      <tr><th>ایمیل</th><td dir="ltr" style="text-align:left">{{ $lead->email ?: '—' }} @if($blocked)<span class="ad-pill" style="background:rgba(239,68,68,.18);color:#ef4444">فهرستِ سیاه</span>@endif</td></tr>
      <tr><th>تلفن</th><td dir="ltr" style="text-align:left">{{ $lead->phone ?: '—' }}</td></tr>
      <tr><th>مکان</th><td>{{ trim($lead->city.' '.$lead->country) ?: '—' }} · {{ $lead->vertical ?: '—' }}</td></tr>
      <tr><th>منبع</th><td>{{ $lead->source ?: '—' }} <small style="color:var(--dim)">{{ $lead->notes }}</small></td></tr>
      <tr><th>امتیازِ سایت</th><td>{{ $lead->audit_score !== null ? $lead->audit_score.' از ۱۰۰' : 'هنوز بررسی نشده' }}</td></tr>
      <tr><th>مرحله</th><td><span class="ad-pill">{{ $lead->stageLabel() }}</span> · اقدامِ بعدی: {{ $lead->next_action_at?->format('Y-m-d') ?: '—' }}</td></tr>
    </tbody>
  </table>
</div>

{{-- ══ مشاهده: تنها دلیلِ مجازِ نوشتنِ ایمیل ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>مشاهده</h2></div>
  @if(filled($lead->observation))
    <p style="padding:14px 18px;line-height:2">{{ $lead->observation }}</p>
  @else
    <p style="padding:14px 18px;color:#fbbf24;line-height:2">
      مشاهده‌ای ثبت نشده. تا وقتی چیزِ مشخصی دربارهٔ سایتشان نداریم که بشود در ۶۰ ثانیه گفت،
      هیچ پیامی ساخته نمی‌شود. این عمدی است، نه خطا.
    </p>
  @endif

  <div style="padding:0 18px 16px;display:flex;gap:8px;flex-wrap:wrap">
    <form method="post" action="/admin/crm/{{ $lead->id }}/enrich">@csrf
      <button class="btn" type="submit">بررسیِ دوبارهٔ سایت</button>
    </form>
    <form method="post" action="/admin/crm/{{ $lead->id }}/compose">@csrf
      <button class="btn btn-primary" type="submit">ساختِ پیشنویسِ پیامِ بعدی</button>
    </form>
    <form method="post" action="/admin/crm/{{ $lead->id }}/suppress" data-confirm="این نشانی برای همیشه در فهرستِ سیاه می‌رود. برگشتی ندارد." data-confirm-danger>@csrf
      <button class="btn del" type="submit">فهرستِ سیاه</button>
    </form>
  </div>
</div>

{{-- ══ ممیزیِ سایت ══ --}}
@if(is_array($lead->audit) && ($lead->audit['ok'] ?? false))
<div class="ad-panel">
  <div class="ad-panel-h"><h2>ممیزیِ سایت</h2></div>
  <table class="ad-table">
    <thead><tr><th>دسته</th><th>امتیاز</th><th>مواردِ مشکل‌دار</th></tr></thead>
    <tbody>
      @foreach(($lead->audit['scores'] ?? []) as $cat => $score)
      <tr>
        <td>{{ $cat }}</td>
        <td>{{ $score }}</td>
        <td style="color:var(--muted);font-size:13px">
          @foreach(($lead->audit['checks'][$cat] ?? []) as $check)
            @if(($check['status'] ?? 'pass') !== 'pass')
              <div>· {{ $check['label'] ?? $check['title'] ?? '' }}</div>
            @endif
          @endforeach
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endif

{{-- ══ گفتگو ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>گفتگو</h2></div>
  @forelse($messages as $m)
    <div style="margin:12px 18px;padding:12px 14px;border:1px solid var(--line);border-radius:10px;
                background:{{ $m->direction === 'in' ? 'rgba(34,197,94,.06)' : 'var(--surface2)' }}">
      <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;color:var(--dim);font-size:12.5px">
        <span>{{ $m->direction === 'in' ? '↙ از مشتری' : '↗ از ما' }} · {{ $m->channel }} · {{ $m->status }}</span>
        <span dir="ltr">{{ $m->sent_at?->format('Y-m-d H:i') ?: $m->created_at->format('Y-m-d H:i') }}</span>
      </div>
      <div dir="ltr" style="text-align:left;margin-top:8px">
        <b>{{ $m->subject }}</b>
        <pre style="white-space:pre-wrap;font:inherit;color:var(--muted);margin:6px 0 0">{{ $m->body }}</pre>
      </div>
      @if($m->error)<div style="color:#ef4444;font-size:12.5px;margin-top:6px" dir="ltr">{{ $m->error }}</div>@endif

      @if($m->status === 'queued')
        <div style="display:flex;gap:8px;margin-top:10px">
          <form method="post" action="/admin/crm/message/{{ $m->id }}/approve" data-confirm="ارسالِ این ایمیل؟">@csrf
            <button class="btn btn-primary" type="submit">تأیید و ارسال</button>
          </form>
          <form method="post" action="/admin/crm/message/{{ $m->id }}/reject">@csrf
            <button class="btn" type="submit">رد</button>
          </form>
        </div>
      @endif
    </div>
  @empty
    <p style="padding:18px;color:var(--muted)">هنوز پیامی رد و بدل نشده.</p>
  @endforelse
</div>

{{-- ══ تغییرِ دستیِ مرحله ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>تغییرِ مرحله</h2></div>
  <form method="post" action="/admin/crm/{{ $lead->id }}/stage" style="padding:14px 18px;display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
    @csrf
    <select name="stage">
      @foreach(\App\Models\CrmLead::STAGES as $key => $label)
        <option value="{{ $key }}" @selected($lead->stage === $key)>{{ $label }}</option>
      @endforeach
    </select>
    <select name="offer">
      <option value="">— پکیج —</option>
      @foreach(array_keys((array) config('crm.offers')) as $offer)
        <option value="{{ $offer }}" @selected($lead->offer === $offer)>{{ $offer }}</option>
      @endforeach
    </select>
    <input name="value" type="number" min="0" dir="ltr" placeholder="ارزش (یورو)" value="{{ $lead->value_eur }}">
    <input name="reason" placeholder="دلیلِ ازدست‌رفتن (اختیاری)" value="{{ $lead->lost_reason }}">
    <button class="btn btn-primary" type="submit">ثبت</button>
  </form>
</div>

@endsection
