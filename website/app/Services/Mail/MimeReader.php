<?php

namespace App\Services\Mail;

/**
 * خوانندهٔ MIME — از پیامِ خامِ IMAP به چیزی که بشود نشان داد.
 *
 * 🔴 عمداً کتابخانه اضافه نشد. روی سرور نه `ext-imap` هست، نه `ext-mailparse`،
 * نه composer. افزودنِ وابستگی یعنی بازنویسیِ `vendor/` روی سایتِ زنده با دیسکِ
 * ۹۸٪ پر — ریسکی بیشتر از خودِ قابلیت.
 *
 * در عوض این کلاس روی چیزهایی سوار است که PHP از قبل دارد و روی سرور تأیید شد:
 * `iconv` · `mbstring` · `dom`. سخت‌ترین بخش، پیمایشِ درختِ چندبخشی است که
 * این‌جا نوشته و تست شده.
 *
 * ⚠️ **این پارسر برای نمایش است، نه برای بازتولیدِ بایت‌به‌بایتِ نامه.** هر جا
 * ساختار را نفهمد، به‌جای خطا دادن، امن‌ترین چیزِ ممکن را برمی‌گرداند (متنِ ساده،
 * یا در بدترین حالت رشتهٔ خالی). یک نامهٔ عجیب باید بد دیده شود، نه اینکه صفحه
 * را بترکاند.
 */
class MimeReader
{
    /** سقفِ عمقِ تودرتویی — نامهٔ سالم هرگز از این عمیق‌تر نیست. */
    private const MAX_DEPTH = 12;

    /**
     * @return array{
     *   headers: array<string,string>,
     *   subject: string,
     *   from: string,
     *   to: string,
     *   date: string,
     *   message_id: string,
     *   in_reply_to: string,
     *   references: string,
     *   text: string,
     *   html: string,
     *   attachments: list<array{name:string, mime:string, size:int, cid:string}>
     * }
     */
    public function parse(string $raw): array
    {
        [$headerBlock, $body] = $this->split($raw);
        $headers = $this->headers($headerBlock);

        $out = [
            'headers'     => $headers,
            'subject'     => $this->decodeHeader($headers['subject']     ?? ''),
            'from'        => $this->decodeHeader($headers['from']        ?? ''),
            'to'          => $this->decodeHeader($headers['to']          ?? ''),
            'date'        => $headers['date']        ?? '',
            'message_id'  => trim($headers['message-id']  ?? ''),
            'in_reply_to' => trim($headers['in-reply-to'] ?? ''),
            'references'  => trim($headers['references']  ?? ''),
            'text'        => '',
            'html'        => '',
            'attachments' => [],
        ];

        $this->walk($headers, $body, $out, 0);

        // اگر فقط HTML آمد، یک نسخهٔ متنی هم بساز تا پیش‌نمایش و نقلِ‌قول چیزی داشته باشد.
        if ($out['text'] === '' && $out['html'] !== '') {
            $out['text'] = trim(html_entity_decode(
                strip_tags(preg_replace('~<br\s*/?>|</p>~i', "\n", $out['html']) ?? ''),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ));
        }

        return $out;
    }

    /** جداکردنِ هدر از بدنه روی اولین خطِ خالی. */
    private function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");

        return $pos === false
            ? [$raw, '']
            : [substr($raw, 0, $pos), substr($raw, $pos + 2)];
    }

    /**
     * هدرها را به آرایهٔ کلیدِ کوچک برمی‌گرداند و خطوطِ ادامه‌دار (folded) را می‌چسباند.
     *
     * ⚠️ RFC می‌گوید خطی که با فاصله یا tab شروع شود، ادامهٔ هدرِ قبلی است. بدونِ
     * این، هر `Subject:`ِ بلند از وسط بریده می‌شود.
     */
    private function headers(string $block): array
    {
        $out  = [];
        $key  = null;

        foreach (explode("\n", $block) as $line) {
            if ($line === '') {
                continue;
            }

            if ($key !== null && (str_starts_with($line, ' ') || str_starts_with($line, "\t"))) {
                $out[$key] .= ' '.trim($line);

                continue;
            }

            $c = strpos($line, ':');

            if ($c === false) {
                continue;
            }

            $key = strtolower(trim(substr($line, 0, $c)));
            $val = ltrim(substr($line, $c + 1));

            // هدرِ تکراری (مثلِ Received) را با خطِ تازه نگه می‌داریم، رونویسی نمی‌کنیم.
            $out[$key] = isset($out[$key]) ? $out[$key]."\n".$val : $val;
        }

        return $out;
    }

    /**
     * پیمایشِ درختِ MIME. متن و HTML را جمع می‌کند، پیوست‌ها را فهرست می‌کند.
     *
     * @param  array<string,string>  $headers
     */
    private function walk(array $headers, string $body, array &$out, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        $ctype    = strtolower($this->paramless($headers['content-type'] ?? 'text/plain'));
        $boundary = $this->param($headers['content-type'] ?? '', 'boundary');

        if (str_starts_with($ctype, 'multipart/') && $boundary !== '') {
            foreach ($this->parts($body, $boundary) as $part) {
                [$ph, $pb] = $this->split($part);
                $this->walk($this->headers($ph), $pb, $out, $depth + 1);
            }

            return;
        }

        $encoding    = strtolower(trim($headers['content-transfer-encoding'] ?? '7bit'));
        $disposition = strtolower($this->paramless($headers['content-disposition'] ?? ''));
        $filename    = $this->param($headers['content-disposition'] ?? '', 'filename')
                    ?: $this->param($headers['content-type'] ?? '', 'name');

        $decoded = $this->decodeBody($body, $encoding);

        // پیوست: یا صریح attachment است، یا نامِ فایل دارد، یا نوعش نمایش‌دادنی نیست.
        $isAttachment = $disposition === 'attachment'
            || ($filename !== '' && $disposition !== 'inline' && ! str_starts_with($ctype, 'text/'))
            || (! str_starts_with($ctype, 'text/') && ! str_starts_with($ctype, 'multipart/'));

        if ($isAttachment) {
            $out['attachments'][] = [
                'name' => $this->decodeHeader($filename) ?: 'بدون‌نام',
                'mime' => $ctype ?: 'application/octet-stream',
                'size' => strlen($decoded),
                'cid'  => trim($headers['content-id'] ?? '', " <>\t\n"),
            ];

            return;
        }

        $charset = $this->param($headers['content-type'] ?? '', 'charset') ?: 'UTF-8';
        $text    = $this->toUtf8($decoded, $charset);

        if ($ctype === 'text/html') {
            // چند بخشِ HTML؟ به‌هم می‌چسبند؛ نامه‌های خبرنامه گاهی این‌طورند.
            $out['html'] .= ($out['html'] === '' ? '' : "\n<hr>\n").$text;
        } else {
            $out['text'] .= ($out['text'] === '' ? '' : "\n\n").$text;
        }
    }

    /**
     * بریدنِ بدنه روی مرزِ چندبخشی.
     *
     * ⚠️ مرزِ پایانی `--boundary--` است؛ هرچه بعدش بیاید (epilogue) دور ریخته می‌شود.
     */
    private function parts(string $body, string $boundary): array
    {
        $chunks = explode('--'.$boundary, $body);
        array_shift($chunks);           // preamble — پیش از اولین مرز، بی‌معنی است

        $out = [];

        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, '--')) {
                break;                  // مرزِ پایانی
            }

            $out[] = ltrim($chunk, "\n");
        }

        return $out;
    }

    private function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64'           => base64_decode(preg_replace('~\s+~', '', $body) ?? '', false) ?: '',
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }

    /**
     * `=?UTF-8?B?...?=` و دوستانش.
     *
     * 🔴 اگر نشانهٔ `=?` نباشد، دست نمی‌زنیم و همان را برمی‌گردانیم.
     *
     * `iconv_mime_decode` روی رشته‌ای که **از قبل** UTF-8ِ خام است، با پرچمِ
     * CONTINUE_ON_ERROR بایت‌های غیرِ ASCII را بی‌صدا دور می‌ریزد. یک تست این را
     * گرفت: نامِ پیوستِ `سند.pdf` که از RFC 2231 رمزگشایی شده بود، از این تابع
     * به شکلِ `.pdf` بیرون می‌آمد — بی‌هیچ خطایی.
     */
    private function decodeHeader(string $value): string
    {
        if ($value === '' || ! str_contains($value, '=?')) {
            return trim($value);
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded === false ? trim($value) : trim($decoded);
    }

    private function toUtf8(string $s, string $charset): string
    {
        $charset = strtoupper(trim($charset, "\"' \t"));

        if ($charset === '' || $charset === 'UTF-8' || $charset === 'UTF8') {
            return $s;
        }

        $converted = @iconv($charset, 'UTF-8//TRANSLIT', $s);

        if ($converted === false) {
            $converted = @mb_convert_encoding($s, 'UTF-8', $charset);
        }

        return $converted === false ? $s : $converted;
    }

    /** `text/html; charset=utf-8` → `text/html` */
    private function paramless(string $header): string
    {
        $c = strpos($header, ';');

        return trim($c === false ? $header : substr($header, 0, $c));
    }

    /**
     * پارامترِ یک هدر: `boundary`, `charset`, `filename`, `name`.
     *
     * ⚠️ هم `x="y"` را می‌گیرد هم `x=y`. پارامترِ چندتکه‌ای (RFC 2231، مثلِ
     * `filename*0=`) پشتیبانی نمی‌شود — نادر است و نبودش فقط یعنی نامِ فایل
     * ناقص، نه خطا.
     */
    private function param(string $header, string $name): string
    {
        if (preg_match('~;\s*'.preg_quote($name, '~').'\s*=\s*"([^"]*)"~i', $header, $m)) {
            return $m[1];
        }

        if (preg_match('~;\s*'.preg_quote($name, '~').'\s*=\s*([^;\s]+)~i', $header, $m)) {
            return trim($m[1], "\"'");
        }

        // RFC 2231: filename*=UTF-8''%D8%B3...
        if (preg_match('~;\s*'.preg_quote($name, '~').'\*\s*=\s*([^\x27]*)\x27[^\x27]*\x27([^;\s]+)~i', $header, $m)) {
            return rawurldecode($m[2]);
        }

        return '';
    }
}
