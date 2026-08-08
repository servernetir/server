@extends('admin.layout')
@section('title', 'جذبِ مشتری')
@section('nav_crm', 'on')
@section('content')

@if($notReady)
  <div class="ad-panel">
    <div class="ad-panel-h"><h2>جذبِ مشتری</h2></div>
    <p style="padding:18px;color:#fbbf24">
      جدول‌های CRM روی این سرور ساخته نشده‌اند. پس از اجرای <code dir="ltr">php artisan migrate</code> این‌جا فعال می‌شود.
    </p>
  </div>
@else

{{-- ══ نوارِ وضعیت: «چرا هیچ اتفاقی نمی‌افتد؟» باید با یک نگاه جواب بگیرد ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>وضعیتِ موتور</h2></div>
  <div style="display:flex;flex-wrap:wrap;gap:10px;padding:14px 18px">
    <span class="ad-pill" style="background:{{ $autopilot ? 'rgba(34,197,94,.18)' : 'rgba(251,191,36,.18)' }};color:{{ $autopilot ? '#22c55e' : '#fbbf24' }}">
      خلبانِ خودکار: {{ $autopilot ? 'روشن' : 'خاموش — ارسال با تأییدِ تو' }}
    </span>
    <span class="ad-pill" style="background:rgba(34,211,238,.18);color:#22d3ee">
      امروز: {{ $sentToday }} از {{ $dailyCap }}
    </span>
    <span class="ad-pill" style="background:{{ $inWindow ? 'rgba(34,197,94,.18)' : 'rgba(148,163,184,.18)' }};color:{{ $inWindow ? '#22c55e' : '#94a3b8' }}">
      پنجرهٔ ارسال: {{ $inWindow ? 'باز' : 'بسته' }}
    </span>
    <span class="ad-pill" style="background:rgba(148,163,184,.18);color:#94a3b8">فهرستِ سیاه: {{ $suppressed }}</span>
  </div>
  <div style="padding:0 18px 16px;color:var(--muted);font-size:13px;line-height:2">
    @foreach(['places' => 'کشفِ سرنخ (Google Places)', 'model' => 'مدلِ نویسنده', 'imap' => 'خواندنِ جواب‌ها (IMAP)', 'mailer' => 'صندوقِ ارسال'] as $k => $label)
      <span style="margin-inline-end:16px">{{ $health[$k] ? '✓' : '×' }} {{ $label }}</span>
    @endforeach
    @unless($health['imap'])
      <div style="color:#fbbf24;margin-top:6px">
        بدونِ IMAP، جوابِ مشتری تشخیص داده نمی‌شود و فالوآپ برای کسی می‌رود که همین دیروز جواب داده.
      </div>
    @endunless
  </div>
</div>

{{-- ══ قیف ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>قیف</h2></div>
  <div style="display:flex;flex-wrap:wrap;gap:8px;padding:14px 18px">
    <a href="/admin/crm" class="ad-pill" style="text-decoration:none;background:{{ $stage === '' ? 'rgba(34,211,238,.18)' : 'rgba(148,163,184,.14)' }};color:{{ $stage === '' ? '#22d3ee' : 'var(--muted)' }}">همه</a>
    @foreach(\App\Models\CrmLead::STAGES as $key => $label)
      <a href="/admin/crm?stage={{ $key }}" class="ad-pill"
         style="text-decoration:none;background:{{ $stage === $key ? 'rgba(34,211,238,.18)' : 'rgba(148,163,184,.14)' }};color:{{ $stage === $key ? '#22d3ee' : 'var(--muted)' }}">
        {{ $label }} · {{ $counts[$key] ?? 0 }}
      </a>
    @endforeach
  </div>
</div>

{{-- ══ صفِ تأیید ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>منتظرِ تأییدِ تو ({{ $pending->count() }})</h2></div>
  @if($pending->isEmpty())
    <p style="padding:18px;color:var(--muted)">چیزی در صف نیست.</p>
  @else
    <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
      هر پیام را بخوان. اگر جمله‌ای هست که خودت جلوی مشتری نمی‌گفتی، ردش کن — رد کردن رایگان است،
      ایمیلِ بد دامنهٔ فرستنده را می‌سوزاند و آن گران است.
    </p>
    @foreach($pending as $m)
      <div style="margin:14px 18px;padding:14px;border:1px solid var(--line);border-radius:10px;background:var(--surface2)">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center">
          <div>
            <b>{{ $m->lead?->company ?? '—' }}</b>
            <small style="color:var(--dim)" dir="ltr">{{ $m->lead?->email }}</small>
            <span class="ad-pill">پیام {{ $m->sequence + 1 }} از {{ \App\Models\CrmMessage::MAX_SEQUENCE + 1 }}</span>
          </div>
          <div style="display:flex;gap:8px">
            <form method="post" action="/admin/crm/message/{{ $m->id }}/approve" data-confirm="ارسالِ این ایمیل؟">@csrf
              <button class="btn btn-primary" type="submit">تأیید و ارسال</button>
            </form>
            <form method="post" action="/admin/crm/message/{{ $m->id }}/reject">@csrf
              <button class="btn" type="submit">رد</button>
            </form>
          </div>
        </div>
        <div dir="ltr" style="margin-top:10px;text-align:left">
          <b style="font-size:14px">{{ $m->subject }}</b>
          <pre style="white-space:pre-wrap;font:inherit;color:var(--muted);margin:8px 0 0">{{ $m->body }}</pre>
        </div>
      </div>
    @endforeach
  @endif
</div>

{{-- ══ سرنخ‌ها ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>سرنخ‌ها</h2></div>
  <table class="ad-table">
    <thead><tr><th>شرکت</th><th>نشانی</th><th>امتیازِ سایت</th><th>مرحله</th><th>اقدامِ بعدی</th><th></th></tr></thead>
    <tbody>
      @forelse($leads as $l)
      <tr>
        <td>
          <b>{{ $l->company }}</b>
          <small style="display:block;color:var(--dim)">{{ $l->city }} · {{ $l->vertical }}</small>
        </td>
        <td dir="ltr" style="text-align:left">
          <a href="{{ $l->website }}" target="_blank" rel="noopener nofollow">{{ parse_url($l->website, PHP_URL_HOST) }}</a>
          <small style="display:block;color:var(--dim)">{{ $l->email ?: '—' }}</small>
        </td>
        <td>{{ $l->audit_score !== null ? $l->audit_score : '—' }}</td>
        <td><span class="ad-pill">{{ $l->stageLabel() }}</span></td>
        <td>{{ $l->next_action_at?->format('Y-m-d') ?: '—' }}</td>
        <td class="ad-row-act"><a href="/admin/crm/{{ $l->id }}">پرونده</a></td>
      </tr>
      @empty
      <tr><td colspan="6" style="color:var(--muted)">سرنخی نیست.</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

{{-- ══ افزودنِ دستی ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>افزودنِ دستیِ سرنخ</h2></div>
  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    تا وقتی <code dir="ltr">GOOGLE_PLACES_KEY</code> نگذاشته‌ای، قیف از همین‌جا پر می‌شود.
    فقط دامنه لازم است؛ نشانیِ ایمیل را خودِ سیستم از سایتشان برمی‌دارد.
  </p>
  <form method="post" action="/admin/crm" style="padding:14px 18px;display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(190px,1fr))">
    @csrf
    <input name="company"  placeholder="نامِ کسب‌وکار" required>
    <input name="website"  placeholder="https://example.com" dir="ltr" required>
    <input name="email"    placeholder="ایمیل (اختیاری)" dir="ltr">
    <input name="city"     placeholder="شهر">
    <input name="country"  placeholder="AE" dir="ltr" maxlength="2">
    <input name="vertical" placeholder="dental / aesthetic">
    <button class="btn btn-primary" type="submit">افزودن</button>
  </form>
</div>

@endif
@endsection
