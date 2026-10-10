<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * ایمیلِ اعلان به مشتری — روی همان قالبِ برنددارِ سرورنت، به زبانِ همان مشتری.
 *
 * برخلاف OtpMail که زبانِ جاری را می‌گیرد، این‌جا زبانِ گیرنده پاس داده می‌شود
 * چون ارسالِ گروهی از پنلِ فارسیِ مدیر انجام می‌شود ولی هر مشتری باید ایمیل را
 * به زبانِ خودش ببیند.
 */
class BroadcastMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ?string $title,
        public string $body,
        string $locale,
    ) {
        $this->locale($locale);
    }

    public function build(): self
    {
        $subject = ($this->title !== null && $this->title !== '')
            ? $this->title
            : __('ui.email_announce_subject');

        [$subject, $html] = \App\Support\PanelText::email($subject, nl2br(e($this->body)));
        return $this->subject($subject)
            ->view('emails.template')
            ->with(['subject' => $subject, 'html' => $html]);
    }
}
