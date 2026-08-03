<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * ایمیلی که متنش از `/admin/templates` می‌آید، روی همان قالبِ برنددارِ سرورنت.
 *
 * چرا Mailable و نه `Mail::html()`ِ خام: بی‌این، ایمیلِ الگو بدونِ لوگو و بدونِ
 * RTL و با فونتِ پیش‌فرضِ کلاینت می‌رفت — درست همان چیزی که یادآوریِ تمدید
 * (`Mail::raw`) تا امروز بود: مهم‌ترین ایمیلِ چرخهٔ مالی و زشت‌ترینشان.
 */
class TemplateMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * ⚠️ نامش `$subject` نیست: `Illuminate\Mail\Mailable` خودش یک `$subject`ِ
     * بی‌نوع دارد و تعریفِ دوبارهٔ نوع‌دار، PHP را با خطای کشندهٔ
     * «Type of ... must not be defined» متوقف می‌کند. همین برای `$html` هم صادق است.
     */
    public function __construct(
        public string $title,
        public string $bodyHtml,
    ) {}

    public function build(): self
    {
        return $this->subject($this->title)
            ->view('emails.template')
            ->with(['subject' => $this->title, 'html' => $this->bodyHtml]);
    }
}
