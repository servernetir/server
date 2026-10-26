@php
  $discountEntryEnabled = app(\App\Services\Marketing\DiscountEntryVisibility::class)->enabled(auth('customer')->user());
  $offerRedemption = \Illuminate\Support\Facades\Schema::hasTable('marketing_redemptions')
      ? \App\Models\MarketingRedemption::where('invoice_id', $invoice->id)->where('customer_id', auth('customer')->id())->first()
      : null;
  $hasReservedOffer = $offerRedemption && in_array($offerRedemption->status, ['reserved', 'reconciliation'], true);
  $offerInvoiceOpen = $invoice->kind !== 'topup' && in_array($invoice->status, ['unpaid', 'draft'], true) && $invoice->paid === 0;
  $showCodeEntry = $discountEntryEnabled && $offerInvoiceOpen && $invoice->due() > 0
      && \Illuminate\Support\Facades\Schema::hasTable('marketing_redemptions');
  $showGiftEntry = $discountEntryEnabled && ($giftCoupon ?? null) && $invoice->isPayable()
      && $invoice->kind !== 'topup' && $invoice->currency_code === 'IRT';
@endphp

{{-- Existing reservations remain actionable even after entry is hidden. --}}
@if($hasReservedOffer && $offerInvoiceOpen)
  @php($offerExpires = $offerRedemption->expires_at->copy()->setTimezone(auth('customer')->user()->timezone ?: 'Asia/Tehran'))
  <section class="pnl-sec" data-discount-reservation>
    <div class="pnl-sec-b" role="status" style="font-size:12px;line-height:1.9">
      <span>{{ __('marketing.reservation_until') }}:
        <time datetime="{{ $offerRedemption->expires_at->toIso8601String() }}">{{ blog_date($offerExpires->toDateString()) }} {{ $offerExpires->format('H:i T') }}</time>
      </span>
      @if($offerRedemption->status === 'reconciliation')
        <p role="alert">{{ __('marketing.reconciliation') }}</p>
      @elseif($invoice->total === 0 && $offerRedemption->expires_at->isFuture())
        <form method="post" action="{{ lroute('account.invoice.offer.free', $invoice) }}">
          @csrf
          <button class="pnl-btn" type="submit">{{ __('marketing.free') }}</button>
        </form>
      @endif
    </div>
  </section>
@elseif($showCodeEntry || $showGiftEntry)
  <details class="invoice-discount" data-discount-entry @if($errors->has('code')) open @endif>
    <summary>
      <span>{{ __('marketing.code_prompt') }}</span>
      <svg class="icon" aria-hidden="true"><use href="#i-chev"/></svg>
    </summary>
    <div class="invoice-discount-body">
      @if($showCodeEntry)
        <form method="post" action="{{ lroute('account.invoice.offer', $invoice) }}" class="invoice-discount-form">
          @csrf
          <label class="invoice-discount-field" for="invoice-discount-code">{{ __('marketing.code') }}
            <input id="invoice-discount-code" class="pnl-input" name="code" dir="ltr" maxlength="40" required
                   value="{{ old('code', session('marketing_coupon', '')) }}" autocomplete="off" autocapitalize="characters" spellcheck="false">
          </label>
          <button class="pnl-btn" type="submit">{{ __('marketing.apply_short') }}</button>
        </form>
        <a class="invoice-discount-link" href="{{ lroute('account.offers') }}">{{ __('marketing.my_offers_short') }}</a>
      @endif
      @if($showGiftEntry)
        <form method="post" action="{{ lroute('account.invoice.coupon', $invoice) }}" class="invoice-discount-gift">
          @csrf
          <input type="hidden" name="code" value="{{ $giftCoupon->code }}">
          <span>{{ __('ui.iv_cp_lead', ['amount' => invoice_money($giftCoupon->amount, 'IRT'), 'hours' => fa_num((string) $giftCoupon->hoursLeft())]) }}</span>
          <button class="pnl-btn" type="submit">{{ __('ui.iv_cp_btn') }}</button>
        </form>
      @endif
    </div>
  </details>
@endif
