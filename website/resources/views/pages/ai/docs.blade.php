@extends('layouts.site')
@section('title',__('ai.docs_title'))
@section('description',__('ai.docs_desc'))
@include('pages.ai.style')
@section('content')<main class="ai-wrap">@include('pages.ai.nav')<h1>{{ __('ai.docs') }}</h1><p class="ai-lead">{{ __('ai.docs_desc') }}</p><ol class="ai-steps">@foreach([1,2,3,4] as $i)<li>{{ __('ai.step'.$i) }}</li>
@endforeach
</ol><div class="ai-note">{{ __('ai.key_safety') }}</div><h2>{{ __('ai.base') }}</h2><pre class="ai-code">https://servernet.cloud/v1</pre><p class="ai-muted">{{ __('ai.model_list') }}</p><pre class="ai-code">curl https://servernet.cloud/v1/models \
  -H "Authorization: Bearer $SERVERNET_API_KEY"</pre><section class="ai-section"><h2>{{ __('ai.python') }}</h2><pre class="ai-code">import os
from openai import OpenAI

client = OpenAI(
    api_key=os.environ["SERVERNET_API_KEY"],
    base_url="https://servernet.cloud/v1",
    max_retries=0,
)
models = [m for m in client.models.list().data if getattr(m, "category", "chat") == "chat"]
if not models:
    raise RuntimeError("No models available")
answer = client.chat.completions.create(
    model=models[0].id,
    messages=[{"role": "user", "content": "Hello"}],
    max_tokens=128,
    extra_headers={"Idempotency-Key": "my-request-001"},
)
print(answer.choices[0].message.content)</pre></section><h2>{{ __('ai.curl') }}</h2><pre class="ai-code">curl https://servernet.cloud/v1/chat/completions \
  -H "Authorization: Bearer $SERVERNET_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: my-request-002" \
  -d '{"model":"{{ $exampleModel }}","messages":[{"role":"user","content":"Hello"}],"max_tokens":128}'</pre><p class="ai-muted">{{ __('ai.streaming') }}</p><div class="ai-note">{{ __('ai.retries') }}</div><section class="ai-section"><h2>{{ __('ai.input_operations') }}</h2><p>{{ __('ai.input_operations_desc') }}</p><pre class="ai-code"># Choose a model with category="embedding" from /v1/models
vector = client.embeddings.create(model="EMBEDDING_MODEL_ID", input=["Hello", "World"], encoding_format="float")
print(vector.usage.prompt_tokens)</pre><pre class="ai-code">curl https://servernet.cloud/v1/rerank \
  -H "Authorization: Bearer $SERVERNET_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: rerank-001" \
  -d '{"model":"RERANK_MODEL_ID","queries":["capital?"],"documents":["Tehran"]}'</pre></section><h2>{{ __('ai.errors') }}</h2><p class="ai-muted">{{ __('ai.errors_text') }}</p><div class="ai-actions"><a class="ai-btn primary" href="{{ lroute('account.ai') }}">{{ __('ai.start') }}</a><a class="ai-btn" href="{{ lroute('ai.models') }}">{{ __('ai.catalog') }}</a></div></main>
@include('pages.ai.native-docs')
@endsection
