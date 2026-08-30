<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Cloud\CloudProvisioner;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * هشدارِ «ماشینِ رهاشده» و راهِ خاموش‌کردنش.
 *
 * ═══ خرابیِ واقعی که این فایل از تکرارش جلوگیری می‌کند ═══
 *
 * سرویسِ #۳۰ چهل‌وشش بار پشتِ سر هم تلاشِ ناموفقِ آزادسازی داشت و هر ساعت دو
 * هشدار می‌فرستاد. علتش این بود که کارفرما ماشین را **دستی** از پنلِ دیتاسنتر
 * پاک کرده بود؛ از آن لحظه API دیگر آن سرور را نمی‌شناخت و هر تلاشِ خودکار
 * برای همیشه شکست می‌خورد.
 *
 * دو ایراد، هر دو در طراحی:
 *   ۱) هشدار نمی‌گفت **کدام ماشین** — نه IP، نه مشتری. یعنی برای هر اقدامی
 *      باید پنل باز می‌شد، و عملاً خوانده نمی‌شد.
 *   ۲) هیچ راهی برای گفتنِ «تمام شد» نبود.
 */
class CloudLingeringAlertTest extends TestCase
{
    use RefreshDatabase;

    private function stuckService(?string $ip = '203.0.113.77'): Service
    {
        $customer = Customer::create([
            'email' => 'c'.random_int(1, 999999).'@x.com',
            'password' => 'secret123',
        ]);

        $service = Service::create([
            'customer_id' => $customer->id,
            'name' => 'سرور مجازی vps-ottt5f (ساعتی)',
            'status' => 'cancelled',
            'provision_status' => Service::PROVISION_RELEASING,
        ]);

        CloudInstance::create([
            'service_id' => $service->id,
            'provider' => 'hetzner',
            'provider_ref' => '12345678',
            'location_code' => 'fsn1',
            'ipv4' => $ip,
            'status' => 'running',
        ]);

        return $service;
    }

    // ══════════════════════════════════════════════════════════════════

    public function test_marking_it_released_takes_it_out_of_the_retry_queue(): void
    {
        $service = $this->stuckService();

        $this->assertSame(1, Service::awaitingRelease()->count());

        $done = app(ProvisioningService::class)->markReleasedManually($service, 'bale');

        $this->assertTrue($done);
        $this->assertSame(0, Service::awaitingRelease()->count(), 'صف باید خالی شود');

        /*
        | ⚠️ همان وضعیتی که مسیرِ **موفق** می‌نویسد، نه یک وضعیتِ تازه. وضعیتِ
        | تازه یعنی هر پرس‌وجویی که امروز روی این دو مقدار حساب می‌کند، فردا یک
        | حالتِ نادیده داشته باشد.
        */
        $this->assertSame(Service::PROVISION_NONE, $service->fresh()->provision_status);
    }

    public function test_it_leaves_a_trace_that_a_human_said_so(): void
    {
        /*
        | 🔴 تفاوتِ «زیرساخت تأیید کرد» و «آدمی گفت تأیید شده» باید بعداً قابلِ
        | تشخیص باشد — به‌خصوص اگر روزی صورت‌حسابِ ارائه‌دهنده بگوید ماشین هنوز
        | زنده بوده.
        */
        $service = $this->stuckService();

        app(ProvisioningService::class)->markReleasedManually($service, 'bale');

        $meta = (array) $service->fresh()->provision_meta;

        $this->assertNotEmpty($meta['released_manually_at'] ?? null);
        $this->assertSame('bale', $meta['released_manually_by'] ?? null);
    }

    public function test_the_cloud_instance_is_closed_too(): void
    {
        // وگرنه ردیف در فهرستِ موجودی «زنده» می‌مانَد و دوباره گیج‌کننده می‌شود
        $service = $this->stuckService();

        app(ProvisioningService::class)->markReleasedManually($service);

        $this->assertSame('deleted', CloudInstance::where('service_id', $service->id)->first()->status);
    }

    public function test_a_service_not_in_the_queue_is_left_alone(): void
    {
        /*
        | 🔴 دکمه نباید روی سرویسی که ماشینش **واقعاً** زنده است کار کند —
        | وگرنه نشتی را از رادار پاک می‌کنیم بی‌آنکه بسته باشیمش.
        */
        $service = $this->stuckService();
        $service->forceFill(['provision_status' => 'done'])->save();

        $this->assertFalse(app(ProvisioningService::class)->markReleasedManually($service));
        $this->assertSame('done', $service->fresh()->provision_status);
    }

    // ══════════════════════════════════════════════════════════════════
    // محتوای خودِ هشدار
    // ══════════════════════════════════════════════════════════════════

    /** متنی که به مدیر می‌رود را برمی‌گرداند. */
    private function alertText(Service $service, string $why = 'Service is in an invalid status'): string
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['ok' => true])]);

        /*
        | 🔴 مقصدِ بله از `config` می‌آید نه از جدولِ `settings`.
        | `AdminNotifier::sendBale()` → `BaleNotifier::toAdmin()` اول
        | `servernet.contact.notify_chat_id` را می‌خواند؛ بی‌آن هیچ درخواستی
        | ثبت نمی‌شود و تست روی رشتهٔ خالی ادعا می‌کند.
        */
        config()->set('services.bale.token', 'bot-token-123');
        config()->set('servernet.contact.notify_chat_id', '700700');
        config()->set('servernet.contact.notify_phone', '09120000000');

        app(CloudProvisioner::class)->recordReleaseFailure($service, $why);

        $out = '';

        foreach (Http::recorded() as [$req]) {
            $out .= ' '.json_encode($req->data(), JSON_UNESCAPED_UNICODE);
        }

        return $out;
    }

    public function test_the_alert_names_the_machine_and_the_customer(): void
    {
        /*
        | 🔴 خرابیِ واقعی: هشدارِ ساعتی فقط نام و شمارهٔ سرویس را می‌گفت. برای
        | فهمیدنِ **کدام ماشین**، کارفرما باید پنل را باز می‌کرد — روی موبایل،
        | وسطِ کار. عملاً یعنی هشدار خوانده نمی‌شد.
        */
        $service = $this->stuckService('203.0.113.77');

        $text = $this->alertText($service);

        $this->assertStringContainsString('203.0.113.77', $text, 'IP باید در هشدار باشد');
        $this->assertStringContainsString('#'.$service->id, $text);
        $this->assertStringContainsString('hetzner', $text, 'ارائه‌دهنده باید معلوم باشد');
        $this->assertStringContainsString('12345678', $text, 'شناسهٔ سرور نزدِ ارائه‌دهنده');
    }

    public function test_the_alert_says_how_to_stop_it(): void
    {
        /*
        | 🔴 هشداری که راهِ بستنش را نگوید، فقط انتظار می‌سازد. کارفرما ماشین را
        | دستی پاک کرده بود و هشدار همچنان می‌آمد، چون هیچ‌جا نگفته بودیم چطور
        | می‌شود گفت «تمام شد».
        */
        $text = $this->alertText($this->stuckService());

        $this->assertStringContainsString('آزادسازی دستی انجام شد', $text);
    }

    public function test_the_retry_command_skips_it_afterwards(): void
    {
        $service = $this->stuckService();

        app(ProvisioningService::class)->markReleasedManually($service);

        // ⚠️ ادعای واقعی: کرونِ ساعتی دیگر سراغش نمی‌رود
        $this->artisan('cloud:release-retry')
            ->expectsOutputToContain('صفِ آزادسازی خالی است.')
            ->assertExitCode(0);
    }
}
