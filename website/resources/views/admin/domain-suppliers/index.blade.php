@extends('admin.layout')
@section('title', __('domain-suppliers.title'))
@section('nav_domain_suppliers', 'on')
@section('content')
<div class="ad-panel" style="padding:20px;margin-bottom:20px">
  <h1>{{ __('domain-suppliers.title') }}</h1>
  <p>{{ __('domain-suppliers.intro') }}</p>
  <p>{{ __('domain-suppliers.approval_note') }}</p>
  <p>{{ __('domain-suppliers.authorization_mode') }}: {{ config('domain_suppliers.authorization_mode', 'pilot') }}</p>
  <p><strong>{{ __('domain-suppliers.feature') }}: {{ $enabled ? __('domain-suppliers.on') : __('domain-suppliers.off') }}</strong></p>
  @if($errors->any())<div role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
</div>
@if(!$installed)
  <div class="ad-panel" style="padding:20px">{{ __('domain-suppliers.not_installed') }}</div>
@else
  <section class="ad-panel" style="padding:20px;margin-bottom:20px;overflow:auto" id="supplier-comparison">
    <h2>{{ __('domain-suppliers.comparison') }}</h2>
    <p>{{ __('domain-suppliers.comparison_note') }}</p>
    <form method="post" action="{{ url('/admin/domain-suppliers/catalogue/refresh') }}" style="margin-bottom:12px">@csrf
      <button class="btn" type="submit">{{ __('domain-suppliers.refresh_catalogue') }}</button>
    </form>
    @if(!$comparison)
      <p>{{ __('domain-suppliers.comparison_empty') }}</p>
    @else
      @php
        $cmpAccounts = $comparison['accounts'];
        $cmpRows = $comparison['rows'];
        $popularRows = [];
        foreach ($popular as $popularTld) {
            if (isset($cmpRows[$popularTld])) $popularRows[$popularTld] = $cmpRows[$popularTld];
        }
        $cmpTables = [
            ['title' => __('domain-suppliers.popular_tlds'), 'rows' => $popularRows, 'open' => true],
            ['title' => __('domain-suppliers.all_tlds'), 'rows' => $cmpRows, 'open' => false],
        ];
      @endphp
      <p>{{ __('domain-suppliers.fetched_at') }}: <span dir="ltr">{{ $comparison['fetched_at'] }}</span></p>
      @if($comparison['stale'])<p role="alert"><strong>{{ __('domain-suppliers.comparison_stale') }}</strong></p>@endif
      <dl style="display:grid;grid-template-columns:max-content 1fr;gap:8px 20px">
        <dt>{{ __('domain-suppliers.compared_count') }}</dt><dd>{{ number_format($comparison['summary']['compared']) }}</dd>
        @foreach($cmpAccounts as $cmpId => $cmpAccount)
          <dt>{{ $cmpAccount['label'] }} <small dir="ltr">{{ $cmpAccount['provider'] }}</small></dt>
          <dd>
            {{ __('domain-suppliers.wins') }}: {{ number_format($comparison['summary']['wins'][$cmpId] ?? 0) }} ·
            {{ __('domain-suppliers.only_here') }}: {{ number_format($comparison['summary']['only'][$cmpId] ?? 0) }} ·
            {{ __('domain-suppliers.covered_count') }}: {{ number_format($comparison['summary']['covered'][$cmpId] ?? 0) }}
            @unless($cmpAccount['current'])<br><strong>{{ __('domain-suppliers.comparison_account_changed') }}</strong>@endunless
          </dd>
        @endforeach
      </dl>
      @foreach($cmpTables as $cmpTable)
        <details style="margin-top:12px" @if($cmpTable['open']) open @endif>
          <summary>{{ $cmpTable['title'] }} ({{ number_format(count($cmpTable['rows'])) }})</summary>
          <table style="width:100%;min-width:720px;text-align:start">
            <thead><tr>
              <th>{{ __('domain-suppliers.tld') }}</th>
              @foreach($cmpAccounts as $cmpAccount)<th>{{ $cmpAccount['label'] }}</th>@endforeach
              <th>{{ __('domain-suppliers.cheapest') }}</th>
              <th>{{ __('domain-suppliers.spread') }}</th>
            </tr></thead>
            <tbody>
            @foreach($cmpTable['rows'] as $cmpTld => $cmpRow)
              <tr>
                <td dir="ltr">.{{ $cmpTld }}</td>
                @foreach($cmpAccounts as $cmpId => $cmpAccount)
                  @php $cmpOffer = $cmpRow['offers'][$cmpId] ?? null; @endphp
                  <td @if($cmpRow['winner'] === $cmpId && count($cmpRow['offers']) > 1) style="font-weight:700" @endif>
                    @if($cmpOffer)
                      {{ number_format($cmpOffer['wholesale_toman']) }} {{ __('domain-suppliers.toman') }}
                      <br><small dir="ltr">{{ $cmpOffer['amount'] }}</small>
                      @if($cmpOffer['promotion'])<br><small>{{ __('domain-suppliers.promotion') }}</small>@endif
                      @unless($cmpOffer['covered'])<br><small>{{ __('domain-suppliers.not_covered') }}</small>@endunless
                    @else
                      —
                    @endif
                  </td>
                @endforeach
                <td>{{ $cmpAccounts[$cmpRow['winner']]['label'] ?? '—' }}</td>
                <td>{{ $cmpRow['spread_toman'] === null ? '—' : number_format($cmpRow['spread_toman']).' '.__('domain-suppliers.toman') }}</td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </details>
      @endforeach
    @endif
  </section>
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
          <td dir="ltr">{{ number_format((int) data_get($operation->quote_snapshot, 'amount_minor', data_get($operation->quote_snapshot, 'purchase_minor', 0))) }} {{ data_get($operation->quote_snapshot, 'currency', '') }} {{ __('domain-suppliers.minor_units') }}
            @if(is_int(data_get($operation->quote_snapshot, 'effective_margin_units')))
              <p>{{ __('domain-suppliers.common_profit') }}: {{ number_format(data_get($operation->quote_snapshot, 'effective_margin_units') / 10000, 4) }}%</p>
              <p>{{ __('domain-suppliers.cost_floor_retail') }}:
                {{ number_format((int) data_get($operation->quote_snapshot, 'wholesale_toman')) }} /
                {{ number_format((int) data_get($operation->quote_snapshot, 'floor_toman')) }} /
                {{ number_format((int) data_get($operation->quote_snapshot, 'sell_toman')) }} IRT</p>
            @endif
          </td>
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
