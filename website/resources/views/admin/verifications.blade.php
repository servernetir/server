@extends('admin.layout')
@section('title', 'احراز هویت')
@section('nav_verifications', 'on')
@section('content')

@php
  $badge = function ($st) {
    return [
      'verified' => ['تأییدشده', '#34d399'],
      'pending'  => ['در انتظار بررسی', '#fbbf24'],
      'rejected' => ['رد شده', '#ff6b6b'],
    ][$st] ?? ['تکمیل‌نشده', 'var(--muted)'];
  };
  $typeLabel = fn ($t) => $t === 'company' ? 'حقوقی' : 'حقیقی';
@endphp

<div class="ad-panel">
  <div class="ad-panel-h"><h2>در انتظار بررسی</h2></div>
  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    درخواست‌های احراز هویتِ مشتریان — به‌ویژه کاربرانِ <b>حقوقی</b> که اطلاعاتِ شرکت،
    معرفی‌نامهٔ نماینده و اساسنامه فرستاده‌اند. مدارک را دانلود و بررسی کنید، سپس
    تأیید یا رد کنید. نتیجه به مشتری (پیامک/بله + ایمیل) اطلاع داده می‌شود.
  </p>

  @if($notReady)
    <p style="padding:18px;color:#fbbf24">جدولِ پروفایل‌ها هنوز روی این سرور ساخته نشده. پس از اجرای مهاجرت فعال می‌شود.</p>
  @elseif($pending->isEmpty())
    <p style="padding:16px;color:var(--dim)">درخواستِ در انتظارِ بررسی‌ای وجود ندارد. 🎉</p>
  @else
    @foreach($pending as $p)
      @php $b = $badge($p->status); @endphp
      <div class="kyc-card">
        <div class="kyc-head">
          <div>
            <b class="kyc-name">{{ $p->company_name ?: ($p->customer?->displayName() ?: '—') }}</b>
            <span class="ad-badge" style="background:rgba(148,163,184,.14);color:var(--muted);margin-inline-start:8px">{{ $typeLabel($p->type) }}</span>
            <span class="ad-badge" style="background:{{ $b[1] }}22;color:{{ $b[1] }};margin-inline-start:6px">{{ $b[0] }}</span>
          </div>
          <a href="/admin/customers/{{ $p->customer_id }}" class="kyc-code" dir="ltr">{{ $p->customer?->code }}</a>
        </div>

        <div class="kyc-grid">
          @if($p->type === 'company')
            <div><span>نام شرکت</span><b>{{ $p->company_name ?: '—' }}</b></div>
            <div><span>شمارهٔ ثبت</span><b dir="ltr">{{ $p->registration_number ?: '—' }}</b></div>
            <div><span>کد اقتصادی</span><b dir="ltr">{{ $p->economic_code ?: '—' }}</b></div>
            <div><span>نمایندهٔ شرکت</span><b>{{ trim(($p->rep_first_name ?? '').' '.($p->rep_last_name ?? '')) ?: '—' }}@if($p->rep_position) — {{ $p->rep_position }}@endif</b></div>
          @endif
          <div><span>ایمیل</span><b dir="ltr">{{ $p->email ?: $p->customer?->email }}</b></div>
          <div><span>موبایل</span><b dir="ltr">{{ $p->mobile ?: $p->customer?->phone }}</b></div>
        </div>

        {{-- مدارک --}}
        <div class="kyc-docs">
          @forelse($p->documents as $d)
            @php $kn = ['rep_letter' => 'معرفی‌نامهٔ نماینده', 'articles' => 'اساسنامه', 'national_id' => 'کارت ملی'][$d->kind] ?? $d->kind; @endphp
            <a class="kyc-doc" href="/admin/verifications/{{ $p->id }}/doc/{{ $d->id }}" target="_blank" rel="noopener">
              <svg class="icon"><use href="#i-file"/></svg>
              <span>{{ $kn }}</span>
              <small>{{ \Illuminate\Support\Str::limit($d->original_name, 26) }} · {{ $d->size_bytes ? round($d->size_bytes/1024).' کیلوبایت' : '' }}</small>
            </a>
          @empty
            <span style="color:var(--dim);font-size:12.5px">مدرکی آپلود نشده.</span>
          @endforelse
        </div>

        {{-- اقدام --}}
        <div class="kyc-act">
          <form method="post" action="/admin/verifications/{{ $p->id }}/approve" data-confirm="هویتِ «{{ $p->company_name ?: $p->customer?->displayName() }}» تأیید شود؟">
            @csrf<button class="btn btn-primary" style="padding:8px 18px;font-size:13px">✅ تأیید</button>
          </form>
          <form method="post" action="/admin/verifications/{{ $p->id }}/reject" class="kyc-reject">
            @csrf
            <input type="text" name="reason" placeholder="دلیلِ رد (به مشتری نشان داده می‌شود)" maxlength="400" required>
            <button class="btn" style="background:#ff6b6b;color:var(--bg);padding:8px 16px;font-size:13px">رد</button>
          </form>
        </div>
      </div>
    @endforeach
  @endif
</div>

@if(!$notReady && $recent->isNotEmpty())
<div class="ad-panel" style="margin-top:18px">
  <div class="ad-panel-h"><h2>بررسی‌شده‌های اخیر</h2></div>
  <table class="ad-table">
    <thead><tr><th>مشتری</th><th>نوع</th><th>وضعیت</th><th>تاریخ</th><th>توضیح</th></tr></thead>
    <tbody>
      @foreach($recent as $p)
        @php $b = $badge($p->status); @endphp
        <tr>
          <td><b>{{ $p->company_name ?: ($p->customer?->displayName() ?: '—') }}</b>
            <a href="/admin/customers/{{ $p->customer_id }}" style="color:var(--dim);font-size:11.5px;display:block" dir="ltr">{{ $p->customer?->code }}</a></td>
          <td>{{ $typeLabel($p->type) }}</td>
          <td><span class="ad-badge" style="background:{{ $b[1] }}22;color:{{ $b[1] }}">{{ $b[0] }}</span></td>
          <td style="color:var(--muted)">{{ sdate($p->verified_at ?: $p->updated_at) }}</td>
          <td style="color:var(--muted);font-size:12.5px">{{ $p->status === 'rejected' ? \Illuminate\Support\Str::limit($p->reject_reason, 60) : '—' }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endif

<style>
.kyc-card{ margin:0 14px 14px; border:1px solid rgba(148,163,184,.16); border-radius:14px; padding:15px 16px; background:rgba(148,163,184,.03); }
.kyc-head{ display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:12px; }
.kyc-name{ font-size:15px; color:var(--text); }
.kyc-code{ color:#22d3ee; font-size:12.5px; text-decoration:none; }
.kyc-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:10px 20px; margin-bottom:13px; }
.kyc-grid > div{ display:flex; flex-direction:column; gap:2px; }
.kyc-grid span{ font-size:11px; color:var(--dim); }
.kyc-grid b{ font-size:13px; color:#cdd7e5; word-break:break-word; }
.kyc-docs{ display:flex; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
.kyc-doc{ display:flex; flex-direction:column; gap:3px; text-decoration:none; border:1px solid rgba(34,211,238,.25); border-radius:11px; padding:9px 13px; background:rgba(34,211,238,.06); min-width:170px; transition:border-color .15s; }
.kyc-doc:hover{ border-color:#22d3ee; }
.kyc-doc .icon{ width:16px; height:16px; color:#22d3ee; }
.kyc-doc span{ font-size:12.5px; color:var(--text); font-weight:600; }
.kyc-doc small{ font-size:10.5px; color:#7c8aa0; }
.kyc-act{ display:flex; flex-wrap:wrap; gap:10px; align-items:flex-start; }
.kyc-act form{ display:flex; gap:8px; }
.kyc-reject{ flex:1; min-width:240px; }
.kyc-reject input{ flex:1; background:var(--bg); border:1px solid rgba(148,163,184,.2); border-radius:9px; padding:8px 11px; color:var(--text); font:inherit; font-size:12.5px; }
</style>
@endsection
