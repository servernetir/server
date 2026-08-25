<?php

namespace Tests\Feature;

use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * پایشِ موجودیِ حساب نزدِ رجیسترار.
 *
 * ═══ چرا (ممیزی + خواستهٔ کارفرما) ═══
 *
 * 🔴 اگر اعتبارِ حسابِ OpenProvider ته بکشد، هر ثبت و تمدیدی شکست می‌خورد و
 * تنها علامتش انباشتِ بی‌صدای صفِ دستی بود — خبری که همیشه یک خرابیِ
 * انجام‌شده دیرتر می‌رسید.
 *
 * ⚠️ جای فیلدِ موجودی در پاسخِ واقعی دیده نشده؛ پس «ناخوانا» هرگز هشدارِ
 * قلابی نمی‌شود (درسِ «آژیرِ خفه») و جست‌وجوی کلید بازگشتی است.
 */
class DomainRegistrarBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.openprovider.username', 'u');
        config()->set('services.openprovider.password', 'p');
        config()->set('services.openprovider.min_balance', 10);

        \Illuminate\Support\Facades\File::put(
            storage_path('app/'.SystemHealth::HEARTBEAT), now()->toDateTimeString()
        );
    }

    private function fakeResellers(array $data): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake([
            '*/auth/login*' => Http::response(['code' => 0, 'data' => ['token' => 't']]),
            '*/resellers*'  => Http::response(['code' => 0, 'data' => $data]),
            '*'             => Http::response([], 500),
        ]);
    }

    private function balanceRow(): array
    {
        return collect(app(SystemHealth::class)->checks())->keyBy('key')['domain_balance'];
    }

    public function test_a_low_balance_fails_the_health_check(): void
    {
        $this->fakeResellers(['balance' => 4.2, 'currency' => 'EUR']);

        $row = $this->balanceRow();

        $this->assertSame('fail', $row['level'], 'موجودیِ روبه‌اتمام باید آژیر بزند — پیش از شکستِ ثبت');
        $this->assertStringContainsString('4.2', $row['detail']);
    }

    public function test_a_healthy_balance_is_ok_and_shows_the_amount(): void
    {
        $this->fakeResellers(['balance' => 250, 'currency' => 'EUR']);

        $row = $this->balanceRow();

        $this->assertTrue((bool) $row['ok']);
        $this->assertStringContainsString('250', $row['detail']);
    }

    /** شکلِ تودرتو هم پیدا می‌شود — جای فیلد در پاسخِ واقعی قطعی نیست */
    public function test_a_nested_balance_shape_is_still_found(): void
    {
        $this->fakeResellers(['results' => [['reseller' => ['balance' => ['value' => 3, 'currency' => 'EUR']]]]]);

        $this->assertSame('fail', $this->balanceRow()['level']);
    }

    /** 🔴 ناخوانا هرگز هشدارِ قلابی نمی‌شود — همان درسِ «آژیرِ خفه» */
    public function test_an_unreadable_balance_never_fails_the_check(): void
    {
        $this->fakeResellers(['something' => 'else']);

        $this->assertTrue((bool) $this->balanceRow()['ok'],
            'ردیفِ دائم-خراب امضای هشدار را اشغال می‌کند و آژیرِ واقعی را خفه می‌کند');
    }

    public function test_registrar_down_is_not_an_alarm_either(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response([], 500)]);

        $this->assertTrue((bool) $this->balanceRow()['ok']);
    }
}
