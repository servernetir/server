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
      <div class="f-col">
        <h5 class="f-head">{{ __('ui.f_products') }}<svg class="icon chev"><use href="#i-chev"/></svg></h5>
        <div class="f-links"><div class="f-in">
          <a href="{{ lroute('hosting', 'linux') }}">{{ __('ui.f_p1') }}</a>
          <a href="{{ lroute('catalog', ['category'=>'vps','slug'=>'iran']) }}">{{ __('ui.f_p2') }}</a>
          <a href="{{ lroute('catalog', ['category'=>'dedicated','slug'=>'iran']) }}">{{ __('ui.f_p3') }}</a>
          <a href="{{ lroute('catalog', ['category'=>'domain','slug'=>'popular-tlds']) }}">{{ __('ui.f_p4') }}</a>
          <a href="{{ lroute('catalog', ['category'=>'cloud','slug'=>'iaas']) }}">{{ __('ui.f_p5') }}</a>
        </div></div>
      </div>
      <div class="f-col">
        <h5 class="f-head">{{ __('ui.f_solutions') }}<svg class="icon chev"><use href="#i-chev"/></svg></h5>
        {{-- ⚠️ قبلاً هر پنج لینک به «#enterprise» صفحهٔ اول می‌رفت: کاربر با پنج
             کلیکِ مختلف به یک جا می‌رسید و پنج فرصتِ لینک‌سازیِ داخلی هدر می‌شد،
             در حالی که برای هرکدام صفحهٔ اختصاصیِ کامل داریم. حالا هر برچسب به
             صفحهٔ خودش می‌رود. «تلفن ابری» هم اضافه شد چون تنها راهکاری بود که
             از هیچ‌جای سایت لینک نداشت (orphan) و گوگل پیدایش نمی‌کرد. --}}
        <div class="f-links"><div class="f-in">
          <a href="{{ lroute('solution', 'infrastructure') }}">{{ __('ui.f_s1') }}</a>
          <a href="{{ lroute('solution', 'ai-agents') }}">{{ __('ui.f_s2') }}</a>
          <a href="{{ lroute('solution', 'bpmn-erp') }}">{{ __('ui.f_s3') }}</a>
          <a href="{{ lroute('solution', 'web-design') }}">{{ __('ui.f_s4') }}</a>
          <a href="{{ lroute('solution', 'seo-services') }}">{{ __('ui.f_s5') }}</a>
          <a href="{{ lroute('solution', 'managed') }}">{{ __('ui.f_s6') }}</a>
          <a href="{{ lroute('solution', 'cloud-phone') }}">{{ __('ui.f_s7') }}</a>
          <a href="{{ lroute('solutions.index') }}"><b>{{ __('ui.f_s_all') }} →</b></a>
        </div></div>
      </div>
      <div class="f-col">
        <h5 class="f-head">{{ __('ui.f_company') }}<svg class="icon chev"><use href="#i-chev"/></svg></h5>
        <div class="f-links"><div class="f-in">
          <a href="{{ lroute('about') }}">{{ __('ui.f_c1') }}</a>
          {{-- بخشِ ارومیه از مرداد ۱۴۰۵ سه‌زبانه است؛ برچسب inline چون فقط همین‌جاست --}}
          <a href="{{ lroute('urmia.hub') }}">{{ ['fa' => 'خدمات ما در ارومیه', 'en' => 'Our services in Urmia', 'tr' => 'Urmiye hizmetlerimiz'][app()->getLocale()] ?? 'Urmia' }}</a>
          <a href="{{ lroute('blog.index') }}">{{ __('ui.f_c3') }}</a>
          <a href="{{ lroute('careers') }}">{{ __('ui.cr_title') }}</a>
          <a href="{{ lroute('status') }}">{{ __('ui.status_title') }}</a>
          <a href="{{ lroute('sla') }}">{{ __('ui.sla_title') }}</a>
          {{-- دو صفحهٔ اثباتِ تازهٔ ممیزی ۴ — بی‌لینکِ ورودی، صفحهٔ یتیم می‌شدند --}}
          <a href="{{ lroute('speed') }}">{{ __('ui.f_speed') }}</a>
          <a href="{{ lroute('terms') }}">{{ __('ui.f_terms') }}</a>
          <a href="{{ lroute('aup') }}">{{ __('ui.f_aup') }}</a>
          <a href="{{ lroute('abuse') }}">{{ __('ui.f_abuse') }}</a>
          <a href="{{ lroute('official') }}">{{ __('ui.f_official') }}</a>
          <a href="{{ lroute('privacy') }}">{{ __('ui.f_c4') }}</a>
          {{-- ناحیهٔ کاربری = کنسولِ خودمان، نه WHMCSِ بیرونی --}}
          <a href="{{ console_lroute('account.home') }}">{{ __('ui.f_c5') }}</a>
        </div></div>
      </div>
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
      شناسه‌های ثبتی در فوتر — برگشتِ تصمیم در ممیزی ۶ (حقوقی): «الزامِ
      قانونی، افشای در دسترس است؛ راه‌حلِ استاندارد فوترِ سراسری است که هر ۵۶۷
      صفحه را پوشش می‌دهد. /about تنها، کافی ولی شکننده است.» مارکتینگ هم همین
      را برای ۲۹ صفحهٔ ارومیه خواست. تصمیمِ قبلیِ کارفرما (فقط /contact) با
      همین استدلالِ حقوقی کنار گذاشته شد — اگر نپذیرفت، همین یک بلوک را بردار.

      همان قاعدهٔ همیشگی: فقط مقادیرِ **واقعاً پرشده** (company_identity)،
      هیچ جای‌نگهداری. تا مدیر در /admin/settings چیزی وارد نکند، هیچ‌چیز
      رندر نمی‌شود.
    --}}
    @php($fLegal = company_identity())
    @if($fLegal !== [] || company_address() !== null)
    <div class="f-legal">
      @foreach($fLegal as $row)
        <span><b>{{ __($row['label']) }}:</b> {{ $isFa ? fa_num($row['value']) : $row['value'] }}</span>
      @endforeach
      @if(company_address() !== null)
        <span><b>{{ __('ui.about_reg_addr') }}:</b> {{ company_address() }}</span>
      @endif
    </div>
    @endif

    <div class="f-bottom">
      <span>{{ __('ui.f_copy') }}</span>
      <span>servernet.cloud · servernet.ir</span>
    </div>
  </div>
</footer>
