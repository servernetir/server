<?php

namespace Tests\Feature;

use App\Models\AiUsage;
use App\Models\CreditEntry;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\AiGatewayFixture;
use Tests\TestCase;

/**
 * سیاستِ شکست (m5-spec §5): شارژ به این بسته است که «آیا ارائه‌دهنده ممکن است کارِ
 * پول‌دار انجام داده باشد» — نه اینکه مشتری بدنه‌ای گرفت یا نه.
 *
 *   نرسید ⇒ آزاد · رد شد (۴xx/۵xx) ⇒ آزاد · ۴۰۱/۴۰۲/۴۰۳ ⇒ آزاد + توقفِ ارائه‌دهنده
 *   رسید و جوابِ قابلِ اتکا نیامد ⇒ نامعلوم: پول نگه داشته می‌شود (نه آزاد، نه شارژ)
 *
 * نسخهٔ M4 مهلتِ خواندن را «نرسید» می‌شمرد و هزینهٔ بالادست را می‌بخشید (B8).
 */
class AiFailurePolicyTest extends TestCase
{
    use AiGatewayFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sellableCatalog();
    }

    private function fakeConnectError(string $message): void
    {
        Http::fake(fn (HttpRequest $r) => throw new ConnectException($message, $r->toPsrRequest()));
    }

    public function test_connect_refused_releases_with_no_ledger_row(): void
    {
        [$c, $plain] = $this->greenKey();
        $this->fakeConnectError('cURL error 7: Failed to connect to api.deepinfra.com port 443');

        $this->v1($plain, $this->chatBody())->assertStatus(502)->assertJsonPath('code', 'upstream_unreachable');

        $this->assertSame(AiUsage::STATUS_RELEASED, AiUsage::sole()->status);
        $this->assertSame(1_000_000, $this->available($c));
        $this->assertSame(0, CreditEntry::where('reason', 'like', 'ai_%')->count());
    }

    public function test_connect_timeout_is_not_sent_and_releases(): void
    {
        [$c, $plain] = $this->greenKey();
        $this->fakeConnectError('cURL error 28: Connection timed out after 10001 milliseconds');

        $this->v1($plain, $this->chatBody())->assertStatus(502);
        $this->assertSame(AiUsage::STATUS_RELEASED, AiUsage::sole()->status);
    }

    public function test_read_timeout_after_send_keeps_the_hold_and_says_do_not_retry(): void
    {
        [$c, $plain] = $this->greenKey();
        $this->fakeConnectError('cURL error 28: Operation timed out after 60000 milliseconds with 0 bytes received');

        $res = $this->v1($plain, $this->chatBody())->assertStatus(504)->assertJsonPath('code', 'upstream_timeout')
            ->assertHeader('x-should-retry', 'false');

        $u = AiUsage::sole();
        $res->assertHeader('X-Request-Id', $u->public_id);
        $this->assertSame(AiUsage::STATUS_UNKNOWN_PENDING, $u->status);
        $this->assertSame(1_000_000 - (int) $u->hold_irt, $this->available($c), 'پول تا تصمیم نگه داشته می‌شود');
        $this->assertSame(1_000_000, $this->balance($c), 'ولی هنوز شارژ نشده');
    }

    public function test_provider_400_releases_and_passes_a_short_reason(): void
    {
        [$c, $plain] = $this->greenKey();
        Http::fake(['*/chat/completions' => Http::response(['error' => ['message' => 'max context exceeded']], 400)]);

        $this->v1($plain, $this->chatBody())->assertStatus(502)->assertJsonPath('code', 'upstream_error')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'max context exceeded'));

        $this->assertSame(1_000_000, $this->available($c));
    }

    public function test_provider_402_releases_and_pauses_the_provider(): void
    {
        [$c, $plain] = $this->greenKey();
        Http::fake(['*/chat/completions' => Http::response(['error' => 'insufficient balance'], 402)]);

        $this->v1($plain, $this->chatBody())->assertStatus(503)->assertJsonPath('code', 'provider_paused');

        $this->assertNotNull($this->provider->fresh()->paused_at);
        $this->assertSame(1_000_000, $this->available($c));

        // تماسِ بعدی اصلاً به بالادست نمی‌رود
        $this->v1($plain, $this->chatBody())->assertStatus(503)->assertJsonPath('code', 'provider_paused');
        Http::assertSentCount(1);
    }

    public function test_non_json_2xx_is_unknown_not_free(): void
    {
        [$c, $plain] = $this->greenKey();
        Http::fake(['*/chat/completions' => Http::response('<html>gateway</html>', 200)]);

        $this->v1($plain, $this->chatBody())->assertStatus(502)->assertJsonPath('code', 'upstream_bad_body')
            ->assertHeader('x-should-retry', 'false');

        $this->assertSame(AiUsage::STATUS_UNKNOWN_PENDING, AiUsage::sole()->status);
    }

    public function test_2xx_without_usage_is_unknown_not_zero(): void
    {
        [$c, $plain] = $this->greenKey();
        Http::fake(['*/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'x']]]], 200)]);

        $this->v1($plain, $this->chatBody())->assertStatus(502)->assertJsonPath('code', 'upstream_bad_body');

        $u = AiUsage::sole();
        $this->assertSame(AiUsage::STATUS_UNKNOWN_PENDING, $u->status);
        $this->assertNull($u->charged_irt);
    }
}
