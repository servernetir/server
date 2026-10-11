<?php

namespace App\Services\Ai;

use App\Models\AiModel;

/**
 * پاک‌سازیِ بدنهٔ /v1 پیش از هر پولی (m5-spec §4.A2–A3، §7).
 *
 * ═══ چرا این‌قدر سخت‌گیر ═══
 *
 * رزرو (`H`) سقفِ ضدِ ضرر است و فقط وقتی سقف است که مصرفِ واقعی از آن بیرون
 * نزند (B6). پس:
 *  • خروجی: `max_tokens` **اجباری** و به سقفِ مدل بسته می‌شود؛ `max_completion_tokens`
 *    به همان نگاشت می‌شود — ارائه‌دهنده هرگز بیش از `O` تولید نمی‌کند.
 *  • ورودی: `I` از **بایت**‌های خودِ بدنهٔ ارسالی سنجیده می‌شود (≤ ۱ توکن به ازای هر
 *    بایتِ UTF-8 برای BPE ِ بایتی) + ۱۶ به ازای هر پیام + ۲۵۶ برای قالبِ چت. ابزارها،
 *    `response_format` و همه‌چیزِ دیگرِ بدنه هم شمرده می‌شوند؛ «÷۴» ِ قدیمی نه.
 *  • `n>1`، `best_of`، صدا/تصویر: ۴۰۰ — هر کدام مصرف را از سقف بیرون می‌برد.
 *  • فقط کلیدهای فهرستِ سفید به بالادست می‌روند؛ بقیه (مثلِ `user` که هویتِ مشتری را به
 *    ارائه‌دهنده نشت می‌دهد) بی‌صدا حذف و در سربرگ نام برده می‌شوند.
 *
 * `stream:true` در M5.1b رد می‌شود (۴۰۰): پیش از M5.3 استریم تماسِ رایگان بود (B5).
 */
final class AiPayload
{
    public static function requiresNonReasoning(AiModel $model): bool
    {
        return $model->provider?->slug === 'deepseek'
            || ($model->provider?->slug === 'gapgpt' && $model->upstream_model === 'grok-4')
            || ($model->provider?->slug === 'deepinfra' && in_array($model->upstream_model, [
                'Qwen/Qwen3.8-Flash', 'Qwen/Qwen3.8-Max', 'zai-org/GLM-5.3', 'Qwen/Qwen3.7-Max',
                'ByteDance/Seed-2.0-mini', 'ByteDance/Seed-1.8', 'ByteDance/Seed-2.0-code',
            ], true));
    }

    /** کلیدهایی که عیناً به بالادست می‌روند */
    public const ALLOWED = [
        'messages', 'temperature', 'top_p', 'top_k', 'min_p', 'stop',
        'presence_penalty', 'frequency_penalty', 'repetition_penalty', 'seed',
        'response_format', 'tools', 'tool_choice', 'parallel_tool_calls',
        'logprobs', 'top_logprobs',
    ];

    /** کلیدهایی که حضورشان سقفِ مصرف را می‌شکند ⇒ ۴۰۰ */
    public const REFUSED = ['best_of', 'audio', 'modalities', 'prediction'];

    public const ROLES = ['system', 'developer', 'user', 'assistant', 'tool'];

    public const IDEMPOTENCY_KEY = '/^[A-Za-z0-9._:-]{1,80}$/';

    public static function validIdempotencyKey(string $key): bool
    {
        return preg_match(self::IDEMPOTENCY_KEY, $key) === 1;
    }

    /**
     * @param  array  $client  بدنهٔ خامِ مشتری (همراهِ `model`)
     *
     * @throws AiRequestException
     */
    public function prepare(array $client, AiModel $model): AiPreparedRequest
    {
        if (isset($client['stream']) && ! is_bool($client['stream'])) {
            throw new AiRequestException('invalid_request', 'stream must be boolean.', 400);
        }

        foreach (self::REFUSED as $k) {
            if (array_key_exists($k, $client)) {
                throw new AiRequestException('unsupported_parameter', "پارامترِ «{$k}» پشتیبانی نمی‌شود.", 400);
            }
        }
        if (array_key_exists('n', $client) && $client['n'] !== 1 && $client['n'] !== null) {
            throw new AiRequestException('unsupported_parameter', 'فقط n=1 پشتیبانی می‌شود.', 400);
        }

        $messages = $client['messages'] ?? null;
        if (! is_array($messages) || $messages === [] || ! array_is_list($messages)) {
            throw new AiRequestException('invalid_request', 'فیلدِ «messages» باید فهرستی ناخالی باشد.', 400);
        }
        foreach ($messages as $i => $m) {
            $this->checkMessage($m, $i);
        }
        if (isset($client['tools'])) {
            if (! is_array($client['tools']) || ! array_is_list($client['tools'])) {
                throw new AiRequestException('invalid_request', 'tools must be a list.', 400);
            }
            foreach ($client['tools'] as $tool) {
                if (! is_array($tool) || ($tool['type'] ?? null) !== 'function'
                    || array_diff(array_keys($tool), ['type', 'function']) !== []) {
                    throw new AiRequestException('unsupported_parameter', 'Only function tools without paid cache options are supported.', 400);
                }
            }
        }

        $cap = max(1, (int) ($model->max_output_tokens ?: config('ai.default_max_output', 4096)));
        $asked = [];
        foreach (['max_tokens', 'max_completion_tokens'] as $k) {
            if (array_key_exists($k, $client) && $client[$k] !== null) {
                if (! is_int($client[$k]) || $client[$k] < 1) {
                    throw new AiRequestException('invalid_request', "«{$k}» باید عددِ صحیحِ مثبت باشد.", 400);
                }
                $asked[] = $client[$k];
            }
        }
        $omitted = $asked === [];
        $output = min($omitted ? $cap : min($asked), $cap);

        $forward = [];
        $dropped = [];
        foreach ($client as $k => $v) {
            if (in_array($k, self::ALLOWED, true)) {
                $forward[$k] = $v;
            } elseif (! in_array($k, ['model', 'max_tokens', 'max_completion_tokens', 'n', 'stream', 'stream_options'], true)) {
                $dropped[] = (string) $k;
            }
        }

        // These upstream models exceeded max_tokens with their default reasoning.
        // Keep the verified non-reasoning contract until a bounded reasoning API exists.
        if (self::requiresNonReasoning($model) && $model->provider?->slug !== 'deepseek') {
            $forward['reasoning_effort'] = 'none';
        }
        if ($model->provider?->slug === 'deepseek') {
            $forward['thinking'] = ['type' => 'disabled'];
        }

        $bytes = strlen((string) json_encode($forward, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $input = $bytes + 16 * count($messages) + 256;
        if ($model->provider?->slug === 'deepinfra' && $model->upstream_model === 'nvidia/Nemotron-Content-Safety-3.5') {
            $input += 1024; // Verified 469 prompt tokens for a short request: internal safety template.
        }
        $tierLimit = $model->capabilities['pricing_context_limit'] ?? null;
        if (is_int($tierLimit) && $input + $output > $tierLimit) {
            throw new AiRequestException('request_too_large', 'Input and output exceed this model pricing tier limit.', 413);
        }
        if (in_array($model->provider?->slug, ['gapgpt', 'deepseek'], true) && $input + $output > $model->context_tokens) {
            throw new AiRequestException('request_too_large', 'Input and output exceed the verified context limit.', 413);
        }

        return new AiPreparedRequest(
            forward: $forward,
            maxInput: $input,
            maxOutput: $output,
            outputCap: $cap,
            clientOmittedMaxTokens: $omitted,
            dropped: $dropped,
            sha256: self::hash($client),
            stream: ($client['stream'] ?? false) === true,
        );
    }

    /**
     * هشِ بدنهٔ مشتری با کلیدهای مرتب (بازگشتی) — ترتیبِ کلید در JSON معنا ندارد
     * و نباید کلیدِ هم‌ارزی را «بدنهٔ دیگر» ببیند. فهرست‌ها ترتیبشان را نگه می‌دارند.
     */
    public static function hash(array $body): string
    {
        return hash('sha256', (string) json_encode(self::canonical($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Text-only input operations: no generation, media, callbacks or arbitrary options. */
    public function prepareInputOnly(array $client, AiModel $model, string $operation): AiPreparedRequest
    {
        if (($client['stream'] ?? false) !== false) {
            throw new AiRequestException('unsupported_parameter', 'Streaming is unavailable for this operation.', 400);
        }
        $forward = [];
        if ($operation === 'embedding') {
            $input = $client['input'] ?? null;
            $texts = is_string($input) ? [$input] : $input;
            $this->checkTexts($texts, 128);
            if (($client['encoding_format'] ?? 'float') !== 'float') {
                throw new AiRequestException('unsupported_parameter', 'encoding_format must be float.', 400);
            }
            $forward = ['input' => $input, 'encoding_format' => 'float'];
            // Includes model instruction/template overhead for every item, not only once per batch.
            $bound = array_sum(array_map('strlen', $texts)) + 1024 * count($texts);
        } elseif ($operation === 'rerank') {
            $queries = $client['queries'] ?? null;
            $documents = $client['documents'] ?? null;
            $this->checkTexts($queries, 128);
            $this->checkTexts($documents, 128);
            if (count($queries) !== count($documents)) {
                throw new AiRequestException('invalid_request', 'queries and documents must have the same length.', 400);
            }
            $instruction = $client['instruction'] ?? 'Given a web search query, retrieve relevant passages that answer the query';
            if (! is_string($instruction) || strlen($instruction) > 2048) {
                throw new AiRequestException('invalid_request', 'instruction must be text of at most 2048 bytes.', 400);
            }
            $forward = ['queries' => $queries, 'documents' => $documents, 'instruction' => $instruction];
            $bound = array_sum(array_map('strlen', $queries)) + array_sum(array_map('strlen', $documents))
                + (strlen($instruction) + 1024) * count($documents);
        } else {
            throw new AiRequestException('unsupported_parameter', 'Unsupported input operation.', 400);
        }
        if ($bound > 1_000_000) {
            throw new AiRequestException('request_too_large', 'Input exceeds the batch limit.', 413);
        }
        $dropped = array_values(array_diff(array_keys($client), ['model', 'stream', ...array_keys($forward)]));
        return new AiPreparedRequest($forward, $bound, 0, 0, false, $dropped,
            self::hash(['operation' => $operation, 'body' => $client]), operation: $operation);
    }

    private function checkTexts(mixed $texts, int $limit): void
    {
        if (! is_array($texts) || ! array_is_list($texts) || $texts === [] || count($texts) > $limit) {
            throw new AiRequestException('invalid_request', 'A nonempty text list of at most '.$limit.' items is required.', 400);
        }
        foreach ($texts as $text) {
            if (! is_string($text) || $text === '' || strlen($text) > 65536) {
                throw new AiRequestException('invalid_request', 'Each item must be nonempty text of at most 65536 bytes.', 400);
            }
        }
    }

    private static function canonical(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (! array_is_list($v)) {
            ksort($v, SORT_STRING);
        }

        return array_map(self::canonical(...), $v);
    }

    private function checkMessage(mixed $m, int $i): void
    {
        if (! is_array($m) || ! in_array($m['role'] ?? null, self::ROLES, true)) {
            throw new AiRequestException('invalid_request', "پیامِ {$i} نقشِ معتبر ندارد.", 400);
        }
        if (array_diff(array_keys($m), ['role', 'content', 'name', 'tool_calls', 'tool_call_id', 'function_call']) !== []) {
            throw new AiRequestException('unsupported_parameter', 'Unsupported message options.', 400);
        }

        $content = $m['content'] ?? null;
        if ($content === null || is_string($content)) {
            return;
        }
        if (! is_array($content) || ! array_is_list($content)) {
            throw new AiRequestException('invalid_request', "محتوای پیامِ {$i} معتبر نیست.", 400);
        }
        foreach ($content as $part) {
            // تصویر/صدا/فایل هزینهٔ جدا دارند و در سقفِ بایتی نمی‌گنجند ⇒ رد، نه حدس
            if (! is_array($part) || ($part['type'] ?? null) !== 'text' || ! is_string($part['text'] ?? null)
                || array_diff(array_keys($part), ['type', 'text']) !== []) {
                throw new AiRequestException('unsupported_parameter', 'فقط محتوای متنی پشتیبانی می‌شود.', 400);
            }
        }
    }
}
