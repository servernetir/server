<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * ایمیلِ «سرویس شما آماده شد» — با اطلاعاتِ ورودِ کنترل‌پنل، به زبانِ مشتری.
 *
 * دو مسیر از این کلاس استفاده می‌کنند و رفتارشان **باید** فرق کند:
 *
 *  • هاستِ اشتراکی (`ProvisioningService`) — رمزِ cPanel در ایمیل می‌آید، چون
 *    جای دیگری نگه داشته نمی‌شود. هیچ‌چیزِ این مسیر عوض نشده.
 *  • سرورِ ابری (`CloudProvisioner`) — رمزِ root فقط **یک بار** در پنل دیده
 *    می‌شود (`CloudInstance::password_seen`). گذاشتنش در ایمیل، نسخهٔ دومی
 *    می‌ساخت که برای همیشه در اینباکس می‌مانْد و همان قاعده را از درِ پشتی
 *    می‌شکست. پس این مسیر با `passwordInPanel` می‌آید: رمز نه، ولی توضیحِ
 *    صریحِ اینکه کجاست.
 *
 * دو پارامترِ آخر پیش‌فرضِ خاموش دارند تا مسیرِ هاست دست‌نخورده بمانَد.
 */
class ServiceReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * اسلاگِ مقالهٔ «اتصال به سرور لینوکس با SSH» در پایگاه دانش.
     *
     * ⚠️ وجودِ مقاله در **زمانِ ارسال** سنجیده می‌شود، نه فرض گرفته. مقالات
     * پایگاه دانش در دیتابیس‌اند (`posts` با `type='kb'`) و با `docs:seed`
     * ساخته می‌شوند؛ روی نصبی که آن فرمان اجرا نشده، لینکِ سخت‌کد یک ۴۰۴
     * تحویلِ مشتری می‌داد — بدتر از نبودِ لینک.
     */
    public const SSH_DOC_SLUG = 'connecting-to-linux-server-ssh';

    public function __construct(
        public string $serviceName,
        public ?string $domain,
        public ?string $panelUrl,
        public ?string $username,
        public ?string $password,
        string $locale,
        public bool $passwordInPanel = false,
        public bool $withSshGuide = false,
    ) {
        $this->locale($locale);
    }

    public function build(): self
    {
        return $this->subject(__('ui.email_service_subject', ['name' => $this->serviceName]))
            ->view('emails.service-ready')
            ->with([
                'serviceName'     => $this->serviceName,
                'domain'          => $this->domain,
                'panelUrl'        => $this->panelUrl,
                'username'        => $this->username,
                'password'        => $this->password,
                'passwordInPanel' => $this->passwordInPanel,
                'sshGuide'        => $this->withSshGuide,
                // ⚠️ این‌جا ساخته می‌شود و نه در سازنده: `build()` داخلِ
                // `withLocale()` اجرا می‌شود، پس `lroute()` پیشوندِ زبانِ **مشتری**
                // را می‌گذارد. در سازنده زبانِ کرون (فارسی) خوانده می‌شد و مشتریِ
                // انگلیسی به نسخهٔ فارسیِ مقاله می‌رفت.
                'sshDocUrl'       => $this->withSshGuide ? self::sshDocUrl() : null,
            ]);
    }

    /**
     * نشانیِ مقالهٔ SSH — یا null اگر منتشر نشده باشد.
     *
     * هرگز استثنا نمی‌دهد: این متد در مسیرِ کرون صدا زده می‌شود و یک استثنا
     * در `schedule:run` کلِ آن دقیقه را می‌کشد (تحویلِ سرور و ثبتِ دامنه هم با
     * آن می‌ایستد — حادثهٔ مستندشدهٔ این پروژه).
     */
    public static function sshDocUrl(): ?string
    {
        try {
            if (app(\App\Services\DocsRepository::class)->find(self::SSH_DOC_SLUG) === null) {
                return null;
            }

            return lroute('docs', self::SSH_DOC_SLUG);
        } catch (\Throwable) {
            return null;
        }
    }
}
