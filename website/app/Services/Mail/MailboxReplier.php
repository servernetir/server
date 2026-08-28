<?php

namespace App\Services\Mail;

use App\Models\ActivityLog;
use App\Mail\MailboxReplyMail;
use App\Models\MailboxMessage;
use App\Services\HtmlSanitizer;
use App\Support\ErrorTracker;
use Illuminate\Support\Facades\Mail;

/**
 * پاسخ‌دادن به یک نامهٔ صندوقِ ورودی — واقعاً فرستادن، نه پیش‌نویس.
 *
 * ═══ 🔴 چرا از SMTPِ *همان صندوق* می‌رود، نه از SMTPِ سیستم ═══
 *
 * وسوسه‌انگیز است که با `Mail::to(...)` پیش‌فرض بفرستیم. ولی آن‌وقت نامه با
 * نشانیِ `MAIL_FROM_ADDRESS` می‌رود، در حالی که مشتری به `support@` نوشته:
 *
 *   • پاسخِ او به صندوقِ دیگری می‌افتد و آن رشته دو تکه می‌شود
 *   • و اگر نشانیِ From با دامنهٔ فرستنده نخوانَد، SPF/DKIM رد می‌کند و
 *     پاسخِ ما مستقیم به اسپم می‌رود — بی‌هیچ خطایی سمتِ ما
 *
 * پس با اعتبارنامهٔ خودِ همان صندوق (همان `MAILBOX_*_USER/PASS` که IMAP با آن
 * می‌خوانَد) به SMTP وصل می‌شویم و From همان است. یعنی فرستنده و احرازشده و
 * دامنه هر سه یکی‌اند.
 *
 * 🔴 **اگر نشد، نمی‌فرستیم.** صندوقی که رمز ندارد، یا روی سرورِ دیگری است و
 * SMTPِ صریح در پیکربندی ندارد، پاسخ داده نمی‌شود و صریح گفته می‌شود. سقوطِ
 * بی‌صدا به فرستندهٔ پیش‌فرض یعنی نامه‌ای که به‌نظر رفته و در واقع در اسپم است
 * — بدتر از نفرستادن، چون کارفرما دیگر پیگیرش نمی‌شود.
 *
 * ⚠️ جیمیل از مرداد ۱۴۰۵ `smtp_host` دارد، پس از نشانیِ خودش پاسخ می‌دهد. هر
 * صندوقِ بیگانهٔ دیگری تا وقتی SMTPش نوشته نشود، همان رفتارِ قبلی را دارد.
 */
class MailboxReplier
{
    /**
     * @param  array{html?:?string, attachments?:list<array{name:string,mime:string,data:string}>}  $opts
     * @return array{ok:bool,message:string}
     */
    public function reply(MailboxMessage $m, string $body, ?int $userId = null, ?string $userName = null, array $opts = []): array
    {
        $body = trim($body);

        if ($body === '') {
            return $this->fail('متنِ پاسخ خالی است.');
        }

        $to = trim((string) $m->from_email);

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('نشانیِ فرستندهٔ این نامه معتبر نیست؛ پاسخ فرستاده نشد.');
        }

        $account = $this->account((string) $m->account);

        if ($account === null) {
            return $this->fail('صندوقِ «'.$m->account.'» در پیکربندی نیست.');
        }

        $from = trim((string) ($account['user'] ?? ''));
        $pass = (string) ($account['pass'] ?? '');

        if ($from === '' || $pass === '') {
            return $this->fail('برای صندوقِ «'.($account['label'] ?? $m->account)
                .'» رمزی در `.env` نیست، پس نمی‌شود از نشانیِ خودش فرستاد.');
        }

        /*
        | ⚠️ صندوقی که روی سرورِ دیگری است، SMTPِ خودش را می‌خواهد — و آن باید
        | **صریح** در پیکربندی آمده باشد، نه حدس زده شود. حدسِ اشتباه یعنی یک
        | ساعت تلاشِ ناموفق و در بهترین حالت نامه‌ای که هرگز نمی‌رسد.
        |
        | 🔴 پس شرط عوض شد ولی سخت‌گیری‌اش نه: پیش‌تر هر صندوقِ میزبان‌دار رد
        | می‌شد، حالا فقط آن‌که `smtp_host` ندارد. جیمیل حالا مقدارش را دارد
        | (`config/mailboxes.php`)، پس از نشانیِ خودش پاسخ می‌دهد.
        */
        $smtpHost   = trim((string) ($account['smtp_host'] ?? ''));
        $smtpPort   = (int) ($account['smtp_port'] ?? 0);
        $smtpScheme = trim((string) ($account['smtp_scheme'] ?? ''));

        if ($smtpHost === '') {
            if (($account['host'] ?? null) !== null) {
                return $this->fail('این صندوق روی سرورِ ما نیست و SMTPِ اختصاصی‌اش تعریف نشده؛ پاسخش باید از خودِ همان سرویس برود.');
            }

            $smtpHost   = (string) config('mailboxes.smtp_host');
            $smtpPort   = (int) config('mailboxes.smtp_port');
            $smtpScheme = (string) config('mailboxes.smtp_scheme');
        }

        /*
        | ⚠️ کلیدِ مِیلر شاملِ نامِ صندوق است.
        |
        | پیش از این همهٔ صندوق‌ها یک کلید داشتند. لاراول مِیلرِ ساخته‌شده را
        | **کش می‌کند**، پس در یک پروسه (کرونِ `bale:work` که چند کار را پشتِ
        | هم می‌برد) پاسخِ دوم با اعتبارنامهٔ صندوقِ اول می‌رفت — دقیقاً همان
        | ناسازگاریِ From/احرازشده که این کلاس برای جلوگیری از آن نوشته شده.
        */
        /*
        | ═══ 🔴 این کلاس از `MAIL_MAILER` رد می‌شد ═══
        |
        | پایین یک mailerِ `transport => smtp` **در لحظه** ساخته می‌شود تا
        | پاسخ از نشانیِ خودِ صندوق برود (دلیلش بالا). ولی همین یعنی
        | تنظیمِ سراسریِ «ایمیل نفرست» بی‌اثر است: در محیطی که
        | `MAIL_MAILER=array` یا `log` است، این کلاس باز هم یک سوکتِ
        | **واقعی** به SMTP باز می‌کند.
        |
        | اثرِ واقعی‌اش در سوئیت دیده شد — نه به‌صورتِ تستِ قرمز، بلکه:
        |
        |     Fatal error: Maximum execution time of 200 seconds exceeded
        |     in symfony/mailer/Transport/Smtp/Stream/SocketStream.php
        |
        | یعنی کلِ اجرا وسطِ کار مُرد و هیچ گزارشی نداد. همان خانوادهٔ باگی که
        | در `ExchangeRate`/`CryptoPrice` بود: کدی که تنظیمِ محیط را دور می‌زند
        | و به بیرون وصل می‌شود.
        |
        | ⚠️ این فقط محافظِ تست نیست: هر محیطی که آگاهانه روی «نفرست» تنظیم
        | شده (staging، اجرای محلی، بازیابیِ حادثه) نباید از این‌جا نامهٔ
        | واقعی بفرستد. برگرداندنِ mailerِ پیش‌فرض دقیقاً همان کارِ درست است —
        | نامه ساخته و ثبت می‌شود، ولی روی سیم نمی‌رود.
        */
        $default = (string) config('mail.default');

        if (in_array($default, ['array', 'log', 'null'], true)) {
            $mailerKey = $default;
        } else {
            $mailerKey = 'mailbox_runtime_'.preg_replace('~[^a-z0-9_]~i', '_', (string) $m->account);
        }

        if ($mailerKey !== $default) {
            config(['mail.mailers.'.$mailerKey => [
                'transport'    => 'smtp',
                'scheme'       => $smtpScheme,
                'host'         => $smtpHost,
                'port'         => $smtpPort,
                'username'     => $from,
                'password'     => $pass,
                'timeout'      => 20,
                'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
            ]]);
        }

        $subject = $this->replySubject((string) $m->subject);
        $text    = $this->compose($body, $m);

        /*
        | ⚠️ HTML **دوباره** پاک‌سازی می‌شود، حتی با اینکه از پنلِ خودمان
        | می‌آید. `contenteditable` مرورگر آشغالِ خودش را تولید می‌کند
        | (`<font>`، `style` درهم، تگِ نیمه‌باز)، و مهم‌تر: یک POSTِ دستی
        | می‌تواند هرچه بخواهد در آن فیلد بگذارد. اعتماد به «فرمِ خودمان»
        | همان جایی است که XSS از آن وارد می‌شود.
        */
        $html = trim((string) ($opts['html'] ?? ''));
        $html = $html === '' ? null : HtmlSanitizer::clean($html);

        $files = $this->normalizeFiles($opts['attachments'] ?? []);

        $mail = new MailboxReplyMail(
            $text,
            $subject,
            trim((string) $m->message_id) ?: null,
            $html,
            trim((string) config('mailboxes.signature', '')),
            $this->quotedSource($m),
            $files,
        );

        /*
        | ⚠️ `from()` روی خودِ Mailable، نه در قالب: نشانهٔ فرستنده باید همان
        | کاربری باشد که چند خط پایین‌تر با آن به SMTP احراز می‌شویم. اگر
        | `MAIL_FROM_ADDRESS`ِ سراسری بنشیند، دقیقاً همان ناسازگاری‌ای می‌شود
        | که این کلاس برای جلوگیری از آن نوشته شده.
        */
        $mail->from($from, (string) ($account['label'] ?? config('app.name')))
            ->replyTo($from);

        try {
            Mail::mailer($mailerKey)->to($to)->send($mail);
        } catch (\Throwable $e) {
            ErrorTracker::note('mailbox', $e, ['step' => 'reply', 'account' => $m->account]);

            return $this->fail('ارسال انجام نشد: '.mb_substr($e->getMessage(), 0, 160));
        }

        /*
        | ⚠️ علامت‌زدن **بعد** از ارسالِ موفق. اگر پیش از آن باشد، یک شکستِ
        | SMTP نامه را از صف بیرون می‌برد در حالی که هیچ‌کس جوابش را نداده.
        */
        try {
            $m->forceFill(['needs_reply' => false, 'handled_at' => now()])->save();

            ActivityLog::record(null, 'mail_reply',
                'پاسخ به «'.mb_substr((string) $m->subject, 0, 80).'» از صندوقِ '.$from
                .' توسط '.($userName ?: 'مدیر').' فرستاده شد',
                null, 'staff');
        } catch (\Throwable $e) {
            // نامه رفته؛ شکستِ ثبت نباید موفقیت را دروغ کند
            ErrorTracker::note('mailbox', $e, ['step' => 'reply-mark']);
        }

        return ['ok' => true, 'message' => 'پاسخ از '.$from.' فرستاده شد.'];
    }

    /** @return array<string,mixed>|null */
    private function account(string $key): ?array
    {
        foreach ((array) config('mailboxes.accounts', []) as $a) {
            if (($a['key'] ?? null) === $key) {
                return $a;
            }
        }

        return null;
    }

    /** «Re: Re: x» نمی‌سازیم — یک بار بس است */
    private function replySubject(string $subject): string
    {
        $subject = trim($subject) ?: '(بدون موضوع)';

        return preg_match('/^\s*(re|پاسخ)\s*:/iu', $subject) === 1
            ? mb_substr($subject, 0, 190)
            : mb_substr('Re: '.$subject, 0, 190);
    }

    /**
     * متنِ نهایی: پاسخ + امضا + نقلِ نامهٔ اصلی.
     *
     * ⚠️ نقل‌قول از `snippet` است چون بدنهٔ کامل اصلاً ذخیره نمی‌شود، و همین
     * در متن گفته می‌شود — نقلِ بریده‌ای که خودش را کامل جا بزند، گیج‌کننده‌تر
     * از نبودنش است.
     */
    private function compose(string $body, MailboxMessage $m): string
    {
        $sig = trim((string) config('mailboxes.signature', ''));

        $quoted = trim((string) $m->snippet);
        $quoted = $quoted === '' ? '' : implode("\n", array_map(
            fn ($l) => '> '.$l,
            preg_split('/\R/u', mb_substr($quoted, 0, 1200)) ?: [],
        ));

        return implode("\n", array_filter([
            $body,
            $sig !== '' ? "\n-- \n".$sig : null,
            $quoted !== '' ? "\n\nدر پاسخ به (بخشی از نامهٔ شما):\n".$quoted : null,
        ]));
    }

    /** متنِ نامهٔ اصلی که در پاسخ نقل می‌شود — همان چیزی که `compose()` هم می‌بُرد. */
    private function quotedSource(MailboxMessage $m): string
    {
        return trim(mb_substr(trim((string) $m->snippet), 0, 1200));
    }

    /**
     * پیوست‌ها را به شکلی درمی‌آورد که `MailboxReplyMail` می‌فهمد.
     *
     * 🔴 ردیفِ بی‌داده **دور ریخته می‌شود، نه با نامِ خالی فرستاده**: پیوستِ
     * صفر بایتی در بعضی کلاینت‌ها کلِ نامه را خراب نشان می‌دهد.
     *
     * @param  mixed  $files
     * @return list<array{name:string, mime:string, data:string}>
     */
    private function normalizeFiles($files): array
    {
        $out = [];

        foreach ((array) $files as $f) {
            $data = (string) ($f['data'] ?? '');

            if ($data === '') {
                continue;
            }

            $out[] = [
                'name' => (string) ($f['name'] ?? 'attachment'),
                'mime' => (string) ($f['mime'] ?? 'application/octet-stream'),
                'data' => $data,
            ];
        }

        return $out;
    }

    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
