<?php

namespace Tests\Feature;

use App\Services\Mail\MimeReader;
use Tests\TestCase;

/**
 * خوانندهٔ MIME دستی نوشته شده (نه کتابخانه)، پس تستش سبک‌تر از حد معمول نیست.
 *
 * هر تست یک نامهٔ واقعیِ کوچک می‌سازد و ادعای مشخصی می‌سنجد. نامه‌ها عمداً با
 * `\r\n` نوشته شده‌اند چون سرورِ IMAP همان را می‌دهد.
 */
class MimeReaderTest extends TestCase
{
    private function read(string $raw): array
    {
        return (new MimeReader())->parse($raw);
    }

    public function test_it_decodes_an_encoded_subject_and_plain_body(): void
    {
        $m = $this->read(
            "Subject: =?UTF-8?B?2LPZhNin2YU=?=\r\n".
            "From: Ali <a@b.com>\r\n".
            "Content-Type: text/plain; charset=UTF-8\r\n\r\n".
            'Hello world'
        );

        $this->assertSame('سلام', $m['subject']);
        $this->assertSame('Ali <a@b.com>', $m['from']);
        $this->assertSame('Hello world', $m['text']);
    }

    public function test_it_decodes_quoted_printable_in_a_legacy_charset(): void
    {
        $m = $this->read(
            "Content-Type: text/plain; charset=ISO-8859-1\r\n".
            "Content-Transfer-Encoding: quoted-printable\r\n\r\n".
            'Caf=E9 cr=E8me'
        );

        $this->assertSame('Café crème', $m['text']);
    }

    public function test_it_keeps_plain_and_html_apart_in_multipart_alternative(): void
    {
        $m = $this->read(
            "Content-Type: multipart/alternative; boundary=\"BB\"\r\n\r\n".
            "--BB\r\nContent-Type: text/plain\r\n\r\nplain here\r\n".
            "--BB\r\nContent-Type: text/html\r\n\r\n<p>rich here</p>\r\n".
            "--BB--\r\nepilogue that must be ignored"
        );

        $this->assertSame('plain here', trim($m['text']));
        $this->assertSame('<p>rich here</p>', trim($m['html']));
    }

    public function test_it_walks_a_nested_tree_and_lists_the_attachment(): void
    {
        $m = $this->read(
            "Content-Type: multipart/mixed; boundary=\"OUT\"\r\n\r\n".
            "--OUT\r\nContent-Type: multipart/alternative; boundary=\"IN\"\r\n\r\n".
            "--IN\r\nContent-Type: text/plain\r\n\r\nbody text\r\n--IN--\r\n".
            "--OUT\r\nContent-Type: application/pdf; name=\"doc.pdf\"\r\n".
            "Content-Disposition: attachment; filename=\"doc.pdf\"\r\n".
            "Content-Transfer-Encoding: base64\r\n\r\n".base64_encode('PDFBYTES')."\r\n".
            '--OUT--'
        );

        $this->assertSame('body text', trim($m['text']));
        $this->assertCount(1, $m['attachments']);
        $this->assertSame('doc.pdf', $m['attachments'][0]['name']);
        $this->assertSame(8, $m['attachments'][0]['size']);
    }

    /** خطِ ادامه‌دار: بدونِ چسباندن، هر موضوعِ بلند از وسط بریده می‌شود. */
    public function test_it_unfolds_a_wrapped_header(): void
    {
        $m = $this->read("Subject: this is a very\r\n  long folded subject\r\nContent-Type: text/plain\r\n\r\nx");

        $this->assertSame('this is a very long folded subject', $m['subject']);
    }

    /** نامهٔ فقط-HTML باید متنِ مشتق داشته باشد، وگرنه نقلِ‌قولِ پاسخ خالی می‌ماند. */
    public function test_it_derives_text_when_only_html_is_present(): void
    {
        $m = $this->read("Content-Type: text/html\r\n\r\n<p>Hello</p><br><p>World</p>");

        $this->assertStringContainsString('Hello', $m['text']);
        $this->assertStringContainsString('World', $m['text']);
    }

    public function test_it_keeps_the_threading_headers(): void
    {
        $m = $this->read(
            "Message-ID: <abc@x>\r\nIn-Reply-To: <prev@x>\r\nReferences: <a@x> <b@x>\r\n".
            "Content-Type: text/plain\r\n\r\nre"
        );

        $this->assertSame('<abc@x>', $m['message_id']);
        $this->assertSame('<prev@x>', $m['in_reply_to']);
        $this->assertSame('<a@x> <b@x>', $m['references']);
    }

    /**
     * 🔴 نامهٔ خراب باید بد **دیده** شود، نه اینکه صفحه را بترکاند.
     *
     * صندوق ورودی جای ورودیِ خصمانه است؛ یک نامهٔ ناقص نباید کلِ پنل را ۵۰۰ کند.
     */
    public function test_it_survives_malformed_input(): void
    {
        foreach (['', 'garbage', "Subject: x\r\n", "Content-Type: multipart/mixed; boundary=\"Z\"\r\n\r\n--Z\r\nbroken"] as $bad) {
            $m = $this->read($bad);
            $this->assertIsArray($m);
            $this->assertArrayHasKey('text', $m);
        }
    }

    /**
     * ⚠️ این یکی یک باگِ واقعی را گرفت: `iconv_mime_decode` روی نامی که از قبل
     * UTF-8ِ خام بود، بایت‌های فارسی را بی‌صدا دور می‌ریخت و `سند.pdf` می‌شد `.pdf`.
     */
    public function test_it_decodes_an_rfc2231_filename_without_dropping_non_ascii(): void
    {
        $m = $this->read(
            "Content-Type: multipart/mixed; boundary=\"Q\"\r\n\r\n".
            "--Q\r\nContent-Type: application/pdf\r\n".
            "Content-Disposition: attachment; filename*=UTF-8''%D8%B3%D9%86%D8%AF.pdf\r\n\r\nX\r\n".
            '--Q--'
        );

        $this->assertSame('سند.pdf', $m['attachments'][0]['name']);
    }
}
