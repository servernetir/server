@extends('panel.layout')
@section('title', __('ui.hu_title'))
@push('head')
<style>
.hu-wrap{max-width:880px;margin-inline:auto}.hu-box{padding:clamp(18px,4vw,32px);margin-block:20px;border:1px solid var(--border,#8884);border-radius:20px;background:var(--card,transparent)}.hu-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:14px}.hu-plan{border:1px solid #8884;border-radius:14px;padding:20px;display:flex;flex-direction:column;gap:12px}.hu-plan h2{font-size:1.1rem;margin:0}.hu-row{display:flex;justify-content:space-between;gap:20px;padding-block:12px;border-bottom:1px solid #8883;flex-wrap:wrap}.hu-total{font-size:1.2rem;font-weight:750}.hu-note{line-height:1.9;opacity:.8}.hu-wrap .pnl-btn{min-height:44px;display:inline-flex;align-items:center;justify-content:center}.hu-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:22px}.hu-box select{width:100%;padding:12px}
</style>
@endpush
@section('panel')
<div class="hu-wrap">
  <div class="pnl-head"><div><h1>{{ __('ui.hu_title') }}</h1><p>{{ \App\Support\PanelText::text($service->name) }} · <b dir="ltr">{{ $service->domain }}</b></p></div></div>
  <p class="hu-note">{{ __('ui.hu_intro') }}</p>
  @if($open)
    <section class="hu-box" aria-live="polite">
      <h2>{{ __('ui.hu_status_'.($open->status === 'awaiting_payment' && $open->invoice->status === 'paid' ? 'applying' : $open->status)) }}</h2>
      <p>{{ __('ui.hu_status_help') }}</p>
      @if($open->status === 'review')
        <p>{{ __('ui.hu_review_help', ['paid' => invoice_money($open->invoice->paid, $open->invoice->currency_code), 'invoice' => $open->invoice->number]) }}</p>
        <a class="pnl-btn" href="{{ lroute('account.ticket.new') }}">{{ __('ui.hu_contact_support') }}</a>
      @endif
      <a class="pnl-btn primary" href="{{ lroute('account.invoice', $open->invoice_id) }}">{{ __('ui.hu_view_invoice') }}</a>
    </section>
  @else
    @if($last?->status === 'complete')<p class="hu-box" role="status">{{ __('ui.hu_status_complete') }}</p>@endif
    @if($quote)
      <section class="hu-box">
        <h2>{{ __('ui.hu_review') }}</h2>
        <div class="hu-row"><span>{{ __('ui.hu_target') }}</span><b>{{ collect($options)->firstWhere('id', $quote['product_id'])->displayName() }}</b></div>
        <div class="hu-row"><span>{{ __('ui.hu_dates') }}</span><b dir="ltr">{{ substr($quote['period_start'],0,10) }} → {{ substr($quote['period_end'],0,10) }}</b></div>
        <div class="hu-row"><span>{{ __('ui.hu_used_days') }}</span><b>{{ number_format(($quote['seconds']-$quote['remaining'])/86400, 1) }}</b></div>
        <div class="hu-row"><span>{{ __('ui.hu_remaining') }}</span><b>{{ number_format($quote['remaining']/86400, 1) }}</b></div>
        @foreach(['price'=>'hu_full_price','credit'=>'hu_credit','debit'=>'hu_debit','subtotal'=>'hu_subtotal','tax'=>'hu_tax','total'=>'hu_total'] as $key=>$label)
          <div class="hu-row {{ $key === 'total' ? 'hu-total' : '' }}"><span>{{ __('ui.'.$label) }}</span><b>{{ invoice_money($quote[$key], $service->currency_code) }}</b></div>
        @endforeach
        <p class="hu-note">{{ __('ui.hu_quote_help') }}</p>
        <form method="post" action="{{ lroute('account.services.upgrade.store', $service) }}">
          @csrf
          <input type="hidden" name="product_id" value="{{ $quote['product_id'] }}">
          <input type="hidden" name="quote_hash" value="{{ $upgrades->hash($quote) }}">
          <div class="hu-actions"><button class="pnl-btn primary">{{ __('ui.hu_issue') }}</button><a class="pnl-btn" href="{{ lroute('account.services.upgrade', $service) }}">{{ __('ui.hu_other') }}</a></div>
        </form>
      </section>
    @else
      <div class="hu-grid">
      @forelse($options as $product)
        <section class="hu-plan"><h2>{{ $product->displayName() }}</h2>
          <b>{{ invoice_money($product->priceForCycle($service->cycle, $service->server->country), $service->currency_code) }} / {{ $service->cycleLabel() }}</b>
          <p class="hu-note">{{ __('ui.hu_full_price') }}</p>
          <a class="pnl-btn primary" href="{{ lroute('account.services.upgrade', $service) }}?product_id={{ $product->id }}">{{ __('ui.hu_calculate') }}</a>
        </section>
      @empty
        <p class="hu-box">{{ __('ui.hu_unavailable') }}</p>
      @endforelse
      </div>
    @endif
  @endif
  <div class="hu-actions"><a class="pnl-btn" href="{{ lroute('account.hosting') }}">{{ __('ui.hu_back') }}</a></div>
</div>
@endsection
