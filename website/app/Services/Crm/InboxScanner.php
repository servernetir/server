<?php

namespace App\Services\Crm;

use App\Models\CrmLead;
use App\Models\CrmMessage;
use App\Models\CrmSuppression;
use App\Services\Notify\AdminNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * خواندنِ صندوقِ فروش — کوچک‌ترین کلاینتِ IMAP که کارِ ما را می‌کند.
 *
 * چرا کتابخانه اضافه نکردیم: `composer.json` این پروژه امروز فقط سه وابستگی
 * دارد و روی cPanel اجرا می‌شود. افزودنِ یک پکیجِ IMAP یعنی هر دیپلوی به
 * `composer install`ِ موفق گره می‌خورد، و `ext-imap` هم روی EA-PHP همیشه نصب
 * نیست. IMAP یک پروتکلِ متنیِ ساده است؛ همین ۱۵۰ خط کافی است و هیچ‌چیز را
 * به هیچ‌چیز گره نمی‌زند.
 *
 * سه چیز را تشخیص می‌دهد و هر سه **باید** تشخیص داده شوند:
 *   • «no» / unsubscribe → فهرستِ سیاهِ دائمی
 *   • بانس → فهرستِ سیاه، چون نشانی وجود ندارد و تکرارش شهرت را می‌سوزاند
 *   • جوابِ واقعی → صفِ فالوآپ فوراً می‌ایستد و به احسان خبر می‌رود
 *
 * 🔴 بدترین حالتِ ممکن این است: کسی جواب بدهد «علاقه‌مندم، قیمت؟» و سه روز
 * بعد فالوآپِ خودکارِ «یادآوری» برایش برود. آن یک فروشِ ازدست‌رفته است.
 */
class InboxScanner
{
    private $sock = null;
    private int $seq = 0;

    private const BOUNCE_FROM = ['mailer-daemon', 'postmaster', 'no-reply@', 'noreply@'];

    private const BOUNCE_SUBJECT = '~(undeliverable|delivery status notification|returned mail|'
        .'delivery has failed|failure notice|mail delivery failed|undelivered mail)~i';

    private const OPT_OUT = '~^\s*(no|stop|unsubscribe)\b|\b(unsubscribe|remove me from|take me off|'
        .'do not contact|don.t contact me|not interested|no thanks|no thank you)\b~i';

    /**
     * @return array{replies:int, optouts:int, bounces:int, seen:int, error?:string}
     */
    public function scan(int $days = 14): array
    {
        $out = ['replies' => 0, 'optouts' => 0, 'bounces' => 0, 'seen' => 0];
        $cfg = (array) config('crm.inbox');

        if (blank($cfg['host'] ?? null) || blank($cfg['pass'] ?? null)) {
            Log::warning('crm.inbox.not_configured');

            return $out + ['error' => 'not_configured'];
        }

        try {
            $this->connect($cfg);
            $ids = $this->searchSince($days);
            $out['seen'] = count($ids);

            foreach (array_slice($ids, -200) as $id) {
                $mail = $this->fetchOne($id);

                if ($mail === null) {
                    continue;
                }

                $kind = $this->handle($mail);

                if ($kind) {
                    $out[$kind]++;
                }
            }
        } catch (Throwable $e) {
            Log::error('crm.inbox.error', ['err' => $e->getMessage()]);
            $out['error'] = mb_substr($e->getMessage(), 0, 200);
        } finally {
            $this->close();
        }

        Log::info('crm.inbox.done', $out);

        return $out;
    }

    // ───────────────────────── تصمیم ─────────────────────────

    /** @return 'replies'|'optouts'|'bounces'|null */
    private function handle(array $mail): ?string
    {
        $from = $mail['from'];
        $subject = $mail['subject'];
        $body = $mail['body'];
        $messageId = $mail['message_id'];

        // قبلاً پردازش شده؟ (اجرای دوباره نباید دو بار حساب کند)
        if ($messageId !== '' && CrmMessage::where('provider_id', $messageId)->exists()) {
            return null;
        }

        $kind = $this->classify($from, $subject, $body);
        $isBounce = $kind === 'bounce';

        // نشانیِ واقعیِ گیرنده در بانس داخلِ متن است، نه در From.
        $lead = $isBounce
            ? $this->leadFromBounceBody($body)
            : CrmLead::where('email', $from)->orderByDesc('id')->first();

        if (! $lead) {
            return null;   // ایمیلی که ربطی به این سیستم ندارد
        }

        if ($isBounce) {
            CrmSuppression::add($lead->email, 'bounce', mb_substr($subject, 0, 190));
            $this->stop($lead, 'بانس — نشانی وجود ندارد');
            $this->record($lead, $messageId, $subject, $body, 'bounce');

            return 'bounces';
        }

        if ($kind === 'optout') {
            CrmSuppression::add($lead->email, 'unsubscribe');
            $this->stop($lead, 'درخواستِ عدمِ تماس');
            $this->record($lead, $messageId, $subject, $body, 'optout');

            return 'optouts';
        }

        // جوابِ واقعی — از این لحظه هیچ پیامِ خودکاری برای این نفر نمی‌رود.
        $this->cancelQueued($lead);
        $lead->stage = 'replied';
        $lead->replied_at = now();
        $lead->next_action_at = now()->addDay()->toDateString();
        $lead->save();

        $this->record($lead, $messageId, $subject, $body, 'reply');
        $this->notify($lead, $subject, $body);

        return 'replies';
    }

    /**
     * این نامه چیست؟ `bounce` | `optout` | `reply`
     *
     * جدا و عمومی است تا بشود بدونِ صندوقِ پستیِ واقعی تستش کرد. اشتباهِ این
     * تابع گران است: «بانس» که «جواب» خوانده شود، سرنخِ مرده را داغ نشان
     * می‌دهد؛ «no» که «جواب» خوانده شود، یعنی فالوآپ برای کسی می‌رود که همین
     * دیروز گفته دیگر ننویس.
     */
    public function classify(string $from, string $subject, string $body): string
    {
        if (preg_match(self::BOUNCE_SUBJECT, $subject)) {
            return 'bounce';
        }

        foreach (self::BOUNCE_FROM as $needle) {
            if (str_contains(mb_strtolower($from), $needle)) {
                return 'bounce';
            }
        }

        if (preg_match(self::OPT_OUT, $subject) || preg_match(self::OPT_OUT, trim($body))) {
            return 'optout';
        }

        return 'reply';
    }

    /** فالوآپِ در صف را می‌بندد — پیامِ خودکار بعد از جواب، فروش را می‌کُشد. */
    private function cancelQueued(CrmLead $lead): void
    {
        CrmMessage::where('lead_id', $lead->id)
            ->where('direction', 'out')
            ->whereIn('status', ['queued', 'draft'])
            ->update(['status' => 'cancelled', 'error' => 'lead replied']);
    }

    private function stop(CrmLead $lead, string $reason): void
    {
        $this->cancelQueued($lead);
        $lead->stage = 'lost';
        $lead->lost_at = now();
        $lead->lost_reason = $reason;
        $lead->next_action_at = null;
        $lead->save();
    }

    private function record(CrmLead $lead, string $messageId, string $subject, string $body, string $kind): void
    {
        CrmMessage::create([
            'lead_id'     => $lead->id,
            'channel'     => 'email',
            'direction'   => 'in',
            'subject'     => mb_substr($subject, 0, 190),
            'body'        => mb_substr($body, 0, 20000),
            'status'      => $kind,
            'sequence'    => 0,
            'provider_id' => $messageId !== '' ? mb_substr($messageId, 0, 190) : null,
            'sent_at'     => now(),
        ]);
    }

    private function notify(CrmLead $lead, string $subject, string $body): void
    {
        try {
            app(AdminNotifier::class)->event(
                'جوابِ سرنخِ فروش',
                [
                    'شرکت'   => $lead->company,
                    'نشانی'  => (string) $lead->email,
                    'موضوع'  => mb_substr($subject, 0, 120),
                    'متن'    => mb_substr(trim($body), 0, 300),
                ],
                url('/admin/crm/'.$lead->id),
                '📩',
            );
        } catch (Throwable $e) {
            Log::warning('crm.inbox.notify_failed', ['err' => $e->getMessage()]);
        }
    }

    /** در بانس، نشانیِ اصلی معمولاً در متنِ گزارش تکرار شده است. */
    private function leadFromBounceBody(string $body): ?CrmLead
    {
        if (! preg_match_all('~[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,24}~i', $body, $m)) {
            return null;
        }

        foreach (array_unique(array_map('mb_strtolower', $m[0])) as $candidate) {
            $lead = CrmLead::where('email', $candidate)->orderByDesc('id')->first();

            if ($lead) {
                return $lead;
            }
        }

        return null;
    }

    // ───────────────────────── IMAP ─────────────────────────

    private function connect(array $cfg): void
    {
        $host = $cfg['host'];
        $port = (int) ($cfg['port'] ?: 993);
        $dsn = ($port === 143 ? 'tcp://' : 'ssl://').$host.':'.$port;

        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $sock = @stream_socket_client($dsn, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);

        if (! $sock) {
            throw new \RuntimeException("IMAP connect failed: {$errstr} ({$errno})");
        }

        $this->sock = $sock;
        stream_set_timeout($this->sock, 25);
        fgets($this->sock);   // بنرِ خوش‌آمد

        $res = $this->cmd('LOGIN '.$this->quote((string) $cfg['user']).' '.$this->quote((string) $cfg['pass']));

        if (! $this->ok($res)) {
            throw new \RuntimeException('IMAP login rejected');
        }

        $res = $this->cmd('SELECT '.$this->quote((string) ($cfg['folder'] ?: 'INBOX')));

        if (! $this->ok($res)) {
            throw new \RuntimeException('IMAP folder not selectable');
        }
    }

    /** @return array<int, int> */
    private function searchSince(int $days): array
    {
        $since = now()->subDays(max(1, $days))->format('d-M-Y');
        $res = $this->cmd('SEARCH SINCE '.$since);

        foreach ($res as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                $ids = array_filter(array_map('intval', preg_split('~\s+~', trim(substr($line, 8))) ?: []));

                sort($ids);

                return array_values($ids);
            }
        }

        return [];
    }

    /**
     * @return array{from:string, subject:string, message_id:string, body:string}|null
     */
    private function fetchOne(int $id): ?array
    {
        $head = implode("\n", $this->cmd(
            "FETCH {$id} (BODY.PEEK[HEADER.FIELDS (FROM SUBJECT MESSAGE-ID)])"
        ));
        $text = implode("\n", $this->cmd("FETCH {$id} (BODY.PEEK[TEXT]<0.4000>)"));

        $from = $this->headerAddress($head);

        if ($from === '') {
            return null;
        }

        return [
            'from'       => $from,
            'subject'    => $this->decodeHeader($this->header($head, 'Subject')),
            'message_id' => trim($this->header($head, 'Message-ID'), '<> '),
            'body'       => $this->plain($text),
        ];
    }

    private function header(string $raw, string $name): string
    {
        if (preg_match('~^'.preg_quote($name, '~').':\s*(.*)$~mi', $raw, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private function headerAddress(string $raw): string
    {
        $from = $this->header($raw, 'From');

        if (preg_match('~<([^>]+)>~', $from, $m)) {
            return mb_strtolower(trim($m[1]));
        }

        if (preg_match('~[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,24}~i', $from, $m)) {
            return mb_strtolower($m[0]);
        }

        return '';
    }

    /** موضوعِ MIME-encoded (`=?UTF-8?B?...?=`) را باز می‌کند */
    private function decodeHeader(string $s): string
    {
        $d = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return is_string($d) && $d !== '' ? $d : $s;
    }

    /** متنِ خام را تا حدِ خوانا تمیز می‌کند — تحلیلِ کاملِ MIME لازم نیست */
    private function plain(string $s): string
    {
        $s = preg_replace('~^\* \d+ FETCH.*$~mi', '', $s) ?? $s;
        $s = preg_replace('~^A\d+ (OK|NO|BAD).*$~mi', '', $s) ?? $s;
        $s = quoted_printable_decode($s);
        $s = preg_replace('~<br\s*/?>~i', "\n", $s) ?? $s;
        $s = strip_tags($s);

        return trim(preg_replace('~\n{3,}~', "\n\n", $s) ?? $s);
    }

    /**
     * یک فرمانِ برچسب‌دار. لیترال‌های `{n}` را دقیق می‌خواند تا متنِ نامه
     * با پاسخِ پروتکل قاطی نشود.
     *
     * @return array<int, string>
     */
    private function cmd(string $command): array
    {
        $tag = 'A'.str_pad((string) ++$this->seq, 4, '0', STR_PAD_LEFT);
        fwrite($this->sock, $tag.' '.$command."\r\n");

        $lines = [];

        while (! feof($this->sock)) {
            $line = fgets($this->sock);

            if ($line === false) {
                break;
            }

            $line = rtrim($line, "\r\n");

            if (preg_match('~\{(\d+)\}$~', $line, $m)) {
                $need = (int) $m[1];
                $buf = '';

                while (strlen($buf) < $need && ! feof($this->sock)) {
                    $chunk = fread($this->sock, $need - strlen($buf));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    $buf .= $chunk;
                }

                $lines[] = $buf;

                continue;
            }

            $lines[] = $line;

            if (str_starts_with($line, $tag.' ')) {
                break;
            }
        }

        return $lines;
    }

    private function ok(array $lines): bool
    {
        foreach ($lines as $line) {
            if (preg_match('~^A\d+ OK~', $line)) {
                return true;
            }
        }

        return false;
    }

    private function quote(string $s): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"';
    }

    private function close(): void
    {
        if ($this->sock) {
            @fwrite($this->sock, "ZZZZ LOGOUT\r\n");
            @fclose($this->sock);
            $this->sock = null;
        }
    }
}
