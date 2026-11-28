@php
    $hardwarePhone = config('servernet.contact.'.(app()->getLocale() === 'fa' ? 'phone_link' : 'phone_intl_link'));
@endphp
<section class="sp-ask">
    <div>
        <h2>{{ __('shop_seo.stock_title') }}</h2>
        <p>{{ __('shop_seo.stock_copy') }}</p>
    </div>
    <div>
        <a class="btn btn-primary" href="{{ isset($quoteSlug) ? lroute('contact', ['part' => $quoteSlug]).'#hardware-quote' : lroute('contact') }}">
            {{ isset($quoteSlug) ? __('part_finder.quote_cta') : __('shop_seo.stock_cta') }}
        </a>
        @if($hardwarePhone)
            <a class="btn btn-glass" href="tel:{{ $hardwarePhone }}">{{ __('ui.srv_call') }}</a>
        @endif
    </div>
</section>
