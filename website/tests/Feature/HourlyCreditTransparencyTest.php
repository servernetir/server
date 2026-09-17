<?php

namespace Tests\Feature;

use App\Models\CloudImage;
use App\Models\CloudInstance;
use App\Models\CloudLocation;
use App\Models\CloudPlan;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Cloud\HourlyHold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «وقتی اعتبار تمام شود چه می‌شود؟» — پیش از خرید، در صفحهٔ خرید، و در پنل.
 *
 * ═══ رخداد (شهریور ۱۴۰۵) ═══
 *
 * کارفرما: «خیلی از تماس‌های پشتیبانی درمورد این است.» صفحهٔ فروشِ ساعتی
 * **دروغ** هم می‌گفت: «فقط ساعت‌های روشن‌بودن را می‌پردازید»، در حالی که مترِ
 * ساعتی سرورِ خاموش را (درست) کسر می‌کند — دقیقاً همان تیکتِ SN-593484.
 * پنل هم نه مهلتِ حذف را نشان می‌داد نه راهِ تغییرِ رفتارِ پایانِ اعتبار را.
 */
class HourlyCreditTransparencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Setting::put('pricing_rate_override', '120000');
        HourlyHold::flush();
    }

    private function hourly(array $over = [], int $credit = 100_000): Service
    {
        $c = Customer::create([
            'code' => 'SN-'.random_int(100000, 999999),
            'email' => 'tr'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret-pass-123'), 'status' => 'active', 'locale' => 'fa',
        ]);

        CreditEntry::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'amount' => $credit,
            'balance_after' => $credit, 'reason' => 'topup', 'source_type' => Customer::class,
            'source_id' => $c->id, 'note' => 'test',
        ]);

        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'], ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);
        CloudImage::firstOrCreate(['provider' => 'hetzner', 'provider_ref' => '161547269'],
            ['key' => 'ubuntu-24.04', 'kind' => 'os', 'family' => 'ubuntu', 'version' => '24.04',
                'label' => 'Ubuntu 24.04', 'arch' => 'x86', 'is_active' => true]);

        $plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22-'.random_int(1, 999999), 'provider_location' => 'fsn1',
            'location_code' => 'de-falkenstein', 'public_name' => 'CV-2-4', 'slug' => 'cv-2c-4g-40d-de-falkenstein',
            'vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme',
            'traffic_gb' => 20480, 'cpu_kind' => 'shared', 'arch' => 'x86',
            'cost_eur_cents' => 379, 'price_eur_cents' => 570, 'price_irt' => 570000,
            'is_active' => true, 'in_stock' => true,
        ]);

        $s = Service::create(array_merge([
            'customer_id' => $c->id, 'name' => 'VPS ساعتی', 'currency_code' => 'IRT', 'price' => 570000,
            'tax_percent' => 0, 'cycle' => 'monthly', 'billing_mode' => 'hourly', 'hourly_rate_irt' => 800,
            'status' => 'active', 'provision_status' => 'done', 'on_credit_out' => 'suspend',
            'cloud_plan_id' => $plan->id, 'cloud_image_key' => 'ubuntu-24.04',
            'activated_at' => now(), 'last_metered_at' => now(),
        ], $over));

        CloudInstance::create([
            'service_id' => $s->id, 'provider' => 'hetzner', 'provider_ref' => 'srv-'.$s->id,
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$s->id, 'ipv4' => '10.3.0.'.($s->id % 250 + 1),
            'status' => ($over['status'] ?? 'active') === 'suspended' ? 'off' : 'running',
            'ready_notified_at' => now()->subDay(),
        ]);

        if (($over['status'] ?? 'active') !== 'suspended') {
            app(HourlyHold::class)->syncRunning($s->fresh());
        }

        return $s->fresh();
    }

    // ═══════════════ صفحهٔ فروش ═══════════════

    /** 🔴 وعدهٔ دروغ دیگر روی صفحه نیست، و پاسخِ درست هست */
    public function test_the_hourly_page_tells_the_truth_about_credit_and_power_off(): void
    {
        $html = (string) $this->get('/vps/hourly')->assertOk()->getContent();

        $this->assertStringNotContainsString('فقط ساعت‌های روشن‌بودن را می‌پردازید', $html);
        $this->assertStringContainsString('id="credit-runs-out"', $html);
        $this->assertStringContainsString(__('ui.cl_t', [], 'fa'), $html);
        $this->assertStringContainsString('برای همیشه حذف', $html);

        // پرسش‌های تازه واردِ FAQPage هم شده‌اند — پاسخی که گوگل نشان می‌دهد
        $this->assertStringContainsString('"name":"'.__('ui.hv_faq9_q', [], 'fa').'"', $html);
    }

    public function test_the_hourly_page_answers_in_english_and_turkish(): void
    {
        $this->get('/en/vps/hourly')->assertOk()->assertSee('What exactly happens when credit runs out?');
        $this->get('/tr/vps/hourly')->assertOk()->assertSee('Bakiye bittiğinde tam olarak ne olur?');
    }

    public function test_the_gpu_page_explains_credit_out_and_ships_a_faq_page(): void
    {
        $html = (string) $this->get('/gpu')->assertOk()->getContent();

        $this->assertStringContainsString('id="credit-runs-out"', $html);
        $this->assertStringContainsString(__('ui.cl_hold_d_gpu', ['grace' => '۲۴'], 'fa'), $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringContainsString(__('ui.gpu_faq3_q', [], 'fa'), $html);
    }

    // ═══════════════ پنل ═══════════════

    public function test_the_panel_shows_rate_spendable_hours_and_the_reserve(): void
    {
        $s = $this->hourly();

        // ذخیره = ۲۴ × ۷۰۰ = ۱۶٬۸۰۰ ⇒ در دسترس ۸۳٬۲۰۰ ⇒ ۱۰۴ ساعت
        $html = (string) $this->actingAs($s->customer, 'customer')
            ->get('/account/cloud/'.$s->id)->assertOk()->getContent();

        $this->assertStringContainsString('id="hourly-billing"', $html);
        $this->assertStringContainsString(fa_num('16,800'), $html);
        $this->assertStringContainsString(__('ui.hb_hours_left', ['hours' => fa_num('104')], 'fa'), $html);
        $this->assertStringContainsString(__('ui.hb_step_off_billed', [], 'fa'), $html);
    }

    /** سرورِ خاموش‌شده به‌خاطرِ اعتبار: زمانِ حذف و مبلغِ لازم برای روشن‌شدن */
    public function test_a_suspended_server_shows_the_deletion_deadline_and_what_to_pay(): void
    {
        $s = $this->hourly([
            'status' => 'suspended', 'suspended_at' => now()->subHours(4),
            'hold_rate_irt' => 700, 'hold_reserve_irt' => 20 * 700,
        ], credit: 14_000);

        $html = (string) $this->actingAs($s->customer, 'customer')
            ->get('/account/cloud/'.$s->id)->assertOk()->getContent();

        $this->assertStringContainsString(__('ui.hb_susp_h', [], 'fa'), $html);
        // لازم = ۸۰۰ + ۱۶٬۸۰۰ − (۰ + ۱۴٬۰۰۰) = ۳٬۶۰۰
        $this->assertStringContainsString(fa_num('3,600'), $html);
        $this->assertStringContainsString('account/topup', $html);
    }

    public function test_the_customer_can_change_the_credit_out_policy_after_buying(): void
    {
        $s = $this->hourly();

        $this->actingAs($s->customer, 'customer')
            ->post('/account/cloud/'.$s->id.'/credit-policy', ['on_credit_out' => 'terminate'])
            ->assertSessionHasNoErrors();

        $s->refresh();
        $this->assertSame('terminate', $s->on_credit_out);
        $this->assertSame(0, (int) $s->hold_reserve_irt, 'حذفِ فوری نگهداری ندارد ⇒ ذخیره آزاد');

        $this->post('/account/cloud/'.$s->id.'/credit-policy', ['on_credit_out' => 'suspend'])
            ->assertSessionHasNoErrors();
        $this->assertSame(24 * 700, (int) $s->fresh()->hold_reserve_irt);
    }

    /** نگهداری‌ای که پولش در کیف نیست وعده داده نمی‌شود */
    public function test_switching_back_to_a_hold_needs_the_reserve_in_the_wallet(): void
    {
        $s = $this->hourly(['on_credit_out' => 'terminate'], credit: 10_000);

        $this->actingAs($s->customer, 'customer')
            ->post('/account/cloud/'.$s->id.'/credit-policy', ['on_credit_out' => 'suspend'])
            ->assertSessionHasErrors();

        $s->refresh();
        $this->assertSame('terminate', $s->on_credit_out);
        $this->assertSame(0, (int) $s->hold_reserve_irt);
    }

    public function test_the_policy_is_locked_while_the_server_is_off_for_credit(): void
    {
        $s = $this->hourly(['status' => 'suspended', 'suspended_at' => now(), 'hold_rate_irt' => 700, 'hold_reserve_irt' => 16_800]);

        $this->actingAs($s->customer, 'customer')
            ->post('/account/cloud/'.$s->id.'/credit-policy', ['on_credit_out' => 'terminate'])
            ->assertSessionHasErrors();

        $this->assertSame('suspend', $s->fresh()->on_credit_out);
    }

    /** 🔴 «فاکتورِ بازمانده را پرداخت کنید» برای سرورِ ساعتی غلط بود — راه، شارژِ کیف است */
    public function test_a_power_action_on_a_credit_suspended_server_points_to_the_wallet(): void
    {
        $s = $this->hourly(['status' => 'suspended', 'suspended_at' => now(), 'hold_rate_irt' => 700, 'hold_reserve_irt' => 16_800]);

        $this->actingAs($s->customer, 'customer')
            ->from('/account/cloud/'.$s->id)
            ->post('/account/cloud/'.$s->id.'/power', ['action' => 'on'])
            ->assertSessionHasErrors();

        $this->assertStringContainsString('کیف پول', (string) session('errors')->first());
    }

    public function test_someone_elses_server_policy_cannot_be_changed(): void
    {
        $s = $this->hourly();
        $other = $this->hourly();

        $this->actingAs($other->customer, 'customer')
            ->post('/account/cloud/'.$s->id.'/credit-policy', ['on_credit_out' => 'terminate'])
            ->assertNotFound();
    }
}
