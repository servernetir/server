@extends('admin.layout')
@section('title', 'مدیریت DNS مشتریان')
@section('nav_dns', 'on')
@section('content')

@php
  $inp = 'background:var(--surface2);border:1px solid var(--line);border-radius:8px;color:var(--text);padding:8px 11px;font:inherit;font-size:13px;width:100%';
  $tabs = ['all' => 'همه', 'failed' => 'خطای انتشار', 'lagging' => 'نودِ ناهمگام', 'pending' => 'در انتظار', 'active' => 'فعال',
           'lost' => 'تفویض رفته', 'dnssec' => 'DS در والد', 'deleting' => 'در حالِ حذف', 'suspended' => 'معلق'];
  $nsRows = old('ns', array_map(fn ($n) => $n, $nameservers));
  while (count($nsRows) < 2) { $nsRows[] = ['host' => '', 'ipv4' => '', 'ipv6' => '', 'port' => 53]; }
  $stateColor = ['in_sync' => 'var(--green)', 'lagging' => 'var(--amber)', 'missing' => 'var(--amber)', 'unreachable' => 'var(--red)', 'unknown' => 'var(--dim)'];
@endphp

@if(session('ok'))<div class="ad-note ok">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="ad-note" style="border-color:var(--red);color:var(--red)">{{ implode(' · ', $errors->all()) }}</div>@endif

<div class="ad-panel">
  <div class="ad-panel-h" style="flex-wrap:wrap;gap:10px">
    <div>
      <h2 style="margin:0">مدیریت DNS مشتریان</h2>
      <p style="margin:6px 0 0;color:var(--muted);font-size:12.5px;line-height:1.9;max-width:80ch">
        دیتابیسِ همین سایت منبعِ حقیقت است؛ <code dir="ltr">dns:publish</code> هر دقیقه تغییرات را روی primaryِ پنهان می‌فرستد و
        نیم‌سرورهای عمومی با catalog و AXFRِ امضاشده با TSIG دریافت می‌کنند. «منتشر شد» فقط وقتی است که هر دو نیم‌سرور serialِ تازه را با AA بدهند.
      </p>
    </div>
    <div style="display:flex;gap:6px;margin-inline-start:auto;flex-wrap:wrap">
      <span class="ad-badge {{ $enabled ? 'pub' : 'draft' }}" style="padding:6px 12px">پرچمِ عرضه: {{ $enabled ? 'روشن' : 'خاموش' }}</span>
      <span class="ad-badge {{ $open ? 'pub' : 'draft' }}" style="padding:6px 12px">{{ $open ? 'سرویس برای مشتری باز است' : 'سرویس برای مشتری بسته است' }}</span>
    </div>
  </div>
  @unless($ready)
    <p style="color:var(--amber);font-size:13.5px">جدول‌های DNS هنوز روی این نصب ساخته نشده‌اند (مهاجرتِ 2026_11_03_000100 و 000200).</p>
  @endunless
</div>

<div class="ad-grid2">
  <div class="ad-panel">
    <div class="ad-panel-h"><h3>اتصال و نیم‌سرورها</h3></div>
    <form method="POST" action="/admin/dns/settings">
      @csrf
      <div class="ad-field">
        <label><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $enabled))> پرچمِ عرضه (بخشِ DNS در پنلِ مشتری)</label>
        <p style="color:var(--dim);font-size:11.5px;margin:4px 0 0">فقط پس از آزمونِ پذیرشِ هر دو نیم‌سرور روشن شود. خاموش‌کردن هیچ zoneی را حذف نمی‌کند.</p>
      </div>
      <div class="ad-field">
        <label>نشانیِ APIِ primary (https)</label>
        <input name="pdns_api_url" dir="ltr" style="{{ $inp }}" value="{{ old('pdns_api_url', $apiUrl) }}" placeholder="https://dns-api.example.net" required>
        @if($apiUrl && ! $apiOk)<p style="color:var(--red);font-size:11.5px;margin:4px 0 0">این نشانی پذیرفتنی نیست؛ هیچ درخواستی فرستاده نمی‌شود.</p>@endif
      </div>
      <div class="ad-field">
        <label>کلیدِ API {!! $hasKey ? '<span style="color:var(--green)">— ذخیره شده؛ خالی = بدونِ تغییر</span>' : '<span style="color:var(--amber)">— وارد نشده</span>' !!}</label>
        <input name="pdns_api_key" type="password" dir="ltr" style="{{ $inp }}" autocomplete="new-password">
      </div>
      <div class="ad-grid2" style="gap:10px">
        <div class="ad-field"><label>نامِ کلیدِ TSIG (فقط نام)</label>
          <input name="tsig_key" dir="ltr" style="{{ $inp }}" value="{{ old('tsig_key', $tsigKey) }}" required></div>
        <div class="ad-field"><label>zoneِ catalog</label>
          <input name="catalog" dir="ltr" style="{{ $inp }}" value="{{ old('catalog', $catalog) }}" required></div>
      </div>
      <div class="ad-field">
        <label>نیم‌سرورهای عمومی (نام، IPv4، IPv6 فقط پس از آزمون، پورت)</label>
        @foreach($nsRows as $i => $n)
          <div style="display:grid;grid-template-columns:2fr 1.3fr 1.6fr .6fr;gap:6px;margin-bottom:6px" dir="ltr">
            <input name="ns[{{ $i }}][host]" style="{{ $inp }}" value="{{ $n['host'] ?? '' }}" placeholder="dns-a.example.net">
            <input name="ns[{{ $i }}][ipv4]" style="{{ $inp }}" value="{{ $n['ipv4'] ?? '' }}" placeholder="IPv4">
            <input name="ns[{{ $i }}][ipv6]" style="{{ $inp }}" value="{{ $n['ipv6'] ?? '' }}" placeholder="IPv6 (optional)">
            <input name="ns[{{ $i }}][port]" style="{{ $inp }}" value="{{ $n['port'] ?? 53 }}" placeholder="53">
          </div>
        @endforeach
        <div style="display:grid;grid-template-columns:2fr 1.3fr 1.6fr .6fr;gap:6px" dir="ltr">
          <input name="ns[{{ count($nsRows) }}][host]" style="{{ $inp }}" placeholder="+ another (optional)">
          <input name="ns[{{ count($nsRows) }}][ipv4]" style="{{ $inp }}" placeholder="IPv4">
          <input name="ns[{{ count($nsRows) }}][ipv6]" style="{{ $inp }}" placeholder="IPv6">
          <input name="ns[{{ count($nsRows) }}][port]" style="{{ $inp }}" placeholder="53">
        </div>
        @if($nsErrors !== [])
          <p style="color:var(--amber);font-size:11.5px;margin:6px 0 0">{{ collect($nsErrors)->map(fn ($e) => \App\Http\Controllers\Admin\DnsController::NS_ERRORS[$e['code']] ?? $e['code'])->unique()->implode(' · ') }}</p>
        @endif
        <p style="color:var(--dim);font-size:11.5px;line-height:1.8;margin:6px 0 0">نام‌ها نباید ns1/ns2.servernet.cloud یا ns1/ns2.servernet.ir باشند. تغییرِ نام پس از داشتنِ مشتری یعنی همهٔ مشتری‌ها باید NSِ دامنه‌شان را دوباره عوض کنند.</p>
      </div>
      <button class="ad-btn" type="submit">ذخیره</button>
    </form>
    <form method="POST" action="/admin/dns/test" style="margin-top:10px">@csrf
      <button class="ad-btn" type="submit">آزمایشِ اتصال و نیم‌سرورها</button>
    </form>
    @if($health)
      <div style="margin-top:12px;font-size:12.5px;line-height:1.9">
        <div style="color:{{ $health['apiOk'] ? 'var(--green)' : 'var(--red)' }}">API: {{ $health['api'] }}</div>
        @if($health['catalog'])<div>{{ $health['catalog'] }}</div>@endif
        @foreach($health['nodes'] as $n)
          <div dir="ltr" style="text-align:left;font-family:ui-monospace,monospace;font-size:11.5px">
            {{ $n['host'] }} {{ $n['ip'] }} {{ $n['proto'] }} → <b style="color:{{ $n['state'] === 'answer' && $n['aa'] ? 'var(--green)' : 'var(--red)' }}">{{ $n['state'] }}{{ $n['aa'] ? ' aa' : '' }}</b> {{ $n['serial'] }}
          </div>
        @endforeach
      </div>
    @endif
  </div>

  <div class="ad-panel">
    <div class="ad-panel-h"><h3>رویدادها</h3></div>
    <div style="max-height:420px;overflow:auto">
      @forelse($events as $e)
        <div style="display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid var(--line);font-size:12.5px">
          <span><code dir="ltr">{{ $e->message }}</code> {{ $e->payload['domain'] ?? '' }} <span style="color:var(--dim)">{{ $e->actor }}</span></span>
          <span style="color:var(--dim);white-space:nowrap">{{ $e->created_at ? sdate($e->created_at, true) : '' }}</span>
        </div>
      @empty
        <p style="color:var(--muted);font-size:12.5px">رویدادی نیست.</p>
      @endforelse
    </div>
  </div>
</div>

@if($ready)
<div class="ad-panel">
  <div class="ad-panel-h" style="flex-wrap:wrap;gap:10px">
    <h3>zoneها</h3>
    <form method="GET" action="/admin/dns" style="display:flex;gap:8px;margin-inline-start:auto">
      <input type="hidden" name="f" value="{{ $filter }}">
      <input name="q" value="{{ $q }}" dir="ltr" placeholder="example.com" style="{{ $inp }};width:220px">
      <button class="ad-btn" type="submit">جست‌وجو</button>
    </form>
  </div>
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">
    @foreach($tabs as $key => $label)
      <a href="/admin/dns?f={{ $key }}{{ $q !== '' ? '&q='.urlencode($q) : '' }}" class="ad-badge {{ $filter === $key ? 'pub' : 'draft' }}" style="text-decoration:none;padding:6px 12px">{{ $label }} ({{ $counts[$key] ?? 0 }})</a>
    @endforeach
  </div>
  <div style="overflow-x:auto">
    <table class="ad-table">
      <thead><tr><th>دامنه</th><th>مشتری</th><th>وضعیت</th><th>انتشار</th><th>نودها</th><th>تفویض</th><th>رکورد</th><th></th></tr></thead>
      <tbody>
        @forelse($zones as $z)
          <tr>
            <td dir="ltr" style="text-align:right;font-weight:600">{{ $z->domain }}@if($z->parent_ds && $z->parent_ds !== [])<div style="color:var(--red);font-size:11px">DS در والد</div>@endif</td>
            <td>@if($z->customer)<a class="t" href="/admin/customers/{{ $z->customer_id }}">{{ $z->customer->displayName() }}</a>@else — @endif
              <div style="color:var(--dim);font-size:11px">{{ $z->ownership }}</div></td>
            <td style="font-size:12.5px">{{ $z->status }}</td>
            <td style="font-size:12px">
              <span style="color:{{ $z->publish_state === 'failed' ? 'var(--red)' : ($z->publish_state === 'published' ? 'var(--green)' : 'var(--amber)') }}">{{ $z->publish_state }}</span>
              <div style="color:var(--dim);font-size:11px" dir="ltr">v{{ $z->applied_version }}/{{ $z->desired_version }} · serial {{ $z->primary_serial ?? '—' }}</div>
              @if($z->last_publish_error)<div style="color:var(--red);font-size:11px;max-width:260px" dir="ltr">{{ \Illuminate\Support\Str::limit($z->last_publish_error, 120) }} ({{ $z->publish_attempts }}×)</div>@endif
            </td>
            <td style="font-size:11.5px">
              @foreach($z->nodes as $n)
                <div dir="ltr" style="text-align:left"><span style="color:{{ $stateColor[$n->state] ?? 'var(--dim)' }}">●</span> {{ $n->node }} {{ $n->serial }}</div>
              @endforeach
            </td>
            <td style="font-size:12px">{{ $z->delegation_state }}<div style="color:var(--dim);font-size:11px">{{ $z->delegation_checked_at ? sdate($z->delegation_checked_at, true) : '—' }}</div></td>
            <td>{{ $z->records_count }}</td>
            <td class="ad-row-act">
              <form method="POST" action="/admin/dns/zones/{{ $z->id }}/check" style="display:inline">@csrf<button class="ad-btn" type="submit" style="padding:5px 10px;font-size:12px">بررسی</button></form>
              <form method="POST" action="/admin/dns/zones/{{ $z->id }}/republish" style="display:inline">@csrf<button class="ad-btn" type="submit" style="padding:5px 10px;font-size:12px">انتشارِ دوباره</button></form>
              @if($z->status !== 'deleting')
                <form method="POST" action="/admin/dns/zones/{{ $z->id }}/status" style="display:inline" onsubmit="return confirm('{{ $z->status === 'suspended' ? 'رفعِ تعلیق؟' : 'تعلیق؟ zone از نیم‌سرورها برداشته می‌شود.' }}')">@csrf
                  <input type="hidden" name="status" value="{{ $z->status === 'suspended' ? 'active' : 'suspended' }}">
                  <button class="ad-btn" type="submit" style="padding:5px 10px;font-size:12px">{{ $z->status === 'suspended' ? 'رفعِ تعلیق' : 'تعلیق' }}</button></form>
                <form method="POST" action="/admin/dns/zones/{{ $z->id }}/delete" style="display:inline" onsubmit="var d=prompt('برای حذف، نامِ دامنه را بنویسید:');if(!d)return false;this.confirm.value=d;return true">@csrf
                  <input type="hidden" name="confirm" value=""><button class="ad-btn" type="submit" style="padding:5px 10px;font-size:12px;color:var(--red)">حذف</button></form>
              @endif
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
