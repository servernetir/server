<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * ایمیل کد ورود — روی قالبِ برنددارِ سرورنت، به زبانِ همان کاربر.
 */
class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public int $minutes,
        string $locale,
    ) {
        // زبانِ ایمیل: لاراول موقع رندر به این زبان سوییچ می‌کند، پس هم موضوع
        // و هم متن با __() به همان زبان درمی‌آید (فارسی/انگلیسی/ترکی جدا).
        $this->locale($locale);
    }

    public function build(): self
    {
        return $this->subject(__('ui.email_otp_subject'))
            ->view('emails.otp')
            ->with(['code' => $this->code, 'minutes' => $this->minutes]);
    }
}
