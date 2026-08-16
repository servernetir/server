<?php

namespace Tests\Feature;

use App\Services\NetworkTools;
use Tests\TestCase;

/**
 * ارزیاب‌های خالص SPF/DMARC و منطق بلک‌لیست — با رکوردهای مرجع، بدون شبکه.
 */
class NetworkToolsEmailBlacklistTest extends TestCase
{
    /* ═══════════════ SPF ═══════════════ */

    public function test_a_healthy_softfail_spf_is_ok(): void
    {
        $r = NetworkTools::spfEvaluate([
            'google-site-verification=abc123',
            'v=spf1 include:_spf.google.com ~all',
        ]);

        $this->assertTrue($r['found']);
        $this->assertFalse($r['multiple']);
        $this->assertSame('soft', $r['policy']);
        $this->assertTrue($r['ok']);
    }

    public function test_hardfail_policy_is_recognised(): void
    {
        $r = NetworkTools::spfEvaluate(['v=spf1 ip4:203.0.113.0/24 -all']);

        $this->assertSame('hard', $r['policy']);
        $this->assertTrue($r['ok']);
    }

    /** ‎+all یعنی «همه مجازند» — از نداشتن SPF بدتر است و نباید سالم شمرده شود */
    public function test_plus_all_is_found_but_never_ok(): void
    {
        $r = NetworkTools::spfEvaluate(['v=spf1 +all']);

        $this->assertTrue($r['found']);
        $this->assertSame('pass_all', $r['policy']);
        $this->assertFalse($r['ok']);
    }

    /** RFC 7208: بیش از یک رکورد SPF خطای قطعی (permerror) است */
    public function test_two_spf_records_are_flagged_as_error(): void
    {
        $r = NetworkTools::spfEvaluate([
            'v=spf1 include:a.com ~all',
            'v=spf1 include:b.com ~all',
        ]);

        $this->assertTrue($r['multiple']);
        $this->assertFalse($r['ok']);
    }

    /** ‎spf ورژن‌دار در وسط TXTهای دیگر نباید با پیشوندهای مشابه اشتباه شود */
    public function test_lookalike_txt_records_are_not_spf(): void
    {
        $r = NetworkTools::spfEvaluate([
            'v=spf10 -all',                      // ورژن ناموجود
            'spf1 ~all',                         // بدون v=
            'MS=ms12345678',
        ]);

        $this->assertFalse($r['found']);
        $this->assertSame('none', $r['policy']);
    }

    public function test_spf_matching_is_case_insensitive(): void
    {
        $r = NetworkTools::spfEvaluate(['V=SPF1 -ALL']);

        $this->assertTrue($r['found']);
        $this->assertSame('hard', $r['policy']);
    }

    /* ═══════════════ DMARC ═══════════════ */

    public function test_dmarc_reject_policy_is_ok(): void
    {
        $r = NetworkTools::dmarcEvaluate(['v=DMARC1; p=reject; rua=mailto:d@x.com']);

        $this->assertTrue($r['found']);
        $this->assertSame('reject', $r['policy']);
        $this->assertTrue($r['ok']);
    }

    /** p=none فقط گزارش می‌گیرد و اجرا نمی‌کند — found ولی نه ok */
    public function test_dmarc_none_policy_is_found_but_not_ok(): void
    {
        $r = NetworkTools::dmarcEvaluate(['v=DMARC1; p=none']);

        $this->assertTrue($r['found']);
        $this->assertSame('none', $r['policy']);
        $this->assertFalse($r['ok']);
    }

    public function test_missing_dmarc_is_reported_honestly(): void
    {
        $r = NetworkTools::dmarcEvaluate(['some-verification=x']);

        $this->assertFalse($r['found']);
        $this->assertNull($r['policy']);
        $this->assertFalse($r['ok']);
    }

    /* ═══════════════ تفسیر پاسخ DNSBL ═══════════════ */

    public function test_rbl_answer_in_loopback_range_means_listed(): void
    {
        $this->assertSame('listed', NetworkTools::rblInterpret('bl.spamcop.net', ['127.0.0.2']));
    }

    /**
     * 🔴 کدهای 127.255.255.x پاسخ Spamhaus به resolver عمومی/بی‌اعتبارند؛
     * «لیست‌شده» خواندنشان یعنی برچسب اسپم به هر IP سالم.
     */
    public function test_spamhaus_public_resolver_codes_are_unknown_not_listed(): void
    {
        $this->assertSame('unknown', NetworkTools::rblInterpret('zen.spamhaus.org', ['127.255.255.254']));
        $this->assertSame('unknown', NetworkTools::rblInterpret('zen.spamhaus.org', ['127.255.255.252']));
    }

    public function test_no_answer_means_clean(): void
    {
        $this->assertSame('clean', NetworkTools::rblInterpret('psbl.surriel.com', []));
    }

    /** بعضی ISPها پاسخ NXDOMAIN را hijack می‌کنند — پاسخ خارج از 127.x اعتبار ندارد */
    public function test_hijacked_answer_outside_loopback_is_unknown(): void
    {
        $this->assertSame('unknown', NetworkTools::rblInterpret('db.wpbl.info', ['185.20.30.40']));
    }

    /* ═══════════════ ترکیب blacklist() با درز استاب ═══════════════ */

    private function fakeNet(array $answers): NetworkTools
    {
        return new class($answers) extends NetworkTools
        {
            public array $queried = [];

            public function __construct(private array $answers) {}

            protected function rblQuery(string $name): array
            {
                $this->queried[] = $name;

                foreach ($this->answers as $zone => $answer) {
                    if (str_ends_with($name, $zone)) {
                        return $answer;
                    }
                }

                return ['ips' => [], 'txt' => null];
            }
        };
    }

    public function test_blacklist_reverses_the_ip_and_reports_listings(): void
    {
        $net = $this->fakeNet([
            'bl.spamcop.net' => ['ips' => ['127.0.0.2'], 'txt' => 'Blocked - see spamcop'],
        ]);

        $r = $net->blacklist('203.0.113.5');

        $this->assertTrue($r['ok']);
        $this->assertSame('203.0.113.5', $r['ip']);
        $this->assertSame(1, $r['listed']);
        $this->assertFalse($r['clean']);

        // ترتیب معکوس IP در نام پرس‌وجو — قلب پروتکل DNSBL
        $this->assertContains('5.113.0.203.bl.spamcop.net', $net->queried);

        $spamcop = collect($r['zones'])->firstWhere('zone', 'bl.spamcop.net');
        $this->assertSame('listed', $spamcop['state']);
        $this->assertSame('Blocked - see spamcop', $spamcop['reason']);

        // بقیه پاک‌اند و دلیلی ندارند
        $other = collect($r['zones'])->firstWhere('zone', 'psbl.surriel.com');
        $this->assertSame('clean', $other['state']);
        $this->assertNull($other['reason']);
    }

    public function test_clean_ip_reports_all_zones_clean(): void
    {
        $r = $this->fakeNet([])->blacklist('203.0.113.9');

        $this->assertTrue($r['clean']);
        $this->assertSame(0, $r['listed']);
        $this->assertCount(count(NetworkTools::RBL_ZONES), $r['zones']);
    }

    /** DNSBLهای ما IPv4اند؛ ورودی IPv6 باید صریح رد شود نه «پاک» گزارش شود */
    public function test_ipv6_input_is_rejected_explicitly(): void
    {
        $r = $this->fakeNet([])->blacklist('2001:db8::1');

        $this->assertFalse($r['ok']);
        $this->assertSame('ipv6_unsupported', $r['error']);
    }

    public function test_invalid_input_is_rejected(): void
    {
        $r = $this->fakeNet([])->blacklist('!!not-valid!!');

        $this->assertFalse($r['ok']);
        $this->assertSame('invalid_domain', $r['error']);
    }
}
