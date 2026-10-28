@php($birthdayRedemption = app(\App\Services\Birthday\BirthdayInvoices::class)->redemption($invoice))
@if($birthdayRedemption && in_array($birthdayRedemption->status, ['applied', 'locked', 'committed', 'reconciliation'], true))
<section class="pnl-sec birthday-invoice" data-birthday-discount>
  <div class="pnl-sec-b">
    <b>🎁 {{ __('birthday.invoice_note') }}</b>
    <p>{{ __('birthday.automatic') }}</p>
    <dl>
      <dt>{{ __('birthday.regular_price') }}</dt><dd><s>{{ invoice_money($birthdayRedemption->snapshot['original']['total'], $invoice->currency_code) }}</s></dd>
      <dt>{{ __('birthday.birthday_price') }}</dt><dd><strong>{{ invoice_money($invoice->total, $invoice->currency_code) }}</strong></dd>
      <dt>{{ __('birthday.savings') }}</dt><dd>{{ invoice_money($birthdayRedemption->snapshot['original']['total'] - $invoice->total, $invoice->currency_code) }}</dd>
    </dl>
    @if(in_array($birthdayRedemption->status, ['locked', 'reconciliation'], true))
      <p role="alert">{{ __('birthday.review_required') }}</p>
    @elseif($invoice->status !== 'paid')
      <small>{{ __('birthday.valid_until') }} <time datetime="{{ $birthdayRedemption->expires_at->toIso8601String() }}">{{ blog_date($birthdayRedemption->expires_at->copy()->setTimezone('Asia/Tehran')->toDateString()) }} {{ $birthdayRedemption->expires_at->copy()->setTimezone('Asia/Tehran')->format('H:i') }}</time></small>
    @endif
  </div>
</section>
@endif
