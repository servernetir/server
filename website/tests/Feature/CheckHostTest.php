<?php

namespace Tests\Feature;

use App\Services\CheckHost;
use App\Services\NetworkTools;
use Tests\TestCase;

/**
 * پینگ/HTTP جهانی (check-host.net) — نرمال‌سازها با payloadهای **واقعی**
 * ضبط‌شده از خود API تست می‌شوند (۱۶ اوت ۲۰۲۶)، نه شکل حدسی.
 */
class CheckHostTest extends TestCase
{
    /** بخشی از پاسخ واقعی check-ping — دست‌نخورده */
    private const PING_NODES = [
        'ir1.node.check-host.net' => ['ir', 'Iran', 'Tehran', '185.105.238.209', 'AS47430'],
        'ca1.node.check-host.net' => ['ca', 'Canada', 'Vancouver', '198.135.169.20', 'AS396993'],
        'vn1.node.check-host.net' => ['vn', 'Vietnam', 'Ho Chi Minh City', '45.252.248.142', 'AS63760'],
    ];

    private const PING_RESULT = [
        'ca1.node.check-host.net' => [[['OK', 0.0247631072998047, '8.8.8.8'], ['OK', 0.0237629413604736], ['OK', 0.0239090919494629], ['OK', 0.0238528251647949]]],
        'vn1.node.check-host.net' => [[['TIMEOUT', 3.0], ['TIMEOUT', 3.0], ['TIMEOUT', 3.0], ['TIMEOUT', 3.0]]],
        // ir1 عمداً غایب = هنوز pending
    ];

    /** بخشی از پاسخ واقعی check-http */
    private const HTTP_NODES = [
        'ir2.node.check-host.net' => ['ir', 'Iran', 'Isfahan', '195.182.38.164', 'AS209279'],
        'de1.node.check-host.net' => ['de', 'Germany', 'Nuremberg', '1.2.3.4', 'AS1'],
        'ch1.node.check-host.net' => ['ch', 'Switzerland', 'Zurich', '5.6.7.8', 'AS2'],
        'br1.node.check-host.net' => ['br', 'Brazil', 'Sao Paulo', '9.9.9.9', 'AS3'],
    ];

    private const HTTP_RESULT = [
        // کد وضعیت در پاسخ واقعی گاهی رشته است و گاهی int — هر دو این‌جا هست
        'de1.node.check-host.net' => [[1, 0.483866930007935, 'OK', 200, '65.109.176.14']],
        'br1.node.check-host.net' => [[1, 1.15053296089172, 'OK', '200', '65.109.176.14']],
        'ch1.node.check-host.net' => [null, ['message' => 'Connect timeout']],
        // ir2 غایب = pending
    ];

    /* ═══════════════ نرمال‌ساز پینگ ═══════════════ */

    public function test_ping_rows_carry_real_latency_stats(): void
    {
        $rows = CheckHost::normalizePing(self::PING_NODES, self::PING_RESULT);
        $byCc = collect($rows)->keyBy('cc');

        $ca = $byCc['ca'];
        $this->assertSame('ok', $ca['state']);
        $this->assertSame(4, $ca['sent']);
        $this->assertSame(4, $ca['recv']);
        $this->assertSame(0, $ca['loss']);
        // 0.0247s → 24.8ms و 0.0238s → 23.8ms — تبدیل ثانیه→میلی‌ثانیه درست است
        $this->assertSame(24.8, $ca['max']);
        $this->assertSame(23.8, $ca['min']);

        $this->assertSame('timeout', $byCc['vn']['state']);
        $this->assertSame(100, $byCc['vn']['loss']);
        $this->assertSame('pending', $byCc['ir']['state']);
    }

    /** نودهای ایران باید اول فهرست باشند — جذاب‌ترین ردیف برای مخاطب ما */
    public function test_iran_nodes_sort_first(): void
    {
        $rows = CheckHost::normalizePing(self::PING_NODES, self::PING_RESULT);

        $this->assertSame('ir', $rows[0]['cc']);
        $this->assertSame('Tehran', $rows[0]['city']);
    }

    public function test_ping_summary_counts_honestly(): void
    {
        $rows = CheckHost::normalizePing(self::PING_NODES, self::PING_RESULT);
        $s = CheckHost::pingSummary($rows);

        $this->assertSame(3, $s['nodes']);
        $this->assertSame(2, $s['answered']);   // ir هنوز pending است، «پاسخ‌داده» نیست
        $this->assertSame(1, $s['ok']);
    }

    /* ═══════════════ نرمال‌ساز HTTP ═══════════════ */

    public function test_http_rows_read_status_time_and_errors(): void
    {
        $rows = CheckHost::normalizeHttp(self::HTTP_NODES, self::HTTP_RESULT);
        $byCc = collect($rows)->keyBy('cc');

        $de = $byCc['de'];
        $this->assertSame('ok', $de['state']);
        $this->assertSame('200', $de['status']);       // int هم به رشته نرمال می‌شود
        $this->assertSame(484, $de['time_ms']);

        $ch = $byCc['ch'];
        $this->assertSame('error', $ch['state']);
        $this->assertSame('Connect timeout', $ch['message']);

        $this->assertSame('pending', $byCc['ir']['state']);
    }

    /** جمع‌بندی ایران: نود ایرانیِ بی‌پاسخ = null (قضاوت نکن)، نه false */
    public function test_iran_summary_never_guesses(): void
    {
        $s = CheckHost::httpSummary(CheckHost::normalizeHttp(self::HTTP_NODES, self::HTTP_RESULT));
        $this->assertNull($s['iran_ok'], 'نود ایران pending است — نمی‌دانیم یعنی نمی‌دانیم');

        // حالا نود ایران واقعاً جواب می‌دهد
        $result = self::HTTP_RESULT + ['ir2.node.check-host.net' => [[1, 0.4, 'OK', '200', '65.109.176.14']]];
        $s2 = CheckHost::httpSummary(CheckHost::normalizeHttp(self::HTTP_NODES, $result));
        $this->assertTrue($s2['iran_ok']);
    }

    /* ═══════════════ ترکیب با درزهای استاب ═══════════════ */

    private function fake(?array $start, array $result): CheckHost
    {
        return new class($start, $result) extends CheckHost
        {
            public array $started = [];

            public function __construct(private ?array $start, private array $result)
            {
                parent::__construct(new NetworkTools);
            }

            protected function startCheck(string $type, string $target): ?array
            {
                $this->started[] = [$type, $target];

                return $this->start;
            }

            protected function pollResult(string $requestId): array
            {
                return $this->result;
            }
        };
    }

    public function test_ping_composes_start_poll_and_summary(): void
    {
        $svc = $this->fake(
            ['ok' => 1, 'request_id' => 'req1', 'permanent_link' => 'https://check-host.net/check-report/req1', 'nodes' => self::PING_NODES],
            self::PING_RESULT,
        );

        $r = $svc->ping('example.com');

        $this->assertTrue($r['ok']);
        $this->assertSame('example.com', $r['domain']);
        $this->assertSame(3, $r['nodes']);
        $this->assertCount(3, $r['rows']);
        $this->assertSame([['check-ping', 'example.com']], $svc->started);
    }

    /** ورودی http بدون scheme باید با https صریح به check-host برود */
    public function test_http_sends_an_explicit_https_url(): void
    {
        $svc = $this->fake(
            ['ok' => 1, 'request_id' => 'r', 'nodes' => self::HTTP_NODES],
            self::HTTP_RESULT,
        );

        $svc->http('Example.com');

        $this->assertSame([['check-http', 'https://example.com/']], $svc->started);
    }

    public function test_unreachable_api_degrades_honestly(): void
    {
        $r = $this->fake(null, [])->ping('example.com');

        $this->assertFalse($r['ok']);
        $this->assertSame('unreachable', $r['error']);
    }

    public function test_garbage_input_is_rejected_without_any_network(): void
    {
        $svc = $this->fake(['ok' => 1, 'request_id' => 'x', 'nodes' => []], []);

        $this->assertFalse($svc->ping('!!bad!!')['ok']);
        $this->assertFalse($svc->http('10.0.0.1')['ok']);   // IP خصوصی
        $this->assertSame([], $svc->started, 'ورودی نامعتبر نباید هیچ تماسی بسازد');
    }
}
