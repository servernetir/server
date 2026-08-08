<?php

namespace App\Services\Mail;

use App\Models\MailboxMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * خواندنِ صندوق‌های مدیریتی و ثبتشان در جدول.
 *
 * این کلاس **تصمیم نمی‌گیرد** و **خبر نمی‌دهد** — فقط می‌خواند و ثبت می‌کند.
 * دسته‌بندی کارِ `MailboxTriage` است و گزارش کارِ `MailboxDigest`. جدا نگه
 * داشتنشان یعنی وقتی گزارش خراب شد، خواندن سرِ جایش است و برعکس.
 *
 * 🔴 تنها قضاوتی که اینجا می‌شود `is_system` است، و آن هم قضاوت نیست: قاعده‌ی
 * مکانیکیِ «موضوع با پیشوندِ AdminNotifier شروع شده» است. همان‌جا و همان لحظه
 * زده می‌شود، چون اگر بعداً زده شود یک پنجرهٔ زمانی باز می‌ماند که در آن یک
 * اعلانِ سیستمی می‌تواند وارد گزارشِ بله شود.
 */
class MailboxSync
{
    /**
     * @return array<string, array{new:int, seen:int, error?:string}>
     */
    public function run(?string $only = null): array
    {
        $out = [];

        foreach ((array) config('mailboxes.accounts', []) as $account) {
            if ($only !== null && ($account['key'] ?? null) !== $only) {
                continue;
            }

            $out[$account['key']] = $this->syncOne($account);
        }

        if ($out === []) {
            Log::warning('mailbox.sync.no_accounts');
        }

        return $out;
    }

    /**
     * @return array{new:int, seen:int, error?:string}
     */
    private function syncOne(array $account): array
    {
        $key = (string) $account['key'];
        $result = ['new' => 0, 'seen' => 0];

        $imap = new ImapClient([
            'host'   => (string) config('mailboxes.host'),
            'port'   => (int) config('mailboxes.port', 993),
            'user'   => (string) $account['user'],
            'pass'   => (string) $account['pass'],
            'folder' => 'INBOX',
        ]);

        try {
            $imap->open();

            $ids = $imap->searchSince((int) config('mailboxes.sync.days', 3));
            $ids = array_slice($ids, -(int) config('mailboxes.sync.max_per_run', 120));
            $result['seen'] = count($ids);

            foreach ($ids as $id) {
                $mail = $imap->fetch($id, 3000);

                if ($mail === null) {
                    continue;
                }

                if ($this->store($key, $mail)) {
                    $result['new']++;
                }
            }
        } catch (Throwable $e) {
            Log::error('mailbox.sync.error', ['account' => $key, 'err' => $e->getMessage()]);
            $result['error'] = mb_substr($e->getMessage(), 0, 200);
        } finally {
            $imap->close();
        }

        Log::info('mailbox.sync.account', ['account' => $key] + $result);

        return $result;
    }

    /** `false` یعنی از قبل ثبت شده بود. */
    private function store(string $account, array $mail): bool
    {
        // نامهٔ بدونِ Message-ID نادر است ولی وجود دارد؛ کلیدِ جایگزین از
        // فرستنده + موضوع + زمان ساخته می‌شود تا باز هم دو بار ثبت نشود.
        $messageId = $mail['message_id'] !== ''
            ? $mail['message_id']
            : $mail['from'].'|'.$mail['subject'].'|'.(string) $mail['date'];

        $hash = MailboxMessage::hashFor($account, $messageId);

        if (MailboxMessage::where('uid_hash', $hash)->exists()) {
            return false;
        }

        MailboxMessage::create([
            'account'     => $account,
            'uid_hash'    => $hash,
            'message_id'  => mb_substr($mail['message_id'], 0, 190) ?: null,
            'from_email'  => mb_substr($mail['from'], 0, 190),
            'from_name'   => mb_substr($mail['from_name'], 0, 160) ?: null,
            'subject'     => mb_substr($mail['subject'], 0, 190) ?: null,
            'snippet'     => mb_substr($mail['body'], 0, (int) config('mailboxes.sync.snippet_chars', 600)),
            'received_at' => $this->date($mail['date']),
            'is_system'   => $this->isSystem($mail['from'], $mail['subject']),
        ]);

        return true;
    }

    /**
     * آیا این همان اعلانی است که خودمان یک‌بار در بله گفته‌ایم؟
     *
     * عمومی است تا بشود مستقیم تستش کرد — این تابع دقیقاً همان چیزی است که
     * خواستهٔ «تکراری نگو» رویش سوار است.
     */
    public function isSystem(string $from, string $subject): bool
    {
        $subject = trim($subject);

        foreach ((array) config('mailboxes.system.subject_prefixes', []) as $prefix) {
            if ($prefix !== '' && str_starts_with($subject, $prefix)) {
                return true;
            }
        }

        $from = mb_strtolower(trim($from));

        foreach ((array) config('mailboxes.system.senders', []) as $sender) {
            $sender = mb_strtolower(trim((string) $sender));

            if ($sender === '') {
                continue;
            }

            // ورودی می‌تواند نشانیِ کامل باشد یا فقط بخشِ محلی مثل `root@`
            if (str_ends_with($sender, '@') ? str_starts_with($from, $sender) : $from === $sender) {
                return true;
            }
        }

        return false;
    }

    private function date(?string $raw): Carbon
    {
        if (blank($raw)) {
            return now();
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            // تاریخِ خراب نباید کلِ نامه را بیندازد؛ «الان» بدترین حالتش است.
            return now();
        }
    }
}
