<?php

namespace Tests\Unit\Dns;

use App\Services\Dns\DomainName;
use App\Services\Dns\TxtCodec;
use PHPUnit\Framework\TestCase;

/**
 * TXT و نامِ دامنه — قاعده‌هایی که آزمایشگاهِ PowerDNS 5.0.2 تحمیل کرد
 * (بک‌اسلش و بایتِ کنترلی را API خراب می‌کند) و نرمال‌سازیِ IDNA.
 */
class TxtCodecAndDomainNameTest extends TestCase
{
    public function test_plain_text_becomes_one_quoted_string(): void
    {
        $r = TxtCodec::fromUserInput('v=spf1 include:_spf.google.com ~all');
        $this->assertTrue($r['ok']);
        $this->assertSame('"v=spf1 include:_spf.google.com ~all"', $r['value']);
    }

    public function test_long_text_is_split_at_255_bytes_and_joins_back(): void
    {
        $long = 'v=DKIM1; k=rsa; p='.str_repeat('A', 400);
        $r = TxtCodec::fromUserInput($long);
        $this->assertTrue($r['ok']);
        foreach ($r['strings'] as $s) {
            $this->assertLessThanOrEqual(255, strlen($s));
        }
        $this->assertSame($long, implode('', TxtCodec::decode($r['value'])));
    }

    /** 🔴 تکه‌کردن نباید یک نویسهٔ چندبایتیِ UTF-8 را نصف کند */
    public function test_utf8_is_split_only_on_character_boundaries(): void
    {
        $text = str_repeat('سلام ', 80);            // ~720 bytes, 2-byte chars
        $r = TxtCodec::fromUserInput($text);
        $this->assertTrue($r['ok']);
        foreach ($r['strings'] as $s) {
            $this->assertTrue(mb_check_encoding($s, 'UTF-8'), 'chunk is valid UTF-8');
            $this->assertLessThanOrEqual(255, strlen($s));
        }
        $this->assertSame(trim($text), implode('', TxtCodec::decode($r['value'])));
    }

    public function test_quoted_multi_string_input_keeps_its_boundaries(): void
    {
        $r = TxtCodec::fromUserInput('"part one" "part two"');
        $this->assertTrue($r['ok']);
        $this->assertSame('"part one" "part two"', $r['value']);
        $this->assertSame(['part one', 'part two'], TxtCodec::decode($r['value']));
    }

    public function test_adjacent_strings_get_one_space_because_the_api_rejects_them_glued(): void
    {
        $r = TxtCodec::fromUserInput('"a""b"');
        $this->assertTrue($r['ok']);
        $this->assertSame('"a" "b"', $r['value']);
    }

    public function test_embedded_quote_round_trips(): void
    {
        $r = TxtCodec::fromUserInput('say "hi"');
        $this->assertTrue($r['ok']);
        $this->assertSame(['say "hi"'], TxtCodec::decode($r['value']));
    }

    public static function rejected(): array
    {
        return [
            'empty'           => ['   ', 'txt_empty'],
            'backslash'       => ['C:\\path', 'txt_backslash'],
            'quoted escape'   => ['"a\\\\b"', 'txt_backslash'],
            'control char'    => ["bad\x01byte", 'txt_control'],
            'del'             => ["bad\x7Fbyte", 'txt_control'],
            'invalid utf8'    => ["\xC3\x28", 'txt_encoding'],
            'unterminated'    => ['"open', 'txt_syntax'],
            'too long'        => [str_repeat('x', 5000), 'txt_too_long'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejected')]
    public function test_rejects_content_powerdns_would_corrupt(string $in, string $code): void
    {
        $r = TxtCodec::fromUserInput($in);
        $this->assertFalse($r['ok']);
        $this->assertSame($code, $r['code']);
        $this->assertContains($code, TxtCodec::CODES);
    }

    public function test_equals_compares_logical_content(): void
    {
        $this->assertTrue(TxtCodec::equals('"ab" "c"', '"ab" "c"'));
        $this->assertFalse(TxtCodec::equals('"ab" "c"', '"abc"'), 'string boundaries are part of the data');
    }

    /* ───────── DomainName ───────── */

    public static function names(): array
    {
        return [
            'case + dot'        => ['Example.COM.', 'example.com'],
            'scheme and path'   => ['https://Example.com/path?x', 'example.com'],
            'idn'               => ['مثال.ایران', 'xn--mgbh0fb.xn--mgba3a4f16a'],
            'punycode kept'     => ['xn--mgbh0fb.xn--mgba3a4f16a', 'xn--mgbh0fb.xn--mgba3a4f16a'],
            'empty'             => ['', null],
            'single label'      => ['localhost', null],
            'leading hyphen'    => ['-bad.com', null],
            'label too long'    => [str_repeat('a', 64).'.com', null],
            'underscore host'   => ['a_b.com', null],
            'ip address'        => ['192.0.2.1', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('names')]
    public function test_normalize(string $in, ?string $expected): void
    {
        $this->assertSame($expected, DomainName::normalize($in));
    }

    public function test_www_is_not_silently_stripped(): void
    {
        $this->assertSame('www.example.com', DomainName::normalize('www.example.com'));
    }

    public function test_subdomain_and_relative_helpers(): void
    {
        $this->assertTrue(DomainName::isSubdomainOf('a.b.example.com', 'example.com'));
        $this->assertFalse(DomainName::isSubdomainOf('badexample.com', 'example.com'));
        $this->assertSame('a.b', DomainName::relative('a.b.example.com', 'example.com'));
        $this->assertSame('@', DomainName::relative('example.com', 'example.com'));
    }
}
