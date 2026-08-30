<?php

namespace Tests\Feature;

use App\Services\Domain\OpenProviderClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * مدارشکنِ احراز هویت — پایانِ «طوفانِ لاگین».
 *
 * ═══ باگِ ممیزیِ شهریور ۱۴۰۵ ═══
 *
 * 🔴 وقتی حساب با کد ۱۹۶ رد می‌شود (IP خارج از allowlist / حسابِ
 * علامت‌خورده)، پاسخ تا دخالتِ انسان عوض نمی‌شود — ولی هیچ ترمزی نبود:
 * هر کالِ منطقی ۲ تلاشِ لاگین می‌زد و یک جستجوی ساده (۷ بچ) یعنی **۱۴
 * تلاشِ لاگین** به حسابی که دقیقاً به‌خاطرِ تماسِ زیاد پرچم خورده بود.
 * خودِ سازوکارِ «تلاش دوباره»، حساب را بیشتر در خطر می‌گذاشت.
 */
class OpenProviderCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openprovider.username', 'test@example.com');
        config()->set('services.openprovider.password', 'secret');
    }

    private function client(): OpenProviderClient
    {
        return app(OpenProviderClient::class);
    }

    private function loginAttempts(): int
    {
        return count(Http::recorded(
            fn ($req) => str_contains($req->url(), '/auth/login')
        ));
    }

    // ═══════════════ مدار باز می‌شود ═══════════════

    public function test_a_failed_login_opens_the_circuit_and_later_calls_send_nothing(): void
    {
        Http::fake(['*' => Http::response(['code' => 196, 'desc' => 'Authentication Failed'], 500)]);

        // سه کالِ منطقیِ پشتِ سرِ هم — مثلِ سه بچِ یک جستجو
        $this->client()->check([['name' => 'a', 'extension' => 'com']]);
        $this->client()->check([['name' => 'b', 'extension' => 'com']]);
        $this->client()->check([['name' => 'c', 'extension' => 'com']]);

        $this->assertSame(1, $this->loginAttempts(),
            'هر کال یک لاگینِ تازه زد — طوفانِ لاگین روی حسابِ پرچم‌خورده');
    }

    public function test_the_failure_is_reported_not_just_logged(): void
    {
        Http::fake(['*' => Http::response(['code' => 196, 'desc' => 'Authentication Failed'], 500)]);

        $this->client()->check([['name' => 'a', 'extension' => 'com']]);

        $this->assertNotNull(Cache::get('openprovider.auth_down'),
            'شکستِ لاگین باید مدار را باز کند');
    }

    // ═══════════════ مدار بسته می‌شود ═══════════════

    public function test_a_successful_login_closes_the_circuit(): void
    {
        // مدار از قبل باز — مثلاً از یک شکستِ ده دقیقه پیش که TTLاش گذشته
        Cache::put('openprovider.auth_down', 196, now()->addMinutes(10));
        Cache::forget('openprovider.auth_down');

        Http::fake([
            '*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't1']]),
            '*'             => Http::response(['code' => 0, 'data' => ['results' => []]]),
        ]);

        $res = $this->client()->check([['name' => 'a', 'extension' => 'com']]);

        $this->assertTrue($res['ok']);
        $this->assertNull(Cache::get('openprovider.auth_down'),
            'لاگینِ موفق باید مدار را بسته نگه دارد');
    }

    /** رفتارِ سالمِ «توکنِ کهنه» نشکند: یک بار توکنِ تازه، بعد موفق */
    public function test_a_stale_token_still_gets_exactly_one_fresh_retry(): void
    {
        Cache::put('openprovider.token', 'stale-token', now()->addHours(6));

        $calls = 0;

        Http::fake([
            '*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 'fresh']]),
            '*'             => function () use (&$calls) {
                $calls++;

                // اولین کال با توکنِ کهنه ⇒ ۱۹۶؛ بعدی موفق
                return $calls === 1
                    ? Http::response(['code' => 196, 'desc' => 'expired'], 500)
                    : Http::response(['code' => 0, 'data' => ['results' => []]]);
            },
        ]);

        $res = $this->client()->check([['name' => 'a', 'extension' => 'com']]);

        $this->assertTrue($res['ok'], 'توکنِ کهنه باید با یک لاگینِ تازه ترمیم شود');
        $this->assertSame(1, $this->loginAttempts());
    }

    /** ۱۹۶ِ پابرجا حتی با لاگینِ موفق، مدار را باز می‌کند (IP روی APIها بسته است) */
    public function test_a_persistent_196_after_a_fresh_token_opens_the_circuit(): void
    {
        Cache::put('openprovider.token', 'stale-token', now()->addHours(6));

        Http::fake([
            '*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 'fresh']]),
            '*'             => Http::response(['code' => 196, 'desc' => 'Authorization Failed'], 500),
        ]);

        $res = $this->client()->check([['name' => 'a', 'extension' => 'com']]);

        $this->assertFalse($res['ok']);
        $this->assertNotNull(Cache::get('openprovider.auth_down'),
            '۱۹۶ بعد از توکنِ تازه یعنی مشکل ساختاری است — مدار باید باز شود');
    }

    // ═══════════════ صفحهٔ عمومی روی کشِ سرد ═══════════════

    /**
     * 🔴 جعبهٔ جستجوی صفحهٔ اول فقط دامنهٔ **تایپ‌شده** را زنده می‌پرسد؛
     * دفترچهٔ قیمتِ پیشنهادها هرگز وسطِ درخواستِ وب گرم نمی‌شود — آن کارِ
     * کرونِ domains:price-book است. بی‌این، هر بازدیدکننده روی کشِ سرد
     * یک استعلامِ ۸پسوندی اضافه به حسابِ پرچم‌خورده می‌زد.
     */
    public function test_the_public_search_box_never_warms_the_price_book_inline(): void
    {
        Http::fake([
            '*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*'             => Http::response(['code' => 0, 'data' => ['results' => []]]),
        ]);

        $this->post('/api/domain-check', ['domain' => 'example.com']);

        $probe = Http::recorded(fn ($req) => str_contains($req->body() ?? '', 'sn7price9check4base'));

        $this->assertCount(0, $probe,
            'درخواستِ وب دفترچهٔ قیمت را زنده گرم کرد — بازدیدکننده به تماسِ API تبدیل شد');
    }
}
