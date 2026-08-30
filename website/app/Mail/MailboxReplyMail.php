<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * پاسخِ کارفرما به یک نامهٔ صندوقِ ورودی.
 *
 * ⚠️ **این کلاس تا مرداد ۱۴۰۵ فقط متنِ ساده می‌فرستاد.** کارفرما ادیتورِ
 * قالب‌دار خواست، پس حالا HTML هم می‌رود. آن استدلالِ قبلی («جوابِ آدم نباید
 * بوی ربات بدهد») دور ریخته نشد — در خودِ قالبِ HTML زندگی می‌کند: بی‌هدر،
 * بی‌فوتر، بی‌رنگ‌بندیِ برند. فقط متنِ نویسنده، با کمی نفس‌کشیدن.
 *
 * 🔴 **نسخهٔ متنیِ ساده هم همیشه همراهش می‌رود** و این تزئین نیست:
 *
 *   • نامهٔ فقط-HTML امتیازِ اسپمِ بالاتری می‌گیرد — تقریباً همهٔ فیلترها
 *     نبودِ بخشِ متنی را نشانهٔ ارسالِ انبوه می‌گیرند
 *   • ساعتِ هوشمند، اعلانِ گوشی و کلاینتِ متنی همان بخش را نشان می‌دهند
 *
 * کارفرما هرگز آن نسخه را نمی‌نویسد؛ خودکار از همان HTML ساخته می‌شود.
 *
 * ⚠️ `{!! !!}` در قالبِ **متنی** عمدی است: `{{ }}` آن‌جا هم HTML-escape
 * می‌کند و آپستروف را `&#039;` می‌فرستد — یعنی مشتری در ایمیلِ متنی، کدِ
 * HTML می‌بیند.
 */
class MailboxReplyMail extends Mailable
{
    /**
     * @param  list<array{name:string, mime:string, data:string}>  $files
     */
    public function __construct(
        public string $bodyText,
        public string $subjectLine,
        public ?string $inReplyTo = null,
        public ?string $bodyHtml = null,
        public string $signature = '',
        public string $quoted = '',
        public array $files = [],
    ) {}

    public function build(): self
    {
        $mail = $this->subject($this->subjectLine);

        /*
        | ⚠️ ترتیب مهم است: `view()` بخشِ HTML را می‌سازد و `text()` بخشِ
        | متنی. با هر دو، لاراول نامه را `multipart/alternative` می‌فرستد و
        | کلاینت خودش انتخاب می‌کند. اگر فقط `text()` بماند، ادیتورِ قالب‌دار
        | بی‌اثر می‌شود — و هیچ خطایی هم نمی‌دهد.
        */
        if ($this->bodyHtml !== null && trim($this->bodyHtml) !== '') {
            $mail->view('emails.mailbox-reply-html', [
                'bodyHtml'    => $this->bodyHtml,
                'subjectLine' => $this->subjectLine,
                'signature'   => $this->signature,
                'quoted'      => $this->quoted,
            ]);
        }

        $mail->text('emails.mailbox-reply', ['bodyText' => $this->bodyText]);

        /*
        | 🔴 پیوست از **حافظه** می‌رود، نه از مسیرِ فایل. فایلِ آپلودشده در
        | `storage` موقتی است و اگر ارسال به صف یا تلاشِ دوباره بیفتد، ممکن
        | است دیگر نباشد — و نامه بی‌پیوست و بی‌خطا برود.
        */
        foreach ($this->files as $f) {
            $name = (string) ($f['name'] ?? 'attachment');
            $data = (string) ($f['data'] ?? '');

            if ($data === '') {
                continue;
            }

            $mail->attachData($data, $name, ['mime' => (string) ($f['mime'] ?? 'application/octet-stream')]);
        }

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
