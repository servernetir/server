@extends('layouts.site')
@php
    $t = trans('transactional-email');
    $currencyLabel = $t[match ($currency) { 'IRT' => 'currency_toman', 'EUR' => 'currency_eur', default => 'currency_usd' }];
    $money = fn (int $amount) => $isFa ? fa_num(number_format($currency === 'IRT' ? $amount : $amount / 100, $currency === 'IRT' ? 0 : 2)) : number_format($amount / 100, 2);
    $estimator = ['plans' => config('transactional_email.plans'), 'extra' => config('transactional_email.extra_thousand_usd_cents'), 'currency' => $currency, 'usdRate' => $usdRate, 'eurRate' => $eurRate, 'locale' => app()->getLocale(), 'unit' => $currencyLabel];
    $faqSchema = ['mainEntity' => array_map(fn ($row) => ['@type' => 'Question', 'name' => $row[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $row[1]]], $t['faq'])];
    $serviceSchema = ['name' => $t['badge'], 'serviceType' => 'Managed transactional email', 'description' => $t['meta_description'], 'url' => lroute('email.transactional'), 'provider' => ['@type' => 'Organization', 'name' => 'ServerNet', 'url' => $homeUrl]];
    $crumbSchema = ['itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => __('ui.brand'), 'item' => $homeUrl],
        ['@type' => 'ListItem', 'position' => 2, 'name' => __('ui.sol_h1'), 'item' => lroute('solutions.index')],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $t['badge'], 'item' => lroute('email.transactional')],
    ]];
@endphp
@section('title', $t['meta_title'])
@section('description', $t['meta_description'])
@section('canonical', lroute('email.transactional'))
@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/transactional-email.css') }}">
@endpush
@section('content')
<div class="tx-page" data-email-product>
<script type="application/ld+json">{!! schema_ld($serviceSchema, 'Service') !!}</script>
<script type="application/ld+json">{!! schema_ld($faqSchema, 'FAQPage') !!}</script>
<script type="application/ld+json">{!! schema_ld($crumbSchema, 'BreadcrumbList') !!}</script>
<section class="tx-hero"><div class="container">
<nav class="tx-crumbs" aria-label="breadcrumb"><a href="{{ $homeUrl }}">{{ __('ui.brand') }}</a><span>/</span><a href="{{ lroute('solutions.index') }}">{{ __('ui.sol_h1') }}</a><span>/</span><span>SMTP / API</span></nav>
<div class="tx-hero-grid"><div>
<p class="tx-eyebrow"><span></span>{{ $t['badge'] }}</p>
<h1>{{ $t['title'] }} <span>{{ $t['title_accent'] }}</span></h1>
<p class="tx-lead">{{ $t['lead'] }}</p>
<div class="tx-actions"><a class="btn btn-primary" href="#pricing" data-growth-cta="view_plans" data-growth-placement="email_hero">{{ $t['cta'] }} <svg class="icon dir"><use href="#i-arrow"/></svg></a><a class="tx-text-link" href="#request">{{ $t['consult'] }}</a></div>
<ul class="tx-trust">@foreach($t['trust'] as $item)<li><svg class="icon"><use href="#i-check"/></svg>{{ $item }}</li>@endforeach</ul>
</div><aside class="tx-preview" aria-label="{{ $t['demo'] }}">
<div class="tx-preview-head"><span class="tx-dot"></span><b>SERVERNET / MAIL</b><span>SMTP + API</span></div>
<div class="tx-preview-message"><span class="tx-mail-icon"><svg class="icon"><use href="#i-mail"/></svg></span><div><small>notifications@your-domain.example</small><h2>{{ $t['demo_subject'] }}</h2><p>{{ $t['demo_body'] }}</p></div></div>
<ol class="tx-flow">@foreach($t['demo_steps'] as $item)<li><span>{{ $isFa ? fa_num($loop->iteration) : $loop->iteration }}</span><b>{{ $item }}</b><svg class="icon"><use href="#i-check"/></svg></li>@endforeach</ol>
<p class="tx-caption">{{ $t['demo'] }}</p>
<p class="tx-delivery-note">{{ $t['demo_note'] }}</p>
</aside></div></div></section>
<nav class="tx-section-nav" aria-label="{{ $t['badge'] }}"><div class="container">@foreach(['usecases','pricing','setup','guides','faq'] as $i => $target)<a href="#{{ $target }}">{{ $t['nav'][$i] }}</a>@endforeach</div></nav>
<section class="tx-section" id="usecases"><div class="container"><div class="tx-section-head"><span class="tx-eyebrow">01 / USE CASES</span><h2>{{ $t['use_title'] }}</h2><p>{{ $t['use_lead'] }}</p></div><div class="tx-use-grid">@foreach($t['usecases'] as $row)<article class="tx-card"><span class="tx-card-num">0{{ $loop->iteration }}</span><h3>{{ $row[0] }}</h3><p>{{ $row[1] }}</p></article>@endforeach</div></div></section>
<section class="tx-section tx-tinted" id="pricing"><div class="container"><div class="tx-section-head"><span class="tx-eyebrow">02 / SIMPLE ESTIMATES</span><h2>{{ $t['price_title'] }}</h2><p>{{ $t['price_lead'] }}</p></div>
@if($currency === 'USD')<p class="tx-callout">{{ $t['fx_fallback'] }}</p>@endif
<div class="tx-plan-grid">@foreach($plans as $i => $plan)<article class="tx-plan {{ $i === 1 ? 'tx-featured' : '' }}">@if($i === 1)<p class="tx-plan-tag">{{ $t['recommended'] }}</p>@endif<h3>{{ $t['plan_names'][$i] }}</h3><p class="tx-volume"><b>{{ $isFa ? fa_num(number_format($plan['volume'])) : number_format($plan['volume']) }}</b> {{ $t['messages'] }}</p><p class="tx-price"><strong>{{ $money($plan['amount']) }}</strong> <span>{{ $currencyLabel }} / {{ $t['monthly'] }}</span></p><small class="tx-usd-basis">{{ $t['usd_basis'] }}: <b dir="ltr">$ {{ number_format($plan['usd_cents'] / 100, 2) }}</b></small><ul>@foreach($t['included'] as $item)<li><svg class="icon"><use href="#i-check"/></svg>{{ $item }}</li>@endforeach</ul><a class="btn {{ $i === 1 ? 'btn-primary' : 'btn-glass' }}" href="#request" data-email-plan="{{ $plan['volume'] }}" data-growth-cta="request_quote" data-growth-placement="email_plan">{{ $t['select'] }}</a></article>@endforeach</div>
<div class="tx-estimator" data-email-estimator='@json($estimator)'><div><label for="email-volume">{{ $t['volume'] }}</label><select id="email-volume">@foreach([10000,50000,100000,250000,500000,1000000] as $volume)<option value="{{ $volume }}" @selected($volume === 50000)>{{ $isFa ? fa_num(number_format($volume)) : number_format($volume) }}</option>@endforeach</select></div><div><span>{{ $t['estimate'] }}</span><output id="email-estimate" for="email-volume" aria-live="polite">{{ $money($plans[1]['amount']) }} {{ $currencyLabel }}</output></div><a class="btn btn-primary" href="#request" data-email-estimate-cta>{{ $t['consult'] }}</a><p>{{ $t['calculator_note'] }}</p></div>
<p class="tx-price-note">{{ $t['price_note'] }}</p></div></section>
<section class="tx-section" id="setup"><div class="container"><div class="tx-section-head"><span class="tx-eyebrow">03 / FROM DOMAIN TO DELIVERY</span><h2>{{ $t['setup_title'] }}</h2></div><div class="tx-step-grid">@foreach($t['steps'] as $row)<article><span>{{ $isFa ? fa_num($loop->iteration) : $loop->iteration }}</span><h3>{{ $row[0] }}</h3><p>{{ $row[1] }}</p></article>@endforeach</div><div class="tx-connect"><div><h2>{{ $t['connect_title'] }}</h2><p>{{ $t['connect_lead'] }}</p><div class="tx-chips"><span>WordPress</span><span>WooCommerce</span><span>Laravel</span><span>Node.js</span><span>CRM / ERP</span><span>n8n</span></div></div><div><p class="tx-caption">{{ $t['code_label'] }}</p><pre dir="ltr"><code>MAIL_MAILER=smtp
MAIL_HOST=YOUR_ASSIGNED_SMTP_HOST
MAIL_PORT=587
MAIL_USERNAME=YOUR_SMTP_USERNAME
MAIL_PASSWORD=YOUR_SMTP_PASSWORD
MAIL_FROM_ADDRESS=notify@your-domain.example
# Require STARTTLS in your mail client.</code></pre></div></div></div></section>
<section class="tx-section tx-tinted"><div class="container tx-compare"><h2>{{ $t['compare_title'] }}</h2><div class="tx-table-wrap"><table><thead><tr>@foreach($t['compare_headers'] as $head)<th scope="col">{{ $head }}</th>@endforeach</tr></thead><tbody>@foreach($t['compare_rows'] as $row)<tr><th scope="row">{{ $row[0] }}</th><td>{{ $row[1] }}</td></tr>@endforeach</tbody></table></div><a class="tx-text-link" href="{{ lroute('hosting', 'email') }}">{{ $t['hosting_link'] }} →</a></div></section>
<section class="tx-section" id="guides"><div class="container"><div class="tx-section-head"><span class="tx-eyebrow">04 / LEARN & CONNECT</span><h2>{{ $t['guides_title'] }}</h2></div><div class="tx-guide-grid">@foreach($t['guides'] as $guide)<a href="{{ lroute('docs', $guide[0]) }}"><svg class="icon"><use href="#i-book"/></svg><h3>{{ $guide[1] }}</h3><span aria-hidden="true">↗</span></a>@endforeach</div><a class="tx-text-link" href="{{ lroute('lookup', 'email') }}">{{ $t['health'] }} →</a></div></section>
<section class="tx-section tx-tinted" id="faq"><div class="container tx-narrow"><div class="tx-section-head"><span class="tx-eyebrow">05 / QUESTIONS, ANSWERED</span><h2>{{ $t['faq_title'] }}</h2></div>@foreach($t['faq'] as $row)<details class="tx-faq"><summary>{{ $row[0] }}<span aria-hidden="true">+</span></summary><p>{{ $row[1] }}</p></details>@endforeach</div></section>
<section class="tx-section" id="request"><div class="container tx-request-grid"><div><span class="tx-eyebrow">LET’S CONNECT</span><h2>{{ $t['form_title'] }}</h2><p>{{ $t['form_lead'] }}</p><a class="tx-phone" href="tel:{{ $contact['phone_link'] }}" data-growth-cta="consultation" data-growth-placement="email_request"><svg class="icon"><use href="#i-phone"/></svg><span dir="ltr">{{ $contact['phone'] }}</span></a><a class="tx-text-link" href="mailto:{{ $contact['sales_email'] }}">{{ $contact['sales_email'] }}</a></div><div>
@if(session('email_quote_reference'))<div class="tx-success" role="status"><b>{{ $t['sent'] }}</b><p>{{ $t['reference'] }}: <span dir="ltr">{{ session('email_quote_reference') }}</span></p></div>@endif
@if($errors->any())<div class="tx-errors" role="alert"><p>{{ $t['error'] }}</p><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form class="tx-form" action="{{ lroute('email.quote') }}" method="post">@csrf
<input type="hidden" name="request_id" value="{{ old('request_id', (string) \Illuminate\Support\Str::uuid()) }}">
<div class="tx-honeypot" aria-hidden="true"><label>Website URL<input type="text" name="website_url" value="" tabindex="-1" autocomplete="off"></label></div>
<div class="tx-form-row"><label>{{ $t['name'] }}<input name="name" required maxlength="120" autocomplete="name" value="{{ old('name') }}"></label><label>{{ $t['company'] }}<input name="company" required maxlength="160" autocomplete="organization" value="{{ old('company') }}"></label></div>
<div class="tx-form-row"><label>{{ $t['email'] }}<input type="email" name="email" required maxlength="190" dir="ltr" autocomplete="email" value="{{ old('email') }}"></label><label>{{ $t['phone'] }}<input type="tel" name="phone" required minlength="7" maxlength="40" dir="ltr" autocomplete="tel" value="{{ old('phone') }}"></label></div>
<div class="tx-form-row"><label>{{ $t['domain'] }}<input name="domain" maxlength="253" dir="ltr" placeholder="example.com" value="{{ old('domain') }}"></label><label>{{ $t['volume'] }}<input type="number" name="volume" min="1000" max="1000000" step="1" required value="{{ old('volume', 50000) }}"></label></div>
<label>{{ $t['application'] }}<textarea name="message" required maxlength="1000" rows="4">{{ old('message') }}</textarea></label>
<label class="tx-consent"><input type="checkbox" name="consent" value="1" required @checked(old('consent'))><span>{{ $t['privacy'] }}</span></label>
<button class="btn btn-primary" type="submit" data-growth-cta="submit_quote" data-growth-placement="email_form">{{ $t['submit'] }}<svg class="icon"><use href="#i-arrow"/></svg></button>
</form></div></div></section>
</div>
<script src="{{ asset_ver('assets/js/transactional-email.js') }}" defer></script>
@endsection
