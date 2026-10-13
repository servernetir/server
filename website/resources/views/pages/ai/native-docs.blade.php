<section class="ai-wrap ai-section">
    <h2>{{ __('ai.native_docs_title') }}</h2>
    <p class="ai-muted">{{ __('ai.native_docs_desc') }}</p>
    <pre class="ai-code">curl https://servernet.cloud/v1/inference \
  -H "Authorization: Bearer $SERVERNET_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: media-001" \
  -d '{"model":"NATIVE_MODEL_ID","prompt":"A blue cube in a sunny garden"}'</pre>
    <p class="ai-muted">{{ __('ai.native_docs_poll') }}</p>
    <pre class="ai-code">curl https://servernet.cloud/v1/inference/REQUEST_ID \
  -H "Authorization: Bearer $SERVERNET_API_KEY"</pre>
</section>
