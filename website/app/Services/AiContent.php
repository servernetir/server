<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * سرویس هوش مصنوعی محتوا — ترجمه‌ی خودکار و تحلیل سئو با GapGPT (claude-fable-5).
 */
class AiContent
{
    /** کاری که الان در حال انجام است — تعیین می‌کند کدام ارائه‌دهنده استفاده شود */
    protected string $purpose = 'article';

    public function enabled(): bool
    {
        return (bool) (config('services.gapgpt.key') || config('services.deepseek.key'));
    }

    /**
     * ارائه‌دهنده‌ی این کار: از config/services.ai_routing خوانده می‌شود.
     * اگر کلیدش تنظیم نشده باشد به gapgpt برمی‌گردد تا چیزی از کار نیفتد.
     */
    protected function provider(string $purpose): array
    {
        $name = (string) config('services.ai_routing.'.$purpose, 'gapgpt');
        $cfg = config('services.'.$name);

        if (! is_array($cfg) || empty($cfg['key'])) {
            $name = 'gapgpt';
            $cfg = config('services.gapgpt');
        }

        return [
            'name'  => $name,
            'key'   => $cfg['key'] ?? '',
            'base'  => $cfg['base'] ?? '',
            'model' => $cfg['model'] ?? '',
        ];
    }

    /**
     * ترجمه‌ی یک پست از فارسی به زبان مقصد. خروجی: ['title','excerpt','content','tags'] یا null.
     */
    public function translate(array $fa, string $target): ?array
    {
        $this->purpose = 'translate';
        $lang = ['en' => 'English', 'tr' => 'Turkish'][$target] ?? 'English';
        $sys = "You are a professional translator and localizer for a web-hosting company (ServerNet). "
            ."Translate the given blog post from Persian to {$lang}. Keep every HTML tag and the structure "
            ."exactly intact — translate only human-readable text. Keep technical terms accurate and keep "
            ."English technical tokens (DNS, SSL, SSH, MySQL…) as-is.\n\n"
            ."Return PLAIN TEXT in EXACTLY this delimited format — no JSON, no markdown fences, no commentary:\n\n"
            ."###TITLE###\n<translated title>\n###EXCERPT###\n<translated excerpt>\n"
            ."###TAGS###\n<translated tags separated by commas>\n###CONTENT###\n<translated HTML body>";

        $user = "TITLE:\n".($fa['title'] ?? '')."\n\nEXCERPT:\n".($fa['excerpt'] ?? '')
            ."\n\nTAGS:\n".implode(', ', (array) ($fa['tags'] ?? []))
            ."\n\nCONTENT:\n".($fa['content'] ?? '');

        $out = $this->call($sys, $user, 8000, 280, true);
        if ($out === null) {
            return null;
        }

        $p = $this->delimited($out);
        if ($p['title'] === '' || $p['content'] === '') {
            Log::error('AiContent::translate unparsable', [
                'target' => $target, 'length' => mb_strlen($out), 'preview' => mb_substr($out, 0, 300),
            ]);

            return null;
        }

        return $p;
    }

    /** پارس خروجی قالب ###TAG### — در برابر بریده‌شدن پاسخ مقاوم است */
    private function delimited(string $out): array
    {
        $part = function (string $tag, string $next = '') use ($out): string {
            $end = $next !== '' ? '###'.$next.'###' : '\z';
            if (preg_match('~###'.$tag.'###(.*?)(?='.$end.')~su', $out, $m)) {
                return trim($m[1]);
            }

            return '';
        };

        return [
            'title'   => mb_substr($part('TITLE', 'EXCERPT'), 0, 200),
            'excerpt' => mb_substr($part('EXCERPT', 'TAGS'), 0, 500),
            // خروجی مدل قابل‌اعتماد نیست: تزریق پرامپت می‌تواند <script> تولید کند
            'content' => HtmlSanitizer::clean($part('CONTENT')),
            'tags'    => array_values(array_filter(array_map('trim', explode(',', $part('TAGS', 'CONTENT'))))),
        ];
    }

    /**
     * نگارش یک مقاله‌ی کامل فارسی از روی بریف. خروجی: ['title','excerpt','content','tags'] یا null.
     */
    public function article(array $brief): ?array
    {
        $this->purpose = 'article';
        $sys = <<<'TXT'
You are a senior technical writer for ServerNet (سرورنت), an Iranian web-hosting and cloud-infrastructure
company. Write ONE complete, original blog article in PERSIAN (Farsi) from the brief you are given.

Requirements:
- 900–1400 words. Genuinely useful and specific — not filler, not marketing fluff.
- Write for a reader who is trying to solve a real problem. Lead with what matters.
- Structure with <h2> sections and <h3> subsections. Use <p>, <ul>/<li>, <ol>/<li>,
  <strong>, <code> and <pre><code> for commands/config. NEVER use <h1>.
- Include concrete details: real command examples, actual record syntax, realistic numbers,
  and at least one "common mistake" or troubleshooting note.
- NEVER invent ServerNet prices, SLA figures, plan specs, or promotional claims. You may mention that
  ServerNet offers a relevant service in general terms, at most once, and only where it genuinely helps.
- Persian technical writing: use standard Persian terms, keep English technical tokens in Latin script
  (DNS, SSL, SSH, MySQL…). Use ZWNJ correctly (می‌شود, نمی‌کند).
- SEO: work the focus keyword naturally into the title, the opening paragraph and 2–3 headings.
  Do not keyword-stuff.

Return PLAIN TEXT in EXACTLY this delimited format — no JSON, no markdown fences, no commentary:

###TITLE###
<45–65 character Persian title containing the focus keyword>
###EXCERPT###
<120–158 character Persian summary usable as a meta description>
###TAGS###
<4–6 Persian tags separated by commas>
###CONTENT###
<the article body as clean HTML: no <html>/<body> wrapper, no <h1>>
TXT;

        $user = json_encode([
            'working_title'  => $brief['title'] ?? '',
            'focus_keyword'  => $brief['keyword'] ?? ($brief['title'] ?? ''),
            'category'       => $brief['category'] ?? '',
            'angle'          => $brief['brief'] ?? '',
        ], JSON_UNESCAPED_UNICODE);

        $out = $this->call($sys, $user, 8000, 280, true);
        if ($out === null) {
            return null;
        }

        $p = $this->delimited($out);
        if ($p['title'] === '' || $p['content'] === '') {
            Log::error('AiContent::article unparsable', [
                'slug'    => $brief['title'] ?? '',
                'length'  => mb_strlen($out),
                'preview' => mb_substr($out, 0, 300),
            ]);

            return null;
        }

        return $p;
    }

    /**
     * تحلیل سئوی یک پست فارسی. خروجی: ['score'=>int, 'items'=>[['type'=>ok|warn|bad,'text'=>...]]].
     */
    public function seo(array $fa): ?array
    {
        $this->purpose = 'seo';
        $sys = "You are an expert SEO reviewer. Analyze the given blog post (Persian) and return STRICT JSON: "
            ."{\"score\": <0-100 integer>, \"items\": [{\"type\": \"ok|warn|bad\", \"text\": \"<short actionable note in Persian>\"}]}. "
            ."Check: title length (ideal 30-65 chars), meta/excerpt length (ideal 70-160), heading structure (H2/H3), "
            ."keyword usage and focus, readability, internal-link opportunities, content length. "
            ."Give 5-8 concise, specific items in Persian. No commentary outside JSON.";
        $user = json_encode([
            'title'   => $fa['title'] ?? '',
            'excerpt' => $fa['excerpt'] ?? '',
            'content' => mb_substr(strip_tags($fa['content'] ?? ''), 0, 4000),
            'headings' => $this->headings($fa['content'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);

        $out = $this->call($sys, $user, 1500);
        if ($out === null) {
            return null;
        }
        $j = $this->json($out);
        if (! $j || ! isset($j['items'])) {
            return null;
        }

        return [
            'score' => (int) ($j['score'] ?? 0),
            'items' => array_map(fn ($i) => [
                'type' => in_array(($i['type'] ?? ''), ['ok', 'warn', 'bad'], true) ? $i['type'] : 'warn',
                'text' => (string) ($i['text'] ?? ''),
            ], array_slice((array) $j['items'], 0, 10)),
        ];
    }

    private function headings(string $html): array
    {
        preg_match_all('~<(h[1-3])[^>]*>(.*?)</\1>~is', $html, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => strtoupper($x[1]).': '.trim(strip_tags($x[2])), array_slice($m, 0, 20));
    }

    /**
     * فراخوانی مدل. برای پاسخ‌های بلند حتماً $stream=true بدهید:
     * درگاه پشت Cloudflare است و درخواست‌های بدون خروجی بعد از ~۱۰۰ ثانیه با 504 قطع می‌شوند.
     * در حالت استریم، بایت‌ها پیوسته می‌رسند و اتصال باز می‌ماند.
     */
    protected function call(string $system, string $user, int $maxTokens, int $timeout = 140, bool $stream = false): ?string
    {
        @set_time_limit($timeout + 20);
        $p = $this->provider($this->purpose);
        $url = rtrim($p['base'], '/').'/chat/completions';
        $ch = curl_init($url);

        $payload = [
            'model'       => $p['model'],
            'messages'    => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            'temperature' => 0.3,
            'max_tokens'  => $maxTokens,
        ];
        if ($stream) {
            $payload['stream'] = true;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer '.$p['key']],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        // حالت استریم: تکه‌های SSE را همان لحظه بچسبان
        $streamed = '';
        if ($stream) {
            $buf = '';
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($c, string $chunk) use (&$buf, &$streamed): int {
                $buf .= $chunk;
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
                    $streamed .= $j['choices'][0]['delta']['content'] ?? '';
                }

                return strlen($chunk);
            });
        }
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $spent = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);
        if ($raw === false) {
            Log::error('AiContent: curl failed', [
                'provider' => $p['name'],
                'error' => $err, 'seconds' => round($spent, 1), 'timeout' => $timeout, 'max_tokens' => $maxTokens,
            ]);

            return null;
        }
        if ($stream) {
            if (trim($streamed) === '') {
                Log::error('AiContent: empty stream', ['http' => $code, 'seconds' => round($spent, 1)]);

                return null;
            }

            return $streamed;
        }

        $d = json_decode($raw, true);
        $content = $d['choices'][0]['message']['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            Log::error('AiContent', ['provider' => $p['name'], 'http' => $code, 'body' => mb_substr((string) $raw, 0, 200)]);

            return null;
        }

        return $content;
    }

    /** استخراج JSON از پاسخ (حتی اگر داخل ```json باشد) */
    protected function json(string $s): ?array
    {
        if (preg_match('~```(?:json)?\s*(\{.*\})\s*```~is', $s, $m)) {
            $s = $m[1];
        } elseif (preg_match('~(\{.*\})~s', $s, $m)) {
            $s = $m[1];
        }
        $j = json_decode(trim($s), true);

        return is_array($j) ? $j : null;
    }
}
