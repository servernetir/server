@extends('admin.layout')
@section('title', 'دروازهٔ AI — ویرایشِ ارائه‌دهنده')
@section('nav_ai_providers', 'on')
@section('content')

<div class="ad-panel">
  <div class="ad-panel-h">
    <h2>ویرایشِ ارائه‌دهنده: <span dir="ltr">{{ $provider->slug }}</span></h2>
    <a href="/admin/ai" class="btn btn-glass" style="font-size:13px">بازگشت</a>
  </div>

  @if(session('ok'))
    <p style="padding:6px 18px;color:#34d399;font-size:13px">{{ session('ok') }}</p>
  @endif

  <form method="post" action="/admin/ai/providers/{{ $provider->id }}" style="padding:14px 18px;display:grid;gap:12px;max-width:720px">
    @csrf
    <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">
      نامِ نمایشی
      <input type="text" name="name" value="{{ old('name', $provider->name) }}" class="ad-input" style="padding:8px">
    </label>

    <div style="display:flex;flex-wrap:wrap;gap:18px">
      @foreach(['enabled' => 'فعالِ فنی', 'commercial_enabled' => 'فروش روشن', 'resale_allowed' => 'اجازهٔ فروشِ دوباره', 'live_calls_enabled' => 'تماسِ زنده (فقط برای تست)'] as $flag => $label)
        <label style="display:flex;gap:6px;align-items:center;font-size:13px">
          <input type="checkbox" name="{{ $flag }}" value="1" @checked(old($flag, $provider->{$flag}))>
          {{ $label }}
        </label>
      @endforeach
    </div>

    <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">
      وضعیتِ توافق
      <select name="agreement_status" class="ad-input" style="padding:8px">
        @foreach(['none' => 'بدون توافق', 'requested' => 'درخواست‌شده', 'signed' => 'امضاشده'] as $val => $label)
          <option value="{{ $val }}" @selected(old('agreement_status', $provider->agreement_status) === $val)>{{ $label }}</option>
        @endforeach
      </select>
    </label>

    @if($hasFeeColumn)
    {{-- خالی = NULL = «این ارائه‌دهنده فروختنی نیست». صفر یک ادعای صریح است. --}}
    <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">
      سربارِ ارز (٪) — هزینهٔ واقعیِ رساندنِ یک {{ $provider->billing_currency_code }} به این ارائه‌دهنده
      <input type="text" inputmode="decimal" name="fx_fee_pct" dir="ltr" maxlength="5"
             value="{{ old('fx_fee_pct', \App\Services\Ai\AiPricing::bpToPercent($provider->fx_fee_bp)) }}"
             placeholder="خالی = فروختنی نیست" class="ad-input" style="padding:8px">
      <small style="color:var(--dim)">کارمزدِ کارت/رمزارز + اسپردِ صرافی + مالیاتِ خارجیِ روی فاکتور. ۰ تا ۲۵، تا دو رقمِ اعشار. حاشیهٔ سود روی بهایِ به‌علاوهٔ همین سربار می‌نشیند.</small>
      @error('fx_fee_pct')<small style="color:#f87171">{{ $message }}</small>@enderror
    </label>
    @else
      <p style="color:#fbbf24;font-size:12.5px">ستونِ سربارِ ارز هنوز ساخته نشده (مهاجرتِ 2026_11_03_000050). تا آن زمان هیچ مدلی از این ارائه‌دهنده فروختنی نیست.</p>
    @endif

    {{-- اتصال: کلید فقط‌نوشتنی است و هرگز به فرم برنمی‌گردد (Setting::putSecret) --}}
    <fieldset style="border:1px solid var(--line, rgba(148,163,184,.2));border-radius:10px;padding:10px 14px;display:grid;gap:10px">
      <legend style="font-size:12.5px;color:var(--muted);padding:0 6px">اتصال به ارائه‌دهنده</legend>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">
        کلیدِ API
        <input type="password" name="api_key" autocomplete="new-password" dir="ltr" maxlength="400"
               placeholder="{{ $hasKey ? 'ثبت شده — برای عوض کردن، کلیدِ تازه را وارد کنید' : 'ثبت نشده' }}" class="ad-input" style="padding:8px">
        <small style="color:var(--dim)">رمزنگاری‌شده ذخیره می‌شود و دیگر نمایش داده نمی‌شود. خالی = بی‌تغییر.</small>
      </label>
      @if($hasKey)
        <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;color:#f87171">
          <input type="checkbox" name="forget_key" value="1"> حذفِ کلیدِ ثبت‌شده
        </label>
      @endif
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">
        آدرسِ پایهٔ API (سازگار با OpenAI)
        <input type="url" name="base_url" dir="ltr" maxlength="255" value="{{ old('base_url', $baseUrl) }}"
               placeholder="https://api.deepinfra.com/v1/openai" class="ad-input" style="padding:8px">
      </label>
      <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">
        درایور
        <select name="driver" class="ad-input" style="padding:8px" dir="ltr">
          @foreach(\App\Models\AiProvider::DRIVERS as $d)
            <option value="{{ $d }}" @selected($provider->driver === $d)>{{ $d }}</option>
          @endforeach
          @unless(in_array($provider->driver, \App\Models\AiProvider::DRIVERS, true))
            <option value="" selected>{{ $provider->driver }} (ناشناخته — تماس‌ها رد می‌شوند)</option>
          @endunless
        </select>
      </label>
    </fieldset>

    @if($hasM5Columns)
    <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">
      سقفِ روزانهٔ هزینه نزدِ ارائه‌دهنده ({{ $provider->billing_currency_code }})
      <input type="text" inputmode="decimal" name="daily_cost_cap_usd" dir="ltr" maxlength="12"
             value="{{ old('daily_cost_cap_usd', $provider->daily_cost_cap_micro ? rtrim(rtrim(sprintf('%d.%06d', intdiv($provider->daily_cost_cap_micro, 1000000), $provider->daily_cost_cap_micro % 1000000), '0'), '.') : '') }}"
             placeholder="خالی = بی‌سقف" class="ad-input" style="padding:8px">
      <small style="color:var(--dim)">اگر بهای مصرفِ امروز به این عدد برسد، تماس‌های تازه تا فردا رد می‌شوند — ترمزِ خطای قیمت یا سوءاستفاده.</small>
    </label>
      @if($provider->paused_at)
        <div style="padding:10px 12px;border-radius:10px;background:rgba(248,113,113,.08);color:#f87171;font-size:13px">
          متوقف از {{ $provider->paused_at->diffForHumans() }}: {{ $provider->paused_reason }}
          <label style="display:flex;gap:6px;align-items:center;margin-top:6px;color:var(--text)">
            <input type="checkbox" name="unpause" value="1"> برداشتنِ توقف (پس از درست کردنِ کلید/اعتبار)
          </label>
        </div>
      @endif
    @endif

    <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">
      اولویت (کوچک‌تر جلوتر)
      <input type="number" name="priority" min="0" max="65535" value="{{ old('priority', $provider->priority) }}" class="ad-input" style="padding:8px">
    </label>

    <label style="display:flex;flex-direction:column;gap:4px;font-size:12.5px;color:var(--muted)">
      یادداشت
      <textarea name="notes" rows="3" class="ad-input">{{ old('notes', $provider->notes) }}</textarea>
    </label>

    <div>
      <button class="btn btn-primary">ثبت</button>
    </div>
  </form>
</div>
@endsection
