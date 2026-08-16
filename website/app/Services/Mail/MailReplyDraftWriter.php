<?php

namespace App\Services\Mail;

use App\Models\MailboxMessage;
use App\Services\AiContent;

/**
 * پیش‌نویسِ پاسخِ یک ایمیلِ صندوقِ ورودی.
 *
 * 🔴 **پیش‌نویس، و فقط پیش‌نویس.** برخلافِ تیکت، این‌جا حتی دکمهٔ ارسال هم
 * وجود ندارد — و نبودش عمدی نیست، **واقعیتِ سامانه** است: صندوق فقط با IMAP
 * خوانده می‌شود (`MailboxSync`) و هیچ مسیرِ SMTPِ پاسخ‌دهی در این اپ نیست.
 *
 * پس متن به کارفرما داده می‌شود تا در برنامهٔ ایمیلِ خودش بفرستد. اگر روزی
 * مسیرِ ارسال ساخته شد، همان‌وقت باید تأییدِ صریح داشته باشد — ایمیل هم مثلِ
 * پاسخِ تیکت برگشت‌ناپذیر است.
 *
 * ⚠️ متنِ نامه از `snippet` می‌آید چون **بدنهٔ کامل اصلاً ذخیره نمی‌شود**؛
 * ستونی به نامِ `body_text` در این جدول وجود ندارد. پس پیش‌نویس از روی
 * چکیده نوشته می‌شود و خودِ متن هم این را می‌گوید، وگرنه کارفرما فکر می‌کند
 * مدل کلِ نامه را دیده.
 */
class MailReplyDraftWriter extends AiContent
{
    public function __construct()
    {
        $this->purpose = 'support';
    }

    /** پیش‌نویس، یا null اگر مدل جواب نداد */
    public function draft(MailboxMessage $m): ?string
    {
        $sys = <<<'TXT'
You draft email replies for ServerNet, a small Iranian hosting and web
development company. You write in the SAME language as the incoming email
(Persian for Persian, English for English).

Return ONLY the reply body: no subject line, no "Re:", no signature block, no
markdown, no quotes around it.

Hard rules — breaking any of these costs the company real money:
- NEVER promise a refund, a discount, a credit, a deadline, or an SLA figure.
- NEVER state a price, a plan name, or a technical detail you were not given.
- NEVER invent an invoice number, an order number, a date, or a person's name.
- You are working from a SHORT EXCERPT of the email, not the whole thing. If
  answering needs something not in the excerpt, ask the sender for exactly that
  one thing instead of guessing.
- If it is marketing, spam, or an automated notice, reply with the single word
  NOREPLY and nothing else.
- Short sentences. No corporate padding. No exclamation marks.
TXT;

        $payload = [
            'from'    => mb_substr((string) ($m->from_name ?: $m->from_email), 0, 120),
            'subject' => mb_substr((string) $m->subject, 0, 200),
            'summary' => (string) $m->summary,
            'excerpt' => mb_substr((string) $m->snippet, 0, 1500),
        ];

        try {
            $raw = $this->call($sys, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 800, 60);
        } catch (\Throwable) {
            return null;
        }

        $text = trim((string) $raw);
        $text = trim(preg_replace('/^```[a-z]*\s*|\s*```$/u', '', $text) ?? $text);
        $text = trim($text, "\"'«» \n\r\t");

        // مدل خودش تشخیص داده که این نامه پاسخ نمی‌خواهد
        if ($text === '' || strtoupper($text) === 'NOREPLY') {
            return null;
        }

        return mb_substr($text, 0, 3000);
    }
}
