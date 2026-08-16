<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * سازنده سایت با هوش مصنوعی — نشست گفتگو با کاربر و تولید یک صفحه HTML کامل.
 * از GapGPT (سازگار با OpenAI) با مدل claude-sonnet-5 استفاده می‌کند.
 * تاریخچه گفتگو در کش نگه داشته می‌شود (کلید = شناسه نشست).
 */
class AiBuilderController extends Controller
{
    private const MAX_TURNS = 16;      // سقف پیام کاربر در یک نشست (کنترل هزینه)
    private const SESSION_TTL = 7200;  // ۲ ساعت

    /** آخرین خطای API برای عیب‌یابی (http + بخشی از بدنه) */
    private ?array $lastError = null;

    public function chat(Request $request): JsonResponse
    {
        // تولید یک صفحه‌ی کامل می‌تواند تا ~۲ دقیقه طول بکشد؛ نگذاریم PHP
        // با محدودیت پیش‌فرض ۳۰ ثانیه request را وسط کار بکشد.
        @set_time_limit(150);

        $data = $request->validate([
            'session' => 'required|string|max:64',
            'message' => 'required|string|max:2000',
            'pro'     => 'nullable|boolean',
        ]);

        return $this->handleTurn($data, null);
    }

    /**
     * نسخهٔ SSE همان گفتگو — دلیلش همان قاعدهٔ ثبت‌شدهٔ پروژه است:
     * گذرگاه پشت Cloudflare است و درخواستِ بی‌خروجی حدودِ ۱۰۰ ثانیه‌ای ۵۰۴
     * می‌گیرد. تولیدِ یک صفحهٔ کامل تا ~۲ دقیقه طول می‌کشد، پس مسیرِ JSONِ
     * ساده دقیقاً روی طولانی‌ترین (بهترین!) خروجی‌ها می‌بُرید.
     *
     * قرارداد با مرورگر: هر رویداد یک خط `data: {json}` است؛
     *   {d: '…'}                        تکهٔ تازهٔ متن (برای پیشرفتِ واقعی)
     *   {done: true, ok, reply, html…}  پاکتِ پایانی — همان شکلِ chat()
     *
     * ⚠️ builder.js اگر این مسیر در دسترس نبود (مثلاً opcache هنوز ریست نشده)
     * خودکار به chat()ِ قدیمی برمی‌گردد؛ پس این endpoint می‌تواند بعد از JS
     * دیپلوی شود بی‌آنکه صفحه بشکند.
     */
    public function stream(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        @set_time_limit(200);

        $data = $request->validate([
            'session' => 'required|string|max:64',
            'message' => 'required|string|max:2000',
            'pro'     => 'nullable|boolean',
        ]);

        return response()->stream(function () use ($data) {
            // بافرهای PHP/لاراول را کنار بزن وگرنه SSE تا پایانِ کار صف می‌شود.
            // ⚠️ نه در تست: بافرِ بیرونی مالِ خودِ PHPUnit است و حذفش سوئیت را می‌شکند.
            if (! app()->runningUnitTests()) {
                while (ob_get_level() > 0) {
                    @ob_end_clean();
                }
                @ob_implicit_flush(true);
            }

            $send = function (array $payload): void {
                echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE)."\n\n";
                flush();
            };

            $this->handleTurn($data, $send);
        }, 200, [
            'Content-Type'      => 'text/event-stream; charset=utf-8',
            'Cache-Control'     => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * یک نوبتِ گفتگو — مشترک بینِ chat() و stream().
     *
     * اگر `$send` داده شود، تکه‌های متن همان لحظه به مرورگر می‌روند و نتیجهٔ
     * نهایی هم به‌صورت رویدادِ `done` فرستاده می‌شود (خروجیِ JSON بی‌معنی است)؛
     * بی‌آن، همان JsonResponse قدیمی برمی‌گردد.
     */
    private function handleTurn(array $data, ?\Closure $send): JsonResponse
    {
        $finish = function (array $payload) use ($send): JsonResponse {
            if ($send) {
                $send(['done' => true] + $payload);
            }

            return response()->json($payload);
        };

        if (! config('services.gapgpt.key')) {
            return $finish(['ok' => false, 'error' => 'not_configured']);
        }

        $sid = 'builder:'.preg_replace('~[^a-zA-Z0-9-]~', '', $data['session']);
        $state = Cache::get($sid, ['turns' => 0, 'history' => [], 'html' => null]);

        if ($state['turns'] >= self::MAX_TURNS) {
            return $finish(['ok' => false, 'error' => 'limit', 'html' => $state['html']]);
        }

        $locale = app()->getLocale();
        $messages = array_merge(
            [['role' => 'system', 'content' => $this->systemPrompt($locale)]],
            $state['history'],
            [['role' => 'user', 'content' => $data['message']]],
        );

        $reply = $this->call($messages, ! empty($data['pro']), $send);
        if ($reply === null) {
            // اگر خطا از سقف/اعتبار سرویس هوش مصنوعی بود، پیام مناسب بده
            $isLimit = ($this->lastError['http'] ?? 0) === 429
                || str_contains(json_encode($this->lastError), 'api_limit');

            return $finish([
                'ok'    => false,
                'error' => $isLimit ? 'ai_busy' : 'ai_error',
                'html'  => $state['html'],
            ]);
        }

        [$chat, $html] = $this->split($reply);
        if ($html === null) {
            $html = $state['html']; // اگر این نوبت فقط گفتگو بود، آخرین سایت را نگه دار
        }

        $state['turns']++;
        $state['history'] = array_slice(array_merge($state['history'], [
            ['role' => 'user', 'content' => $data['message']],
            // برای صرفه‌جویی توکن، به‌جای HTML کامل خلاصه‌ای در تاریخچه نگه می‌داریم
            ['role' => 'assistant', 'content' => $chat.($html ? "\n[HTML site generated]" : '')],
        ]), -2 * self::MAX_TURNS);
        $state['html'] = $html;
        Cache::put($sid, $state, self::SESSION_TTL);

        return $finish([
            'ok'     => true,
            'reply'  => $chat,
            'html'   => $html,
            'turns'  => $state['turns'],
            'left'   => self::MAX_TURNS - $state['turns'],
        ]);
    }

    /** ذخیره سایت نهایی برای دپلوی — مرجع پیگیری برمی‌گرداند */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session' => 'required|string|max:64',
            'domain'  => 'nullable|string|max:100',
            'plan'    => 'nullable|string|max:40',
        ]);

        $sid = 'builder:'.preg_replace('~[^a-zA-Z0-9-]~', '', $data['session']);
        $state = Cache::get($sid);
        if (! $state || empty($state['html'])) {
            return response()->json(['ok' => false, 'error' => 'no_site']);
        }

        $ref = 'SB-'.strtoupper(Str::random(6));
        Cache::put('builder_deploy:'.$ref, [
            'html'    => $state['html'],
            'domain'  => $data['domain'] ?? null,
            'plan'    => $data['plan'] ?? null,
            'locale'  => app()->getLocale(),
            'at'      => now()->toIso8601String(),
        ], 604800); // ۷ روز

        // اطلاع به تیم فروش از طریق همان خط لوله n8n (اختیاری)
        if ($webhook = config('services.n8n.chat_webhook')) {
            $this->notify($webhook, $ref, $data);
        }

        return response()->json(['ok' => true, 'ref' => $ref]);
    }

    private function systemPrompt(string $locale): string
    {
        $lang = ['fa' => 'Persian (RTL)', 'tr' => 'Turkish', 'en' => 'English'][$locale] ?? 'English';

        return <<<PROMPT
        You are ServerNet's AI website builder. You help a non-technical user create their website by chatting.

        Rules:
        - Reply language: {$lang}. Keep the chat part short, friendly and encouraging (1-3 sentences).
        - After your short chat reply, ALWAYS output the COMPLETE website as ONE self-contained HTML document inside a single ```html code fence.
        - The HTML must be production-quality: a full <!doctype html> page with inline <style> (no external CSS/JS files, no CDN), modern responsive design, good typography, and tasteful colors. Embed any images as inline SVG or CSS gradients — never hotlink external images.
        - If the site language is Persian, set <html lang="fa" dir="rtl"> and use a clean sans-serif font stack.
        - Make it genuinely beautiful and modern: hero section, clear sections, call-to-action, footer. Use real, relevant sample content based on what the user described (not lorem ipsum).
        - On each new user message, REGENERATE the full updated HTML reflecting all requests so far. Never output partial HTML.
        - Do not include explanations outside the chat sentence and the code fence.
        PROMPT;
    }

    /**
     * فراخوانی GapGPT (chat completions سازگار با OpenAI).
     *
     * با `$onDelta` حالتِ استریم روشن می‌شود: هر تکهٔ متن همان لحظه به کلوژر
     * می‌رود (`['d' => '…']`) و اگر آپستریم چند لحظه فقط فکر کند (بی‌متن)،
     * یک کامنتِ ضربان فرستاده می‌شود تا Cloudflare اتصالِ ساکت را نبُرد —
     * همان الگوی تست‌شدهٔ `AiContent::call()`.
     */
    private function call(array $messages, bool $pro, ?\Closure $onDelta = null): ?string
    {
        $model = $pro ? config('services.gapgpt.model_pro') : config('services.gapgpt.model');
        $url = rtrim(config('services.gapgpt.base'), '/').'/chat/completions';

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0.7,
            'max_tokens'  => 8000,
        ];
        if ($onDelta) {
            $payload['stream'] = true;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer '.config('services.gapgpt.key'),
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $onDelta ? 170 : 120,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);

        $streamed = '';
        if ($onDelta) {
            $buf = '';
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($c, string $chunk) use (&$buf, &$streamed, $onDelta): int {
                $buf .= $chunk;
                $out = '';
                while (($nl = strpos($buf, "\n")) !== false) {
                    $line = trim(substr($buf, 0, $nl));
                    $buf = substr($buf, $nl + 1);
                    if (! str_starts_with($line, 'data:')) {
                        continue;
                    }
                    $data = trim(substr($line, 5));
                    if ($data === '' || $data === '[DONE]') {
                        continue;
                    }
                    $j = json_decode($data, true);
                    if (! is_array($j)) {
                        continue;
                    }
                    $choice = $j['choices'][0] ?? [];
                    $piece = $choice['delta']['content'] ?? $choice['message']['content'] ?? $choice['text'] ?? null;
                    if (is_string($piece) && $piece !== '') {
                        $streamed .= $piece;
                        $out .= $piece;
                    }
                }

                if ($out !== '') {
                    $onDelta(['d' => $out]);
                } else {
                    // بایتی رسید ولی متنی نبود (مثلاً reasoning) — اتصال را زنده نگه دار
                    echo ": hb\n\n";
                    flush();
                }

                return strlen($chunk);
            });
        }

        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false && $streamed === '') {
            Log::warning('AI builder curl: '.curl_error($ch));
            curl_close($ch);

            return null;
        }
        curl_close($ch);

        if ($onDelta) {
            if (trim($streamed) === '') {
                $this->lastError = ['http' => $code, 'model' => $model, 'body' => 'empty stream'];
                Log::warning('AI builder API (stream)', $this->lastError);

                return null;
            }

            return $streamed;
        }

        $d = json_decode((string) $raw, true);
        $content = $d['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            // خطای API را برای عیب‌یابی نگه دار (کلید نامعتبر، مدل ناشناخته و…)
            $this->lastError = ['http' => $code, 'model' => $model, 'body' => mb_substr((string) $raw, 0, 300)];
            Log::warning('AI builder API', $this->lastError);

            return null;
        }

        return $content;
    }

    /** جدا کردن متن گفتگو از بلوک HTML */
    private function split(string $reply): array
    {
        if (preg_match('~```(?:html)?\s*(.*?)```~is', $reply, $m)) {
            $html = trim($m[1]);
            $chat = trim(preg_replace('~```(?:html)?\s*.*?```~is', '', $reply));

            return [$chat !== '' ? $chat : '✓', $this->sanitize($html)];
        }

        // بدون code fence: اگر خودش HTML خام بود
        if (stripos($reply, '<!doctype') !== false || stripos($reply, '<html') !== false) {
            return ['✓', $this->sanitize($reply)];
        }

        return [trim($reply), null];
    }

    /** پاکسازی پایه‌ای HTML تولیدشده پیش از نمایش در iframe */
    private function sanitize(string $html): string
    {
        // حذف ارجاع‌های خارجی احتمالی برای امنیت و آفلاین بودن پیش‌نمایش
        $html = preg_replace('~<script\b[^>]*\bsrc=[^>]*>\s*</script>~i', '', $html);

        return trim($html);
    }

    private function notify(string $webhook, string $ref, array $data): void
    {
        $ch = curl_init($webhook);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'message' => "درخواست دپلوی سایت‌ساز AI — مرجع {$ref}، دامنه: ".($data['domain'] ?? '—').'، پلن: '.($data['plan'] ?? '—'),
                'locale'  => app()->getLocale(),
                'session' => 'builder-'.$ref,
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
