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
        if (isset($client['stream']) && $client['stream'] === true) {
            throw new AiRequestException('stream_unsupported',
                'استریم هنوز فعال نیست؛ stream را false بفرستید.', 400);
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

        $bytes = strlen((string) json_encode($forward, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $input = $bytes + 16 * count($messages) + 256;

        return new AiPreparedRequest(
            forward: $forward,
            maxInput: $input,
            maxOutput: $output,
            outputCap: $cap,
            clientOmittedMaxTokens: $omitted,
            dropped: $dropped,
            sha256: self::hash($client),
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

        $content = $m['content'] ?? null;
        if ($content === null || is_string($content)) {
            return;
        }
        if (! is_array($content) || ! array_is_list($content)) {
            throw new AiRequestException('invalid_request', "محتوای پیامِ {$i} معتبر نیست.", 400);
        }
        foreach ($content as $part) {
            // تصویر/صدا/فایل هزینهٔ جدا دارند و در سقفِ بایتی نمی‌گنجند ⇒ رد، نه حدس
            if (! is_array($part) || ($part['type'] ?? null) !== 'text' || ! is_string($part['text'] ?? null)) {
                throw new AiRequestException('unsupported_parameter', 'فقط محتوای متنی پشتیبانی می‌شود.', 400);
            }
        }
    }
}
