<footer>
  <div class="container">
    <div class="f-grid">
      <div class="f-about">
        <a href="{{ $homeUrl }}" class="logo"><span class="logo-mark"><svg class="icon"><use href="#i-server"/></svg></span> {{ $isFa ? 'سرورنت' : 'ServerNet' }}</a>
        <p>{{ __('ui.f_about') }}</p>
        <div class="f-social">
          <a href="{{ $social['linkedin'] }}" target="_blank" rel="noopener" aria-label="LinkedIn"><svg class="icon"><use href="#i-linkedin"/></svg></a>
          <a href="{{ $social['instagram'] }}" target="_blank" rel="noopener" aria-label="Instagram"><svg class="icon"><use href="#i-instagram"/></svg></a>
          <a href="https://wa.me/{{ $contact['whatsapp'] }}" target="_blank" rel="noopener" aria-label="WhatsApp"><svg class="icon"><use href="#i-message"/></svg></a>
        </div>
      </div>
      {{-- ══ ستون‌های لینک ══

           🔴 تا امروز هر سه ستون همین‌جا سخت‌کد بودند و مدیر نمی‌توانست لینکی
           کم یا زیاد کند. حالا از `config('servernet.footer_menu')` می‌آیند و
           `MenuManager` رویهٔ پنل را رویشان می‌گذارد.

           ⚠️ نشانی‌ها را **آن‌جا** ساخته و امتحان شده‌اند: مقصدی که ساخته نشود
           رد می‌شود، نه اینکه استثنا بدهد. فوتر روی هر صفحهٔ سایت است و مرداد
           ۱۴۰۵ یک لینک به روتِ بی‌نام همین‌جا کلِ en/tr را ۵۰۰ کرد. --}}
      @foreach(app(\App\Services\MenuManager::class)->footer(app()->getLocale()) as $fcol)
      <div class="f-col">
        <h5 class="f-head">{{ $fcol['head'] }}<svg class="icon chev"><use href="#i-chev"/></svg></h5>
        <div class="f-links"><div class="f-in">
          @foreach($fcol['items'] as $fl)
          <a href="{{ $fl['href'] }}">@if($fl['strong'])<b>{{ $fl['text'] }}@if($fl['arrow']) →@endif</b>@else{{ $fl['text'] }}@endif</a>
          @endforeach
        </div></div>
      </div>
      @endforeach
      <div class="f-col f-contact">
        <h5 class="f-head">{{ __('ui.f_contact') }}<svg class="icon chev"><use href="#i-chev"/></svg></h5>
        <div class="f-links"><div class="f-in">
          <a class="fc" href="tel:{{ $contact['phone_link'] }}"><svg class="icon"><use href="#i-phone"/></svg>{{ $contact['phone'] }}</a>
          <a class="fc" href="mailto:{{ $contact['email'] }}"><svg class="icon"><use href="#i-mail"/></svg>{{ $contact['email'] }}</a>
          <a class="fc" href="mailto:{{ $contact['sales_email'] }}"><svg class="icon"><use href="#i-mail"/></svg>{{ $contact['sales_email'] }}</a>
          <a class="fc" href="https://wa.me/{{ $contact['whatsapp'] }}" target="_blank" rel="noopener"><svg class="icon"><use href="#i-message"/></svg>WhatsApp</a>
          {{-- 🔴 نشانی عمداً این‌جا **نیست** — تصمیمِ کارفرما. جایش صفحهٔ تماس
               است، و در دادهٔ ساختاریافتهٔ `site.blade.php` هم می‌آید. فوتر روی
               هر صفحه تکرار می‌شود و نشانی چیزی است که کاربر یک‌بار و آگاهانه
               دنبالش می‌گردد، نه چیزی که همه‌جا جلوی چشمش باشد. --}}
        </div></div>
      </div>
    </div>

    {{--
      ══ مهرهای اعتماد ══

      🔴 فقط وقتی **واقعاً** ثبت شده باشند. `trust_seals()` مهری را که هر دو
      مقدارش پر نباشد اصلاً برنمی‌گرداند، چون آدرسِ نیمه‌ساخته به صفحهٔ
      نامعتبرِ نماد می‌رود — و خریدارِ ایرانی این مهر را **کلیک می‌کند**.
      مهرِ بی‌اعتبار کلِ سایت را مشکوک می‌کند؛ از نداشتنش بسیار بدتر است.

      ⚠️ نسخهٔ تصویری، نه اسکریپتی: CSP این پروژه اسکریپت و آی‌فریمِ بیرونی را
      بی‌هیچ خطایی بلاک می‌کند ولی `img-src` هر https را می‌پذیرد.
    --}}
    @php($seals = trust_seals())
    @if($seals)
    <div class="f-seals">
      @foreach($seals as $s)
        <a href="{{ $s['href'] }}" target="_blank" rel="noopener noreferrer" referrerpolicy="origin" title="{{ $s['alt'] }}">
          <img src="{{ $s['src'] }}" alt="{{ $s['alt'] }}" loading="lazy" referrerpolicy="origin" width="100" height="100">
        </a>
      @endforeach
    </div>
    @endif

    {{--
      (تاریخچه) تا ممیزی ۶، شناسه‌های ثبتی عمداً در فوتر نبودند — تصمیمِ کارفرما.
      پایین‌تر با استدلالِ حقوقیِ ممیزی ۶ برگشتند؛ این بلوک فقط برای فهمِ گذشته مانده.

      جایشان `/contact` است و `company_identity()` همان‌جا صدا زده می‌شود.
      مهرِ نماد این‌جا می‌مانَد چون کارکردش فرق دارد: مهر یک نشانِ **دیداری**
      است که در همان لحظهٔ خرید باید دیده شود، ولی شمارهٔ ثبت چیزی است که
      کاربر یک‌بار و آگاهانه دنبالش می‌گردد.

      ⚠️ اگر روزی خواستید برگردند، `company_identity()` و حلقهٔ `f-legal` را
      برگردانید — کلاسِ CSSاش هنوز در `site.css` هست.

      🔴 نامِ دستورِ بلوکِ Blade عمداً این‌جا نوشته نشده. نسخهٔ اولِ همین کامنت
      آن را داشت و `CloudServerPageLayoutTest` گرفتش: دستورِ خام داخلِ کامنت با
      دستورِ پایانیِ بعدی جفت می‌شود و همه‌چیزِ میانشان را بی‌صدا از DOM حذف
      می‌کند — صفحه ۲۰۰ می‌ماند و شبیهِ باگِ CSS دیده می‌شود.
    --}}

    {{--
      شناسه‌های ثبتی **در فوتر نمی‌آیند** — تصمیمِ کارفرما، تأییدشده در
      شهریور ۱۴۰۵ پس از یک دورِ رفت‌وبرگشت.

      تاریخچه، تا کسی دوباره از اول شروع نکند:
        ۱) از اول عمداً نبودند — تصمیمِ کارفرما.
        ۲) ممیزی ۶ (حقوقی) برشان گرداند: «الزامِ قانونی، افشای در دسترس
           است؛ فوترِ سراسری هر ۵۶۷ صفحه را پوشش می‌دهد و /about تنها
           کافی ولی شکننده است.»
        ۳) کارفرما تصمیمِ اولش را نگه داشت. بلوک برداشته شد.

      🔴 جایشان `/contact` است و `company_identity()` همان‌جا صدا زده
      می‌شود — یعنی افشا **هست**، فقط سراسری نیست. اگر روزی الزامِ حقوقی
      دوباره مطرح شد، بحث دربارهٔ همین است نه دربارهٔ نبودنِ افشا.

      مهرِ نماد بالاتر می‌مانَد چون کارکردش فرق دارد: مهر یک نشانِ
      **دیداری** است که در همان لحظهٔ خرید باید دیده شود، ولی شمارهٔ ثبت
      چیزی است که کاربر یک‌بار و آگاهانه دنبالش می‌گردد.

      ⚠️ اگر روزی خواستید برگردد: `company_identity()` و حلقهٔ `f-legal`
      را برگردانید — کلاسِ CSSاش هنوز در `site.css` هست — و **حتماً**
      شرطِ «فقط مقادیرِ واقعاً پرشده» را هم با خودش بیاورید. فوترِ سراسری
      با «شمارهٔ ثبت: —» روی ۵۶۷ صفحه، به‌جای اعتماد بی‌دقتی می‌فروشد.

      🔴 نامِ دستورِ بلوکِ Blade عمداً این‌جا نوشته نشده — دستورِ خام داخلِ
      کامنت با دستورِ پایانیِ بعدی جفت می‌شود و همه‌چیزِ میانشان را بی‌صدا
      از DOM حذف می‌کند.
    --}}

    <div class="f-bottom">
      <span>{{ __('ui.f_copy') }}</span>
      <span>servernet.cloud · servernet.ir</span>
    </div>
  </div>
</footer>
