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
        /*
        | 🔴 هدفِ کد — بی‌این، ایمیلِ **حذفِ سرور** با موضوعِ «کد ورود» می‌رفت.
        |
        | کارفرما خودش دید: دکمهٔ حذفِ سرور را زد و ایمیلی گرفت که می‌گفت کدِ
        | ورود است. کاربری که فکر می‌کند دارد وارد می‌شود، کدی را تأیید می‌کند
        | که سرورش را برای همیشه پاک می‌کند — و پیامکش درست بود، پس هیچ نشانهٔ
        | دیگری هم نداشت که چیزی غلط است.
        |
        | ⚠️ برای کنشِ ویرانگر، «متنِ عمومی» کافی نیست: باید بگوید چه چیزی پاک
        | می‌شود و برگشت‌ناپذیر است.
        */
        public string $purpose = 'login',
    ) {
        // زبانِ ایمیل: لاراول موقع رندر به این زبان سوییچ می‌کند، پس هم موضوع
        // و هم متن با __() به همان زبان درمی‌آید (فارسی/انگلیسی/ترکی جدا).
        $this->locale($locale);
    }

    public function build(): self
    {
        // ⚠️ نامِ واقعیِ purpose در `Account\ServiceController` است، نه حدسِ ما:
        //    `service_terminate`. رشتهٔ اشتباه یعنی شرط هرگز صادق نشود و ایمیلِ
        //    حذف بی‌صدا همان قالبِ ورود بمانَد — دقیقاً همان باگ، از درِ دیگر.
        $destructive = str_contains($this->purpose, 'terminate');

        return $this->subject(__($destructive ? 'ui.email_otp_del_subject' : 'ui.email_otp_subject'))
            ->view('emails.otp')
            ->with([
                'code' => $this->code,
                'minutes' => $this->minutes,
                'destructive' => $destructive,
            ]);
    }
}
