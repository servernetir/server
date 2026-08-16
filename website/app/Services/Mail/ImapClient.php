<?php

namespace App\Services\Mail;

use RuntimeException;

/**
 * کوچک‌ترین کلاینتِ IMAP که کارِ ما را می‌کند — بدونِ هیچ وابستگی.
 *
 * چرا کتابخانه اضافه نکردیم: `composer.json` این پروژه سه وابستگی دارد و روی
 * cPanel اجرا می‌شود. افزودنِ پکیجِ IMAP یعنی هر دیپلوی به `composer install`ِ
 * موفق گره می‌خورد، و `ext-imap` هم روی EA-PHP همیشه نصب نیست. IMAP یک
 * پروتکلِ متنیِ ساده است؛ همین چند ده خط کافی است.
 *
 * ⚠️ **چرا IMAP و نه POP3**: POP3 نه پوشه می‌فهمد، نه پرچمِ خوانده/نخوانده، و
 * در عمل نامه را برمی‌دارد و می‌برد. سؤالِ «چند تا ایمیلِ نخوانده داریم» در
 * POP3 اصلاً جواب ندارد. برای این کار IMAP تنها گزینه است، نه گزینهٔ بهتر.
 *
 * 🔴 همه‌جا `BODY.PEEK` استفاده می‌شود، هرگز `BODY`. تفاوتشان این است که
 * دومی پرچمِ \Seen می‌زند — یعنی هر بار که این کد صندوق را می‌خواند، نامه‌های
 * نخواندهٔ احسان در Roundcube «خوانده‌شده» می‌شوند. آن یک بار اتفاق بیفتد،
 * دیگر هیچ‌وقت به این پنل اعتماد نمی‌کند.
 */
class ImapClient
{
    /** @var resource|null */
    private $sock = null;

    private int $seq = 0;

    /**
     * @param  array{host:string, port?:int, user:string, pass:string, folder?:string}  $cfg
     */
    public function __construct(private array $cfg) {}

    public function open(): void
    {
        $host = (string) ($this->cfg['host'] ?? '');
        $port = (int) ($this->cfg['port'] ?? 993);

        if ($host === '') {
            throw new RuntimeException('IMAP host is empty');
        }

        // ۱۴۳ = بدونِ TLS از ابتدا (STARTTLS اینجا پشتیبانی نمی‌شود، عمداً:
        // cPanel همیشه ۹۹۳ را می‌دهد و پشتیبانی از مسیرِ ناامن فقط یک راهِ
        // اضافه برای اشتباهِ پیکربندی است).
        $dsn = ($port === 143 ? 'tcp://' : 'ssl://').$host.':'.$port;

        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $sock = @stream_socket_client($dsn, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);

        if (! $sock) {
            throw new RuntimeException("IMAP connect failed: {$errstr} ({$errno})");
        }

        $this->sock = $sock;
        stream_set_timeout($this->sock, 25);
        fgets($this->sock);   // بنرِ خوش‌آمد

        if (! $this->ok($this->cmd('LOGIN '.$this->quote((string) $this->cfg['user']).' '.$this->quote((string) $this->cfg['pass'])))) {
            throw new RuntimeException('IMAP login rejected');
        }

        if (! $this->ok($this->cmd('SELECT '.$this->quote((string) ($this->cfg['folder'] ?? 'INBOX'))))) {
            throw new RuntimeException('IMAP folder not selectable');
        }
    }

    public function close(): void
    {
        if ($this->sock) {
            @fwrite($this->sock, "ZZZZ LOGOUT\r\n");
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    /**
     * شماره‌ی نامه‌های N روزِ گذشته.
     *
     * ⚠️ عمداً `SINCE` و نه `UNSEEN`: اگر احسان صبح در Roundcube صندوق را باز
     * کند، همه‌چیز `\Seen` می‌شود و `UNSEEN` هیچ‌وقت چیزی برنمی‌گرداند. تکرارِ
     * پردازش با کلیدِ Message-ID در دیتابیس گرفته می‌شود، نه با پرچمِ سرور.
     *
     * @return array<int, int>
     */
    public function searchSince(int $days): array
    {
        $since = now()->subDays(max(1, $days))->format('d-M-Y');

        foreach ($this->cmd('SEARCH SINCE '.$since) as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                $ids = array_values(array_filter(array_map('intval', preg_split('~\s+~', trim(substr($line, 8))) ?: [])));
                sort($ids);

                return $ids;
            }
        }

        return [];
    }

    /**
     * سرآیندها + آغازِ متنِ یک نامه.
     *
     * @return array{from:string, from_name:string, subject:string, message_id:string, date:?string, body:string}|null
     */
    public function fetch(int $id, int $bodyBytes = 4000): ?array
    {
        $head = implode("\n", $this->cmd(
            "FETCH {$id} (BODY.PEEK[HEADER.FIELDS (FROM SUBJECT MESSAGE-ID DATE)])"
        ));

        $from = $this->header($head, 'From');
        $address = self::addressIn($from);

        if ($address === '') {
            return null;
        }

        $text = implode("\n", $this->cmd("FETCH {$id} (BODY.PEEK[TEXT]<0.{$bodyBytes}>)"));

        return [
            'from'       => $address,
            'from_name'  => self::displayName($from),
            'subject'    => self::decodeHeader($this->header($head, 'Subject')),
            'message_id' => trim($this->header($head, 'Message-ID'), '<> '),
            'date'       => $this->header($head, 'Date') ?: null,
            'body'       => self::plain($text),
        ];
    }

    /**
     * نامهٔ **کامل** (سرآیند + بدنه + پیوست‌ها) به‌صورت خام، برای `MimeReader`.
     *
     * 🔴 `BODY.PEEK[]` نه `BODY[]` — همان قاعدهٔ بالای کلاس: `BODY[]` پرچمِ
     * \Seen می‌زند و نامهٔ نخواندهٔ کاربر را در Roundcube «خوانده» می‌کند.
     * باز کردنِ یک نامه در پنلِ ما نباید وضعیتش را در کلاینتِ واقعی عوض کند.
     *
     * ⚠️ **سقفِ حجم عمدی است.** یک نامه با پیوستِ ۸۰ مگابایتی، بی‌سقف، همان
     * ۸۰ مگ را در حافظهٔ PHP می‌ریزد و درخواست با خطای حافظه می‌میرد — روی
     * صفحه‌ای که مدیر هر روز بازش می‌کند. پس تا سقف می‌خوانیم و **صادقانه**
     * می‌گوییم بریده شد؛ نامهٔ بزرگ باید ناقص دیده شود، نه اینکه پنل بیفتد.
     *
     * @return array{raw:string, size:int, truncated:bool}|null
     */
    public function fetchRaw(int $id, int $maxBytes = 5_242_880): ?array
    {
        // اندازهٔ واقعی را اول بپرس تا بدانیم بریدیم یا نه.
        $sizeLines = $this->cmd("FETCH {$id} (RFC822.SIZE)");
        $size = 0;

        foreach ($sizeLines as $line) {
            if (preg_match('~RFC822\.SIZE\s+(\d+)~i', $line, $m)) {
                $size = (int) $m[1];

                break;
            }
        }

        $want  = $size > 0 ? min($size, $maxBytes) : $maxBytes;
        $lines = $this->cmd("FETCH {$id} (BODY.PEEK[]<0.{$want}>)");

        // `cmd()` خودِ خطِ نشانگرِ `{n}` را دور می‌ریزد و فقط محتوای لیترال را
        // در آرایه می‌گذارد؛ پس بلندترین عضو همان نامه است. این FETCH دقیقاً
        // یک لیترال دارد، بنابراین ابهامی نیست.
        $raw = '';

        foreach ($lines as $line) {
            if (strlen($line) > strlen($raw)) {
                $raw = $line;
            }
        }

        if (trim($raw) === '') {
            return null;
        }

        return [
            'raw'       => $raw,
            'size'      => $size ?: strlen($raw),
            'truncated' => $size > 0 && $size > $want,
        ];
    }

    // ───────────────────────── پارسِ سرآیند ─────────────────────────

    public function header(string $raw, string $name): string
    {
        if (preg_match('~^'.preg_quote($name, '~').':\s*(.*)$~mi', $raw, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /** `"Dr Ali" <ali@x.com>` → `ali@x.com` */
    public static function addressIn(string $from): string
    {
        if (preg_match('~<([^>]+)>~', $from, $m)) {
            return mb_strtolower(trim($m[1]));
        }

        if (preg_match('~[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,24}~i', $from, $m)) {
            return mb_strtolower($m[0]);
        }

        return '';
    }

    /** `"Dr Ali" <ali@x.com>` → `Dr Ali` */
    public static function displayName(string $from): string
    {
        $name = trim((string) preg_replace('~<[^>]*>~', '', $from));
        $name = trim($name, " \t\"'");

        return self::decodeHeader($name);
    }

    /** سرآیندِ `=?UTF-8?B?...?=` را باز می‌کند (موضوعِ فارسی و عربی) */
    public static function decodeHeader(string $s): string
    {
        if ($s === '') {
            return '';
        }

        $d = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return is_string($d) && $d !== '' ? $d : $s;
    }

    /**
     * متنِ خام را تا حدِ خوانا تمیز می‌کند.
     *
     * تحلیلِ کاملِ MIME عمداً انجام نمی‌شود: مصرف‌کننده‌های ما (دسته‌بندیِ مدل و
     * پیش‌نمایشِ پنل) به چند صد نویسهٔ خوانا نیاز دارند، نه به بازسازیِ دقیقِ
     * ساختارِ نامه. پیاده‌سازیِ ناقصِ MIME بدتر از نداشتنش است.
     */
    public static function plain(string $s): string
    {
        $s = preg_replace('~^\* \d+ FETCH.*$~mi', '', $s) ?? $s;
        $s = preg_replace('~^A\d+ (OK|NO|BAD).*$~mi', '', $s) ?? $s;
        $s = preg_replace('~^--[\w\-]+$~mi', '', $s) ?? $s;
        $s = preg_replace('~^(Content-Type|Content-Transfer-Encoding|Content-Disposition):.*$~mi', '', $s) ?? $s;
        $s = quoted_printable_decode($s);
        $s = preg_replace('~<br\s*/?>~i', "\n", $s) ?? $s;
        $s = preg_replace('~<(style|script)\b.*?</\1>~is', '', $s) ?? $s;
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('~[ \t]{2,}~', ' ', $s) ?? $s;

        return trim(preg_replace('~\n{3,}~', "\n\n", $s) ?? $s);
    }

    // ───────────────────────── پروتکل ─────────────────────────

    /**
     * یک فرمانِ برچسب‌دار. لیترال‌های `{n}` دقیق خوانده می‌شوند تا متنِ نامه با
     * پاسخِ پروتکل قاطی نشود — بدونِ این، یک ایمیلِ حاوی خطی که با برچسب شروع
     * شود، خواندن را وسطِ کار قطع می‌کند.
     *
     * @return array<int, string>
     */
    private function cmd(string $command): array
    {
        if (! $this->sock) {
            throw new RuntimeException('IMAP socket is not open');
        }

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

    /** @param  array<int, string>  $lines */
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
}
