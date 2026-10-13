@extends('admin.layout')
@section('title', 'مدیریت DNS مشتریان')
@section('nav_dns', 'on')
@section('content')

@php
  $inp = 'background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 11px;font:inherit;font-size:13px;width:100%';
  $tabs = [
      ''          => ['همه', $counts['all']],
      'active'    => ['فعال', $counts['active']],
      'pending'   => ['در انتظار', $counts['pending']],
      'lost'      => ['تفویض از دست رفته', $counts['lost']],
      'dirty'     => ['همگام‌نشده', $counts['dirty']],
      'suspended' => ['معلق', $counts['suspended']],
  ];
@endphp

@if(session('ok'))<div class="ad-note ok">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="ad-note" style="border-color:var(--red);color:var(--red)">{{ $errors->first() }}</div>@endif

<div class="ad-panel">
  <div class="ad-panel-h" style="flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0">مدیریت DNS مشتریان</h2>
      <p style="margin:6px 0 0;color:var(--muted);font-size:12.5px;line-height:1.9;max-width:75ch">
        مشتری نیم‌سرورهای دامنه‌اش را روی سرورنت می‌گذارد و رکوردها را از پنلِ خودش مدیریت می‌کند.
        دیتابیسِ همین سایت منبعِ حقیقت است و PowerDNS آینه‌اش؛ <code dir="ltr">dns:reconcile</code> هر ۱۰ دقیقه تفاوت‌ها را می‌فرستد.
      </p>
    </div>
    <span class="ad-badge" style="margin-inline-start:auto;font-size:12px;padding:7px 12px;{{ $configured ? 'background:rgba(52,211,153,.12);color:var(--green)' : 'background:rgba(245,158,11,.14);color:var(--amber)' }}">
      {{ $configured ? 'اتصال پیکربندی شده' : 'PowerDNS پیکربندی نشده' }}
    </span>
  </div>

  @unless($ready)
    <p style="color:var(--amber);font-size:13.5px;line-height:2">
      جدول‌های <code dir="ltr">cdn_*</code> هنوز روی این نصب ساخته نشده‌اند. پس از اجرای مهاجرت‌ها این صفحه کامل می‌شود.
    </p>
  @endunless
</div>

<div class="ad-grid2">
  {{-- ═════════ اتصال ═════════ --}}
  <div class="ad-panel">
    <div class="ad-panel-h"><h3>اتصال به PowerDNS و نیم‌سرورها</h3></div>
    <form method="POST" action="/admin/dns/settings">
      @csrf
      <div class="ad-field">
        <label>آدرسِ API (پورتِ webserver در pdns.conf)</label>
        <input name="pdns_api_url" dir="ltr" style="{{ $inp }}" value="{{ old('pdns_api_url', $apiUrl) }}" placeholder="http://10.0.0.5:8081" required>
      </div>
      <div class="ad-field">
        <label>کلیدِ API {!! $hasKey ? '<span style="color:var(--green)">— ذخیره شده؛ خالی بگذارید تا عوض نشود</span>' : '<span style="color:var(--amber)">— هنوز وارد نشده</span>' !!}</label>
        <input name="pdns_api_key" type="password" dir="ltr" style="{{ $inp }}" autocomplete="new-password" placeholder="{{ $hasKey ? '••••••••••••' : 'api-key' }}">
      </div>
      <div class="ad-field">
        <label>نیم‌سرورها (هر خط یکی؛ دست‌کم دو تا، روی دو شبکهٔ جدا)</label>
        <textarea name="dns_nameservers" dir="ltr" rows="3" style="{{ $inp }};font-family:ui-monospace,monospace" required>{{ old('dns_nameservers', implode("\n", $nameservers)) }}</textarea>
        <p style="color:var(--dim);font-size:11.5px;line-height:1.8;margin:6px 0 0">
          ⚠️ این‌ها باید با <b>ns1/ns2.servernet.cloud</b> (BINDِ سرورِ هاست) فرق داشته باشند.
          عوض‌کردنشان بعد از داشتنِ مشتری یعنی همهٔ مشتری‌ها باید نیم‌سرورِ دامنه‌شان را دوباره عوض کنند.
        </p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="ad-btn" type="submit">ذخیره</button>
      </div>
    </form>
    <form method="POST" action="/admin/dns/test" style="margin-top:10px">
      @csrf
      <button class="ad-btn" type="submit">آزمایشِ اتصال</button>
      @if($health)
        <span style="font-size:12.5px;margin-inline-start:8px;color:{{ $health['ok'] ? 'var(--green)' : 'var(--red)' }}">
          {{ $health['ok'] ? 'وصل شد — PowerDNS '.$health['version'] : 'ناموفق: '.$health['reason'] }}
        </span>
      @endif
    </form>
  </div>

  {{-- ═════════ وضعیت ═════════ --}}
  <div class="ad-panel">
    <div class="ad-panel-h"><h3>وضعیت</h3></div>
    <div class="ad-stats" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px">
      <div class="ad-stat"><b>{{ fa_num($counts['active']) }}</b><span>zoneِ فعال</span></div>
      <div class="ad-stat"><b>{{ fa_num($counts['pending']) }}</b><span>در انتظارِ تفویض</span></div>
      <div class="ad-stat"><b style="{{ $counts['dirty'] ? 'color:var(--amber)' : '' }}">{{ fa_num($counts['dirty']) }}</b><span>همگام‌نشده</span></div>
    </div>
    <form method="POST" action="/admin/dns/reconcile">
      @csrf
      <button class="ad-btn" type="submit" @disabled(! $configured || ! $counts['dirty'])>همگام‌سازیِ همگام‌نشده‌ها</button>
    </form>

    @if($events->count())
      <div style="margin-top:16px;max-height:260px;overflow:auto">
        @foreach($events as $e)
          <div style="display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid var(--line);font-size:12.5px">
            <span>{{ $e->message }}@if($e->payload && isset($e->payload['reason']))<span style="color:var(--red)"> — {{ \Illuminate\Support\Str::limit($e->payload['reason'], 90) }}</span>@endif</span>
            <span style="color:var(--dim);white-space:nowrap">{{ $e->created_at ? sdate($e->created_at, true) : '' }}</span>
          </div>
        @endforeach
      </div>
    @endif
  </div>
</div>

@if($ready)
{{-- ═════════ zoneها ═════════ --}}
<div class="ad-panel">
  <div class="ad-panel-h" style="flex-wrap:wrap;gap:10px">
    <h3>zoneها</h3>
    <form method="GET" action="/admin/dns" style="display:flex;gap:8px;margin-inline-start:auto">
      @if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
      <input name="q" value="{{ $q }}" dir="ltr" placeholder="example.com" style="{{ $inp }};width:220px">
      <button class="ad-btn" type="submit">جست‌وجو</button>
    </form>
  </div>

  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">
    @foreach($tabs as $key => [$label, $n])
      <a href="/admin/dns?status={{ $key }}{{ $q !== '' ? '&q='.urlencode($q) : '' }}" class="ad-badge {{ $status === $key ? 'pub' : 'draft' }}" style="text-decoration:none;padding:6px 12px">
        {{ $label }} ({{ fa_num($n) }})</a>
    @endforeach
  </div>

  <div style="overflow-x:auto">
  <table class="ad-table">
    <thead><tr><th>دامنه</th><th>مشتری</th><th>وضعیت</th><th>مالکیت</th><th>رکورد</th><th>همگام</th><th>آخرین بررسی</th><th></th></tr></thead>
    <tbody>
      @forelse($zones as $z)
        <tr>
          <td dir="ltr" style="text-align:right;font-weight:600">{{ $z->domain }}</td>
          <td>
            @if($z->customer)
              <a class="t" href="/admin/customers/{{ $z->customer_id }}">{{ $z->customer->displayName() }}</a>
            @else — @endif
          </td>
          <td>
            @php
              [$c, $l] = match(true) {
                $z->status === 'suspended'                   => ['var(--red)', 'معلق'],
                $z->status === 'active' && $z->verify_error  => ['var(--amber)', 'تفویض رفته'],
                $z->status === 'active'                      => ['var(--green)', 'فعال'],
                default                                      => ['var(--amber)', 'در انتظار'],
              };
            @endphp
            <span style="color:{{ $c }};font-weight:600;font-size:12.5px">{{ $l }}</span>
            @if($z->verify_error)<div style="color:var(--dim);font-size:11px">{{ $z->verify_error }}</div>@endif
          </td>
          <td style="font-size:12px;color:var(--muted)">{{ ['registered' => 'ثبت نزدِ ما', 'txt' => 'TXT', 'delegated' => 'تفویض'][$z->ownership] ?? 'اثبات‌نشده' }}</td>
          <td>{{ fa_num($z->records_count) }}</td>
          <td style="font-size:12px">
            @if($z->dirty)
              <span style="color:var(--amber)" title="{{ $z->sync_error }}">در صف</span>
              @if($z->sync_error)<div style="color:var(--red);font-size:11px;max-width:220px">{{ \Illuminate\Support\Str::limit($z->sync_error, 80) }}</div>@endif
            @else
              <span style="color:var(--green)">{{ $z->synced_at ? sdate($z->synced_at, true) : '✓' }}</span>
            @endif
          </td>
          <td style="font-size:12px;color:var(--muted)">{{ $z->checked_at ? sdate($z->checked_at, true) : '—' }}</td>
          <td class="ad-row-act">
            <form method="POST" action="/admin/dns/zones/{{ $z->id }}/verify" style="display:inline">@csrf<button class="ad-btn" type="submit" style="padding:5px 10px;font-size:12px">بررسی</button></form>
            <form method="POST" action="/admin/dns/zones/{{ $z->id }}/reconcile" style="display:inline">@csrf<button class="ad-btn" type="submit" style="padding:5px 10px;font-size:12px">همگام</button></form>
            <form method="POST" action="/admin/dns/zones/{{ $z->id }}/status" style="display:inline"
                  onsubmit="return confirm('{{ $z->status === 'suspended' ? 'رفعِ تعلیق؟' : 'تعلیق؟ رکوردها فوراً از سرورِ DNS برداشته می‌شوند.' }}')">
              @csrf
              <input type="hidden" name="status" value="{{ $z->status === 'suspended' ? 'active' : 'suspended' }}">
              <button class="ad-btn" type="submit" style="padding:5px 10px;font-size:12px">{{ $z->status === 'suspended' ? 'رفعِ تعلیق' : 'تعلیق' }}</button>
            </form>
            <form method="POST" action="/admin/dns/zones/{{ $z->id }}/delete" style="display:inline"
                  onsubmit="var d=prompt('برای حذف، نامِ دامنه را بنویسید:');if(!d)return false;this.confirm.value=d;return true">
              @csrf
              <input type="hidden" name="confirm" value="">
              <button class="ad-btn" type="submit" style="padding:5px 10px;font-size:12px;color:var(--red)">حذف</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:30px">zoneی نیست.</td></tr>
      @endforelse
    </tbody>
  </table>
  </div>
</div>
@endif

@endsection
