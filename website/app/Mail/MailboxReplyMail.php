<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * پاسخِ کارفرما به یک نامهٔ صندوقِ ورودی.
 *
 * 🔴 **متنِ ساده، نه HTML** — و این یک تنبلی نیست.
 *
 * بقیهٔ ایمیل‌های این پروژه (فاکتور، رمزِ سرویس، گزارش) نامه‌های **سیستمی**
 * برندداری‌اند که ما شروعشان می‌کنیم. این یکی جواب یک آدم است به نامه‌ای که
 * خودش نوشته. جوابِ HTMLدارِ برنددارِ یک انسان، بوی ربات می‌دهد و در رشتهٔ
 * گفتگو هم بد جا می‌افتد — به‌خصوص وقتی طرف از موبایل خوانده باشد.
 *
 * ⚠️ `{!! !!}` عمدی است: در قالبِ **متنی**، `{{ }}` هم HTML-escape می‌کند و
 * آپستروف و گیومهٔ متنِ فارسی/انگلیسی را به `&#039;` و `&quot;` تبدیل می‌کند —
 * یعنی مشتری در ایمیلِ متنی، کدِ HTML می‌بیند.
 */
class MailboxReplyMail extends Mailable
{
    public function __construct(
        public string $bodyText,
        public string $subjectLine,
        public ?string $inReplyTo = null,
    ) {}

    public function build(): self
    {
        $mail = $this->subject($this->subjectLine)
            ->text('emails.mailbox-reply', ['bodyText' => $this->bodyText]);

        /*
        | 🔴 سرنخِ رشته. بی‌این دو هدر، پاسخِ ما در برنامهٔ ایمیلِ گیرنده یک
        | نامهٔ **تازه** است، نه ادامهٔ همان گفتگو — و او باید خودش حدس بزند
        | این جوابِ کدام سؤال بوده. روی موبایل عملاً غیرممکن.
        |
        | `In-Reply-To` والدِ مستقیم را می‌گوید و `References` کلِ رشته را؛
        | کلاینت‌ها به هر دو نگاه می‌کنند و نبودِ یکی کافی است تا نخ پاره شود.
        */
        if ($this->inReplyTo !== null && $this->inReplyTo !== '') {
            $mid = str_starts_with($this->inReplyTo, '<') ? $this->inReplyTo : '<'.$this->inReplyTo.'>';

            $mail->withSymfonyMessage(function ($message) use ($mid) {
                $h = $message->getHeaders();
                $h->addTextHeader('In-Reply-To', $mid);
                $h->addTextHeader('References', $mid);
            });
        }

        return $mail;
    }
}
