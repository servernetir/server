<?php

namespace App\Services\Mail;

use App\Models\MailboxMessage;
use App\Support\ErrorTracker;

/**
 * متنِ کاملِ یک نامه — **در لحظه از IMAP**، نه از دیتابیس.
 *
 * ═══ 🔴 چرا بدنه ذخیره نمی‌شود ═══
 *
 * جدولِ `mailbox_messages` عمداً فقط سرآیند و ۶۰۰ نویسه پیش‌نمایش دارد. آن
 * تصمیم با این صفحه **عوض نمی‌شود**: صندوقِ support@ پر از دادهٔ مشتری است و
 * کپی‌کردنش در دیتابیس یعنی همان داده در هر بکاپ، هر دامپِ عیب‌یابی و هر
 * کپیِ محلیِ توسعه تکثیر می‌شود. قیمتی که بابتش می‌دهیم یک رفت‌وبرگشتِ IMAP
 * به‌ازای هر «باز کردن» است — معاملهٔ خوبی است.
 *
 * ⚠️ پس این صفحه بدونِ دسترسیِ زنده به صندوق کار نمی‌کند. اگر رمز عوض شود یا
 * نامه در Roundcube پاک شده باشد، «پیدا نشد» می‌گیریم — و همان را صریح
 * می‌گوییم، چون فهرستِ پنل هنوز آن ردیف را دارد و کاربر باید بداند چرا خالی
 * است.
 */
class MailboxReader
{
    /**
     * @return array{ok:bool, message:string, mail:?array, size:int, truncated:bool}
     */
    public function read(MailboxMessage $m, bool $withAttachmentData = false): array
    {
        $messageId = trim((string) $m->message_id);

        if ($messageId === '') {
            return $this->fail('این نامه شناسهٔ پیام ندارد، پس روی سرور پیدایش نمی‌کنیم.');
        }

        $account = $this->account((string) $m->account);

        if ($account === null) {
            return $this->fail('صندوقِ «'.$m->account.'» دیگر در پیکربندی نیست.');
        }

        /*
        | ⚠️ هاست و پورت از خودِ حساب می‌آیند و بعد به پیش‌فرض می‌افتند — دقیقاً
        | مثلِ `MailboxSync`. جیمیل هاستِ خودش را دارد؛ اگر این‌جا پیش‌فرض
        | استفاده می‌شد، نامهٔ جیمیل را در صندوقِ سرورنت می‌گشتیم و «پیدا نشد»
        | می‌گرفتیم بی‌آنکه بفهمیم چرا.
        */
        $imap = new ImapClient([
            'host'   => (string) ($account['host'] ?? config('mailboxes.host')),
            'port'   => (int) ($account['port'] ?? config('mailboxes.port', 993)),
            'user'   => (string) ($account['user'] ?? ''),
            'pass'   => (string) ($account['pass'] ?? ''),
            'folder' => (string) ($account['folder'] ?? 'INBOX'),
        ]);

        try {
            $imap->open();

            $id = $imap->searchMessageId($messageId);

            if ($id === null) {
                return $this->fail('این نامه دیگر در صندوق نیست — احتمالاً پاک یا بایگانی شده.');
            }

            $raw = $imap->fetchRaw($id);

            if ($raw === null) {
                return $this->fail('سرور بدنهٔ این نامه را نداد.');
            }

            $mail = (new MimeReader())->parse($raw['raw'], $withAttachmentData);

            return [
                'ok'        => true,
                'message'   => '',
                'mail'      => $mail,
                'size'      => (int) $raw['size'],
                'truncated' => (bool) $raw['truncated'],
            ];
        } catch (\Throwable $e) {
            ErrorTracker::note('mailbox', $e, ['step' => 'read', 'account' => $m->account]);

            return $this->fail('خواندن از صندوق انجام نشد: '.mb_substr($e->getMessage(), 0, 160));
        } finally {
            /*
            | 🔴 `finally` و نه بعد از return: بدونِ این، هر مسیرِ خطا یک
            | نشستِ IMAP باز جا می‌گذاشت. cPanel روی هر صندوق سقفِ نشستِ
            | هم‌زمان دارد و وقتی پر شود، **کرونِ همگام‌سازی** هم دیگر وصل
            | نمی‌شود — یعنی یک صفحهٔ پرکلیک، سکوتِ کاملِ گزارشِ روزانه را
            | می‌سازد.
            */
            $imap->close();
        }
    }

    /**
     * یک پیوستِ مشخص، با بایت‌هایش.
     *
     * ⚠️ شماره از ترتیبِ پیمایشِ `MimeReader` می‌آید. همان نامهٔ خام همیشه
     * همان ترتیب را می‌دهد، پس شماره در طولِ عمرِ نامه پایدار است.
     *
     * @return array{ok:bool, message:string, attachment:?array}
     */
    public function attachment(MailboxMessage $m, int $index): array
    {
        $res = $this->read($m, true);

        if (! $res['ok']) {
            return ['ok' => false, 'message' => $res['message'], 'attachment' => null];
        }

        $list = $res['mail']['attachments'] ?? [];

        if (! isset($list[$index]) || ! is_string($list[$index]['data'] ?? null)) {
            return ['ok' => false, 'message' => 'این پیوست در نامه نیست.', 'attachment' => null];
        }

        /*
        | 🔴 نامهٔ بریده‌شده پیوستِ **ناقص** می‌دهد. فایلِ نصفه‌ای که بی‌صدا
        | دانلود شود، بدتر از دانلودنشدن است: کاربر بازش می‌کند، خراب است، و
        | دنبالِ مقصر در جای اشتباه می‌گردد.
        */
        if ($res['truncated']) {
            return [
                'ok'      => false,
                'message' => 'این نامه بزرگ‌تر از سقفِ خواندن است و پیوستش کامل نیست؛ از وب‌میل بگیریدش.',
                'attachment' => null,
            ];
        }

        return ['ok' => true, 'message' => '', 'attachment' => $list[$index]];
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

    /** @return array{ok:bool, message:string, mail:null, size:int, truncated:bool} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'mail' => null, 'size' => 0, 'truncated' => false];
    }
}
