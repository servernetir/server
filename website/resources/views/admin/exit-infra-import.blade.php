@extends('admin.layout')
@section('title', 'وارد کردنِ ماشین به اکسیت')
@section('nav_exit_infra', 'on')
@section('content')

@php
  $inp = 'background:rgba(148,163,184,.10);color:var(--text);border:1px solid rgba(148,163,184,.3);border-radius:8px;padding:7px 9px;font-size:12.5px';
  $lbl = 'display:block;margin:0 0 6px;font-size:12.5px;color:var(--muted)';
@endphp

<div style="margin-bottom:14px">
  <a href="{{ route('admin.exit-infra') }}" class="ad-badge"
     style="background:rgba(148,163,184,.14);color:var(--muted);padding:8px 14px;text-decoration:none">← بازگشت به زیرساختِ اکسیت</a>
</div>

{{-- ── اسکنِ Proxmox ── --}}
<div class="ad-panel">
  <div class="ad-panel-h" style="display:flex;justify-content:space-between;align-items:center">
    <h2>اسکنِ ماشین‌های Proxmox</h2>
    @if($configured)
      <a href="{{ route('admin.exit-infra.import', ['scan' => 1]) }}" class="ad-badge"
         style="background:rgba(34,211,238,.18);color:var(--text);padding:8px 14px;text-decoration:none">اسکن کن</a>
    @endif
  </div>

  @if(! $configured)
    <p style="padding:12px 18px 18px;color:#fbbf24;font-size:13px;line-height:1.9">
      توکنِ Proxmox در تنظیمات ثبت نشده، پس اسکنِ خودکار ممکن نیست. می‌توانی ماشین را
      دستی (پایین) وارد کنی، یا اول توکن را در
      <a href="/admin/cloud" style="color:var(--text)">زیرساختِ ابری</a> بگذاری.
    </p>
  @elseif($scan === null)
    <p style="padding:12px 18px 18px;color:var(--dim);font-size:13px;line-height:1.9">
      «اسکن کن» را بزن تا ماشین‌های واقعیِ نودِ Proxmox فهرست شوند. هرکدام که هنوز در
      سیستمِ اکسیت نیست، با یک کلیک وارد می‌شود. 🔴 VMهای خطِ‌قرمز (مثلِ ۱۰۸) عمداً
      قابلِ‌ورود نیستند.
    </p>
  @elseif(! $scan['ok'])
    <p style="padding:12px 18px 18px;color:#ff6b6b;font-size:13px">اسکن ناموفق بود: {{ $scan['message'] ?: 'خطای ناشناخته' }}</p>
  @elseif(empty($scan['servers']))
    <p style="padding:12px 18px 18px;color:var(--dim);font-size:13px">هیچ ماشینی روی نود پیدا نشد.</p>
  @else
    {{-- فرم‌ها بیرونِ جدول (form داخلِ <tr> نامعتبر است)؛ کنترل‌های داخلِ ردیف با
         صفتِ form= به این‌ها وصل می‌شوند. --}}
    @foreach($scan['servers'] as $s)
      @if(! $s['protected'] && ! $s['registered'] && $s['ipv4'] !== '')
        <form id="imp-{{ $s['ref'] }}" method="post" action="{{ route('admin.exit-infra.import.store') }}" style="display:none">
          @csrf
          <input type="hidden" name="ref" value="{{ $s['ref'] }}">
          <input type="hidden" name="hostname" value="{{ $s['name'] }}">
          <input type="hidden" name="ipv4" value="{{ $s['ipv4'] }}">
          <input type="hidden" name="status" value="{{ $s['status'] }}">
        </form>
      @endif
    @endforeach

    <div style="padding:0 4px 10px;overflow-x:auto">
      <table class="ad-table">
        <thead><tr><th>نام</th><th>vmid</th><th>وضعیت</th><th>آی‌پیِ داخلی</th><th>سیستم‌عامل</th><th></th></tr></thead>
        <tbody>
          @foreach($scan['servers'] as $s)
            <tr>
              <td style="font-size:12.5px">{{ $s['name'] }}</td>
              <td dir="ltr" style="font-size:12.5px;color:var(--muted)">{{ $s['ref'] }}</td>
              <td style="font-size:12px;color:var(--muted)">{{ $s['status'] }}</td>
              <td dir="ltr" style="font-size:12px;color:var(--muted)">{{ $s['ipv4'] !== '' ? $s['ipv4'] : '—' }}</td>
              @if($s['protected'])
                <td colspan="2"><span class="ad-badge" style="background:rgba(255,107,107,.16);color:#ff6b6b;font-size:12px">🔴 خطِ‌قرمز — قابلِ ورود نیست</span></td>
              @elseif($s['registered'])
                <td colspan="2"><span class="ad-badge" style="background:rgba(52,211,153,.16);color:#34d399;font-size:12px">✓ از قبل ثبت شده</span></td>
              @elseif($s['ipv4'] === '')
                <td colspan="2"><span style="font-size:11.5px;color:#fbbf24">بی‌IP — دستی واردش کن (پایین)</span></td>
              @else
                <td>
                  <select name="os" form="imp-{{ $s['ref'] }}" dir="ltr" style="{{ $inp }}">
                    @foreach($osOptions as $key => $label)
                      <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                  </select>
                </td>
                <td>
                  <button type="submit" form="imp-{{ $s['ref'] }}" class="ad-badge"
                          style="background:rgba(34,211,238,.16);color:var(--text);border:0;cursor:pointer;font-size:12.5px;padding:6px 12px">وارد کن</button>
                </td>
              @endif
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <p style="padding:0 18px 16px;color:var(--dim);font-size:11.5px">
      بعد از ورود، کشورِ خروج و پورت را از خودِ صفحه‌ی «زیرساختِ اکسیت» تنظیم کن.
    </p>
  @endif
</div>

{{-- ── ثبتِ دستی ── --}}
<div class="ad-panel" style="margin-top:16px;max-width:720px">
  <div class="ad-panel-h"><h2>ثبتِ دستیِ یک ماشین</h2></div>
  <form method="post" action="{{ route('admin.exit-infra.import.store') }}" style="padding:6px 18px 20px;display:grid;gap:14px">
    @csrf
    <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px">
      <div>
        <label style="{{ $lbl }}">نامِ ماشین (hostname)</label>
        <input type="text" name="hostname" required maxlength="190" dir="auto"
               value="{{ old('hostname') }}" placeholder="مثلاً personal-vm109" style="{{ $inp }};width:100%">
      </div>
      <div>
        <label style="{{ $lbl }}">vmid (اختیاری)</label>
        <input type="text" name="ref" dir="ltr" maxlength="32" value="{{ old('ref') }}" placeholder="109" style="{{ $inp }};width:100%">
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div>
        <label style="{{ $lbl }}">آی‌پیِ داخلی</label>
        <input type="text" name="ipv4" required dir="ltr" value="{{ old('ipv4') }}" placeholder="10.10.10.50" style="{{ $inp }};width:100%">
      </div>
      <div>
        <label style="{{ $lbl }}">سیستم‌عامل</label>
        <select name="os" dir="ltr" style="{{ $inp }};width:100%">
          @foreach($osOptions as $key => $label)
            <option value="{{ $key }}" @selected(old('os') === $key)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
      <div>
        <label style="{{ $lbl }}">کشورِ خروج (اختیاری)</label>
        <select name="country" dir="ltr" style="{{ $inp }};width:100%">
          <option value="">— بدونِ اکسیت (ایران)</option>
          @foreach($exitOptions as $opt)
            <option value="{{ $opt['code'] }}" @selected(old('country') === $opt['code'])>{{ $opt['flag'] }} {{ $opt['name'] }} ({{ $opt['code'] }})</option>
          @endforeach
        </select>
      </div>
      <div>
        <label style="{{ $lbl }}">پورتِ عمومی (اختیاری)</label>
        <input type="number" name="port" dir="ltr" min="1" max="65535" value="{{ old('port') }}" placeholder="خودکار" style="{{ $inp }};width:100%">
      </div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;margin-top:4px">
      <button type="submit" class="ad-badge"
              style="background:rgba(34,211,238,.20);color:var(--text);border:0;cursor:pointer;font-size:13.5px;padding:10px 22px">افزودن به اکسیت</button>
      <a href="{{ route('admin.exit-infra') }}" style="color:var(--muted);font-size:13px;text-decoration:none">انصراف</a>
    </div>
  </form>
</div>
@endsection
