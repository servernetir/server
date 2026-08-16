<?php

namespace App\Services\Mail;

use App\Models\ActivityLog;
use App\Mail\MailboxReplyMail;
use App\Models\MailboxMessage;
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
 * 🔴 **اگر نشد، نمی‌فرستیم.** صندوقی که رمز ندارد یا دامنه‌اش مالِ ما نیست
 * (مثلِ جیمیلِ شخصی) پاسخ داده نمی‌شود و صریح گفته می‌شود. سقوطِ بی‌صدا به
 * فرستندهٔ پیش‌فرض یعنی نامه‌ای که به‌نظر رفته و در واقع در اسپم است — بدتر از
 * نفرستادن، چون کارفرما دیگر پیگیرش نمی‌شود.
 */
class MailboxReplier
{
    /**
     * @return array{ok:bool,message:string}
     */
    public function reply(MailboxMessage $m, string $body, ?int $userId = null, ?string $userName = null): array
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
        | ⚠️ صندوقی که روی سرورِ دیگری است (جیمیلِ شخصی) از این‌جا پاسخ داده
        | نمی‌شود. هاست و پورتِ SMTPش فرق دارد و اپلیکیشن‌پسوردِ گوگل قواعدِ
        | خودش را دارد؛ حدسِ اشتباه یعنی یک ساعت تلاشِ ناموفق و در بهترین حالت
        | نامه‌ای که هرگز نمی‌رسد.
        */
        if (($account['host'] ?? null) !== null) {
            return $this->fail('این صندوق روی سرورِ ما نیست و پاسخش باید از خودِ همان سرویس برود.');
        }

        $mailerKey = 'mailbox_runtime';

        config(['mail.mailers.'.$mailerKey => [
            'transport'    => 'smtp',
            'scheme'       => config('mailboxes.smtp_scheme'),
            'host'         => config('mailboxes.smtp_host'),
            'port'         => (int) config('mailboxes.smtp_port'),
            'username'     => $from,
            'password'     => $pass,
            'timeout'      => 20,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
        ]]);

        $subject = $this->replySubject((string) $m->subject);
        $text    = $this->compose($body, $m);

        $mail = new MailboxReplyMail($text, $subject, trim((string) $m->message_id) ?: null);

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

    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
