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
 * «افزودن سرور» — دکمه‌ای که فقط در حالتِ **خالی** وجود داشت.
 *
 * کارفرما: مشتری‌ای که یک سرور دارد هیچ راهِ دیدنی برای خریدِ دومی نداشت؛ راهِ
 * خرید فقط در همان حالتی نشان داده می‌شد که مشتری هنوز چیزی نخریده بود. یعنی
 * دقیقاً برای کسی که ثابت کرده می‌خرد، پنهان بود.
 *
 * ⚠️ ادعا روی **مقدارِ دیداری** است نه کدِ ۲۰۰، و روی هر دو جای رندرِ همان
 * partial: اتاقِ سرور (`/account/servers`) و بخشِ سرورِ `/account/services`.
 */
class AccountAddServerButtonTest extends TestCase
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
            'email' => 'add'.random_int(1, 999999).'@example.com',
            'phone' => '0912'.random_int(1000000, 9999999),
            'password' => bcrypt('secret1234'), 'status' => 'active', 'locale' => 'fa',
        ]);
    }

    private function cloudService(Customer $c): Service
    {
        CloudLocation::firstOrCreate(['code' => 'de-falkenstein'],
            ['country' => 'DE', 'city' => 'Falkenstein', 'is_active' => true]);

        $plan = CloudPlan::create([
            'provider' => 'hetzner', 'provider_ref' => 'cx22', 'provider_location' => 'fsn1',
            'location_code' => 'de-falkenstein', 'public_name' => 'CV-2-4',
            'slug' => 'cv-2c-4g-40d-de-falkenstein', 'vcpu' => 2, 'ram_mb' => 4096,
            'disk_gb' => 40, 'disk_type' => 'nvme', 'traffic_gb' => 20480,
            'cpu_kind' => 'shared', 'arch' => 'x86', 'cost_eur_cents' => 379,
            'price_eur_cents' => 570, 'price_irt' => 570000, 'is_active' => true, 'in_stock' => true,
        ]);

        $s = Service::create([
            'customer_id' => $c->id, 'currency_code' => 'IRT', 'price' => 570000,
            'tax_percent' => 0, 'cycle' => 'monthly', 'status' => 'active',
            'provision_status' => 'done', 'activated_at' => now(), 'next_due_at' => now()->addMonth(),
            'name' => 'سرور ابری من', 'cloud_plan_id' => $plan->id,
        ]);

        CloudInstance::create([
            'service_id' => $s->id, 'provider' => 'hetzner', 'provider_ref' => '42',
            'location_code' => 'de-falkenstein', 'image_key' => 'ubuntu-24.04',
            'hostname' => 'sn-svc-'.$s->id, 'ipv4' => '203.0.113.45', 'ipv6' => '2a01:4f8::1',
            'status' => 'running', 'password_seen' => true,
            'specs' => ['vcpu' => 2, 'ram_mb' => 4096, 'disk_gb' => 40, 'disk_type' => 'nvme'],
        ]);

        return $s;
    }

    private function html(Customer $c, string $route): string
    {
        return $this->actingAs($c, 'customer')
            ->get(route($route, [], false))->assertOk()->getContent();
    }

    /** مشتری‌ای که از قبل سرور دارد، بالای بخش دکمهٔ خریدِ سرورِ تازه می‌بیند */
    public function test_a_customer_who_already_owns_a_server_still_gets_a_buy_button_at_the_top(): void
    {
        $c = $this->customer();
        $this->cloudService($c);

        foreach (['account.servers', 'account.services'] as $route) {
            $html = $this->html($c, $route);

            $this->assertStringContainsString(__('ui.sec_add_server'), $html, "دکمه در «{$route}» نیست");
            $this->assertMatchesRegularExpression(
                '~<a class="pnl-btn pnl-add" href="[^"]*'.preg_quote(route('account.cloud.store', [], false), '~').'"~',
                $html, "لینکِ دکمه در «{$route}» به فروشگاهِ سرور نمی‌رود");

            // …و **بالای** فهرست است، نه ته صفحه
            $btn = strpos($html, 'pnl-add');
            $grid = strpos($html, 'svc-grid');
            $this->assertNotFalse($btn);
            $this->assertNotFalse($grid);
            $this->assertLessThan($grid, $btn, "دکمه در «{$route}» زیرِ فهرست افتاده");

            // و کارتِ سرور همچنان رندر شده — دکمه جای چیزی را نگرفته
            $this->assertStringContainsString('سرور ابری من', $html);
        }
    }

    /** حالتِ خالی دست‌نخورده: همان CTAِ خودش، بی‌دکمهٔ تکراری در سرصفحه */
    public function test_the_empty_state_keeps_its_own_call_to_action_and_gains_no_duplicate(): void
    {
        $c = $this->customer();
        $html = $this->html($c, 'account.servers');

        $this->assertStringContainsString(__('ui.sec_empty_servers_cta'), $html);
        $this->assertStringContainsString('pnl-empty', $html);
        $this->assertStringNotContainsString('pnl-add', $html,
            'وقتی سروری نیست، سرصفحه نباید دکمهٔ دومِ خرید بگذارد');
    }

    /** سه‌زبانه — همان واژگانِ طراحی، در هر سه زبان */
    public function test_the_button_is_translated_in_all_three_locales(): void
    {
        $c = $this->customer();
        $this->cloudService($c);

        foreach (['account.servers' => 'fa', 'en.account.servers' => 'en', 'tr.account.servers' => 'tr'] as $route => $loc) {
            $html = $this->html($c, $route);
            $want = (array) require lang_path($loc.'/ui.php');

            $this->assertStringContainsString($want['sec_add_server'], $html, "«{$route}» ترجمه نشده");
            $this->assertStringNotContainsString('ui.sec_add_server', $html);
        }
    }
}
