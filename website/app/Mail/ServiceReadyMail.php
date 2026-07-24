<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * ایمیلِ «سرویس شما آماده شد» — با اطلاعاتِ ورودِ کنترل‌پنل، به زبانِ مشتری.
 */
class ServiceReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $serviceName,
        public ?string $domain,
        public ?string $panelUrl,
        public ?string $username,
        public ?string $password,
        string $locale,
    ) {
        $this->locale($locale);
    }

    public function build(): self
    {
        return $this->subject(__('ui.email_service_subject', ['name' => $this->serviceName]))
            ->view('emails.service-ready')
            ->with([
                'serviceName' => $this->serviceName,
                'domain'      => $this->domain,
                'panelUrl'    => $this->panelUrl,
                'username'    => $this->username,
                'password'    => $this->password,
            ]);
    }
}
