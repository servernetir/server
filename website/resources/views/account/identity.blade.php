@extends('panel.layout')
@section('title',__('identity.menu'))
@section('noindex','1')
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/account-api.css') }}">
@endpush
@section('panel')
@php
  $serviceGroups = collect($services)->groupBy('category');
  $financialFilter = is_string(request()->query('financial_status')) ? request()->query('financial_status') : '';
  $activeApplications = $applications->where('status', 'active');
  $selectedCard = $selected ? collect($services)->firstWhere('slug', $selected['slug']) : null;
@endphp
<div class="api-workspace api-workspace--identity identity-account">
  <header class="aw-hero">
    <div>
      <span class="aw-eyebrow">{{ __('identity.workspace_eyebrow') }}</span>
      <h1>{{ __('identity.menu') }}</h1>
      <p class="aw-lead">{{ __('identity.workspace_intro') }}</p>
      <div class="aw-actions">
        <a class="pnl-btn primary aw-btn" href="{{ lroute('account.topup') }}"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>{{ __('identity.topup') }}</a>
        <a class="pnl-btn aw-btn" href="{{ lroute('account.security') }}#sec-api"><svg class="icon" aria-hidden="true"><use href="#i-key"/></svg>{{ __('identity.keys') }}</a>
        <a class="pnl-btn aw-btn" href="{{ lroute('identity.docs') }}"><svg class="icon" aria-hidden="true"><use href="#i-book"/></svg>{{ __('identity.docs') }}</a>
      </div>
    </div>
    <aside class="aw-hero-aside" aria-hidden="true">
      <span class="aw-hero-emblem"><svg class="icon"><use href="#i-shield"/></svg></span>
      <span class="aw-badge">{{ __('identity.workspace_wallet_badge') }}</span>
    </aside>
  </header>

  <div class="aw-stats" role="group" aria-label="{{ __('identity.workspace_wallet_heading') }}">
    <article class="aw-stat aw-stat--accent">
      <span class="aw-stat-icon"><svg class="icon" aria-hidden="true"><use href="#i-coins"/></svg></span>
      <span class="aw-stat-label">{{ __('identity.available_balance') }}</span>
      <strong class="aw-stat-value"><bdi>{{ number_format($balance-$reserved) }}</bdi><small>{{ __('identity.irt') }}</small></strong>
      <span class="aw-stat-note">{{ __('identity.workspace_available_note') }}</span>
    </article>
    <article class="aw-stat">
      <span class="aw-stat-icon"><svg class="icon" aria-hidden="true"><use href="#i-lock"/></svg></span>
      <span class="aw-stat-label">{{ __('identity.reserved') }}</span>
      <strong class="aw-stat-value"><bdi>{{ number_format($reserved) }}</bdi><small>{{ __('identity.irt') }}</small></strong>
      <span class="aw-stat-note">{{ __('identity.workspace_reserved_note') }}</span>
    </article>
    <article class="aw-stat">
      <span class="aw-stat-icon"><svg class="icon" aria-hidden="true"><use href="#i-gauge"/></svg></span>
      <span class="aw-stat-label">{{ __('identity.balance') }}</span>
      <strong class="aw-stat-value"><bdi>{{ number_format($balance) }}</bdi><small>{{ __('identity.irt') }}</small></strong>
      <span class="aw-stat-note">{{ __('identity.workspace_wallet_badge') }}</span>
    </article>
  </div>
  <p class="aw-footnote">{{ __('identity.wallet_only') }}</p>

  @if(session('ok'))
    <div class="aw-alert is-good" role="status"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg><p>{{ session('ok') }}</p></div>
  @endif
  @if(isset($errors) && $errors->any())
    <div class="aw-alert is-warn" role="alert"><svg class="icon" aria-hidden="true"><use href="#i-info"/></svg><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
  @endif

  @if(!$ready)
    <div class="aw-alert is-warn" role="status"><svg class="icon" aria-hidden="true"><use href="#i-info"/></svg><p>{{ __('identity.unavailable') }}</p></div>
  @elseif(!$eligible)
    <div class="aw-alert is-warn" role="status"><svg class="icon" aria-hidden="true"><use href="#i-shield"/></svg><div><p>{{ __('identity.kyc_required') }}</p><a class="pnl-btn aw-btn" href="{{ lroute('account.verify') }}">{{ __('identity.workspace_verify') }}</a></div></div>
  @else
    <section class="aw-card" aria-labelledby="identity-applications-heading">
      <div class="aw-card-head">
        <div><h2 class="aw-card-title" id="identity-applications-heading"><span class="aw-card-icon"><svg class="icon" aria-hidden="true"><use href="#i-box"/></svg></span>{{ __('identity.applications') }}</h2><p>{{ __('identity.workspace_applications_intro') }}</p></div>
        <span class="aw-badge">{{ __('identity.workspace_application_count', ['count' => fa_num($applications->count())]) }}</span>
      </div>
      <div class="aw-card-body aw-grid">
        <div class="aw-card-grid">
          @forelse($applications as $a)
            <article class="aw-mini-card">
              <div class="aw-service-top"><h3>{{ $a->name }}</h3><span class="aw-badge {{ $a->status==='active' ? 'is-good' : 'is-muted' }}">{{ __($a->status==='active' ? 'identity.available' : 'identity.disabled') }}</span></div>
              <form class="aw-inline-form" method="post" action="{{ lroute('account.identity.application',$a) }}">@csrf
                <label class="aw-field" for="identity-app-status-{{ $a->id }}"><span>{{ __('identity.status') }}</span><select name="status" id="identity-app-status-{{ $a->id }}"><option value="active" @selected($a->status==='active')>{{ __('identity.available') }}</option><option value="disabled" @selected($a->status==='disabled')>{{ __('identity.disabled') }}</option></select></label>
                <button class="pnl-btn aw-btn" type="submit">{{ __('identity.save') }}</button>
              </form>
            </article>
          @empty
            <div class="aw-empty"><span class="aw-empty-icon"><svg class="icon" aria-hidden="true"><use href="#i-box"/></svg></span><h3>{{ __('identity.workspace_no_applications') }}</h3><p>{{ __('identity.workspace_no_applications_help') }}</p></div>
          @endforelse
        </div>
        <div class="aw-mini-card">
          <h3>{{ __('identity.workspace_create_application') }}</h3>
          <form method="post" action="{{ lroute('account.identity.activate') }}">@csrf
            <label class="aw-field" for="identity-app-name"><span>{{ __('identity.workspace_application_name') }}</span><input id="identity-app-name" name="name" maxlength="80" required></label>
            <label class="aw-check"><input type="checkbox" name="accept" value="1" required><span>{{ __('identity.accept_terms') }} <a href="{{ lroute('identity.terms') }}">{{ __('identity.terms') }}</a></span></label>
            <div class="aw-form-actions"><button class="pnl-btn primary aw-btn" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>{{ __('identity.create') }}</button></div>
          </form>
        </div>
      </div>
    </section>
  @endif

  <section class="aw-card" aria-labelledby="identity-catalog-heading">
    <div class="aw-card-head"><div><h2 class="aw-card-title" id="identity-catalog-heading"><span class="aw-card-icon"><svg class="icon" aria-hidden="true"><use href="#i-layout"/></svg></span>{{ __('identity.catalog') }}</h2><p>{{ __('identity.workspace_catalog_intro') }}</p></div></div>
    <div class="aw-card-body">
      <form class="aw-inline-form" method="get" action="{{ lroute('account.identity') }}#identity-inquiry">
        <label class="aw-field" for="identity-service-select"><span>{{ __('identity.service') }}</span><select name="service" id="identity-service-select"><option value="">{{ __('identity.workspace_choose_service') }}</option>@foreach($services as $s)<option value="{{ $s['slug'] }}" @selected(($selected['slug']??null)===$s['slug'])>{{ $s['name'] }} — {{ __('identity.'.$s['status']) }}</option>@endforeach</select></label>
        @if($financialFilter)<input type="hidden" name="financial_status" value="{{ $financialFilter }}">@endif
        <button class="pnl-btn aw-btn" type="submit">{{ __('identity.view') }}<svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg></button>
      </form>
      @foreach($serviceGroups as $category=>$categoryServices)
        @php
          $categoryIcon = ['authentication'=>'shield', 'bank'=>'coins', 'utility'=>'globe'][$category] ?? 'code';
          $categoryOpen = $selected ? ($selectedCard['category'] ?? null) === $category : $loop->first;
        @endphp
        <details class="aw-detail aw-catalog-group" @if($categoryOpen) open @endif>
          <summary><span><svg class="icon" aria-hidden="true"><use href="#i-{{ $categoryIcon }}"/></svg>{{ __('identity.category_'.$category) }}</span><span class="aw-badge">{{ fa_num($categoryServices->count()) }}</span><svg class="icon aw-detail-chevron" aria-hidden="true"><use href="#i-chev"/></svg></summary>
          <div class="aw-detail-body">
            <p class="aw-footnote">{{ __('identity.category_'.$category.'_description') }}</p>
            <div class="aw-catalog">
              @foreach($categoryServices as $s)
                <a class="aw-service {{ ($selected['slug']??null)===$s['slug'] ? 'is-selected' : '' }}" href="{{ lroute('account.identity', array_filter(['service'=>$s['slug'], 'financial_status'=>$financialFilter], fn($value)=>$value!==null && $value!=='')) }}#identity-inquiry" @if(($selected['slug']??null)===$s['slug']) aria-current="true" @endif>
                  <div class="aw-service-top"><span class="aw-card-icon"><svg class="icon" aria-hidden="true"><use href="#i-{{ $categoryIcon }}"/></svg></span><span class="aw-badge {{ $s['active'] ? 'is-good' : 'is-muted' }}">{{ __('identity.'.$s['status']) }}</span></div>
                  <h3 class="aw-service-name">{{ $s['name'] }}</h3>
                  <div class="aw-price">@if($s['price']===null)<span>{{ __('identity.price_pending') }}</span>@else<strong><bdi>{{ number_format($s['price']) }}</bdi> <small>{{ __('identity.irt') }}</small></strong><span>{{ __('identity.workspace_price_before_tax') }}</span>@endif</div>
                  <span class="aw-service-link">{{ __('identity.view') }}<svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg></span>
                </a>
              @endforeach
            </div>
          </div>
        </details>
      @endforeach
    </div>
  </section>

  <section class="aw-card" id="identity-inquiry" aria-labelledby="identity-inquiry-heading">
    <div class="aw-card-head"><div><h2 class="aw-card-title" id="identity-inquiry-heading"><span class="aw-card-icon"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg></span>{{ $selected ? __('identity.service_'.$selected['slug']) : __('identity.workspace_inquiry_heading') }}</h2><p>{{ __('identity.workspace_inquiry_intro') }}</p></div>@if($selectedCard)<span class="aw-badge {{ $selectedCard['active'] ? 'is-good' : 'is-muted' }}">{{ __('identity.'.$selectedCard['status']) }}</span>@endif</div>
    <div class="aw-card-body">
      @if($selected && $selectedCard)
        <div class="aw-price"><strong>{{ $selectedCard['price']===null ? __('identity.price_pending') : number_format($selectedCard['price']).' '.__('identity.irt') }}</strong>@if($selectedCard['price']!==null)<span>{{ __('identity.workspace_price_before_tax') }}</span>@endif</div>
        @if($selectedCard['active'] && $checkout && $activeApplications->isNotEmpty())
          <form class="aw-inquiry-form" method="post" action="{{ lroute('account.identity.inquire') }}" autocomplete="off">@csrf
            <input type="hidden" name="service" value="{{ $selected['slug'] }}"><input type="hidden" name="idempotency_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
            <input type="hidden" name="price_version" value="{{ $checkout['price_version'] }}">
            <div class="aw-form-grid">
              <label class="aw-field" for="identity-inquiry-app"><span>{{ __('identity.application') }}</span><select name="application_id" id="identity-inquiry-app">@foreach($activeApplications as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
              @foreach($selected['operations'][0]['input']['properties'] as $field=>$spec)
                @php $inputLabelKey = 'identity.workspace_input_'.$field; @endphp
                <label class="aw-field" for="identity-input-{{ $field }}">
                  <span>{{ \Illuminate\Support\Facades\Lang::has($inputLabelKey) ? __($inputLabelKey) : $field }}</span>
                  @if(\Illuminate\Support\Facades\Lang::has($inputLabelKey))
                    <small><code dir="ltr">{{ $field }}</code></small>
                  @endif
                  @if(($spec['type']??'string')==='boolean')
                    <select name="input[{{ $field }}]" id="identity-input-{{ $field }}"><option value="false">false</option><option value="true">true</option></select>
                  @else
                    <input type="text" id="identity-input-{{ $field }}" name="input[{{ $field }}]" dir="auto" maxlength="{{ $spec['maxLength']??256 }}" @required(in_array($field,$selected['operations'][0]['input']['required']??[],true))>
                  @endif
                </label>
              @endforeach
            </div>
            <div class="identity-checkout" aria-label="{{ __('identity.checkout_summary') }}"><h3>{{ __('identity.checkout_summary') }}</h3><dl><dt>{{ __('identity.preview_net') }}</dt><dd><bdi>{{ number_format($checkout['subtotal_irt']) }}</bdi> {{ __('identity.irt') }}</dd><dt>{{ __('identity.preview_tax') }}</dt><dd><bdi>{{ number_format($checkout['tax_irt']) }}</bdi> {{ __('identity.irt') }}</dd><dt class="aw-checkout-total">{{ __('identity.preview_total') }}</dt><dd class="aw-checkout-total"><strong><bdi>{{ number_format($checkout['total_irt']) }}</bdi> {{ __('identity.irt') }}</strong></dd></dl></div>
            <input type="hidden" name="max_charge_irt" value="{{ $checkout['total_irt'] }}">
            <label class="aw-check aw-charge-confirm"><input type="checkbox" name="real_charge" value="1" required><span>{{ __('identity.real_charge') }}</span></label>
            <div class="aw-form-actions"><button class="pnl-btn primary aw-btn" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>{{ __('identity.execute') }}</button></div>
          </form>
        @elseif($selectedCard['active'] && $checkout && $activeApplications->isEmpty())
          <div class="aw-alert"><svg class="icon" aria-hidden="true"><use href="#i-info"/></svg><p>{{ __('identity.workspace_active_application_needed') }}</p></div>
        @else
          <div class="aw-alert"><svg class="icon" aria-hidden="true"><use href="#i-info"/></svg><p>{{ __('identity.availability_notice') }}</p></div>
        @endif
      @else
        <div class="aw-empty"><span class="aw-empty-icon"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg></span><h3>{{ __('identity.workspace_choose_service') }}</h3><p>{{ __('identity.workspace_choose_service_help') }}</p><a class="pnl-btn aw-btn" href="#identity-catalog-heading">{{ __('identity.catalog') }}</a></div>
      @endif
    </div>
  </section>

  <section class="aw-card aw-sandbox" aria-labelledby="identity-sandbox-heading"><div class="aw-card-body aw-inline-form"><span class="aw-card-icon"><svg class="icon" aria-hidden="true"><use href="#i-code"/></svg></span><div><h2 id="identity-sandbox-heading">{{ __('identity.sandbox') }}</h2><p class="aw-footnote">{{ __('identity.sandbox_help') }}</p></div><form method="post" action="{{ lroute('account.identity.sandbox') }}">@csrf<button class="pnl-btn aw-btn" type="submit">{{ __('identity.workspace_try_sandbox') }}<svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg></button></form></div></section>

  <section class="aw-card aw-history" aria-labelledby="identity-history-heading">
    <div class="aw-card-head"><div><h2 class="aw-card-title" id="identity-history-heading"><span class="aw-card-icon"><svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg></span>{{ __('identity.history') }}</h2><p>{{ __('identity.workspace_history_intro') }}</p></div><span class="aw-badge">{{ __('identity.workspace_request_count', ['count'=>fa_num($history ? $history->total() : 0)]) }}</span></div>
    <div class="aw-card-body">
      <form class="aw-inline-form" method="get" action="{{ lroute('account.identity') }}">
        @if($selected)<input type="hidden" name="service" value="{{ $selected['slug'] }}">@endif
        <label class="aw-field" for="identity-history-finance"><span>{{ __('identity.finance') }}</span><select name="financial_status" id="identity-history-finance"><option value="">{{ __('identity.workspace_all_statuses') }}</option>@foreach(['reserved','captured','released','pending_reconciliation','refunded'] as $s)<option value="{{ $s }}" @selected($financialFilter===$s)>{{ __('identity.state_'.$s) }}</option>@endforeach</select></label><button class="pnl-btn aw-btn" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-filter"/></svg>{{ __('identity.filter') }}</button>
      </form>
      @if($history && $history->isNotEmpty())
        <div class="aw-table-wrap" role="region" aria-label="{{ __('identity.history') }}" tabindex="0">
          <table class="aw-table"><thead><tr>@foreach(['date','service','states','charged','request'] as $h)<th scope="col">{{ __('identity.'.$h) }}</th>@endforeach</tr></thead><tbody>
            @foreach($history as $r)<tr>
              <td data-label="{{ __('identity.date') }}"><time datetime="{{ $r->created_at->toIso8601String() }}">{{ stime($r->created_at) }}</time></td>
              <td data-label="{{ __('identity.service') }}">{{ __('identity.service_'.$r->service) }}</td>
              <td data-label="{{ __('identity.states') }}"><div class="aw-row-stack"><span class="aw-badge is-muted">{{ __('identity.state_'.$r->processing_status) }}</span><span class="aw-badge {{ in_array($r->financial_status,['captured','released','refunded'],true) ? 'is-good' : 'is-warn' }}">{{ __('identity.state_'.$r->financial_status) }}</span>@if($r->inquiry_outcome)<span class="aw-footnote">{{ __('identity.state_'.$r->inquiry_outcome) }}</span>@endif</div></td>
              <td data-label="{{ __('identity.charged') }}"><span class="aw-amount"><bdi>{{ number_format($r->charged_irt) }}</bdi> {{ __('identity.irt') }}</span></td>
              <td data-label="{{ __('identity.request') }}"><a class="aw-code" href="{{ lroute('account.identity.result',$r->public_id) }}"><code dir="ltr">{{ $r->public_id }}</code></a></td>
            </tr>@endforeach
          </tbody></table>
        </div>
        {{ $history->withQueryString()->links() }}
      @else
        <div class="aw-empty"><span class="aw-empty-icon"><svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg></span><h3>{{ __('identity.workspace_no_history') }}</h3><p>{{ __('identity.workspace_no_history_help') }}</p></div>
      @endif
    </div>
  </section>

  <section class="aw-card" aria-labelledby="identity-keys-heading">
    <div class="aw-card-head"><div><h2 class="aw-card-title" id="identity-keys-heading"><span class="aw-card-icon"><svg class="icon" aria-hidden="true"><use href="#i-key"/></svg></span>{{ __('identity.keys') }}</h2><p>{{ __('identity.workspace_keys_intro') }}</p></div><a class="pnl-btn aw-btn" href="{{ lroute('account.security') }}#sec-api">{{ __('identity.workspace_manage_keys') }}<svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg></a></div>
    <div class="aw-card-body aw-card-grid">
      @forelse($keys as $key)
        <article class="aw-mini-card">
          <div class="aw-service-top"><h3>{{ $key->name }}</h3><span class="aw-card-icon"><svg class="icon" aria-hidden="true"><use href="#i-key"/></svg></span></div>
          <p class="aw-footnote">{{ __('identity.workspace_permissions') }}</p>
          <div class="aw-key-scopes">@foreach(array_filter((array)$key->abilities,fn($s)=>str_starts_with($s,'identity:')) as $scope)<code class="aw-badge is-muted" dir="ltr">{{ $scope }}</code>@endforeach</div>
          <dl class="aw-key-meta"><div><dt>{{ __('identity.last_used') }}</dt><dd>{{ $key->last_used_at ? stime($key->last_used_at) : '—' }}</dd></div><div><dt>{{ __('ui.sec_api_expiry') }}</dt><dd>{{ $key->expires_at ? stime($key->expires_at) : '—' }}</dd></div></dl>
          <details class="aw-detail">
            <summary>{{ __('identity.workspace_key_settings') }}<svg class="icon aw-detail-chevron" aria-hidden="true"><use href="#i-chev"/></svg></summary>
            <div class="aw-detail-body">
              <form method="post" action="{{ lroute('account.identity.key-update',$key) }}">@csrf
                <div class="aw-form-grid"><label class="aw-field" for="identity-key-cidrs-{{ $key->id }}"><span>{{ __('ui.sec_api_cidrs') }}</span><input id="identity-key-cidrs-{{ $key->id }}" name="cidrs" maxlength="500" value="{{ implode(', ',(array)$key->allowed_cidrs) }}" dir="ltr"></label><label class="aw-field" for="identity-key-expiry-{{ $key->id }}"><span>{{ __('ui.sec_api_expiry') }}</span><input id="identity-key-expiry-{{ $key->id }}" type="number" name="expires_days" min="1" max="1825"></label></div>
                <div class="aw-form-actions"><button class="pnl-btn aw-btn" type="submit">{{ __('identity.save') }}</button></div>
              </form>
              <div class="aw-divider"></div>
              <form method="post" action="{{ lroute('account.security.token.delete',$key) }}">@csrf<div class="aw-form-actions"><button class="pnl-btn aw-btn aw-btn-danger" type="submit"><svg class="icon" aria-hidden="true"><use href="#i-x"/></svg>{{ __('identity.revoke') }}</button></div></form>
            </div>
          </details>
        </article>
      @empty
        <div class="aw-empty"><span class="aw-empty-icon"><svg class="icon" aria-hidden="true"><use href="#i-key"/></svg></span><h3>{{ __('identity.workspace_no_keys') }}</h3><p>{{ __('identity.workspace_no_keys_help') }}</p></div>
      @endforelse
    </div>
  </section>
</div>
@endsection
