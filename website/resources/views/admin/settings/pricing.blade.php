{{--
  تبِ نرخ‌گذاری و سود — **همهٔ** درصدهای سود در یک صفحه.

  تا امروز نرخِ یورو در بالای تنظیمات بود، حاشیهٔ سودِ سرورِ ابری وسطِ بلوکِ
  زیرساخت، و حاشیهٔ سودِ دامنه ته همان بلوک. سه عددِ هم‌خانواده در سه جای
  نامرتبط یعنی مدیر نمی‌توانست یک‌جا ببیند «امروز روی چه چیزی چقدر سود می‌گیریم».
--}}
<form method="post" action="/admin/settings">
  @csrf
  <input type="hidden" name="tab" value="pricing">

  <div class="ad-panel">
    <div class="ad-panel-h"><h2>نرخِ یورو — لنگرِ همهٔ قیمت‌های سایت</h2></div>
    <p class="set-lead">
      قیمت‌های پایه (تومان) لنگرند. اگر «نرخِ مبنا» را برابرِ نرخِ فعلیِ یورو بگذارید،
      از این پس همهٔ قیمت‌های سایت و فروشگاه خودکار با نرخِ روزِ یورو بالا/پایین
      می‌روند. برای تغییرِ کلی هم فقط همین یک عدد را عوض کنید.
      <br><b>تا وقتی «نرخِ مبنا» خالی باشد، هیچ قیمتی تغییر نمی‌کند</b> (حالتِ امن).
    </p>

    @if($liveRate)
      <div class="set-rate">
        <div><small>نرخِ زندهٔ یورو</small><b dir="ltr">{{ fa_num(number_format($liveRate)) }} <span>تومان</span></b></div>
        <div><small>ضریبِ فعلیِ قیمت‌ها</small><b dir="ltr">{{ fa_num(number_format($priceFactor, 3)) }}<span>×</span></b></div>
      </div>
    @endif

    <div class="set-grid three" style="padding:0 18px 18px">
      <label class="set-f">نرخِ مبنای یورو (تومان)
        <input type="number" name="pricing_baseline_rate" dir="ltr" min="0"
               value="{{ $pricing['pricing_baseline_rate'] }}" placeholder="خالی = خاموش">
        <small>لنگر. تا پر نشود هیچ قیمتی خودکار جابه‌جا نمی‌شود.</small></label>
      <label class="set-f">نرخِ دستی (به‌جای نرخِ زنده)
        <input type="number" name="pricing_rate_override" dir="ltr" min="0" max="5000000"
               value="{{ $pricing['pricing_rate_override'] }}" placeholder="خالی = نرخِ زنده">
        <small>۲۰٬۰۰۰ تا ۵٬۰۰۰٬۰۰۰ تومان، یا خالی.</small></label>
      <label class="set-f">نرخِ دستیِ دلار (تومان)
        <span style="color:var(--dim);font-size:11.5px">زیرساختِ GPU به دلار می‌فروشد؛ بی‌نرخ، پلن‌هایش صفر و نافروختنی می‌شوند.</span>
        <input type="number" name="pricing_usd_rate_override" dir="ltr" min="0" max="5000000"
               value="{{ $pricing['pricing_usd_rate_override'] }}" placeholder="خالی = نرخِ زنده"></label>
      <label class="set-f">کارمزد انتقال ارز — پیش‌فرض (٪)
        <span style="color:var(--dim);font-size:11.5px">روی بهای زیرساخت (ماهانه و ساعتی) پیش از حاشیه می‌نشیند — کارمزد حواله/اسپرد و VAT. این عدد <b>پشتیبانِ</b> زیرساخت‌هایی است که پایین عددِ اختصاصی ندارند.</span>
        <input type="number" name="pricing_fx_fee_pct" dir="ltr" min="0" max="25" step="0.1"
               value="{{ $pricing['pricing_fx_fee_pct'] }}" placeholder="مثلاً 1.5"></label>
      {{-- سربار به تفکیکِ زیرساخت — واقعیتِ مالی‌شان یکی نیست: هتزنر روی
           صورت‌حساب VAT آلمان می‌زند، قیمتِ aeza نهایی است. یک عددِ مشترک یا
           هتزنر را ضررده می‌کرد یا aeza را غیررقابتی. --}}
      <label class="set-f">سربارِ هتزنر (٪)
        <input type="number" name="pricing_fx_fee_pct_hetzner" dir="ltr" min="0" max="25" step="0.1"
               value="{{ $pricing['pricing_fx_fee_pct_hetzner'] }}" placeholder="خالی = پیش‌فرض">
        <small>اگر روی فاکتورهایت VAT آلمان (۱۹٪) هست: ۱۹ + کارمزد حواله (مثلاً ۲۱).</small></label>
      <label class="set-f">سربارِ aeza (٪)
        <input type="number" name="pricing_fx_fee_pct_aeza" dir="ltr" min="0" max="25" step="0.1"
               value="{{ $pricing['pricing_fx_fee_pct_aeza'] }}" placeholder="خالی = پیش‌فرض">
        <small>قیمتش نهایی است؛ معمولاً فقط کارمزد حواله/صرافی (۱ تا ۵).</small></label>
      <label class="set-f">سربارِ GPU/سالاد (٪)
        <input type="number" name="pricing_fx_fee_pct_salad" dir="ltr" min="0" max="25" step="0.1"
               value="{{ $pricing['pricing_fx_fee_pct_salad'] }}" placeholder="خالی = پیش‌فرض">
        <small>پرداختِ دلاری؛ کارمزد کارت/حواله‌ات به آن‌ها.</small></label>
      <label class="set-f">حاشیهٔ سودِ عمومی (٪)
        <input type="number" name="price_margin_pct" dir="ltr" step="0.1"
               value="{{ $pricing['price_margin_pct'] }}" placeholder="۰">
        <small>روی قیمت‌های لنگردارِ سایت (هاست و…) اعمال می‌شود.</small></label>
    </div>
  </div>

  <div class="ad-panel">
    <div class="ad-panel-h"><h2>حاشیهٔ سود به تفکیکِ محصول</h2></div>
    <p class="set-lead">
      ⚠️ عمداً یکی نیستند. بهای دامنه <b>سالانه و کوچک</b> است، پس همان درصدی که
      روی سرور منطقی است روی دامنه قیمت را غیررقابتی می‌کند.
    </p>

    <div class="set-grid three" style="padding:0 18px 18px">
      <label class="set-f">سرورِ ابری (٪)
        <input type="number" name="cloud_margin_pct" dir="ltr" step="1" min="0" max="500"
               value="{{ $pricing['cloud_margin_pct'] }}"
               placeholder="{{ fa_num(\App\Services\Cloud\CloudPricing::DEFAULT_MARGIN_PCT) }} (پیش‌فرض)">
        <small>روی بهایِ یوروییِ زیرساخت اعمال و بعد با نرخِ روز به تومان می‌آید.</small></label>

      <label class="set-f">دامنه (٪)
        <input type="number" name="domain_margin_pct" dir="ltr" step="1" min="0" max="500"
               value="{{ $pricing['domain_margin_pct'] }}" placeholder="۰ (پیش‌فرض)">
        <small>روی ثبت، تمدید و انتقال. صفر یعنی دقیقاً به بهای تمام‌شده — برای جذبِ مشتری.</small></label>

      <label class="set-f">هزینهٔ IPv4 (سنتِ یورو، ماهانه)
        <input type="number" name="cloud_ipv4_eur_cents" dir="ltr" step="1" min="-1" max="10000"
               value="{{ $pricing['cloud_ipv4_eur_cents'] }}" placeholder="خالی = خودکار از زیرساخت">
        <small>🔴 از ۲۰۲۴ در قیمتِ پایهٔ زیرساخت نیست. اگر به بهای تمام‌شده اضافه نشود، ماهی حدود ۰٫۶ یورو روی هر سرور ضرر است.</small></label>
    </div>
  </div>

  {{-- دروازهٔ AI (M5) — سه کلید، و عمداً هیچ پیش‌فرضی: حاشیهٔ خالی یعنی فروش بسته،
       نه فروش به بها. سربارِ ارزِ هر ارائه‌دهنده جای دیگری است (ستونِ خودش در
       /admin/ai) چون این فرم کلیدِ ناشناخته را بی‌صدا دور می‌ریزد. --}}
  <div class="ad-panel">
    <div class="ad-panel-h"><h2>هوش مصنوعی — حاشیه و درِ فروش</h2>
      @if(Route::has('admin.ai.pricing'))<a href="{{ route('admin.ai.pricing') }}" class="btn btn-glass" style="font-size:13px">پیش‌نمایشِ قیمت</a>@endif
    </div>
    <p class="set-lead">
      قیمتِ هر مدل = بهای ارائه‌دهنده × نرخِ روزِ ارز × (۱ + سربارِ ارزِ ارائه‌دهنده) × (۱ + حاشیه)،
      همه رو به بالا گرد. <b>تا حاشیه خالی است هیچ مدلی فروخته نمی‌شود.</b>
      مالیات بر ارزش افزوده جدا و روی همین قیمت اضافه می‌شود.
    </p>
    <div class="set-grid three" style="padding:0 18px 18px">
      <label class="set-f">حاشیهٔ سودِ AI (٪)
        <input type="text" inputmode="decimal" name="ai_margin_pct" dir="ltr" maxlength="6"
               value="{{ $pricing['ai_margin_pct'] }}" placeholder="خالی = فروش بسته">
        <small>بزرگ‌تر از صفر، حداکثر ۵۰۰، تا دو رقمِ اعشار (مثلاً 25 یا 12.5). هر مدل می‌تواند حاشیهٔ خودش را داشته باشد.</small></label>
      <label class="set-f">مشتریانِ آزمایشی (شناسه، با کاما)
        <input type="text" name="ai_canary_customer_ids" dir="ltr" maxlength="500"
               value="{{ $pricing['ai_canary_customer_ids'] }}" placeholder="مثلاً 1,42">
        <small>پیش از بازشدنِ فروش، فقط همین حساب‌ها می‌توانند تماس بزنند.</small></label>
      <label class="set-f" style="justify-content:flex-start">درِ فروشِ عمومی
        <span style="display:flex;gap:8px;align-items:center;font-size:13px;margin-top:6px">
          <input type="checkbox" name="ai_sales_open" value="1" @checked(($pricing['ai_sales_open'] ?? null) === '1')>
          باز برای همهٔ مشتریان
        </span>
        <small>⚠️ این کلید از مرحلهٔ بعدی (M5.1b) در مسیرِ /v1 خوانده می‌شود؛ تا آن زمان اثری ندارد.</small></label>
    </div>
  </div>

  @include('admin.settings._save')
</form>
