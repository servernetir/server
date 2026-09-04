@php
  /** @var \App\Models\Server|null $server */
  $isEdit = $server !== null;
  /* ⚠️ این فهرست دستی است و باید با Server::TYPES بخواند. نوعی که این‌جا نیاید
     در فرم قابلِ انتخاب نیست — هرچند اعتبارسنجیِ کنترلر قبولش کند. */
  $types = ['whm'=>'WHM / cPanel (خودکار)','directadmin'=>'DirectAdmin (خودکار)','hetzner_storage'=>'فضای بکاپ — Hetzner Storage Box (خودکار)','plesk'=>'Plesk (دستی)','vps'=>'VPS (دستی)','dedicated'=>'سرور اختصاصی (دستی)','generic'=>'عمومی (دستی)'];
  /*
  | ⚠️ فیلدهای هزینه فقط وقتی نشان داده می‌شوند که ستونشان واقعاً باشد.
  |
  | مهاجرتِ پروداکشن دستی اجرا می‌شود؛ در آن پنجره کنترلر مقدارِ ارسالی را
  | بی‌صدا دور می‌ریزد (وگرنه SQL می‌ترکید). یعنی مدیر اجاره را وارد می‌کرد،
  | «ذخیره شد» می‌گرفت و عدد غیب می‌شد — همان شکستِ خاموشی که این پروژه
  | بارها از آن ضربه خورده. پس یا فیلد هست و کار می‌کند، یا اصلاً نیست.
  */
  $costReady = \Illuminate\Support\Facades\Schema::hasTable('servers')
      && \Illuminate\Support\Facades\Schema::hasColumn('servers', 'monthly_cost');
@endphp
<form method="post" action="{{ $action }}" class="srv-f">
  @csrf
  <label>نام نمایشی
    <input type="text" name="name" value="{{ old('name', $server->name ?? '') }}" required maxlength="80" placeholder="WHM-DE-01">
  </label>
  <label>نوع
    <select name="type">
      @foreach($types as $v => $t)
        <option value="{{ $v }}" @selected(old('type', $server->type ?? 'whm') === $v)>{{ $t }}</option>
      @endforeach
    </select>
  </label>
  {{-- مکان: مشتری در لحظهٔ خرید همین را انتخاب می‌کند. بدونِ کشور، این سرور در
       فهرستِ انتخابِ مشتری نمی‌آید. --}}
  <label>کشور (محلِ سرور)
    <select name="country">
      <option value="">— انتخاب نشده (در خرید نمایش داده نمی‌شود) —</option>
      @foreach(config('billing.locations', []) as $code => $loc)
        <option value="{{ $code }}" @selected(old('country', $server->country ?? '') === $code)>{{ ($loc['flag'] ?? '').' '.($loc['label']['fa'] ?? $code) }}</option>
      @endforeach
    </select>
  </label>
  <label>شهر (نمایشی)
    <input type="text" name="city" value="{{ old('city', $server->city ?? '') }}" maxlength="60" placeholder="تهران / فرانکفورت">
  </label>
  <label>میزبان (hostname)
    <input type="text" name="hostname" dir="ltr" value="{{ old('hostname', $server->hostname ?? '') }}" maxlength="190" placeholder="server1.servernet.cloud">
  </label>
  <label>پورت API
    <input type="number" name="port" dir="ltr" value="{{ old('port', $server->port ?? '') }}" min="1" max="65535" placeholder="۲۰۸۷ برای WHM">
  </label>
  <label>کاربر API
    <input type="text" name="username" dir="ltr" value="{{ old('username', $server->username ?? 'root') }}" maxlength="60" placeholder="root">
  </label>
  <label>توکن API
    <input type="password" name="api_token" dir="ltr" autocomplete="new-password" maxlength="400"
           placeholder="{{ $isEdit ? 'برای تغییر، توکن جدید بزنید' : 'توکن WHM (Manage API Tokens)' }}">
  </label>
  <label>IP سرور (اختیاری)
    <input type="text" name="server_ip" dir="ltr" value="{{ old('server_ip', $server->server_ip ?? '') }}" maxlength="45" placeholder="برای IP اختصاصی">
  </label>
  <label>نیم‌سرورها (اختیاری)
    <input type="text" name="nameservers" dir="ltr" value="{{ old('nameservers', $server->nameservers ?? '') }}" maxlength="190" placeholder="ns1.x,ns2.x">
  </label>
  <label>وضعیت
    <select name="status">
      @foreach(['active'=>'فعال','maintenance'=>'تعمیر','full'=>'پر'] as $v => $t)
        <option value="{{ $v }}" @selected(old('status', $server->status ?? 'active') === $v)>{{ $t }}</option>
      @endforeach
    </select>
  </label>
  <label>سقف حساب (اختیاری)
    <input type="number" name="max_accounts" dir="ltr" value="{{ old('max_accounts', $server->max_accounts ?? '') }}" min="0" placeholder="ظرفیت">
  </label>
  @if($costReady)
  {{-- ══ بهایِ اجاره ══
       بدونِ این چهار فیلد، بزرگ‌ترین هزینهٔ جاریِ شرکت هیچ‌جای سامانه نیست و
       «سودِ خالص» در صفحهٔ مالی یعنی درآمد منهای چیزی که یادتان مانده وارد
       کنید. خالی گذاشتن مجاز است و معنایش «نمی‌دانم» است، نه «رایگان». --}}
  <label>اجارهٔ ماهانه (اختیاری)
    <input type="number" name="monthly_cost" dir="ltr" min="0"
           value="{{ old('monthly_cost', $server?->monthly_cost ?? '') }}"
           placeholder="خالی = نامشخص · ۰ = رایگان">
  </label>
  <label>ارزِ اجاره
    <select name="cost_currency">
      @foreach(['EUR' => 'یورو (سنت وارد کنید: ۳۹۹۰ = ۳۹٫۹۰ €)', 'IRT' => 'تومان', 'USD' => 'دلار (سنت)'] as $v => $t)
        <option value="{{ $v }}" @selected(old('cost_currency', $server->cost_currency ?? 'EUR') === $v)>{{ $t }}</option>
      @endforeach
    </select>
  </label>
  <label>روزِ صورت‌حساب (۱ تا ۲۸)
    <input type="number" name="billing_day" dir="ltr" min="1" max="28"
           value="{{ old('billing_day', $server?->billing_day ?? '') }}" placeholder="مثلاً ۵">
  </label>
  <label>تأمین‌کننده (داخلی)
    <input type="text" name="vendor" value="{{ old('vendor', $server->vendor ?? '') }}" maxlength="60"
           placeholder="در هیچ صفحهٔ عمومی نمایش داده نمی‌شود">
  </label>

  @endif

  <label class="chk col2">
    <input type="checkbox" name="verify_tls" value="1" @checked(old('verify_tls', $server->verify_tls ?? true))>
    بررسیِ گواهیِ TLS (برای گواهیِ self-signed خاموش کنید)
  </label>
  <label class="col2">یادداشت (اختیاری)
    <input type="text" name="note" value="{{ old('note', $server->note ?? '') }}" maxlength="1000">
  </label>
  <div class="col2" style="display:flex;justify-content:flex-end">
    <button type="submit" class="btn btn-primary"><svg class="icon"><use href="#i-check"/></svg>{{ $isEdit ? 'ذخیرهٔ تغییرات' : 'افزودن سرور' }}</button>
  </div>
</form>
