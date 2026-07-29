@extends('admin.layout')
@section('title', 'زیرساختِ سرورِ ابری')
@section('nav_cloud', 'on')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h"><h2>زیرساختِ سرورِ ابری</h2></div>
  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    سرورهای مجازی از <b>چند زیرساخت</b> تأمین می‌شوند، ولی مشتری فقط «مکان» و «مشخصات» را می‌بیند.
    اگر دو زیرساخت پلنِ یکسان در یک شهر داشته باشند، روی سایت <b>یک گزینه</b> است و تحویل از
    <b>ارزان‌ترینِ موجود</b> انجام می‌شود.
    <br>توکن‌ها در <a href="/admin/settings" style="color:#22d3ee">تنظیمات</a> وارد می‌شوند.
  </p>

  @if($notReady)
    <p style="padding:18px;color:#fbbf24">جدول‌های ابری هنوز روی این سرور ساخته نشده‌اند. پس از اجرای مهاجرت فعال می‌شود.</p>
  @else

  <div style="padding:2px 18px 14px;display:flex;gap:10px;flex-wrap:wrap">
    <form method="post" action="/admin/cloud/test" style="display:inline">
      @csrf<button class="btn btn-glass" style="font-size:12.5px"><svg class="icon"><use href="#i-server"/></svg>آزمونِ اتصال</button>
    </form>
    <form method="post" action="/admin/cloud/sync" style="display:inline"
          data-confirm="کاتالوگ از زیرساخت‌ها خوانده و قیمت‌ها بازمحاسبه شود؟">
      @csrf<button class="btn btn-primary" style="font-size:12.5px"><svg class="icon"><use href="#i-restore"/></svg>همگام‌سازیِ کاتالوگ</button>
    </form>
    <form method="post" action="/admin/cloud/sync" style="display:inline">
      @csrf<input type="hidden" name="prices_only" value="1">
      <button class="btn btn-glass" style="font-size:12.5px"><svg class="icon"><use href="#i-coins"/></svg>فقط بازمحاسبهٔ قیمت</button>
    </form>
    <a class="btn btn-glass" style="font-size:12.5px" href="/admin/cloud/probe"><svg class="icon"><use href="#i-code"/></svg>ساختارِ خامِ پاسخ</a>
  </div>

  {{-- وضعیتِ زیرساخت‌ها. عمداً «زیرساختِ ۱/۲» نام‌گذاری شده‌اند تا حتی در پنلِ
       مدیریت هم عادتِ نوشتنِ نامِ ارائه‌دهنده در رابط شکل نگیرد. --}}
  <table class="ad-table">
    <thead><tr><th>زیرساخت</th><th>توکن</th><th>پلنِ فعال</th><th>توانایی‌ها</th></tr></thead>
    <tbody>
      @foreach($providers as $slug => $p)
      <tr>
        <td><b>زیرساختِ {{ fa_num($loop->iteration) }}</b></td>
        <td>
          @if($p['configured'])
            <span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">ذخیره‌شده</span>
          @else
            <span class="ad-badge" style="background:rgba(251,191,36,.12);color:#fbbf24">تنظیم نشده</span>
          @endif
        </td>
        <td dir="ltr">{{ fa_num($p['plans']) }}</td>
        <td style="font-size:12px;color:var(--muted)">
          @php
            $capLabels = [
              'console' => 'کنسول', 'rebuild' => 'نصبِ دوباره', 'resize' => 'تغییرِ پلن',
              'metrics' => 'نمودارِ مصرف', 'reset_password' => 'رمزِ تازه', 'rescue' => 'حالتِ نجات',
            ];
          @endphp
          @foreach($capLabels as $k => $label)
            <span style="color:{{ ($p['caps'][$k] ?? false) ? '#34d399' : 'var(--dim)' }}">
              {{ ($p['caps'][$k] ?? false) ? '✓' : '×' }} {{ $label }}
            </span>@if(! $loop->last) · @endif
          @endforeach
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif
</div>

@unless($notReady)
{{-- مکان‌ها --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h3>مکان‌ها <span class="ad-badge" style="background:rgba(34,211,238,.12);color:#22d3ee">{{ fa_num($locations->count()) }}</span></h3></div>
  @if($locations->isEmpty())
    <p style="padding:16px;color:var(--dim)">هنوز همگام‌سازی نشده.</p>
  @else
    <div class="cl-locs">
      @foreach($locations as $loc)
        <div class="cl-loc">
          <span style="font-size:20px">{{ $loc->flagEmoji() }}</span>
          <div>
            <b>{{ $loc->label('fa') }}</b>
            <small dir="ltr" style="display:block;color:var(--dim)">{{ $loc->code }}</small>
          </div>
          <small style="margin-inline-start:auto;color:var(--muted)">
            {{ fa_num($loc->plans()->where('is_active', true)->count()) }} پلن
          </small>
        </div>
      @endforeach
    </div>
  @endif
</div>

{{-- عرضه‌های عمومی: دقیقاً همان چیزی که مشتری می‌بیند --}}
<div class="ad-panel">
  <div class="ad-panel-h">
    <h3>عرضه‌های عمومی
      <span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">{{ fa_num($offers->count()) }}</span>
      <small style="color:var(--dim);font-weight:400">از {{ fa_num($planCount) }} ردیفِ خام</small>
    </h3>
  </div>
  <p style="padding:0 18px;color:var(--muted);font-size:12.5px;line-height:1.8">
    هر ردیف یک کارت روی سایت است. ستونِ «زیرساخت» فقط برای شماست تا ببینید تحویل از کجا انجام می‌شود.
  </p>
  @if($offers->isEmpty())
    <p style="padding:16px;color:var(--dim)">
      هنوز عرضه‌ای نیست. اگر همگام‌سازی انجام شده ولی این‌جا خالی است، احتمالاً
      <b>نرخِ یورو</b> در دسترس نبوده و قیمتِ تومانی ساخته نشده.
    </p>
  @else
    <div style="overflow-x:auto">
    <table class="ad-table">
      <thead><tr>
        <th>نام</th><th>مکان</th><th>هسته</th><th>رم</th><th>دیسک</th><th>ترافیک</th>
        <th>بها (یورو)</th><th>فروش (یورو)</th><th>فروش (تومان)</th><th>زیرساخت</th>
      </tr></thead>
      <tbody>
        @foreach($offers as $o)
        <tr>
          <td><b dir="ltr">{{ $o->public_name }}</b><small dir="ltr" style="display:block;color:var(--dim)">{{ $o->slug }}</small></td>
          <td>{{ $o->location?->label('fa') ?? $o->location_code }}</td>
          <td dir="ltr">{{ fa_num($o->vcpu) }}</td>
          <td dir="ltr">{{ $o->ramLabel() }}</td>
          <td dir="ltr">{{ $o->diskLabel() }}</td>
          <td dir="ltr">{{ $o->trafficLabel('fa') }}</td>
          <td dir="ltr" style="color:var(--dim)">€{{ number_format($o->cost_eur_cents / 100, 2) }}</td>
          <td dir="ltr">€{{ number_format($o->price_eur_cents / 100, 2) }}</td>
          <td dir="ltr"><b>{{ fa_num(number_format($o->price_irt)) }}</b></td>
          <td><small style="color:var(--muted)">{{ app(\App\Services\Cloud\CloudManager::class)->label($o->provider) }}</small></td>
        </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>
@endunless

<style>
.cl-locs{ padding:8px 18px 18px; display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:10px }
.cl-loc{ display:flex; align-items:center; gap:11px; padding:11px 13px; background:var(--surface2); border:1px solid var(--line); border-radius:11px; font-size:13px }
.cl-loc b{ font-size:13px }
</style>
@endsection
