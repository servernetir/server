@extends('panel.layout')
@section('title', $domain->domain)

@section('panel')

<div class="pnl-head">
  <div>
    {{-- ⚠️ همهٔ لینک‌ها و همهٔ action‌های این صفحه با lroute() ساخته می‌شوند.
         روت‌های account داخلِ closureِ $site‌اند، پس /en/account/… و
         /tr/account/… وجود دارند؛ route()ِ خام چهار فرمِ مدیریتیِ این صفحه
         (نام‌سرور، قفل، کد انتقال، تمدید خودکار) را به آدرسِ فارسی POST
         می‌کرد و زبانِ مشتری وسطِ کار عوض می‌شد. --}}
    <nav class="blog-crumbs" style="margin-bottom:8px">
      <a href="{{ lroute('account.home') }}">پنل</a><span>/</span>
      <a href="{{ lroute('account.domains') }}">دامنه‌ها</a><span>/</span>
      <span dir="ltr">{{ $domain->domain }}</span>
    </nav>
    <h1 dir="ltr">{{ $domain->domain }}</h1>
  </div>
  @if($domain->isActive())
    <span class="pnl-pill ok">فعال</span>
  @elseif($domain->status === 'expired')
    <span class="pnl-pill danger">منقضی — شاید قابل بازیابی</span>
  @elseif($domain->provision_status === 'manual')
    <span class="pnl-pill danger">بررسی دستی</span>
  @else
    <span class="pnl-pill info">در انتظار ثبت</span>
  @endif
</div>

@if(session('ok'))<div class="dm-note ok">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="dm-note danger">{{ $errors->first() }}</div>@endif

{{-- 🔴 کدِ انتقال فقط **یک بار** و فقط از session نشان داده می‌شود: ذخیره‌اش
     نمی‌کنیم چون کلیدِ مالکیت است و هرکس داشته باشد دامنه را می‌برد. --}}
@if(session('authCode'))
  <div class="dm-note warn">
    <b>کد انتقال (EPP):</b>
    <code dir="ltr" style="user-select:all;font-size:14px">{{ session('authCode') }}</code>
    <br><small>این کد کلید مالکیت دامنه است. فقط به رجیستراری بدهید که می‌خواهید دامنه به آن منتقل شود. با تازه‌کردن صفحه ناپدید می‌شود.</small>
  </div>
@endif

{{-- 🔴 سفارشِ انتقال پیامِ خودش را دارد. تا ممیزیِ شهریور ۱۴۰۵ این صفحه به
     ردیفِ انتقال هم می‌گفت «در صف ثبت است» — و بدتر: پیامِ بعد از پرداخت
     می‌گفت «کد انتقال را در همین صفحه وارد کنید» ولی فرمی وجود نداشت.
     مشتری پول داده بود و به بن‌بست می‌خورد. --}}
@if($domain->isTransfer() && ! $domain->isActive() && $domain->status !== 'expired')
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>انتقال دامنه به سرورنت</h2></div>
    <div class="pnl-sec-b">
      @if($domain->transfer_status === 'pending' && $transferUnpaid !== null)
        <p style="margin-top:0">برای شروعِ انتقال، ابتدا فاکتور را پرداخت کنید. پس از پرداخت، همین‌جا کدِ انتقال (EPP) را وارد می‌کنید.</p>
        <a class="pnl-btn" href="{{ lroute('account.invoice', $transferUnpaid) }}">پرداخت فاکتور انتقال</a>
      @elseif($domain->transfer_status === 'pending')
        <p style="margin-top:0">
          پرداخت انجام شده است. حالا کدِ انتقال (EPP) را وارد کنید — این کد را از رجیسترارِ فعلیِ دامنه می‌گیرید
          و پیش از آن باید قفلِ انتقال را نزدِ همان رجیسترار خاموش کرده باشید.
        </p>
        <form method="post" action="{{ lroute('account.domain.transfer.submit', $domain) }}"
              style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
          @csrf
          <input name="auth_code" dir="ltr" class="pnl-input" style="max-width:280px"
                 autocomplete="off" placeholder="EPP / Auth Code" required minlength="4">
          <button class="pnl-btn" type="submit">شروع انتقال</button>
        </form>
        <p style="font-size:12px;color:var(--dim);margin-bottom:0;line-height:2">
          کدِ انتقال ذخیره نمی‌شود و فقط برای همین درخواست به رجیسترار می‌رود.
        </p>
      @elseif($domain->transfer_status === 'submitted')
        <p style="margin:0">
          درخواستِ انتقال به رجیسترار ارسال شده و در انتظارِ تأییدِ رجیسترارِ فعلی است — معمولاً تا ۵ روزِ کاری.
          وضعیت به‌صورت خودکار پیگیری می‌شود و نتیجه به شما اطلاع داده می‌شود.
        </p>
      @elseif($domain->transfer_status === 'failed')
        <p style="margin:0">
          ثبتِ درخواستِ انتقال ممکن نشد و همکاران ما در حالِ بررسی‌اند.
          اگر ظرفِ ۲۴ ساعت انجام نشود، مبلغ به اعتبارِ حسابتان بازمی‌گردد.
        </p>
      @endif
    </div>
  </section>
@elseif($domain->status === 'expired')
  {{-- 🔴 مسیرِ نجات (redemption) — تا شهریور ۱۴۰۵ دامنهٔ منقضی از پنل غیب
       می‌شد و تنها راهش «تماس با پشتیبانی» بود، دقیقاً در پنجره‌ای که هنوز
       می‌شد نجاتش داد. --}}
  <section class="pnl-sec">
    <div class="pnl-sec-h"><h2>بازیابی دامنهٔ منقضی</h2></div>
    <div class="pnl-sec-b">
      @php $dmRestoreFee = (int) \App\Models\Setting::get('domain_restore_fee_toman'); @endphp
      @if($domain->provision_status === 'pending' || $domain->provision_status === 'running')
        <p style="margin:0">پرداخت شما رسید و بازیابی در حال انجام است — نتیجه به شما اطلاع داده می‌شود.</p>
      @elseif($domain->provision_status === 'manual')
        <p style="margin:0">بازیابی در دست بررسی همکاران ماست. اگر ممکن نشود، مبلغ به اعتبار حسابتان بازمی‌گردد.</p>
      @elseif($dmRestoreFee > 0 && $domain->op_id)
        <p style="margin-top:0">
          این دامنه منقضی شده ولی احتمالاً هنوز در دورهٔ بازیابی رجیستری است.
          هزینهٔ نجات = هزینهٔ تمدید یک‌ساله + کارمزد بازیابی ({{ cloud_price($dmRestoreFee) }})؛
          <b>هر روز تأخیر شانس نجات را کم می‌کند.</b>
        </p>
        <form method="post" action="{{ lroute('account.domain.restore', $domain) }}">
          @csrf
          <button class="pnl-btn" type="submit">صدور فاکتور بازیابی</button>
        </form>
        <p style="font-size:12px;color:var(--dim);margin-bottom:0;line-height:2">
          اگر بازیابی نزد رجیستری ممکن نشود، کل مبلغ به اعتبار حسابتان بازمی‌گردد.
        </p>
      @else
        <p style="margin:0">برای بررسی امکان نجات این دامنه با پشتیبانی تماس بگیرید — هر روز تأخیر شانس را کم می‌کند.</p>
      @endif
    </div>
  </section>
@elseif(! $domain->isActive())
  <section class="pnl-sec">
    <div class="pnl-sec-b">
      @if($domain->provision_status === 'manual')
        <p>ثبت این دامنه نیاز به بررسی دستی دارد و همکاران ما در حال پیگیری‌اند. به‌محض ثبت به شما اطلاع می‌دهیم.</p>
      @else
        <p>این دامنه در صف ثبت است. پس از تأیید پرداخت، ثبت به‌صورت خودکار انجام می‌شود و معمولاً چند دقیقه طول می‌کشد.</p>
      @endif
    </div>
  </section>
@endif

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>مشخصات</h2></div>
  <div class="pnl-sec-b">
    <div class="pnl-tw">
      <table class="pnl-table">
        <tbody>
          <tr><th>تاریخ ثبت</th><td>{{ $domain->registered_at ? sdate($domain->registered_at) : '—' }}</td></tr>
          <tr><th>تاریخ انقضا</th><td>{{ $domain->expires_at ? sdate($domain->expires_at) : '—' }}</td></tr>
          <tr><th>دورهٔ ثبت</th><td>{{ fa_num($domain->period_years) }} سال</td></tr>
          {{-- قیمتِ مؤثرِ روز (ذخیره + استعلامِ تازه + کفِ ارزی) — همان عددی
               که فاکتورِ تمدید می‌گیرد، نه عددِ فریزشدهٔ روزِ خرید. --}}
          <tr><th>هزینهٔ تمدید</th><td>{{ cloud_price(($renewUnit ?? 0) > 0 ? $renewUnit : $domain->renew_toman) }} / سال</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

@if($domain->isActive())
{{-- 🔴 تمدیدِ دستی — تا مرداد ۱۴۰۵ اصلاً وجود نداشت: تنها مسیرِ تمدید،
     فاکتورِ خودکارِ کرون در ۲۱ روزِ آخر بود و مشتریِ نگران هیچ دکمه‌ای
     نداشت. مدتِ تازه به پایانِ دورهٔ فعلی اضافه می‌شود؛ تمدیدِ زودتر
     یعنی هیچ روزی از دست نمی‌رود. --}}
<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>تمدید دامنه</h2></div>
  <div class="pnl-sec-b">
    {{-- قیمتِ مؤثرِ تمدید از کنترلر می‌آید (renew_toman یا کفِ ارزی، هرکدام بالاتر)
         تا عددِ روی فرم همانی باشد که فاکتور می‌گیرد. --}}
    @php $dmRenewUnit = (int) ($renewUnit ?? ($domain->renew_toman ?: $domain->price_toman)); @endphp
    @if($dmRenewUnit > 0)
      <p style="font-size:13px;color:var(--dim);line-height:2;margin-top:0">
        هر زمان می‌توانید تمدید کنید — مدتِ تازه به پایانِ دورهٔ فعلی اضافه می‌شود و روزی از دست نمی‌رود.
        پس از پرداختِ فاکتور، تمدید خودکار انجام و تاریخِ انقضای تازه همین‌جا دیده می‌شود.
      </p>
      <form method="post" action="{{ lroute('account.domain.renew', $domain) }}"
            style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        @csrf
        <select name="years" class="pnl-input" style="max-width:260px">
          @foreach(range(1, 5) as $y)
            <option value="{{ $y }}">{{ fa_num($y) }} سال — {{ cloud_price($dmRenewUnit * $y) }}</option>
          @endforeach
        </select>
        <button class="pnl-btn" type="submit">صدور فاکتور تمدید</button>
      </form>
      <p style="font-size:12px;color:var(--dim);margin-bottom:0;line-height:2">
        مبلغِ بالا بدونِ مالیات است؛ مالیات روی فاکتور محاسبه می‌شود.
      </p>
    @else
      <p style="margin:0">قیمتِ تمدید برای این دامنه ثبت نشده است؛ برای تمدید با پشتیبانی تماس بگیرید.</p>
    @endif
  </div>
</section>

<section class="pnl-sec">
  <div class="pnl-sec-h">
    <h2>نام‌سرورها</h2>
  </div>
  <div class="pnl-sec-b">
    <p style="font-size:13px;color:var(--dim);line-height:2;margin-top:0">
      نام‌سرور تعیین می‌کند دامنه به کدام هاست اشاره کند. اگر هاست را از ما گرفته‌اید، مقدار پیش‌فرض درست است.
      تغییرات تا ۲۴ ساعت طول می‌کشد تا در همهٔ دنیا منتشر شود.
    </p>
    <form method="post" action="{{ lroute('account.domain.ns', $domain) }}">
      @csrf
      @foreach(range(0, 3) as $i)
        <p class="dm-ns-row">
          <label for="ns{{ $i }}" style="display:block;font-size:12.5px;margin-bottom:4px">
            نام‌سرور {{ fa_num($i + 1) }}@if($i < 2) <span style="color:var(--danger)">*</span>@endif
          </label>
          <input id="ns{{ $i }}" name="ns[]" dir="ltr" autocomplete="off"
                 value="{{ $domain->effectiveNameServers()[$i] ?? '' }}"
                 placeholder="{{ $defaultNs[$i] ?? 'ns.example.com' }}">
        </p>
      @endforeach
      <button class="pnl-btn" type="submit">ذخیرهٔ نام‌سرورها</button>
    </form>
  </div>
</section>

<section class="pnl-sec">
  <div class="pnl-sec-h"><h2>امنیت و انتقال</h2></div>
  <div class="pnl-sec-b">
    <p style="font-size:13px;color:var(--dim);line-height:2;margin-top:0">
      وقتی قفل انتقال روشن است، هیچ‌کس نمی‌تواند دامنه را از سرورنت خارج کند — حتی با داشتن کد انتقال.
      برای انتقال دامنه به جای دیگر، اول قفل را خاموش و سپس کد انتقال را دریافت کنید.
    </p>

    <form method="post" action="{{ lroute('account.domain.lock', $domain) }}" style="display:inline">
      @csrf
      <input type="hidden" name="lock" value="{{ $domain->is_locked ? 0 : 1 }}">
      <button class="pnl-btn" type="submit">
        {{ $domain->is_locked ? 'خاموش‌کردن قفل انتقال' : 'روشن‌کردن قفل انتقال' }}
      </button>
    </form>

    @unless($domain->is_locked)
      <form method="post" action="{{ lroute('account.domain.authcode', $domain) }}" style="display:inline">
        @csrf
        <button class="pnl-btn" type="submit">دریافت کد انتقال</button>
      </form>
    @endunless

    <form method="post" action="{{ lroute('account.domain.autorenew', $domain) }}" style="display:inline">
      @csrf
      <input type="hidden" name="auto_renew" value="{{ $domain->auto_renew ? 0 : 1 }}">
      <button class="pnl-btn" type="submit">
        {{ $domain->auto_renew ? 'خاموش‌کردن تمدید خودکار' : 'روشن‌کردن تمدید خودکار' }}
      </button>
    </form>

    <p style="font-size:12px;color:var(--dim);margin-bottom:0;line-height:2">
      وضعیت فعلی — قفل انتقال: <b>{{ $domain->is_locked ? 'روشن' : 'خاموش' }}</b> ·
      تمدید خودکار: <b>{{ $domain->auto_renew ? 'روشن' : 'خاموش' }}</b>
    </p>
  </div>
</section>
@endif

@endsection
