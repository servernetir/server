<form method="post" action="{{ url('/admin/domain-suppliers'.($account ? '/'.$account->id : '')) }}" style="display:grid;gap:12px;max-width:800px;margin-top:16px" autocomplete="off">
  @csrf
  <label>{{ __('domain-suppliers.label') }}<input name="label" value="{{ $account?->label }}" maxlength="100" required></label>
  <label>{{ __('domain-suppliers.provider') }}
    <select name="provider" required>
      @foreach(['openprovider' => 'OpenProvider', 'joker' => 'Joker.com'] as $provider => $name)<option value="{{ $provider }}" @selected($account?->provider === $provider)>{{ $name }}</option>@endforeach
    </select>
  </label>
  <label>{{ __('domain-suppliers.environment') }}
    <select name="environment" required><option value="ote" @selected(!$account || $account->environment === 'ote')>{{ __('domain-suppliers.ote') }}</option><option value="production" @selected($account?->environment === 'production')>{{ __('domain-suppliers.production') }}</option></select>
  </label>
  <p>{{ __('domain-suppliers.secret_note') }}</p>
  @if($account?->legacy_config)
    <p>{{ __('domain-suppliers.legacy_credentials') }}</p>
  @else
    <label>{{ __('domain-suppliers.username') }}<input name="username" autocomplete="off" dir="ltr"></label>
    <label>{{ __('domain-suppliers.password') }}<input name="password" type="password" autocomplete="new-password" dir="ltr"></label>
    <label>{{ __('domain-suppliers.api_key') }}<input name="api_key" type="password" autocomplete="new-password" dir="ltr"></label>
  @endif
  <label>{{ __('domain-suppliers.supplier_vat_pct') }}<input name="supplier_vat_pct" type="number" min="0" max="100" step="0.001" value="{{ $account?->settings['supplier_vat_pct'] ?? '' }}"></label>
  <label>{{ __('domain-suppliers.supplier_fee_pct') }}<input name="supplier_fee_pct" type="number" min="0" max="100" step="0.001" value="{{ $account?->settings['supplier_fee_pct'] ?? '' }}"></label>
  <p>{{ __('domain-suppliers.capability_note') }}</p>
  <label>{{ __('domain-suppliers.capabilities') }}<textarea name="capabilities" rows="8" dir="ltr" maxlength="50000">{{ json_encode((array) $account?->capabilities, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</textarea></label>
  <label><input type="checkbox" name="active" value="1" @checked(!$account || $account->active)> {{ __('domain-suppliers.active') }}</label>
  <p>{{ __('domain-suppliers.verification_note') }}</p>
  @foreach(['contract_verified', 'identity_verified', 'pricing_verified'] as $flag)
    <label><input type="checkbox" name="{{ $flag }}" value="1" @checked($account?->{$flag})> {{ __('domain-suppliers.'.$flag) }}</label>
  @endforeach
  <button class="btn" type="submit">{{ __('domain-suppliers.save') }}</button>
</form>
