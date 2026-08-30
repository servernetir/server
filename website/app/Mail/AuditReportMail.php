<?php

namespace App\Mail;

use App\Models\AuditReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * ایمیلِ «گزارشِ بررسیِ سایتِ شما آماده است».
 *
 * دو کاربرد، یک قالب:
 *  • مدیر گزارشی را برای **مشتریِ خودمان** می‌فرستد ($outreach = false)
 *  • کمپینِ بازاریابی به صاحبِ سایتی که هنوز مشتری نیست ($outreach = true)
 *
 * 🔴 تفاوتشان تزئینی نیست و نباید یکی شود. پیامِ کمپین به کسی می‌رود که
 * درخواستش نکرده، پس **باید** بگوید ما که هستیم، چرا این ایمیل را گرفته، و
 * چطور با یک کلیک جلویش را بگیرد. آن سه چیز مرزِ میانِ «بررسیِ رایگان» و
 * «اسپم» است — هم از نظرِ گیرنده، هم از نظرِ قانون در بازارِ اروپا که سایتِ ما
 * به آن می‌فروشد.
 */
class AuditReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AuditReport $report,
        public string $reportUrl,
        public bool $outreach = false,
        public ?string $unsubscribeUrl = null,
        public ?string $note = null,
        string $locale = 'fa',
    ) {
        $this->locale($locale);
    }

    public function build(): self
    {
        $subject = $this->outreach
            ? __('ui.rp_mail_subj_out', ['host' => $this->report->host])
            : __('ui.rp_mail_subj', ['host' => $this->report->host]);

        $mail = $this->subject($subject)->view('emails.audit-report');

        /*
         * سرتیترِ استانداردِ لغوِ اشتراک.
         *
         * جیمیل و اوت‌لوک از روی همین، دکمهٔ «لغو اشتراک» را **بالای خودِ پیام**
         * می‌گذارند. وقتی آن دکمه هست، گیرنده‌ای که پیام را نمی‌خواهد همان را
         * می‌زند نه دکمهٔ «اسپم» را — و دکمهٔ اسپم چیزی است که به اعتبارِ
         * ارسالِ کلِ دامنه می‌خورد، نه فقط به این یک پیام.
         */
        if ($this->unsubscribeUrl) {
            $mail->withSymfonyMessage(function ($message) {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$this->unsubscribeUrl.'>');
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
        }

        return $mail;
    }
}
