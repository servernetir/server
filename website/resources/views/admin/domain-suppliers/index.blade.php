@extends('admin.layout')
@section('title', __('domain-suppliers.title'))
@section('nav_domain_suppliers', 'on')
@section('content')
<div class="ad-panel" style="padding:20px;margin-bottom:20px">
  <h1>{{ __('domain-suppliers.title') }}</h1>
  <p>{{ __('domain-suppliers.intro') }}</p>
  <p>{{ __('domain-suppliers.approval_note') }}</p>
  <p><strong>{{ __('domain-suppliers.feature') }}: {{ $enabled ? __('domain-suppliers.on') : __('domain-suppliers.off') }}</strong></p>
  @if($errors->any())<div role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
</div>
@if(!$installed)
  <div class="ad-panel" style="padding:20px">{{ __('domain-suppliers.not_installed') }}</div>
@else
  <section class="ad-panel" style="padding:20px;margin-bottom:20px;overflow:auto">
    <h2>{{ __('domain-suppliers.operations') }}</h2>
    <p>{{ __('domain-suppliers.operation_note') }}</p>
    <table style="width:100%;min-width:720px;text-align:start">
      <thead><tr><th>{{ __('domain-suppliers.domain') }}</th><th>{{ __('domain-suppliers.account_invoice') }}</th><th>{{ __('domain-suppliers.operation') }}</th><th>{{ __('domain-suppliers.maximum_cost') }}</th><th>{{ __('domain-suppliers.status_label') }}</th></tr></thead>
      <tbody>
      @forelse($operations as $operation)
        <tr>
          <td dir="ltr">{{ $operation->fqdn }}<br>{{ $operation->domain?->customer?->code }}</td>
          <td>{{ $operation->account?->label }}<br><span dir="ltr">{{ $operation->invoice?->number }}</span></td>
          <td>{{ __('domain-suppliers.operation_names.'.$operation->operation) }} · {{ $operation->period_months }} {{ __('domain-suppliers.months') }}</td>
          <td dir="ltr">{{ number_format((int) data_get($operation->quote_snapshot, 'amount_minor', data_get($operation->quote_snapshot, 'purchase_minor', 0))) }} {{ data_get($operation->quote_snapshot, 'currency', '') }} {{ __('domain-suppliers.minor_units') }}</td>
          <td>
            {{ __('domain-suppliers.operation_status.'.$operation->status) }}
            @if($operation->status === 'prepared' && $canApprove && in_array($operation->operation, ['create', 'renew', 'transfer', 'restore'], true))
              @if(data_get($operation->request_context, 'approval.actor_id'))
                <p>{{ __('domain-suppliers.operation_approved') }}</p>
              @else
                <form method="post" action="{{ url('/admin/domain-suppliers/operations/'.$operation->id.'/approve') }}">@csrf
                  <input type="hidden" name="request_hash" value="{{ $operation->request_hash }}">
                  <button type="submit" class="btn">{{ __('domain-suppliers.approve_operation') }}</button>
                </form>
              @endif
            @endif
            @if($canApprove && in_array($operation->status, ['prepared', 'manual_review'], true) && !$operation->sent_at && in_array($operation->operation, ['create', 'renew', 'transfer', 'restore'], true))
              <form method="post" action="{{ url('/admin/domain-suppliers/operations/'.$operation->id.'/refresh-quote') }}">@csrf
                <button type="submit" class="btn">{{ __('domain-suppliers.refresh_quote') }}</button>
              </form>
            @endif
          </td>
        </tr>
      @empty<tr><td colspan="5">{{ __('domain-suppliers.no_operations') }}</td></tr>@endforelse
      </tbody>
    </table>
  </section>
  @foreach($accounts as $account)
  <section class="ad-panel" style="padding:20px;margin-bottom:20px">
    <h2>{{ $account->label }} <small dir="ltr">{{ $account->provider }} · {{ $account->environment }}</small></h2>
    <dl style="display:grid;grid-template-columns:max-content 1fr;gap:8px 20px">
      <dt>{{ __('domain-suppliers.connection') }}</dt><dd>{{ __('domain-suppliers.status.'.$account->connection_status) }}</dd>
      <dt>{{ __('domain-suppliers.balance') }}</dt><dd dir="ltr">{{ $account->balance_minor === null ? __('domain-suppliers.unknown') : number_format($account->balance_minor).' '.$account->balance_currency.' '.__('domain-suppliers.minor_units') }}</dd>
      <dt>{{ __('domain-suppliers.last_sync') }}</dt><dd dir="ltr">{{ $account->synced_at?->toIso8601String() ?? __('domain-suppliers.unknown') }}</dd>
      <dt>{{ __('domain-suppliers.sales') }}</dt><dd>{{ $account->saleEligible() ? __('domain-suppliers.on') : __('domain-suppliers.off') }}</dd>
    </dl>
    @if($account->readinessIssues() !== [])
      <p>{{ __('domain-suppliers.readiness') }}:
        @foreach($account->readinessIssues() as $issue)<span>{{ __('domain-suppliers.reasons.'.$issue) }}@unless($loop->last) · @endunless</span>@endforeach
      </p>
    @endif
    <details><summary>{{ __('domain-suppliers.edit') }}</summary>
      @include('admin.domain-suppliers.form', ['account' => $account])
    </details>
    @if($canApprove)
    <details style="margin-top:12px"><summary>{{ __('domain-suppliers.contact_title') }}</summary>
      <p>{{ __('domain-suppliers.contact_note') }}</p>
      <form method="post" action="{{ url('/admin/domain-suppliers/'.$account->id.'/contacts') }}" style="display:grid;gap:12px;max-width:700px">
        @csrf
        <label>{{ __('domain-suppliers.profile_id') }}<input name="customer_profile_id" type="number" min="1" required></label>
        <label>{{ __('domain-suppliers.contact_handle') }}<input name="handle" dir="ltr" maxlength="128" required></label>
        <label>{{ __('domain-suppliers.contact_evidence') }}<input name="evidence" minlength="10" maxlength="500" required></label>
        <label><input type="checkbox" name="verified" value="1" required> {{ __('domain-suppliers.contact_verified') }}</label>
        <button class="btn" type="submit">{{ __('domain-suppliers.contact_record') }}</button>
      </form>
    </details>
    @endif
    <div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:16px">
      <form method="post" action="{{ url('/admin/domain-suppliers/'.$account->id.'/probe') }}">@csrf
        <button class="btn" type="submit">{{ __('domain-suppliers.probe') }}</button>
      </form>
      <form method="post" action="{{ url('/admin/domain-suppliers/'.$account->id.'/sales') }}">@csrf
        <input type="hidden" name="enabled" value="{{ $account->sales_enabled ? '0' : '1' }}">
        <button class="btn" type="submit" @disabled(!$account->sales_enabled && $account->readinessIssues() !== [])>{{ $account->sales_enabled ? __('domain-suppliers.disable_sales') : __('domain-suppliers.enable_sales') }}</button>
      </form>
    </div>
  </section>
  @endforeach
  <section class="ad-panel" style="padding:20px;margin-bottom:20px">
    <h2>{{ __('domain-suppliers.add') }}</h2>
    @include('admin.domain-suppliers.form', ['account' => null])
  </section>
@endif
@endsection
