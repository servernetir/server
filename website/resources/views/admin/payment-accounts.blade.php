@extends('admin.layout')

@section('title', 'حساب‌های ارزی و رمزارز')
@section('nav_payacc', 'active')

@section('content')

{{--
  مقصدهای دریافتِ **آفلاین**: حوالهٔ بانکیِ ارزی و کیفِ رمزارز.

  ⚠️ این‌ها با «واریز به حساب» ریالی فرق دارند: آن یکی یک حسابِ ثابت در
  تنظیمات است، این‌ها چند مقصدند و مشتری در صفحهٔ فاکتور یکی را انتخاب می‌کند.
  جریانِ تأیید ولی **یکی است** — هر دو رسید می‌سازند و در «واریز به حساب»
  تأیید می‌شوند، پس هیچ منطقِ تسویهٔ موازی‌ای وجود ندارد.
--}}

<div class="ad-head">
  <h1>حساب‌های ارزی و رمزارز</h1>
  <p class="ad-sub">مقصدهایی که مشتریِ خارجی می‌تواند به آن‌ها حواله کند. هر حسابِ فعال در صفحهٔ فاکتورِ همان ارز دیده می‌شود؛ رمزارز در همهٔ فاکتورها.</p>
</div>

@if($notReady)
  <div class="ad-card" style="border-color:var(--warn-line)">
    <div class="ad-card-b" style="color:var(--warn)">
      جدول <code>payment_accounts</code> هنوز ساخته نشده. اول مهاجرت‌ها را روی سرور اجرا کنید.
    </div>
  </div>
@endif

@if(session('ok'))<div class="ad-flash ok">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="ad-flash err">{{ $errors->first() }}</div>@endif

<div class="ad-card">
  <div class="ad-card-h"><h2>حساب‌های ثبت‌شده</h2><span class="ad-muted">{{ $accounts->count() }} مورد</span></div>
  <div class="ad-card-b" style="overflow-x:auto">
    @if($accounts->isEmpty())
      <p class="ad-muted">هنوز حسابی ثبت نشده. تا وقتی هیچ حسابی نباشد، مشتریِ انگلیسی/ترک در صفحهٔ فاکتور «به‌زودی» می‌بیند.</p>
    @else
      <table class="ad-table">
        <thead><tr>
          <th>نوع</th><th>ارز</th><th>عنوان</th><th>مقصد</th><th>وضعیت</th><th>ترتیب</th><th></th>
        </tr></thead>
        <tbody>
        @foreach($accounts as $a)
          <tr>
            <td>{{ $a->kind === 'crypto' ? 'رمزارز' : 'بانکی' }}</td>
            <td dir="ltr">{{ strtoupper($a->currency_code) }}</td>
            <td>{{ $a->displayLabel() }}</td>
            <td dir="ltr" style="max-width:280px;overflow-wrap:anywhere">
              {{ $a->kind === 'crypto' ? $a->network.' · '.$a->address : ($a->iban ?: $a->account_no) }}
            </td>
            <td>
              @if(! $a->is_active)<span class="ad-badge off">بایگانی</span>
              @elseif(! $a->isUsable())<span class="ad-badge warn">ناقص — نمایش داده نمی‌شود</span>
              @else<span class="ad-badge ok">فعال</span>@endif
            </td>
            <td>{{ $a->sort }}</td>
            <td>
              <details>
                <summary class="ad-link">ویرایش</summary>
                <form method="POST" action="/admin/payment-accounts/{{ $a->id }}" class="pa-form">
                  @csrf
                  @include('admin.partials.payment-account-fields', ['a' => $a])
                  <button class="ad-btn primary">ذخیره</button>
                </form>
                @if($a->is_active)
                  <form method="POST" action="/admin/payment-accounts/{{ $a->id }}/archive" style="margin-top:8px">
                    @csrf<button class="ad-btn danger">بایگانی</button>
                  </form>
                @endif
              </details>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div class="ad-card">
  <div class="ad-card-h"><h2>افزودن حساب</h2></div>
  <div class="ad-card-b">
    <form method="POST" action="/admin/payment-accounts" class="pa-form">
      @csrf
      @include('admin.partials.payment-account-fields', ['a' => null])
      <button class="ad-btn primary">افزودن</button>
    </form>
  </div>
</div>

<style>
.pa-form{ display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:12px; align-items:end; margin-top:10px; }
.pa-form label{ display:flex; flex-direction:column; gap:5px; font-size:12.5px; color:var(--ad-muted,#94a3b8); }
.pa-form input, .pa-form select, .pa-form textarea{ background:var(--ad-surface-2,#131a26); color:inherit;
  border:1px solid var(--ad-line,#243044); border-radius:10px; padding:9px 11px; font:inherit; font-size:13px; }
.pa-form textarea{ min-height:62px; resize:vertical; }
.pa-form .full{ grid-column:1/-1; }
.pa-form .chk{ flex-direction:row; align-items:center; gap:8px; }
.pa-note{ grid-column:1/-1; font-size:12px; color:var(--ad-muted,#94a3b8); line-height:1.9;
  border:1px dashed var(--ad-line,#243044); border-radius:10px; padding:10px 12px; }
</style>
@endsection
