@extends('panel.layout')
@section('title',__('ai.usage').' — ServerNet')
@section('noindex','1')
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/account-api.css') }}">
@endpush
@section('panel')
@php
  // The controller supplies all totals. Do not infer account-wide usage from this page of receipts.
  $projectCount = $projects->count();
  $activeProjectKeys = (int) $projects->where('status', 'active')->sum('active_tokens_count');
  $requestCount = $history->total();
@endphp
<div class="api-workspace api-workspace--ai" data-ai-workspace>
  <header class="aw-hero">
    <div>
      <p class="aw-eyebrow">{{ __('ai.workspace_eyebrow') }}</p>
      <h1>{{ __('ai.usage') }}</h1>
      <p class="aw-lead">{{ __('ai.workspace_lead') }}</p>
      <div class="aw-actions">
        <a class="pnl-btn primary aw-btn" href="{{ lroute('account.security') }}#sec-ai"><svg class="icon" aria-hidden="true"><use href="#i-key"/></svg>{{ __('ai.key') }}</a>
        <a class="pnl-btn aw-btn" href="{{ lroute('account.topup') }}"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>{{ __('ai.topup') }}</a>
      </div>
    </div>
    <div class="aw-hero-art" aria-hidden="true"><svg class="icon"><use href="#i-sparkles"/></svg></div>
  </header>

  <section class="aw-stats" aria-label="{{ __('ai.workspace_overview') }}" data-ai-overview>
    <article class="aw-stat aw-stat--accent">
      <span class="aw-stat-icon" aria-hidden="true"><svg class="icon"><use href="#i-coins"/></svg></span>
      <p class="aw-stat-label">{{ __('ai.month') }}</p>
      <p class="aw-stat-value"><bdi>{{ fa_num(number_format($spent)) }}</bdi></p>
      <p class="aw-stat-note">{{ __('ai.workspace_month_note') }}</p>
    </article>
    <article class="aw-stat">
      <span class="aw-stat-icon" aria-hidden="true"><svg class="icon"><use href="#i-box"/></svg></span>
      <p class="aw-stat-label">{{ __('ai.projects') }}</p>
      <p class="aw-stat-value"><bdi>{{ fa_num(number_format($projectCount)) }}</bdi></p>
      <p class="aw-stat-note">{{ __('ai.workspace_projects_note') }}</p>
    </article>
    <article class="aw-stat">
      <span class="aw-stat-icon" aria-hidden="true"><svg class="icon"><use href="#i-key"/></svg></span>
      <p class="aw-stat-label">{{ __('ai.workspace_active_keys') }}</p>
      <p class="aw-stat-value"><bdi>{{ fa_num(number_format($activeProjectKeys)) }}</bdi></p>
      <p class="aw-stat-note">{{ __('ai.workspace_keys_note') }}</p>
    </article>
    <article class="aw-stat">
      <span class="aw-stat-icon" aria-hidden="true"><svg class="icon"><use href="#i-flow"/></svg></span>
      <p class="aw-stat-label">{{ __('ai.workspace_requests') }}</p>
      <p class="aw-stat-value"><bdi>{{ fa_num(number_format($requestCount)) }}</bdi></p>
      <p class="aw-stat-note">{{ __('ai.workspace_requests_note') }}</p>
    </article>
  </section>

  <div class="aw-grid">
    <section class="aw-card" aria-labelledby="ai-projects-heading">
      <div class="aw-card-head">
        <div>
          <h2 id="ai-projects-heading" class="aw-card-title"><span class="aw-card-icon" aria-hidden="true"><svg class="icon"><use href="#i-box"/></svg></span>{{ __('ai.projects') }}</h2>
          <p class="aw-footnote">{{ __('ai.workspace_projects_intro') }}</p>
        </div>
        <span class="aw-badge is-muted">{{ fa_num(number_format($projectCount)) }}</span>
      </div>
      <div class="aw-card-body">
        @if($projects->isNotEmpty())
          <div class="aw-card-grid" data-ai-project-cards>
            @foreach($projects as $project)
              <article class="aw-mini-card">
                <div class="aw-service-top">
                  <span class="aw-card-icon" aria-hidden="true"><svg class="icon"><use href="#i-code"/></svg></span>
                  <span class="aw-badge {{ $project->status === 'active' ? 'is-good' : ($project->status === 'disabled' ? 'is-warn' : 'is-muted') }}">{{ __('ui.sec_ai_st_'.$project->status) }}</span>
                </div>
                <h3>{{ $project->name }}</h3>
                <dl class="aw-project-meta">
                  <div><dt>{{ __('ai.workspace_project_keys') }}</dt><dd><bdi>{{ fa_num(number_format((int) $project->active_tokens_count)) }}</bdi></dd></div>
                  <div><dt>{{ __('ai.workspace_project_budget') }}</dt><dd>@if($project->budget_period === 'monthly')<bdi>{{ fa_num(number_format((int) $project->monthly_budget_irt)) }}</bdi> {{ __('ai.workspace_currency') }}@else{{ __('ui.sec_ai_budget_none') }}@endif</dd></div>
                </dl>
                <a class="pnl-btn aw-btn" href="{{ lroute('account.security') }}#sec-ai">{{ __('ai.workspace_manage_project') }}<svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg></a>
              </article>
            @endforeach
          </div>
        @else
          <div class="aw-empty">
            <span class="aw-empty-icon" aria-hidden="true"><svg class="icon"><use href="#i-box"/></svg></span>
            <h3>{{ __('ai.no_projects') }}</h3>
            <p>{{ __('ai.workspace_empty_projects') }}</p>
            <a class="pnl-btn primary aw-btn" href="{{ lroute('account.security') }}#sec-ai"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>{{ __('ai.key') }}</a>
          </div>
        @endif
      </div>
    </section>

    <section class="aw-card" aria-labelledby="ai-connect-heading">
      <div class="aw-card-head">
        <div>
          <h2 id="ai-connect-heading" class="aw-card-title"><span class="aw-card-icon" aria-hidden="true"><svg class="icon"><use href="#i-rocket"/></svg></span>{{ __('ai.workspace_connect') }}</h2>
          <p class="aw-footnote">{{ __('ai.workspace_connect_intro') }}</p>
        </div>
      </div>
      <div class="aw-card-body aw-row-stack">
        <a class="aw-mini-card aw-resource" href="{{ lroute('ai.models') }}">
          <span class="aw-card-icon" aria-hidden="true"><svg class="icon"><use href="#i-sparkles"/></svg></span>
          <div><h3>{{ __('ai.catalog') }}</h3><p class="aw-footnote">{{ __('ai.workspace_models_help') }}</p></div>
          <svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg>
        </a>
        <a class="aw-mini-card aw-resource" href="{{ lroute('ai.docs') }}">
          <span class="aw-card-icon" aria-hidden="true"><svg class="icon"><use href="#i-book"/></svg></span>
          <div><h3>{{ __('ai.docs') }}</h3><p class="aw-footnote">{{ __('ai.workspace_docs_help') }}</p></div>
          <svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg>
        </a>
        <div class="aw-alert">
          <svg class="icon" aria-hidden="true"><use href="#i-shield"/></svg>
          <div><strong>{{ __('ai.workspace_budget_heading') }}</strong><p>{{ __('ai.budget_note') }}</p></div>
        </div>
      </div>
    </section>
  </div>

  <section class="aw-card aw-history" aria-labelledby="ai-history-heading">
    <div class="aw-card-head">
      <div>
        <h2 id="ai-history-heading" class="aw-card-title"><span class="aw-card-icon" aria-hidden="true"><svg class="icon"><use href="#i-clock"/></svg></span>{{ __('ai.history') }}</h2>
        <p class="aw-footnote">{{ __('ai.workspace_history_help') }}</p>
      </div>
      <span class="aw-badge is-muted">{{ __('ai.workspace_request_total', ['count' => fa_num(number_format($requestCount))]) }}</span>
    </div>
    <div class="aw-card-body">
      <div class="aw-table-wrap" tabindex="0" role="region" aria-label="{{ __('ai.history') }}">
        <table class="aw-table {{ $history->isEmpty() ? 'is-empty' : '' }}" data-ai-billing-receipts>
          <thead><tr>@foreach(['date','detail','status','usage_subtotal','usage_tax','amount','request'] as $label)<th scope="col">{{ __('ai.'.$label) }}</th>@endforeach</tr></thead>
          <tbody>
            @forelse($history as $usage)
              <tr>
                <td><time datetime="{{ $usage->created_at->toIso8601String() }}">{{ $usage->created_at->format('Y-m-d H:i') }}</time></td>
                <td><div class="aw-row-stack"><bdi>{{ $usage->model_slug }}</bdi><small class="aw-footnote"><span>{{ __('ai.workspace_token_breakdown') }}: </span><bdi>{{ $usage->prompt_tokens === null ? '—' : number_format($usage->prompt_tokens) }} / {{ $usage->completion_tokens === null ? '—' : number_format($usage->completion_tokens) }}</bdi></small></div></td>
                <td><span class="aw-badge {{ $usage->status === 'settled' ? 'is-good' : (in_array($usage->status, ['reserved', 'sending', 'streaming', 'settle_pending', 'unknown_pending', 'unknown_charged'], true) ? 'is-warn' : 'is-muted') }}">{{ __('ai.state_'.$usage->status) }}</span></td>
                <td>{{ in_array($usage->status, \App\Models\AiUsage::CHARGED, true) ? number_format((int)$usage->sell_irt) : '—' }}</td>
                <td>{{ in_array($usage->status, \App\Models\AiUsage::CHARGED, true) ? number_format((int)$usage->tax_irt) : '—' }}</td>
                <td>{{ number_format((int)$usage->charged_irt-(int)$usage->refunded_irt) }}</td>
                <td><code class="aw-code" dir="ltr">{{ $usage->public_id }}</code></td>
              </tr>
            @empty
              <tr><td colspan="7"><div class="aw-empty">
                <span class="aw-empty-icon" aria-hidden="true"><svg class="icon"><use href="#i-flow"/></svg></span>
                <h3>{{ __('ai.no_usage') }}</h3>
                <p>{{ __('ai.workspace_empty_usage') }}</p>
                <a class="pnl-btn aw-btn" href="{{ lroute('ai.docs') }}">{{ __('ai.docs') }}<svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg></a>
              </div></td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      {{ $history->links() }}
    </div>
  </section>
</div>
@endsection
