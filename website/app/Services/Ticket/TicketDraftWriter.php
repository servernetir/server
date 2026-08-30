<?php

namespace App\Services\Ticket;

use App\Models\Ticket;
use App\Services\AiContent;

/**
 * پیش‌نویسِ پاسخِ تیکت با هوشِ مصنوعی.
 *
 * 🔴 **پیش‌نویس، نه ارسال.** خروجیِ این کلاس هرگز مستقیم به مشتری نمی‌رود؛
 * کارفرما در بله می‌بیندش و اگر پسندید دکمهٔ ارسال را می‌زند (که خودش کدِ تأیید
 * می‌خواهد). دلیلش یک ترجیح نیست:
 *
 *   • مدل قیمت و مهلت و سیاستِ بازگشتِ وجه را از خودش می‌سازد، و یک جملهٔ
 *     «سرورتان را برمی‌گردانیم» یک تعهدِ واقعی است
 *   • پاسخِ پشتیبانی برگشت‌ناپذیر است: پیامک و بله و ایمیل هم‌زمان می‌رود
 *
 * با تأییدِ آدم، همهٔ سرعتش را داریم و هیچ‌کدام از ریسکش را.
 *
 * ⚠️ روی `AiContent` سوار می‌شود نه کنارش: مسیریابیِ ارائه‌دهنده، کلید،
 * تایم‌اوت و پارس از قبل آن‌جا حل شده. فقط `purpose` عوض می‌شود تا اگر روزی
 * خواستی مدلِ دیگری برای پشتیبانی بگذاری، یک متغیرِ محیطی کافی باشد.
 */
class TicketDraftWriter extends AiContent
{
    /** لحن‌هایی که کارفرما می‌تواند با دکمه بینشان بچرخد */
    public const TONES = [
        'n' => 'معمولی و دوستانه',
        's' => 'کوتاه و مستقیم — حداکثر دو جمله',
        'f' => 'رسمی و محترمانه',
        'a' => 'با عذرخواهی بابتِ مشکل پیش‌آمده',
    ];

    /** چند پیامِ آخرِ گفتگو به مدل داده می‌شود */
    private const CONTEXT_MESSAGES = 6;

    public function __construct()
    {
        $this->purpose = 'support';
    }

    /**
     * یک پیش‌نویس بساز، یا null اگر مدل جواب نداد.
     *
     * ⚠️ `null` عمداً از «متنِ خالی» جدا است: فراخوان باید بتواند بگوید
     * «پیش‌نویس ساخته نشد» و نه اینکه پیامِ خالی به کارفرما نشان دهد.
     */
    public function draft(Ticket $ticket, string $tone = 'n'): ?string
    {
        $toneText = self::TONES[$tone] ?? self::TONES['n'];

        /*
        | ⚠️ فقط `visibleMessages()` — یادداشتِ داخلی به مدل داده نمی‌شود.
        |
        | آن یادداشت‌ها عمداً از مشتری پنهان‌اند («این مشتری بدحساب است»)، و
        | مدلی که ببیندشان لحنش را رویشان تنظیم می‌کند یا بدتر، بازتابشان
        | می‌دهد. دادهٔ پنهان نباید وارد متنی شود که قرار است به همان مشتری
        | برسد.
        */
        $thread = $ticket->visibleMessages()
            ->orderByDesc('id')->limit(self::CONTEXT_MESSAGES)->get()->reverse()
            ->map(fn ($m) => [
                'who'  => $m->author_role === 'staff' ? 'support' : 'customer',
                'text' => mb_substr((string) $m->body, 0, 1500),
            ])->values()->all();

        if ($thread === []) {
            return null;
        }

        $sys = <<<'TXT'
You draft support replies for ServerNet, a small Iranian hosting company
(shared hosting, VPS, domains). You write in PERSIAN.

Return ONLY the reply body. No greeting line like "با سلام" unless it fits
naturally, no signature, no subject line, no markdown, no quotes around it.

Hard rules — breaking any of these costs the company real money:
- NEVER promise a refund, a discount, a credit, a deadline, or an SLA figure.
- NEVER state a price, a plan name, or a technical limit you were not given.
- NEVER invent an order number, an invoice number, an IP, or a date.
- If solving it needs information you do not have, ask the customer for exactly
  that one thing instead of guessing.
- If the request is outside what a hosting provider can do, say so plainly and
  briefly.
- Do not apologise more than once.
- Write like a competent human colleague: short sentences, no filler, no
  corporate padding, no exclamation marks.
TXT;

        $payload = [
            'subject'    => mb_substr((string) $ticket->subject, 0, 200),
            'department' => (string) $ticket->department,
            'tone'       => $toneText,
            'thread'     => $thread,
        ];

        try {
            $raw = $this->call($sys, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 900, 60);
        } catch (\Throwable) {
            return null;
        }

        $text = trim((string) $raw);

        // مدل گاهی متن را داخلِ گیومه یا بلوکِ کد می‌گذارد
        $text = trim(preg_replace('/^```[a-z]*\s*|\s*```$/u', '', $text) ?? $text);
        $text = trim($text, "\"'«» \n\r\t");

        return $text !== '' ? mb_substr($text, 0, 3000) : null;
    }
}
