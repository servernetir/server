@if(app(\App\Services\Birthday\BirthdayInvoices::class)->redemption($invoice))
<input type="hidden" name="birthday_price_token" value="{{ app(\App\Services\Birthday\BirthdayInvoices::class)->token($invoice) }}">
@endif
