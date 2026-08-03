@extends('admin.layout')
@section('title', 'اتصال سرور موجود به مشتری')
@section('content')

{{-- کلاس‌ها عمداً همان‌های موجودِ admin.css‌اند: .ad-field / .ad-input / .ad-flash
     کلاسِ نبود بی‌هیچ خطایی بی‌استایل رندر می‌شود، پس چیزِ تازه اختراع نمی‌کنیم.
     چیدمانِ دوستونه چون کلاسِ آماده ندارد، درون‌خطی نوشته شده. --}}

<div class="ad-panel">
  <div class="ad-panel-h"><h2>اتصال سرور موجود به مشتری</h2></div>

  <p style="padding:0 18px 14px;color:var(--muted);font-size:13px;line-height:1.9">
    سروری که <b>دستی نزد زیرساخت ساخته‌اید</b> را این‌جا به مشتری وصل کنید — مثلاً وقتی
    مشتری تلفنی سفارش داده و پول را جدا گرفته‌اید.
    <br>پس از ثبت: در پنل خودِ مشتری دیده می‌شود، کنسول و روشن/خاموش و رمز و نصب دوباره
    برایش کار می‌کند، و <b>سررسید تمدید</b> می‌گیرد تا کرون صورت‌حساب سرِ موعد فاکتور بسازد.
    <br><b>این فرم سرور نمی‌سازد</b> — فقط چیزی را که هست ثبت می‌کند، و پیش از ثبت وجودش را
    از خودِ زیرساخت می‌پرسد.
  </p>

  @if($errors->any())
    <div class="ad-flash err" style="margin:0 18px 14px">
      @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
  @endif

  {{-- ── کشفِ خودکار: به‌جای اینکه مدیر شناسه را از پنلِ زیرساخت پیدا و تایپ
       کند (که غلطِ تایپی و سرویسِ بی‌سرور می‌سازد)، از فهرست انتخاب می‌کند. ── --}}
  <div style="padding:0 18px 16px">
    @if($scan === null)
      <a class="btn btn-glass" style="font-size:12.5px" href="/admin/cloud/attach?scan=1">
        <svg class="icon"><use href="#i-search"/></svg>
        سرورهای وصل‌نشده را پیدا کن
      </a>
      <small style="color:var(--muted);font-size:11.5px;display:block;margin-top:7px">
        از همهٔ زیرساخت‌ها می‌پرسد و سرورهایی را نشان می‌دهد که به هیچ مشتری‌ای وصل نیستند.
        چند ثانیه طول می‌کشد.
      </small>
    @else
      @foreach($scan['errors'] as $slug => $msg)
        <div class="ad-flash err" style="margin-bottom:10px">
          {{ $labelOf($slug) }}: {{ $msg }}
          — سرورهای این زیرساخت در فهرستِ زیر <b>نیستند</b>.
        </div>
      @endforeach

      @if(empty($scan['orphans']))
        <div class="ad-flash ok">
          هیچ سرورِ وصل‌نشده‌ای پیدا نشد — همهٔ سرورهای زیرساخت به یک مشتری وصل‌اند.
        </div>
      @else
        <div style="font-size:13px;margin-bottom:9px">
          <b>{{ fa_num(count($scan['orphans'])) }}</b> سرور به هیچ مشتری‌ای وصل نیست.
          روی هرکدام بزنید تا فرمِ پایین پر شود.
        </div>
        <div style="overflow-x:auto">
          <table class="ad-table">
            <thead><tr><th>نام</th><th>شناسه</th><th>آی‌پی</th><th>پلن</th><th>مکان</th><th>زیرساخت</th><th></th></tr></thead>
            <tbody>
              @foreach($scan['orphans'] as $o)
                <tr>
                  <td><b>{{ $o['name'] }}</b></td>
                  <td dir="ltr" style="font-size:12px;color:var(--muted)">{{ $o['ref'] }}</td>
                  <td dir="ltr" style="font-size:12px">{{ $o['ipv4'] ?: '—' }}</td>
                  <td style="font-size:12px;color:var(--muted)">{{ $o['plan'] ?: '—' }}</td>
                  <td style="font-size:12px;color:var(--muted)">{{ $o['location'] ?: '—' }}</td>
                  <td style="font-size:12px">{{ $o['provider_label'] }}</td>
                  <td style="text-align:end">
                    <a class="btn btn-glass" style="font-size:12px;padding:6px 12px"
                       href="/admin/cloud/attach?scan=1&ref={{ urlencode($o['ref']) }}&sname={{ urlencode($o['name']) }}">انتخاب</a>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    @endif
  </div>

  <form method="post" action="/admin/cloud/attach" style="padding:0 18px 18px">
    @csrf

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 18px">
      <div class="ad-field">
        <label>مشتری</label>
        <select class="ad-input" name="customer_id" required>
          <option value="">— انتخاب کنید —</option>
          @foreach($customers as $c)
            <option value="{{ $c->id }}" @selected(old('customer_id') == $c->id)>
              {{ $c->code ?: ('#'.$c->id) }} — {{ $c->email ?: $c->phone }}
            </option>
          @endforeach
        </select>
      </div>

      <div class="ad-field">
        <label>پلن — زیرساخت و مکان از همین برداشته می‌شود</label>
        <select class="ad-input" name="cloud_plan_id" required>
          <option value="">— انتخاب کنید —</option>
          @foreach($plans as $p)
            <option value="{{ $p->id }}" @selected(old('cloud_plan_id') == $p->id)>
              [{{ $labelOf($p->provider) }}] {{ $p->public_name }} ·
              {{ fa_num($p->vcpu) }}هسته/{{ fa_num((int) round($p->ram_mb / 1024)) }}گیگ/{{ fa_num($p->disk_gb) }}گیگ ·
              {{ $p->location_code }}
            </option>
          @endforeach
        </select>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 18px">
      <div class="ad-field">
        <label>شناسهٔ سرور نزد زیرساخت</label>
        <input class="ad-input" type="text" name="provider_ref" dir="ltr" required
               value="{{ old('provider_ref', $prefill) }}" placeholder="مثلاً 123456">
        <small style="color:var(--muted);font-size:11.5px">همان id/uuid که در پنلِ خودِ زیرساخت می‌بینید.</small>
      </div>

      <div class="ad-field">
        <label>نام سرویس — چیزی که مشتری می‌بیند</label>
        <input class="ad-input" type="text" name="name" required maxlength="150"
               value="{{ old('name', $prefillName ?: 'سرور مجازی') }}">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 18px">
      <div class="ad-field">
        <label>مبلغ دوره (تومان)</label>
        <input class="ad-input" type="number" name="price" required min="0" value="{{ old('price') }}">
        <small style="color:var(--muted);font-size:11.5px">مبلغی که هنگام تمدید فاکتور می‌شود.</small>
      </div>

      <div class="ad-field">
        <label>دوره</label>
        <select class="ad-input" name="cycle" required>
          @foreach($cycles as $cy)
            <option value="{{ $cy }}" @selected(old('cycle', 'monthly') === $cy)>{{ \App\Models\Service::labelFor($cy) }}</option>
          @endforeach
        </select>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 18px">
      <div class="ad-field">
        <label>تاریخ شروع — همان روزی که سرور را تحویل داده‌اید</label>
        <input class="ad-input" type="date" name="activated_at" required dir="ltr"
               value="{{ old('activated_at', now()->toDateString()) }}">
      </div>

      <div class="ad-field">
        <label>سررسید تمدید بعدی</label>
        <input class="ad-input" type="date" name="next_due_at" required dir="ltr"
               min="{{ now()->startOfDay()->addDays(6)->toDateString() }}"
               value="{{ old('next_due_at', now()->addMonth()->toDateString()) }}">
        <small style="color:var(--muted);font-size:11.5px;line-height:1.8;display:block">
          روزی که اولین فاکتور تمدید صادر می‌شود.
          <br>⚠️ <b>دستِ‌کم شش روز دیگر</b> باشد. کرونِ صورت‌حساب هر سرویسی را که تا
          پنج روزِ آینده سررسید دارد فاکتور می‌کند، و بعد بابتِ همان فاکتورِ پرداخت‌نشده
          سرور را <b>خاموش</b> می‌کند. اگر مشتری همین حالا بدهکار است، سررسید را دورتر
          بگذارید و فاکتورش را دستی صادر کنید.
        </small>
      </div>
    </div>

    <div style="margin-top:6px;display:flex;gap:10px;flex-wrap:wrap">
      <button class="btn btn-primary" style="font-size:13px">ثبت و اتصال</button>
      <a class="btn btn-glass" style="font-size:12.5px" href="/admin/cloud">بازگشت</a>
    </div>
  </form>
</div>
@endsection
