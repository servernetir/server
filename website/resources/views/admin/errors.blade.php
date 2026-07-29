@extends('admin.layout')
@section('title', 'ردیاب خطا')
@section('nav_errors', 'on')
@section('content')


<div class="ad-toolbar" style="justify-content:space-between">
  <div style="color:var(--muted);font-size:13px">
    {{ fa_num(count($serverErrors)) }} خطای سرور · {{ fa_num(count($nf)) }} صفحهٔ یافت‌نشده
  </div>
  @if(count($serverErrors) || count($nf))
    <form method="post" action="/admin/errors/clear" data-confirm="پاک کردن همهٔ رکوردها؟" data-confirm-danger>
      @csrf<button type="submit" class="ad-badge" style="background:rgba(255,107,107,.15);color:#ff6b6b;border:0;padding:8px 16px;cursor:pointer;font:inherit">پاک کردن</button>
    </form>
  @endif
</div>

{{-- ══ خطاهای ۵۰۰ ══ --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h2>خطاهای سرور (۵۰۰)</h2></div>
  @if(empty($serverErrors))
    <p style="padding:20px;color:#34d399">هیچ خطای سروری ثبت نشده — عالی.</p>
  @else
    <div style="display:flex;flex-direction:column">
      @foreach($serverErrors as $e)
        <div class="err-row">
          <div class="err-top">
            <span class="err-badge">{{ $e['status'] ?? 500 }}</span>
            <b class="err-class">{{ class_basename($e['class'] ?? 'Error') }}</b>
            <span class="err-time" dir="ltr">{{ \Carbon\Carbon::parse($e['at'])->format('m/d H:i:s') }}</span>
          </div>
          <div class="err-msg">{{ $e['message'] ?? '' }}</div>
          <div class="err-meta">
            <span dir="ltr"><b>{{ $e['method'] ?? '' }}</b> {{ $e['url'] ?? '' }}</span>
          </div>
          <div class="err-meta">
            @if(!empty($e['frame']))<span dir="ltr">📍 {{ $e['frame'] }}</span>@endif
            @if(!empty($e['file']))<span dir="ltr" style="color:var(--dim)">{{ $e['file'] }}</span>@endif
          </div>
          <div class="err-meta err-who">
            <span>{{ $e['who'] ?? 'guest' }}</span>
            @if(!empty($e['ip']))<span dir="ltr">{{ $e['ip'] }}</span>@endif
            @if(!empty($e['referer']))<span dir="ltr" style="color:var(--dim)">از: {{ $e['referer'] }}</span>@endif
          </div>
        </div>
      @endforeach
    </div>
  @endif
</div>

{{-- ══ ۴۰۴ها ══ --}}
<div class="ad-panel" style="margin-top:16px">
  <div class="ad-panel-h"><h2>صفحه‌های یافت‌نشده (۴۰۴)</h2></div>
  @if(empty($nf))
    <p style="padding:20px;color:var(--muted)">۴۰۴ای ثبت نشده.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>زمان</th><th>آدرس</th><th>از کجا آمد</th><th>کاربر</th></tr></thead>
      <tbody>
        @foreach($nf as $e)
          <tr>
            <td dir="ltr">{{ \Carbon\Carbon::parse($e['at'])->format('m/d H:i') }}</td>
            <td dir="ltr"><b>{{ $e['method'] ?? '' }}</b> {{ $e['url'] ?? '' }}</td>
            <td dir="ltr" style="color:var(--dim)">{{ $e['referer'] ?? '—' }}</td>
            <td>{{ $e['who'] ?? 'guest' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

<style>
.err-row{ padding:14px 16px; border-bottom:1px solid var(--line) }
.err-row:last-child{ border-bottom:0 }
.err-top{ display:flex; align-items:center; gap:10px; margin-bottom:7px }
.err-badge{ background:rgba(255,107,107,.15); color:#ff6b6b; font-weight:800; padding:2px 9px; border-radius:6px; font-size:12px }
.err-class{ color:#fbbf24; font-size:14px }
.err-time{ margin-inline-start:auto; color:var(--dim); font-size:12px; font-variant-numeric:tabular-nums }
.err-msg{ font-size:13.5px; color:var(--text); line-height:1.8; margin-bottom:7px; word-break:break-word }
.err-meta{ display:flex; gap:14px; flex-wrap:wrap; font-size:12px; color:var(--muted); margin-top:3px }
.err-meta b{ color:#22d3ee }
.err-who{ color:var(--dim) }
</style>

@endsection
