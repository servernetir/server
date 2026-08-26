{{--
  تبِ زیرساخت و CDN — اعتبارنامهٔ هر چیزی که سرور یا DNS تحویل می‌دهد.

  ⚠️ همهٔ توکن‌ها با `putSecret()` **رمزنگاری‌شده** ذخیره می‌شوند، هرگز به فرم
  برنمی‌گردند، و «خالی = دست نزن». برای حذف، هر کدام تیکِ جدا دارد — وگرنه یک
  بازکردنِ ساده صفحه، توکن را پاک می‌کرد.
--}}
<form method="post" action="/admin/settings">
  @csrf
  <input type="hidden" name="tab" value="infra">

  {{-- ═══ CDN و DNS ═══ --}}
  <div class="ad-panel">
    <div class="ad-panel-h">
      <h2>Cloudflare — DNS زیردامنهٔ رایگان</h2>
      @if($cloud['cloudflare'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">فعال</span>
      @else<span class="ad-badge" style="background:rgba(251,191,36,.12);color:#fbbf24">تنظیم نشده</span>@endif
    </div>
    <p class="set-lead">
      وقتی مشتری «زیردامنهٔ رایگان» می‌گیرد، پس از تحویل رکوردِ <b>A</b> آن خودکار
      به IPِ سرور ست می‌شود (بدونِ این، سایتش بالا نمی‌آید چون nameserverها روی
      Cloudflare است). در Cloudflare یک <b>API Token</b> بسازید با دسترسیِ
      <b dir="ltr">Zone → DNS → Edit</b> و فقط برای zoneِ
      <b dir="ltr">{{ config('servernet.subdomain_zone') }}</b>.
    </p>
    <div class="set-grid" style="padding:0 18px 18px">
      <label class="set-f">API Token @if($cloud['cloudflare'])<span style="color:var(--dim)">(خالی = بدونِ تغییر)</span>@endif
        <input type="password" name="cloudflare_token" dir="ltr" autocomplete="new-password" maxlength="200"
               placeholder="{{ $cloud['cloudflare'] ? '••••••••••  ذخیره‌شده' : 'توکن را این‌جا بچسبانید' }}"></label>
      <label class="set-f">Zone ID <span style="color:var(--dim)">(اختیاری)</span>
        <input type="text" name="cloudflare_zone_id" dir="ltr" maxlength="64"
               value="{{ $cloud['cf_zone'] }}" placeholder="خالی = خودکار پیدا می‌شود"></label>
      @if($cloud['cloudflare'])
        <label class="set-danger full"><input type="checkbox" name="cloudflare_forget" value="1"> حذفِ توکنِ ذخیره‌شده</label>
      @endif
    </div>
  </div>

  {{-- ═══ زیرساختِ سرورِ ابری ═══ --}}
  <div class="ad-panel">
    <div class="ad-panel-h">
      <h2>زیرساختِ سرورِ ابری (VPS)</h2>
      @if($cloud['plans'] > 0)
        <span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">{{ fa_num($cloud['plans']) }} پلن فعال</span>
      @endif
    </div>
    <p class="set-lead">
      قیمتِ فروش از بهایِ یوروییِ زیرساخت + حاشیهٔ سود ساخته می‌شود و با نرخِ روزِ
      یورو به تومان می‌آید — روزی یک‌بار خودکار. درصدِ سود در
      <a href="/admin/settings?tab=pricing" style="color:#22d3ee">تبِ نرخ‌گذاری</a> است.
      <br><b style="color:#fbbf24">نامِ زیرساخت هیچ‌جای سایت و پنلِ مشتری دیده نمی‌شود.</b>
    </p>

    <div class="set-grid" style="padding:0 18px 18px">
      <label class="set-f">زیرساختِ ۱ — API Token
        @if($cloud['hetzner'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">ذخیره‌شده</span>@endif
        <input type="password" name="hetzner_api_token" dir="ltr" autocomplete="new-password" maxlength="300"
               placeholder="{{ $cloud['hetzner'] ? '••••••••••  خالی = بدونِ تغییر' : 'توکن را این‌جا بچسبانید' }}"></label>
      <label class="set-f">زیرساختِ ۲ — API Key
        @if($cloud['aeza'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">ذخیره‌شده</span>@endif
        <input type="password" name="aeza_api_token" dir="ltr" autocomplete="new-password" maxlength="300"
               placeholder="{{ $cloud['aeza'] ? '••••••••••  خالی = بدونِ تغییر' : 'کلید را این‌جا بچسبانید' }}"></label>
      <label class="set-f full">زیرساختِ ۳ — API Key <span style="color:var(--dim)">(ایرانی)</span>
        @if($cloud['arvan'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">ذخیره‌شده</span>@endif
        <input type="password" name="arvan_api_token" dir="ltr" autocomplete="new-password" maxlength="400"
               placeholder="{{ $cloud['arvan'] ? '••••••••••  خالی = بدونِ تغییر' : 'کلید را با پیشوندِ Apikey بچسبانید' }}"></label>
    </div>

    {{-- زیرساختِ ۴ سه کلید دارد، نه یکی: OVH هر درخواست را جداگانه امضا می‌کند
         و بدونِ هر سه، امضا ساخته نمی‌شود و همه‌چیز ۴۰۳ می‌گیرد. --}}
    <div class="set-box">
      <div class="set-box-h">
        <b>زیرساختِ ۴ — OVHcloud</b>
        @if($cloud['ovh'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">هر سه کلید ذخیره‌شده</span>@endif
      </div>
      <p>
        این زیرساخت سه کلید می‌خواهد. هر سه را از <span dir="ltr">eu.api.ovh.com/createToken</span>
        بسازید و دسترسی‌های <span dir="ltr">GET/POST /vps*</span> و <span dir="ltr">GET /me</span> را بدهید.
        <br>⚠️ <b>خرید خودکار هنوز فعال نیست</b> — سفارش در OVH از سبد خرید چندمرحله‌ای می‌گذرد
        و تا وقتی روی حساب واقعی آزمایش نشده، سفارش‌ها به صف تحویل دستی می‌روند.
        مدیریت سرورهای موجود (روشن/خاموش/نصب دوباره) کامل کار می‌کند.
      </p>
      <div class="set-grid three">
        <label class="set-f">Application Key
          <input type="password" name="ovh_app_key" dir="ltr" autocomplete="new-password" maxlength="200"
                 placeholder="{{ $cloud['ovh'] ? '••••••••  خالی = بدونِ تغییر' : 'AK' }}"></label>
        <label class="set-f">Application Secret
          <input type="password" name="ovh_app_secret" dir="ltr" autocomplete="new-password" maxlength="200"
                 placeholder="{{ $cloud['ovh'] ? '••••••••  خالی = بدونِ تغییر' : 'AS' }}"></label>
        <label class="set-f">Consumer Key
          <input type="password" name="ovh_consumer_key" dir="ltr" autocomplete="new-password" maxlength="200"
                 placeholder="{{ $cloud['ovh'] ? '••••••••  خالی = بدونِ تغییر' : 'CK' }}"></label>
      </div>
      @if($cloud['ovh'])
        <label class="set-danger"><input type="checkbox" name="ovh_forget" value="1"> هر سه کلید را فراموش کن</label>
      @endif
    </div>

    {{-- زیرساختِ ۶ — GPU. از هر پنجِ بالا متفاوت است و متنِ زیر عمداً صریح
         می‌گوید چرا: این‌جا ماشینِ مجازی نیست، کانتینر است؛ و نمونه‌ها **قطع
         می‌شوند** حتی در بالاترین اولویت. مدیری که این را نداند، محصول را با
         زبانِ «سرورِ اختصاصیِ پایدار» می‌فروشد و تعهدِ /sla را زیرش می‌گذارد. --}}
    <div class="set-box">
      <div class="set-box-h">
        <b>زیرساختِ ۶ — GPU</b>
        @if($cloud['salad'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">کلید و سازمان ذخیره‌شده</span>@endif
      </div>
      <p>
        کلید را از بخشِ <span dir="ltr">API Access</span> حسابتان بردارید. نامِ
        سازمان و پروژه در <b>هر</b> مسیرِ این API هستند، پس بدونشان هیچ درخواستی
        ساخته نمی‌شود.
        <br>⚠️ <b>این زیرساخت ماشینِ مجازی ندارد — کانتینر است.</b> نه سیستم‌عاملِ
        انتخابی، نه رمزِ root، نه IP اضافه؛ دسترسی با کلیدِ SSH به خودِ نمونه.
        <br>🔴 <b>نمونه‌ها قطع می‌شوند — حتی در بالاترین اولویت</b>، چون گره‌ها
        رایانه‌های خانگیِ بی‌کارند. برای بارِ کاریِ تحمل‌پذیر (استنتاج، رندر،
        آموزش) مناسب است، نه برای سرورِ همیشه‌روشنِ مشتری.
      </p>
      <div class="set-grid">
        <label class="set-f full">API Key
          <input type="password" name="salad_api_key" dir="ltr" autocomplete="new-password" maxlength="300"
                 placeholder="{{ $cloud['salad'] ? '••••••••••  خالی = بدونِ تغییر' : 'کلید را این‌جا بچسبانید' }}"></label>
        <label class="set-f">نامِ سازمان
          <input type="text" name="salad_org" dir="ltr" maxlength="120"
                 value="{{ $cloud['sl']['org'] }}" placeholder="organization name"></label>
        <label class="set-f">نامِ پروژه
          <input type="text" name="salad_project" dir="ltr" maxlength="120"
                 value="{{ $cloud['sl']['project'] }}" placeholder="default"></label>

        {{-- 🔴 بی‌این، تحویل عمداً انجام **نمی‌شود**: کانتینری که بالا بیاید و
             مشتری راهی به داخلش نداشته باشد، از تحویل‌نشدن بدتر است. --}}
        <label class="set-f full">ایمیجِ کانتینر <span style="color:var(--dim)">(بدونِ آن تحویل انجام نمی‌شود)</span>
          <input type="text" name="salad_image" dir="ltr" maxlength="200"
                 value="{{ $cloud['sl']['image'] }}" placeholder="registry/image:tag — باید SSH و SSH_PUBLIC_KEY را بشناسد"></label>

        <label class="set-f">اولویت
          <select name="salad_priority">
            @foreach(['high' => 'بالا (گران‌تر، کم‌ترین قطعی)', 'medium' => 'متوسط', 'low' => 'پایین', 'batch' => 'کمینه (ارزان‌ترین، بیشترین قطعی)'] as $k => $lbl)
              <option value="{{ $k }}" @selected(($cloud['sl']['priority'] ?: 'high') === $k)>{{ $lbl }}</option>
            @endforeach
          </select></label>

        {{-- 🔴 این دو نرخ در API آنها **نیستند** و فقط در مستنداتِ متنی‌اند.
             بهایِ تمام‌شده = قیمتِ GPU + vCPU×نرخ + گیگ‌رم×نرخ؛ اگر تکهٔ GPU را
             تنها بگیریم، روی پیکربندیِ بزرگ زیرِ قیمتِ خرید می‌فروشیم. --}}
        <label class="set-f">نرخِ هر vCPU در ساعت (دلار)
          <input type="text" name="salad_vcpu_usd_hour" dir="ltr" inputmode="decimal" maxlength="12"
                 value="{{ $cloud['sl']['vcpu'] }}" placeholder="0.004"></label>
        <label class="set-f">نرخِ هر گیگ رم در ساعت (دلار)
          <input type="text" name="salad_ram_gb_usd_hour" dir="ltr" inputmode="decimal" maxlength="12"
                 value="{{ $cloud['sl']['ram'] }}" placeholder="0.001"></label>

        <label class="set-f full" style="flex-direction:row;align-items:center;gap:8px">
          <input type="checkbox" name="salad_forget" value="1">
          <span>کلید را فراموش کن <span style="color:var(--dim)">(سازمان و پروژه می‌مانند)</span></span></label>
      </div>
    </div>

    {{-- زیرساختِ ۵ — Proxmox VE: میزبانِ خودمان در ایران، برای Exit VPS. فقط
         Token Secret رمزنگاری‌شده است؛ بقیه کانفیگِ ساده‌اند و خالی = پیش‌فرضِ
         درایور. برای «تعویضِ» میزبان کافی است API URL عوض شود — بی‌دیپلوی. --}}
    <div class="set-box">
      <div class="set-box-h">
        <b>زیرساختِ ۵ — Proxmox (تهران)</b>
        @if($cloud['proxmox'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">توکن ذخیره‌شده</span>@endif
      </div>
      <p>
        میزبانِ Proxmoxِ خودمان برای «Exit VPS». توکن را در پنلِ Proxmox زیرِ
        <span dir="ltr">Datacenter → Permissions → API Tokens</span> بسازید.
        مقادیرِ خالی به پیش‌فرضِ درایور برمی‌گردند.
      </p>
      <div class="set-grid">
        <label class="set-f">API URL
          <input type="text" name="proxmox_api_url" dir="ltr" maxlength="200"
                 value="{{ $cloud['px']['api_url'] }}" placeholder="https://85.9.108.118:8006/api2/json"></label>
        <label class="set-f">Node
          <input type="text" name="proxmox_node" dir="ltr" maxlength="64"
                 value="{{ $cloud['px']['node'] }}" placeholder="ir"></label>
        <label class="set-f">Token ID
          <input type="text" name="proxmox_token_id" dir="ltr" maxlength="120"
                 value="{{ $cloud['px']['token_id'] }}" placeholder="svc-controller@pve!provisioner"></label>
        <label class="set-f">Token Secret
          <input type="password" name="proxmox_token_secret" dir="ltr" autocomplete="new-password" maxlength="200"
                 placeholder="{{ $cloud['proxmox'] ? '••••••••  خالی = بدونِ تغییر' : 'UUIDِ توکن' }}"></label>
        <label class="set-f">Template VMID
          <input type="number" name="proxmox_template_vmid" dir="ltr" min="1"
                 value="{{ $cloud['px']['template'] }}" placeholder="9002"></label>
        <label class="set-f">Storage
          <input type="text" name="proxmox_storage" dir="ltr" maxlength="64"
                 value="{{ $cloud['px']['storage'] }}" placeholder="vmstoreid"></label>
        <label class="set-f">Bridge
          <input type="text" name="proxmox_bridge" dir="ltr" maxlength="32"
                 value="{{ $cloud['px']['bridge'] }}" placeholder="vmbr1"></label>
        <label class="set-f">Gateway
          <input type="text" name="proxmox_gateway" dir="ltr" maxlength="45"
                 value="{{ $cloud['px']['gateway'] }}" placeholder="10.10.10.1"></label>
        <label class="set-f">IP شروع
          <input type="text" name="proxmox_ip_start" dir="ltr" maxlength="45"
                 value="{{ $cloud['px']['ip_start'] }}" placeholder="10.10.10.60"></label>
        <label class="set-f">کشورهای خروج (Exit VPS) — جدا با کاما
          <input type="text" name="proxmox_exit_countries" dir="ltr" maxlength="200"
                 value="{{ $cloud['exit_countries'] }}" placeholder="de,nl,fi (پیش‌فرض)"></label>
      </div>
      @if($cloud['proxmox'])
        <label class="set-danger"><input type="checkbox" name="proxmox_forget" value="1"> توکن را فراموش کن</label>
      @endif
    </div>

    {{-- توکنِ pull-agentِ هاستِ ایران --}}
    <div class="set-box">
      <div class="set-box-h">
        <b>توکنِ Pull-Agent (هاستِ ایران)</b>
        @if($cloud['agent'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">توکن ذخیره‌شده</span>@endif
      </div>
      <p>
        موتورِ هاستِ ایران با هدرِ <span dir="ltr">X-Agent-Token</span> از
        <span dir="ltr">/agent/countryroutes</span> و <span dir="ltr">/agent/portforwards</span>
        حالتِ مطلوب را می‌خوانَد. همین توکن را در تنظیماتِ ایجنت هم بگذارید.
      </p>
      <label class="set-f">Agent Token
        <input type="password" name="agent_pull_token" dir="ltr" autocomplete="new-password" maxlength="200"
               placeholder="{{ $cloud['agent'] ? '••••••••  خالی = بدونِ تغییر' : 'یک رشتهٔ تصادفیِ قوی' }}"></label>
      @if($cloud['agent'])
        <label class="set-danger"><input type="checkbox" name="agent_forget" value="1"> توکن را فراموش کن</label>
      @endif
    </div>

    @if($cloud['hetzner'] || $cloud['aeza'] || $cloud['arvan'])
      <div style="padding:0 18px 18px;display:flex;gap:16px;flex-wrap:wrap">
        @if($cloud['hetzner'])<label class="set-danger"><input type="checkbox" name="hetzner_forget" value="1"> حذفِ توکنِ زیرساختِ ۱</label>@endif
        @if($cloud['aeza'])<label class="set-danger"><input type="checkbox" name="aeza_forget" value="1"> حذفِ کلیدِ زیرساختِ ۲</label>@endif
        @if($cloud['arvan'])<label class="set-danger"><input type="checkbox" name="arvan_forget" value="1"> حذفِ کلیدِ زیرساختِ ۳</label>@endif
      </div>
    @endif
  </div>

  {{-- ═══ سیاست‌های فروش و تحویل ═══ --}}
  <div class="ad-panel">
    <div class="ad-panel-h"><h2>سیاست‌های فروش و تحویل</h2></div>
    <div class="set-grid" style="padding:0 18px 18px">
      {{-- 🔴 سقفِ محافظِ سوءاستفاده. خالی = «پیش‌فرضِ کد»، نه «بی‌سقف». برای
           **همه** یکسان است؛ معافیتِ حساب عمداً وجود ندارد. --}}
      <label class="set-f">سقفِ سفارشِ سرور در ۲۴ ساعت (هر مشتری)
        <input type="number" name="cloud_guard_daily_max" dir="ltr" step="1" min="1" max="100"
               value="{{ $cloud['guard'] }}" placeholder="{{ fa_num(\App\Services\Cloud\CloudFraudGuard::DAILY_MAX) }} (پیش‌فرض)">
        <small>بیش از این تعداد ⇒ سفارش تحویل نمی‌شود و به صفِ بازبینیِ دستی می‌رود (هیچ پولی خرج نمی‌شود).</small></label>

      <label class="set-f">نام‌سرورهای پیش‌فرضِ دامنه
        <input type="text" name="domain_nameservers" dir="ltr" value="{{ $cloud['dns'] }}"
               placeholder="{{ implode(',', (array) config('services.openprovider.nameservers')) }}">
        <small>با کاما جدا کنید. دستِ‌کم دو تا لازم است، وگرنه ثبتِ دامنه به صفِ دستی می‌رود.</small></label>

      <label class="set-chk full">
        <input type="checkbox" name="cloud_traffic_unlimited" value="1" @checked($cloud['unlimited'])>
        <span>ترافیک را «نامحدود» نشان بده
          <small>⚠️ وعدهٔ تجاری است نه توصیف فنی: سقف واقعی زیرساخت سر جایش می‌مانَد و مصرف بیش از آن هزینه‌اش با ماست.</small></span>
      </label>

      <label class="set-chk full">
        <input type="checkbox" name="aeza_include_promo" value="1" @checked($cloud['promo'])>
        <span>پلن‌های تشویقی (PROMO) هم فروخته شوند
          <small>پیش‌فرض <b>خاموش</b>. قیمتشان واقعاً پایین ولی <b>موقت</b> است؛ نرخِ تمدید بالاتر می‌رود و چون قیمتِ مشتری سرِ سفارش قفل می‌شود، از دورهٔ دوم هر تمدید ضررِ خالص است.</small></span>
      </label>
    </div>
  </div>

  @include('admin.settings._save')
</form>

{{-- آزمونِ اتصال و همگام‌سازی — **بیرونِ** فرمِ تنظیمات، چون عملیاتِ جداگانه‌اند
     و نباید ذخیرهٔ تنظیمات را به تماسِ شبکه گره بزنند. --}}
<div class="ad-panel">
  <div class="ad-panel-h"><h3>کاتالوگِ سرورِ ابری</h3></div>
  <p class="set-lead">
    اول توکن‌ها را ذخیره کنید، بعد <b>آزمونِ اتصال</b> بزنید و در پایان
    <b>همگام‌سازی</b> تا پلن‌ها و مکان‌ها و سیستم‌عامل‌ها خوانده شوند.
    همگام‌سازی خودکار هم <b>دو روز یک‌بار</b> انجام می‌شود.
  </p>
  <div style="padding:2px 18px 16px;display:flex;gap:10px;flex-wrap:wrap">
    <form method="post" action="/admin/cloud/test" style="display:inline">
      @csrf<button class="btn btn-glass" style="font-size:12.5px"><svg class="icon"><use href="#i-server"/></svg>آزمونِ اتصال</button>
    </form>
    <form method="post" action="/admin/cloud/sync" style="display:inline"
          data-confirm="کاتالوگ از زیرساخت‌ها خوانده و قیمت‌ها بازمحاسبه شود؟ چند ده ثانیه طول می‌کشد.">
      @csrf<button class="btn btn-primary" style="font-size:12.5px"><svg class="icon"><use href="#i-restore"/></svg>همگام‌سازیِ کاتالوگ</button>
    </form>
    <form method="post" action="/admin/cloud/sync" style="display:inline">
      @csrf<input type="hidden" name="prices_only" value="1">
      <button class="btn btn-glass" style="font-size:12.5px"><svg class="icon"><use href="#i-coins"/></svg>فقط بازمحاسبهٔ قیمت</button>
    </form>
  </div>
  <p style="padding:0 18px 16px;color:var(--muted);font-size:12.5px;line-height:1.9">
    اگر قیمت‌های زیرساختِ ۲ غیرعادی به‌نظر رسیدند،
    <a href="/admin/cloud/probe" style="color:#22d3ee">ساختارِ خامِ پاسخ</a>
    عددِ خام را کنارِ قیمتِ تفسیرشده نشان می‌دهد تا با فاکتورِ خودتان مقایسه کنید.
  </p>
</div>
