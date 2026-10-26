<style>
/* ══ Compact infrastructure settings accordion — native details, no layout script ══ */
.set-infra-list{ display:grid; gap:9px; margin-block:0 20px; min-width:0 }
.set-infra-item{ min-width:0; border:1px solid var(--line); border-radius:13px; background:var(--surface); overflow:clip }
.set-infra-summary{ display:flex; align-items:center; gap:12px; min-height:64px; padding:13px 17px;
  color:var(--text); cursor:pointer; list-style:none; border-radius:12px; transition:background-color .16s,border-color .16s }
.set-infra-summary::-webkit-details-marker{ display:none }
.set-infra-summary::marker{ content:'' }
.set-infra-summary::after{ content:''; direction:ltr; width:7px; height:7px; border-inline-end:2px solid var(--dim);
  border-block-end:2px solid var(--dim); transform:rotate(45deg); flex:none; margin-inline-start:2px;
  transition:transform .16s,border-color .16s }
.set-infra-summary:hover{ background:var(--surface2) }
.set-infra-summary:focus-visible{ outline:2px solid var(--cyan); outline-offset:-3px }
.set-infra-item[open]>.set-infra-summary{ border-end-start-radius:0; border-end-end-radius:0 }
.set-infra-item[open]>.set-infra-summary::after{ transform:rotate(225deg); border-color:var(--cyan) }
.set-infra-name{ display:flex; align-items:center; gap:11px; min-width:0; flex:1; font-size:13.5px; font-weight:700; line-height:1.7 }
.set-infra-name b{ min-width:0; overflow-wrap:anywhere }
.set-infra-name small{ display:block; color:var(--muted); font-size:11.5px; font-weight:400; line-height:1.65 }
.set-infra-number{ display:inline-grid; place-items:center; width:29px; height:29px; flex:none;
  border:1px solid var(--line2); border-radius:9px; background:var(--surface2); color:var(--muted);
  font-size:12px; font-weight:700; font-variant-numeric:tabular-nums }
.set-infra-status{ display:inline-flex; align-items:center; justify-content:center; gap:6px; flex:none;
  max-inline-size:45%; padding:4px 9px; border:1px solid currentColor; border-radius:999px;
  background:var(--surface2); font-size:11.5px; font-weight:600; line-height:1.65; text-align:center }
.set-infra-status::before{ content:''; display:block; width:6px; height:6px; flex:none;
  border-radius:50%; background:currentColor }
.set-infra-status[data-state="active"]{ color:var(--green) }
.set-infra-status[data-state="inactive"]{ color:var(--red) }
.set-infra-status[data-state="review"]{ color:var(--amber) }
html[data-theme="light"] .set-infra-status[data-state="active"]{ color:color-mix(in srgb,var(--green) 38%,var(--text)) }
html[data-theme="light"] .set-infra-status[data-state="inactive"]{ color:color-mix(in srgb,var(--red) 58%,var(--text)) }
html[data-theme="light"] .set-infra-status[data-state="review"]{ color:color-mix(in srgb,var(--amber) 35%,var(--text)) }
.set-infra-body{ min-width:0; padding:18px; border-block-start:1px solid var(--line) }
.set-infra-body>.ad-panel:last-child{ margin-block-end:0 }
.set-infra-body>.set-box{ margin-inline:0 }
.set-infra-body .set-lead{ padding-inline:0 }
.set-infra-caption{ margin:0 0 15px; color:var(--muted); font-size:12px; line-height:1.9 }
.set-infra-tools{ display:flex; align-items:center; gap:9px; flex-wrap:wrap; margin-block:12px }
.set-infra-tools form{ margin:0 }
@media(max-width:600px){
  .set-infra-list{ gap:7px }
  .set-infra-summary{ gap:8px; min-height:62px; padding:11px 12px }
  .set-infra-name{ gap:8px; font-size:12.5px }
  .set-infra-number{ width:25px; height:25px; border-radius:8px; font-size:11px }
  .set-infra-status{ gap:5px; padding:3px 7px; font-size:10.5px; max-inline-size:43% }
  .set-infra-body{ padding:14px 12px }
  .set-infra-tools{ align-items:stretch }
}
@media(prefers-reduced-motion:reduce){
  .set-infra-summary,.set-infra-summary::after{ transition:none }
}
/* ══ End compact infrastructure settings accordion ══ */
</style>

{{-- Provider credentials share one settings form; VPS.TC keeps its own protected forms. --}}
<form id="infra-settings-form" method="post" action="/admin/settings">
  @csrf
  <input type="hidden" name="tab" value="infra">
</form>

@if(session('success'))<div class="ad-flash ok" role="status">{{ session('success') }}</div>@endif
@if(is_array(session('vpstc_report')))<div class="ad-flash ok" role="status">گزارش بررسی VPS.TC آماده است؛ برای دیدن نتیجه، ردیف زیرساخت ۹ را باز کنید.</div>@endif

<p class="set-infra-caption">زیرساخت‌ها به‌ترتیب شماره نمایش داده می‌شوند. هر ردیف را برای تنظیم اتصال باز کنید؛ وضعیت سبز فقط پس از آزمون اتصال موفق و تازه نمایش داده می‌شود.</p>
<div class="set-infra-list" data-infra-accordion>
  <details class="set-infra-item" id="infra-hetzner" data-provider="hetzner">
    @include('admin.settings._infra-summary', ['infraStatus' => $infraStatuses['hetzner']])
    <div class="set-infra-body">
      <p class="set-infra-caption">{{ $infraStatuses['hetzner']['detail'] }}</p>
      <div class="set-grid"><label class="set-f">زیرساختِ ۱ — API Token
        @if($cloud['hetzner'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">ذخیره‌شده</span>@endif
        <input form="infra-settings-form" type="password" name="hetzner_api_token" dir="ltr" autocomplete="new-password" maxlength="300"
               placeholder="{{ $cloud['hetzner'] ? '••••••••••  خالی = بدونِ تغییر' : 'توکن را این‌جا بچسبانید' }}"></label></div>
@if($cloud['hetzner'])<label class="set-danger"><input form="infra-settings-form" type="checkbox" name="hetzner_forget" value="1">حذف کلید ذخیره‌شده</label>@endif
    </div>
  </details>
  <details class="set-infra-item" id="infra-aeza" data-provider="aeza">
    @include('admin.settings._infra-summary', ['infraStatus' => $infraStatuses['aeza']])
    <div class="set-infra-body">
      <p class="set-infra-caption">{{ $infraStatuses['aeza']['detail'] }}</p>
      <div class="set-grid"><label class="set-f">زیرساختِ ۲ — API Key
        @if($cloud['aeza'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">ذخیره‌شده</span>@endif
        <input form="infra-settings-form" type="password" name="aeza_api_token" dir="ltr" autocomplete="new-password" maxlength="300"
               placeholder="{{ $cloud['aeza'] ? '••••••••••  خالی = بدونِ تغییر' : 'کلید را این‌جا بچسبانید' }}"></label></div>
@if($cloud['aeza'])<label class="set-danger"><input form="infra-settings-form" type="checkbox" name="aeza_forget" value="1">حذف کلید ذخیره‌شده</label>@endif
    </div>
  </details>
  <details class="set-infra-item" id="infra-arvan" data-provider="arvan">
    @include('admin.settings._infra-summary', ['infraStatus' => $infraStatuses['arvan']])
    <div class="set-infra-body">
      <p class="set-infra-caption">{{ $infraStatuses['arvan']['detail'] }}</p>
      <div class="set-grid"><label class="set-f full">زیرساختِ ۳ — API Key <span style="color:var(--dim)">(ایرانی)</span>
        @if($cloud['arvan'])<span class="ad-badge" style="background:rgba(52,211,153,.12);color:#34d399">ذخیره‌شده</span>@endif
        <input form="infra-settings-form" type="password" name="arvan_api_token" dir="ltr" autocomplete="new-password" maxlength="400"
               placeholder="{{ $cloud['arvan'] ? '••••••••••  خالی = بدونِ تغییر' : 'کلید را با پیشوندِ Apikey بچسبانید' }}"></label></div>
@if($cloud['arvan'])<label class="set-danger"><input form="infra-settings-form" type="checkbox" name="arvan_forget" value="1">حذف کلید ذخیره‌شده</label>@endif
    </div>
  </details>
  <details class="set-infra-item" id="infra-ovh" data-provider="ovh">
    @include('admin.settings._infra-summary', ['infraStatus' => $infraStatuses['ovh']])
    <div class="set-infra-body">
      <p class="set-infra-caption">{{ $infraStatuses['ovh']['detail'] }}</p>
      @php
        // نشانیِ ساختِ کلید **منطقه‌ای** است، مثلِ خودِ کلیدها. نشان‌دادنِ نشانیِ
        // اروپا به حسابِ آمریکایی یعنی کاربر کلیدی می‌سازد که هرگز کار نمی‌کند
        // و خطایش هم نمی‌گوید چرا.
        $ovhTokenUrl = [
            'eu' => 'eu.api.ovh.com/createToken/',
            'ca' => 'ca.api.ovh.com/createToken/',
            'us' => 'api.us.ovhcloud.com/createToken/',
        ][$cloud['ovh_region'] ?? 'eu'];
      @endphp
      <p>
        این زیرساخت سه کلید می‌خواهد. اول <b>منطقه</b> را درست انتخاب کنید، بعد هر سه کلید را از
        <span dir="ltr">{{ $ovhTokenUrl }}</span>
        بسازید و دسترسی‌های <span dir="ltr">GET /me</span> و <span dir="ltr">GET/POST/PUT /vps*</span> را بدهید.
        <br>⚠️ در فرم OVH گزینهٔ <span dir="ltr">Validity</span> پیش‌فرض <span dir="ltr">1 day</span> است؛
        اگر روی <span dir="ltr">Unlimited</span> نگذارید، اتصال فردا بی‌هیچ خبری قطع می‌شود.
        <br>⚠️ <b>خرید خودکار هنوز فعال نیست</b> — سفارش در OVH از سبد خرید چندمرحله‌ای می‌گذرد
        و تا وقتی روی حساب واقعی آزمایش نشده، سفارش‌ها به صف تحویل دستی می‌روند.
        مدیریت سرورهای موجود (روشن/خاموش/نصب دوباره) کامل کار می‌کند.
      </p>
      <div class="set-grid">
        <label class="set-f">منطقهٔ حساب
          <select form="infra-settings-form" name="ovh_region" dir="ltr">
            <option value="eu" @selected(($cloud['ovh_region'] ?? 'eu') === 'eu')>ovh-eu — ovh.com (اروپا)</option>
            <option value="ca" @selected(($cloud['ovh_region'] ?? 'eu') === 'ca')>ovh-ca — ca.ovh.com (کانادا)</option>
            <option value="us" @selected(($cloud['ovh_region'] ?? 'eu') === 'us')>ovh-us — us.ovhcloud.com (آمریکا)</option>
          </select></label>
      </div>
      <div class="set-grid three">
        <label class="set-f">Application Key
          <input form="infra-settings-form" type="password" name="ovh_app_key" dir="ltr" autocomplete="new-password" maxlength="200"
                 placeholder="{{ $cloud['ovh'] ? '••••••••  خالی = بدونِ تغییر' : 'AK' }}"></label>
        <label class="set-f">Application Secret
          <input form="infra-settings-form" type="password" name="ovh_app_secret" dir="ltr" autocomplete="new-password" maxlength="200"
                 placeholder="{{ $cloud['ovh'] ? '••••••••  خالی = بدونِ تغییر' : 'AS' }}"></label>
        <label class="set-f">Consumer Key
          <input form="infra-settings-form" type="password" name="ovh_consumer_key" dir="ltr" autocomplete="new-password" maxlength="200"
                 placeholder="{{ $cloud['ovh'] ? '••••••••  خالی = بدونِ تغییر' : 'CK' }}"></label>
      </div>
      @if($cloud['ovh'])
        <label class="set-danger"><input form="infra-settings-form" type="checkbox" name="ovh_forget" value="1"> هر سه کلید را فراموش کن</label>
      @endif
    </div>
  </details>
  <details class="set-infra-item" id="infra-proxmox" data-provider="proxmox">
    @include('admin.settings._infra-summary', ['infraStatus' => $infraStatuses['proxmox']])
    <div class="set-infra-body">
      <p class="set-infra-caption">{{ $infraStatuses['proxmox']['detail'] }}</p>
      <p>
        میزبانِ Proxmoxِ خودمان برای «Exit VPS». توکن را در پنلِ Proxmox زیرِ
        <span dir="ltr">Datacenter → Permissions → API Tokens</span> بسازید.
        مقادیرِ خالی به پیش‌فرضِ درایور برمی‌گردند.
      </p>
      <div class="set-grid">
        <label class="set-f">API URL
          <input form="infra-settings-form" type="text" name="proxmox_api_url" dir="ltr" maxlength="200"
                 value="{{ $cloud['px']['api_url'] }}" placeholder="https://85.9.108.118:8006/api2/json"></label>
        <label class="set-f">Node
          <input form="infra-settings-form" type="text" name="proxmox_node" dir="ltr" maxlength="64"
                 value="{{ $cloud['px']['node'] }}" placeholder="ir"></label>
        <label class="set-f">Token ID
          <input form="infra-settings-form" type="text" name="proxmox_token_id" dir="ltr" maxlength="120"
                 value="{{ $cloud['px']['token_id'] }}" placeholder="svc-controller@pve!provisioner"></label>
        <label class="set-f">Token Secret
          <input form="infra-settings-form" type="password" name="proxmox_token_secret" dir="ltr" autocomplete="new-password" maxlength="200"
                 placeholder="{{ $cloud['proxmox'] ? '••••••••  خالی = بدونِ تغییر' : 'UUIDِ توکن' }}"></label>
        <label class="set-f">Template VMID
          <input form="infra-settings-form" type="number" name="proxmox_template_vmid" dir="ltr" min="1"
                 value="{{ $cloud['px']['template'] }}" placeholder="9002"></label>
        <label class="set-f">Storage
          <input form="infra-settings-form" type="text" name="proxmox_storage" dir="ltr" maxlength="64"
                 value="{{ $cloud['px']['storage'] }}" placeholder="vmstoreid"></label>
        <label class="set-f">Bridge
          <input form="infra-settings-form" type="text" name="proxmox_bridge" dir="ltr" maxlength="32"
                 value="{{ $cloud['px']['bridge'] }}" placeholder="vmbr1"></label>
        <label class="set-f">Gateway
          <input form="infra-settings-form" type="text" name="proxmox_gateway" dir="ltr" maxlength="45"
                 value="{{ $cloud['px']['gateway'] }}" placeholder="10.10.10.1"></label>
        <label class="set-f">IP شروع
          <input form="infra-settings-form" type="text" name="proxmox_ip_start" dir="ltr" maxlength="45"
                 value="{{ $cloud['px']['ip_start'] }}" placeholder="10.10.10.60"></label>
        <label class="set-f">کشورهای خروج (Exit VPS) — جدا با کاما
          <input form="infra-settings-form" type="text" name="proxmox_exit_countries" dir="ltr" maxlength="200"
                 value="{{ $cloud['exit_countries'] }}" placeholder="de,nl,fi (پیش‌فرض)"></label>
        {{-- 🔴 بی‌این دو، ماشینِ پشتِ NAT هیچ آدرسی برای دادن به مشتری ندارد:
             پورت‌فوروارد ساخته می‌شود ولی کسی نمی‌داند روی کدام IP. --}}
        <label class="set-f">IP عمومیِ میزبانِ ایران (برای پورت‌فوروارد)
          <input form="infra-settings-form" type="text" name="public_ip" dir="ltr" maxlength="45"
                 value="{{ $cloud['public_ip'] }}" placeholder="85.9.108.118"></label>
        <label class="set-f">نامِ دامنه به‌جای IP (اختیاری — پورت را حذف نمی‌کند)
          <input form="infra-settings-form" type="text" name="public_host" dir="ltr" maxlength="120"
                 value="{{ $cloud['public_host'] }}" placeholder="ir1.servernet.cloud"></label>
      </div>
      @if($cloud['proxmox'])
        <label class="set-danger"><input form="infra-settings-form" type="checkbox" name="proxmox_forget" value="1"> توکن را فراموش کن</label>
      @endif
    </div>
  </details>
  <details class="set-infra-item" id="infra-salad" data-provider="salad">
    @include('admin.settings._infra-summary', ['infraStatus' => $infraStatuses['salad']])
    <div class="set-infra-body">
      <p class="set-infra-caption">{{ $infraStatuses['salad']['detail'] }}</p>
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
          <input form="infra-settings-form" type="password" name="salad_api_key" dir="ltr" autocomplete="new-password" maxlength="300"
                 placeholder="{{ $cloud['salad'] ? '••••••••••  خالی = بدونِ تغییر' : 'کلید را این‌جا بچسبانید' }}"></label>
        <label class="set-f">نامِ سازمان
          <input form="infra-settings-form" type="text" name="salad_org" dir="ltr" maxlength="120"
                 value="{{ $cloud['sl']['org'] }}" placeholder="organization name"></label>
        <label class="set-f">نامِ پروژه
          <input form="infra-settings-form" type="text" name="salad_project" dir="ltr" maxlength="120"
                 value="{{ $cloud['sl']['project'] }}" placeholder="default"></label>

        {{-- 🔴 بی‌این، تحویل عمداً انجام **نمی‌شود**: کانتینری که بالا بیاید و
             مشتری راهی به داخلش نداشته باشد، از تحویل‌نشدن بدتر است. --}}
        <label class="set-f full">ایمیجِ کانتینر <span style="color:var(--dim)">(بدونِ آن تحویل انجام نمی‌شود)</span>
          <input form="infra-settings-form" type="text" name="salad_image" dir="ltr" maxlength="200"
                 value="{{ $cloud['sl']['image'] }}" placeholder="registry/image:tag — باید SSH و SSH_PUBLIC_KEY را بشناسد"></label>

        <label class="set-f">اولویت
          <select form="infra-settings-form" name="salad_priority">
            @foreach(['high' => 'بالا (گران‌تر، کم‌ترین قطعی)', 'medium' => 'متوسط', 'low' => 'پایین', 'batch' => 'کمینه (ارزان‌ترین، بیشترین قطعی)'] as $k => $lbl)
              <option value="{{ $k }}" @selected(($cloud['sl']['priority'] ?: 'high') === $k)>{{ $lbl }}</option>
            @endforeach
          </select></label>
      <label class="set-f">دامنهٔ برندشدهٔ دروازه <span style="color:var(--dim)">(اختیاری)</span>
        <input form="infra-settings-form" type="text" name="salad_branded_domain" dir="ltr" maxlength="120"
               value="{{ $cloud['sl']['branded'] ?? '' }}" placeholder="servernet.cloud">
        <small style="color:var(--dim)">نشانیِ مشتری g-xxxx.{دامنه} می‌شود — نیازمندِ Workerِ Cloudflare (راهنما در relay/cloudflare)</small>
      </label>
      <label class="set-f full" style="flex-direction:row;align-items:center;gap:8px">
        <input form="infra-settings-form" type="hidden" name="salad_jupyter_sales_paused" value="0">
        <input form="infra-settings-form" type="checkbox" name="salad_jupyter_sales_paused" value="1" @checked($cloud['sl']['jupyter_sales_paused'] ?? false)>
        <span>توقف موقت فروش جدید Jupyter تا تأیید اتصال اجرای کد؛ سرویس‌های موجود تغییر نمی‌کنند.</span>
      </label>
      @foreach(['ollama' => 'Ollama', 'comfyui' => 'ComfyUI'] as $gpuApp => $gpuAppLabel)
      <label class="set-f full" style="flex-direction:row;align-items:center;gap:8px">
        <input form="infra-settings-form" type="hidden" name="salad_{{ $gpuApp }}_sales_paused" value="0">
        <input form="infra-settings-form" type="checkbox" name="salad_{{ $gpuApp }}_sales_paused" value="1" @checked($cloud['sl'][$gpuApp.'_sales_paused'] ?? false)>
        <span>توقف موقت فروش جدید {{ $gpuAppLabel }} تا تأیید خروجی واقعی؛ سرویس‌های موجود تغییر نمی‌کنند.</span>
      </label>
      @endforeach
      <label class="set-f">رازِ دروازهٔ برندشده (Gateway secret)
        <input form="infra-settings-form" type="password" name="salad_gateway_secret" dir="ltr" autocomplete="new-password" maxlength="200"
               placeholder="باید با GATE_SECRET در Worker یکی باشد — خالی = بدونِ تغییر">
        <small style="color:var(--dim)">توکنِ دسترسیِ هر ماشین از این راز ساخته می‌شود؛ بی‌آن، دروازه باز است</small>
      </label>

        <label class="set-f">نرخ قدیمی CPU — در محاسبه GPU استفاده نمی‌شود
          <input form="infra-settings-form" type="text" name="salad_vcpu_usd_hour" dir="ltr" disabled
                 value="{{ $cloud['sl']['vcpu'] }}"></label>
        <label class="set-f">نرخ قدیمی RAM — در محاسبه GPU استفاده نمی‌شود
          <input form="infra-settings-form" type="text" name="salad_ram_gb_usd_hour" dir="ltr" disabled
                 value="{{ $cloud['sl']['ram'] }}">
          <small>طبق تعرفهٔ Salad، CPU و RAM در قیمت کارت GPU لحاظ شده‌اند.</small></label>

        <label class="set-f full" style="flex-direction:row;align-items:center;gap:8px">
          <input form="infra-settings-form" type="checkbox" name="salad_forget" value="1">
          <span>کلید را فراموش کن <span style="color:var(--dim)">(سازمان و پروژه می‌مانند)</span></span></label>
      </div>
    </div>
  </details>
  <details class="set-infra-item" id="infra-hetzner-robot" data-provider="hetzner-robot">
    @include('admin.settings._infra-summary', ['infraStatus' => $infraStatuses['hetzner-robot']])
    <div class="set-infra-body">
      <p class="set-infra-caption">{{ $infraStatuses['hetzner-robot']['detail'] }}</p>
      <p>
        کاربرِ webservice را در پنلِ Robot (<span dir="ltr">Settings → Webservice and app settings</span>)
        بسازید. بعد از ذخیره، <b>cloud:sync</b> کاتالوگِ سرورهای اختصاصی، مزایده و GPU اختصاصی را
        می‌کشد و پلن‌ها با همان زنجیرهٔ «بها + کارمزد + حاشیه» قیمت می‌خورند.
        <br>⚠️ <b>خرید خودکار فعال نیست</b> — سفارشِ مشتری به صفِ تحویلِ دستی می‌رود و
        شما در پنلِ زیرساخت نهایی‌اش می‌کنید. محصولاتِ دارای هزینهٔ نصب عمداً وارد کاتالوگ نمی‌شوند.
      </p>
      <div class="set-grid">
        <label class="set-f">کاربرِ Webservice
          <input form="infra-settings-form" type="password" name="hetzner_robot_user" dir="ltr" autocomplete="new-password" maxlength="120"
                 placeholder="{{ $cloud['hetzner_robot'] ? '••••••••  خالی = بدونِ تغییر' : '#ws+XXXXXX' }}"></label>
        <label class="set-f">رمزِ Webservice
          <input form="infra-settings-form" type="password" name="hetzner_robot_pass" dir="ltr" autocomplete="new-password" maxlength="200"
                 placeholder="{{ $cloud['hetzner_robot'] ? '••••••••  خالی = بدونِ تغییر' : 'رمز را این‌جا بچسبانید' }}"></label>
      </div>
      @if($cloud['hetzner_robot'])
        <label class="set-danger"><input form="infra-settings-form" type="checkbox" name="hetzner_robot_forget" value="1"> کاربر و رمز را فراموش کن</label>
      @endif
    </div>
  </details>
  <details class="set-infra-item" id="infra-lightnode" data-provider="lightnode">
    @include('admin.settings._infra-summary', ['infraStatus' => $infraStatuses['lightnode']])
    <div class="set-infra-body">
      <p class="set-infra-caption">{{ $infraStatuses['lightnode']['detail'] }}</p>
      <p class="set-lead">فروش با پهنای‌باند ثابت، قیمت تازهٔ LightNode و سود فعلی سایت انجام می‌شود. استفاده از مدل مشترک مناطق با تأیید مالک، جدا از آزمون واقعی هر پلن ثبت می‌شود؛ تحویل پس از تطبیق پاسخ واقعی زیرساخت است. خاموشی، هزینه را متوقف نمی‌کند.</p>
    <div class="set-grid" style="padding:0 18px 18px">
      <label class="set-f">کلید API
        <input form="infra-settings-form" type="password" name="lightnode_api_token" dir="ltr" autocomplete="new-password" maxlength="1000"
          placeholder="{{ $cloud['lightnode'] ? 'ذخیره‌شده؛ خالی = بدون تغییر' : 'کلید API تأییدشدهٔ حساب' }}"></label>
      @if($cloud['lightnode'])<label class="set-danger"><input form="infra-settings-form" type="checkbox" name="lightnode_forget" value="1">حذف کلید ذخیره‌شده</label>@endif
      <label class="set-f full">نگاشت مناطق و مشخصات تأییدشدهٔ LightNode
        <textarea form="infra-settings-form" name="lightnode_manifest" dir="ltr" rows="8">{{ $cloud['lightnode_manifest'] }}</textarea>
        <span class="set-lead">قیمت از محاسبه‌گر عمومی LightNode دریافت و سود فعلی سایت اعمال می‌شود. این بخش فقط برای نگاشت مناطق و سری پردازندهٔ پلن‌های تأییدشده است؛ نرخ دستی مبنای فروش نیست. کلید API و رمز سرور را اینجا وارد نکنید.</span>
      </label>
    </div>
    </div>
  </details>
  <details class="set-infra-item" id="infra-vpstc" data-provider="vpstc">
    @include('admin.settings._infra-summary', ['infraStatus' => $infraStatuses['vpstc']])
    <div class="set-infra-body">
      <p class="set-infra-caption">{{ $infraStatuses['vpstc']['detail'] }}</p>
      @include('admin.partials.vpstc-settings-panel', $vpstcPanel)
    </div>
  </details>
</div>

<p class="set-infra-caption">DNS، دسترسی زیرساخت داخلی و سیاست‌های مشترک</p>
<div class="set-infra-list">
  <details class="set-infra-item" id="infra-cloudflare">
    <summary class="set-infra-summary"><span class="set-infra-name"><b>Cloudflare — DNS و CDN</b></span><span class="set-infra-status" data-state="{{ $cloud['cloudflare'] ? 'review' : 'inactive' }}">{{ $cloud['cloudflare'] ? 'نیاز به بررسی' : 'غیرفعال' }}</span></summary>
    <div class="set-infra-body"><p class="set-lead">
      وقتی مشتری «زیردامنهٔ رایگان» می‌گیرد، پس از تحویل رکوردِ <b>A</b> آن خودکار
      به IPِ سرور ست می‌شود (بدونِ این، سایتش بالا نمی‌آید چون nameserverها روی
      Cloudflare است). در Cloudflare یک <b>API Token</b> بسازید با دسترسیِ
      <b dir="ltr">Zone → DNS → Edit</b> و فقط برای zoneِ
      <b dir="ltr">{{ config('servernet.subdomain_zone') }}</b>.
    </p>
    <div class="set-grid" style="padding:0 18px 18px">
      <label class="set-f">API Token @if($cloud['cloudflare'])<span style="color:var(--dim)">(خالی = بدونِ تغییر)</span>@endif
        <input form="infra-settings-form" type="password" name="cloudflare_token" dir="ltr" autocomplete="new-password" maxlength="200"
               placeholder="{{ $cloud['cloudflare'] ? '••••••••••  ذخیره‌شده' : 'توکن را این‌جا بچسبانید' }}"></label>
      <label class="set-f">Zone ID <span style="color:var(--dim)">(اختیاری)</span>
        <input form="infra-settings-form" type="text" name="cloudflare_zone_id" dir="ltr" maxlength="64"
               value="{{ $cloud['cf_zone'] }}" placeholder="خالی = خودکار پیدا می‌شود"></label>
      @if($cloud['cloudflare'])
        <label class="set-danger full"><input form="infra-settings-form" type="checkbox" name="cloudflare_forget" value="1"> حذفِ توکنِ ذخیره‌شده</label>
      @endif
    </div></div>
  </details>
  <details class="set-infra-item" id="infra-agent">
    <summary class="set-infra-summary"><span class="set-infra-name"><b>دسترسی Pull-Agent و Nginx Proxy Manager</b></span><span class="set-infra-status" data-state="{{ $cloud['agent'] && $cloud['npm'] ? 'review' : 'inactive' }}">{{ $cloud['agent'] && $cloud['npm'] ? 'نیاز به بررسی' : 'غیرفعال' }}</span></summary>
    <div class="set-infra-body"><p>
        موتورِ هاستِ ایران با هدرِ <span dir="ltr">X-Agent-Token</span> از
        <span dir="ltr">/agent/countryroutes</span> و <span dir="ltr">/agent/portforwards</span>
        حالتِ مطلوب را می‌خوانَد. همین توکن را در تنظیماتِ ایجنت هم بگذارید.
      </p>
      <label class="set-f">آدرسِ Nginx Proxy Manager
        <input form="infra-settings-form" type="text" name="npm_base_url" dir="ltr" maxlength="200"
               value="{{ $cloud['npm_url'] }}" placeholder="http://10.10.10.7:81"></label>
      <label class="set-f">دامنهٔ پایهٔ سرورها (زیر‌دامنه زیرِ این ساخته می‌شود)
        <input form="infra-settings-form" type="text" name="npm_base_domain" dir="ltr" maxlength="120"
               value="{{ $cloud['npm_domain'] }}" placeholder="servernet.cloud"></label>
      <label class="set-f">ایمیلِ ورودِ NPM
        <input form="infra-settings-form" type="text" name="npm_email" dir="ltr" autocomplete="off" maxlength="190"
               placeholder="{{ $cloud['npm'] ? '••••••••  خالی = بدونِ تغییر' : 'admin@example.com' }}"></label>
      <label class="set-f">رمزِ ورودِ NPM
        <input form="infra-settings-form" type="password" name="npm_password" dir="ltr" autocomplete="new-password" maxlength="200"
               placeholder="{{ $cloud['npm'] ? '••••••••  خالی = بدونِ تغییر' : 'رمزِ پنلِ NPM' }}"></label>
      @if($cloud['npm'])
        <label class="set-danger"><input form="infra-settings-form" type="checkbox" name="npm_forget" value="1"> اعتبارنامهٔ NPM را فراموش کن</label>
      @endif
      <label class="set-f">Agent Token
        <input form="infra-settings-form" type="password" name="agent_pull_token" dir="ltr" autocomplete="new-password" maxlength="200"
               placeholder="{{ $cloud['agent'] ? '••••••••  خالی = بدونِ تغییر' : 'یک رشتهٔ تصادفیِ قوی' }}"></label>
      @if($cloud['agent'])
        <label class="set-danger"><input form="infra-settings-form" type="checkbox" name="agent_forget" value="1"> توکن را فراموش کن</label>
      @endif</div>
  </details>
  <details class="set-infra-item" id="infra-policies">
    <summary class="set-infra-summary"><span class="set-infra-name"><b>سیاست‌های مشترک فروش و تحویل</b></span></summary>
    <div class="set-infra-body"><div class="set-grid" style="padding:0 18px 18px">
      {{-- 🔴 سقفِ محافظِ سوءاستفاده. خالی = «پیش‌فرضِ کد»، نه «بی‌سقف». برای
           **همه** یکسان است؛ معافیتِ حساب عمداً وجود ندارد. --}}
      <label class="set-f">سقفِ سفارشِ سرور در ۲۴ ساعت (هر مشتری)
        <input form="infra-settings-form" type="number" name="cloud_guard_daily_max" dir="ltr" step="1" min="1" max="100"
               value="{{ $cloud['guard'] }}" placeholder="{{ fa_num(\App\Services\Cloud\CloudFraudGuard::DAILY_MAX) }} (پیش‌فرض)">
        <small>بیش از این تعداد ⇒ سفارش تحویل نمی‌شود و به صفِ بازبینیِ دستی می‌رود (هیچ پولی خرج نمی‌شود).</small></label>

      <label class="set-f">نام‌سرورهای پیش‌فرضِ دامنه
        <input form="infra-settings-form" type="text" name="domain_nameservers" dir="ltr" value="{{ $cloud['dns'] }}"
               placeholder="{{ implode(',', (array) config('services.openprovider.nameservers')) }}">
        <small>با کاما جدا کنید. دستِ‌کم دو تا لازم است، وگرنه ثبتِ دامنه به صفِ دستی می‌رود.</small></label>

      <label class="set-chk full">
        <input form="infra-settings-form" type="checkbox" name="cloud_traffic_unlimited" value="1" @checked($cloud['unlimited'])>
        <span>ترافیک را «نامحدود» نشان بده
          <small>⚠️ وعدهٔ تجاری است نه توصیف فنی: سقف واقعی زیرساخت سر جایش می‌مانَد و مصرف بیش از آن هزینه‌اش با ماست.</small></span>
      </label>

      <label class="set-chk full">
        <input form="infra-settings-form" type="checkbox" name="aeza_include_promo" value="1" @checked($cloud['promo'])>
        <span>پلن‌های تشویقی (PROMO) هم فروخته شوند
          <small>پیش‌فرض <b>خاموش</b>. قیمتشان واقعاً پایین ولی <b>موقت</b> است؛ نرخِ تمدید بالاتر می‌رود و چون قیمتِ مشتری سرِ سفارش قفل می‌شود، از دورهٔ دوم هر تمدید ضررِ خالص است.</small></span>
      </label>
    </div></div>
  </details>
  <details class="set-infra-item" id="infra-catalog-tools">
    <summary class="set-infra-summary"><span class="set-infra-name"><b>آزمون اتصال و همگام‌سازی کاتالوگ</b></span></summary>
    <div class="set-infra-body"><p class="set-lead">
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
  </p></div>
  </details>
</div>

@include('admin.settings._save', ['formId' => 'infra-settings-form'])
