<?php

namespace Tests\Feature;

use App\Models\CloudPlan;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مرکزِ تحویل‌ها (/admin/provisioning) — دیدِ یک‌جای هر سفارشِ پول‌آمدهٔ
 * تحویل‌نشده، با تشخیصِ به‌زبانِ‌آدم و دکمهٔ اقدامِ درست.
 *
 * ═══ نقصی که این را لازم کرد ═══
 *
 * 🔴 همهٔ اجزا وجود داشتند ولی پراکنده: خطا روی پروفایل، تلاشِ دوباره
 * همان‌جا، قرنطینه در ErrorTracker. مدیر «خطا در تحویل»ِ SN-604534 را اتفاقی
 * دید و نمی‌دانست چه شده و چه کند. صفحه‌ای که نیست، برای مدیر وجود ندارد —
 * همان درسِ دکمهٔ رباتِ بله.
 */
class AdminProvisioningCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function customer(): Customer
    {
        return Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'p'.random_int(1, 999999).'@example.com',
            'password' => bcrypt('secret-pass-123'), 'status' => 'active',
        ]);
    }

    private function plan(array $over = []): CloudPlan
    {
        return CloudPlan::create(array_merge([
            'provider' => 'hetzner', 'provider_ref' => 'cx22',
            'provider_location' => 'fsn1', 'location_code' => 'de-falkenstein',
            'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-'.random_int(1, 99999),
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ], $over));
    }

    private function service(Customer $c, array $over = []): Service
    {
        return Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'سرویس آزمایشی', 'currency_code' => 'IRT',
            'price' => 500000, 'cycle' => 'monthly', 'status' => 'awaiting_provision',
        ], $over));
    }

    /** 🔴 سرویسِ خطاخورده با تشخیص، خطای خام، تلاشِ دوباره و لینکِ پروفایل. */
    public function test_a_failed_service_shows_diagnosis_and_actions(): void
    {
        $c = $this->customer();
        $s = $this->service($c, [
            'cloud_plan_id' => $this->plan()->id,
            'provision_status' => 'failed',
            'provision_error' => 'Insufficient balance for this action',
        ]);

        $html = $this->actingAs($this->admin)
            ->get('/admin/provisioning')->assertOk()->getContent();

        $this->assertStringContainsString($c->code, $html, 'مشتریِ سفارشِ خطاخورده در فهرست نیست');
        $this->assertStringContainsString('Insufficient balance', $html, 'خطای خام نمایش داده نمی‌شود');
        $this->assertStringContainsString('زیرساختِ فروشنده سفارش را نپذیرفت', $html, 'تشخیصِ به‌زبانِ‌آدم نیست');
        $this->assertStringContainsString('/admin/services/'.$s->id.'/provision', $html, 'دکمهٔ تلاشِ دوباره نیست');
        $this->assertStringContainsString('/admin/customers/'.$c->id, $html, 'لینکِ پروفایل (لغو/بازگشت) نیست');
    }

    /** سفارشِ نگه‌داشتهٔ محافظ: تشخیصِ محافظ + دکمهٔ «تأیید و ساخت». */
    public function test_a_guard_hold_offers_the_override_door(): void
    {
        $c = $this->customer();
        $s = $this->service($c, [
            'cloud_plan_id' => $this->plan()->id,
            'provision_status' => 'manual',
            'provision_error' => 'نیازمندِ تأییدِ دستی: بیش از حد سفارش در ۲۴ ساعت',
        ]);

        $html = $this->actingAs($this->admin)
            ->get('/admin/provisioning')->assertOk()->getContent();

        $this->assertStringContainsString('محافظِ سوءاستفاده', $html);
        $this->assertStringContainsString('/admin/services/'.$s->id.'/provision-override', $html,
            'تنها درِ خروجِ سفارشِ پارک‌شده روی صفحه نیست');
    }

    /**
     * 🔴 سرویسِ مرده هرگز در صف نیاید — «لغو + بازگشت وجه» سرویس را می‌کشد ولی
     * provision_status=failed می‌مانَد؛ بدونِ این قید، هر لغوی برای همیشه
     * این‌جا جا خوش می‌کرد و صفحه پر از کارِ تمام‌شده می‌شد.
     */
    public function test_dead_services_never_appear(): void
    {
        $c = $this->customer();
        $this->service($c, [
            'status' => 'cancelled',
            'provision_status' => 'failed',
            'provision_error' => 'quota exceeded',
        ]);

        $html = $this->actingAs($this->admin)
            ->get('/admin/provisioning')->assertOk()->getContent();

        $this->assertStringNotContainsString($c->code, $html, 'سرویسِ لغوشده نباید در صفِ رسیدگی باشد');
    }

    /**
     * نشانِ منو فقط حالت‌های «بی‌آدم تکان نمی‌خورد» را می‌شمارد؛ صفِ سالمِ
     * pending شمرده نمی‌شود وگرنه نشان همیشه روشن می‌مانْد و کور می‌شد.
     */
    public function test_the_nav_badge_counts_only_human_needed_states(): void
    {
        $c = $this->customer();
        $this->service($c, ['provision_status' => 'failed', 'provision_error' => 'x']);
        $this->service($c, ['provision_status' => 'pending']);

        $html = $this->actingAs($this->admin)
            ->get('/admin/tickets')->assertOk()->getContent();

        $this->assertStringContainsString('/admin/provisioning', $html, 'آیتمِ منو نیست — صفحهٔ کشف‌نشدنی وجود ندارد');
        $this->assertMatchesRegularExpression(
            '~تحویل‌ها<span class="ad-pill">'.fa_num(1).'</span>~u',
            $html,
            'نشان باید فقط ۱ (failed) را بشمارد، نه صفِ سالم را'
        );
    }

    /** پلنِ ۲+ شکست در ۱۴ روز، با دکمهٔ «توقفِ فروش» بالا می‌آید. */
    public function test_a_repeat_failing_plan_is_surfaced_with_a_close_button(): void
    {
        $plan = $this->plan();
        $c = $this->customer();

        foreach ([1, 2] as $i) {
            $this->service($c, [
                'cloud_plan_id' => $plan->id,
                'provision_status' => 'failed',
                'provision_error' => 'server create failed '.$i,
            ]);
        }

        $html = $this->actingAs($this->admin)
            ->get('/admin/provisioning')->assertOk()->getContent();

        $this->assertStringContainsString('پلن‌های پرخطا', $html);
        $this->assertStringContainsString($plan->public_name, $html, 'پلنِ پرخطا فهرست نشده');
        $this->assertStringContainsString('/admin/cloud/plans/'.$plan->id.'/toggle', $html,
            'دکمهٔ توقفِ فروش نیست — «نفروش اگر ساختنی نیست» بی‌افورد‌نس مانده');
    }

    /** فقط admin؛ نویسنده ۴۰۳. */
    public function test_authors_are_kept_out(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'author']))
            ->get('/admin/provisioning')->assertForbidden();
    }

    /** ضربانِ کرون همیشه بالای صفحه است — نبودنش یعنی «هیچ تحویلی خودکار نیست». */
    public function test_the_cron_heartbeat_is_always_shown(): void
    {
        $html = $this->actingAs($this->admin)
            ->get('/admin/provisioning')->assertOk()->getContent();

        $this->assertStringContainsString('آخرین ضربانِ کرون', $html);
    }
}
