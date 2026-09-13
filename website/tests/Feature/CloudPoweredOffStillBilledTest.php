<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «خاموش کردم، ولی هنوز پول کم می‌شود.»
 *
 * ═══ رخدادِ ۱۵ شهریور ۱۴۰۵ — مشتریِ SN-593484 ═══
 *
 * مشتری سرورش را خاموش کرد، در پنلِ زیرساخت هم خاموش بود، و اعتبارش همچنان
 * ساعتی کم می‌شد. حق داشت گیج شود: صفحهٔ سرویس «خاموش» می‌گفت و
 * `/account/servers` «فعال» — یکی وضعیتِ **برقِ ماشین** است و دیگری وضعیتِ
 * **اشتراک**، ولی مشتری آن دو کلمه را کنارِ هم متناقض می‌خوانَد.
 *
 * 🔴 **کسر باگ نبود.** اجارهٔ ماشینِ رزروشده را ما همچنان به زیرساخت می‌دهیم و
 * فقط **حذف** آن را قطع می‌کند — `CloudMeterHourly::billable()` عمداً همین را
 * می‌کند و کامنتش هم همین را می‌گوید. پس رفع، برداشتنِ کسر نیست؛ **گفتنِ
 * حقیقت پیش از کلیک** است.
 *
 * ⚠️ و مهم‌ترین ادعای این فایل **منفی** است: روی پلنِ `is_interruptible` این
 * هشدار نباید بیاید. آن‌جا ماشینِ خاموش واقعاً برای ما هزینه ندارد و متر هم
 * نمی‌شمارد — هشدارِ نابه‌جا یعنی به مشتری دروغ گفته‌ایم و او سروری را که
 * می‌توانست خاموش نگه دارد، روشن می‌گذارد.
 */
class CloudPoweredOffStillBilledTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function service(bool $hourly, bool $interruptible): Service
    {
        $customer = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'po'.random_int(1, 99999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-pass-123'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        $plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22-'.random_int(1, 999999),
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'cost_hour_eur_micro' => 800,
            'is_active' => true, 'in_stock' => true,
            'is_interruptible' => $interruptible,
        ]);

        return Service::create([
            'customer_id' => $customer->id, 'name' => 'سرورِ ابری CV-2-4',
            'currency_code' => 'IRT', 'price' => 570000, 'tax_percent' => 0,
            'cycle' => 'monthly', 'billing_mode' => $hourly ? 'hourly' : 'cycle',
            'status' => 'active', 'provision_status' => 'done',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
            'activated_at' => now(), 'next_due_at' => now()->addMonth(),
        ]);
    }

    private function machine(Service $service, string $status): CloudInstance
    {
        return CloudInstance::create([
            'service_id' => $service->id, 'provider' => 'hetzner', 'provider_ref' => '4711',
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$service->id, 'ipv4' => '185.51.200.9',
            'status' => $status,
            'specs' => ['vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => 20480],
            'synced_at' => now(),
        ]);
    }

    private function render(Service $service): string
    {
        return $this->actingAs($service->customer, 'customer')
            ->get('/account/cloud/'.$service->id)
            ->assertOk()->getContent();
    }

    // ───────────────────────── ادعاها ─────────────────────────

    /** 🔴 سرورِ ساعتیِ خاموش باید صریح بگوید صورت‌حساب ادامه دارد */
    public function test_a_stopped_hourly_server_says_billing_continues(): void
    {
        $service = $this->service(hourly: true, interruptible: false);
        $this->machine($service, 'off');

        $this->assertStringContainsString(
            __('ui.cs_off_still_billed'),
            $this->render($service),
            'صفحهٔ سرورِ خاموش باید بگوید کسر ادامه دارد.'
        );
    }

    /**
     * 🔴 ادعای منفیِ اصلی: روی پلنِ قطع‌شدنی این هشدار **دروغ** است.
     *
     * `CloudMeterHourly::billable()` برای `is_interruptible` ساعتِ خاموش را
     * نمی‌شمارد، پس اگر این‌جا هشدار بدهیم مشتری سروری را روشن نگه می‌دارد که
     * می‌توانست رایگان خاموش بماند.
     */
    public function test_an_interruptible_plan_never_shows_the_warning(): void
    {
        $service = $this->service(hourly: true, interruptible: true);
        $this->machine($service, 'off');

        $this->assertStringNotContainsString(
            __('ui.cs_off_still_billed'),
            $this->render($service)
        );
    }

    /** سرورِ روشن هشدار نمی‌گیرد — وگرنه نویز می‌شود و کسی نمی‌خوانَدش */
    public function test_a_running_server_shows_no_warning(): void
    {
        $service = $this->service(hourly: true, interruptible: false);
        $this->machine($service, 'running');

        $this->assertStringNotContainsString(
            __('ui.cs_off_still_billed'),
            $this->render($service)
        );
    }

    /** سرویسِ ماهانه ساعتی کسر نمی‌شود، پس این جمله برایش بی‌معناست */
    public function test_a_monthly_service_shows_no_hourly_warning(): void
    {
        $service = $this->service(hourly: false, interruptible: false);
        $this->machine($service, 'off');

        $this->assertStringNotContainsString(
            __('ui.cs_off_still_billed'),
            $this->render($service)
        );
    }

    /**
     * 🔴 هشدار باید **پیش از کلیک** هم باشد، نه فقط بعد از خاموشی.
     *
     * صفحهٔ بعدازخاموشی دیر است: مشتری آن‌وقت پول داده. متنِ تأیید تنها جایی
     * است که پیش از تصمیم دیده می‌شود.
     */
    public function test_the_power_off_confirmation_warns_about_billing(): void
    {
        $service = $this->service(hourly: true, interruptible: false);
        $this->machine($service, 'running');

        $html = $this->render($service);

        $this->assertStringContainsString(e(__('ui.cs_confirm_off_billed')), $html);
        $this->assertStringNotContainsString(e(__('ui.cs_confirm_off')), $html,
            'متنِ عمومی نباید جای متنِ صورت‌حساب‌دار را بگیرد.');
    }

    /** و روی پلنِ قطع‌شدنی همان متنِ عمومی می‌مانَد */
    public function test_an_interruptible_plan_keeps_the_plain_confirmation(): void
    {
        $service = $this->service(hourly: true, interruptible: true);
        $this->machine($service, 'running');

        $html = $this->render($service);

        $this->assertStringContainsString(e(__('ui.cs_confirm_off')), $html);
        $this->assertStringNotContainsString(e(__('ui.cs_confirm_off_billed')), $html);
    }

    /**
     * ⚠️ کلیدِ تازه باید در **هر سه** زبان باشد — کلیدِ جامانده یعنی مشتریِ
     * انگلیسی‌زبان متنِ خامِ `ui.cs_off_still_billed` می‌بیند.
     */
    public function test_the_new_keys_exist_in_all_three_languages(): void
    {
        foreach (['fa', 'en', 'tr'] as $locale) {
            $strings = require base_path("lang/{$locale}/ui.php");

            foreach (['cs_off_still_billed', 'cs_confirm_off_billed'] as $key) {
                $this->assertArrayHasKey($key, $strings, "{$key} در {$locale} نیست");
                $this->assertNotSame('', trim((string) $strings[$key]));
            }
        }
    }
}
