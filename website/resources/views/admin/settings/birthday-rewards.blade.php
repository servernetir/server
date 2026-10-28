@php
  $birthdaySchema = \Illuminate\Support\Facades\Schema::hasTable('birthday_rewards');
  $birthdayEnabled = \App\Models\Setting::get('birthday_offer_enabled', '0') === '1';
  $birthdayPatternReady = app(\App\Services\Birthday\BirthdayMessages::class)->configured();
@endphp
<section class="ad-panel" id="birthday-rewards">
  <div class="ad-panel-h"><h2>{{ __('birthday.admin_title') }}</h2></div>
  <div style="padding:0 18px 18px">
    <p class="set-lead">{{ __('birthday.admin_help') }}</p>
    @if(!$birthdaySchema)<p role="status">{{ __('birthday.missing_schema') }}</p>@endif
    <form method="post" action="/admin/settings/birthday-rewards">
      @csrf
      <input type="hidden" name="enabled" value="0">
      <label class="set-f"><input type="checkbox" name="enabled" value="1" @checked($birthdayEnabled) @disabled(!$birthdaySchema)> {{ __('birthday.enable') }}</label>
      <input type="hidden" name="sms_enabled" value="0">
      <label style="display:block;margin:14px 0"><input type="checkbox" name="sms_enabled" value="1" @checked(\App\Models\Setting::get('birthday_sms_enabled', '0') === '1') @disabled(!$birthdaySchema || !$birthdayPatternReady)> {{ __('birthday.sms_enable') }}</label>
      <p role="status">{{ __($birthdayPatternReady ? 'birthday.pattern_ready' : 'birthday.pattern_pending') }}</p>
      <button class="btn btn-primary" type="submit" @disabled(!$birthdaySchema)>{{ __('birthday.save') }}</button>
    </form>
    <details style="margin-top:16px"><summary>{{ __('birthday.pending_copy') }}</summary><p style="white-space:pre-line">{{ config('birthday_rewards.message') }}</p></details>
  </div>
</section>
<section class="ad-panel" id="birthday-costs">
  <div class="ad-panel-h"><h2>{{ __('birthday.cost_title') }}</h2></div>
  <div style="padding:0 18px 18px">
    <p class="set-lead">{{ __('birthday.cost_help') }}</p>
    @if(\Illuminate\Support\Facades\Schema::hasTable('birthday_product_costs'))
      <p>{{ __('birthday.cost_rows') }}: {{ \App\Models\BirthdayProductCost::where('valid_until', '>', now())->count() }}</p>
      <div style="overflow:auto;margin:14px 0">
        <table style="width:100%;font-size:13px">
          <thead><tr><th>{{ __('birthday.product') }}</th><th>{{ __('birthday.server') }}</th><th>{{ __('birthday.cycle') }}</th><th>{{ __('birthday.component') }}</th><th>{{ __('birthday.amount') }}</th><th>{{ __('birthday.reference') }}</th><th>{{ __('birthday.valid_until') }}</th></tr></thead>
          <tbody>
          @foreach(\App\Models\BirthdayProductCost::orderByDesc('id')->limit(50)->get() as $birthdayCostRow)
            <tr>
              <td>{{ $birthdayCostRow->product_id }}</td><td>{{ $birthdayCostRow->server_id ?: '—' }}</td>
              <td>{{ \App\Models\Service::labelFor($birthdayCostRow->cycle) }}</td>
              <td>{{ __('birthday.'.$birthdayCostRow->component) }}</td>
              <td dir="ltr">{{ number_format($birthdayCostRow->amount / ($birthdayCostRow->currency_code === 'IRT' ? 1 : 100), $birthdayCostRow->currency_code === 'IRT' ? 0 : 2) }} {{ $birthdayCostRow->currency_code }}</td>
              <td>{{ $birthdayCostRow->reference }}</td>
              <td>{{ blog_date($birthdayCostRow->valid_until->copy()->setTimezone('Asia/Tehran')->toDateString()) }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      <form method="post" action="/admin/settings/birthday-costs" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px">
        @csrf
        <label class="set-f">{{ __('birthday.product') }}<select name="product_id" required >
          @foreach(\App\Models\Product::where('is_active', true)->orderBy('id')->get() as $birthdayProduct)<option value="{{ $birthdayProduct->id }}">{{ $birthdayProduct->id }} — {{ $birthdayProduct->name }}</option>@endforeach
        </select></label>
        <label class="set-f">{{ __('birthday.server') }}<input  name="server_id" type="number" min="1"></label>
        <label class="set-f">{{ __('birthday.cycle') }}<select  name="cycle" required>@foreach(\App\Models\Service::cycles() as $birthdayCycle)<option value="{{ $birthdayCycle }}">{{ \App\Models\Service::labelFor($birthdayCycle) }}</option>@endforeach</select></label>
        <label class="set-f">{{ __('birthday.component') }}<select  name="component"><option value="service">{{ __('birthday.service') }}</option><option value="setup">{{ __('birthday.setup') }}</option></select></label>
        <label class="set-f">{{ __('birthday.currency') }}<select  name="currency_code">@foreach(['IRT','EUR','USD','TRY'] as $birthdayCurrency)<option>{{ $birthdayCurrency }}</option>@endforeach</select></label>
        <label class="set-f">{{ __('birthday.amount') }}<input  name="amount" inputmode="decimal" required></label>
        <label class="set-f">{{ __('birthday.reference') }}<input  name="reference" maxlength="255" required></label>
        <label class="set-f">{{ __('birthday.valid_days') }}<input  name="valid_days" type="number" min="1" max="365" value="30" required></label>
        <button class="btn btn-primary" type="submit">{{ __('birthday.save_cost') }}</button>
      </form>
    @endif
  </div>
</section>
