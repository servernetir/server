<?php

namespace App\Services\Crm;

use App\Services\AiContent;

/**
 * نویسندهٔ پیامِ فروش — روی `AiContent` سوار می‌شود، نه کنارش.
 *
 * چرا ارث‌بری و نه یک سرویسِ تازه: مسیریابیِ ارائه‌دهنده (`ai_routing`)، مدیریتِ
 * کلید، تایم‌اوت و پارسِ JSON از قبل آن‌جا حل شده و تست شده. ساختنِ نسخهٔ دوم
 * یعنی دو جا برای خراب شدن. فقط `purpose` عوض می‌شود تا اگر روزی خواستی
 * برای فروش مدلِ دیگری بگذاری، یک متغیرِ محیطی کافی باشد:
 *
 *     AI_PROVIDER_OUTREACH=deepseek
 */
class OutreachWriter extends AiContent
{
    public function __construct()
    {
        $this->purpose = 'outreach';
    }

    /**
     * از گزارشِ SiteAudit یک «مشاهدهٔ مشخص» بیرون می‌کشد.
     *
     * 🔴 اگر مدل چیزِ مشخصی پیدا نکرد، **باید** null برگرداند. سرنخِ بدونِ
     * مشاهده دور انداخته می‌شود — این همان قانونِ ۶۰ ثانیه است، در سطحِ کد.
     * پیامِ بی‌ربط چیزی است که اکانت و اعتبار را می‌سوزاند، نه کمبودِ حجم.
     */
    public function observe(array $lead, array $audit): ?string
    {
        $sys = <<<'TXT'
You analyse a clinic's website audit and find ONE specific, verifiable problem
worth mentioning in a cold email.

Rules:
- Say only what the audit data supports. Never guess, never generalise.
- Clinics in this market usually have modern, fast sites. If speed is fine, say
  so implicitly by choosing a different, real gap: no pricing, no patient
  reviews, no practitioner bios, no Arabic version, broken booking flow.
- No numbers, percentages or statistics of any kind.
- If nothing specific and true stands out, reply exactly: NONE
- Reply with one or two plain sentences. No greeting, no pitch, no formatting.
TXT;

        $user = "Clinic: {$lead['company']}\nURL: {$lead['website']}\n\nAudit JSON:\n"
            .json_encode($this->trimAudit($audit), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $out = trim((string) $this->call($sys, $user, 400, 90));

        if ($out === '' || str_contains(strtoupper($out), 'NONE')) {
            return null;
        }

        return $out;
    }

    /**
     * ایمیلِ بیرونی. `$sequence` صفر یعنی پیامِ اول، ۱ و ۲ فالوآپ.
     *
     * @return array{subject: string, body: string}|null
     */
    public function email(array $lead, int $sequence = 0): ?array
    {
        $from = config('crm.from');
        $risk = config('crm.risk_reversal');

        $tone = match ($sequence) {
            0 => 'First contact. Lead with the observation, not with yourself.',
            1 => 'Short follow-up, five days later. Two or three sentences. Offer an easy way out: tell them to reply "no" and you will stop.',
            2 => 'Final note. Give something useful with no strings, wish them well, and stop. Do not ask for anything.',
            default => 'Short and polite.',
        };

        $sys = <<<TXT
You write cold B2B emails for {$from['name']}, {$from['title']}.

He builds websites for clinics AND runs his own hosting company (15 years), so
speed, uptime, backups and security are his responsibility rather than a
vendor's. He was also CTO of a manufacturer for 7 years. That is the whole
credibility stack — do not embellish it.

HARD RULES — breaking any of these makes the email unusable:
- No numbers, percentages, statistics or "Nx" claims. He has never measured KPIs.
- No invented clients, case studies or testimonials.
- No fake urgency, no scarcity, no deadlines.
- No discounts. If you need to lower risk, use exactly: "{$risk}"
- Never claim to be located anywhere he is not.

Style: plain, specific, short. Sound like a competent person who looked at
their site, not like marketing. British/neutral English. No em dashes.
Always end the body with an easy opt-out: reply "no" and he won't write again.

{$tone}

Return JSON only: {"subject": "...", "body": "..."}
Subject: under 60 characters, curiosity not accusation, no company name shouting.
Body: plain text, no signature (it is appended separately).
TXT;

        $user = "Clinic: {$lead['company']}\n"
            ."City/Country: ".trim(($lead['city'] ?? '').' '.($lead['country'] ?? ''))."\n"
            ."Website: {$lead['website']}\n"
            ."Observation to open with: {$lead['observation']}";

        $raw = $this->call($sys, $user, 900, 120);
        if (! $raw) {
            return null;
        }

        $j = $this->json($raw);
        if (! is_array($j) || blank($j['subject'] ?? null) || blank($j['body'] ?? null)) {
            return null;
        }

        return [
            'subject' => trim((string) $j['subject']),
            'body'    => trim((string) $j['body']),
        ];
    }

    /**
     * پیامِ کوتاهِ لینکدین یا اینستاگرام — برای اینکه **انسان** بفرستد.
     *
     * 🔴 چرا اینجا هست ولی ارسالش خودکار نیست: اتوماتیکِ لینکدین و اینستاگرام
     * نقضِ شرایطشان است و اکانت را می‌سوزاند. ولی کارِ سختِ این پیام‌ها نوشتنِ
     * آن‌هاست، نه کلیک کردن. سیستم سختش را می‌کند، آدم آسانش را.
     *
     * @param  'linkedin'|'instagram'  $channel
     * @param  'note'|'dm'  $kind   یادداشتِ درخواستِ ارتباط، یا پیامِ مستقیم
     * @return array{body: string, limit: int}|null
     */
    public function social(array $lead, string $channel, string $kind = 'dm'): ?array
    {
        $limit = (int) config("crm.social.{$channel}.{$kind}", 900);
        $from  = config('crm.from');

        $shape = match ("{$channel}.{$kind}") {
            'linkedin.note' => "A LinkedIn connection request note. Under {$limit} characters, hard limit. "
                ."One or two sentences. Say why you are reaching out to THEM specifically, using the "
                ."observation. Do not pitch, do not ask for a call, do not include any link. "
                ."The goal is only that they accept.",
            'linkedin.dm' => "A LinkedIn message to someone who already accepted the connection. "
                ."Three or four short sentences. Lead with the observation, say in one line what you "
                ."would do about it, and end with a low-friction question they can answer with one line.",
            default => "An Instagram direct message. Two or three short sentences, warmer and plainer "
                ."than email, no formatting, no bullet points. Lead with the observation. End with a "
                ."question that is easy to answer. Never open with 'Hi, I am a web designer'.",
        };

        $sys = <<<TXT
You write short outbound social messages for {$from['name']}, {$from['title']}.

He builds websites for clinics AND runs his own hosting company (15 years), so
speed, uptime, backups and security are his responsibility rather than a
vendor's. That is the whole credibility stack — do not embellish it.

HARD RULES — breaking any makes the message unusable:
- No numbers, percentages, statistics or "Nx" claims. He has never measured KPIs.
- No invented clients, case studies or testimonials.
- No urgency, no scarcity, no discounts.
- Never claim to be located anywhere he is not.
- No emoji. No hashtags. No "Hope you are doing well".

{$shape}

Return JSON only: {"body": "..."}
The body MUST be under {$limit} characters including spaces. Count before you answer.
TXT;

        $user = "Business: {$lead['company']}\n"
            ."City/Country: ".trim(($lead['city'] ?? '').' '.($lead['country'] ?? ''))."\n"
            ."Website: {$lead['website']}\n"
            ."Observation to open with: {$lead['observation']}";

        $raw = $this->call($sys, $user, 700, 90);

        if (! $raw) {
            return null;
        }

        $j = $this->json($raw);
        $body = trim((string) ($j['body'] ?? ''));

        if ($body === '') {
            return null;
        }

        $body = $this->fit($body, $limit);

        return $body === null ? null : ['body' => $body, 'limit' => $limit];
    }

    /**
     * متن را زیرِ سقف می‌آورد — ولی **وسطِ جمله نمی‌بُرد**.
     *
     * ⚠️ اگر حتی جملهٔ اول هم از سقف بلندتر بود، `null` برمی‌گردد. پیامی که با
     * «…» تمام شود، از نفرستادن بدتر است: طرف اولین برداشتش این است که با یک
     * ربات طرف است.
     */
    protected function fit(string $body, int $limit): ?string
    {
        if (mb_strlen($body) <= $limit) {
            return $body;
        }

        $cut = mb_substr($body, 0, $limit);
        $end = max(
            mb_strrpos($cut, '.') ?: 0,
            mb_strrpos($cut, '?') ?: 0,
            mb_strrpos($cut, '!') ?: 0,
        );

        return $end > 0 ? trim(mb_substr($cut, 0, $end + 1)) : null;
    }

    /**
     * گزارشِ ممیزی سنگین است و بیشترش به درد مدل نمی‌خورد. فقط چیزهایی که
     * «مشکل» هستند + متادیتای صفحه فرستاده می‌شود؛ هم ارزان‌تر، هم دقیق‌تر.
     */
    protected function trimAudit(array $audit): array
    {
        $bad = [];

        foreach (($audit['checks'] ?? []) as $cat => $checks) {
            foreach ((array) $checks as $ch) {
                if (($ch['status'] ?? 'pass') !== 'pass') {
                    $bad[$cat][] = $ch;
                }
            }
        }

        return [
            'overall' => $audit['overall'] ?? null,
            'scores'  => $audit['scores'] ?? [],
            'meta'    => $audit['meta'] ?? [],
            'issues'  => $bad,
        ];
    }
}
