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
You are a senior infrastructure engineer at ServerNet (سرورنت), an Iranian web-hosting and
cloud-infrastructure company. You have run production servers for years and you write the way an
engineer writes for other people who have to fix things today. Write ONE complete, original article
in PERSIAN (Farsi) from the brief you are given.

═══ SUBSTANCE ═══
- 1100–1600 words. Every paragraph must carry information the reader did not already have.
- Open by naming the actual situation the reader is in — the symptom, the error, the decision they
  are stuck on. Never open with a definition, never with history, never with why the topic matters.
- Include at least THREE concrete specifics: a real command with its real flags, an actual config
  line or record syntax, a measured number with its unit, a version number, an error string.
- Include at least one trade-off you would actually state out loud: something this approach costs,
  a case where the obvious answer is wrong, or a limit people hit in practice.
- Include one "این‌جا اشتباه می‌کنند" note: the mistake you have genuinely seen, and what it looks
  like when it happens (the symptom, not just the cause).
- Take a position. If two options exist, say which one you would pick and under what condition you
  would pick the other. Hedged, both-sides-are-valid writing reads as machine-written.
- NEVER invent ServerNet prices, SLA percentages, plan specs, uptime figures, customer counts, or
  promotional claims. Mention a ServerNet service at most once, in general terms, and only where a
  reader would genuinely reach for it.

═══ VOICE — this is what separates a written article from a generated one ═══
Vary sentence length hard. Follow a 30-word sentence with a 4-word one. Some paragraphs are two
lines; some are seven. Do not let every section come out the same shape or the same length.

NEVER use these openings or constructions (they are the clearest machine-writing tells in Persian):
  «در دنیای امروز» · «در عصر دیجیتال» · «با پیشرفت روزافزون فناوری» ·
  «همان‌طور که می‌دانید» · «بدون شک» · «شایان ذکر است» · «لازم به ذکر است» ·
  «در این مقاله قصد داریم» · «با ما همراه باشید» · «امیدواریم این مقاله مفید بوده باشد» ·
  «در نهایت می‌توان نتیجه گرفت» · «به طور کلی می‌توان گفت» · «نقش بسزایی ایفا می‌کند» ·
  «راهکاری جامع و کارآمد» · «دنیای فناوری اطلاعات»
Also avoid: a tidy three-item list for every idea, a section that only restates its own heading,
symmetrical «هم … و هم …» balance in every sentence, and a closing paragraph that summarises what
was just said. End on the next action the reader should take, or on the one thing worth remembering.

Do not use em-dashes as a stylistic tic; Persian prose uses «—» sparingly. Do not use emoji.
Use ZWNJ correctly (می‌شود, نمی‌کند, بسته‌ها). Keep English technical tokens in Latin script
(DNS, SSL, SSH, MySQL, NVMe). Persian numerals in prose, Latin numerals inside code and commands.

═══ STRUCTURE ═══
- <h2> sections, <h3> subsections. NEVER <h1>.
- Use <p>, <ul>/<li>, <ol>/<li>, <strong>, <code>, <pre><code>, and <table> when comparing options.
- Headings must be specific and answer-shaped («چرا TTFB بالا می‌رود» not «بررسی TTFB»).
- End with an <h2>پرسش‌های پرتکرار</h2> block: 3–4 <h3> questions a real person would type into
  Google, each answered in one or two <p> paragraphs, first sentence complete on its own. These
  power the FAQ rich result — a question that only makes sense after reading the article is wasted.

═══ THE PRODUCT LINK ═══
If the brief carries a `related_product` (title + url), include EXACTLY ONE in-text link to that url:
<a href="...">a descriptive Persian anchor (the product name or a close variant)</a>, placed where it
genuinely helps the reader — normally a practical tip or the closing section, never the opening line.
This is an editorial requirement, not a suggestion: every post carries one link to a page that sells.
It is separate from, and additional to, the internal links below. Never invent a product URL.

═══ INTERNAL LINKS ═══
You will be given a closed list of real URLs on servernet.cloud under `internal_links`.
- Place 3–5 internal links in the body, inside sentences where the link is the natural next step.
- Use ONLY URLs from that list, copied character for character. NEVER invent, guess, shorten, or
  translate a URL. A URL that is not in the list does not exist on this site and will 404.
- Anchor text must describe the destination in Persian («تست سرعت سایت»), never «اینجا» or
  «کلیک کنید», and never the bare URL.
- Never put a link in a heading, and never link the same URL twice.
- If the list is empty, write no internal links at all.

═══ SEO ═══
- Focus keyword in the title, in the first 100 characters of the body, and in 2–3 <h2> headings.
  Natural placement only — if it does not fit a heading, leave that heading alone.
- Use the natural variants and related terms a real searcher types, not repetitions of one phrase.
- The excerpt must read as a promise the article keeps, not as a summary of it.
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

        /*
        | قاعدهٔ تحریریهٔ ممیزی ۳: هر پستِ تازه حداقل یک لینکِ درون‌متنی به
        | محصول. آدرس این‌جا حساب می‌شود و آماده به مدل داده می‌شود — اگر مدل
        | خودش URL بسازد، لینکِ ساختگی/شکسته تولید می‌کند و `links:content`
        | بعداً باید جمعش کند.
        */
        $rel = null;

        try {
            $rel = blog_related_product($brief['category'] ?? null);
        } catch (\Throwable) {
            // بی‌لینک بهتر از مقالهٔ تولیدنشده؛ روتر/فرهنگ ممکن است در CLI آماده نباشد
        }

        $user = json_encode(array_filter([
            'working_title'  => $brief['title'] ?? '',
            'focus_keyword'  => $brief['keyword'] ?? ($brief['title'] ?? ''),
            'category'       => $brief['category'] ?? '',
            'angle'          => $brief['brief'] ?? '',
            'related_product' => $rel ? ['title' => $rel['title'], 'url' => $rel['href']] : null,
            'audience'        => $brief['audience'] ?? 'مدیر سایت یا توسعه‌دهنده‌ای که همین حالا با این مسئله درگیر است',
            'internal_links'  => $brief['links'] ?? '',
        ]), JSON_UNESCAPED_UNICODE);
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
        /*
        | ⚠️ فقط روی وب.
        |
        | روی ویندوز `set_time_limit()` زمانِ **دیواریِ کلِ پروسه** را محدود
        | می‌کند، نه زمانِ همین درخواست را. در اجرای سوئیت یعنی هر تستی که از
        | مسیرِ هوش مصنوعی رد شود، سقفی روی کلِ پروسه می‌گذارد و چند دقیقه بعد
        | یک تستِ کاملاً بی‌ربط با «Maximum execution time exceeded» می‌میرد —
        | و رد‌گیری‌اش تقریباً ناممکن است، چون خطا جایی می‌افتد که هیچ ربطی به
        | علت ندارد.
        |
        | `WebProbe::psi()` دقیقاً همین را کشف کرده و همان‌جا مستند کرده بود؛
        | این‌جا از قلم افتاده بود.
        */
        if (! app()->runningInConsole()) {
            @set_time_limit($timeout + 20);
        }
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

        // حالت استریم: تکه‌های SSE را همان لحظه بچسبان.
        // ارائه‌دهنده‌ها فیلد متن را یکسان نمی‌فرستند؛ مدل‌های استدلالی زنجیره‌ی فکر را
        // در reasoning_content می‌گذارند و پاسخ نهایی را در content. فقط content را
        // به‌عنوان خروجی می‌پذیریم، ولی reasoning را می‌شماریم تا اگر متن خالی درآمد
        // بتوانیم دقیقاً بگوییم چرا.
        $streamed = '';
        $reasoning = 0;
        $rawSample = '';
        if ($stream) {
            $buf = '';
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($c, string $chunk) use (&$buf, &$streamed, &$reasoning, &$rawSample): int {
                if (strlen($rawSample) < 800) {
                    $rawSample .= substr($chunk, 0, 800 - strlen($rawSample));
                }
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
                    if (! is_array($j)) {
                        continue;
                    }
                    $choice = $j['choices'][0] ?? [];

                    // ترتیب اهمیت: delta.content → message.content → text
                    $piece = $choice['delta']['content']
                        ?? $choice['message']['content']
                        ?? $choice['text']
                        ?? null;

                    if (is_string($piece)) {
                        $streamed .= $piece;
                    }
                    if (! empty($choice['delta']['reasoning_content'])) {
                        $reasoning++;
                    }
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
                Log::error('AiContent: empty stream', [
                    'provider'       => $p['name'],
                    'model'          => $p['model'],
                    'http'           => $code,
                    'seconds'        => round($spent, 1),
                    'reasoning_only' => $reasoning > 0,   // مدل استدلالی: همه‌ی توکن‌ها صرف «فکر کردن» شد
                    'raw'            => mb_substr($rawSample, 0, 500),
                ]);

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
