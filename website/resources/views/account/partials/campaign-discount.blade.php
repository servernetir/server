@if(\Illuminate\Support\Facades\Schema::hasTable('marketing_redemptions'))
@php($offerRedemption = \App\Models\MarketingRedemption::where('invoice_id',$invoice->id)->where('customer_id',auth('customer')->id())->first())
@if($invoice->kind !== 'topup' && in_array($invoice->status,['unpaid','draft'],true) && $invoice->paid === 0)
<section class="pnl-sec"><div class="pnl-sec-h"><h2>{{ __('marketing.apply') }}</h2></div><div class="pnl-sec-b">
@if($offerRedemption && in_array($offerRedemption->status,['reserved','reconciliation'],true))
<p>{{ __('marketing.reservation_until') }}: <time datetime="{{ $offerRedemption->expires_at->toIso8601String() }}">{{ blog_date($offerRedemption->expires_at->setTimezone(auth('customer')->user()->timezone ?: 'Asia/Tehran')->toDateString()) }} {{ $offerRedemption->expires_at->format('H:i T') }}</time></p>
@if($offerRedemption->status === 'reconciliation')<p role="alert">{{ __('marketing.reconciliation') }}</p>
@elseif($invoice->total === 0 && $offerRedemption->expires_at->isFuture())
<form method="post" action="{{ lroute('account.invoice.offer.free',$invoice) }}">@csrf<button class="pnl-btn primary" type="submit">{{ __('marketing.free') }}</button></form>
@endif
@else
<form method="post" action="{{ lroute('account.invoice.offer',$invoice) }}" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">@csrf
<label>{{ __('marketing.code') }} <input class="pnl-input" name="code" dir="ltr" maxlength="40" required value="{{ old('code',session('marketing_coupon','')) }}" autocomplete="off"></label><button class="pnl-btn" type="submit">{{ __('marketing.apply') }}</button><a href="{{ lroute('account.offers') }}">{{ __('marketing.title') }}</a>
</form>
@endif
</div></section>
@endif
@endif
