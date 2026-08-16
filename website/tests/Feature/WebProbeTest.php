<?php

namespace Tests\Feature;

use App\Services\NetworkTools;
use App\Services\WebProbe;
use Tests\TestCase;

/**
 * منطق خالص WebProbe با مقدار مرجع — بدون هیچ تماس شبکه.
 * درس §۸: «کد ۲۰۰ یعنی هیچ» — این‌جا خود مقادیر سنجیده می‌شوند.
 */
class WebProbeTest extends TestCase
{
    /* ═══════════════ نمره‌دهی هدرهای امنیتی ═══════════════ */

    public function test_all_six_headers_score_a_plus(): void
    {
        $g = WebProbe::gradeHeaders([
            'strict-transport-security' => 'max-age=63072000',
            'content-security-policy'   => "default-src 'self'",
            'x-frame-options'           => 'SAMEORIGIN',
            'x-content-type-options'    => 'nosniff',
            'referrer-policy'           => 'strict-origin-when-cross-origin',
            'permissions-policy'        => 'camera=()',
        ]);

        $this->assertSame(100, $g['score']);
        $this->assertSame('A+', $g['grade']);
        $this->assertNotContains(false, $g['checks']);
    }

    public function test_no_headers_score_f(): void
    {
        $g = WebProbe::gradeHeaders(['content-type' => 'text/html']);

        $this->assertSame(0, $g['score']);
        $this->assertSame('F', $g['grade']);
    }

    public function test_hsts_plus_nosniff_is_exactly_40_grade_d(): void
    {
        $g = WebProbe::gradeHeaders([
            'strict-transport-security' => 'max-age=1',
            'x-content-type-options'    => 'nosniff',
        ]);

        $this->assertSame(40, $g['score']);
        $this->assertSame('D', $g['grade']);
    }

    /** frame-ancestors داخل CSP باید به‌جای XFO پذیرفته شود */
    public function test_csp_frame_ancestors_counts_as_frame_protection(): void
    {
        $g = WebProbe::gradeHeaders([
            'content-security-policy' => "default-src 'self'; frame-ancestors 'none'",
        ]);

        $this->assertTrue($g['checks']['frame']);
        $this->assertSame(40, $g['score']);   // csp 25 + frame 15
    }

    /** هدرها با هر بزرگی/کوچکی حروف باید شناخته شوند */
    public function test_header_names_are_case_insensitive(): void
    {
        $g = WebProbe::gradeHeaders(['Strict-Transport-Security' => 'max-age=1']);

        $this->assertTrue($g['checks']['hsts']);
    }

    /* ═══════════════ بسته‌ی DNS خام (RFC1035) ═══════════════ */

    public function test_dns_query_packet_matches_reference_bytes(): void
    {
        $packet = WebProbe::dnsQueryPacket('example.com', 0x1234);

        $this->assertSame(
            '123401000001000000000000076578616d706c6503636f6d0000010001',
            bin2hex($packet)
        );
    }

    /** رکورد پاسخ با اشاره‌گر فشرده‌سازی نام (0xC00C) — شکل رایج پاسخ واقعی */
    public function test_dns_answer_ips_reference_vector(): void
    {
        $header = pack('n6', 0x1234, 0x8180, 1, 1, 0, 0);
        $question = "\x07example\x03com\x00".pack('n2', 1, 1);
        $rr = "\xC0\x0C".pack('n2', 1, 1).pack('N', 60).pack('n', 4)."\x0A\x0A\x22\x22";   // 10.10.34.34

        $ips = WebProbe::dnsAnswerIps($header.$question.$rr);

        $this->assertSame(['10.10.34.34'], $ips);
    }

    /** پاسخ NXDOMAIN (کد خطای ۳) هرگز IP نمی‌دهد */
    public function test_nxdomain_yields_no_ips(): void
    {
        $header = pack('n6', 1, 0x8183, 1, 0, 0, 0);
        $question = "\x07example\x03com\x00".pack('n2', 1, 1);

        $this->assertSame([], WebProbe::dnsAnswerIps($header.$question));
    }

    public function test_garbage_packet_yields_no_ips_and_no_exception(): void
    {
        $this->assertSame([], WebProbe::dnsAnswerIps(''));
        $this->assertSame([], WebProbe::dnsAnswerIps('abc'));
        $this->assertSame([], WebProbe::dnsAnswerIps(str_repeat("\xFF", 40)));
    }

    public function test_iran_block_page_ip_detection(): void
    {
        $this->assertTrue(WebProbe::isIranBlockIp('10.10.34.34'));
        $this->assertTrue(WebProbe::isIranBlockIp('10.10.34.36'));
        $this->assertFalse(WebProbe::isIranBlockIp('93.184.216.34'));
        $this->assertFalse(WebProbe::isIranBlockIp('10.10.35.1'));
    }

    /* ═══════════════ جمع‌بندی دسترسی از ایران ═══════════════ */

    public function test_access_verdict_truth_table(): void
    {
        // مدرک مستقیم فیلترینگ بر همه‌چیز مقدم است
        $this->assertSame('filtered', WebProbe::accessVerdict(true, true, 200, ['state' => 'ok', 'ok' => true, 'status' => 200]));

        // probe جواب داد و سایت باز شد
        $this->assertSame('accessible', WebProbe::accessVerdict(false, true, 200, ['state' => 'ok', 'ok' => true, 'status' => 200]));

        // probe جواب داد ولی سایت از ایران باز نشد (مثلاً 403 تحریمی)
        $this->assertSame('unreachable_iran', WebProbe::accessVerdict(false, true, 200, ['state' => 'ok', 'ok' => true, 'status' => 403]));

        // probe نداریم؛ DNS ایران سالم + از اروپا باز = به‌احتمال زیاد سالم
        $this->assertSame('likely_ok', WebProbe::accessVerdict(false, true, 200, ['state' => 'unconfigured']));

        // هیچ مدرکی — صادقانه «نمی‌دانم»
        $this->assertSame('unknown', WebProbe::accessVerdict(false, false, null, ['state' => 'unconfigured']));
    }

    /* ═══════════════ زنجیره‌ی ریدایرکت (با درز استاب) ═══════════════ */

    /** WebProbe با شبکه‌ی استاب‌شده — هیچ DNS و HTTP واقعی */
    private function fakeProbe(array $hops): WebProbe
    {
        return new class($hops) extends WebProbe
        {
            public function __construct(private array $hopMap)
            {
                parent::__construct(new NetworkTools);
            }

            protected function urlAllowed(string $url): bool
            {
                return ! str_contains($url, 'unsafe');
            }

            protected function fetchHop(string $url): ?array
            {
                return $this->hopMap[$url] ?? null;
            }
        };
    }

    public function test_redirect_chain_is_followed_to_the_end(): void
    {
        $r = $this->fakeProbe([
            'http://site.com/'       => ['status' => 301, 'location' => 'https://site.com/'],
            'https://site.com/'      => ['status' => 301, 'location' => 'https://site.com/home'],
            'https://site.com/home'  => ['status' => 200, 'location' => ''],
        ])->redirects('http://site.com');

        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['count']);
        $this->assertSame('https://site.com/home', $r['final']);
        $this->assertSame(200, $r['final_status']);
        $this->assertTrue($r['https_upgrade']);
        $this->assertFalse($r['loop']);
    }

    public function test_redirect_loop_is_detected_not_followed_forever(): void
    {
        $r = $this->fakeProbe([
            'https://a.com/' => ['status' => 302, 'location' => 'https://b.com/'],
            'https://b.com/' => ['status' => 302, 'location' => 'https://a.com/'],
        ])->redirects('a.com');

        $this->assertTrue($r['loop']);
        $this->assertLessThanOrEqual(3, count($r['hops']));
    }

    /** پرش به مقصد ناامن باید همان‌جا قطع شود — SSRF از راه ریدایرکت */
    public function test_redirect_to_unsafe_target_is_blocked(): void
    {
        $r = $this->fakeProbe([
            'https://a.com/' => ['status' => 302, 'location' => 'https://unsafe.internal/'],
        ])->redirects('a.com');

        $last = end($r['hops']);
        $this->assertTrue($last['blocked'] ?? false);
    }

    public function test_no_redirect_page_reports_zero_hops(): void
    {
        $r = $this->fakeProbe([
            'https://a.com/' => ['status' => 200, 'location' => ''],
        ])->redirects('a.com');

        $this->assertSame(0, $r['count']);
        $this->assertFalse($r['https_upgrade']);
    }

    /* ═══════════════ پیکربندی probe ایران ═══════════════ */

    /**
     * 🔴 محافظ تله‌ی ثبت‌شده‌ی «مسیر غلط config = درایور خاموش»: کلید باید
     * دقیقاً همان‌جایی در فایل واقعی باشد که WebProbe می‌خواند — فایل از دیسک
     * خوانده می‌شود و هیچ config()ی دستی ست نمی‌شود.
     */
    public function test_the_iran_probe_key_sits_where_webprobe_reads_it(): void
    {
        $cfg = require base_path('config/services.php');

        $this->assertArrayHasKey('iran_probe', $cfg);
        $this->assertArrayHasKey('url', $cfg['iran_probe']);
        $this->assertArrayHasKey('token', $cfg['iran_probe']);
        // و مسیریابی AI پیشنهادگر هم در همان بلوکی است که AiContent::provider می‌خواند
        $this->assertArrayHasKey('ideas', $cfg['ai_routing']);
    }

    public function test_probe_unconfigured_degrades_without_error(): void
    {
        config(['services.iran_probe.url' => null]);
        $probe = new WebProbe(new NetworkTools);

        $this->assertFalse($probe->probeConfigured());
    }

    public function test_probe_requires_https(): void
    {
        config(['services.iran_probe.url' => 'http://insecure.example/webhook']);
        $probe = new WebProbe(new NetworkTools);

        $this->assertFalse($probe->probeConfigured());

        config(['services.iran_probe.url' => 'https://flow.example/webhook/x']);
        $this->assertTrue((new WebProbe(new NetworkTools))->probeConfigured());
    }
}
