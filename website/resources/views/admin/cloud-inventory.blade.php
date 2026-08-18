@extends('admin.layout')
@section('title', 'تطبیق موجودی سرورها')
@section('nav_cloud', 'active')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h"><h2>تطبیق موجودی سرورها</h2></div>

  <p style="padding:0 18px 14px;color:var(--muted);font-size:13px;line-height:1.9">
    آنچه <b>نزد زیرساخت‌ها</b> داریم با آنچه <b>در سامانه ثبت است</b> مقایسه می‌شود.
    هر مغایرت یک نشتی پول است: سرورِ بی‌مشتری را ما اجاره می‌دهیم، و سرویسی که سرورش
    وجود ندارد یعنی مشتری فاکتور می‌گیرد بابت چیزی که نیست.
  </p>

  @foreach($report['errors'] as $slug => $msg)
    <div class="ad-flash err" style="margin:0 18px 12px">
      <b>{{ $labelOf($slug) }}</b> — {{ $msg }}
      <br><small>سرورهای این زیرساخت در هیچ‌کدام از فهرست‌های زیر شمرده نشده‌اند.
      تا وقتی این خطا هست، «سرور یتیم ندارید» را باور نکنید.</small>
    </div>
  @endforeach

  @if(empty($report['checked']))
    <p style="padding:0 18px 18px;color:var(--muted);font-size:13px">
      هیچ زیرساختی توکن ندارد، پس چیزی برای مقایسه نیست.
      توکن‌ها را در <a href="/admin/settings">تنظیمات</a> وارد کنید.
    </p>
  @endif

  {{-- ── یتیم‌ها ── --}}
  <div style="padding:0 18px 6px">
    <h3 style="font-size:13.5px;color:var(--cyan);margin:14px 0 10px">
      سرورهای بی‌مشتری ({{ fa_num(count($report['orphans'])) }})
    </h3>
    <p style="color:var(--muted);font-size:12.5px;line-height:1.9;margin:0 0 10px">
      نزد زیرساخت هستند و پولشان را می‌دهیم، ولی به هیچ سرویسی وصل نیستند.
      یا به مشتری وصلشان کنید، یا اگر واقعاً بی‌استفاده‌اند نزد زیرساخت حذفشان کنید.
    </p>
  </div>

  @if(empty($report['orphans']))
    <p style="padding:0 18px 14px;color:var(--muted);font-size:13px">موردی نیست.</p>
  @else
    <div style="padding:0 18px 14px;overflow-x:auto">
      <table class="ad-table">
        <thead><tr><th>نام</th><th>شناسه</th><th>آی‌پی</th><th>پلن</th><th>مکان</th><th>وضعیت</th><th>زیرساخت</th><th></th></tr></thead>
        <tbody>
          @foreach($report['orphans'] as $o)
            <tr>
              <td><b>{{ $o['name'] }}</b></td>
              <td dir="ltr" style="font-size:12px;color:var(--muted)">{{ $o['ref'] }}</td>
              <td dir="ltr" style="font-size:12px">{{ $o['ipv4'] ?: '—' }}</td>
              <td style="font-size:12px;color:var(--muted)">{{ $o['plan'] ?: '—' }}</td>
              <td style="font-size:12px;color:var(--muted)">{{ $o['location'] ?: '—' }}</td>
              <td style="font-size:12px">{{ $o['status'] }}</td>
              <td style="font-size:12px">{{ $o['provider_label'] }}</td>
              <td style="text-align:end">
                <a class="btn btn-glass" style="font-size:12px;padding:6px 12px"
                   href="/admin/cloud/attach?ref={{ urlencode($o['ref']) }}&sname={{ urlencode($o['name']) }}">وصل به مشتری</a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  {{-- ── شبح‌ها ── --}}
  <div style="padding:0 18px 6px">
    <h3 style="font-size:13.5px;color:var(--cyan);margin:14px 0 10px">
      سرویس‌های بی‌سرور ({{ fa_num(count($report['ghosts'])) }})
    </h3>
    <p style="color:var(--muted);font-size:12.5px;line-height:1.9;margin:0 0 10px">
      در سامانه ثبت‌اند ولی زیرساخت چنین سروری نمی‌شناسد. مشتری در پنلش دکمه‌هایی
      می‌بیند که همه خطا می‌دهند. معمولاً یعنی سرور را نزد زیرساخت دستی حذف کرده‌ایم
      ولی سرویس در سامانه باز مانده.
    </p>
  </div>

  @if(empty($report['ghosts']))
    <p style="padding:0 18px 18px;color:var(--muted);font-size:13px">موردی نیست.</p>
  @else
    <div style="padding:0 18px 18px;overflow-x:auto">
      <table class="ad-table">
        <thead><tr><th>سرویس</th><th>مشتری</th><th>شناسه نزد زیرساخت</th><th>آی‌پی ثبت‌شده</th><th>وضعیت سرویس</th><th>زیرساخت</th><th></th></tr></thead>
        <tbody>
          @foreach($report['ghosts'] as $g)
            <tr>
              <td><b>{{ $g['service_name'] ?: '—' }}</b>
                <div style="font-size:11.5px;color:var(--dim)">#{{ fa_num($g['service_id']) }}</div></td>
              <td dir="ltr" style="font-size:12px">{{ $g['customer_code'] ?: '—' }}</td>
              <td dir="ltr" style="font-size:12px;color:var(--muted)">{{ $g['ref'] }}</td>
              <td dir="ltr" style="font-size:12px">{{ $g['ipv4'] ?: '—' }}</td>
              <td style="font-size:12px">{{ $g['service_status'] }}</td>
              <td style="font-size:12px">{{ $g['provider_label'] }}</td>
              <td style="text-align:end">
                <a class="btn btn-glass" style="font-size:12px;padding:6px 12px"
                   href="/admin/services/{{ $g['service_id'] }}/history">تاریخچه</a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  {{-- ── مغایرت آی‌پی ── --}}
  @php $mismatched = array_values(array_filter($report['attached'], fn ($a) => $a['ip_mismatch'] ?? false)); @endphp
  @if($mismatched)
    <div style="padding:0 18px 6px">
      <h3 style="font-size:13.5px;color:var(--cyan);margin:14px 0 10px">
        مغایرت آی‌پی ({{ fa_num(count($mismatched)) }})
      </h3>
      <p style="color:var(--muted);font-size:12.5px;line-height:1.9;margin:0 0 10px">
        آی‌پی واقعی سرور با آنچه در پنل مشتری نشان می‌دهیم فرق دارد — یعنی مشتری
        آی‌پی مرده می‌بیند. با <code dir="ltr">cloud:sync</code> یا همگام‌سازی سرویس درست می‌شود.
      </p>
    </div>
    <div style="padding:0 18px 18px;overflow-x:auto">
      <table class="ad-table">
        <thead><tr><th>سرویس</th><th>مشتری</th><th>آی‌پی واقعی</th><th>زیرساخت</th></tr></thead>
        <tbody>
          @foreach($mismatched as $m)
            <tr>
              <td><b>{{ $m['service_name'] ?: '—' }}</b>
                <div style="font-size:11.5px;color:var(--dim)">#{{ fa_num($m['service_id']) }}</div></td>
              <td dir="ltr" style="font-size:12px">{{ $m['customer_code'] ?: '—' }}</td>
              <td dir="ltr" style="font-size:12px">{{ $m['ipv4'] ?: '—' }}</td>
              <td style="font-size:12px">{{ $m['provider_label'] }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  {{-- ── ماشینِ زندهٔ سرویسِ بسته‌شده ──

    🔴 برعکسِ شبح، و پرهزینه‌ترین حالتِ این صفحه: سرور هنوز نزدِ زیرساخت است و
    اجاره‌اش می‌رود، ولی سرویسش لغو/خاتمه یافته و هیچ‌کس پولی نمی‌دهد.

    تا امروز در سطلِ «وصل و سالم» می‌افتاد. شبح‌ها هم نمی‌گرفتندش، چون آن‌جا
    سرویسِ مرده **عمداً** کنار گذاشته می‌شود (نبودنِ سرورِ سرویسِ بسته‌شده طبیعی
    است). یعنی این حالت در هیچ سطلی نبود و صفحه با خیالِ راحت سبز می‌ماند. --}}
  @php
    $deadStill = array_values(array_filter($report['attached'], fn ($a) => $a['service_dead'] ?? false));
    $healthy   = array_values(array_filter($report['attached'], fn ($a) => ! ($a['service_dead'] ?? false)));
  @endphp

  @if($deadStill)
    <div style="padding:0 18px 6px">
      <h3 style="font-size:13.5px;color:var(--danger,#f87171);margin:14px 0 10px">
        سرورِ سرویسِ بسته‌شده ({{ fa_num(count($deadStill)) }})
      </h3>
      <p style="color:var(--muted);font-size:12.5px;line-height:1.9;margin:0 0 10px">
        سرویس لغو یا خاتمه یافته ولی ماشین هنوز نزدِ زیرساخت است — یعنی اجاره‌اش
        از حسابِ ما می‌رود و هیچ مشتری‌ای پشتش نیست. کرونِ
        <code dir="ltr">cloud:release-retry</code> ساعتی تلاش می‌کند؛ اگر ماند،
        نزدِ زیرساخت دستی پاکش کنید.
      </p>
    </div>
    <div style="padding:0 18px 18px;overflow-x:auto">
      <table class="ad-table" data-no-enhance>
        <thead><tr><th>سرویس</th><th>مشتری</th><th>نامِ ماشین</th><th>آی‌پی</th><th>وضعیتِ ماشین</th><th>زیرساخت</th></tr></thead>
        <tbody>
          @foreach($deadStill as $d)
            <tr>
              <td><b>{{ $d['service_name'] ?: '—' }}</b>
                <div style="font-size:11.5px;color:var(--dim)">#{{ fa_num($d['service_id']) }} · {{ $d['service_status'] }}</div></td>
              <td dir="ltr" style="font-size:12px">{{ $d['customer_code'] ?: '—' }}</td>
              <td dir="ltr" style="font-size:12px">{{ $d['name'] ?: '—' }}</td>
              <td dir="ltr" style="font-size:12px">{{ $d['ipv4'] ?: '—' }}</td>
              <td style="font-size:12px">{{ $d['status'] ?: '—' }}</td>
              <td style="font-size:12px">{{ $d['provider_label'] }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  {{-- ⚠️ فهرست، نه فقط شمارنده. «۳ سرور سالم است» ادعایی است که مدیر هیچ راهی
       برای راستی‌آزمایی‌اش ندارد — و دقیقاً وقتی لازم می‌شود که یک ردیف مشکوک
       است و باید بشود دید کدام. --}}
  @if($healthy)
    <details class="ad-fold" style="margin:0 18px 18px">
      <summary style="cursor:pointer;color:var(--muted);font-size:12.5px">
        {{ fa_num(count($healthy)) }} سرور به سرویسِ زنده وصل و سالم است — نمایش فهرست
      </summary>
      <div style="overflow-x:auto;margin-top:10px">
        <table class="ad-table" data-no-enhance>
          <thead><tr><th>سرویس</th><th>مشتری</th><th>نامِ ماشین</th><th>آی‌پی</th><th>وضعیت</th><th>زیرساخت</th></tr></thead>
          <tbody>
            @foreach($healthy as $h)
              <tr>
                <td><b>{{ $h['service_name'] ?: '—' }}</b>
                  <div style="font-size:11.5px;color:var(--dim)">#{{ fa_num($h['service_id']) }}</div></td>
                <td dir="ltr" style="font-size:12px">{{ $h['customer_code'] ?: '—' }}</td>
                <td dir="ltr" style="font-size:12px">{{ $h['name'] ?: '—' }}</td>
                <td dir="ltr" style="font-size:12px">{{ $h['ipv4'] ?: '—' }}</td>
                <td style="font-size:12px">{{ $h['status'] ?: '—' }}</td>
                <td style="font-size:12px">{{ $h['provider_label'] }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </details>
  @else
    <div style="padding:0 18px 18px;color:var(--muted);font-size:12px">
      هیچ سرورِ وصل‌شده‌ای پیدا نشد.
    </div>
  @endif
</div>
@endsection
