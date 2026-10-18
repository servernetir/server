@extends('layouts.site')
@section('title',__('identity.docs'))
@section('description',__('identity.description'))
@include('pages.identity.style')
@section('content')<div class="id-wrap">@include('pages.identity.nav')<h1>{{ __('identity.docs') }}</h1><p class="id-lead">{{ __('identity.docs_intro') }}</p>
<a href="{{ lroute('identity.openapi') }}">OpenAPI 3.1 JSON</a><div class="id-note">{{ __('identity.key_help') }} {{ __('identity.retry_help') }}</div>
<p>{{ __('identity.wallet_only') }}</p><h2>curl</h2><pre class="id-code">curl https://servernet.cloud/api/v1/identity/services \
  -H "Authorization: Bearer $SERVERNET_API_KEY"

curl https://servernet.cloud/api/v1/identity/quotes/shahkar-inquiry \
  -H "Authorization: Bearer $SERVERNET_API_KEY"

curl -X POST https://servernet.cloud/api/v1/identity/inquiries/shahkar-inquiry \
  -H "Authorization: Bearer $SERVERNET_API_KEY" \
  -H "Idempotency-Key: $REQUEST_KEY" \
  -H 'Content-Type: application/json' \
  --data-binary @request.json

{
  "input": {"mobile": "YOUR_AUTHORIZED_MOBILE", "national_code": "YOUR_AUTHORIZED_NATIONAL_CODE"},
  "max_charge_irt": 0,
  "price_version": 0
}</pre><p>{{ __('identity.quote_help') }}</p>
<h2>PHP / Laravel</h2><pre class="id-code">$response = Http::withToken(env('SERVERNET_API_KEY'))
    ->withHeaders(['Idempotency-Key' => $savedRequestKey])
    ->timeout(40)->post(
        'https://servernet.cloud/api/v1/identity/inquiries/shahkar-inquiry',
        ['input' => $authorizedInput, 'max_charge_irt' => $acceptedQuote,
         'price_version' => $priceVersion]
    );</pre>
<h2>Python</h2><pre class="id-code">import os, requests

response = requests.post(
    "https://servernet.cloud/api/v1/identity/inquiries/shahkar-inquiry",
    headers={"Authorization": "Bearer " + os.environ["SERVERNET_API_KEY"],
             "Idempotency-Key": saved_request_key},
    json={"input": authorized_input, "max_charge_irt": accepted_quote,
          "price_version": price_version}, timeout=40)
result = response.json()</pre>
<h2>JavaScript (Node.js)</h2><pre class="id-code">const response = await fetch(
  'https://servernet.cloud/api/v1/identity/inquiries/shahkar-inquiry', {
    method: 'POST',
    headers: {'Authorization': `Bearer ${process.env.SERVERNET_API_KEY}`,
      'Idempotency-Key': savedRequestKey, 'Content-Type': 'application/json'},
    body: JSON.stringify({input: authorizedInput,
      max_charge_irt: acceptedQuote, price_version: priceVersion})
  });
const result = await response.json();</pre>
<h2>{{ __('identity.states') }}</h2><p>{{ __('identity.states_help') }}</p><p>{{ __('identity.terms_unknown') }}</p>
<pre class="id-code">GET /api/v1/identity/requests/{request_id}</pre><p>{{ __('identity.idempotency_help') }}</p>
</div>@endsection
