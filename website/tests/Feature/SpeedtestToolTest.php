<?php

namespace Tests\Feature;

use App\Services\WebProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تست سرعت اینترنت + پارسر PSI — «کد ۲۰۰ یعنی هیچ»، پس بایت و هدر و مقدار
 * واقعی سنجیده می‌شود.
 */
class SpeedtestToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_renders_in_all_three_locales(): void
    {
        $this->get('/tools/speedtest')->assertOk()
            ->assertSee('اینترنت شما الان')
            ->assertSee('spt-start', false)
            ->assertDontSee('ui.tl_spt');

        $this->get('/en/tools/speedtest')->assertOk()->assertSee('How fast is your internet');
        $this->get('/tr/tools/speedtest')->assertOk()->assertSee('nternetiniz');
    }

    public function test_ping_endpoint_is_empty_and_uncacheable(): void
    {
        $this->get('/api/speedtest/ping')
            ->assertNoContent()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    /** بایت‌های وعده‌داده‌شده باید دقیقاً تحویل شوند — وگرنه عدد Mbps دروغ است */
    public function test_download_streams_exactly_the_requested_bytes(): void
    {
        $res = $this->get('/api/speedtest/down?mb=2');

        $res->assertOk();
        $this->assertSame((string) (2 * 1048576), $res->headers->get('Content-Length'));
        $this->assertSame(2 * 1048576, strlen($res->streamedContent()));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    /**
     * 🔴 سقف حجم: این پرهزینه‌ترین endpoint پهنای‌باند سایت است. درخواست ۵۰۰
     * مگابایتی باید بی‌سروصدا به سقف ۱۶ کوتاه شود، نه اجرا شود.
     */
    public function test_download_size_is_capped(): void
    {
        $res = $this->get('/api/speedtest/down?mb=500');

        $this->assertSame((string) (16 * 1048576), $res->headers->get('Content-Length'));

        $res2 = $this->get('/api/speedtest/down?mb=0');
        $this->assertSame((string) 1048576, $res2->headers->get('Content-Length'));
    }

    public function test_upload_echoes_the_received_byte_count(): void
    {
        $payload = str_repeat('x', 262144);

        $this->call('POST', '/api/speedtest/up', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], $payload)
            ->assertOk()
            ->assertJson(['ok' => true, 'bytes' => 262144]);
    }

    /* ═══════════════ پارسر PSI ═══════════════ */

    public function test_psi_parser_reads_numeric_values_not_localized_strings(): void
    {
        $d = [
            'lighthouseResult' => [
                'categories' => ['performance' => ['score' => 0.87]],
                'audits' => [
                    'largest-contentful-paint' => ['numericValue' => 2412.5, 'displayValue' => '۲٫۴ ث'],
                    'first-contentful-paint'   => ['numericValue' => 1100.0],
                    'total-blocking-time'      => ['numericValue' => 184.0],
                    'speed-index'              => ['numericValue' => 3300.0],
                    'cumulative-layout-shift'  => ['numericValue' => 0.0421],
                ],
            ],
        ];

        $p = WebProbe::parsePsi($d);

        $this->assertSame(87, $p['score']);
        $this->assertSame(2413, $p['lcp_ms']);
        $this->assertSame(1100, $p['fcp_ms']);
        $this->assertSame(184, $p['tbt_ms']);
        $this->assertSame(3300, $p['si_ms']);
        $this->assertSame(0.042, $p['cls']);
    }

    public function test_psi_parser_rejects_incomplete_payloads(): void
    {
        $this->assertNull(WebProbe::parsePsi([]));
        $this->assertNull(WebProbe::parsePsi(['lighthouseResult' => ['audits' => []]]));
    }

    /** خطای PSI هرگز کش نمی‌شود — سهمیه‌ی تمام‌شده نباید ۱۰ دقیقه «خرابی» بماند */
    public function test_psi_failures_are_not_cached(): void
    {
        $probe = new class extends WebProbe
        {
            public int $calls = 0;

            public function __construct()
            {
                parent::__construct(new \App\Services\NetworkTools);
            }

            protected function urlAllowed(string $url): bool
            {
                return true;
            }

            protected function fetchPsi(string $url): ?array
            {
                $this->calls++;

                return null;   // سهمیه تمام / گوگل قهر
            }
        };

        $this->assertFalse($probe->pagespeed('example.com')['ok']);
        $this->assertFalse($probe->pagespeed('example.com')['ok']);
        $this->assertSame(2, $probe->calls, 'شکست نباید کش شود — هر بار دوباره تلاش');
    }
}
