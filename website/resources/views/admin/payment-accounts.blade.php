@extends('admin.layout')

@section('title', 'حساب‌های ارزی و رمزارز')
@section('nav_payacc', 'on')

@section('content')

{{--
  مقصدهای دریافتِ **آفلاین**: حوالهٔ بانکیِ ارزی و کیفِ رمزارز.

  ⚠️ نسخهٔ اولِ این صفحه با کلاس‌هایی مثل `ad-head`, `ad-card-h`, `ad-btn`,
  `ad-muted` نوشته شده بود که **هیچ‌کدام در admin.css وجود ندارند** — پس صفحه
  بی‌هیچ خطایی بی‌استایل رندر می‌شد. همان تله‌ای که در CLAUDE.md نوشته شده و
  باز هم تکرار شد. واژگانِ واقعیِ این پنل اینهاست و پیش از استفاده grep شده‌اند:
  `.ad-panel` · `.ad-panel-h` · `.ad-table` · `.ad-badge` · `.ad-field`
  `.ad-input` · `.ad-hint` · `.ad-note` · `.ad-flash` · `.btn` `.btn-primary`
  `.btn-glass` · `.ad-pill`

  ⚠️ این‌ها با «واریز به حساب» ریالی فرق دارند: آن یکی یک حسابِ ثابت در
  تنظیمات است. جریانِ تأیید ولی **یکی است** — هر دو رسید می‌سازند و در
  «واریز به حساب» تأیید می‌شوند، پس منطقِ تسویهٔ موازی وجود ندارد.
--}}

@if(session('ok'))<div class="ad-flash">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="ad-flash" style="background:rgba(255,107,107,.12);color:#ff6b6b">{{ $errors->first() }}</div>@endif

<div class="ad-panel">
  <div class="ad-panel-h">
    <h2>حساب‌های ارزی و رمزارز</h2>
    <span class="ad-pill">{{ $accounts->count() }}</span>
  </div>

  <p style="padding:0 18px;color:var(--muted);font-size:13.5px;line-height:1.9">
    مقصدهایی که مشتریِ خارجی می‌تواند به آن‌ها حواله کند. هر حسابِ فعال در صفحهٔ
    فاکتورِ <b>همان ارز</b> دیده می‌شود؛ کیفِ رمزارز در <b>همهٔ</b> فاکتورها.
    پرداخت آفلاین است: مشتری می‌فرستد و شناسه ثبت می‌کند، شما در
    <a href="/admin/bank-transfers" style="color:#22d3ee">واریز به حساب</a> تأیید می‌کنید.
  </p>

  @if($notReady)
    <p style="padding:18px;color:#fbbf24">
      جدول <code>payment_accounts</code> هنوز روی این سرور ساخته نشده. پس از اجرای مهاجرت فعال می‌شود.
    </p>
  @elseif($accounts->isEmpty())
    <p style="padding:16px;color:var(--dim)">
      هنوز حسابی ثبت نشده. تا وقتی هیچ حسابی نباشد، مشتریِ انگلیسی/ترک در صفحهٔ فاکتور «به‌زودی» می‌بیند.
    </p>
  @else
    <table class="ad-table">
      <thead><tr>
        <th>نوع</th><th>ارز</th><th>عنوان</th><th>مقصد</th><th>وضعیت</th><th>ترتیب</th><th></th>
      </tr></thead>
      <tbody>
      @foreach($accounts as $a)
        <tr>
          <td>
            @if($a->isCrypto())
              <span class="ad-badge" style="background:rgba(38,161,123,.14);color:#26a17b">رمزارز</span>
            @else
              <span class="ad-badge" style="background:rgba(56,189,248,.14);color:#38bdf8">بانکی</span>
            @endif
          </td>
          <td dir="ltr"><b>{{ strtoupper($a->currency_code) }}</b></td>
          <td>{{ $a->displayLabel() }}</td>
          <td dir="ltr" style="color:var(--muted);max-width:300px;overflow-wrap:anywhere;font-size:12.5px">
            @if($a->isCrypto())
              {{ $a->network }} · {{ $a->address }}
            @else
              {{ $a->iban ?: $a->account_no }}@if($a->swift)<br><small>{{ $a->swift }}</small>@endif
            @endif
          </td>
          <td>
            @if(! $a->is_active)
              <span class="ad-badge" style="background:rgba(148,163,184,.12);color:var(--muted)">بایگانی</span>
            @elseif(! $a->isUsable())
              <span class="ad-badge" style="background:rgba(251,191,36,.14);color:#fbbf24"
                    title="ناقص است، پس در صفحهٔ فاکتور نمایش داده نمی‌شود">ناقص — نامرئی</span>
            @else
              <span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">فعال</span>
            @endif
          </td>
          <td dir="ltr" style="color:var(--muted)">{{ $a->sort }}</td>
          <td style="text-align:left;white-space:nowrap"></td>
        </tr>
        <tr><td colspan="7" style="padding:0 12px 12px">
          <details>
            <summary style="cursor:pointer;color:#22d3ee;font-size:12.5px;padding:6px 0">ویرایش</summary>
            <form method="post" action="/admin/payment-accounts/{{ $a->id }}" class="pa-form">
              @csrf
              @include('admin.partials.payment-account-fields', ['a' => $a])
              <button class="btn btn-primary" style="font-size:12.5px;padding:8px 16px">ذخیره</button>
            </form>
            @if($a->is_active)
              <form method="post" action="/admin/payment-accounts/{{ $a->id }}/archive" style="margin-top:10px"
                    data-confirm="حساب «{{ $a->displayLabel() }}» بایگانی شود؟ دیگر به مشتری نشان داده نمی‌شود.">
                @csrf<button class="btn" style="background:#ff6b6b;color:var(--bg);font-size:12.5px;padding:7px 13px">بایگانی</button>
              </form>
            @endif
          </details>
        </td></tr>
      @endforeach
      </tbody>
    </table>
  @endif
</div>

@if(! $notReady)
<div class="ad-panel">
  <div class="ad-panel-h"><h2>افزودن حساب</h2></div>
  <div style="padding:16px 18px">
    <form method="post" action="/admin/payment-accounts" class="pa-form">
      @csrf
      @include('admin.partials.payment-account-fields', ['a' => null])
      <button class="btn btn-primary" style="font-size:13px;padding:9px 20px">افزودن</button>
    </form>
  </div>
</div>
@endif

<style>
.pa-form{ display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:13px; align-items:end; }
.pa-form label{ display:flex; flex-direction:column; gap:5px; font-size:12.5px; color:var(--muted); }
.pa-form input, .pa-form select, .pa-form textarea{
  background:var(--bg-2,#0f1520); color:var(--text,#e8eefc); font:inherit; font-size:13px;
  border:1px solid var(--line,#243044); border-radius:10px; padding:9px 11px; }
.pa-form input:focus, .pa-form select:focus, .pa-form textarea:focus{
  outline:none; border-color:#22d3ee; box-shadow:0 0 0 3px rgba(34,211,238,.12); }
.pa-form textarea{ min-height:60px; resize:vertical; }
.pa-form .full{ grid-column:1/-1; }
.pa-form .chk{ flex-direction:row; align-items:center; gap:8px; }
.pa-form .pa-note{ grid-column:1/-1; font-size:12px; color:var(--muted); line-height:1.9;
  border:1px dashed var(--line,#243044); border-radius:10px; padding:11px 13px; margin:0; }
.pa-form button{ grid-column:1/-1; justify-self:start; }
</style>
@endsection
