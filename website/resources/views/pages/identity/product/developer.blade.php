@php
$samples = [
    'curl' => <<<'CODE'
curl https://servernet.cloud/api/v1/identity/quotes/shahkar-inquiry \
  -H "Authorization: Bearer $SERVERNET_API_KEY"

# request.json: input + accepted total_irt + price_version
curl -X POST https://servernet.cloud/api/v1/identity/inquiries/shahkar-inquiry \
  -H "Authorization: Bearer $SERVERNET_API_KEY" \
  -H "Idempotency-Key: $SAVED_REQUEST_KEY" \
  -H 'Content-Type: application/json' \
  --data-binary @request.json
CODE,
    'php' => <<<'CODE'
// Laravel HTTP client — server-side only
// $authorizedInput: mobile + national_code (with permission)
$quote = Http::withToken(env('SERVERNET_API_KEY'))->get(
    'https://servernet.cloud/api/v1/identity/quotes/shahkar-inquiry'
)->throw()->json();

// After your customer accepts the quoted total:
$response = Http::withToken(env('SERVERNET_API_KEY'))
    ->withHeaders(['Idempotency-Key' => $savedRequestKey])
    ->timeout(40)->post(
        'https://servernet.cloud/api/v1/identity/inquiries/shahkar-inquiry',
        ['input' => $authorizedInput,
         'max_charge_irt' => $quote['total_irt'],
         'price_version' => $quote['price_version']]
    );
CODE,
    'python' => <<<'CODE'
import os, requests

headers = {"Authorization": "Bearer " + os.environ["SERVERNET_API_KEY"]}
base = "https://servernet.cloud/api/v1/identity"
quote_response = requests.get(
    base + "/quotes/shahkar-inquiry", headers=headers, timeout=40)
quote_response.raise_for_status()
quote = quote_response.json()

# After your customer accepts the quoted total:
# authorized_input: mobile + national_code (with permission)
response = requests.post(
    base + "/inquiries/shahkar-inquiry",
    headers={**headers, "Idempotency-Key": saved_request_key},
    json={"input": authorized_input,
          "max_charge_irt": quote["total_irt"],
          "price_version": quote["price_version"]}, timeout=40)
CODE,
];
@endphp
<section class="ip-section ip-developer" aria-labelledby="ip-dev-title"><div class="ip-dev-copy"><p class="ip-eyebrow">{{ __('identity_product.dev_kicker') }}</p><h2 id="ip-dev-title">{{ __('identity_product.dev_title') }}</h2><p>{{ __('identity_product.dev_intro') }}</p><ol>@foreach([1,2,3] as $i)<li><span aria-hidden="true">{{ $i }}</span>{{ __('identity_product.dev_'.$i) }}</li>@endforeach</ol><a class="ip-text-link" href="{{ lroute('identity.docs') }}">{{ __('identity.docs') }} ↗</a></div>
<div class="ip-code-component"><div class="ip-code-bar"><div role="tablist" aria-label="{{ __('identity_product.connect') }}" dir="ltr">@foreach(['curl'=>'cURL','php'=>'PHP','python'=>'Python'] as $key=>$name)<button type="button" role="tab" id="ip-tab-{{ $key }}" aria-controls="ip-panel-{{ $key }}" aria-selected="{{ $loop->first?'true':'false' }}" tabindex="{{ $loop->first?'0':'-1' }}" data-code-tab="{{ $key }}">{{ $name }}</button>@endforeach</div><button type="button" class="ip-copy" data-copy hidden>{{ __('identity_product.copy') }}</button></div>
@foreach($samples as $key=>$sample)<div id="ip-panel-{{ $key }}" role="tabpanel" aria-labelledby="ip-tab-{{ $key }}" data-code-panel="{{ $key }}" tabindex="0" @if(!$loop->first) hidden @endif><pre dir="ltr"><code>{{ $sample }}</code></pre></div>@endforeach
<div class="ip-code-foot"><span aria-hidden="true">◈</span> {{ __('identity_product.code_note') }}<details class="ip-response"><summary>{{ __('identity_product.response') }} · {{ __('identity_product.preview') }}</summary><pre dir="ltr"><code>{
  "processing_status": "completed",
  "inquiry_outcome": "matched",
  "financial_status": "captured",
  "result": { "matched": true }
}</code></pre></details></div><p class="ip-sr" role="status" aria-live="polite" data-copy-status></p></div></section>
