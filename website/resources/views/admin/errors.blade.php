@extends('admin.layout')
@section('title', 'ردیاب خطا')
@section('nav_errors', 'on')
@section('content')


<div class="ad-toolbar" style="justify-content:space-between">
  <div style="color:var(--muted);font-size:13px">
    {{ fa_num(count($serverErrors)) }} خطای سرور · {{ fa_num(count($incidents)) }} خرابیِ خاموش · {{ fa_num(count($nf)) }} صفحهٔ یافت‌نشده
  </div>
  {{-- incidents هم شمرده می‌شود، وگرنه با ردیابِ فقط-حادثه نشانِ کنارِ منو
       روشن می‌مانْد و هیچ راهی برای پاک‌کردنش نبود. --}}
  @if(count($serverErrors) || count($incidents) || count($nf))
    <form method="post" action="/admin/errors/clear" data-confirm="پاک کردن همهٔ رکوردها؟" data-confirm-danger>
      @csrf<button type="submit" class="ad-badge" style="background:rgba(255,107,107,.15);color:#ff6b6b;border:0;padding:8px 16px;cursor:pointer;font:inherit">پاک کردن</button>
    </form>
  @endif
</div>

{{--
  ══ سلامتِ سامانه ══

  🔴 عمداً **بالای** فهرستِ خطاهاست.

  ردیابِ خطا فقط چیزی را نشان می‌دهد که استثنا پرتاب کرده. ولی گران‌ترین
  خرابی‌های این پروژه هیچ استثنایی نساختند: کرونی که نمی‌دود، دامنه‌ای که پول
  گرفته شده و ثبت نمی‌شود، سرویسی که در صف مانده. آن‌ها را باید **پرسید**، نه
  منتظرِ گزارششان ماند.
--}}
<div class="ad-panel" style="margin-bottom:18px">
  <div class="ad-panel-h"><h2>سلامتِ سامانه</h2></div>
  <div style="display:flex;flex-direction:column">
    @foreach($health as $c)
      <div class="err-row" style="border-inline-start:3px solid {{ $c['level'] === 'fail' ? '#ff6b6b' : ($c['level'] === 'warn' ? '#fbbf24' : '#34d399') }}">
        <div class="err-top">
          <span style="font-size:15px">{{ $c['level'] === 'fail' ? '🔴' : ($c['level'] === 'warn' ? '⚠️' : '✅') }}</span>
          <b class="err-class">{{ $c['title'] }}</b>
        </div>
        <div class="err-msg" style="color:var(--muted)">{{ $c['detail'] }}</div>
        {{--
          🔴 نام‌بردن نیمی از کار است.

          کارفرما: «نمی‌دونم مربوط به کدوم مشتریاست … بتونم مدیریتش کنم.» متن
          حالا نام می‌برد، ولی اگر برای اقدام باز هم باید در پنل دنبالِ همان
          مشتری گشت، هشدار همچنان کارِ پیداکردن را به آدم واگذار کرده.
        --}}
        @if(!empty($c['links']))
          <div class="hl-acts">
            @foreach($c['links'] as $l)
              <a href="{{ $l['url'] }}" class="hl-act">{{ $l['label'] }} ↩</a>
            @endforeach
          </div>
        @endif
      </div>
    @endforeach
  </div>
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

{{-- ══ خرابی‌های گرفته‌شده ══
     خطاهایی که کد عمداً گرفت و جریان را نشکست. مسیرهای پول و تحویل همه
     این‌شکلی‌اند (یک پیامکِ نرفته نباید پرداختِ واقعی را برگرداند)، پس تا امروز
     هیچ‌کدامشان به این صفحه نمی‌رسیدند — یعنی همان کلاسی از باگ که بدترین است:
     «شکست نخورد، فقط اتفاق نیفتاد». --}}
<div class="ad-panel" style="margin-top:16px">
  <div class="ad-panel-h"><h2>خرابی‌های خاموش</h2></div>
  <p style="padding:0 18px 12px;color:var(--muted);font-size:12.5px;line-height:1.9">
    اینها سایت را نشکسته‌اند، ولی کاری که باید انجام می‌شد انجام نشده —
    مثلاً پول گرفته شد و سرویس فعال نشد، یا سرور ساخته نشد.
  </p>
  @if(empty($incidents))
    <p style="padding:0 20px 20px;color:#34d399">موردی ثبت نشده.</p>
  @else
    <table class="ad-table">
      <thead><tr><th>زمان</th><th>حوزه</th><th>پیام</th><th>جزئیات</th><th>جا</th></tr></thead>
      <tbody>
        @foreach($incidents as $e)
        <tr>
          <td dir="ltr" style="font-size:12px;color:var(--muted);white-space:nowrap">{{ $e['at'] ?? '—' }}</td>
          <td><span class="ad-badge" style="background:rgba(251,191,36,.14);color:#fbbf24">{{ $e['area'] ?? '—' }}</span></td>
          <td style="font-size:12.5px">{{ $e['message'] ?? '—' }}</td>
          <td dir="ltr" style="font-size:11.5px;color:var(--muted)">
            @foreach((array) ($e['ctx'] ?? []) as $k => $v){{ $k }}={{ $v }} @endforeach
          </td>
          <td dir="ltr" style="font-size:11.5px;color:var(--dim)">{{ $e['frame'] ?? $e['file'] ?? '—' }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
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

/* میان‌برهای اقدام روی هشدارِ سلامت — از متن به پروندهٔ همان مشتری */
.hl-acts{ display:flex; flex-wrap:wrap; gap:7px; margin-top:9px }
.hl-act{ display:inline-flex; align-items:center; gap:5px; font-size:12.5px; font-weight:600;
         padding:5px 11px; border-radius:9px; border:1px solid var(--line2);
         background:var(--surface2); color:var(--muted); transition:.18s }
.hl-act:hover{ color:#22d3ee; border-color:#22d3ee }
</style>

@endsection
