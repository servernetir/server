@push('head')
<link rel="stylesheet" href="{{ asset_ver('assets/css/customer-api.css') }}">
@endpush
<div class="sn-api-docs">
  <header class="sn-api-hero">
    <span class="sn-api-label">ServerNet · API v1</span>
    <h1>{{ __('customer-api.title') }}</h1>
    <p>{{ __('customer-api.intro') }}</p>
    <div class="sn-api-actions">
      <a class="btn primary" href="{{ lroute('account.security') }}#sec-api">{{ __('customer-api.keys') }}</a>
      <a class="btn" href="{{ lroute('developers.platform.openapi') }}">OpenAPI 3.1 · JSON</a>
    </div>
  </header>
  <nav class="sn-api-categories" aria-label="{{ __('customer-api.categories') }}">
    @foreach(['cloud', 'account', 'domains', 'ai', 'identity', 'tunnel', 'other'] as $group)
      <a href="#api-{{ $group }}">{{ __('customer-api.group_'.$group) }}</a>
    @endforeach
  </nav>
  <section class="sn-api-card">
    <h2>{{ __('customer-api.auth') }}</h2>
    <p>{{ __('customer-api.auth_note') }}</p>
    <p>{{ __('customer-api.service_ids_note') }}</p>
    <pre dir="ltr"><code>Authorization: Bearer $SERVERNET_API_TOKEN
Accept: application/json
Idempotency-Key: &lt;unique UUID per intended power command&gt;</code></pre>
    <p>{{ __('customer-api.scope_note') }}</p>
  </section>
  @foreach(['cloud', 'account'] as $group)
  <section class="sn-api-card" id="api-{{ $group }}">
    <h2>{{ __('customer-api.group_'.$group) }}</h2>
    <p>{{ __('customer-api.note_'.$group) }}</p>
    <div class="sn-api-table-wrap"><table>
      <thead><tr><th>{{ __('customer-api.endpoint') }}</th><th>{{ __('customer-api.scope') }}</th></tr></thead>
      <tbody>
      @foreach(\App\Services\CustomerApi\Catalog::ENDPOINTS as [$method, $path, $scope, $tag, $summary])
        @if($tag === $group)
        <tr><td><code dir="ltr">{{ $method }} /api/v1{{ $path }}</code></td><td><code dir="ltr">{{ $scope }}</code></td></tr>
        @endif
      @endforeach
      </tbody>
    </table></div>
  </section>
  @endforeach
  @foreach(['domains' => 'developers', 'ai' => 'ai.docs', 'identity' => 'identity.docs', 'tunnel' => 'developers.tunnel'] as $group => $route)
  <section class="sn-api-card" id="api-{{ $group }}">
    <h2>{{ __('customer-api.group_'.$group) }}</h2>
    <p>{{ __('customer-api.note_'.$group) }}</p>
    <a href="{{ lroute($route) }}">{{ __('customer-api.full_reference') }} →</a>
  </section>
  @endforeach
  <section class="sn-api-card" id="api-other">
    <h2>{{ __('customer-api.group_other') }}</h2>
    <p>{{ __('customer-api.note_other') }}</p>
  </section>
  <section class="sn-api-card">
    <h2>{{ __('customer-api.example') }}</h2>
    <p>{{ __('customer-api.example_note') }}</p>
    <pre dir="ltr"><code># Keep this token on your backend, never in frontend JavaScript.
export SERVERNET_API_TOKEN='YOUR_TOKEN'
export SERVERNET_SERVICE_ID='YOUR_SERVICE_ID'
export SERVERNET_BASE='https://console.servernet.cloud/api/v1'

curl -sS "$SERVERNET_BASE/cloud" \
  -H "Authorization: Bearer $SERVERNET_API_TOKEN"

# Generate once per intended Start. Reuse this SAME key if the response is lost.
START_KEY=$(python3 -c 'import uuid; print(uuid.uuid4())')
curl -sS -X POST "$SERVERNET_BASE/cloud/$SERVERNET_SERVICE_ID/actions/start" \
  -H "Authorization: Bearer $SERVERNET_API_TOKEN" \
  -H "Idempotency-Key: $START_KEY" -H 'Accept: application/json'

# Poll every 20–30 seconds. Wait for status=running AND ready=true.
curl -sS "$SERVERNET_BASE/cloud/$SERVERNET_SERVICE_ID/status" \
  -H "Authorization: Bearer $SERVERNET_API_TOKEN"

STOP_KEY=$(python3 -c 'import uuid; print(uuid.uuid4())')
curl -sS -X POST "$SERVERNET_BASE/cloud/$SERVERNET_SERVICE_ID/actions/stop" \
  -H "Authorization: Bearer $SERVERNET_API_TOKEN" \
  -H "Idempotency-Key: $STOP_KEY" -H 'Accept: application/json'</code></pre>
  </section>
  <section class="sn-api-card">
    <h2>{{ __('customer-api.billing') }}</h2>
    <ul>
      <li>{{ __('customer-api.billing_units') }}</li>
      <li>{{ __('customer-api.billing_start') }}</li>
      <li>{{ __('customer-api.billing_stop') }}</li>
      <li>{{ __('customer-api.billing_cap') }}</li>
    </ul>
    <p>{{ __('customer-api.gpu_data') }}</p>
  </section>
  <section class="sn-api-card">
    <h2>{{ __('customer-api.responses') }}</h2>
    <pre dir="ltr"><code>{"ok":true,"accepted":true,"service_id":123,"action":"on","status":"building","operation_id":"UUID"}
{"ok":true,"data":{"service_id":123,"status":"running","ready":true,"observed_at":"UTC timestamp","cache_seconds":20}}
{"ok":false,"error":"insufficient_credit","minimum_irt":1000,"available_irt":500,"operation_id":"UUID"}</code></pre>
    <p>{{ __('customer-api.response_note') }}</p>
    <p>{{ __('customer-api.limits') }}</p>
    <p>{{ __('customer-api.errors') }}</p>
    <p>{{ __('customer-api.reconcile') }}</p>
  </section>
</div>
