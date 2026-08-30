<?php

namespace Tests\Feature;

use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Services\Cloud\CloudProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 🔴 دکمهٔ حذفی که در حالِ تحویل **بی‌صدا هیچ کاری نمی‌کرد**.
 *
 * ═══ چطور این حالت ساخته می‌شود ═══
 *
 * `CloudProvisioner::finalize()` در لحظهٔ **پذیرشِ سفارش** می‌نویسد
 * `status='active'` و `provision_status='done'` — پیش از آنکه IPای وجود داشته
 * باشد. در آن پنجره، کارتِ سرویس هم‌زمان دو چیزِ ناسازگار نشان می‌داد:
 *
 *   • فهرستِ چهارمرحله‌ایِ «در حالِ ساخت»
 *   • و دکمهٔ **قرمزِ حذف** — که زده می‌شد و هیچ اتفاقی نمی‌افتاد، چون
 *     `terminate()` وقتی شناسهٔ ماشین را ندارد `true` برمی‌گرداند و فراخوان
 *     «موفق» ثبتش می‌کند.
 *
 * و دکمهٔ «لغو سفارش» — تنها راهِ پس‌گرفتنِ پول — در همان حالت **پنهان** بود.
 * یعنی مشتریِ پول‌داده فقط یک دکمهٔ بی‌اثر داشت.
 *
 * ⚠️ رفعِ نیمه‌کاره از خودِ باگ بدتر بود: اگر فقط دکمهٔ حذف خاموش می‌شد و
 * `cancel()` گسترده نمی‌شد، مشتری **هیچ** دکمه‌ای نداشت.
 */
class ServiceDeleteDuringDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function customer(): Customer
    {
        return Customer::create([
            'email' => 'd'.random_int(1, 999999).'@x.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('x'), 'status' => 'active', 'locale' => 'fa',
        ])->fresh();
    }

    private function plan(): CloudPlan
    {
        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        return CloudPlan::create([
            'provider' => 'aeza', 'provider_ref' => (string) random_int(100, 999999),
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-fal-'.random_int(1, 99999),
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);
    }

    /** دقیقاً همان پنجره: سفارش پذیرفته شده، ماشین هنوز نیامده */
    private function orderAcceptedButUndelivered(Customer $c): Service
    {
        $s = Service::create([
            'customer_id' => $c->id, 'name' => 'سرورِ ابری CV-2-4', 'currency_code' => 'IRT',
            'price' => 570000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done',
            'cloud_plan_id' => $this->plan()->id, 'activated_at' => now(),
            'next_due_at' => now()->addMonth(),
        ]);

        CloudInstance::create([
            'service_id' => $s->id, 'provider' => 'aeza',
            'provider_ref' => 'order:8801',            // شناسهٔ نهایی‌نشده
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$s->id, 'status' => 'building',   // بی‌IP
        ]);

        return $s->fresh();
    }

    /** و یک سرورِ واقعاً تحویل‌شده، برای اینکه ثابت شود چیزی خراب نشده */
    private function delivered(Customer $c): Service
    {
        $s = Service::create([
            'customer_id' => $c->id, 'name' => 'سرورِ زنده', 'currency_code' => 'IRT',
            'price' => 570000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'active', 'provision_status' => 'done',
            'cloud_plan_id' => $this->plan()->id, 'activated_at' => now(),
            'next_due_at' => now()->addMonth(),
        ]);

        CloudInstance::create([
            'service_id' => $s->id, 'provider' => 'aeza', 'provider_ref' => '90210',
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$s->id, 'status' => 'running', 'ipv4' => '203.0.113.44',
        ]);

        return $s->fresh();
    }

    // ═══════════════ ۱) تعریفِ واحد ═══════════════

    /** تعریفِ «هنوز تحویل نشده» از مدل می‌آید، پس ویو و کنترلر نمی‌توانند واگرا شوند */
    public function test_the_undelivered_predicate_matches_the_single_delivery_definition(): void
    {
        $c = $this->customer();

        $this->assertTrue($this->orderAcceptedButUndelivered($c)->cloudUndelivered(),
            'سرویسِ active+done بی‌IP باید «تحویل‌نشده» شمرده شود — همان‌جایی که پنل «در حالِ ساخت» می‌گوید');

        $this->assertFalse($this->delivered($c)->cloudUndelivered());
    }

    // ═══════════════ ۲) آنچه مشتری می‌بیند ═══════════════

    /**
     * 🔴 دکمهٔ حذف **با علتِ گفته‌شده** خاموش است، و راهِ خروج کنارش هست.
     *
     * ⚠️ هر سه ادعا با هم لازم‌اند. «حذف خاموش» به‌تنهایی یعنی مشتری هیچ
     * دکمه‌ای ندارد؛ «لغو هست» به‌تنهایی یعنی دکمهٔ بی‌اثر هنوز سرِ جایش است.
     */
    public function test_the_panel_disables_delete_with_a_reason_and_offers_cancel_instead(): void
    {
        $c = $this->customer();
        $s = $this->orderAcceptedButUndelivered($c);

        $html = $this->actingAs($c, 'customer')
            ->get('/account/servers')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('ui.svc_terminate_locked'), $html,
            'علتِ خاموش‌بودنِ دکمه باید نوشته شود — افورد‌نسی که بی‌توضیح ناپدید شود، تیکت می‌سازد');
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringNotContainsString('/services/'.$s->id.'/terminate/start', $html,
            'فرمِ حذف نباید رندر شود؛ زدنش بی‌صدا هیچ کاری نمی‌کرد');
        $this->assertStringContainsString('/services/'.$s->id.'/cancel', $html,
            'راهِ خروجِ سفارشِ گیرکرده باید همان‌جا باشد، وگرنه مشتری هیچ دکمه‌ای ندارد');
    }

    /** و سرورِ واقعاً تحویل‌شده دقیقاً مثلِ قبل، دکمهٔ حذفِ فعال دارد */
    public function test_a_really_delivered_server_still_has_a_working_delete_button(): void
    {
        $c = $this->customer();
        $s = $this->delivered($c);

        $html = $this->actingAs($c, 'customer')->get('/account/servers')->assertOk()->getContent();

        $this->assertStringContainsString('/services/'.$s->id.'/terminate/start', $html);
        $this->assertStringNotContainsString(__('ui.svc_terminate_locked'), $html);
    }

    // ═══════════════ ۳) ویو و سرور یک حرف می‌زنند ═══════════════

    /**
     * ⚠️ دکمه‌ای که ویو نشان می‌دهد و سرور ردش می‌کند، همان‌قدر بد است که
     * دکمهٔ نبود. هر دو جهت سنجیده می‌شود.
     */
    public function test_the_server_refuses_the_delete_it_no_longer_offers(): void
    {
        $c = $this->customer();
        $s = $this->orderAcceptedButUndelivered($c);

        $this->actingAs($c, 'customer')
            ->post('/account/services/'.$s->id.'/terminate/start')
            ->assertSessionHasErrors();

        $this->assertSame('active', $s->fresh()->status, 'سرویس نباید از این مسیر بسته شود');
    }

    /** و لغو — که تا امروز پنهان بود — واقعاً کار می‌کند */
    public function test_cancel_now_covers_the_order_accepted_but_undelivered_state(): void
    {
        $c = $this->customer();
        $s = $this->orderAcceptedButUndelivered($c);

        $this->actingAs($c, 'customer')
            ->post('/account/services/'.$s->id.'/cancel')
            ->assertRedirect();

        $this->assertSame('cancelled', $s->fresh()->status,
            'مشتریِ پول‌دادهٔ بی‌سرور باید بتواند خودش لغو کند — وگرنه نه سرور دارد نه پول');
    }

    // ═══════════════ ۴) حذفِ بی‌شناسه دیگر «موفق» نیست ═══════════════

    /**
     * 🔴 «چیزی برای حذف نیست» با «نمی‌دانم چه چیزی را حذف کنم» یکی نیست.
     *
     * ردیفِ نمونه‌ای که شناسه‌اش حل نشده، ممکن است یک ماشینِ **زندهٔ** خریده‌شده
     * باشد که پاسخش به ما نرسیده. `true` گفتن یعنی صفِ آزادسازی بسته می‌شود و
     * آن ماشین برای همیشه یتیم می‌مانَد و اجاره‌اش پای ماست.
     */
    public function test_terminating_an_instance_with_no_provider_ref_is_reported_as_a_failure(): void
    {
        $c = $this->customer();
        $s = $this->orderAcceptedButUndelivered($c);

        CloudInstance::where('service_id', $s->id)->update(['provider_ref' => null]);

        $this->assertFalse(app(CloudProvisioner::class)->terminate($s->fresh()),
            'حذفِ بی‌شناسه «موفق» گزارش شد — ماشینِ احتمالاً زنده بی‌هیچ ردی رها می‌شود');
    }

    /**
     * ⚠️ ولی جهتِ مخالف هم باگ است: سرویسی که **هرگز** سفارشی نداده نباید
     * صفِ آزادسازی را پر کند. زنگی که همیشه قرمز باشد، زنگِ بعدی را خفه می‌کند.
     */
    public function test_terminating_a_service_that_never_ordered_anything_is_a_success(): void
    {
        $c = $this->customer();

        $s = Service::create([
            'customer_id' => $c->id, 'name' => 'سفارشِ بی‌سرور', 'currency_code' => 'IRT',
            'price' => 570000, 'tax_percent' => 0, 'cycle' => 'monthly',
            'status' => 'awaiting_provision', 'provision_status' => 'manual',
            'cloud_plan_id' => $this->plan()->id,
        ]);

        $this->assertTrue(app(CloudProvisioner::class)->terminate($s->fresh()),
            'ردیفِ نمونه‌ای وجود ندارد — چیزی برای حذف نیست و این خرابی نیست');
    }
}
